<?php

/**
 * PostgreSQL regression for the two display backfills, not a full business seed.
 * Uses session-local temporary tables and test-only append-only sentinels.
 * Never run against a deployment database; the database name is checked below.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Database\Seeders\DisplayFieldCoverageSeeder;
use Modules\Warehouse\Database\Seeders\WarehouseOperationsCoverageSeeder;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'component_issue_seeder_test') {
    throw new RuntimeException('Requires APP_ENV=testing and the isolated PostgreSQL component_issue_seeder_test database.');
}

$checks = 0;
$check = function (bool $ok, string $name) use (&$checks): void {
    if (! $ok) {
        throw new RuntimeException('FAIL: '.$name);
    }
    $checks++;
    echo 'PASS: '.$name.PHP_EOL;
};
$invoke = function (object $seeder, string $method): mixed {
    return (new ReflectionMethod($seeder, $method))->invoke($seeder);
};
$rows = fn (string $table): string => json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR);

// TEMP tables shadow no persistent business data and disappear with this connection.
DB::unprepared(<<<'SQL'
    CREATE TEMP TABLE item_instances (item_id uuid PRIMARY KEY, location_hint text);
    CREATE TEMP TABLE containers (container_id uuid PRIMARY KEY, container_type text, location_hint text, zone_code text, updated_at timestamp);
    CREATE TEMP TABLE stock_documents (id uuid PRIMARY KEY, doc_type text, status text, source_name text, doc_no text, updated_at timestamp);
    CREATE TEMP TABLE reconciliation_incidents (id uuid PRIMARY KEY, reason text, updated_at timestamp);
    CREATE TEMP TABLE component_issue_documents (id uuid PRIMARY KEY, status text, component_issue_no text, note text, updated_at timestamp);
    CREATE TEMP TABLE component_issue_lines (id uuid PRIMARY KEY, component_issue_document_id uuid, note text);
    CREATE TEMP TABLE inbound_lines (id uuid PRIMARY KEY, note text);
    CREATE TEMP TABLE outbound_lines (id uuid PRIMARY KEY, note text);
    CREATE TEMP TABLE outbound_request_lines (id uuid PRIMARY KEY, note text);
    CREATE FUNCTION pg_temp.guard_seeder_document() RETURNS trigger LANGUAGE plpgsql AS $$
    BEGIN
        IF OLD.status = 'POSTED' THEN
            RAISE EXCEPTION 'test sentinel: posted document is append-only';
        END IF;
        IF OLD.component_issue_no = 'REJECT-DRAFT' THEN
            RAISE EXCEPTION 'test sentinel: unexpected draft write failure';
        END IF;
        RETURN NEW;
    END; $$;
    CREATE TRIGGER seeder_document_guard BEFORE UPDATE ON component_issue_documents
        FOR EACH ROW EXECUTE FUNCTION pg_temp.guard_seeder_document();
    CREATE FUNCTION pg_temp.guard_seeder_line() RETURNS trigger LANGUAGE plpgsql AS $$
    BEGIN
        IF EXISTS (SELECT 1 FROM component_issue_documents WHERE id = OLD.component_issue_document_id AND status = 'POSTED') THEN
            RAISE EXCEPTION 'test sentinel: posted line is append-only';
        END IF;
        RETURN NEW;
    END; $$;
    CREATE TRIGGER seeder_line_guard BEFORE UPDATE ON component_issue_lines
        FOR EACH ROW EXECUTE FUNCTION pg_temp.guard_seeder_line();
SQL);

$draft = '10000000-0000-4000-8000-000000000001';
$posted = '10000000-0000-4000-8000-000000000002';
$cancelled = '10000000-0000-4000-8000-000000000003';
$existing = '10000000-0000-4000-8000-000000000004';
foreach ([$draft => 'DRAFT', $posted => 'POSTED', $cancelled => 'CANCELLED', $existing => 'DRAFT'] as $id => $status) {
    DB::table('component_issue_documents')->insert([
        'id' => $id, 'status' => $status, 'component_issue_no' => 'TEST-'.$status,
        'note' => $id === $existing ? 'Existing note must survive' : null,
        'updated_at' => '2026-01-01 00:00:00',
    ]);
    DB::table('component_issue_lines')->insert(['id' => $id, 'component_issue_document_id' => $id, 'note' => null]);
}

$immutableBefore = DB::table('component_issue_documents')->whereIn('id', [$posted, $cancelled])->orderBy('id')->get()->toJson();
$linesBefore = $rows('component_issue_lines');
$seeder = new WarehouseOperationsCoverageSeeder;
DB::beginTransaction();
try {
    $invoke($seeder, 'backfillDisplayGaps');
    $check(DB::table('component_issue_documents')->where('id', $draft)->value('note') === 'Xuất linh kiện thay thế cho hồ sơ bảo hành — phiếu TEST-DRAFT', 'DRAFT missing note is filled');
    $check(DB::table('component_issue_documents')->whereIn('id', [$posted, $cancelled])->orderBy('id')->get()->toJson() === $immutableBefore, 'POSTED and CANCELLED documents remain byte-identical');
    $check(DB::table('component_issue_documents')->where('id', $existing)->value('note') === 'Existing note must survive', 'existing DRAFT note is preserved');
    $check((int) DB::selectOne('SELECT 1 AS ok')->ok === 1, 'outer PostgreSQL transaction remains usable');

    $afterFirst = $rows('component_issue_documents');
    $invoke($seeder, 'backfillDisplayGaps');
    $check($rows('component_issue_documents') === $afterFirst, 'second backfill does not update rows again');
    $invoke(new DisplayFieldCoverageSeeder, 'backfillLineNotes');
    $check($rows('component_issue_lines') === $linesBefore, 'historical component line notes are not backfilled');

    foreach (['component_issue_documents' => $posted, 'component_issue_lines' => $posted] as $table => $id) {
        $rejected = false;
        try {
            DB::transaction(fn () => DB::table($table)->where('id', $id)->update(['note' => 'forbidden test update']));
        } catch (QueryException) {
            $rejected = true;
        }
        $check($rejected, 'append-only sentinel still rejects direct update of '.$table);
    }
    $check((int) DB::selectOne('SELECT 1 AS ok')->ok === 1, 'savepoint rollback leaves outer transaction usable');

    DB::table('component_issue_documents')->insert([
        'id' => '10000000-0000-4000-8000-000000000005', 'status' => 'DRAFT',
        'component_issue_no' => 'REJECT-DRAFT', 'note' => null, 'updated_at' => '2026-01-01 00:00:00',
    ]);
    $beforeFailure = $rows('component_issue_documents');
    $failed = false;
    try {
        $invoke($seeder, 'backfillDisplayGaps');
    } catch (QueryException) {
        $failed = true;
    }
    $check($failed, 'unexpected SQL error is propagated, not swallowed');
    $check($rows('component_issue_documents') === $beforeFailure, 'failed nested backfill rolls back safely');
} finally {
    DB::rollBack();
}

$summary = "PASS: {$checks} PostgreSQL display-backfill assertions. Test-only temporary tables/triggers; full seed, real deployment database and production sign-off NOT exercised.\n";
echo $summary;
if (is_string(getenv('GITHUB_STEP_SUMMARY')) && getenv('GITHUB_STEP_SUMMARY') !== '') {
    file_put_contents(getenv('GITHUB_STEP_SUMMARY'), $summary, FILE_APPEND);
}
