<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Modules\Warehouse\Database\Seeders\PackingLabelReportSeeder;
use RuntimeException;
use Tests\TestCase;

final class PackingLabelReportSeederTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(int $count = 10): string
    {
        $actor = (string) User::create(['username' => 'packing-fixture-owner', 'display_name' => 'Packing owner',
            'password_hash' => 'unused-fixture-hash', 'status' => 'ACTIVE', 'version' => 1])->getKey();
        $warehouse = $this->ensureActiveWarehouse('PACKING-TEST');
        for ($i = 1; $i <= $count; $i++) {
            DB::table('stock_documents')->insert(['id' => (string) Str::uuid(), 'doc_no' => sprintf('REAL-PX-%02d', $i),
                'doc_type' => 'issue', 'doc_date' => '2026-11-02', 'status' => 'POSTED',
                'recipient_name' => 'Đại lý '.$i, 'recipient_address' => 'Địa chỉ '.$i,
                'src_warehouse_id' => $warehouse->id, 'posted_at' => '2026-11-02 08:00:00', 'posted_by' => $actor,
                'created_by' => $actor, 'version' => 1, 'created_at' => '2026-11-02 08:00:00', 'updated_at' => '2026-11-02 08:00:00']);
        }
        config(['packing_label_report_seed.enabled' => true, 'packing_label_report_seed.date' => '2026-11-05',
            'packing_label_report_seed.actor_id' => $actor, 'deployment_safety.protect_database' => false]);

        return $actor;
    }

    public function test_additive_scenarios_are_repeatable_without_touching_source_documents_or_stock(): void
    {
        $this->fixtures();
        $tables = ['stock_documents', 'stock_document_lines', 'stock_movements', 'inventory_balances', 'item_instances', 'container_items'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $seeder = new PackingLabelReportSeeder;
        $seeder->run();
        $this->assertDatabaseCount('label_snapshots', 10);
        $this->assertDatabaseCount('label_print_events', 13);
        $this->assertSame(['FAILED' => 4, 'PENDING' => 1, 'PRINTED' => 7, 'SENT' => 1],
            DB::table('label_print_events')->selectRaw('status, count(*) as total')->groupBy('status')->orderBy('status')->pluck('total', 'status')->all());
        $this->assertDatabaseHas('label_print_events', ['error_code' => 'PAPER_OUT']);
        $this->assertDatabaseHas('label_print_events', ['error_code' => 'PRINTER_UNAVAILABLE']);
        $this->assertDatabaseHas('label_snapshots', ['status' => 'READY_TO_PRINT']);
        $this->assertSame(1, DB::table('label_snapshots')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('label_print_events')
            ->whereColumn('label_print_events.label_snapshot_id', 'label_snapshots.id'))->count());
        $manifest = $seeder->manifest;
        $allRows = DB::table('label_print_events')->get()->toJson();
        $seeder->run();
        $this->assertSame($manifest, $seeder->manifest);
        $this->assertSame($allRows, DB::table('label_print_events')->get()->toJson());
        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->get()->toJson(), $table.' must not change');
        }
    }

    public function test_insufficient_eligible_documents_fails_without_partial_data(): void
    {
        $this->fixtures(9);
        try {
            (new PackingLabelReportSeeder)->run();
            $this->fail('Insufficient source documents must fail closed.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('SOURCE_DOCUMENTS_REQUIRED', $error->getMessage());
        }
        $this->assertDatabaseCount('label_snapshots', 0);
        $this->assertDatabaseCount('printer_configs', 0);
    }

    public function test_opted_in_canonical_sources_keep_stock_on_owned_sku_and_replay_without_writes(): void
    {
        $this->fixtures(0);
        DB::table('sku')->insert(['sku_id' => (string) Str::uuid(), 'sku_code' => 'REAL-PRODUCT',
            'sku_name' => 'Existing template product', 'sku_type' => 'PRODUCT', 'unit' => 'cái',
            'is_serial_tracked' => true, 'status' => 'ACTIVE', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $template = DB::table('sku')->first();
        config(['packing_label_report_seed.create_sources' => true]);
        $seeder = new PackingLabelReportSeeder;
        $seeder->run();
        $graph = $seeder->manifest['source_graph'];
        $this->assertDatabaseHas('stock_documents', ['id' => $graph['inbound_document_id'], 'status' => 'POSTED']);
        $this->assertSame(5, DB::table('stock_documents')->whereIn('id', $seeder->manifest['source_document_ids'])->where('status', 'POSTED')->count());
        $this->assertSame(5, DB::table('stock_documents')->whereIn('id', $seeder->manifest['source_document_ids'])->where('status', 'SCANNING')->count());
        $this->assertDatabaseHas('inventory_balances', ['product_id' => $graph['sku_id'], 'on_hand_qty' => 5]);
        $this->assertSame(5, (int) DB::table('stock_movements')->where('sku_id', $graph['sku_id'])->sum('qty_delta'));
        $this->assertDatabaseCount('stock_movements', 6);
        $this->assertDatabaseCount('item_instances', 10);
        $this->assertEquals($template, DB::table('sku')->where('sku_id', $template->sku_id)->first());
        $before = [];
        foreach (['stock_documents', 'stock_document_lines', 'stock_movements', 'inventory_balances', 'item_instances', 'label_snapshots', 'label_print_events'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $seeder->run();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson());
        }
    }

    public function test_replay_rejects_changed_fixture_without_overwriting_history(): void
    {
        $this->fixtures();
        $seeder = new PackingLabelReportSeeder;
        $seeder->run();
        DB::table('label_snapshots')->where('id', $seeder->manifest['label_ids'][0])->update(['version' => 99]);
        $this->expectExceptionMessage('PACKING_LABEL_SEED_COLLISION');
        $seeder->run();
    }

    public function test_replay_rejects_an_appended_attempt_without_overwriting_it(): void
    {
        $this->fixtures();
        $seeder = new PackingLabelReportSeeder;
        $seeder->run();
        $row = (array) DB::table('label_print_events')->where('label_snapshot_id', $seeder->manifest['label_ids'][0])->first();
        $row['id'] = (string) Str::uuid();
        $row['attempt_no'] = 2;
        $row['request_id'] = 'REAL-APPENDED-REPRINT';
        DB::table('label_print_events')->insert($row);
        $this->expectExceptionMessage('PACKING_LABEL_SEED_COLLISION');
        $seeder->run();
    }

    public function test_opt_in_environment_actor_and_protected_database_guards_fail_closed(): void
    {
        $this->fixtures();
        foreach ([
            ['packing_label_report_seed.enabled' => false],
            ['deployment_safety.protect_database' => true, 'deployment_safety.allow_protected_database_seed' => false],
            ['packing_label_report_seed.actor_id' => (string) Str::uuid()],
            ['packing_label_report_seed.date' => '2026-02-30'],
        ] as $override) {
            $saved = [];
            foreach ($override as $key => $value) {
                $saved[$key] = config($key);
            }
            config($override);
            try {
                (new PackingLabelReportSeeder)->run();
                $this->fail('Unsafe configuration must fail closed.');
            } catch (RuntimeException $error) {
                $this->assertStringStartsWith('PACKING_LABEL_SEED_', $error->getMessage());
            } finally {
                config($saved);
            }
        }
        $this->app->detectEnvironment(fn () => 'production');
        try {
            (new PackingLabelReportSeeder)->run();
            $this->fail('Production environment must fail closed.');
        } catch (RuntimeException $error) {
            $this->assertSame('PACKING_LABEL_SEED_ENVIRONMENT_NOT_ALLOWED', $error->getMessage());
        }
        $this->assertDatabaseCount('label_snapshots', 0);
    }
}
