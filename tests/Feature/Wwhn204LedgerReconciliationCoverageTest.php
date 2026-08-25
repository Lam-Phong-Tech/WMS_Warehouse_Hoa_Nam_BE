<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\TestCase;

/**
 * WWHN-204 (BE2): ledger/số dư/truy vấn/đối soát — rà soát output "tồn kho backend".
 *
 * Covers the two endpoints in the ledger/balance/reconciliation surface that had
 * zero HTTP test coverage before this task: GET /stock-movements/reversible (never
 * exercised by any existing test) and GET /inventory/balances with a real
 * container_items row present (existing Wwhn191 tests only exercise the item
 * subledger branch, not the container branch of InventoryBalanceController::
 * buildSubledgerCounts()). Also re-verifies `php artisan inventory:reconcile`
 * end-to-end via a deliberately introduced ledger/balance mismatch.
 */
class Wwhn204LedgerReconciliationCoverageTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'wwhn204-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-204 Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function userWithReversalViewPermission(): array
    {
        $roleCode = 'REV_VIEW_'.Str::upper(Str::random(6));
        $role = Role::create(['role_code' => $roleCode, 'role_name' => $roleCode, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        foreach (['inventory.reversal.post', 'inventory.view', 'inbound.view', 'inbound.create', 'inbound.scan', 'inbound.post', 'container.manage'] as $permissionKey) {
            $permission = Permission::firstOrCreate(
                ['permission_key' => $permissionKey],
                ['permission_name' => $permissionKey, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']
            );
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permission->permission_id],
                ['role_permission_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $user = User::create([
            'username' => 'wwhn204-rev-'.Str::lower(Str::random(6)),
            'display_name' => 'WWHN-204 Reversal View Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hòa Nam', 'status' => 'ACTIVE']
        );
    }

    /**
     * Drives a full post-receipt HTTP flow theo contract GĐ1 scan-driven
     * (header-only + component-lines + Box + đếm thực tế), returns
     * [document_id, line_id, box_id].
     */
    private function postReceiptOf(array $headers, Warehouse $warehouse, Sku $sku, int $qty, string $boxCode, string $idemKey): array
    {
        $document = $this->createInboundHeader($headers, (string) $warehouse->id, [
            'source_name' => 'Nha cung cap WWHN-204',
        ]);

        $received = $this->receiveComponentQuantity(
            $headers,
            $document['document_id'],
            (string) $sku->sku_id,
            $qty,
            $document['version'],
            $boxCode
        );

        $this->postInboundReceipt($headers, $document['document_id'], $received['version'], $idemKey)
            ->assertOk()->assertJsonPath('data.status', 'POSTED');

        return [$document['document_id'], $received['line_id'], $received['container_id']];
    }

    /**
     * GET /stock-movements/reversible had zero test coverage anywhere in the suite.
     * It joins against `products`, the updatable compatibility view backed by `sku`
     * (see migration 2026_08_09_000300_create_products_compatibility_view.php) —
     * this must succeed (200, not 500) and surface the correct sku_code/sku_name.
     */
    public function test_stock_movements_reversible_lists_posted_receipt_with_correct_sku_fields(): void
    {
        $headers = $this->userWithReversalViewPermission();
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-REV-001',
            'sku_name' => 'Component reversible list test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $this->postReceiptOf($headers, $warehouse, $sku, 5, 'BOX-REV-001', 'wwhn204-reversible-001');

        $response = $this->getJson('/api/v1/stock-movements/reversible', $headers);

        $response->assertOk();
        $rows = collect($response->json('data'));
        $row = $rows->firstWhere('lines.0.sku', $sku->sku_code);

        $this->assertNotNull($row, 'Expected the posted receipt movement to appear in the reversible list.');
        $this->assertSame($sku->sku_code, $row['lines'][0]['sku']);
        $this->assertSame($sku->sku_name, $row['lines'][0]['sku_name']);
        $this->assertSame('cai', $row['lines'][0]['uom']);
        $this->assertSame(5, $row['lines'][0]['original_qty']);
        $this->assertTrue($row['can_reverse']);
    }

    /**
     * The `search` filter builds `ILIKE` (Postgres-only syntax) directly into the SQL
     * string via whereRaw — this is a real portability bug: on any non-Postgres
     * connection (e.g. this suite's SQLite test DB) it throws a raw PDOException that
     * surfaces as an uncaught 500, not a handled API error.
     */
    public function test_stock_movements_reversible_search_filter_does_not_crash_on_sqlite(): void
    {
        $headers = $this->userWithReversalViewPermission();
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-REV-002',
            'sku_name' => 'Component reversible search test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $this->postReceiptOf($headers, $warehouse, $sku, 2, 'BOX-REV-002', 'wwhn204-reversible-002');

        $this->getJson('/api/v1/stock-movements/reversible?search='.$sku->sku_code, $headers)
            ->assertOk();
    }

    public function test_stock_movements_reversible_requires_authentication(): void
    {
        $this->getJson('/api/v1/stock-movements/reversible')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    /**
     * InventoryBalanceController::index() calls buildSubledgerCounts(), which counts
     * both item_instances AND container_items rows per SKU. Prior WWHN-191 coverage
     * only ever produced item_instances rows (no active container_items row for the
     * balance's SKU), so the container_items branch of that query was never actually
     * exercised end-to-end. This test forces a real container_items row to exist for
     * the posted SKU and asserts GET /inventory/balances still returns 200 with a
     * correct container_subledger_count instead of a 500 from a bad column reference.
     */
    public function test_inventory_balances_list_succeeds_with_active_container_items_present(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-CTR-001',
            'sku_name' => 'Component container subledger test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $this->postReceiptOf($headers, $warehouse, $sku, 6, 'BOX-CTR-001', 'wwhn204-container-001');

        // Force a real ACTIVE container_items row for this SKU via the container content
        // API (POST /containers/{id}/items, quantity-mode), independent of whatever the
        // inbound flow itself produced, so buildSubledgerCounts()'s container_items branch
        // is deterministically exercised end-to-end.
        $container = $this->postJson('/api/v1/containers', [
            'container_type' => 'BOX',
            'container_code' => 'CTR-WWHN204-'.Str::upper(Str::random(6)),
            'primary_sku_id' => $sku->sku_id,
        ], $headers);
        $container->assertCreated();
        $containerId = (string) $container->json('data.container_id');

        $addItem = $this->postJson("/api/v1/containers/{$containerId}/items", [
            'sku_id' => $sku->sku_id,
            'quantity' => 4,
        ], $headers + ['If-Match' => '1']);
        $addItem->assertCreated();

        $this->assertTrue(
            DB::table('container_items')->where('container_id', $containerId)->where('sku_id', $sku->sku_id)->where('status', 'ACTIVE')->exists(),
            'Fixture setup expected an ACTIVE container_items row for the posted SKU.'
        );

        $response = $this->getJson('/api/v1/inventory/balances?product_id='.$sku->sku_id, $headers);

        $response->assertOk();
        $rows = collect($response->json('data'));
        $row = $rows->firstWhere('product_id', $sku->sku_id);

        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(1, (int) $row['container_subledger_count']);
    }

    /**
     * php artisan inventory:reconcile: end-to-end via a real posted receipt followed by
     * a deliberate direct mutation of inventory_balances.on_hand_qty (simulating a
     * ledger/projection divergence), asserting the command detects it, records a
     * reconciliation_incidents row, and is idempotent (no duplicate incident on rerun).
     */
    public function test_inventory_reconcile_command_detects_and_records_manufactured_mismatch(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-REC-001',
            'sku_name' => 'Component reconcile command test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $this->postReceiptOf($headers, $warehouse, $sku, 7, 'BOX-REC-001', 'wwhn204-reconcile-001');

        // Manufacture a divergence: ledger (stock_movements) still says 7, but the
        // projection (inventory_balances) is corrupted to 99 — as if a bug or manual
        // DB edit desynced the two, exactly what this command exists to catch.
        DB::table('inventory_balances')->where('product_id', $sku->sku_id)->update(['on_hand_qty' => 99]);

        Artisan::call('inventory:reconcile');
        $output = Artisan::output();
        $this->assertStringContainsString('1 lệch phát hiện', $output);

        $incidentTable = DB::getDriverName() === 'pgsql' ? 'reconciliation_incidents' : 'reconciliation_incident';
        $skuColumn = DB::getDriverName() === 'pgsql' ? 'sku_id' : 'product_id';
        $incident = DB::table($incidentTable)->where($skuColumn, $sku->sku_id)->first();

        $this->assertNotNull($incident, 'Expected a reconciliation_incidents row for the manufactured mismatch.');
        $this->assertSame('QUANTITY_MISMATCH', $incident->divergence_type);
        $this->assertSame('OPEN', $incident->status);

        // Idempotency: rerunning while the incident is still OPEN must not create a duplicate.
        Artisan::call('inventory:reconcile');
        $countAfterRerun = DB::table($incidentTable)->where($skuColumn, $sku->sku_id)->count();
        $this->assertSame(1, $countAfterRerun, 'Rerunning inventory:reconcile must not duplicate an already-OPEN incident.');
    }

    public function test_inventory_reconcile_command_dry_run_does_not_write_incident(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->warehouse();
        $sku = Sku::create([
            'sku_code' => 'COMP-REC-002',
            'sku_name' => 'Component reconcile dry-run test',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $this->postReceiptOf($headers, $warehouse, $sku, 3, 'BOX-REC-002', 'wwhn204-reconcile-002');
        DB::table('inventory_balances')->where('product_id', $sku->sku_id)->update(['on_hand_qty' => 50]);

        Artisan::call('inventory:reconcile', ['--dry-run' => true]);

        $incidentTable = DB::getDriverName() === 'pgsql' ? 'reconciliation_incidents' : 'reconciliation_incident';
        $skuColumn = DB::getDriverName() === 'pgsql' ? 'sku_id' : 'product_id';
        $this->assertSame(0, DB::table($incidentTable)->where($skuColumn, $sku->sku_id)->count());
    }
}
