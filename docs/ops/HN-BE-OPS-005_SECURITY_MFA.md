# HN-BE-OPS-005 — Xác thực hai bước (MFA) cho tài khoản đặc quyền

Phạm vi: WWHN-SEC-08. Đối tượng đọc: DevOps, quản trị viên hệ thống, người ký cutover.

---

## 1. Phạm vi — đặt MFA ở đâu và vì sao KHÔNG đặt ở chỗ khác

MFA đặt ở nơi nó **đổi được kết cục** của một vụ lộ mật khẩu, và không đặt ở nơi
nó chỉ làm chậm công việc.

| | Quyết định |
|---|---|
| Vai trò **bắt buộc** | `SUPER_ADMIN`, `ADMIN`, `IAM_ADMIN` (`MFA_REQUIRED_ROLES`) |
| Vai trò vận hành kho | **Không bắt buộc**, nhưng ai cũng tự bật được |
| Tần suất | **Một lần mỗi phiên đăng nhập**, không phải mỗi thao tác |

Lý do loại vai trò kho ra: nhân viên quét mã cả ca, mỗi phiếu vài chục lần chạm
màn hình. Bắt nhập 6 chữ số ở mỗi thao tác là cách chắc chắn để họ dùng chung một
điện thoại đặt cạnh máy quét — MFA khi đó **biến mất trên thực tế** nhưng vẫn "có"
trong báo cáo tuân thủ. Đó là kết quả tệ hơn không có MFA, vì nó tạo cảm giác an
toàn sai.

---

## 2. Thiết kế

- **TOTP RFC 6238** (HMAC-SHA1, 6 chữ số, chu kỳ 30 giây, cửa sổ ±1 bước).
  Cài trong `App\Support\Security\Totp`, đối chiếu với **chính bộ vector kiểm thử
  của RFC** ở `tests/Feature/WwhnSec08MfaTest::test_khop_vector_kiem_thu_cua_rfc_6238`.
  Không thêm dependency composer cho ~40 dòng mã có đặc tả từng bit; phần *không*
  được tự chế (sinh bí mật, lưu bí mật) vẫn dùng `random_bytes()` + AEAD.
- **Bí mật TOTP** lưu ở `user_mfa.secret_ciphertext` dưới keyring PII
  (WWHN-SEC-06). Lấy được bản dump mà không có khoá thì **không sinh được mã**.
- **Mã khôi phục**: 8 mã, lưu ở dạng **băm** (bcrypt), mỗi mã dùng **một lần**.
  Băm chứ không mã hoá — hệ thống chỉ cần *kiểm*, không bao giờ cần đọc lại.
- **Phiên chờ** giữa hai bước là một chuỗi ngẫu nhiên trong cache (Valkey), TTL
  5 phút, **dùng một lần**, và mất hiệu lực nếu mật khẩu đổi giữa chừng.

### Năm chỗ MFA hay bị cài sai — và cách đã xử lý ở đây

| Sai lầm phổ biến | Ở đây |
|---|---|
| Bước 1 trả về một token "hạn chế" | **Không phát token nào.** JWT tạo ra khi kiểm mật khẩu bị `logout()` ngay trong cùng hàm |
| Mã TOTP dùng lại được trong cửa sổ 30–90 giây | Ghi lại mã đã dùng trong cache theo (user, mã) |
| Mã phiên chờ dùng được nhiều lần trong TTL | `consumeChallenge()` xoá khoá **ngay khi tiêu thụ**, kể cả khi bước sau thất bại |
| Không có trần thử → dò 10^6 khả năng | Hai lớp: throttle theo (IP + mfa_token), và `failed_attempts`/`locked_until` theo tài khoản |
| Chỉ kiểm trạng thái tài khoản ở bước mật khẩu | `verify()` kiểm lại `status === 'ACTIVE'` — xem mục dưới |

**Vì sao phải kiểm trạng thái LẦN NỮA ở bước 2.** Phiên chờ sống 5 phút, còn bước
phát token là bước 2. Nếu chỉ kiểm ở bước 1 thì một lệnh khoá tài khoản khẩn cấp
chỉ có hiệu lực **sau khi phiên chờ hết hạn** — đúng khoảng thời gian mà người ta
cần nó nhất. Bản đầu tiên của tính năng này thiếu đúng chỗ đó và **không test nào
bắt được**, vì cả hai đường đều trả về token hợp lệ; khác biệt duy nhất nằm ở một
tài khoản vừa bị khoá vài giây trước. Đã khoá lại bằng
`WwhnSec08MfaTest::test_khoa_tai_khoan_giua_hai_buoc_thi_KHONG_phat_token`.

---

## 3. Bề mặt API

| Method | Path | Auth | Ghi chú |
|---|---|---|---|
| POST | `/api/v1/auth/login` | — | Trả `mfa_required: true` + `mfa_token` khi tài khoản đã bật |
| POST | `/api/v1/auth/mfa/verify` | — | Bước 2. Nhận mã TOTP **hoặc** mã khôi phục |
| GET | `/api/v1/auth/mfa/status` | Bearer | Tự phục vụ |
| POST | `/api/v1/auth/mfa/enroll` | Bearer | Trả bí mật + `otpauth://` |
| POST | `/api/v1/auth/mfa/confirm` | Bearer | Trả mã khôi phục **một lần duy nhất** |
| POST | `/api/v1/auth/mfa/disable` | Bearer | Cần mật khẩu **và** một mã hợp lệ |

`disable` bị **từ chối** (403 `MFA_REQUIRED_FOR_ROLE`) khi cưỡng chế đang bật và
tài khoản thuộc vai trò bắt buộc: cho phép tự tắt nghĩa là ai chiếm được phiên
đăng nhập cũng gỡ được lớp bảo vệ.

### Năm route này phải khai ở đâu nữa

Thêm route vào `Modules/Auth/routes/api.php` là **chưa đủ**. Repo có nhiều sổ đăng
ký độc lập, mỗi sổ do một test riêng canh, và chúng không nhắc nhau:

| Route | Sổ đăng ký | Test canh |
|---|---|---|
| `auth/mfa/verify` (không có `auth:api`) | `ApiAuthContext::PUBLIC_PATH_PATTERNS` | `AuthPublicEndpointMiddlewareBypassTest` |
| `auth/mfa/verify` | `Wwhn249PvSurfaceGovernanceTest::DECLARED_ANONYMOUS_ROUTES` + `scripts/generate-endpoint-permission-matrix.mjs` → `PUBLIC_ALLOWLIST` | `Wwhn249PvSurfaceGovernanceTest` |
| 4 route tự phục vụ (có `auth:api`, không có `permission:`) | `PermissionEnforcementMap::SELF_SCOPED_ROUTES` | `AuthorizationCoverageInvariantTest` |
| cả 5 | `BackendGoLiveCheck::PUBLIC_ROUTES` / `SELF_SERVICE_ROUTES` | `Wwhn300BackendGoLiveCheckTest` |

**Bẫy ngược chiều — đọc kỹ trước khi thêm route public.** Khai vào
`PUBLIC_PATH_PATTERNS` chính là **tắt** `ApiRateLimit` cho route đó (nhánh
`isPublicEndpoint()` → `return $next()`). Nghĩa là vá một test governance có thể
vô tình mở đường dò mã. Ở đây không mất gì vì route bước 2 tự mang
`throttle:auth-mfa-verify` riêng — và điều kiện kép "vừa public vừa có throttle"
đã được khoá lại bằng
`WwhnSec08MfaTest::test_route_buoc_hai_duoc_khai_la_public_va_van_co_throttle_rieng`,
để lần sau không ai gỡ throttle mà tưởng vô hại.

Bốn route tự phục vụ **cố ý không có** `permission:`: `MfaService` khoá mọi truy
vấn theo `user_id = <actor>` và không nhận tham số trỏ sang user khác. Gắn quyền
vào đây sẽ biến việc tự bật MFA thành một quyền phải được cấp — đúng chiều ngược
lại với mục tiêu.

---

## 4. Bật cưỡng chế — TRÌNH TỰ BẮT BUỘC

Làm sai thứ tự = khoá toàn bộ quản trị viên ra khỏi hệ thống.

1. Deploy bản có tính năng, `MFA_ENFORCE_FOR_PRIVILEGED_ROLES=false`.
2. Từng quản trị viên đăng nhập, vào `/profile/mfa`, quét mã, xác nhận, **lưu mã
   khôi phục**.
3. `php artisan wms:golive-check` → `security.mfa` cho thấy
   `privileged_without_mfa: 0`.
4. **Chỉ khi đó** mới đặt `MFA_ENFORCE_FOR_PRIVILEGED_ROLES=true` và deploy lại.

Nếu vẫn có người bị kẹt: middleware `EnforceMfaEnrollment` **cố ý** cho đi qua
nhóm `/auth/mfa/*`, `logout`, `change-password`, `me`, `refresh`. Người bị chặn
vẫn đăng nhập và tự đăng ký được — không cần ai sửa DB.

**Đường thoát hiểm cuối** (mất điện thoại **và** hết mã khôi phục): một
SUPER_ADMIN khác xoá bản ghi MFA của người đó bằng
`DELETE FROM user_mfa WHERE user_id = '<uuid>'`, người đó đăng ký lại. Thao tác
này **phải** kèm xác minh danh tính ngoài hệ thống (gọi điện, gặp trực tiếp) —
nếu không, nó chính là backdoor bỏ qua MFA.

---

## 5. Sự kiện audit

`MFA_ENROLL_STARTED`, `MFA_ENROLL_FAILED`, `MFA_ENABLED`, `MFA_DISABLED`,
`MFA_CHALLENGE_ISSUED`, `MFA_VERIFY_FAILED`, `MFA_LOCKED`,
`MFA_RECOVERY_CODE_USED`.

`MFA_VERIFY_FAILED` liên tiếp trên cùng một tài khoản là **tín hiệu sớm nhất của
việc mật khẩu đã bị lộ** — kẻ tấn công có mật khẩu đúng và đang mắc ở lớp thứ hai.
Nên cấu hình cảnh báo cho chuỗi ≥3 sự kiện này trong 10 phút; mất tín hiệu đó thì
MFA chặn được kẻ tấn công nhưng không cho ai biết là đã có kẻ tấn công.

`MFA_RECOVERY_CODE_USED` cũng đáng cảnh báo: mã khôi phục là đường thoát hiểm,
không phải cách đăng nhập hằng ngày.

---

## 6. Giao diện

- `/login` — bước 2 hiện ngay trên màn đăng nhập khi BE trả `mfa_required`.
  Store xác thực **vẫn rỗng** cho tới khi qua bước 2 (khoá lại bằng
  `src/modules/auth/pages/LoginPage.mfa.test.tsx`).
- `/profile/mfa` — tự đăng ký, hiện mã khôi phục một lần.

**Cố ý KHÔNG dùng dịch vụ tạo ảnh QR bên ngoài**: gửi `otpauth://` chứa bí mật
TOTP sang máy chủ của người khác là tự huỷ toàn bộ lớp bảo vệ. Màn hình hiện bí
mật dạng chữ để dán vào ứng dụng xác thực, kèm liên kết `otpauth://` mở thẳng app
trên chính thiết bị đó. Muốn có QR thật thì phải render **cục bộ** bằng thư viện
trong bundle.

---

## 7. Bằng chứng

- `tests/Feature/WwhnSec08MfaTest.php` — 17 test, gồm vector RFC 6238, chống dùng
  lại mã, chống dùng lại phiên chờ, khoá sau N lần sai, allowlist của middleware,
  khoá tài khoản giữa hai bước, và bất biến "public thì phải có throttle riêng".
- `tests/Feature/AuthPublicEndpointMiddlewareBypassTest.php` và
  `tests/Feature/AuthorizationCoverageInvariantTest.php` — hai bất biến của bảng
  route; cả hai từng đỏ vì thiếu khai sổ, nay xanh.
- `src/modules/auth/pages/LoginPage.mfa.test.tsx` — bước 2 ở phía giao diện.
- `php artisan wms:golive-check` → `security.mfa`.
