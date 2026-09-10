<?php

declare(strict_types=1);

/**
 * DEV199-P1-01R — dựng fixture cho race thật trên PostgreSQL.
 *
 * Vì sao cần script riêng thay vì dùng trait test: bộ test PHPUnit chạy trong MỘT
 * process với MỘT connection và `RefreshDatabase` bọc transaction, nên hai "racer"
 * bên trong nó không bao giờ là hai transaction PostgreSQL thật đang tranh nhau row
 * lock. Register DEV199-P1-01R yêu cầu đúng thứ PHPUnit không tạo được: hai HTTP
 * client + hai connection DB overlap trên cùng một item. Script này chỉ dựng dữ
 * liệu và phát hành JWT; phần bắn đồng thời do scripts/run-wwhn199-reservation-race.mjs
 * thực hiện qua HTTP thật tới nginx/php-fpm (mỗi request là một worker, một connection).
 *
 * Dùng:
 *   php scripts/wwhn199-race-fixture.php build build/evidence/wwhn-199/race-fixture.json
 *   php scripts/wwhn199-race-fixture.php cleanup
 *
 * Mọi bản ghi đều mang tiền tố W199R- để cleanup không đụng dữ liệu khác.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/lib/assert-non-production.php';

const W199_PREFIX = 'W199R';

/** Bảng vật lý đảo chiều tên theo driver (migration 000791) — luôn hỏi schema. */
function w199_physical(string $plural, string $singular): string
{
    return Schema::hasTable($plural) ? $plural : $singular;
}

function w199_cleanup(): void
{
    $like = W199_PREFIX.'-%';

    DB::transaction(function () use ($like): void {
        $skuIds = Sku::query()->where('sku_code', 'like', $like)->pluck('sku_id');

        // Import/toa do kịch bản B tạo qua HTTP mang số tự sinh (không có tiền tố),
        // nên phải truy ngược theo SKU của fixture mới dọn hết đồ thị FK.
        $importIds = DB::table('outbound_request_imports')->where('import_no', 'like', $like)->pluck('id');
        if (Schema::hasTable('outbound_request_import_rows')) {
            $importIds = $importIds
                ->merge(DB::table('outbound_request_import_rows')->whereIn('resolved_sku_id', $skuIds)->pluck('import_id'))
                ->unique()
                ->values();
        }

        $requestIds = DB::table('outbound_requests')
            ->where('request_no', 'like', $like)
            ->orWhereIn('import_id', $importIds)
            ->pluck('id');
        $documentIds = DB::table('stock_documents')
            ->where('doc_no', 'like', $like)
            ->orWhereIn('outbound_request_id', $requestIds)
            ->pluck('id');

        $lineIds = DB::table('stock_document_lines')->whereIn('stock_document_id', $documentIds)->pluck('id');
        DB::table('outbound_item_matches')->whereIn('outbound_line_id', $lineIds)->delete();
        DB::table('inbound_scan_entries')->whereIn('inbound_line_id', $lineIds)->delete();
        DB::table('label_snapshots')->whereIn('outbound_document_id', $documentIds)->delete();
        // Bảng mirror ERD (outbound_documents/outbound_lines) do trigger
        // trg_erd_mirror_outbound_* sinh ra và giữ FK tới outbound_request_lines,
        // nên phải dọn trước bảng nguồn.
        foreach (['outbound_lines', 'inbound_lines'] as $mirrorLines) {
            if (Schema::hasTable($mirrorLines)) {
                DB::table($mirrorLines)->whereIn('id', $lineIds)->delete();
            }
        }
        foreach (['outbound_documents', 'inbound_documents'] as $mirrorDocuments) {
            if (Schema::hasTable($mirrorDocuments)) {
                DB::table($mirrorDocuments)->whereIn('id', $documentIds)->delete();
            }
        }
        DB::table('stock_document_lines')->whereIn('id', $lineIds)->delete();
        DB::table('stock_documents')->whereIn('id', $documentIds)->delete();
        DB::table('outbound_request_lines')->whereIn('outbound_request_id', $requestIds)->delete();
        DB::table('outbound_requests')->whereIn('id', $requestIds)->delete();
        if (Schema::hasTable('outbound_request_import_rows')) {
            DB::table('outbound_request_import_rows')->whereIn('import_id', $importIds)->delete();
        }
        DB::table('outbound_request_imports')->whereIn('id', $importIds)->delete();
        DB::table('physical_codes')->where('code_value', 'like', $like)->delete();
        DB::table('item_instances')->where('serial_number', 'like', $like)->delete();
        DB::table('inventory_balances')->whereIn('sku_id', $skuIds)->delete();
        Sku::query()->whereIn('sku_id', $skuIds)->delete();
        DB::table('user_role')->whereIn(
            'user_id',
            User::query()->where('username', 'like', strtolower(W199_PREFIX).'-%')->pluck('user_id')
        )->delete();
        User::query()->where('username', 'like', strtolower(W199_PREFIX).'-%')->forceDelete();
    });
}

/**
 * Một toa + một phiếu xuất DRAFT đúng hình dạng mà Confirm toa sinh ra
 * (SRS §8.1 không có POST /outbound-documents).
 *
 * @return array{request_id: string, document_id: string, line_id: string}
 */
function w199_seed_request_and_document(string $warehouseId, string $skuId, string $userId, string $suffix): array
{
    $importId = (string) Str::uuid();
    $checksum = hash('sha256', 'wwhn199-race:'.$suffix.':'.$importId);
    DB::table('outbound_request_imports')->insert([
        'id' => $importId,
        'import_no' => W199_PREFIX.'-OBI-'.strtoupper(Str::random(8)),
        'source_file_name' => 'wwhn199-race.xlsx',
        'source_checksum' => $checksum,
        'source_key' => 'CHECKSUM:'.$checksum,
        'status' => 'CONSUMED',
        'total_rows' => 1,
        'valid_rows' => 1,
        'error_rows' => 0,
        'consumed_at' => now(),
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $requestId = (string) Str::uuid();
    DB::table('outbound_requests')->insert([
        'id' => $requestId,
        'request_no' => W199_PREFIX.'-TOA-'.$suffix.'-'.strtoupper(Str::random(5)),
        'import_id' => $importId,
        'source_checksum' => $checksum,
        'customer_name' => 'Dai ly race',
        'customer_address' => '123 Race Street',
        'recipient_name' => 'Dai ly race',
        'recipient_address' => '123 Race Street',
        'request_date' => now()->toDateString(),
        'status' => 'CONSUMED',
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $requestLineId = (string) Str::uuid();
    DB::table('outbound_request_lines')->insert([
        'id' => $requestLineId,
        'outbound_request_id' => $requestId,
        'line_no' => 1,
        'sku_id' => $skuId,
        'required_qty' => 1,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $documentId = (string) Str::uuid();
    DB::table('stock_documents')->insert([
        'id' => $documentId,
        'doc_type' => 'issue',
        'doc_no' => W199_PREFIX.'-PX-'.$suffix.'-'.strtoupper(Str::random(6)),
        'doc_date' => now()->toDateString(),
        'status' => 'DRAFT',
        'src_warehouse_id' => $warehouseId,
        'outbound_request_id' => $requestId,
        'recipient_name' => 'Dai ly race',
        'recipient_address' => '123 Race Street',
        'version' => 1,
        'created_by' => $userId,
        'updated_by' => $userId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $lineId = (string) Str::uuid();
    DB::table('stock_document_lines')->insert([
        'id' => $lineId,
        'stock_document_id' => $documentId,
        'outbound_request_line_id' => $requestLineId,
        'line_no' => 1,
        'product_id' => $skuId,
        'qty_planned' => 1,
        'qty_actual' => 1,
        'scanned_qty' => 0,
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['request_id' => $requestId, 'document_id' => $documentId, 'line_id' => $lineId];
}

$mode = $argv[1] ?? 'build';
$output = $argv[2] ?? 'build/evidence/wwhn-199/race-fixture.json';
$outputPath = preg_match('#^([A-Za-z]:)?[\\\\/]#', $output) === 1 ? $output : dirname(__DIR__).'/'.$output;

if (DB::getDriverName() !== 'pgsql') {
    fwrite(STDERR, 'DEV199-P1-01R yeu cau PostgreSQL; driver hien tai: '.DB::getDriverName()."\n");
    exit(2);
}

if ($mode === 'cleanup') {
    w199_cleanup();
    fwrite(STDOUT, "cleanup done\n");
    exit(0);
}

w199_cleanup();

if ((int) DB::table('permission')->count() === 0) {
    Artisan::call('db:seed', [
        '--class' => AuthDatabaseSeeder::class,
        '--force' => true,
    ]);
}

$roleId = DB::table('role')->where('role_code', 'SUPER_ADMIN')->value('role_id');
if ($roleId === null) {
    fwrite(STDERR, "Khong tim thay role SUPER_ADMIN sau khi seed.\n");
    exit(2);
}

/**
 * Ghi qua Eloquent model (không raw insert) vì `app_user`/`sku` là write-bridge
 * view của bảng vật lý số nhiều; model giữ đúng ánh xạ PK theo driver.
 */
$user = User::create([
    'username' => strtolower(W199_PREFIX).'-'.strtolower(Str::random(8)),
    'display_name' => 'WWHN-199 race driver',
    'password_hash' => Hash::make('secret123'),
    'status' => 'ACTIVE',
    'version' => 1,
]);
$user->roles()->attach($roleId, ['user_role_id' => (string) Str::uuid()]);
$userId = (string) $user->user_id;
$token = auth('api')->login($user);

/** uk_warehouses_single_active: chỉ một warehouse ACTIVE — tái dùng bản có sẵn. */
$warehouse = Warehouse::query()->where('status', 'ACTIVE')->first()
    ?? Warehouse::create([
        'code' => 'KHO-TONG-HN',
        'name' => 'Kho tong Hoa Nam',
        'status' => 'ACTIVE',
    ]);
$warehouseId = (string) $warehouse->id;

$skuCode = W199_PREFIX.'-SKU-'.strtoupper(Str::random(6));
$sku = Sku::create([
    'sku_code' => $skuCode,
    'sku_name' => 'WWHN-199 race product',
    'sku_type' => 'PRODUCT',
    'unit' => 'cai',
    'is_serial_tracked' => true,
    'status' => 'ACTIVE',
]);
$skuId = (string) $sku->sku_id;

DB::table('inventory_balances')->insert([
    'id' => (string) Str::uuid(),
    'warehouse_id' => $warehouseId,
    'product_id' => $skuId,
    'sku_id' => $skuId,
    'stock_status' => 'AVAILABLE',
    'quantity' => 5,
    'on_hand_qty' => 5,
    'available_qty' => 5,
    'reserved_qty' => 0,
    'version' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

/**
 * Mỗi round là một bộ độc lập (1 item IN_STOCK + 2 phiếu xuất DRAFT từ 2 toa khác
 * nhau). Chạy nhiều round vì một lần bắn đồng thời có thể tình cờ không chồng lấn
 * ở tầng DB; kết luận "guard đúng" chỉ vững khi lặp lại và luôn ra đúng
 * một winner.
 */
$roundCount = max(1, (int) ($argv[3] ?? 5));
$rounds = [];
for ($index = 1; $index <= $roundCount; $index++) {
    $itemId = (string) Str::uuid();
    $serialNumber = W199_PREFIX.'-SN-'.strtoupper(Str::random(8));
    // Cột canonical (id/item_code/status/condition) do trg_erd_normalize_runtime_item_instances
    // điền — giữ đúng shape insert của tests/Feature/Concerns/PreparesOutboundFixtures.
    DB::table('item_instances')->insert([
        'item_id' => $itemId,
        'sku_id' => $skuId,
        'serial_number' => $serialNumber,
        'item_status' => 'IN_STOCK',
        'item_condition' => 'NORMAL',
        'version' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rounds[] = [
        'round' => $index,
        'item_id' => $itemId,
        'serial_number' => $serialNumber,
        'document_a' => w199_seed_request_and_document($warehouseId, $skuId, $userId, 'A'.$index),
        'document_b' => w199_seed_request_and_document($warehouseId, $skuId, $userId, 'B'.$index),
    ];
}

$itemId = $rounds[0]['item_id'];
$serialNumber = $rounds[0]['serial_number'];
$docA = $rounds[0]['document_a'];
$docB = $rounds[0]['document_b'];

$fixture = [
    'schema_version' => '1.0.0',
    'task' => 'WWHN-199',
    'register_item' => 'DEV199-P1-01R',
    'driver' => DB::getDriverName(),
    'database' => DB::connection()->getDatabaseName(),
    'generated_at' => now()->toIso8601String(),
    'prefix' => W199_PREFIX,
    'token' => $token,
    'user_id' => $userId,
    'warehouse_id' => $warehouseId,
    'sku_id' => $skuId,
    'sku_code' => $skuCode,
    'item_id' => $itemId,
    'serial_number' => $serialNumber,
    'document_a' => $docA,
    'document_b' => $docB,
    'rounds' => $rounds,
];

if (! is_dir(dirname($outputPath))) {
    mkdir(dirname($outputPath), 0777, true);
}
file_put_contents(
    $outputPath,
    json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
);
fwrite(STDOUT, 'fixture written: '.$outputPath."\n");
