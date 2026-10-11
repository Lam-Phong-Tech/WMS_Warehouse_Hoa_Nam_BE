<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Support\IdempotencyKeyGuard;
use App\Support\IdempotencyStore;
use App\Support\IdempotentEnvelope;
use App\Support\Validation\InputLimits;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Warehouse\Services\ComponentContainerSubledgerService;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Support\Concerns\ResolvesActor;
use OpenApi\Attributes as OA;

class CurrentBaselineComponentFlowController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;
    use ResolvesActor;

    /**
     * Nghiệp vụ 04: chỉ hồ sơ "Đang kiểm tra" (CHECKING) và "Đang sửa chữa"
     * (REPAIRING) mới được xuất linh kiện.
     *
     * Guard cũ chỉ loại RETURNED/CANCELLED nên `RECEIVED` (vừa tiếp nhận, chưa ai
     * kiểm tra) và `COMPLETED` (đã sửa xong, chỉ chờ trả khách) vẫn xuất được linh
     * kiện — vừa lệch quy trình, vừa thổi phồng "linh kiện dùng cho case đang xử lý"
     * của RPT-WAR-COMP-01. Kiểm ở CẢ store/scan/lines/post vì case có thể đổi trạng
     * thái giữa lúc quét và lúc Post.
     */
    public const CASE_STATUSES_ALLOWING_ISSUE = ['CHECKING', 'REPAIRING'];

    /** §6: hai trạng thái đóng — giữ nguyên mã lỗi cũ để client đang bắt không vỡ. */
    private const CASE_CLOSED_STATUSES = ['RETURNED', 'CANCELLED'];

    public function __construct(
        private readonly CurrentBaselineStockService $stock,
        private readonly ComponentContainerSubledgerService $containers,
    ) {}

    /**
     * Tạo/scan/huỷ Component Issue là STOCK-NEUTRAL: không đi qua mutateBalance() nên
     * không ai bump 'stock:version' hộ, trong khi read cache của Component Issue
     * (danh sách/chi tiết, scanned_qty, status) đọc đúng những cột vừa đổi. Trong
     * transaction thì afterCommit() hoãn tới commit thật.
     */
    private function invalidateReadCache(): void
    {
        DB::afterCommit(fn () => $this->bumpReadCache('stock:version'));
    }

    /**
     * Cửa duy nhất kiểm trạng thái hồ sơ bảo hành cho luồng xuất linh kiện.
     *
     * Tách hai mã lỗi vì hướng xử lý khác hẳn nhau: case đã đóng thì không làm gì
     * được nữa, còn case "chưa tới lượt" (RECEIVED) chỉ cần chuyển sang Đang kiểm
     * tra rồi quét lại — client cần phân biệt để chỉ đúng việc cho người quét.
     *
     * @param  string  $phase  Mô tả bước đang chạy, ghép thẳng vào thông điệp lỗi.
     */
    private function assertCaseAllowsComponentIssue(?object $case, string $phase): void
    {
        if ($case === null) {
            ApiError::abort(422, 'WARRANTY_CASE_NOT_ACTIVE', 'Không tìm thấy hồ sơ bảo hành của phiếu xuất linh kiện.');
        }

        $status = strtoupper((string) $case->status);
        if (in_array($status, self::CASE_STATUSES_ALLOWING_ISSUE, true)) {
            return;
        }

        if (in_array($status, self::CASE_CLOSED_STATUSES, true)) {
            ApiError::abort(422, 'WARRANTY_CASE_NOT_ACTIVE', "Hồ sơ bảo hành đã đóng nên không xuất linh kiện được {$phase}.", [
                'warranty_case_id' => (string) $case->warranty_case_id,
                'warranty_case_status' => $status,
                'allowed_statuses' => self::CASE_STATUSES_ALLOWING_ISSUE,
            ]);
        }

        ApiError::abort(422, 'WARRANTY_CASE_STATUS_NOT_ALLOWED', "Chỉ hồ sơ bảo hành đang kiểm tra hoặc đang sửa chữa mới được xuất linh kiện {$phase}.", [
            'warranty_case_id' => (string) $case->warranty_case_id,
            'warranty_case_status' => $status,
            'allowed_statuses' => self::CASE_STATUSES_ALLOWING_ISSUE,
        ]);
    }

    /**
     * Khoá hàng hồ sơ bảo hành của phiếu rồi kiểm trạng thái. Dùng chung cho mọi
     * mutation sau bước tạo phiếu (scan/unscan/lines/post).
     */
    private function assertDocumentCaseAllowsIssue(object $document, string $phase): void
    {
        $case = DB::table('warranty_case')
            ->where('warranty_case_id', $document->warranty_case_id)
            ->lockForUpdate()
            ->first();

        $this->assertCaseAllowsComponentIssue($case, $phase);
    }

    #[OA\Post(
        path: '/api/v1/component-issue-documents',
        operationId: 'createComponentIssue',
        summary: 'Tạo phiếu xuất linh kiện bảo hành (DRAFT, stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['warehouse_id', 'warranty_case_id', 'lines'],
                properties: [
                    new OA\Property(property: 'component_issue_no', type: 'string', maxLength: 80, nullable: true, description: 'Bỏ trống để hệ thống tự sinh mã phiếu.', example: 'CID-20260821-0001'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'warranty_case_id', type: 'string', format: 'uuid', example: '9c6b1a2e-3f4d-4b7a-8c1e-2d3f4a5b6c7d'),
                    new OA\Property(property: 'document_date', type: 'string', format: 'date', nullable: true, description: 'Thiếu thì lấy ngày lập phiếu = hôm nay.', example: '2026-08-21'),
                    new OA\Property(property: 'purpose', type: 'string', enum: ['WARRANTY'], nullable: true, example: 'WARRANTY'),
                    new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true, example: 'Xuất linh kiện thay thế theo phiếu bảo hành'),
                    new OA\Property(property: 'lines', type: 'array', minItems: 1, items: new OA\Items(
                        required: ['sku_id', 'required_qty'],
                        properties: [
                            new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', description: 'Chỉ nhận SKU COMPONENT ACTIVE.', example: '4b6f1c2a-8e3d-4f5a-9b2c-1d3e4f5a6b7c'),
                            new OA\Property(property: 'required_qty', type: 'integer', minimum: 1, maximum: InputLimits::QUANTITY_MAX, example: 2),
                            new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true, example: 'Thay bo mạch nguồn'),
                        ]
                    )),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Component Issue DRAFT được tạo; stock_effect=NONE; ETag = version.'),
        ]
    )]
    public function store(Request $request)
    {
        $validated = $request->validate([
            'component_issue_no' => ['nullable', 'string', 'max:80', 'unique:component_issue_documents,component_issue_no'],
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'warranty_case_id' => ['required', 'uuid', 'exists:warranty_case,warranty_case_id'],
            // ERD §48 không có document_date — cột chỉ tồn tại ở runtime legacy nên
            // không được bắt client gửi; thiếu thì lấy ngày lập phiếu = hôm nay.
            'document_date' => array_merge(['nullable'], InputLimits::pastDate()),
            'purpose' => ['nullable', 'in:WARRANTY'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.sku_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'lines.*.required_qty' => ['required', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
            'lines.*.note' => ['nullable', 'string', 'max:2000'],
        ]);

        $id = DB::transaction(function () use ($validated, $request): string {
            $warehouse = DB::table('warehouses')->where('id', $validated['warehouse_id'])->lockForUpdate()->first();
            if ($warehouse === null || strtoupper((string) $warehouse->status) !== 'ACTIVE') {
                ApiError::abort(422, 'WAREHOUSE_NOT_ACTIVE', 'Component Issue yêu cầu warehouse ACTIVE.');
            }
            $case = DB::table('warranty_case')->where('warranty_case_id', $validated['warranty_case_id'])->lockForUpdate()->first();
            $this->assertCaseAllowsComponentIssue($case, 'khi tạo phiếu');

            $documentId = (string) Str::uuid();
            DB::table('component_issue_documents')->insert([
                'id' => $documentId,
                'component_issue_no' => $validated['component_issue_no'] ?? ('CID-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4))),
                'warehouse_id' => $validated['warehouse_id'],
                'warranty_case_id' => $validated['warranty_case_id'],
                'document_date' => $validated['document_date'] ?? now()->toDateString(),
                'purpose' => 'WARRANTY',
                'status' => 'DRAFT',
                'note' => $validated['note'] ?? null,
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
                'version' => 1,
            ]);

            foreach (array_values($validated['lines']) as $index => $line) {
                $sku = DB::table('sku')->where('sku_id', $line['sku_id'])->first();
                if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE' || strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
                    ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Component Issue chỉ nhận SKU COMPONENT ACTIVE.');
                }
                DB::table('component_issue_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'component_issue_document_id' => $documentId,
                    'line_no' => $index + 1,
                    'sku_id' => $line['sku_id'],
                    'required_qty' => (int) $line['required_qty'],
                    'scanned_qty' => 0,
                    'note' => $line['note'] ?? null,
                    'created_at' => now(),
                    'created_by' => $this->actorId(),
                    'updated_at' => now(),
                    'updated_by' => $this->actorId(),
                    'version' => 1,
                ]);
            }

            AuditLogger::record(
                action: 'CREATE_COMPONENT_ISSUE',
                objectType: 'component_issue_document',
                objectId: $documentId,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                afterData: ['status' => 'DRAFT', 'purpose' => 'WARRANTY', 'version' => 1],
            );
            $this->invalidateReadCache();

            return $documentId;
        }, 3);

        return $this->respondCreated($this->payload($id), 'Đã tạo phiếu xuất linh kiện (DRAFT); tồn kho chưa thay đổi (stock_effect=NONE).')
            ->header('ETag', '"1"');
    }

    #[OA\Post(
        path: '/api/v1/component-issue-documents/{id}/scan',
        operationId: 'scanComponentIssue',
        summary: 'Quét linh kiện cho phiếu xuất bảo hành (stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['line_id', 'quantity'],
                properties: [
                    new OA\Property(property: 'line_id', type: 'string', format: 'uuid', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'code_value', type: 'string', maxLength: 200, nullable: true, description: 'Barcode/QR/RFID; resolve về đúng một item hoặc BOX container.', example: 'HN1|DCH-DRILL-DJZ10A-220V-V1|IT000123'),
                    new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true, example: '9c6b1a2e-3f4d-4b7a-8c1e-2d3f4a5b6c7d'),
                    new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true, example: '1b2c3d4e-5f6a-4b7c-8d9e-0f1a2b3c4d5e'),
                    new OA\Property(property: 'quantity', type: 'integer', minimum: 1, maximum: InputLimits::QUANTITY_MAX, description: 'Serial component bắt buộc quantity = 1.', example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Scan hợp lệ; stock_effect=NONE; ETag = version mới.'),
        ]
    )]
    public function scan(Request $request, string $id)
    {
        $validated = $request->validate([
            'line_id' => ['required', 'uuid'],
            'code_value' => ['nullable', 'string', 'max:200'],
            'item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
        ]);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $validated, $expectedVersion): array {
            $document = DB::table('component_issue_documents')->where('id', $id)->lockForUpdate()->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
            }
            $this->assertVersion($document, $expectedVersion);
            if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được scan Component Issue.');
            }
            $this->assertDocumentCaseAllowsIssue($document, 'khi quét linh kiện');

            $line = DB::table('component_issue_lines')->where('id', $validated['line_id'])->where('component_issue_document_id', $id)->lockForUpdate()->first();
            if ($line === null) {
                ApiError::abort(404, 'COMPONENT_LINE_NOT_FOUND', 'Không tìm thấy Component Issue line.');
            }
            $sku = DB::table('sku')->where('sku_id', $line->sku_id)->first();
            if ($sku === null || strtoupper((string) $sku->sku_type) !== 'COMPONENT' || strtoupper((string) $sku->status) !== 'ACTIVE') {
                ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Line phải là COMPONENT ACTIVE.');
            }

            [$itemId, $containerId, $physicalCodeId] = $this->resolveTarget($validated);
            if (($itemId !== null) === ($containerId !== null)) {
                ApiError::abort(422, 'COMPONENT_TARGET_XOR', 'Mỗi scan phải resolve đúng một item hoặc BOX container.');
            }

            $quantity = (int) $validated['quantity'];
            $after = (int) $line->scanned_qty + $quantity;
            if ($after > (int) $line->required_qty) {
                ApiError::abort(422, 'SCAN_EXCEEDS_REQUIRED', 'Component scan vượt required_qty.');
            }

            if ($itemId !== null) {
                if ($quantity !== 1) {
                    ApiError::abort(422, 'SERIAL_QUANTITY_MUST_BE_ONE', 'Serial component quantity phải = 1.');
                }
                $item = DB::table('item_instances')->where('item_id', $itemId)->lockForUpdate()->first();
                if ($item === null || (string) $item->sku_id !== (string) $line->sku_id || $item->item_status !== 'IN_STOCK') {
                    ApiError::abort(422, 'COMPONENT_ITEM_NOT_AVAILABLE', 'Component item sai SKU hoặc không IN_STOCK.');
                }
                // Global, không chỉ trong phiếu hiện tại: unique index DB
                // `uk_component_issue_active_item` cấm 1 item ACTIVE ở HAI
                // Component Issue cùng lúc (item vật lý không thể ở 2 nơi).
                // Trước đây chỉ check trong phiếu hiện tại nên lọt xuống DB,
                // vi phạm unique constraint và hiện lỗi kỹ thuật khó hiểu
                // "dữ liệu ghi bởi thao tác khác" thay vì lý do nghiệp vụ rõ.
                $activeElsewhere = DB::table('component_issue_items as ci')
                    ->join('component_issue_lines as cl', 'cl.id', '=', 'ci.component_issue_line_id')
                    ->join('component_issue_documents as cid', 'cid.id', '=', 'cl.component_issue_document_id')
                    ->where('ci.item_id', $itemId)
                    ->where('ci.status', 'ACTIVE')
                    ->select('cid.id as document_id', 'cid.component_issue_no')
                    ->first();
                if ($activeElsewhere !== null) {
                    if ((string) $activeElsewhere->document_id === (string) $id) {
                        ApiError::abort(409, 'DUPLICATE_COMPONENT_ITEM', 'Item đã được scan ACTIVE trong Component Issue này.');
                    }
                    ApiError::abort(
                        422,
                        'COMPONENT_ITEM_ACTIVE_ELSEWHERE',
                        "Linh kiện này đang được quét ở phiếu xuất linh kiện khác ({$activeElsewhere->component_issue_no}) và chưa hủy/hoàn tất. Hủy dòng quét đó hoặc xử lý xong phiếu kia trước khi quét vào phiếu này."
                    );
                }
            } else {
                $this->containers->assertContainerAvailable((string) $containerId, (string) $line->sku_id, $quantity);
            }

            $scanEventId = $this->recordScanEvent($document, $physicalCodeId, $itemId, $containerId, $validated['code_value'] ?? null);
            $scanEntryId = (string) Str::uuid();
            DB::table('component_issue_items')->insert([
                'id' => $scanEntryId,
                'component_issue_line_id' => $line->id,
                'scan_event_id' => $scanEventId,
                'item_id' => $itemId,
                'container_id' => $containerId,
                'quantity' => $quantity,
                'status' => 'ACTIVE',
                'selected_at' => now(),
                'selected_by' => $this->actorId(),
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
                'version' => 1,
            ]);
            DB::table('component_issue_lines')->where('id', $line->id)->update([
                'scanned_qty' => $after,
                'version' => ((int) $line->version) + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            DB::table('component_issue_documents')->where('id', $id)->update([
                'status' => 'SCANNING',
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            $this->invalidateReadCache();

            return [
                'component_issue_document_id' => $id,
                'line_id' => (string) $line->id,
                'scan_entry_id' => $scanEntryId,
                'scan_event_id' => $scanEventId,
                'item_id' => $itemId,
                'container_id' => $containerId,
                'required_qty' => (int) $line->required_qty,
                'scanned_qty' => $after,
                'status' => 'SCANNING',
                'version' => $expectedVersion + 1,
                'stock_effect' => 'NONE',
            ];
        }, 3);

        return $this->respondSuccess($result, 'Đã ghi nhận quét linh kiện hợp lệ; tồn kho chưa thay đổi (stock_effect=NONE).')
            ->header('ETag', '"'.$result['version'].'"');
    }

    #[OA\Post(
        path: '/api/v1/component-issue-documents/{id}/unscan',
        operationId: 'unscanComponentIssue',
        summary: 'Gỡ một dòng linh kiện đã quét khỏi phiếu xuất bảo hành (stock-neutral)',
        description: 'Quét nhầm thì gỡ đúng dòng đó thay vì phải huỷ cả phiếu. Chỉ tác động lên bằng chứng quét: dòng chuyển REMOVED, scanned_qty của line giảm tương ứng, tồn kho không đổi. Dòng quét theo HỘP bị gỡ nguyên dòng (muốn sửa số lượng thì gỡ rồi quét lại với số đúng).',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"2"')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'component_issue_item_id', type: 'string', format: 'uuid', nullable: true, description: 'Id dòng quét lấy từ `lines[].items[].id` của bề mặt chi tiết — cách chỉ định chính xác nhất.', example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                    new OA\Property(property: 'line_id', type: 'string', format: 'uuid', nullable: true, description: 'Thu hẹp phạm vi tìm khi gỡ theo mã quét.'),
                    new OA\Property(property: 'code_value', type: 'string', maxLength: 200, nullable: true, description: 'Quét lại đúng mã cần gỡ; gỡ dòng ACTIVE mới nhất khớp mã đó.', example: 'BOX-HN-COMP-BASE-003-0001'),
                    new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'reason', type: 'string', maxLength: 2000, nullable: true, example: 'Quét nhầm linh kiện'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã gỡ dòng quét; stock_effect=NONE; ETag = version mới.'),
            new OA\Response(response: 404, description: 'RESOURCE_NOT_FOUND hoặc COMPONENT_SCAN_NOT_FOUND'),
            new OA\Response(response: 409, description: 'Chỉ DRAFT/SCANNING mới được gỡ dòng quét'),
        ]
    )]
    public function unscan(Request $request, string $id)
    {
        $validated = $request->validate([
            'component_issue_item_id' => ['nullable', 'uuid'],
            'line_id' => ['nullable', 'uuid'],
            'code_value' => ['nullable', 'string', 'max:200'],
            'item_id' => ['nullable', 'uuid', 'exists:item_instances,item_id'],
            'container_id' => ['nullable', 'uuid', 'exists:containers,container_id'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $validated, $expectedVersion, $request): array {
            $document = $this->lockMutableDocument($id, $expectedVersion, 'gỡ dòng quét', 'khi gỡ dòng quét');
            $row = $this->resolveActiveScanRow($id, $validated);

            DB::table('component_issue_items')->where('id', $row->id)->update([
                'status' => 'REMOVED',
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);

            // max(0, ...) là phòng vệ: scanned_qty được cập nhật cùng transaction với
            // mọi lần scan nên về lý không thể âm, nhưng cột này còn được đọc bởi
            // guard FULL_POST_REQUIRED — âm một lần là phiếu không bao giờ Post được.
            $after = max(0, (int) $row->scanned_qty - (int) $row->quantity);
            DB::table('component_issue_lines')->where('id', $row->line_id)->update([
                'scanned_qty' => $after,
                'version' => ((int) $row->line_version) + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);

            // Không còn dòng quét nào ACTIVE thì phiếu quay về DRAFT — đúng ngữ nghĩa
            // "chưa quét gì", và là trạng thái mà postComponentIssue() yêu cầu.
            $stillScanning = DB::table('component_issue_items as ci')
                ->join('component_issue_lines as cl', 'cl.id', '=', 'ci.component_issue_line_id')
                ->where('cl.component_issue_document_id', $id)
                ->where('ci.status', 'ACTIVE')
                ->exists();
            $status = $stillScanning ? 'SCANNING' : 'DRAFT';

            DB::table('component_issue_documents')->where('id', $id)->update([
                'status' => $status,
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);

            AuditLogger::record(
                action: 'UNSCAN_COMPONENT_ISSUE',
                objectType: 'component_issue_document',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: $request->attributes->get('request_id'),
                reason: $validated['reason'] ?? null,
                beforeData: ['status' => (string) $document->status, 'scanned_qty' => (int) $row->scanned_qty, 'version' => $expectedVersion],
                afterData: ['status' => $status, 'scanned_qty' => $after, 'version' => $expectedVersion + 1],
            );
            $this->invalidateReadCache();

            return [
                'component_issue_document_id' => $id,
                'component_issue_item_id' => (string) $row->id,
                'line_id' => (string) $row->line_id,
                'item_id' => $row->item_id !== null ? (string) $row->item_id : null,
                'container_id' => $row->container_id !== null ? (string) $row->container_id : null,
                'removed_qty' => (int) $row->quantity,
                'required_qty' => (int) $row->required_qty,
                'scanned_qty' => $after,
                'status' => $status,
                'version' => $expectedVersion + 1,
                'lines' => $this->linesSummary($id),
                'stock_effect' => 'NONE',
            ];
        }, 3);

        return $this->respondSuccess($result, 'Đã gỡ dòng quét khỏi phiếu xuất linh kiện; tồn kho không đổi (stock_effect=NONE).')
            ->header('ETag', '"'.$result['version'].'"');
    }

    #[OA\Post(
        path: '/api/v1/component-issue-documents/{id}/lines',
        operationId: 'addComponentIssueLine',
        summary: 'Thêm dòng linh kiện vào phiếu xuất bảo hành đang soạn (stock-neutral)',
        description: 'Phát sinh thêm linh kiện giữa chừng thì thêm dòng, không phải huỷ phiếu tạo lại.',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"1"')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sku_id', 'required_qty'],
                properties: [
                    new OA\Property(property: 'sku_id', type: 'string', format: 'uuid', description: 'Chỉ nhận SKU COMPONENT ACTIVE.'),
                    new OA\Property(property: 'required_qty', type: 'integer', minimum: 1, maximum: InputLimits::QUANTITY_MAX, example: 1),
                    new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã thêm dòng; stock_effect=NONE; ETag = version mới.'),
        ]
    )]
    public function storeLine(Request $request, string $id)
    {
        $validated = $request->validate([
            'sku_id' => ['required', 'uuid', 'exists:sku,sku_id'],
            'required_qty' => ['required', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $validated, $expectedVersion): array {
            $this->lockMutableDocument($id, $expectedVersion, 'thêm dòng', 'khi thêm dòng linh kiện');
            $this->assertComponentSku((string) $validated['sku_id']);

            $lineNo = (int) DB::table('component_issue_lines')->where('component_issue_document_id', $id)->max('line_no') + 1;
            $lineId = (string) Str::uuid();
            DB::table('component_issue_lines')->insert([
                'id' => $lineId,
                'component_issue_document_id' => $id,
                'line_no' => $lineNo,
                'sku_id' => $validated['sku_id'],
                'required_qty' => (int) $validated['required_qty'],
                'scanned_qty' => 0,
                'note' => $validated['note'] ?? null,
                'created_at' => now(),
                'created_by' => $this->actorId(),
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
                'version' => 1,
            ]);
            $this->touchDocumentVersion($id, $expectedVersion);
            $this->invalidateReadCache();

            return [
                'component_issue_document_id' => $id,
                'line_id' => $lineId,
                'line_no' => $lineNo,
                'version' => $expectedVersion + 1,
                'lines' => $this->linesSummary($id),
                'stock_effect' => 'NONE',
            ];
        }, 3);

        return $this->respondSuccess($result, 'Đã thêm dòng linh kiện vào phiếu; tồn kho chưa thay đổi (stock_effect=NONE).')
            ->header('ETag', '"'.$result['version'].'"');
    }

    #[OA\Patch(
        path: '/api/v1/component-issue-documents/{id}/lines/{lineId}',
        operationId: 'updateComponentIssueLine',
        summary: 'Sửa số lượng cần xuất của một dòng linh kiện (stock-neutral)',
        description: 'Xuất ít/nhiều hơn dự kiến thì chỉnh required_qty rồi Post, thay vì huỷ phiếu tạo lại. Không hạ được xuống dưới số đã quét — gỡ bớt dòng quét trước (POST .../unscan).',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lineId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"2"')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'required_qty', type: 'integer', minimum: 1, maximum: InputLimits::QUANTITY_MAX, nullable: true, example: 1),
                    new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã cập nhật dòng; stock_effect=NONE; ETag = version mới.'),
            new OA\Response(response: 422, description: 'REQUIRED_QTY_BELOW_SCANNED — hạ dưới số đã quét'),
        ]
    )]
    public function updateLine(Request $request, string $id, string $lineId)
    {
        $validated = $request->validate([
            'required_qty' => ['nullable', 'integer', 'min:1', 'max:'.InputLimits::QUANTITY_MAX],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! $request->has('required_qty') && ! $request->has('note')) {
            ApiError::abort(422, 'VALIDATION_ERROR', 'Phải gửi ít nhất một trong required_qty hoặc note.');
        }
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $lineId, $validated, $request, $expectedVersion): array {
            $this->lockMutableDocument($id, $expectedVersion, 'sửa dòng', 'khi sửa dòng linh kiện');
            $line = $this->lockLine($id, $lineId);

            $requiredQty = array_key_exists('required_qty', $validated) && $validated['required_qty'] !== null
                ? (int) $validated['required_qty']
                : (int) $line->required_qty;
            if ($requiredQty < (int) $line->scanned_qty) {
                ApiError::abort(422, 'REQUIRED_QTY_BELOW_SCANNED', 'Số lượng cần xuất không được nhỏ hơn số đã quét; gỡ bớt dòng quét trước.', [
                    'line_id' => $lineId,
                    'required_qty' => $requiredQty,
                    'scanned_qty' => (int) $line->scanned_qty,
                ]);
            }

            DB::table('component_issue_lines')->where('id', $lineId)->update([
                'required_qty' => $requiredQty,
                'note' => $request->has('note') ? ($validated['note'] ?? null) : $line->note,
                'version' => ((int) $line->version) + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            $this->touchDocumentVersion($id, $expectedVersion);
            $this->invalidateReadCache();

            return [
                'component_issue_document_id' => $id,
                'line_id' => $lineId,
                'required_qty' => $requiredQty,
                'scanned_qty' => (int) $line->scanned_qty,
                'version' => $expectedVersion + 1,
                'lines' => $this->linesSummary($id),
                'stock_effect' => 'NONE',
            ];
        }, 3);

        return $this->respondSuccess($result, 'Đã cập nhật dòng linh kiện; tồn kho chưa thay đổi (stock_effect=NONE).')
            ->header('ETag', '"'.$result['version'].'"');
    }

    #[OA\Delete(
        path: '/api/v1/component-issue-documents/{id}/lines/{lineId}',
        operationId: 'deleteComponentIssueLine',
        summary: 'Xoá một dòng linh kiện chưa quét khỏi phiếu (stock-neutral)',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lineId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"2"')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Đã xoá dòng; stock_effect=NONE; ETag = version mới.'),
            new OA\Response(response: 422, description: 'COMPONENT_LINE_HAS_SCANS — dòng còn bằng chứng quét'),
        ]
    )]
    public function destroyLine(Request $request, string $id, string $lineId)
    {
        $expectedVersion = $this->expectedVersion($request);

        $result = DB::transaction(function () use ($id, $lineId, $expectedVersion): array {
            $this->lockMutableDocument($id, $expectedVersion, 'xoá dòng', 'khi xoá dòng linh kiện');
            $line = $this->lockLine($id, $lineId);

            // Bằng chứng quét là dữ liệu chỉ ghi thêm (NFR-SRS-004): không xoá kèm
            // dòng quét, bắt gỡ tường minh bằng unscan trước.
            if ((int) $line->scanned_qty > 0
                || DB::table('component_issue_items')->where('component_issue_line_id', $lineId)->where('status', 'ACTIVE')->exists()) {
                ApiError::abort(422, 'COMPONENT_LINE_HAS_SCANS', 'Dòng còn bằng chứng quét; gỡ hết dòng quét (unscan) trước khi xoá.', [
                    'line_id' => $lineId,
                    'scanned_qty' => (int) $line->scanned_qty,
                ]);
            }

            DB::table('component_issue_lines')->where('id', $lineId)->delete();
            $this->touchDocumentVersion($id, $expectedVersion);
            $this->invalidateReadCache();

            return [
                'component_issue_document_id' => $id,
                'line_id' => $lineId,
                'deleted' => true,
                'version' => $expectedVersion + 1,
                'lines' => $this->linesSummary($id),
                'stock_effect' => 'NONE',
            ];
        }, 3);

        return $this->respondSuccess($result, 'Đã xoá dòng linh kiện khỏi phiếu; tồn kho chưa thay đổi (stock_effect=NONE).')
            ->header('ETag', '"'.$result['version'].'"');
    }

    #[OA\Post(
        path: '/api/v1/component-issue-documents/{id}/cancel',
        operationId: 'cancelComponentIssue',
        summary: 'Huỷ phiếu xuất linh kiện bảo hành (chỉ DRAFT/SCANNING)',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 2000, example: 'Huỷ do nhập sai warranty case'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Đã huỷ; stock_effect=NONE; ETag = version mới.'),
        ]
    )]
    public function cancel(Request $request, string $id)
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $expectedVersion = $this->expectedVersion($request);
        $result = DB::transaction(function () use ($id, $validated, $expectedVersion): array {
            $document = DB::table('component_issue_documents')->where('id', $id)->lockForUpdate()->first();
            if ($document === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
            }
            $this->assertVersion($document, $expectedVersion);
            if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được Cancel.');
            }
            $lineIds = DB::table('component_issue_lines')->where('component_issue_document_id', $id)->pluck('id');
            if ($lineIds->isNotEmpty()) {
                DB::table('component_issue_items')->whereIn('component_issue_line_id', $lineIds)->where('status', 'ACTIVE')->update([
                    'status' => 'REMOVED',
                    'updated_at' => now(),
                    'updated_by' => $this->actorId(),
                ]);
            }
            DB::table('component_issue_documents')->where('id', $id)->update([
                'status' => 'CANCELLED',
                'cancelled_at' => now(),
                'cancel_reason' => $validated['reason'],
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
                'updated_by' => $this->actorId(),
            ]);
            AuditLogger::record(
                action: 'CANCEL_COMPONENT_ISSUE',
                objectType: 'component_issue_document',
                objectId: $id,
                actorId: $this->actorId(),
                requestId: request()?->attributes->get('request_id'),
                reason: $validated['reason'],
                beforeData: ['status' => $document->status, 'version' => $expectedVersion],
                afterData: ['status' => 'CANCELLED', 'version' => $expectedVersion + 1],
            );
            $this->invalidateReadCache();

            return ['component_issue_document_id' => $id, 'status' => 'CANCELLED', 'version' => $expectedVersion + 1, 'stock_effect' => 'NONE'];
        }, 3);

        return $this->respondSuccess($result, 'Đã hủy phiếu xuất linh kiện.')
            ->header('ETag', '"'.$result['version'].'"');
    }

    #[OA\Post(
        path: '/api/v1/component-issue-documents/{id}/post',
        operationId: 'postComponentIssue',
        summary: 'Hoàn tất xuất linh kiện bảo hành (trừ tồn COMPONENT; chỉ DRAFT/SCANNING, mọi dòng phải quét đủ)',
        security: [['bearerAuth' => []]],
        tags: ['14. Component Issue'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string', example: '"1"')),
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Đã hoàn tất xuất linh kiện; ETag = version mới.'),
            new OA\Response(response: 404, description: 'Không tìm thấy Component Issue'),
            new OA\Response(response: 409, description: 'Chỉ DRAFT/SCANNING mới được Post'),
            new OA\Response(response: 412, description: 'STALE_VERSION — If-Match không khớp version hiện tại'),
            new OA\Response(response: 422, description: 'WARRANTY_CASE_STATUS_NOT_ALLOWED (hồ sơ không ở Đang kiểm tra/Đang sửa chữa), WARRANTY_CASE_NOT_ACTIVE (hồ sơ đã đóng) hoặc FULL_POST_REQUIRED (còn dòng chưa quét đủ)'),
            new OA\Response(response: 428, description: 'Thiếu Idempotency-Key hoặc If-Match hợp lệ'),
        ]
    )]
    public function post(Request $request, string $id)
    {
        $expectedVersion = $this->expectedVersion($request);
        $key = IdempotencyKeyGuard::require($request);
        $hash = IdempotencyStore::requestHash($request);
        [$code, $body, $replay] = IdempotencyStore::handle(
            $key,
            "post_component_issue_{$id}",
            $this->actorId(),
            $hash,
            function () use ($id, $expectedVersion, $key, $hash): array {
                $result = DB::transaction(function () use ($id, $expectedVersion, $key, $hash): array {
                    $document = DB::table('component_issue_documents')->where('id', $id)->lockForUpdate()->first();
                    if ($document === null) {
                        ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
                    }
                    $this->assertVersion($document, $expectedVersion);
                    if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
                        ApiError::abort(409, 'STATE_CONFLICT', 'Chỉ DRAFT/SCANNING mới được Post.');
                    }
                    $this->assertDocumentCaseAllowsIssue($document, 'tại thời điểm Post');
                    foreach (DB::table('component_issue_lines')->where('component_issue_document_id', $id)->lockForUpdate()->get() as $line) {
                        if ((int) $line->scanned_qty !== (int) $line->required_qty) {
                            ApiError::abort(422, 'FULL_POST_REQUIRED', 'Mọi Component line phải scanned_qty = required_qty.');
                        }
                    }

                    $this->containers->consumeDocument($id);
                    DB::table('component_issue_documents')->where('id', $id)->update([
                        'status' => 'DRAFT',
                        'version' => $expectedVersion + 1,
                        'updated_at' => now(),
                        'updated_by' => $this->actorId(),
                    ]);
                    $posted = $this->stock->postComponentIssue($id, $this->actorId(), $key, $hash);
                    $lineIds = DB::table('component_issue_lines')->where('component_issue_document_id', $id)->pluck('id');
                    if ($lineIds->isNotEmpty()) {
                        DB::table('component_issue_items')->whereIn('component_issue_line_id', $lineIds)->where('status', 'ACTIVE')->update([
                            'status' => 'POSTED',
                            'updated_at' => now(),
                            'updated_by' => $this->actorId(),
                        ]);
                    }
                    $posted['version'] = $expectedVersion + 1;

                    return $posted;
                }, 3);

                return [200, $this->envelope('Đã ghi nhận xuất linh kiện.', $result)];
            }
        );

        // WWHN-234 F-09: Component Issue Post là action đổi tồn (COMPONENT), cùng hợp đồng
        // replay như Post Receipt / Post Issue.
        $body = IdempotentEnvelope::withReplayFlag($body, $replay);
        $response = response()->json($body, $code)->header('Idempotent-Replay', $replay ? 'true' : 'false');
        $version = is_array($body) ? ($body['data']['version'] ?? null) : null;

        return $version !== null ? $response->header('ETag', '"'.$version.'"') : $response;
    }

    /**
     * Khoá phiếu + kiểm version/trạng thái/hồ sơ bảo hành cho mọi mutation soạn thảo.
     *
     * @param  string  $action  Tên thao tác, ghép vào thông điệp 409.
     * @param  string  $phase   Mô tả bước, ghép vào thông điệp guard trạng thái case.
     */
    private function lockMutableDocument(string $id, int $expectedVersion, string $action, string $phase): object
    {
        $document = DB::table('component_issue_documents')->where('id', $id)->lockForUpdate()->first();
        if ($document === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy Component Issue.');
        }
        $this->assertVersion($document, $expectedVersion);
        if (! in_array($document->status, ['DRAFT', 'SCANNING'], true)) {
            ApiError::abort(409, 'STATE_CONFLICT', "Chỉ DRAFT/SCANNING mới được {$action}.");
        }
        $this->assertDocumentCaseAllowsIssue($document, $phase);

        return $document;
    }

    private function lockLine(string $documentId, string $lineId): object
    {
        $line = DB::table('component_issue_lines')
            ->where('id', $lineId)
            ->where('component_issue_document_id', $documentId)
            ->lockForUpdate()
            ->first();
        if ($line === null) {
            ApiError::abort(404, 'COMPONENT_LINE_NOT_FOUND', 'Không tìm thấy Component Issue line.');
        }

        return $line;
    }

    private function assertComponentSku(string $skuId): object
    {
        $sku = DB::table('sku')->where('sku_id', $skuId)->first();
        if ($sku === null || strtoupper((string) $sku->status) !== 'ACTIVE' || strtoupper((string) $sku->sku_type) !== 'COMPONENT') {
            ApiError::abort(422, 'COMPONENT_SKU_REQUIRED', 'Component Issue chỉ nhận SKU COMPONENT ACTIVE.');
        }

        return $sku;
    }

    private function touchDocumentVersion(string $id, int $expectedVersion): void
    {
        DB::table('component_issue_documents')->where('id', $id)->update([
            'version' => $expectedVersion + 1,
            'updated_at' => now(),
            'updated_by' => $this->actorId(),
        ]);
    }

    /**
     * Ảnh chụp gọn các dòng của phiếu, đủ để client vẽ lại màn quét sau mỗi mutation
     * mà không phải gọi thêm bề mặt chi tiết.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linesSummary(string $documentId): array
    {
        return DB::table('component_issue_lines as cl')
            ->leftJoin('sku as s', 's.sku_id', '=', 'cl.sku_id')
            ->where('cl.component_issue_document_id', $documentId)
            ->orderBy('cl.line_no')
            ->get(['cl.id', 'cl.line_no', 'cl.sku_id', 'cl.required_qty', 'cl.scanned_qty', 'cl.note', 'cl.version', 's.sku_code', 's.sku_name'])
            ->map(fn (object $row): array => [
                'line_id' => (string) $row->id,
                'line_no' => (int) $row->line_no,
                'sku_id' => (string) $row->sku_id,
                'sku_code' => $row->sku_code !== null ? (string) $row->sku_code : null,
                'sku_name' => $row->sku_name !== null ? (string) $row->sku_name : null,
                'required_qty' => (int) $row->required_qty,
                'scanned_qty' => (int) $row->scanned_qty,
                'note' => $row->note !== null ? (string) $row->note : null,
                'version' => (int) $row->version,
            ])
            ->all();
    }

    /**
     * Tìm đúng một dòng quét ACTIVE để gỡ.
     *
     * Ưu tiên `component_issue_item_id` (chính xác tuyệt đối). Không có thì resolve
     * mã quét qua CÙNG hàm mà scan() dùng — hai bề mặt phải hiểu mã giống hệt nhau,
     * nếu không sẽ có mã quét vào được nhưng gỡ không ra. Trùng nhiều dòng (quét cùng
     * một hộp nhiều lần) thì gỡ dòng mới nhất, đúng trực giác "undo".
     */
    private function resolveActiveScanRow(string $documentId, array $validated): object
    {
        $query = DB::table('component_issue_items as ci')
            ->join('component_issue_lines as cl', 'cl.id', '=', 'ci.component_issue_line_id')
            ->where('cl.component_issue_document_id', $documentId)
            ->where('ci.status', 'ACTIVE');

        if (! empty($validated['component_issue_item_id'])) {
            $query->where('ci.id', $validated['component_issue_item_id']);
        } else {
            [$itemId, $containerId] = $this->resolveTarget($validated);
            if ($itemId === null && $containerId === null) {
                ApiError::abort(422, 'COMPONENT_UNSCAN_TARGET_REQUIRED', 'Phải chỉ định dòng cần gỡ bằng component_issue_item_id hoặc code_value/item_id/container_id.');
            }
            if (! empty($validated['line_id'])) {
                $query->where('cl.id', $validated['line_id']);
            }
            if ($itemId !== null) {
                $query->where('ci.item_id', $itemId);
            } else {
                $query->where('ci.container_id', $containerId);
            }
        }

        $row = $query
            ->orderByDesc('ci.created_at')
            ->lockForUpdate()
            ->first([
                'ci.id', 'ci.quantity', 'ci.item_id', 'ci.container_id',
                'cl.id as line_id', 'cl.scanned_qty', 'cl.required_qty', 'cl.version as line_version',
            ]);
        if ($row === null) {
            ApiError::abort(404, 'COMPONENT_SCAN_NOT_FOUND', 'Không tìm thấy dòng quét đang hiệu lực khớp yêu cầu trong phiếu này.');
        }

        return $row;
    }

    private function resolveTarget(array $validated): array
    {
        $itemId = $validated['item_id'] ?? null;
        $containerId = $validated['container_id'] ?? null;
        $physicalCodeId = null;
        if (! empty($validated['code_value'])) {
            // DEC-QR-001: mã composite dán trên item thật (HN1|SKU=...|ITEM=...
            // |SER=...) phải parse ra item_unique trước khi tra
            // physical_codes.code_value — bảng đó chỉ lưu phần ITEM, không lưu
            // nguyên chuỗi composite. Thiếu bước này, MỌI lần quét QR thật (khác
            // với mã hộp/legacy trần) đều báo nhầm PHYSICAL_CODE_NOT_FOUND dù
            // item có sẵn trong kho. Mã hộp/legacy không khớp prefix composite
            // nên isComposite()=false, normalizedCodeValue giữ nguyên hành vi cũ
            // (strtoupper(trim(...))).
            $parsedCode = app(ProductQrPayloadParser::class)->parse((string) $validated['code_value']);
            $lookupCodeValue = $parsedCode->isComposite()
                ? (string) $parsedCode->itemUnique
                : (string) $parsedCode->normalizedCodeValue;
            $physical = DB::table('physical_codes')
                ->where('code_value', $lookupCodeValue)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->first();
            if ($physical === null) {
                // Mã hộp BOX-<SKU>-<seq> (BoxCodeParser) tự mô tả — đường DUY NHẤT
                // tạo BOX (InboundBoxRegistrar::ensure, dùng chung bởi POST
                // /inbound-documents/{id}/boxes và scan-driven inbound) cố ý KHÔNG
                // ghi physical_codes cho BOX (physical_code_id=null), vì
                // container_code chính là mã quét được — cùng cách
                // ScanClassificationService::resolveContainerByCode() đã tra khi
                // vắng physical_codes. Thiếu fallback này thì MỌI BOX tạo qua nhập
                // kho chuẩn không bao giờ quét lại được ở Component Issue.
                $boxContainer = DB::table('containers')
                    ->where('container_code', $lookupCodeValue)
                    ->where('container_type', 'BOX')
                    ->lockForUpdate()
                    ->first();
                if ($boxContainer === null) {
                    ApiError::abort(422, 'PHYSICAL_CODE_NOT_FOUND', 'Barcode/QR/RFID không tồn tại hoặc không ACTIVE.');
                }
                $resolvedContainer = (string) $boxContainer->container_id;
                if ($containerId !== null && strtolower((string) $containerId) !== strtolower($resolvedContainer)) {
                    ApiError::abort(422, 'PHYSICAL_CODE_TARGET_MISMATCH', 'code_value và container_id không cùng target.');
                }

                return [$itemId ? (string) $itemId : null, $resolvedContainer, null];
            }
            $physicalCodeId = (string) $physical->physical_code_id;
            $resolvedItem = $physical->item_id ?? (($physical->assigned_object_type ?? null) === 'ITEM' ? $physical->assigned_object_id : null);
            $resolvedContainer = $physical->container_id ?? (($physical->assigned_object_type ?? null) === 'CONTAINER' ? $physical->assigned_object_id : null);
            if ($itemId !== null && $resolvedItem !== null && strtolower((string) $itemId) !== strtolower((string) $resolvedItem)) {
                ApiError::abort(422, 'PHYSICAL_CODE_TARGET_MISMATCH', 'code_value và item_id không cùng target.');
            }
            if ($containerId !== null && $resolvedContainer !== null && strtolower((string) $containerId) !== strtolower((string) $resolvedContainer)) {
                ApiError::abort(422, 'PHYSICAL_CODE_TARGET_MISMATCH', 'code_value và container_id không cùng target.');
            }
            $itemId = $resolvedItem ?? $itemId;
            $containerId = $resolvedContainer ?? $containerId;
        }

        return [$itemId ? (string) $itemId : null, $containerId ? (string) $containerId : null, $physicalCodeId];
    }

    private function recordScanEvent(object $document, ?string $physicalCodeId, ?string $itemId, ?string $containerId, ?string $rawCode): ?string
    {
        // scan_event là compatibility VIEW — hasTable() trả false cho view trên
        // PostgreSQL, phải kiểm tra thêm hasView để không bỏ rơi evidence NFR-SRS-004.
        $schema = DB::getSchemaBuilder();
        $hasScanEvent = $schema->hasTable('scan_event') || $schema->hasView('scan_event');
        if ($rawCode === null || ! $schema->hasTable('scan_batch') || ! $hasScanEvent) {
            return null;
        }
        $batchId = (string) Str::uuid();
        DB::table('scan_batch')->insert([
            'scan_batch_id' => $batchId,
            'source_type' => 'COMPONENT_ISSUE_DOCUMENT',
            'source_id' => $document->id,
            'warehouse_id' => $document->warehouse_id,
            'created_by' => $this->actorId(),
            'created_at' => now(),
        ]);
        $eventId = (string) Str::uuid();
        DB::table('scan_event')->insert([
            'scan_event_id' => $eventId,
            'scan_batch_id' => $batchId,
            'raw_code' => $rawCode,
            'physical_code_id' => $physicalCodeId,
            'resolved_object_type' => $itemId !== null ? 'ITEM' : 'CONTAINER',
            'resolved_object_id' => $itemId ?? $containerId,
            'result_status' => 'VALID',
            'message' => 'Đã quét hợp lệ cho phiếu xuất linh kiện',
            'created_at' => now(),
        ]);

        return $eventId;
    }

    private function payload(string $id): array
    {
        $lines = DB::table('component_issue_lines')->where('component_issue_document_id', $id)->orderBy('line_no')->get();

        return [
            'document' => DB::table('component_issue_documents')->where('id', $id)->first(),
            'lines' => $lines->map(fn (object $line): array => [
                ...(array) $line,
                'items' => DB::table('component_issue_items')->where('component_issue_line_id', $line->id)->get()->map(fn (object $row): array => (array) $row)->all(),
            ])->all(),
        ];
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho Component Issue mutation.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header)) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên không âm.');
        }

        return (int) $header;
    }

    private function assertVersion(object $document, int $expected): void
    {
        if ((int) $document->version !== $expected) {
            ApiError::abort(412, 'STALE_VERSION', 'Component Issue version mismatch.', [
                'expected_version' => $expected,
                'current_version' => (int) $document->version,
            ]);
        }
    }

    private function envelope(string $message, array $data): array
    {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => ['request_id' => request()?->attributes->get('request_id'), 'timestamp' => now()->toIso8601String()],
        ];
    }
}
