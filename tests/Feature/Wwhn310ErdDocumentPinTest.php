<?php

namespace Tests\Feature;

use App\Support\HoaNamErdSchema;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * WWHN-310 / DEV310-P1-02 — pin bản ERD có hiệu lực.
 *
 * Tài liệu `HN-WMS-ERD-DD-001` tồn tại hai bản giống hệt nhau ở repo BE và repo
 * FE, và đã được amend 8 lần trong khi trường "Version" vẫn là 1.0. Vì thế câu
 * "đã review ERD" trước đây không chỉ được vào một bản cụ thể nào: reviewer
 * không có checksum, không có mã bản, và bản FE từng lệch 5 nhóm nội dung mà
 * không cổng nào bắt.
 *
 * Cổng này khoá bốn thứ lại với nhau:
 *   1. checksum thật của file ERD trong repo này == `sha256` đã pin;
 *   2. tập mã `ERD-AMD-xxx` trong §0.0 == danh sách `amendments` đã pin;
 *   3. hậu tố `+AMD<nnn>` của `effective_revision` == mã amendment lớn nhất;
 *   4. không governance register nào trong `docs/` còn pin một digest ERD cũ.
 *
 * Repo FE pin **đúng cùng một chuỗi sha256** ở
 * `docs/contracts/erd-document-pin.json` và kiểm bằng
 * `src/test/wwhn-310-erd-document-pin.test.ts`, nên hai bản lệch nhau là đỏ ở
 * cả hai phía thay vì im lặng như trước.
 */
class Wwhn310ErdDocumentPinTest extends TestCase
{
    private const PIN_PATH = 'docs/contracts/erd-document-pin.json';

    /** Khoá được coi là "đường dẫn tài liệu" trong một register JSON. */
    private const PATH_KEYS = ['path', 'file', 'filepath', 'repositoryPath', 'repository_path', 'document', 'artifact'];

    /** @return array<string, mixed> */
    private function pin(): array
    {
        $path = base_path(self::PIN_PATH);
        $this->assertFileExists($path, 'Thiếu file pin ERD: '.self::PIN_PATH);

        $pin = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($pin, self::PIN_PATH.' không phải JSON object hợp lệ.');

        return $pin;
    }

    public function test_pin_tro_dung_tai_lieu_erd_dang_dung_trong_repo(): void
    {
        $pin = $this->pin();

        $this->assertSame(HoaNamErdSchema::DOCUMENT_ID, $pin['document_id'] ?? null);
        $this->assertSame(HoaNamErdSchema::DOCUMENT_PATH, $pin['path'] ?? null);
        $this->assertSame(HoaNamErdSchema::TABLE_COUNT, $pin['table_count'] ?? null);
    }

    public function test_checksum_that_cua_erd_trung_voi_checksum_da_pin(): void
    {
        $pin = $this->pin();
        $erdPath = base_path(HoaNamErdSchema::DOCUMENT_PATH);
        $this->assertFileExists($erdPath);

        $actual = hash_file('sha256', $erdPath);
        $actualBytes = filesize($erdPath);

        $this->assertSame(
            $pin['sha256'] ?? null,
            $actual,
            "ERD đã đổi nhưng pin chưa cập nhật.\n"
            ."Sửa đúng thứ tự: (1) thêm dòng ERD-AMD mới vào §0.0, (2) nâng hậu tố +AMD<nnn>, "
            ."(3) cập nhật sha256/bytes/amendments trong ".self::PIN_PATH." của CẢ HAI repo.\n"
            ."sha256 thật = {$actual}; bytes thật = {$actualBytes}.",
        );
        $this->assertSame($pin['bytes'] ?? null, $actualBytes, 'Số byte của ERD lệch giá trị đã pin.');
    }

    public function test_danh_sach_amendment_da_pin_khop_so_dang_ky_trong_erd(): void
    {
        $pin = $this->pin();
        $erd = (string) file_get_contents(base_path(HoaNamErdSchema::DOCUMENT_PATH));

        // Chỉ lấy mã đứng đầu một dòng của bảng §0.0 (cột "Mã"), không lấy mã
        // được nhắc lại trong phần thân tài liệu.
        preg_match_all('/^\s{2}(ERD-AMD-\d{3})\b/m', $erd, $matches);
        $registered = array_values(array_unique($matches[1]));
        sort($registered);

        $pinned = $pin['amendments'] ?? [];
        $this->assertIsArray($pinned);
        sort($pinned);

        $this->assertSame(
            $pinned,
            $registered,
            'Sổ đăng ký §0.0 và danh sách amendments đã pin không khớp. '
            .'Mỗi lần sửa ERD phải ghi một dòng ERD-AMD và cập nhật pin trong cùng một commit.',
        );
        $this->assertNotEmpty($registered);
    }

    public function test_hau_to_ban_hieu_luc_bang_ma_amendment_lon_nhat(): void
    {
        $pin = $this->pin();
        $erd = (string) file_get_contents(base_path(HoaNamErdSchema::DOCUMENT_PATH));

        preg_match_all('/^\s{2}ERD-AMD-(\d{3})\b/m', $erd, $matches);
        $this->assertNotEmpty($matches[1], 'Không đọc được mã ERD-AMD nào trong §0.0.');
        $highest = max(array_map('intval', $matches[1]));

        $revision = (string) ($pin['effective_revision'] ?? '');
        $expected = sprintf('%s+AMD%03d', (string) ($pin['released_version'] ?? ''), $highest);

        $this->assertSame($expected, $revision, 'effective_revision phải là <released_version>+AMD<mã lớn nhất>.');
        $this->assertStringContainsString(
            $revision,
            $erd,
            'Bản hiệu lực ghi trong §0.0 của ERD phải trùng effective_revision đã pin.',
        );
    }

    public function test_pin_ghi_ro_hai_cong_chan_va_quy_tac_hai_repo(): void
    {
        $pin = $this->pin();

        $this->assertSame(
            'tests/Feature/Wwhn310ErdDocumentPinTest.php',
            $pin['gates']['backend'] ?? null,
        );
        $this->assertSame(
            'src/test/wwhn-310-erd-document-pin.test.ts',
            $pin['gates']['frontend'] ?? null,
        );
        $this->assertFileExists(base_path((string) $pin['gates']['backend']));
        $this->assertStringContainsString('CA HAI file pin', (string) ($pin['rule'] ?? ''));
    }

    public function test_khong_register_nao_con_pin_checksum_erd_cu(): void
    {
        $pin = $this->pin();
        $expected = (string) $pin['sha256'];
        $document = basename(HoaNamErdSchema::DOCUMENT_PATH);

        $stale = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('docs'), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (! $entry->isFile() || ! in_array(strtolower($entry->getExtension()), ['json', 'md'], true)) {
                continue;
            }

            $body = (string) file_get_contents($entry->getPathname());
            if (! str_contains($body, $document)) {
                continue;
            }

            $found = [];

            if (strtolower($entry->getExtension()) === 'json') {
                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $this->collectStaleFromJson($decoded, $document, $expected, $found);
                }
            }

            // Dạng viết trên cùng một dòng (bảng Markdown, dòng ghi chú).
            foreach (explode("\n", $body) as $line) {
                if (! str_contains($line, $document)) {
                    continue;
                }
                if (preg_match_all('/\b([0-9a-f]{64})\b/', $line, $hits) > 0) {
                    foreach ($hits[1] as $digest) {
                        if ($digest !== $expected) {
                            $found[] = $digest;
                        }
                    }
                }
            }

            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $entry->getPathname());
            foreach (array_unique($found) as $digest) {
                $stale[] = str_replace('\\', '/', $relative).' -> '.$digest;
            }
        }

        sort($stale);

        $this->assertSame(
            [],
            $stale,
            'Còn register pin checksum ERD cũ (pin rot). Sửa ERD phải cập nhật MỌI register ghi digest của nó, '
            .'không chỉ '.self::PIN_PATH.". Checksum đúng hiện tại: {$expected}.",
        );
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $found
     */
    private function collectStaleFromJson(array $node, string $document, string $expected, array &$found): void
    {
        // Dạng map: { "docs/HoaNam_ERD_Moi_Nhat.md": "<digest>" }
        foreach ($node as $key => $value) {
            if (is_string($key) && str_ends_with($key, $document)
                && is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1
                && $value !== $expected) {
                $found[] = $value;
            }
        }

        // Dạng object: { "path": "…/HoaNam_ERD_Moi_Nhat.md", "sha256": "<digest>" }
        $pointsAtErd = false;
        foreach (self::PATH_KEYS as $pathKey) {
            $value = $node[$pathKey] ?? null;
            if (is_string($value) && str_ends_with($value, $document)) {
                $pointsAtErd = true;
                break;
            }
        }

        if ($pointsAtErd) {
            foreach ($node as $key => $value) {
                if (is_string($key) && preg_match('/(sha256|sha_256|digest|checksum)/i', $key) === 1
                    && is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1
                    && $value !== $expected) {
                    $found[] = $value;
                }
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectStaleFromJson($value, $document, $expected, $found);
            }
        }
    }
}
