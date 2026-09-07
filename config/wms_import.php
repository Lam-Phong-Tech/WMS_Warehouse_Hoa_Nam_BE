<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Đĩa lưu file CSV chi tiết lỗi import SKU
    |--------------------------------------------------------------------------
    |
    | `ImportErrorFileService` GHI file này trong chính request import, còn
    | `GET /skus/import-reports/{id}/error-file` TẢI VỀ ở một request SAU — sau
    | load balancer, request đó có thể rơi vào instance khác. Cùng bài toán với
    | file export báo cáo (config/wms_export.php): đĩa của từng instance không còn
    | là "cùng một chỗ".
    |
    | MẶC ĐỊNH GIỮ `local` — hành vi y hệt trước. Hồ sơ chịu tải
    | (docker-compose.perf.yml) đặt IMPORT_ERROR_FILE_DISK=s3 cho backend VÀ
    | scheduler (lệnh dọn dẹp PurgeExpiredImportErrorFiles chạy ở scheduler) —
    | hai bên PHẢI cùng giá trị. Kiểm đĩa bằng một lệnh ghi thật trước khi đổi.
    |
    */

    'error_disk' => env('IMPORT_ERROR_FILE_DISK', 'local'),

];
