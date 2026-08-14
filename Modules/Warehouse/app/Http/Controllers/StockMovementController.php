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
        path: '/api/v1/stock-movements/reversible',
        operationId: 'stock_movement_reversible',
        summary: 'Lookup posted movements that can still be reversed (partially or fully)',
        security: [['bearerAuth' => []]],
        tags: ['07. Inventory'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reversible_only', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Reversible movements with lines and quantities'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function reversible(Request $request)
    {
        $query = DB::table('stock_movements')
            ->leftJoin('warehouses', 'stock_movements.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('products', 'stock_movements.product_id', '=', 'products.id')
            ->leftJoin('stock_documents', 'stock_movements.stock_document_id', '=', 'stock_documents.id')
            ->whereIn('stock_movements.movement_type', ['RECEIPT', 'ISSUE'])
            ->select([
                'stock_movements.id',
                'stock_movements.movement_no',
                'stock_movements.movement_type',
                'stock_movements.source_type',
                'stock_movements.source_id',
                'stock_movements.warehouse_id',
                'stock_movements.product_id',
                'stock_movements.quantity',
                'stock_movements.occurred_at',
                'stock_movements.created_by',
                'stock_movements.stock_document_id',
                DB::raw('COALESCE(stock_documents.version, 1) AS version'),
                'warehouses.name as warehouse_name',
                'warehouses.code as warehouse_code',
                'products.code as sku_code',
                'products.name as sku_name',
                'products.unit',
            ])
            ->orderByDesc('stock_movements.occurred_at');

        if ($request->filled('search')) {
            $keyword = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($keyword) {
                $q->whereRaw('CAST(stock_movements.movement_no AS TEXT) ILIKE ?', [$keyword])
                  ->orWhereRaw('CAST(products.code AS TEXT) ILIKE ?', [$keyword])
                  ->orWhereRaw('CAST(products.name AS TEXT) ILIKE ?', [$keyword]);
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

        $result = [];
        foreach ($movements->items() as $mv) {
            $originalQty = abs((int) $mv->quantity);
            $reversedQty = $reversedQtys[$mv->id] ?? 0;
            $reversibleQty = $originalQty - $reversedQty;

            if ($request->boolean('reversible_only') && $reversibleQty <= 0) {
                continue;
            }

            $canReverse = $reversibleQty > 0;
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
                'dependency_guard' => null,
                'can_reverse' => $canReverse,
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
                        'dependency_guard' => null,
                        'can_reverse' => $canReverse,
                    ],
                ],
            ];
        }

        return $this->respondSuccess($result);
    }
}
