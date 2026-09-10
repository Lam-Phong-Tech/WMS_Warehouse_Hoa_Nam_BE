<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Support\PvRelations;
use OpenApi\Attributes as OA;

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
    use CachesReadPayload;

    private const CANONICAL_WAREHOUSE_CODE = 'KHO-TONG-HN';

    #[OA\Get(
        path: '/api/v1/mini-app/products',
        operationId: 'miniAppListProducts',
        summary: 'Mini App — tra cứu SKU kèm tồn khả dụng tại Kho tổng Hoa Nam',
        description: 'Chỉ đọc. Mặc định chỉ trả SKU ACTIVE, sắp theo tên. `available_qty` là tồn AVAILABLE tại kho canonical duy nhất mà Mini App vận hành; `meta.warehouse_id` cho biết kho đó. Không có kho ACTIVE thì trả 422 WAREHOUSE_NOT_FOUND.',
        security: [['bearerAuth' => []]],
        tags: ['22. Mini App'],
        parameters: [
            new OA\Parameter(name: 'keyword', in: 'query', required: false, description: 'Tìm theo mã hoặc tên SKU, không phân biệt hoa thường.', schema: new OA\Schema(type: 'string', maxLength: 200)),
            new OA\Parameter(name: 'sku_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['PRODUCT', 'COMPONENT'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'INACTIVE'], default: 'ACTIVE')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách SKU; `meta` gồm current_page, per_page, total, last_page, warehouse_id.'),
            new OA\Response(response: 422, description: 'VALIDATION_ERROR hoặc WAREHOUSE_NOT_FOUND'),
        ]
    )]
    public function index(Request $request)
    {
        $validated = $request->validate([
            'keyword' => ['nullable', 'string', 'max:200'],
            'sku_type' => ['nullable', 'string', 'in:PRODUCT,COMPONENT'],
            'status' => ['nullable', 'string', 'in:ACTIVE,INACTIVE'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Giải kho canonical TRƯỚC cache: thiếu kho ACTIVE phải trả 422 ngay, một cache
        // hit không được nuốt mất lỗi này.
        $warehouseId = $this->canonicalWarehouseId();

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);

        // TTL 'stock_short' (20s) + version `stock:version`: payload mang available_qty,
        // tức là SỐ TỒN — mọi mutation tồn kho đi qua CurrentBaselineStockService::
        // mutateBalance() đều bump namespace này nên số tồn đổi ngay sau khi ghi sổ; 20s
        // chỉ là lưới an toàn cho đường ghi nào chưa qua điểm chốt đó. Đây là dữ liệu máy
        // quét đọc nên không được dùng TTL dài hơn. Phân trang chạy TRONG SQL (forPage)
        // nên page/per_page BẮT BUỘC nằm trong khoá.
        $cached = $this->cachedReadPayload(
            'miniapp:product:list',
            // array_merge chứ KHÔNG dùng `+`: `+` để vế trái thắng khi trùng khoá, nên một
            // `?warehouse_id=...` gửi kèm sẽ ghi đè kho canonical do server giải.
            array_merge($this->readFiltersWithPaging($request), ['warehouse_id' => $warehouseId]),
            function () use ($validated, $warehouseId, $perPage, $page): array {
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

                $total = (clone $query)->count();
                $rows = $query->orderBy('s.sku_name')->forPage($page, $perPage)->get();
                $availableQtyBySku = $this->availableQtyBySku($warehouseId, $rows->pluck('sku_id')->all());

                return [
                    'total' => $total,
                    'data' => $rows->map(fn (object $row): array => $this->decorate((array) $row, $availableQtyBySku))
                        ->values()->all(),
                ];
            },
            'stock_short',
            'stock:version',
        );

        $total = (int) $cached['total'];

        // `meta.request_id`/`timestamp` dựng NGOÀI cache để log truy vết còn dùng được.
        return response()->json([
            'success' => true,
            'data' => $cached['data'],
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

    #[OA\Get(
        path: '/api/v1/mini-app/products/{id}',
        operationId: 'miniAppGetProduct',
        summary: 'Mini App — chi tiết SKU kèm tồn khả dụng tại Kho tổng Hoa Nam',
        description: 'Chỉ đọc. Trả thương hiệu, danh mục, model, nguồn điện, quy cách đóng gói, mô tả App PV và `available_qty` tại kho canonical.',
        security: [['bearerAuth' => []]],
        tags: ['22. Mini App'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'sku_id', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Chi tiết SKU'),
            new OA\Response(response: 404, description: 'Không tìm thấy sản phẩm'),
            new OA\Response(response: 422, description: 'WAREHOUSE_NOT_FOUND'),
        ]
    )]
    public function show(string $id)
    {
        // Cùng lý do với index(): kho canonical phải giải TRƯỚC cache.
        $warehouseId = $this->canonicalWarehouseId();

        // Cùng TTL/version với index() vì payload cũng mang available_qty. Route param
        // không nằm trong query string nên phải đưa vào khoá thủ công.
        $cached = $this->cachedReadPayload(
            'miniapp:product:detail',
            ['sku_id' => $id, 'warehouse_id' => $warehouseId],
            function () use ($id, $warehouseId): array {
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
                    return ['product' => null];
                }

                return ['product' => $this->decorate((array) $row, $this->availableQtyBySku($warehouseId, [$id]))];
            },
            'stock_short',
            'stock:version',
        );

        if (($cached['product'] ?? null) === null) {
            return $this->respondNotFound('Không tìm thấy sản phẩm.');
        }

        return $this->respondSuccess($cached['product']);
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
