<?php

namespace Tests\Feature;

use App\Support\Wwhn172MigrationInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Database\Seeders\Wwhn172ContainerItemInvariantSeeder;
use RuntimeException;
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
        $this->withFixtureDatabase(function (): void {
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
        });
    }

    public function test_seed_reconciliation_backfills_deterministic_item_keys(): void
    {
        $this->withFixtureDatabase(function (): void {
            $this->createContainerItemFixtureTable();
            DB::table('container_item')->insert([
                [
                    'container_item_id' => 'ci-product',
                    'container_id' => 'container-1',
                    'item_key' => null,
                    'inventory_serial_id' => null,
                    'product_id' => 'PRODUCT-A',
                    'quantity' => 5,
                ],
                [
                    'container_item_id' => 'ci-serial',
                    'container_id' => 'container-2',
                    'item_key' => '',
                    'inventory_serial_id' => 'SERIAL-A',
                    'product_id' => 'PRODUCT-B',
                    'quantity' => 1,
                ],
            ]);

            app(Wwhn172ContainerItemInvariantSeeder::class)->run();

            $this->assertSame(
                'PRODUCT:product-a',
                DB::table('container_item')->where('container_item_id', 'ci-product')->value('item_key'),
            );
            $this->assertSame(
                'SERIAL:serial-a',
                DB::table('container_item')->where('container_item_id', 'ci-serial')->value('item_key'),
            );
        });
    }

    public function test_seed_reconciliation_is_fail_closed_without_partial_write(): void
    {
        $this->withFixtureDatabase(function (): void {
            $this->createContainerItemFixtureTable();
            DB::table('container_item')->insert([
                [
                    'container_item_id' => 'ci-1',
                    'container_id' => 'container-1',
                    'item_key' => null,
                    'inventory_serial_id' => null,
                    'product_id' => 'PRODUCT-A',
                    'quantity' => 1,
                ],
                [
                    'container_item_id' => 'ci-2',
                    'container_id' => 'container-1',
                    'item_key' => null,
                    'inventory_serial_id' => null,
                    'product_id' => 'PRODUCT-A',
                    'quantity' => 2,
                ],
            ]);

            $error = null;
            try {
                app(Wwhn172ContainerItemInvariantSeeder::class)->run();
            } catch (RuntimeException $exception) {
                $error = $exception;
            }

            $this->assertNotNull($error);
            $this->assertStringContainsString('duplicate item_key', $error->getMessage());
            $this->assertSame(2, DB::table('container_item')->whereNull('item_key')->count());
        });
    }

    private function createContainerItemFixtureTable(): void
    {
        Schema::create('container_item', function ($table): void {
            $table->string('container_item_id')->primary();
            $table->string('container_id');
            $table->string('item_key')->nullable();
            $table->string('inventory_serial_id')->nullable();
            $table->string('product_id')->nullable();
            $table->decimal('quantity', 18, 3)->nullable();
        });
    }

    private function withFixtureDatabase(callable $callback): void
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
            $callback();
        } finally {
            DB::setDefaultConnection($original);
            DB::disconnect('wwhn172_fixture');
            DB::purge('wwhn172_fixture');
        }
    }
}
