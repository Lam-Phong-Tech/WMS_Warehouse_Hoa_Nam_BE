<?php

/** Scoped PostgreSQL rehearsal/apply; never migrates or invokes the full seed chain. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Warehouse\Database\Seeders\UiuxV2ScenarioSeeder;
use Modules\Warehouse\Http\Controllers\StockMovementController;
use Modules\Warehouse\Support\UiuxV2OperationalScenario;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$source = getenv('UIUX_V2_SOURCE_DIR') ?: dirname(__DIR__);
foreach (['Modules/Warehouse/app/Support/UiuxV2OperationalScenario.php', 'Modules/Warehouse/database/seeders/UiuxV2ScenarioSeeder.php', 'Modules/Warehouse/app/Http/Controllers/StockMovementController.php'] as $path) {
    require_once $source.'/'.$path;
}
config(['uiux_v2_seed' => require $source.'/config/uiux_v2_seed.php']);
// Keep deployed DB/secret/cache configuration intact; opt-in belongs to this process.
config(['deployment_safety.allow_protected_database_seed' => getenv('WMS_ALLOW_PROTECTED_DATABASE_SEED') === 'CONFIRM_SEED_WRITE']);
app()->singleton(UiuxV2OperationalScenario::class);
if ($fixtureRoot = getenv('WMS_UIUX_V2_FIXTURE_STORAGE')) {
    config(['filesystems.disks.local.root' => $fixtureRoot]);
    Storage::forgetDisk('local');
}
$expected = getenv('WMS_UIUX_V2_VERIFY_DATABASE');
$actual = DB::connection()->getDatabaseName();
if (! $expected || $expected !== $actual || DB::getDriverName() !== 'pgsql') {
    throw new RuntimeException('UIUX verification requires explicit exact PostgreSQL database identity.');
}
$apply = getenv('WMS_UIUX_V2_APPLY') === 'CONFIRM_ADDITIVE_SCENARIO';
if ($actual === 'wms_hoanam_green' && ! $apply) {
    throw new RuntimeException('Deployed database requires explicit additive execution. Rehearse on its clone.');
}
$tables = [
    'app_user' => 'user_id', 'warehouses' => 'id', 'sku' => 'sku_id', 'skus' => 'id',
    'stock_documents' => 'id', 'stock_document_lines' => 'id', 'physical_codes' => 'physical_code_id',
    'inventory_balances' => 'id', 'stock_movements' => 'id', 'item_instances' => 'item_id',
    'containers' => 'container_id', 'container_items' => 'container_item_id',
    'inbound_documents' => 'id', 'inbound_lines' => 'id', 'outbound_documents' => 'id', 'outbound_lines' => 'id',
    'reversal_documents' => 'id', 'reversal_lines' => 'id', 'label_snapshots' => 'id',
    'label_print_events' => 'id', 'import_reports' => 'import_report_id', 'printer_configs' => 'id',
    'brands' => 'id', 'product_categories' => 'id', 'product_models' => 'id',
    'power_sources' => 'id', 'package_variants' => 'id', 'product_masters' => 'master_id',
    'agencies' => 'agency_id', 'outbound_requests' => 'id', 'outbound_request_lines' => 'id', 'outbound_request_imports' => 'id',
];
$snapshot = static function () use ($tables): array {
    $result = [];
    foreach ($tables as $table => $key) {
        if (Schema::getColumnListing($table) === []) {
            continue;
        }
        // Fixed identifiers only; PostgreSQL JSONB normalizes column/JSON object order.
        $rows = DB::select('SELECT "'.$key.'" AS id, md5(to_jsonb(t)::text) AS fingerprint FROM "'.$table.'" t ORDER BY "'.$key.'"');
        $result[$table] = array_column($rows, 'fingerprint', 'id');
    }

    return $result;
};
DB::beginTransaction();
try {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $before = $snapshot();
    $seeder = new UiuxV2ScenarioSeeder;
    $seeder->run();
    $manifest = $seeder->manifest;
    $op = $manifest['operations'];
    $require = static function (bool $ok, string $reason): void {
        if (! $ok) {
            throw new RuntimeException('UIUX scenario verification failed: '.$reason);
        }
    };
    $require(DB::table('inbound_documents')->where('id', $op['inbound_document_id'])->where('status', 'POSTED')->exists(), 'posted inbound');
    $require(DB::table('outbound_documents')->where('id', $op['outbound_document_id'])->where('status', 'POSTED')->exists(), 'posted outbound');
    $require(DB::table('reversal_documents')->where('id', $op['reversal_document_id'])->where('status', 'POSTED')->where('original_document_id', $op['outbound_document_id'])->exists(), 'linked posted return');
    $require(DB::table('containers')->where('container_id', $op['box_container_id'])->where('parent_container_id', $op['tray_container_id'])->exists(), 'BOX/tray link');
    $boxCode = DB::table('containers')->where('container_id', $op['box_container_id'])->value('container_code');
    $trace = app(StockMovementController::class)->traceByPhysicalCode(Request::create('/trace', 'GET', ['code' => $boxCode]))->getData(true);
    $require(count($trace['data']['movements'] ?? []) >= 1, 'BOX scan-linked movement trace');
    $require(DB::table('label_snapshots')->where('id', $op['label_snapshot_id'])->where('outbound_document_id', $op['outbound_document_id'])->exists(), 'outbound label');
    $require(DB::table('label_print_events')->where('label_snapshot_id', $op['label_snapshot_id'])->count() >= 2, 'multiple print attempts');
    $skuIds = array_unique(array_filter(array_merge($op['sku_ids'] ?? [], [$op['sku_id'] ?? null, $op['component_sku_id'] ?? null, $op['product_sku_id'] ?? null])));
    $require($skuIds !== [], 'fixture SKU identity');
    foreach ($skuIds as $skuId) {
        $require(DB::table('inventory_balances')->whereRaw('COALESCE(sku_id,product_id)=?', [$skuId])->exists(), 'fixture balance exists');
        $variance = DB::selectOne('SELECT count(*) AS count FROM inventory_balances b LEFT JOIN
        (SELECT warehouse_id, COALESCE(sku_id,product_id) AS sku, stock_status, SUM(qty_delta) AS qty FROM stock_movements GROUP BY warehouse_id, COALESCE(sku_id,product_id), stock_status) m
        ON b.warehouse_id=m.warehouse_id AND COALESCE(b.sku_id,b.product_id)=m.sku AND b.stock_status=m.stock_status
        WHERE COALESCE(b.sku_id,b.product_id)=? AND b.on_hand_qty <> COALESCE(m.qty,0)', [$skuId]);
        $require((int) $variance->count === 0, 'fixture balance equals ledger');
    }
    $after = $snapshot();
    $counts = [];
    foreach ($before as $table => $rows) {
        if (array_diff_assoc($rows, $after[$table])) {
            throw new RuntimeException('Existing row changed in '.$table.'; transaction will roll back.');
        }
        $counts[$table] = count($after[$table]) - count($rows);
    }
    $seeder->run();
    if ($after !== $snapshot() || $manifest !== $seeder->manifest) {
        throw new RuntimeException('Replay changed scenario data; transaction will roll back.');
    }
    if ($apply) {
        DB::commit();
        app(UiuxV2OperationalScenario::class)->releaseCreatedFiles();
    } else {
        DB::rollBack();
        app(UiuxV2OperationalScenario::class)->cleanupCreatedFiles();
    }
    echo json_encode(['database' => $actual, 'applied' => $apply, 'existing_rows_unchanged' => true,
        'replay_unchanged' => true, 'added' => $counts, 'manifest' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    app(UiuxV2OperationalScenario::class)->cleanupCreatedFiles();
    throw $error;
}
