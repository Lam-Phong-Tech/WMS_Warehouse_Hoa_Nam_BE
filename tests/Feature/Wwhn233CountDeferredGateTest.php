<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Khoá disposition DISP-STK-233 (docs/decision-records/DISP-STK-233-COUNT-RECOUNT-DEFERRED.md).
 *
 * `WWHN-59` / `WWHN-233` mang AC count / recount / partial / stale của một phiên kiểm
 * kê. `REQ-SRS-037` (Must, SRS/FRD v1.12 @ WWHN-334) cấm toàn bộ bề mặt đó. Disposition
 * là DEFERRED — NO CODE, nên nghĩa vụ của MVP này là **phủ định** và phải đo được trên
 * runtime thật, không phải trên văn bản.
 *
 * Bổ trợ chứ không lặp `Wwhn219ApplyAdjustmentDispositionTest` (khoá `apply-adjustment`)
 * và `Flow05InventoryControlAlignmentTest` (khoá chuỗi `stocktake`). Test này khoá phần
 * bề mặt *count/recount* mà hai test kia không bắt, và khoá đường ghi tồn ở tầng DB.
 */
class Wwhn233CountDeferredGateTest extends TestCase
{
    /**
     * Mảnh đường dẫn bị cấm sau khi cả nhánh WWHN-236 lẫn WWHN-233 đóng.
     * Ba mảnh cuối là phần riêng của WWHN-233: một route đếm lại tồn kho không
     * nhất thiết chứa chữ `stocktake` hay `adjustment`.
     */
    private const FORBIDDEN_FRAGMENTS = [
        'stocktake',
        'adjustment',
        'variance',
        'recount',
        'cycle-count',
        'physical-count',
    ];

    private const EXCLUDED_TABLES = [
        'stocktake_session',
        'stocktake_snapshot',
        'stocktake_count',
        'stocktake_variance',
    ];

    private const CANONICAL_MOVEMENT_TYPES = [
        'OPENING',
        'RECEIPT',
        'ISSUE',
        'COMPONENT_ISSUE',
        'REVERSAL',
    ];

    /** DEV233-P1-04 — không có endpoint count/recount/partial/stale nào được đăng ký. */
    public function test_no_count_or_recount_route_is_registered(): void
    {
        $offending = [];

        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());
            foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                if (str_contains($uri, $fragment)) {
                    $methods = implode('|', array_diff($route->methods(), ['HEAD']));
                    $offending[] = "{$methods} {$route->uri()} [{$fragment}]";
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offending,
            'REQ-SRS-037 / DISP-STK-233 cấm bề mặt count/recount/variance/adjustment; tìm thấy: '
                .implode(', ', $offending)
        );
    }

    /** DEV233-P1-04 — permission catalog thật không có capability kiểm kê. */
    public function test_no_stocktake_or_count_permission_is_registered(): void
    {
        $offending = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                $middleware = strtolower((string) $middleware);
                if (! str_starts_with($middleware, 'permission:')) {
                    continue;
                }
                foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                    if (str_contains($middleware, $fragment)) {
                        $offending[] = "{$route->uri()} => {$middleware}";
                        break 2;
                    }
                }
            }
        }

        $this->assertSame([], $offending, 'Permission kiểm kê tái xuất hiện: '.implode(', ', $offending));

        $contract = $this->baselineContract();
        foreach ($contract['permissions'] ?? [] as $permission) {
            foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                $this->assertStringNotContainsString(
                    $fragment,
                    strtolower($permission),
                    "Baseline contract khai permission [{$permission}] thuộc bề mặt bị cấm."
                );
            }
        }
    }

    /**
     * DEV233-P1-05 — end-state schema không có bảng kiểm kê.
     *
     * Chain migration được giữ nguyên có chủ đích (xoá migration đã chạy sẽ phá
     * migrate:rollback — xem WWHN-300), nên nghĩa vụ được đo ở end-state.
     * `Schema::hasTable()` trả false cho VIEW trên sqlite, nên bốn bảng này được
     * kiểm bằng truy vấn thật thay vì chỉ tin hasTable.
     */
    public function test_no_stocktake_table_survives_migration(): void
    {
        foreach (self::EXCLUDED_TABLES as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "REQ-SRS-037: bảng [{$table}] không được tồn tại ở end-state schema."
            );

            $reachable = true;
            try {
                DB::table($table)->limit(1)->get();
            } catch (\Throwable) {
                $reachable = false;
            }

            $this->assertFalse(
                $reachable,
                "REQ-SRS-037: [{$table}] vẫn truy vấn được (view hoặc bảng còn sót)."
            );
        }

        $this->assertFalse(
            Schema::hasColumn('stock_movements', 'stocktake_variance_id'),
            'stock_movements không được giữ FK sang variance.'
        );
    }

    /**
     * DEV233-P1-05 — không tồn tại đường ghi tồn thứ hai.
     *
     * `chk_stock_movement_type_current` chỉ cho 5 movement type canonical, nên
     * `ADJUST_IN` / `ADJUST_OUT` bị từ chối ở tầng DB chứ không chỉ ở tầng ứng dụng.
     */
    public function test_movement_type_surface_has_no_adjustment_path(): void
    {
        $contract = $this->baselineContract();

        $this->assertSame(
            self::CANONICAL_MOVEMENT_TYPES,
            $contract['movement_types'] ?? [],
            'movement_types của baseline contract phải giữ đúng 5 giá trị canonical.'
        );

        foreach (['ADJUST_IN', 'ADJUST_OUT', 'ADJUSTMENT', 'STOCKTAKE'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $contract['movement_types'] ?? [],
                "movement_type [{$forbidden}] mở lại đường sửa tồn trực tiếp."
            );
        }

        // Constraint phải còn trong nguồn migration — nó là lớp chặn cuối ở PostgreSQL.
        $migration = base_path(
            'Modules/Warehouse/database/migrations/'
            .'2026_08_10_000620_remove_superseded_fields_and_lock_append_only_history.php'
        );
        $this->assertFileExists($migration, 'Migration khoá append-only/movement type không được biến mất.');

        $source = (string) file_get_contents($migration);
        $this->assertStringContainsString('chk_stock_movement_type_current', $source);
        $this->assertStringContainsString(
            "movement_type IN ('OPENING','RECEIPT','ISSUE','COMPONENT_ISSUE','REVERSAL')",
            $source,
            'Danh sách movement_type trong constraint đã bị nới rộng.'
        );
        $this->assertStringContainsString(
            'hn_reject_append_only_change',
            $source,
            'Trigger append-only của lịch sử tồn kho không được gỡ.'
        );
    }

    /** DEV233-P1-04 — baseline contract chặn đủ mảnh count/recount. */
    public function test_baseline_contract_forbids_count_surface(): void
    {
        $fragments = $this->baselineContract()['api']['forbidden_path_fragments'] ?? [];

        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            $this->assertContains(
                $fragment,
                $fragments,
                "forbidden_path_fragments thiếu [{$fragment}] — bề mặt này lọt mọi gate hiện có."
            );
        }
    }

    /**
     * DEV233-P1-01/P1-02 — disposition tồn tại, và hàng rào nó cam kết cũng tồn tại.
     *
     * Root cause của điểm 34/100: một disposition có thể mô tả hàng rào mà hàng rào
     * đó chưa bao giờ được viết.
     */
    public function test_disposition_record_exists_and_its_guards_are_real(): void
    {
        $path = base_path('docs/decision-records/DISP-STK-233-COUNT-RECOUNT-DEFERRED.md');
        $this->assertFileExists($path, 'WWHN-233 bắt buộc có disposition bằng văn bản.');

        $contents = (string) file_get_contents($path);
        foreach (['DISP-STK-233', 'REQ-SRS-037', 'DEFERRED', 'WWHN-59', 'WWHN-235'] as $needle) {
            $this->assertStringContainsString($needle, $contents, "Disposition thiếu neo [{$needle}].");
        }

        foreach ([
            'scripts/verify-wwhn233-count-disposition.mjs',
            'tests/Feature/Wwhn233CountDeferredGateTest.php',
            'docs/evidence/WWHN-233/NO_CODE_HANDOFF.md',
            'docs/evidence/WWHN-233/QA_DISPOSITION_WWHN-235.md',
        ] as $guard) {
            $this->assertFileExists(
                base_path($guard),
                "DISP-STK-233 cam kết [{$guard}] nhưng file không tồn tại — disposition lại thành lời hứa suông."
            );
        }
    }

    /** DEV233-P1-04 — DEC-STK-001 là nơi duy nhất định nghĩa contract count/recount; phải ở nguyên PROPOSED. */
    public function test_stocktake_decision_record_stays_proposed(): void
    {
        $path = base_path('docs/decision-records/DEC-STK-001.md');
        $this->assertFileExists($path, 'Bản nháp DEC-STK-001 phải được giữ làm bản ghi lịch sử.');

        $contents = (string) file_get_contents($path);
        $this->assertMatchesRegularExpression(
            '/\|\s*Status\s*\|\s*\*\*PROPOSED\*\*\s*\|/u',
            $contents,
            'DEC-STK-001 không còn PROPOSED; mô hình kiểm kê A chỉ mở qua CR + WWHN-25 GO + rebaseline SRS.'
        );
        $this->assertMatchesRegularExpression(
            '/\|\s*Effective date\s*\|\s*`NULL/u',
            $contents,
            'DEC-STK-001 đã có effective date — bề mặt kiểm kê sẽ được coi là active.'
        );
    }

    /** @return array<string, mixed> */
    private function baselineContract(): array
    {
        $path = base_path('docs/contracts/HoaNam_Current_Baseline_Contract.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
