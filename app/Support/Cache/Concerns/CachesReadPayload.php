<?php

namespace App\Support\Cache\Concerns;

use App\Support\Cache\CacheKey;
use App\Support\Cache\CacheService;
use App\Support\Cache\CacheTtl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Auth\Models\User;

/**
 * Cache cho MỌI endpoint ĐỌC — bản tổng quát của
 * `Modules\Warehouse\Http\Controllers\Concerns\CachesReportPayload`.
 *
 * Trait báo cáo ra đời trước và chỉ phục vụ 5 controller báo cáo, với TTL cứng
 * là `report`. 21 controller đọc còn lại truy vấn thẳng DB mỗi request. Trait
 * này giữ nguyên toàn bộ bài học đã trả giá của trait kia, chỉ mở thêm hai chỗ:
 * chọn được CHÍNH SÁCH TTL và chọn được NAMESPACE PHIÊN BẢN.
 *
 * BỐN ĐIỀU BẮT BUỘC — vi phạm cái nào cũng là TRẢ SAI DỮ LIỆU, không phải chậm:
 *
 *  1. SCOPE NẰM TRONG KHOÁ. Hai người khác data-scope không bao giờ dùng chung
 *     một entry. Scope phải được giải TRƯỚC khi vào cache, nếu không một cache
 *     hit sẽ nuốt mất 403 của kho ngoài scope.
 *
 *  2. MỌI THAM SỐ ẢNH HƯỞNG KẾT QUẢ NẰM TRONG KHOÁ. Nơi gọi tự quyết định
 *     `$filters`. Hai kiểu phân trang: cắt trang TRONG BỘ NHỚ (bỏ page/per_page
 *     ra khỏi khoá) và phân trang TRONG SQL (bắt buộc giữ). Đoán sai kiểu thứ
 *     hai là trả trang 1 cho người đang xem trang 3.
 *
 *  3. PAYLOAD PHẢI LÀ MẢNG THUẦN. `config/cache.php` đặt
 *     `serializable_classes = false` để chặn gadget-chain — biện pháp an ninh,
 *     KHÔNG được nới. Hệ quả: Collection/stdClass/Carbon nằm trong payload khi
 *     đọc lại thành `__PHP_Incomplete_Class`, CacheService coi là miss nên
 *     KHÔNG sai dữ liệu, nhưng cache KHÔNG BAO GIỜ hit — vẫn chạy full SQL, cộng
 *     thêm chi phí ghi/đọc/dò hỏng. Đã xảy ra thật ở 4 báo cáo BaM1, thấy qua
 *     `cache.corrupted` trong log.
 *
 *  4. GHI LÀ VÔ HIỆU HOÁ NGAY. Khoá mang số phiên bản của namespace; mọi đường
 *     ghi phải `bumpVersion()` cùng namespace đó (xem `bumpReadCache()`). TTL
 *     chỉ là lưới an toàn cho đường ghi nào lỡ chưa đi qua điểm chốt.
 *
 * KHÔNG cache phần `meta` (`request_id`, timestamp): nó phải mới mỗi request để
 * truy vết log còn dùng được.
 */
trait CachesReadPayload
{
    protected function readCacheService(): CacheService
    {
        return app(CacheService::class);
    }

    /**
     * Warehouse-scope của người đang gọi, dùng làm thành phần cách ly của khoá.
     *
     * `null` = không giới hạn (SUPER_ADMIN / vai trò SYSTEM). Danh sách được
     * sort để thứ tự vai trò do DB trả về không làm đổi khoá.
     *
     * @return list<string>|null
     */
    protected function readCacheScopeIds(): ?array
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
     * Định danh người gọi — BẮT BUỘC có trong khoá khi dữ liệu là RIÊNG TỪNG
     * NGƯỜI (thông báo, việc được giao). Thiếu nó là người này đọc được dữ liệu
     * của người kia, lỗi nặng hơn hẳn dữ liệu cũ.
     */
    protected function readCacheActorId(): string
    {
        $user = auth('api')->user();

        return $user instanceof User ? (string) $user->getAuthIdentifier() : 'guest';
    }

    /**
     * @param  array<string, mixed>  $filters  Mọi tham số ảnh hưởng kết quả.
     * @param  list<string>|null  $scope  null = GLOBAL; [] = fail-closed.
     */
    protected function readCacheKey(
        string $namespace,
        array $filters,
        ?array $scope,
        string $versionNamespace,
        ?string $actorId = null,
    ): string {
        $parts = [
            'f' => CacheKey::hashFilters($filters),
            'scope' => $scope === null ? 'global' : CacheKey::scopeSignature($scope),
        ];
        if ($actorId !== null) {
            $parts['actor'] = $actorId;
        }

        return CacheKey::of(
            $namespace,
            $parts,
            $this->readCacheService()->currentVersion($versionNamespace),
        );
    }

    /**
     * Dựng payload đọc qua cache. Khoá dùng chung được chống stampede; khoá riêng
     * từng actor không cần thêm vòng lock Valkey trên mỗi cache miss.
     *
     * @param  array<string, mixed>  $filters
     * @param  \Closure(): array<string, mixed>  $builder
     * @param  string  $ttlPolicy  Khoá trong `config/wms_cache.php` (ttl.*).
     * @param  string  $versionNamespace  Namespace bị bump khi có ghi.
     * @return array<string, mixed>
     */
    protected function cachedReadPayload(
        string $namespace,
        array $filters,
        \Closure $builder,
        string $ttlPolicy = 'search',
        string $versionNamespace = 'stock:version',
        ?string $actorId = null,
    ): array {
        $cache = $this->readCacheService();
        $key = $this->readCacheKey(
            $namespace,
            $filters,
            $this->readCacheScopeIds(),
            $versionNamespace,
            $actorId,
        );
        $ttl = CacheTtl::seconds($ttlPolicy);
        $resolver = fn (): array => $this->plainReadPayload($builder());

        return $actorId === null
            ? $cache->rememberStampede($key, $ttl, $resolver)
            : $cache->remember($key, $ttl, $resolver);
    }

    /**
     * Vô hiệu hoá cache đọc của một namespace phiên bản. Gọi trong `DB::afterCommit`
     * ở MỌI đường ghi chạm dữ liệu mà namespace đó phục vụ — gọi trước commit thì
     * một request khác có thể nạp lại cache từ dữ liệu CHƯA commit.
     */
    protected function bumpReadCache(string $versionNamespace = 'stock:version'): void
    {
        $this->readCacheService()->bumpVersion($versionNamespace);
    }

    /**
     * Ép payload về mảng/giá trị vô hướng thuần trước khi ghi cache — xem điều 3
     * ở đầu trait. Đi qua đúng bộ mã hoá JSON mà response dùng, nên kết quả giống
     * hệt thứ API phát ra, không có nguy cơ đổi shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function plainReadPayload(array $payload): array
    {
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : $payload;
        } catch (\JsonException $e) {
            // Không được để một payload lạ làm hỏng cả request. Trả nguyên bản:
            // response vẫn đúng, chỉ là lần này cache không dùng lại được.
            Log::warning('read.cache.payload_not_plain', [
                'namespace' => static::class,
                'error' => $e->getMessage(),
            ]);

            return $payload;
        }
    }

    /**
     * Bộ lọc cho endpoint cắt trang TRONG BỘ NHỚ: bỏ page/per_page để một entry
     * phục vụ mọi trang.
     *
     * @return array<string, mixed>
     */
    protected function readFiltersWithoutPaging(Request $request): array
    {
        return collect($request->query())->except(['page', 'per_page'])->all();
    }

    /**
     * Bộ lọc cho endpoint phân trang TRONG SQL: giữ nguyên toàn bộ query string.
     *
     * @return array<string, mixed>
     */
    protected function readFiltersWithPaging(Request $request): array
    {
        return $request->query();
    }
}
