<?php

namespace Tests\Unit;

use DateTimeZone;
use InvalidArgumentException;
use Modules\Warehouse\Support\P23WarrantyDateFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\P23WarrantyDateCases;

/** Pure date contract tests; no HTTP requests, application bootstrap or database. */
final class P23WarrantyDateFilterTest extends TestCase
{
    public static function validCases(): array
    {
        return P23WarrantyDateCases::valid();
    }

    public static function invalidCases(): array
    {
        return P23WarrantyDateCases::invalid();
    }

    #[DataProvider('validCases')]
    public function test_valid_windows(array $input, string $timezone, ?array $expected): void
    {
        $window = P23WarrantyDateFilter::parse($input, new DateTimeZone($timezone));
        if ($expected === null) {
            self::assertNull($window);

            return;
        }
        self::assertSame($expected['mode'], $window['mode']);
        self::assertSame($expected['field'], $window['field']);
        self::assertSame($timezone, $window['timezone']);
        if (isset($expected['start'])) {
            self::assertSame($expected['start'], $window['start']->format(DATE_ATOM));
            self::assertSame($expected['end'], $window['end']->format(DATE_ATOM));
        } else {
            self::assertNull($window['start']);
            self::assertNull($window['end']);
        }
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_windows_are_not_silently_ignored(array $input, string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($code);
        P23WarrantyDateFilter::parse($input, new DateTimeZone('Asia/Ho_Chi_Minh'));
    }

    public function test_database_bounds_preserve_storage_semantics(): void
    {
        $window = P23WarrantyDateFilter::parse(['date_mode' => 'DATE', 'date_field' => 'received_at', 'date' => '2026-10-10'], new DateTimeZone('Asia/Ho_Chi_Minh'));
        self::assertSame(['2026-10-10 00:00:00', '2026-10-11 00:00:00'], P23WarrantyDateFilter::bounds($window, 'APP_LOCAL_WALL_CLOCK'));
        self::assertSame(['2026-10-09 17:00:00+00:00', '2026-10-10 17:00:00+00:00'], P23WarrantyDateFilter::bounds($window, 'TIMESTAMP_WITH_TIME_ZONE'));
        self::assertSame('2026-10-10T00:00:00+07:00', $window['start']->format(DATE_ATOM));
    }

    public function test_unknown_storage_is_rejected(): void
    {
        $window = P23WarrantyDateFilter::parse(['date_mode' => 'DATE', 'date_field' => 'created_at', 'date' => '2026-10-10'], new DateTimeZone('UTC'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WARRANTY_DATE_STORAGE_UNSUPPORTED');
        P23WarrantyDateFilter::bounds($window, 'UNKNOWN');
    }

    public function test_all_has_no_database_window(): void
    {
        $window = P23WarrantyDateFilter::parse(['date_mode' => 'ALL'], new DateTimeZone('UTC'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WARRANTY_DATE_WINDOW_REQUIRED');
        P23WarrantyDateFilter::bounds($window, 'APP_LOCAL_WALL_CLOCK');
    }
}
