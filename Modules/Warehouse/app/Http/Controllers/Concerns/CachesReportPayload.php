<?php

namespace Modules\Warehouse\Http\Controllers\Concerns;

use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheService;
use App\Support\Cache\CacheTtl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Models\User;

/**
 * Lớp cache dùng chung cho MỌI controller báo cáo.
 *
 * Trước đây chỉ `OperationalReportController` biết cách cache; bốn controller báo
 * cáo còn lại (import-match, label-log, và 4 báo cáo của BaM1ReportController)
 * chạy thẳng SQL mỗi lần gọi. Trait này để cả năm dùng đúng MỘT cách dựng key và
 * MỘT chính sách TTL — thay vì mỗi nơi tự chế một biến thể rồi lệch nhau.
 *
 * BA ĐIỀU BẮT BUỘC, vi phạm cái nào cũng là trả sai số liệu chứ không phải chậm:
 *
 *  1. SCOPE nằm trong key. Hai người dùng khác data-scope không bao giờ được dùng
 *     chung một entry. Và scope phải được giải TRƯỚC khi vào cache, nếu không một
 *     cache hit sẽ nuốt mất 403 của `warehouse_id` ngoài scope.
 *
 *  2. MỌI tham số ảnh hưởng kết quả phải nằm trong key. Nơi gọi tự quyết định
 *     `$filters` chứ trait không tự đoán, vì có hai kiểu phân trang khác nhau
 *     trong dự án: phân trang TRONG BỘ NHỚ sau builder (bỏ page/per_page ra khỏi
 *     key để một entry phục vụ mọi trang) và phân trang TRONG SQL (bắt buộc phải
 *     có page/per_page trong key). Đoán sai kiểu thứ hai là trả trang 1 cho người
 *     đang xem trang 3.
 *
 *  3. KHÔNG cache phần `meta` của response (`request_id`, timestamp). Nó phải mới
 *     ở mỗi request để truy vết log còn dùng được.
 */
trait CachesReportPayload
{
    protected function cacheService(): CacheService
    {
        return app(CacheService::class);
    }

    /**
     * Warehouse-scope của người đang gọi, dùng làm thành phần cách ly của key.
     *
     * `null` = không giới hạn (SUPER_ADMIN / vai trò SYSTEM). Trả về danh sách đã
     * sort để thứ tự vai trò do DB trả về không làm đổi key.
     *
     * @return list<string>|null
     */
    protected function reportScopeIds(): ?array
    {
        $user = auth('api')->user();
        $scope = $user instanceof User ? $user->warehouseScopeIds() : null;
        if ($scope === null) {
            return null;
        }

        return collect($scope)
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters  Mọi tham số ảnh hưởng kết quả.
     * @param  list<string>|null  $scope  null = GLOBAL; [] = fail-closed.
     */
    protected function reportCacheKey(string $namespace, array $filters, ?array $scope): string
    {
        return CacheKey::of($namespace, [
            'f' => CacheKey::hashFilters($filters),
            'scope' => $scope === null ? 'global' : CacheKey::scopeSignature($scope),
            // Version namespace dùng chung với tồn kho: `stock:version` được bump
            // ngay sau mỗi mutation tồn kho, nên entry cũ mồ côi tức thì. TTL
            // 'report' chỉ là lưới an toàn cho các đường ghi chưa đi qua điểm chốt đó.
        ], $this->cacheService()->currentVersion('stock:version'));
    }

    /**
     * Dựng payload báo cáo qua cache, có chống stampede.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>|null  $scope
     * @param  \Closure(): array<string, mixed>  $builder
     * @return array<string, mixed>
     */
    protected function cachedReportPayload(
        string $namespace,
        array $filters,
        ?array $scope,
        \Closure $builder,
    ): array {
        return $this->cacheService()->rememberStampede(
            $this->reportCacheKey($namespace, $filters, $scope),
            CacheTtl::seconds('report'),
            fn (): array => $this->plainReportPayload($builder())
        );
    }

    /**
     * Ép payload về mảng/giá trị vô hướng thuần trước khi ghi cache.
     *
     * VÌ SAO BẮT BUỘC: `config/cache.php` đặt `serializable_classes = false` để
     * chặn gadget-chain khi unserialize (biện pháp an ninh, KHÔNG được nới). Hệ
     * quả là mọi PHP object nằm trong payload — `Illuminate\Support\Collection`
     * trả về từ query builder, `stdClass` của từng dòng `->get()`, `Carbon` — khi
     * đọc lại sẽ thành `__PHP_Incomplete_Class`. CacheService phát hiện và coi đó
     * là miss, nên KHÔNG sai dữ liệu, nhưng cache sẽ KHÔNG BAO GIỜ hit: mỗi
     * request vẫn chạy full SQL, cộng thêm chi phí ghi/đọc/dò hỏng. Đúng lỗi đã
     * xảy ra thật với 4 báo cáo của BaM1ReportController, thấy qua `cache.corrupted`
     * trong log.
     *
     * Cách ép: đi qua đúng bộ mã hoá JSON mà response dùng. Kết quả vì thế giống
     * hệt những gì API phát ra — không có nguy cơ đổi shape — mà lại là mảng thuần.
     * Chi phí chỉ phát sinh ở lần MISS, và nhỏ hơn nhiều so với chính truy vấn nó
     * thay thế.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function plainReportPayload(array $payload): array
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : $payload;
        } catch (\JsonException $e) {
            // Không được để một payload lạ làm hỏng cả request. Trả nguyên bản:
            // response vẫn đúng, chỉ là lần này cache không dùng lại được.
            Log::warning('report.cache.payload_not_plain', [
                'error' => $e->getMessage(),
            ]);

            return $payload;
        }
    }

    /**
     * Bộ lọc cho báo cáo phân trang TRONG BỘ NHỚ (builder trả toàn bộ dòng, cắt
     * trang sau). Bỏ page/per_page để một entry phục vụ được mọi trang — đưa chúng
     * vào chỉ khiến mỗi trang dựng lại toàn bộ báo cáo.
     *
     * @return array<string, mixed>
     */
    protected function reportFiltersWithoutPaging(Request $request): array
    {
        return collect($request->query())->except(['page', 'per_page'])->all();
    }

    /**
     * Bộ lọc cho báo cáo phân trang TRONG SQL. Giữ nguyên toàn bộ query string,
     * kể cả page/per_page: mỗi trang là một kết quả khác nhau.
     *
     * @return array<string, mixed>
     */
    protected function reportFiltersWithPaging(Request $request): array
    {
        return $request->query();
    }
}
