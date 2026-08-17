<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\StockMovement;
use OpenApi\Attributes as OA;

/** Lịch sử biến động tồn (inventory_movement) — append-only, không cho sửa/xoá. */
class StockMovementController extends Controller
{
    use ApiResponse;

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
        summary: 'Truy vết chứng từ gốc, lịch sử duyệt/hạch toán, và reversal/correction liên quan tới movement này',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Chứng từ gốc + lịch sử trạng thái + movement gốc (nếu đây là reversal) + reversal (nếu movement này đã bị đảo ngược)'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy movement'),
        ]
    )]
    public function traceSource(string $id)
    {
        $movement = StockMovement::with(['product', 'warehouse', 'creator:user_id,display_name', 'document.statusHistory', 'document.lines.product'])->find($id);

        if (! $movement) {
            return $this->respondNotFound('Không tìm thấy movement.');
        }

        $original = $movement->original_movement_id ? StockMovement::find($movement->original_movement_id) : null;
        $reversal = StockMovement::where('original_movement_id', $movement->id)->first();

        return $this->respondSuccess([
            'movement' => $movement,
            'document' => $movement->document,
            'status_history' => $movement->document?->statusHistory,
            'original_movement' => $original,
            'reversal_movement' => $reversal,
        ]);
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
        $request->validate(['code' => ['required', 'string', 'max:200']]);
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

        return $this->respondSuccess([
            'physical_code' => $physicalCode,
            'resolved_type' => $resolvedType,
            'resolved_id' => $resolvedId,
            'resolved_by' => $resolvedBy,
            'resolved_object' => $resolvedObject,
            'movements' => $movements,
            'container_items' => $containerItems,
        ]);
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
            ->leftJoin('sku', 'stock_movements.product_id', '=', 'sku.sku_id')
            ->whereIn('stock_movements.movement_type', ['RECEIPT', 'ISSUE'])
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
                DB::raw('COALESCE(stock_documents.version, 1) AS version'),
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
                        'items' => $mv->is_serial_tracked && $mv->stock_document_id
                            ? array_values(array_filter(
                                $itemCandidateMap[$mv->stock_document_id] ?? [],
                                fn (array $item): bool => (string) $item['sku_id'] === (string) $mv->product_id
                            ))
                            : [],
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

    private function bucketKey(?string $warehouseId, ?string $skuId, ?string $stockStatus): string
    {
        return ($warehouseId ?? '').'|'.($skuId ?? '').'|'.($stockStatus ?: 'AVAILABLE');
    }

    /**
     * Tồn khả dụng theo bucket (warehouse, sku, stock_status) cho các movement đang xét.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $movements
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
