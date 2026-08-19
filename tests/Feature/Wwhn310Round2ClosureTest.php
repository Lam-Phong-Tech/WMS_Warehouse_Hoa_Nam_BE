<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * WWHN-310 vòng 2 — cổng chống "đóng bằng lời".
 *
 * Register DEV310-P1-01..06 / P2-01 đòi mỗi hạng mục một *closure evidence* cụ
 * thể: exact SHA, checksum, raw run, artifact dry-run/commit/rollback. Test này
 * kiểm chính những artifact đó có thật và có đúng nội dung đã tuyên bố — không
 * kiểm "có file nào đó tên na ná".
 *
 * Nếu ai đó sửa register mà không sinh lại evidence (hoặc ngược lại), test đỏ.
 */
class Wwhn310Round2ClosureTest extends TestCase
{
    private const EVIDENCE = 'docs/evidence/WWHN-310';

    private const ROUND2 = self::EVIDENCE.'/round2';

    /** @return array<string, mixed> */
    private function evidenceJson(string $relative): array
    {
        $path = base_path($relative);
        $this->assertFileExists($path, "Thiếu artifact bắt buộc: {$relative}");

        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data, "{$relative} không phải JSON hợp lệ.");

        return $data;
    }

    public function test_pin_nguon_ghi_dung_supersede_va_exact_sha(): void
    {
        $pin = $this->evidenceJson(self::EVIDENCE.'/WWHN-310_SOURCE_PIN.json');

        $this->assertSame('WWHN-310', $pin['jira'] ?? null);
        $this->assertSame('m1-be-2', $pin['source_pin']['superseded_branch'] ?? null);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/',
            (string) ($pin['source_pin']['superseded_sha'] ?? ''),
            'SHA của nhánh bị supersede phải là exact SHA đầy đủ.',
        );
        $this->assertSame('m1', $pin['source_pin']['canonical_branch'] ?? null);

        foreach (['code_candidate', 'final_candidate'] as $key) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{40}$/',
                (string) ($pin[$key]['sha'] ?? ''),
                "{$key}.sha phải là exact SHA 40 ký tự — không dùng tên nhánh hay placeholder.",
            );
        }
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$/',
            (string) ($pin['frontend']['sha'] ?? ''),
            'frontend.sha phải là exact SHA của repo FE.',
        );

        // Identity của ticket nằm ở file set + blob hash vì commit bị gộp.
        $files = $pin['code_candidate']['files'] ?? [];
        $this->assertIsArray($files);
        $this->assertGreaterThanOrEqual(15, count($files));
        foreach ($files as $file) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) ($file['blob'] ?? ''));
            $this->assertNotSame('', (string) ($file['path'] ?? ''));
        }
    }

    public function test_dry_run_pass_va_commit_dung_source_checksum(): void
    {
        $dryRun = $this->evidenceJson(self::ROUND2.'/10-dry-run.json');
        $commit = $this->evidenceJson(self::ROUND2.'/11-commit.json');

        $this->assertSame('PASSED', $dryRun['status'] ?? null);
        $this->assertSame('COMMITTED', $commit['status'] ?? null);
        $this->assertSame(
            $dryRun['source_checksum'] ?? 'dry-run',
            $commit['source_checksum'] ?? 'commit',
            'COMMIT phải chạy trên đúng package đã dry-run (SRS §13.12: file đổi thì checksum mới và phải chạy lại).',
        );

        // valid + invalid + duplicate = tổng dòng nguồn.
        $counts = $dryRun['counts'] ?? [];
        $this->assertSame(
            (int) ($counts['total'] ?? -1),
            (int) ($counts['valid'] ?? 0) + (int) ($counts['invalid'] ?? 0) + (int) ($counts['duplicate'] ?? 0),
        );
        $this->assertTrue((bool) ($counts['invariant_holds'] ?? false));
        $this->assertSame(0, (int) ($counts['invalid'] ?? -1));

        $this->assertSame('PASS', $commit['reconciliation']['status'] ?? null);
        $checks = array_column($commit['reconciliation']['checks'] ?? [], 'check');
        foreach ([
            'SOURCE_QTY_EQUALS_LEDGER',
            'BALANCE_EQUALS_LEDGER',
            'SERIAL_TRACKED_QTY_EQUALS_ITEM_COUNT',
            'CONTAINER_MAPPING_NO_EXTRA_STOCK',
            'RUN_WRITES_ONLY_OPENING_MOVEMENTS',
        ] as $required) {
            $this->assertContains($required, $checks, "Thiếu phép đối soát bắt buộc {$required}.");
        }
    }

    public function test_rollback_khong_de_lai_ton_du(): void
    {
        $rollback = $this->evidenceJson(self::ROUND2.'/12-rollback.json');

        $this->assertSame('ROLLED_BACK', $rollback['status'] ?? null);
        $this->assertSame('PASS', $rollback['reconciliation']['status'] ?? null);
        $this->assertSame(0, (int) ($rollback['reconciliation']['residual_opening_qty'] ?? -1));
        $this->assertSame(0, (int) ($rollback['reconciliation']['residual_master_rows'] ?? -1));
        $this->assertSame([], $rollback['reconciliation']['master_rollback']['missing_rows'] ?? null);
    }

    public function test_dong_bi_tu_choi_duoc_ghi_nhan_va_commit_bi_chan(): void
    {
        $rejected = $this->evidenceJson(self::ROUND2.'/13-dry-run-rejected.json');
        $blocked = $this->evidenceJson(self::ROUND2.'/16-commit-blocked.json');

        $this->assertSame('FAILED', $rejected['status'] ?? null);

        $counts = $rejected['counts'] ?? [];
        $this->assertSame(
            (int) ($counts['total'] ?? -1),
            (int) ($counts['valid'] ?? 0) + (int) ($counts['invalid'] ?? 0) + (int) ($counts['duplicate'] ?? 0),
            'Bất biến §13.12 valid+invalid+duplicate = số dòng nguồn phải đúng cả khi FAILED.',
        );
        $this->assertGreaterThan(0, (int) ($counts['invalid'] ?? 0));

        $codes = array_unique(array_column($rejected['errors'] ?? [], 'error_code'));
        foreach (['REQUIRED', 'INVALID_ENUM', 'DUPLICATE_CODE', 'UNKNOWN_SKU'] as $code) {
            $this->assertContains($code, $codes, "Mẫu dòng bị từ chối phải phủ error_code {$code} của §13.12.");
        }

        $this->assertSame('BLOCKED', $blocked['status'] ?? null);
        $this->assertSame('DRY_RUN_NOT_PASSED', $blocked['error_code'] ?? null);
        $this->assertNull($blocked['migration_run_id'] ?? 'not-null', 'Commit bị chặn không được để lại run rác.');
    }

    public function test_evidence_doi_soat_bang_sql_co_that(): void
    {
        foreach ([
            '14-reconcile-after-commit.txt',
            '15-reconcile-after-rollback.txt',
            '17-rejected-rows.txt',
            '14-reconcile-after-commit.sql',
            '15-reconcile-after-rollback.sql',
            'run-final-load-rehearsal.sh',
            'package/manifest.json',
            'package-rejected/manifest.json',
        ] as $file) {
            $this->assertFileExists(base_path(self::ROUND2.'/'.$file), "Thiếu evidence {$file}");
        }

        $afterCommit = (string) file_get_contents(base_path(self::ROUND2.'/14-reconcile-after-commit.txt'));
        $this->assertStringContainsString('COMMITTED', $afterCommit);
        $this->assertStringContainsString('MIGRATION_COMMIT_COMPLETED', $afterCommit);

        $afterRollback = (string) file_get_contents(base_path(self::ROUND2.'/15-reconcile-after-rollback.txt'));
        $this->assertStringContainsString('residual_opening_qty', $afterRollback);
        $this->assertStringContainsString('MIGRATION_ROLLBACK_COMPLETED', $afterRollback);

        $rejectedRows = (string) file_get_contents(base_path(self::ROUND2.'/17-rejected-rows.txt'));
        foreach (['REQUIRED', 'INVALID_ENUM', 'DUPLICATE_CODE', 'UNKNOWN_SKU'] as $code) {
            $this->assertStringContainsString($code, $rejectedRows);
        }
    }

    public function test_register_va_so_bang_giao_tro_dung_evidence_co_that(): void
    {
        $registerPath = base_path(self::EVIDENCE.'/WWHN-310_ROUND2_REGISTER.md');
        $this->assertFileExists($registerPath);
        $register = (string) file_get_contents($registerPath);

        foreach (['DEV310-P1-01', 'DEV310-P1-02', 'DEV310-P1-03', 'DEV310-P1-04', 'DEV310-P1-05', 'DEV310-P1-06', 'DEV310-P2-01'] as $id) {
            $this->assertStringContainsString($id, $register, "Register thiếu hạng mục {$id}.");
        }

        // Mọi đường dẫn docs/... nhắc trong register phải tồn tại thật.
        preg_match_all('#`(docs/[^`]+?\.(?:md|json|txt|sh|sql|php))`#', $register, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $referenced) {
            $this->assertFileExists(base_path($referenced), "Register trỏ tới evidence không tồn tại: {$referenced}");
        }

        $index = base_path('docs/output/HN-HANDOVER-INDEX.md');
        $this->assertFileExists($index, 'DEV310-P1-06 cần Handover Index.');
        $indexBody = (string) file_get_contents($index);
        $this->assertStringContainsString('BE2-HN-HO-001', $indexBody);
        $this->assertStringContainsString('WWHN-310_SOURCE_PIN.json', $indexBody);
    }

    public function test_ho_so_review_erd_ghi_dung_checksum_da_pin(): void
    {
        $pin = $this->evidenceJson('docs/contracts/erd-document-pin.json');
        $record = (string) file_get_contents(base_path(self::EVIDENCE.'/ERD_AMENDMENT_REVIEW_RECORD.md'));

        $this->assertStringContainsString((string) $pin['sha256'], $record, 'Hồ sơ review phải ghi đúng checksum ERD đang pin.');
        $this->assertStringContainsString((string) $pin['effective_revision'], $record);
    }
}
