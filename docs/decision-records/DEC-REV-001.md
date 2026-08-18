# DEC-REV-001 — Đảo giao dịch/điều chỉnh theo append-only

- Contract release: `1.3.0-proposed`
- Trạng thái: `PROPOSED — PENDING INDEPENDENT SIGN-OFF`
- Immutable release path: `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/`
- Decision Owner: Nguyễn Quang Đức (PM/PO)
- Technical Owner/Author: Quang Nguyễn Thanh (BE1)
- Parent: WWHN-26
- Delivery task: WWHN-134
- Traceability task: WWHN-136
- Prior proposal commit: `f9c8c5ca74017c4269cf503bd7d6222b5ad3cc9c`
- Release parent observed: `7d7091c48798114b5bbf7ff4c0b58617d177c737`

## 1. Nguồn chuẩn và supersession

Nguồn điều khiển:

- BRD v3.11;
- SRS/FRD v1.4;
- BPD v1.3;
- WWHN-17;
- `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`.

BRD v3.9 và các handoff dựa trên v3.9 là `SUPERSEDED`; chỉ dùng lịch sử, không dùng làm baseline triển khai.

## 2. Quyết định đề xuất

Chọn Option B: mọi reversal/correction phải append-only. Không sửa hoặc xóa `inventory_movements` đã phát sinh. Reversal tạo movement đối ứng, giữ reference bất biến tới giao dịch/movement gốc và cập nhật balance trong cùng transaction.

Lý do:

- bảo toàn audit trail và khả năng forensic;
- reconciliation được từ movement ledger;
- loại bỏ chỉnh sửa lịch sử và double counting;
- phù hợp nguyên tắc transaction, row-level lock, idempotency và backend authorization trong technical guide.

## 3. Bất biến nghiệp vụ

1. Chỉ document `POSTED` và còn reversible quantity mới được đảo.
2. Tổng quantity đảo không vượt quantity gốc theo line/SKU/serial/box.
3. Reversal không được reverse lại; correction chain không tạo cycle.
4. Movement gốc bất biến; movement đối ứng có `reversal_of_movement_id`.
5. Balance, movement, reversal, audit/outbox và idempotent response snapshot phải atomic.
6. Lock document/line và balance keys theo thứ tự ổn định trước khi revalidate.
7. Cùng `Idempotency-Key` + cùng canonical payload: replay, không có second write.
8. Cùng key + payload khác: HTTP 409, `error.code=IDEMPOTENCY_ERROR`.
9. Backend enforce permission `stock.reversal.create` và warehouse/data scope.
10. Audit có actor, request_id, idempotency-key hash, reason, references, before/after và timestamp.
11. Error response không lộ stack trace, SQL, secret hoặc internal path.
12. Scan/lưu nháp không đổi tồn; chỉ reversal đã post mới tạo stock effect.

## 4. State model và PENDING_RETURN

Document status chỉ dùng:

`DRAFT | CONFIRMED | POSTED | CANCELLED`

`PENDING_RETURN` không phải document status. Trạng thái hoàn trả vật lý được quản lý tại line/evidence:

`NOT_REQUIRED | PENDING_RETURN | RETURNED | WAIVED`

UI có thể tổng hợp để hiển thị nhưng không được làm thay đổi document state machine.

## 5. Target API contract

- Canonical proposal: `POST /api/v1/reversals`
- Permission: `stock.reversal.create`
- Data scope: warehouse
- Required headers: `Authorization`, `X-Request-Id`, `Idempotency-Key`
- First success: HTTP 201
- Same-key/same-payload replay: HTTP 200
- Errors:
  - 401 `UNAUTHENTICATED`
  - 403 `NO_PERMISSION`
  - 404 `ORIGINAL_NOT_FOUND`
  - 409 `IDEMPOTENCY_ERROR`, `INVALID_STATE`, `REVERSAL_LIMIT_EXCEEDED`, `REVERSAL_DEPENDENCY_CONFLICT`, `CONCURRENT_MODIFICATION`
  - 422 `VALIDATION_ERROR`
  - 428 `IDEMPOTENCY_KEY_REQUIRED`

The complete contract is in the immutable OpenAPI, JSON Schema and Postman artifacts. Canonical alias files must be byte-identical to the immutable release copies.

## 6. Runtime divergence and adoption gate

Observed runtime at parent commit `7d7091c48798114b5bbf7ff4c0b58617d177c737`:

- `POST /api/v1/inbound-documents/{id}/reverse`
- `POST /api/v1/outbound-documents/{id}/reverse`
- permission `stock_document.reverse`

Target proposal:

- `POST /api/v1/reversals`
- permission `stock.reversal.create`

Disposition: `FAIL-CLOSED / NOT IMPLEMENTATION EVIDENCE`.

This release does not silently rename or alias routes. Adoption requires a separate implementation change with:

- controller/service/use-case and migration impact review;
- exact-SHA PHPUnit/integration/Newman evidence;
- PostgreSQL transaction/locking/race verification;
- DB before/after movement and balance delta;
- permission/data-scope 401/403 evidence;
- replay/different-payload/rollback/audit/reconciliation evidence;
- independent QA and FE result.

## 7. Transaction and concurrency sequence

1. Validate request and mandatory headers.
2. Resolve authentication, permission and warehouse scope.
3. Begin database transaction.
4. Claim idempotency key using a unique scope and canonical payload hash.
5. Lock original document and lines in deterministic order.
6. Lock affected inventory balances in deterministic order.
7. Revalidate state, dependencies, reversible quantity and no-negative rule.
8. Create reversal document and lines.
9. Append opposite movements and update balance.
10. Write audit and outbox records.
11. Persist response snapshot and commit.
12. Roll back all writes on any failure.

## 8. Testability scope

Contract review must cover:

- happy 201 and replay 200;
- same key/different payload 409;
- missing key 428 and invalid key 422;
- 401 authentication and 403 permission/data scope;
- invalid state, limit and dependency conflicts;
- concurrent double reversal;
- transaction rollback and no partial write;
- append-only audit/movement chain;
- no stack trace or secret leakage;
- reconciliation: balance equals signed movement ledger.

The Postman collection defines contract cases only. It is not proof that the runtime exists or tests passed.

## 9. Official release governance

Official immutable payload:

- `openapi.yaml`
- `reversal.schema.json`
- `postman_collection.json`
- `CHANGELOG.md`
- `REVIEW_AND_APPROVAL.md`
- `TRACEABILITY.md`
- `RELEASE_MANIFEST.json`
- `SHA256SUMS`

Publisher separation:

| Role | Responsibility | Current result |
|---|---|---|
| BE1 author | Prepare proposal and hashes | `PROPOSED` |
| Tech Lead independent publisher | Verify exact SHA/hash and technical sufficiency | `PENDING` |
| QA | Verify testability | `PENDING` |
| FE | Verify consumer mapping | `PENDING` |
| BAS | Pin traceability | `PENDING` |
| PM/PO | Formal outcome | `PENDING` |

BE1 and CI must not sign for independent reviewers.

## 10. Open points and disposition

| ID | Nội dung | Disposition | Owner | Due/next gate |
|---|---|---|---|---|
| OP-REV-02 | Dependency downstream | Fail closed by `REVERSAL_DEPENDENCY_CONFLICT`; whitelist requires BA approval | BA + BE2 | Before approval |
| OP-REV-03 | Return evidence by transaction type | Line-level status; evidence URI required when policy requires | BA + QA | Before approval |
| OP-REV-04 | High-value reversal approval/SLA | Configurable policy; no hard-coded threshold | PM/PO | Before approval |
| OP-REV-05 | Canonical vs legacy route/permission | Separate implementation/adoption ticket; no implicit compatibility | Tech Lead + BE1 + FE + QA | Before runtime Done |

## 11. Downstream evidence boundary

Actual PHPUnit/Newman/API/DB/race/reconciliation evidence is downstream implementation evidence. The Decision Record, OpenAPI, Postman, schema, workflow and checksums do not prove deployment or runtime correctness.

No branch URL, local path, abbreviated SHA, BE self-review or CI-only result may substitute for independent reviewer disposition on the exact artifact.

## 12. Sign-off ledger

| Role | Owner | Allowed result | Current result | Evidence target |
|---|---|---|---|---|
| BE1 Author | Quang Nguyễn Thanh | `PROPOSED` | `PROPOSED` | exact release commit |
| BA | Nguyễn Đình Hùng | `REVIEWED-SUFFICIENT` / `CHANGES REQUIRED` | `PENDING` | WWHN-134 |
| Tech Lead | Independent reviewer | `PUBLISHED-VERIFIED` / `CHANGES REQUIRED` | `PENDING` | WWHN-134/WWHN-31 |
| QA | QA1/QA2 | `REVIEWED-SUFFICIENT` / `CHANGES REQUIRED` | `PENDING` | WWHN-134/WWHN-33 |
| FE | FE consumer | `REVIEWED-SUFFICIENT` / `CHANGES REQUIRED` | `PENDING` | WWHN-134/WWHN-40 |
| BAS | Nguyễn Thị Hải Yến | `TRACEABILITY-PINNED` / `CHANGES REQUIRED` | `PENDING` | WWHN-136 |
| PM/PO | Nguyễn Quang Đức | `APPROVED` / `REJECTED` / `CHANGES REQUIRED` | `PENDING` | WWHN-26 |

Closure is fail-closed until the required independent results include exact commit SHA, release path, manifest SHA-256, reviewer, date and evidence.

## 13. Đính chính contract theo SRS v1.12 (WWHN-240 · DEV240-P1-01)

Bản ghi này viết theo SRS/FRD v1.4. Baseline hiện hành là **SRS/FRD v1.12**, và bốn điểm ở
§5/§3 đã lỗi thời so với baseline lẫn runtime nhánh `m1`:

| # | Ghi trong bản này | Baseline v1.12 + runtime | Kết luận |
|---|---|---|---|
| 1 | `POST /api/v1/reversals` | `POST /api/v1/reversal-documents`, `POST /api/v1/{inbound\|outbound}-documents/{id}/reversals` | SRS v1.12 §6 thắng |
| 2 | permission `stock.reversal.create` | `inventory.reversal.post` | SRS v1.12 §6/§8.1 thắng |
| 3 | `reversal_of_movement_id` (§3.4) | `original_movement_id` + `correction_chain_id` | ERD/§7 Data Dictionary thắng |
| 4 | `IDEMPOTENCY_ERROR` / `NO_PERMISSION` / `ORIGINAL_NOT_FOUND` | catalog §8.2 hiện hành | §8.2 thắng (đã đóng ở WWHN-289) |

**Nội dung nghiệp vụ Option B (append-only, movement gốc bất biến, không có generic
stock-edit) KHÔNG đổi** và đã được adopt trong SRS v1.12 §5.5 BR-REV-01..12. Vì vậy việc
phê duyệt formal bản ghi này không kéo theo thay đổi code nào.

Chi tiết và ranh giới phụ thuộc của WWHN-240:
`docs/decision-records/DISPOSITION-WWHN-239-REVERSAL-CANDIDATE.md`.
