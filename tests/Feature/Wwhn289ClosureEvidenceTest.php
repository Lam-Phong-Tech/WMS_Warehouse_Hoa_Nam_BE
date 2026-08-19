<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * WWHN-289 / DEV289-P1-02 — chống "pin rot" và chống evidence rỗng.
 *
 * Bài học từ WWHN-290: pin SHA chép tay giữa nhiều file sẽ lệch âm thầm trên một
 * nhánh dùng chung như `m1`, và reviewer sẽ thấy một SHA không resolve được —
 * evidence trông như bị làm giả trong khi thực ra chỉ là chép tay. Ở đây mọi SHA
 * chỉ có MỘT nguồn (`WWHN-289_PINS.json`) và test này ép các tài liệu trích đúng
 * giá trị đó, đồng thời giữ các marker fail-closed để không ai âm thầm biến
 * "chờ QA1" thành "đã PASS".
 *
 * Không dùng RefreshDatabase: đây là kiểm tra tài liệu/pin thuần, không chạm DB.
 */
class Wwhn289ClosureEvidenceTest extends TestCase
{
    private const EVIDENCE_DIR = __DIR__.'/../../docs/evidence/WWHN-289';

    private const REQUIRED_DOCUMENTS = [
        'WWHN-289_PINS.json',
        'WWHN-289_DEFECT_FIX_REGISTER.md',
        'WWHN-289_STOCK_INVARIANT_PROOF.md',
        'WWHN-289_SECURITY_TRACE.md',
        'WWHN-289_QA1_HANDOFF.md',
        'WWHN-289_SIGNOFF_COMMENTS.md',
    ];

    public function test_every_required_evidence_document_exists_and_is_not_empty(): void
    {
        foreach (self::REQUIRED_DOCUMENTS as $document) {
            $path = self::EVIDENCE_DIR.'/'.$document;
            $this->assertFileExists($path, "Thiếu tài liệu evidence WWHN-289: {$document}");
            $this->assertGreaterThan(0, (int) filesize($path), "Tài liệu rỗng: {$document}");
        }
    }

    public function test_pins_file_is_valid_json_and_declares_every_register_item(): void
    {
        $pins = $this->pins();

        $this->assertSame('WWHN-289', $pins['jira']);
        $this->assertSame('WWHN-77', $pins['parent']);
        $this->assertSame('WWHN-291', $pins['qa_ticket']);
        $this->assertSame('m1', $pins['canonical_source']['be_branch']);
        $this->assertSame('m1', $pins['canonical_source']['fe_branch']);

        foreach (['DEV289-P1-01', 'DEV289-P1-02', 'DEV289-P1-03', 'DEV289-P1-04', 'DEV289-P1-05', 'DEV289-P1-06'] as $item) {
            $this->assertArrayHasKey(
                $item,
                $pins['independent_gates'],
                "Register item {$item} phải có chủ sở hữu cổng độc lập trong PINS."
            );
        }

        foreach (['SEC289-01', 'SEC289-02', 'SEC289-03'] as $finding) {
            $this->assertArrayHasKey($finding, $pins['findings']);
            foreach (['severity', 'root_cause', 'impact', 'fix', 'regression_test'] as $field) {
                $this->assertNotEmpty(
                    $pins['findings'][$finding][$field] ?? null,
                    "Finding {$finding} thiếu trường bắt buộc `{$field}` — review đòi trace finding→fix→retest."
                );
            }
        }
    }

    public function test_every_document_quotes_the_same_pinned_shas(): void
    {
        $pins = $this->pins();
        $beFinal = (string) $pins['change_range']['final'];
        $beBase = (string) $pins['change_range']['base'];

        $register = $this->read('WWHN-289_DEFECT_FIX_REGISTER.md');
        $this->assertStringContainsString($beBase, $register, 'Register phải trích đúng change-range base từ PINS.');
        $this->assertStringContainsString($beFinal, $register, 'Register phải trích đúng change-range final từ PINS.');

        $handoff = $this->read('WWHN-289_QA1_HANDOFF.md');
        $this->assertStringContainsString($beFinal, $handoff, 'Handoff QA1 phải chỉ đúng build đã pin.');

        // Không tài liệu nào được nhắc tới một SHA 40 ký tự lạ ngoài tập đã pin.
        // Tập hợp lệ gồm cả revision ĐÃ BỊ THAY THẾ và head_sha của CI run được pin:
        // DEV289-P2-01 đòi ghi supersession tường minh, mà muốn ghi thì tài liệu buộc phải
        // gọi tên revision cũ. Nếu không cho phép, register sẽ phải im lặng về đúng thứ nó
        // có nghĩa vụ giải thích.
        $allowed = array_values(array_filter([
            $beBase,
            $beFinal,
            (string) $pins['fe_consumer']['base'],
            (string) $pins['fe_consumer']['final'],
            (string) ($pins['ci_run']['head_sha'] ?? ''),
            ...array_map(
                static fn (array $entry): string => (string) ($entry['sha'] ?? ''),
                $pins['superseded_revisions'] ?? []
            ),
        ]));
        foreach (['WWHN-289_DEFECT_FIX_REGISTER.md', 'WWHN-289_QA1_HANDOFF.md', 'WWHN-289_STOCK_INVARIANT_PROOF.md', 'WWHN-289_SECURITY_TRACE.md'] as $document) {
            preg_match_all('/\b[0-9a-f]{40}\b/', $this->read($document), $matches);
            foreach (array_unique($matches[0]) as $sha) {
                $this->assertContains(
                    $sha,
                    $allowed,
                    "{$document} trích SHA `{$sha}` không có trong WWHN-289_PINS.json — đây chính là kiểu pin rot đã làm hỏng evidence của WWHN-290."
                );
            }
        }
    }

    public function test_register_stays_fail_closed_about_gates_dev_cannot_sign(): void
    {
        $register = $this->read('WWHN-289_DEFECT_FIX_REGISTER.md');

        foreach (['DEV289-P1-01', 'DEV289-P1-02', 'DEV289-P1-03', 'DEV289-P1-04', 'DEV289-P1-05', 'DEV289-P1-06'] as $item) {
            $this->assertStringContainsString($item, $register, "Register phải xử lý {$item}.");
        }

        $this->assertStringContainsString(
            'FAIL-CLOSED / NEEDS OWNER DISPOSITION',
            $register,
            'DEV289-P1-01 không có defect UAT đầu vào nên phải giữ fail-closed, không được tự đóng.'
        );
        $this->assertStringContainsString(
            'không** tuyên bố QA1 PASS',
            $register,
            'Register không được tự nhận chữ ký QA1.'
        );
        $this->assertStringContainsString('Known gaps', $register, 'Register phải liệt kê known gaps.');
    }

    public function test_signoff_comments_have_one_comment_per_role_without_placeholder_verdicts(): void
    {
        $comments = $this->read('WWHN-289_SIGNOFF_COMMENTS.md');

        foreach (['BE1', 'QA1', 'BA', 'Tech Lead'] as $role) {
            $this->assertStringContainsString($role, $comments, "Thiếu comment xác nhận cho vai trò {$role}.");
        }

        // Bài học WWHN-328: reviewer coi verdict dạng lựa chọn là CHƯA ký.
        foreach (['VERIFIED PASS / CHANGES REQUIRED', 'PASS / FAIL', '[GLOBAL / WAREHOUSE-SCOPED]', 'TBD', 'TODO'] as $placeholder) {
            $this->assertStringNotContainsString(
                $placeholder,
                $comments,
                "Comment sign-off còn placeholder `{$placeholder}` — reviewer sẽ coi là chưa ký."
            );
        }
    }

    /** @return array<string, mixed> */
    private function pins(): array
    {
        $decoded = json_decode($this->read('WWHN-289_PINS.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function read(string $document): string
    {
        $path = self::EVIDENCE_DIR.'/'.$document;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
