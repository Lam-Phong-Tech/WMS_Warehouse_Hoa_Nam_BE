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

    /**
     * DEV233-P1-02R2 / DEV233-P1-06R2 — bề mặt bị cấm không chỉ *chưa đăng ký*, mà còn
     * **không với tới được** khi gọi thật.
     *
     * `test_no_count_or_recount_route_is_registered` đọc router; test này đi qua đúng
     * middleware stack và khẳng định không có route nào bắt các URL đó. Đây là bằng
     * chứng "no reachable Apply Adjustment" mà QA hard gate của WWHN-235 đòi.
     */
    public function test_count_and_apply_adjustment_urls_are_unreachable_at_runtime(): void
    {
        $probes = [
            ['POST', '/api/v1/stocktake/sessions/01HZZZZZZZZZZZZZZZZZZZZZZZ/apply-adjustment'],
            ['GET', '/api/v1/stocktake/sessions'],
            ['POST', '/api/v1/stocktake/sessions/01HZZZZZZZZZZZZZZZZZZZZZZZ/counts'],
            ['POST', '/api/v1/inventory/recount'],
            ['POST', '/api/v1/inventory/cycle-count'],
            ['POST', '/api/v1/inventory/physical-count'],
            ['POST', '/api/v1/inventory-balances/apply-adjustment'],
        ];

        $reachable = [];

        foreach ($probes as [$method, $uri]) {
            $response = $this->json($method, $uri);

            if ($response->getStatusCode() !== 404) {
                $reachable[] = "{$method} {$uri} => HTTP {$response->getStatusCode()}";
            }
        }

        $this->assertSame(
            [],
            $reachable,
            'REQ-SRS-037: URL kiểm kê/sửa tồn phải không có route nào bắt (404). Với tới được: '
                .implode(', ', $reachable)
        );
    }

    /**
     * DEV233-P2-03R2 — dormant source không được "còn tồn tại dù route disabled".
     *
     * Kiểm ba tầng: không class nào nạp được, không binding nào trong container, và
     * không route action nào trỏ tới controller mang ngữ nghĩa kiểm kê.
     */
    public function test_dormant_stocktake_surface_is_not_loadable_bound_or_routable(): void
    {
        $candidates = [
            'Modules\\Warehouse\\App\\Http\\Controllers\\StocktakeController',
            'Modules\\Warehouse\\App\\Http\\Controllers\\StocktakeSessionController',
            'Modules\\Warehouse\\App\\Http\\Controllers\\CycleCountController',
            'Modules\\Warehouse\\App\\Http\\Controllers\\RecountController',
            'Modules\\Warehouse\\App\\Services\\StocktakeService',
            'Modules\\Warehouse\\App\\Services\\ApplyAdjustmentService',
            'Modules\\Warehouse\\App\\Models\\StocktakeSession',
            'Modules\\Warehouse\\App\\Models\\StocktakeCount',
            'Modules\\Warehouse\\App\\Models\\StocktakeVariance',
            'App\\Http\\Controllers\\StocktakeController',
        ];

        foreach ($candidates as $class) {
            $this->assertFalse(
                class_exists($class),
                "REQ-SRS-037: class [{$class}] không được tồn tại ở trạng thái ngủ."
            );
            $this->assertFalse(
                app()->bound($class),
                "REQ-SRS-037: [{$class}] không được đăng ký trong service container."
            );
        }

        $offending = [];
        foreach (Route::getRoutes() as $route) {
            $action = strtolower((string) ($route->getActionName() ?? ''));
            foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
                if (str_contains($action, str_replace('-', '', $fragment)) || str_contains($action, $fragment)) {
                    $offending[] = "{$route->uri()} => {$route->getActionName()}";
                    break;
                }
            }
        }
        $this->assertSame([], $offending, 'Route action trỏ tới controller kiểm kê: '.implode(', ', $offending));

        // Đăng ký disposition phải đầy đủ và trỏ tới file có thật.
        $register = $this->evidenceJson('DORMANT_SOURCE_DISPOSITION.json');
        $this->assertNotEmpty($register['backend'] ?? [], 'DORMANT_SOURCE_DISPOSITION.json trống phần backend.');

        foreach ($register['backend'] as $entry) {
            $this->assertArrayHasKey('classification', $entry);
            $this->assertArrayHasKey('disposition', $entry);
            $this->assertArrayHasKey(
                $entry['classification'],
                $register['classification_legend'] ?? [],
                "Classification [{$entry['classification']}] không có trong legend."
            );

            if (($entry['status'] ?? null) === 'PRESENT') {
                $this->assertFileExists(
                    base_path($entry['path']),
                    "Đăng ký khai [{$entry['path']}] là PRESENT nhưng file không tồn tại."
                );
            }
        }
    }

    /**
     * DEV233-P2-03R2 — `variance` trong reconciliation là ĐỒNG ÂM, không phải
     * `Stocktake_Variance`. Ranh giới này phải được khoá, nếu không một vòng rà soát
     * sau sẽ hoặc gỡ nhầm nó, hoặc để nó lớn dần thành entity kiểm kê.
     */
    public function test_reconciliation_variance_homonym_stays_report_only(): void
    {
        $path = base_path('Modules/Warehouse/app/Services/InventoryProjectionRebuildService.php');
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        foreach (['stocktake_variance', 'stocktake_session', 'stocktake_count', 'ADJUST_IN', 'ADJUST_OUT'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                "Reconciliation service chạm [{$forbidden}] — đồng âm `variance` đã biến thành bề mặt kiểm kê."
            );
        }

        $register = $this->evidenceJson('DORMANT_SOURCE_DISPOSITION.json');
        $entry = collect($register['backend'] ?? [])
            ->firstWhere('path', 'Modules/Warehouse/app/Services/InventoryProjectionRebuildService.php');

        $this->assertNotNull($entry, 'Service reconciliation phải được đăng ký trong dormant source register.');
        $this->assertSame('HOMONYM-ALLOWED', $entry['classification']);
    }

    /**
     * DEV233-P1-01R2 — không tồn tại trạng thái phê duyệt nửa vời.
     *
     * Phát hiện của vòng R2 đúng là "candidate có nhưng approval/effective date chưa
     * final". Bất biến dưới đây làm cho tình trạng đó không biểu diễn được: hoặc
     * PROPOSED và effective date rỗng, hoặc đã duyệt và đủ cả chữ ký lẫn ngày.
     */
    public function test_approval_record_cannot_be_half_approved(): void
    {
        $approval = $this->evidenceJson('APPROVAL_RECORD.json');

        $status = $approval['status'] ?? null;
        $this->assertContains($status, $approval['allowed_status'] ?? [], "status [{$status}] không hợp lệ.");

        if ($status === 'PROPOSED') {
            $this->assertNull($approval['effective_date'], 'Chưa duyệt thì không được có effective date.');
        } else {
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                (string) ($approval['effective_date'] ?? ''),
                'Đã duyệt thì effective date bắt buộc theo ISO-8601.'
            );
            $this->assertSame(
                hash_file('sha256', base_path('docs/decision-records/DISP-STK-233-COUNT-RECOUNT-DEFERRED.md')),
                $approval['under_approval']['disposition_sha256'] ?? null,
                'Phê duyệt phải gắn vào đúng nội dung disposition đã đọc.'
            );

            foreach ($approval['approvers'] as $slot) {
                foreach (['result', 'actor', 'date'] as $field) {
                    $this->assertNotNull($slot[$field] ?? null, "Slot [{$slot['slot']}] thiếu {$field}.");
                }
            }
        }

        $slots = collect($approval['approvers'] ?? []);
        foreach (['SCOPE-AUTHORITY', 'SOURCE-REBASELINE', 'TRACEABILITY', 'QA-HARD-GATE', 'MAINTAINABILITY-DORMANT-SOURCE'] as $required) {
            $this->assertNotNull($slots->firstWhere('slot', $required), "Thiếu slot bắt buộc [{$required}].");
        }

        foreach (['SCOPE-AUTHORITY', 'SOURCE-REBASELINE'] as $guarded) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bDEV\b/',
                (string) $slots->firstWhere('slot', $guarded)['role'],
                "DEV không được tự duyệt gate [{$guarded}]."
            );
        }

        $this->assertSame('WWHN-25', $approval['under_approval']['decision_ticket'] ?? null);
        $this->assertSame('ATT-11898', $approval['under_approval']['attachment_ref'] ?? null);
    }

    /** DEV233-P1-03R2 — parent WWHN-59 phải có bản ghi rebaseline đầy đủ. */
    public function test_parent_rebaseline_record_is_complete(): void
    {
        $rebaseline = $this->evidenceJson('WWHN-59_AC_REBASELINE.json');

        $this->assertSame('REQ-SRS-037', $rebaseline['decision']['controlling_requirement'] ?? null);
        $this->assertNotEmpty($rebaseline['ac_rebaseline'] ?? []);

        foreach ($rebaseline['ac_rebaseline'] as $ac) {
            $this->assertContains(
                $ac['disposition'],
                ['SUPERSEDED', 'REMAPPED', 'DEFERRED'],
                "AC [{$ac['ac_group']}] có disposition ngoài tập cho phép."
            );

            if ($ac['disposition'] === 'REMAPPED') {
                $this->assertNotEmpty(
                    $ac['canonical_replacement'] ?? null,
                    "AC [{$ac['ac_group']}] được REMAPPED nhưng không nói map sang đâu."
                );
            }
        }

        $v39 = collect($rebaseline['source_rebaseline'] ?? [])
            ->first(fn (array $entry): bool => str_contains((string) $entry['source'], 'v3.9'));
        $this->assertNotNull($v39, 'Phải nêu BRD/Handoff v3.9 trong source rebaseline.');
        $this->assertMatchesRegularExpression('/SUPERSEDED|HISTORICAL/', (string) $v39['new_role']);

        $qaGate = collect($rebaseline['children_alignment'] ?? [])->firstWhere('ticket', 'WWHN-235');
        $this->assertNotNull($qaGate);
        $this->assertStringContainsString('DEFERRED', (string) $qaGate['disposition']);

        $this->assertCount(4, $rebaseline['reopen_conditions'] ?? [], 'Bốn điều kiện re-open phải giữ nguyên.');
    }

    /**
     * DEV233-P1-02R2 — pin immutable phải khớp cây làm việc.
     *
     * Kiểm bằng digest nội dung chứ không bằng git, nên nó đúng ở mọi checkout kể cả
     * khi lịch sử của nhánh dùng chung `m1` đã tiến lên vì commit của task khác.
     */
    public function test_immutable_candidate_pin_matches_working_tree(): void
    {
        $pin = $this->evidenceJson('IMMUTABLE_CANDIDATE_PIN.json');

        $this->assertNotEmpty($pin['expected_checks'] ?? [], 'Pin phải khai expected no-route/no-OA checks.');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/',
            (string) ($pin['candidate']['be_commit_sha'] ?? ''),
            'candidate.be_commit_sha phải là full SHA 40 ký tự.'
        );

        $files = $pin['defer_surface']['files'] ?? [];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $abs = base_path($file['path']);
            $this->assertFileExists($abs, "Pin trỏ tới [{$file['path']}] nhưng file không tồn tại.");
            $this->assertSame(
                hash_file('sha256', $abs),
                $file['sha256'],
                "[{$file['path']}] đã đổi so với candidate đã pin — refresh pin bằng "
                    .'`node scripts/verify-wwhn233-count-disposition.mjs --emit-evidence`.'
            );
        }
    }

    /** @return array<string, mixed> */
    private function evidenceJson(string $file): array
    {
        $path = base_path("docs/evidence/WWHN-233/{$file}");
        $this->assertFileExists($path, "Evidence pack thiếu [{$file}].");

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function baselineContract(): array
    {
        $path = base_path('docs/contracts/HoaNam_Current_Baseline_Contract.json');
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
