# HN-DEC-002 — DEC-INV-001: Nguồn dữ liệu chuẩn của tồn kho

## 0. Kiểm soát tài liệu

| Trường | Giá trị |
|---|---|
| Ticket thực hiện | `WWHN-128` |
| Task cha | `WWHN-24` |
| Parent Temp Key | `HN-DEC-002` |
| Epic Temp Key | `HN-EPIC-01` |
| Decision ID | `DEC-INV-001` |
| Role | `BE1` |
| Assignee Placeholder | `HN-BE-01` |
| Phiên bản | `1.4-R4` |
| Trạng thái | **PROPOSED — FINAL APPROVAL CANDIDATE; EXTERNAL GATES PENDING** |
| Ngày phát hành | `2026-08-04` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Nhánh duy nhất | `m1` |
| Parent commit trước R4 | `60d5e5ba3f0d5d13c120a50c245f00c75367bd87` |
| Release commit SHA | **Ghi bằng exact 40-char SHA trong Jira comment sau khi commit; không tự nhúng SHA vòng lặp vào artifact** |
| Decision artifact gần nhất trước R4 | `WWHN-128.md` — Jira attachment `11560` |
| Source register gần nhất trước R4 | `WWHN-128-source-register.json` — Jira attachment `11559` |
| Release unit R4 | `WWHN-128.md` + `WWHN-128-source-register.json` tại cùng exact commit |
| CI exact-SHA | `.github/workflows/wwhn-128-inventory-decision-contract.yml` |

> R4 không tự nhận `APPROVED`, `BASELINED`, `EFFECTIVE`, `TEST PASS` hoặc `DONE`. Các trạng thái đó chỉ hợp lệ sau khi cặp artifact R4 được upload, tính full SHA-256 từ exact bytes, CI chạy trên exact commit và BA/BAS, Tech Lead, QA1, PM/Approver xác nhận độc lập.

### 0.1. Baseline kỹ thuật áp dụng

R4 tuân thủ `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`:

1. Backend enforce RBAC/data scope; frontend không phải security boundary.
2. Operation đổi tồn phải dùng transaction, lock và idempotency.
3. `stock_movements` là append-only audit ledger; scan/lưu nháp không đổi tồn.
4. PostgreSQL là database chính cho transaction, constraint, reconciliation và report.
5. Error contract phải rõ, không lộ stack trace/secret.
6. Test evidence phải gắn exact code revision và môi trường thực thi.

### 0.2. Mục tiêu R4

R4 xử lý đầy đủ phần việc thuộc BE1 của re-review 75%:

- khóa Warranty status-only và `IN_STOCK` rejection;
- thay approval target cũ bằng exact release unit R4;
- điền current source IDs từ WWHN-129;
- hoàn thiện traceability cho WWHN-130;
- khóa design/review gate của idempotency và reconciliation;
- không claim test PASS khi thiếu run/digest/QA review;
- tạo four-role ledger và closure comments có cấu trúc;
- đồng bộ Decision Record và machine-readable Source Register.

## 1. Quyết định cốt lõi

### 1.1. Nguồn dữ liệu chuẩn

1. `stock_movements` là **authoritative append-only ledger**.
2. `inventory_balances` là projection phục vụ đọc nhanh, không phải nguồn sự thật cuối cùng.
3. Serial/container là physical subledger phục vụ truy vết vật lý; không thay thế ledger số lượng.
4. Mọi operation đổi tồn phải atomic, idempotent, audit được và dùng signed `qty_delta`.
5. Movement đã post không sửa/xóa; sửa sai bằng reversal/adjustment có reference.
6. Warranty là vòng đời dịch vụ, không phải stock transaction.

### 1.2. Dimension chuẩn

```text
(warehouse_id, product_id, stock_status)
```

Reconciliation không được aggregate bỏ `stock_status`, không được đảo dấu `qty_delta` lần hai theo `movement_type`.

## 2. P0 — Warranty status-only và eligibility guard

### 2.1. Business rule bắt buộc

Warranty receive/update/TEMP_ONLY là **status-only**:

- không tạo `stock_movements`;
- không cập nhật `inventory_balances`;
- không tạo movement `qty_delta = 0` để giả lập audit;
- audit vòng đời nằm ở `warranty_event`;
- response công khai `stock_effect=NONE`, `movement_created=false`.

Eligibility cho item đã định danh:

- chỉ serial ở `ISSUED` hoặc `RETURNED_TO_CUSTOMER` được tiếp nhận;
- serial `IN_STOCK` trả HTTP `422`, `error.code=WARRANTY_ITEM_NOT_ELIGIBLE`;
- lỗi trả `required_action=POST_ISSUE`;
- một serial không có nhiều hơn một warranty case chưa `CLOSED`/`CANCELLED`.

TEMP_ONLY:

- sau transition: `inventory_serial_id=null`, `product_id=null`;
- bắt buộc `temporary_item_code`, `missing_code_reason`, `condition_status`;
- transaction chỉ cập nhật case và tạo đúng một `warranty_event`;
- replay cùng idempotency key/payload trả exact response snapshot, không chạy callback lần hai.

### 2.2. Code/test mapping

| Artifact | Git blob SHA tại baseline R4 | Kiểm soát |
|---|---|---|
| `Modules/Warehouse/app/Models/InventorySerial.php` | `fced829234390ca6faa3a7d862159d1c4ec33c0d` | Status constants chuẩn |
| `Modules/Warehouse/app/Http/Controllers/WarrantyController.php` | `c112ae240e95d169b429e3e6dac9bcbe53e50ad1` | Row lock, eligibility, active-case guard, status-only transaction |
| `app/Support/IdempotencyStore.php` | `78e45f98f799da90d6170dfb6259ce23263383b3` | Operation scope, canonical request hash, exact replay, lease ownership |
| `tests/Feature/WarrantyStatusOnlyContractTest.php` | `485fa3253ddb6f908a839a8d99499ff2096509ae` | No stock effect, replay, 409 payload conflict, IN_STOCK reject, active case |

### 2.3. Acceptance Criteria / Test Case

| AC/TC | Kỳ vọng | Test method |
|---|---|---|
| `WAR-AC-01` | TEMP_ONLY trả `stock_effect=NONE`, `movement_created=false` | `test_temp_only_is_status_only_and_replay_does_not_duplicate_event` |
| `WAR-AC-02` | Movement count và balance values/version không đổi | test trên + `test_in_stock_serial_is_rejected_without_stock_or_case_effect` |
| `WAR-AC-03` | Replay cùng key/payload trả exact snapshot; event count vẫn 1 | `test_temp_only_is_status_only_and_replay_does_not_duplicate_event` |
| `WAR-AC-04` | Cùng key khác payload trả `409 IDEMPOTENCY_ERROR` | `test_same_key_with_different_payload_returns_idempotency_conflict` |
| `WAR-AC-05` | Serial `IN_STOCK` trả 422 và yêu cầu Post Issue | `test_in_stock_serial_is_rejected_without_stock_or_case_effect` |
| `WAR-AC-06` | Active warranty khác trả `409 ACTIVE_WARRANTY_EXISTS` | `test_serial_with_another_active_warranty_case_is_rejected` |

### 2.4. Reconciliation expectation

Warranty không tham gia inventory ledger reconciliation:

```text
warranty_case.status/version
↕
warranty_event.from_status/to_status/event_at
```

QA phải đối chiếu movement count, balance values/version trước/sau; không chỉ kiểm tra response.

### 2.5. P0 independent gate

| Reviewer | Scope xác nhận | Evidence hợp lệ | Trạng thái |
|---|---|---|---|
| BA/BAS | AC và business consequence status-only | Jira comment trên exact R4 attachment/hash | `PENDING` |
| Tech Lead | Transaction boundary, locks, no stock write | Review exact commit + artifact | `PENDING` |
| QA1 | TC + DB before/after + reconciliation expectation | CI artifact/digest + Jira comment | `PENDING` |

## 3. P1-01 — Exact approval gate và artifact pair

Approval target không còn là `11552` hoặc `11560`. Release R4 phải upload **cả hai file** từ cùng exact commit:

1. `docs/docs_task_jira/WWHN-128.md`;
2. `docs/docs_task_jira/WWHN-128-source-register.json`.

Comment approval trên WWHN-24 bắt buộc có:

```text
APPROVAL DEC-INV-001 v1.4-R4
Repository: Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE
Branch: m1
Commit SHA: <exact 40-char SHA>

Decision filename: <exact filename>
Decision attachment ID: <new ID>
Decision SHA-256: <64 lowercase hex from exact downloaded bytes>

Source register filename: <exact filename>
Source register attachment ID: <new ID>
Source register SHA-256: <64 lowercase hex from exact downloaded bytes>

Supersedes decision attachments: 11560, 11552, 11420
Supersedes source-register attachment: 11559
Scope: inventory authority, warranty status-only, operation idempotency,
       signed reconciliation, source/traceability/evidence policy
Approval date: <ISO-8601>
Decision: APPROVED | APPROVED WITH CONDITIONS | REJECTED
Conditions: <none or explicit conditions>
Approver: <exact Jira account>
```

Approval không hợp lệ nếu hai artifact không cùng commit, thiếu filename/ID/full SHA-256/commit SHA/scope/date/decision/approver.

## 4. P1-02 — Current Source Register

### 4.1. Current business sources theo WWHN-129

| Source | Version | Attachment ID | Trạng thái chính xác |
|---|---:|---:|---|
| BRD | `3.12` | `11386` | `CURRENT_ID_VERIFIED_SHA_PENDING` |
| SRS/FRD | `1.5` | `11415` | `CURRENT_ID_VERIFIED_SHA_PENDING` |
| OpenAPI | `1.1` | `11414` | `CURRENT_ID_VERIFIED_SHA_PENDING` |
| BPD | `1.4` | `11419` | `CURRENT_ID_VERIFIED_SHA_PENDING` |
| Baseline Register | `2.2.1` | `11545` | `CURRENT_ID_VERIFIED_SHA_PENDING` |

Quy tắc:

- Attachment ID xác định Jira object; full SHA-256 xác định content.
- Chỉ chuyển `CURRENT_SHA_VERIFIED` sau khi tải exact bytes và tính SHA-256.
- Không dùng `PENDING_JIRA_READ` cho các ID đã được BA công bố.
- Handoff artifact không thay thế implementation baseline.
- Revision cũ hơn các version trên là `SUPERSEDED`, trừ khi Baseline Register 2.2.1 chỉ rõ ngoại lệ.

### 4.2. Machine-readable register

`docs/docs_task_jira/WWHN-128-source-register.json` là artifact bắt buộc, schema `1.2`, đồng bộ với R4 và không còn:

- `document_revision=1.3-R3`;
- approval target `11552`;
- source attachment IDs null;
- claim Jira read unavailable cho dữ liệu đã được reviewer cung cấp.

## 5. P1-03 — Traceability chain cho WWHN-130

| Source/Decision | Rule | API/UI/Code | Test/AC | Affected ticket |
|---|---|---|---|---|
| BRD 3.12 / DEC §2 | Warranty status-only | `POST /api/v1/warranty-cases/{id}/temp-only`; `WarrantyController::tempOnly` | WAR-AC-01..06 | WWHN-24, WWHN-31, WWHN-33, WWHN-128, WWHN-129, WWHN-130 |
| BRD 3.12 / DEC §2 | `IN_STOCK` reject; Post Issue required | `WarrantyController::assertLinkedSerialEligible` | WAR-AC-05 | WWHN-31, WWHN-33, WWHN-128, WWHN-130 |
| DEC §2 | Một active warranty/serial | serial row lock + active-case query | WAR-AC-06 | WWHN-31, WWHN-33, WWHN-128, WWHN-130 |
| OpenAPI 1.1 / DEC §6 | Operation-level idempotency | `IdempotencyStore::handle`; `warranty_temp_only_{case_id}` | WAR-AC-03..04; WWHN-180 suite | WWHN-31, WWHN-33, WWHN-128, WWHN-130 |
| DEC §7 | Signed `SUM(qty_delta)` theo `stock_status` | `GET /api/v1/reports/inventory-reconciliation`; `ReportController` | RECON-01..06 | WWHN-31, WWHN-33, WWHN-128, WWHN-130 |
| Baseline Register 2.2.1 / DEC §8 | Không claim PASS thiếu evidence | exact-SHA workflow + artifact manifest | EVID-01..05 | WWHN-24, WWHN-33, WWHN-128, WWHN-129, WWHN-130 |

Repository trace không thay thế Jira trace. BAS phải đăng structured text ở §13.2 lên WWHN-130, kèm exact commit/artifact IDs/hash.

## 6. P1-04 — Operation-level idempotency

### 6.1. Scope và replay contract

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

- cùng scope + cùng canonical payload: replay exact committed 2xx snapshot;
- cùng scope + payload khác: HTTP `409`, `error.code=IDEMPOTENCY_ERROR`;
- callback, business effect/event và response snapshot cùng outer transaction;
- callback lỗi rollback business write và dọn đúng lease ownership;
- stale takeover dùng conditional update; request thua race không chạy callback;
- key validation: thiếu key `428`, key invalid `422` khi endpoint yêu cầu.

### 6.2. Multi-line business key

```text
SHA1(action | aggregate_id | product_id | stock_status [| line_id])
```

Mỗi movement line dùng business key riêng; một line lỗi rollback toàn operation.

### 6.3. PostgreSQL schema contract

```sql
CREATE UNIQUE INDEX uk_stock_movements_business_key
    ON stock_movements (business_key);

CREATE UNIQUE INDEX uk_idempotency_operation_user_key
    ON idempotency_keys (idem_key, operation, user_id);

CREATE UNIQUE INDEX uk_inventory_balance_dimension
    ON inventory_balances (warehouse_id, product_id, stock_status);

CREATE UNIQUE INDEX uk_movement_idempotency_not_null
    ON stock_movements (idempotency_key)
    WHERE idempotency_key IS NOT NULL;
```

### 6.4. Test/review mapping

`tests/Feature/Wwhn180IdempotencyAtomicityTest.php` — blob `37e5f6806c021d009bca904e922e30d5da85e971`:

- canonical payload khác thứ tự key vẫn replay;
- same-key/same-payload callback một lần;
- same-key/different-payload 409;
- rollback khi callback lỗi;
- lease cleanup theo ownership;
- key validation.

Trạng thái: `IMPLEMENTED / TECH LEAD + QA1 REVIEW PENDING`. Không tự chuyển PASS.

## 7. P1-05 — Signed reconciliation và stock_status dimension

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

### 7.2. Runtime/test mapping

| Artifact | Git blob SHA | Kiểm soát |
|---|---|---|
| `Modules/Warehouse/app/Http/Controllers/ReportController.php` | `91612835ee00192fdd36590c403c1d1363fd8edc` | Union dimensions, signed SUM, filters, missing balance/ledger |
| `tests/Feature/InventoryReconciliationIntegrityTest.php` | `2374dbff3c679a0cfebb022b1a4ff8ece6fa6588` | Ledger-only và balance-only dimensions |
| `tests/Feature/InventoryReconciliationSignedStatusTest.php` | `3d941e075585bfb2c68f2b1b4eb78b57da5588be` | `+10 + (-3) = 7`; AVAILABLE/DAMAGED độc lập |

Regression expectation:

- receipt `+10`, issue `-3` => expected `7`;
- AVAILABLE và DAMAGED không gộp;
- ledger thiếu balance => `BALANCE_MISSING_FOR_LEDGER`;
- balance thiếu ledger => `LEDGER_MISSING_FOR_BALANCE`;
- `only_mismatch=true` chỉ trả mismatch rows.

Trạng thái: `IMPLEMENTED / EXACT-SHA CI + QA1 REVIEW PENDING`.

## 8. P1-06 — BA re-review exact revision

BA WWHN-129 chỉ được ký khi review:

- `DEC-INV-001 v1.4-R4`;
- decision artifact + source register artifact mới, exact IDs/filenames/full SHA-256;
- exact repository commit SHA;
- source IDs `11386`, `11415`, `11414`, `11419`, `11545`;
- từng finding R3 bằng `ACCEPTED`, `ACCEPTED WITH CONDITIONS` hoặc `REJECTED`.

Review trên `11315`, `11420`, `11552`, `11560` hoặc register `11559` không đóng R4.

## 9. P1-07 — Exact-SHA test evidence

Workflow: `.github/workflows/wwhn-128-inventory-decision-contract.yml` — blob `9e6dfab80742be4902ec139845762700c7243fd6`.

Evidence tối thiểu:

- exact commit SHA;
- workflow run ID + attempt + conclusion;
- `junit.xml`;
- `phpunit.log`;
- `route-list.json`;
- `commit-stat.txt`;
- `changed-files.txt`;
- `build-metadata.json`;
- `SHA256SUMS` và artifact digest;
- database engine/environment;
- QA1 identity + review date.

Hiện trạng tại thời điểm soạn R4: `NO VERIFIED RUN/STATUS AVAILABLE`. Do đó chỉ được ghi:

```text
DESIGNED / AUTOMATED TEST PRESENT / EXECUTION EVIDENCE PENDING
```

## 10. P1-08 — Four-role independent review ledger

| Vai trò | Owner chức năng | Scope bắt buộc | Evidence hợp lệ | Trạng thái |
|---|---|---|---|---|
| BA/BAS | BA/BAS được phân công trên Jira | AC, source register, traceability, consequence | Comment exact R4 hash | `PENDING` |
| Tech Lead | Reviewer kỹ thuật được phân công | Transaction, locking, idempotency, indexes, signed query | Review exact commit/artifact | `PENDING` |
| QA1 | QA1 được phân công | Negative/race/regression, DB evidence, artifact digest | CI evidence + Jira comment | `PENDING` |
| PM/Approver | Formal approver trên WWHN-24 | Scope, version, hashes, effective date, conditions | Formal approval | `PENDING` |

Không self-sign-off; không dùng cùng một comment chung chung để thay bốn scope review. Nếu governance yêu cầu segregation, cùng một cá nhân không ký hai vai trò độc lập.

## 11. P2 — Quality corrections

Đã chuẩn hóa:

- `RETURNED_TO_CUSTOMER`, không dùng `RETURNED_TO_CUSTOMED`;
- UUID dùng `string`;
- controller/service trả result rõ ràng;
- version history không trùng version;
- SQL index đúng PostgreSQL;
- pseudocode dùng field thật `product_id`, `stock_status`, `on_hand_qty`, signed `qty_delta`;
- branch metadata chỉ dùng `m1`;
- source IDs và approval artifact pair đồng bộ;
- không dùng `PENDING_JIRA_READ` cho IDs đã biết;
- structured closure là text có field cố định, không phải blob/link-only comment.

## 12. R3 → R4 Closure Matrix

| ID | Kết quả trong release unit R4 | External gate còn lại | Trạng thái trung thực |
|---|---|---|---|
| `BE1-R3-P0-01` | Rule + code + AC/TC + reconciliation expectation | BA + Tech Lead + QA1 ký exact R4 | `BE1 COMPLETE / SIGN-OFF PENDING` |
| `BE1-R3-P1-01` | Approval gate dùng exact decision + register R4; supersede 11560/11559 và cũ hơn sau upload | Upload/hash/PM approve | `RELEASE PACKAGE READY / APPROVAL PENDING` |
| `BE1-R3-P1-02` | IDs 11386/11415/11414/11419/11545 được đồng bộ vào MD + JSON | Full source SHA-256 | `ID VERIFIED / SHA PENDING` |
| `BE1-R3-P1-03` | Trace matrix và WWHN-130 comment template hoàn chỉnh | BAS post/comment/attachment/link | `BE1 COMPLETE / BAS ACTION PENDING` |
| `BE1-R3-P1-04` | Design/code/test mapping idempotency hoàn chỉnh | Tech Lead + QA1 review | `IMPLEMENTED / REVIEW PENDING` |
| `BE1-R3-P1-05` | Signed query/status dimension/test mapping hoàn chỉnh | CI + QA1 review | `IMPLEMENTED / EVIDENCE PENDING` |
| `BE1-R3-P1-06` | Exact-R4 BA re-review request chuẩn hóa | BA comment WWHN-129 | `REQUEST READY / BA PENDING` |
| `BE1-R3-P1-07` | No false PASS; exact evidence contract | Run ID/conclusion/digest/QA1 | `POLICY COMPLETE / RUN PENDING` |
| `BE1-R3-P1-08` | Four-role ledger + independent criteria | Bốn xác nhận | `LEDGER READY / SIGNATURES PENDING` |
| `BE1-R3-P2-01` | Quality corrections và metadata đồng bộ | Không | `DONE` |
| `BE1-R3-P2-02` | Structured comments tại §13 | Post lên Jira | `READY_TO_POST / JIRA WRITE BLOCKED` |

## 13. Structured Jira comments

### 13.1. WWHN-128 — BE1 closure matrix

```text
[BE1 R4 CLOSURE MATRIX — WWHN-128]
Decision: DEC-INV-001 v1.4-R4
Repository: Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE
Branch: m1
Commit SHA: <exact 40-char SHA>

Decision artifact: <filename> / attachment <ID> / SHA-256 <64 hex>
Source register: <filename> / attachment <ID> / SHA-256 <64 hex>
Supersedes: decision 11560, 11552, 11420; register 11559

P0-01 Warranty: BE1 COMPLETE; BA/TL/QA1 sign-off PENDING.
P1-01 Exact artifact pair gate: READY; upload/hash/approval PENDING.
P1-02 Source IDs: 11386/11415/11414/11419/11545; full source SHA PENDING.
P1-03 Traceability: COMPLETE in R4 §5; WWHN-130 BAS action PENDING.
P1-04 Idempotency: IMPLEMENTED; TL/QA1 review PENDING.
P1-05 Reconciliation: IMPLEMENTED; exact-SHA CI/QA1 PENDING.
P1-06 BA re-review: REQUEST READY; WWHN-129 PENDING.
P1-07 No unsupported PASS; run/conclusion/digest PENDING.
P1-08 Four-role ledger: READY; signatures PENDING.
P2-01: DONE.
P2-02: THIS STRUCTURED COMMENT POSTED.

Result: READY FOR INDEPENDENT RE-REVIEW; NOT APPROVED/BASELINED.
```

### 13.2. WWHN-130 — Traceability evidence

```text
[WWHN-130 TRACEABILITY — DEC-INV-001 v1.4-R4]
Exact commit: <SHA>
Decision attachment/hash: <ID> / <SHA-256>
Source register attachment/hash: <ID> / <SHA-256>

1) BRD 3.12 → Warranty status-only → POST /warranty-cases/{id}/temp-only
   → WarrantyController::tempOnly → WAR-AC-01..06
   → WWHN-24/31/33/128/129/130.
2) BRD 3.12 → IN_STOCK reject/POST_ISSUE required
   → assertLinkedSerialEligible → WAR-AC-05.
3) OpenAPI 1.1 → operation idempotency
   → IdempotencyStore::handle → WAR-AC-03/04 + WWHN-180 suite.
4) DEC §7 → signed SUM(qty_delta) by warehouse/product/stock_status
   → inventory-reconciliation endpoint → RECON suite.
5) Baseline Register 2.2.1 → exact-SHA evidence policy
   → workflow + artifact manifest → EVID gate.

Disposition: TRACE LINKED; BAS REVIEW <ACCEPTED/CONDITION/REJECTED>.
Reviewer/date: <account>/<ISO-8601>.
```

### 13.3. WWHN-129 — BA re-review request

```text
[BA RE-REVIEW REQUEST — WWHN-128 R4]
Please review only DEC-INV-001 v1.4-R4 at exact commit <SHA>.
Decision artifact: <ID>/<filename>/<SHA-256>.
Source register: <ID>/<filename>/<SHA-256>.
Current sources: BRD 3.12/11386; SRS-FRD 1.5/11415;
OpenAPI 1.1/11414; BPD 1.4/11419; Baseline Register 2.2.1/11545.

Return one result per BE1-R3 finding:
ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED,
with reviewer account and ISO-8601 timestamp.
```

### 13.4. WWHN-24 — Formal approval request

Dùng đúng template ở §3 sau khi BA/BAS, Tech Lead và QA1 đã ký; không gửi approval request trước khi exact hashes và CI evidence tồn tại.

## 14. External gate register

| Gate | Owner | Target SLA đề xuất | Disposition | Ảnh hưởng nếu chưa đóng |
|---|---|---|---|---|
| Upload/hash cặp artifact R4 | BE1/Jira uploader | Ngay khi Jira write access khả dụng | Upload exact files từ final commit; paste SHA vào comments | Approval target chưa tồn tại |
| WWHN-130 trace post | BAS | ≤ 1 business day sau upload | Post §13.2 và review | Traceability Jira chưa đạt |
| WWHN-129 BA re-review | BA | ≤ 1 business day sau upload | Post §13.3, review từng finding | P1-06 chưa đóng |
| Tech Lead review | Tech Lead | ≤ 1 business day sau exact SHA | Review §6/§7 + schema/query | P0/P1 design gate chưa đóng |
| CI exact-SHA + QA review | QA1 | ≤ 1 business day sau workflow | Verify run/artifact/SHA256SUMS | Không được claim PASS |
| Formal approval | PM/Approver | ≤ 1 business day sau ba review | Approve theo §3 | Decision chưa effective |

SLA là đề xuất để owner xác nhận trên Jira; không phải chữ ký thay owner.

## 15. Independent verification commands

```bash
php -l Modules/Warehouse/app/Models/InventorySerial.php
php -l Modules/Warehouse/app/Http/Controllers/WarrantyController.php
php -l Modules/Warehouse/app/Http/Controllers/ReportController.php
php -l app/Support/IdempotencyStore.php
php -l tests/Feature/WarrantyStatusOnlyContractTest.php
php -l tests/Feature/InventoryReconciliationSignedStatusTest.php
php -l tests/Feature/InventoryReconciliationIntegrityTest.php
php -l tests/Feature/Wwhn180IdempotencyAtomicityTest.php

git diff --check

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

## 16. Definition of Done và status boundary

### 16.1. BE1 repository package complete khi

- R4 Decision Record và Source Register đồng bộ trong một commit trên `m1`;
- code/test/blob mappings chính xác;
- source IDs chính xác;
- closure/trace/re-review/approval templates có cấu trúc;
- không còn false claim;
- external gate có owner, target SLA, disposition và impact.

### 16.2. Formal Decision complete khi

- cặp artifact R4 đã upload và hash;
- WWHN-130 trace evidence đã post;
- BA/BAS, Tech Lead, QA1 review exact R4;
- CI exact-SHA có run/conclusion/digest;
- PM/Approver ký formal approval.

## 17. Version history

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-07-23 | Initial decision draft |
| 1.1 | 2026-07-31 | Prior revision |
| 1.2-R2 | 2026-08-03 | Correct warranty/idempotency/reconciliation design and claim policy |
| 1.3-R3 | 2026-08-03 | Code/test mapping, exact-SHA workflow, attachment 11552 gate |
| 1.4-R4 | 2026-08-04 | Exact R4 artifact pair; current source IDs; synchronized JSON register; structured Jira comments; owner/SLA/disposition |
