<?php

namespace Modules\Auth\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Cửa sổ hiệu lực của một lần gán vai trò (user_role.effective_from / effective_to).
 *
 * Màn IAM gửi NGÀY LỊCH dạng `YYYY-MM-DD`. Trước đây chuỗi đó được ghi thẳng
 * vào cột timestamptz: PostgreSQL (session UTC) hiểu "2026-11-11" là
 * 2026-11-11 00:00:00+00 = 07:00 sáng giờ Việt Nam. Hậu quả đo được 11/11/2026:
 * tài khoản đặt "hiệu lực đến 11/11" mất toàn bộ vai trò từ 07:00 sáng chính ngày
 * đó, /auth/me trả roles=[] và giao diện hiển thị "Người dùng vận hành", 0 quyền.
 *
 * Quy ước thống nhất với FE (iam-user-permissions.ts): ngày lịch tính theo múi
 * giờ ứng dụng — "từ" là 00:00:00 đầu ngày, "đến" là 23:59:59 cuối ngày (bao gồm
 * trọn ngày hết hạn). Giá trị có kèm giờ được giữ nguyên thời điểm.
 */
final class RoleEffectiveWindow
{
    private const DATE_ONLY = '/^\d{4}-\d{2}-\d{2}$/';

    public static function normalizeFrom(mixed $value): ?CarbonImmutable
    {
        return self::normalize($value, endOfDay: false);
    }

    public static function normalizeTo(mixed $value): ?CarbonImmutable
    {
        return self::normalize($value, endOfDay: true);
    }

    /** ISO 8601 kèm offset theo múi giờ ứng dụng, hoặc null. */
    public static function toIso(mixed $value): ?string
    {
        $instant = self::parseInstant($value);

        return $instant?->setTimezone(self::timezone())->toIso8601String();
    }

    /** 'EXPIRED' | 'NOT_YET_EFFECTIVE' | null (đang hiệu lực). */
    public static function inactiveReason(mixed $from, mixed $to, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now();
        $start = self::parseInstant($from);
        $end = self::parseInstant($to);

        if ($end !== null && $end->lessThanOrEqualTo($now)) {
            return 'EXPIRED';
        }
        if ($start !== null && $start->greaterThan($now)) {
            return 'NOT_YET_EFFECTIVE';
        }

        return null;
    }

    private static function normalize(mixed $value, bool $endOfDay): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (preg_match(self::DATE_ONLY, $raw) === 1) {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $raw, self::timezone());

            return $endOfDay ? $day->endOfDay()->startOfSecond() : $day->startOfDay();
        }

        return CarbonImmutable::parse($raw, self::timezone());
    }

    private static function parseInstant(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        try {
            // Giá trị cũ không kèm offset (SQLite, dữ liệu lịch sử) là giờ tường
            // theo múi giờ ứng dụng — cùng quy ước với PostgresTimestampGrammar.
            return CarbonImmutable::parse((string) $value, self::timezone());
        } catch (Throwable) {
            return null;
        }
    }

    private static function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Ho_Chi_Minh');
    }
}
