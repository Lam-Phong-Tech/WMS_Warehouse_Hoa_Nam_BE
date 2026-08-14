<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\AuditLog\Models\AuditLog;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class Wwhn219InventoryReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $username): User
    {
        return User::query()->create([
            'username' => $username,
            'display_name' => $username,
            'email' => $username.'@wms.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
    }

    public function test_reconcile_command_creates_incident_and_audit_for_mismatch(): void
    {
        $user = $this->createUser('reconcile-cmd-user-01');
        $warehouse = Warehouse::query()->create(['code' => 'KHO-CMD-01', 'name' => 'Kho CMD 01', 'status' => 'ACTIVE']);
        $product = Product::query()->create(['code' => 'SP-CMD-01', 'name' => 'SP CMD 01', 'status' => 'ACTIVE']);

        StockMovement::create([
            'movement_no' => 'MV-CMD-MISMATCH',
            'business_key' => 'test:cmd:mismatch',
            'movement_type' => StockMovement::RECEIPT_POSTED,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => 0,
            'qty_delta' => 10,
            'qty_after' => 10,
            'created_by' => $user->user_id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        InventoryBalance::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'on_hand_qty' => 6,
            'available_qty' => 6,
            'reserved_qty' => 0,
            'version' => 1,
        ]);

        $this->artisan('inventory:reconcile')->assertSuccessful();

        $this->assertDatabaseHas('reconciliation_incident', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'divergence_type' => 'QUANTITY_MISMATCH',
            'status' => 'OPEN',
        ]);

        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'RECONCILIATION_INCIDENT_DETECTED')->count()
        );
    }

    public function test_reconcile_command_does_not_duplicate_incident_when_open_incident_exists(): void
    {
        $user = $this->createUser('reconcile-cmd-user-02');
        $warehouse = Warehouse::query()->create(['code' => 'KHO-CMD-02', 'name' => 'Kho CMD 02', 'status' => 'ACTIVE']);
        $product = Product::query()->create(['code' => 'SP-CMD-02', 'name' => 'SP CMD 02', 'status' => 'ACTIVE']);

        StockMovement::create([
            'movement_no' => 'MV-CMD-DUP',
            'business_key' => 'test:cmd:dup',
            'movement_type' => StockMovement::RECEIPT_POSTED,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => 0,
            'qty_delta' => 5,
            'qty_after' => 5,
            'created_by' => $user->user_id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        DB::table('reconciliation_incident')->insert([
            'incident_id' => (string) Str::uuid(),
            'incident_no' => 'REC-EXISTING-0001',
            'divergence_type' => 'LEDGER_MISSING_FOR_BALANCE',
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'status' => 'OPEN',
            'created_at' => now(),
        ]);

        $this->artisan('inventory:reconcile')->assertSuccessful();

        $this->assertSame(
            1,
            DB::table('reconciliation_incident')
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->count()
        );
    }

    public function test_dry_run_reports_mismatch_without_writing_incident(): void
    {
        $user = $this->createUser('reconcile-cmd-user-03');
        $warehouse = Warehouse::query()->create(['code' => 'KHO-CMD-03', 'name' => 'Kho CMD 03', 'status' => 'ACTIVE']);
        $product = Product::query()->create(['code' => 'SP-CMD-03', 'name' => 'SP CMD 03', 'status' => 'ACTIVE']);

        StockMovement::create([
            'movement_no' => 'MV-CMD-DRYRUN',
            'business_key' => 'test:cmd:dryrun',
            'movement_type' => StockMovement::RECEIPT_POSTED,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'stock_status' => 'AVAILABLE',
            'qty_before' => 0,
            'qty_delta' => 3,
            'qty_after' => 3,
            'created_by' => $user->user_id,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $this->artisan('inventory:reconcile', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(
            0,
            DB::table('reconciliation_incident')
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->count()
        );
    }
}
