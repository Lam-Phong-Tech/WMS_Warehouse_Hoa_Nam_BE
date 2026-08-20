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

        /*
         | DEV330-P1-02R3 — CHÍNH SÁCH QUÉT DÒNG VẬT LÝ (physical-scan policy).
         |
         | Hạn mức nghiệp vụ đếm theo DÒNG DỮ LIỆU, nhưng một workbook có thể trải
         | `max_rows` dòng dữ liệu đó ra rất nhiều dòng vật lý (dòng trống xen giữa,
         | dimension "ma" do định dạng cũ để lại). Nếu quét không có trần thì một file
         | vài KB vẫn ép server đọc hàng triệu dòng vật lý — đây là bề mặt DoS, không
         | phải chuyện tiện nghi. Trần quét = bội số này × (max_rows + 2).
         |
         | Bội số 20 là giá trị đã chốt tại DEC-IMP-001 §1: đủ rộng cho file "thưa" của
         | người dùng thật (đo được ở benchmark case `sparse/1000 rows`, tỉ lệ ~2×) và
         | vẫn chặn đứng file trải dài bất thường. Vượt trần KHÔNG bị bỏ qua im lặng mà
         | trả 422 `SKU_IMPORT_ROW_LIMIT_EXCEEDED` kèm hướng dẫn xoá dòng trống thừa.
         */
        'max_physical_scan_multiplier' => (int) env('SKU_IMPORT_MAX_PHYSICAL_SCAN_MULTIPLIER', 20),

        /*
         | DEV330-P1-02R3 — MỤC TIÊU HIỆU NĂNG ĐÃ DUYỆT (performance objective), tính
         | bằng mili-giây cho một request trọn vẹn ở ĐÚNG hạn mức 1 000 dòng.
         |
         | Đây là NGƯỠNG TRẦN để phát hiện hồi quy (regression budget), KHÔNG phải cam
         | kết SLA production: benchmark chạy trên máy CI/dev chia sẻ tài nguyên nên số
         | đo dao động mạnh. Vượt ngưỡng nghĩa là "có thứ gì đó đã đổi bậc độ phức tạp"
         | (ví dụ quay lại nạp toàn bộ workbook, hoặc N+1 query khi ghi SKU) — lúc đó
         | benchmark test fail và bắt buộc phải điều tra, thay vì để số liệu trôi dần.
         | SLA thật do QA1/DevOps đo lại trên môi trường production-like (WWHN-332).
         */
        'performance_objective_ms' => [
            'dry_run_at_max_rows' => (int) env('SKU_IMPORT_PERF_DRY_RUN_MS', 15000),
            'commit_at_max_rows' => (int) env('SKU_IMPORT_PERF_COMMIT_MS', 60000),
            'reject_oversize' => (int) env('SKU_IMPORT_PERF_REJECT_MS', 15000),
        ],

        /*
         | DEV330-P1-07R3 — KHOÁ CHỐNG ĐUA CHO IMPORT COMMIT.
         |
         | `on_timeout` = FAIL_CLOSED: hết thời gian chờ lock thì TỪ CHỐI request (409
         | CONCURRENCY_CONFLICT) chứ KHÔNG chạy tiếp. Chạy tiếp (fail-open) nghĩa là bỏ
         | luôn tính atomic của bước "kiểm tra trùng + ghi" — đúng thứ mà lock sinh ra
         | để bảo vệ — và tạo ra hai dòng import_reports COMMITTED cho cùng một file mà
         | không ai biết. Đúng tính chất một hạng mục Data Integrity: khi không bảo đảm
         | được bất biến thì phải từ chối, không được lặng lẽ hạ tiêu chuẩn.
         */
        /*
         | FE331-P1-08R4 — CHỐT CHẶN THẬT NẰM TRONG CƠ SỞ DỮ LIỆU, KHÔNG NẰM Ở CACHE.
         |
         | Khoá cache bên trên là hàng chờ (xếp hàng, chờ `wait_seconds` rồi mới từ chối)
         | — tiện cho người dùng bấm đúp, nhưng nó KHÔNG phải bảo đảm: `array`/`null`
         | store, hoặc `file` store khi chạy nhiều node, vẫn trả về một khoá "giành được"
         | cho cả hai request mà không ném lỗi nào; Redis/Valkey hỏng thì khoá không dựng
         | được; và `on_timeout=FAIL_OPEN` cho phép bỏ qua khoá bằng cấu hình.
         |
         | Vì vậy mọi lần import COMMIT còn phải giành một CHỖ GIỮ CHÍNH DANH trong bảng
         | `sku_import_claims` (UNIQUE theo định danh import). Chốt này dùng chính cơ sở
         | dữ liệu mà kết quả đằng nào cũng phải ghi vào, nên đúng trên mọi driver, mọi
         | node, và `FAIL_OPEN` KHÔNG vô hiệu hoá được nó.
         |
         | `claim_ttl_seconds` chỉ là van cứu hộ cho tiến trình chết giữa chừng, KHÔNG
         | phải cửa sổ idempotent (24h). Hết hạn thì request sau tiếp quản và ghi log
         | WARNING `SKU_IMPORT_CLAIM_TAKEOVER`. Giá trị bị ép sàn ở mức 2× ngân sách
         | commit đã duyệt để một cấu hình quá thấp không mở lại ca đua.
         */
        'lock' => [
            'ttl_seconds' => (int) env('SKU_IMPORT_LOCK_TTL_SECONDS', 120),
            'wait_seconds' => (int) env('SKU_IMPORT_LOCK_WAIT_SECONDS', 15),
            'on_timeout' => env('SKU_IMPORT_LOCK_ON_TIMEOUT', 'FAIL_CLOSED'),
            'claim_ttl_seconds' => (int) env('SKU_IMPORT_CLAIM_TTL_SECONDS', 900),
        ],

        /** Số giờ file lỗi CSV còn tải được kể từ lúc sinh (hạn dùng LOGIC → API trả 404). */
        'error_file_expiry_hours' => (int) env('SKU_IMPORT_ERROR_FILE_EXPIRY_HOURS', 24),

        /*
         | DEV330-P1-01R3 — RETENTION VẬT LÝ của file lỗi CSV, tách bạch với hạn dùng logic.
         |
         | Hai khái niệm khác nhau và trước đây chỉ có một cái được chốt:
         | - Hạn dùng LOGIC (`error_file_expiry_hours`): quá hạn thì API trả 404. File
         |   vẫn nằm trên đĩa.
         | - RETENTION VẬT LÝ: mốc file thực sự bị xoá khỏi storage. Nếu không chốt thì
         |   nội dung file người dùng nộp (mã SKU, tên hàng, từng ô sai) nằm trên đĩa vô
         |   thời hạn dù hợp đồng đã nói "hết hạn sau 24h".
         |
         | `purge_grace_hours` = khoảng đệm sau hạn dùng logic trước khi xoá vật lý; 0
         | nghĩa là hết hạn logic là đủ điều kiện xoá ngay ở lần chạy job kế tiếp. Vì job
         | chạy mỗi ngày một lần, thời gian tồn tại tối đa trên đĩa =
         | expiry + grace + (tối đa 24h chờ tới lần chạy kế). Con số đó được ghi tường
         | minh trong DEC-IMP-001 §5 thay vì để người đọc tự suy.
         |
         | `orphan_sweep` xử lý file KHÔNG còn bản ghi nào trỏ tới (report bị xoá, hoặc
         | ghi file xong nhưng lưu path vào DB thất bại). Quét theo report sẽ không bao
         | giờ chạm tới chúng — đó là rò rỉ retention thật, không phải giả định.
         */
        'error_file_purge_grace_hours' => (int) env('SKU_IMPORT_ERROR_FILE_PURGE_GRACE_HOURS', 0),
        'error_file_purge_daily_at' => env('SKU_IMPORT_ERROR_FILE_PURGE_AT', '02:15'),
        'error_file_orphan_sweep' => (bool) env('SKU_IMPORT_ERROR_FILE_ORPHAN_SWEEP', true),

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

    /*
    |--------------------------------------------------------------------------
    | PRODUCT Barcode/QR composite payload (Change Pack QR/SKU 20/08/2026)
    |--------------------------------------------------------------------------
    |
    | PRODUCT QR mới chứa đồng thời sku_code + item_unique và PHẢI được backend
    | parse authoritative (FE chỉ gửi raw payload). Đây là nguồn sự thật duy nhất
    | của các hằng số parser; ProductQrPayloadParser và các scan endpoint đều đọc
    | từ đây để khi nhà cung cấp chốt format thật chỉ phải đổi adapter/config,
    | không đụng luồng nghiệp vụ (DEC-QR-01).
    |
    | Quyết định chốt: docs/decision-records/DEC-QR-001.md
    |
    */
    'product_qr' => [
        'decision_ref' => 'DEC-QR-001',

        /** Prefix nhận diện composite payload; segment đầu = <prefix><version>. */
        'composite_prefix' => env('WMS_PRODUCT_QR_PREFIX', 'HN'),

        /** Các payload_version parser hiện hỗ trợ; version lạ -> UNSUPPORTED_PAYLOAD_VERSION. */
        'supported_versions' => [1],

        /*
         | DEC-QR-04 — transition flag cho mã legacy (plain code_value) trên dòng
         | PRODUCT của scan nhập/xuất. OFF nghĩa là mã PRODUCT bắt buộc composite;
         | các ngữ cảnh read-only (trace/warranty/classify) vẫn resolve mã cũ để
         | không khoá dữ liệu lịch sử.
         */
        'legacy_enabled' => (bool) env('WMS_PRODUCT_QR_LEGACY_ENABLED', true),

        /*
         | Trần độ dài raw payload nhận ở request (Change Pack §8: nâng 150 -> 512).
         | Evidence scan_event.raw_code bị cắt còn 200 ký tự theo trần cột ERD bảng
         | 25; phần parse được lưu đủ trong metadata/parsed fields.
         */
        'max_payload_length' => (int) env('WMS_PRODUCT_QR_MAX_PAYLOAD_LENGTH', 512),
    ],
];
