<?php

namespace Modules\Warehouse\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Support\PvRelations;

/**
 * WWHN-252 / HN-PV-001 — read model công khai của App PV.
 *
 * Hợp đồng nghiệp vụ (SRS v1.12):
 * - REQ-SRS-017: list/detail/search/filter responsive, CHỈ SKU active + published.
 * - REQ-SRS-018 + §15 OP-09: lộ ra available_qty kho tổng, stock_status và
 *   data_as_of; KHÔNG lộ serial/item/container/movement/cost hay dữ liệu vận hành.
 * - REQ-SRS-036 / DEC-CATALOG-001: publish độc lập tồn kho, tồn 0 vẫn hiển thị
 *   với nhãn "Chưa có hàng".
 * - REQ-SRS-019: gợi ý theo ngành nghề bằng rule ACTIVE, priority cấu hình.
 * - NFR-SRS-016: phát ra data_as_of + ngưỡng staleness để UI hiện trạng thái tươi/degraded.
 *
 * Ghi chú kỹ thuật: nguồn "đã publish" là `sku.published_to_app`, KHÔNG phải
 * `pv_product_profile.published`. Trên PostgreSQL view `sku` chiếu thẳng
 * pv_product_profiles.published (migration 2026_08_16_121000) nên hai giá trị
 * luôn khớp; trên sqlite publish nằm ở cột thật của bảng `sku`. Dùng
 * published_to_app là cách duy nhất cho kết quả GIỐNG NHAU trên cả hai driver.
 */
class PvCatalogReadModel
{
    public const STOCK_IN = 'IN_STOCK';

    public const STOCK_OUT = 'OUT_OF_STOCK';

    /** DEC-CATALOG-001: nhãn nghiệp vụ cho tồn 0. */
    public const LABEL_OUT = 'Chưa có hàng';

    public const LABEL_IN = 'Còn hàng';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateProducts(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->publishedProductQuery();
        $this->applyFilters($query, $filters);

        $total = (clone $query)->getCountForPagination();
        $rows = $query
            ->orderByRaw('COALESCE(p.sort_order, 2147483647) asc')
            ->orderBy('s.sku_name')
            ->orderBy('s.sku_id')
            ->forPage($page, $perPage)
            ->get();

        return $this->paginator($this->presentMany($rows), $total, $perPage, $page);
    }

    /**
     * §13.8 + Annex C getPvProduct (error set 400,404,429,500): SKU chưa publish
     * hoặc không ACTIVE phải trả 404 masked chứ không phải 403 — 403 sẽ xác nhận
     * cho người ẩn danh biết mã SKU đó có tồn tại.
     *
     * @return array<string, mixed>|null
     */
    public function findPublishedProduct(string $skuId): ?array
    {
        $row = $this->publishedProductQuery()->where('s.sku_id', $skuId)->first();
        if ($row === null) {
            return null;
        }

        $presented = $this->presentMany(collect([$row]))[0];
        $presented['specifications'] = $this->decodeSpecification($row->specification ?? null);

        return $presented;
    }

    /**
     * REQ-SRS-033 / BR-PV-MST: ngành nghề INACTIVE bị loại khỏi mặt công khai.
     */
    public function paginateIndustries(?string $keyword, int $page, int $perPage): LengthAwarePaginator
    {
        $query = DB::table(PvRelations::READ_INDUSTRY.' as i')->where('i.status', 'ACTIVE');
        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $like = $this->likeOperator();
            $query->where(function ($nested) use ($like, $keyword): void {
                $nested->where('i.industry_name', $like, '%'.$keyword.'%')
                    ->orWhere('i.industry_code', $like, '%'.$keyword.'%');
            });
        }

        $total = (clone $query)->getCountForPagination();
        $rows = $query->orderBy('i.sort_order')->orderBy('i.industry_name')
            ->forPage($page, $perPage)
            ->get(['i.industry_id', 'i.industry_code', 'i.industry_name', 'i.sort_order']);

        $items = $rows->map(fn ($row): array => [
            'industry_id' => (string) $row->industry_id,
            'industry_code' => (string) $row->industry_code,
            'industry_name' => (string) $row->industry_name,
            'sort_order' => (int) ($row->sort_order ?? 0),
        ])->all();

        return $this->paginator($items, $total, $perPage, $page);
    }

    /**
     * REQ-SRS-019 / BR-PV-05..08. Rule ACTIVE của một ngành chọn sản phẩm bằng
     * MỘT trong ba selector: SKU trực tiếp, nhóm hàng (category) hoặc tag PV.
     * Sản phẩm cuối vẫn phải active + published, và priority nhỏ hơn xếp trước.
     */
    public function paginateRecommendations(string $industryId, int $page, int $perPage): LengthAwarePaginator
    {
        $priorityBySku = $this->resolveRecommendationPriorities($industryId);
        if ($priorityBySku === []) {
            return $this->paginator([], 0, $perPage, $page);
        }

        $orderedSkuIds = array_keys($priorityBySku);
        usort($orderedSkuIds, static function (string $left, string $right) use ($priorityBySku): int {
            return [$priorityBySku[$left], $left] <=> [$priorityBySku[$right], $right];
        });

        $total = count($orderedSkuIds);
        $pageSkuIds = array_slice($orderedSkuIds, ($page - 1) * $perPage, $perPage);
        if ($pageSkuIds === []) {
            return $this->paginator([], $total, $perPage, $page);
        }

        $rows = $this->publishedProductQuery()->whereIn('s.sku_id', $pageSkuIds)->get();
        $indexed = [];
        foreach ($this->presentMany($rows) as $item) {
            $indexed[$item['sku_id']] = $item;
        }

        $items = [];
        foreach ($pageSkuIds as $skuId) {
            if (! isset($indexed[$skuId])) {
                continue;
            }
            $items[] = $indexed[$skuId] + ['recommendation_priority' => $priorityBySku[$skuId]];
        }

        return $this->paginator($items, $total, $perPage, $page);
    }

    /**
     * NFR-SRS-016 + §13.7: meta độ tươi đi kèm MỌI response PV để UI biết số tồn
     * được chụp lúc nào và ngưỡng nào thì phải báo degraded.
     *
     * @return array<string, mixed>
     */
    public function freshnessMeta(): array
    {
        return [
            'data_as_of' => Carbon::now()->toIso8601String(),
            'max_staleness_seconds' => (int) config('pv.max_staleness_seconds', 60),
            // Read model đọc thẳng projection tồn kho trong cùng transaction đọc
            // nên độ trễ đồng bộ bằng 0; giá trị này chỉ chuyển sang DEGRADED khi
            // response được phục vụ lại từ một tầng cache cũ hơn ngưỡng trên.
            'inventory_freshness' => 'LIVE',
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function publishedProductQuery(): Builder
    {
        $availableSum = '(SELECT COALESCE(SUM(b.available_qty), 0) FROM inventory_balances b'
            ." WHERE b.product_id = s.sku_id AND b.stock_status = 'AVAILABLE')";
        $balanceUpdatedAt = '(SELECT MAX(b2.updated_at) FROM inventory_balances b2'
            ." WHERE b2.product_id = s.sku_id AND b2.stock_status = 'AVAILABLE')";

        return DB::table('sku as s')
            ->leftJoin(PvRelations::READ_PROFILE.' as p', 'p.product_id', '=', 's.sku_id')
            ->leftJoin('brands as br', 'br.id', '=', 's.brand_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 's.category_id')
            ->leftJoin('power_sources as ps', 'ps.id', '=', 's.power_source_id')
            ->leftJoin('product_models as pm', 'pm.id', '=', 's.model_id')
            ->where('s.status', 'ACTIVE')
            ->where('s.published_to_app', true)
            ->select([
                's.sku_id', 's.sku_code', 's.sku_name', 's.unit', 's.specification',
                's.brand_id', 's.category_id', 's.power_source_id', 's.model_id',
                'br.brand_name', 'pc.category_name', 'ps.power_source_name', 'pm.model_name',
                'p.profile_id', 'p.display_name', 'p.description', 'p.sort_order', 'p.last_inventory_sync_at',
                DB::raw($availableSum.' as available_qty'),
                DB::raw($balanceUpdatedAt.' as balance_updated_at'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $like = $this->likeOperator();
            $query->where(function ($nested) use ($like, $keyword): void {
                $nested->where('s.sku_code', $like, '%'.$keyword.'%')
                    ->orWhere('s.sku_name', $like, '%'.$keyword.'%')
                    ->orWhere('p.display_name', $like, '%'.$keyword.'%');
            });
        }

        $directColumns = [
            'brand_id' => 's.brand_id',
            'category_id' => 's.category_id',
            'power_source_id' => 's.power_source_id',
        ];
        foreach ($directColumns as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }

        if (! empty($filters['industry_id'])) {
            $industryId = $filters['industry_id'];
            $query->whereExists(function ($sub) use ($industryId): void {
                $sub->select(DB::raw(1))
                    ->from(PvRelations::READ_PRODUCT_INDUSTRY.' as pi')
                    ->join(PvRelations::READ_INDUSTRY.' as ind', 'ind.industry_id', '=', 'pi.industry_id')
                    ->whereColumn('pi.profile_id', 'p.profile_id')
                    ->where('pi.industry_id', $industryId)
                    ->where('ind.status', 'ACTIVE');
            });
        }

        if (! empty($filters['tag_id'])) {
            $tagId = $filters['tag_id'];
            $query->whereExists(function ($sub) use ($tagId): void {
                $sub->select(DB::raw(1))
                    ->from(PvRelations::READ_PRODUCT_TAG.' as pt')
                    ->join(PvRelations::READ_TAG.' as tg', 'tg.tag_id', '=', 'pt.tag_id')
                    ->whereColumn('pt.profile_id', 'p.profile_id')
                    ->where('pt.tag_id', $tagId)
                    ->where('tg.status', 'ACTIVE');
            });
        }

        if (($filters['in_stock'] ?? null) === true) {
            $query->whereRaw('(SELECT COALESCE(SUM(b3.available_qty), 0) FROM inventory_balances b3'
                ." WHERE b3.product_id = s.sku_id AND b3.stock_status = 'AVAILABLE') > 0");
        }
    }

    /**
     * @return array<string, int> map sku_id => priority nhỏ nhất trong các rule khớp
     */
    private function resolveRecommendationPriorities(string $industryId): array
    {
        $industryIsActive = DB::table(PvRelations::READ_INDUSTRY)
            ->where('industry_id', $industryId)
            ->where('status', 'ACTIVE')
            ->exists();
        if (! $industryIsActive) {
            return [];
        }

        $rules = DB::table(PvRelations::READ_RULE)
            ->where('industry_id', $industryId)
            ->where('status', 'ACTIVE')
            ->orderBy('priority')
            ->get(['product_id', 'category_id', 'tag_id', 'priority']);

        $cap = max(1, (int) config('pv.max_recommendation_products', 500));
        $priorityBySku = [];
        $assign = static function (?string $skuId, int $priority) use (&$priorityBySku, $cap): void {
            if ($skuId === null || $skuId === '') {
                return;
            }
            if (! isset($priorityBySku[$skuId])) {
                if (count($priorityBySku) >= $cap) {
                    return;
                }
                $priorityBySku[$skuId] = $priority;

                return;
            }
            $priorityBySku[$skuId] = min($priorityBySku[$skuId], $priority);
        };

        foreach ($rules as $rule) {
            $priority = (int) $rule->priority;

            if (! empty($rule->product_id)) {
                $assign((string) $rule->product_id, $priority);
            }

            if (! empty($rule->category_id)) {
                $categorySkuIds = DB::table('sku')
                    ->where('category_id', $rule->category_id)
                    ->where('status', 'ACTIVE')
                    ->where('published_to_app', true)
                    ->limit($cap)
                    ->pluck('sku_id');
                foreach ($categorySkuIds as $skuId) {
                    $assign((string) $skuId, $priority);
                }
            }

            if (! empty($rule->tag_id)) {
                $tagSkuIds = DB::table(PvRelations::READ_PRODUCT_TAG.' as pt')
                    ->join(PvRelations::READ_PROFILE.' as pp', 'pp.profile_id', '=', 'pt.profile_id')
                    ->join('sku as s2', 's2.sku_id', '=', 'pp.product_id')
                    ->where('pt.tag_id', $rule->tag_id)
                    ->where('s2.status', 'ACTIVE')
                    ->where('s2.published_to_app', true)
                    ->limit($cap)
                    ->pluck('s2.sku_id');
                foreach ($tagSkuIds as $skuId) {
                    $assign((string) $skuId, $priority);
                }
            }
        }

        // Rule chọn SKU trực tiếp không tự bảo đảm SKU đó còn active + published.
        $candidateIds = array_keys($priorityBySku);
        if ($candidateIds === []) {
            return [];
        }

        $visible = DB::table('sku')
            ->whereIn('sku_id', $candidateIds)
            ->where('status', 'ACTIVE')
            ->where('published_to_app', true)
            ->pluck('sku_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return array_intersect_key($priorityBySku, array_flip($visible));
    }

    /**
     * Chiếu whitelist tường minh. Đây là chốt chặn §15 OP-09: chỉ những khoá liệt
     * kê ở đây mới ra tới client ẩn danh, nên thêm cột vào query cũng không vô
     * tình lộ dữ liệu vận hành (item/container/movement/cost/warehouse).
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function presentMany(Collection $rows): array
    {
        $profileIds = $rows->pluck('profile_id')->filter()
            ->map(fn ($id): string => (string) $id)->unique()->values()->all();
        $industries = $this->industriesByProfile($profileIds);
        $tags = $this->tagsByProfile($profileIds);
        $images = $this->imagesByProfile($profileIds);
        $staleness = (int) config('pv.max_staleness_seconds', 60);

        $items = [];
        foreach ($rows as $row) {
            $profileId = $row->profile_id !== null ? (string) $row->profile_id : null;
            $availableQty = (int) round((float) ($row->available_qty ?? 0));
            $syncedAt = $row->last_inventory_sync_at ?: ($row->balance_updated_at ?: null);

            $items[] = [
                'sku_id' => (string) $row->sku_id,
                'sku_code' => (string) $row->sku_code,
                'sku_name' => (string) $row->sku_name,
                'display_name' => (string) ($row->display_name ?: $row->sku_name),
                'description' => $row->description !== null ? (string) $row->description : null,
                'unit' => $row->unit !== null ? (string) $row->unit : null,
                'brand_id' => $row->brand_id !== null ? (string) $row->brand_id : null,
                'brand_name' => $row->brand_name !== null ? (string) $row->brand_name : null,
                'category_id' => $row->category_id !== null ? (string) $row->category_id : null,
                'category_name' => $row->category_name !== null ? (string) $row->category_name : null,
                'power_source_id' => $row->power_source_id !== null ? (string) $row->power_source_id : null,
                'power_source_name' => $row->power_source_name !== null ? (string) $row->power_source_name : null,
                'model_name' => $row->model_name !== null ? (string) $row->model_name : null,
                'available_qty' => $availableQty,
                'stock_status' => $availableQty > 0 ? self::STOCK_IN : self::STOCK_OUT,
                'stock_label' => $availableQty > 0 ? self::LABEL_IN : self::LABEL_OUT,
                'last_inventory_sync_at' => $this->toIso($syncedAt),
                'max_staleness_seconds' => $staleness,
                'images' => $profileId !== null ? ($images[$profileId] ?? []) : [],
                'industries' => $profileId !== null ? ($industries[$profileId] ?? []) : [],
                'tags' => $profileId !== null ? ($tags[$profileId] ?? []) : [],
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<int, array<string, string>>>
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
            ->orderBy('pi.priority')
            ->orderBy('i.sort_order')
            ->get(['pi.profile_id', 'i.industry_id', 'i.industry_code', 'i.industry_name']);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->profile_id][] = [
                'industry_id' => (string) $row->industry_id,
                'industry_code' => (string) $row->industry_code,
                'industry_name' => (string) $row->industry_name,
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<int, string>  $profileIds
     * @return array<string, array<int, array<string, string>>>
     */
    private function tagsByProfile(array $profileIds): array
    {
        if ($profileIds === []) {
            return [];
        }

        $rows = DB::table(PvRelations::READ_PRODUCT_TAG.' as pt')
            ->join(PvRelations::READ_TAG.' as t', 't.tag_id', '=', 'pt.tag_id')
            ->whereIn('pt.profile_id', $profileIds)
            ->where('t.status', 'ACTIVE')
            ->orderBy('t.sort_order')
            ->get(['pt.profile_id', 't.tag_id', 't.tag_code', 't.tag_name']);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->profile_id][] = [
                'tag_id' => (string) $row->tag_id,
                'tag_code' => (string) $row->tag_code,
                'tag_name' => (string) $row->tag_name,
            ];
        }

        return $grouped;
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
            ->orderBy('sort_order')
            ->get(['profile_id', 'image_url', 'storage_url', 'alt_text', 'is_primary', 'sort_order']);

        $grouped = [];
        foreach ($rows as $row) {
            $url = $row->image_url ?: $row->storage_url;
            if (! $url) {
                continue;
            }
            $grouped[(string) $row->profile_id][] = [
                'url' => (string) $url,
                'alt_text' => $row->alt_text !== null ? (string) $row->alt_text : null,
                'is_primary' => (bool) $row->is_primary,
            ];
        }

        return $grouped;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function paginator(array $items, int $total, int $perPage, int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
        ]);
    }

    /**
     * Bảng thông số của mặt CÔNG KHAI: khoá kỹ thuật → nhãn người mua đọc được,
     * theo đúng thứ tự người ta muốn đọc (nguồn điện → công suất → khả năng →
     * tốc độ → … → mã vạch).
     *
     * Trước đây trang chi tiết in thẳng khoá JSON của SKU nên khách nhìn thấy
     * "no_load_speed", "pack_ratio", "catalog_pdf_page" — vừa khó hiểu vừa lộ
     * trường nội bộ (số trang catalog, mã phiên bản, định mức tồn).
     *
     * @var array<string,string>
     */
    private const PUBLIC_SPEC_LABELS = [
        'power_input' => 'Nguồn điện',
        'rated_power' => 'Công suất',
        'capability' => 'Khả năng',
        'no_load_speed' => 'Tốc độ không tải',
        'impact_rate' => 'Tần suất va đập',
        'max_torque' => 'Momen xoắn tối đa',
        'weight' => 'Trọng lượng',
        'packaging' => 'Phụ kiện & đóng gói',
        'pack_ratio' => 'Quy cách đóng thùng',
        'target_users' => 'Đối tượng sử dụng',
        'barcode' => 'Mã vạch',
    ];

    /**
     * Khoá chỉ dùng cho vận hành nội bộ — KHÔNG đẩy ra mặt công khai.
     *
     * @var list<string>
     */
    private const INTERNAL_SPEC_KEYS = [
        'catalog_page', 'catalog_pdf_page', 'version_code', 'package_variant',
        'power_or_voltage', 'warranty_applicable', 'min_stock', 'max_stock',
    ];

    /**
     * @return array<string, mixed>
     */
    private function decodeSpecification(mixed $raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $parsed = json_decode($raw, true);
            $decoded = is_array($parsed) ? $parsed : [];
        } else {
            return [];
        }

        $out = [];
        foreach (self::PUBLIC_SPEC_LABELS as $key => $label) {
            if (isset($decoded[$key]) && $decoded[$key] !== '' && ! is_array($decoded[$key])) {
                $out[$label] = $decoded[$key];
            }
        }

        // Thông số riêng của SKU tự nhập (không nằm trong bảng nhãn) vẫn hiển thị
        // — chỉ loại trường nội bộ; chặn mất dữ liệu của mã hàng ngoài catalog.
        foreach ($decoded as $key => $value) {
            if (isset(self::PUBLIC_SPEC_LABELS[$key])
                || in_array($key, self::INTERNAL_SPEC_KEYS, true)
                || $value === '' || $value === null || is_array($value)) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private function toIso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function likeOperator(): string
    {
        return PvRelations::isPgsql() ? 'ilike' : 'like';
    }
}
