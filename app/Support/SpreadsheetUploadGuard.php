<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * WWHN-SEC-09: hai endpoint import Excel (skus/import-excel,
 * outbound-request-imports) trước đây chỉ dựa vào rule `mimes:` của Laravel —
 * rule đó tin phần mở rộng/Content-Type do CLIENT khai. Tệp bất kỳ đổi tên
 * thành .xlsx vẫn lọt tới PhpSpreadsheet (bề mặt parse phức tạp, từng có CVE
 * XXE/zip-bomb). Guard này sniff MIME THẬT bằng finfo như
 * DocumentAttachmentScanner::assertContentType đã làm cho ảnh/PDF.
 *
 * Danh sách chấp nhận đủ hẹp để chặn HTML/PHP/script đổi tên, đủ rộng để không
 * chặn file xlsx thật (finfo trên xlsx có thể trả zip; xls cũ là OLE/CDF).
 */
class SpreadsheetUploadGuard
{
    private const ALLOWED_REAL_MIME = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'application/zip',
        'application/x-ole-storage',
        'application/CDFV2',
        'application/CDFV2-unknown',
    ];

    public static function assertRealSpreadsheet(UploadedFile $file): void
    {
        $path = $file->getRealPath();
        if ($path === false || $path === '') {
            ApiError::abort(422, 'INVALID_FILE_CONTENT', 'Không đọc được nội dung tệp tải lên.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = $finfo === false ? false : finfo_file($finfo, $path);
        if ($finfo !== false) {
            finfo_close($finfo);
        }

        if (! is_string($realMime) || ! in_array($realMime, self::ALLOWED_REAL_MIME, true)) {
            ApiError::abort(
                422,
                'INVALID_FILE_CONTENT',
                'Nội dung tệp không phải bảng tính Excel hợp lệ (phần mở rộng không khớp nội dung thật).'
            );
        }
    }
}
