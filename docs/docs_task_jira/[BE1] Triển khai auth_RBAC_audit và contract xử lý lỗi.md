# [BE1] Triển khai auth/RBAC/audit và contract xử lý lỗi

- Jira: WWHN-35, WWHN-166, WWHN-167
- Repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
- Base: `m1`
- Scoped branch: `fix/wwhn-auth-rbac-audit-hardening`
- Pull request: `#1`
- Evidence SHA đã pass gate: `aa75b3ec22f7705e7c339e325e14899a4ffaf63d`
- CI run: `30783838510` — **SUCCESS**
- Artifact: `8844568934`
- Artifact name: `auth-rbac-audit-contract-53365c76137e8858a4725614f4e8724cd9040cd9`
- Artifact digest: `sha256:2fcccecefa1bb13ca25426f4483be14004f75c15071084a20faef17b7798c6a6`
- Cập nhật: 2026-08-03

> Không lưu credential dùng được trong tài liệu, source, Postman collection hoặc evidence. `APPROVED` chỉ do reviewer có thẩm quyền xác nhận trên PR/Jira.

## 1. Exact Fix Action Register

| ID | Sev | Trạng thái kỹ thuật | Kết quả thực hiện | Gate còn phải có từ người phụ trách |
|---|---|---|---|---|
| FIX-01 | P1 | **CI PASS / Ready for QA1** | Test token invalid/expired, refresh replay, RBAC, audit redaction/failure, negative API, Kho A/Kho B. Full suite pass **93 tests / 856 assertions** trên SHA evidence. | QA1 chạy lại staging/current build, đính kèm command log, SHA và DB evidence; BA/Lead ký Done/Redflag. |
| FIX-02 | P1 | **Parent Ready** | Backend đã trả role, permission, `scope_ref`, `warehouse_scope_ids`; 401/403/logout/session contract đã khóa bằng automated tests. | QA2/FE1 chạy browser thật: login, expiry, logout, direct URL, 403, menu role và viewport; đính kèm video/screenshots/build SHA. |
| FIX-03 | P1 | **Contract generated/linted** | Login canonical dùng `email`; loại password mẫu thật; server lấy từ `APP_URL`/`L5_SWAGGER_CONST_HOST`; CI regenerate JSON/YAML và Redocly lint pass. | Tech Lead/FE/QA duyệt generated artifact. |
| FIX-04 | P1 | **Collection validated** | Postman có login/refresh/logout/me; rotate/replay 401; invalid credential 401; missing refresh token 422; không tự ghi APPROVED. | QA1 import collection và chạy Newman trên staging bằng secret environment riêng. |
| FIX-05 | P1 | **PR + CI PASS** | Scoped PR #1; exact SHA/run/artifact đã ghi trên đầu tài liệu. Syntax, scoped Pint, full PHPUnit, Swagger, OpenAPI lint, Postman validation, npm lint/test/build đều pass. | Tech Lead/QA1 review và approval thật trước merge. |
| FIX-06 | P1 | **Implemented/tested** | `scope_ref` từ active role assignment; list filter scope; detail/mutation ngoài scope trả `403 ACCESS_DENIED`; test Kho A/Kho B pass. | QA1/Security review policy/query filter và DB evidence staging. |
| FIX-07 | P1 | **Implemented/tested** | Policy `outbox_required`; audit primary failure ghi `audit_outbox`; nested secret redaction; lỗi cả audit/outbox thì fail-closed; có `audit:reconcile`, retry/backoff và error/critical logs. | Tech Lead/Security duyệt policy; DevOps cấu hình alert/monitoring và lịch reconcile. |
| FIX-08 | P1 | **Repository sanitized** | Không còn credential trong tài liệu/Postman variables; audit redaction chặn password/token/authorization/cookie/session/secret/API key. | DevOps/Security rotate/revoke account từng chia sẻ nếu có và lưu rotation record ngoài repository. |
| FIX-09 | P2 | **Implemented** | Evidence đồng bộ key runtime: `audit_log.view`, `warehouse.view`, `warehouse.manage`, `stock_document.*`, `inventory.view`, `report.view`. | BA/QA1 xác nhận traceability version. |
| FIX-10 | P2 | **Implemented** | Xóa toàn bộ tracked `.npm-cache` bằng commit `56584b38a29068a9c05ca16a21c5ac901b97a6c5`; `.gitignore` chặn tái phát. | Tech Lead xác nhận clean PR diff. |

## 2. Auth và token lifecycle

| Method | Endpoint | Contract |
|---|---|---|
| POST | `/api/v1/auth/login` | Bắt buộc `email`, `password`; trả access token, refresh token, user, roles, permissions, warehouse scopes. |
| POST | `/api/v1/auth/refresh` | Rotate một lần. Replay token cũ trả 401 và revoke toàn bộ active refresh-token family của user. |
| POST | `/api/v1/auth/logout` | Revoke refresh token được gửi hoặc toàn bộ refresh token của user; invalidate access token hiện tại. |
| GET | `/api/v1/auth/me` | Trả profile, active roles theo effective window, permissions, `scope_ref`, `warehouse_scope_ids`. |

Error baseline: `400 MALFORMED_JSON`, `401 UNAUTHENTICATED`, `403 ACCESS_DENIED`, `404 RESOURCE_NOT_FOUND`, `405 METHOD_NOT_ALLOWED`, `409 STATE_CONFLICT/IDEMPOTENCY_ERROR`, `422 VALIDATION_ERROR/BUSINESS_RULE_ERROR`, `500 INTERNAL_SERVER_ERROR`. Response có `X-Request-ID` và `meta.request_id` để trace audit.

## 3. Data-scope policy

- `SUPER_ADMIN`: unrestricted.
- Active role có `scope_ref=<warehouse UUID>`: chỉ được truy cập các kho đã gán.
- List: filter records theo scope.
- Detail/mutation ngoài scope: trả `403 ACCESS_DENIED` trước khi đọc hoặc cập nhật dữ liệu.
- Active assignment phải thỏa role ACTIVE, `effective_from <= now`, `effective_to > now` hoặc mốc null.
- FE có thể dùng scope payload để dựng menu/context; backend vẫn là enforcement source of truth.

## 4. Audit reliability policy

1. Sanitize payload đệ quy trước khi ghi.
2. Ghi `audit_log` thành công: hoàn tất.
3. Ghi chính lỗi: phát `AUDIT_WRITE_FAILED`, ghi payload đã sanitize vào `audit_outbox`.
4. Cả audit và outbox lỗi: phát `AUDIT_OUTBOX_WRITE_FAILED` mức critical và fail request.
5. `php artisan audit:reconcile` replay theo batch, tăng attempts và exponential backoff.
6. Có thể dùng `AUDIT_WRITE_POLICY=fail_closed` cho mutation cực nhạy cảm.

Sensitive keys gồm password/password hash, current/new password, access/refresh token, Authorization, cookie, session, secret, API/private key; giá trị được thay bằng `[REDACTED]`.

## 5. Evidence kỹ thuật

| Hạng mục | File/evidence |
|---|---|
| Audit policy | `config/audit.php` |
| Audit outbox | `Modules/AuditLog/database/migrations/2026_08_03_000001_create_audit_outbox_table.php`; `Modules/AuditLog/app/Models/AuditOutbox.php` |
| Audit writer/reconcile | `Modules/AuditLog/app/Services/AuditLogger.php`; `app/Console/Commands/ReconcileAuditOutbox.php` |
| Data scope | `Modules/Auth/app/Models/User.php`; `Modules/Warehouse/app/Http/Controllers/WarehouseController.php` |
| Auth lifecycle | `Modules/Auth/app/Http/Controllers/AuthController.php` |
| Security/regression test | `tests/Feature/AuthRbacAuditHardeningTest.php`; `tests/Feature/SecurityRbacAuditNegativeTest.php` |
| Postman | `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` |
| CI | `.github/workflows/auth-rbac-audit-contract.yml` |
| PR | `#1`, base `m1`, head `fix/wwhn-auth-rbac-audit-hardening` |
| CI pass | Run `30783838510`, SHA `aa75b3ec22f7705e7c339e325e14899a4ffaf63d` |
| Generated contract artifact | ID `8844568934`, digest `sha256:2fcccecefa1bb13ca25426f4483be14004f75c15071084a20faef17b7798c6a6` |

CI gate đã pass:

```text
PHP 8.3 syntax
Pint --test trên các PHP file thay đổi
93 PHPUnit tests / 856 assertions
L5-Swagger JSON/YAML generation
Redocly OpenAPI lint
Postman JSON/non-empty validation
npm lint
npm test
npm build
artifact upload theo exact SHA
```

## 6. Closure rule

Không đánh dấu 100% Done hoặc merge chỉ dựa vào tài liệu này. Closure cần đủ:

1. CI xanh trên final PR head SHA.
2. Tech Lead review code/data-scope/audit policy.
3. QA1 report + command log + DB evidence trên cùng SHA/build.
4. QA2 browser evidence cho WWHN-167.
5. Security/DevOps xác nhận credential rotation nếu từng có credential được chia sẻ và cấu hình audit monitoring.
6. FE/QA/Tech Lead duyệt generated OpenAPI/Postman artifact.

Các đầu việc code, automated test, contract, CI và repository hygiene đã hoàn tất. Approval, staging/browser evidence và rotation record là external gate, không được BE tự xác nhận thay.