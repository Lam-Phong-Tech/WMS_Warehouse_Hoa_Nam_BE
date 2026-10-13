<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use App\Support\DocumentTimestamp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/** Normalize BEFORE UNION/filter/sort/cursor, independently of PG session timezone. */
final class DocumentTimestampSql
{
    public static function createdAt(string $source): string
    {
        [$table, $column] = match ($source) {
            'INBOUND', 'OUTBOUND' => ['stock_documents', 'd.created_at'],
            'WARRANTY' => ['warranty_case', 'c.created_at'],
            default => throw new \InvalidArgumentException('Unsupported document timestamp source.'),
        };
        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite') {
            // SQLite strftime truncates sub-millisecond precision. Use a pure,
            // deterministic scalar function; no schema or stored value changes.
            $connection->getPdo()->sqliteCreateFunction('hn_document_utc', DocumentTimestamp::key(...), 1, PDO::SQLITE_DETERMINISTIC);

            return 'hn_document_utc('.$column.')';
        }
        if ($connection->getDriverName() !== 'pgsql') {
            ApiError::abort(503, 'DOCUMENTS_SOURCE_UNAVAILABLE', 'Nguồn thời gian chưa có contract cho hệ cơ sở dữ liệu này.');
        }
        $legacy = DocumentTimestamp::legacyTimezone();
        $type = strtolower(Schema::getColumnType($table, 'created_at'));

        return match ($type) {
            'timestamp', 'timestamp without time zone' => "(($column AT TIME ZONE '$legacy') AT TIME ZONE 'UTC')",
            'timestamptz', 'timestamp with time zone' => "($column AT TIME ZONE 'UTC')",
            default => self::unsupported(),
        };
    }

    private static function unsupported(): never
    {
        ApiError::abort(503, 'DOCUMENTS_SOURCE_UNAVAILABLE', 'Kiểu thời gian nguồn không được hỗ trợ; không suy diễn offset.');
    }
}
