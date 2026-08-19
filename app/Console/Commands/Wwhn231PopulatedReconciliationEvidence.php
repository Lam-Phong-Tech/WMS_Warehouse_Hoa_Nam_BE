<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\LedgerReconciliationService;

/**
 * WWHN-231 (DEV231-P1-04R2) — review vòng 3 chấm RECONCILIATION_RUN.json cũ chạy trên DB
 * rỗng nên mismatch=0 không mang thông tin. Lệnh này dựng 4 dimension trên PostgreSQL thật
 * qua đúng luồng nghiệp vụ HTTP (post-receipt), rồi cấy lệch có chủ đích trên 3/4 dimension,
 * chạy `inventory:reconcile` (KHÔNG dry-run) để chứng minh incident được ghi và tồn kho
 * (movement + balance) không đổi trước/sau — đúng yêu cầu register "matching dimension +
 * seeded mismatch + missing-ledger/balance".
 *
 * An toàn dữ liệu dùng chung: lệnh CHỈ chạy khi DB hiện tại đúng tên `hn_wwhn231_recon`
 * (DB cô lập tạo riêng cho evidence này) và đang rỗng — từ chối chạy trên `hoa_nam_audit`
 * hay bất kỳ DB nào khác đang được các phiên làm việc song song dùng chung.
 */
class Wwhn231PopulatedReconciliationEvidence extends Command
{
    protected $signature = 'wwhn231:populated-reconciliation-evidence';

    protected $description = 'DEV231-P1-04R2: seed 4 dimension tren PostgreSQL co lap, chay inventory:reconcile that, xac nhan incident + stock-neutral';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('Chỉ chạy trên pgsql.');

            return self::FAILURE;
        }

        $dbName = DB::connection()->getDatabaseName();
        if ($dbName !== 'hn_wwhn231_recon') {
            $this->error("Từ chối chạy: DB hiện tại là '{$dbName}'. Lệnh này CHỈ được chạy trên DB cô lập 'hn_wwhn231_recon' để không đụng dữ liệu dùng chung giữa các phiên.");

            return self::FAILURE;
        }

        if (DB::table('stock_movements')->count() > 0 || DB::table('reconciliation_incidents')->count() > 0) {
            $this->error('DB không rỗng — có vẻ lệnh đã chạy trước đó. Dừng lại để tránh double-seed.');

            return self::FAILURE;
        }

        if (DB::table('permission')->count() === 0) {
            $this->call('db:seed', ['--class' => AuthDatabaseSeeder::class]);
        }

        $role = Role::where('role_code', 'SUPER_ADMIN')->firstOrFail();
        $user = User::create([
            'username' => 'wwhn231-recon-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-231 Populated Reconciliation Evidence',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);
        $headers = ['Authorization' => "Bearer {$token}"];

        // REQ-SRS-001 + uk_warehouses_single_active: chỉ một warehouse ACTIVE, và
        // INBOUND_WAREHOUSE_NOT_CANONICAL bắt buộc đúng code 'KHO-TONG-HN' — cùng canonical
        // warehouse mà warehouseFixture() của bộ test dùng, không tự đặt code riêng.
        $warehouse = Warehouse::query()->where('status', 'ACTIVE')->first() ?? Warehouse::create([
            'code' => 'KHO-TONG-HN',
            'name' => 'Kho tổng Hòa Nam',
            'status' => 'ACTIVE',
        ]);

        $this->info('[1/6] Post 3 receipt thật qua HTTP-in-process (đúng luồng nghiệp vụ post-receipt)');
        $matching = $this->postedReceipt($headers, (string) $warehouse->id, 'WWHN231-POP-MATCH', 50);
        $qtyMismatchSeed = $this->postedReceipt($headers, (string) $warehouse->id, 'WWHN231-POP-QTYMIS', 50);
        $balanceMissingSeed = $this->postedReceipt($headers, (string) $warehouse->id, 'WWHN231-POP-BALMISS', 30);

        $this->info('[2/6] Cấy 3 dạng lệch có chủ đích trước khi đối soát');

        // QUANTITY_MISMATCH: ledger=50 nhưng balance bị lệch còn 42 (mô phỏng drift ngoài ý muốn).
        DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $qtyMismatchSeed['sku_id'])
            ->update(['on_hand_qty' => 42, 'quantity' => 42, 'available_qty' => 42, 'updated_at' => now()]);

        // BALANCE_MISSING_FOR_LEDGER: ledger có 30 nhưng không còn balance row.
        DB::table('inventory_balances')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $balanceMissingSeed['sku_id'])
            ->delete();

        // LEDGER_MISSING_FOR_BALANCE: balance có 20 nhưng không có movement nào (giống hệt
        // seedAvailableBalance() của Wwhn231StockNeutralLedgerTest trên sqlite).
        $ledgerMissingSku = Sku::create([
            'sku_code' => 'WWHN231-POP-LEDMISS',
            'sku_name' => 'WWHN-231 populated ledger-missing fixture',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        DB::table('inventory_balances')->insert([
            'id' => (string) Str::uuid(),
            'warehouse_id' => $warehouse->id,
            'product_id' => $ledgerMissingSku->sku_id,
            'sku_id' => $ledgerMissingSku->sku_id,
            'stock_status' => 'AVAILABLE',
            'quantity' => 20,
            'on_hand_qty' => 20,
            'available_qty' => 20,
            'reserved_qty' => 0,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info('[3/6] Chụp trạng thái tồn kho TRƯỚC khi chạy inventory:reconcile');
        $before = $this->stockSnapshot();
        $incidentsBefore = DB::table('reconciliation_incidents')->count();

        $this->info('[4/6] Chạy inventory:reconcile THẬT (không --dry-run)');
        $exitCode = Artisan::call('inventory:reconcile');
        $reconcileOutput = Artisan::output();

        $this->info('[5/6] Chụp trạng thái tồn kho SAU và đếm incident mới');
        $after = $this->stockSnapshot();
        $incidentsAfter = DB::table('reconciliation_incidents')->count();

        $incidentRows = DB::table('reconciliation_incidents')
            ->orderBy('created_at')
            ->get(['incident_no', 'divergence_type', 'warehouse_id', 'sku_id', 'balance_qty', 'ledger_qty', 'difference_qty', 'severity', 'status'])
            ->map(fn ($row) => (array) $row)
            ->all();

        $stockNeutral = $before === $after;

        $this->info('[6/6] Ghi evidence JSON');
        $result = [
            'task' => 'WWHN-231',
            'register_item' => 'DEV231-P1-04R2',
            'generated_by' => 'php artisan wwhn231:populated-reconciliation-evidence',
            'database' => $dbName,
            'postgres_version' => DB::selectOne('SHOW server_version')->server_version,
            'warehouse_id' => (string) $warehouse->id,
            'seeded_dimensions' => [
                'matching' => [
                    'sku_id' => $matching['sku_id'], 'sku_code' => 'WWHN231-POP-MATCH',
                    'ledger_qty' => 50, 'balance_qty' => 50, 'expected_divergence' => null,
                ],
                'quantity_mismatch' => [
                    'sku_id' => $qtyMismatchSeed['sku_id'], 'sku_code' => 'WWHN231-POP-QTYMIS',
                    'ledger_qty' => 50, 'balance_qty' => 42, 'expected_divergence' => 'QUANTITY_MISMATCH',
                ],
                'balance_missing_for_ledger' => [
                    'sku_id' => $balanceMissingSeed['sku_id'], 'sku_code' => 'WWHN231-POP-BALMISS',
                    'ledger_qty' => 30, 'balance_qty' => null, 'expected_divergence' => 'BALANCE_MISSING_FOR_LEDGER',
                ],
                'ledger_missing_for_balance' => [
                    'sku_id' => (string) $ledgerMissingSku->sku_id, 'sku_code' => 'WWHN231-POP-LEDMISS',
                    'ledger_qty' => 0, 'balance_qty' => 20, 'expected_divergence' => 'LEDGER_MISSING_FOR_BALANCE',
                ],
            ],
            'reconcile_command_exit_code' => $exitCode,
            'reconcile_command_output' => $reconcileOutput,
            'incidents_before' => $incidentsBefore,
            'incidents_after' => $incidentsAfter,
            'incidents_created' => $incidentsAfter - $incidentsBefore,
            'incident_rows' => $incidentRows,
            'stock_state_before' => $before,
            'stock_state_after' => $after,
            'stock_neutral' => $stockNeutral,
        ];

        $outPath = base_path('docs/evidence/WWHN-231/RECONCILIATION_RUN_POPULATED_20260819.json');
        file_put_contents($outPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->info('stock_neutral: '.($stockNeutral ? 'PASS' : 'FAIL'));
        $this->info('incidents created: '.($incidentsAfter - $incidentsBefore).' (kỳ vọng 3)');
        $this->info("Đã ghi {$outPath}");

        $ok = $stockNeutral && $exitCode === 0 && ($incidentsAfter - $incidentsBefore) === 3;

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function stockSnapshot(): array
    {
        return [
            'movement_count' => (int) DB::table('stock_movements')->count(),
            'movement_delta_sum' => (float) (DB::table('stock_movements')->sum('qty_delta') ?? 0),
            'balances' => DB::table('inventory_balances')
                ->select('warehouse_id', 'product_id', 'stock_status', 'on_hand_qty', 'available_qty', 'reserved_qty')
                ->orderBy('warehouse_id')
                ->orderBy('product_id')
                ->orderBy('stock_status')
                ->get()
                ->map(fn ($row): array => [
                    'key' => LedgerReconciliationService::dimensionKey($row->warehouse_id, $row->product_id, $row->stock_status),
                    'on_hand_qty' => (float) $row->on_hand_qty,
                    'available_qty' => (float) $row->available_qty,
                    'reserved_qty' => (float) $row->reserved_qty,
                ])
                ->all(),
        ];
    }

    /** @return array{document_id:string, sku_id:string} */
    private function postedReceipt(array $headers, string $warehouseId, string $skuCode, int $qty): array
    {
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => "WWHN-231 populated fixture {$skuCode}",
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $create = $this->httpJson('POST', '/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouseId,
            'source_name' => 'WWHN-231 populated reconciliation evidence',
            'lines' => [['product_id' => $sku->sku_id, 'qty_planned' => $qty]],
        ], $headers);
        $documentId = (string) $create['data']['id'];
        $lineId = (string) $create['data']['lines'][0]['id'];

        $box = $this->httpJson('POST', "/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-'.Str::upper(Str::random(8)),
        ], $headers);
        $containerId = (string) $box['data']['container_id'];

        $this->httpJson('POST', "/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => $qty,
            'container_id' => $containerId,
        ], $headers + ['If-Match' => '1']);

        $this->httpJson('POST', "/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'wwhn231-recon-'.Str::lower(Str::random(14)),
        ]);

        return ['document_id' => $documentId, 'sku_id' => (string) $sku->sku_id];
    }

    /**
     * Gửi request HTTP-in-process qua đúng HTTP Kernel (middleware/auth/validation thật),
     * không hand-roll lại nghiệp vụ posting bằng tay.
     *
     * @return array<string, mixed>
     */
    private function httpJson(string $method, string $uri, array $data, array $headers): array
    {
        $request = Request::create($uri, $method, [], [], [], [], json_encode($data, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Accept', 'application/json');
        foreach ($headers as $key => $value) {
            $request->headers->set($key, (string) $value);
        }

        $kernel = app(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $decoded = json_decode($response->getContent(), true) ?? [];
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException("HTTP {$method} {$uri} thất bại [{$response->getStatusCode()}]: ".$response->getContent());
        }

        return $decoded;
    }
}
