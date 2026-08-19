<?php

declare(strict_types=1);

/**
 * DEV199-P1-01R (DB after-state) + DEV199-P1-02R (unique behaviour trên PostgreSQL).
 *
 * Ba việc, đều fail-closed:
 *
 *  1. after-state — sau khi race chạy, mỗi item trong fixture phải có ĐÚNG một
 *     outbound_item_matches ACTIVE, và mỗi toa phải có ĐÚNG một phiếu xuất chưa
 *     huỷ. Đây là bằng chứng dữ liệu, độc lập với mã HTTP mà racer nhận được.
 *
 *  2. pg_indexes — liệt kê đúng các unique index đang bảo vệ reservation, để
 *     evidence không phải "đọc migration rồi tin là nó đã chạy".
 *
 *  3. duplicate test — cố tình INSERT một match ACTIVE thứ hai cho cùng item ở
 *     tầng DB (bỏ qua toàn bộ guard ứng dụng). Phải nhận unique violation 23505
 *     hoặc raise từ trigger. Nếu insert lọt ⇒ backstop DB không tồn tại.
 *
 * Dùng:
 *   php scripts/verify-wwhn199-reservation-invariants.php \
 *     build/evidence/wwhn-199/race-fixture.json \
 *     build/evidence/wwhn-199/db-invariants.json
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fixturePath = $argv[1] ?? 'build/evidence/wwhn-199/race-fixture.json';
$outputPath = $argv[2] ?? 'build/evidence/wwhn-199/db-invariants.json';
$resolve = static fn (string $path): string => preg_match('#^([A-Za-z]:)?[\\\\/]#', $path) === 1
    ? $path
    : dirname(__DIR__).'/'.$path;

$fixturePath = $resolve($fixturePath);
$outputPath = $resolve($outputPath);

if (DB::getDriverName() !== 'pgsql') {
    fwrite(STDERR, 'DEV199-P1-02R yeu cau PostgreSQL; driver hien tai: '.DB::getDriverName()."\n");
    exit(2);
}

$fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$failures = [];
$checks = [];

/* ---------------------------------------------------------------- 1. after-state */

$itemStates = [];
foreach ($fixture['rounds'] ?? [] as $round) {
    $itemId = (string) $round['item_id'];
    $active = (int) DB::table('outbound_item_matches')
        ->where('item_id', $itemId)
        ->where('status', 'ACTIVE')
        ->count();
    $all = (int) DB::table('outbound_item_matches')->where('item_id', $itemId)->count();
    $documents = DB::table('outbound_item_matches as m')
        ->join('stock_document_lines as l', 'l.id', '=', 'm.outbound_line_id')
        ->where('m.item_id', $itemId)
        ->where('m.status', 'ACTIVE')
        ->distinct()
        ->pluck('l.stock_document_id')
        ->all();

    $itemStates[] = [
        'round' => $round['round'],
        'item_id' => $itemId,
        'active_matches' => $active,
        'total_matches' => $all,
        'documents_holding_item' => $documents,
    ];

    if ($active !== 1) {
        $failures[] = "Item {$itemId}: co {$active} match ACTIVE (phai la 1).";
    }
    if (count($documents) > 1) {
        $failures[] = "Item {$itemId}: dang bi giu boi ".count($documents).' phieu xuat.';
    }
}
$checks['item_level_after_state'] = $itemStates;

$requestStates = [];
$requestIds = [];
foreach ($fixture['rounds'] ?? [] as $round) {
    $requestIds[] = (string) $round['document_a']['request_id'];
    $requestIds[] = (string) $round['document_b']['request_id'];
}
foreach (array_unique($requestIds) as $requestId) {
    $documents = (int) DB::table('stock_documents')
        ->where('outbound_request_id', $requestId)
        ->whereIn('status', ['DRAFT', 'SCANNING', 'POSTED'])
        ->count();
    $requestStates[] = ['outbound_request_id' => $requestId, 'active_documents' => $documents];
    if ($documents !== 1) {
        $failures[] = "Toa {$requestId}: co {$documents} phieu xuat active (phai la 1).";
    }
}
$checks['request_level_after_state'] = $requestStates;

/* -------------------------------------------------------------- 2. pg_indexes */

$reservationIndexes = DB::select(<<<'SQL'
    SELECT tablename, indexname, indexdef
    FROM pg_indexes
    WHERE schemaname = current_schema()
      AND tablename IN ('outbound_item_matches', 'inbound_scan_entries', 'component_issue_items')
      AND indexdef LIKE 'CREATE UNIQUE%'
    ORDER BY tablename, indexname
SQL);
$checks['reservation_unique_indexes'] = array_map(static fn (object $row): array => (array) $row, $reservationIndexes);

$outboundActive = array_values(array_filter(
    $checks['reservation_unique_indexes'],
    static fn (array $row): bool => $row['tablename'] === 'outbound_item_matches'
        && str_contains($row['indexdef'], '(item_id)')
        && str_contains($row['indexdef'], "'ACTIVE'")
));
$checks['outbound_active_item_index_count'] = count($outboundActive);

if ($checks['outbound_active_item_index_count'] < 1) {
    $failures[] = 'Khong con unique index nao chan match ACTIVE trung item tren outbound_item_matches.';
}
if ($checks['outbound_active_item_index_count'] > 1) {
    $failures[] = 'Con '.$checks['outbound_active_item_index_count'].
        ' unique index tuong duong tren outbound_item_matches (DEV199-P2-01 chua duoc dong): '.
        implode(', ', array_column($outboundActive, 'indexname'));
}

$inboundActive = array_values(array_filter(
    $checks['reservation_unique_indexes'],
    static fn (array $row): bool => $row['tablename'] === 'inbound_scan_entries'
        && str_contains($row['indexdef'], '(item_id)')
        && str_contains($row['indexdef'], "'ACTIVE'")
));
$checks['inbound_active_item_index_count'] = count($inboundActive);
if ($checks['inbound_active_item_index_count'] !== 1) {
    $failures[] = 'inbound_scan_entries phai co dung 1 unique index item ACTIVE, dang co '.
        $checks['inbound_active_item_index_count'].'.';
}

$checks['reservation_triggers'] = array_map(static fn (object $row): array => (array) $row, DB::select(<<<'SQL'
    SELECT c.relname AS tablename, t.tgname AS triggername
    FROM pg_trigger t
    JOIN pg_class c ON c.oid = t.tgrelid
    WHERE NOT t.tgisinternal
      AND c.relname IN ('outbound_item_matches', 'inbound_scan_entries')
    ORDER BY c.relname, t.tgname
SQL));

/* ---------------------------------------------------------- 3. duplicate test */

$probeItem = $fixture['rounds'][0]['item_id'] ?? null;
$duplicateProbe = ['executed' => false];

if ($probeItem !== null) {
    $winner = DB::table('outbound_item_matches')
        ->where('item_id', $probeItem)
        ->where('status', 'ACTIVE')
        ->first();

    if ($winner === null) {
        $failures[] = 'Khong tim thay match ACTIVE de dung lam moc cho duplicate test.';
    } else {
        try {
            DB::transaction(function () use ($winner, $probeItem): void {
                DB::table('outbound_item_matches')->insert([
                    'id' => (string) Str::uuid(),
                    'outbound_line_id' => $winner->outbound_line_id,
                    'item_id' => $probeItem,
                    'quantity' => 1,
                    'status' => 'ACTIVE',
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                // Không bao giờ tới đây nếu backstop DB còn nguyên.
                throw new RuntimeException('WWHN199_DUPLICATE_INSERT_SUCCEEDED');
            });
            $failures[] = 'Duplicate ACTIVE match da INSERT duoc o tang DB — backstop khong ton tai.';
            $duplicateProbe = ['executed' => true, 'blocked' => false, 'sqlstate' => null, 'message' => null];
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $blocked = ! str_contains($message, 'WWHN199_DUPLICATE_INSERT_SUCCEEDED');
            $sqlstate = null;
            if (preg_match('/SQLSTATE\[(\w+)\]/', $message, $matches) === 1) {
                $sqlstate = $matches[1];
            }
            $duplicateProbe = [
                'executed' => true,
                'blocked' => $blocked,
                'sqlstate' => $sqlstate,
                'message' => Str::limit(preg_replace('/\s+/', ' ', $message), 400),
            ];
            if (! $blocked) {
                $failures[] = 'Duplicate ACTIVE match da INSERT duoc o tang DB — backstop khong ton tai.';
            }
        }
    }
}
$checks['duplicate_active_match_probe'] = $duplicateProbe;

/* -------------------------------------------------------------------- report */

$report = [
    'schema_version' => '1.0.0',
    'task' => 'WWHN-199',
    'register_items' => ['DEV199-P1-01R', 'DEV199-P1-02R', 'DEV199-P2-01'],
    'driver' => DB::getDriverName(),
    'database' => DB::connection()->getDatabaseName(),
    'server_version' => DB::scalar('SHOW server_version'),
    'generated_at' => now()->toIso8601String(),
    'checks' => $checks,
    'failures' => $failures,
    'result' => $failures === [] ? 'PASS' : 'FAIL',
];

if (! is_dir(dirname($outputPath))) {
    mkdir(dirname($outputPath), 0777, true);
}
file_put_contents(
    $outputPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
);

fwrite(STDOUT, 'WWHN-199 DB invariants: '.$report['result']."\n");
foreach ($failures as $failure) {
    fwrite(STDOUT, '  - '.$failure."\n");
}
fwrite(STDOUT, 'report: '.$outputPath."\n");

exit($failures === [] ? 0 : 1);
