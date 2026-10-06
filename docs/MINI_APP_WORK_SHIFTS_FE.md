# FE Mini App — Context kho và bắt đầu ca

Cập nhật 06/10/2026. **Không thêm endpoint mới.** Sửa `POST /api/v1/auth/switch-context` hiện có để lưu kho đã xác nhận theo `auth_session_id`, độc lập với ca. Hướng dẫn này thay phần context của bản 05/10/2026.

## BE triển khai trước

Triển khai migration `2026_10_06_000001_add_auth_session_warehouse_contexts.php` trước khi mở lưu lượng vào code mới; trên đúng môi trường BE:

```bash
php artisan migrate --force
php artisan route:clear
# Nếu môi trường dùng route cache:
php artisan route:cache
```

Không `migrate:fresh`, không seed demo, không xoá cache token/blacklist. Migration chỉ thêm bảng context; không tự gán kho cho tài khoản/phiên cũ.

## FE nối luồng

**1. Đọc phiên:** `GET /api/v1/auth/me` với Bearer token. Lấy `data.user_id`, `data.auth_session_id`, `data.active_warehouse_id`.

BE bổ sung `data.warehouse_context = { status, error_code }` và `data.available_warehouses[] = { warehouse_id, code, name, status }`. Danh sách chỉ chứa kho ACTIVE trong quyền tài khoản; **là lựa chọn, không phải kho đã chọn**.

| Context | FE xử lý |
|---|---|
| `READY` + UUID kho | Kho đã xác nhận và còn hợp lệ; kiểm tra ca. |
| `REQUIRED` + kho null | Hiển thị “Chưa xác nhận kho làm việc”; cho xác nhận từ danh sách API. |
| `UNAVAILABLE` | Hiển thị theo `error_code`: kho ngừng hoạt động/ngoài quyền/không tồn tại/context không khớp. Không tự thay kho. |
| `NO_SESSION` | Xử lý refresh/đăng nhập theo auth hiện có; không tự tạo session ID. |

Nếu danh sách rỗng: hiển thị “Chưa có kho hoạt động trong phạm vi được cấp. Liên hệ quản trị viên.” BE/IAM kiểm tra kho và phân quyền; FE không hard-code tên/UUID hoặc tự thêm quyền.

**2. Xác nhận kho khi cần:** người dùng xác nhận đúng kho làm việc rồi gửi API đã có (có thể là bước đầu của thao tác bấm “Bắt đầu ca”):

```http
POST /api/v1/auth/switch-context
Authorization: Bearer <access_token>
Content-Type: application/json

{"context_type":"WAREHOUSE","context_id":"<warehouse_id thực từ API>"}
```

Nhận HTTP 200: cập nhật access token bằng `data.access_token`; kiểm tra `data.user.active_warehouse_id` đúng kho và `data.user.auth_session_id` không đổi. Đọc lại `/auth/me` cũng trả cùng kho. `scope_ref` vẫn được hỗ trợ như alias; nếu gửi cả hai ID, chúng phải giống nhau. Không gửi actor/session từ FE.

Ngay cả chỉ có một kho, không tự lấy phần tử đầu rồi coi là đã xác nhận. `active_warehouse_id:null` trước xác nhận **không phải JSON hỏng**. Xác nhận kho **chưa tạo ca**, không thay cho API start.

**3. Kiểm tra/bắt đầu ca:** `GET /api/v1/mini-app/work-shifts/current`. HTTP 200 + `data:null` = chưa bắt đầu, giữ màn xác nhận. Khi người dùng bắt đầu, gửi:

```http
POST /api/v1/mini-app/work-shifts/start
Authorization: Bearer <access_token>
Idempotency-Key: <UUID>
Content-Type: application/json

{"warehouse_id":"<active_warehouse_id đã xác nhận>"}
```

201 = ca mới; 200 = ca đã có. Chỉ mở Home khi ca khớp `actor_id`, `auth_session_id`, `warehouse_id`, có `status:"ACTIVE"`, `preview_ready:true`. Giữ `shift_id` và `started_at` do BE trả; không tạo giá trị demo. `started_at` là ISO 8601 UTC, hiển thị theo Asia/Ho_Chi_Minh.

**4. Timeout/reload:** khóa nút khi gửi/đối chiếu. Timeout lúc xác nhận kho: đọc lại `/auth/me` và đối chiếu kho/phiên; chưa khớp thì không start. Timeout lúc start: đọc lại `current`; nếu vẫn null, retry **cùng key, cùng body**. Lưu yêu cầu start đang chờ theo phiên để reload vẫn đối chiếu được; không đổi kho khi start chưa rõ kết quả.

## Phiên cũ và lỗi

Phiên cũ có ca: dùng đúng kho từ ca của chính phiên, kiểm tra lại ACTIVE và scope. Phiên cũ chưa có context/ca: xác nhận qua `switch-context`, không bắt đăng nhập lại nếu phiên còn hợp lệ. Đăng nhập mới là phiên mới và cần xác nhận riêng. Refresh/reload cùng phiên giữ context; logout/thu hồi/hết hạn không được khôi phục bằng dữ liệu FE cũ.

401: auth hết hiệu lực; 403: ngoài quyền; 404: kho không tồn tại; 409 `WAREHOUSE_INACTIVE`, `WAREHOUSE_CONTEXT_MISMATCH`, `SHIFT_WAREHOUSE_CONFLICT`: giữ màn xác nhận, đối chiếu lại, không tự chọn kho khác; 409 `AUTH_SESSION_REQUIRED`: cần phiên refresh hợp lệ. 422: kiểm tra UUID/body. Không vào Home vì 429/5xx/offline. Giữ nguyên bảo mật cookie HttpOnly và transport auth hiện tại.

BE không cho đổi/bỏ kho của phiên đã bắt đầu ca; cùng kho có thể xác nhận lại an toàn. Chưa thêm nghiệp vụ kết ca/chấm công hoặc gate ca cho các API nghiệp vụ khác.

## QA bàn giao

Kiểm tra: xác nhận kho → `/auth/me` có UUID, `current` vẫn null → start 201 → retry 200 cùng ca → refresh/reload giữ phiên/kho/ca → đăng nhập mới không kế thừa → kho INACTIVE hoặc mất scope không còn `READY`.

Báo lỗi kèm môi trường, commit triển khai, thời điểm, endpoint/HTTP status, `meta.request_id` hoặc `X-Request-ID` và các ID liên quan đã che thông tin cá nhân. Không gửi access/refresh token, cookie hoặc mật khẩu. Đường dẫn `C:/Users/...` chỉ dùng được trên máy FE; cần đính kèm nội dung/file hoặc đưa vào repo để BE đối chiếu.
