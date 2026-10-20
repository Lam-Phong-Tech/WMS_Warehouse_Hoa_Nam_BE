<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\DocumentTimestamp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/** Source convention, not display/filter timezone. Never modifies persisted history. */
final class HistoryTimestamp
{
    public const CONTRACT = 'history-timestamps-v1';

    public const NAIVE_TIMEZONE = 'Asia/Ho_Chi_Minh';

    private const SOURCES = [
        'document_status_history' => ['created_at'], 'warranty_event' => ['event_at'],
        'audit_log' => ['created_at'], 'audit_logs' => ['created_at'],
        'scan_events' => ['created_at'], 'scan_record_events' => ['created_at'],
        'scan_batch' => ['created_at', 'started_at', 'ended_at'],
        'scan_batches' => ['created_at', 'started_at', 'ended_at'],
    ];

    public static function expression(string $table, string $column): string
    {
        $field = substr($column, strrpos($column, '.') + 1);
        if (! in_array($field, self::SOURCES[$table] ?? [], true)
            || ! preg_match('/\A[a-z]+\.[a-z_]+\z/D', $column)) {
            throw new \InvalidArgumentException('Unsupported history timestamp column.');
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction('hn_history_utc',
                static fn ($value) => DocumentTimestamp::key($value, self::NAIVE_TIMEZONE), 1, PDO::SQLITE_DETERMINISTIC);

            return 'hn_history_utc('.$column.')';
        }
        if (DB::getDriverName() === 'pgsql') {
            $type = strtolower(Schema::getColumnType($table, $field));

            return match ($type) {
                'timestamp', 'timestamp without time zone' => "(($column AT TIME ZONE '".self::NAIVE_TIMEZONE."') AT TIME ZONE 'UTC')",
                'timestamptz', 'timestamp with time zone' => "($column AT TIME ZONE 'UTC')",
                default => self::unavailable(),
            };
        }

        return self::unavailable();
    }

    public static function utc(mixed $normalized): ?string
    {
        return DocumentTimestamp::utc($normalized, 'UTC');
    }

    public static function key(mixed $instant): ?string
    {
        return DocumentTimestamp::key($instant, 'UTC');
    }

    private static function unavailable(): never
    {
        ApiError::abort(503, 'HISTORY_TIMESTAMP_SOURCE_UNAVAILABLE', 'Kiểu thời gian lịch sử chưa có contract; không suy diễn offset.');
    }
}
