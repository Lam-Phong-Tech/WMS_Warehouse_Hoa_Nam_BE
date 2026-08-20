# ADR-AUDIT-002 — Deny-audit policy (audit `result='DENIED'` cho 403 permission/data-scope)

- Ticket: WWHN-165 (BE authz hardening), traceability WWHN-35 / WWHN-166, QA1-AUD-002
- Canonical repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
- Canonical branch: `m1`
- File điều chỉnh:
  - `Modules/AuditLog/app/Services/DenyAuditRecorder.php` (mới)
  - `Modules/Auth/app/Http/Middleware/CheckPermission.php`
  - `Modules/Warehouse/app/Http/Middleware/EnforceInventoryWarehouseScope.php`
  - `Modules/Warehouse/app/Http/Middleware/EnforceInboundWarehouseScope.php`
  - `Modules/Warehouse/app/Http/Middleware/EnforceOutboundWarehouseScope.php`
  - `Modules/Warehouse/app/Http/Middleware/EnforceReversalWarehouseScope.php`
  - `Modules/Warehouse/app/Http/Middleware/EnforceComponentIssueWarehouseScope.php`
- Trạng thái: `ACCEPTED`

## Bối cảnh

ERD §7 định nghĩa `audit_logs.result CHECK (result IN ('SUCCESS','FAILED','DENIED'))` —
schema đã chừa chỗ cho "denied action audit", và QA1-AUD-002 yêu cầu tường minh
"denied action audit theo policy". Nhưng trước ADR này, điểm phát `result='DENIED'`
DUY NHẤT trong toàn hệ thống là `LOGIN_DENIED` (`AuthController::login`, tài khoản
INACTIVE/LOCKED). Mọi 403 khác đều câm lặng về audit:

- `CheckPermission` (alias `permission:<key>`) trả 403 `ACCESS_DENIED` không audit.
- 5 middleware data-scope (`EnforceInventoryWarehouseScope` — 403 `ACCESS_DENIED`,
  và `EnforceInbound/Outbound/Reversal/ComponentIssueWarehouseScope` — 403
  `DATA_SCOPE_DENIED`) cũng không audit.

Hệ quả: một chiến dịch dò quyền (user hợp lệ thử lần lượt các endpoint ngoài quyền/
ngoài kho của mình) không để lại bất kỳ dấu vết nào trong audit trail — vi phạm
NFR-SRS-004 (auditability) dù response HTTP đã fail-closed đúng.

## Quyết định

1. **Điểm phát tập trung**: mọi deny-audit đi qua
   `Modules\AuditLog\Services\DenyAuditRecorder` (không rải `AuditLogger::record`
   tay ở từng middleware). Hai action mới:
   - `AUTHZ_PERMISSION_DENIED` — 403 thiếu function permission (`CheckPermission`).
   - `AUTHZ_DATA_SCOPE_DENIED` — 403 dữ liệu ngoài warehouse data-scope (5 middleware
     Enforce*WarehouseScope).
2. **Policy ghi = BEST_EFFORT** cho riêng call-site deny-audit: toàn bộ đường ghi
   bọc try/catch nuốt `Throwable`; lỗi ghi chỉ `Log::critical('DENY_AUDIT_WRITE_FAILED')`.
   Response 403 tuyệt đối không bị chặn/đổi bởi audit availability, và không dùng
   chung transaction với bất kỳ nghiệp vụ nào.
3. **Không đổi vocabulary lỗi**: giữ nguyên `ACCESS_DENIED` (permission +
   EnforceInventoryWarehouseScope theo WWHN-211) vs `DATA_SCOPE_DENIED` (4 middleware
   scope còn lại) đúng như hợp đồng đã chốt ở WWHN-267 §8.2. `error_code` trong audit
   row ghi đúng mã mà middleware phát ra.
4. **Nội dung ghi**: `request_id` (từ `ApiConventionMiddleware`/X-Request-ID, khớp
   header response), `actor_id` (user đã xác thực), `action`, `object_type`/`object_id`
   suy từ route nếu suy được (param `id`/`documentId`, segment tài nguyên trên path),
   `after_data` = metadata {route, method, permission thiếu hoặc scope reason,
   error_code}, `result='DENIED'`, `error_code`. KHÔNG ghi PII/token thô —
   `AuditLogger::payload()` đã sanitize theo `config('audit.sensitive_keys')` và
   metadata không bao giờ chứa header Authorization hay body request.

## Vì sao BEST_EFFORT (khác mặc định `outbox_required` của ADR-AUDIT-001)

- **403 đã là kết cục fail-closed an toàn**, độc lập với audit availability. Nếu
  deny-audit fail-closed thì lỗi hạ tầng audit sẽ biến 403 thành 500 — tức là kẻ dò
  quyền nhận thêm thông tin (audit đang chết), còn user hợp lệ bị chặn oan trên các
  path GET chỉ-đọc.
- **Deny không có business side-effect**: không movement, không đổi version/status —
  không tồn tại "trạng thái nghiệp vụ mất traceability" mà `outbox_required`/
  `fail_closed` của ADR-AUDIT-001 được sinh ra để bảo vệ. Bản thân `AuditLogger`
  bên trong vẫn thử outbox fallback trước khi DenyAuditRecorder nuốt lỗi.
- ERD `result='DENIED'` + NFR-SRS-004: giá trị của deny-audit là phát hiện xu hướng
  (nhiều DENIED liên tiếp của cùng actor), không phải chứng cứ pháp lý cho một giao
  dịch — mất một row khi hạ tầng audit chết (đã có `Log::critical` để alert) là
  trade-off chấp nhận được.

## Phạm vi — cái gì KHÔNG ghi

- **401 anonymous KHÔNG ghi audit**: không có actor đã xác thực nên `actor_id` sẽ
  null và row không quy trách nhiệm được cho ai; kẻ quét ẩn danh sẽ dùng chính
  endpoint làm máy bơm rác vào audit trail (unauthenticated write amplification).
  Chống dò ẩn danh đã có tầng rate-limit (SEC289-02, `RATE_LIMIT_EXCEEDED` + audit
  `LOGIN_THROTTLED` cho login). 401 do token hết hạn/thiếu cũng vậy.
- 404/422/409/412/428: không phải "denied action" theo QA1-AUD-002 — ngoài phạm vi.
- `LOGIN_DENIED` hiện hữu (AuthController) giữ nguyên, không gộp vào recorder này.

## Traceability

- WWHN-165 (BE authz gaps), WWHN-166 (FE counterpart), WWHN-35 (epic RBAC/audit).
- QA1-AUD-002 "denied action audit theo policy" — policy chính là tài liệu này.
- ERD §7 `audit_logs.result` enum; NFR-SRS-004 auditability; SRS §8.1/§8.2.

## Bằng chứng đóng

- Code: `DenyAuditRecorder` + 6 call-site nêu trên.
- Test: `tests/Feature/Wwhn165AuthzDenyAuditProofTest.php` — 403 permission deny và
  data-scope deny đều tăng đúng 1 row `result='DENIED'` với `request_id` khớp header;
  bảng nghiệp vụ không đổi; audit hỏng cưỡng bức vẫn trả 403 nguyên trạng
  (best-effort proof); 401 anonymous không thêm row; chạy cả sqlite lẫn pgsql.
