<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Models\Permission;
use Modules\Warehouse\Http\Controllers\OperationalReportController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * WWHN-237 / DISP-STK-237 — Variance query/report DEFERRED (REQ-SRS-037).
 *
 * `DISP-STK-236` khoá *hành động* Apply Adjustment; test này khoá phần `REQ-SRS-037`
 * cũng cấm nhưng chưa tầng nào canh: **truy vấn và báo cáo variance**. Một report
 * code, một mục hub, một cột export hay một field KPI mang ngữ nghĩa chênh lệch
 * kiểm kê đều là "report" theo đúng nghĩa của requirement.
 *
 * Ranh giới phải giữ đúng: variance của **reconciliation** (balance vs ledger) là
 * cơ chế thay thế mà chính `AC-SRS-037` chỉ định, nên nó phải còn sống. Test vì vậy
 * có cả nghĩa vụ âm (capability vắng mặt) lẫn nghĩa vụ dương (đường thay thế chạy).
 */
class Wwhn237VarianceReportDeferredGateTest extends TestCase
{
    use RefreshDatabase;

    /** Mảnh ngữ nghĩa kiểm kê bị cấm ở tầng route/permission/entity. */
    private const FORBIDDEN_SURFACE = [
        'stocktake',
        'variance_line',
        'stocktake_variance',
        'apply-adjustment',
        'stock-adjustment',
        'discrepancy',
    ];

    /** Mã báo cáo hợp lệ của MVP — khớp `/reports/hub` và §13.10. */
    private const CANONICAL_REPORT_CODES = [
        'inventory-balance',
        'inventory-ledger',
        'inventory-xnt',
        'document-history',
        'movement-trace',
        'outbound-by-agency',
        'import-match',
        'label-log',
        'warranty-defects',
    ];

    // ── Nghĩa vụ âm: capability variance không tồn tại ────────────────────────

    public function test_no_route_exposes_a_variance_or_stocktake_surface(): void
    {
        foreach (Route::getRoutes() as $route) {
            foreach (self::FORBIDDEN_SURFACE as $fragment) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $fragment,
                    $route->uri(),
                    "REQ-SRS-037 cấm route mang ngữ nghĩa kiểm kê [{$fragment}]: {$route->uri()}"
                );
            }
        }
    }

    public function test_variance_report_codes_are_not_routable(): void
    {
        foreach ([
            '/api/v1/reports/stocktake-variance',
            '/api/v1/reports/variance',
            '/api/v1/reports/inventory-discrepancy',
        ] as $uri) {
            $route = null;
            try {
                $route = Route::getRoutes()->match(Request::create($uri, 'GET'));
            } catch (NotFoundHttpException) {
                // Không có route nào khớp: đạt.
                continue;
            }

            // `reports/{report_code}` là catch-all nên URI vẫn khớp route; khi đó
            // dispatcher phải từ chối mã lạ chứ không được dựng một báo cáo variance.
            $this->assertSame(
                'api/v1/reports/{report_code}',
                $route->uri(),
                "[{$uri}] không được có route chuyên biệt."
            );
        }
    }

    public function test_report_registry_hub_and_export_paths_carry_no_variance_code(): void
    {
        $source = (string) file_get_contents(
            base_path('Modules/Warehouse/app/Http/Controllers/OperationalReportController.php')
        );

        foreach ([
            'REPORT_BUILDERS' => '/const REPORT_BUILDERS = \[(.*?)\];/s',
            'ALIGNED_REPORTS' => '/const ALIGNED_REPORTS = \[(.*?)\];/s',
            'EXPORT_DOWNLOAD_PATHS' => '/const EXPORT_DOWNLOAD_PATHS = \[(.*?)\];/s',
            'hub' => '/public function hub\(\)\s*\{(.*?)\n    \}/s',
        ] as $name => $pattern) {
            $this->assertSame(1, preg_match($pattern, $source, $matches), "Không đọc được khối [{$name}].");

            foreach (['stocktake', 'variance', 'adjustment', 'discrepancy', 'kiem-ke'] as $fragment) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $fragment,
                    $matches[1],
                    "Khối [{$name}] khai mã/nhãn báo cáo bị REQ-SRS-037 cấm: [{$fragment}]."
                );
            }
        }
    }

    public function test_dashboard_quick_has_no_stocktake_variance_field(): void
    {
        $source = (string) file_get_contents(
            base_path('Modules/Warehouse/app/Http/Controllers/OperationalReportController.php')
        );
        $this->assertSame(
            1,
            preg_match('/public function dashboardQuick\(.*?\n    \}/s', $source, $matches),
            'Không đọc được dashboardQuick.'
        );

        foreach (['stocktake_variance', 'variance_qty', 'variance_count', 'count_variance'] as $field) {
            $this->assertStringNotContainsStringIgnoringCase(
                $field,
                $matches[0],
                "DEV237-P1-03: dashboardQuick không được có field [{$field}]."
            );
        }
    }

    public function test_no_variance_permission_is_seeded(): void
    {
        foreach (['%stocktake%', '%variance%', '%discrepancy%'] as $pattern) {
            $this->assertFalse(
                Permission::query()->where('permission_key', 'like', $pattern)->exists(),
                "Không được seed permission khớp [{$pattern}]."
            );
        }
    }

    public function test_no_variance_entity_survives_migration(): void
    {
        foreach ([
            'stocktake_session',
            'stocktake_sessions',
            'stocktake_snapshot',
            'stocktake_count',
            'stocktake_variance',
            'variance_line',
            'variance_lines',
        ] as $table) {
            $this->assertFalse(
                DB::getSchemaBuilder()->hasTable($table),
                "Entity [{$table}] phải vắng mặt trong deployment v1.12 (ERD §0.1)."
            );
        }
    }

    public function test_report_dispatcher_rejects_a_variance_report_code(): void
    {
        $known = self::CANONICAL_REPORT_CODES;
        sort($known);

        $reflection = new \ReflectionClass(
            OperationalReportController::class
        );
        $builders = array_keys($reflection->getConstant('REPORT_BUILDERS'));
        $aligned = array_keys($reflection->getConstant('ALIGNED_REPORTS'));
        $actual = array_merge($builders, $aligned);
        sort($actual);

        $this->assertSame(
            $known,
            $actual,
            'Danh mục report_code lệch khỏi 9 báo cáo MVP — mọi thay đổi phải đi qua BA rebaseline.'
        );
    }

    // ── Nghĩa vụ dương: đường thay thế mà AC-SRS-037 chỉ định vẫn sống ────────

    public function test_reconciliation_variance_between_balance_and_ledger_stays_available(): void
    {
        $source = (string) file_get_contents(
            base_path('Modules/Warehouse/app/Http/Controllers/OperationalReportController.php')
        );

        $this->assertStringContainsString(
            'variance = COALESCE(balance.on_hand_qty, 0) - SUM(stock_movements.qty_delta)',
            $source,
            'AC-SRS-037 chỉ định reconciliation là cơ chế thay thế — công thức variance ledger-vs-balance không được gỡ theo.'
        );
        $this->assertStringContainsString(
            'reconciliation_status',
            $source,
            'RPT-INV-01 phải tiếp tục trả reconciliation_status.'
        );

        $route = Route::getRoutes()->match(Request::create('/api/v1/reports/inventory-reconciliation', 'GET'));
        $this->assertContains(
            'permission:report.view',
            $route->gatherMiddleware(),
            'Báo cáo đối soát phải giữ nguyên permission report.view.'
        );
    }

    public function test_append_only_reversal_stays_the_only_correction_path(): void
    {
        $id = '00000000-0000-0000-0000-000000000000';

        foreach ([
            "/api/v1/inbound-documents/{$id}/reverse",
            "/api/v1/outbound-documents/{$id}/reverse",
        ] as $uri) {
            $route = Route::getRoutes()->match(Request::create($uri, 'POST'));
            $this->assertNotNull($route, "Đường sửa sai [{$uri}] phải còn tồn tại.");
        }

        $route = Route::getRoutes()->match(Request::create('/api/v1/reversal-documents', 'POST'));
        $this->assertContains(
            'permission:inventory.reversal.post',
            $route->gatherMiddleware(),
            'Tạo chứng từ đảo phải giữ permission inventory.reversal.post.'
        );
    }

    // ── Neo governance: gate không được sống lâu hơn requirement sinh ra nó ───

    public function test_disposition_record_and_controlling_requirement_stay_traceable(): void
    {
        $disposition = base_path('docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md');
        $this->assertFileExists($disposition, 'WWHN-237 bắt buộc có disposition bằng văn bản.');

        $doc = (string) file_get_contents($disposition);
        foreach (['REQ-SRS-037', 'DEFERRED', 'WWHN-238', 'WWHN-60', 'WWHN-25'] as $needle) {
            $this->assertStringContainsString($needle, $doc, "Disposition thiếu mục [{$needle}].");
        }

        $srs = base_path('docs/HN_SRS_FRD_v1.12_FULL.md');
        $this->assertFileExists($srs, 'Baseline điều khiển phải có mặt trong repo.');
        $this->assertStringContainsString(
            'Không expose Stocktake/Variance',
            (string) file_get_contents($srs),
            'Guard chỉ được tồn tại chừng nào REQ-SRS-037 còn phát biểu điều đó.'
        );
    }
}
