# WWHN-131 — Stocktake contract, apply variance và integrity ba lớp

## 1. Document Control

| Trường | Giá trị |
|---|---|
| Jira key | `WWHN-131` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` — sửa trực tiếp, không tạo branch riêng |
| Document version | `1.0.0` |
| Document status | **PROPOSED** |
| Implementation commit | `04c373e37582961c8295c4f0fdecba3c16e3ae42` |
| Approval status | **PENDING FORMAL JIRA APPROVAL** |
| Artifact name | `stocktake-evidence-<exact_commit_sha>` |
| Hash source | `build/evidence/api-data/SHA256SUMS` và `source-tree.sha256` |

Không có checkbox approval được đánh dấu trong tài liệu này. Không được đổi trạng thái sang `APPROVED`, `Done` hoặc `100%` nếu chưa có Jira comment ghi đầy đủ exact commit SHA, source-tree SHA-256, artifact manifest, reviewer và ngày xác nhận.

## 2. Kết quả xử lý exact fix action register

| ID | Sev | Exact fix đã triển khai trên `m1` | Repository evidence | Trạng thái hiện tại | External closure còn lại |
|---|---|---|---|---|---|
| `STK-P0-01` | P0 | Document status đổi về `PROPOSED`; không tạo approval checkbox; source register không điền attachment/hash giả; CI sinh exact commit, source-tree SHA-256 và file SHA-256. | `WWHN-131.md`, `WWHN-131-source-register.json`, `source-tree.sha256`, `SHA256SUMS` | BE IMPLEMENTED | BA/Tech Lead/PM formal approval trên Jira với exact version/hash/date. |
| `STK-P0-02` | P0 | Apply dùng unique `(operation,idempotency_key)`, canonical SHA-256 request hash, committed response snapshot, replay sau commit, same-key/different-payload `409`, session row lock + CAS, unique movement business key theo session/dimension. | `StocktakeAdjustmentService.php`, migration, `STK-TC-006`, Postman requests 08–10 | BE IMPLEMENTED | Tech Lead + QA1 review artifact và race/retry evidence trên cùng SHA. |
| `STK-P0-03` | P0 | Apply chỉ nhận `variance_ids`; query bắt buộc `session_id`, session warehouse scope, exact requested set/count; foreign/missing IDs trả `422 INVALID_VARIANCE_SELECTION`. | `StocktakeAdjustmentService.php`, `StocktakeSessionGuard.php`, `STK-TC-005`, `STK-TC-008` | BE IMPLEMENTED | QA1/Security independent retest. |
| `STK-P0-04` | P0 | Chốt signed convention: inbound/adjust-in dương; outbound/adjust-out âm; net movement = `SUM(qty_delta)`; không đảo dấu theo `movement_type` lần hai. Case 100 − 10, actual 90 cho expected 90, variance 0, inventory effect 0. | `StocktakeService.php`, `StockMovement.php`, `STK-TC-003`, evidence snapshot schema v2 | BE IMPLEMENTED | BE2/Tech Lead/QA1 xác nhận ledger/balance/reconcile. |
| `STK-P0-05` | P0 | Apply cập nhật balance, append-only movement, item serial và container item trong cùng transaction. Một lỗi physical layer rollback toàn session, balance, movement và item/container. | `StocktakeAdjustmentService.php`, `STK-TC-007`, DB before/after | BE IMPLEMENTED | QA1 chạy API/DB rollback độc lập. |
| `STK-P1-01` | P1 | Migration defensive bảo đảm `stocktake_session.warehouse_id`, FK, index `(warehouse_id,status)`, nullable start timestamp cho DRAFT, reviewed metadata và model đồng nhất. | migration `2026_08_03_000100...`, `StocktakeSession.php` | BE IMPLEMENTED | DBA/Tech Lead approve DDL. |
| `STK-P1-02` | P1 | Thay nullable composite uniqueness bằng canonical `grain_type/grain_id/grain_key`; unique `(session_id,grain_key)` cho snapshot/count/variance; migration dừng an toàn nếu có legacy duplicate. | migration + `StocktakeGrainService.php`, `STK-TC-002` | BE IMPLEMENTED | DBA/QA1 constraint retest trên target DB. |
| `STK-P1-03` | P1 | Chọn một contract duy nhất: create luôn `DRAFT`; endpoint `/start` thực hiện CAS `DRAFT → IN_PROGRESS` và tạo snapshot đúng một lần. | OpenAPI annotations, routes, `STK-TC-001`, Postman requests 03–04 | BE IMPLEMENTED | BA/QA1/FE xác nhận contract cùng version. |
| `STK-P1-04` | P1 | Tách `detected_movement_ids` và `acknowledged_movement_ids`; lưu `acknowledged_by/at`; exact-set mismatch trả `409`; apply chưa ack trả `428`. | model/migration/service, `STK-TC-004` | BE IMPLEMENTED | BA/QA1 negative API sign-off. |
| `STK-P1-05` | P1 | OpenAPI sinh từ source; Postman stocktake v1.0.0 chạy Newman; test-case register v1.0.0; verifier đối chiếu runtime route, permission, OpenAPI, Postman và test IDs; toàn bộ file có SHA-256 trong cùng artifact. | evidence pipeline, `stocktake-contract-verification.json`, Newman JSON/raw log | AUTOMATION IMPLEMENTED | WWHN-31/33/40 reviewer xác nhận exact artifact SHA/hash. |
| `STK-P1-06` | P1 | Source Register tách repository-controlled sources và external BRD/SRS/BPD/DEC-INV. Không tự điền attachment ID hoặc hash khi Atlassian không truy cập được. | `WWHN-131-source-register.json` | PROPOSED / EXTERNAL BLOCKED | BA/BAS cung cấp current attachment IDs + SHA-256; PM/QA xác nhận rebase. |
| `STK-P2-01` | P2 | Jira handoff payload chuẩn hóa ở mục 10, gồm file/version/hash/status/scope/key decision/reviewer/impacted tickets. | mục 10 | READY, NOT POSTED | Atlassian connector hiện trả `403 app is not installed`; đăng comment khi site/app khả dụng. |

## 3. Contract nghiệp vụ đã khóa trong code

### 3.1 State machine

```text
DRAFT --start(CAS)--> IN_PROGRESS --count/review--> REVIEWED --apply(CAS)--> APPLIED
```

- `POST /api/v1/stocktake-sessions` chỉ tạo `DRAFT`, `session_version = 1`, chưa có snapshot.
- `POST /api/v1/stocktake-sessions/{id}/start` bắt buộc `session_version`; giữ row lock, CAS trạng thái/version, sau đó tạo snapshot SKU/ITEM/CONTAINER.
- Count chỉ nhận grain đã tồn tại trong snapshot của chính session. Grain phát sinh sau start bị từ chối.
- Review tạo variance backend-authoritative. Client không gửi `expected_qty`, `actual adjustment` hoặc movement effect vào apply.
- Apply bắt buộc chọn toàn bộ variance `APPROVED` của đúng session, không cho partial set âm thầm.

### 3.2 Grain integrity

| Grain | `grain_id` | Physical source | Actual rule | Apply physical effect |
|---|---|---|---|---|
| `SKU` | `product_id` | `inventory_balances` | số lượng không âm | balance + signed movement |
| `ITEM` | `inventory_serials.id` | `inventory_serials` | chỉ `0` hoặc `1` | `IN_STOCK` hoặc `MISSING`, cập nhật stock condition |
| `CONTAINER` | `container_item.container_item_id` | `container_item` + warehouse của container | số lượng không âm | cập nhật đúng `container_item.quantity` |

DB unique source of truth:

```text
UNIQUE(session_id, grain_key)
grain_key = grain_type | grain_id | stock_status
```

### 3.3 Signed ledger convention

```text
positive qty_delta: OPENING, RECEIPT_POSTED, ADJUST_IN, TRANSFER_IN,
                    TRANSFER_RETURN, ISSUE_REVERSED
negative qty_delta: ISSUE_POSTED, ADJUST_OUT, TRANSFER_OUT, RECEIPT_REVERSED
net movement:       SUM(stock_movements.qty_delta)
```

Không tồn tại bước mapping dấu lần hai theo `movement_type`.

Đối với dimension SKU:

```text
expected_at_count = snapshot_qty + SUM(qty_delta từ started_at đến count cutoff)
variance_qty      = actual_qty - expected_at_count
inventory_effect  = variance_qty
```

Đối với physical grain ITEM/CONTAINER:

```text
physical variance per grain = actual grain qty - snapshot grain qty
inventory effect dimension  = SUM(actual grain qty)
                              - (SUM(snapshot grain qty) + signed net movement)
```

`inventory_effect_qty` chỉ được phân bổ một lần cho dimension, tránh cộng movement đã phát sinh hai lần.

### 3.4 Apply idempotency và concurrency

Operation key:

```text
operation       = stocktake_apply_adjustment_{session_id}
idempotency key = request header Idempotency-Key
unique key      = UNIQUE(operation, idempotency_key)
request hash    = SHA-256(canonical session/version/sorted variance IDs/reason)
movement key    = SHA-256(stocktake-adjust|session|product|stock_status)
```

Behavior:

- Same key + same canonical payload sau commit: trả `response_snapshot`, không chạy transaction lần hai.
- Same key + different payload: `409 IDEMPOTENCY_ERROR`.
- Missing key: `428 PRECONDITION_REQUIRED`.
- Different key sau session đã apply hoặc stale version: `409`.
- Request cạnh tranh bị chặn bởi session `lockForUpdate`, balance optimistic version và unique movement business key.
- Posting event được insert trong cùng transaction với balance/movement/physical/session. Timeout ở client sau DB commit vẫn replay được.

### 3.5 Movement acknowledgement

Review lưu hai trường độc lập:

- `detected_movement_ids`: backend phát hiện từ signed ledger.
- `acknowledged_movement_ids`: reviewer xác nhận exact set.

Nếu detected set không rỗng, apply chỉ chạy khi:

```text
sort(unique(detected IDs)) == sort(unique(acknowledged IDs))
acknowledged_by IS NOT NULL
acknowledged_at IS NOT NULL
```

## 4. DDL và migration safety

Migration:

`Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php`

Nội dung chính:

- Bổ sung/chuẩn hóa warehouse FK và session scope index.
- Cho phép `stocktake_started_at = null` ở DRAFT.
- Bổ sung reviewed metadata.
- Backfill legacy rows sang grain key trước khi đặt NOT NULL và unique.
- Dừng migration nếu phát hiện duplicate grain hoặc duplicate posting event; không tự xóa/chọn dữ liệu để “làm migration pass”.
- Liên kết `stock_movements.stocktake_variance_id` để trace adjustment.

Target DBA phải chạy migration/rehearsal trên bản sao dữ liệu thật và attach raw log. SQLite CI chứng minh portability baseline nhưng không thay DBA approval trên production engine.

## 5. Automated verification

### 5.1 PHPUnit register

File: `tests/Feature/StocktakeThreeLayerContractTest.php`

| TC | Nội dung |
|---|---|
| `STK-TC-001` | Create DRAFT, start snapshot một lần, stale CAS bị chặn |
| `STK-TC-002` | Duplicate count và grain ngoài snapshot bị chặn |
| `STK-TC-003` | 100 − 10, actual 90, variance/effect 0, signed reconcile pass |
| `STK-TC-004` | Thiếu/sai movement acknowledgment trả 428/409 |
| `STK-TC-005` | Foreign/missing variance ID bị từ chối |
| `STK-TC-006` | Commit, replay timeout-after-commit, payload conflict, unique event |
| `STK-TC-007` | Physical failure rollback balance/movement/item/session |
| `STK-TC-008` | Warehouse data scope trên create/read/legacy list |
| `STK-TC-009` | Newman canonical flow và idempotency negative cases |

### 5.2 Postman/Newman

- Collection: `docs/postman/WWHN-131 Stocktake Contract.postman_collection.json`
- Environment: `docs/postman/WWHN-131 Stocktake CI.postman_environment.json`
- Version: `1.0.0`
- Credential/token không được commit; CI truyền bằng `--env-var`.

Newman flow:

```text
login → select authoritative balance → create DRAFT → start → count SKU
→ review signed ledger → missing key 428 → apply → replay → payload conflict 409
```

### 5.3 Contract verifier

`scripts/verify-stocktake-contract.mjs` kiểm tra:

- Required OpenAPI path + exact operation ID + bearer security.
- Runtime route + `auth:api` + exact permission.
- Postman endpoint/header/error assertions.
- Test-case IDs `STK-TC-001..009`.
- Required implementation/migration/test guards tồn tại trong source.
- Test/source register giữ trạng thái `PROPOSED`.

## 6. Immutable evidence pack

Workflow: `.github/workflows/auth-rbac-audit-contract.yml`

Artifact: `stocktake-evidence-<exact_commit_sha>`

Bắt buộc có:

- `build-metadata.json`: exact commit SHA, source-tree SHA-256, runtime versions, document status.
- `source-tree.sha256`: SHA-256 của `git archive HEAD`.
- `SHA256SUMS`: SHA-256 cho mọi file evidence, kể cả source copy.
- OpenAPI JSON/YAML và raw generation log.
- Runtime route list và permission catalog.
- Main API Newman raw/JSON.
- Stocktake Newman raw/JSON.
- `stocktake-contract-verification.json`.
- Full PHPUnit raw log.
- Migration/seed raw logs.
- DB before/after gồm balance, signed ledger, stocktake session/variance/movement, item và container item.
- Source Register và test-case register.

Verification command:

```bash
bash scripts/collect-api-data-evidence.sh
(cd build/evidence/api-data && sha256sum --check SHA256SUMS)
```

## 7. Source Register và traceability

File source of truth:

`docs/docs_task_jira/WWHN-131-source-register.json`

Repository-controlled sources đã được gắn path/version và sẽ nhận SHA-256 thật từ CI artifact.

Các external source sau **không được tự điền giả** vì Atlassian connector trả `403 — app is not installed on this instance`:

- WWHN-25 decision attachment.
- WWHN-31 OpenAPI review attachment.
- WWHN-33 QA report attachment.
- WWHN-40 gate minutes attachment.
- Current BRD/SRS/BPD/DEC-INV attachment IDs và SHA-256.

BA/BAS phải cập nhật attachment ID/hash thật rồi PM/QA mới xác nhận rebase Decision.

## 8. Approval gates

| Gate | Owner | Required evidence | Status |
|---|---|---|---|
| Decision | BA | PROPOSED → Go/Conditional Go/No-Go trên Jira, exact version/hash/date | PENDING |
| Core correctness | Tech Lead + QA1 | race/retry/timeout-after-commit tests trên exact SHA | PENDING INDEPENDENT REVIEW |
| Data/RBAC | QA1/Security | cross-session + warehouse scope negative evidence | PENDING INDEPENDENT REVIEW |
| Inventory | Tech Lead + QA1 | signed ledger/balance/reconcile report | PENDING INDEPENDENT REVIEW |
| Three-layer integrity | QA1 | API/DB rollback before-after | PENDING INDEPENDENT REVIEW |
| Schema | Tech Lead/DBA | migration rehearsal và approved DDL | PENDING DBA |
| Contract | BA/QA1/FE | OpenAPI/Postman/TC cùng exact hash | PENDING |
| Traceability | PM/QA | BRD/SRS/BPD/DEC-INV current attachment register | BLOCKED SOURCE IDS |
| Process | BA | Structured Jira handoff comment | BLOCKED ATLASSIAN APP |

## 9. Acceptance checklist trước khi đổi APPROVED

- [ ] Chọn exact commit SHA, không review theo branch động.
- [ ] GitHub workflow trên SHA đó có trạng thái `success`.
- [ ] Download artifact `stocktake-evidence-<SHA>`.
- [ ] `sha256sum --check SHA256SUMS` PASS.
- [ ] `source-tree.sha256` khớp artifact/source được review.
- [ ] PHPUnit và cả hai Newman run PASS.
- [ ] `stocktake-contract-verification.summary.failed = 0`.
- [ ] QA1 attach race/retry/timeout-after-commit, cross-session và rollback evidence.
- [ ] DBA approve migration trên target DB engine.
- [ ] BA/FE xác nhận OpenAPI/Postman/state contract cùng version/hash.
- [ ] BA/BAS cập nhật current BRD/SRS/BPD/DEC-INV attachment ID/hash.
- [ ] Tech Lead/PM ghi formal decision trên Jira với reviewer/date.

Các checkbox trên chỉ là checklist cần hoàn thành; hiện không checkbox nào được dùng làm bằng chứng approval trong tài liệu PROPOSED này.

## 10. Structured Jira handoff payload

```text
[WWHN-131 STOCKTAKE HANDOFF]
Document: docs/docs_task_jira/WWHN-131.md
Document version/status: 1.0.0 / PROPOSED
Branch: m1
Implementation commit: 04c373e37582961c8295c4f0fdecba3c16e3ae42
Exact review commit: <from build-metadata.json>
Source-tree SHA-256: <from source-tree.sha256>
Artifact: stocktake-evidence-<exact review commit>
Artifact SHA256SUMS: PASS|FAIL — <attachment/link>

Scope:
- Create DRAFT / explicit start
- SKU, ITEM, CONTAINER grain constraints
- signed qty_delta and canonical SUM(qty_delta)
- movement acknowledgement
- apply idempotency/CAS/replay
- three-layer atomic rollback
- warehouse data scope

Key decisions:
- ADJUST_OUT is negative qty_delta
- apply accepts variance_ids only
- requested variance set must equal all APPROVED variances of session
- same key/different payload returns 409
- missing movement acknowledgement returns 428

OpenAPI hash: <sha256>
Postman v1.0.0 hash: <sha256>
Test cases v1.0.0 hash: <sha256>
Migration hash: <sha256>
PHPUnit: PASS|FAIL — <raw log>
Stocktake Newman: PASS|FAIL — <raw/json>
DB before/after + reconcile: PASS|FAIL — <attachment>

Reviewers:
- BA: <name/date/result>
- Tech Lead: <name/date/result>
- QA1: <name/date/result>
- DBA: <name/date/result>
- Security: <name/date/result>
- PM: <name/date/decision>

Impacted tickets:
WWHN-25, WWHN-31, WWHN-33, WWHN-40, WWHN-131
External source register:
BRD <attachment/hash>; SRS <attachment/hash>; BPD <attachment/hash>; DEC-INV <attachment/hash>

Final status: PROPOSED|CONDITIONAL GO|APPROVED|NO-GO
Open blockers: <exact list>
```

## 11. Current truthful status

Repository-controlled implementation and automated evidence configuration are present on `m1`.

Formal `100% / APPROVED` is not claimed because these actions cannot be fabricated from repository code:

1. GitHub workflow success and downloadable artifact must exist on the final exact SHA.
2. QA1/Tech Lead/DBA/Security independent reviews must be attached.
3. Current BRD/SRS/BPD/DEC-INV attachment IDs/hashes must come from BA/BAS.
4. Jira formal approval/comment cannot be posted while the Atlassian app is unavailable.
