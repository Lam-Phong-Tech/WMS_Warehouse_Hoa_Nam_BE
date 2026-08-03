# WWHN-131 — Stocktake contract, apply variance và integrity ba lớp

## 1. Document Control

| Trường | Giá trị |
|---|---|
| Jira key | `WWHN-131` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` — sửa trực tiếp, không tạo branch riêng |
| Document version | `1.2.0` |
| Document revision | `2026-08-03.3` |
| Document status | **PROPOSED** |
| Repository remediation | **COMPLETE — PENDING EXACT-SHA CI + INDEPENDENT REVIEW + FORMAL DECISION** |
| Code/test baseline trước commit tài liệu | `cd4bb7d0d90dcb5c96f927bdf640c3179887861e` |
| Exact review SHA source | `build/evidence/wwhn-131/build-metadata.json.commit_sha` |
| Workflow | `.github/workflows/wwhn-131-stocktake-contract.yml` |
| Artifact | `stocktake-evidence-<exact_commit_sha>` |
| Source tree hash | `source-tree.sha256` |
| Hash manifest | `SHA256SUMS` |
| Manifest digest | `SHA256SUMS.sha256` |
| Source Register | `docs/docs_task_jira/WWHN-131-source-register.json` — `1.2.0 / PROPOSED` |
| Independent review protocol | `docs/evidence/WWHN-131/INDEPENDENT_REVIEW_AND_DECISION_PROTOCOL.md` |
| Approval status | **PENDING QA1 / TECH LEAD / DBA / SECURITY / BA / PM** |

Exact review không được lấy theo branch động hoặc một SHA ghi tay trong tài liệu. Reviewer phải lấy SHA từ `build-metadata.json` của artifact do workflow checkout đúng commit sinh ra.

Không được ghi `APPROVED`, `Done`, `100% formally closed` hoặc đánh dấu approval nếu chưa có đủ:

1. Workflow exact SHA PASS.
2. `SHA256SUMS` và `SHA256SUMS.sha256` verify PASS.
3. Contract/schema/Newman/PHPUnit/reconciliation PASS.
4. QA1, Tech Lead, DBA và Security review độc lập trên cùng SHA/digest.
5. BAS cập nhật BRD/SRS/BPD/DEC-INV attachment ID và SHA-256 hiện hành.
6. BA/PM phát hành `DEC-STK-001` trên Jira.

## 2. Closure register cho đánh giá 76/100

| ID | Sev | Required fix | Fix trong repository `m1` | Evidence | Trạng thái chính xác | External closure gate |
|---|---|---|---|---|---|---|
| `STK-P1-01` | P1 | BA/Tech Lead/PM comment exact version/hash/date/result | Chuẩn hóa `DEC-STK-001`, role separation, exact SHA/artifact/manifest/reviewer/date/result và cấm tự nâng APPROVED. | Protocol mục decision; mục 10 tài liệu này | **READY FOR FORMAL DECISION** | BA + Tech Lead + PM comment thật trên `WWHN-25`. |
| `STK-P1-02` | P1 | QA/Tech review race/retry/timeout/idempotency/rollback/reconcile | Dedicated exact-SHA workflow; focused/full PHPUnit; replay, payload conflict, different-key, rollback, ledger mismatch, zero-effect và duplicate variance no-write tests. | Workflow, collector, 3 feature suites, `STK-TC-001..015` | **AUTOMATION + PROTOCOL COMPLETE** | QA1 + Tech Lead chạy độc lập và attach raw evidence cùng SHA/digest. |
| `STK-P1-03` | P1 | DBA rehearsal; Security cross-session/cross-warehouse negative | Schema verifier SQLite/MySQL/PostgreSQL; migration rehearsal; FK/index/NOT NULL/unique checks; replay scope leak, cross-session, cross-warehouse no-write tests. | `verify-wwhn131-schema.php`, `StocktakeReviewGateTest.php` | **AUTOMATION + PROTOCOL COMPLETE** | DBA chạy target engine/data copy; Security chạy QA/staging độc lập. |
| `STK-P1-04` | P1 | Artifact thật và hash thật | Workflow tạo artifact exact-SHA, metadata, source-tree hash, `SHA256SUMS`, manifest digest, OpenAPI/Postman/test/migration/verifier hashes và raw logs. | Workflow + `collect-wwhn131-evidence.sh` | **IMPLEMENTED — WAITING FINAL SHA WORKFLOW RESULT** | Workflow commit cuối PASS và link artifact thật được đăng Jira. |
| `STK-P1-05` | P1 | BA phát hành final decision | Payload hỗ trợ `GO`, `CONDITIONAL GO`, `NO-GO`; bắt buộc conditions/owner/deadline khi conditional. | Mục 10 | **READY FOR BA FINAL** | BA phát hành trên `WWHN-132`; PM xác nhận. |
| `STK-P1-06` | P1 | BAS cập nhật attachment ID/hash và impacted tickets | Source Register liệt kê code/test/workflow/protocol; handoff liệt kê `WWHN-25/131/132/133`. | Source Register + mục 9 | **REPOSITORY TRACEABILITY TEMPLATE PREPARED** | BAS điền attachment IDs/SHA-256 thật trên `WWHN-133`. |
| `STK-P2-01` | P2 | Jira handoff có cấu trúc | Có payload file/version/status/scope/SHA/artifact/hash/results/reviewer/ticket/blocker. | Mục 9 | **READY TO POST** | Atlassian app/permission phải khả dụng. |

Các đầu việc còn mở là review, approval, target-DB rehearsal hoặc attachment từ hệ thống/người độc lập. BE1 không được tạo giả bằng chứng này.

## 3. P0 core correctness được bảo toàn và siết thêm

| Control | Enforcement | Automation |
|---|---|---|
| Idempotency | Unique `(operation,idempotency_key)`, canonical SHA-256 request hash, committed response snapshot, same-key/different-payload `409` | `STK-TC-006`, `012`, `013` |
| Race/CAS | Session row lock, session version CAS, balance version CAS, unique movement business key | Focused tests + independent race protocol |
| Exact variance set | Đúng session, `APPROVED`, full set, không foreign/missing/partial/duplicate | `STK-TC-005`, `015` |
| Defense in depth | Controller `distinct`; OpenAPI `uniqueItems=true`; service `normalizeVarianceIds()` không tự khử trùng | `STK-TC-015` |
| Data scope | Warehouse scope trước posting-event replay; scope trên create/detail/count/review/ack/variance/apply/list | `STK-TC-008`, `012`, `014` |
| Signed ledger | Canonical `SUM(stock_movements.qty_delta)`; không đảo dấu lần hai | `STK-TC-003`, `011` |
| Ledger reconcile | Ledger phải bằng balance trước apply; mismatch `409`, không commit | `STK-TC-011` |
| Zero effect | Không tạo movement `0`, không tăng balance version | `STK-TC-010` |
| Three-layer atomicity | Balance, movement, variance, session, posting event, ITEM/CONTAINER cùng transaction | `STK-TC-007` |
| Movement acknowledgement | Detected IDs phải bằng acknowledged IDs, có actor/time | `STK-TC-004` |
| Schema integrity | Warehouse/grain NOT NULL, unique session grain, posting-event unique, FK/index | Schema verifier + DBA protocol |

## 4. Technical contract khóa trong code

### 4.1 State machine

```text
DRAFT --start(CAS)--> IN_PROGRESS --review(CAS)--> REVIEWED --apply(CAS)--> APPLIED
```

- Create luôn trả `DRAFT`, `session_version=1`, chưa có snapshot.
- `/start` khóa session, kiểm tra state/version và tạo snapshot đúng một lần.
- Count chỉ nhận grain tồn tại trong snapshot của đúng session.
- Review backend tự tính expected/variance/effect.
- Apply chỉ nhận `session_version`, `variance_ids`, `reason`.
- Client không được gửi `expected_qty` hoặc `inventory_effect_qty`.
- Replay không được trả response snapshot trước khi warehouse scope pass.

### 4.2 Exact variance selection và duplicate guard

Apply khóa toàn bộ variance:

```text
session_id = requested session
status = APPROVED
lock for update
```

Các invariant bắt buộc:

```text
count(requested variance IDs) == count(distinct requested variance IDs)
sort(requested variance IDs) == sort(all approved variance IDs)
```

Enforcement ba lớp:

1. HTTP validation: `variance_ids.* = required|uuid|distinct`.
2. OpenAPI: `minItems=1`, `maxItems=500`, `uniqueItems=true`.
3. Service: `normalizeVarianceIds()` phát hiện duplicate và trả `422 INVALID_VARIANCE_SELECTION`; không dùng `array_unique()` để che payload lỗi.

Service kiểm tra warehouse scope trước duplicate guard và duplicate guard trước posting-event lookup. Vì vậy request ngoài scope vẫn nhận `403`, còn request trong scope có duplicate bị chặn trước replay/apply và không có DB write.

Foreign, missing, partial hoặc duplicate set đều bị từ chối. Duplicate response chứa `requested_count`, `distinct_count`, `duplicate_ids` để QA truy vết.

### 4.3 Idempotency

```text
operation       = stocktake_apply_adjustment_<session_id>
idempotency key = trimmed Idempotency-Key
request hash    = SHA-256(session/version/sorted unique variance IDs/trimmed reason)
unique          = (operation, idempotency_key)
```

Rules:

- Missing key: `428 PRECONDITION_REQUIRED`.
- Key dài quá 100 ký tự: `422`.
- Same key + same payload + committed snapshot: replay `200`.
- Same key + different payload: `409 IDEMPOTENCY_ERROR`.
- Event pending/incomplete: `409 IDEMPOTENCY_IN_PROGRESS`.
- Different key sau session đã apply: stale version/state conflict; không tạo effect lần hai.
- Duplicate IDs bị chặn trước khi tạo request hash hoặc tra event.

### 4.4 Signed ledger và balance

```text
ledger quantity = SUM(stock_movements.qty_delta)
```

Inbound/adjust-in dương; outbound/adjust-out âm. Trước apply:

```text
SUM(qty_delta) == inventory_balances.on_hand_qty
```

Mismatch trả `409 LEDGER_BALANCE_MISMATCH`; session giữ `REVIEWED`, variance giữ `APPROVED`, không movement/posting event mới.

### 4.5 Three-layer transaction

Cùng transaction:

1. Lock scoped session.
2. Reject duplicate variance IDs.
3. Lookup/replay posting event.
4. Check version/state.
5. Lock exact approved variance set.
6. Check movement acknowledgement.
7. Lock balance và review baseline/version.
8. Reconcile signed ledger.
9. Tạo signed adjustment movement nếu effect khác `0`.
10. CAS balance.
11. Update ITEM/CONTAINER physical layer.
12. Mark variance/session applied.
13. Insert committed posting event + response snapshot.

Bất kỳ lỗi nào rollback toàn bộ.

## 5. Runtime API/RBAC

| Method | Path | Permission |
|---|---|---|
| GET | `/api/v1/stocktake-sessions` | `inventory.view` |
| POST | `/api/v1/stocktake-sessions` | `stocktake.adjust` |
| GET | `/api/v1/stocktake-sessions/{id}` | `inventory.view` |
| POST | `/api/v1/stocktake-sessions/{id}/start` | `stocktake.adjust` |
| POST | `/api/v1/stocktake-sessions/{id}/counts` | `stocktake.adjust` |
| POST | `/api/v1/stocktake-sessions/{id}/review` | `stocktake.adjust` |
| POST | `/api/v1/stocktake-sessions/{id}/acknowledge-movements` | `stocktake.adjust` |
| GET | `/api/v1/stocktake-sessions/{id}/variances` | `inventory.view` |
| POST | `/api/v1/stocktake-sessions/{id}/apply-adjustment` | `stocktake.adjust` |
| GET | `/api/v1/stocktake/sessions` | `inventory.view` — scoped legacy alias |

Tất cả nằm dưới `auth:api`.

## 6. Automated test register `1.2.0`

| TC | Coverage |
|---|---|
| `STK-TC-001` | Create DRAFT/start snapshot once/CAS |
| `STK-TC-002` | Unique grain/snapshot membership |
| `STK-TC-003` | Signed movement `100-10`, actual `90`, effect `0` |
| `STK-TC-004` | Exact movement acknowledgement |
| `STK-TC-005` | Foreign/missing variance IDs |
| `STK-TC-006` | Commit replay/payload conflict/unique event |
| `STK-TC-007` | Three-layer rollback |
| `STK-TC-008` | Warehouse scope create/read/apply/legacy list |
| `STK-TC-009` | Newman canonical flow |
| `STK-TC-010` | Zero-effect no movement/no version increment |
| `STK-TC-011` | Ledger mismatch blocks/rolls back |
| `STK-TC-012` | Scope before committed replay snapshot disclosure |
| `STK-TC-013` | Different key cannot double apply |
| `STK-TC-014` | Cross-warehouse lifecycle no-write |
| `STK-TC-015` | Duplicate IDs rejected by HTTP and service; no event/movement/state change |

Test files:

- `tests/Feature/StocktakeThreeLayerContractTest.php`
- `tests/Feature/StocktakeApplyLedgerIntegrityTest.php`
- `tests/Feature/StocktakeReviewGateTest.php`

## 7. Exact-SHA evidence gate

Workflow:

```text
.github/workflows/wwhn-131-stocktake-contract.yml
```

Collector:

```text
scripts/collect-wwhn131-evidence.sh
```

Artifact:

```text
stocktake-evidence-<exact_commit_sha>
```

Artifact tối thiểu:

- `build-metadata.json`
- `source-tree.sha256`
- `SHA256SUMS`
- `SHA256SUMS.sha256`
- OpenAPI JSON/YAML và generation log
- Runtime route list
- Postman collection/environment
- Newman raw/JSON
- Test register và Source Register
- Contract verifier và schema verifier result
- Migration rehearsal log
- Focused/full PHPUnit logs
- DB before/after và reconciliation report
- Source copies dùng cho review

Verification:

```bash
bash scripts/collect-wwhn131-evidence.sh
cd build/evidence/wwhn-131
sha256sum --check SHA256SUMS
sha256sum --check SHA256SUMS.sha256
```

CI phải fail nếu metadata SHA khác `GITHUB_SHA`, artifact không bind SHA, verifier/schema/Newman/reconcile fail, hoặc Source Register bị nâng khỏi `PROPOSED` khi chưa sign-off.

## 8. Independent review ownership

| Gate | Owner/Reviewer | Evidence bắt buộc | Current status |
|---|---|---|---|
| Repository implementation | BE1 | Code/test/workflow/protocol committed `m1` | **COMPLETE** |
| Exact-SHA CI | BE1 automation | Workflow PASS + artifact + checksums | PENDING FINAL SHA RUN |
| Core correctness | QA1 + Tech Lead | race/retry/timeout/idempotency/rollback/reconcile/duplicate no-write | PENDING INDEPENDENT REVIEW |
| Data/RBAC | QA1 + Security | cross-session/cross-warehouse/replay leak/no-write | PENDING INDEPENDENT REVIEW |
| Schema | DBA + Tech Lead | target DB rehearsal/schema verifier/rollback plan | PENDING DBA |
| Business contract | BA | exact OpenAPI/Postman/state/decision | PENDING BA |
| Traceability | BAS | current BRD/SRS/BPD/DEC-INV IDs/hashes | PENDING BAS |
| Final decision | BA + PM + Tech Lead | `DEC-STK-001` exact version/hash/date/result | PENDING FORMAL DECISION |
| Jira handoff | BE1 | structured comment | BLOCKED ATLASSIAN APP/PERMISSION |

## 9. Structured BE1 Jira handoff

```text
[WWHN-131 BE1 STRUCTURED HANDOFF]
Document: docs/docs_task_jira/WWHN-131.md
Document version/revision/status: 1.2.0 / 2026-08-03.3 / PROPOSED
Source Register: docs/docs_task_jira/WWHN-131-source-register.json
Source Register version/status: 1.2.0 / PROPOSED
Branch: m1
Exact review SHA: <build-metadata.json.commit_sha>
CI run: <build-metadata.json.ci_run_url>
Artifact: stocktake-evidence-<exact SHA>
Source-tree SHA-256: <source-tree.sha256>
Manifest: SHA256SUMS
Manifest digest: <SHA256SUMS.sha256 digest>
Manifest validation: PASS|FAIL

Scope completed:
- DRAFT/start/count/review/ack/apply lifecycle
- exact full variance set, foreign/missing/partial/duplicate rejection
- controller/OpenAPI/service duplicate defense in depth
- idempotency/replay/payload conflict/different-key guard
- scope before replay response disclosure
- signed ledger and pre-apply reconcile
- zero-effect no movement/no version increment
- three-layer atomic rollback
- schema constraints/verifier
- exact-SHA evidence workflow
- independent QA/Tech/DBA/Security/Decision protocol

Hashes:
OpenAPI: <build-metadata.openapi_sha256>
Postman: <build-metadata.postman_sha256>
Test register: <build-metadata.test_case_sha256>
Source Register: <build-metadata.source_register_sha256>
Migration: <build-metadata.migration_sha256>
Contract verifier: <build-metadata.contract_verifier_sha256>
Schema verifier: <build-metadata.schema_verifier_sha256>

Results:
Contract verifier: PASS|FAIL
Schema verifier: PASS|FAIL
Focused PHPUnit: PASS|FAIL
Full PHPUnit: PASS|FAIL
Newman: PASS|FAIL
Reconciliation: PASS|FAIL

Required reviewers:
QA1, Tech Lead, DBA, Security, BA, PM

Impacted tickets:
WWHN-25, WWHN-131, WWHN-132, WWHN-133

External blockers:
- QA1/Tech Lead independent review: <status/link>
- DBA rehearsal: <status/link>
- Security retest: <status/link>
- BRD/SRS/BPD/DEC-INV IDs/hashes: <status/link>
- DEC-STK-001: <status/link>
```

## 10. DEC-STK-001 formal decision payload

```text
[DEC-STK-001 — STOCKTAKE APPLY DECISION]
Jira: WWHN-25
BA final: WWHN-132
Traceability: WWHN-133

Document: WWHN-131.md v1.2.0 rev 2026-08-03.3 / PROPOSED
Source Register: v1.2.0 / PROPOSED
Exact SHA: <sha>
CI run: <url>
Artifact: stocktake-evidence-<sha>
Source tree SHA-256: <hash>
SHA256SUMS digest: <hash>
Deployment SHA/environment: <value>

QA1: <name/date/result/evidence>
Tech Lead: <name/date/result/evidence>
DBA: <name/date/result/evidence>
Security: <name/date/result/evidence>
BA: <name/date/contract result>
PM: <name/date/decision>

OpenAPI/Postman/Test/Migration/Verifier hashes: <exact values>
Open defects/conditions: <IDs or NONE>
Decision result: GO | CONDITIONAL GO | NO-GO
Conditions/owner/deadline: <exact list or NONE>
```

## 11. Commits và exact blobs của đợt xử lý

| Commit | Artifact | Exact blob SHA |
|---|---|---|
| `b4d9814952b39d3d621fa83e3e14ae814a3957e3` | Đồng bộ WWHN-131 theo register 72 điểm | `WWHN-131.md`: baseline trước đợt siết duplicate |
| `6087cc78f80bb8e0d5d6346e51c5524a254aa25f` | Đăng ký `STK-TC-015` | `5bab25729feae54caccb30c0ec767bb917a8284e` |
| `a1a0deb4e35e3dde74ba9417ccb3750cc735054e` | Siết contract verifier lên 15 test và uniqueItems | `90f2d25f385fe198f6279ae3b9183248c189f7d4` |
| `97960be034d3b137bfd726aab39a3d7ffabbd800` | Đồng bộ OpenAPI `uniqueItems=true`, max 500 | `9a59ff9c84451f413d5bc79f103d730dd899881c` |
| `52455a23d4b2c01d2936c932085c688cbf047710` | Regression HTTP + service duplicate no-write | `87b38ae0e4954f9c9d2463a1f04fd229af130126` |
| `cd4bb7d0d90dcb5c96f927bdf640c3179887861e` | Chặn duplicate tại service trước replay lookup | `e21104a5329d416f57181bd89453cf55b35832b6` |

Tất cả commit được ghi trực tiếp vào `m1`; không tạo branch phụ.

## 12. Verification đã thực hiện và giới hạn

Đã thực hiện trước khi ghi repository:

- `php -l` PASS cho controller, service và regression test đã sửa.
- `node --check` PASS cho contract verifier.
- JSON parse PASS cho test register.
- GitHub content blob SHA của cả 5 file khớp `git hash-object` cục bộ:
  - Service: `e21104a5329d416f57181bd89453cf55b35832b6`
  - Controller: `9a59ff9c84451f413d5bc79f103d730dd899881c`
  - Review gate test: `87b38ae0e4954f9c9d2463a1f04fd229af130126`
  - Test register: `5bab25729feae54caccb30c0ec767bb917a8284e`
  - Contract verifier: `90f2d25f385fe198f6279ae3b9183248c189f7d4`

Chưa được phép ghi full test PASS tại thời điểm cập nhật tài liệu vì kết quả đó phải đến từ workflow exact final SHA và artifact thật. Không thay thế bằng suy đoán hoặc log của SHA cũ.

## 13. Remediation thực thi cho đánh giá 76/100

### 13.1 Thay đổi code và security gate

- Mọi write endpoint theo session (`start`, `counts`, `review`, `acknowledge-movements`, `apply-adjustment`) gọi `findScopedSession()` trước validation. Actor ngoài warehouse scope luôn bị chặn `403 WAREHOUSE_SCOPE_DENIED` trước khi server diễn giải payload, không lộ validation oracle và không ghi DB.
- Regression ledger so sánh decimal theo giá trị số, không phụ thuộc PDO serialize `99`, `99.0` hay `99.000`.
- Migration stocktake rollback gỡ named index và foreign key theo đúng thứ tự trước khi drop cột; gate `DatabaseMigrations` chứng minh rollback/re-apply không làm hỏng schema SQLite.
- Evidence collector từ chối chạy nếu Laravel không resolve đúng `APP_ENV=testing`, driver `sqlite`, database `/tmp/wms-wwhn131.sqlite`. Workflow export các biến này ở job scope, nên `.env.example` PostgreSQL không thể khiến `migrate:fresh` chạm nhầm DB.
- Lease `idempotency_keys.locked_until` được chuyển nullable bằng migration riêng vì terminal response phải giải phóng lease bằng `NULL`.
- SKU replay đã nhận diện key đã tồn tại trước unique validation; cùng key được chuyển đến idempotency hash gate để replay hoặc trả conflict, thay vì bị `422` sai contract.
- `WarrantyEvent` tắt Eloquent timestamps vì schema chỉ có `created_at/event_at`, loại bỏ write tới cột `updated_at` không tồn tại.

### 13.2 Kết quả local verification trên worktree trước final commit

| Gate | Lệnh | Kết quả |
|---|---|---|
| Stocktake focused | `php artisan test --do-not-cache-result --filter='Stocktake(ThreeLayerContract\|ApplyLedgerIntegrity\|ReviewGate)Test'` | **PASS — 14 tests, 199 assertions** |
| Migration/seed rollback | `php artisan test --do-not-cache-result --filter=BackendMigrationSeedHealthTest` | **PASS — 6 tests, 76 assertions** |
| Schema verifier SQLite file DB | `php scripts/verify-wwhn131-schema.php storage/framework/testing/wwhn131-schema-verification.json` sau `migrate:fresh` cô lập | **PASS — 28 checks, 0 failed** |
| Regression liên quan | `php artisan test --do-not-cache-result --filter='(StockDocumentIdempotencyTest\|WarrantyStatusOnlyContractTest\|Wwhn172SkuContainerTransactionTest)'` | **PASS — 24 tests, 180 assertions** |
| Full PHPUnit | `php artisan test --do-not-cache-result` | **PASS — 133 tests, 1,246 assertions** |

Các kết quả trên là pre-commit local verification, không được thay thế exact-SHA CI artifact hoặc independent sign-off.

### 13.3 Evidence ledger bắt buộc để đóng từng finding

| Finding | Evidence repository đã chuẩn bị | Evidence thực tế còn bắt buộc | Trạng thái sau remediation |
|---|---|---|---|
| `STK-P1-01` | DEC-STK-001 payload khóa exact SHA/digest/result | Jira comment ID/link, reviewer BA + Tech Lead + PM, date, result trên `WWHN-25` | **PENDING EXTERNAL DECISION** |
| `STK-P1-02` | Focused/full test, no-write assertions, raw-log collector | QA1 + Tech Lead chạy độc lập trên exact final SHA; race/timeout target-DB log và sign-off | **LOCAL AUTOMATION PASS; PENDING INDEPENDENT REVIEW** |
| `STK-P1-03` | Schema verifier, reversible migration fixes, security negative tests | DBA rehearsal trên target PostgreSQL/clone dữ liệu; Security raw HTTP + DB before/after và sign-off | **SQLITE GATE PASS; PENDING TARGET-DB/SECURITY** |
| `STK-P1-04` | Workflow, collector safety guard, manifest/digest pipeline | CI run PASS sau final commit; artifact URL/ID `stocktake-evidence-<final_sha>` và verified digest | **PENDING EXACT-SHA CI** |
| `STK-P1-05` | GO/CONDITIONAL GO/NO-GO payload | BA decision thật trên `WWHN-132`, condition/owner/deadline nếu conditional | **PENDING BA FINAL** |
| `STK-P1-06` | Source Register và impacted-ticket matrix | BAS điền current attachment ID + SHA-256 cho BRD/SRS/BPD/DEC-INV trên `WWHN-133` | **TEMPLATE PREPARED; PENDING BAS** |
| `STK-P2-01` | Structured handoff đầy đủ ở mục 9 | Jira comment ID/link sau khi post payload đã điền exact artifact/hash/result | **READY TO POST AFTER CI** |

Không có tên reviewer, attachment ID, Jira comment ID, target-DB result hay approval nào được tự tạo giả trong repository. Vì các trường này thuộc Jira/con người/hạ tầng độc lập, repository chỉ được ghi **technical implementation complete**, không được ghi `100% formally closed` trước khi ledger trên có bằng chứng thật.

## 14. Current truthful status

### Repository

**Toàn bộ phần P0/P1/P2 có thể xử lý bằng code, contract, test, verifier, workflow và tài liệu trong repository đã hoàn tất.**

### Formal closure

Chưa được phép tuyên bố `100% / APPROVED / Done` cho Jira cho đến khi có:

1. Workflow exact final SHA PASS và artifact thật tồn tại.
2. QA1 + Tech Lead chạy race/retry/timeout/rollback/reconcile/duplicate no-write độc lập.
3. DBA rehearsal migration trên target engine/data copy.
4. Security retest cross-session/cross-warehouse/replay leak.
5. BAS điền attachment IDs/hashes hiện hành.
6. BA/PM phát hành `DEC-STK-001`.
7. Jira handoff được post thật.

Ngày `2026-08-03`, Atlassian connector trả `403 — The app is not installed on this instance`; vì vậy BE1 không thể post comment hoặc chuyển trạng thái `WWHN-25/132/133` từ phiên làm việc này.

Trạng thái chính xác: **REPOSITORY REMEDIATION COMPLETE; PENDING EXACT-SHA CI AND EXTERNAL SIGN-OFF**.
