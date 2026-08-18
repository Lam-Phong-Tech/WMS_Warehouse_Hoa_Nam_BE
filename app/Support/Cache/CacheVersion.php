<?php

namespace App\Support\Cache;

use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\Log;

/**
 * Version-based invalidation cho các namespace lớn (list/search/report) — mục 13.
 * Thay vì xoá hàng loạt theo wildcard (cấm `KEYS *`/`FLUSHDB`), chỉ tăng 1 counter
 * nguyên tử; mọi cache entry cũ tự "mồ côi" (namespace cũ không còn ai đọc) và tự
 * hết hạn theo TTL riêng — không cần dọn ngay, không cần biết trước danh sách key.
 */
final class CacheVersion
{
    private function __construct() {}

    public static function current(string $namespace): int
    {
        try {
            return (int) (CacheFacade::get(self::key($namespace)) ?? 1);
        } catch (\Throwable $e) {
            Log::warning('cache.version.read_failed', ['namespace' => $namespace, 'error' => $e->getMessage()]);

            return 1;
        }
    }

    public static function bump(string $namespace): int
    {
        try {
            $key = self::key($namespace);
            // add() no-op nếu đã tồn tại — tránh reset counter về 1 khi nhiều request
            // bump đồng thời lần đầu.
            CacheFacade::add($key, 1);
            $value = (int) CacheFacade::increment($key);

            Log::info('cache.version.bump', ['namespace' => $namespace, 'version' => $value]);

            return $value;
        } catch (\Throwable $e) {
            Log::warning('cache.version.bump_failed', ['namespace' => $namespace, 'error' => $e->getMessage()]);

            return 1;
        }
    }

    private static function key(string $namespace): string
    {
        return CacheKey::of('cache-version', [$namespace]);
    }
}
