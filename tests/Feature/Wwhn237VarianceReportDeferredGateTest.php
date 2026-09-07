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

        // Từ 07/09/2026 endpoint này gác `report.stock_reconcile.view` thay cho
        // `report.view`: BA tách quyền xem báo cáo theo TỪNG MÀN, mà
        // reports/inventory-reconciliation chỉ phục vụ RPT-02 (Tồn kho & đối soát).
        // Điều AC-SRS-037 cần giữ là "báo cáo đối soát vẫn nằm sau một permission
        // riêng, không phải route mở" — vẫn đúng, chỉ đổi mã.
        $route = Route::getRoutes()->match(Request::create('/api/v1/reports/inventory-reconciliation', 'GET'));
        $this->assertContains(
            'permission:report.stock_reconcile.view',
            $route->gatherMiddleware(),
            'Báo cáo đối soát phải nằm sau permission riêng của màn RPT-02.'
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
    // ── Vòng R2: khoá chính bộ bằng chứng, không chỉ khoá capability ──────────

    /**
     * `DEV237-P1-02R2`. Vòng R1 ghi định danh bằng `git rev-parse HEAD` ở hai thời điểm
     * khác nhau nên BE và FE khai **hai cặp SHA khác nhau** — bốn SHA cho một cặp.
     * Test này khoá bất biến: đúng một cặp, đầy đủ 40 ký tự, hai bản byte-identical,
     * scope không bị co lại, và `m1-be-2` được chứng minh là ancestor thuần.
     */
    public function test_frozen_candidate_pair_is_a_single_self_verifying_pair(): void
    {
        $path = base_path('docs/evidence/WWHN-237/CANDIDATE_PAIR.json');
        $this->assertFileExists($path, 'DEV237-P1-02R2 bắt buộc có CANDIDATE_PAIR.json.');

        $raw = (string) file_get_contents($path);
        $pin = json_decode($raw, true);
        $this->assertIsArray($pin, 'CANDIDATE_PAIR.json phải là JSON hợp lệ.');

        foreach (['backend', 'frontend'] as $side) {
            $sha = $pin['pair'][$side]['commit_sha'] ?? '';
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                (string) $sha,
                "pair.{$side}.commit_sha phải là SHA đầy đủ 40 ký tự, không phải HEAD viết tắt."
            );
        }

        // Scope không được co lại cho pin dễ thoả.
        foreach ([
            'docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md',
            'scripts/verify-wwhn237-variance-report-disposition.mjs',
            'tests/Feature/Wwhn237VarianceReportDeferredGateTest.php',
        ] as $mandatory) {
            $this->assertContains(
                $mandatory,
                $pin['pair']['backend']['artifact_scope'] ?? [],
                "Scope BE thiếu file bắt buộc [{$mandatory}]."
            );
        }

        // Anti-dangling: pin không được trỏ file không tồn tại.
        foreach ($pin['pair']['backend']['artifact_scope'] ?? [] as $rel) {
            $this->assertFileExists(base_path($rel), "Scope BE trỏ file không tồn tại [{$rel}].");
        }

        // File pin phải nằm ngoài tập nó pin, nếu không mỗi lần ghi pin lại tự làm lệch.
        $this->assertContains(
            'docs/evidence/WWHN-237/**',
            $pin['excluded_from_scope'] ?? [],
            'Phải loại docs/evidence/WWHN-237/** khỏi scope để cắt vòng gà-trứng.'
        );

        // Supersede m1-be-2 → m1 phải là số đo, không phải câu khẳng định.
        $this->assertSame('m1-be-2', $pin['branch_supersession']['superseded_branch'] ?? null);
        $this->assertSame('m1', $pin['branch_supersession']['canonical_branch'] ?? null);
        $this->assertTrue($pin['branch_supersession']['is_pure_ancestor'] ?? false, 'm1-be-2 phải là ancestor thuần.');
        $this->assertSame(0, $pin['branch_supersession']['m1_be_2_commits_ahead_of_m1'] ?? -1);

        // Hai bản sao phải byte-identical — reviewer diff hai repo là ra ngay.
        $feCopy = base_path('../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE/docs/evidence/WWHN-237/CANDIDATE_PAIR.json');
        if (is_file($feCopy)) {
            $this->assertSame(
                hash('sha256', $raw),
                hash_file('sha256', $feCopy),
                'Bản sao FE của CANDIDATE_PAIR.json phải byte-identical với bản BE.'
            );
        }
    }

    /**
     * `DEV237-P1-01R2`. Register R2 nói thẳng: không dùng `DISP-STK-237` DEV-authored thay
     * approval. Test khoá đúng chỗ đó — không ai flip được sang APPROVED nếu thiếu approver
     * thật, thiếu tham chiếu comment Jira, thiếu effective date, hoặc nếu người duyệt lại
     * chính là vai trò DEV.
     */
    public function test_approval_record_is_separate_from_the_dev_authored_disposition(): void
    {
        $path = base_path('docs/evidence/WWHN-237/APPROVAL_RECORD.json');
        $this->assertFileExists($path, 'DEV237-P1-01R2 bắt buộc có APPROVAL_RECORD.json tách khỏi disposition.');

        $record = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($record);
        $this->assertContains($record['approval_state'] ?? null, ['PENDING', 'APPROVED', 'REJECTED']);

        $slots = $record['required_approvals'] ?? [];
        $this->assertGreaterThanOrEqual(3, count($slots), 'Phải đủ 3 slot: Process Owner/Client, PM/PO, BA Main.');

        $forbidden = array_map('strtolower', $record['self_approval_forbidden_roles'] ?? []);
        $this->assertNotEmpty($forbidden, 'Phải khai vai trò không được tự duyệt.');

        foreach ($slots as $slot) {
            if (in_array($slot['status'] ?? null, ['APPROVED', 'REJECTED'], true)) {
                foreach ($record['required_fields_when_approved'] ?? [] as $field) {
                    $this->assertNotEmpty(
                        $slot[$field] ?? null,
                        "Slot [{$slot['slot']}] khai {$slot['status']} nhưng thiếu [{$field}]."
                    );
                }
                $role = strtolower((string) ($slot['approver_role'] ?? ''));
                foreach ($forbidden as $bad) {
                    $this->assertStringNotContainsString(
                        $bad,
                        $role,
                        "Slot [{$slot['slot']}] bị duyệt bởi vai trò DEV — self-approval không hợp lệ."
                    );
                }
            }
        }

        if (($record['approval_state'] ?? null) !== 'APPROVED') {
            $this->assertNull($record['effective_date'] ?? null, 'Chưa APPROVED thì không được có effective_date.');
            $this->assertStringNotContainsStringIgnoringCase(
                '| Status | **APPROVED',
                (string) file_get_contents(base_path('docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md')),
                'Disposition DEV-authored không được tự khai APPROVED khi approval còn PENDING.'
            );
        }

        // ATT-11898 là input authority bắt buộc phải được nêu tên, kể cả khi không hash được.
        $this->assertStringContainsString(
            'ATT-11898',
            (string) file_get_contents($path),
            'Bản ghi approval phải nêu WWHN-132 ATT-11898 trong source_authority_inputs.'
        );
    }

    /**
     * `DEV237-P1-04R2`. Anti-dangling theo luật `DISP-STK-233`: một dòng khai PASS mà raw
     * log không tồn tại là dòng chép tay.
     */
    public function test_qa_execution_matrix_covers_n1_n8_and_r1_r4_with_raw_logs(): void
    {
        $path = base_path('docs/evidence/WWHN-237/QA_EXECUTION_MATRIX.json');
        $this->assertFileExists($path, 'DEV237-P1-04R2 bắt buộc có QA_EXECUTION_MATRIX.json.');

        $matrix = json_decode((string) file_get_contents($path), true);
        $rows = collect($matrix['rows'] ?? [])->keyBy('id');

        foreach (['N1', 'N2', 'N3', 'N4', 'N5', 'N6', 'N7', 'N8', 'R1', 'R2', 'R3', 'R4'] as $id) {
            $this->assertTrue($rows->has($id), "Ma trận QA thiếu dòng [{$id}].");
            $row = $rows->get($id);
            $this->assertContains($row['status'] ?? null, ['PASS', 'FAIL', 'N/A'], "[{$id}] status phải PASS/FAIL/N/A.");
            $this->assertNotEmpty($row['driver'] ?? null, "[{$id}] thiếu env/DB driver.");
            $this->assertNotSame('FAIL', $row['status'], "[{$id}] còn FAIL.");

            if (($row['status'] ?? null) === 'N/A') {
                $this->assertNotEmpty($row['na_reason'] ?? null, "[{$id}] N/A phải kèm na_reason.");

                continue;
            }
            $log = base_path((string) ($row['raw_log'] ?? ''));
            $this->assertFileExists($log, "[{$id}] khai {$row['status']} nhưng raw log không tồn tại.");
            $this->assertGreaterThan(0, (int) filesize($log), "[{$id}] raw log rỗng.");
        }

        // Raw API/DB là yêu cầu tường minh của register R2, không phải tuỳ chọn.
        $this->assertNotEmpty($matrix['raw_api_db_evidence'] ?? [], 'Thiếu raw API/DB evidence.');
        foreach ($matrix['raw_api_db_evidence'] as $item) {
            $this->assertFileExists(base_path((string) ($item['log'] ?? '')), "Raw evidence [{$item['id']}] thiếu log thật.");
        }

        // Ma trận phải chạy trên đúng cặp đã pin, không phải một HEAD nào khác.
        $pin = json_decode((string) file_get_contents(base_path('docs/evidence/WWHN-237/CANDIDATE_PAIR.json')), true);
        $this->assertSame($pin['pair']['backend']['commit_sha'], $matrix['executed_at_pair']['backend'] ?? null);
        $this->assertSame($pin['pair']['frontend']['commit_sha'], $matrix['executed_at_pair']['frontend'] ?? null);
    }

    /**
     * `DEV237-P1-05R2`. Ba consumer phải xác nhận trên **cùng một cặp**, và FE
     * self-confirmation của chính DEV không tính là independent.
     */
    public function test_contract_digest_pin_requires_independent_confirmation(): void
    {
        $path = base_path('docs/evidence/WWHN-237/CONTRACT_DIGEST_PIN.json');
        $this->assertFileExists($path, 'DEV237-P1-05R2 bắt buộc có CONTRACT_DIGEST_PIN.json.');

        $pin = json_decode((string) file_get_contents($path), true);
        $this->assertNotEmpty($pin['artifacts'] ?? [], 'Pin phải liệt kê artifact hợp đồng.');

        $kinds = array_column($pin['artifacts'], 'kind');
        foreach (['openapi-runtime', 'postman-living', 'fe-negative-contract'] as $required) {
            $this->assertContains($required, $kinds, "Pin thiếu nhóm hợp đồng [{$required}].");
        }

        $roles = array_column($pin['independent_confirmations'] ?? [], 'role');
        foreach (['Tech Lead', 'BA', 'QA1'] as $role) {
            $this->assertContains($role, $roles, "Thiếu slot xác nhận độc lập cho [{$role}].");
        }

        foreach ($pin['independent_confirmations'] as $confirmation) {
            if (($confirmation['status'] ?? null) !== 'CONFIRMED') {
                continue;
            }
            foreach (['confirmed_by', 'jira_comment_ref', 'confirmed_at', 'pair_backend_sha', 'pair_frontend_sha'] as $field) {
                $this->assertNotEmpty(
                    $confirmation[$field] ?? null,
                    "Xác nhận của [{$confirmation['role']}] khai CONFIRMED nhưng thiếu [{$field}]."
                );
            }
            $this->assertStringNotContainsStringIgnoringCase(
                'dev',
                (string) $confirmation['confirmed_by'],
                'FE self-confirmation của chính DEV không tính là independent.'
            );
        }

        // Pin phải neo đúng cặp đã đóng băng.
        $pair = json_decode((string) file_get_contents(base_path('docs/evidence/WWHN-237/CANDIDATE_PAIR.json')), true);
        $this->assertSame($pair['pair']['backend']['commit_sha'], $pin['pinned_at_pair']['backend'] ?? null);
        $this->assertSame($pair['pair']['frontend']['commit_sha'], $pin['pinned_at_pair']['frontend'] ?? null);
    }
}
