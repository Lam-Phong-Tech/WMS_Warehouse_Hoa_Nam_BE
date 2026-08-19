# WWHN-222 — Event/Log Catalog v1.1 (DEV222-P1-03, cập nhật R2)

| Trường | Giá trị |
| --- | --- |
| Task | WWHN-222 — Operational logging / request tracing / audit trail |
| Phạm vi | Backend `WMS_Warehouse_Hoa_Nam_BE`, nhánh `m1` |
| Ngày chốt | 2026-08-19 (v1.1 cùng ngày, sau review R2 75/100) |
| Phiên bản | v1.1 — đổi vocabulary `result` về đúng enum ERD (xem §2a) |

## 1. Nguồn sự thật

Hai lớp ghi log/sự kiện độc lập, cùng khoá đối chiếu `request_id`:

1. **`audit_log`** (append-only, `Modules\AuditLog\Models\AuditLog`) — sự kiện nghiệp vụ có actor rõ ràng (login, CRUD, post, cancel...). Writer duy nhất: `Modules\AuditLog\Services\AuditLogger::record()`.
2. **Monolog structured log** (`storage/logs/laravel.log`, kênh `stack`) — mọi log framework/exception. `Illuminate\Support\Facades\Context` (Laravel 11+ Context) tự động gắn context vào **mọi** dòng log, gồm cả log do Laravel tự phát khi `report()` một exception chưa bắt — không cần processor thủ công.

## 2. Field bắt buộc của một event (sau WWHN-222)

| Field | audit_log column | Bắt buộc | Ghi chú |
| --- | --- | --- | --- |
| request_id | `request_id` | Có (auto-fallback) | `ApiConventionMiddleware` sinh/validate `X-Request-ID`, set vào `request->attributes` + `Context::add()`. `AuditLogger::payload()` tự fallback lấy từ request hiện tại/Context nếu caller truyền `null` — đóng gap "caller quên truyền thì request_id null" đã ghi nhận ở review 61/100. |
| actor | `actor_id` | Có (nullable khi anonymous, vd LOGIN_FAILED trước khi xác định user) | FK tới `app_user.user_id` |
| action/event | `action` | Có | Chuỗi tự do viết hoa, vd `LOGIN`, `LOGIN_FAILED`, `POST_ISSUE`, `TOKEN_REFRESH_FAILED` |
| object/reference | `object_type` + `object_id` | Có | Polymorphic reference, không FK cứng |
| **result** | `result` (mới, WWHN-222) | Khuyến nghị, bắt buộc ở event có failure semantics | `SUCCESS` \| `FAILED` \| `DENIED` — đúng enum ERD §7 `audit_logs.result CHECK SUCCESS/FAILED/DENIED` (xem §2a). Cột nullable trên view/sqlite — entry lịch sử trước WWHN-222 giữ `null` (không suy diễn ngược, tránh gán sai). Đã bổ sung tường minh cho toàn bộ audit call site trong `AuthController.php` — case mẫu đầy đủ cho closure evidence. |
| **error_code** | `error_code` (mới, WWHN-222) | Khi `result=FAILED`/`DENIED` | Khớp đúng `error.code` đã trả về ở response envelope (§8.2 error contract), vd `UNAUTHENTICATED`, `ACCESS_DENIED`, `RATE_LIMIT_EXCEEDED` |
| **reason** | `reason` (view `audit_log`; vật lý PG: `metadata->>'legacy_reason'`) | Theo NFR-SRS-004 khi nghiệp vụ có reason | NFR-SRS-004 đòi "Actor/time/reason/request_id/result/before-after". `AuditLogger::record()` nhận `$reason`; trên PostgreSQL view map vào `metadata` (ERD `audit_logs` không có cột reason riêng — đúng thiết kế "error/context đi vào metadata"). |
| timestamp | `created_at` | Có | UTC ISO8601 |
| scope/idempotency reference | `idempotency_key` (mới, WWHN-222) | Khi applicable | Chỉ điền khi caller biết chắc request đi qua `Idempotency-Key` (không auto-suy đoán từ header để tránh gán nhầm cho action không idempotent-guarded) |

## 2a. Vocabulary `result` (DEC-AUDIT-RESULT-001, chốt ở R2)

- **Nguồn chuẩn:** ERD §7 `audit_logs` — `result varchar(30) No CHECK SUCCESS/FAILED/DENIED`.
  v1.0 của catalog dùng `SUCCESS|FAILURE` là **drift khỏi ERD** (đúng loại drift doc-vs-code
  từng gây P1 lặp lại); v1.1 sửa về đúng enum ERD, không sửa ERD (ERD pin checksum 2 repo).
- **Mapping semantic:** `FAILED` = thao tác thất bại (sai mật khẩu, token hỏng, rate-limit);
  `DENIED` = từ chối truy cập/quyền (403 `ACCESS_DENIED`, tài khoản INACTIVE/LOCKED).
  Auth: `LOGIN_THROTTLED`/`LOGIN_FAILED`/`TOKEN_REFRESH_FAILED` → `FAILED`;
  `LOGIN_DENIED` → `DENIED`.
- **Enforcement (pgsql):** migration `2026_08_19_150000_align_audit_result_vocabulary_with_erd`
  materialize CHECK của ERD dạng `NOT VALID` (chỉ áp bản ghi mới — bảng append-only, dòng
  lịch sử `FAILURE` không được và không cần sửa) + trigger view chuẩn hoá `FAILURE`→`FAILED`
  tại điểm ghi để payload cũ trong `audit_outbox` vẫn replay được qua `audit:reconcile`.
- **sqlite (dev):** không thêm CHECK (đòi rebuild bảng); vocabulary được giữ bởi code +
  test `Wwhn222AuditEventCatalogTest`, PostgreSQL là DB tham chiếu.
- **ERD gap còn lại (BA/PM follow-up, không tự sửa ERD):** cột vật lý `error_code`,
  `idempotency_key` (thêm bởi WWHN-222/WWHN-184 fix) chưa có trong bảng ERD §7 — cần
  ERD-AMD ghi nhận bổ sung additive này (ERD §7 gắn nhãn BASELINE + TECH-PROPOSAL).

## 3. Quy ước rollout (quyết định phạm vi, tránh sửa tràn lan)

- Cột `result`/`error_code`/`idempotency_key` là **additive, nullable**, không đổi chữ ký bắt buộc của 73+ call site `AuditLogger::record()`/`log()` hiện có trong repo — tránh đụng code đang thuộc phạm vi của các task/phiên khác đang chạy song song trên `m1`.
- Đã cập nhật tường minh **toàn bộ** call site trong `Modules\Auth\app\Http\Controllers\AuthController.php` (LOGIN, LOGIN_FAILED, LOGIN_DENIED, LOGIN_THROTTLED, TOKEN_REFRESH, TOKEN_REFRESH_FAILED, LOGOUT, CHANGE_PASSWORD, SWITCH_CONTEXT) — đây là module xác thực, rủi ro thấp nhất và có test coverage sẵn để verify không phá hành vi cũ.
- Các call site khác (Warehouse/Warranty/Import...) **chưa** được gán `result`/`error_code` — giữ `null`, không suy diễn. Rollout tiếp theo (nếu QA/Tech Lead yêu cầu) nên đi theo từng flow, không phải một đợt sửa toàn repo.
- PII redact (`config/audit.php` → `sensitive_keys`) chỉ thêm **key cụ thể đã xác nhận là PII thật trong schema** (`email`, `phone`, `contact_phone`, `customer_name`, `customer_phone`, `customer_address`, `recipient_name`, `recipient_contact_phone`, `recipient_address`) — cố ý **không** thêm key chung chung như `address`/`name` vì `warehouses.address` là dữ liệu nghiệp vụ hợp lệ, redact sẽ làm mất khả năng truy vết audit cho thay đổi địa chỉ kho.

## 4. Bằng chứng chạy (evidence, local — xem `TEST_EVIDENCE.md`)

Chứng minh 3 nhánh bắt buộc của P1-03 cùng một `request_id` xuyên suốt request → response envelope → audit_log/log:

1. **Success** — `POST /api/v1/auth/login` đúng mật khẩu: `audit_log.action=LOGIN`, `result=SUCCESS`, `request_id` khớp `meta.request_id` của response.
2. **Business error** — sai mật khẩu: HTTP 401 `error.code=UNAUTHENTICATED`, `audit_log.action=LOGIN_FAILED`, `result=FAILED`, `error_code=UNAUTHENTICATED`, cùng `request_id`; `email` bị redact `[REDACTED]` trong `after_data`. Biến thể DENIED: tài khoản INACTIVE → HTTP 403, `LOGIN_DENIED`, `result=DENIED`, `error_code=ACCESS_DENIED`.
3. **Server/error-path** — giả lập `audit_log` không ghi được (đổi tên bảng tạm thời): `Log::error('AUDIT_WRITE_FAILED', ['request_id' => ...])` nhận đúng `request_id`, payload outbox có `[REDACTED]`, không lộ raw PII.
4. **Fallback request_id** — gọi `AuditLogger::record()` không truyền `$requestId`: cột `request_id` vẫn lấy đúng từ request hiện tại thay vì `null`.

## 5. Giới hạn đã biết (không tự nhận đã đóng)

- Đây là **bằng chứng chạy local** (sqlite/pgsql theo `phpunit.xml`), **không phải chạy trên UAT thật**. Chạy trên UAT phụ thuộc WWHN-221/DEV222-P1-01 (log sink, environment, monitor path do DevOps pin) — chưa có trong phạm vi sửa của phiên này vì đó là hạ tầng, không phải local code.
- Chưa retrofit `result`/`error_code` cho toàn bộ 73+ call site ngoài `AuthController.php` — xem §3.
- `idempotency_key` mới có cột, chưa có call site nào điền (chưa có nghiệp vụ nào trong scope WWHN-222 vừa sửa đi qua `Idempotency-Key`).
