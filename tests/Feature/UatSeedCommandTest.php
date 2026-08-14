<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Models\User;
use Tests\TestCase;

class UatSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_uat_seed_command_creates_repeatable_accounts_and_import_traceability_data(): void
    {
        $password = 'UatSeed!234';

        $this->artisan('wms:seed-uat', ['--password' => $password])
            ->expectsOutputToContain('UAT seed complete')
            ->assertExitCode(0);

        $inbound = User::query()->where('username', 'uat.inbound')->firstOrFail();
        $this->assertTrue(Hash::check($password, $inbound->password_hash));
        $this->assertTrue($inbound->roles()->where('role_code', 'WAREHOUSE_STAFF')->exists());
        $this->assertDatabaseHas('import_batches', ['import_batch_code' => 'UAT-IMP-2026-001']);
        $this->assertDatabaseHas('physical_codes', ['code_value' => 'UAT-QR-ITEM-0001', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('item_instances', ['serial_number' => 'UAT-ITEM-0001', 'item_status' => 'NEW']);
        $this->assertDatabaseHas('inventory_serials', ['serial_no' => 'UAT-INV-0001', 'status' => 'IN_STOCK']);

        $this->artisan('wms:seed-uat', ['--password' => $password])->assertExitCode(0);

        $this->assertSame(4, User::query()->whereIn('username', ['uat.inbound', 'uat.approver', 'uat.keeper', 'uat.viewer'])->count());
        $this->assertSame(1, $inbound->fresh()->roles()->where('role_code', 'WAREHOUSE_STAFF')->count());
        $this->assertSame(1, \DB::table('import_batches')->where('import_batch_code', 'UAT-IMP-2026-001')->count());
        $this->assertSame(1, \DB::table('item_instances')->where('serial_number', 'UAT-ITEM-0001')->count());
    }

    public function test_uat_seed_command_requires_a_password(): void
    {
        config(['warehouse.uat_seed_password' => null]);

        $this->artisan('wms:seed-uat')
            ->expectsOutputToContain('Missing UAT password')
            ->assertExitCode(1);
    }
}
