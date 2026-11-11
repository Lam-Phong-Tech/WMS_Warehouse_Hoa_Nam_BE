# Quy trình triển khai (Rollout) — Bảo mật đăng nhập thích ứng theo rủi ro

Tài liệu này hướng dẫn quy trình bật từng lớp bảo mật đăng nhập theo đặc tả `docs/superpowers/specs/2026-11-10-risk-adaptive-login-design.md` trên DEV/TEST và PROD (`prod-blue`), kèm điều kiện tiên quyết, kiểm tra sau triển khai và quy trình rollback.

---

## 1. Cấu hình cờ tính năng mặc định (Safe Defaults)

| Biến môi trường | Mặc định | Ý nghĩa |
|---|---|---|
| `LOGIN_BACKOFF_ENABLED` | `true` | Luỹ tiến thời gian chờ `429 Retry-After` theo cặp `(IP, email)` và theo tài khoản khi bị dò mật khẩu từ nhiều IP |
| `LOGIN_STEP_UP_ENABLED` | `true` | Sau 5 chu kỳ chạm trần 900s liên tiếp, yêu cầu xác minh bổ sung (MFA hoặc OTP email) trước khi kiểm tra mật khẩu |
| `TRUSTED_DEVICE_ENABLED` | `false` | Yêu cầu OTP email khi đăng nhập từ trình duyệt chưa có cookie thiết bị tin cậy (`wms_trusted_device_<hash>`) |
| `LOGIN_POW_ENABLED` | `false` | Yêu cầu giải bài toán SHA-256 Proof-of-Work (`428 POW_REQUIRED`) khi cặp `(IP, email)` có từ 3 lần sai trở lên |

---

## 2. Điều kiện tiên quyết trước khi bật `TRUSTED_DEVICE_ENABLED=true`

1. **Migration cơ sở dữ liệu:**
   ```bash
   php artisan migrate:status
   php artisan migrate --force
   ```
   Đảm bảo hai bảng `login_device_otps` và `trusted_devices` đã được tạo thành công.
2. **Hạ tầng gửi thư (SMTP / Transactional Email):**
   - Giai đoạn Pilot nội bộ (≤ 30 nhân sự): có thể dùng Gmail App Password (`smtp.gmail.com:587`), đã kiểm tra gửi thư thực tế.
   - Giai đoạn mở rộng toàn công ty (> 30 nhân sự): bắt buộc cấu hình dịch vụ gửi thư giao dịch có xác thực SPF, DKIM, DMARC (Amazon SES, Postmark, Resend hoặc Mailgun) để tránh chạm hạn mức ngày của Gmail.
   - Cơ chế **Fail-closed**: nếu gửi email thất bại, API trả `503 EMAIL_DELIVERY_UNAVAILABLE` và tuyệt đối không phát token phiên.
3. **Valkey / Cache & Scheduler:**
   - Kiểm tra kết nối Valkey và tiến trình `queue` / `scheduler` hoạt động ổn định, không gây bão khởi động lại Reverb.

---

## 3. Lộ trình bật từng giai đoạn (Phased Rollout)

### Giai đoạn 1 — Lớp 1: Backoff luỹ tiến & Step-up 5 chu kỳ
- Bật sẵn với `LOGIN_BACKOFF_ENABLED=true` và `LOGIN_STEP_UP_ENABLED=true`.
- Kiểm tra cấu hình:
  ```bash
  php artisan config:show login_security
  ```
- Mở khoá thủ công khi người dùng hợp lệ quên mật khẩu bị kẹt (có ghi nhận `LOGIN_THROTTLE_CLEARED` vào `audit_logs` chỉ kèm mã băm email):
  ```bash
  php artisan auth:clear-login-throttle --email="user@example.com"
  ```

### Giai đoạn 2 — Lớp 2: Thiết bị tin cậy (`TRUSTED_DEVICE_ENABLED`)
1. Thử nghiệm trên DEV/TEST với hộp thư thật.
2. Bật `TRUSTED_DEVICE_ENABLED=true` cho nhóm Pilot nội bộ (5–10 tài khoản quản trị/kho).
3. Theo dõi 48 giờ các sự kiện trong `audit_logs`:
   - `DEVICE_OTP_SENT`
   - `DEVICE_OTP_FAILED`
   - `DEVICE_TRUSTED`
   - `DEVICE_TRUST_REVOKED`
4. Sau khi chuyển sang nhà cung cấp email giao dịch chính thức, bật trên PROD.

### Giai đoạn 3 — Lớp 3: Proof-of-Work có điều kiện (`LOGIN_POW_ENABLED`)
1. Đo thời gian giải PoW trên thiết bị quét mã / máy trạm yếu nhất tại kho ở độ khó 16 bit:
   - Mục tiêu: trung vị `0.5–2.0s`, phân vị p95 `≤ 3.0s`.
   - Nếu p95 `> 3.0s`: giảm `LOGIN_POW_BASE_DIFFICULTY` xuống 14 hoặc giữ `LOGIN_POW_ENABLED=false`.
2. Khi đạt ngưỡng hiệu năng, đặt `LOGIN_POW_ENABLED=true`.

---

## 4. Danh mục kiểm tra thủ công trên DEV/TEST (Manual Verification Checklist)

- [ ] Hộp thư thật nhận được mã OTP thiết bị và OTP step-up; nội dung email chỉ chứa mã 6 chữ số, không chứa liên kết hay token.
- [ ] Xoá cookie trình duyệt → đăng nhập → nhận OTP → xác minh → nhận cookie `wms_trusted_device_<hash>` → đăng xuất → đăng nhập lại bỏ qua OTP.
- [ ] Sai mật khẩu qua 5 chu kỳ chạm trần → yêu cầu step-up → xác minh mã → nhập đúng mật khẩu → bộ đếm được xoá; nhập sai mật khẩu 3 lần sau step-up sẽ bắt xác minh step-up mới.
- [ ] Trình duyệt dùng chung: hai tài khoản khác nhau lưu hai cookie `wms_trusted_device_<hash>` độc lập và không ghi đè lẫn nhau.
- [ ] Mạng NAT dùng chung tại kho: hai tài khoản trên cùng một IP công cộng không tự khoá lẫn nhau; thiết bị đã tin cậy được miễn thời gian chờ.
- [ ] Tài khoản bật MFA: không gửi thêm OTP thiết bị qua email; bước step-up ưu tiên dùng MFA; không hỏi xác thực hai lần trong cùng một lượt đăng nhập.
- [ ] Máy quét / trình duyệt cũ: thời gian giải PoW ở 16 bit có trung vị 0.5–2s, p95 ≤ 3s; trình duyệt không hỗ trợ WebWorker/WebCrypto tự động fallback với `pow_supported=false` và ghi log `LOGIN_POW_UNAVAILABLE`.
- [ ] Kiểm tra `php artisan migrate:status`, `php artisan config:show login_security`, trạng thái `queue`/`scheduler` và đảm bảo không có hiện tượng Reverb restart storm sau khi deploy.

---

## 5. Quy trình Rollback khẩn cấp

Mỗi lớp có thể tắt độc lập ngay lập tức mà không cần rollback cơ sở dữ liệu:

```dotenv
TRUSTED_DEVICE_ENABLED=false
LOGIN_POW_ENABLED=false
LOGIN_STEP_UP_ENABLED=false
LOGIN_BACKOFF_ENABLED=false
```

Sau khi cập nhật `.env`, nạp lại cấu hình:
```bash
php artisan config:clear
php artisan config:cache
```
Khi `LOGIN_BACKOFF_ENABLED=false`, hệ thống tự động quay về bộ giới hạn `RateLimiter` 10 lần/phút ban đầu.
