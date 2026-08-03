# WWHN-131 — Independent Review, DBA/Security Retest và DEC-STK-001 Protocol

> Version `1.0.0` — Status **PROPOSED**. Tài liệu này là protocol bắt buộc để tạo bằng chứng độc lập; không phải QA/Tech Lead/DBA/Security/BA/PM sign-off và không được dùng để tự chuyển Jira sang Done.

## 1. Exact-SHA input bắt buộc

Mọi reviewer phải dùng cùng một bộ input:

| Field | Giá trị phải ghi |
|---|---|
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` |
| Workflow | `.github/workflows/wwhn-131-stocktake-contract.yml` |
| Exact SHA | `build-metadata.json.commit_sha` |
| CI run | `build-metadata.json.ci_run_url` |
| Artifact | `stocktake-evidence-<exact_sha>` |
| Source tree | `source-tree.sha256` |
| Manifest | `SHA256SUMS` |
| Manifest digest | `SHA256SUMS.sha256` |
| Environment/deployment SHA | `<QA/staging SHA hoặc N/A>` |
| Reviewer | `<họ tên + Jira account>` |
| Executed at | `<ISO-8601 +07:00>` |

Artifact chỉ hợp lệ khi:

```bash
cd stocktake-evidence-<exact_sha>
sha256sum --check SHA256SUMS
sha256sum --check SHA256SUMS.sha256
```

Cả hai lệnh phải PASS. Reviewer phải đối chiếu `build-metadata.json.commit_sha`, workflow run SHA, artifact name và source-tree hash. Không review theo branch động.

## 2. Phân tách trách nhiệm

| Role | Được xác nhận | Không được tự xác nhận thay |
|---|---|---|
| BE1 | Implementation, source paths, artifact generation, handoff | QA1, Tech Lead, DBA, Security, BA, PM |
| QA1 | API/DB negative, retry, rollback, race evidence | Tech architecture decision, DBA rehearsal |
| Tech Lead | Concurrency/idempotency/transaction/ledger design review | PM/BA business decision |
| DBA | Migration rehearsal, constraint/index/FK validation trên target engine/data copy | Security/RBAC |
| Security | Cross-session/cross-warehouse/RBAC response leak negative tests | DBA schema |
| BA | Business contract, impacted requirement sources, DEC-STK-001 draft/final | Tech/QA evidence |
| PM | Final GO/CONDITIONAL/NO-GO | Test execution |

Một người có nhiều vai trò chỉ được ký khi Jira/RACI chính thức cho phép; phải ghi rõ vai trò và account.

## 3. QA1 + Tech Lead independent retest

### 3.1 Automation baseline

Reviewer chạy trên source checkout đúng exact SHA:

```bash
php artisan test --filter=StocktakeThreeLayerContractTest
php artisan test --filter=StocktakeApplyLedgerIntegrityTest
php artisan test --filter=StocktakeReviewGateTest
php artisan test
node scripts/verify-stocktake-contract.mjs \
  build/evidence/wwhn-131/openapi.json \
  build/evidence/wwhn-131/route-list.raw.json \
  build/evidence/wwhn-131/stocktake.postman.collection.json \
  build/evidence/wwhn-131/stocktake-test-cases.json \
  build/evidence/wwhn-131/stocktake-source-register.json \
  /tmp/stocktake-contract-verification.json
```

### 3.2 Mandatory matrix

| Gate | Setup | Expected PASS |
|---|---|---|
| Race apply | Hai DB connection/worker gửi cùng session/version, cùng key/payload đồng thời | Một business effect; request còn lại replay hoặc conflict hợp lệ; không duplicate movement/event |
| Same key retry | Request đầu commit nhưng client mô phỏng mất response; retry cùng key/payload | Replay response snapshot; movement/balance/session không thay đổi lần hai |
| Same key conflict | Retry cùng key, khác reason/variance/version | `409 IDEMPOTENCY_ERROR`; không có DB write mới |
| Different key double post | Apply thành công rồi gọi key khác với payload cũ | `409`; chỉ một posting event business operation và một movement effect |
| Pending/incomplete event | Event cùng operation/key nhưng chưa COMMITTED hoặc thiếu snapshot | `409 IDEMPOTENCY_IN_PROGRESS`; không lộ partial response |
| Exact variance set | Foreign, missing, duplicate hoặc partial variance IDs | `422`; session/variance/balance/movement không đổi |
| Movement ack | Thiếu ack hoặc ack set khác detected set | `428` hoặc `409`; không apply |
| Timeout after commit | Network timeout sau DB commit | Retry trả snapshot giống request đầu |
| Three-layer rollback | Làm ITEM/CONTAINER grain invalid sau review | Toàn bộ balance/movement/session/variance/physical update/posting_event rollback |
| Ledger mismatch | Sửa balance không qua signed ledger | `409 LEDGER_BALANCE_MISMATCH`; không operation commit |
| Zero effect | Expected tại count bằng actual | Không movement `qty_delta=0`; balance version không tăng |
| Reconcile | Sau canonical Newman | `reconciliation-report.summary.status=PASS`, mismatch `0` |

### 3.3 Evidence phải attach

- Focused/full PHPUnit raw logs.
- Race worker request/response timestamps và raw DB transaction logs.
- `posting_event`, `stock_movements`, `inventory_balances`, `stocktake_session`, `stocktake_variance` before/after queries.
- Newman raw/JSON.
- Reconciliation report.
- Exact SHA, artifact digest, environment/deployment SHA.
- Reviewer result: `PASS | FAIL | CONDITIONAL`, failed TC/defect IDs.

## 4. DBA migration rehearsal

### 4.1 CI schema baseline

Artifact phải có:

- `migration-rehearsal.raw.log`
- `schema-verification.json`
- migration source copy
- exact migration SHA-256

CI verifier kiểm tra:

- `stocktake_session.warehouse_id` NOT NULL.
- `grain_type`, `grain_id`, `grain_key` NOT NULL trên snapshot/count/variance.
- Unique `(session_id, grain_key)` trên ba bảng.
- Unique `(operation, idempotency_key)` trên `posting_event`.
- Index `(warehouse_id, status)` và `(session_id, status)`.
- FK `stocktake_session.warehouse_id -> warehouses.id`.
- FK `stock_movements.stocktake_variance_id -> stocktake_variance.variance_id`.

### 4.2 Target DB rehearsal bắt buộc

DBA chạy trên bản sao dữ liệu gần production, đúng engine/version:

1. Backup/snapshot và ghi row count/checksum trước migration.
2. Dry-run kiểm tra legacy `warehouse_id` NULL.
3. Kiểm tra grain NULL/duplicate theo `(session_id, grain_key)`.
4. Kiểm tra duplicate `posting_event(operation,idempotency_key)`.
5. Backfill bằng mapping nghiệp vụ được BA xác nhận; không đoán warehouse/grain.
6. Chạy migration.
7. Chạy `php scripts/verify-wwhn131-schema.php <output.json>`.
8. So sánh row count/checksum trước/sau.
9. Chạy canonical create/start/count/review/apply.
10. Ghi execution time, lock duration, blocking query, rollback plan.

DBA result phải có: engine/version, dataset snapshot ID, exact SHA, migration hash, schema verifier JSON, backup/rollback reference, `PASS | FAIL | CONDITIONAL`.

## 5. Security independent negative retest

| Case | Actor | Target | Expected |
|---|---|---|---|
| Cross-warehouse create | User scope Warehouse A | Create session Warehouse B | `403 WAREHOUSE_SCOPE_DENIED`, no row |
| Cross-warehouse detail | User scope A | Session B | `403`, no session/variance data |
| Cross-warehouse variance list | User scope A | Variances session B | `403`, no IDs/quantity leak |
| Cross-warehouse review | User scope A | Review session B | `403`, no status/version change |
| Cross-warehouse acknowledge | User scope A | Ack variance session B | `403`, no acknowledged_by/at |
| Cross-warehouse apply | User scope A | Apply session B | `403`, no posting event/movement |
| Replay leak | Admin commits session B; user A retries same key/payload | Committed response snapshot | `403` before replay; no response data leak |
| Cross-session variance | Authorized warehouse, variance from other session | Apply | `422 INVALID_VARIANCE_SELECTION` |
| Missing permission | User has scope but lacks `stocktake.adjust` | Write lifecycle endpoints | `403`, no DB write |
| Legacy list alias | Restricted user | `/api/v1/stocktake/sessions` | Only in-scope rows |

Security attach raw HTTP requests/responses with secrets redacted, DB no-write evidence, exact SHA/digest, reviewer/date/result.

## 6. DEC-STK-001 formal decision payload

BA prepares; Tech Lead, QA1, DBA, Security provide referenced evidence; PM decides.

```text
[DEC-STK-001 — STOCKTAKE APPLY DECISION]
Jira decision issue: WWHN-25
BA final issue: WWHN-132
Traceability issue: WWHN-133

Document: docs/docs_task_jira/WWHN-131.md
Document version/status: 1.2.0 / PROPOSED
Source Register: docs/docs_task_jira/WWHN-131-source-register.json
Source Register version/status: 1.2.0 / PROPOSED
Branch: m1
Exact review SHA: <build-metadata.commit_sha>
CI run: <build-metadata.ci_run_url>
Artifact: stocktake-evidence-<exact_sha>
Source-tree SHA-256: <source-tree.sha256>
SHA256SUMS digest: <SHA256SUMS.sha256 digest>
Deployment SHA/environment: <value>

QA1: <name/date/PASS|FAIL|CONDITIONAL/evidence URL>
Tech Lead: <name/date/PASS|FAIL|CONDITIONAL/evidence URL>
DBA: <name/date/PASS|FAIL|CONDITIONAL/evidence URL>
Security: <name/date/PASS|FAIL|CONDITIONAL/evidence URL>
BA: <name/date/contract result>
PM: <name/date/GO|CONDITIONAL GO|NO-GO>

OpenAPI SHA-256: <hash>
Postman SHA-256: <hash>
Test register SHA-256: <hash>
Migration SHA-256: <hash>
Contract verifier: PASS / <hash>
Schema verifier: PASS / <hash>
Full PHPUnit: PASS / <log>
Newman: PASS / <raw+json>
Reconciliation: PASS / <json>
Open defects/conditions: <IDs or NONE>

Decision result: GO | CONDITIONAL GO | NO-GO
Decision effective at: <ISO-8601 +07:00>
Conditions/deadline/owner: <exact list or NONE>
```

Không dùng chữ `APPROVED`, `100%` hoặc chuyển Done nếu thiếu một reviewer bắt buộc hoặc hash không cùng exact SHA.

## 7. WWHN-133 traceability update

BAS phải thay toàn bộ NULL bằng attachment hiện hành:

| Source | Attachment ID | Version/date | SHA-256 | Impacted rules/tickets | BAS reviewer |
|---|---|---|---|---|---|
| BRD | `<id>` | `<value>` | `<hash>` | `<list>` | `<name/date>` |
| SRS | `<id>` | `<value>` | `<hash>` | `<list>` | `<name/date>` |
| BPD | `<id>` | `<value>` | `<hash>` | `<list>` | `<name/date>` |
| DEC-INV | `<id>` | `<value>` | `<hash>` | `<list>` | `<name/date>` |
| DEC-STK-001 | `<id/comment>` | `final` | `<hash/N/A comment>` | WWHN-25/131/132/133 | `<name/date>` |

BAS phải ghi impacted tickets và xác nhận source hiện hành, không dùng bản archived/deprecated.

## 8. Structured BE1 Jira handoff

```text
[WWHN-131 BE1 STRUCTURED HANDOFF]
File: docs/docs_task_jira/WWHN-131.md
Version/status: 1.2.0 / PROPOSED
Scope: stocktake DRAFT/start/count/review/ack/apply; idempotency; signed ledger; data scope; three-layer rollback; schema integrity
Branch: m1
Exact SHA: <sha>
CI run: <url>
Artifact: stocktake-evidence-<sha>
Manifest digest: <sha256>
Source tree hash: <sha256>

Repository completion:
- dedicated exact-SHA workflow and immutable artifact
- OpenAPI/Postman/test/source register hashes
- focused and full PHPUnit logs
- schema verifier and migration rehearsal log
- DB before/after and reconciliation
- QA/Tech/DBA/Security/Decision protocol

Independent reviewers required:
QA1, Tech Lead, DBA, Security, BA, PM

Impacted tickets:
WWHN-25, WWHN-131, WWHN-132, WWHN-133

Blockers:
- <exact external gates; use NONE only when evidence is attached>
```

## 9. Closure states

| State | Điều kiện |
|---|---|
| `REPOSITORY_IMPLEMENTED` | Code/tests/workflow/protocol committed on `m1` |
| `CI_VERIFIED` | Exact-SHA workflow PASS; artifact checksums PASS |
| `INDEPENDENTLY_VERIFIED` | QA1 + Tech Lead + DBA + Security PASS cùng SHA/digest |
| `CONDITIONAL GO` | PM/BA decision có conditions/owner/deadline |
| `APPROVED` | DEC-STK-001 GO, traceability complete, no open P0/P1 |
| `NO-GO` | Một mandatory gate FAIL hoặc evidence mismatch |

Repository automation không tự nâng trạng thái vượt `CI_VERIFIED`.
