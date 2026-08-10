<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class LatestErdBaselineSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const ERD_OBJECTS = [
        'app_users',
        'roles',
        'permissions',
        'user_roles',
        'role_permissions',
        'idempotency_records',
        'audit_logs',
        'warehouses',
        'agencies',
        'product_categories',
        'brands',
        'power_sources',
        'product_models',
        'package_variants',
        'defect_catalog',
        'skus',
        'sku_aliases',
        'sku_compatible_accessories',
        'import_batches',
        'item_instances',
        'physical_codes',
        'containers',
        'container_items',
        'putaway_events',
        'scan_events',
        'inbound_documents',
        'inbound_lines',
        'inbound_scan_entries',
        'inventory_balances',
        'inventory_movements',
        'posting_events',
        'reconciliation_incidents',
        'reversal_documents',
        'reversal_lines',
        'outbound_request_imports',
        'outbound_request_import_rows',
        'outbound_requests',
        'outbound_request_lines',
        'outbound_documents',
        'outbound_lines',
        'outbound_item_matches',
        'label_snapshots',
        'printer_configs',
        'label_print_events',
        'warranty_cases',
        'warranty_events',
        'warranty_attachments',
        'component_issue_documents',
        'component_issue_lines',
        'component_issue_items',
        'pv_product_profiles',
        'pv_product_images',
        'pv_industries',
        'pv_tags',
        'pv_product_industries',
        'pv_product_tags',
        'pv_recommendation_rules',
        'migration_runs',
        'migration_errors',
    ];

    private const EXCLUDED_MVP_OBJECTS = [
        'stocktake_session',
        'stocktake_snapshot',
        'stocktake_count',
        'stocktake_variance',
        'inventory_reservations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'agency',
        'import_batch',
        'product_master',
        'pv_view_event',
        'migration_batch',
    ];

    public function test_latest_erd_objects_exist_as_tables_or_canonical_views(): void
    {
        $objects = $this->databaseObjects();

        foreach (self::ERD_OBJECTS as $object) {
            $this->assertContains($object, $objects, "Missing latest ERD object [{$object}].");
        }
    }

    public function test_objects_excluded_by_latest_erd_are_not_created(): void
    {
        $objects = $this->databaseObjects();

        foreach (self::EXCLUDED_MVP_OBJECTS as $object) {
            $this->assertNotContains($object, $objects, "Excluded MVP object [{$object}] should not exist.");
        }
    }

    public function test_partial_posting_and_stocktake_routes_are_not_exposed(): void
    {
        foreach ([
            Request::create('/api/v1/inbound-documents/00000000-0000-0000-0000-000000000000/close-remainder', 'POST'),
            Request::create('/api/v1/outbound-documents/00000000-0000-0000-0000-000000000000/close-remainder', 'POST'),
            Request::create('/api/v1/stock-requests', 'GET'),
            Request::create('/api/v1/transfer-orders', 'GET'),
            Request::create('/api/v1/inventory-reservations', 'GET'),
            Request::create('/api/v1/inventory-snapshots', 'GET'),
            Request::create('/api/v1/safe-stock-configs', 'GET'),
            Request::create('/api/v1/safe-stock-alerts', 'GET'),
            Request::create('/api/v1/stocktake-sessions', 'POST'),
            Request::create('/api/v1/stocktake/sessions', 'POST'),
        ] as $request) {
            try {
                Route::getRoutes()->match($request);
                $this->fail("Route [{$request->method()} {$request->path()}] should not be exposed.");
            } catch (NotFoundHttpException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_latest_inventory_field_aliases_and_status_values_are_canonical(): void
    {
        foreach (['warehouse_id', 'sku_id', 'stock_status', 'quantity'] as $column) {
            $this->assertTrue(Schema::hasColumn('inventory_balances', $column), "inventory_balances missing [{$column}].");
        }

        foreach (['warehouse_id', 'sku_id', 'stock_status', 'movement_type', 'quantity'] as $column) {
            $this->assertTrue(Schema::hasColumn('stock_movements', $column), "stock_movements missing [{$column}].");
        }

        $this->assertFalse(Schema::hasColumn('stock_movements', 'stocktake_variance_id'));

        $documentStatuses = DB::table('stock_documents')->distinct()->pluck('status')->all();
        $this->assertEmpty(array_diff($documentStatuses, ['DRAFT', 'SCANNING', 'POSTED', 'CANCELLED']));

        $movementTypes = DB::table('stock_movements')->distinct()->pluck('movement_type')->all();
        $this->assertEmpty(array_diff($movementTypes, ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL']));
    }

    /** @return array<int, string> */
    private function databaseObjects(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("SELECT name FROM sqlite_master WHERE type IN ('table', 'view')"))
                ->pluck('name')
                ->all();
        }

        if ($driver === 'pgsql') {
            return collect(DB::select(<<<'SQL'
                SELECT c.relname AS name
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema()
                  AND c.relkind IN ('r', 'p', 'v')
            SQL))
                ->pluck('name')
                ->all();
        }

        return collect(DB::select('SHOW FULL TABLES'))
            ->map(fn (object $row): string => (string) array_values((array) $row)[0])
            ->all();
    }
}
