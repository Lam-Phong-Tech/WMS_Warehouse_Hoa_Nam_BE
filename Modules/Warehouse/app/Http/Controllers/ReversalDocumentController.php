<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Services\CurrentBaselineReversalService;

class ReversalDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CurrentBaselineReversalService $reversalService) {}

    public function index(Request $request)
    {
        $query = DB::table('reversal_documents')
            ->leftJoin('warehouses', 'reversal_documents.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('stock_documents', 'reversal_documents.original_document_id', '=', 'stock_documents.id')
            ->leftJoin('app_user', 'reversal_documents.created_by', '=', 'app_user.user_id')
            ->select([
                'reversal_documents.id',
                'reversal_documents.reversal_no as reversal_code',
                'reversal_documents.original_document_type as reference_document_type',
                'reversal_documents.original_document_id as reference_document_id',
                'stock_documents.doc_no as reference_document_code',
                'reversal_documents.status',
                'reversal_documents.reason_code',
                'reversal_documents.reason_text as reason',
                'reversal_documents.warehouse_id',
                'reversal_documents.correction_chain_id',
                'reversal_documents.posted_at as reversed_at',
                'reversal_documents.posted_by',
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

        if ($request->filled('status')) {
            $query->where('reversal_documents.status', $request->input('status'));
        }
        if ($request->filled('keyword')) {
            $keyword = '%'.mb_strtolower((string) $request->input('keyword')).'%';
            // sqlite không có ILIKE; hạ chữ hai vế để cùng ngữ nghĩa trên mọi driver.
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(CAST(reversal_documents.reversal_no AS TEXT)) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(CAST(reversal_documents.reason_text AS TEXT)) LIKE ?', [$keyword]);
            });
        }

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    public function show(string $id)
    {
        $doc = DB::table('reversal_documents')
            ->leftJoin('warehouses', 'reversal_documents.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('stock_documents', 'reversal_documents.original_document_id', '=', 'stock_documents.id')
            ->leftJoin('app_user', 'reversal_documents.created_by', '=', 'app_user.user_id')
            ->select([
                'reversal_documents.id',
                'reversal_documents.reversal_no as reversal_code',
                'reversal_documents.original_document_type as reference_document_type',
                'reversal_documents.original_document_id as reference_document_id',
                'stock_documents.doc_no as reference_document_code',
                'reversal_documents.status',
                'reversal_documents.reason_code',
                'reversal_documents.reason_text as reason',
                'reversal_documents.warehouse_id',
                'reversal_documents.correction_chain_id',
                'reversal_documents.posted_at as reversed_at',
                'reversal_documents.posted_by',
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

        if (! $doc) {
            return $this->respondNotFound('Không tìm thấy reversal document.');
        }

        return $this->respondSuccess($doc);
    }

    /**
     * SRS §6 Reversal state model + Annex C. Ba bước tách bạch:
     * confirm (xác nhận hàng vật lý về, stock-neutral) → post (điểm duy nhất sinh
     * movement) → hoặc cancel khi còn DRAFT. BR-REV-02 bắt buộc If-Match;
     * BR-REV-12 bắt buộc Idempotency-Key ở bước Post.
     */
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

        return $this->respondSuccess($result, 'Đã xác nhận hàng vật lý quay lại kho; chưa phát sinh stock effect.');
    }

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
    public function lines(string $id)
    {
        $doc = DB::table('reversal_documents')->where('id', $id)->first();
        if (! $doc) {
            return $this->respondNotFound('Không tìm thấy reversal document.');
        }

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

        return $this->respondSuccess($lines->map(function (object $line) use ($reversedByMovement, $docStatus): array {
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
        })->all());
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'reversal_type' => ['nullable', 'string'],
            'reference_document_id' => ['required', 'string'],
            'reference_document_type' => ['required', 'string'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            // BR-REV-06/07: Reverse Issue phải khai tình trạng hàng quay về;
            // chỉ SELLABLE mới cộng available_qty. Mặc định giữ hành vi cũ.
            'return_condition' => ['nullable', 'in:SELLABLE,DEFECTIVE,NON_SELLABLE'],
            'physical_return_confirmed' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string'],
            'lines.*.reversal_qty' => ['required', 'integer', 'min:1'],
            'lines.*.item_id' => ['nullable', 'uuid'],
            'lines.*.reason' => ['nullable', 'string'],
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

        $movements = DB::table('stock_movements')
            ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
            ->where(function ($q) use ($refId) {
                $q->where('stock_movements.stock_document_id', $refId)
                    ->orWhere('stock_movements.source_id', $refId)
                    ->orWhere('stock_movements.id', $refId);
            })
            ->whereIn('stock_movements.movement_type', ['RECEIPT', 'ISSUE'])
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
        $type = $firstMv->movement_type === 'RECEIPT' ? 'receipt' : 'issue';
        $stockDocId = $firstMv->stock_document_id;

        if (! $stockDocId) {
            ApiError::abort(422, 'NO_STOCK_DOCUMENT', 'Movement không liên kết stock document.');
        }

        $servicePayload = [
            'reason' => $validated['reason'],
            'reason_code' => 'CORRECTION',
            'lines' => $reversalLines,
        ];
        if ($type === 'issue') {
            // BR-REV-06: chỉ cộng tồn khi client xác nhận tường minh hàng vật lý
            // đã về — default true sẽ cộng tồn cho hàng chưa quay lại kho.
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
                    'message' => 'Reversal document created.',
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

    private function actorId(): string
    {
        $id = auth('api')->id();
        if ($id === null) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Authentication required.');
        }

        return (string) $id;
    }
}
