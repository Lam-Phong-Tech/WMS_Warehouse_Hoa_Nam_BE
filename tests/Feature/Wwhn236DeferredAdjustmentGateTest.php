<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Models\Permission;
use Tests\TestCase;

/**
 * WWHN-236 — khoá disposition DISP-STK-236
 * (docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md).
 *
 * `apply-adjustment` đã được nêu lại ở WWHN-180, WWHN-219 và WWHN-236. Mỗi lần trước
 * đều đóng bằng văn bản, nên vòng rà soát sau lại mở ra một nguồn khác (DEC-STK-001
 * PROPOSED, WWHN-180 OpenAPI v1.7.0, BRD v3.9) rồi kết luận ngược lại. Test này khoá
 * đúng mệnh đề mà controlling source phát biểu, để nó không phải chứng minh lại:
 *
 * - REQ-SRS-037 (Must, SRS/FRD v1.12 adopt tại WWHN-334): không expose Stocktake /
 *   Variance, menu / API / permission / entity / report hoặc action sửa tồn trực tiếp.
 * - BR-NOSTK-01..03 / AC-SRS-037: deployment v1.12 không chứa capability legacy.
 * - BR-REV-01/02: đường sửa sai duy nhất là Reversal append-only — nên test cũng khoá
 *   chiều ngược lại: ba route reverse phải còn sống, không được "dọn" nhầm.
 *
 * Bổ sung cho Wwhn219ApplyAdjustmentDispositionTest: test đó chỉ chặn mảnh
 * `apply-adjustment` và `stocktake`; test này chặn cả `variance` và kiểm tra tầng
 * schema/constraint/permission chứ không chỉ route.
 */
class Wwhn236DeferredAdjustmentGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Mảnh bị cấm. Trước DISP-STK-236 gate chỉ biết "stocktake", nên một route sửa
     * tồn đặt tên khác — ví dụ /api/v1/inventory/apply-adjustment — lọt hết mọi cửa.
     */
    private const FORBIDDEN_FRAGMENTS = ['stocktake', 'adjustment', 'variance'];

    private const EXCLUDED_TABLES = [
        'stocktake_session',
        'stocktake_snapshot',
        'stocktake_count',
        'stocktake_variance',
    ];

    private const CANONICAL_MOVEMENT_TYPES = ['OPENING', 'RECEIPT', 'ISSUE', 'COMPONENT_ISSUE', 'REVERSAL'];

    public function test_no_adjustment_stocktake_or_variance_route_is_registered(): void
    {
        $offending = [];

        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());
            foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                if (str_contains($uri, $fragment)) {
                    $offending[] = implode('|', array_diff($route->methods(), ['HEAD']))
                        .' '.$route->uri().' [fragment: '.$fragment.']';
                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $offending,
            'REQ-SRS-037 cấm mọi API sửa tồn trực tiếp. Route vi phạm: '.implode(', ', $offending)
        );
    }

    public function test_permission_catalog_has_no_stocktake_or_adjustment_permission(): void
    {
        foreach (['%stocktake%', '%adjustment%', '%variance%'] as $pattern) {
            $found = Permission::query()
                ->where('permission_key', 'like', $pattern)
                ->pluck('permission_key')
                ->all();

            $this->assertSame(
                [],
                $found,
                "REQ-SRS-037 cấm permission sửa tồn trực tiếp; catalog còn [{$pattern}]: ".implode(', ', $found)
            );
        }
    }

    public function test_excluded_stocktake_tables_are_absent_from_the_final_schema(): void
    {
        foreach (self::EXCLUDED_TABLES as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "ERD §0.1 / REQ-SRS-037: bảng [{$table}] không được tồn tại ở schema cuối cùng."
            );
        }

        // Cột FK trỏ variance từng tồn tại trên ledger; nó phải đã bị gỡ hẳn.
        $this->assertFalse(
            Schema::hasColumn('stock_movements', 'stocktake_variance_id'),
            'stock_movements.stocktake_variance_id phải bị gỡ — ledger không được tham chiếu variance.'
        );
    }

    /**
     * Hàng rào cuối cùng chặn "sửa tồn trực tiếp": kể cả khi ai đó viết được một
     * đường ghi mới, movement_type ADJUST_IN/ADJUST_OUT vẫn phải bị DB từ chối.
     *
     * CHECK constraint chỉ được tạo trên PostgreSQL (addPostgresBusinessChecks), nên
     * test phân nhánh theo driver thay vì giả định — sqlite in-memory không có
     * constraint đó và một assertion chung sẽ pass giả trên sqlite.
     */
    public function test_movement_type_check_constraint_rejects_direct_stock_adjustment(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $definition = DB::selectOne(
                'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
                ['chk_stock_movement_type_current']
            );

            $this->assertNotNull(
                $definition,
                'chk_stock_movement_type_current phải tồn tại trên PostgreSQL — đây là hàng rào chặn ADJUST_IN/ADJUST_OUT.'
            );

            $this->assertStringNotContainsStringIgnoringCase(
                'ADJUST',
                (string) $definition->def,
                'chk_stock_movement_type_current không được cho phép movement_type ADJUST_*.'
            );

            foreach (self::CANONICAL_MOVEMENT_TYPES as $type) {
                $this->assertStringContainsString(
                    $type,
                    (string) $definition->def,
                    "chk_stock_movement_type_current thiếu movement_type canonical [{$type}]."
                );
            }

            return;
        }

        // Driver không tạo CHECK constraint: khoá ở tầng nguồn migration để mệnh đề
        // vẫn được kiểm chứng, thay vì bỏ qua im lặng.
        $migration = base_path(
            'Modules/Warehouse/database/migrations/2026_08_10_000620_remove_superseded_fields_and_lock_append_only_history.php'
        );
        $this->assertFileExists($migration, 'Migration khai chk_stock_movement_type_current đã biến mất.');

        $source = (string) file_get_contents($migration);
        $this->assertMatchesRegularExpression(
            "/chk_stock_movement_type_current CHECK \(movement_type IN \('OPENING','RECEIPT','ISSUE','COMPONENT_ISSUE','REVERSAL'\)\)/",
            $source,
            'Danh sách movement_type hợp lệ đã đổi; ADJUST_* không được phép quay lại.'
        );
    }

    public function test_disposition_record_pins_req_srs_037_as_the_single_authority(): void
    {
        $path = base_path('docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md');
        $this->assertFileExists($path, 'WWHN-236 bắt buộc có disposition bằng văn bản (DEV236-P1-01).');

        $doc = (string) file_get_contents($path);

        foreach (['REQ-SRS-037', 'DEFERRED', 'WWHN-238', 'HN-STK-002', 'WWHN-60'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $doc,
                "DISP-STK-236 phải nêu [{$needle}] để register DEV236-P1-01..07 đóng được."
            );
        }

        // DEC-STK-001 là bản nháp mô tả đúng capability bị exclude. Nếu nó được nâng
        // lên APPROVED mà không qua CR + rebaseline SRS thì authority lại mâu thuẫn.
        $decStk = (string) file_get_contents(base_path('docs/decision-records/DEC-STK-001.md'));
        $this->assertMatchesRegularExpression(
            '/\|\s*Status\s*\|\s*\*\*PROPOSED\*\*\s*\|/',
            $decStk,
            'DEC-STK-001 phải còn PROPOSED; kích hoạt Mô hình kiểm kê A cần CR + WWHN-25 GO + rebaseline SRS.'
        );
    }

    public function test_baseline_contract_forbids_every_adjustment_path_fragment(): void
    {
        $contract = json_decode(
            (string) file_get_contents(base_path('docs/contracts/HoaNam_Current_Baseline_Contract.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $fragments = $contract['api']['forbidden_path_fragments'] ?? [];
        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            $this->assertContains(
                $fragment,
                $fragments,
                "forbidden_path_fragments thiếu [{$fragment}] — route sửa tồn mang tên đó sẽ lọt gate."
            );
        }

        foreach (self::EXCLUDED_TABLES as $table) {
            $this->assertContains($table, $contract['excluded_tables'] ?? [], "excluded_tables thiếu [{$table}].");
        }

        $this->assertSame(
            self::CANONICAL_MOVEMENT_TYPES,
            $contract['movement_types'] ?? [],
            'movement_types phải đúng canonical set; ADJUST_* không được xuất hiện.'
        );
    }

    /**
     * Chiều ngược lại của cùng một quyết định: vì Reversal là correction path duy nhất
     * (BR-REV-01/02, DISP-STK-236 §3), ba route reverse không được biến mất trong lúc
     * "dọn dẹp" bề mặt adjustment.
     */
    public function test_canonical_reversal_correction_path_stays_available(): void
    {
        $expected = [
            'api/v1/inbound-documents/{id}/reverse',
            'api/v1/outbound-documents/{id}/reverse',
            'api/v1/reversal-documents',
        ];

        $registered = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('POST', $route->methods(), true)) {
                $registered[] = $route->uri();
            }
        }

        foreach ($expected as $uri) {
            $this->assertContains(
                $uri,
                $registered,
                "Correction path canonical [POST {$uri}] phải còn tồn tại; gỡ nó là gỡ luôn cách sửa sai hợp lệ duy nhất."
            );
        }
    }
}
