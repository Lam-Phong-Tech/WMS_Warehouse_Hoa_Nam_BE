# WWHN-131 — Stocktake contract, apply variance và integrity ba lớp

## 1. Document Control

| Trường | Giá trị |
|---|---|
| Jira key | `WWHN-131` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` — sửa trực tiếp, không tạo branch riêng |
| Document version | `1.1.0` |
| Document status | **PROPOSED** |
| Technical implementation baseline | `f9a2280c35da797fb0c32b4e6d66d7d7e039b328` |
| Source Register commit | `41aeacd73f0fd9bfdae32bb806622ab84dd3224f` |
| Approval status | **PENDING FORMAL JIRA APPROVAL** |
| CI artifact | `stocktake-evidence-<exact_review_commit_sha>` |
| Hash sources | `build/evidence/api-data/SHA256SUMS` và `source-tree.sha256` |

`Technical implementation baseline` là commit cuối chứa code, migration, route, test, verifier và evidence pipeline trước khi cập nhật Source Register/tài liệu. Commit dùng để review chính thức phải là exact commit sinh ra artifact, lấy từ `build-metadata.json`; không review theo branch động.

Không có checkbox approval nào được đánh dấu trong tài liệu này. Không được đổi trạng thái thành `APPROVED`, `Done` hoặc tuyên bố `100%` nếu chưa có đủ GitHub Actions artifact trên exact SHA, independent review và Jira comment ghi version/hash/reviewer/date.

## 2. Kết quả xử lý exact fix action register

| ID | Sev | Exact fix đã triển khai trên `m1` | Repository evidence | Trạng thái trung thực | Gate còn lại |
|---|---|---|---|---|---|
| `STK-P0-01` | P0 | Giữ tài liệu và Source Register ở `PROPOSED`; không tạo approval checkbox; không điền attachment/hash giả; CI tạo exact commit SHA, source-tree SHA-256 và manifest SHA-256. | `WWHN-131.md`, `WWHN-131-source-register.json`, `collect-api-data-evidence.sh` | REPOSITORY IMPLEMENTED | BA/Tech Lead/PM formal approval trên Jira bằng exact artifact/version/hash/date. |
| `STK-P0-02` | P0 | Apply dùng unique `(operation,idempotency_key)`, canonical request SHA-256, committed response snapshot, replay sau commit, same-key/different-payload `409`, session row lock/CAS, balance optimistic lock và unique movement effect. | `StocktakeAdjustmentService.php`, migration, `STK-TC-006` | REPOSITORY IMPLEMENTED | Tech Lead + QA1 xác nhận race/retry/timeout-after-commit trên exact SHA. |
| `STK-P0-03` | P0 | Apply chỉ nhận `variance_ids`; truy vấn bắt buộc đúng `session_id`, warehouse data scope và exact full set/count; foreign/missing ID bị từ chối. Restricted user gọi apply vào kho ngoài scope nhận `403`. | service/guard, `STK-TC-005`, `STK-TC-008` | REPOSITORY IMPLEMENTED | QA1/Security independent negative retest. |
| `STK-P0-04` | P0 | Chốt signed convention: inbound/adjust-in dương, outbound/adjust-out âm, net=`SUM(qty_delta)`; trước apply bắt buộc ledger bằng balance. Case `100 - 10`, actual `90` cho variance/effect `0`, không tạo movement rỗng và không tăng balance version. | `StocktakeService.php`, `StocktakeAdjustmentService.php`, `STK-TC-003`, `STK-TC-010`, `STK-TC-011` | REPOSITORY IMPLEMENTED | BE2/Tech Lead/QA1 xác nhận ledger/balance/reconcile artifact. |
| `STK-P0-05` | P0 | Balance, movement, Item và Container được cập nhật trong cùng transaction. Lỗi physical layer rollback session, variance, balance, movement và các physical update đã chạy trước đó. | `StocktakeAdjustmentService.php`, `STK-TC-007`, DB before/after | REPOSITORY IMPLEMENTED | QA1 chạy API/DB rollback độc lập. |
| `STK-P1-01` | P1 | `stocktake_session.warehouse_id` có FK/index/data scope; migration không đoán kho cho legacy row. Nếu còn NULL, migration dừng để DBA backfill đúng; sau đó mới đặt `NOT NULL`. | migration `2026_08_03_000100...`, model/guard | REPOSITORY IMPLEMENTED | DBA rehearsal/approve trên target DB. |
| `STK-P1-02` | P1 | Canonical `grain_type/grain_id/grain_key`; backfill khi bất kỳ grain field nào NULL; dừng khi còn NULL/duplicate; sau đó đặt `NOT NULL` và unique `(session_id,grain_key)` cho snapshot/count/variance. Container ưu tiên `container_item_id`. | migration, `StocktakeGrainService.php`, `STK-TC-002` | REPOSITORY IMPLEMENTED | DBA/QA1 constraint retest trên target DB và legacy dataset. |
| `STK-P1-03` | P1 | Một contract duy nhất: create luôn `DRAFT`, `/start` thực hiện CAS `DRAFT → IN_PROGRESS` và tạo snapshot đúng một lần. Toàn bộ lifecycle routes được đăng ký runtime trong `routes/api.php`. | controller/OpenAPI/routes, `STK-TC-001` | REPOSITORY IMPLEMENTED | BA/QA1/FE xác nhận cùng exact OpenAPI hash. |
| `STK-P1-04` | P1 | Tách `detected_movement_ids` và `acknowledged_movement_ids`; lưu `acknowledged_by/at`; exact-set mismatch `409`; apply chưa ack `428`. | model/migration/services, `STK-TC-004` | REPOSITORY IMPLEMENTED | BA/QA1 negative API sign-off. |
| `STK-P1-05` | P1 | OpenAPI/Postman/test register/verifier/source copies/logs/DB snapshots được đóng trong một artifact; metadata ghi exact commit và version; tất cả file được băm trong `SHA256SUMS`. Test register/verifier là `1.1.0`, Postman là `1.0.0`. | workflow, evidence script, verifier, test register | AUTOMATION IMPLEMENTED | Workflow trên exact final SHA phải PASS; WWHN-31/33/40 reviewer xác nhận cùng artifact/hash. |
| `STK-P1-06` | P1 | Source Register `1.1.0` liệt kê route, contract, services, migration, hai test suites, verifier và evidence pipeline; external BRD/SRS/BPD/DEC-INV/Jira attachment vẫn NULL có chủ đích. | `WWHN-131-source-register.json` | PROPOSED / EXTERNAL BLOCKED | BA/BAS cung cấp current attachment IDs + SHA-256; PM/QA xác nhận rebase. |
| `STK-P2-01` | P2 | Structured Jira handoff ở mục 11 gồm file/version/status/scope/key decisions/exact artifact/reviewer/impacted tickets/blockers. | mục 11 | READY, NOT POSTED | Atlassian connector trả `403 app is not installed`; phải đăng khi site/app khả dụng. |

## 3. Các commit đã thực hiện trên nhánh `m1`

| Commit | Nội dung |
|---|---|
| `9739345e47a7e5bcbb97f58888cb7d654c7a1221` | Đăng ký đầy đủ lifecycle route stocktake và legacy scoped list. |
| `1b26a5f674b47821b3779ba71536b155eadec685` | Bổ sung apply data-scope negative test và actor audit hợp lệ. |
| `f3c572e705fb220f3b1505f76fb55d48365cef8e` | Siết warehouse/grain `NOT NULL` sau backfill/validation an toàn. |
| `3e61a92b8553f6bf596eea04dff1a1ead17f093d` | Chặn apply khi ledger lệch balance; không tạo zero-effect movement. |
| `dc42e33d36a89d439fa989bcd01571bf55ea0fe1` | Thêm feature tests cho zero-effect và ledger mismatch rollback. |
| `762e6c3f41c52c654d3b3da99ce46ff24279012a` | Nâng test-case register lên `1.1.0`, đủ `STK-TC-001..011`. |
| `15990b80044f53f7bb3ac6e1d148dcc79be1b4b5` | Siết contract verifier cho route/source/ledger/zero-effect/11 test case. |
| `f9a2280c35da797fb0c32b4e6d66d7d7e039b328` | Đóng gói đầy đủ route/controller/services/migration/tests vào evidence SHA-256. |
| `41aeacd73f0fd9bfdae32bb806622ab84dd3224f` | Cập nhật Source Register đúng baseline và giữ approval external ở `PROPOSED`. |

Tất cả commit trên được ghi trực tiếp vào `m1`; không tạo branch phụ.

## 4. Contract nghiệp vụ đã khóa trong code

### 4.1 State machine

```text
DRAFT --start(CAS)--> IN_PROGRESS --count/review--> REVIEWED --apply(CAS)--> APPLIED
```

- `POST /api/v1/stocktake-sessions` tạo `DRAFT`, `session_version = 1`, chưa có snapshot.
- `/start` bắt buộc `session_version`, khóa row, kiểm tra CAS rồi tạo snapshot SKU/ITEM/CONTAINER đúng một lần.
- Count chỉ nhận grain tồn tại trong snapshot của chính session.
- Review tạo variance backend-authoritative; client không gửi expected/effect vào apply.
- Apply yêu cầu toàn bộ variance `APPROVED` của session, không cho partial set âm thầm.
- Mọi endpoint detail/variance/apply đều đi qua warehouse data-scope guard.

### 4.2 Grain integrity

| Grain | Canonical `grain_id` | Physical source | Actual rule | Apply effect |
|---|---|---|---|---|
| `SKU` | `product_id` | `inventory_balances` | không âm | signed movement + balance khi effect khác `0` |
| `ITEM` | `inventory_serials.id` | `inventory_serials` | chỉ `0` hoặc `1` | `IN_STOCK`/`MISSING`, stock condition |
| `CONTAINER` | `container_item.container_item_id` | `container_item` + container warehouse | không âm | cập nhật đúng `container_item.quantity` |

DB source of truth:

```text
grain_key = grain_type | grain_id | stock_status
NOT NULL(grain_type, grain_id, grain_key)
UNIQUE(session_id, grain_key)
```

Migration dừng có chủ đích khi legacy session chưa có `warehouse_id`, grain còn NULL hoặc duplicate; không xóa/chọn dữ liệu để ép migration pass.

### 4.3 Signed ledger và reconcile

```text
positive qty_delta: OPENING, RECEIPT_POSTED, ADJUST_IN, TRANSFER_IN,
                    TRANSFER_RETURN, ISSUE_REVERSED
negative qty_delta: ISSUE_POSTED, ADJUST_OUT, TRANSFER_OUT, RECEIPT_REVERSED
ledger quantity:    SUM(stock_movements.qty_delta)
```

Không đảo dấu lần hai dựa vào `movement_type`.

Trước apply cho từng `(warehouse_id, product_id, stock_status)`:

```text
SUM(stock_movements.qty_delta) == inventory_balances.on_hand_qty
```

Nếu lệch, trả `409 LEDGER_BALANCE_MISMATCH`; transaction không tạo movement/posting event và session vẫn `REVIEWED`.

Case chuẩn:

```text
snapshot = 100
signed movement after start = -10
expected_at_count = 90
actual = 90
variance = 0
inventory_effect = 0
```

Với effect `0`:

- Không tạo `ADJUST_IN 0` hoặc `ADJUST_OUT 0`.
- Không tăng `inventory_balances.version`.
- Variance/session vẫn được chốt atomically.
- Posting event vẫn lưu response snapshot để retry có thể replay.
- Response ghi dimension vào `zero_effect_dimensions`.

### 4.4 Idempotency và concurrency

```text
operation       = stocktake_apply_adjustment_{session_id}
idempotency key = Idempotency-Key header
unique key      = UNIQUE(operation, idempotency_key)
request hash    = SHA-256(session/version/sorted variance IDs/trimmed reason)
movement key    = SHA-256(stocktake-adjust|session|product|stock_status)
```

- Same key + same payload sau commit: replay `response_snapshot`, không apply lần hai.
- Same key + different payload: `409 IDEMPOTENCY_ERROR`.
- Missing key: `428 PRECONDITION_REQUIRED`.
- Event tồn tại nhưng chưa committed/snapshot thiếu: `409 IDEMPOTENCY_IN_PROGRESS`.
- Session row lock + status/version CAS + balance version CAS + unique movement key chặn race/retry.
- Posting event nằm trong cùng transaction với session/variance/balance/movement/physical updates.

### 4.5 Movement acknowledgement

Review lưu độc lập:

- `detected_movement_ids`
- `acknowledged_movement_ids`
- `acknowledged_by`
- `acknowledged_at`

Apply chỉ chạy khi detected set rỗng hoặc:

```text
sort(unique(detected IDs)) == sort(unique(acknowledged IDs))
acknowledged_by IS NOT NULL
acknowledged_at IS NOT NULL
```

Thiếu acknowledgement trả `428`; exact-set mismatch trả `409`.

### 4.6 Three-layer transaction

Trong cùng transaction:

1. Lock scoped session và approved variances.
2. Xác minh exact variance set và movement acknowledgement.
3. Lock balance; kiểm tra review baseline/version và signed ledger reconcile.
4. Với effect khác `0`: tạo append-only movement và CAS balance.
5. Update Item/Container đúng grain.
6. Mark variances/session applied.
7. Insert committed posting event + response snapshot.

Bất kỳ lỗi nào ở Item/Container rollback toàn bộ các bước trước đó.

## 5. Runtime API routes

Các route canonical đã được đăng ký trong `Modules/Warehouse/routes/api.php`:

```text
GET  /api/v1/stocktake-sessions
POST /api/v1/stocktake-sessions
GET  /api/v1/stocktake-sessions/{id}
POST /api/v1/stocktake-sessions/{id}/start
POST /api/v1/stocktake-sessions/{id}/counts
POST /api/v1/stocktake-sessions/{id}/review
POST /api/v1/stocktake-sessions/{id}/acknowledge-movements
GET  /api/v1/stocktake-sessions/{id}/variances
POST /api/v1/stocktake-sessions/{id}/apply-adjustment
GET  /api/v1/stocktake/sessions   # legacy scoped alias
```

Read routes dùng `inventory.view`; write/apply routes dùng `stocktake.adjust`; toàn bộ nằm dưới `auth:api`.

## 6. Automated verification

Test-case register: `docs/testing/WWHN-131-stocktake-test-cases.json`, version `1.1.0`, status `PROPOSED`.

| TC | Nội dung |
|---|---|
| `STK-TC-001` | Create DRAFT, start snapshot một lần, stale CAS bị chặn. |
| `STK-TC-002` | Duplicate count và grain ngoài snapshot bị chặn. |
| `STK-TC-003` | `100 - 10`, actual `90`, variance/effect `0`, signed reconcile pass. |
| `STK-TC-004` | Thiếu/sai movement acknowledgement trả `428/409`. |
| `STK-TC-005` | Foreign/missing variance ID bị từ chối. |
| `STK-TC-006` | Commit, timeout-after-commit replay, payload conflict, unique event. |
| `STK-TC-007` | Physical failure rollback balance/movement/item/session. |
| `STK-TC-008` | Warehouse scope trên create/read/apply/legacy list. |
| `STK-TC-009` | Newman canonical flow và idempotency negative cases. |
| `STK-TC-010` | Zero-effect không tạo movement rỗng, không tăng balance version. |
| `STK-TC-011` | Ledger-balance mismatch trả `409` và rollback operation. |

Automated test files:

- `tests/Feature/StocktakeThreeLayerContractTest.php`
- `tests/Feature/StocktakeApplyLedgerIntegrityTest.php`

Contract verifier `scripts/verify-stocktake-contract.mjs`, schema version `1.1.0`, kiểm tra:

- Exact OpenAPI path/operation ID/bearer security.
- Runtime route + `auth:api` + exact permission.
- Postman endpoint/header/error assertions.
- Đủ `STK-TC-001..011` và test register `1.1.0`.
- Source guard cho idempotency, acknowledgement, ledger mismatch, zero-effect, NOT NULL grain, rollback và data scope.
- Test/source register vẫn `PROPOSED`.

## 7. Postman/Newman

- Collection: `docs/postman/WWHN-131 Stocktake Contract.postman_collection.json`
- Environment: `docs/postman/WWHN-131 Stocktake CI.postman_environment.json`
- Version: `1.0.0`
- Credential/token không commit; CI truyền bằng `--env-var`.

Canonical flow:

```text
login → select authoritative balance → create DRAFT → start → count SKU
→ review signed ledger → missing key 428 → apply → replay → payload conflict 409
```

## 8. Immutable evidence pack

Workflow `.github/workflows/auth-rbac-audit-contract.yml` chạy trên cả `push` và `pull_request` vào `m1`.

Artifact:

```text
stocktake-evidence-<exact_commit_sha>
```

Evidence pipeline đóng gói:

- `build-metadata.json`: exact commit SHA, source-tree SHA-256, runtime versions, Postman/test/verifier versions và status.
- `source-tree.sha256`: SHA-256 của `git archive HEAD`.
- `SHA256SUMS`: hash của mọi evidence file/source copy.
- OpenAPI JSON/YAML + generation log.
- Runtime route list + permission catalog.
- Main API và stocktake Newman raw/JSON.
- Full PHPUnit raw log.
- Migration/seed logs.
- DB before/after snapshot.
- Source Register + test-case register.
- Source copies: route, controller, services, guard, grain service, migration và cả hai feature tests.
- `stocktake-contract-verification.json` phải có `summary.failed = 0` và `status = PASS`.

Verification command:

```bash
bash scripts/collect-api-data-evidence.sh
(cd build/evidence/api-data && sha256sum --check SHA256SUMS)
```

## 9. Source Register và traceability

Source of truth:

```text
docs/docs_task_jira/WWHN-131-source-register.json
version 1.1.0 / PROPOSED
```

Repository sources có path/version và nhận SHA-256 thật từ exact CI artifact.

External sources chưa được giả lập vì Atlassian connector trả `403 — app is not installed on this instance`:

- WWHN-25 Decision attachment.
- WWHN-31 OpenAPI review attachment.
- WWHN-33 QA report attachment.
- WWHN-40 gate minutes attachment.
- Current BRD/SRS/BPD/DEC-INV attachment IDs và SHA-256.

BA/BAS phải cập nhật attachment ID/hash thật; PM/QA mới được xác nhận Decision rebase.

## 10. Approval gates

| Gate | Owner/Reviewer | Required evidence | Status |
|---|---|---|---|
| Decision | BA / Tech Lead / PM | Jira decision exact version/hash/date | PENDING |
| Core correctness | BE1 / Tech Lead / QA1 | race/retry/timeout-after-commit on exact SHA | PENDING INDEPENDENT REVIEW |
| Data/RBAC | BE1 / QA1 / Security | cross-session + cross-warehouse negatives | PENDING INDEPENDENT REVIEW |
| Inventory | BE1+BE2 / Tech Lead / QA1 | signed ledger/balance/reconcile including zero-effect | PENDING INDEPENDENT REVIEW |
| Three-layer integrity | BE1+BE2 / QA1 | API/DB rollback before-after | PENDING INDEPENDENT REVIEW |
| Schema | BE1 / DBA / Tech Lead | migration rehearsal on target engine/data | PENDING DBA |
| Contract | BA+BE1 / QA1 / FE | exact OpenAPI/Postman/TC hashes | PENDING |
| Traceability | BA/BAS/BE1 / PM/QA | current BRD/SRS/BPD/DEC-INV IDs/hashes | BLOCKED SOURCE IDS |
| Process | BE1 / BA | structured Jira handoff | BLOCKED ATLASSIAN APP |

## 11. Structured Jira handoff payload

```text
[WWHN-131 STOCKTAKE HANDOFF]
Document: docs/docs_task_jira/WWHN-131.md
Document version/status: 1.1.0 / PROPOSED
Branch: m1
Technical implementation baseline: f9a2280c35da797fb0c32b4e6d66d7d7e039b328
Source Register version/status: 1.1.0 / PROPOSED
Source Register commit: 41aeacd73f0fd9bfdae32bb806622ab84dd3224f
Exact review commit: <build-metadata.json.commit_sha>
Source-tree SHA-256: <source-tree.sha256>
Artifact: stocktake-evidence-<exact review commit>
SHA256SUMS validation: PASS|FAIL — <artifact/link>

Scope completed in repository:
- DRAFT create / explicit start / CAS state machine
- SKU, ITEM, CONTAINER canonical grain and DB constraints
- signed qty_delta and SUM(qty_delta)
- pre-apply ledger-balance reconcile
- zero-effect: no empty movement/no balance version increment
- movement acknowledgement exact-set guard
- idempotency/replay/same-key conflict
- exact variance set/session scope
- three-layer atomic rollback
- warehouse data scope including apply
- runtime lifecycle routes

Key decisions:
- ADJUST_OUT is negative qty_delta
- apply accepts variance_ids only
- requested set equals all APPROVED variances of session
- same key/different payload returns 409
- missing movement acknowledgement returns 428
- ledger/balance mismatch returns 409 and no operation commit
- zero inventory effect creates no stock movement

OpenAPI hash: <sha256>
Postman v1.0.0 hash: <sha256>
Test cases v1.1.0 hash: <sha256>
Verifier v1.1.0 result/hash: PASS|FAIL / <sha256>
Migration hash: <sha256>
PHPUnit: PASS|FAIL — <raw log>
Stocktake Newman: PASS|FAIL — <raw/json>
DB before/after + reconcile: PASS|FAIL — <artifact>

Reviewers:
- BA: <name/date/result>
- Tech Lead: <name/date/result>
- QA1: <name/date/result>
- DBA: <name/date/result>
- Security: <name/date/result>
- PM: <name/date/decision>

Impacted tickets:
WWHN-25, WWHN-31, WWHN-33, WWHN-40, WWHN-131
External register:
BRD <attachment/hash>; SRS <attachment/hash>; BPD <attachment/hash>; DEC-INV <attachment/hash>

Final status: PROPOSED|CONDITIONAL GO|APPROVED|NO-GO
Open blockers: <exact list>
```

## 12. Acceptance checklist trước khi đổi `APPROVED`

- [ ] Chọn exact commit SHA từ artifact; không review theo branch động.
- [ ] GitHub workflow trên SHA đó `success`.
- [ ] Download `stocktake-evidence-<SHA>`.
- [ ] `sha256sum --check SHA256SUMS` PASS.
- [ ] `source-tree.sha256` khớp source được review.
- [ ] Full PHPUnit + cả hai Newman run PASS.
- [ ] `stocktake-contract-verification.summary.failed = 0`.
- [ ] QA1 attach race/retry/timeout, scope và rollback evidence.
- [ ] Tech Lead/QA1 xác nhận signed ledger, zero-effect và mismatch guard.
- [ ] DBA approve migration trên target DB/data copy.
- [ ] BA/FE xác nhận OpenAPI/Postman/state contract cùng hash.
- [ ] BA/BAS cập nhật current BRD/SRS/BPD/DEC-INV attachment ID/hash.
- [ ] Tech Lead/PM ghi formal Jira decision với reviewer/date.

Các checkbox trên là điều kiện cần hoàn thành, không phải approval evidence hiện tại.

## 13. Current truthful status

Các action P0/P1/P2 có thể xử lý trong repository đã được triển khai trực tiếp trên `m1`, gồm runtime wiring, core transaction, idempotency, data scope, signed ledger, zero-effect, three-layer rollback, schema hardening, test register, verifier, evidence pipeline, Source Register và Jira handoff template.

Chưa tuyên bố `100% / APPROVED` vì bốn phần không thể được tạo giả từ code:

1. GitHub Actions trên exact final SHA phải hoàn tất và artifact phải kiểm tra được.
2. QA1/Tech Lead/DBA/Security phải independent review trên cùng artifact/hash.
3. BA/BAS phải cung cấp current BRD/SRS/BPD/DEC-INV attachment IDs/hashes.
4. Formal Jira approval/comment chưa thể đăng khi Atlassian app chưa được cài trên instance.
