<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\Validation\InputLimits;
use App\Traits\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Warehouse\Services\CurrentBaselineReversalService;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use OpenApi\Attributes as OA;

class ReversalDocumentController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;
    use ResolvesActor;

    public function __construct(private readonly CurrentBaselineReversalService $reversalService) {}

    /**
     * Ghi là vô hiệu hoá NGAY (điều 4 của CachesReadPayload). Trong transaction thì
     * afterCommit() hoãn tới lúc commit thật, ngoài transaction thì chạy luôn — không
     * đường ghi nào nạp lại cache từ dữ liệu chưa commit.
     */
    private function invalidateReadCache(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));
    }

    /**
     * Dựng lại paginator từ payload đã cache để `respondPaginated()` phát ra ĐÚNG
     * envelope cũ (data/meta/links). `meta.request_id` + `meta.timestamp` sinh trong
     * respondPaginated() nên luôn tươi; options trùng `Builder::paginate()` nên
     * `links` không đổi.
     *
     * @param  array<string, mixed>  $payload
     */
    private function paginatorFromReadCache(array $payload): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            $payload['items'] ?? [],
            (int) ($payload['total'] ?? 0),
            max(1, (int) ($payload['per_page'] ?? 10)),
            max(1, (int) ($payload['current_page'] ?? 1)),
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    #[OA\Get(
        path: '/api/v1/reversal-documents',
        operationId: 'flow06_reversal_list',
        summary: 'Danh sách chứng từ điều chỉnh (reversal), phân trang, filter theo status/keyword, theo data-scope kho',
        security: [['bearerAuth' => []]],
        tags: ['11. Reversal'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'keyword', in: 'query', description: 'Reversal number, displayed original document number (stock or component issue), or reason', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách chứng từ điều chỉnh'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function index(Request $request)
    {
        // TTL 'search' (120s): chứng từ điều chỉnh là chứng từ đang lưu thông.
        // Data scope kho được giải NGAY TRONG KHOÁ (readCacheScopeIds) và vẫn được
        // applyWarehouseScope() lọc trong SQL — hai user khác scope không bao giờ
        // dùng chung entry, nên cache hit không thể nuốt mất giới hạn phạm vi.
        $payload = $this->cachedReadPayload(
            'warehouse:reversal-document:list:source-search-v2',
            // Phân trang chạy TRONG SQL ⇒ page/per_page BẮT BUỘC nằm trong khoá.
            $this->readFiltersWithPaging($request),
            fn (): array => $this->buildIndexPayload($request),
            'search',
            'stock:version',
        );

        return $this->respondPaginated($this->paginatorFromReadCache($payload));
    }

    /**
     * Phần chạy SQL của index(), tách ra để cache được (mảng thuần).
     *
     * @return array<string, mixed>
     */
    private function buildIndexPayload(Request $request): array
    {
        $query = DB::table('reversal_documents')
            ->leftJoin('warehouses', 'reversal_documents.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('stock_documents', 'reversal_documents.original_document_id', '=', 'stock_documents.id')
            // DEC-REV-COMPONENT-001: chứng từ gốc COMPONENT_ISSUE nằm ở bảng riêng;
            // join thêm để REV-00 vẫn hiển thị được mã phiếu nguồn.
            ->leftJoin('component_issue_documents', 'reversal_documents.original_document_id', '=', 'component_issue_documents.id')
            ->leftJoin('app_user', 'reversal_documents.created_by', '=', 'app_user.user_id')
            // Người ghi nhận (posted_by) cũng phải ra TÊN: FE cấm in UUID thô.
            ->leftJoin('app_user as poster', 'reversal_documents.posted_by', '=', 'poster.user_id')
            ->select([
                'reversal_documents.id',
                'reversal_documents.reversal_no as reversal_code',
                'reversal_documents.original_document_type as reference_document_type',
                'reversal_documents.original_document_id as reference_document_id',
                DB::raw('COALESCE(stock_documents.doc_no, component_issue_documents.component_issue_no) as reference_document_code'),
                'reversal_documents.status',
                'reversal_documents.reason_code',
                'reversal_documents.reason_text as reason',
                'reversal_documents.warehouse_id',
                'reversal_documents.correction_chain_id',
                'reversal_documents.posted_at as reversed_at',
                'reversal_documents.posted_by',
                'poster.display_name as posted_by_name',
                // ERD bảng 33 + BR-REV-06/07: hai cột này là điều kiện Post của Reverse
                // Issue. Không chiếu ra thì REV-00/REV-01 không bao giờ thấy bước
                // CONFIRMED, nút "Xác nhận hàng vật lý đã về" không render và mọi lần
                // Post đều bị 409 PHYSICAL_RETURN_REQUIRED.
                'reversal_documents.physical_return_status',
                'reversal_documents.condition_status',
                'reversal_documents.cancelled_at',
                'reversal_documents.cancel_reason',
                'reversal_documents.created_at',
                'reversal_documents.created_by',
                'app_user.display_name as created_by_name',
                'reversal_documents.version',
                'warehouses.name as warehouse_name',
                'warehouses.code as warehouse_code',
                DB::raw('(SELECT COUNT(*) FROM reversal_lines rl WHERE rl.reversal_document_id = reversal_documents.id) as total_lines'),
                DB::raw('(SELECT COALESCE(SUM(rl.quantity), 0) FROM reversal_lines rl WHERE rl.reversal_document_id = reversal_documents.id) as total_qty'),
            ])
            ->orderByDesc('reversal_documents.created_at');

        // WWHN-242 · defense-in-depth data scope. `reversal_documents` được đọc bằng
        // query builder thô nên không có global scope của model che. Lọc ngay trong
        // query (không lọc sau phản hồi) để `meta.total` của phân trang cũng đúng theo
        // phạm vi kho của actor.
        $this->applyWarehouseScope($query);

        if ($request->filled('status')) {
            $query->where('reversal_documents.status', $request->input('status'));
        }
        if ($request->filled('keyword')) {
            $keyword = '%'.mb_strtolower(trim((string) $request->input('keyword'))).'%';
            // sqlite không có ILIKE; hạ chữ hai vế để cùng ngữ nghĩa trên mọi driver.
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(CAST(reversal_documents.reversal_no AS TEXT)) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(CAST(COALESCE(stock_documents.doc_no, component_issue_documents.component_issue_no) AS TEXT)) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(CAST(reversal_documents.reason_text AS TEXT)) LIKE ?', [$keyword]);
            });
        }

        $paginator = $query->paginate($this->perPage($request));

        return [
            'items' => $paginator->items(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
        ];
    }

    #[OA\Get(
        path: '/api/v1/reversal-documents/{id}',
        operationId: 'flow06_reversal_detail',
        summary: 'Chi tiết chứng từ điều chỉnh',
        security: [['bearerAuth' => []]],
        tags: ['11. Reversal'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết chứng từ điều chỉnh'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        ]
    )]
    public function show(string $id)
    {
        // TTL 'search' (120s): chứng từ đang lưu thông. `[]` = không tìm thấy — một
        // dòng DB luôn có cột nên không thể trùng với dữ liệu thật. Chứng từ mới chỉ
        // sinh qua store() (bump ngay sau commit) nên entry rỗng không sống lâu.
        $payload = $this->cachedReadPayload(
            'warehouse:reversal-document:detail',
            ['id' => $id],
            fn (): array => (array) ($this->reversalDocumentRow($id) ?? []),
            'search',
            'stock:version',
        );

        return $payload === []
            ? $this->respondNotFound('Không tìm thấy reversal document.')
            : $this->respondSuccess($payload);
    }

    /** Header chứng từ điều chỉnh đã join sẵn tên kho/người thực hiện; `null` = không có. */
    private function reversalDocumentRow(string $id): ?object
    {
        return DB::table('reversal_documents')
            ->leftJoin('warehouses', 'reversal_documents.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('stock_documents', 'reversal_documents.original_document_id', '=', 'stock_documents.id')
            // DEC-REV-COMPONENT-001: chứng từ gốc COMPONENT_ISSUE nằm ở bảng riêng;
            // join thêm để REV-00 vẫn hiển thị được mã phiếu nguồn.
            ->leftJoin('component_issue_documents', 'reversal_documents.original_document_id', '=', 'component_issue_documents.id')
            ->leftJoin('app_user', 'reversal_documents.created_by', '=', 'app_user.user_id')
            // Người ghi nhận (posted_by) cũng phải ra TÊN: FE cấm in UUID thô.
            ->leftJoin('app_user as poster', 'reversal_documents.posted_by', '=', 'poster.user_id')
            ->select([
                'reversal_documents.id',
                'reversal_documents.reversal_no as reversal_code',
                'reversal_documents.original_document_type as reference_document_type',
                'reversal_documents.original_document_id as reference_document_id',
                DB::raw('COALESCE(stock_documents.doc_no, component_issue_documents.component_issue_no) as reference_document_code'),
                'reversal_documents.status',
                'reversal_documents.reason_code',
                'reversal_documents.reason_text as reason',
                'reversal_documents.warehouse_id',
                'reversal_documents.correction_chain_id',
                'reversal_documents.posted_at as reversed_at',
                'reversal_documents.posted_by',
                'poster.display_name as posted_by_name',
                // ERD bảng 33 + BR-REV-06/07: hai cột này là điều kiện Post của Reverse
                // Issue. Không chiếu ra thì REV-00/REV-01 không bao giờ thấy bước
                // CONFIRMED, nút "Xác nhận hàng vật lý đã về" không render và mọi lần
                // Post đều bị 409 PHYSICAL_RETURN_REQUIRED.
                'reversal_documents.physical_return_status',
                'reversal_documents.condition_status',
                'reversal_documents.cancelled_at',
                'reversal_documents.cancel_reason',
                'reversal_documents.created_at',
                'reversal_documents.created_by',
                'app_user.display_name as created_by_name',
                'reversal_documents.version',
                'warehouses.name as warehouse_name',
                'warehouses.code as warehouse_code',
                DB::raw('(SELECT COUNT(*) FROM reversal_lines rl WHERE rl.reversal_document_id = reversal_documents.id) as total_lines'),
                DB::raw('(SELECT COALESCE(SUM(rl.quantity), 0) FROM reversal_lines rl WHERE rl.reversal_document_id = reversal_documents.id) as total_qty'),
            ])
            ->where('reversal_documents.id', $id)
            ->first();
    }

    /**
     * SRS §6 Reversal state model + Annex C. Ba bước tách bạch:
     * confirm (xác nhận hàng vật lý về, stock-neutral) → post (điểm duy nhất sinh
     * movement) → hoặc cancel khi còn DRAFT. BR-REV-02 bắt buộc If-Match;
     * BR-REV-12 bắt buộc Idempotency-Key ở bước Post.
     */
    #[OA\Post(
        path: '/api/v1/reversal-documents/{id}/confirm',
        operationId: 'confirmReversalPhysicalReturn',
        summary: 'Xác nhận hàng vật lý quay lại kho cho Reversal (stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'return_condition', type: 'string', enum: ['SELLABLE', 'DEFECTIVE', 'NON_SELLABLE'], nullable: true, description: 'BR-REV-06/07: chỉ SELLABLE mới cộng available_qty khi Post.', example: 'SELLABLE'),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã xác nhận hàng vật lý quay lại kho; chưa phát sinh stock effect.'),
        ]
    )]
    public function confirm(Request $request, string $id)
    {
        $validated = $request->validate([
            'return_condition' => ['nullable', 'in:SELLABLE,DEFECTIVE,NON_SELLABLE'],
        ]);

        $result = $this->reversalService->confirmPhysicalReturn(
            $id,
            $this->actorId(),
            $this->requiredVersion($request),
            $validated['return_condition'] ?? null
        );
        // Confirm là stock-neutral nên KHÔNG đi qua mutateBalance() — phải tự bump,
        // nếu không REV-00/REV-01 còn thấy bản DRAFT cũ suốt TTL.
        $this->invalidateReadCache();

        return $this->respondSuccess($result, 'Đã xác nhận hàng vật lý quay lại kho; chưa phát sinh stock effect.');
    }

    #[OA\Post(
        path: '/api/v1/reversal-documents/{id}/post',
        operationId: 'flow06_reversal_post',
        summary: 'Post chứng từ điều chỉnh (thực thi điều chỉnh tồn kho)',
        security: [['bearerAuth' => []]],
        tags: ['11. Reversal'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Post chứng từ điều chỉnh thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
            new OA\Response(response: 409, description: 'State/idempotency/dependency conflict'),
            new OA\Response(response: 412, description: 'STALE_VERSION — If-Match không khớp'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key hoặc If-Match hợp lệ'),
        ]
    )]
    public function post(Request $request, string $id)
    {
        $key = IdempotencyKeyGuard::require($request);
        $expectedVersion = $this->requiredVersion($request);

        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            'post_reversal_'.$id,
            $this->actorId(),
            IdempotencyStore::requestHash($request),
            function () use ($id, $key, $request, $expectedVersion): array {
                $result = $this->reversalService->postReversalDocument(
                    $id,
                    $this->actorId(),
                    $key,
                    IdempotencyStore::requestHash($request),
                    $expectedVersion
                );
                // Bump NẰM TRONG closure của IdempotencyStore: lần replay không thực
                // thi lại nghiệp vụ nên cũng không được xoá cache vô ích.
                $this->invalidateReadCache();

                return [200, [
                    'success' => true,
                    'message' => 'Đã Post reversal; movement append-only đã ghi.',
                    'data' => $result,
                ]];
            }
        );

        if (is_array($body) && isset($body['data']) && is_array($body['data'])) {
            $body['data']['replayed'] = $replay;
        }

        return response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    #[OA\Post(
        path: '/api/v1/reversal-documents/{id}/cancel',
        operationId: 'cancelReversalDocument',
        summary: 'Huỷ Reversal khi còn DRAFT (stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 2000, example: 'Hoàn trả do nhập sai số lượng'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã huỷ reversal DRAFT; không phát sinh stock effect.'),
        ]
    )]
    public function cancel(Request $request, string $id)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $result = $this->reversalService->cancelReversalDocument(
            $id,
            $this->actorId(),
            $this->requiredVersion($request),
            (string) $validated['reason']
        );
        // Cancel DRAFT là stock-neutral nên không đi qua mutateBalance() — tự bump.
        $this->invalidateReadCache();

        return $this->respondSuccess($result, 'Đã huỷ reversal DRAFT; không phát sinh stock effect.');
    }

    /** BR-REV-02 + §8.2: thiếu If-Match phải trả 428, không ngầm giả định version. */
    private function requiredVersion(Request $request): int
    {
        if (! $request->hasHeader('If-Match')) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Thao tác reversal bắt buộc header If-Match.');
        }
        $raw = preg_replace('/^W\//', '', trim((string) $request->header('If-Match'))) ?? '';
        $version = (int) trim($raw, '" ');
        if ($version < 1) {
            ApiError::abort(400, 'INVALID_HEADER', 'If-Match phải là số nguyên dương.');
        }

        return $version;
    }

    /**
     * Annex D SCR-REV-01 yêu cầu hiển thị original_movement, qty_to_reverse và
     * return_condition. `reversal_lines.quantity` và `quantity_to_reverse` luôn bằng
     * nhau (trigger view ghi cả hai) nên không được dùng làm "số lượng gốc" — số gốc
     * phải lấy từ chính movement gốc, còn reversible_qty tính theo BR-REV-05.
     */
    #[OA\Get(
        path: '/api/v1/reversal-documents/{id}/lines',
        operationId: 'flow06_reversal_lines',
        summary: 'Danh sách dòng của chứng từ điều chỉnh',
        security: [['bearerAuth' => []]],
        tags: ['11. Reversal'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách dòng điều chỉnh'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        ]
    )]
    public function lines(string $id)
    {
        // Kiểm tra tồn tại NẰM NGOÀI cache: 404 không được cache, và payload thật có
        // thể là danh sách RỖNG nên `[]` không dùng làm cờ "không tìm thấy" được.
        $doc = DB::table('reversal_documents')->where('id', $id)->first();
        if (! $doc) {
            return $this->respondNotFound('Không tìm thấy reversal document.');
        }

        // TTL 'search' (120s): dòng chứng từ đang lưu thông. `status` nằm trong khoá
        // vì cột line_status suy ra từ trạng thái header.
        $payload = $this->cachedReadPayload(
            'warehouse:reversal-document:lines',
            ['id' => $id, 'status' => (string) ($doc->status ?? '')],
            fn (): array => $this->buildLinesPayload($id, $doc),
            'search',
            'stock:version',
        );

        return $this->respondSuccess($payload);
    }

    /**
     * Phần chạy SQL của lines(), tách ra để cache được (mảng thuần).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLinesPayload(string $id, object $doc): array
    {
        $lines = DB::table('reversal_lines as rl')
            ->leftJoin('stock_movements as mv', 'mv.id', '=', 'rl.original_movement_id')
            ->leftJoin('sku', 'sku.sku_id', '=', 'rl.sku_id')
            ->leftJoin('item_instances as it', 'it.item_id', '=', 'rl.item_id')
            ->where('rl.reversal_document_id', $id)
            ->orderBy('rl.line_no')
            ->get([
                'rl.id',
                'rl.reversal_document_id',
                'rl.line_no',
                'rl.original_movement_id',
                'rl.sku_id',
                'rl.item_id',
                'rl.quantity_to_reverse',
                'rl.stock_status',
                'rl.return_condition',
                'rl.downstream_check_result',
                'rl.posted_movement_id',
                'rl.reason',
                'rl.version',
                'rl.created_at',
                'mv.movement_no',
                'mv.movement_type',
                'mv.quantity as original_movement_quantity',
                'mv.qty_delta as original_movement_qty_delta',
                'sku.sku_code',
                'sku.sku_name',
                'sku.unit',
                'it.item_code',
                'it.serial_number',
            ]);

        $reversedByMovement = $this->postedReversedQtyByMovement(
            $lines->pluck('original_movement_id')->filter()->unique()->all()
        );

        $docStatus = strtoupper((string) $doc->status);

        return $lines->map(function (object $line) use ($reversedByMovement, $docStatus): array {
            $originalQty = (int) abs((int) ($line->original_movement_quantity ?? $line->original_movement_qty_delta ?? 0));
            $reversalQty = (int) $line->quantity_to_reverse;
            $reversedQty = (int) ($reversedByMovement[(string) $line->original_movement_id] ?? 0);

            return [
                'id' => $line->id,
                'reversal_document_id' => $line->reversal_document_id,
                'reversal_id' => $line->reversal_document_id,
                'line_no' => (int) $line->line_no,
                'original_movement_id' => $line->original_movement_id,
                'original_movement_code' => $line->movement_no,
                'original_movement_type' => $line->movement_type,
                'sku' => $line->sku_code ?? '',
                'sku_name' => $line->sku_name ?? '-',
                'uom' => $line->unit ?? '',
                'item_id' => $line->item_id,
                'item_code' => $line->item_code,
                'serial_number' => $line->serial_number,
                'original_qty' => $originalQty,
                'reversal_qty' => $reversalQty,
                'reversed_qty' => $reversedQty,
                'remaining_qty' => max(0, $originalQty - $reversedQty),
                'return_condition' => $line->return_condition,
                'stock_status' => $line->stock_status,
                'dependency_check' => $line->downstream_check_result,
                'posted_movement_id' => $line->posted_movement_id,
                // §6: line chỉ "POSTED" khi đã sinh movement đối ứng; còn lại theo header.
                'status' => $line->posted_movement_id !== null ? 'POSTED' : $docStatus,
                'reason' => $line->reason,
                'version' => (int) $line->version,
                'created_at' => $line->created_at,
            ];
        })->all();
    }

    /**
     * BR-REV-05: tổng lượng đã đảo (chỉ tính reversal đã POSTED) theo movement gốc.
     *
     * @param  list<string>  $movementIds
     * @return array<string, int>
     */
    private function postedReversedQtyByMovement(array $movementIds): array
    {
        if ($movementIds === []) {
            return [];
        }

        $rows = DB::table('reversal_lines as rl')
            ->join('reversal_documents as rd', 'rd.id', '=', 'rl.reversal_document_id')
            ->whereIn('rl.original_movement_id', $movementIds)
            ->where('rd.status', 'POSTED')
            ->groupBy('rl.original_movement_id')
            ->select('rl.original_movement_id', DB::raw('COALESCE(SUM(rl.quantity_to_reverse), 0) AS total_reversed'))
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->original_movement_id] = (int) $row->total_reversed;
        }

        return $map;
    }

    #[OA\Post(
        path: '/api/v1/reversal-documents',
        operationId: 'createReversalDocument',
        summary: 'Tạo Reversal document từ chứng từ gốc (RECEIPT/ISSUE/COMPONENT_ISSUE)',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reference_document_id', 'reference_document_type', 'reason', 'lines'],
                properties: [
                    new OA\Property(property: 'reversal_type', type: 'string', nullable: true, example: 'issue'),
                    new OA\Property(property: 'reference_document_id', type: 'string', example: '1'),
                    new OA\Property(property: 'reference_document_type', type: 'string', example: 'ISSUE'),
                    new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 2000, example: 'Hoàn trả do nhập sai số lượng'),
                    new OA\Property(property: 'return_condition', type: 'string', enum: ['SELLABLE', 'DEFECTIVE', 'NON_SELLABLE'], nullable: true, description: 'BR-REV-06/07: chỉ áp dụng cho Reverse Issue/Component Issue.', example: 'SELLABLE'),
                    new OA\Property(property: 'physical_return_confirmed', type: 'boolean', nullable: true, description: 'BR-REV-06: chỉ cộng tồn khi xác nhận tường minh hàng vật lý đã về.', example: true),
                    new OA\Property(property: 'lines', type: 'array', minItems: 1, items: new OA\Items(
                        required: ['sku', 'reversal_qty'],
                        properties: [
                            new OA\Property(property: 'sku', type: 'string', example: 'SKU-000123'),
                            new OA\Property(property: 'reversal_qty', type: 'integer', minimum: 1, maximum: InputLimits::QUANTITY_MAX, example: 10),
                            new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true, example: 'b6f2a1d4-3c9e-4a2b-8f1d-7e6c5b4a3210'),
                            new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Hoàn trả do nhập sai số lượng'),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Reversal document được tạo; header Idempotent-Replay cho biết replay.'),
        ]
    )]
    public function store(Request $request)
    {
        $validated = $request->validate([
            'reversal_type' => ['nullable', 'string', 'max:50'],
            'reference_document_id' => ['required', 'string', 'max:100'],
            'reference_document_type' => ['required', 'string', 'max:50'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            // BR-REV-06/07: Reverse Issue phải khai tình trạng hàng quay về;
            // chỉ SELLABLE mới cộng available_qty. Mặc định giữ hành vi cũ.
            'return_condition' => ['nullable', 'in:SELLABLE,DEFECTIVE,NON_SELLABLE'],
            'physical_return_confirmed' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.sku' => ['required', 'string', 'max:100'],
            'lines.*.reversal_qty' => ['required', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
            'lines.*.item_id' => ['nullable', 'uuid'],
            'lines.*.reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $idempotencyKey = IdempotencyKeyGuard::require($request);

        // BR-REV-02 + §8.2: thiếu If-Match phải trả 428 PRECONDITION_REQUIRED,
        // không được ngầm giả định version=1 (bypass optimistic lock).
        if (! $request->hasHeader('If-Match')) {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Reversal bắt buộc header If-Match theo version chứng từ gốc.');
        }
        $raw = preg_replace('/^W\//', '', trim((string) $request->header('If-Match'))) ?? '';
        $expectedVersion = (int) trim($raw, '" ');
        if ($expectedVersion < 1) {
            ApiError::abort(400, 'INVALID_HEADER', 'If-Match phải là số nguyên dương.');
        }

        $refId = $validated['reference_document_id'];

        // DEC-REV-COMPONENT-001 (BE266-D03): phạm vi reversal theo ERD bảng 33
        // original_document_type INBOUND/OUTBOUND/COMPONENT_ISSUE — thêm movement
        // COMPONENT_ISSUE vào tập nguồn; movement REVERSAL vẫn ngoài tập (BR-REV-08).
        $movements = DB::table('stock_movements')
            ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
            ->where(function ($q) use ($refId) {
                $q->where('stock_movements.stock_document_id', $refId)
                    ->orWhere('stock_movements.source_id', $refId)
                    ->orWhere('stock_movements.id', $refId);
            })
            ->whereIn('stock_movements.movement_type', ['RECEIPT', 'ISSUE', 'COMPONENT_ISSUE'])
            ->select('stock_movements.*', 'products.code as product_code')
            ->get();

        if ($movements->isEmpty()) {
            ApiError::abort(404, 'MOVEMENT_NOT_FOUND', 'Không tìm thấy movement cho reference document.');
        }

        $reversalLines = [];
        $totalQty = 0;
        foreach ($validated['lines'] as $line) {
            $mv = $movements->first(fn ($m) => $m->product_code === $line['sku']);
            if (! $mv) {
                ApiError::abort(422, 'SKU_NOT_FOUND', "Không tìm thấy movement cho SKU '{$line['sku']}'.");
            }
            $reversalLines[] = [
                'movement_id' => $mv->id,
                'quantity' => $line['reversal_qty'],
                'item_id' => $line['item_id'] ?? null,
            ];
            $totalQty += $line['reversal_qty'];
        }

        $firstMv = $movements->first();
        $type = match ($firstMv->movement_type) {
            'RECEIPT' => 'receipt',
            'COMPONENT_ISSUE' => 'component_issue',
            default => 'issue',
        };
        // Movement COMPONENT_ISSUE không thuộc stock_documents; chứng từ gốc là
        // component_issue_documents nằm ở source_id (postComponentIssue ghi
        // source_type=COMPONENT_ISSUE_DOCUMENT).
        $stockDocId = $type === 'component_issue' ? $firstMv->source_id : $firstMv->stock_document_id;

        if (! $stockDocId) {
            ApiError::abort(422, 'NO_STOCK_DOCUMENT', 'Movement không liên kết chứng từ gốc.');
        }

        $servicePayload = [
            'reason' => $validated['reason'],
            'reason_code' => 'CORRECTION',
            'lines' => $reversalLines,
        ];
        if ($type === 'issue' || $type === 'component_issue') {
            // BR-REV-06: chỉ cộng tồn khi client xác nhận tường minh hàng vật lý
            // đã về — default true sẽ cộng tồn cho hàng chưa quay lại kho.
            // Component Issue cùng chiều stock effect với Issue nên chịu cùng guard.
            $servicePayload['condition_status'] = strtoupper((string) ($validated['return_condition'] ?? 'SELLABLE'));
            $servicePayload['physical_return_confirmed'] = (bool) ($validated['physical_return_confirmed'] ?? false);
        }

        $actorId = $this->actorId();
        $hash = IdempotencyStore::requestHash($request);
        $operation = 'create_reversal_'.$stockDocId;
        $lineCount = count($reversalLines);

        [$code, $body, $replay] = IdempotencyStore::handle(
            $idempotencyKey,
            $operation,
            $actorId,
            $hash,
            function () use ($stockDocId, $type, $servicePayload, $actorId, $idempotencyKey, $hash, $expectedVersion, $totalQty, $lineCount): array {
                $result = $this->reversalService->execute(
                    $stockDocId, $type, $servicePayload, $actorId, $idempotencyKey, $hash, $expectedVersion
                );
                // Bump NẰM TRONG closure của IdempotencyStore: lần replay không thực
                // thi lại nghiệp vụ nên cũng không được xoá cache vô ích.
                $this->invalidateReadCache();

                $reversalId = $result['reversal_document_id'];
                $doc = DB::table('reversal_documents')->where('id', $reversalId)->first();

                $response = [
                    'id' => $reversalId,
                    'reversal_code' => $doc?->reversal_no ?? $reversalId,
                    'status' => $result['reversal_status'] ?? 'POSTED',
                    'total_qty' => $totalQty,
                    'reversed_lines' => $lineCount,
                ];

                return [201, [
                    'success' => true,
                    'message' => 'Tạo chứng từ điều chỉnh thành công.',
                    'data' => $response,
                    'meta' => [
                        'request_id' => request()?->attributes->get('request_id'),
                        'timestamp' => now()->toIso8601String(),
                    ],
                ]];
            }
        );

        return response()->json($body, $code)
            ->header('Idempotent-Replay', $replay ? 'true' : 'false');
    }

    /**
     * WWHN-242 · warehouse data scope cho danh sách reversal.
     *
     * `null` = không giới hạn (super admin hoặc role gán scope rỗng theo
     * `User::warehouseScopeIds()`); `[]` = có giới hạn nhưng không kho nào ⇒ fail-closed
     * về tập rỗng, không phải mở toàn bộ. `EnforceReversalWarehouseScope` chặn ở tầng
     * route cho các thao tác theo id; hàm này là lớp thứ hai cho danh sách.
     */
    private function applyWarehouseScope(Builder $query): void
    {
        $user = auth('api')->user();
        if (! $user instanceof User) {
            return;
        }

        $warehouseIds = $user->warehouseScopeIds();
        if ($warehouseIds === null) {
            return;
        }

        if ($warehouseIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('reversal_documents.warehouse_id', $warehouseIds);
    }
}
