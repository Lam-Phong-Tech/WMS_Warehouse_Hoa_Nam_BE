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

    /**
     * DEV236-P1-01R3 — bản ghi phê duyệt phải có Decision/version/hash/effective date
     * và phải ghi tường minh limitation mà PM/PO chấp nhận.
     *
     * Khoá thêm chiều nguy hiểm nhất: không ai được tự tuyên bố APPROVED. Trạng thái
     * APPROVED chỉ hợp lệ khi đủ chữ ký, có effective date và đã pin exact SHA.
     */
    public function test_approval_record_carries_decision_version_hash_and_accepted_limitation(): void
    {
        $path = base_path('docs/decision-records/DISP-STK-236-APPROVAL.json');
        $this->assertFileExists($path, 'DEV236-P1-01R3 đòi bản ghi phê duyệt máy đọc được cho DISP-STK-236.');

        $approval = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('DEFERRED_NO_CODE', $approval['decision'] ?? null);
        $this->assertSame('REQ-SRS-037', $approval['controllingRequirement'] ?? null);
        $this->assertSame('1.12', $approval['controllingBaseline']['version'] ?? null);
        $this->assertSame('WWHN-334', $approval['controllingBaseline']['adoptionIssue'] ?? null);

        // Hash phải đo lại được từ chính tài liệu, không phải con số chép tay.
        $dispositionPath = base_path((string) ($approval['dispositionPath'] ?? ''));
        $this->assertFileExists($dispositionPath);
        $this->assertSame(
            hash_file('sha256', $dispositionPath),
            $approval['dispositionSha256'] ?? null,
            'dispositionSha256 lệch nội dung hiện tại — chạy `node scripts/build-wwhn236-source-register.mjs`.'
        );

        $disposition = (string) file_get_contents($dispositionPath);
        $this->assertMatchesRegularExpression(
            '/\|\s*Version\s*\|\s*`'.preg_quote((string) ($approval['dispositionVersion'] ?? ''), '/').'`\s*\|/',
            $disposition,
            'dispositionVersion trong approval phải khớp version ghi ở document control của disposition.'
        );

        // LIM-STK-236-01: physical mismatch không có source transaction chỉ tạo incident.
        $limitation = $approval['limitationAccepted'] ?? [];
        $this->assertSame('LIM-STK-236-01', $limitation['id'] ?? null);
        $this->assertNotEmpty(
            $limitation['whatTheSystemCannotDo'] ?? [],
            'Chấp nhận một giới hạn mà không liệt kê giới hạn thì không phải là chấp nhận.'
        );
        $serialized = (string) json_encode($limitation, JSON_UNESCAPED_UNICODE);
        foreach (['reconciliation_incidents', 'Reversal', 'source transaction'] as $needle) {
            $this->assertStringContainsString($needle, $serialized);
        }

        if (($approval['status'] ?? null) === 'APPROVED') {
            $this->assertNotNull($approval['effectiveDate'] ?? null, 'APPROVED phải kèm effective date.');
            $this->assertNotNull($approval['exactCommitSha']['backend'] ?? null, 'APPROVED phải kèm exact BE SHA.');
            foreach (($approval['signOff'] ?? []) as $role => $signature) {
                $this->assertNotNull(
                    $signature['decision'] ?? null,
                    "status=APPROVED nhưng vai trò [{$role}] chưa ký — DEV không được tự duyệt thay approver."
                );
            }
        }
    }

    /**
     * DEV236-P1-03R3 — cả chuỗi ticket dùng chung một disposition hình thức, và không
     * ticket nào (nhất là QA task) bị để Blocked/Pending vô thời hạn.
     */
    public function test_chain_disposition_leaves_no_ticket_blocked(): void
    {
        $path = base_path('docs/evidence/WWHN-236/CHAIN_DISPOSITION.json');
        $this->assertFileExists($path, 'DEV236-P1-03R3 đòi chuỗi ticket ở dạng máy đọc được.');

        $chain = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $byTicket = [];
        foreach ($chain['tickets'] ?? [] as $ticket) {
            $byTicket[$ticket['ticket']] = $ticket;
        }

        foreach (['WWHN-25', 'WWHN-60', 'WWHN-236', 'WWHN-238', 'HN-STK-002', 'WWHN-233', 'WWHN-235'] as $required) {
            $this->assertArrayHasKey($required, $byTicket, "Chuỗi disposition thiếu {$required}.");
        }

        foreach ($byTicket as $id => $ticket) {
            $status = (string) ($ticket['targetStatus'] ?? '');
            foreach (['Blocked', 'Pending', 'In Progress'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $status,
                    "{$id} còn ở trạng thái [{$forbidden}] — đó chính là thứ làm mọi vòng rà soát phải mở lại ticket."
                );
            }
            $this->assertStringContainsString('DEFERRED', (string) ($ticket['disposition'] ?? ''));

            // Anti-dangling: disposition nào cũng phải trỏ tới một file có thật.
            $ownerDoc = (string) ($ticket['ownerDoc'] ?? '');
            if ($ownerDoc !== '' && ($ticket['crossBranch'] ?? false) !== true) {
                $this->assertFileExists(base_path($ownerDoc), "{$id}: ownerDoc {$ownerDoc} không tồn tại.");
            }
        }

        foreach ($chain['qaTasksMustNotBeBlocked'] ?? [] as $qaTicket) {
            $this->assertMatchesRegularExpression(
                '/DEFERRED|N\/A/i',
                (string) ($byTicket[$qaTicket]['targetStatus'] ?? ''),
                "QA task {$qaTicket} phải là DEFERRED / N/A, không phải Blocked."
            );
        }
    }

    /**
     * DEV236-P1-02R3 + DEV236-P1-04R3 — source register phải nói rõ nguồn hiện hành
     * thay cho BRD v3.9, và pin candidate bằng tập file/blob chứ không bằng lời văn.
     */
    public function test_source_register_pins_candidate_and_rebaselines_parent_ac(): void
    {
        $path = base_path('docs/evidence/WWHN-236/SOURCE_REGISTER.json');
        $this->assertFileExists($path);

        $register = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $rebaseline = $register['parent_rebaseline'] ?? [];
        $this->assertSame('WWHN-60', $rebaseline['ticket'] ?? null);
        $this->assertSame('REQ-SRS-037', $rebaseline['current_source']['requirement'] ?? null);
        $this->assertSame('1.12', $rebaseline['current_source']['version'] ?? null);
        $this->assertStringContainsString('v3.9', (string) ($rebaseline['superseded_source']['label'] ?? ''));
        $this->assertNotEmpty($rebaseline['ac_to_remove_from_current_mvp'] ?? []);
        $this->assertNotEmpty($rebaseline['ac_replacement'] ?? null);

        $backend = null;
        foreach ($register['candidate_identity'] ?? [] as $repo) {
            if (($repo['repo'] ?? null) === 'BE') {
                $backend = $repo;
            }
        }
        $this->assertNotNull($backend, 'Register phải có candidate identity của repo BE.');
        $this->assertNotNull(
            $backend['candidate_sha'] ?? null,
            'candidate_sha null nghĩa là register được sinh khi artifact chưa commit — pin đó không trỏ tới bằng chứng nào.'
        );
        $this->assertSame('CLEAN', $backend['governed_working_tree'] ?? null);
        $this->assertSame('N/A — direct-to-m1', $backend['pull_request'] ?? null);

        foreach ($backend['governed_files'] ?? [] as $file) {
            $this->assertFileExists(
                base_path((string) $file['path']),
                'Register liệt một governed file không tồn tại: '.$file['path']
            );
        }
    }
}
