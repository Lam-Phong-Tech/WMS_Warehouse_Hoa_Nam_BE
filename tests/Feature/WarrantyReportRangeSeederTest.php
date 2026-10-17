<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Modules\Auth\Models\User;
use Modules\Warehouse\Database\Seeders\DongChengAnchorSkus;
use Modules\Warehouse\Database\Seeders\WarrantyReportRangeSeeder;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Services\CurrentBaselineStockService;
use Modules\Warehouse\Support\SeedStrictCommand;
use Modules\Warehouse\Support\WarrantyCasePii;
use ReflectionClass;
use RuntimeException;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/** Disposable fixtures only; not live seed or stock-write approval. */
final class WarrantyReportRangeSeederTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private string $actorId;

    private string $warehouseId;

    private string $componentSku;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 09:00:00+07:00');
        Http::preventStrayRequests();
        $this->warehouseId = (string) $this->warehouseFixture()->id;
        $this->actorId = (string) User::create([
            'username' => 'keeper01', 'display_name' => 'Isolated warranty seed actor',
            'password_hash' => Hash::make(bin2hex(random_bytes(24)).'aA1!'),
            'status' => 'ACTIVE', 'version' => 1,
        ])->user_id;
        $this->componentSku = (string) Sku::create([
            'sku_code' => DongChengAnchorSkus::COMPONENT_WARRANTY,
            'sku_name' => 'Isolated component anchor', 'sku_type' => 'COMPONENT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
        ])->sku_id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_component_receipt_materializes_one_stocked_mapping_and_matching_balance(): void
    {
        $box = $this->invoke('ensureStockedBox', $this->componentSku, 120);
        $this->assertSame(120, $this->boxQuantity($box));
        $this->assertSame(1, DB::table('container_items')->where('container_id', $box)->where('is_active', true)->count());
        $this->assertSame(120, $this->balance());
        $this->assertSame(120, $this->ledger());
        $this->assertContains(DB::table('containers')->where('container_id', $box)->value('status'),
            ['IN_STOCK', 'PARTIAL', 'RECEIVED_PENDING_PUTAWAY', 'STORED']);
    }

    public function test_component_issue_posts_once_for_a_repairing_case_with_consistent_subledger(): void
    {
        $box = $this->invoke('ensureStockedBox', $this->componentSku, 120);
        $case = (string) Str::uuid();
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $case, 'warranty_case_code' => 'WC-ISOLATED-COMPONENT',
            'identification_status' => 'TEMP_ONLY', 'temporary_item_code' => 'TEMP-ISOLATED',
            'missing_code_reason' => 'Isolated test has no product tag',
            'manual_product_description' => 'Isolated warranty fixture',
            'status' => 'REPAIRING', 'version' => 1, 'created_by' => $this->actorId,
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->invoke('postComponentIssueForCase', 'CID-ISOLATED-01', $case, $this->componentSku, $box, 2);
        $this->assertSame('POSTED', DB::table('component_issue_documents')->where('component_issue_no', 'CID-ISOLATED-01')->value('status'));
        $this->assertSame(118, $this->boxQuantity($box));
        $this->assertSame(118, $this->balance());
        $this->assertSame(118, $this->ledger());
    }

    public function test_complete_report_seed_and_replay_leave_every_owned_row_unchanged(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Full report fixture requires an isolated PostgreSQL database.');
        }
        $this->issuedProductPool();
        $seeder = new WarrantyReportRangeSeeder;
        $seeder->setCommand(new SeedStrictCommand);
        $seeder->run();
        $this->assertSame(160, DB::table('warranty_case')->where('warranty_case_code', 'like', 'WC-RPTW-%')->count());
        $this->assertGreaterThan(0, DB::table('component_issue_documents')->where('component_issue_no', 'like', 'CID-RPTW-%')->count());
        $this->assertSame(0, DB::table('component_issue_documents')->where('status', '!=', 'POSTED')->count());
        $before = $this->fingerprints();
        $seeder->run();
        $this->assertSame($before, $this->fingerprints());
        $this->assertSame($this->balance(), $this->ledger());
        $box = DB::table('containers')->where('container_code', 'BOX-RPTW-001')->value('container_id');
        $this->assertSame($this->balance(), $this->boxQuantity($box));
    }

    public function test_completed_case_rejection_rolls_back_document_and_box_consumption(): void
    {
        $box = $this->invoke('ensureStockedBox', $this->componentSku, 120);
        $case = $this->temporaryCase('WC-ISOLATED-COMPLETED', 'COMPLETED');
        $before = $this->fingerprints();
        try {
            $this->invoke('postComponentIssueForCase', 'CID-INVALID-COMPLETED', $case, $this->componentSku, $box, 2);
            $this->fail('A completed case must not accept a component issue.');
        } catch (RuntimeException $error) {
            $this->assertSame('FIXTURE_OWNER_REJECTED: WARRANTY_CASE_STATUS_NOT_ALLOWED', $error->getMessage());
        }
        $this->assertSame($before, $this->fingerprints());
    }

    public function test_partial_last_case_is_not_a_completion_marker_and_is_not_repaired(): void
    {
        $this->requirePostgres();
        $this->temporaryCase('WC-RPTW-0160', 'COMPLETED');
        $before = $this->fingerprints();
        try {
            (new WarrantyReportRangeSeeder)->run();
            $this->fail('A last case alone is not complete component evidence.');
        } catch (RuntimeException $error) {
            $this->assertSame('WARRANTY_REPORT_EXISTING_FIXTURE_REQUIRES_REVIEW', $error->getMessage());
        }
        $this->assertSame($before, $this->fingerprints());
    }

    public function test_existing_box_is_not_adopted_or_restocked(): void
    {
        $this->requirePostgres();
        $id = (string) Str::uuid();
        DB::table('containers')->insert([
            'container_id' => $id, ...(Schema::hasColumn('containers', 'id') ? ['id' => $id] : []),
            'container_code' => 'BOX-RPTW-001', 'container_type' => 'BOX',
            'primary_sku_id' => $this->componentSku, 'status' => 'EMPTY', 'version' => 1,
            'created_by' => $this->actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->fingerprints();
        try {
            (new WarrantyReportRangeSeeder)->run();
            $this->fail('An existing BOX must not be claimed as newly created fixture stock.');
        } catch (RuntimeException $error) {
            $this->assertSame('WARRANTY_REPORT_EXISTING_RESOURCES_REQUIRES_REVIEW', $error->getMessage());
        }
        $this->assertSame($before, $this->fingerprints());
    }

    public function test_component_failure_rolls_back_the_entire_report_phase_and_redacts_details(): void
    {
        $this->requirePostgres();
        $this->issuedProductPool();
        $stock = Mockery::mock(CurrentBaselineStockService::class)->makePartial();
        $stock->shouldReceive('postComponentIssue')->once()->andThrow(new RuntimeException('do-not-export-private-fixture-data'));
        $this->app->instance(CurrentBaselineStockService::class, $stock);
        $before = $this->fingerprints();
        $seeder = new WarrantyReportRangeSeeder;
        $seeder->setCommand(new SeedStrictCommand);
        try {
            $seeder->run();
            $this->fail('Failed component work must fail the complete report fixture phase.');
        } catch (RuntimeException $error) {
            $this->assertSame('WARRANTY_REPORT_FIXTURE_FAILED: UNCLASSIFIED_FAILURE', $error->getMessage());
            $this->assertStringNotContainsString('do-not-export', $error->getMessage());
        }
        $this->assertSame($before, $this->fingerprints());
    }

    public function test_protected_database_stops_before_any_seeder_queries(): void
    {
        config(['deployment_safety.protect_database' => true]);
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        try {
            (new WarrantyReportRangeSeeder)->run();
            $this->fail('Protected databases must remain blocked.');
        } catch (RuntimeException $error) {
            $this->assertSame('WARRANTY_REPORT_PROTECTED_DATABASE', $error->getMessage());
        }
        $this->assertSame([], $queries);
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Report fixture execution requires isolated PostgreSQL.');
        }
    }

    private function issuedProductPool(): void
    {
        $sku = $this->productSkuFixture('ISOLATED-WARRANTY-PRODUCT');
        for ($i = 0; $i < 160; $i++) {
            $id = $this->seedItemInStock($sku->sku_id);
            DB::table('item_instances')->where('item_id', $id)->update(['item_status' => 'ISSUED']);
        }
    }

    private function temporaryCase(string $code, string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('warranty_case')->insert(WarrantyCasePii::encode([
            'warranty_case_id' => $id, 'warranty_case_code' => $code, 'status' => $status,
            'identification_status' => 'TEMP_ONLY', 'temporary_item_code' => 'TEMP-'.$code,
            'missing_code_reason' => 'Isolated test has no physical tag',
            'manual_product_description' => 'Isolated test fixture', 'version' => 1,
            'created_by' => $this->actorId, 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $seeder = new WarrantyReportRangeSeeder;
        $reflection = new ReflectionClass($seeder);
        foreach (['actorId' => $this->actorId, 'warehouseId' => $this->warehouseId,
            'stock' => app(CurrentBaselineStockService::class)] as $name => $value) {
            $reflection->getProperty($name)->setValue($seeder, $value);
        }
        try {
            return $reflection->getMethod($method)->invoke($seeder, ...$arguments);
        } catch (HttpResponseException $error) {
            $body = json_decode($error->getResponse()->getContent(), true);
            throw new RuntimeException('FIXTURE_OWNER_REJECTED: '.($body['error_code'] ?? $body['error']['code'] ?? 'UNKNOWN'));
        } catch (QueryException $error) {
            throw new RuntimeException('FIXTURE_SQLSTATE: '.($error->errorInfo[0] ?? 'UNKNOWN'));
        }
    }

    private function boxQuantity(string $id): int
    {
        return (int) DB::table('container_items')->where('container_id', $id)->where('is_active', true)->sum('quantity');
    }

    private function balance(): int
    {
        return (int) DB::table('inventory_balances')->where('warehouse_id', $this->warehouseId)->where('product_id', $this->componentSku)->sum('on_hand_qty');
    }

    private function ledger(): int
    {
        return (int) DB::table('stock_movements')->where('warehouse_id', $this->warehouseId)->where('product_id', $this->componentSku)->sum('qty_delta');
    }

    private function fingerprints(): array
    {
        $tables = ['warranty_case', 'warranty_event', 'warranty_case_defects', 'item_instances',
            'sku', 'stock_documents', 'stock_document_lines', 'inbound_scan_entries', 'stock_movements',
            'inventory_balances', 'inventory_movements', 'containers', 'container_items',
            'component_issue_documents', 'component_issue_lines', 'component_issue_items', 'audit_logs'];
        $result = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
            $result[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $result;
    }
}
