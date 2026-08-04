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
| Phiên bản | `1.5-R5` |
| Trạng thái | **READY FOR INDEPENDENT RE-REVIEW — NOT APPROVED / NOT BASELINED** |
| Ngày phát hành | `2026-08-04` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Nhánh duy nhất | `m1` |
| Parent commit trước R5 | `10680c2e07ca6366e0d51004344daa3ad7634b96` |
| Release commit R5 | Ghi exact 40-char SHA trong Jira sau khi commit; không tự nhúng SHA vòng lặp vào chính artifact |
| Decision artifact trước R5 | Jira attachment `11560` — `WWHN-128.md` |
| Source Register trước R5 | Jira attachment `11559` — `WWHN-128-source-register.json` |
| Release unit R5 | `WWHN-128.md` + `WWHN-128-source-register.json` từ cùng exact commit |
| CI exact-SHA | `.github/workflows/wwhn-128-inventory-decision-contract.yml` |

> **Status boundary:** tài liệu này không tự nhận `APPROVED`, `BASELINED`, `EFFECTIVE`, `TEST PASS` hoặc `DONE`. Các trạng thái đó chỉ hợp lệ khi exact release unit R5 được upload lên Jira, hash từ exact attachment bytes, CI hoàn tất trên exact commit, và BA/BAS, Tech Lead, QA1, PM/Approver xác nhận độc lập đúng scope.

## 0.1. Baseline kỹ thuật áp dụng

R5 tuân thủ `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`:

1. Backend enforce RBAC/data scope; frontend không phải security boundary.
2. Operation đổi tồn phải có transaction, row lock/concurrency guard và idempotency.
3. `stock_movements` là append-only audit ledger; scan/draft/Warranty status-only không đổi tồn.
4. PostgreSQL là database chính cho transaction, constraint, reconciliation và reporting.
5. Lỗi phải có mã ổn định, không lộ stack trace/secret.
6. Test/evidence phải gắn exact code revision và môi trường thực thi.

## 0.2. Live Jira state được xác minh ngày 2026-08-04

- Jira read và comment write đang khả dụng qua Atlassian Rovo.
- Jira attachment upload/download bytes không có action tương ứng trong connector hiện tại; vì vậy attachment ID và SHA-256 của exact Jira bytes là post-commit external gate, không được giả lập.
- `WWHN-129` đang `In Progress`, BA comment mới nhất đang chờ current BE revision.
- `WWHN-130` đang `To Do`, trước R5 chưa có attachment hoặc structured traceability evidence.
- Latest pre-R5 artifacts trên `WWHN-128`: decision `11560`, source register `11559`.

## 1. Quyết định cốt lõi

### 1.1. Authority model

1. `stock_movements` là **authoritative append-only ledger** của số lượng tồn.
2. `inventory_balances` là projection/read model phục vụ truy vấn nhanh; không phải nguồn sự thật cuối cùng.
3. Serial/container là physical subledger phục vụ truy vết vật lý; không thay thế ledger số lượng.
4. Mọi stock-changing operation phải atomic, idempotent, audit được và dùng signed `qty_delta`.
5. Movement đã post không sửa/xóa; sửa sai bằng reversal/adjustment có reference.
6. Warranty là service lifecycle; không phải stock transaction.

### 1.2. Dimension chuẩn

```text
(warehouse_id, product_id, stock_status)
```

Reconciliation không được bỏ `stock_status` và không được đảo dấu `qty_delta` lần hai theo `movement_type`.

## 2. P0 — Warranty status-only và eligibility guard

### 2.1. Business rule bắt buộc

Warranty receive/update/TEMP_ONLY là **status-only**:

- không tạo `stock_movements`;
- không cập nhật `inventory_balances`;
- không tạo movement `qty_delta = 0` để giả lập audit;
- audit lifecycle nằm ở `warranty_event`;
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

### 2.2. Idempotency-Key contract thống nhất

Tất cả endpoint bắt buộc idempotency dùng chung `App\Support\IdempotencyKeyGuard`:

- thiếu/blank: HTTP `428`, `PRECONDITION_REQUIRED`;
- sau trim ngắn hơn 8 hoặc dài hơn 100 ký tự: HTTP `422`, `VALIDATION_ERROR`;
- biên 8 và 100 ký tự hợp lệ;
- validation xảy ra trước mọi DB/business effect.

OpenAPI của Warranty dùng cùng constants `MIN_LENGTH=8`, `MAX_LENGTH=100`, loại bỏ lệch contract 128 ký tự trước R5.

### 2.3. Code/test mapping R5

| Artifact | Git blob SHA R5 | Kiểm soát |
|---|---|---|
| `app/Support/IdempotencyKeyGuard.php` | `f0ea48754fbab4c9b09cc522b7f59a864e924161` | Guard 428/422 và boundary 8–100 dùng chung |
| `Modules/Warehouse/app/Http/Controllers/Concerns/InteractsWithStockDocuments.php` | `d24467b2368d81acdf44bbfc3512bc397642cb72` | Inbound/Outbound dùng shared guard |
| `Modules/Warehouse/app/Http/Controllers/WarrantyController.php` | `dbafecd8b606dafa7c5ca3c83b9b4ee840160fa2` | Row lock, eligibility, active-case guard, status-only, OpenAPI key contract |
| `Modules/Warehouse/app/Models/InventorySerial.php` | `fced829234390ca6faa3a7d862159d1c4ec33c0d` | Status constants chuẩn |
| `app/Support/IdempotencyStore.php` | `78e45f98f799da90d6170dfb6259ce23263383b3` | Operation scope, canonical request hash, exact replay, lease ownership |
| `tests/Feature/WarrantyStatusOnlyContractTest.php` | `485fa3253ddb6f908a839a8d99499ff2096509ae` | No stock effect, replay, 409 payload conflict, IN_STOCK reject, active case |
| `tests/Feature/WarrantyIdempotencyKeyContractTest.php` | `f07d1b983e4108e0e46c2989878a25256755b0f0` | 428/422/no-effect và boundary 8/100 |

### 2.4. Acceptance Criteria / Test Case

| AC/TC | Kỳ vọng | Automated test |
|---|---|---|
| `WAR-AC-01` | TEMP_ONLY trả `stock_effect=NONE`, `movement_created=false` | `test_temp_only_is_status_only_and_replay_does_not_duplicate_event` |
| `WAR-AC-02` | Movement count và balance values/version không đổi | status-only + IN_STOCK tests |
| `WAR-AC-03` | Replay cùng key/payload trả exact snapshot; event count vẫn 1 | status-only replay test |
| `WAR-AC-04` | Cùng key khác payload trả `409 IDEMPOTENCY_ERROR` | different-payload test |
| `WAR-AC-05` | Serial `IN_STOCK` trả 422 và yêu cầu Post Issue | IN_STOCK test |
| `WAR-AC-06` | Active warranty khác trả `409 ACTIVE_WARRANTY_EXISTS` | active-case test |
| `WAR-AC-07` | Thiếu key trả 428, không tạo event/case effect | Warranty idempotency key test |
| `WAR-AC-08` | Key <8 hoặc >100 trả 422 trước DB effect | Warranty idempotency key test |
| `WAR-AC-09` | Key 8 và 100 ký tự được chấp nhận | Warranty idempotency key test |

### 2.5. Reconciliation expectation cho Warranty

Warranty không tham gia inventory ledger reconciliation:

```text
warranty_case.status/version
↕
warranty_event.from_status/to_status/event_at
```

QA phải đối chiếu movement count và balance values/version trước/sau, không chỉ kiểm tra response.

## 3. P1-01 — Exact approval target và artifact identity

Approval target R5 **không phải** attachment `11552`, `11560` hay source register `11559`.

Release R5 phải upload nguyên trạng hai file từ cùng exact commit:

1. `docs/docs_task_jira/WWHN-128.md`;
2. `docs/docs_task_jira/WWHN-128-source-register.json`.

Sau upload, Jira comment approval bắt buộc có:

```text
APPROVAL TARGET — DEC-INV-001 v1.5-R5
Repository: Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE
Branch: m1
Commit SHA: <exact 40-char SHA>

Decision filename: WWHN-128.md
Decision attachment ID: <new exact ID>
Decision SHA-256: <64 lowercase hex calculated from downloaded Jira bytes>

Source register filename: WWHN-128-source-register.json
Source register attachment ID: <new exact ID>
Source register SHA-256: <64 lowercase hex calculated from downloaded Jira bytes>

Supersedes decision attachments after successful upload: 11560, 11552, 11420
Supersedes source-register attachment after successful upload: 11559
Scope: inventory authority, warranty status-only, shared idempotency-key guard,
       operation idempotency, signed reconciliation, traceability/evidence policy
Approval date: <ISO-8601>
Decision: APPROVED | APPROVED WITH CONDITIONS | REJECTED
Conditions: <none or explicit conditions>
Approver: <exact Jira account>
```

Approval không hợp lệ nếu thiếu một trong: exact filename, attachment ID, full SHA-256, commit SHA, scope, date, decision, approver.

## 4. P1-02 — Current Source Register

Current business sources được live-verified từ BA comment trên `WWHN-129`:

| Source | Version | Attachment ID | Trạng thái trung thực |
|---|---:|---:|---|
| BRD | `3.12` | `11386` | `CURRENT_ID_VERIFIED_SHA_PENDING` |
| SRS/FRD | `1.5` | `11415` | `CURRENT_ID_VERIFIED_SHA_PENDING` |
| OpenAPI | `1.1` | `11414` | `CURRENT_ID_VERIFIED_SHA_PENDING` |
| BPD | `1.4` | `11419` | `CURRENT_ID_VERIFIED_SHA_PENDING` |
| Baseline Register | `2.2.1` | `11545` | `CURRENT_ID_VERIFIED_SHA_PENDING` |

Quy tắc:

- Attachment ID xác định Jira object; full SHA-256 xác định content.
- Chỉ chuyển `CURRENT_SHA_VERIFIED` sau khi tải exact bytes và tính SHA-256.
- Không dùng `PENDING_JIRA_READ` cho các ID đã được live-verified.
- Handoff artifact không thay thế implementation baseline.
- Revision cũ hơn các version trên là `SUPERSEDED`, trừ ngoại lệ được Baseline Register 2.2.1 ghi rõ.

Machine-readable register: `docs/docs_task_jira/WWHN-128-source-register.json`, schema `1.3`, revision `1.5-R5`.

## 5. P1-03 — Traceability chain cho WWHN-130

| Source/Decision | Rule | API/UI/Code | Test/AC | Affected ticket |
|---|---|---|---|---|
| BRD 3.12 / DEC §2 | Warranty status-only | `POST /api/v1/warranty-cases/{id}/temp-only`; `WarrantyController::tempOnly` | WAR-AC-01..09 | WWHN-24, 31, 33, 128, 129, 130 |
| BRD 3.12 / DEC §2 | `IN_STOCK` reject; Post Issue required | `assertLinkedSerialEligible` | WAR-AC-05 | WWHN-31, 33, 128, 130 |
| DEC §2 | Một active warranty/serial | serial row lock + active-case query | WAR-AC-06 | WWHN-31, 33, 128, 130 |
| OpenAPI 1.1 / DEC §2.2 | Shared Idempotency-Key 8–100 | `IdempotencyKeyGuard::require` | WAR-AC-07..09; WWHN-180 key tests | WWHN-31, 33, 128, 130, 180 |
| OpenAPI 1.1 / DEC §6 | Operation-level idempotency | `IdempotencyStore::handle`; operation includes aggregate ID | WAR-AC-03..04; WWHN-180 suite | WWHN-31, 33, 128, 130, 180 |
| DEC §7 | Signed `SUM(qty_delta)` theo `stock_status` | `GET /api/v1/reports/inventory-reconciliation`; `ReportController` | RECON-01..06 | WWHN-31, 33, 128, 130 |
| Baseline Register 2.2.1 / DEC §8 | Không claim PASS thiếu evidence | exact-SHA workflow + artifact manifest | EVID-01..05 | WWHN-24, 33, 128, 129, 130 |

Repository trace không thay thế Jira trace. Sau commit, BE1 đăng structured trace comment lên `WWHN-130`; BAS vẫn phải review/disposition bằng account của BAS.

## 6. P1-04 — Operation-level idempotency

### 6.1. Scope

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
- missing/blank key 428; invalid length 422 trước DB effect.

### 6.2. Multi-line business key

```text
SHA1(action | aggregate_id | product_id | stock_status [| line_id])
```

Mỗi movement line có business key riêng; một line lỗi rollback toàn operation.

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

Trạng thái: `IMPLEMENTED / TECH LEAD + QA1 REVIEW PENDING`.

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

BA chỉ được ký khi review:

- `DEC-INV-001 v1.5-R5`;
- exact commit R5;
- decision + source register R5 với exact new attachment IDs/full SHA-256;
- source IDs `11386`, `11415`, `11414`, `11419`, `11545`;
- từng finding bằng `ACCEPTED`, `ACCEPTED WITH CONDITIONS` hoặc `REJECTED`.

Review trên `11315`, `11420`, `11552`, `11560` hoặc register `11559` không đóng R5.

## 9. P1-07 — Exact-SHA test evidence

Workflow R5 blob: `7668045c51ec0a30e612fbe4c96d4e781adbed0c`.

Workflow chạy khi push `m1` chạm code/doc/test R5 và bao gồm:

- PHP syntax + Pint cho helper/concern/controllers/tests;
- Warranty status-only suite;
- Warranty Idempotency-Key 428/422/boundary suite;
- signed reconciliation/integrity suite;
- WWHN-180 idempotency atomicity suite;
- exact commit metadata, route list, changed files, JUnit, log, `SHA256SUMS`;
- immutable artifact `wwhn-128-evidence-<exact-github-sha>`.

Evidence tối thiểu để QA1 ký:

- exact commit SHA;
- workflow run ID + attempt + conclusion;
- `junit.xml`, `phpunit.log`, `route-list.json`;
- `commit-stat.txt`, `changed-files.txt`, `build-metadata.json`;
- `SHA256SUMS` và artifact digest;
- database engine/environment;
- QA1 identity + review date.

Trước khi có run/conclusion/digest/QA1 review, chỉ được ghi:

```text
DESIGNED / AUTOMATED TEST PRESENT / EXECUTION EVIDENCE PENDING
```

## 10. P1-08 — Four-role independent review ledger

| Vai trò | Scope bắt buộc | Evidence hợp lệ | Trạng thái R5 |
|---|---|---|---|
| BA/BAS | AC, source register, traceability, business consequence | Jira comment exact R5 attachment/hash | `PENDING` |
| Tech Lead | Transaction, locks, idempotency, indexes, signed query | Review exact commit/artifact | `PENDING` |
| QA1 | Negative/race/regression, DB before-after, artifact digest | CI evidence + Jira comment | `PENDING` |
| PM/Approver | Scope, version, hashes, effective date, conditions | Formal approval on WWHN-24 | `PENDING` |

Không self-sign-off; một BE1 comment không thay bốn review độc lập.

## 11. P2 — Quality corrections

Đã chuẩn hóa:

- `RETURNED_TO_CUSTOMER`, không dùng typo;
- UUID dùng `string`;
- result và version history rõ ràng;
- SQL/index đúng PostgreSQL;
- pseudocode dùng field thật và signed `qty_delta`;
- branch metadata chỉ dùng `m1`;
- live Jira state không còn claim `HTTP 403`/`write blocked` sai thực tế;
- source IDs không còn `PENDING_JIRA_READ`;
- structured closure là text có field cố định, không phải blob/link-only comment;
- exact artifact identity tách Git blob/commit SHA khỏi Jira attachment ID/SHA-256.

## 12. R3 → R5 Closure Matrix

| ID | Kết quả thuộc BE1 trong R5 | External gate còn lại | Trạng thái trung thực |
|---|---|---|---|
| `BE1-R3-P0-01` | Rule + code + AC/TC + reconciliation expectation + key guard | BA + TL + QA1 ký exact R5 | `BE1 COMPLETE / SIGN-OFF PENDING` |
| `BE1-R3-P1-01` | Approval target là exact R5 pair, không trỏ 11552/11560 | Upload/hash/new IDs + PM approval | `RELEASE PACKAGE READY / UPLOAD PENDING` |
| `BE1-R3-P1-02` | IDs 11386/11415/11414/11419/11545 đồng bộ MD + JSON | Full source SHA-256 | `ID VERIFIED / SHA PENDING` |
| `BE1-R3-P1-03` | Trace matrix và structured WWHN-130 comment hoàn chỉnh | Post-commit comment + BAS review | `BE1 READY / BAS DISPOSITION PENDING` |
| `BE1-R3-P1-04` | Shared key guard + operation idempotency code/test mapping | TL + QA1 review | `IMPLEMENTED / REVIEW PENDING` |
| `BE1-R3-P1-05` | Signed query/status dimension/test mapping | CI + QA1 review | `IMPLEMENTED / EVIDENCE PENDING` |
| `BE1-R3-P1-06` | Exact-R5 BA re-review request | BA comment WWHN-129 | `REQUEST READY / BA PENDING` |
| `BE1-R3-P1-07` | Workflow mở rộng, không false PASS | Run/conclusion/digest/QA1 | `AUTOMATION READY / RUN PENDING` |
| `BE1-R3-P1-08` | Four-role ledger + independent criteria | Bốn xác nhận | `LEDGER READY / SIGNATURES PENDING` |
| `BE1-R3-P2-01` | Typo/type/result/history/SQL/branch metadata | Không | `DONE` |
| `BE1-R3-P2-02` | Structured comments tại §13 | Post sau exact commit | `READY_TO_POST` |

## 13. Structured Jira comments — post-commit

### 13.1. WWHN-128 — BE1 closure matrix

```text
[BE1 R5 CLOSURE MATRIX — WWHN-128]
Decision: DEC-INV-001 v1.5-R5
Repository: Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE
Branch: m1
Commit SHA: <exact 40-char SHA>
Decision path/blob: docs/docs_task_jira/WWHN-128.md / <Git blob SHA>
Source register path/blob: docs/docs_task_jira/WWHN-128-source-register.json / <Git blob SHA>
Previous Jira artifacts: decision 11560; register 11559.
New Jira attachment IDs/SHA-256: PENDING UPLOAD FROM EXACT COMMIT.

P0-01 Warranty: BE1 COMPLETE; BA/TL/QA1 sign-off PENDING.
P1-01 Exact R5 pair: READY; Jira upload/hash/approval PENDING.
P1-02 Source IDs: 11386/11415/11414/11419/11545; source SHA PENDING.
P1-03 Traceability: STRUCTURED COMMENT POSTED TO WWHN-130; BAS review PENDING.
P1-04 Idempotency: shared 8–100 guard + atomic replay IMPLEMENTED; TL/QA1 PENDING.
P1-05 Reconciliation: IMPLEMENTED; exact-SHA CI/QA1 PENDING.
P1-06 BA re-review request POSTED TO WWHN-129; BA disposition PENDING.
P1-07 No unsupported PASS; run/conclusion/digest PENDING.
P1-08 Four-role ledger ready; signatures PENDING.
P2-01 DONE.
P2-02 THIS STRUCTURED COMMENT IS THE CLOSURE EVIDENCE.

Result: READY FOR INDEPENDENT RE-REVIEW; NOT APPROVED/BASELINED.
```

### 13.2. WWHN-130 — Traceability evidence

```text
[WWHN-130 TRACEABILITY — DEC-INV-001 v1.5-R5]
Exact commit: <SHA>
Decision path/blob: <path>/<blob>
Source register path/blob: <path>/<blob>
Jira attachment IDs/SHA-256: pending exact upload.

1) BRD 3.12 → Warranty status-only → POST /warranty-cases/{id}/temp-only
   → WarrantyController::tempOnly → WAR-AC-01..09
   → WWHN-24/31/33/128/129/130.
2) BRD 3.12 → IN_STOCK reject/POST_ISSUE required
   → assertLinkedSerialEligible → WAR-AC-05.
3) OpenAPI 1.1 → Idempotency-Key 8–100 and operation idempotency
   → IdempotencyKeyGuard + IdempotencyStore → WAR-AC-03/04/07/08/09.
4) DEC §7 → signed SUM(qty_delta) by warehouse/product/stock_status
   → inventory-reconciliation endpoint → RECON suite.
5) Baseline Register 2.2.1 → exact-SHA evidence policy
   → workflow + artifact manifest → EVID gate.

BE1 disposition: TRACE PROVIDED.
BAS disposition required: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED.
Reviewer/date: <BAS account>/<ISO-8601>.
```

### 13.3. WWHN-129 — BA re-review request

```text
[BA RE-REVIEW REQUEST — WWHN-128 R5]
Review only DEC-INV-001 v1.5-R5 at exact commit <SHA>.
Decision path/blob: <path>/<blob>.
Source register path/blob: <path>/<blob>.
New Jira attachment IDs/full SHA-256: pending exact upload.
Current sources: BRD 3.12/11386; SRS-FRD 1.5/11415;
OpenAPI 1.1/11414; BPD 1.4/11419; Baseline Register 2.2.1/11545.

Please return one result per BE1-R3 finding:
ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED,
with reviewer account and ISO-8601 timestamp.
```

### 13.4. WWHN-24 — BE1 handoff, không phải formal approval

Chỉ post release-candidate handoff. Formal approval request chỉ hợp lệ sau khi exact Jira attachment IDs/full SHA-256, CI evidence và ba review BA/TL/QA1 tồn tại.

## 14. External gate register

| Gate | Owner | Target SLA đề xuất | Disposition | Ảnh hưởng nếu chưa đóng |
|---|---|---|---|---|
| Upload/hash exact R5 pair | BE1/Jira uploader | Ngay sau commit | Upload nguyên trạng hai file; download lại và SHA-256 | Approval target chưa hoàn chỉnh |
| WWHN-130 trace review | BAS | ≤ 1 business day sau trace post | Review structured trace | P1-03 chưa formal-close |
| WWHN-129 BA re-review | BA | ≤ 1 business day sau upload | Review từng finding trên exact R5 | P1-06 chưa đóng |
| Tech Lead review | Tech Lead | ≤ 1 business day sau exact SHA | Review transaction/key guard/schema/query | P0/P1 design gate chưa đóng |
| CI exact-SHA + QA review | QA1 | ≤ 1 business day sau workflow | Verify run/artifact/SHA256SUMS | Không được claim TEST PASS |
| Formal approval | PM/Approver | Sau ba review | Approve exact artifact pair | Decision chưa effective |

SLA là đề xuất để owner xác nhận trên Jira; không thay chữ ký.

## 15. Verification commands

```bash
php -l app/Support/IdempotencyKeyGuard.php
php -l Modules/Warehouse/app/Http/Controllers/Concerns/InteractsWithStockDocuments.php
php -l Modules/Warehouse/app/Http/Controllers/WarrantyController.php
php -l Modules/Warehouse/app/Http/Controllers/ReportController.php
php -l app/Support/IdempotencyStore.php
php -l tests/Feature/WarrantyStatusOnlyContractTest.php
php -l tests/Feature/WarrantyIdempotencyKeyContractTest.php
php -l tests/Feature/InventoryReconciliationSignedStatusTest.php
php -l tests/Feature/InventoryReconciliationIntegrityTest.php
php -l tests/Feature/Wwhn180IdempotencyAtomicityTest.php

git diff --check

./vendor/bin/phpunit \
  --filter '/(WarrantyStatusOnlyContractTest|WarrantyIdempotencyKeyContractTest|InventoryReconciliationSignedStatusTest|InventoryReconciliationIntegrityTest|Wwhn180IdempotencyAtomicityTest)/'
```

## 16. Definition of Done và status boundary

### 16.1. BE1 repository package complete khi

- Decision Record R5 và Source Register R5 đồng bộ trong một commit trên `m1`;
- shared Idempotency-Key guard được áp dụng cho Warranty và stock-document concern;
- code/test/workflow/blob mappings chính xác;
- source IDs chính xác;
- closure/trace/re-review/approval templates có cấu trúc;
- không có false claim;
- mọi external gate có owner, SLA, disposition và impact.

### 16.2. Formal Decision complete khi

- exact R5 pair được upload, download lại và hash;
- WWHN-130 trace được BAS review;
- BA/BAS, Tech Lead, QA1 review exact R5;
- CI exact-SHA có run/conclusion/digest;
- PM/Approver ký formal approval.

## 17. Version history

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-07-23 | Initial decision draft |
| 1.1 | 2026-07-31 | Prior revision |
| 1.2-R2 | 2026-08-03 | Warranty/idempotency/reconciliation design và claim policy |
| 1.3-R3 | 2026-08-03 | Code/test mapping, exact-SHA workflow, attachment 11552 gate |
| 1.4-R4 | 2026-08-04 | Exact artifact pair concept, current source IDs, structured templates |
| 1.5-R5 | 2026-08-04 | Live Jira verification; shared Idempotency-Key guard; Warranty/OpenAPI 8–100; negative/boundary tests; expanded exact-SHA workflow; closure matrix R5 |
