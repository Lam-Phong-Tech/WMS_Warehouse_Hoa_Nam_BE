# SEC-20260910 P1 — Hợp đồng refresh token: chỉ cookie HttpOnly cho trình duyệt

**Phạm vi:** BE từ `e5dbe0d`, FE từ `266f340`, cả hai trên nhánh `dev`.
**Đối tượng:** FE, BE, Mini App/tích hợp, DevOps, QA.

---

## 1. Vì sao đổi

- **Trước đây**, `/auth/login`, `/auth/refresh` và `/auth/mfa/verify` trả refresh token 14 ngày **trong JSON**. FE giữ nó trong bộ nhớ JavaScript rồi gửi lại trong body.
- **Hệ quả:** một lỗi XSS bất kỳ trên origin FE đọc được credential dài hạn này và mang sang máy khác dùng. Cookie HttpOnly có tồn tại song song nhưng không che được gì.
- **Nay** trình duyệt chỉ nhận refresh token qua `Set-Cookie` HttpOnly. JavaScript không bao giờ thấy nó.
- **Access token** vẫn giữ nguyên:
  - chỉ nằm trong bộ nhớ;
  - TTL theo `JWT_TTL`, mặc định 60 phút;
  - bị vô hiệu khi logout (blacklist bật).

## 2. Hợp đồng theo endpoint (kênh cookie, mặc định)

| Endpoint | Request | Response thành công | Từ chối |
|---|---|---|---|
| `POST /api/v1/auth/login` | `{email, password}`, kèm `Origin` của FE | `data.access_token`, `token_type`, `expires_in`, `user`. **Không có** `refresh_token`. `Set-Cookie` refresh HttpOnly. | Origin lạ: `403 ORIGIN_NOT_ALLOWED`, trả **trước** khi kiểm mật khẩu (chống login CSRF). |
| Login không có `Origin`/`Referer` (client dòng lệnh) | như trên | Có access token. **Không** có refresh token nào (không cookie, không JSON). | — |
| `POST /api/v1/auth/mfa/verify` | `{mfa_token, code}` | Giống login, kèm `user` | Như login |
| `POST /api/v1/auth/refresh` | Body rỗng `{}`. Gửi kèm cookie (`withCredentials`) và `Origin` của FE. | Access token mới và `user`. `Set-Cookie` mang refresh token **mới** (xoay vòng). | Thiếu Origin/Referer tin cậy: `403`, phiên **không** bị kết thúc. `refresh_token` trong body: `422 REFRESH_TOKEN_TRANSPORT_MISMATCH`. Token hết hạn, bị thu hồi hoặc replay: `401`. |
| `POST /api/v1/auth/logout` | Body rỗng, `Authorization: Bearer`, cookie, `Origin` | `200`. Cookie bị xoá. Refresh token bị thu hồi, access token vào blacklist. | Như refresh |
| `POST /api/v1/auth/change-password` | như cũ | `200`. Mọi refresh token của tài khoản bị thu hồi, cookie bị xoá. | — |

**Header `X-WMS-Token-Transport`:**

- Chỉ nhận `cookie` hoặc `body`. Giá trị khác trả `422 TOKEN_TRANSPORT_INVALID`.
- Xin kênh `body` khi kênh này không được bật trả `403 TOKEN_TRANSPORT_NOT_ALLOWED`.

### Thuộc tính cookie

| Thuộc tính | Giá trị | Biến môi trường |
|---|---|---|
| Tên | `wms_refresh_token` | `REFRESH_TOKEN_COOKIE_NAME` |
| Path | `/api/v1/auth` (hẹp: chỉ gửi tới nhóm auth) | `REFRESH_TOKEN_COOKIE_PATH` |
| HttpOnly | luôn bật | — |
| Secure | **bắt buộc** ngoài `local`/`testing`, và bắt buộc khi `SameSite=None` | `REFRESH_TOKEN_COOKIE_SECURE` (chỉ bật thêm được, không tắt được trên production) |
| SameSite | `lax` mặc định, nhận `strict` | `REFRESH_TOKEN_COOKIE_SAME_SITE` |
| Domain | không đặt (host-only) | `REFRESH_TOKEN_COOKIE_DOMAIN` |
| Hạn | 14 ngày | `REFRESH_TOKEN_TTL_MINUTES` |

## 3. Phân tích CSRF

1. **Cookie chỉ tới `/api/v1/auth/*`.** Không endpoint nghiệp vụ nào xác thực bằng cookie: API nghiệp vụ đòi `Authorization: Bearer`, mà trang lạ không đọc được access token. Vì vậy CSRF lên API nghiệp vụ không có đường vào.
2. **Endpoint dùng cookie** (`refresh`, `logout`) chỉ chấp nhận request có `Origin` (hoặc `Referer`, khi trình duyệt không gửi `Origin`) nằm trong `cors.allowed_origins` hoặc trùng origin của `APP_URL`.
   - Kẻ tấn công không cưỡng ép được việc xoay vòng hay replay để đá phiên.
   - Request bị chặn không chạm tới token, nên phiên thật vẫn sống. Điều này có kiểm tra ở runtime probe.
3. **`SameSite=Lax`** là lớp thứ hai: trình duyệt không gửi cookie trong POST cross-site.
4. **Login CSRF** (ép nạn nhân đăng nhập vào tài khoản của kẻ tấn công) bị chặn: login mang Origin lạ trả 403 trước khi kiểm mật khẩu.
5. **CORS là allowlist chính xác.**
   - `CORS_ALLOWED_ORIGINS` là danh sách origin đầy đủ, không wildcard, và `allowed_origins_patterns` rỗng.
   - `supports_credentials=true` chỉ có ý nghĩa với các origin trong danh sách.
   - Wildcard (`*`) bị loại khỏi danh sách origin tin cậy của refresh.
   - Production **phải** đặt `CORS_ALLOWED_ORIGINS` đúng origin FE thật. Để trống thì không origin nào được tin (fail-closed).

## 4. Phân tích XSS

- Refresh token không có mặt trong JavaScript. XSS vẫn gọi API được trong lúc trang còn mở, nhưng không lấy được credential dài hạn mang đi.
- Access token chỉ nằm trong bộ nhớ và sống ngắn. FE không ghi token vào `localStorage`/`sessionStorage`. Có test quét tĩnh toàn `src/` (`src/test/sec-p1-refresh-token-out-of-js.test.ts`).
- Sink nguy hiểm: grep `src/` của FE (trừ test) không thấy `dangerouslySetInnerHTML`, gán `innerHTML`/`outerHTML`, `insertAdjacentHTML`, `document.write`, `eval` hay `new Function`.
- **CSP là lớp chặn script lạ trên chính origin giữ phiên.** Cấu hình mẫu `deploy/nginx/frontend.conf.example` của FE đạt cổng header: đã đo PASS trên nginx dựng từ đúng file đó. **Production hiện không phát CSP hay header bảo mật nào** (32 vi phạm, đo 10/09/2026). Đây là blocker hạ tầng của P2.

## 5. Xoay vòng, replay, đồng thời

- Mỗi refresh thu hồi token cũ và phát token mới trong một transaction có khoá hàng.
- **Replay** (dùng lại token đã xoay) thu hồi **cả họ token**: token mới nhất cũng chết. Kẻ trộm token và nạn nhân không thể cùng dùng một họ token.
- **Nhiều refresh song song cùng một token:** tối đa một request thành công. Các request còn lại là replay nên thu hồi cả họ. Tức là client **không được** refresh song song:
  - FE gộp mọi refresh trong một tab thành một request (single-flight);
  - FE xếp hàng refresh giữa các tab bằng Web Locks (`wms-auth-refresh`).
- **Đổi mật khẩu, đặt lại mật khẩu, logout, khoá tài khoản:** refresh token bị thu hồi.

## 6. Thứ tự triển khai và tương thích

| FE \ BE | BE cũ (trước `e5dbe0d`) | BE mới |
|---|---|---|
| **FE cũ** | như trước | **Hỏng refresh.** FE cũ gửi `refresh_token` trong body, bị trả 422, và người dùng bị đăng xuất khi access token hết hạn. |
| **FE mới** (`266f340`+) | **Chạy được.** BE cũ đọc cookie HttpOnly khi body không có token (`PersistRefreshTokenCookie` cũ), và FE mới bỏ qua `refresh_token` còn sót trong JSON. | Đúng hợp đồng |

Vì vậy thứ tự bắt buộc là **FE trước, BE sau**. Sau khi deploy FE, chờ các tab cũ tải lại (service worker `no-cache` cho `index.html`) rồi mới deploy BE.

**Rollback:**

- Rollback BE về bản cũ vẫn tương thích với FE mới.
- **Không** rollback FE về bản cũ khi BE đã là bản mới.

## 7. Client không phải trình duyệt (Mini App, tích hợp)

- Kênh body **tắt mặc định**. Bật bằng `REFRESH_TOKEN_BODY_TRANSPORT_ENABLED=true`.
- Client phải gửi `X-WMS-Token-Transport: body`. Khi đó refresh token nằm trong JSON (`data.refresh_token`), refresh và logout đọc `refresh_token` trong body, và kênh này **không bao giờ** đọc hay đặt cookie.
- Request mang `Origin` chỉ dùng được kênh body khi origin thuộc `REFRESH_TOKEN_BODY_TRANSPORT_ORIGINS`.
  - **Không bao giờ** đưa origin web FE WMS vào danh sách này, nếu không XSS trên web lại đọc được refresh token.
  - Ứng dụng native không gửi `Origin` nên không cần danh sách.
- **Việc cần làm cho Mini App:** client Mini App không có trong repo này. Trước khi bật BE mới trên môi trường Mini App đang dùng, đội Mini App phải xác nhận cách refresh hiện tại:
  - nếu Mini App gửi `refresh_token` trong body: bật kênh body và thêm header;
  - nếu Mini App chạy trong webview có `Origin`: thêm đúng origin đó vào `REFRESH_TOKEN_BODY_TRANSPORT_ORIGINS`.

## 8. Bằng chứng

- **Feature test BE** (kernel HTTP, CSDL thật, không mock): `tests/Feature/WwhnSecRefreshTokenTransportTest.php` kiểm:
  - login cookie-only;
  - login không Origin thì không có refresh;
  - Origin/Referer lạ bị 403;
  - xoay vòng, CSRF 403 mà không kết thúc phiên;
  - body token bị 422;
  - replay thu hồi cả họ, double refresh cho `[200, 401]`;
  - đổi mật khẩu thu hồi token;
  - tài khoản khoá;
  - kênh body tắt, header sai, kênh body bật;
  - Origin trình duyệt không dùng được kênh body;
  - bất biến middleware và bất biến "không controller nào gán refresh_token vào JSON".
  Cùng thuộc nhóm này: `AuthSessionPersistenceTest`, `AuthLoginContractSecurityTest`, `FoundationAuthRbacAuditTest`.
- **Test FE:** `src/test/sec-p1-refresh-token-out-of-js.test.ts` (quét tĩnh và luồng axios thật với adapter tầng mạng), `auth.service.test.ts`, `axios.test.ts` (single-flight, Web Locks).
- **Runtime probe hộp đen:** `scripts/sec-p1-refresh-runtime-probe.php` chạy trên máy chủ thật, gồm cookie, CORS preflight, CSRF, replay, race N request, logout và header API. Script cũng có chế độ `--perf` đo độ trễ refresh.
