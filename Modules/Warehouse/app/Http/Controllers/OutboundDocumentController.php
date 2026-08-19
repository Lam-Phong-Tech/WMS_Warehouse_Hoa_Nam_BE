<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Http\Controllers\Concerns\InteractsWithStockDocuments;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Services\StockDocumentService;
use OpenApi\Attributes as OA;

/**
 * Phiếu xuất kho (doc_type=issue) — read side (index/show).
 *
 * Mutation (cancel/scan/unmatch/post-issue/reverse) đi qua
 * CurrentBaselineDocumentController / AlignedOutboundFlowController để enforce
 * baseline full-post; mọi transition duyệt/partial legacy đã bị supersede và
 * không còn tồn tại (SCOPE LOCK 0.3).
 *
 * WWHN-195 / DEV195-P1-05R2 — nguồn sự thật của annotation trong file này:
 * routes/api.php + Flow06AlignmentServiceProvider (handler thực thi). Không khai
 * operation nào không có route tương ứng; RouteSyncProcessor sẽ cắt bỏ nhưng
 * annotation ghost vẫn khiến FE/QA đọc nhầm contract nên phải xoá tại nguồn.
 */
#[OA\Get(
    path: '/api/v1/outbound-documents',
    operationId: 'stock_out_list',
    summary: 'Danh sách phiếu xuất kho (phân trang, filter status, kèm label/RFID progress)',
    security: [['bearerAuth' => []]],
    tags: ['10. Stock Out (Outbound)'],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED'])),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Danh sách phiếu xuất kho; mỗi phần tử kèm matched_count, label_printed_count, rfid_mapped_count, required_total'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền outbound.view'),
    ]
)]
// GHOST OPERATION ĐÃ GỠ (DEV195-P1-05R2): `POST /api/v1/outbound-documents`
// (operationId stock_out_create). SRS v1.12 §8.1/Annex C ghi rõ "Confirm creates
// exactly one outbound DRAFT; no direct POST /outbound-documents create
// operation" và routes/api.php:124-127 cũng đã bỏ route này. Đường tạo phiếu
// duy nhất: POST /api/v1/outbound-request-imports/{id}/confirm.
#[OA\Get(
    path: '/api/v1/outbound-documents/{id}',
    operationId: 'stock_out_detail',
    summary: 'Chi tiết phiếu xuất kho (kèm lines, movements, status history, item_matches)',
    security: [['bearerAuth' => []]],
    tags: ['10. Stock Out (Outbound)'],
    parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
    responses: [
        new OA\Response(response: 200, description: 'Chi tiết phiếu xuất kho'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
    ]
)]
// GHOST OPERATION ĐÃ GỠ (DEV195-P1-05R2): `PATCH /api/v1/outbound-documents/{id}`
// (operationId stock_out_update). Không có route runtime; header phiếu xuất được
// chốt tại bước Confirm toa (recipient/địa chỉ/SĐT resolve trước Confirm theo
// REQ-SRS-014), sau đó phiếu chỉ đi tiếp scan → label → post-issue → cancel.
#[OA\Post(
    path: '/api/v1/outbound-documents/{id}/cancel',
    operationId: 'stock_out_cancel',
    summary: 'Huỷ phiếu xuất kho (DRAFT/SCANNING; reason + If-Match bắt buộc)',
    description: 'CANCELLED là terminal và stock-neutral; toa nguồn được trả về OPEN để tạo phiếu mới nếu không còn phiếu active/POSTED khác dùng toa.',
    security: [['bearerAuth' => []]],
    tags: ['10. Stock Out (Outbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['reason'],
        properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 2000)]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Huỷ phiếu thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền outbound.cancel'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Chỉ DRAFT/SCANNING mới được Cancel'),
        new OA\Response(response: 412, description: 'If-Match version mismatch'),
        new OA\Response(response: 428, description: 'Thiếu If-Match'),
    ]
)]
#[OA\Post(
    path: '/api/v1/outbound-documents/{id}/scan',
    operationId: 'stock_out_scan',
    summary: 'Quét & match item vào dòng phiếu xuất (stock_effect=NONE)',
    description: 'Handler thực thi là AlignedOutboundFlowController::outboundScan (Flow06AlignmentServiceProvider override); alias Annex C `/scans` dùng chung handler nên hai path có cùng nghiệp vụ. Camera và nhập tay dùng chung endpoint/validation (AC-SRS-005); `scan_source` khai báo tường minh nguồn quét theo ERD bảng 25 (CAMERA/MANUAL/HARDWARE), không gửi thì server suy luận và ghi rõ scan_source_origin=INFERRED. Mỗi lần scan là một scan event độc lập — không khoá chế độ theo phiếu, được đổi qua lại giữa camera và nhập tay trong cùng phiếu (NFR-SRS-021 camera-denied fallback). Scan KHÔNG in nhãn: chỉ khi full match hệ thống mới tổng hợp và tạo/đảm bảo đúng 01 Packing Label DRAFT cho cả phiếu (SRS §5.2/§6); Preview/Ready/Print là action riêng dưới /packing-labels/{label_id}. Không ghi stock_movements, không đổi inventory_balances.',
    security: [['bearerAuth' => []]],
    tags: ['10. Stock Out (Outbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['line_id', 'quantity'],
            properties: [
                new OA\Property(property: 'line_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'quantity', type: 'integer', example: 1),
                new OA\Property(property: 'code_value', type: 'string', maxLength: 150, nullable: true),
                new OA\Property(property: 'item_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'scan_source', type: 'string', enum: ['CAMERA', 'MANUAL', 'HARDWARE'], nullable: true),
                new OA\Property(property: 'container_id', type: 'string', format: 'uuid', nullable: true),
            ]
        )
    ),
    responses: [
        new OA\Response(response: 200, description: 'Item matched; kèm scan_source/scan_source_origin, packing_label_ready và label_status (DRAFT khi vừa full match, NOT_CREATED khi chưa đủ); stock_effect=NONE'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền outbound.scan'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ hoặc dòng'),
        new OA\Response(response: 409, description: 'Trùng item / item đang match phiếu khác / STATE_CONFLICT'),
        new OA\Response(response: 422, description: 'Sai SKU, mã không tồn tại, item đã xuất, vượt required_qty hoặc thiếu tồn'),
        new OA\Response(response: 428, description: 'Thiếu If-Match'),
    ]
)]
#[OA\Post(
    path: '/api/v1/outbound-documents/{id}/unmatch',
    operationId: 'stock_out_unmatch',
    summary: 'Gỡ item đã match trước Post (match ACTIVE → REMOVED, stock_effect=NONE)',
    security: [['bearerAuth' => []]],
    tags: ['10. Stock Out (Outbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
        required: ['outbound_item_match_id'],
        properties: [
            new OA\Property(property: 'outbound_item_match_id', type: 'string', format: 'uuid'),
            new OA\Property(property: 'reason', type: 'string', maxLength: 2000, nullable: true),
        ]
    )),
    responses: [
        new OA\Response(response: 200, description: 'Match REMOVED; scanned_qty giảm; item có thể quét lại'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền outbound.scan'),
        new OA\Response(response: 404, description: 'Không tìm thấy match trong phiếu'),
        new OA\Response(response: 409, description: 'Match không ACTIVE hoặc phiếu không còn DRAFT/SCANNING'),
    ]
)]
#[OA\Post(
    path: '/api/v1/outbound-documents/{id}/post-issue',
    operationId: 'stock_out_post_issue',
    summary: 'Full Post Issue (SCANNING → POSTED, giảm tồn atomic đúng một lần)',
    description: 'Chỉ Post khi mọi line scanned_qty = required_qty, đủ tồn, mọi PRODUCT unit có item match ACTIVE, và Packing Label của phiếu đã PRINTED + snapshot còn current + đã xác nhận dán nhãn (SRS §5.2/§6: DRAFT → PREVIEWED → READY_TO_PRINT → PRINTED). RFID/hardware_uid là tuỳ chọn và KHÔNG phải gate (DEC-RFID-FALLBACK-001). Bắt buộc Idempotency-Key + If-Match. Không có xuất một phần.',
    security: [['bearerAuth' => []]],
    tags: ['10. Stock Out (Outbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'If-Match', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    responses: [
        new OA\Response(response: 200, description: 'Post Issue thành công; movement append-only, item → ISSUED'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền outbound.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'STATE_CONFLICT hoặc Idempotency-Key dùng với payload khác'),
        new OA\Response(response: 412, description: 'If-Match version mismatch'),
        new OA\Response(response: 422, description: 'FULL_POST_REQUIRED / LABEL_NOT_PRINTED / INSUFFICIENT_STOCK (RFID optional — DEC-RFID-FALLBACK-001, không phải gate)'),
        new OA\Response(response: 428, description: 'Thiếu Idempotency-Key hoặc If-Match'),
    ]
)]
#[OA\Post(
    path: '/api/v1/outbound-documents/{id}/reverse',
    operationId: 'stock_out_reverse',
    summary: 'Đảo ngược (reverse/correction) phiếu xuất đã hạch toán',
    description: 'Sinh movement đảo ngược liên kết ngược tới movement gốc qua original_movement_id. Đổi tồn thật, bắt buộc header Idempotency-Key.',
    security: [['bearerAuth' => []]],
    tags: ['10. Stock Out (Outbound)'],
    parameters: [
        new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
    ],
    requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'reason', type: 'string', nullable: true),
    ])),
    responses: [
        new OA\Response(response: 200, description: 'Đảo ngược chứng từ thành công'),
        new OA\Response(response: 401, description: 'Chưa xác thực'),
        new OA\Response(response: 403, description: 'Không có quyền inventory.reversal.post'),
        new OA\Response(response: 404, description: 'Không tìm thấy chứng từ'),
        new OA\Response(response: 409, description: 'Idempotency-Key đã dùng với payload khác hoặc xung đột optimistic lock'),
        new OA\Response(response: 422, description: 'Chứng từ chưa hạch toán hoặc đã bị đảo ngược trước đó'),
        new OA\Response(response: 428, description: 'Thiếu header Idempotency-Key'),
    ]
)]
class OutboundDocumentController extends Controller
{
    use ApiResponse;
    use InteractsWithStockDocuments;

    public function __construct(private readonly StockDocumentService $documentService) {}

    protected function docType(): string
    {
        return StockDocument::TYPE_ISSUE;
    }

    protected function service(): StockDocumentService
    {
        return $this->documentService;
    }

    /**
     * Danh sách phiếu xuất kèm tiến độ nhãn/RFID theo từng phiếu (SCR-OUT-01:
     * cột Match / Nhãn / RFID) để FE không phải quét toàn bộ label-snapshots
     * và physical-codes.
     */
    public function index(Request $request)
    {
        $query = StockDocument::query()
            ->with('lines')
            ->where('doc_type', $this->docType());

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        $this->applyKeyword($query, $request, ['doc_no', 'note']);

        $paginator = $query->orderByDesc('created_at')->paginate($this->perPage($request));
        $stats = $this->documentProgressStats(
            collect($paginator->items())->map(fn (StockDocument $doc): string => (string) $doc->id)->all()
        );

        $paginator->getCollection()->transform(function (StockDocument $doc) use ($stats): array {
            $stat = $stats[(string) $doc->id] ?? null;

            return [
                ...$doc->toArray(),
                'required_total' => (int) $doc->lines->sum('qty_planned'),
                'matched_count' => (int) ($stat->matched_count ?? 0),
                'label_printed_count' => (int) ($stat->label_printed_count ?? 0),
                'rfid_mapped_count' => (int) ($stat->rfid_mapped_count ?? 0),
            ];
        });

        return $this->respondPaginated($paginator);
    }

    /** @return array<string, object> keyed by document id */
    private function documentProgressStats(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        return DB::table('stock_document_lines as l')
            ->leftJoin('outbound_item_matches as m', function ($join): void {
                $join->on('m.outbound_line_id', '=', 'l.id')
                    ->where(function ($query): void {
                        $query->whereNull('m.status')->orWhere('m.status', '!=', 'REMOVED');
                    });
            })
            ->leftJoin('label_snapshots as ls', 'ls.outbound_item_match_id', '=', 'm.id')
            ->leftJoin('physical_codes as pc', function ($join): void {
                $join->on('pc.assigned_object_id', '=', 'm.item_id')
                    ->where('pc.assigned_object_type', 'ITEM')
                    ->where('pc.carrier_type', 'RFID')
                    ->where('pc.status', 'ACTIVE')
                    ->whereNotNull('pc.hardware_uid');
            })
            ->whereIn('l.stock_document_id', $documentIds)
            ->groupBy('l.stock_document_id')
            ->selectRaw('l.stock_document_id')
            ->selectRaw('COUNT(DISTINCT m.id) AS matched_count')
            ->selectRaw("COUNT(DISTINCT m.id) FILTER (WHERE ls.status = 'PRINTED') AS label_printed_count")
            ->selectRaw('COUNT(DISTINCT m.id) FILTER (WHERE pc.physical_code_id IS NOT NULL) AS rfid_mapped_count')
            ->get()
            ->keyBy('stock_document_id')
            ->all();
    }

    /**
     * Chi tiết phiếu xuất kèm item_matches (trạng thái match + nhãn + RFID theo
     * từng item) để FE dựng màn OUT-03/LBL-01/RFID-01 mà không phải quét toàn
     * bộ label-snapshots/physical-codes.
     */
    public function show(string $id)
    {
        $doc = StockDocument::with(['lines', 'movements', 'statusHistory'])
            ->where('doc_type', $this->docType())
            ->find($id);
        if ($doc === null) {
            return $this->respondNotFound('Không tìm thấy chứng từ.');
        }

        $payload = $doc->toArray();
        $payload['item_matches'] = $this->outboundItemMatches($id);

        return $this->respondSuccess($payload);
    }

    private function outboundItemMatches(string $documentId): array
    {
        $rows = DB::table('outbound_item_matches as m')
            ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
            ->leftJoin('label_snapshots as ls', 'ls.outbound_item_match_id', '=', 'm.id')
            ->leftJoin('item_instances as i', 'i.item_id', '=', 'm.item_id')
            ->where('l.stock_document_id', $documentId)
            ->orderBy('m.created_at')
            ->get([
                'm.id',
                'm.outbound_line_id as line_id',
                'm.item_id',
                'm.container_id',
                'm.quantity',
                'm.status',
                'm.matched_at',
                'm.removed_at',
                'l.product_id as sku_id',
                'i.item_code',
                'i.serial_number',
                'ls.id as label_snapshot_id',
                'ls.status as label_status',
            ]);

        $itemIds = $rows->pluck('item_id')->filter()->unique()->values();
        $rfidByItem = $itemIds->isEmpty()
            ? collect()
            : DB::table('physical_codes')
                ->where('assigned_object_type', 'ITEM')
                ->whereIn('assigned_object_id', $itemIds)
                ->where('carrier_type', 'RFID')
                ->where('status', 'ACTIVE')
                ->whereNotNull('hardware_uid')
                ->pluck('code_value', 'assigned_object_id');

        return $rows->map(function (object $row) use ($rfidByItem): array {
            $itemId = $row->item_id !== null ? (string) $row->item_id : null;

            return [
                'id' => (string) $row->id,
                'line_id' => (string) $row->line_id,
                'item_id' => $itemId,
                'container_id' => $row->container_id !== null ? (string) $row->container_id : null,
                'sku_id' => (string) $row->sku_id,
                'item_code' => $row->item_code,
                'serial_number' => $row->serial_number,
                'quantity' => (int) $row->quantity,
                'status' => (string) ($row->status ?? 'ACTIVE'),
                'matched_at' => $row->matched_at,
                'removed_at' => $row->removed_at,
                'label_snapshot_id' => $row->label_snapshot_id !== null ? (string) $row->label_snapshot_id : null,
                'label_status' => $row->label_status,
                'rfid_code' => $itemId !== null ? ($rfidByItem[$itemId] ?? null) : null,
            ];
        })->values()->all();
    }
}
