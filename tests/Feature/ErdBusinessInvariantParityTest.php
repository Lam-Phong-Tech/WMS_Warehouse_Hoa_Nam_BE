<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ErdBusinessInvariantParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_cross_table_guard_triggers_exist(): void
    {
        $this->requirePostgres();

        $required = [
            'trg_erd_item_tracking_policy',
            'trg_erd_container_hierarchy',
            'trg_erd_container_item_primary_sku',
            'trg_erd_putaway_types',
            'trg_putaway_events_history_guard',
            'trg_erd_inbound_full_post',
            'trg_erd_outbound_full_post',
            'trg_erd_outbound_agency_active',
            'trg_erd_outbound_item_exclusive',
            'trg_erd_component_item_exclusive',
            'trg_erd_component_issue_line_sku',
            'trg_erd_component_issue_warranty',
            'trg_erd_print_printer_active',
            'trg_erd_pv_publish_active_sku',
            'trg_erd_migration_commit_evidence',
            'trg_label_snapshot_payload_immutable',
            'trg_audit_logs_append_only',
            'trg_inventory_movements_append_only',
            'trg_warranty_events_append_only',
        ];

        $actual = collect(DB::select('SELECT tgname FROM pg_trigger WHERE NOT tgisinternal'))
            ->pluck('tgname')
            ->all();

        foreach ($required as $trigger) {
            $this->assertContains($trigger, $actual, "Missing ERD business trigger [{$trigger}].");
        }
    }

    public function test_required_named_check_constraints_exist(): void
    {
        $this->requirePostgres();

        $required = [
            ['container_items', 'chk_erd_container_item_shape'],
            ['warranty_cases', 'chk_erd_warranty_purchase_date'],
            ['warranty_cases', 'chk_erd_warranty_identification_shape'],
            ['pv_recommendation_rules', 'chk_erd_pv_recommendation_selector'],
            ['pv_recommendation_rules', 'chk_erd_pv_recommendation_priority'],
        ];

        foreach ($required as [$table, $constraint]) {
            $exists = DB::selectOne(
                "SELECT 1 AS present FROM pg_constraint WHERE conrelid=?::regclass AND conname=? AND contype='c' LIMIT 1",
                [$table, $constraint],
            );
            $this->assertNotNull($exists, "Missing ERD CHECK [{$table}.{$constraint}].");
        }
    }

    public function test_required_partial_unique_business_indexes_exist(): void
    {
        $this->requirePostgres();

        $required = [
            'ux_erd_warehouses_one_active',
            'ux_erd_inventory_balance_bucket',
            'ux_erd_item_serial_per_sku',
            'ux_erd_physical_primary_item_carrier',
            'ux_erd_physical_primary_container_carrier',
            'ux_erd_container_active_item',
            'ux_erd_outbound_active_item',
            'ux_erd_component_active_item',
            'ux_erd_pv_primary_image',
            'ux_erd_warranty_one_active_case_item',
            'ux_erd_pv_recommendation_scope_priority',
        ];

        $actual = collect(DB::select(<<<'SQL'
            SELECT indexname
            FROM pg_indexes
            WHERE schemaname=current_schema()
        SQL))->pluck('indexname')->all();

        foreach ($required as $index) {
            $this->assertContains($index, $actual, "Missing ERD unique/index guard [{$index}].");
        }
    }

    public function test_append_only_tables_reject_update_and_delete(): void
    {
        $this->requirePostgres();

        $definitions = collect(DB::select(<<<'SQL'
            SELECT c.relname AS table_name, t.tgname, pg_get_triggerdef(t.oid) AS definition
            FROM pg_trigger t
            JOIN pg_class c ON c.oid=t.tgrelid
            JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname=current_schema() AND NOT t.tgisinternal
        SQL))->keyBy(fn (object $row): string => $row->table_name.'|'.$row->tgname);

        foreach (['audit_logs', 'inventory_movements', 'warranty_events'] as $table) {
            $trigger = 'trg_'.$table.'_append_only';
            $row = $definitions->get($table.'|'.$trigger);
            $this->assertNotNull($row, "{$table} must be append-only.");
            $normalized = strtoupper((string) $row->definition);
            $this->assertStringContainsString('UPDATE', $normalized);
            $this->assertStringContainsString('DELETE', $normalized);
        }
    }

    public function test_runtime_to_canonical_mirror_triggers_exist_for_critical_legacy_tables(): void
    {
        $this->requirePostgres();

        $required = [
            ['app_user', 'trg_erd_mirror_app_users_from_app_user'],
            ['role', 'trg_erd_mirror_roles_from_role'],
            ['permission', 'trg_erd_mirror_permissions_from_permission'],
            ['user_role', 'trg_erd_mirror_user_roles_from_user_role'],
            ['role_permission', 'trg_erd_mirror_role_permissions_from_role_permission'],
            ['warranty_case', 'trg_erd_mirror_warranty_cases_from_warranty_case'],
            ['defect_catalogs', 'trg_erd_mirror_defect_catalog_from_runtime'],
        ];

        foreach ($required as [$table, $trigger]) {
            $exists = DB::selectOne(<<<'SQL'
                SELECT 1 AS present
                FROM pg_trigger t
                JOIN pg_class c ON c.oid=t.tgrelid
                JOIN pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname=current_schema()
                  AND c.relname=?
                  AND t.tgname=?
                  AND NOT t.tgisinternal
                LIMIT 1
            SQL, [$table, $trigger]);
            $this->assertNotNull($exists, "Missing runtime -> ERD mirror [{$table}.{$trigger}].");
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('ERD business invariant parity is certified on PostgreSQL 14+ reference DB.');
        }
    }
}
