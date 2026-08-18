<?php

return [
    'uat_seed_password' => env('UAT_SEED_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Hạn mức + ngữ nghĩa idempotency của import SKU (WWHN-330 / WWHN-331)
    |--------------------------------------------------------------------------
    |
    | FE331-P1-03R3 yêu cầu: các giá trị 1000 dòng / 10MB / 24h không được nằm rải
    | rác dưới dạng hằng số "tạm" trong code và càng không được hiển thị cho người
    | dùng kèm chú thích "chưa chốt". Đây là NGUỒN SỰ THẬT DUY NHẤT của bốn giá trị
    | đó: ImportService, ImportController (validate upload), ImportErrorFileService
    | và OpenAPI đều đọc từ đây, và API trả nguyên khối `constraints` này ra cho
    | client để UI hiển thị đúng số máy chủ đang áp dụng thay vì tự viết lại.
    |
    | Quyết định chốt giá trị: docs/decision-records/DEC-IMP-001.md
    | (BA + Tech Lead duyệt trên WWHN-331; đổi giá trị = sửa decision record trước).
    |
    */
    'sku_import' => [
        /** Quyết định nghiệp vụ đang có hiệu lực cho khối giá trị bên dưới. */
        'decision_ref' => 'DEC-IMP-001',

        /** Số dòng dữ liệu tối đa mỗi lần import (không tính dòng header). */
        'max_rows' => (int) env('SKU_IMPORT_MAX_ROWS', 1000),

        /** Kích thước file upload tối đa, tính bằng KB (10240 KB = 10 MB). */
        'max_file_size_kb' => (int) env('SKU_IMPORT_MAX_FILE_SIZE_KB', 10240),

        /** Phần mở rộng file được chấp nhận (khớp rule `mimes:` của request). */
        'allowed_extensions' => ['xlsx', 'xls'],

        /** Số giờ file lỗi CSV còn tải được kể từ lúc sinh. */
        'error_file_expiry_hours' => (int) env('SKU_IMPORT_ERROR_FILE_EXPIRY_HOURS', 24),

        /** Cửa sổ coi 2 lần COMMIT giống hệt nhau là replay (idempotent). */
        'idempotency_window_hours' => (int) env('SKU_IMPORT_IDEMPOTENCY_WINDOW_HOURS', 24),

        /*
         | FE331-P1-02R3: định danh của một lần import COMMIT. Hai lần gửi chỉ là
         | "cùng một thao tác" khi TRÙNG ĐỦ cả ba thành phần này. `update_existing`
         | nằm trong định danh vì nó đổi hẳn hiệu lực nghiệp vụ của cùng một file
         | (false = chỉ tạo mới và báo DUPLICATE_CODE nếu SKU đã có; true = cho phép
         | ghi đè SKU đang tồn tại) — coi hai lần đó là trùng nhau sẽ nuốt mất một
         | thao tác người dùng thực sự muốn chạy.
         */
        'idempotency_identity' => ['file_sha256', 'actor_id', 'update_existing'],

        /*
         | FE331-P1-06R3: phạm vi dữ liệu của Import báo cáo SKU.
         | GLOBAL       = người gọi không bị giới hạn kho (User::warehouseScopeIds() === null)
         |                → xem được mọi Import báo cáo SKU.
         | ACTOR_SCOPED = người gọi bị giới hạn kho → chỉ xem được báo cáo do chính
         |                mình tạo; bản ghi ngoài phạm vi trả 404 (không lộ tồn tại).
         | SKU là master data toàn cục (không có warehouse_id) nên không thể áp scope
         | theo kho lên chính báo cáo; thứ cần bảo vệ là nội dung file người khác nộp.
         */
        'report_data_scope' => 'ACTOR_SCOPED_UNLESS_GLOBAL',
    ],
];
