# HN-DEC-002 — DEC-INV-001: Nguồn sự thật tồn kho

## 0. Kiểm soát tài liệu

| Trường | Giá trị |
|---|---|
| Ticket | `WWHN-128` |
| Phiên bản đề xuất | `1.3-R3` |
| Trạng thái | **PROPOSED — REPOSITORY IMPLEMENTED, INDEPENDENT GATES PENDING** |
| Ngày cập nhật | `2026-08-03` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Nhánh duy nhất | `m1` |
| Cách triển khai | Commit trực tiếp vào `m1` theo yêu cầu; không tạo nhánh riêng, không tạo PR |
| Implementation baseline trước commit tài liệu | `ce4023dfc4c251907121c3c782a80489f53e4bba` |
| Jira decision attachment hiện hành theo re-review | `11552` |
| Attachment cũ bị supersede | `11420` — **không được dùng cho approval mới** |
| Source register máy đọc | `docs/docs_task_jira/WWHN-128-source-register.json` |
| Exact-SHA CI gate | `.github/workflows/wwhn-128-inventory-decision-contract.yml` |

> Tài liệu này không tự nhận `APPROVED`, `BASELINED`, `EFFECTIVE`, `TEST PASS` hoặc `DONE`. Các trạng thái đó chỉ hợp lệ sau khi attachment `11552` được xác minh bằng exact filename/full SHA-256, CI exact-SHA hoàn tất và BA/BAS, Tech Lead, QA1, PM/Approver xác nhận độc lập trên Jira.

### 0.1. Giới hạn xác minh Jira trong lần sửa này

Atlassian connector trả `HTTP 403 — app is not installed on this instance`. Vì vậy lần sửa này không thể:

- tải bytes của attachment `11552` để tính full SHA-256;
- đọc exact attachment IDs của BRD v3.12, SRS/FRD v1.5, BPD v1.4 từ WWHN-129;
- chuyển trạng thái hoặc đăng structured closure comment lên WWHN-130;
- giả lập chữ ký BA/BAS, Tech Lead, QA1 hoặc PM.

Repository đã được harden đến mức có thể kiểm chứng độc lập. Mọi trường Jira chưa đọc được được giữ `PENDING`, không điền suy đoán.

## 1. Quyết định cốt lõi

### 1.1. Nguồn sự thật

1. `stock_movements` là authoritative append-only ledger.
2. `inventory_balances` là projection phục vụ đọc nhanh.
3. Serial/container là physical subledger, không thay thế ledger số lượng.
4. Mọi operation đổi tồn phải atomic, idempotent và dùng signed `qty_delta`.
5. Không sửa/xóa movement gốc; sửa sai bằng reversal/adjustment có kiểm soát.
6. Warranty là vòng đời dịch vụ, không phải stock transaction.

### 1.2. Dimension chuẩn

Khóa logic của balance và reconciliation:

```text
(warehouse_id, product_id, stock_status)
```

Không được aggregate movement bỏ `stock_status` rồi so với balance có `stock_status`.

## 2. BE1-R2-P0-01 — Warranty status-only, không stock effect

### 2.1. Rule đã khóa trong code

Warranty receive/update/return là **status-only**:

- không tạo `stock_movements`;
- không thay đổi `inventory_balances`;
- không dùng movement `qty_delta = 0` để trace;
- trace vòng đời nằm ở `warranty_event`;
- response công khai `stock_effect=NONE`, `movement_created=false`.

Item đã định danh:

- chỉ hợp lệ khi serial ở `ISSUED` hoặc `RETURNED_TO_CUSTOMER`;
- serial `IN_STOCK` trả `422 WARRANTY_ITEM_NOT_ELIGIBLE`;
- response lỗi chỉ rõ `required_action=POST_ISSUE`;
- một serial không được có hơn một warranty case chưa `CLOSED`/`CANCELLED`.

`TEMP_ONLY`:

- sau transition, `inventory_serial_id=null`, `product_id=null`;
- bắt buộc `temporary_item_code`, `missing_code_reason`, `condition_status`;
- transaction chỉ cập nhật `warranty_case` và tạo đúng một `warranty_event`;
- replay cùng idempotency key/payload không chạy callback lần hai.

### 2.2. Artifact triển khai

| Artifact | Exact Git blob SHA | Nội dung kiểm soát |
|---|---|---|
| `Modules/Warehouse/app/Models/InventorySerial.php` | `fced829234390ca6faa3a7d862159d1c4ec33c0d` | Constants `IN_STOCK`, `ISSUED`, `IN_TRANSIT`, `RETURNED_TO_CUSTOMER` |
| `Modules/Warehouse/app/Http/Controllers/WarrantyController.php` | `c112ae240e95d169b429e3e6dac9bcbe53e50ad1` | Eligibility, row lock, active-case guard, status-only transaction, response contract |
| `app/Support/IdempotencyStore.php` | `78e45f98f799da90d6170dfb6259ce23263383b3` | Atomic callback + response snapshot theo `(idem_key, operation, user_id)` |
| `tests/Feature/WarrantyStatusOnlyContractTest.php` | `f2e101eec4a0836fab4d2e0f5661282e703e01d0` | WAR-AC-01..05 và single-active-warranty |

### 2.3. Acceptance Criteria và test mapping

| AC | Kỳ vọng | Test method |
|---|---|---|
| WAR-AC-01 | TEMP_ONLY trả `stock_effect=NONE`, `movement_created=false` | `test_temp_only_is_status_only_and_replay_does_not_duplicate_event` |
| WAR-AC-02 | Movement count và balance values/version không đổi | cùng test trên; `test_in_stock_serial_is_rejected_without_stock_or_case_effect` |
| WAR-AC-03 | Replay cùng key/payload trả exact response snapshot, event count vẫn 1 | `test_temp_only_is_status_only_and_replay_does_not_duplicate_event` |
| WAR-AC-04 | Cùng key khác payload trả `409 IDEMPOTENCY_ERROR` | `test_same_key_with_different_payload_returns_idempotency_conflict` |
| WAR-AC-05 | Serial `IN_STOCK` trả 422 và yêu cầu Post Issue | `test_in_stock_serial_is_rejected_without_stock_or_case_effect` |
| WAR-AC-06 | Serial có active warranty khác trả `409 ACTIVE_WARRANTY_EXISTS` | `test_serial_with_another_active_warranty_case_is_rejected` |

### 2.4. Reconciliation expectation

Warranty không xuất hiện trong inventory ledger reconciliation. Đối soát warranty dùng:

```text
warranty_case.status/version
↕
warranty_event.from_status/to_status/event_at
```

Không dùng `stock_movements` để mô phỏng trace warranty.

## 3. BE1-R2-P1-01 — Approval gate phải trỏ attachment 11552

### 3.1. Fix repository

- Tất cả reference approval mới trong tài liệu này dùng attachment `11552`.
- Attachment `11420` được đánh dấu `SUPERSEDED`; không được dùng làm approval target.
- Source register tách rõ `attachment_id=11552` và các metadata chưa xác minh.

### 3.2. Gate bắt buộc trên Jira WWHN-24

```text
APPROVAL DEC-INV-001 v1.3-R3
Attachment ID: 11552
Filename: <exact filename from Jira metadata>
SHA-256: <64 lowercase hex calculated from exact attachment bytes>
Supersedes: Attachment 11420
Scope: Warranty status-only; operation idempotency; signed reconciliation; source/trace/evidence policy
Approval date: <ISO-8601>
Conditions: <none or explicit conditions>
Decision: APPROVED | APPROVED WITH CONDITIONS | REJECTED
Approver: <Jira account/name>
```

Approval không hợp lệ khi thiếu filename, full SHA-256, scope, date, decision hoặc approver.

## 4. BE1-R2-P1-02 — Source Register

### 4.1. Repository source đã xác minh

| Source ID | Artifact | Declared/embedded version | Git blob SHA | Status |
|---|---|---|---|---|
| `HN-BA-MASTER-001` | `HN_BA_Master_WMS_PV_v1_0_MD/HN_BA_Master_WMS_PV_v1_0.md` | Master 1.0; BRD 3.11; SRS/FRD 1.3; BPD 1.2 | `1f0ca8de0982b7e5676c6cf3b72b4dd3c1b58a5a` | `WORKING_REFERENCE` |

Working reference không thay thế source attachment approval.

### 4.2. Business source mới từ WWHN-129

| Source | Version được re-review nêu | Attachment ID | Filename | Full SHA-256 | Status |
|---|---:|---:|---|---|---|
| BRD | 3.12 | `PENDING_JIRA_READ` | `PENDING_JIRA_READ` | `PENDING_JIRA_READ` | `PENDING_WWHN_129_METADATA_RETRIEVAL` |
| SRS/FRD | 1.5 | `PENDING_JIRA_READ` | `PENDING_JIRA_READ` | `PENDING_JIRA_READ` | `PENDING_WWHN_129_METADATA_RETRIEVAL` |
| BPD | 1.4 | `PENDING_JIRA_READ` | `PENDING_JIRA_READ` | `PENDING_JIRA_READ` | `PENDING_WWHN_129_METADATA_RETRIEVAL` |

Không được đổi các dòng trên thành `CURRENT` cho đến khi BA/BAS ghi exact attachment ID, filename, 64-char SHA-256, uploaded date, supersedes và scope.

Máy đọc: `docs/docs_task_jira/WWHN-128-source-register.json`.

## 5. BE1-R2-P1-03 — WWHN-130 traceability chain

| Source/Decision | Rule | Code/API | Test | Ticket |
|---|---|---|---|---|
| BASE-04 / DEC §2 | Warranty status-only | `POST /api/v1/warranty-cases/{id}/temp-only`; `WarrantyController::tempOnly` | WAR-AC-01..06 | WWHN-128, WWHN-129, WWHN-130 |
| BASE-04 / DEC §2 | `IN_STOCK` reject, Post Issue required | `WarrantyController::assertLinkedSerialEligible` | WAR-AC-05 | WWHN-128, WWHN-130 |
| BASE-04 / DEC §2 | Một active warranty/serial | row lock + active-case query | WAR-AC-06 | WWHN-128, WWHN-130 |
| BASE-06 / DEC §6 | Operation-level idempotency | `IdempotencyStore::handle`; operation `warranty_temp_only_{case_id}` | WAR-AC-03..04; WWHN-180 idempotency tests | WWHN-31, WWHN-33, WWHN-128, WWHN-130 |
| BASE-07 / DEC §7 | Signed `SUM(qty_delta)` theo `stock_status` | `GET /api/v1/reports/inventory-reconciliation`; `ReportController` | RECON-01..06 | WWHN-31, WWHN-33, WWHN-128, WWHN-130 |
| BASE-14 / DEC §8 | Không claim PASS thiếu evidence | exact-SHA workflow + artifact manifest | EVID-01..05 | WWHN-24, WWHN-128, WWHN-129, WWHN-130 |

Repository trace đã hoàn tất. Jira WWHN-130 vẫn phải nhận attachment/link/comment thật và workflow transition bởi BAS/BA khi connector hoạt động.

## 6. BE1-R2-P1-04 — Operation-level idempotency và multi-line

### 6.1. Request-level scope

```text
(idem_key, operation, user_id)
```

Operation chứa action + aggregate ID:

```text
post_receipt_{document_id}
post_issue_{document_id}
stocktake_apply_adjustment_{session_id}
warranty_temp_only_{warranty_case_id}
```

Contract:

- cùng scope + cùng canonical payload: replay exact 2xx snapshot;
- cùng scope + payload khác: `409 IDEMPOTENCY_ERROR`;
- callback, stock effect/event và response snapshot cùng outer transaction;
- callback lỗi: rollback business write và xóa đúng lease do request sở hữu;
- stale lease takeover dùng compare-and-update, không xóa lease của request khác.

### 6.2. Multi-line business key

Mỗi movement line có business key riêng:

```text
SHA1(action | aggregate_id | product_id | stock_status [| line_id])
```

Không reuse một movement idempotency key cho nhiều line. Một line lỗi phải rollback toàn operation.

### 6.3. Index contract PostgreSQL

```sql
CREATE UNIQUE INDEX uk_stock_movements_business_key
    ON stock_movements (business_key);

CREATE UNIQUE INDEX uk_idempotency_operation_user_key
    ON idempotency_keys (idem_key, operation, user_id);

CREATE UNIQUE INDEX uk_inventory_balance_dimension
    ON inventory_balances (warehouse_id, product_id, stock_status);
```

Partial unique index chỉ dùng cú pháp index-level:

```sql
CREATE UNIQUE INDEX uk_movement_idempotency_not_null
    ON stock_movements (idempotency_key)
    WHERE idempotency_key IS NOT NULL;
```

### 6.4. Review evidence cần ký

- Tech Lead: review transaction boundary, lease takeover, unique index và line business key.
- QA1: chạy race/retry/same-key-different-payload/timeout-after-write trên exact SHA.
- Không ghi `PASS` chỉ vì test file tồn tại.

## 7. BE1-R2-P1-05 — Signed reconciliation và stock_status dimension

### 7.1. Công thức duy nhất

```sql
SELECT warehouse_id, product_id, stock_status,
       COALESCE(SUM(qty_delta), 0) AS expected_qty
FROM stock_movements
GROUP BY warehouse_id, product_id, stock_status;
```

```text
variance = COALESCE(inventory_balances.on_hand_qty, 0) - expected_qty
```

Không dùng `- SUM(qty_delta)` cho issue/reversal âm.

### 7.2. Runtime implementation

| Artifact | Git blob SHA | Kiểm soát |
|---|---|---|
| `Modules/Warehouse/app/Http/Controllers/ReportController.php` | `91612835ee00192fdd36590c403c1d1363fd8edc` | Union dimensions, signed SUM, missing balance/ledger detection, filter warehouse/product/status |
| `tests/Feature/InventoryReconciliationIntegrityTest.php` | `2374dbff3c679a0cfebb022b1a4ff8ece6fa6588` | Ledger-only và balance-only dimensions |
| `tests/Feature/InventoryReconciliationSignedStatusTest.php` | `3d941e075585bfb2c68f2b1b4eb78b57da5588be` | `+10 + (-3) = 7`; AVAILABLE và DAMAGED đối soát độc lập |

`AVAILABLE.available_qty` tuân theo sellable policy; `DAMAGED`/`RESERVED` reconciliation dùng `on_hand_qty`, không ép `available_qty = on_hand_qty`.

## 8. BE1-R2-P1-06 — BA re-review đúng revision

BA re-review chỉ hợp lệ khi comment ghi đủ:

```text
RE-REVIEW WWHN-128 — DEC-INV-001 v1.3-R3
Decision attachment: 11552
Attachment SHA-256: <exact 64 hex>
Repository branch: m1
Repository exact SHA: <exact commit reviewed>
Sections reviewed: §2, §3, §4, §5, §6, §7, §8, §9, §10
P0-01: PASS | FAIL — <comment>
P1-01: PASS | FAIL — <comment>
...
P2-02: PASS | FAIL — <comment>
Reviewer: <BA account/name>
Reviewed at: <ISO-8601>
Result: ACCEPTED | ACCEPTED_WITH_CONDITIONS | REJECTED
```

Hiện trạng: `PENDING BA RE-REVIEW ON ATTACHMENT 11552 AND EXACT REPOSITORY SHA`.

## 9. BE1-R2-P1-07 — Evidence policy

Core test chỉ được ghi `PASS` khi có đủ:

1. exact commit SHA;
2. workflow run ID + attempt + conclusion;
3. command/test suite;
4. test/assertion counts hoặc JUnit;
5. immutable artifact name bound to exact SHA;
6. `SHA256SUMS` được verify;
7. database engine/environment;
8. QA1 identity + review date.

Thiếu một trường: dùng `IMPLEMENTED_UNVERIFIED`, `CI_PENDING` hoặc `PENDING_QA`, không dùng `PASS`.

### 9.1. Exact-SHA workflow đã thêm

`/.github/workflows/wwhn-128-inventory-decision-contract.yml`

Gate thực hiện:

- checkout exact `GITHUB_SHA`;
- PHP 8.3 + SQLite isolated environment;
- `php -l` cho code/test liên quan;
- Pint check;
- chạy Warranty, idempotency và reconciliation regression suite;
- xuất JUnit, PHPUnit log, route list, commit stat, changed files, build metadata;
- tạo và verify `SHA256SUMS`;
- upload `wwhn-128-evidence-<GITHUB_SHA>` retention 90 ngày.

Workflow file được thêm tại commit `d8643c66cb2dd7cf24d16833ebfcdb411c645385`.

Hiện trạng trong lần sửa này: chưa có verified run ID/conclusion qua connector, nên không claim test PASS.

## 10. BE1-R2-P1-08 — Independent four-role ledger

| Role | Trách nhiệm | Evidence bắt buộc | Trạng thái |
|---|---|---|---|
| BA/BAS | Source/version/AC/trace đúng business | Jira re-review attachment 11552 + exact SHA | `PENDING` |
| Tech Lead | Transaction, locking, idempotency, schema/query | Code review/Jira comment trên exact repository SHA | `PENDING` |
| QA1 | Regression/race/reconciliation evidence | Workflow run + artifact digest + QA comment | `PENDING` |
| PM/Approver | Decision approval và điều kiện hiệu lực | WWHN-24 approval comment | `PENDING` |

Không chấp nhận self-sign-off hoặc cùng một người đóng thay nhiều vai trò độc lập.

## 11. P2 quality corrections

- Dùng `RETURNED_TO_CUSTOMER`, không dùng `RETURNED_TO_CUSTOMED`.
- UUID dùng `string`.
- Controller/service trả result rõ ràng, không dùng biến vòng lặp cuối.
- Version history không trùng version.
- SQL index dùng cú pháp PostgreSQL hợp lệ.
- Section numbering duy nhất.
- Pseudocode phản ánh field thật: `product_id`, `stock_status`, `on_hand_qty`, signed `qty_delta`.
- Branch metadata chỉ còn `m1`, không còn nhánh `fix/...` sai thực tế.
- Approval reference chỉ còn attachment `11552`; attachment `11420` bị supersede.

## 12. Exact Fix Action Register

| ID | Repository result | External result | Trạng thái trung thực |
|---|---|---|---|
| BE1-R2-P0-01 | Code + row locks + error contract + WAR-AC-01..06 tests | BA/Tech Lead/QA1 chưa ký | `REPO IMPLEMENTED / SIGN-OFF PENDING` |
| BE1-R2-P1-01 | Gate đổi sang attachment 11552; 11420 superseded | filename/hash/approval chưa đọc được | `REPO FIXED / JIRA METADATA PENDING` |
| BE1-R2-P1-02 | Source register JSON có exact repo blobs và explicit pending fields | WWHN-129 source IDs/hash chưa đọc được | `PARTIAL — JIRA READ REQUIRED` |
| BE1-R2-P1-03 | Trace matrix source→rule→code/API→test→ticket | WWHN-130 vẫn cần comment/link/transition | `REPO FIXED / JIRA ACTION PENDING` |
| BE1-R2-P1-04 | Existing idempotency design mapped; atomic/race tests included in CI gate | Tech Lead/QA1 review pending | `IMPLEMENTED / REVIEW PENDING` |
| BE1-R2-P1-05 | Signed query + stock_status regression test | Exact-SHA CI/QA review pending | `IMPLEMENTED / EVIDENCE PENDING` |
| BE1-R2-P1-06 | Exact BA re-review template cho v1.3-R3/11552 | BA comment chưa tồn tại | `JIRA PENDING` |
| BE1-R2-P1-07 | Không claim pass; exact-SHA workflow/artifact policy added | Run ID/conclusion/QA comment pending | `POLICY DONE / EXECUTION PENDING` |
| BE1-R2-P1-08 | Four-role ledger và evidence criteria defined | Bốn chữ ký đều pending | `JIRA PENDING` |
| BE1-R2-P2-01 | Typo/UUID/result/version/SQL/branch metadata corrected | Không có | `DONE` |
| BE1-R2-P2-02 | Structured closure matrix sẵn để post | Chưa post do Jira 403 | `REPO DONE / JIRA POST PENDING` |

## 13. Structured Jira closure matrix — ready to paste

```text
[BE1 R2 CLOSURE MATRIX — WWHN-128]
Decision: DEC-INV-001 v1.3-R3
Decision attachment ID: 11552
Superseded attachment ID: 11420
Attachment filename: <exact Jira metadata>
Attachment SHA-256: <exact 64 hex from downloaded attachment bytes>
Repository: Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE
Branch: m1
Implementation baseline: ce4023dfc4c251907121c3c782a80489f53e4bba
CI workflow: .github/workflows/wwhn-128-inventory-decision-contract.yml
CI run/artifact: <run id> / wwhn-128-evidence-<exact SHA> / <SHA256SUMS digest>

P0-01 Warranty status-only:
- IMPLEMENTED in WarrantyController.
- IN_STOCK => 422 WARRANTY_ITEM_NOT_ELIGIBLE, required_action=POST_ISSUE.
- No stock movement/balance effect.
- Same-key replay and different-payload conflict covered.
- Independent BA/Tech Lead/QA1 sign-off: PENDING.

P1-01 Approval target:
- FIXED to attachment 11552.
- Attachment 11420 is superseded.
- Exact filename/full SHA/approval: PENDING JIRA METADATA + APPROVER.

P1-02 Source register:
- Repository working source and code/test blobs: VERIFIED.
- BRD 3.12 / SRS 1.5 / BPD 1.4 Jira attachment IDs and full SHA: PENDING WWHN-129 READ.

P1-03 Traceability:
- Repository matrix: COMPLETE.
- WWHN-130 attachment/link/comment/workflow transition: PENDING BAS/BA.

P1-04 Idempotency:
- Operation scope (idem_key, operation, user_id), canonical request hash, exact replay, payload conflict, lease/rollback contract: IMPLEMENTED.
- Tech Lead + QA1 review: PENDING.

P1-05 Reconciliation:
- signed SUM(qty_delta), grouped by warehouse/product/stock_status: IMPLEMENTED.
- Negative delta and multi-status regression test: ADDED.
- Exact-SHA CI + QA1 review: PENDING.

P1-06 BA re-review:
- Must review v1.3-R3 + attachment 11552 + exact repository SHA.
- Status: PENDING.

P1-07 Test claim:
- No unsupported PASS claim.
- Exact-SHA workflow and immutable artifact gate: ADDED.
- Verified run/conclusion: PENDING.

P1-08 Four-role ledger:
- BA/BAS: PENDING.
- Tech Lead: PENDING.
- QA1: PENDING.
- PM/Approver: PENDING.

P2-01 Quality corrections: DONE.
P2-02 Structured closure matrix: READY; Jira post PENDING due connector HTTP 403.

Overall result:
- Repository implementation: COMPLETE for scoped P0/P1/P2 fixes.
- Formal decision closure: NOT YET COMPLETE until exact attachment/source metadata, CI evidence and four independent approvals exist.
```

## 14. Commands for independent verification

```bash
php -l Modules/Warehouse/app/Models/InventorySerial.php
php -l Modules/Warehouse/app/Http/Controllers/WarrantyController.php
php -l Modules/Warehouse/app/Http/Controllers/ReportController.php
php -l app/Support/IdempotencyStore.php
php -l tests/Feature/WarrantyStatusOnlyContractTest.php
php -l tests/Feature/InventoryReconciliationSignedStatusTest.php

./vendor/bin/pint --test \
  Modules/Warehouse/app/Models/InventorySerial.php \
  Modules/Warehouse/app/Http/Controllers/WarrantyController.php \
  Modules/Warehouse/app/Http/Controllers/ReportController.php \
  app/Support/IdempotencyStore.php \
  tests/Feature/WarrantyStatusOnlyContractTest.php \
  tests/Feature/InventoryReconciliationSignedStatusTest.php \
  tests/Feature/InventoryReconciliationIntegrityTest.php \
  tests/Feature/Wwhn180IdempotencyAtomicityTest.php

./vendor/bin/phpunit \
  --filter '/(WarrantyStatusOnlyContractTest|InventoryReconciliationSignedStatusTest|InventoryReconciliationIntegrityTest|Wwhn180IdempotencyAtomicityTest)/'
```

## 15. Version history

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-07-23 | Initial decision draft |
| 1.1 | 2026-07-31 | Prior revision; contained approval/source/evidence inconsistencies |
| 1.2-R2 | 2026-08-03 | Removed false approval claims; fixed design for warranty, idempotency and signed reconciliation |
| 1.3-R3 | 2026-08-03 | Direct `m1` implementation; IN_STOCK/active warranty guards; Warranty and reconciliation regression tests; exact-SHA CI; attachment gate 11552; source register; structured closure matrix |
