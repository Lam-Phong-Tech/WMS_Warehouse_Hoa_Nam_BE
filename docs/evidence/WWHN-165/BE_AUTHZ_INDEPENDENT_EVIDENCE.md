# WWHN-165 — BE authz independent evidence (local exact-SHA)

- Jira: WWHN-165 (traceability WWHN-35 / WWHN-166), QA1-AUD-002
- Canonical branch: `m1`
- **BE SHA (commit chứa code fix): `a452e4459fe20aaaf1c23981577b3e0570c13c00`**
  (`fix(WWHN-165): audit DENIED cho 403 permission/data-scope + no-stack-trace
  fail-closed moi env + proof test [skip ci]`)
- Ngày chạy: 2026-08-20 (ICT), máy dev Windows 11 + container Docker PG 16.

## DEC-165-CI-LOCAL-01 — vì sao evidence local thay CI

GitHub Actions quota của org đã cạn (3000/3000 phút). Theo quyết định đã áp cho
WWHN-325/311/292 (DEC-325-CI-LOCAL-01 v.v.), evidence WWHN-165 được chạy **local
tại exact SHA** thay cho CI run: mọi lệnh dưới đây chạy trên working tree có nội
dung code trùng khớp commit `a452e44` (code+test+ADR+catalog đã commit trước khi
đóng pack; openapi/postman của pack lấy từ nội dung git lưu tại chính SHA đó,
không lấy từ working tree). FE sẽ ghi nhận quyết định tương ứng ở phía FE.
Mọi commit đều mang hậu tố `[skip ci]` để không trigger workflow.

## Lệnh đã chạy và kết quả

### sqlite in-memory (driver mặc định, `phpunit.xml`)

| Lệnh | Kết quả |
|---|---|
| `php artisan test tests/Feature/Wwhn165AuthzDenyAuditProofTest.php` | **PASS 7/7** (93 assertions, ~23s) |
| `php artisan test tests/Feature/AuthorizationTest.php tests/Feature/AuthRbacAuditHardeningTest.php tests/Feature/SecurityRbacAuditNegativeTest.php tests/Feature/Wwhn217RuntimeErrorContractTest.php tests/Feature/Wwhn289SecurityRegressionProofTest.php tests/Feature/Wwhn211InventoryScopeTest.php` | **PASS 48/48** (436 assertions) |
| `php artisan test tests/Unit/AuditLoggerWritePolicyTest.php tests/Feature/Wwhn172AuditFailClosedTest.php tests/Feature/Wwhn172SkuContainerTransactionTest.php tests/Feature/Wwhn174SkuImportValidationTest.php tests/Feature/Wwhn193PutawayAuditFailClosedTest.php tests/Feature/Wwhn224SkuImportDryRunTest.php` (các suite đếm audit_log — kiểm tra hành vi mới không phá count cũ) | **PASS 28/28** (187 assertions) |
| `php artisan test tests/Feature/Wwhn209OutboundSecurityRbacAuditTest.php` (test mới của phiên song song WWHN-209, chạm cùng DATA_SCOPE_DENIED) | **PASS 5/5** (50 assertions) |
| `php artisan test tests/Feature/Wwhn309PermissionCatalogEvidenceTest.php` (sau khi regenerate catalog vì hash CheckPermission.php đổi) | **PASS 6/6** (1065 assertions) |
| `php artisan test` (full suite) | 1198 tests: **1147 PASS**, 35 skipped, 16 đỏ — trong đó **1 do WWHN-165** (catalog stale, đã fix bằng regenerate + re-run 6/6 ở dòng trên) và **15 pre-existing** (chứng minh bên dưới) |

**Chứng minh 15 ca đỏ còn lại là pre-existing:** tạo worktree sạch tại HEAD
*trước* commit WWHN-165 (`19acdf3`, checkout sạch + junction vendor + copy .env),
chạy đúng 5 file test đỏ: `DocumentationBaselineContractTest`,
`MigrationFinalDataLoadTest`, `Wwhn299CutoverRehearsalEvidenceTest`,
`Wwhn260MigrationPackClosureTest`, `Wwhn295RuntimeConfigHealthTest` →
**tái hiện y nguyên 10 failures + 5 errors** (swagger /health thiếu 400;
migration commit exit 1; `MIGRATION_SNAPSHOT_REDACTED` cột agencies trùng
sensitive keys; thiếu `docker/nginx/default.conf`). Không ca nào liên quan
authz/audit/error-envelope của WWHN-165.

### PostgreSQL 16 (container, `audit_log` là VIEW trên `audit_logs` append-only)

Container: `wms_warehouse_hoa_nam_be-backend-1`, config `phpunit.pgsql.xml`
(DB test riêng của phpunit, KHÔNG đụng DB dev dùng chung).

| Lệnh | Kết quả |
|---|---|
| `docker exec wms_warehouse_hoa_nam_be-backend-1 php artisan test --configuration=phpunit.pgsql.xml tests/Feature/Wwhn165AuthzDenyAuditProofTest.php` | **PASS 7/7** (93 assertions, 48.43s) |
| `docker exec wms_warehouse_hoa_nam_be-backend-1 php artisan test --configuration=phpunit.pgsql.xml tests/Feature/AuthorizationTest.php tests/Feature/Wwhn289SecurityRegressionProofTest.php` | **PASS 14/14** (206 assertions, 156.12s) |

## Mapping yêu cầu review → bằng chứng

| Yêu cầu | Test / evidence |
|---|---|
| Direct API 403 khi thiếu permission (WRITE endpoint, không side-effect) | `Wwhn165AuthzDenyAuditProofTest::test_permission_denied_403_writes_denied_audit_row_without_business_side_effect` — 403 ACCESS_DENIED, `stock_movements` count và `stock_documents.version/status` bất động (đếm before/after) |
| Data-scope deny (kho ngoài `scope_ref`) | `::test_data_scope_denied_403_writes_denied_audit_row_without_business_side_effect` — 403 DATA_SCOPE_DENIED qua EnforceInboundWarehouseScope |
| DB no-side-effect trên mọi nhánh deny | hai test trên + positive control `::test_positive_control_in_scope_user_with_permission_posts_successfully` (đúng quyền + đúng kho thì Post được — guard không phải chặn-tất-cả) |
| Audit DENIED theo policy (QA1-AUD-002, ERD result IN SUCCESS/FAILED/DENIED) | hai test deny assert audit_log +1 row `result='DENIED'`, action `AUTHZ_PERMISSION_DENIED`/`AUTHZ_DATA_SCOPE_DENIED`, `request_id` khớp header X-Request-ID response, `actor_id` không null, metadata không chứa JWT/PII; policy = BEST_EFFORT theo `docs/decision-records/ADR-AUDIT-002-deny-audit-policy.md` |
| Audit unavailable không đổi deny response (best-effort proof) | `::test_deny_response_is_unchanged_when_audit_write_is_unavailable` — ép cả `audit_log` lẫn `audit_outbox` throw ở `creating` → vẫn 403 ACCESS_DENIED nguyên envelope, 0 row |
| 401 anonymous không ghi audit (phạm vi ADR-AUDIT-002) | `::test_anonymous_401_does_not_write_any_audit_row` |
| No-stack-trace kể cả `app.debug=true` (403 và 500) | `::test_stack_trace_never_leaks_even_when_debug_is_enabled_outside_local` — APP_ENV=testing + debug=true: 403 và 500 (route ném RuntimeException thật) đều `assertJsonMissingPath` trace/exception/file/line; root fix tại `app/Http/Middleware/ApiConventionMiddleware.php` (debug payload đòi thêm env local/development) |
| 409 idempotency / 409 state / 412 / 422 / 428 | `::test_error_contract_samples_cover_release_pack_matrix` — mỗi nhánh assert HTTP + error code rồi dump body thật vào `build/evidence/wwhn-165/samples/` → copy vào `docs/api-contract/releases/WWHN-165/1.0.0/samples/` |
| Hai driver | Toàn bộ file test chạy PASS cả sqlite lẫn PG (bảng trên) |

## Ghi chú trung thực

- Full suite sqlite chạy trên working tree dùng chung với các phiên Claude song
  song (WWHN-209 commit `b40cbda`/`19acdf3` trong lúc chạy); các suite mục tiêu
  đã được chạy riêng trước/sau và đều xanh. 15 ca đỏ pre-existing tái hiện được
  tại checkout sạch HEAD `19acdf3` (không có thay đổi WWHN-165).
- `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json` regenerate từ seed baseline
  (sqlite tạm + `AuthDatabaseSeeder`) vì catalog pin sha256 của
  `CheckPermission.php`; diff chỉ gồm `generated_at_utc` + hash middleware —
  không đổi permission/role/route matrix. Chạy exporter trên DB dev dùng chung
  bị FAIL do drift dữ liệu dev (không dùng, không mutate).
- Error vocabulary giữ nguyên WWHN-267: `ACCESS_DENIED` (permission +
  EnforceInventoryWarehouseScope) vs `DATA_SCOPE_DENIED` (4 middleware scope
  còn lại) — không rename.
