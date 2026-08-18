<?php

namespace App\Support\Cache;

/**
 * TTL policy tập trung (mục 17) — không hard-code số giây rải rác trong controller/
 * service/repository. Đọc từ config/wms_cache.php (override qua .env theo môi trường).
 */
final class CacheTtl
{
    private function __construct() {}

    public static function seconds(string $policy): int
    {
        return self::withJitter((int) config("wms_cache.ttl.{$policy}", 60));
    }

    /**
     * +/- jitter_percent quanh TTL gốc để tránh nhiều key hết hạn cùng lúc rồi
     * đồng loạt dội query vào DB (mục 17).
     */
    public static function withJitter(int $seconds): int
    {
        if ($seconds <= 0) {
            return $seconds;
        }

        $jitterPercent = (int) config('wms_cache.jitter_percent', 0);
        if ($jitterPercent <= 0) {
            return $seconds;
        }

        $delta = (int) round($seconds * ($jitterPercent / 100));
        if ($delta <= 0) {
            return $seconds;
        }

        return max(1, $seconds + random_int(-$delta, $delta));
    }
}
