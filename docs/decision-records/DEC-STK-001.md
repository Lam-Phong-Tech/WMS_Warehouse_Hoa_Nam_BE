# DEC-STK-001 — Mô hình kiểm kê A

## Document control

| Field | Value |
|---|---|
| Decision ID | `DEC-STK-001` |
| Jira parent | `WWHN-25` |
| BE draft task | `WWHN-131` |
| Parent Temp Key | `HN-DEC-003` |
| Epic Temp Key | `HN-EPIC-01` |
| Version | `1.0.0` |
| Status | **PROPOSED** |
| Proposed by | BE1 — Quang Nguyễn Thanh |
| Proposed date | `2026-08-04` |
| Required approvers | BA, Tech Lead, PM |
| Effective date | `NULL — chỉ có sau formal sign-off` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` |
| Exact evidence source | `build/evidence/wwhn-131/build-metadata.json.commit_sha` |

Tài liệu này là **bản nháp Decision Record**. Không được diễn giải là `APPROVED`, `GO`, Jira Done hoặc 100% formal closure trước khi các approver ghi kết quả trên Jira với cùng exact commit SHA và artifact digest.

## Context

Kiểm kê diễn ra trong khi kho vẫn có thể phát sinh nhập, xuất hoặc adjustment. Nếu expected quantity chỉ lấy snapshot lúc start thì movement xảy ra trước thời điểm đếm làm variance sai. Nếu lấy live balance tại review thì kết quả phụ thuộc thời điểm review, không tái lập được và có thể che mất chênh lệch vật lý.

Baseline nghiệp vụ v3.9 yêu cầu:

```text
expected_at_count = snapshot_qty + net signed movement từ stocktake_started_at đến count_cutoff_at
variance_qty      = actual_qty - expected_at_count
```

`stock_movements.qty_delta` dùng signed convention: receipt/adjust-in dương; issue/adjust-out âm. Vì vậy net movement là `SUM(qty_delta)`, không đảo dấu lần hai.

Mô hình phải bảo toàn ba grain `SKU`, `ITEM`, `CONTAINER`; data scope theo warehouse; movement acknowledgment; idempotency; concurrency guard; append-only ledger; rollback toàn transaction.

## Decision drivers

1. Kết quả kiểm kê phải deterministic và audit được.
2. Không freeze toàn kho trong suốt phiên kiểm kê MVP.
3. Không cho client tự gửi expected hoặc inventory effect.
4. Không double apply khi retry, race hoặc timeout-after-commit.
5. Không được dùng variance của session khác hoặc warehouse ngoài scope.
6. Balance, movement, variance, posting event và physical layer phải commit/rollback cùng nhau.
7. OpenAPI, Postman, testcase và evidence phải bind exact SHA.

## Options considered

### Option A — Snapshot + signed net movement đến count cutoff

- Create session ở `DRAFT`; `/start` tạo snapshot và chuyển `IN_PROGRESS`.
- Mỗi count có `counted_at/count_cutoff_at`.
- Review tính expected bằng snapshot cộng signed movement đến cutoff.
- Movement phát sinh được backend detect; apply bị chặn cho đến khi acknowledged set khớp exact detected set.
- Apply dùng session row lock, session/balance CAS, full approved variance set và idempotency event.

**Ưu điểm:** deterministic, audit được, không cần freeze kho, phù hợp baseline v3.9.  
**Nhược điểm:** implementation/test phức tạp hơn; cần movement ledger chính xác và acknowledgment UX.

### Option B — Freeze warehouse trong toàn bộ session

- Chặn mọi stock posting từ lúc start đến apply/cancel.

**Ưu điểm:** expected đơn giản.  
**Nhược điểm:** ảnh hưởng vận hành lớn, lock kéo dài, không phù hợp MVP kho hoạt động liên tục.

### Option C — Dùng live balance tại review/apply

- Không tái dựng expected theo cutoff; lấy balance hiện tại.

**Ưu điểm:** ít bảng và truy vấn hơn.  
**Nhược điểm:** không deterministic, khó audit, race cao, có thể che movement và variance thật.

## Proposed decision

**Chọn Option A — Mô hình kiểm kê A: snapshot + signed net movement đến count cutoff.**

State machine canonical:

```text
DRAFT --start(CAS)--> IN_PROGRESS --review(CAS)--> REVIEWED --apply(CAS)--> APPLIED
```

Rules bắt buộc:

1. Create luôn trả `DRAFT`, `session_version=1`; snapshot chỉ tạo tại `/start`.
2. Snapshot/count/variance dùng canonical `grain_type`, `grain_id`, `grain_key` và unique `(session_id, grain_key)`.
3. Review dùng `SUM(stock_movements.qty_delta)` theo warehouse/product/status và cutoff; `ADJUST_OUT`/`ISSUE_OUT` là số âm.
4. Apply chỉ nhận `session_version`, exact full set `variance_ids`, `reason` và header `Idempotency-Key`.
5. Scope check xảy ra trước posting-event replay để không lộ response snapshot của warehouse khác.
6. Same key/same payload replay committed response; same key/different payload trả `409`; pending event trả `409`.
7. Foreign, missing, partial hoặc duplicate variance IDs bị từ chối và không tạo DB write.
8. Detected movement IDs và acknowledged movement IDs được lưu tách biệt; thiếu actor/time hoặc mismatch thì trả `428/409`.
9. Pre-apply phải reconcile `SUM(qty_delta) == inventory_balances.on_hand_qty`.
10. Zero effect không tạo movement và không tăng balance version.
11. SKU balance, ITEM/CONTAINER physical layer, variance, session, movement và posting event nằm trong một transaction.
12. Error response dùng error code ổn định, không lộ stack trace/secret.

## API and data impact

Affected endpoints:

- `GET/POST /api/v1/stocktake-sessions`
- `GET /api/v1/stocktake-sessions/{id}`
- `POST /api/v1/stocktake-sessions/{id}/start`
- `POST /api/v1/stocktake-sessions/{id}/counts`
- `POST /api/v1/stocktake-sessions/{id}/review`
- `POST /api/v1/stocktake-sessions/{id}/acknowledge-movements`
- `GET /api/v1/stocktake-sessions/{id}/variances`
- `POST /api/v1/stocktake-sessions/{id}/apply-adjustment`
- Legacy scoped aliases under `/api/v1/stocktake/sessions`

Core tables:

- `stocktake_session`
- `stocktake_snapshot`
- `stocktake_count`
- `stocktake_variance`
- `posting_event`
- `inventory_balances`
- `stock_movements`
- `inventory_serials`
- `container_item`

RBAC:

- Read: `inventory.view`
- Write/review/apply: `stocktake.adjust`
- All endpoints remain under `auth:api` and warehouse data scope.

## Consequences

### Positive

- Variance can be reproduced from immutable snapshot, count cutoff and signed ledger.
- Warehouse can continue normal posting during count.
- Retry/race/timeout do not create duplicate inventory effect.
- Cross-session and cross-warehouse variance injection is blocked.
- Three-layer physical state and aggregate balance stay transactional.
- Evidence can be reviewed independently on exact source SHA.

### Negative / cost

- More schema constraints, indexes and migration rehearsal are required.
- FE must expose movement warning/acknowledgment state and stale baseline error.
- QA needs race, retry, rollback and cross-scope negative cases.
- DBA must rehearse migration/backfill on target PostgreSQL/data copy.
- Apply can be blocked when ledger reconciliation fails; operational runbook is required.

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Movement ledger/balance mismatch | Pre-apply reconciliation gate; no-write `409 LEDGER_BALANCE_MISMATCH` |
| Stale reviewed balance | Balance qty/version captured at review; CAS at apply |
| Duplicate request | Unique `(operation,idempotency_key)`, canonical request hash, response snapshot |
| Partial variance set | Compare requested sorted IDs with full approved set under lock |
| Cross-scope replay leak | Warehouse scope before event lookup/replay |
| ITEM/CONTAINER update failure | Entire apply transaction rolls back |
| Artifact leaks password/token | Ephemeral credentials; redaction before manifest and upload |

## Impacted tickets

Primary:

- `WWHN-25` — formal Decision Record
- `WWHN-131` — BE1 draft/implementation/evidence
- `WWHN-132` — BA final decision
- `WWHN-133` — BAS traceability

Dependent/affected gates identified in review:

- `WWHN-58`, `WWHN-230` — stocktake story/core; must follow decision status
- `WWHN-31`, `WWHN-33`, `WWHN-40` — API/test/contract governance where exact OpenAPI/Postman/TC hashes are consumed

BAS must validate the final impacted list and attach current BRD/SRS/BPD/DEC-INV source IDs/hashes.

## Evidence package

Workflow: `.github/workflows/wwhn-131-stocktake-contract.yml`  
Artifact: `stocktake-evidence-<exact_commit_sha>`

Required outputs include exact SHA metadata, source-tree hash, `SHA256SUMS`, manifest digest, generated OpenAPI, Postman/Newman, focused/full PHPUnit logs, schema verifier, migration rehearsal, DB before/after, reconciliation report, credential-hygiene report and source copies.

## Approval and effective status

Current result: **PROPOSED / NO EFFECTIVE DATE**.

Formal decision must be one of:

- `GO`
- `CONDITIONAL GO` — requires exact conditions, owner and deadline
- `NO-GO`

Required Jira record on `WWHN-25`:

```text
DEC-STK-001 version: 1.0.0
Exact BE commit SHA: <sha>
Artifact: stocktake-evidence-<sha>
Source-tree SHA-256: <hash>
SHA256SUMS digest: <hash>
QA1 result/date/evidence: <value>
Tech Lead result/date/evidence: <value>
DBA result/date/evidence: <value>
Security result/date/evidence: <value>
BA result/date: <value>
PM decision/date: <GO|CONDITIONAL GO|NO-GO>
Effective date: <ISO-8601 or NULL>
Conditions/owner/deadline: <list or NONE>
```

Until this exists, dependent tickets remain governed by **PROPOSED** status and no repository file may claim approval.
