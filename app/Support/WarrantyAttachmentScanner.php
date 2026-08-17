<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * SRS v1.12 §13.8 + Annex D SCR-WAR-01 `images`: ảnh warranty phải qua
 * "malware/content-type check" trước khi được lưu vào private storage.
 *
 * Hai lớp kiểm tra tách biệt:
 *  1. content-type: MIME thật (đọc magic bytes) phải nằm trong allowlist VÀ khớp phần
 *     mở rộng — chặn kiểu đổi tên .php thành .jpg mà validator `mimes:` bỏ sót khi
 *     client tự khai Content-Type.
 *  2. malware: gọi scanner ngoài (clamscan…) theo config `warranty.attachment_scan_command`.
 *     Không cấu hình thì bỏ qua bước AV và trả UNSCANNED để caller quyết định — MVP không
 *     bắt buộc dựng ClamAV nhưng cũng không được giả vờ đã quét.
 */
final class WarrantyAttachmentScanner
{
    /** Annex D SCR-WAR-01: images = jpg/png/webp. */
    public const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    public const RESULT_CLEAN = 'CLEAN';

    public const RESULT_INFECTED = 'INFECTED';

    public const RESULT_UNSCANNED = 'UNSCANNED';

    /**
     * @return array{mime: string, extension: string}
     */
    public static function assertContentType(UploadedFile $file): array
    {
        // Đọc magic bytes trực tiếp bằng finfo thay vì UploadedFile::getMimeType():
        // getMimeType() suy ra từ phần mở rộng khi file ở chế độ test, nên chính nhánh
        // "đổi tên .php thành .jpg" — thứ cần chặn nhất — lại không được kiểm ở test.
        $mime = strtolower(self::sniffMime($file));
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! array_key_exists($mime, self::ALLOWED_MIME_EXTENSIONS)) {
            ApiError::abort(422, 'ATTACHMENT_CONTENT_TYPE_INVALID', 'Ảnh bảo hành chỉ nhận JPG/PNG/WEBP.', [
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

    /** MIME thật theo magic bytes; rỗng nếu không đọc được (bị coi là không hợp lệ). */
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
        $command = (string) config('warranty.attachment_scan_command', '');
        if (trim($command) === '') {
            return ['result' => self::RESULT_UNSCANNED, 'detail' => 'scanner_not_configured'];
        }

        $path = (string) $file->getRealPath();
        $process = Process::fromShellCommandline($command.' '.escapeshellarg($path));
        $process->setTimeout((float) config('warranty.attachment_scan_timeout', 30));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // Timeout không được coi là "sạch": file bị đưa vào QUARANTINED để review.
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
