<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Chứng từ nhập/xuất/hoàn trả: ảnh chụp/scan/PDF đính kèm phiếu phải qua cùng hai lớp
 * kiểm tra như warranty (App\Support\WarrantyAttachmentScanner) — content-type theo magic
 * bytes + malware scan ngoài — nhưng allowlist khác (thêm PDF, không nhận video).
 *
 * Giữ tách khỏi WarrantyAttachmentScanner vì hai nghiệp vụ có allowlist/độ lớn khác nhau
 * và không muốn một thay đổi ở đây vô tình đổi hành vi ảnh warranty đã đóng gate.
 */
final class DocumentAttachmentScanner
{
    /** Chứng từ chấp nhận: ảnh chụp/scan (jpg/png/webp) hoặc PDF. */
    public const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];

    public const RESULT_CLEAN = 'CLEAN';

    public const RESULT_INFECTED = 'INFECTED';

    public const RESULT_UNSCANNED = 'UNSCANNED';

    /**
     * @return array{mime: string, extension: string}
     */
    public static function assertContentType(UploadedFile $file): array
    {
        // finfo đọc magic bytes thật, không suy ra từ phần mở rộng client gửi lên —
        // chặn kiểu đổi tên .php thành .jpg/.pdf.
        $mime = strtolower(self::sniffMime($file));
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! array_key_exists($mime, self::ALLOWED_MIME_EXTENSIONS)) {
            ApiError::abort(422, 'ATTACHMENT_CONTENT_TYPE_INVALID', 'Chứng từ chỉ nhận ảnh JPG/PNG/WEBP hoặc PDF.', [
                'detected_mime' => $mime,
                'allowed_mime' => array_keys(self::ALLOWED_MIME_EXTENSIONS),
            ]);
        }

        if (! in_array($extension, self::ALLOWED_MIME_EXTENSIONS[$mime], true)) {
            ApiError::abort(422, 'ATTACHMENT_CONTENT_TYPE_MISMATCH', 'Phần mở rộng tệp không khớp nội dung thật.', [
                'detected_mime' => $mime,
                'extension' => $extension,
                'expected_extension' => self::ALLOWED_MIME_EXTENSIONS[$mime],
            ]);
        }

        return ['mime' => $mime, 'extension' => $extension];
    }

    private static function sniffMime(UploadedFile $file): string
    {
        $path = (string) $file->getRealPath();
        if ($path === '' || ! is_file($path)) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }

        try {
            $mime = finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        return $mime === false ? '' : $mime;
    }

    /**
     * @return array{result: string, detail: string|null}
     */
    public static function scanForMalware(UploadedFile $file): array
    {
        $command = (string) config('documents.attachment_scan_command', '');
        if (trim($command) === '') {
            return ['result' => self::RESULT_UNSCANNED, 'detail' => 'scanner_not_configured'];
        }

        $path = (string) $file->getRealPath();
        $process = Process::fromShellCommandline($command.' '.escapeshellarg($path));
        $process->setTimeout((float) config('documents.attachment_scan_timeout', 30));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ['result' => self::RESULT_INFECTED, 'detail' => 'scanner_timeout'];
        }

        // Quy ước clamscan: 0 = sạch, 1 = có mã độc, còn lại = lỗi scanner.
        return match ($process->getExitCode()) {
            0 => ['result' => self::RESULT_CLEAN, 'detail' => null],
            1 => ['result' => self::RESULT_INFECTED, 'detail' => trim($process->getOutput()) ?: 'scanner_reported_infection'],
            default => ['result' => self::RESULT_INFECTED, 'detail' => 'scanner_failed_exit_'.$process->getExitCode()],
        };
    }
}
