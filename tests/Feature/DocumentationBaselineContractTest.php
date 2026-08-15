<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentationBaselineContractTest extends TestCase
{
    use RefreshDatabase;

    private array $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->contract = json_decode(
            (string) file_get_contents(base_path('docs/contracts/HoaNam_Current_Baseline_Contract.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    public function test_source_documents_and_manifest_are_pinned(): void
    {
        foreach ($this->contract['sources'] as $source) {
            $content = (string) file_get_contents(base_path($source['path']));
            $this->assertStringContainsString($source['document_id'], $content, $source['path']);
        }
        $alignment = (string) file_get_contents(base_path('docs/DR_HN_20260815_PACKING_LABEL_RFID_FLOW06.md'));
        $this->assertStringContainsString('exactly 01 Packing Label', $alignment);
        $this->assertStringContainsString('RFID is optional', $alignment);
    }

    public function test_api_surface_matches_current_baseline_and_forbidden_scope_is_absent(): void
    {
        $operations = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[strtoupper($method).' /'.$route->uri()] = true;
                }
            }
        }

        $this->assertCount($this->expectedOperationCount(), $operations);
        $joined = strtolower(implode("\n", array_keys($operations)));
        foreach ($this->contract['api']['forbidden_path_fragments'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $joined, "Forbidden API scope [{$fragment}] is exposed.");
        }
    }

    public function test_current_business_routes_use_final_baseline_handlers(): void
    {
        $expected = [
            'POST api/v1/skus' => 'CurrentBaselineSkuController@store',
            'PATCH api/v1/skus/{id}' => 'CurrentBaselineSkuController@update',
            'POST api/v1/skus/{id}/physical-codes' => 'CurrentBaselineItemIdentityController@assign',
            'DELETE api/v1/skus/{id}/physical-codes/{physicalCodeId}' => 'CurrentBaselineItemIdentityController@revoke',
            'POST api/v1/inbound-documents' => 'CurrentBaselineDocumentController@storeInbound',
            'PATCH api/v1/inbound-documents/{id}' => 'CurrentBaselineDocumentController@updateInbound',
            'POST api/v1/inbound-documents/{id}/cancel' => 'CurrentBaselineDocumentController@cancelInbound',
            'POST api/v1/inbound-documents/{id}/scan' => 'CurrentBaselineStockFlowController@inboundScan',
            'POST api/v1/inbound-documents/{id}/post-receipt' => 'CurrentBaselineStockFlowController@postReceipt',
            'POST api/v1/inbound-documents/{id}/reverse' => 'CurrentBaselineStockFlowController@reverseInbound',
            'POST api/v1/outbound-documents' => 'CurrentBaselineDocumentController@storeOutbound',
            'PATCH api/v1/outbound-documents/{id}' => 'CurrentBaselineDocumentController@updateOutbound',
            'POST api/v1/outbound-documents/{id}/cancel' => 'CurrentBaselineDocumentController@cancelOutbound',
            'POST api/v1/outbound-documents/{id}/scan' => 'AlignedOutboundFlowController@outboundScan',
            'POST api/v1/outbound-documents/{id}/post-issue' => 'AlignedOutboundFlowController@postIssue',
            'POST api/v1/outbound-documents/{id}/reverse' => 'CurrentBaselineStockFlowController@reverseOutbound',
            'POST api/v1/containers' => 'CurrentBaselineContainerFlowController@store',
            'PATCH api/v1/containers/{id}' => 'CurrentBaselineContainerFlowController@update',
            'POST api/v1/containers/{id}/items' => 'CurrentBaselineContainerFlowController@addItem',
            'DELETE api/v1/containers/{id}/items/{containerItemId}' => 'CurrentBaselineContainerFlowController@removeItem',
            'POST api/v1/physical-codes' => 'CurrentBaselinePhysicalCodeController@store',
            'PATCH api/v1/physical-codes/{id}' => 'CurrentBaselinePhysicalCodeController@update',
            'POST api/v1/outbound-request-imports' => 'CurrentBaselineImportController@storeOutboundRequestImport',
            'POST api/v1/outbound-request-imports/{id}/consume' => 'CurrentBaselineImportController@consumeOutboundRequestImport',
            'POST api/v1/component-issue-documents' => 'CurrentBaselineComponentFlowController@store',
            'POST api/v1/component-issue-documents/{id}/scan' => 'CurrentBaselineComponentFlowController@scan',
            'POST api/v1/component-issue-documents/{id}/cancel' => 'CurrentBaselineComponentFlowController@cancel',
            'POST api/v1/component-issue-documents/{id}/post' => 'CurrentBaselineComponentFlowController@post',
            'POST api/v1/warranty-cases' => 'CurrentBaselineWarrantyController@storeWarrantyCase',
            'PATCH api/v1/warranty-cases/{id}' => 'CurrentBaselineWarrantyController@updateWarrantyCase',
            'POST api/v1/label-snapshots' => 'AlignedOutboundFlowController@storePackingLabel',
            'POST api/v1/label-snapshots/{id}/apply' => 'AlignedOutboundFlowController@applyPackingLabel',
            'POST api/v1/label-snapshots/{id}/print' => 'CurrentBaselineOperationsController@printLabelSnapshot',
            'POST api/v1/label-snapshots/{id}/reprint' => 'CurrentBaselineOperationsController@reprintLabelSnapshot',
            'GET api/v1/pv/catalog' => 'CurrentBaselineOperationsController@pvCatalog',
            'GET api/v1/pv/recommendations' => 'CurrentBaselineOperationsController@pvRecommendations',
        ];

        foreach ($expected as $key => $actionSuffix) {
            [$method, $uri] = explode(' ', $key, 2);
            $route = $this->findRoute($method, $uri);
            $this->assertNotNull($route, "Missing route [{$key}].");
            $this->assertStringEndsWith($actionSuffix, $route->getActionName(), "Route [{$key}] is not using final baseline handler.");
        }
    }

    public function test_canonical_route_permissions_cover_core_mutations(): void
    {
        $expected = [
            'GET api/v1/users' => 'permission:iam.user.view',
            'POST api/v1/users' => 'permission:iam.user.manage',
            'GET api/v1/skus' => 'permission:sku.view',
            'POST api/v1/skus' => 'permission:sku.manage',
            'POST api/v1/skus/{id}/physical-codes' => 'permission:physical_code.assign_rfid',
            'POST api/v1/inbound-documents/{id}/scan' => 'permission:inbound.scan',
            'POST api/v1/inbound-documents/{id}/post-receipt' => 'permission:inbound.post',
            'POST api/v1/outbound-documents/{id}/scan' => 'permission:outbound.scan',
            'POST api/v1/outbound-documents/{id}/post-issue' => 'permission:outbound.post',
            'POST api/v1/inbound-documents/{id}/reverse' => 'permission:inventory.reversal.post',
            'POST api/v1/outbound-documents/{id}/reverse' => 'permission:inventory.reversal.post',
            'POST api/v1/outbound-request-imports' => 'permission:outbound.import',
            'POST api/v1/component-issue-documents/{id}/scan' => 'permission:warranty.component_issue',
            'POST api/v1/component-issue-documents/{id}/post' => 'permission:warranty.component_issue',
            'PATCH api/v1/warranty-cases/{id}' => 'permission:warranty.manage',
            'POST api/v1/label-snapshots' => 'permission:label.print',
            'POST api/v1/label-snapshots/{id}/apply' => 'permission:label.print',
            'POST api/v1/label-snapshots/{id}/print' => 'permission:label.print',
            'POST api/v1/label-snapshots/{id}/reprint' => 'permission:label.reprint',
        ];

        foreach ($expected as $key => $permission) {
            [$method, $uri] = explode(' ', $key, 2);
            $route = $this->findRoute($method, $uri);
            $this->assertNotNull($route, "Missing route [{$key}].");
            $this->assertContains($permission, $route->gatherMiddleware(), "Route [{$key}] must use [{$permission}].");
        }
    }

    public function test_required_objects_columns_and_excluded_scope_match_manifest(): void
    {
        $objects = $this->databaseObjects();
        foreach ($this->contract['required_objects'] as $object) {
            $this->assertContains($object, $objects, "Missing baseline DB object [{$object}].");
        }
        foreach ($this->contract['excluded_tables'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Superseded DB table [{$table}] must not exist.");
        }
        foreach ($this->contract['critical_columns'] as $object => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($object, $column), "{$object} missing documented column [{$column}].");
            }
        }
        foreach (['completion_type', 'shortage_reason', 'remaining_qty', 'cancelled_remainder_qty'] as $column) {
            $this->assertFalse(Schema::hasColumn('stock_document_lines', $column), "Superseded partial field [{$column}] must be absent.");
        }
        foreach (['outbound_document_id', 'applied_at', 'applied_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('label_snapshots', $column), "label_snapshots missing Flow 06 column [{$column}].");
        }
    }

    public function test_mutable_operational_storage_is_physical(): void
    {
        $requiredPhysical = [
            'inventory_balances', 'stock_movements', 'outbound_request_imports', 'outbound_request_import_rows',
            'outbound_requests', 'outbound_request_lines', 'inbound_scan_entries', 'outbound_item_matches',
            'label_snapshots', 'printer_configs', 'label_print_events', 'component_issue_documents',
            'component_issue_lines', 'component_issue_items', 'putaway_events', 'reversal_document', 'reversal_line',
        ];
        foreach ($requiredPhysical as $table) {
            $this->assertTrue($this->isPhysicalTable($table), "{$table} must be physical storage, not a compatibility view.");
        }
    }

    public function test_reference_database_foreign_keys_match_documented_relationships(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite compatibility gate does not add post-hoc FK constraints.');
        }
        foreach ($this->contract['reference_db_relationships'] as [$table, $column, $foreignTable, $foreignColumn]) {
            $matched = collect(Schema::getForeignKeys($table))->contains(function (array $fk) use ($column, $foreignTable, $foreignColumn): bool {
                $columns = $fk['columns'] ?? [];
                $foreignColumns = $fk['foreign_columns'] ?? $fk['foreignColumns'] ?? [];
                $target = $fk['foreign_table'] ?? $fk['foreignTable'] ?? null;
                return in_array($column, $columns, true) && $target === $foreignTable && in_array($foreignColumn, $foreignColumns, true);
            });
            $this->assertTrue($matched, "Missing FK {$table}.{$column} -> {$foreignTable}.{$foreignColumn}.");
        }
    }

    public function test_canonical_permissions_and_required_roles_are_seeded(): void
    {
        $permissionCodes = DB::table('permissions')->pluck('permission_key')->all();
        foreach ($this->contract['permissions'] as $permission) {
            $this->assertContains($permission, $permissionCodes, "Missing canonical permission [{$permission}].");
        }
        $roleCodes = DB::table('roles')->pluck('role_code')->all();
        foreach ($this->contract['required_roles'] as $role) {
            $this->assertContains($role, $roleCodes, "Missing required role [{$role}].");
        }
    }

    public function test_generated_swagger_has_route_parity_and_baseline_error_responses(): void
    {
        Artisan::call('l5-swagger:generate');
        $swagger = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $count = 0;
        foreach ($swagger['paths'] ?? [] as $path => $pathItem) {
            foreach (['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'] as $method) {
                if (! isset($pathItem[$method])) continue;
                $count++;
                foreach ($this->contract['api']['http_error_statuses'] as $status) {
                    $this->assertArrayHasKey((string) $status, $pathItem[$method]['responses'] ?? [], "{$method} {$path} missing HTTP {$status}.");
                }
            }
        }
        $this->assertSame($this->expectedOperationCount(), $count);
    }

    public function test_postgres_types_single_warehouse_and_history_guards(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL physical contract gate.');
        }
        foreach ($this->contract['postgres_bigint_columns'] as [$table, $column]) {
            $type = DB::table('information_schema.columns')
                ->where('table_schema', DB::raw('current_schema()'))
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('data_type');
            $this->assertSame('bigint', $type, "{$table}.{$column} must be BIGINT.");
        }

        $triggerNames = collect(DB::select('SELECT tgname FROM pg_trigger WHERE NOT tgisinternal'))->pluck('tgname')->all();
        foreach ($this->contract['append_only_storage'] as $table) {
            $this->assertContains("trg_{$table}_append_only", $triggerNames, "{$table} must have append-only trigger.");
        }
        $this->assertContains('trg_stock_documents_posted_immutable', $triggerNames);
        $this->assertContains('trg_reversal_document_posted_immutable', $triggerNames);
        $this->assertContains('trg_component_issue_documents_posted_immutable', $triggerNames);
        $this->assertSame(1, DB::table('warehouses')->where('status', 'ACTIVE')->count());
    }

    private function expectedOperationCount(): int
    {
        // Base manifest remains pinned to the approved source bundle. The project-owner
        // Flow 06 alignment adds exactly one non-stock-changing action: confirm that the
        // already printed document-level Packing Label has physically been applied.
        return (int) $this->contract['api']['operation_count'] + 1;
    }

    private function findRoute(string $method, string $uri): mixed
    {
        return collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true));
    }

    private function isPhysicalTable(string $name): bool
    {
        return match (DB::getDriverName()) {
            'sqlite' => DB::table('sqlite_master')->where('name', $name)->where('type', 'table')->exists(),
            'pgsql' => DB::table('pg_class as c')->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')->where('n.nspname', DB::raw('current_schema()'))->where('c.relname', $name)->whereIn('c.relkind', ['r', 'p'])->exists(),
            default => collect(DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))->map(fn (object $row): string => (string) array_values((array) $row)[0])->contains($name),
        };
    }

    private function databaseObjects(): array
    {
        return match (DB::getDriverName()) {
            'sqlite' => collect(DB::select("SELECT name FROM sqlite_master WHERE type IN ('table','view')"))->pluck('name')->all(),
            'pgsql' => collect(DB::select("SELECT c.relname AS name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND c.relkind IN ('r','p','v')"))->pluck('name')->all(),
            default => collect(DB::select('SHOW FULL TABLES'))->map(fn (object $row): string => (string) array_values((array) $row)[0])->all(),
        };
    }
}
