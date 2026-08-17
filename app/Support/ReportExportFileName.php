<?php

namespace App\Support;

/**
 * SRS v1.12 §5.6 BR-EXP-03: "tên file có timestamp".
 *
 * Mọi file báo cáo Flow 06 (XLSX/PDF) phải mang dấu thời gian phát hành để hai
 * lần export cùng một bộ lọc không ghi đè nhau trong thư mục tải về và để evidence
 * QA/audit truy được đúng lần xuất nào.
 */
final class ReportExportFileName
{
    public static function stamp(string $fileName): string
    {
        $stamp = now()->format('Ymd-His');
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        return $extension === ''
            ? "{$fileName}-{$stamp}"
            : substr($fileName, 0, -(strlen($extension) + 1))."-{$stamp}.{$extension}";
    }
}
