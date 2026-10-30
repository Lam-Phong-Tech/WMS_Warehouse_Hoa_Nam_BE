<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Http\Controllers\StockMovementController;
use Modules\Warehouse\Support\UiuxV2OperationalScenario;
use RuntimeException;
use Tests\TestCase;
use Tests\TestCredentials;

final class UiuxV2OperationalScenarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        $app = $this->createApplication();
        $connection = $app['config']->get('database.default');
        $db = $app['config']->get('database.connections.'.$connection);
        $safe = $app->environment('testing') && empty($db['url']) &&
            (($db['driver'] === 'sqlite' && $db['database'] === ':memory:') ||
             ($db['driver'] === 'pgsql' && $db['database'] === 'p12_contract_test' && $db['host'] === '127.0.0.1'));
        $app->flush();
        if (! $safe) {
            throw new RuntimeException('UIUX tests require an isolated database.');
        }
        parent::setUp();
        Storage::fake('local');
    }

    private function actor(): string
    {
        return (string) User::create(['username' => 'uiux-operations-owner', 'display_name' => 'Fixture owner',
            'password_hash' => Hash::make(TestCredentials::PASSWORD), 'status' => 'ACTIVE', 'version' => 1])->getKey();
    }

    private function warehouse(): string
    {
        $id = (string) Str::uuid();
        DB::table('warehouses')->insert(['id' => $id, 'code' => 'UIUX-TEST-WH', 'name' => 'Existing warehouse',
            'status' => 'ACTIVE', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function test_real_stock_and_return_graph_is_repeatable_without_print_dispatch(): void
    {
        $actor = $this->actor();
        $warehouse = $this->warehouse();
        $before = DB::table('warehouses')->where('id', $warehouse)->first();
        $date = Carbon::parse('2026-10-29', 'Asia/Ho_Chi_Minh');
        $scenario = app(UiuxV2OperationalScenario::class);
        $result = $scenario->run($date, $actor);
        $this->assertSame($warehouse, $result['warehouse_id']);
        foreach (['inbound_document_id', 'component_inbound_document_id', 'outbound_document_id'] as $key) {
            $this->assertDatabaseHas('stock_documents', ['id' => $result[$key], 'status' => 'POSTED']);
        }
        $this->assertDatabaseHas('reversal_document', ['reversal_document_id' => $result['reversal_document_id'],
            'original_document_id' => $result['outbound_document_id'], 'status' => 'POSTED']);
        $attachment = DB::table('document_attachments')->where('document_id', $result['reversal_document_id'])->first();
        Storage::disk('local')->assertExists($attachment->storage_key);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($attachment->storage_key));
        $this->assertDatabaseHas('container_items', ['container_id' => $result['box_container_id'],
            'sku_id' => $result['component_sku_id'], 'quantity' => 12, 'is_active' => true]);
        $this->assertDatabaseHas('containers', ['container_id' => $result['box_container_id'],
            'parent_container_id' => $result['tray_container_id']]);
        $this->assertSame(['FAILED', 'PRINTED'], DB::table('label_print_events')
            ->where('label_snapshot_id', $result['label_snapshot_id'])->orderBy('attempt_no')->pluck('status')->all());
        $this->assertDatabaseHas('inventory_balances', ['product_id' => $result['sku_id'], 'on_hand_qty' => 1]);
        $this->assertDatabaseHas('inventory_balances', ['product_id' => $result['component_sku_id'], 'on_hand_qty' => 12]);
        $trace = app(StockMovementController::class)->traceByPhysicalCode(Request::create('/trace', 'GET', ['code' => 'UIUXV2-20261029-BOX']))->getData(true);
        $this->assertCount(1, $trace['data']['movements']);
        $this->assertSame(12, (int) $trace['data']['movements'][0]['qty_delta']);
        $tables = ['stock_documents', 'stock_movements', 'reversal_document', 'label_print_events', 'container_items'];
        $snapshots = [];
        foreach ($tables as $table) {
            $snapshots[$table] = DB::table($table)->get()->toJson();
        }
        $this->assertSame($result, $scenario->run($date, $actor));
        foreach ($tables as $table) {
            $this->assertSame($snapshots[$table], DB::table($table)->get()->toJson());
        }
        $this->assertEquals($before, DB::table('warehouses')->where('id', $warehouse)->first());
        $this->assertDatabaseCount('warehouses', 1);
    }

    public function test_foreign_collision_is_rejected_atomically(): void
    {
        $actor = $this->actor();
        $this->warehouse();
        DB::table('sku')->insert(['sku_id' => (string) Str::uuid(), 'sku_code' => 'UIUXV2-20261029-PRODUCT',
            'sku_name' => 'Unrelated stock', 'sku_type' => 'PRODUCT', 'unit' => 'cái',
            'is_serial_tracked' => true, 'status' => 'ACTIVE', 'version' => 1,
            'created_at' => now(), 'updated_at' => now()]);
        try {
            app(UiuxV2OperationalScenario::class)->run(Carbon::parse('2026-10-29'), $actor);
            $this->fail('Foreign fixture key must be rejected.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('COLLISION', $error->getMessage());
        }
        $this->assertDatabaseCount('stock_documents', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('sku', 1);
    }

    public function test_box_trace_does_not_attribute_a_shared_line_to_one_box(): void
    {
        $actor = $this->actor();
        $this->warehouse();
        $result = app(UiuxV2OperationalScenario::class)->run(Carbon::parse('2026-10-29'), $actor);
        $box = (array) DB::table('containers')->where('container_id', $result['box_container_id'])->first();
        $box['container_id'] = (string) Str::uuid();
        if (isset($box['id'])) {
            $box['id'] = $box['container_id'];
        }
        $box['container_code'] = 'UIUXV2-OTHER-BOX';
        DB::table('containers')->insert($box);
        $line = DB::table('stock_document_lines')->where('stock_document_id', $result['component_inbound_document_id'])->value('id');
        $entry = (array) DB::table('inbound_scan_entries')->where('inbound_line_id', $line)->first();
        $entry['id'] = (string) Str::uuid();
        $entry['container_id'] = $box['container_id'];
        DB::table('inbound_scan_entries')->insert($entry);
        $trace = app(StockMovementController::class)->traceByPhysicalCode(Request::create('/trace', 'GET', ['code' => 'UIUXV2-20261029-BOX']))->getData(true);
        $this->assertSame([], $trace['data']['movements']);
    }

    public function test_late_failure_rolls_back_stock_and_removes_new_evidence_file(): void
    {
        $actor = $this->actor();
        $this->warehouse();
        $injected = false;
        DB::listen(static function (QueryExecuted $event) use (&$injected): void {
            if (! $injected && str_contains($event->sql, 'insert into "document_attachments"')) {
                $injected = true;
                throw new RuntimeException('late fixture failure');
            }
        });
        try {
            app(UiuxV2OperationalScenario::class)->run(Carbon::parse('2026-10-29'), $actor);
            $this->fail('Injected failure must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('late fixture failure', $error->getMessage());
        }
        $this->assertTrue($injected);
        $this->assertDatabaseCount('stock_documents', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        Storage::disk('local')->assertMissing('uiux-v2-fixtures/UIUXV2-20261029/return.pdf');
    }

    public function test_missing_evidence_on_replay_is_reported_without_recreating_history(): void
    {
        $actor = $this->actor();
        $this->warehouse();
        $scenario = app(UiuxV2OperationalScenario::class);
        $result = $scenario->run(Carbon::parse('2026-10-29'), $actor);
        $attachment = DB::table('document_attachments')->where('document_id', $result['reversal_document_id'])->first();
        Storage::disk('local')->delete($attachment->storage_key);
        $this->expectExceptionMessage('UIUX_V2_SEED_EVIDENCE_MISSING_OR_CHANGED');
        $scenario->run(Carbon::parse('2026-10-29'), $actor);
    }
}
