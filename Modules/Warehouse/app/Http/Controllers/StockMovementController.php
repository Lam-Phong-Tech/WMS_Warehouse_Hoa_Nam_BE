<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\HoaNamErdSchema;
use App\Support\WarrantyPiiMasker;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Services\ProductIdentityResolver;
use Modules\Warehouse\Services\ProductQrPayloadParser;
use Modules\Warehouse\Support\ProductQrPayload;
use OpenApi\Attributes as OA;

/** Lịch sử biến động tồn (inventory_movement) — append-only, không cho sửa/xoá. */
class StockMovementController extends Controller
{
    use ApiResponse;

    /**
     * WWHN-240 / BR-REV-04, BR-REV-08: correction chain là append-only và một Reversal có
     * thể tham chiếu chính một Reversal trước đó. Trần độ sâu để việc đi ngược/duyệt xuôi
     * không lặp vô hạn nếu dữ liệu cũ có vòng; vượt trần thì báo cờ `truncated` thay vì
     * âm thầm trả chain thiếu.
     */
    private const MAX_CORRECTION_CHAIN_DEPTH = 32;

    #[OA\Get(
        path: '/api/v1/stock-movements',
        operationId: 'stock_movement_list',
        summary: 'Lịch sử biến động tồn (phân trang, filter theo warehouse_id/product_id/movement_type)',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'product_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'movement_type', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'keyword', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách movement'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền inventory.view'),
        ]
    )]
    public function index(Request $request)
    {
        $query = StockMovement::query()->with(['product', 'warehouse', 'document:id,doc_no,doc_type,status', 'creator:user_id,display_name'])->orderByDesc('occurred_at');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }
        if ($request->filled('movement_type')) {
            $query->where('movement_type', $request->input('movement_type'));
        }
        $this->applyKeyword($query, $request, ['movement_no', 'business_key', 'source_type']);

        return $this->respondPaginated($query->paginate($this->perPage($request)));
    }

    #[OA\Get(
        path: '/api/v1/stock-movements/{id}',
        operationId: 'stock_movement_detail',
        summary: 'Chi tiết 1 movement',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết movement'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy movement'),
        ]
    )]
    public function show(string $id)
    {
        $movement = StockMovement::with(['product', 'warehouse', 'document'])->find($id);

        return $movement ? $this->respondSuccess($movement) : $this->respondNotFound('Không tìm thấy movement.');
    }

    #[OA\Get(
        path: '/api/v1/stock-movements/{id}/trace-source',
        operationId: 'stock_movement_trace_source',
        summary: 'Truy vết chứng từ gốc, correction chain đầy đủ và net effect của một movement',
        description: 'WWHN-240 / BR-REV-11 + §13.10 RPT-MOV-01: "report hiển thị movement gốc, toàn bộ Reversal chain và net effect; không ẩn hoặc sửa lịch sử". `correction_chain.nodes` là chuỗi append-only đã sắp xếp theo thời gian, gồm movement gốc (`role=ORIGINAL`, `depth=0`) và MỌI reversal tham chiếu nó — kể cả reversal của reversal theo BR-REV-08. `net_effect` tính từ ledger `stock_movements` nên luôn khớp cột NET của RPT-XNT-01. Data scope: EnforceInventoryWarehouseScope trả 403 khi movement thuộc kho ngoài scope hiệu lực, và trace yêu cầu ĐỒNG THỜI `inventory.trace` lẫn `inventory.view`.',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'stock_movements.id', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Chứng từ gốc + lịch sử trạng thái + correction chain đầy đủ + net effect',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: '#/components/schemas/ApiSuccessEnvelope'),
                    new OA\Schema(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/StockMovementTraceSource')]),
                ])
            ),
            new OA\Response(response: 401, description: 'Chưa xác thực', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope')),
            new OA\Response(response: 403, description: 'Thiếu inventory.trace/inventory.view, hoặc movement nằm ngoài warehouse data scope (error.code = ACCESS_DENIED)', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope')),
            new OA\Response(response: 404, description: 'Không tìm thấy movement', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorEnvelope')),
        ]
    )]
    public function traceSource(string $id)
    {
        $movement = StockMovement::with(['product', 'warehouse', 'creator:user_id,display_name', 'document.statusHistory', 'document.lines.product'])->find($id);

        if (! $movement) {
            return $this->respondNotFound('Không tìm thấy movement.');
        }

        // BR-REV-11: một movement có thể bị đảo NHIỀU lần (BR-REV-05 cho phép đảo từng
        // phần). Bản cũ chỉ trả `->first()`, nên mọi reversal thứ hai trở đi biến mất khỏi
        // trace và net effect không thể tính được từ response.
        $directReversals = StockMovement::where('original_movement_id', $movement->id)
            ->orderBy('occurred_at')->orderBy('id')->get();

        $chain = $this->correctionChain($movement);

        return $this->respondSuccess([
            'movement' => $movement,
            'document' => $movement->document,
            'status_history' => $movement->document?->statusHistory,
            'original_movement' => $movement->original_movement_id ? StockMovement::find($movement->original_movement_id) : null,
            'root_movement' => $chain['root_movement_id'] === (string) $movement->id
                ? $movement
                : StockMovement::find($chain['root_movement_id']),
            // DEPRECATED nhưng vẫn giữ nguyên hình dạng cũ cho client hiện hành.
            'reversal_movement' => $directReversals->first(),
            'reversal_movements' => $directReversals->values(),
            'correction_chain' => $chain['chain'],
            'net_effect' => $chain['net_effect'],
        ]);
    }

    /**
     * WWHN-240 DEV240-P1-02B: dựng correction chain đầy đủ quanh một movement.
     *
     * Đi NGƯỢC theo `original_movement_id` tới movement gốc (BR-REV-08 cho phép một
     * Reversal tham chiếu một Reversal khác), rồi duyệt XUÔI theo bề rộng để gom mọi
     * movement đối ứng. Toàn bộ truy vấn dùng Eloquent nên global scope
     * `ScopesWarehouseData` vẫn có hiệu lực — không mắt xích nào rò ra ngoài data scope.
     *
     * @return array{root_movement_id: string, chain: array<string, mixed>, net_effect: array<string, mixed>}
     */
    private function correctionChain(StockMovement $subject): array
    {
        $rootId = (string) $subject->id;
        $cursor = $subject->original_movement_id;
        $guard = 0;
        $truncated = false;
        while ($cursor !== null) {
            if ($guard++ >= self::MAX_CORRECTION_CHAIN_DEPTH) {
                $truncated = true;
                break;
            }
            $parent = StockMovement::select(['id', 'original_movement_id', 'warehouse_id'])->find($cursor);
            if ($parent === null) {
                break;
            }
            $rootId = (string) $parent->id;
            $cursor = $parent->original_movement_id;
        }

        $ids = [$rootId];
        $frontier = [$rootId];
        $guard = 0;
        while ($frontier !== []) {
            if ($guard++ >= self::MAX_CORRECTION_CHAIN_DEPTH) {
                $truncated = true;
                break;
            }
            $children = StockMovement::whereIn('original_movement_id', $frontier)
                ->pluck('id')->map(fn ($value): string => (string) $value)->all();
            $children = array_values(array_diff($children, $ids));
            if ($children === []) {
                break;
            }
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        $movements = StockMovement::with(['creator:user_id,display_name', 'warehouse', 'product'])
            ->whereIn('id', $ids)
            ->orderBy('occurred_at')->orderBy('id')->get();

        $reversalDocs = $this->reversalDocumentContext($movements);

        $depthOf = [$rootId => 0];
        // $movements đã sắp theo thời gian nên cha thường đứng trước con; vẫn lặp nhiều
        // vòng phòng khi occurred_at trùng nhau làm thứ tự không phản ánh quan hệ cha-con.
        for ($pass = 0; $pass < self::MAX_CORRECTION_CHAIN_DEPTH; $pass++) {
            $changed = false;
            foreach ($movements as $row) {
                $id = (string) $row->id;
                $parentId = $row->original_movement_id === null ? null : (string) $row->original_movement_id;
                if ($parentId !== null && isset($depthOf[$parentId]) && ! isset($depthOf[$id])) {
                    $depthOf[$id] = $depthOf[$parentId] + 1;
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }

        $subjectId = (string) $subject->id;
        $nodes = $movements->map(function (StockMovement $row) use ($depthOf, $rootId, $subjectId, $reversalDocs): array {
            $id = (string) $row->id;
            $doc = $row->source_type === 'REVERSAL_DOCUMENT' && $row->source_id !== null
                ? ($reversalDocs[(string) $row->source_id] ?? null)
                : null;

            return [
                'id' => $id,
                'movement_no' => $row->movement_no === null ? null : (string) $row->movement_no,
                'movement_type' => (string) $row->movement_type,
                'role' => $id === $rootId ? 'ORIGINAL' : 'REVERSAL',
                'depth' => (int) ($depthOf[$id] ?? 0),
                'is_subject' => $id === $subjectId,
                'occurred_at' => $row->occurred_at?->toIso8601String(),
                'warehouse_id' => $row->warehouse_id === null ? null : (string) $row->warehouse_id,
                'warehouse_code' => $row->warehouse?->code === null ? null : (string) $row->warehouse->code,
                'product_id' => $row->product_id === null ? null : (string) $row->product_id,
                'product_code' => $row->product?->sku_code === null ? null : (string) $row->product->sku_code,
                'stock_status' => $row->stock_status === null ? null : (string) $row->stock_status,
                'item_id' => $row->item_id === null ? null : (string) $row->item_id,
                'quantity' => $row->quantity === null ? null : (float) $row->quantity,
                'qty_delta' => (float) $row->qty_delta,
                'qty_before' => $row->qty_before === null ? null : (float) $row->qty_before,
                'qty_after' => $row->qty_after === null ? null : (float) $row->qty_after,
                'source_type' => $row->source_type === null ? null : (string) $row->source_type,
                'source_id' => $row->source_id === null ? null : (string) $row->source_id,
                'stock_document_id' => $row->stock_document_id === null ? null : (string) $row->stock_document_id,
                'original_movement_id' => $row->original_movement_id === null ? null : (string) $row->original_movement_id,
                'correction_chain_id' => $row->correction_chain_id === null ? null : (string) $row->correction_chain_id,
                'actor' => $row->creator?->display_name === null ? null : (string) $row->creator->display_name,
                'reversal_document_no' => $doc?->document_no === null ? null : (string) $doc->document_no,
                'reversal_status' => $doc?->status === null ? null : (string) $doc->status,
                'reason_code' => $doc?->reason_code === null ? null : (string) $doc->reason_code,
                'reason_text' => $doc?->reason_text === null ? null : (string) $doc->reason_text,
            ];
        })->values()->all();

        $root = collect($nodes)->firstWhere('id', $rootId);
        $rootDelta = (float) ($root['qty_delta'] ?? 0.0);
        $netDelta = (float) array_sum(array_column($nodes, 'qty_delta'));
        $originalQty = abs($rootDelta);
        // Reversal luôn ngược dấu movement nó đảo, nên phần đã đảo chính là khoảng cách
        // giữa bút toán gốc và tác động ròng còn lại. Một reversal-của-reversal sẽ tự động
        // LÀM GIẢM con số này, đúng ngữ nghĩa BR-REV-08.
        $reversedQty = abs($rootDelta - $netDelta);
        $subjectDelta = abs((float) $subject->qty_delta);
        $subjectReversed = abs((float) collect($nodes)->where('original_movement_id', $subjectId)->sum('qty_delta'));

        return [
            'root_movement_id' => $rootId,
            'chain' => [
                'correction_chain_id' => collect($nodes)->pluck('correction_chain_id')->filter()->first(),
                'root_movement_id' => $rootId,
                'node_count' => count($nodes),
                'reversal_count' => max(0, count($nodes) - 1),
                'max_depth' => $depthOf === [] ? 0 : max($depthOf),
                'truncated' => $truncated,
                'nodes' => $nodes,
            ],
            'net_effect' => [
                'basis' => 'LEDGER',
                'original_movement_id' => $rootId,
                'original_movement_type' => (string) ($root['movement_type'] ?? $subject->movement_type),
                'original_qty' => $originalQty,
                'original_qty_delta' => $rootDelta,
                'reversed_qty' => $reversedQty,
                'remaining_reversible_qty' => max(0.0, $originalQty - $reversedQty),
                'net_qty_delta' => $netDelta,
                'fully_reversed' => $originalQty > 0.0 && $reversedQty >= $originalQty - 1e-9,
                'reversal_count' => max(0, count($nodes) - 1),
                'subject_reversed_qty' => $subjectDelta === 0.0 ? $subjectReversed : min($subjectReversed, $subjectDelta),
            ],
        ];
    }

    /**
     * Reason code/text và trạng thái phiếu Reversal cho từng mắt xích.
     *
     * `reversal_document` là bảng vật lý trên SQLite và là view tương thích trên
     * PostgreSQL (tên số nhiều thì ngược lại), nên phải dò bằng
     * `HoaNamErdSchema::relationExists()` — `Schema::hasTable()` trả false cho view.
     *
     * @param  Collection<int, StockMovement>  $movements
     * @return array<string, object>
     */
    private function reversalDocumentContext($movements): array
    {
        $documentIds = $movements
            ->where('source_type', 'REVERSAL_DOCUMENT')
            ->pluck('source_id')
            ->filter()
            ->map(fn ($value): string => (string) $value)
            ->unique()
            ->values()
            ->all();

        if ($documentIds === [] || ! HoaNamErdSchema::relationExists('reversal_document')) {
            return [];
        }

        // select('*') có chủ đích: view tương thích giữa hai driver không đảm bảo cùng một
        // tập cột, nên đọc mềm rồi truy cập bằng `?->` thay vì liệt kê cột cứng.
        return DB::table('reversal_document')
            ->whereIn('reversal_document_id', $documentIds)
            ->get()
            ->keyBy('reversal_document_id')
            ->all();
    }

    #[OA\Get(
        path: '/api/v1/stock-movements/trace-by-code',
        operationId: 'stock_movement_trace_by_physical_code',
        summary: 'Truy vết từ mã vật lý (physical code) → item/container/SKU → movement chain',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Trace chain: physical_code → resolved object → movements → reversal/correction'),
            new OA\Response(response: 404, description: 'Physical code not found'),
        ]
    )]
    public function traceByPhysicalCode(Request $request)
    {
        // Change Pack QR/SKU: chấp nhận cả raw composite payload (trần 512);
        // resolveTraceTarget parse + chuẩn hoá trước khi tra movement chain.
        $request->validate(['code' => ['required', 'string', 'max:'.(int) config('warehouse.product_qr.max_payload_length', 512)]]);
        $codeValue = trim((string) $request->input('code'));

        $resolution = $this->resolveTraceTarget($codeValue);
        if ($resolution === null) {
            return $this->respondNotFound('Không tìm thấy mã tra cứu (physical code / item / container / SKU).');
        }

        [$resolvedType, $resolvedId, $physicalCode, $resolvedBy] = $resolution;
        $resolvedObject = null;

        if (! $resolvedType || ! $resolvedId) {
            return $this->respondSuccess([
                'physical_code' => $physicalCode,
                'resolved_type' => null,
                'resolved_id' => null,
                'resolved_by' => $resolvedBy,
                'resolved_object' => null,
                'movements' => [],
                'container_items' => [],
            ]);
        }

        if ($resolvedType === 'ITEM') {
            $resolvedObject = DB::table('item_instances')
                ->leftJoin('sku', 'item_instances.sku_id', '=', 'sku.sku_id')
                ->where('item_instances.item_id', $resolvedId)
                ->select('item_instances.*', 'sku.sku_code', 'sku.sku_name')
                ->first();
        } elseif ($resolvedType === 'CONTAINER') {
            $resolvedObject = DB::table('containers')
                ->where('container_id', $resolvedId)
                ->first();
        } elseif ($resolvedType === 'SKU') {
            $resolvedObject = DB::table('sku')
                ->where('sku_id', $resolvedId)
                ->first();
        }

        $movementSelect = [
            'stock_movements.*',
            'sku.sku_code as product_code',
            'sku.sku_name as product_name',
            'warehouses.code as warehouse_code',
            'warehouses.name as warehouse_name',
            'stock_documents.doc_no',
            'stock_documents.doc_type',
        ];
        $movementQuery = DB::table('stock_movements')
            ->leftJoin('sku', 'stock_movements.product_id', '=', 'sku.sku_id')
            ->leftJoin('warehouses', 'stock_movements.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('stock_documents', 'stock_movements.stock_document_id', '=', 'stock_documents.id')
            ->orderByDesc('stock_movements.occurred_at')
            ->limit(50);

        $movements = collect();
        if ($resolvedType === 'ITEM') {
            $movements = (clone $movementQuery)->where('stock_movements.item_id', $resolvedId)->select($movementSelect)->get();
            if ($movements->isEmpty()) {
                // stock_movements is posted per document-line (aggregate qty), not per item —
                // mutateBalance() does not populate item_id on the movement row. Fall back to
                // resolving the line(s) this item was actually scanned/matched into, then the
                // movement(s) posted for those lines, so item-level trace-by-code still works.
                $lineIds = DB::table('inbound_scan_entries')->where('item_id', $resolvedId)->pluck('inbound_line_id')
                    ->merge(DB::table('outbound_item_matches')->where('item_id', $resolvedId)->pluck('outbound_line_id'))
                    ->unique();
                if ($lineIds->isNotEmpty()) {
                    $movements = (clone $movementQuery)->whereIn('stock_movements.stock_document_line_id', $lineIds)->select($movementSelect)->get();
                }
            }
        } elseif ($resolvedType === 'SKU') {
            $movements = (clone $movementQuery)->where('stock_movements.product_id', $resolvedId)->select($movementSelect)->get();
        } elseif ($resolvedType === 'CONTAINER') {
            $movements = (clone $movementQuery)->where('stock_movements.container_id', $resolvedId)->select($movementSelect)->get();
        }

        $containerItems = collect();
        if ($resolvedType === 'ITEM') {
            $containerItems = DB::table('container_items')
                ->leftJoin('containers', 'container_items.container_id', '=', 'containers.container_id')
                ->where('container_items.item_id', $resolvedId)
                ->where('container_items.is_active', true)
                ->select('container_items.*', 'containers.container_code', 'containers.container_type')
                ->get();
        } elseif ($resolvedType === 'CONTAINER') {
            $containerItems = DB::table('container_items')
                ->leftJoin('containers', 'container_items.container_id', '=', 'containers.container_id')
                ->where('container_items.container_id', $resolvedId)
                ->where('container_items.is_active', true)
                ->select('container_items.*', 'containers.container_code', 'containers.container_type')
                ->get();
        }

        $warrantyHistory = $resolvedType === 'ITEM'
            ? $this->traceWarrantyHistory($resolvedId, $request)
            : collect();

        return $this->respondSuccess([
            'physical_code' => $physicalCode,
            'resolved_type' => $resolvedType,
            'resolved_id' => $resolvedId,
            'resolved_by' => $resolvedBy,
            'resolved_object' => $resolvedObject,
            'movements' => $movements,
            'container_items' => $containerItems,
            'warranty_history' => $warrantyHistory,
        ]);
    }

    /**
     * Mục 6 (yêu cầu vận hành): "mã sản phẩm/ID quét phải đi xuyên suốt từ nhập
     * đến xuất, bảo hành, hoàn trả để tra cứu được vòng đời sản phẩm". Nối tiếp
     * `movements` (nhập/xuất/điều chỉnh) bằng lịch sử bảo hành của CÙNG item —
     * liên kết thật là `warranty_case.item_id` (bảng CurrentBaselineWarrantyController
     * dùng để tạo case), KHÔNG phải `inventory_serial_id` (cột base cũ, luôn NULL ở
     * flow tạo case hiện hành — xem createWarrantyCase()). Mỗi case kèm timeline
     * warranty_event riêng để FE vẽ được diễn biến trong ca đó.
     *
     * PII (customer_name/phone) bị mask khi actor thiếu warranty.pii.view — cùng
     * quy tắc BR-TRACE/§13.8 đã áp dụng cho outbound-by-agency.
     */
    private function traceWarrantyHistory(string $itemId, Request $request): Collection
    {
        $cases = DB::table('warranty_case')
            ->leftJoin('defect_catalog', 'warranty_case.defect_id', '=', 'defect_catalog.defect_id')
            ->where('warranty_case.item_id', $itemId)
            ->orderByDesc('warranty_case.received_at')
            ->select(
                'warranty_case.warranty_case_id', 'warranty_case.warranty_case_code',
                'warranty_case.status', 'warranty_case.identification_status',
                'warranty_case.customer_name', 'warranty_case.customer_phone',
                'warranty_case.description', 'warranty_case.confirmed_defect',
                'warranty_case.received_at', 'warranty_case.completed_at', 'warranty_case.returned_at',
                'warranty_case.created_at', 'defect_catalog.defect_name'
            )
            ->get();

        if ($cases->isEmpty()) {
            return collect();
        }

        $showContact = (bool) $request->user()?->hasPermission('warranty.pii.view');
        $events = DB::table('warranty_event')
            ->whereIn('warranty_case_id', $cases->pluck('warranty_case_id'))
            ->orderBy('event_at')
            ->get()
            ->groupBy('warranty_case_id');

        return $cases->map(function ($case) use ($events, $showContact) {
            $case = (array) $case;
            $case['customer_name'] = $showContact || empty($case['customer_name'])
                ? $case['customer_name']
                : WarrantyPiiMasker::name((string) $case['customer_name']);
            $case['customer_phone'] = $showContact || empty($case['customer_phone'])
                ? $case['customer_phone']
                : WarrantyPiiMasker::phone((string) $case['customer_phone']);
            $case['events'] = ($events->get($case['warranty_case_id']) ?? collect())->values();

            return $case;
        });
    }

    /**
     * REQ-SRS-030 / BR-TRACE-01..06: truy vết "theo SKU/item/carton/box/tray/code".
     * Mã người dùng nhập ở TRACE-01 có thể là physical code (barcode/QR/RFID), item
     * code, serial, mã container (carton/box/tray) hoặc mã SKU — resolver phải thử đủ
     * bốn nguồn thay vì chỉ physical_codes, nếu không item chưa gán tem sẽ trả 404.
     *
     * So khớp không phân biệt hoa thường: physical_codes.code_value được chuẩn hoá
     * strtoupper lúc gán nên so sánh nguyên văn sẽ trượt khi người dùng gõ thường.
     *
     * @return array{0: ?string, 1: ?string, 2: ?object, 3: string}|null
     */
    private function resolveTraceTarget(string $codeValue): ?array
    {
        // DEC-QR-001 / AC-QR-07: raw composite PRODUCT QR phải qua parser trước —
        // trace chạy theo normalized item_unique, không bao giờ theo raw payload.
        $parser = app(ProductQrPayloadParser::class);
        if ($parser->looksComposite($codeValue)) {
            $parsed = $parser->parse($codeValue);
            if ($parsed->parseStatus === ProductQrPayload::STATUS_UNSUPPORTED_VERSION) {
                ApiError::abort(422, 'UNSUPPORTED_PAYLOAD_VERSION', 'Version cấu trúc QR không được hỗ trợ.', [
                    'payload_version' => $parsed->payloadVersion,
                ]);
            }
            if (! $parsed->isValid()) {
                ApiError::abort(422, 'INVALID_PAYLOAD', 'QR/Barcode composite sai cấu trúc; không thể parse SKU + item unique.');
            }
            // SKU embedded phải tồn tại/ACTIVE (Change Pack §5: not found -> REJECT).
            app(ProductIdentityResolver::class)->requireActiveSkuByCode((string) $parsed->skuCode);
            $codeValue = (string) $parsed->normalizedCodeValue;
        }

        $needle = mb_strtolower($codeValue);

        $physicalCode = DB::table('physical_codes')
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('LOWER(CAST(code_value AS TEXT)) = ?', [$needle])
                    ->orWhereRaw('LOWER(CAST(hardware_uid AS TEXT)) = ?', [$needle]);
            })
            ->first();

        if ($physicalCode !== null) {
            if ($physicalCode->item_id) {
                return ['ITEM', (string) $physicalCode->item_id, $physicalCode, 'physical_code'];
            }
            if ($physicalCode->container_id) {
                return ['CONTAINER', (string) $physicalCode->container_id, $physicalCode, 'physical_code'];
            }
            if ($physicalCode->assigned_object_type && $physicalCode->assigned_object_id) {
                return [
                    (string) $physicalCode->assigned_object_type,
                    (string) $physicalCode->assigned_object_id,
                    $physicalCode,
                    'physical_code',
                ];
            }

            return [null, null, $physicalCode, 'physical_code'];
        }

        $item = DB::table('item_instances')
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('LOWER(CAST(item_code AS TEXT)) = ?', [$needle])
                    ->orWhereRaw('LOWER(CAST(serial_number AS TEXT)) = ?', [$needle]);
            })
            ->first();
        if ($item !== null) {
            return ['ITEM', (string) $item->item_id, $this->primaryPhysicalCodeOfItem((string) $item->item_id), 'item_code'];
        }

        $container = DB::table('containers')
            ->whereRaw('LOWER(CAST(container_code AS TEXT)) = ?', [$needle])
            ->first();
        if ($container !== null) {
            return ['CONTAINER', (string) $container->container_id, null, 'container_code'];
        }

        $sku = DB::table('sku')
            ->whereRaw('LOWER(CAST(sku_code AS TEXT)) = ?', [$needle])
            ->first();
        if ($sku !== null) {
            return ['SKU', (string) $sku->sku_id, null, 'sku_code'];
        }

        return null;
    }

    private function primaryPhysicalCodeOfItem(string $itemId): ?object
    {
        return DB::table('physical_codes')
            ->where('assigned_object_type', 'ITEM')
            ->where('assigned_object_id', $itemId)
            ->orderByDesc('is_primary')
            ->orderByDesc('assigned_at')
            ->first();
    }

    /**
     * Annex C getInventoryTrace: GET /inventory/trace/{code} — cùng nghiệp vụ với
     * trace-by-code nhưng mã nằm ở path thay vì query.
     */
    public function traceByPathCode(Request $request, string $code)
    {
        $request->merge(['code' => $code]);

        return $this->traceByPhysicalCode($request);
    }

    #[OA\Get(
        path: '/api/v1/stock-movements/reversible',
        operationId: 'stock_movement_reversible',
        summary: 'Lookup posted movements that can still be reversed, with dependency guard preflight',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reversible_only', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reversible movements with lines, quantities, and dependency guard results'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function reversible(Request $request)
    {
        $query = DB::table('stock_movements')
            ->leftJoin('warehouses', 'stock_movements.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('stock_documents', 'stock_movements.stock_document_id', '=', 'stock_documents.id')
            // DEC-REV-COMPONENT-001 (WWHN-266 · BE266-D03): movement COMPONENT_ISSUE
            // cũng đảo được; chứng từ gốc của nó nằm ở component_issue_documents
            // (qua source_id) nên version If-Match phải COALESCE từ bảng đó.
            ->leftJoin('component_issue_documents', function ($join): void {
                $join->on('stock_movements.source_id', '=', 'component_issue_documents.id')
                    ->where('stock_movements.movement_type', '=', 'COMPONENT_ISSUE');
            })
            ->leftJoin('sku', 'stock_movements.product_id', '=', 'sku.sku_id')
            ->whereIn('stock_movements.movement_type', ['RECEIPT', 'ISSUE', 'COMPONENT_ISSUE'])
            ->select([
                'stock_movements.id',
                'stock_movements.movement_no',
                'stock_movements.movement_type',
                'stock_movements.source_type',
                'stock_movements.source_id',
                'stock_movements.warehouse_id',
                'stock_movements.product_id',
                'stock_movements.stock_status',
                'stock_movements.quantity',
                'stock_movements.occurred_at',
                'stock_movements.created_by',
                'stock_movements.stock_document_id',
                DB::raw('COALESCE(stock_documents.version, component_issue_documents.version, 1) AS version'),
                'warehouses.name as warehouse_name',
                'warehouses.code as warehouse_code',
                'sku.sku_code',
                'sku.sku_name',
                'sku.unit',
                DB::raw('COALESCE(sku.is_serial_tracked, false) AS is_serial_tracked'),
            ])
            ->orderByDesc('stock_movements.occurred_at');

        if ($request->filled('search')) {
            $keyword = '%'.mb_strtolower((string) $request->input('search')).'%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('LOWER(CAST(stock_movements.movement_no AS TEXT)) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(CAST(sku.sku_code AS TEXT)) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(CAST(sku.sku_name AS TEXT)) LIKE ?', [$keyword]);
            });
        }

        $movements = $query->paginate($this->perPage($request));

        $movementIds = collect($movements->items())->pluck('id')->all();

        $reversedQtys = [];
        if ($movementIds) {
            $reversed = DB::table('reversal_lines as rl')
                ->join('reversal_documents as rd', 'rd.id', '=', 'rl.reversal_document_id')
                ->whereIn('rl.original_movement_id', $movementIds)
                ->where('rd.status', 'POSTED')
                ->groupBy('rl.original_movement_id')
                ->select('rl.original_movement_id', DB::raw('COALESCE(SUM(rl.quantity), 0) as total_reversed'))
                ->get();
            foreach ($reversed as $row) {
                $reversedQtys[$row->original_movement_id] = (int) $row->total_reversed;
            }
        }

        $serialDocIds = collect($movements->items())
            ->filter(fn ($mv) => $mv->is_serial_tracked && $mv->stock_document_id)
            ->pluck('stock_document_id')
            ->unique()
            ->all();
        $itemDependencyMap = $this->buildItemDependencyMap($serialDocIds);
        $itemCandidateMap = $this->buildItemCandidateMap($serialDocIds);
        $componentDocIds = collect($movements->items())
            ->filter(fn ($mv) => $mv->is_serial_tracked && $mv->movement_type === 'COMPONENT_ISSUE' && $mv->source_id)
            ->pluck('source_id')
            ->unique()
            ->all();
        $componentCandidateMap = $this->buildComponentItemCandidateMap($componentDocIds);
        $availableByBucket = $this->buildAvailableByBucket(collect($movements->items()));

        $result = [];
        foreach ($movements->items() as $mv) {
            $originalQty = abs((int) $mv->quantity);
            $reversedQty = $reversedQtys[$mv->id] ?? 0;
            $reversibleQty = $originalQty - $reversedQty;

            if ($request->boolean('reversible_only') && $reversibleQty <= 0) {
                continue;
            }

            $docDepGuard = null;
            if ($mv->is_serial_tracked && $mv->stock_document_id) {
                $docDepGuard = $itemDependencyMap[$mv->stock_document_id] ?? null;
            } elseif ($mv->movement_type === 'RECEIPT' && $reversibleQty > 0) {
                // BR-REV-03/04 cho SKU quantity-mode: phần đã bị Issue/Component Issue
                // tiêu thụ sau khi nhập không còn đảo trực tiếp được. Preflight phải nói
                // đúng điều backend sẽ enforce, nếu không REV-01 hiện "Reversible" rồi
                // Post mới 409.
                $bucketKey = $this->bucketKey($mv->warehouse_id, $mv->product_id, $mv->stock_status);
                if ((int) ($availableByBucket[$bucketKey] ?? 0) < $reversibleQty) {
                    $docDepGuard = 'DOWNSTREAM_CONSUMPTION_EXISTS';
                }
            }

            $canReverse = $reversibleQty > 0 && $docDepGuard === null;

            $result[] = [
                'id' => $mv->id,
                'movement_code' => $mv->movement_no,
                'movement_type' => $mv->movement_type,
                'source_document_type' => $mv->source_type,
                'source_document_id' => $mv->source_id ?? $mv->stock_document_id,
                'source_document_code' => $mv->movement_no,
                'warehouse_id' => $mv->warehouse_id,
                'warehouse_name' => $mv->warehouse_name ?? '-',
                'posted_at' => $mv->occurred_at,
                'version' => (string) $mv->version,
                'dependency_guard' => $docDepGuard,
                'can_reverse' => $canReverse,
                // BR-REV/AC-GWT-REV-001: SKU serial-tracked bắt buộc reverse theo
                // item (quantity=1 + item_id) — FE cần biết cờ này và ứng viên item.
                'is_serial_tracked' => (bool) $mv->is_serial_tracked,
                'lines' => [
                    [
                        'id' => $mv->id,
                        'sku' => $mv->sku_code ?? '',
                        'sku_name' => $mv->sku_name ?? '-',
                        'original_qty' => $originalQty,
                        'reversed_qty' => $reversedQty,
                        'reversible_qty' => $reversibleQty,
                        'remaining_qty' => $reversibleQty,
                        'uom' => $mv->unit ?? '',
                        'dependency_guard' => $docDepGuard,
                        'can_reverse' => $canReverse,
                        'is_serial_tracked' => (bool) $mv->is_serial_tracked,
                        'items' => match (true) {
                            ! (bool) $mv->is_serial_tracked => [],
                            // Serial component: ứng viên lấy từ component_issue_items
                            // (item còn ISSUED), không phải outbound_item_matches.
                            $mv->movement_type === 'COMPONENT_ISSUE' => array_values(array_filter(
                                $componentCandidateMap[$mv->source_id] ?? [],
                                fn (array $item): bool => (string) $item['sku_id'] === (string) $mv->product_id
                            )),
                            (bool) $mv->stock_document_id => array_values(array_filter(
                                $itemCandidateMap[$mv->stock_document_id] ?? [],
                                fn (array $item): bool => (string) $item['sku_id'] === (string) $mv->product_id
                            )),
                            default => [],
                        },
                    ],
                ],
            ];
        }

        return $this->respondSuccess($result);
    }

    /**
     * Ứng viên item cho Reverse theo document serial-tracked: ISSUE → item ISSUED
     * (khách trả về), RECEIPT → item còn IN_STOCK/NEW (chưa downstream).
     *
     * @param  list<string>  $stockDocIds
     * @return array<string, list<array{item_id: string, sku_id: string, item_code: ?string, serial_number: ?string, item_status: string}>>
     */
    private function buildItemCandidateMap(array $stockDocIds): array
    {
        if (empty($stockDocIds)) {
            return [];
        }

        $map = [];
        $outboundItems = DB::table('item_instances')
            ->join('outbound_item_matches', 'outbound_item_matches.item_id', '=', 'item_instances.item_id')
            ->join('stock_document_lines', 'stock_document_lines.id', '=', 'outbound_item_matches.outbound_line_id')
            ->whereIn('stock_document_lines.stock_document_id', $stockDocIds)
            ->where('item_instances.item_status', 'ISSUED')
            ->get([
                'stock_document_lines.stock_document_id',
                'item_instances.item_id',
                'item_instances.sku_id',
                'item_instances.item_code',
                'item_instances.serial_number',
                'item_instances.item_status',
            ]);
        $inboundItems = DB::table('item_instances')
            ->join('inbound_scan_entries', 'inbound_scan_entries.item_id', '=', 'item_instances.item_id')
            ->join('stock_document_lines', 'stock_document_lines.id', '=', 'inbound_scan_entries.inbound_line_id')
            ->whereIn('stock_document_lines.stock_document_id', $stockDocIds)
            ->whereIn('item_instances.item_status', ['IN_STOCK', 'NEW'])
            ->get([
                'stock_document_lines.stock_document_id',
                'item_instances.item_id',
                'item_instances.sku_id',
                'item_instances.item_code',
                'item_instances.serial_number',
                'item_instances.item_status',
            ]);

        foreach ($outboundItems->merge($inboundItems) as $row) {
            $map[(string) $row->stock_document_id][] = [
                'item_id' => (string) $row->item_id,
                'sku_id' => (string) $row->sku_id,
                'item_code' => $row->item_code,
                'serial_number' => $row->serial_number,
                'item_status' => (string) $row->item_status,
            ];
        }

        return $map;
    }

    /**
     * DEC-REV-COMPONENT-001: ứng viên item cho Reverse Component Issue serial-tracked.
     * Item được chọn nằm ở component_issue_items (ACTIVE lúc scan → POSTED sau Post)
     * và chỉ item còn ISSUED mới nhận return (đã đảo thì item về IN_STOCK và tự
     * rời khỏi danh sách này — cùng nguyên lý buildItemCandidateMap cho ISSUE).
     *
     * @param  list<string>  $componentDocIds
     * @return array<string, list<array{item_id: string, sku_id: string, item_code: ?string, serial_number: ?string, item_status: string}>>
     */
    private function buildComponentItemCandidateMap(array $componentDocIds): array
    {
        if (empty($componentDocIds)) {
            return [];
        }

        $rows = DB::table('item_instances')
            ->join('component_issue_items', 'component_issue_items.item_id', '=', 'item_instances.item_id')
            ->join('component_issue_lines', 'component_issue_lines.id', '=', 'component_issue_items.component_issue_line_id')
            ->whereIn('component_issue_lines.component_issue_document_id', $componentDocIds)
            ->whereIn('component_issue_items.status', ['ACTIVE', 'POSTED'])
            ->where('item_instances.item_status', 'ISSUED')
            ->get([
                'component_issue_lines.component_issue_document_id',
                'item_instances.item_id',
                'item_instances.sku_id',
                'item_instances.item_code',
                'item_instances.serial_number',
                'item_instances.item_status',
            ]);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row->component_issue_document_id][] = [
                'item_id' => (string) $row->item_id,
                'sku_id' => (string) $row->sku_id,
                'item_code' => $row->item_code,
                'serial_number' => $row->serial_number,
                'item_status' => (string) $row->item_status,
            ];
        }

        return $map;
    }

    private function bucketKey(?string $warehouseId, ?string $skuId, ?string $stockStatus): string
    {
        return ($warehouseId ?? '').'|'.($skuId ?? '').'|'.($stockStatus ?: 'AVAILABLE');
    }

    /**
     * Tồn khả dụng theo bucket (warehouse, sku, stock_status) cho các movement đang xét.
     *
     * @param  Collection<int, object>  $movements
     * @return array<string, int>
     */
    private function buildAvailableByBucket($movements): array
    {
        $warehouseIds = $movements->pluck('warehouse_id')->filter()->unique()->all();
        $skuIds = $movements->pluck('product_id')->filter()->unique()->all();
        if ($warehouseIds === [] || $skuIds === []) {
            return [];
        }

        $rows = DB::table('inventory_balances')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $skuIds)
            ->get(['warehouse_id', 'product_id', 'stock_status', 'available_qty']);

        $map = [];
        foreach ($rows as $row) {
            $map[$this->bucketKey($row->warehouse_id, $row->product_id, $row->stock_status)] = (int) $row->available_qty;
        }

        return $map;
    }

    /** @param list<string> $stockDocIds */
    private function buildItemDependencyMap(array $stockDocIds): array
    {
        if (empty($stockDocIds)) {
            return [];
        }

        $blockedByInbound = DB::table('item_instances')
            ->join('inbound_scan_entries', 'inbound_scan_entries.item_id', '=', 'item_instances.item_id')
            ->join('stock_document_lines', 'stock_document_lines.id', '=', 'inbound_scan_entries.inbound_line_id')
            ->whereIn('stock_document_lines.stock_document_id', $stockDocIds)
            ->whereNotIn('item_instances.item_status', ['IN_STOCK', 'NEW', 'ADJUSTED_OUT'])
            ->groupBy('stock_document_lines.stock_document_id')
            ->select('stock_document_lines.stock_document_id', DB::raw("'ITEM_ALREADY_USED_BY_DOWNSTREAM' AS guard_reason"))
            ->get();

        $blockedByOutbound = DB::table('item_instances')
            ->join('outbound_item_matches', 'outbound_item_matches.item_id', '=', 'item_instances.item_id')
            ->join('stock_document_lines', 'stock_document_lines.id', '=', 'outbound_item_matches.outbound_line_id')
            ->whereIn('stock_document_lines.stock_document_id', $stockDocIds)
            ->whereNotIn('item_instances.item_status', ['ISSUED', 'RETURNED_TO_CUSTOMER'])
            ->groupBy('stock_document_lines.stock_document_id')
            ->select('stock_document_lines.stock_document_id', DB::raw("'ITEM_STATUS_NOT_REVERSIBLE' AS guard_reason"))
            ->get();

        $map = [];
        foreach ($blockedByInbound as $row) {
            $map[$row->stock_document_id] = $row->guard_reason;
        }
        foreach ($blockedByOutbound as $row) {
            if (! isset($map[$row->stock_document_id])) {
                $map[$row->stock_document_id] = $row->guard_reason;
            }
        }

        return $map;
    }
}
