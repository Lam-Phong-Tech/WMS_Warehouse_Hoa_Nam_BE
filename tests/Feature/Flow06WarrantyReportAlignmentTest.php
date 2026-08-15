<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\AlignedWarrantyReportController;
use Tests\TestCase;

class Flow06WarrantyReportAlignmentTest extends TestCase
{
    public function test_warranty_report_uses_current_status_lifecycle(): void
    {
        $source = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php'));

        foreach (['RECEIVED', 'CHECKING', 'REPAIRING', 'COMPLETED', 'RETURNED', 'CANCELLED'] as $status) {
            $this->assertStringContainsString($status, $source);
        }
        foreach (['INSPECTING', 'WAITING_CHECK', 'REPAIRED'] as $legacyStatus) {
            $this->assertStringNotContainsString("'{$legacyStatus}'", $source);
        }
        $this->assertStringContainsString('warranty.pii.export', $source);
        $this->assertStringContainsString("'format'", $source);
        $this->assertStringContainsString("'pagination'", $source);
    }

    public function test_warranty_report_routes_resolve_to_aligned_controller(): void
    {
        foreach ([
            ['GET', 'api/v1/reports/warranty-defects', 'index'],
            ['GET', 'api/v1/reports/warranty-defects/export-excel', 'export'],
        ] as [$method, $uri, $action]) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->last(fn ($candidate) => in_array($method, $candidate->methods(), true) && $candidate->uri() === $uri);
            $this->assertNotNull($route);
            $this->assertSame(AlignedWarrantyReportController::class.'@'.$action, $route->getActionName());
        }
    }
}
