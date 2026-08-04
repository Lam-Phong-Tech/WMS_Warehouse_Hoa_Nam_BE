<?php

namespace Tests\Feature;

use App\Support\Wwhn172MigrationInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Wwhn172MigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_schema_passes_preflight(): void
    {
        $report = app(Wwhn172MigrationInspector::class)->inspect();

        $this->assertSame('PASS', $report['summary']['status']);
        $this->assertSame(0, $report['summary']['failed']);
        $this->assertSame(DB::getDriverName(), $report['database_driver']);
    }

    public function test_preflight_blocks_legacy_duplicate_serial_claims(): void
    {
        $original = DB::getDefaultConnection();
        Config::set('database.connections.wwhn172_fixture', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('wwhn172_fixture');
        DB::setDefaultConnection('wwhn172_fixture');

        try {
            Schema::create('container_item', function ($table): void {
                $table->string('container_item_id')->primary();
                $table->string('container_id');
                $table->string('item_key')->nullable();
                $table->string('inventory_serial_id')->nullable();
                $table->string('product_id')->nullable();
                $table->decimal('quantity', 18, 3)->nullable();
                $table->string('status')->default('ACTIVE');
            });
            Schema::create('container', function ($table): void {
                $table->string('container_id')->primary();
                $table->string('physical_code_id')->nullable();
                $table->timestamp('deleted_at')->nullable();
            });

            DB::table('container_item')->insert([
                [
                    'container_item_id' => 'ci-1',
                    'container_id' => 'c-1',
                    'item_key' => 'SERIAL:s-1',
                    'inventory_serial_id' => 's-1',
                    'product_id' => 'p-1',
                    'quantity' => 1,
                    'status' => 'ACTIVE',
                ],
                [
                    'container_item_id' => 'ci-2',
                    'container_id' => 'c-2',
                    'item_key' => 'SERIAL:s-1',
                    'inventory_serial_id' => 's-1',
                    'product_id' => 'p-1',
                    'quantity' => 1,
                    'status' => 'ACTIVE',
                ],
            ]);

            $report = app(Wwhn172MigrationInspector::class)->inspect();
            $serialCheck = collect($report['checks'])->firstWhere('name', 'active_serial_claimed_by_multiple_containers');

            $this->assertSame('BLOCKED', $report['summary']['status']);
            $this->assertSame('BLOCKED', $serialCheck['status']);
            $this->assertSame(1, $serialCheck['violation_count']);
        } finally {
            DB::setDefaultConnection($original);
            DB::disconnect('wwhn172_fixture');
            DB::purge('wwhn172_fixture');
        }
    }
}
