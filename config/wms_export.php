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

];
