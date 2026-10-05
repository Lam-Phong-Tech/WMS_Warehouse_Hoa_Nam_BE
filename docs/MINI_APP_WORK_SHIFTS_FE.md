# Mini App — Bắt đầu ca (FE)

## Triển khai BE trước
Chạy `php artisan migrate --force`, sau đó `php artisan route:clear` trên đúng môi trường BE; nếu dùng route cache, dựng lại bằng `php artisan route:cache`. Không dùng `migrate:fresh`, không seed dữ liệu demo, không xoá cache token/blacklist.

## FE nối 4 bước
1. Đăng nhập như hiện tại. `GET /api/v1/auth/me` có thêm `data.auth_session_id` (ổn định qua refresh) và `data.active_warehouse_id` (null khi chưa xác nhận kho). `login`, `refresh`, MFA verify cũng có các trường này trong `data.user` khi thực sự cấp phiên refresh.
2. Gọi `GET /api/v1/mini-app/work-shifts/current` với Bearer token. `200 + data:null`: chưa bắt đầu ca, giữ màn xác nhận. Có ca đúng người/phiên/kho, `status:ACTIVE`, `preview_ready:true`: tiếp tục ca đó.
3. Khi bấm **Bắt đầu ca**, gọi `POST /api/v1/mini-app/work-shifts/start`, header `Idempotency-Key: <UUID>`, body `{"warehouse_id":"<UUID thực của kho>"}`. Không gửi actor/session/status từ client. Không gửi mã kho `WH-01` thay ID. BE trả **201** khi tạo, **200** khi đã có ca cùng phiên/kho.
4. Chỉ mở Home sau response xác nhận ca hợp lệ. Timeout/offline/5xx: giữ màn xác nhận, gọi lại `current` khi kết nối trở lại. Nếu vẫn null, retry **cùng key và cùng body**, không sinh key mới cho yêu cầu chưa rõ kết quả. Lưu key đang chờ theo auth_session_id để còn đối chiếu sau reload. Khóa nút trong lúc gửi/đối chiếu.

## Data của cả hai API ca
```ts
type WorkShift = {
  shift_id: string; actor_id: string; auth_session_id: string;
  warehouse_id: string; status: 'ACTIVE'; started_at: string;
  preview_ready: true;
};
```
`started_at` là ISO 8601 UTC; FE hiển thị theo Asia/Ho_Chi_Minh. `preview_ready` chỉ xác nhận sẵn sàng qua màn xác nhận phiên (ca đã lưu + phiên/tài khoản/kho/quyền hợp lệ), không phải trạng thái máy in hoặc nhãn.

## Lỗi cần xử lý
- **401**: token/phiên hết hạn hoặc bị thu hồi. Gia hạn theo cơ chế auth hiện có khi phù hợp; nếu phiên refresh đã hết hiệu lực thì đăng nhập lại. Không biến thành `data:null`.
- **403**: thiếu quyền Mini App hoặc kho ngoài phạm vi; không vào Home.
- **409 AUTH_SESSION_REQUIRED**: token không có phiên refresh; đăng nhập qua đúng kênh transport. Trình duyệt/WebView dùng cookie HttpOnly + credentials + Origin được cho phép. Client native chỉ dùng `X-WMS-Token-Transport: body` khi BE đã bật cơ chế này; không tự mở rộng CORS/giảm bảo mật.
- **409 WAREHOUSE_INACTIVE / SHIFT_WAREHOUSE_CONFLICT / IDEMPOTENCY_KEY_REUSED**: giữ màn xác nhận, hiển thị lỗi; không tự chọn kho hoặc tạo ca khác.
- **422**: kiểm tra UUID kho, key và body. **404**: kho không tồn tại. **429/5xx/offline**: chưa có xác nhận thành công; không vào Home.

## Phạm vi
Một ca kỹ thuật trên mỗi phiên đăng nhập ổn định. Reload/refresh giữ nguyên ca; đăng nhập mới là phiên mới. Logout/thu hồi/hết hạn refresh khiến ca cũ không còn sử dụng được; chứng cứ bắt đầu được giữ lại, không bịa giờ kết thúc. Chưa bổ sung nghiệp vụ chấm công/kết ca. Không dùng `auth/sessions` để dò ID; không dùng `auth/switch-context` thay API bắt đầu ca. Không thêm kho/role/permission mới: giữ nguyên giới hạn kho và quyền nghiệp vụ đã có. Các endpoint nghiệp vụ hiện có không bị gắn thêm gate ca trong thay đổi này.
