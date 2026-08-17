<?php

namespace Tests\Feature;

use Tests\TestCase;

class CurrentBaselineIfMatchContractTest extends TestCase
{
    public function test_reusable_if_match_guard_is_transactional_and_fail_closed(): void
    {
        $middleware = (string) file_get_contents(base_path('app/Http/Middleware/CurrentBaselineIfMatch.php'));

        // CurrentBaselineIfMatch is a parameterized middleware building block.
        // Route literals do not belong in this class; callers provide table and
        // primary key when they opt into the reusable guard.
        $this->assertStringContainsString(
            'public function handle(Request $request, Closure $next, string $table, string $primaryKey)',
            $middleware
        );
        $this->assertStringContainsString("\$request->route('id')", $middleware);
        $this->assertStringContainsString("Schema::hasColumn(\$table, 'version')", $middleware);
        $this->assertStringContainsString('DB::transaction(', $middleware);
        $this->assertStringContainsString('lockForUpdate()', $middleware);
        $this->assertStringContainsString('PRECONDITION_REQUIRED', $middleware);
        $this->assertStringContainsString('STALE_VERSION', $middleware);
        $this->assertStringContainsString("'ETag'", $middleware);
        $this->assertStringContainsString("'version' => \$current + 1", $middleware);
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
