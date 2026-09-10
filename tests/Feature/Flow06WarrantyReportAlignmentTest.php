<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\AlignedWarrantyReportController;
use Modules\Warehouse\Http\Controllers\BaM1ReportController;
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

        // GĐ1-REQ-003/009 (5c64d42): danh sách báo cáo bảo hành nay chạy trên BaM1ReportController
        // (xem test route bên dưới); AlignedWarrantyReportController chỉ còn phục vụ xuất Excel.
        // Guard trạng thái cũ phải soi cả handler danh sách đang chạy, không chỉ handler xuất.
        $liveListSource = (string) file_get_contents(base_path('Modules/Warehouse/app/Http/Controllers/BaM1ReportController.php'));
        foreach (['INSPECTING', 'WAITING_CHECK', 'REPAIRED'] as $legacyStatus) {
            $this->assertStringNotContainsString("'{$legacyStatus}'", $liveListSource);
        }
    }

    /**
     * GĐ1-REQ-003/009 (5c64d42, 25/08/2026; khối reports/warranty-defects trong
     * Modules/Warehouse/routes/api.php): danh sách do BaM1ReportController@warrantyDefects phục
     * vụ — đếm đủ case nhiều lỗi qua warranty_case_defects, handler Aligned cũ chỉ join lỗi
     * chính — còn xuất Excel vẫn là AlignedWarrantyReportController@export. Cổng quyền theo
     * DELTA-07 (docs/evidence/WWHN-267/ROLE_BASELINE_PIN.json): đọc cần mã màn
     * report.warranty.view, xuất cần report.export.
     *
     * Trước đây tên test_warranty_report_routes_resolve_to_aligned_controller và ghim cả hai
     * route về handler Aligned.
     */
    public function test_warranty_report_routes_resolve_to_live_gd1_handlers(): void
    {
        foreach ([
            ['GET', 'api/v1/reports/warranty-defects', BaM1ReportController::class.'@warrantyDefects', 'permission:report.warranty.view'],
            ['GET', 'api/v1/reports/warranty-defects/export-excel', AlignedWarrantyReportController::class.'@export', 'permission:report.export'],
        ] as [$method, $uri, $handler, $permission]) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->last(fn ($candidate) => in_array($method, $candidate->methods(), true) && $candidate->uri() === $uri);
            $this->assertNotNull($route, "Thiếu route {$method} {$uri}.");
            $this->assertSame($handler, $route->getActionName());
            $this->assertContains($permission, $route->gatherMiddleware(), "{$uri} phải gác {$permission}.");
        }
    }
}
