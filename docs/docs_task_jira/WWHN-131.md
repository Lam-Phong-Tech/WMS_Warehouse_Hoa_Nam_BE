# WWHN-131 — Stocktake contract, apply variance và integrity ba lớp

## 1. Document Control

| Trường | Giá trị |
|---|---|
| Jira key | `WWHN-131` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` — sửa trực tiếp, không tạo branch riêng |
| Document version | `1.2.0` |
| Document status | **PROPOSED** |
| Repository remediation | **COMPLETE — PENDING EXACT-SHA CI + INDEPENDENT REVIEW + FORMAL DECISION** |
| Exact commit source | `build/evidence/wwhn-131/build-metadata.json.commit_sha` |
| Workflow | `.github/workflows/wwhn-131-stocktake-contract.yml` |
| Artifact | `stocktake-evidence-<exact_commit_sha>` |
| Source tree hash | `source-tree.sha256` |
| Hash manifest | `SHA256SUMS` |
| Manifest digest | `SHA256SUMS.sha256` |
| Source Register | `docs/docs_task_jira/WWHN-131-source-register.json` — `1.2.0 / PROPOSED` |
| Independent review protocol | `docs/evidence/WWHN-131/INDEPENDENT_REVIEW_AND_DECISION_PROTOCOL.md` |
| Approval status | **PENDING QA1 / TECH LEAD / DBA / SECURITY / BA / PM** |

Không hard-code một commit cũ làm review baseline. Exact review SHA phải lấy từ `build-metadata.json` của artifact do workflow checkout đúng commit sinh ra. Không review theo branch động.

Không được ghi `APPROVED`, `Done`, `100% formally closed` hoặc đánh dấu approval checkbox nếu chưa có:

1. Workflow exact SHA PASS.
2. `SHA256SUMS` và `SHA256SUMS.sha256` verify PASS.
3. Contract/schema/reconcile PASS.
4. QA1, Tech Lead, DBA, Security review độc lập trên cùng SHA/digest.
5. BA/PM phát hành `DEC-STK-001` trên Jira.
6. BAS cập nhật BRD/SRS/BPD/DEC-INV attachment ID/hash hiện hành.

## 2. Closure register cho đánh giá 72/100

| ID | Sev | Vấn đề đánh giá | Required fix | Fix đã triển khai trực tiếp trên `m1` | Repository evidence | Trạng thái trung thực | External closure gate |
|---|---|---|---|---|---|---|---|
| `STK-P1-01` | P1 | Formal Decision chưa có | BA/Tech Lead/PM comment exact version/hash/date/result | Phát hành protocol `DEC-STK-001`, exact decision payload, role separation, required SHA/artifact/hash/reviewer/date/result; chặn tự nâng `APPROVED`. | `INDEPENDENT_REVIEW_AND_DECISION_PROTOCOL.md`, Source Register `1.2.0`, mục 11 | **READY FOR FORMAL DECISION** | BA + Tech Lead + PM phải comment thật trên `WWHN-25`. |
| `STK-P1-02` | P1 | QA/Tech review độc lập chưa có | Race/retry/timeout/idempotency/rollback/ledger reconcile trên exact SHA | Dedicated exact-SHA workflow; focused + full PHPUnit logs; replay/different payload/different key/rollback/ledger/zero-effect tests; mandatory independent race/timeout matrix. | workflow, `collect-wwhn131-evidence.sh`, 3 test suites, review protocol | **AUTOMATION + PROTOCOL COMPLETE** | QA1 + Tech Lead chạy độc lập và attach raw evidence trên cùng SHA/digest. |
| `STK-P1-03` | P1 | DBA/Security chưa xác nhận | DBA rehearsal; Security cross-session/cross-warehouse negative | Thêm schema verifier SQLite/MySQL/PostgreSQL; migration rehearsal log; FK/index/NOT NULL/unique checks; test replay scope leak, lifecycle cross-warehouse no-write, cross-session variance; Security matrix đầy đủ. | `verify-wwhn131-schema.php`, `StocktakeReviewGateTest.php`, protocol mục 4–5 | **AUTOMATION + PROTOCOL COMPLETE** | DBA chạy target DB/data copy; Security chạy độc lập QA/staging. |
| `STK-P1-04` | P1 | Artifact/hash còn placeholder | Attach artifact thật, SHA256SUMS, source-tree SHA, OpenAPI/Postman/test hash | Tách workflow/artifact riêng cho WWHN-131; artifact exact-SHA; metadata chứa hash OpenAPI/Postman/test/source register/migration/verifier; manifest và manifest digest verify trong CI. | `.github/workflows/wwhn-131-stocktake-contract.yml`, `collect-wwhn131-evidence.sh` | **IMPLEMENTED — WAITING FINAL SHA WORKFLOW RESULT** | Workflow commit cuối phải PASS; link artifact/CI thật phải được đăng Jira. |
| `STK-P1-05` | P1 | BA final chưa phát hành | BA phát hành `DEC-STK-001` hoặc Conditional/No-Go | Chuẩn hóa payload `GO | CONDITIONAL GO | NO-GO`, reviewers, evidence links, conditions/owner/deadline; không cho final nếu thiếu independent evidence. | protocol mục 6; mục 11 tài liệu này | **READY FOR BA FINAL** | BA phát hành trên `WWHN-132`; PM xác nhận decision. |
| `STK-P1-06` | P1 | Traceability chưa cập nhật | BAS cập nhật attachment ID/hash và impacted tickets | Source Register `1.2.0` liệt kê source code/artifact/test/protocol; cung cấp matrix BRD/SRS/BPD/DEC-INV/DEC-STK-001 và impacted tickets. | Source Register; protocol mục 7 | **REPOSITORY TRACEABILITY COMPLETE** | BAS điền attachment IDs/SHA-256 thật trên `WWHN-133`. |
| `STK-P2-01` | P2 | Jira handoff chưa structured | Post file/version/status/scope/hash/reviewer/blockers | Structured handoff đã có đầy đủ exact SHA, CI URL, artifact, manifest digest, scope, reviewers, impacted tickets, blockers. | mục 10; protocol mục 8 | **READY TO POST** | Atlassian app/permission phải khả dụng để đăng comment thật. |

Kết luận đối với register này: toàn bộ phần có thể xử lý trong repository đã được triển khai. Các dòng còn mở là review/approval/attachment từ người hoặc hệ thống bên ngoài; BE1 không được tạo giả.

## 3. P0 core correctness được bảo toàn

Các P0 trước đó không bị bỏ qua khi xử lý P1/P2:

| Control | Enforcement trong code | Automation |
|---|---|---|
| Idempotency | Unique `(operation,idempotency_key)`, canonical request SHA-256, committed response snapshot, same-key/different-payload `409` | `STK-TC-006`, `STK-TC-012`, `STK-TC-013` |
| Race/CAS | Session row lock, `session_version` CAS, balance version CAS, unique movement business key | focused tests + independent race protocol |
| Exact variance set | Query `APPROVED` theo exact `session_id`; requested IDs phải bằng full approved set | `STK-TC-005` |
| Data scope | Warehouse scope trước event replay; scope trên create/detail/count/review/ack/variance/apply/list | `STK-TC-008`, `STK-TC-012`, `STK-TC-014` |
| Signed ledger | Canonical `SUM(stock_movements.qty_delta)`; không remap sign lần hai | `STK-TC-003`, `STK-TC-011` |
| Ledger reconcile | Pre-apply ledger bằng balance; mismatch trả `409` và không commit operation | `STK-TC-011` |
| Zero effect | Không tạo movement `0`; không tăng balance version | `STK-TC-010` |
| Three-layer atomicity | Balance, movement, variance, session, posting event, ITEM/CONTAINER cùng transaction | `STK-TC-007` |
| Movement acknowledgement | Detected IDs phải bằng acknowledged IDs; lưu actor/time | `STK-TC-004` |
| Schema integrity | warehouse/grain NOT NULL, unique session grain, posting-event unique, FK/index | schema verifier + DBA protocol |

## 4. Technical contract khóa trong code

### 4.1 State machine

```text
DRAFT --start(CAS)--> IN_PROGRESS --review(CAS)--> REVIEWED --apply(CAS)--> APPLIED
```

- `POST /api/v1/stocktake-sessions` luôn tạo `DRAFT`, `session_version=1`, chưa snapshot.
- `/start` khóa session, kiểm tra state/version, cập nhật `IN_PROGRESS`, tạo snapshot đúng một lần.
- Count chỉ nhận grain tồn tại trong snapshot của đúng session.
- Review backend tự tính expected/variance/effect.
- Apply chỉ nhận `session_version`, `variance_ids`, `reason`; không nhận client expected/effect.
- Replay không được trả response snapshot trước khi warehouse scope pass.

### 4.2 Exact variance selection

Apply lấy toàn bộ variance:

```text
session_id = requested session
status = APPROVED
lock for update
```

Sau đó:

```text
sort(unique(requested variance IDs)) == sort(all approved variance IDs)
```

Foreign, missing, partial hoặc duplicate set không được apply; trả `422 INVALID_VARIANCE_SELECTION` và không có DB write.

### 4.3 Idempotency

```text
operation       = stocktake_apply_adjustment_<session_id>
idempotency key = trimmed Idempotency-Key
request hash    = SHA-256(session/version/sorted variance IDs/trimmed reason)
unique          = (operation, idempotency_key)
```

Rules:

- Missing key: `428 PRECONDITION_REQUIRED`.
- Same key + same payload + committed snapshot: replay `200`.
- Same key + different payload: `409 IDEMPOTENCY_ERROR`.
- Event incomplete/pending: `409 IDEMPOTENCY_IN_PROGRESS`.
- Different key sau session đã apply: stale version/state conflict; không tạo effect lần hai.
- Scope được kiểm tra trước posting-event replay để không lộ response kho khác.

### 4.4 Signed ledger và balance

```text
ledger quantity = SUM(stock_movements.qty_delta)
```

Inbound/adjust-in là dương; outbound/adjust-out là âm. Trước apply:

```text
SUM(qty_delta) == inventory_balances.on_hand_qty
```

Mismatch trả `409 LEDGER_BALANCE_MISMATCH`; session giữ `REVIEWED`, variance giữ `APPROVED`, không movement/posting event mới.

### 4.5 Three-layer transaction

Cùng transaction:

1. Lock scoped session.
2. Check replay/idempotency.
3. Check version/state.
4. Lock exact approved variance set.
5. Check movement acknowledgement.
6. Lock balance và review baseline/version.
7. Reconcile signed ledger.
8. Tạo signed adjustment movement nếu effect khác `0`.
9. CAS balance.
10. Update ITEM/CONTAINER physical layer.
11. Mark variance/session applied.
12. Insert committed posting event + response snapshot.

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
| `STK-TC-014` | Cross-warehouse count/review/ack/variance/apply no-write |

Test files:

- `tests/Feature/StocktakeThreeLayerContractTest.php`
- `tests/Feature/StocktakeApplyLedgerIntegrityTest.php`
- `tests/Feature/StocktakeReviewGateTest.php`

## 7. Dedicated exact-SHA evidence gate

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

Artifact outputs tối thiểu:

- `build-metadata.json`
- `source-tree.sha256`
- `SHA256SUMS`
- `SHA256SUMS.sha256`
- OpenAPI JSON/YAML + generation log
- Runtime route list
- Postman collection/environment
- Newman raw/JSON
- Test-case register and Source Register
- Contract verifier result
- Schema verifier result
- Migration rehearsal log
- Focused three-layer/ledger/security test logs
- Full PHPUnit log
- DB before/after
- Reconciliation report
- Source copies used for review

Verification:

```bash
bash scripts/collect-wwhn131-evidence.sh
cd build/evidence/wwhn-131
sha256sum --check SHA256SUMS
sha256sum --check SHA256SUMS.sha256
```

CI additionally fails nếu:

- Metadata commit khác `GITHUB_SHA`.
- Artifact name không bind exact SHA.
- Contract/schema/reconcile không `PASS`.
- Newman có failure.
- Source Register bị nâng khỏi `PROPOSED` khi chưa sign-off.

## 8. Schema/DBA evidence

`php scripts/verify-wwhn131-schema.php <output.json>` hỗ trợ SQLite/MySQL/PostgreSQL và kiểm tra:

- `stocktake_session.warehouse_id NOT NULL`.
- `grain_type/grain_id/grain_key NOT NULL` trên snapshot/count/variance.
- Unique `(session_id,grain_key)` trên snapshot/count/variance.
- Unique `(operation,idempotency_key)` trên `posting_event`.
- Index warehouse/status và variance session/status.
- FK session warehouse.
- FK movement variance.

CI rehearsal không thay thế DBA target-DB rehearsal. DBA phải chạy trên bản sao dữ liệu gần production, ghi engine/version/dataset snapshot/backup/lock duration/rollback plan.

## 9. Independent review ownership

| Gate | Owner/Reviewer | Evidence bắt buộc | Current status |
|---|---|---|---|
| Repository implementation | BE1 | Code/tests/workflow/protocol committed `m1` | **COMPLETE** |
| Exact-SHA CI | BE1 automation | Workflow PASS + artifact + checksums | PENDING FINAL SHA RUN |
| Core correctness | QA1 + Tech Lead | race/retry/timeout-after-commit/idempotency/rollback | PENDING INDEPENDENT REVIEW |
| Data/RBAC | QA1 + Security | cross-session/cross-warehouse/replay leak/no-write | PENDING INDEPENDENT REVIEW |
| Schema | DBA + Tech Lead | target DB migration rehearsal/schema verifier | PENDING DBA |
| Business contract | BA | exact OpenAPI/Postman/state/decision | PENDING BA |
| Traceability | BAS | current BRD/SRS/BPD/DEC-INV IDs/hashes | PENDING BAS |
| Final decision | BA + PM + Tech Lead | `DEC-STK-001` exact version/hash/date/result | PENDING FORMAL DECISION |
| Jira handoff | BE1 | structured comment | BLOCKED ATLASSIAN APP/PERMISSION |

## 10. Structured BE1 Jira handoff

```text
[WWHN-131 BE1 STRUCTURED HANDOFF]
Document: docs/docs_task_jira/WWHN-131.md
Document version/status: 1.2.0 / PROPOSED
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
- exact variance selection and session scope
- idempotency/replay/payload conflict/different-key double-apply guard
- scope before replay response disclosure
- signed ledger and pre-apply reconcile
- zero-effect no movement/no balance version increment
- three-layer atomic rollback
- schema constraints and verifier
- exact-SHA immutable evidence workflow
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

## 11. DEC-STK-001 formal decision payload

```text
[DEC-STK-001 — STOCKTAKE APPLY DECISION]
Jira: WWHN-25
BA final: WWHN-132
Traceability: WWHN-133

Document: WWHN-131.md v1.2.0 / PROPOSED
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

## 12. Commits xử lý đợt đánh giá này

| Commit | Nội dung |
|---|---|
| `7688b04d6d823e82bdbfa1022b008bc28c8900c5` | Bổ sung protocol rà soát độc lập và quyết định WWHN-131. |
| `b9956c355a638412cc6caae3b1b8c8b958858ce6` | Thêm bộ xác minh schema kiểm kê WWHN-131. |
| `45d90525d240b7778d8d4beabb581fc282111a1d` | Bổ sung test bảo mật replay và chặn apply lặp WWHN-131. |
| `8656aa7c931fff2681e88243c16d8de9172cda39` | Thêm pipeline evidence riêng cho WWHN-131. |
| `531de33699db89e30b2ee2c5ed7dea498f3b0b87` | Thêm workflow exact-SHA riêng cho WWHN-131. |
| `6face18071c2edc9225b33feb0dd51f0195f0e08` | Mở rộng test register WWHN-131 lên phiên bản 1.2. |
| `8b124b70dd11a0b6a98cf0a94b9a314c4125f5c8` | Siết verifier contract theo exact SHA và Source Register. |
| `9db6d6fe3f73d398b56c8595a8580ac7586acac1` | Đồng bộ Source Register WWHN-131 lên phiên bản 1.2. |

Tất cả commit được ghi trực tiếp vào `m1`; không tạo branch phụ.

## 13. Current truthful status

### Repository

**Đã hoàn tất toàn bộ phần sửa P0/P1/P2 có thể thực hiện bằng code, test, workflow và tài liệu trong repository.**

### Formal closure

Chưa được phép tuyên bố `100% / APPROVED / Done` cho Jira vì các bằng chứng sau phải do reviewer độc lập hoặc hệ thống ngoài repository tạo:

1. Workflow exact final SHA phải PASS và artifact thật phải tồn tại.
2. QA1 + Tech Lead phải chạy race/retry/timeout/rollback/reconcile độc lập.
3. DBA phải rehearsal migration trên target engine/data copy.
4. Security phải retest cross-session/cross-warehouse/replay leak.
5. BAS phải điền attachment IDs/hashes hiện hành.
6. BA/PM phải phát hành `DEC-STK-001` trên Jira.
7. Atlassian connector hiện trả `403 — app is not installed on this instance`, nên BE1 chưa thể post Jira comment thật từ phiên làm việc này.

Trạng thái chính xác tại repository: **REPOSITORY REMEDIATION COMPLETE; PENDING EXACT-SHA CI AND EXTERNAL SIGN-OFF**.
