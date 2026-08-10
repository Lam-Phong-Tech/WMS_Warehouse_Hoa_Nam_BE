<?php

namespace Tests\Feature;

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

        $this->assertCount($this->contract['api']['operation_count'], $operations);
        $joined = strtolower(implode("\n", array_keys($operations)));
        foreach ($this->contract['api']['forbidden_path_fragments'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $joined, "Forbidden API scope [{$fragment}] is exposed.");
        }
    }

    public function test_canonical_route_permissions_are_used_for_core_mutations(): void
    {
        $expected = [
            'GET api/v1/users' => 'permission:iam.user.view',
            'POST api/v1/users' => 'permission:iam.user.manage',
            'GET api/v1/skus' => 'permission:sku.view',
            'POST api/v1/skus' => 'permission:sku.manage',
            'POST api/v1/inbound-documents/{id}/scan' => 'permission:inbound.scan',
            'POST api/v1/inbound-documents/{id}/post-receipt' => 'permission:inbound.post',
            'POST api/v1/outbound-documents/{id}/scan' => 'permission:outbound.scan',
            'POST api/v1/outbound-documents/{id}/post-issue' => 'permission:outbound.post',
            'GET api/v1/stock-movements/{id}/trace-source' => 'permission:inventory.trace',
            'POST api/v1/warranty-cases' => 'permission:warranty.manage',
            'POST api/v1/component-issue-documents/{id}/post' => 'permission:warranty.component_issue',
        ];

        foreach ($expected as $key => $permission) {
            [$method, $uri] = explode(' ', $key, 2);
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true));
            $this->assertNotNull($route, "Missing route [{$key}].");
            $this->assertContains($permission, $route->gatherMiddleware(), "Route [{$key}] must use [{$permission}].");
        }
    }

    public function test_required_objects_and_critical_columns_exist(): void
    {
        $objects = $this->databaseObjects();
        foreach ($this->contract['required_objects'] as $object) {
            $this->assertContains($object, $objects, "Missing baseline DB object [{$object}].");
        }
        foreach ($this->contract['critical_columns'] as $object => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($object, $column), "{$object} missing documented column [{$column}].");
            }
        }
    }

    public function test_canonical_permissions_and_required_roles_are_seeded(): void
    {
        $permissionCodes = DB::table('permission')->pluck('permission_key')->all();
        foreach ($this->contract['permissions'] as $permission) {
            $this->assertContains($permission, $permissionCodes, "Missing canonical permission [{$permission}].");
        }
        $roleCodes = DB::table('role')->pluck('role_code')->all();
        foreach ($this->contract['required_roles'] as $role) {
            $this->assertContains($role, $roleCodes, "Missing required role [{$role}].");
        }
    }

    public function test_generated_swagger_has_route_parity_and_baseline_error_responses(): void
    {
        Artisan::call('l5-swagger:generate');
        $swagger = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $count = 0;
        $methods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];
        foreach ($swagger['paths'] ?? [] as $path => $pathItem) {
            foreach ($methods as $method) {
                if (! isset($pathItem[$method])) {
                    continue;
                }
                ++$count;
                foreach ($this->contract['api']['http_error_statuses'] as $status) {
                    $this->assertArrayHasKey((string) $status, $pathItem[$method]['responses'] ?? [], "{$method} {$path} missing HTTP {$status} response.");
                }
            }
        }
        $this->assertSame($this->contract['api']['operation_count'], $count);
    }

    public function test_postgres_quantity_columns_are_bigint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL physical type gate.');
        }

        foreach ([['inventory_balances', 'quantity'], ['stock_movements', 'quantity']] as [$table, $column]) {
            if (! Schema::hasColumn($table, $column)) {
                continue;
            }
            $type = DB::table('information_schema.columns')
                ->where('table_schema', DB::raw('current_schema()'))
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('data_type');
            $this->assertSame('bigint', $type, "{$table}.{$column} must be BIGINT.");
        }
    }

    /** @return array<int, string> */
    private function databaseObjects(): array
    {
        return match (DB::getDriverName()) {
            'sqlite' => collect(DB::select("SELECT name FROM sqlite_master WHERE type IN ('table','view')"))->pluck('name')->all(),
            'pgsql' => collect(DB::select("SELECT c.relname AS name FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND c.relkind IN ('r','p','v')"))->pluck('name')->all(),
            default => collect(DB::select('SHOW FULL TABLES'))->map(fn (object $row): string => (string) array_values((array) $row)[0])->all(),
        };
    }
}
