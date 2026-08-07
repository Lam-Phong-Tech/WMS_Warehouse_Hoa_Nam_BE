<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$outputPath = $argv[1] ?? 'build/evidence/wwhn-131/schema-verification.json';
$driver = DB::connection()->getDriverName();
$database = (string) DB::connection()->getDatabaseName();
$schema = $driver === 'pgsql'
    ? (string) (DB::selectOne('SELECT current_schema() AS name')->name ?? '')
    : ($driver === 'mysql' ? $database : 'main');
$serverVersion = match ($driver) {
    'sqlite' => (string) (DB::selectOne('SELECT sqlite_version() AS version')->version ?? ''),
    'mysql' => (string) (DB::selectOne('SELECT VERSION() AS version')->version ?? ''),
    'pgsql' => (string) (DB::selectOne('SHOW server_version')->server_version ?? ''),
    default => 'unknown',
};
$checks = [];

$record = static function (string $id, bool $passed, string $message, array $details = []) use (&$checks): void {
    $checks[] = [
        'id' => $id,
        'passed' => $passed,
        'message' => $message,
        'details' => $details,
    ];
};

$columnNullable = static function (string $table, string $column) use ($driver, $database): ?bool {
    if ($driver === 'sqlite') {
        $rows = DB::select('PRAGMA table_info("'.str_replace('"', '""', $table).'")');
        foreach ($rows as $row) {
            if ((string) $row->name === $column) {
                return (int) $row->notnull !== 1;
            }
        }

        return null;
    }

    if ($driver === 'mysql') {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE AS is_nullable
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            [$database, $table, $column]
        );

        return $row ? strtoupper((string) $row->is_nullable) === 'YES' : null;
    }

    if ($driver === 'pgsql') {
        $row = DB::selectOne(
            'SELECT is_nullable
             FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );

        return $row ? strtoupper((string) $row->is_nullable) === 'YES' : null;
    }

    return null;
};

$indexes = static function (string $table) use ($driver, $database): array {
    $result = [];

    if ($driver === 'sqlite') {
        $list = DB::select('PRAGMA index_list("'.str_replace('"', '""', $table).'")');
        foreach ($list as $index) {
            $name = (string) $index->name;
            $columns = array_map(
                static fn (object $row): string => (string) $row->name,
                DB::select('PRAGMA index_info("'.str_replace('"', '""', $name).'")')
            );
            $result[$name] = [
                'unique' => (int) $index->unique === 1,
                'columns' => $columns,
            ];
        }

        return $result;
    }

    if ($driver === 'mysql') {
        $rows = DB::select(
            'SELECT INDEX_NAME AS index_name, NON_UNIQUE AS non_unique, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS seq_in_index
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$database, $table]
        );
        foreach ($rows as $row) {
            $name = (string) $row->index_name;
            $result[$name] ??= ['unique' => (int) $row->non_unique === 0, 'columns' => []];
            $result[$name]['columns'][] = (string) $row->column_name;
        }

        return $result;
    }

    if ($driver === 'pgsql') {
        $rows = DB::select(
            'SELECT
                index_class.relname AS index_name,
                index_meta.indisunique AS is_unique,
                attribute.attname AS column_name,
                key_position.ordinality AS seq_in_index
             FROM pg_class table_class
             JOIN pg_namespace namespace ON namespace.oid = table_class.relnamespace
             JOIN pg_index index_meta ON index_meta.indrelid = table_class.oid
             JOIN pg_class index_class ON index_class.oid = index_meta.indexrelid
             JOIN LATERAL unnest(index_meta.indkey) WITH ORDINALITY AS key_position(attnum, ordinality) ON true
             JOIN pg_attribute attribute
               ON attribute.attrelid = table_class.oid
              AND attribute.attnum = key_position.attnum
             WHERE namespace.nspname = current_schema()
               AND table_class.relname = ?
             ORDER BY index_class.relname, key_position.ordinality',
            [$table]
        );
        foreach ($rows as $row) {
            $name = (string) $row->index_name;
            $result[$name] ??= ['unique' => filter_var($row->is_unique, FILTER_VALIDATE_BOOLEAN), 'columns' => []];
            $result[$name]['columns'][] = (string) $row->column_name;
        }
    }

    return $result;
};

$foreignKeys = static function (string $table) use ($driver, $database): array {
    if ($driver === 'sqlite') {
        return array_map(
            static fn (object $row): array => [
                'column' => (string) $row->from,
                'references_table' => (string) $row->table,
                'references_column' => (string) $row->to,
            ],
            DB::select('PRAGMA foreign_key_list("'.str_replace('"', '""', $table).'")')
        );
    }

    if ($driver === 'mysql') {
        return array_map(
            static fn (object $row): array => [
                'column' => (string) $row->column_name,
                'references_table' => (string) $row->referenced_table_name,
                'references_column' => (string) $row->referenced_column_name,
            ],
            DB::select(
                'SELECT COLUMN_NAME AS column_name,
                        REFERENCED_TABLE_NAME AS referenced_table_name,
                        REFERENCED_COLUMN_NAME AS referenced_column_name
                 FROM information_schema.key_column_usage
                 WHERE table_schema = ?
                   AND table_name = ?
                   AND referenced_table_name IS NOT NULL',
                [$database, $table]
            )
        );
    }

    if ($driver === 'pgsql') {
        return array_map(
            static fn (object $row): array => [
                'column' => (string) $row->column_name,
                'references_table' => (string) $row->foreign_table_name,
                'references_column' => (string) $row->foreign_column_name,
            ],
            DB::select(
                'SELECT
                    kcu.column_name,
                    ccu.table_name AS foreign_table_name,
                    ccu.column_name AS foreign_column_name
                 FROM information_schema.table_constraints tc
                 JOIN information_schema.key_column_usage kcu
                   ON tc.constraint_name = kcu.constraint_name
                  AND tc.table_schema = kcu.table_schema
                 JOIN information_schema.constraint_column_usage ccu
                   ON ccu.constraint_name = tc.constraint_name
                  AND ccu.table_schema = tc.table_schema
                 WHERE tc.constraint_type = \'FOREIGN KEY\'
                   AND tc.table_schema = current_schema()
                   AND tc.table_name = ?',
                [$table]
            )
        );
    }

    return [];
};

$requiredTables = [
    'stocktake_session',
    'stocktake_snapshot',
    'stocktake_count',
    'stocktake_variance',
    'stock_movements',
    'posting_event',
];
foreach ($requiredTables as $table) {
    $record(
        "table:{$table}",
        Schema::hasTable($table),
        "{$table} phải tồn tại sau migration"
    );
}

$record(
    'migration:wwhn131-hardening-applied',
    DB::table('migrations')->where('migration', '2026_08_03_000100_harden_stocktake_three_layer_contract')->exists(),
    'Migration WWHN-131 hardening phải được ghi nhận đã apply'
);

$notNullColumns = [
    'stocktake_session' => ['warehouse_id'],
    'stocktake_snapshot' => ['grain_type', 'grain_id', 'grain_key'],
    'stocktake_count' => ['grain_type', 'grain_id', 'grain_key'],
    'stocktake_variance' => ['grain_type', 'grain_id', 'grain_key'],
    'posting_event' => ['operation', 'idempotency_key', 'request_hash'],
];
foreach ($notNullColumns as $table => $columns) {
    foreach ($columns as $column) {
        $nullable = Schema::hasColumn($table, $column) ? $columnNullable($table, $column) : null;
        $record(
            "not-null:{$table}.{$column}",
            $nullable === false,
            "{$table}.{$column} phải NOT NULL",
            ['nullable' => $nullable]
        );
    }
}

$requiredIndexes = [
    'stocktake_session' => [
        'idx_stocktake_session_warehouse_status' => [
            'unique' => false,
            'columns' => ['warehouse_id', 'status'],
        ],
    ],
    'stocktake_snapshot' => [
        'uk_stocktake_snapshot_session_grain' => [
            'unique' => true,
            'columns' => ['session_id', 'grain_key'],
        ],
    ],
    'stocktake_count' => [
        'uk_stocktake_count_session_grain' => [
            'unique' => true,
            'columns' => ['session_id', 'grain_key'],
        ],
    ],
    'stocktake_variance' => [
        'uk_stocktake_variance_session_grain' => [
            'unique' => true,
            'columns' => ['session_id', 'grain_key'],
        ],
        'idx_stocktake_variance_session_status' => [
            'unique' => false,
            'columns' => ['session_id', 'status'],
        ],
    ],
    'posting_event' => [
        'uk_posting_event_operation_idem' => [
            'unique' => true,
            'columns' => ['operation', 'idempotency_key'],
        ],
    ],
];

foreach ($requiredIndexes as $table => $expectedIndexes) {
    $actualIndexes = $indexes($table);
    foreach ($expectedIndexes as $name => $expected) {
        $actual = $actualIndexes[$name] ?? null;
        $record(
            "index:{$table}.{$name}",
            $actual !== null
                && $actual['unique'] === $expected['unique']
                && $actual['columns'] === $expected['columns'],
            "{$table}.{$name} phải đúng uniqueness và thứ tự cột",
            ['expected' => $expected, 'actual' => $actual]
        );
    }
}

$requiredForeignKeys = [
    'stocktake_session' => [
        'column' => 'warehouse_id',
        'references_table' => 'warehouses',
        'references_column' => 'id',
    ],
    'stock_movements' => [
        'column' => 'stocktake_variance_id',
        'references_table' => 'stocktake_variance',
        'references_column' => 'variance_id',
    ],
];
foreach ($requiredForeignKeys as $table => $expected) {
    $actual = $foreignKeys($table);
    $matched = collect($actual)->contains(
        static fn (array $foreignKey): bool => $foreignKey === $expected
    );
    $record(
        "foreign-key:{$table}.{$expected['column']}",
        $matched,
        "{$table}.{$expected['column']} phải tham chiếu đúng",
        ['expected' => $expected, 'actual' => $actual]
    );
}

$failed = count(array_filter($checks, static fn (array $check): bool => ! $check['passed']));
$result = [
    'schema_version' => '1.0.0',
    'generated_at_utc' => gmdate('c'),
    'driver' => $driver,
    'database' => $database,
    'schema' => $schema,
    'server_version' => $serverVersion,
    'summary' => [
        'passed' => count($checks) - $failed,
        'failed' => $failed,
        'status' => $failed === 0 ? 'PASS' : 'FAIL',
    ],
    'checks' => $checks,
];

$directory = dirname($outputPath);
if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
    throw new RuntimeException("Không thể tạo thư mục output: {$directory}");
}
file_put_contents(
    $outputPath,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
);
fwrite(STDOUT, json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);

exit($failed === 0 ? 0 : 1);
