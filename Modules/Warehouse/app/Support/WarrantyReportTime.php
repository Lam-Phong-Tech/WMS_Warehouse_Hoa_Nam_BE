<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\DocumentTimestamp;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/** Read-only source normalization shared by report tables, totals, charts and exports. */
final class WarrantyReportTime
{
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::today(self::TIMEZONE);
    }

    public static function range(string $from, string $to): Closure
    {
        $lower = CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay()->utc()->format('Y-m-d H:i:s.u');
        $upper = CarbonImmutable::parse($to, self::TIMEZONE)->startOfDay()->addDay()->utc()->format('Y-m-d H:i:s.u');

        return static function (Builder $query) use ($lower, $upper): void {
            $received = self::received();
            $query->whereRaw($received.' >= ? AND '.$received.' < ?', [$lower, $upper]);
        };
    }

    public static function received(): string
    {
        return 'COALESCE('.self::column('received_at').', '.self::column('created_at').')';
    }

    public static function turnaround(mixed $received, mixed $closed): ?int
    {
        if ($received === null || $received === '' || $closed === null || $closed === '') {
            return null;
        }
        $start = CarbonImmutable::instance(DocumentTimestamp::instant($received, self::TIMEZONE))->utc();
        $end = CarbonImmutable::instance(DocumentTimestamp::instant($closed, self::TIMEZONE))->utc();

        return (int) $start->diffInDays($end);
    }

    private static function column(string $field): string
    {
        $column = 'wc.'.$field;
        if (DB::getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('hn_warranty_report_utc',
                static fn ($value) => DocumentTimestamp::key($value, self::TIMEZONE), 1, PDO::SQLITE_DETERMINISTIC);

            return 'hn_warranty_report_utc('.$column.')';
        }
        if (DB::getDriverName() === 'pgsql') {
            return match (strtolower(Schema::getColumnType('warranty_case', $field))) {
                'timestamp', 'timestamp without time zone' => "(($column AT TIME ZONE '".self::TIMEZONE."') AT TIME ZONE 'UTC')",
                'timestamptz', 'timestamp with time zone' => "($column AT TIME ZONE 'UTC')",
                default => self::unavailable(),
            };
        }

        return self::unavailable();
    }

    private static function unavailable(): never
    {
        ApiError::abort(503, 'WARRANTY_REPORT_TIMESTAMP_UNAVAILABLE', 'Nguồn thời gian báo cáo bảo hành chưa được hỗ trợ.');
    }
}
