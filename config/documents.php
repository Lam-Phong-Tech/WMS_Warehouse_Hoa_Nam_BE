<?php

return [
    /*
     * Chứng từ nhập/xuất/hoàn trả (SRS bổ sung "Chứng từ nhập/xuất/hoàn trả"): ảnh/scan/PDF
     * đính kèm phiếu nhập, phiếu xuất, hoàn trả/điều chỉnh cũng phải qua malware check,
     * cùng nguyên tắc với warranty (App\Support\WarrantyAttachmentScanner).
     *
     * Mặc định dùng lại lệnh scan đã cấu hình cho warranty (cùng một binary AV vận hành)
     * nếu DOCUMENT_ATTACHMENT_SCAN_COMMAND không set riêng.
     */
    'attachment_scan_command' => env('DOCUMENT_ATTACHMENT_SCAN_COMMAND', env('WARRANTY_ATTACHMENT_SCAN_COMMAND', '')),

    'attachment_scan_timeout' => (float) env('DOCUMENT_ATTACHMENT_SCAN_TIMEOUT', 30),

    'disk' => env('DOCUMENT_ATTACHMENT_DISK', 'local'),
];
