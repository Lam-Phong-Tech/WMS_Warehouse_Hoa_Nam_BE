<?php

namespace Modules\Warehouse\Services;

use App\Support\Database\SchemaCache;
use App\Support\PublicApiCursor;
use App\Support\PublicApiError;
use App\Support\PublicApiErrorCode;
use App\Support\SqliteUnaccentFunction;
use App\Support\VietnameseText;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Support\PvRelations;

/**
 * Read model của storefront công khai `/api/v1/public/*`.
 *
 * TÁCH KHỎI PvCatalogReadModel một cách có chủ đích. Hai read model đọc gần
 * cùng bộ bảng nhưng thi hành hai hợp đồng NGƯỢC NHAU ở hai điểm cốt lõi:
 *
 * | | /api/v1/pv (WWHN-252) | /api/v1/public (App PV mới) |
 * |---|---|---|
 * | Tồn 0 | vẫn hiện, nhãn "Chưa có hàng" (DEC-CATALOG-001) | ẩn hẳn |
 * | Số tồn | trả `available_qty` | KHÔNG bao giờ trả |
 *
 * Gộp chung sẽ buộc một trong hai hợp đồng phải nhượng bộ, và Wwhn252PvContractTest
 * đang khoá vế trái. Nên chúng sống song song.
 *
 * ĐIỂM CHẶN RÒ RỈ: mọi trường ra tới client ẩn danh đều đi qua whitelist tường
 * minh trong presentProduct(). Thêm cột vào SELECT không tự động lộ ra ngoài.
 * Tuyệt đối không đưa vào đây: giá, số lượng tồn, nhà cung cấp, ghi chú nội bộ,
 * đường dẫn nguồn, cờ publish thô, hay bất kỳ dữ liệu vận hành nào
 * (item/container/movement/cost/warehouse).
 */
class PublicCatalogReadModel
{
    public const SORT_FEATURED = 'featured';

    public const SORT_UPDATED_DESC = 'updated_desc';

    public const SORT_NAME_ASC = 'name_asc';

    /** @var array<int, string> */
    public const SORTS = [self::SORT_FEATURED, self::SORT_UPDATED_DESC, self::SORT_NAME_ASC];

    /** Cache trong một request: driver không đổi giữa chừng. */
    private ?bool $hasProfileExtras = null;

    // ==================================================================
    // Listing
    // ==================================================================

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, page_info: array<string, mixed>}
     */
    /**
     * Tra CỨNG theo danh sách product_id, dùng cho màn "đã lưu" của App PV:
     * app chỉ giữ id, khi mở lại thì hỏi một lượt cho toàn bộ id đã lưu.
     *
     * Khác listProducts() ở ba điểm, và cả ba đều có chủ đích:
     *  - KHÔNG phân trang/cursor/sort: tập kết quả do client quyết định, không
     *    phải một dòng chảy vô hạn để cuộn.
     *  - Thứ tự trả về ĐÚNG THỨ TỰ id gửi lên, không theo sort của catalog —
     *    app dựng lại danh sách đã lưu theo thứ tự của người dùng.
     *  - Trả kèm `missing_ids` cho id không còn hiển thị công khai (bị ẩn, gỡ
     *    khỏi app, hoặc hết tồn). Client cần phân biệt "đã hỏi và không còn"
     *    với "chưa tải xong"; im lặng bỏ bớt phần tử thì hai việc đó nhìn y hệt.
     *
     * Điều kiện hiển thị dùng CHUNG baseQuery() với danh sách: một sản phẩm
     * không tự nhiên public hơn chỉ vì được hỏi đích danh.
     *
     * @param  list<string>  $ids  đã chuẩn hoá, đã loại trùng, giữ nguyên thứ tự
     * @return array{items: array<int, array<string, mixed>>, missing_ids: list<string>}
     */
    public function productsByIds(array $ids): array
    {
        if ($ids === []) {
            return ['items' => [], 'missing_ids' => []];
        }

        $rows = $this->baseQuery()->whereIn('s.sku_id', $ids)->get();
        $items = $this->presentMany($rows);

        // Sắp lại theo thứ tự client gửi. presentMany() đi theo thứ tự của DB,
        // còn hợp đồng là thứ tự của id đầu vào.
        $byId = [];
        foreach ($items as $item) {
            $byId[strtolower((string) $item['id'])] = $item;
        }

        $ordered = [];
        $missing = [];
        foreach ($ids as $id) {
            $key = strtolower($id);
            if (isset($byId[$key])) {
                $ordered[] = $byId[$key];
            } else {
                $missing[] = $id;
            }
        }

        return ['items' => $ordered, 'missing_ids' => $missing];
    }

    public function listProducts(array $filters, string $sort, ?string $cursor, int $limit): array
    {
        $fingerprint = PublicApiCursor::fingerprint($filters);
        $cursorKeys = $this->decodeCursor($cursor, $sort, $fingerprint);

        $keyword = VietnameseText::fold($filters['q'] ?? null);
        $order = $this->orderSpec($sort, $keyword !== '');

        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);
        $this->applyOrder($query, $order, $keyword);
        $this->applyKeyset($query, $order, $cursorKeys, $keyword);

        // Lấy dư một bản ghi để biết còn trang sau hay không mà không cần COUNT
        // toàn bảng — COUNT(*) trên mỗi lần cuộn là chi phí vô ích cho một danh
        // sách vô hạn.
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $items = $this->presentMany($rows);

        $nextCursor = null;
        if ($hasMore && $rows->isNotEmpty()) {
            $nextCursor = PublicApiCursor::encode($sort, $fingerprint, $this->cursorKeysOf($rows->last(), $order));
        }

        return [
            'items' => $items,
            'page_info' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $slug = mb_strtolower(trim($slug));
        if ($slug === '') {
            return null;
        }

        $query = $this->baseQuery();
        $query->where(function (Builder $nested) use ($slug): void {
            $nested->whereRaw('lower(s.sku_code) = ?', [$slug]);
            if ($this->profileHasExtras()) {
                $nested->orWhereRaw('lower(pp.seo_slug) = ?', [$slug]);
            }
        });

        $row = $query->first();
        if ($row === null) {
            return null;
        }

        $product = $this->presentMany(collect([$row]))[0];

        // Chi tiết bổ sung thêm phần nặng mà listing không cần tải.
        $product['specifications'] = $this->publicSpecifications($row);
        $product['variants'] = $this->variantsOf($row);
        $product['family'] = $this->familyContext($row);

        return $product;
    }

    /**
     * "Sản phẩm này có được phép nhìn thấy công khai không" — dùng chung ĐÚNG
     * baseQuery() với listing/detail, nên không thể xảy ra chuyện POST
     * /quote-requests chấp nhận một sản phẩm mà GET /products/{slug} lại 404.
     */
    public function hasVisibleProduct(string $productId): bool
    {
        return $this->baseQuery()->where('s.sku_id', $productId)->exists();
    }

    /**
     * Kiểm tra hiển thị + lấy ảnh chụp mã/tên cho NHIỀU sản phẩm bằng MỘT câu.
     *
     * Một yêu cầu tư vấn nhiều sản phẩm mà hỏi từng cái một sẽ nhân baseQuery()
     * — vốn có subquery cộng tồn kho — lên N lần cho mỗi lần bấm gửi. Trả về
     * luôn mã và tên vì cùng một hàng đó chính là "ảnh chụp tại thời điểm gửi"
     * mà bảng items cần: tra lại lần nữa lúc ghi là mở ra khe hở để hai giá trị
     * lệch nhau.
     *
     * Sản phẩm không hiển thị công khai thì KHÔNG có khoá trong mảng kết quả —
     * người gọi so khớp khoá để biết dòng nào hỏng.
     *
     * @param  array<int, string>  $productIds
     * @return array<string, array{code: string, name: string}>
     */
    public function visibleProductSnapshots(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));
        if ($productIds === []) {
            return [];
        }

        return $this->baseQuery()
            ->whereIn('s.sku_id', $productIds)
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->sku_id => [
                    'code' => (string) $row->sku_code,
                    'name' => (string) ($row->display_name ?: $row->sku_name),
                ],
            ])
            ->all();
    }

    /**
     * `variant_id` có thật sự là một biến thể của `product_id` không.
     *
     * Trong danh mục này biến thể KHÔNG phải một thực thể riêng: nó là một SKU
     * anh em cùng family (xem variantsOf()). Nên câu hỏi đúng là "hai SKU này
     * có cùng family, cùng nhãn hiệu, cùng nhóm hàng không", và dùng lại đúng
     * siblingRows() mà GET /products/{slug} dùng để dựng danh sách `variants` —
     * FE gửi lên id nào cũng phải khớp với id mà chính API đó đã đưa xuống.
     *
     * `variant_id === product_id` vẫn hợp lệ: đó là cách FE cũ nói "không chọn
     * biến thể nào cả".
     */
    public function variantBelongsToProduct(string $productId, string $variantId): bool
    {
        if ($variantId === $productId) {
            return true;
        }

        $row = $this->baseQuery()->where('s.sku_id', $productId)->first();
        if ($row === null) {
            return false;
        }

        return $this->siblingRows($row)->contains(
            fn (object $sibling): bool => (string) $sibling->sku_id === $variantId
        );
    }

    /**
     * Sản phẩm liên quan. Ba nhánh nối bằng HOẶC, theo thứ tự liên quan giảm
     * dần: cùng nhóm hàng, chia sẻ ít nhất một feature, hoặc cùng domain.
     *
     * Nhánh domain là chốt chặn để trang chi tiết không bao giờ trống: sản phẩm
     * duy nhất trong nhóm hàng của nó (ví dụ máy bơm duy nhất) mà chỉ xét
     * category + feature thì sẽ ra danh sách rỗng, và một khu "sản phẩm liên
     * quan" trống là lỗi hiển thị chứ không phải câu trả lời đúng.
     *
     * @param  array<string, mixed>  $product
     * @return array{items: array<int, array<string, mixed>>, page_info: array<string, mixed>}
     */
    public function related(array $product, ?string $cursor, int $limit): array
    {
        $filters = [
            'category' => $product['category']['code'] ?? null,
            '__related_to' => $product['id'],
        ];

        $fingerprint = PublicApiCursor::fingerprint($filters);
        $cursorKeys = $this->decodeCursor($cursor, self::SORT_FEATURED, $fingerprint);
        $order = $this->orderSpec(self::SORT_FEATURED, false);

        $query = $this->baseQuery();
        $query->where('s.sku_id', '!=', $product['id']);

        $categoryCode = $product['category']['code'] ?? null;
        $featureCodes = array_column($product['features'] ?? [], 'code');
        $domainCategoryCodes = $product['domain'] !== null
            ? $this->categoryCodesOfDomain((string) $product['domain'])
            : [];

        if ($categoryCode !== null || $featureCodes !== [] || $domainCategoryCodes !== []) {
            $query->where(function (Builder $nested) use ($categoryCode, $featureCodes, $domainCategoryCodes): void {
                if ($categoryCode !== null) {
                    $nested->orWhere('pc.category_code', $categoryCode);
                }
                if ($featureCodes !== []) {
                    $nested->orWhereExists(function ($sub) use ($featureCodes): void {
                        $sub->select(DB::raw(1))
                            ->from(PvRelations::READ_PRODUCT_TAG.' as rpt')
                            ->join(PvRelations::READ_TAG.' as rt', 'rt.tag_id', '=', 'rpt.tag_id')
                            ->whereColumn('rpt.profile_id', 'p.profile_id')
                            ->where('rt.status', 'ACTIVE')
                            ->whereIn('rt.tag_code', $featureCodes);
                    });
                }
                if ($domainCategoryCodes !== []) {
                    $nested->orWhereIn('pc.category_code', $domainCategoryCodes);
                }
            });
        }

        $this->applyOrder($query, $order, '');
        $this->applyKeyset($query, $order, $cursorKeys, '');

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $nextCursor = null;
        if ($hasMore && $rows->isNotEmpty()) {
            $nextCursor = PublicApiCursor::encode(self::SORT_FEATURED, $fingerprint, $this->cursorKeysOf($rows->last(), $order));
        }

        return [
            'items' => $this->presentMany($rows),
            'page_info' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    // ==================================================================
    // Facets / categories / home
    // ==================================================================

    /**
     * Bộ lọc động. Đếm được tính trên CÙNG tập filter đang áp dụng (trừ chính
     * facet đang đếm), nên số bên cạnh mỗi lựa chọn là số sản phẩm người dùng
     * thật sự nhận được khi bấm vào — không phải tổng toàn catalog.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function facets(array $filters): array
    {
        return [
            'domain' => $this->domainFacet($filters),
            'category' => $this->categoryFacet($filters),
            'power_source' => $this->powerSourceFacet($filters),
            'feature' => $this->featureFacet($filters),
            'spec' => $this->specFacets($filters),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function categories(?string $domain): array
    {
        $query = $this->baseQuery()
            ->whereNotNull('pc.category_code')
            ->select([])
            ->selectRaw('pc.category_code as code, max(pc.category_name) as name, count(distinct s.sku_id) as product_count')
            ->groupBy('pc.category_code');

        if ($domain !== null) {
            $codes = $this->categoryCodesOfDomain($domain);
            if ($codes === []) {
                return [];
            }
            $query->whereIn('pc.category_code', $codes);
        }

        $rows = $query->orderBy('pc.category_code')->get();

        return $rows->map(fn ($row): array => [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'domain' => $this->domainOfCategory((string) $row->code),
            'product_count' => (int) $row->product_count,
        ])->all();
    }

    /**
     * Ba domain của hợp đồng FE, kèm số sản phẩm đang bán thật.
     *
     * @return array<int, array<string, mixed>>
     */
    public function domains(): array
    {
        $counts = $this->countsByCategoryCode([]);

        $domains = [];
        foreach ((array) config('public_api.domains', []) as $code => $definition) {
            $categoryCodes = (array) ($definition['categories'] ?? []);
            $productCount = 0;
            foreach ($categoryCodes as $categoryCode) {
                $productCount += $counts[$categoryCode] ?? 0;
            }

            $domains[] = [
                'code' => (string) $code,
                'name' => (string) ($definition['name'] ?? $code),
                'description' => $definition['description'] ?? null,
                'product_count' => $productCount,
            ];
        }

        return $domains;
    }

    /**
     * Các section của Home. CHỈ trả section có dữ liệu — hợp đồng FE nói "các
     * section Home CÓ DỮ LIỆU", nên section rỗng bị loại ở BE thay vì để FE
     * render một hàng trống.
     *
     * @return array<int, array<string, mixed>>
     */
    public function homeSections(): array
    {
        $size = max(1, (int) config('public_api.home_section_size', 12));
        $sections = [];

        $featured = $this->listProducts(['featured' => true], self::SORT_FEATURED, null, $size);
        if ($featured['items'] !== []) {
            $sections[] = [
                'code' => 'FEATURED',
                'title' => 'Sản phẩm nổi bật',
                'type' => 'PRODUCT_CAROUSEL',
                'items' => $featured['items'],
            ];
        }

        $newest = $this->listProducts([], self::SORT_UPDATED_DESC, null, $size);
        if ($newest['items'] !== []) {
            $sections[] = [
                'code' => 'NEWEST',
                'title' => 'Mới cập nhật',
                'type' => 'PRODUCT_CAROUSEL',
                'items' => $newest['items'],
            ];
        }

        foreach ($this->domains() as $domain) {
            if ($domain['product_count'] === 0) {
                continue;
            }

            $items = $this->listProducts(['domain' => $domain['code']], self::SORT_FEATURED, null, $size);
            if ($items['items'] === []) {
                continue;
            }

            $sections[] = [
                'code' => 'DOMAIN_'.$domain['code'],
                'title' => $domain['name'],
                'type' => 'PRODUCT_GRID',
                'domain' => $domain['code'],
                'items' => $items['items'],
            ];
        }

        return $sections;
    }

    // ==================================================================
    // Query building
    // ==================================================================

    /**
     * Hai điều kiện hiển thị của hợp đồng mới, cả hai đều BẮT BUỘC:
     *   PUBLISHED  -> s.status = ACTIVE và s.published_to_app = true
     *   IN_STOCK   -> tổng available_qty kho tổng > 0
     *
     * Số tồn CHỈ dùng để lọc, không bao giờ đi vào SELECT của tầng trình bày.
     */
    private function baseQuery(): Builder
    {
        SqliteUnaccentFunction::ensureRegistered(DB::connection());

        $query = DB::table('sku as s')
            ->leftJoin(PvRelations::READ_PROFILE.' as p', 'p.product_id', '=', 's.sku_id')
            ->leftJoin('brands as br', 'br.id', '=', 's.brand_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 's.category_id')
            ->leftJoin('power_sources as ps', 'ps.id', '=', 's.power_source_id')
            ->leftJoin('product_models as pm', 'pm.id', '=', 's.model_id')
            ->where('s.status', 'ACTIVE')
            ->where('s.published_to_app', true)
            ->whereRaw(
                '(SELECT COALESCE(SUM(b.available_qty), 0) FROM inventory_balances b'
                ." WHERE b.product_id = s.sku_id AND b.stock_status = 'AVAILABLE') > 0"
            );

        if ($this->profileHasExtras()) {
            $query->leftJoin('pv_product_profiles as pp', 'pp.sku_id', '=', 's.sku_id');
        }

        return $query->select($this->selectColumns());
    }

    /**
     * @return array<int, mixed>
     */
    private function selectColumns(): array
    {
        $columns = [
            's.sku_id', 's.sku_code', 's.sku_name', 's.unit', 's.specification', 's.updated_at',
            's.family_name', 's.variant_label',
            'br.brand_code', 'br.brand_name',
            'pc.category_code', 'pc.category_name',
            'ps.power_source_code', 'ps.power_source_name',
            'pm.model_name',
            'p.profile_id', 'p.display_name', 'p.description', 'p.sort_order',
            DB::raw($this->featuredExpression().' as sort_featured'),
            DB::raw($this->sortOrderExpression().' as sort_order_value'),
            DB::raw($this->updatedEpochExpression().' as sort_updated'),
            DB::raw($this->foldedNameExpression().' as sort_name'),
        ];

        if ($this->profileHasExtras()) {
            $columns[] = 'pp.seo_slug';
            $columns[] = 'pp.short_description';
            $columns[] = 'pp.public_specifications';
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $keyword = VietnameseText::fold($filters['q'] ?? null);
        if ($keyword !== '') {
            $pattern = '%'.VietnameseText::escapeLike($keyword).'%';
            $folded = $this->foldedNameExpression();
            $code = $this->foldExpression('s.sku_code');
            $model = $this->foldExpression('pm.model_name');

            $query->where(function (Builder $nested) use ($folded, $code, $model, $pattern): void {
                $nested->whereRaw("{$folded} like ?", [$pattern])
                    ->orWhereRaw("{$code} like ?", [$pattern])
                    ->orWhereRaw("{$model} like ?", [$pattern]);
            });
        }

        if (! empty($filters['domain'])) {
            $codes = $this->categoryCodesOfDomain((string) $filters['domain']);
            // Domain hợp lệ nhưng chưa ánh xạ tới category nào (ví dụ HAND_TOOLS
            // khi chưa có nhóm hàng): trả rỗng, KHÔNG âm thầm bỏ qua bộ lọc.
            $query->whereIn('pc.category_code', $codes === [] ? ['__none__'] : $codes);
        }

        if (! empty($filters['category'])) {
            $query->where('pc.category_code', $filters['category']);
        }

        if (! empty($filters['power_source'])) {
            $query->where('ps.power_source_code', $filters['power_source']);
        }

        if (($filters['featured'] ?? null) === true) {
            $query->whereRaw($this->featuredExpression().' = 1');
        }

        // Nhiều `feature` = HOẶC trong cùng một facet (ngữ nghĩa faceted search
        // chuẩn): chọn thêm một feature là MỞ RỘNG kết quả, và nhờ vậy con số
        // đếm cạnh mỗi facet mới nhất quán với kết quả thật.
        $features = array_values(array_filter((array) ($filters['feature'] ?? [])));
        if ($features !== []) {
            $query->whereExists(function ($sub) use ($features): void {
                $sub->select(DB::raw(1))
                    ->from(PvRelations::READ_PRODUCT_TAG.' as fpt')
                    ->join(PvRelations::READ_TAG.' as ft', 'ft.tag_id', '=', 'fpt.tag_id')
                    ->whereColumn('fpt.profile_id', 'p.profile_id')
                    ->where('ft.status', 'ACTIVE')
                    ->whereIn('ft.tag_code', $features);
            });
        }

        foreach ((array) ($filters['spec'] ?? []) as $code => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            // $code đã được controller đối chiếu allowlist config, nên nội suy
            // vào SQL ở đây là an toàn; giá trị vẫn đi qua binding.
            $query->whereRaw($this->specExpression((string) $code).' = ?', [(string) $value]);
        }
    }

    // ------------------------------------------------------------------
    // Sắp xếp + keyset
    // ------------------------------------------------------------------

    /**
     * Mô tả thứ tự dưới dạng dữ liệu: mỗi phần tử là (biểu thức SQL, hướng, khoá
     * đọc lại từ row). ORDER BY, điều kiện keyset và nội dung cursor đều sinh ra
     * từ CÙNG mảng này, nên không thể lệch nhau.
     *
     * Cột cuối luôn là sku_id (duy nhất) để thứ tự là toàn phần — thiếu nó thì
     * hai bản ghi bằng điểm sẽ nhảy qua lại giữa các trang.
     *
     * @return array<int, array{expr: string, dir: string, key: string, ranked?: bool}>
     */
    private function orderSpec(string $sort, bool $hasQuery): array
    {
        $spec = [];

        // Hợp đồng: "ưu tiên khớp chính xác model/primary_code trước tên gần
        // đúng" — hạng khớp phải là tiêu chí ĐẦU TIÊN, đứng trên cả `sort`.
        if ($hasQuery) {
            $spec[] = ['expr' => $this->matchRankExpression(), 'dir' => 'asc', 'key' => 'sort_rank', 'ranked' => true];
        }

        $spec = array_merge($spec, match ($sort) {
            self::SORT_UPDATED_DESC => [
                ['expr' => $this->updatedEpochExpression(), 'dir' => 'desc', 'key' => 'sort_updated'],
            ],
            self::SORT_NAME_ASC => [
                ['expr' => $this->foldedNameExpression(), 'dir' => 'asc', 'key' => 'sort_name'],
            ],
            default => [
                ['expr' => $this->featuredExpression(), 'dir' => 'desc', 'key' => 'sort_featured'],
                ['expr' => $this->sortOrderExpression(), 'dir' => 'asc', 'key' => 'sort_order_value'],
                ['expr' => $this->foldedNameExpression(), 'dir' => 'asc', 'key' => 'sort_name'],
            ],
        });

        $spec[] = ['expr' => 's.sku_id', 'dir' => 'asc', 'key' => 'sku_id'];

        return $spec;
    }

    /**
     * @param  array<int, array<string, mixed>>  $order
     */
    private function applyOrder(Builder $query, array $order, string $keyword): void
    {
        foreach ($order as $column) {
            $bindings = ($column['ranked'] ?? false) ? $this->matchRankBindings($keyword) : [];
            $query->orderByRaw($column['expr'].' '.$column['dir'], $bindings);
        }

        if ($keyword !== '') {
            $query->addSelect(DB::raw($this->matchRankExpression().' as sort_rank'));
            // addSelect không mang binding theo; gắn thủ công vào đúng nhóm
            // "select" để thứ tự tham số của câu lệnh cuối vẫn đúng.
            $query->addBinding($this->matchRankBindings($keyword), 'select');
        }
    }

    /**
     * Keyset: "lấy các bản ghi đứng SAU bản ghi cuối trang trước" theo đúng thứ
     * tự nhiều cột, viết thành OR lồng nhau:
     *
     *   (c1 > v1) OR (c1 = v1 AND c2 > v2) OR (c1 = v1 AND c2 = v2 AND c3 > v3)
     *
     * Không dùng so sánh row-value `(a,b) > (x,y)` vì nó không diễn đạt được
     * trường hợp trộn ASC/DESC như sort `featured`.
     *
     * @param  array<int, array<string, mixed>>  $order
     * @param  array<int, mixed>|null  $cursorKeys
     */
    private function applyKeyset(Builder $query, array $order, ?array $cursorKeys, string $keyword): void
    {
        if ($cursorKeys === null) {
            return;
        }

        if (count($cursorKeys) !== count($order)) {
            PublicApiError::abort(400, PublicApiErrorCode::INVALID_CURSOR, 'cursor không còn phù hợp, vui lòng tải lại danh sách.');
        }

        $query->where(function (Builder $outer) use ($order, $cursorKeys, $keyword): void {
            foreach ($order as $index => $column) {
                $outer->orWhere(function (Builder $branch) use ($order, $cursorKeys, $index, $keyword): void {
                    for ($i = 0; $i < $index; $i++) {
                        $branch->whereRaw(
                            $order[$i]['expr'].' = ?',
                            array_merge($this->bindingsFor($order[$i], $keyword), [$cursorKeys[$i]])
                        );
                    }

                    $operator = $order[$index]['dir'] === 'desc' ? '<' : '>';
                    $branch->whereRaw(
                        $order[$index]['expr']." {$operator} ?",
                        array_merge($this->bindingsFor($order[$index], $keyword), [$cursorKeys[$index]])
                    );
                });
            }
        });
    }

    /**
     * @param  array<string, mixed>  $column
     * @return array<int, mixed>
     */
    private function bindingsFor(array $column, string $keyword): array
    {
        return ($column['ranked'] ?? false) ? $this->matchRankBindings($keyword) : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $order
     * @return array<int, mixed>
     */
    private function cursorKeysOf(object $row, array $order): array
    {
        $keys = [];
        foreach ($order as $column) {
            $value = $row->{$column['key']} ?? null;
            // Cursor đi qua JSON nên số phải giữ nguyên kiểu; PDO trả về chuỗi
            // cho số nguyên trên vài driver, ép lại để so sánh không bị lệch.
            $keys[] = is_numeric($value) && ! is_string($row->{$column['key']} ?? null)
                ? $value
                : (is_numeric($value) && $column['key'] !== 'sku_id' ? 0 + $value : $value);
        }

        return $keys;
    }

    private function decodeCursor(?string $cursor, string $sort, string $fingerprint): ?array
    {
        try {
            return PublicApiCursor::decode($cursor, $sort, $fingerprint);
        } catch (\InvalidArgumentException $exception) {
            PublicApiError::abort(400, PublicApiErrorCode::INVALID_CURSOR, $exception->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Biểu thức SQL (driver-aware)
    // ------------------------------------------------------------------

    /**
     * Hạng khớp, nhỏ hơn = ưu tiên hơn:
     *   0 mã sản phẩm / model trùng KHỚP CHÍNH XÁC
     *   1 tên trùng khớp chính xác
     *   2 tên bắt đầu bằng từ khoá
     *   3 còn lại (khớp mờ ở giữa chuỗi)
     */
    private function matchRankExpression(): string
    {
        $name = $this->foldedNameExpression();
        $code = $this->foldExpression('s.sku_code');
        $model = $this->foldExpression('pm.model_name');

        return "(CASE WHEN {$code} = ? THEN 0 WHEN {$model} = ? THEN 0"
            ." WHEN {$name} = ? THEN 1 WHEN {$name} like ? THEN 2 ELSE 3 END)";
    }

    /**
     * @return array<int, string>
     */
    private function matchRankBindings(string $keyword): array
    {
        return [$keyword, $keyword, $keyword, VietnameseText::escapeLike($keyword).'%'];
    }

    private function foldedNameExpression(): string
    {
        return $this->foldExpression('COALESCE(p.display_name, s.sku_name)');
    }

    /**
     * Bỏ dấu + hạ hoa thường. PostgreSQL dùng translate() dựng sẵn; SQLite dùng
     * UDF hn_unaccent đăng ký ở SqliteUnaccentFunction. Cả hai đọc CÙNG bảng ánh
     * xạ ký tự của VietnameseText nên cho kết quả giống hệt nhau.
     */
    private function foldExpression(string $column): string
    {
        if (PvRelations::isPgsql()) {
            $accented = VietnameseText::accentedChars();
            $plain = VietnameseText::plainChars();

            return "translate(lower(COALESCE({$column}, '')), '{$accented}', '{$plain}')";
        }

        return SqliteUnaccentFunction::NAME."(COALESCE({$column}, ''))";
    }

    private function featuredExpression(): string
    {
        if (! $this->profileHasExtras()) {
            // PHẢI là CAST chứ không phải hằng `0` trần: trong ORDER BY, cả
            // SQLite lẫn PostgreSQL đều hiểu một số nguyên trần là SỐ THỨ TỰ CỘT
            // của SELECT, nên `ORDER BY 0 desc` ném "ORDER BY term out of range"
            // thay vì sắp xếp theo hằng số.
            return 'CAST(0 AS INTEGER)';
        }

        return '(CASE WHEN pp.featured THEN 1 ELSE 0 END)';
    }

    private function sortOrderExpression(): string
    {
        return 'COALESCE(p.sort_order, 2147483647)';
    }

    /**
     * Thời điểm cập nhật quy về epoch (số nguyên) thay vì timestamp: giá trị đi
     * vào cursor JSON rồi quay lại làm tham số so sánh, số nguyên tránh hoàn
     * toàn chuyện lệch định dạng/timezone giữa hai driver.
     */
    private function updatedEpochExpression(): string
    {
        if (PvRelations::isPgsql()) {
            return "CAST(EXTRACT(EPOCH FROM COALESCE(s.updated_at, TIMESTAMP '1970-01-01')) AS BIGINT)";
        }

        return "CAST(strftime('%s', COALESCE(s.updated_at, '1970-01-01')) AS INTEGER)";
    }

    private function specExpression(string $code): string
    {
        if (PvRelations::isPgsql()) {
            return "s.specification->>'{$code}'";
        }

        return "json_extract(s.specification, '$.{$code}')";
    }

    /**
     * seo_slug/featured/public_specifications chỉ tồn tại ở bảng vật lý
     * pv_product_profiles của PostgreSQL — view số ít dùng chung giữa hai driver
     * không chiếu chúng ra. Thiếu thì suy biến an toàn (slug lấy từ sku_code,
     * featured = 0) chứ không vỡ.
     */
    private function profileHasExtras(): bool
    {
        if ($this->hasProfileExtras === null) {
            $this->hasProfileExtras = PvRelations::isPgsql()
                && SchemaCache::hasTable('pv_product_profiles')
                && SchemaCache::hasColumn('pv_product_profiles', 'seo_slug');
        }

        return $this->hasProfileExtras;
    }

    // ==================================================================
    // Trình bày (whitelist)
    // ==================================================================

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function presentMany($rows): array
    {
        $profileIds = $rows->pluck('profile_id')->filter()
            ->map(fn ($id): string => (string) $id)->unique()->values()->all();

        $images = $this->imagesByProfile($profileIds);
        $features = $this->featuresByProfile($profileIds);
        $industries = $this->industriesByProfile($profileIds);

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->presentProduct($row, $images, $features, $industries);
        }

        return $items;
    }

    /**
     * DANH SÁCH TRẮNG DUY NHẤT ra tới client ẩn danh.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $images
     * @param  array<string, array<int, array<string, mixed>>>  $features
     * @param  array<string, array<int, array<string, mixed>>>  $industries
     * @return array<string, mixed>
     */
    private function presentProduct(object $row, array $images, array $features, array $industries): array
    {
        $profileId = $row->profile_id !== null ? (string) $row->profile_id : null;
        $categoryCode = $row->category_code !== null ? (string) $row->category_code : null;

        return [
            'id' => (string) $row->sku_id,
            'slug' => $this->slugOf($row),
            'name' => (string) ($row->display_name ?: $row->sku_name),
            'short_description' => $this->nullableString($row->short_description ?? null),
            'description' => $this->nullableString($row->description ?? null),
            // primary_code/model là hai trường FE dùng để hiện "mã hàng" và để
            // người dùng tìm chính xác; chúng là mã catalog công khai, không phải
            // mã nội bộ kho.
            'primary_code' => (string) $row->sku_code,
            'model' => $this->nullableString($row->model_name ?? null),
            'unit' => $this->nullableString($row->unit ?? null),
            'domain' => $categoryCode !== null ? $this->domainOfCategory($categoryCode) : null,
            'brand' => $this->reference($row->brand_code ?? null, $row->brand_name ?? null),
            'category' => $this->reference($categoryCode, $row->category_name ?? null),
            'power_source' => $this->reference($row->power_source_code ?? null, $row->power_source_name ?? null),
            'features' => $profileId !== null ? ($features[$profileId] ?? []) : [],
            'industries' => $profileId !== null ? ($industries[$profileId] ?? []) : [],
            'images' => $profileId !== null ? ($images[$profileId] ?? []) : [],
            'is_featured' => (int) ($row->sort_featured ?? 0) === 1,
            'updated_at' => $this->epochToIso($row->sort_updated ?? null),
        ];
    }

    private function slugOf(object $row): string
    {
        $seoSlug = $this->nullableString($row->seo_slug ?? null);

        // sku_code đã được kiểm là URL-safe trong dữ liệu hiện tại; hạ hoa
        // thường là đủ để có slug ổn định, duy nhất và tra ngược được bằng một
        // phép so sánh chính xác trong SQL.
        return $seoSlug !== null ? mb_strtolower($seoSlug) : mb_strtolower((string) $row->sku_code);
    }

    /**
     * @return array<string, string>|null
     */
    private function reference(?string $code, ?string $name): ?array
    {
        if ($code === null && $name === null) {
            return null;
        }

        return ['code' => (string) $code, 'name' => (string) ($name ?? $code)];
    }

    /**
     * Chỉ những khoá spec nằm trong allowlist config mới ra ngoài.
     * `sku.specification` là JSON tự do của vận hành — trả nguyên khối là lộ
     * mọi khoá nội bộ ai đó lỡ ghi vào.
     *
     * @return array<int, array<string, mixed>>
     */
    private function publicSpecifications(object $row): array
    {
        $allowed = (array) config('public_api.spec_facets', []);
        $source = $this->decodeJson($row->specification ?? null)
            + $this->decodeJson($row->public_specifications ?? null);

        $specs = [];
        foreach ($allowed as $code => $label) {
            $value = $source[$code] ?? null;
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $specs[] = [
                'code' => (string) $code,
                'label' => (string) $label,
                'value' => is_scalar($value) ? (string) $value : null,
            ];
        }

        return $specs;
    }

    /**
     * Biến thể trong cùng family (Hand Tools DongCheng, P0). `family_name` là
     * cột mô tả trên chính `sku` — không có bảng family riêng — nên "cùng
     * family" được suy ra bằng (brand_code, category_code, family_name) ngay
     * tại đây. SKU không có family_name (mọi SKU trước P0, và SKU Hand Tools
     * đơn lẻ không thuộc nhóm nào) suy biến về đúng MỘT biến thể mặc định như
     * hành vi cũ, để FE không phải xử lý hai hình dạng response khác nhau.
     *
     * dùng lại baseQuery() cho truy vấn sibling nên một variant KHÔNG published
     * hoặc HẾT HÀNG sẽ tự động vắng mặt khỏi danh sách — đúng yêu cầu "variant
     * không public/không còn hàng thì không được selectable".
     *
     * @return Collection<int, object>
     */
    private function siblingRows(object $row): Collection
    {
        $familyName = $this->nullableString($row->family_name ?? null);
        if ($familyName === null) {
            return collect([$row]);
        }

        $query = $this->baseQuery()
            ->where('s.family_name', $familyName)
            ->orderByRaw('COALESCE(s.variant_label, s.sku_code)');

        $this->whereMatches($query, 'br.brand_code', $row->brand_code ?? null);
        $this->whereMatches($query, 'pc.category_code', $row->category_code ?? null);

        return $query->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function variantsOf(object $row): array
    {
        return $this->siblingRows($row)->map(fn (object $sibling): array => [
            'id' => (string) $sibling->sku_id,
            'code' => (string) $sibling->sku_code,
            'name' => (string) ($sibling->display_name ?? null ?: $sibling->sku_name),
            'variant_label' => $this->nullableString($sibling->variant_label ?? null),
            'unit' => $this->nullableString($sibling->unit ?? null),
            'is_default' => (string) $sibling->sku_id === (string) $row->sku_id,
        ])->values()->all();
    }

    /**
     * Ngữ cảnh family cho trang chi tiết — null khi SKU không thuộc family nào
     * (giữ đúng hợp đồng cũ, FE không cần hiển thị khối family).
     *
     * @return array<string, mixed>|null
     */
    private function familyContext(object $row): ?array
    {
        $familyName = $this->nullableString($row->family_name ?? null);
        if ($familyName === null) {
            return null;
        }

        return [
            'name' => $familyName,
            'variant_count' => $this->siblingRows($row)->count(),
        ];
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function imagesByProfile(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $rows = DB::table('pv_product_images')
            ->whereIn('profile_id', $profileIds)
            ->where(function ($nested): void {
                $nested->whereNull('status')->orWhere('status', 'ACTIVE');
            })
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->get(['profile_id', 'image_url', 'storage_url', 'alt_text', 'is_primary']);

        $grouped = [];
        foreach ($rows as $row) {
            $url = $row->image_url ?: $row->storage_url;
            if (! $url) {
                continue;
            }
            $grouped[(string) $row->profile_id][] = [
                'url' => (string) $url,
                'alt_text' => $this->nullableString($row->alt_text),
                'is_primary' => (bool) $row->is_primary,
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function featuresByProfile(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $rows = DB::table(PvRelations::READ_PRODUCT_TAG.' as pt')
            ->join(PvRelations::READ_TAG.' as t', 't.tag_id', '=', 'pt.tag_id')
            ->whereIn('pt.profile_id', $profileIds)
            ->where('t.status', 'ACTIVE')
            ->orderBy('t.sort_order')
            ->get(['pt.profile_id', 't.tag_code', 't.tag_name', 't.tag_type']);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->profile_id][] = [
                'code' => (string) $row->tag_code,
                'name' => (string) $row->tag_name,
                'type' => $this->nullableString($row->tag_type ?? null),
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function industriesByProfile(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $rows = DB::table(PvRelations::READ_PRODUCT_INDUSTRY.' as pi')
            ->join(PvRelations::READ_INDUSTRY.' as i', 'i.industry_id', '=', 'pi.industry_id')
            ->whereIn('pi.profile_id', $profileIds)
            ->where('i.status', 'ACTIVE')
            ->orderBy('i.sort_order')
            ->get(['pi.profile_id', 'i.industry_code', 'i.industry_name']);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->profile_id][] = [
                'code' => (string) $row->industry_code,
                'name' => (string) $row->industry_name,
            ];
        }

        return $grouped;
    }

    // ==================================================================
    // Facet helpers
    // ==================================================================

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function domainFacet(array $filters): array
    {
        $counts = $this->countsByCategoryCode($this->without($filters, ['domain', 'category']));

        $facet = [];
        foreach ((array) config('public_api.domains', []) as $code => $definition) {
            $count = 0;
            foreach ((array) ($definition['categories'] ?? []) as $categoryCode) {
                $count += $counts[$categoryCode] ?? 0;
            }
            if ($count === 0) {
                continue;
            }

            $facet[] = ['code' => (string) $code, 'name' => (string) ($definition['name'] ?? $code), 'count' => $count];
        }

        return $facet;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function categoryFacet(array $filters): array
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $this->without($filters, ['category']));

        $rows = $query->select([])
            ->selectRaw('pc.category_code as code, max(pc.category_name) as name, count(distinct s.sku_id) as total')
            ->whereNotNull('pc.category_code')
            ->groupBy('pc.category_code')
            ->orderByDesc('total')
            ->limit($this->maxFacetValues())
            ->get();

        return $rows->map(fn ($row): array => [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'count' => (int) $row->total,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function powerSourceFacet(array $filters): array
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $this->without($filters, ['power_source']));

        $rows = $query->select([])
            ->selectRaw('ps.power_source_code as code, max(ps.power_source_name) as name, count(distinct s.sku_id) as total')
            ->whereNotNull('ps.power_source_code')
            ->groupBy('ps.power_source_code')
            ->orderByDesc('total')
            ->limit($this->maxFacetValues())
            ->get();

        return $rows->map(fn ($row): array => [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'count' => (int) $row->total,
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function featureFacet(array $filters): array
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $this->without($filters, ['feature']));

        $rows = $query->select([])
            ->join(PvRelations::READ_PRODUCT_TAG.' as gpt', 'gpt.profile_id', '=', 'p.profile_id')
            ->join(PvRelations::READ_TAG.' as gt', 'gt.tag_id', '=', 'gpt.tag_id')
            ->where('gt.status', 'ACTIVE')
            ->selectRaw('gt.tag_code as code, max(gt.tag_name) as name, count(distinct s.sku_id) as total')
            ->groupBy('gt.tag_code')
            ->orderByDesc('total')
            ->limit($this->maxFacetValues())
            ->get();

        return $rows->map(fn ($row): array => [
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'count' => (int) $row->total,
        ])->all();
    }

    /**
     * Facet `spec.<code>`: một nhóm cho mỗi khoá spec trong allowlist, chỉ giữ
     * nhóm thật sự có giá trị.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function specFacets(array $filters): array
    {
        $facets = [];
        foreach ((array) config('public_api.spec_facets', []) as $code => $label) {
            $code = (string) $code;

            $scoped = $filters;
            unset($scoped['spec'][$code]);

            $query = $this->baseQuery();
            $this->applyFilters($query, $scoped);

            $expression = $this->specExpression($code);
            $rows = $query->select([])
                ->selectRaw("{$expression} as value, count(distinct s.sku_id) as total")
                ->whereRaw("{$expression} is not null")
                ->whereRaw("{$expression} <> ''")
                ->groupBy(DB::raw($expression))
                ->orderByDesc('total')
                ->limit($this->maxFacetValues())
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $facets[] = [
                'code' => $code,
                'label' => (string) $label,
                // FE gửi lại đúng chuỗi này làm tên tham số truy vấn.
                'param' => 'spec.'.$code,
                'values' => $rows->map(fn ($row): array => [
                    'value' => (string) $row->value,
                    'count' => (int) $row->total,
                ])->all(),
            ];
        }

        return $facets;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function countsByCategoryCode(array $filters): array
    {
        $query = $this->baseQuery();
        $this->applyFilters($query, $filters);

        $rows = $query->select([])
            ->selectRaw('pc.category_code as code, count(distinct s.sku_id) as total')
            ->whereNotNull('pc.category_code')
            ->groupBy('pc.category_code')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->code] = (int) $row->total;
        }

        return $counts;
    }

    // ==================================================================
    // Domain mapping (config-driven, xem config/public_api.php)
    // ==================================================================

    /**
     * @return array<int, string>
     */
    private function categoryCodesOfDomain(string $domain): array
    {
        $definition = (array) config('public_api.domains.'.$domain, []);

        return array_values(array_filter((array) ($definition['categories'] ?? [])));
    }

    private function domainOfCategory(string $categoryCode): ?string
    {
        foreach ((array) config('public_api.domains', []) as $code => $definition) {
            if (in_array($categoryCode, (array) ($definition['categories'] ?? []), true)) {
                return (string) $code;
            }
        }

        return null;
    }

    // ==================================================================
    // Tiện ích nhỏ
    // ==================================================================

    private function whereMatches(Builder $query, string $column, ?string $value): void
    {
        if ($value === null) {
            $query->whereNull($column);

            return;
        }

        $query->where($column, $value);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    private function without(array $filters, array $keys): array
    {
        foreach ($keys as $key) {
            unset($filters[$key]);
        }

        return $filters;
    }

    private function maxFacetValues(): int
    {
        return max(1, (int) config('public_api.max_facet_values', 50));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    private function epochToIso(mixed $epoch): ?string
    {
        if ($epoch === null || ! is_numeric($epoch)) {
            return null;
        }

        return Carbon::createFromTimestampUTC((int) $epoch)->toIso8601String();
    }
}
