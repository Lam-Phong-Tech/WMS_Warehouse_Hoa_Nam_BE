# P02 — Phần BE đã sửa và cách nối FE

Cập nhật 06/10/2026. Phạm vi commit: context kho (xem `MINI_APP_WORK_SHIFTS_FE.md`) và bản sửa đọc 5 file đã rà soát. Đây không phải tuyên bố hoàn tất cả 8 mục BE-P02 hoặc đã deploy server.

## Đã sửa

- `warehouse_id=<UUID>` thực sự thu hẹp SQL của danh sách nhập/xuất Web và Mini App; vẫn giao với scope tài khoản. Không gửi thì giữ cách lấy dữ liệu cũ. `meta.total` là tổng sau các bộ lọc được endpoint đó hỗ trợ, không phải độ dài một trang.
- Mặc định nhập/xuất sắp xếp `created_at DESC, id DESC`; bảo hành `created_at DESC, warranty_case_id DESC`. `sort_order` hiện có của Web/bảo hành vẫn giữ ý nghĩa tạo/sửa gần nhất, không chuyển ngầm thành thời gian tạo.
- Cache danh sách nhập/xuất có namespace mới để không dùng kết quả trước bản sửa. Không xóa cache auth/blacklist.
- Context kho đã xác nhận được lưu theo phiên trước khi tạo ca. Không hard-code hoặc tự lấy kho đầu tiên; `/auth/me` trả danh sách lựa chọn và trạng thái context. Chi tiết ở `MINI_APP_WORK_SHIFTS_FE.md`.

## FE nối ngay sau khi BE deploy

Sau khi xác nhận kho/ca hợp lệ, dùng UUID server trả. KPI nhập/xuất dùng endpoint Mini App đang có:

```http
GET /api/v1/mini-app/dashboard/summary?warehouse_id=<UUID>
GET /api/v1/mini-app/inbound-documents?warehouse_id=<UUID>&status=WAITING_APPROVAL&page=1&per_page=1
GET /api/v1/mini-app/outbound-documents?warehouse_id=<UUID>&ready_for_issue=1&page=1&per_page=1
```

Đọc tổng từ `meta.total` ở danh sách, hoặc `data.inbound.pending_approval_count` / `data.outbound.pending_approval_count` ở summary. Khối null vì không có quyền không được đổi thành 0. Hàng chờ này nghĩa quét đủ khi SCANNING, không bảo đảm mọi điều kiện Post đều đã đủ; vẫn đọc `can_post`/lỗi nghiệp vụ khi thao tác.

Khi mở danh sách từ KPI phải giữ đúng kho và cùng filter được hỗ trợ. **Không áp `WAITING_APPROVAL` cho phiếu xuất.** Bản này chưa thêm alias readiness cho endpoint Web: không gọi Web với filter Mini App rồi coi HTTP 200 là đã lọc đúng.

Tên/trạng thái kho: `/api/v1/warehouses/{active_warehouse_id}` cần `warehouse.view`; enum `ACTIVE`/`INACTIVE`. Badge thông báo vẫn lấy riêng `/api/v1/notifications/unread-count`, không đếm trang thông báo. Giờ ca lấy `started_at` server; không thêm API đồng hồ.

## Các mục chưa chốt/ngoài phạm vi bản sửa này

- Scope kho của danh sách bảo hành và KPI mở vẫn cần quyết định canonical; không gán một hồ sơ vào kho từ tên kho, creator hoặc kho hiện tại của item bằng suy đoán.
- Canonical trạng thái mở bảo hành: `RECEIVED,CHECKING,REPAIRING,COMPLETED`; `COMPLETED` là hoàn tất xử lý nhưng chưa trả khách. “Chờ bàn giao” chỉ dùng khi nghiệp vụ xác nhận đó là chờ trả khách.
- Ba chứng từ recent tổng hợp chưa có endpoint mới. Thứ tự mặc định đã ổn định nhưng chưa giải quyết scope bảo hành hoặc snapshot đồng thời giữa ba API. Không tuyên bố recent đúng kho khi phần scope chưa chốt.
- Logout giữ nguyên hợp đồng: Bearer **access token**; native thêm `X-WMS-Token-Transport: body` và body refresh_token nếu server đã cho phép; Web dùng cookie HttpOnly + `{}`. Không dùng refresh token làm Bearer và không bỏ Bearer ở endpoint logout. Chưa thay cơ chế thu hồi toàn bộ access token của phiên.
- Không thêm permission, gate ca cho API nghiệp vụ, chấm công/kết ca hoặc API nháp đa thiết bị.

## Triển khai và kiểm chứng

Deploy code cùng migration context `2026_10_06_000001_add_auth_session_warehouse_contexts.php` trước khi mở lưu lượng; `php artisan migrate --force`, `php artisan route:clear`, dựng lại route cache nếu môi trường dùng. Không `migrate:fresh`, không seed demo hoặc xoá blacklist.

Workflow `Mini App Work Shifts` chạy 5 lớp test ca/context/P02/auth trên SQLite và PostgreSQL **dùng database tách biệt**. Đọc kết quả theo đúng SHA trên GitHub Actions; không coi test của SHA cũ là bằng chứng cho release mới. Đây không phải full-suite hoặc live UAT.

Sau deploy, FE gửi HTTP status, `meta.request_id`/`X-Request-ID`, commit server, thời điểm và response đã che thông tin nhạy cảm để đối chiếu; không gửi token/cookie/mật khẩu. Các Request-ID ngày 06/10 trước đây là evidence lịch sử một phiên, không phải lần kiểm tra mới.
