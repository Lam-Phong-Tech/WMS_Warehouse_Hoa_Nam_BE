<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * WWHN-205 / WWHN-214 — khoá hợp đồng đóng register P1-01R..P1-04R.
 *
 * Vì sao cần cả test này KHI ĐÃ CÓ scripts/verify-wwhn205-closure.mjs: verifier .mjs
 * chỉ chạy trong workflow wwhn-205-invariant-gate.yml, mà workflow đó chỉ kích hoạt
 * theo `paths`. Nếu ai đó xoá luôn workflow, hoặc thu hẹp `paths`, thì verifier im
 * lặng và register mục ruỗng mà không ai đỏ. Bộ full-suite (chạy trên sqlite ở
 * current-baseline-doc-contract.yml) không có path filter, nên test này là lớp
 * bắt cuối cùng. Đây là bài học trực tiếp của chính WWHN-205 §3: một workflow
 * PostgreSQL từng nằm im vì `paths` chỉ trỏ vào chính nó.
 *
 * Test thuần đọc file, không cần DB, nên chạy được trên cả hai driver.
 */
class Wwhn205InvariantClosureContractTest extends TestCase
{
    private const REGISTER = 'docs/evidence/WWHN-205/wwhn-205-source-register.json';

    private const MATRIX = 'docs/evidence/WWHN-205/EVIDENCE_MATRIX.md';

    private const RUNBOOK = 'docs/evidence/WWHN-205/QA1_INDEPENDENT_RUNBOOK.md';

    private const GATE = '.github/workflows/wwhn-205-invariant-gate.yml';

    private const DIAGNOSTIC = '.github/workflows/erd-postgres-diagnostic.yml';

    private const RACE = 'scripts/wwhn205-receipt-issue-race.php';

    private const RECON_PHP = 'scripts/wwhn206-ledger-reconciliation.php';

    private const RECON_SQL = 'scripts/wwhn206-ledger-reconciliation.sql';

    private function read(string $relative): string
    {
        $path = base_path($relative);
        $this->assertFileExists($path, "Thiếu artifact bắt buộc của WWHN-205: {$relative}");

        return (string) file_get_contents($path);
    }

    public function test_register_pins_three_commit_roles_and_all_four_p1_items(): void
    {
        $register = json_decode($this->read(self::REGISTER), true, 512, JSON_THROW_ON_ERROR);

        $chain = $register['commit_chain'] ?? [];
        foreach (['pre_fix_baseline', 'actual_fix', 'chosen_candidate'] as $role) {
            $this->assertArrayHasKey($role, $chain, "commit_chain phải pin vai trò {$role}.");
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                (string) ($chain[$role]['sha'] ?? ''),
                "{$role} phải là SHA đầy đủ 40 ký tự, không phải short SHA."
            );
        }

        // Register vòng 2 gọi tên đích danh hai SHA này; pin sai vai trò là đúng lỗi
        // P1-04R đã bắt ở vòng trước.
        $this->assertStringStartsWith('fad4242', $chain['pre_fix_baseline']['sha']);
        $this->assertStringStartsWith('7a78477', $chain['actual_fix']['sha']);

        $ids = array_column($register['p1_register'] ?? [], 'id');
        foreach (['P1-01R', 'P1-02R', 'P1-03R', 'P1-04R'] as $required) {
            $this->assertContains($required, $ids, "Register thiếu mục {$required}.");
        }

        foreach ($register['p1_register'] ?? [] as $item) {
            $this->assertNotEmpty($item['evidence'] ?? [], "{$item['id']} không khai evidence nào.");
            foreach ($item['evidence'] as $evidence) {
                if (str_ends_with($evidence, '/')) {
                    continue;
                }
                // Evidence sinh lúc chạy (runtime/…) không nằm trong git; các artifact
                // còn lại phải tồn tại thật, nếu không register chỉ là lời hứa.
                if (str_contains($evidence, '/runtime/')) {
                    continue;
                }
                $this->assertFileExists(base_path($evidence), "{$item['id']} khai evidence không tồn tại: {$evidence}");
            }
        }
    }

    public function test_every_blocker_carries_owner_and_disposition(): void
    {
        $register = json_decode($this->read(self::REGISTER), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotEmpty($register['blockers'] ?? [], 'Register phải liệt kê blocker, kể cả blocker của phiên/luồng khác.');

        foreach ($register['blockers'] as $blocker) {
            $this->assertNotEmpty(trim((string) ($blocker['owner'] ?? '')), "Blocker {$blocker['id']} không có owner.");
            $this->assertGreaterThan(
                20,
                strlen(trim((string) ($blocker['disposition'] ?? ''))),
                "Blocker {$blocker['id']} phải có disposition cụ thể, không phải một chữ 'Mở'."
            );
        }
    }

    public function test_fix_semantics_still_present_at_head_not_just_the_files(): void
    {
        // "Còn file" khác "còn fix". Một refactor sau này có thể giữ nguyên tên file
        // mà xoá mất chính đoạn code WWHN-205 đã thêm.
        $service = $this->read('Modules/Warehouse/app/Services/CurrentBaselineStockService.php');
        $this->assertStringContainsString('private function resolveLineItemId(', $service);
        $this->assertStringContainsString('?string $itemId = null', $service);
        $this->assertStringContainsString("'item_id' => \$itemId,", $service);

        $migration = $this->read('Modules/Warehouse/database/migrations/2026_08_15_000100_enforce_stock_movements_append_only_trigger.php');
        $this->assertStringContainsString('trg_stock_movements_append_only', $migration);
        $this->assertStringContainsString('hn_erd_reject_append_only_change', $migration);

        $parity = $this->read('tests/Feature/ErdBusinessInvariantParityTest.php');
        $this->assertStringContainsString('chk_inventory_balance_non_negative', $parity);
        $this->assertStringContainsString('trg_stock_movements_append_only', $parity);

        $itemIdTest = $this->read('tests/Feature/Wwhn205StockMovementItemIdInvariantTest.php');
        $this->assertSame(
            4,
            preg_match_all('/public function test_/', $itemIdTest),
            'Bộ 4 test case invariant item_id của WWHN-205 phải còn nguyên.'
        );
    }

    public function test_postgres_diagnostic_workflow_is_not_dormant_again(): void
    {
        // WWHN-205 §3: workflow này từng chỉ tự chạy khi sửa chính nó. Thu `paths`
        // lại là quay về đúng trạng thái đã bị bắt lỗi.
        $diagnostic = $this->read(self::DIAGNOSTIC);

        foreach ([
            'Modules/Warehouse/database/migrations/**',
            'Modules/Warehouse/app/Services/**',
            'pull_request',
        ] as $needle) {
            $this->assertStringContainsString($needle, $diagnostic, "erd-postgres-diagnostic.yml mất trigger '{$needle}' — workflow trở lại dormant.");
        }
    }

    public function test_gate_executes_concurrency_and_reconciliation_scripts_for_real(): void
    {
        $gate = $this->read(self::GATE);

        // Đây là lỗi đã bị bắt ở WWHN-218/BE1-OP-04: script concurrency tồn tại
        // nhưng CI chỉ `node --check` nó, nên không có gì được thực thi.
        $this->assertStringContainsString('php '.self::RACE, $gate, 'Cổng phải CHẠY script race, không chỉ khai tên.');
        $this->assertStringContainsString('php '.self::RECON_PHP, $gate, 'Cổng phải CHẠY script đối soát.');
        $this->assertStringContainsString(self::RECON_SQL, $gate, 'Cổng phải chạy cả đường psql độc lập của QA1.');
        $this->assertStringContainsString('node scripts/verify-wwhn205-closure.mjs', $gate);

        $this->assertStringContainsString('postgres:16', $gate, 'Register đòi bằng chứng trên PostgreSQL 16.');
        $this->assertStringContainsString('fetch-depth: 0', $gate, 'Không đủ lịch sử thì không verify được exact SHA.');
        $this->assertStringNotContainsString(
            'continue-on-error: true',
            $gate,
            'continue-on-error biến một bước đỏ thành xanh — đúng điều register cấm.'
        );
    }

    public function test_reconciliation_assertion_layer_is_plain_sql_covering_all_required_metrics(): void
    {
        $sql = $this->read(self::RECON_SQL);

        foreach ([
            'ledger_balance_mismatch_count',      // ledger = balance
            'duplicate_source_count',             // duplicate_source = 0
            'duplicate_business_key_count',
            'orphan_movement_document_count',     // orphan = 0
            'movement_on_unposted_document_count', // rollback residue = 0
            'posted_document_without_movement_count',
            'negative_balance_count',
            // SRS §7.5 có BỐN check, AC-SRS-023 nói "ba lớp khớp sau posting".
            // Chỉ đối soát Ledger↔Balance là chưa phủ hết control của baseline.
            'serial_subledger_mismatch_count',
            'quantity_subledger_mismatch_count',
            'container_integrity_mismatch_count',
        ] as $metric) {
            $this->assertStringContainsString($metric, $sql, "File SQL đối soát thiếu metric {$metric} mà P1-02R yêu cầu.");
        }

        // Dimension phải trùng hằng dùng chung, không phải một biến thể GROUP BY riêng
        // (WWHN-231 đã đóng đúng lỗi hai bên tự viết aggregate khác nhau).
        $this->assertStringContainsString('warehouse_id, product_id, stock_status', $sql);

        $php = $this->read(self::RECON_PHP);
        $this->assertStringContainsString(
            'wwhn206-ledger-reconciliation.sql',
            $php,
            'Script PHP phải đọc lại chính file SQL của QA1, nếu không hai đường sẽ trôi khỏi nhau.'
        );
        $this->assertStringContainsString('inventory:reconcile', $php, 'Bước rebuild phải gọi lệnh sản phẩm, không phải logic riêng của script.');
    }

    public function test_race_script_covers_both_directions_and_post_race_reconcile(): void
    {
        $race = $this->read(self::RACE);

        $this->assertStringContainsString("'type' => 'post_receipt'", $race, 'P1-03R đòi kịch bản có Receipt.');
        $this->assertStringContainsString("'type' => 'post_issue'", $race, 'P1-03R đòi kịch bản có Issue.');
        $this->assertStringContainsString('wwhn219-concurrency-worker.php', $race, 'Phải tái dùng worker đa process đã được duyệt, không tự định nghĩa lại "race".');
        $this->assertStringContainsString('pg_backend_pid', file_get_contents(base_path('scripts/wwhn219-concurrency-worker.php')));

        foreach ([
            'post_race_reconciliation',
            'no_duplicate_business_key',
            'no_lost_update_path_consistent',
            'ledger_equals_balance_globally',
            // Oversell của S2 làm lệch Serial subledger §7.5 theo đúng thiết kế;
            // phép kiểm này bắt buộc lệch đó bị giam trong đúng SKU oversell, để
            // "đã biết trước" không trở thành chỗ giấu defect thật.
            'serial_divergence_confined_to_intentional_oversell_sku',
        ] as $needle) {
            $this->assertStringContainsString($needle, $race, "Race script thiếu phép kiểm '{$needle}' mà P1-03R yêu cầu.");
        }
    }

    public function test_evidence_matrix_and_runbook_pin_exact_shas_and_independent_path(): void
    {
        $register = json_decode($this->read(self::REGISTER), true, 512, JSON_THROW_ON_ERROR);
        $matrix = $this->read(self::MATRIX);

        foreach (['pre_fix_baseline', 'actual_fix', 'chosen_candidate'] as $role) {
            $sha = (string) $register['commit_chain'][$role]['sha'];
            $this->assertStringContainsString($sha, $matrix, "EVIDENCE_MATRIX.md phải pin nguyên văn SHA của {$role}.");
        }

        $runbook = $this->read(self::RUNBOOK);
        $this->assertStringContainsString('psql', $runbook, 'Runbook QA1 phải chạy được không qua PHP.');
        $this->assertStringContainsString(self::RECON_SQL, $runbook);
    }
}
