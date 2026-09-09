<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Đĩa lưu file export báo cáo
    |--------------------------------------------------------------------------
    |
    | File export do JOB NỀN sinh ra (GenerateReportExportJob) nhưng lại được TẢI
    | VỀ qua một request HTTP khác (`GET /reports/export/{id}/download`). Hai việc
    | đó chạy ở hai tiến trình khác nhau — trong compose hiện tại là container
    | `queue` và container `backend`.
    |
    | Trước đây cả hai đều dùng cứng `Storage::disk('local')`. Nó chỉ hoạt động vì
    | hai container đang bind-mount CÙNG một thư mục trên máy chủ. Khi chạy NHIỀU
    | INSTANCE sau load balancer (đúng mục tiêu 3.000 người dùng), điều đó không
    | còn đúng: worker ghi file vào đĩa của nó, còn request tải về rơi vào một
    | instance bất kỳ — người dùng nhận 404 "file đã bị dọn dẹp" cho một file thật
    | sự đang tồn tại.
    |
    | MẶC ĐỊNH GIỮ `local` — hành vi y hệt trước, không môi trường nào đổi ngầm.
    |
    | ĐỂ CHẠY NHIỀU INSTANCE: đặt `REPORT_EXPORT_DISK` sang một đĩa DÙNG CHUNG
    | (s3 / R2 / MinIO). KIỂM TRA TRƯỚC KHI ĐỔI: đĩa đó phải thật sự dùng được.
    | Đo ngày 06/09/2026 trên stack local, `FILESYSTEM_DISK=s3` trỏ vào MinIO
    | nhưng bucket `wms-hoanam` CHƯA ĐƯỢC TẠO — mọi thao tác đĩa s3 đều hỏng, nên
    | mặc định theo `FILESYSTEM_DISK` sẽ làm hỏng chức năng xuất báo cáo. Xem
    | docs/perf/PERF_AUDIT_2026-09-06.md §7.
    |
    |   docker compose exec backend php artisan tinker --execute=\
    |     "Storage::disk('s3')->put('healthcheck.txt','ok');"
    |
    | LƯU Ý KHI ĐỔI GIÁ TRỊ NÀY: job đã READY trước đó trỏ vào đĩa cũ sẽ không tải
    | được nữa. Chúng tự hết hạn sau 24 giờ (expires_at), nên cách an toàn là đổi
    | vào lúc ít người dùng, hoặc chấp nhận người dùng bấm xuất lại.
    |
    */
    'disk' => env('REPORT_EXPORT_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Chống job export kẹt PENDING vô thời hạn (WWHN-343)
    |--------------------------------------------------------------------------
    |
    | `POST /reports/export` chỉ ĐẨY GenerateReportExportJob vào Redis. Nếu container
    | `queue` không chạy thì không có gì nhặt job: bản ghi nằm ở PENDING mãi mãi, API
    | vẫn trả 200 nhưng `download_url=null` và `error_code=null` nên FE không tải được
    | file mà cũng không có gì để báo cho người dùng — đúng triệu chứng WWHN-343
    | (TC_FUNC_03), dựng lại được bằng cách tắt container `queue`.
    |
    | Ba tham số dưới đây là bánh đà tự lành, áp dụng ở MỌI chỗ đọc trạng thái job
    | (ReportExportJobRunner::ensureProgress()):
    |
    | - pickup_grace_seconds: PENDING quá ngần này giây = worker coi như không có.
    |   Phải LỚN hơn thời gian worker khoẻ mạnh cần để nhặt job (đo local: < 2s) và
    |   NHỎ hơn nhiều so với timeout poll của FE (60s) để lần poll kế tiếp còn kịp
    |   nhận file.
    |
    | - inline_fallback: true = tiến trình phát hiện job quá hạn sẽ tự chạy export
    |   ngay trong tiến trình của mình (chậm hơn nhưng NGƯỜI DÙNG VẪN CÓ FILE).
    |   false = chỉ chốt FAILED kèm error_code/error_message. Đặt false khi muốn ép
    |   toàn bộ export đi qua worker và coi worker chết là sự cố phải nhìn thấy.
    |
    | - processing_timeout_seconds: PROCESSING quá ngần này giây mà không ai đụng vào
    |   bản ghi = tiến trình chạy nó đã chết giữa chừng (worker timeout/OOM/bị kill).
    |   Phải LỚN hơn QUEUE_WORKER_TIMEOUT (mặc định 90s) để không giết oan job đang
    |   chạy thật.
    |
    */
    'pickup_grace_seconds' => (int) env('REPORT_EXPORT_PICKUP_GRACE_SECONDS', 20),

    'inline_fallback' => filter_var(env('REPORT_EXPORT_INLINE_FALLBACK', true), FILTER_VALIDATE_BOOL),

    'processing_timeout_seconds' => (int) env('REPORT_EXPORT_PROCESSING_TIMEOUT_SECONDS', 180),

    /*
    |--------------------------------------------------------------------------
    | Trần bộ nhớ và trần dòng PDF (WWHN-347)
    |--------------------------------------------------------------------------
    |
    | WWHN-343 lo phần "không ai nhặt job". WWHN-347 là nửa còn lại: job ĐƯỢC nhặt,
    | chạy, rồi CHẾT vì hết bộ nhớ — và PHP fatal "Allowed memory size exhausted"
    | KHÔNG phải Throwable nên `catch (Throwable)` trong ReportExportJobRunner::run()
    | không bắt được. Bản ghi đứng nguyên ở PENDING/PROCESSING, error_code=null.
    |
    | Số đo 09/09/2026 trên stack dev (document-history, 17 cột, dữ liệu thật
    | 06→09/2026), chạy với `php -d memory_limit=3G` để đo đỉnh thật:
    |
    |   XLSX (PhpSpreadsheet, sau khi bỏ setAutoSize):
    |      4.243 dòng → 130 MB      10.000 dòng → 211 MB / 4,1 s
    |   PDF (Dompdf, sau khi rút gọn CSS bảng):
    |        500 dòng → 251 MB / 3,8 s     1.000 dòng → 443 MB / 10,2 s
    |      2.000 dòng → KHÔNG XONG trong 3 GB
    |
    | memory_limit: trần đặt CHO RIÊNG tiến trình đang dựng file, không phụ thuộc
    | php.ini của container (base image để 128M — nhỏ hơn cả một file XLSX 4.000
    | dòng, đúng lý do WWHN-347). 512M ôm trọn hợp đồng 10.000 dòng XLSX (211 MB)
    | với dư ~2,4 lần. Đặt chuỗi rỗng để giữ nguyên trần của tiến trình.
    |
    | pdf_row_limit: Dompdf dựng một frame cho MỖI ô nên bộ nhớ tăng nhanh hơn
    | tuyến tính — 10.000 dòng là bất khả thi ở mọi trần bộ nhớ hợp lý, nên PDF phải
    | có trần RIÊNG, thấp hơn EXPORT_ROW_LIMIT. 1.000 dòng = 443 MB, vừa trong 512M
    | nhưng sát; 800 dòng (~355 MB) để lại ~30% dư cho dữ liệu rộng hơn bộ đo.
    | Vượt trần trả 422 REPORT_EXPORT_PDF_ROW_LIMIT_EXCEEDED và chỉ sang XLSX —
    | thất bại NHÌN THẤY ĐƯỢC, thay cho job treo im lặng.
    |
    */
    'memory_limit' => env('REPORT_EXPORT_MEMORY_LIMIT', '512M'),

    'pdf_row_limit' => (int) env('REPORT_EXPORT_PDF_ROW_LIMIT', 800),

    /*
    |--------------------------------------------------------------------------
    | Ngân sách Ô cho một file PDF (WWHN-344)
    |--------------------------------------------------------------------------
    |
    | `pdf_row_limit` ở trên được hiệu chuẩn trên bảng 17 cột (document-history, 347) và
    | 20 cột (label-log, 345). Nhưng Dompdf dựng một frame + một bộ style đã resolve cho
    | mỗi Ô, nên thứ quyết định tiến trình sống hay chết là SỐ Ô (dòng × cột), không phải
    | số dòng. Áp thẳng 800 dòng cho một báo cáo RỘNG HƠN là vượt ngân sách đã đo mà
    | không ai thấy — đúng cách import-match (22 cột) lọt lưới:
    |
    |     17 cột × 800 dòng = 13.600 ô → 355 MB   (đo WWHN-347)
    |     20 cột × 800 dòng = 16.000 ô → 460 MB   (đo WWHN-345, mốc chấp nhận trong 512M)
    |     22 cột × 800 dòng = 17.600 ô → vượt mốc trên 10%, không còn dư nào
    |
    | Và bảng 22 cột với template PDF CŨ thì hỏng sớm hơn nhiều — đo 09/09/2026, trần 512M:
    |
    |     367 dòng, template cũ (border-collapse + viền 4 cạnh) → HẾT BỘ NHỚ
    |     367 dòng, template rút gọn của 347                    → 394 MB / 12,2 s, xong
    |
    | 16.000 = đúng ngân sách ô của mốc 460 MB đã được chấp nhận ở WWHN-345. Giữ nguyên
    | con số đó nghĩa là các báo cáo ≤20 cột KHÔNG đổi hành vi (vẫn chạm `pdf_row_limit`
    | trước), còn báo cáo rộng hơn tự hạ trần dòng xuống cho vừa cùng lượng bộ nhớ:
    |
    |     17 cột → 800 dòng      20 cột → 800 dòng      22 cột → 727 dòng
    |
    | Đặt 0 để bỏ ngân sách ô và chỉ dùng `pdf_row_limit`.
    |
    */
    'pdf_cell_limit' => (int) env('REPORT_EXPORT_PDF_CELL_LIMIT', 16000),

];
