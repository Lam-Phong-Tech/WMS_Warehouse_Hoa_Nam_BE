# [BE1] Triển khai auth/RBAC/audit và contract xử lý lỗi

- Jira: WWHN-35, WWHN-166, WWHN-167
- Repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
- Base branch: `m1`
- Scoped branch: `fix/wwhn-auth-rbac-audit-hardening`
- Owner thực thi code: BE1
- Review bắt buộc: Tech Lead, QA1, Security; QA2/FE1 cho browser evidence
- Cập nhật: 2026-08-03

> Tài liệu này không chứa username, password, access token, refresh token, API key hoặc credential dùng được. Trạng thái `APPROVED` chỉ được ghi bởi reviewer có thẩm quyền trên PR/Jira.

## 1. Kết quả xử lý theo Exact Fix Action Register

| ID | Sev | Trạng thái BE | Thay đổi đã thực hiện | Evidence/Gate còn cần |
|---|---|---|---|---|
| FIX-01 | P1 | Implemented, chờ CI/QA1 sign-off | Bổ sung test 401 token hết hạn, refresh replay, RBAC, audit redaction/failure, malformed JSON, 422 và data scope. Replay token cũ thu hồi toàn bộ refresh-token family và ghi `TOKEN_REFRESH_FAILED`. | QA1 chạy lại trên current PR SHA, lưu command log, DB evidence và ký Done/Redflag. |
| FIX-02 | P1 | Parent Ready | Backend contract đã khóa cho login, session `/auth/me`, logout, 403 và permission/menu payload; `warehouse_scope_ids` và `scope_ref` có trong response. | QA2 phải chạy browser thật cho login/session expiry/logout/direct URL/403/menu role và viewport; BE không tự ghi pass browser. |
| FIX-03 | P1 | Implemented, chờ artifact CI | Source annotation login dùng `email`; loại credential mẫu; server được cấu hình qua `APP_URL`/`L5_SWAGGER_CONST_HOST`; CI regenerate JSON/YAML và upload artifact theo SHA. | Tech Lead/FE/QA review generated artifact và diff trước Contract gate. |
| FIX-04 | P1 | Implemented | Postman collection không còn rỗng; có login/refresh/logout/me, token rotation, replay 401, invalid credential 401, missing refresh token 422. Collection không tự ghi `APPROVED`. | CI parse collection; QA1 import/run Newman trên môi trường được cấp credential qua secret/environment riêng. |
| FIX-05 | P1 | Implemented, chờ CI | Tạo scoped branch/PR flow; workflow ghi nhận exact SHA, chạy syntax, Pint, full PHPUnit, Swagger generation, Redocly lint, Postman validation, npm lint/test/build và upload contract artifact. | PR review + CI run xanh; ghi PR number, run ID, artifact ID vào phần Evidence sau khi có. |
| FIX-06 | P1 | Implemented | `User::warehouseScopeIds()` lấy scope từ active role assignment; Warehouse list filter scope; detail/update ngoài scope trả 403. Test thật Kho A/Kho B bao phủ list/detail/mutation. | QA1/Security review policy và DB evidence trên current SHA. |
| FIX-07 | P1 | Implemented | Chính sách mặc định `outbox_required`; audit lỗi không còn bị nuốt. Primary write lỗi sẽ redaction + ghi `audit_outbox` + error alert. Nếu cả audit và outbox lỗi, request fail-closed. Có `audit:reconcile`, retry và exponential backoff. | Tech Lead/Security duyệt policy; vận hành cấu hình alert/monitoring production. |
| FIX-08 | P1 | Repository sanitized; rotation external pending | Xóa credential plaintext khỏi hồ sơ này và không dùng credential trong Postman variables. Audit redaction chặn password/token/secret ở mọi cấp nested. | DevOps/Security xác nhận rotate/revoke các tài khoản từng chia sẻ bên ngoài repository, nếu có; đính kèm rotation record trên Jira, không commit secret. |
| FIX-09 | P2 | Implemented | Permission evidence đồng bộ đúng key runtime: `audit_log.view`, `warehouse.view`, `warehouse.manage`, `stock_document.*`, `inventory.view`, `report.view`. | BA/QA1 xác nhận traceability version hiện hành. |
| FIX-10 | P2 | Implemented | Xóa toàn bộ tracked `.npm-cache` bằng Git tree commit; `/.npm-cache` đã có trong `.gitignore`; CI dùng cache của runner thay vì commit cache. | Tech Lead xác nhận clean PR diff. |

## 2. Thiết kế Auth và token lifecycle

### Endpoints canonical

| Method | Endpoint | Auth | Contract chính |
|---|---|---|---|
| POST | `/api/v1/auth/login` | Public | Request bắt buộc `email`, `password`; trả access token, refresh token, user, active roles, permissions và warehouse scopes. |
| POST | `/api/v1/auth/refresh` | Refresh token | Rotate một lần; token cũ bị revoke. Replay token cũ revoke toàn bộ active refresh token của user. |
| POST | `/api/v1/auth/logout` | Bearer JWT | Nếu gửi refresh token thì revoke token đó; nếu không gửi thì revoke toàn bộ refresh token của user; access token hiện tại bị invalidate. |
| GET | `/api/v1/auth/me` | Bearer JWT | Trả profile, active roles theo effective window, permissions và `warehouse_scope_ids`. |

### Negative/error baseline

| HTTP | `error.code` | Tình huống |
|---|---|---|
| 400 | `MALFORMED_JSON` | JSON request hỏng. |
| 401 | `UNAUTHENTICATED` | Thiếu/sai/hết hạn access token; refresh token invalid/expired/replayed. |
| 403 | `ACCESS_DENIED` | Thiếu permission, account locked, hoặc warehouse ngoài scope. |
| 404 | `RESOURCE_NOT_FOUND` | Resource/path không tồn tại. |
| 405 | `METHOD_NOT_ALLOWED` | Sai HTTP method. |
| 409 | `STATE_CONFLICT` / `IDEMPOTENCY_ERROR` | State transition hoặc idempotency conflict. |
| 422 | `BUSINESS_RULE_ERROR` / `VALIDATION_ERROR` | Validation/business rule fail. |
| 500 | `INTERNAL_SERVER_ERROR` | Lỗi hệ thống không dự kiến; production không trả trace. |

Mọi response API có `X-Request-ID` và `meta.request_id`; audit dùng cùng request ID để trace.

## 3. Data-scope policy

- `SUPER_ADMIN`: unrestricted.
- User có active role assignment với `scope_ref=<warehouse UUID>`: chỉ truy cập các warehouse ID đã gán.
- Warehouse list: chỉ trả records trong scope.
- Warehouse detail: ngoài scope trả `403 ACCESS_DENIED`, không trả dữ liệu Kho B cho user Kho A.
- Warehouse mutation: ngoài scope trả `403 ACCESS_DENIED` trước khi update.
- Active role phải thỏa `role.status=ACTIVE`, `effective_from <= now`, `effective_to > now` hoặc các mốc null.
- `/auth/me` trả `scope_ref` trên từng role và `warehouse_scope_ids` để FE dựng menu/context, nhưng backend vẫn là enforcement source of truth.

## 4. Audit reliability policy

### Policy mặc định: `outbox_required`

1. Payload được sanitize đệ quy trước khi ghi.
2. Ghi `audit_log` thành công: hoàn tất.
3. Ghi `audit_log` lỗi: phát log `AUDIT_WRITE_FAILED`, ghi payload đã sanitize vào `audit_outbox`.
4. Ghi cả `audit_log` và `audit_outbox` lỗi: phát `AUDIT_OUTBOX_WRITE_FAILED` mức critical và fail request.
5. `php artisan audit:reconcile` replay outbox theo batch; lỗi dùng exponential backoff và tăng `attempts`.
6. Có thể cấu hình `AUDIT_WRITE_POLICY=fail_closed` cho mutation cực nhạy cảm.

Sensitive keys được redaction gồm password, password hash, current/new password, access/refresh token, Authorization, cookie, session, secret, API/private key. Giá trị thay bằng `[REDACTED]`.

## 5. Permission matrix hiện hành

| Permission | Runtime use |
|---|---|
| `warehouse.view` | GET warehouse list/detail; document-status transition reference. |
| `warehouse.manage` | PATCH warehouse; snapshot generation; transition configuration. |
| `audit_log.view` | GET `/api/v1/audit-logs`. |
| `product.view`, `product.manage` | Product read/mutation. |
| `sku.manage`, `master.manage` | SKU/import và master data. |
| `stock_document.view/create/submit/approve/post/scan/cancel/reverse` | Full stock-document lifecycle. |
| `inventory.view` | Balance, movements, reservation/snapshot/reference inventory APIs. |
| `report.view` | Inventory reports/reconciliation. |
| `stock_request.*`, `transfer.*` | Stock request và transfer lifecycle. |

Không dùng các key cũ/sai như `audit-log.view`, `master-data.warehouse.view` hoặc `warehouse.stock-document.view` trong evidence runtime.

## 6. Test và quality gate

### Focused security suite

```bash
php artisan test tests/Feature/AuthRbacAuditHardeningTest.php --do-not-cache-result
php artisan test tests/Feature/SecurityRbacAuditNegativeTest.php --do-not-cache-result
```

Coverage mới:

- Expired access token -> 401 chuẩn.
- Refresh rotate thành công; replay token cũ -> 401; token family mới cũng bị revoke.
- `TOKEN_REFRESH_FAILED` có audit nhưng không chứa raw token.
- Kho A list/detail/update thành công; Kho B bị deny detail và mutation.
- Nested secrets bị `[REDACTED]`.
- Primary audit storage fail -> payload tồn tại ở outbox, không mất âm thầm.
- Malformed JSON -> 400; refresh thiếu token -> 422.

### CI gate

Workflow: `.github/workflows/auth-rbac-audit-contract.yml`

```text
PHP 8.3 syntax
Pint --test
Full php artisan test
l5-swagger:generate
Redocly OpenAPI lint
Postman JSON/non-empty validation
npm lint
npm test
npm build
Upload generated JSON/YAML/Postman artifact keyed by github.sha
```

## 7. OpenAPI/Postman contract

- OpenAPI source annotations là source of truth.
- Login field canonical: `email`.
- Mẫu tài liệu chỉ dùng placeholder không dùng được: `user@example.com`, `your-password`.
- Server URL lấy từ environment, không hard-code staging/production credential.
- CI regenerate JSON/YAML từ current attributes.
- Postman variables `email`, `password`, token để trống; credential được QA inject qua environment/secret.
- Reviewer approval nằm trên PR/Jira; collection không tự tuyên bố approved.

## 8. Evidence register

| Evidence | Location/status |
|---|---|
| Scoped branch | `fix/wwhn-auth-rbac-audit-hardening` |
| Current implementation baseline before final docs commit | `56584b38a29068a9c05ca16a21c5ac901b97a6c5` |
| Audit policy | `config/audit.php` |
| Audit outbox migration/model | `Modules/AuditLog/database/migrations/2026_08_03_000001_create_audit_outbox_table.php`, `Modules/AuditLog/app/Models/AuditOutbox.php` |
| Audit writer/reconcile | `Modules/AuditLog/app/Services/AuditLogger.php`, `app/Console/Commands/ReconcileAuditOutbox.php` |
| Data scope | `Modules/Auth/app/Models/User.php`, `Modules/Warehouse/app/Http/Controllers/WarehouseController.php` |
| Auth lifecycle | `Modules/Auth/app/Http/Controllers/AuthController.php` |
| Automated test | `tests/Feature/AuthRbacAuditHardeningTest.php` |
| Postman | `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` |
| CI | `.github/workflows/auth-rbac-audit-contract.yml` |
| Cache cleanup | Git commit `56584b38a29068a9c05ca16a21c5ac901b97a6c5` |
| PR/CI run/artifact | Điền sau khi PR được tạo và workflow chạy. |
| QA1 report/DB command evidence | Chờ QA1 thực thi trên PR SHA. |
| QA2 browser video/screenshots | Chờ QA2 thực thi trên build từ PR SHA. |
| Credential rotation record | Chờ DevOps/Security xác nhận ngoài repository. |

## 9. Closure rule

BE implementation chỉ được đánh dấu Done khi:

1. CI xanh trên exact PR head SHA.
2. Tech Lead review code/data-scope/audit policy.
3. QA1 đính kèm command log, test report và DB evidence trên cùng SHA.
4. QA2 đính kèm browser evidence cho WWHN-167 trên build cùng SHA.
5. Security xác nhận không còn plaintext credential và hoàn tất rotation nếu credential từng được chia sẻ.
6. Generated OpenAPI/Postman artifact được FE/QA/Tech Lead duyệt.

Không sử dụng tài liệu này để thay thế approval thực tế.
