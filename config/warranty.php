<?php

return [
    /*
     * SRS v1.12 §13.8 / Annex D SCR-WAR-01: ảnh warranty phải qua malware check.
     *
     * Lệnh nhận đường dẫn tệp làm tham số cuối và theo quy ước exit code của clamscan
     * (0 = sạch, 1 = nhiễm, khác = lỗi scanner). Ví dụ:
     *   WARRANTY_ATTACHMENT_SCAN_COMMAND="clamscan --no-summary"
     *
     * Để trống thì bỏ qua bước AV: attachment được lưu với scan_result=UNSCANNED thay vì
     * bị coi là đã quét sạch, để môi trường chưa dựng ClamAV vẫn dùng được mà không che
     * mất khoảng trống tuân thủ.
     */
    'attachment_scan_command' => env('WARRANTY_ATTACHMENT_SCAN_COMMAND', ''),

    'attachment_scan_timeout' => (float) env('WARRANTY_ATTACHMENT_SCAN_TIMEOUT', 30),

    /*
     * Video đính kèm tiếp nhận (WAR-02): lưu tách khỏi ảnh vì dung lượng lớn hơn
     * nhiều — mặc định disk 'local' để môi trường chưa tạo bucket R2 vẫn dùng được;
     * đổi thành 'r2' sau khi điền đủ biến R2_* trong .env (xem config/filesystems.php).
     */
    'video_disk' => env('WARRANTY_VIDEO_DISK', 'local'),
];
