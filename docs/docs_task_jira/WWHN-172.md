# WWHN-172 — [BE1] Rà soát ràng buộc/audit/ranh giới giao dịch

## 1. Document control

| Field | Value |
|---|---|
| Task | WWHN-172 |
| Parent | WWHN-37 — `[SKU] Mô hình/API cho SKU, item, component, mã vật lý và container` |
| Parent Temp Key | HN-MC-001 |
| Epic Temp Key | HN-EPIC-04 |
| Dependency | HN-MC-001-ST01 |
| Branch | `m1` |
| Owner | BE1 — Quang Nguyễn Thanh |
| Scope | SKU/component/physical-code/container/import, transaction, audit, idempotency, migration safety và evidence handoff |
| Trạng thái BE1 | Code, contract, test source và protocol evidence đã hoàn thiện; runtime/independent sign-off vẫn là gate bắt buộc |

## 2. Baseline kỹ thuật áp dụng

Triển khai tuân thủ `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`:

- Laravel Modular Monolith, PostgreSQL là DB chuẩn.
- Controller mỏng; validation/request → service/use case → model/repository.
- Mutation phải có transaction, lock, idempotency và rollback toàn bộ khi lỗi.
- Audit/movement là append-only; không lộ secret, token hoặc stack trace.
- Backend enforce permission và data scope; không tin dữ liệu frontend.
- Contract được quản lý bằng OpenAPI, Postman, test-case JSON và CI.

## 3. Phạm vi runtime đã harden

### 3.1 SKU aggregate

`SkuAggregateService` quản lý nguyên tử:

```text
products
+ sku_component
+ physical_code
+ audit_log / audit_outbox
+ idempotency response snapshot
```

Các guard:

- code chuẩn hóa uppercase và unique;
- component khác chính nó;
- quantity > 0;
- graph không có cycle;
- component phải tồn tại, ACTIVE và đúng type;
- physical code không được chuyển ownership trái phép;
- optimistic concurrency qua `If-Match`/`version`;
- exact replay không tạo thêm business/audit row;
- same key + different payload trả `409` và không side effect.

### 3.2 Container aggregate

`ContainerService` quản lý nguyên tử:

```text
container
+ container_item
+ serial/product lock
+ physical-code binding
+ audit_log / audit_outbox
+ idempotency response snapshot
```

Các guard:

- parent không self/cycle;
- parent/child không lệch warehouse;
- serial chỉ thuộc một active container;
- item grain dùng `item_key` xác định, không phụ thuộc NULL semantics;
- bulk item quantity > 0 và product ACTIVE;
- SEALED/CLOSED immutable;
- seal chỉ từ ACTIVE và container phải có item;
- stale version trả `409`;
- deadlock transaction retry có giới hạn.

### 3.3 Import/data validation

Import SKU/container được thực hiện theo batch transaction và chống side effect:

- validate toàn bộ row trước hoặc fail batch theo contract;
- duplicate code/SKU/serial/container được phát hiện rõ;
- invalid format, unknown reference và inactive reference trả error code ổn định;
- không tạo partial business row khi batch thất bại;
- audit và idempotency nằm trong cùng commit boundary;
- file/row metadata được sanitize trước khi ghi audit.

## 4. P0/P1/P2 remediation matrix

| ID | Required fix | Thực hiện trong `m1` | Evidence/gate |
|---|---|---|---|
| BE1-P1-01 | Full PHPUnit + DB matrix trên exact SHA | Workflow `.github/workflows/wwhn-172-sku-container-contract.yml` chạy static/contract/PHPUnit/integration trên PostgreSQL và MySQL; evidence collector ghi SHA, PHP/Laravel, DB engine/version, migration state | Chỉ đóng khi có workflow run và raw logs gắn exact final SHA |
| BE1-P1-02 | OpenAPI/Postman/test-case có hash/version | `docs/api-contract/WWHN-172-sku-container-openapi.yaml`; `docs/postman/WWHN-172 SKU Container Contract.postman_collection.json`; CI environment; `docs/testing/WWHN-172-test-cases.json`; source register | Hash phải lấy từ artifact ở exact SHA, không lấy từ file local khác nguồn |
| BE1-P1-03 | QA1 race/idempotency/cycle/audit/no-stack-trace | Feature tests, concurrency runner, audit fail-closed test và `QA1_CONSTRAINT_API_TRACE_CHECKLIST.md` | WWHN-173 cần QA1 chạy độc lập, ghi request/response + DB trace |
| BE1-P1-04 | QA2 import/data validation | `ImportService`, import controller/route, `Wwhn174SkuImportValidationTest.php`, Postman/test-case và `QA2_IMPORT_VALIDATION_CHECKLIST.md` | WWHN-174 cần QA2 xác nhận duplicate/invalid/inactive/no-side-effect |
| BE1-P1-05 | BE2 transaction/schema boundary | `BE1_BE2_BOUNDARY_MATRIX.md` mô tả owner, table, lock order, transaction boundary, trace grain, impacted contract và branch alignment | WWHN-171/Tech Lead phải sign-off phần shared boundary |
| BE1-P1-06 | DB before-after + audit + fail-closed proof | `AUDIT_DB_PROOF_PROTOCOL.md`, `collect-wwhn172-db-snapshot.php`, `Wwhn172AuditFailClosedTest.php`, audit outbox/reconcile path | Cần raw DB snapshot từ môi trường test; không dùng matrix Markdown thay bằng chứng runtime |
| BE1-P1-07 | Migration dry-run/precheck/rollback | `wwhn172:migration-preflight`, migration uniqueness hardening, `Wwhn172MigrationSafetyTest.php`, `MIGRATION_ROLLBACK_RUNBOOK.md`, `DBA_DEVOPS_SIGNOFF.md` | DBA/DevOps xác nhận precheck, dry-run và rollback note trên DB mục tiêu |
| BE1-P2-01 | Jira handoff text matrix | Section 9 của tài liệu này và Jira comment sau commit | Comment phải ghi final SHA, artifact/hash, reviewer và blocker |

Không có P0 code finding còn mở trong phạm vi BE1. Các gate phụ thuộc QA1/QA2/BE2/DevOps/DBA không được tự đánh dấu PASS bởi BE1.

## 5. Files chính

### Runtime

- `Modules/Warehouse/app/Services/SkuAggregateService.php`
- `Modules/Warehouse/app/Services/ContainerService.php`
- `Modules/Warehouse/app/Services/ImportService.php`
- `Modules/Warehouse/app/Http/Controllers/SkuGovernanceController.php`
- `Modules/Warehouse/app/Http/Controllers/ContainerController.php`
- `Modules/Warehouse/app/Http/Controllers/ImportController.php`
- `app/Support/IdempotencyStore.php`
- `app/Support/IdempotencyKeyGuard.php`
- `app/Http/Middleware/Wwhn172AggregateLock.php`
- `Modules/AuditLog/app/Services/AuditLogger.php`
- `Modules/Warehouse/database/migrations/2026_08_03_000210_harden_sku_component_container_contract.php`
- `Modules/Warehouse/database/migrations/2026_08_04_000220_harden_wwhn172_serial_and_code_uniqueness.php`

### Tests/tooling

- `tests/Feature/Wwhn172SkuContainerTransactionTest.php`
- `tests/Feature/Wwhn172AuditFailClosedTest.php`
- `tests/Feature/Wwhn172MigrationSafetyTest.php`
- `tests/Feature/Wwhn174SkuImportValidationTest.php`
- `scripts/run-wwhn172-concurrency.mjs`
- `scripts/verify-wwhn172-contract.mjs`
- `scripts/collect-wwhn172-evidence.sh`
- `scripts/collect-wwhn172-db-snapshot.php`
- `app/Console/Commands/Wwhn172MigrationPreflight.php`

### Contract/evidence

- `docs/api-contract/WWHN-172-sku-container-openapi.yaml`
- `docs/postman/WWHN-172 SKU Container Contract.postman_collection.json`
- `docs/postman/WWHN-172 SKU Container CI.postman_environment.json`
- `docs/testing/WWHN-172-test-cases.json`
- `docs/docs_task_jira/WWHN-172-source-register.json`
- `docs/evidence/WWHN-172/AUDIT_DB_PROOF_PROTOCOL.md`
- `docs/evidence/WWHN-172/BE1_BE2_BOUNDARY_MATRIX.md`
- `docs/evidence/WWHN-172/MIGRATION_ROLLBACK_RUNBOOK.md`
- `docs/evidence/WWHN-172/QA1_CONSTRAINT_API_TRACE_CHECKLIST.md`
- `docs/evidence/WWHN-172/QA2_IMPORT_VALIDATION_CHECKLIST.md`
- `docs/evidence/WWHN-172/DBA_DEVOPS_SIGNOFF.md`

## 6. Transaction and lock contract

Lock order bắt buộc để giảm deadlock:

```text
idempotency lease
→ aggregate root (product/container)
→ parent/root references
→ component/product references theo ID tăng dần
→ physical codes theo normalized code tăng dần
→ serials theo ID tăng dần
→ business write
→ audit/audit_outbox
→ response snapshot
→ commit
```

Bất kỳ validation, constraint, audit hoặc persistence error nào đều rollback toàn bộ. Audit primary failure chỉ được fallback sang outbox trong cùng transaction; nếu cả audit và outbox thất bại thì fail-closed.

## 7. Error/no-side-effect contract

Các error code trọng yếu:

- `PRECONDITION_REQUIRED` (`428`)
- `IDEMPOTENCY_ERROR` (`409`)
- `SKU_VERSION_CONFLICT` (`409`)
- `CONTAINER_VERSION_CONFLICT` (`409`)
- `SKU_COMPONENT_SELF_REFERENCE` (`422`)
- `SKU_COMPONENT_CYCLE` (`422`)
- `DUPLICATE_COMPONENT` (`422`)
- `PHYSICAL_CODE_ALREADY_ASSIGNED` (`409`)
- `SERIAL_ALREADY_CONTAINERIZED` (`409`)
- `CONTAINER_PARENT_CYCLE` (`422`)
- `CONTAINER_WAREHOUSE_MISMATCH` (`422`)
- `CONTAINER_IMMUTABLE` (`409`)
- `EMPTY_CONTAINER_CANNOT_SEAL` (`422`)
- import duplicate/invalid/inactive errors theo OpenAPI/test-case register.

Error response không chứa exception class, SQL, file path, stack trace, authorization header hoặc secret.

## 8. Runtime evidence commands

Chạy trên exact commit SHA:

```bash
git rev-parse HEAD
php artisan about
php artisan wwhn172:migration-preflight --connection=<connection>
php artisan migrate --pretend --database=<connection>
php artisan test --testsuite=Feature
bash scripts/collect-wwhn172-evidence.sh
```

Concurrency/API contract:

```bash
node scripts/verify-wwhn172-contract.mjs
node scripts/run-wwhn172-concurrency.mjs
```

Evidence package tối thiểu phải có:

- exact commit SHA/build URL;
- OS/PHP/Laravel/DB engine và version;
- migration preflight + dry-run output;
- PHPUnit/integration raw log;
- Newman/Postman raw log;
- concurrency result;
- DB before/after business row;
- audit row/outbox row;
- fail-closed rollback proof;
- artifact SHA-256 manifest;
- reviewer/sign-off.

## 9. Jira handoff template

```text
[WWHN-172] BE1 HANDOFF
Branch: m1
Final commit SHA: <exact SHA>
Scope: SKU/component/physical-code/container/import transaction + audit + idempotency + migration safety
OpenAPI: docs/api-contract/WWHN-172-sku-container-openapi.yaml — SHA256: <hash>
Postman: docs/postman/WWHN-172 SKU Container Contract.postman_collection.json — SHA256: <hash>
Test cases: docs/testing/WWHN-172-test-cases.json — SHA256: <hash>
Source register: docs/docs_task_jira/WWHN-172-source-register.json — SHA256: <hash>
CI/build: <workflow URL/run ID>
DB matrix: PostgreSQL <version>; MySQL <version>
QA1: WWHN-173 — <status/reviewer/evidence>
QA2: WWHN-174 — <status/reviewer/evidence>
BE2 boundary: WWHN-171 — <status/reviewer/evidence>
DBA/DevOps: <reviewer/evidence>
Open blockers: <none or explicit owner + ETA + impact>
```

## 10. Definition of Done interpretation

### BE1 code-complete

- transaction/lock/idempotency/audit guards implemented;
- import/data validation implemented;
- migration precheck/rollback tooling implemented;
- OpenAPI/Postman/test cases/source register present;
- CI/evidence scripts present;
- handoff documentation updated.

### Formal Done/100%

Chỉ đạt khi tất cả điều kiện sau có bằng chứng runtime trên cùng exact final SHA:

1. PostgreSQL và MySQL CI/PHPUnit/integration PASS;
2. QA1 ký xác nhận WWHN-173;
3. QA2 ký xác nhận WWHN-174;
4. BE2 + Tech Lead ký boundary WWHN-171;
5. DBA/DevOps ký migration preflight/dry-run/rollback;
6. Jira có handoff text matrix và artifact hashes;
7. không còn P0/P1 blocker chưa có disposition.

Tài liệu không tự tuyên bố PASS cho các bước chưa có raw log hoặc reviewer độc lập.
