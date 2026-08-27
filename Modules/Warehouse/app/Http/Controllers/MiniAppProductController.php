<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Support\PvRelations;

/**
 * Mini App read-only product lookup.
 *
 * Thin read-only adapter over the canonical SKU master (`sku` table) — no
 * create/update here, that stays on ProductController. Adds available_qty at
 * the single canonical warehouse (Kho tổng Hoa Nam) because Mini App only ever
 * operates against that one warehouse (see EnsureCanonicalInboundWarehouse /
 * MiniAppOutboundStagingController::assertActiveWarehouse for the same rule).
 */
class MiniAppProductController extends Controller
{
    use ApiResponse;

    private const CANONICAL_WAREHOUSE_CODE = 'KHO-TONG-HN';

    public function index(Request $request)
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:200'],
            'sku_type' => ['nullable', 'string', 'in:PRODUCT,COMPONENT'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $warehouseId = $this->canonicalWarehouseId();

        $query = DB::table('sku as s')
            ->leftJoin('brands as b', 'b.id', '=', 's.brand_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 's.category_id')
            ->leftJoin('product_models as m', 'm.id', '=', 's.model_id')
            ->leftJoin(PvRelations::READ_PROFILE.' as pv', 'pv.product_id', '=', 's.sku_id')
            ->select([
                's.sku_id', 's.sku_code', 's.sku_name', 's.sku_type', 's.unit',
                's.is_serial_tracked', 's.status', 's.specification',
                'b.brand_name', 'c.category_name', 'm.model_name',
                'pv.description as description',
            ])
            ->where('s.status', $validated['status'] ?? 'ACTIVE');

        if (! empty($validated['sku_type'])) {
            $query->where('s.sku_type', $validated['sku_type']);
        }

        if (! empty($validated['keyword'])) {
            $keyword = '%'.mb_strtolower(trim((string) $validated['keyword'])).'%';
            $query->where(function ($nested) use ($keyword): void {
                $nested->whereRaw('LOWER(s.sku_code) LIKE ?', [$keyword])
                    ->orWhereRaw('LOWER(s.sku_name) LIKE ?', [$keyword]);
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);
        $total = (clone $query)->count();
        $rows = $query->orderBy('s.sku_name')->forPage($page, $perPage)->get();

        $availableQtyBySku = $this->availableQtyBySku($warehouseId, $rows->pluck('sku_id')->all());

        $data = $rows->map(function (object $row) use ($availableQtyBySku): array {
            return $this->decorate((array) $row, $availableQtyBySku);
        })->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'warehouse_id' => $warehouseId,
                'request_id' => $request->attributes->get('request_id'),
                'timestamp' => now()->toIso8601String(),
            ],
        ]);
    }

    public function show(string $id)
    {
        $warehouseId = $this->canonicalWarehouseId();

        $row = DB::table('sku as s')
            ->leftJoin('brands as b', 'b.id', '=', 's.brand_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 's.category_id')
            ->leftJoin('product_models as m', 'm.id', '=', 's.model_id')
            ->leftJoin('power_sources as ps', 'ps.id', '=', 's.power_source_id')
            ->leftJoin('package_variants as pkg', 'pkg.id', '=', 's.package_variant_id')
            ->leftJoin(PvRelations::READ_PROFILE.' as pv', 'pv.product_id', '=', 's.sku_id')
            ->where('s.sku_id', $id)
            ->select([
                's.sku_id', 's.sku_code', 's.sku_name', 's.sku_type', 's.unit',
                's.is_serial_tracked', 's.status', 's.specification', 's.warranty_applicable',
                'b.brand_name', 'c.category_name', 'm.model_name',
                'ps.power_source_name', 'pkg.variant_name as package_variant_name',
                'pv.description as description', 'pv.display_name as pv_display_name',
            ])
            ->first();

        if ($row === null) {
            return $this->respondNotFound('Không tìm thấy sản phẩm.');
        }

        $availableQtyBySku = $this->availableQtyBySku($warehouseId, [$id]);

        return $this->respondSuccess($this->decorate((array) $row, $availableQtyBySku));
    }

    /** @param array<string,mixed> $row @param array<string,int> $availableQtyBySku @return array<string,mixed> */
    private function decorate(array $row, array $availableQtyBySku): array
    {
        $row['specification'] = $row['specification'] !== null
            ? json_decode((string) $row['specification'], true)
            : null;
        $row['available_qty'] = $availableQtyBySku[$row['sku_id']] ?? 0;

        return $row;
    }

    private function canonicalWarehouseId(): string
    {
        $id = DB::table('warehouses')
            ->where('code', self::CANONICAL_WAREHOUSE_CODE)
            ->where('status', 'ACTIVE')
            ->value('id');
        if ($id === null) {
            ApiError::abort(422, 'WAREHOUSE_NOT_FOUND', 'Không tìm thấy Kho tổng Hoa Nam ACTIVE.');
        }

        return (string) $id;
    }

    /** @param array<int,string> $skuIds @return array<string,int> */
    private function availableQtyBySku(string $warehouseId, array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }

        return DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $skuIds)
            ->where('stock_status', 'AVAILABLE')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(available_qty) as qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }
}
