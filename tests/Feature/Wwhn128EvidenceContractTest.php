<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Sinh evidence DB có cấu trúc cho WWHN-128 trên đúng database engine của CI.
 *
 * Test này không thay chữ ký QA1. Nó chứng minh tự động rằng:
 * - ledger dùng qty_delta có dấu và tách stock_status;
 * - balance projection khớp ledger sau fixture operation;
 * - các unique index cốt lõi tồn tại trên database runtime;
 * - artifact có snapshot trước/sau và response reconciliation để QA1 tải về kiểm tra.
 */
class Wwhn128EvidenceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_sha_db_before_after_reconciliation_and_indexes_are_exportable(): void
    {
        [$user, $token] = $this->createSuperAdmin();
        $warehouse = Warehouse::query()->create([
            'code' => 'KHO-W128-EVID',
            'name' => 'Kho evidence WWHN-128',
            'status' => 'ACTIVE',
        ]);
        $product = Product::query()->create([
            'code' => 'SP-W128-EVID',
            'name' => 'Sản phẩm evidence WWHN-128',
            'status' => 'ACTIVE',
        ]);

        $before = $this->snapshot($warehouse->id, $product->id);
        $this->assertSame(0, $before['movement_count']);
        $this->assertSame([], $before['balances']);

        InventoryBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'on_hand_qty' => 7,
            'available_qty' => 7,
            'reserved_qty' => 0,
            'version' => 1,
        ]);
        InventoryBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'DAMAGED',
            'on_hand_qty' => 2,
            'available_qty' => 0,
            'reserved_qty' => 0,
            'version' => 1,
        ]);

        $this->movement($user, $warehouse, $product, 'AVAILABLE', '10.000', '0.000', '10.000', StockMovement::RECEIPT_POSTED);
        $this->movement($user, $warehouse, $product, 'AVAILABLE', '-3.000', '10.000', '7.000', StockMovement::ISSUE_POSTED);
        $this->movement($user, $warehouse, $product, 'DAMAGED', '2.000', '0.000', '2.000', StockMovement::RECEIPT_POSTED);

        $response = $this->getJson(
            "/api/v1/reports/inventory-reconciliation?warehouse_id={$warehouse->id}&product_id={$product->id}",
            ['Authorization' => "Bearer {$token}"]
        )
            ->assertOk()
            ->assertJsonPath('data.summary.rows_checked', 2)
            ->assertJsonPath('data.summary.mismatch_count', 0)
            ->assertJsonPath('data.summary.status', 'PASS');

        $after = $this->snapshot($warehouse->id, $product->id);
        $this->assertSame(3, $after['movement_count']);
        $this->assertSame(7.0, $after['ledger_by_status']['AVAILABLE']);
        $this->assertSame(2.0, $after['ledger_by_status']['DAMAGED']);
        $this->assertSame(7.0, $after['balance_by_status']['AVAILABLE']);
        $this->assertSame(2.0, $after['balance_by_status']['DAMAGED']);

        $schema = $this->schemaVerification();
        $this->assertSame('PASS', $schema['summary']['status']);
        $this->assertSame(0, $schema['summary']['failed']);

        $this->writeEvidence('db-before.json', [
            'ticket' => 'WWHN-128',
            'phase' => 'BEFORE_LEDGER_AND_PROJECTION_FIXTURE',
            'database_driver' => DB::connection()->getDriverName(),
            'snapshot' => $before,
        ]);
        $this->writeEvidence('db-after.json', [
            'ticket' => 'WWHN-128',
            'phase' => 'AFTER_SIGNED_LEDGER_AND_PROJECTION_FIXTURE',
            'database_driver' => DB::connection()->getDriverName(),
            'snapshot' => $after,
        ]);
        $this->writeEvidence('reconciliation-report.json', [
            'ticket' => 'WWHN-128',
            'formula' => 'variance = COALESCE(balance.on_hand_qty, 0) - SUM(stock_movements.qty_delta)',
            'dimension' => ['warehouse_id', 'product_id', 'stock_status'],
            'summary' => $response->json('data.summary'),
            'rows' => $response->json('data.rows'),
            'status' => $response->json('data.summary.status'),
        ]);
        $this->writeEvidence('schema-verification.json', $schema);
    }

    private function snapshot(string $warehouseId, string $productId): array
    {
        $balances = DB::table('inventory_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->orderBy('stock_status')
            ->get(['stock_status', 'on_hand_qty', 'available_qty', 'reserved_qty', 'version'])
            ->map(fn ($row) => [
                'stock_status' => (string) $row->stock_status,
                'on_hand_qty' => (float) $row->on_hand_qty,
                'available_qty' => (float) $row->available_qty,
                'reserved_qty' => (float) $row->reserved_qty,
                'version' => (int) $row->version,
            ])
            ->all();

        $ledger = DB::table('stock_movements')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->select('stock_status')
            ->selectRaw('SUM(qty_delta) AS signed_qty, COUNT(*) AS movement_count')
            ->groupBy('stock_status')
            ->orderBy('stock_status')
            ->get();

        return [
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'movement_count' => (int) $ledger->sum('movement_count'),
            'balances' => $balances,
            'balance_by_status' => collect($balances)->mapWithKeys(
                fn (array $row) => [$row['stock_status'] => $row['on_hand_qty']]
            )->all(),
            'ledger_by_status' => $ledger->mapWithKeys(
                fn ($row) => [(string) $row->stock_status => (float) $row->signed_qty]
            )->all(),
        ];
    }

    private function schemaVerification(): array
    {
        $checks = [
            $this->uniqueIndexCheck('idempotency_keys', ['idem_key', 'operation', 'user_id']),
            $this->uniqueIndexCheck('inventory_balances', ['warehouse_id', 'product_id', 'stock_status']),
            $this->uniqueIndexCheck('stock_movements', ['business_key']),
        ];
        $failed = collect($checks)->where('status', 'FAIL')->count();

        return [
            'ticket' => 'WWHN-128',
            'database_driver' => DB::connection()->getDriverName(),
            'checks' => $checks,
            'summary' => [
                'total' => count($checks),
                'failed' => $failed,
                'status' => $failed === 0 ? 'PASS' : 'FAIL',
            ],
        ];
    }

    private function uniqueIndexCheck(string $table, array $expectedColumns): array
    {
        $indexes = $this->indexesFor($table);
        $match = collect($indexes)->first(function (array $index) use ($expectedColumns): bool {
            $actual = $index['columns'];

            return $index['unique'] === true && $actual === $expectedColumns;
        });

        return [
            'table' => $table,
            'expected_columns' => $expectedColumns,
            'matched_index' => $match,
            'status' => $match === null ? 'FAIL' : 'PASS',
        ];
    }

    /** @return array<int, array{name:string,unique:bool,columns:array<int,string>}> */
    private function indexesFor(string $table): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $result = [];
            foreach (DB::select("PRAGMA index_list('{$table}')") as $index) {
                $columns = collect(DB::select("PRAGMA index_info('{$index->name}')"))
                    ->sortBy('seqno')
                    ->pluck('name')
                    ->map(fn ($column) => (string) $column)
                    ->values()
                    ->all();
                $result[] = [
                    'name' => (string) $index->name,
                    'unique' => (bool) $index->unique,
                    'columns' => $columns,
                ];
            }

            return $result;
        }

        if ($driver === 'pgsql') {
            return collect(DB::select(
                'SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?',
                [$table]
            ))->map(function ($index): array {
                preg_match('/\(([^)]+)\)/', (string) $index->indexdef, $matches);
                $columns = collect(explode(',', $matches[1] ?? ''))
                    ->map(fn (string $column) => trim($column, " \t\n\r\0\x0B\""))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'name' => (string) $index->indexname,
                    'unique' => str_contains(strtoupper((string) $index->indexdef), 'UNIQUE INDEX'),
                    'columns' => $columns,
                ];
            })->all();
        }

        return [];
    }

    private function writeEvidence(string $filename, array $payload): void
    {
        $directory = getenv('WWHN128_EVIDENCE_DIR');
        if ($directory === false || trim($directory) === '') {
            return;
        }

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->fail("Không thể tạo evidence directory: {$directory}");
        }

        $payload['generated_at'] = now()->toIso8601String();
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $written = file_put_contents(rtrim($directory, '/').'/'.$filename, $encoded."\n");
        $this->assertNotFalse($written, "Không thể ghi evidence file {$filename}");
    }

    private function movement(
        User $user,
        Warehouse $warehouse,
        Product $product,
        string $stockStatus,
        string $delta,
        string $before,
        string $after,
        string $type
    ): void {
        StockMovement::query()->create([
            'movement_no' => 'MV-W128-'.strtoupper(Str::random(10)),
            'business_key' => hash('sha256', (string) Str::uuid()),
            'movement_type' => $type,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => $stockStatus,
            'qty_before' => $before,
            'qty_delta' => $delta,
            'qty_after' => $after,
            'created_by' => $user->user_id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function createSuperAdmin(): array
    {
        $role = Role::query()->create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Quản trị hệ thống',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::query()->create([
            'username' => 'wwhn128-evidence-admin',
            'display_name' => 'WWHN-128 Evidence Admin',
            'email' => 'wwhn128-evidence-admin@wms.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return [$user, JWTAuth::fromUser($user)];
    }
}
