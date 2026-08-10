<?php

namespace Tests\Feature;

use Tests\TestCase;

class CurrentBaselineIfMatchContractTest extends TestCase
{
    public function test_legacy_mutable_routes_are_covered_by_transactional_if_match_guard(): void
    {
        $middleware = (string) file_get_contents(base_path('app/Http/Middleware/CurrentBaselineIfMatch.php'));
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        foreach ([
            'PATCH api/v1/warehouses/{id}',
            'PATCH api/v1/agencies/{id}',
            'DELETE api/v1/agencies/{id}',
            'PATCH api/v1/defect-catalogs/{id}',
            'DELETE api/v1/defect-catalogs/{id}',
            'PATCH api/v1/product-masters/{id}',
            'PATCH api/v1/import-batches/{id}',
            'PATCH api/v1/item-instances/{id}',
            'PATCH api/v1/users/{id}',
            'DELETE api/v1/users/{id}',
            'PUT api/v1/users/{id}/roles',
            'POST api/v1/users/{id}/deactivate',
            'PATCH api/v1/roles/{id}',
            'DELETE api/v1/roles/{id}',
            'PUT api/v1/roles/{id}/permissions',
        ] as $route) {
            $this->assertStringContainsString($route, $middleware, $route);
        }

        $this->assertStringContainsString('lockForUpdate()', $middleware);
        $this->assertStringContainsString('PRECONDITION_REQUIRED', $middleware);
        $this->assertStringContainsString('PRECONDITION_FAILED', $middleware);
        $this->assertStringContainsString("'ETag'", $middleware);
        $this->assertStringContainsString('CurrentBaselineIfMatch::class', $bootstrap);
        $this->assertStringContainsString('api(prepend:', $bootstrap);
    }

    public function test_remaining_mutable_storage_has_version_migration(): void
    {
        $migration = (string) file_get_contents(base_path('Modules/Warehouse/database/migrations/2026_08_10_000730_add_version_to_remaining_mutable_aggregates.php'));
        foreach (['warehouses', 'agency', 'defect_catalog', 'product_master', 'import_batches', 'item_instances', 'app_user', 'role'] as $table) {
            $this->assertStringContainsString($table, $migration);
        }
        $this->assertStringContainsString("unsignedInteger('version')->default(1)", $migration);
    }

    public function test_current_handlers_keep_strict_if_match_without_generic_double_increment(): void
    {
        foreach ([
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineSkuController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselinePhysicalCodeController.php',
            'Modules/Warehouse/app/Http/Controllers/CurrentBaselineWarrantyController.php',
        ] as $path) {
            $source = (string) file_get_contents(base_path($path));
            $this->assertStringContainsString('If-Match', $source, $path);
            $this->assertStringContainsString('PRECONDITION_REQUIRED', $source, $path);
        }
    }
}
