# HN-DEC-002 — DEC-INV-001: Nguồn sự thật tồn kho

## 0. Kiểm soát tài liệu

| Trường | Giá trị |
|---|---|
| Phiên bản đề xuất | 1.2-R2 |
| Trạng thái | **DRAFT — AWAITING FORMAL JIRA APPROVAL** |
| Ngày cập nhật | 2026-08-03 |
| Nhánh triển khai | `fix/wwhn-r2-inventory-decision-hardening` |
| Base | `m1` |
| Phạm vi | Warranty stock effect, idempotency, reconciliation, source register, traceability, evidence và handoff |
| Điều kiện hiệu lực | Chỉ có hiệu lực sau comment phê duyệt chính thức trên Jira, tham chiếu đúng attachment `11420`, full SHA-256, scope, ngày và điều kiện |

> Tài liệu này không tự nhận `APPROVED`, `BASELINED`, `EFFECTIVE` hoặc `DONE`. Các trạng thái đó chỉ được ghi sau khi có bằng chứng độc lập trên Jira.

## 1. Quyết định cốt lõi

### 1.1. Nguồn sự thật

1. `stock_movements` là authoritative append-only ledger.
2. `inventory_balances` là projection để đọc nhanh.
3. Serial/container là physical subledger.
4. Mọi operation đổi tồn phải atomic, idempotent và có signed `qty_delta`.
5. Không sửa/xóa movement gốc; sửa sai bằng reversal/adjustment được phê duyệt.

### 1.2. Dimension chuẩn

Khóa logic của balance và reconciliation là:

```text
(warehouse_id, product_id, stock_status)
```

Mọi movement phải mang cùng `stock_status`; không được aggregate movement bỏ dimension này rồi so với balance có dimension.

## 2. BE1-R2-P0-01 — Warranty status-only, không stock effect

### 2.1. Quy tắc quyết định

Warranty receive/update/return là **status-only**:

- Không tạo `stock_movements`.
- Không tăng/giảm `inventory_balances`.
- Không biến hàng bảo hành thành sellable stock.
- Không dùng movement `qty_delta = 0` để “trace”; trace thuộc `warranty_event`.

Với item đã định danh:

- Chỉ nhận warranty khi serial ở `ISSUED` hoặc `RETURNED_TO_CUSTOMER`.
- `IN_STOCK` phải trả `422 WARRANTY_ITEM_NOT_ELIGIBLE` và hướng dẫn hoàn tất **Post Issue** trước.
- Một serial chỉ có một active warranty case.

Với `TEMP_ONLY`:

- Không gắn `inventory_serial_id` hay `product_id` của item gốc.
- Bắt buộc `temporary_item_code`, `missing_code_reason`, `condition_status`.
- Chỉ cập nhật `warranty_case` và tạo `warranty_event`.

### 2.2. Acceptance Criteria

| AC | Kỳ vọng |
|---|---|
| WAR-AC-01 | TEMP_ONLY thành công và trả `stock_effect=NONE`, `movement_created=false` |
| WAR-AC-02 | Trước/sau request, số movement và balance không đổi |
| WAR-AC-03 | Replay cùng key/payload trả cùng kết quả, không tạo event lần hai |
| WAR-AC-04 | Cùng key khác payload trả `409 IDEMPOTENCY_ERROR` |
| WAR-AC-05 | Item `IN_STOCK` bị từ chối và yêu cầu Post Issue |

### 2.3. Reconciliation expectation

Warranty không xuất hiện trong ledger reconciliation. Việc theo dõi vòng đời warranty đối soát bằng `warranty_case` ↔ `warranty_event`, không bằng `stock_movements`.

## 3. BE1-R2-P1-04 — Operation-level idempotency và multi-line

### 3.1. Request-level key

Khóa request dùng scope:

```text
(idem_key, operation, user_id)
```

`operation` phải chứa action và aggregate ID, ví dụ:

```text
post_receipt_{document_id}
stocktake_apply_adjustment_{session_id}
warranty_temp_only_{warranty_case_id}
```

Cùng key + operation + user + payload:

- replay response 2xx đã lưu;
- không chạy callback lần hai;
- không tạo movement/event/balance update lần hai.

Cùng key nhưng payload khác: `409 IDEMPOTENCY_ERROR`.

### 3.2. Multi-line business key

Idempotency của operation không được gán một key unique giống nhau cho từng movement. Mỗi dòng có business key riêng:

```text
SHA1(action | aggregate_id | product_id | stock_status [| line_id])
```

Ví dụ Apply Adjustment 3 dòng phải tạo tối đa 3 movement khác nhau trong một transaction. Một dòng lỗi làm rollback toàn bộ.

### 3.3. PostgreSQL index hợp lệ

Không dùng partial predicate bên trong table-level `UNIQUE CONSTRAINT`. Cú pháp chuẩn:

```sql
CREATE UNIQUE INDEX uk_stock_movements_business_key
    ON stock_movements (business_key);

CREATE UNIQUE INDEX uk_idempotency_operation_user_key
    ON idempotency_keys (idem_key, operation, user_id);

CREATE UNIQUE INDEX uk_inventory_balance_dimension
    ON inventory_balances (warehouse_id, product_id, stock_status);
```

Nếu cột nullable cần partial index:

```sql
CREATE UNIQUE INDEX uk_movement_idempotency_not_null
    ON stock_movements (idempotency_key)
    WHERE idempotency_key IS NOT NULL;
```

## 4. BE1-R2-P1-05 — Công thức reconciliation

`qty_delta` đã có dấu. Công thức duy nhất:

```sql
SELECT warehouse_id, product_id, stock_status,
       COALESCE(SUM(qty_delta), 0) AS expected_qty
FROM stock_movements
GROUP BY warehouse_id, product_id, stock_status;
```

So sánh:

```text
variance = inventory_balances.on_hand_qty - expected_qty
```

Không được dùng `- SUM(qty_delta)` cho movement xuất/reversal âm vì sẽ đảo dấu lần hai.

Đối với `AVAILABLE`, `available_qty` phải khớp policy sellable. Với `DAMAGED`/`RESERVED`, reconciliation ledger dùng `on_hand_qty`; `available_qty` không tự đồng nhất với on-hand.

Pseudocode chuẩn dùng UUID/string:

```php
public function reconcile(string $warehouseId, ?string $productId = null): ReconciliationReport
```

## 5. Source Register hiện hành

### 5.1. Nguồn đã xác minh trong repository

| Source | Version tự khai báo | Vị trí | Trạng thái |
|---|---:|---|---|
| BRD | v3.11 | `HN_BA_Master_WMS_PV_v1_0_MD/HN_BA_Master_WMS_PV_v1_0.md` | Working reference; không thay approval evidence |
| SRS/FRD | v1.3 | cùng file Master | Working reference |
| BPD | v1.2 | cùng file Master | Working reference |
| DEC-INV-001 | 1.2-R2 | tài liệu này | Draft chờ approval |

### 5.2. Phiên bản chưa được xác minh

BRD v3.12; SRS v1.4/v1.5; BPD v1.3/v1.4 chưa có artifact bytes/attachment ID/full SHA-256 khả dụng trong repository hoặc connector Jira tại thời điểm cập nhật. Không được tự nhận chúng là baseline.

Khi nhận source mới, BA/BAS phải ghi đủ:

| Field bắt buộc | Yêu cầu |
|---|---|
| Jira attachment ID | Số nguyên chính xác |
| Filename/version | Đúng metadata file |
| SHA-256 | Đủ 64 ký tự hex |
| Uploaded/approved date | ISO date/time |
| Status | CURRENT / SUPERSEDED / REJECTED |
| Supersedes | Attachment/version cũ |
| Scope/conditions | Phạm vi áp dụng và ngoại lệ |

## 6. WWHN-130 — Traceability chain

| Source/Decision | Rule | API/UI | Test | Affected ticket |
|---|---|---|---|---|
| BASE-04 / DEC §2 | Warranty status-only | `POST /warranty-cases/{id}/temp-only`; UI không hiển thị stock effect | WAR-AC-01..04 | WWHN-128, WWHN-129, WWHN-130 |
| BASE-06 / DEC §3 | Operation-level idempotency | Header `Idempotency-Key`; 409 conflict | IDEM-01..05 | WWHN-31, WWHN-33, WWHN-130 |
| BASE-07 / DEC §4 | Signed SUM theo stock_status | `/reports/inventory-reconciliation` | RECON-01..04 | WWHN-31, WWHN-33, WWHN-130 |
| BASE-14 / DEC §8 | Không claim pass thiếu evidence | Jira/PR evidence ledger | EVID-01..03 | WWHN-24, WWHN-33, WWHN-128, WWHN-129 |

Attachment/link/comment thật trên WWHN-130 vẫn phải do BAS/BA thực hiện khi Jira connector khả dụng.

## 7. WWHN-128/WWHN-129 — Re-review ledger

| Fix | Section | Result repository | External review |
|---|---|---|---|
| Warranty contradiction | §2 | Resolved in DEC and WarrantyController | BA + Tech Lead pending Jira confirmation |
| Idempotency/multi-line | §3 | Resolved in StocktakeController/IdempotencyStore contract | Tech Lead + QA1 pending |
| Reconciliation signed sum/status | §4 | Design fixed; code review/test gate required | BE2 + Tech Lead + QA1 pending |
| Fake Approved/Effective | §0, §8 | Removed | PM/Approver pending |
| Source version conflict | §5 | Unverified versions flagged | BA/BAS pending |
| Test-pass claim | §8 | Removed unless exact run evidence exists | QA1 pending |

## 8. Evidence policy và approval gate

### 8.1. Không claim test pass thiếu evidence

Core inventory test chỉ được ghi `PASS` khi có đủ:

- exact commit SHA;
- CI run ID và conclusion;
- command/test suite;
- test/assertion counts;
- log/artifact digest;
- environment/database engine;
- QA reviewer identity và date.

Nếu thiếu một trường, trạng thái là `DESIGNED`, `IMPLEMENTED_UNVERIFIED` hoặc `PENDING_QA`, không phải `PASS`.

### 8.2. Approval comment bắt buộc trên WWHN-24

Approver phải comment theo mẫu:

```text
APPROVAL DEC-INV-001 v1.2-R2
Attachment ID: 11420
Filename: <exact filename>
SHA-256: <64 hex characters>
Scope: <exact scope>
Approval date: <ISO-8601>
Conditions: <none or explicit conditions>
Decision: APPROVED | APPROVED WITH CONDITIONS | REJECTED
Approver: <name/account>
```

Tài liệu chỉ được đổi sang Effective sau khi comment này tồn tại và hash khớp artifact.

### 8.3. Independent four-role ledger

| Role | Người xác nhận | Evidence | Trạng thái |
|---|---|---|---|
| BA/BAS | Chưa xác minh | Jira comment/review | PENDING |
| Tech Lead | Chưa xác minh | PR review + Jira closure | PENDING |
| QA1 | Chưa xác minh | Exact-SHA test report | PENDING |
| PM/Approver | Chưa xác minh | WWHN-24 approval comment | PENDING |

Cùng assignee hoặc FE tự xác nhận không được tính là independent review.

## 9. P2 quality corrections

- Dùng `RETURNED_TO_CUSTOMER`, không dùng typo `RETURNED_TO_CUSTOMED`.
- UUID được biểu diễn bằng `string`, không phải `int`.
- Service trả result/response rõ ràng; không tham chiếu biến vòng lặp cuối cùng.
- Version history không trùng version number.
- SQL index dùng cú pháp PostgreSQL hợp lệ.
- Section numbering duy nhất.
- Pseudocode phản ánh đúng field thực tế: `product_id`, `stock_status`, `on_hand_qty`, signed `qty_delta`.

## 10. Version history

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-07-23 | Initial decision draft |
| 1.1 | 2026-07-31 | Prior revision; contained approval/source/evidence inconsistencies |
| 1.2-R2 | 2026-08-03 | Removed false approval claims; fixed warranty stock effect, operation-level idempotency, multi-line keys, PostgreSQL index design, signed reconciliation, source register and evidence gates |

## 11. Exact Fix Action Register — repository closure

| ID | Repository action | Status |
|---|---|---|
| BE1-R2-P0-01 | Warranty TEMP_ONLY status-only; no movement/balance; AC and reconciliation expectation | IMPLEMENTED |
| BE1-R2-P1-01 | Removed Approved/Effective; formal Jira approval template for attachment 11420 | REPO DONE / JIRA PENDING |
| BE1-R2-P1-02 | Current vs unverified source versions, mandatory attachment/hash fields | REPO DONE / BA BASELINE PENDING |
| BE1-R2-P1-03 | Source→Decision→Rule→API/UI→TC→ticket matrix | REPO DONE / WWHN-130 COMMENT PENDING |
| BE1-R2-P1-04 | Operation idempotency, multi-line business key, valid indexes | IMPLEMENTED |
| BE1-R2-P1-05 | Signed SUM and stock_status dimension | DESIGN IMPLEMENTED; EXACT-SHA TEST PENDING |
| BE1-R2-P1-06 | Re-review matrix by fix/section/result | REPO DONE / INDEPENDENT COMMENT PENDING |
| BE1-R2-P1-07 | Removed unsupported pass claims; exact evidence policy | DONE |
| BE1-R2-P1-08 | Four-role ledger requires independent identities | REPO DONE / FOUR CONFIRMATIONS PENDING |
| BE1-R2-P2-01 | Typo, UUID, result, version history, SQL/pseudocode corrected | DONE |
| BE1-R2-P2-02 | Structured Jira closure comment defined below | REPO DONE / JIRA POST PENDING |

## 12. Jira closure comment template

```text
[BE1 R2 CLOSURE MATRIX]
Exact commit SHA: <sha>
Branch/PR: <branch and PR>
CI run/artifact: <run id, artifact id, digest>

P0-01 Warranty stock effect: IMPLEMENTED — warranty_event only; no movement/balance.
P1-01 Approval: PENDING APPROVER — requires attachment 11420 + full SHA-256 + scope/date/conditions.
P1-02 Source register: CURRENT sources verified; newer versions flagged UNVERIFIED.
P1-03 Traceability: matrix attached/linked; BAS confirmation pending.
P1-04 Idempotency: operation-level key; per-line business key; atomic multi-line.
P1-05 Reconciliation: signed SUM(qty_delta), grouped by stock_status.
P1-06 BA re-review: pending independent closure comment.
P1-07 Tests: only exact-SHA CI evidence may be marked PASS.
P1-08 Independent ledger: BA/BAS, Tech Lead, QA1, PM required.
P2-01 Document quality corrections: DONE.
P2-02 Jira handoff: this comment is the closure matrix; final status remains gated by pending roles.
```
