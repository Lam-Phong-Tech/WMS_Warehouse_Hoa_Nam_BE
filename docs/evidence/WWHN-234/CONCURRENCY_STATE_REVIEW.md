# WWHN-234 · DEV234-P1-02 — Concurrency & State Review

| Field | Value |
|---|---|
| Register item | `DEV234-P1-02` (P1) |
| Owner → Reviewer | DEV → Tech Lead + BA |
| Gate | Done |
| Baseline pin | xem `REVIEW_IDENTITY_PIN.json` (BE SHA, FE SHA, OpenAPI `info.version`) |
| Contract nguồn | SRS/FRD v1.12 §5.5, §6, §8.1, §8.2, §13.2, §13.3; `HN_OpenAPI` `2.0.0` (231 operation) |
| Ngày review | 2026-08-18 |
| Scope authority | **Nhánh A** của `SCOPE_AUTHORITY_DISPOSITION.md` — Stocktake OUT OF SCOPE |

> Đây là bản review thật, không phải bản tóm tắt. Mọi mệnh đề đều dẫn `file:line` trên
> baseline được pin, hoặc dẫn kết quả lệnh đã chạy trong `EXECUTION_LOG.md`.

---

## 1. Vì sao vòng review trước không đạt

Vòng trước bị đánh giá 32/100 vì đã claim "đã review concurrency/state" mà output không
tồn tại: không state matrix, không lock order, không SHA. Nhưng nguyên nhân sâu hơn — và là
thứ task này phải đóng — là **không có đường nối kiểm chứng được giữa ma trận TC của SRS
§13.3 và test đang chạy trong repo**:

| Đo | Kết quả trên baseline |
|---|---|
| Số TC trong SRS §13.3 | **32** (`TC-P1-001..016`, `TC-P1-025..040`) |
| Số TC được test BE gọi tên | **1** (`TC-P1-030`, trong `tests/Feature/Wwhn289StockInvariantProofTest.php`) |

Nghĩa là 31/32 TC không có cách nào trả lời câu "test nào chứng minh nó?" ngoài việc đọc
tay 120 file test. Mỗi vòng review lại phải suy luận lại từ đầu, và reviewer không có cách
xác minh — nên mặc định là không tin. Bản review này kèm `TC_P1_TRACEABILITY.json` để biến
ánh xạ đó thành dữ liệu máy đọc được, và `scripts/verify-wwhn234-closure.mjs` để nó không
mục đi ở lần refactor kế tiếp.

## 2. Phạm vi review

### 2.1. Trong phạm vi — action đổi tồn thật sự tồn tại trên baseline

| # | Action | Endpoint (operationId) | Permission | Data scope |
|---|---|---|---|---|
| A1 | POST_RECEIPT | `stock_in_post_receipt` — `POST /api/v1/inbound-documents/{id}/post-receipt` | `inbound.post` | `EnforceInboundWarehouseScope` |
| A2 | POST_ISSUE | `post_api_v1_outbound__documents_id_post__issue` | `outbound.post` | `EnforceOutboundWarehouseScope` |
| A3 | POST_REVERSAL (inbound) | `post_api_v1_inbound__documents_id_reversals` | `inventory.reversal.post` | `EnforceInboundWarehouseScope` |
| A3' | POST_REVERSAL (outbound) | `post_api_v1_outbound__documents_id_reversals` | `inventory.reversal.post` | `EnforceOutboundWarehouseScope` |
| A4 | COMPONENT_ISSUE_POST | `post_api_v1_warranty__component__issues_id_post`, `post_api_v1_component__issue__documents_id_post` | `warranty.component_issue` | **không có** → F-05 |
| A5 | MIGRATION_COMMIT | job/script (`REQ-SRS-021`: không có endpoint public/admin) | `migration.commit` | job-scoped |

Action stock-neutral nhưng có state machine và cũng được review vì chúng **gate** A1/A2:
scan/unscan/unmatch (`CurrentBaselineStockFlowController:27-231`), Packing Label
preview/ready/print, Putaway confirm/cancel.

### 2.2. Ngoài phạm vi

**Stocktake**: `REQ-SRS-037` (Must) cấm expose; đo trên baseline có 0 route / 0 permission /
0 controller / 0 operation / 0 bảng (chi tiết `SCOPE_AUTHORITY_DISPOSITION.md` §2). Không có
runtime nào để review. `TC-P1-017..024` **không tồn tại** trong SRS §13.3 — đây là khoảng
trống đánh số của baseline, không phải TC bị bỏ sót; ghi nhận cho BA ở F-07.

---

## 3. Ma trận trạng thái (SRS §6 ↔ code)

| Object | States (SRS §6) | Transition được code thực thi | Nơi enforce | Hành vi invalid |
|---|---|---|---|---|
| Stock document (inbound/outbound) | DRAFT, SCANNING, POSTED, CANCELLED | scan chỉ ở `DRAFT`/`SCANNING`; Post chỉ từ `SCANNING`; POSTED terminal | `CurrentBaselineStockFlowController:258-260`, `:309-311`; `CurrentBaselineStockService:119-122` | `409 STATE_CONFLICT`, không ghi |
| Item | NEW, IN_STOCK, ISSUED, IN_WARRANTY, RETURNED_TO_CUSTOMER, LOST | NEW→IN_STOCK tại Post Receipt; IN_STOCK→ISSUED tại Post Issue; reversal tạo transition đối ứng | `CurrentBaselineStockService::applyReceiptSubledger/applyIssueSubledger`; `CurrentBaselineReversalService:529-539` | `409` (guard dependency), `422 ITEM_NOT_AVAILABLE` |
| Reversal | DRAFT, CONFIRMED, POSTED, CANCELLED | DRAFT→CONFIRMED→POSTED; POSTED/CANCELLED terminal | `CurrentBaselineReversalService:225`, `:264`, `:723` | `409 REVERSAL_NOT_DRAFT` / `REVERSAL_POSTED_IMMUTABLE` |
| Packing_Label | DRAFT, PREVIEWED, READY_TO_PRINT, PRINTED, PRINT_FAILED | tuần tự; reprint dùng lại snapshot | `AlignedOutboundFlowController` (label ops, có If-Match) | `409 LABEL_STATE_CONFLICT` |
| Component Issue | DRAFT, SCANNING, POSTED, CANCELLED | Post chỉ từ DRAFT + case active + purpose WARRANTY | `CurrentBaselineStockService:394-408` | `409 STATE_CONFLICT`, `422` |
| Container (Carton/Box) | IN_STOCK/ISSUED/CLOSED · RECEIVED_PENDING_PUTAWAY/STORED/CLOSED | mapping stock-neutral; fullness suy ra, không persist | `ContainerService`, `ComponentContainerSubledgerService` | `409 CONTAINER_STATE_CONFLICT` |
| Putaway Task | DRAFT, CONFIRMED, CANCELLED | DRAFT→CONFIRMED/CANCELLED, terminal | `CurrentBaselinePutawayTaskController` | `409` |

**Kết luận §3:** ma trận trạng thái của code khớp SRS §6 cho toàn bộ object trong phạm vi;
mọi transition không hợp lệ trả `409` và không ghi — đúng cột "invalid-state behavior".

## 4. Lock target và lock order

Thứ tự khoá của Post (A1/A2) — đọc từ `CurrentBaselineStockFlowController:293-352` và
`CurrentBaselineStockService:112-215`:

```text
1. idempotency_keys       (lease row, FOR UPDATE)   IdempotencyStore::run  :196
2. stock_documents        (FOR UPDATE)              lockDocument           :606
3. stock_document_lines   (FOR UPDATE, ORDER BY line_no)                   :124-128
4. inventory_balances     (FOR UPDATE per SKU, rồi CAS on version)         :858-906
5. stock_movements        (INSERT, unique movement_key)                    :909+
6. subledger item/container                                               :731-813
7. posting_event + audit_log                                              :1011+
```

Reversal (A3) theo cùng cột sống, chèn thêm `reversal_document` → `reversal_line` →
`stock_movements` gốc (`CurrentBaselineReversalService:264-301`) trước khi chạm balance ở
`:392-440`.

**Đánh giá:**

- Lock **luôn đi từ ngoài vào trong** (idempotency → document → line → balance), không có
  đường nào khoá balance trước document. Đây là điều kiện đủ để tránh deadlock chéo giữa
  Post và Reversal trên cùng chứng từ.
- `stock_document_lines` được khoá kèm `ORDER BY line_no` → thứ tự xác định trong cùng một
  chứng từ.
- **F-04 (P2):** `inventory_balances` được khoá theo **thứ tự line của chứng từ**, không
  theo một thứ tự toàn cục (vd `sku_id`). Hai chứng từ cùng chạm hai SKU nhưng khai theo
  thứ tự dòng ngược nhau có thể khoá chéo. Rủi ro được hấp thụ bởi `DB::transaction(..., 3)`
  (retry deadlock 3 lần, `CurrentBaselineStockService:118`), nên không phải lỗi đúng/sai dữ
  liệu; nhưng dưới tải cao nó biến thành deadlock thấy được trong log. Đề xuất: sắp dòng
  theo `product_id` riêng cho pha `mutateBalance`.

## 5. Stale / version behavior

| Tầng | Cơ chế | Mã lỗi | Vị trí |
|---|---|---|---|
| Precondition | `If-Match` bắt buộc cho mọi scan/post/reversal | `428 PRECONDITION_REQUIRED` | `CurrentBaselineStockFlowController:616-629` |
| Định dạng | `W/` và dấu nháy được bóc; phải là số nguyên | `422 INVALID_VERSION` | `:622-627` |
| So khớp | so **dưới lock** `FOR UPDATE`, không so trước khi khoá | `412 STALE_VERSION` + `expected_version`/`current_version` | `:631-639` |
| Ghi | version tăng **trong cùng** transaction, trước khi status thành POSTED | — | `:319-326` |
| Trả về | `ETag: "<version>"` trên mọi response 2xx của scan/post | — | `:34`, `:48`, `:141`, `:230`, `:345-349` |
| Balance | CAS `where('version', $balance->version)` sau khi đã `lockForUpdate` | `409 CONCURRENCY_CONFLICT` | `CurrentBaselineStockService:895-907` |

Điểm quan trọng: `assertVersion` chạy **sau** `lockDocument`, nên không tồn tại cửa sổ
check-then-act. Comment ở `:319-321` giải thích vì sao version phải bump trước khi status
sang POSTED — trên PostgreSQL row POSTED bị chặn mọi UPDATE về sau.

**F-01 (P1 · ĐÃ SỬA trong task này).** Mất CAS trên `inventory_balances` trước đây trả
`409 VERSION_CONFLICT` (3 chỗ), cộng `CONTAINER_VERSION_CONFLICT` và
`REVERSAL_CONCURRENT_CONFLICT`. Không mã nào trong số đó nằm trong §8.2, và FE không map mã
nào trong số đó. Hệ quả thật: request thua race nhận một lỗi đỏ vô nghĩa, người vận hành bấm
Post lại — đúng hành vi mà AC-GWT-CONC-001 cấm. Đã canonical hoá về
`ConflictCode::CONCURRENCY` (`app/Support/ConflictCode.php`), 8 điểm phát.

## 6. Duplicate handling

| Lớp | Cơ chế | Vị trí |
|---|---|---|
| Cùng key, cùng payload | replay đúng response snapshot đã commit, `replayed=true` trong body + header `Idempotent-Replay` | `IdempotencyStore:204-206`; `CurrentBaselineStockFlowController:341-345` |
| Cùng key, khác payload | `409 IDEMPOTENCY_CONFLICT` (canonical JSON + SHA-256, có nhánh tương thích md5 cũ) | `IdempotencyStore:126-139`, `:258-293` |
| Hai request cùng key chạy song song | lease `processing` + `locked_until`; kẻ thua nhận `409 CONCURRENCY_CONFLICT` | `IdempotencyStore:152-154`, `:207-209` |
| Lease chết (process crash) | takeover sau `LOCK_MINUTES = 5` | `:69-92`, `:295-302` |
| Duplicate movement | `movement_key = sha1(TYPE\|documentId\|lineId)` + unique index | `CurrentBaselineStockService:161-172` |
| Duplicate item trong cùng chứng từ | entry/match `ACTIVE` bị chặn; `REMOVED` được quét lại | `CurrentBaselineStockFlowController:522-538` |
| Item đang nằm ở chứng từ khác | `409 ITEM_ALREADY_MATCHED` / `ITEM_ALREADY_RECEIVING` | `CurrentBaselineStockService:526-575` |
| Reversal trùng movement | re-check dưới lock ở cả grain quantity và grain ITEM | `CurrentBaselineReversalService:283-306` |

Hai lớp chống trùng nằm **độc lập** với nhau: kể cả khi client không gửi Idempotency-Key,
unique `movement_key` vẫn chặn movement thứ hai. Đây là lý do TC-P1-005/013/029 vượt được
mà không phụ thuộc riêng vào bảng idempotency.

## 7. Transaction boundary

```text
IdempotencyStore::run  →  DB::transaction  (outer, retry 3)
  └── lease FOR UPDATE + kiểm tra payload hash
  └── $fn()  →  DB::transaction  (nested ⇒ savepoint của Laravel)
        └── document lock → line lock → balance CAS → movement → subledger
        └── document status → posting_event → audit
  └── UPDATE idempotency_keys SET status='done', response_body=<snapshot>
```

Điểm cốt lõi được xác nhận: **snapshot response và stock effect commit trong cùng một
transaction ngoài cùng** (`IdempotencyStore:195-234`). Vì vậy không tồn tại cửa sổ "tồn đã
trừ nhưng idempotency vẫn `processing`" — cửa sổ này nếu có sẽ khiến retry sau timeout tạo
lần trừ tồn thứ hai.

Response không-2xx **không** được cache: lease bị xoá (`:229-231`) nên client sửa dữ liệu rồi
gửi lại bằng cùng key vẫn chạy được. Đây là hành vi đúng theo AC-GWT-IDEMP-001 (chỉ commit
thành công mới được replay).

## 8. Rollback và no-partial-write

- Mọi `ApiError::abort` ném exception → outer transaction rollback → `deleteOwnedLease`
  chỉ xoá **đúng lease của request hiện tại** (`IdempotencyStore:236-248`), không xoá lease
  đã bị request khác takeover.
- Bằng chứng chạy thật: `tests/Feature/Wwhn289StockInvariantProofTest.php`
  `test_rollback_dimension_injected_failure_rolls_back_document_movement_and_balance` —
  inject lỗi sau bước movement/balance rồi assert document/movement/balance về nguyên trạng.
- Bằng chứng chạy thật cho lease: `tests/Feature/Wwhn180IdempotencyAtomicityTest.php`
  `test_business_write_rolls_back_and_lease_is_removed_when_callback_fails`.

## 9. Partial behavior

- **Full-post only**: mọi dòng phải `scanned_qty == qty_planned`, nếu không `422
  FULL_POST_REQUIRED` kèm `line_id/required_qty/scanned_qty`
  (`CurrentBaselineStockService:133-141`). Không có Post một phần.
- **Reversal có phần**: `lines[].movement_id + quantity` là bắt buộc; không có auto-reverse
  toàn chứng từ (`CurrentBaselineStockService:254`, `422 REVERSAL_LINES_REQUIRED`), và
  `reversible_qty` được re-check dưới lock.
- **Gỡ trước Post**: `inboundUnscan`/`outboundUnmatch` chuyển entry/match `ACTIVE → REMOVED`,
  giảm `scanned_qty`, hoàn toàn stock-neutral, chỉ cho phép ở `DRAFT`/`SCANNING`
  (`CurrentBaselineStockFlowController:61-231`).

## 10. Ranh giới stock-effect

| Nhóm action | stock_effect | Xác nhận |
|---|---|---|
| scan / unscan / unmatch | `NONE` | trả `stock_effect: 'NONE'`, `movement_created: false` (`:134-135`, `:223-224`) |
| Packing Label preview/ready/print/reprint | `NONE` | label ops không gọi `mutateBalance` |
| Putaway confirm/cancel | `NONE` | chỉ cập nhật mapping/location |
| Container mapping | `NONE` | không tạo stock bucket |
| **Post Receipt** | `INCREASE` | `mutateBalance(sign=+1)` |
| **Post Issue** | `DECREASE` | `mutateBalance(sign=-1)`; gate: full match + `assertLabelsPrinted` + đủ tồn |
| **Reversal POSTED** | inverse | movement đối ứng, movement gốc bất biến |
| **Component Issue Post** | `DECREASE` (COMPONENT) | `postComponentIssue` |
| Warranty case/status | `NONE` | không chạm sellable stock |

Không âm kho được chặn ở hai lớp: `422 INSUFFICIENT_STOCK` khi `on_hand` sẽ âm
(`CurrentBaselineStockService:884-887`) và `422 INSUFFICIENT_AVAILABLE_STOCK` khi
`available_qty` sẽ âm (`:889-893`).

## 11. Permission / error / audit

- **Permission**: chuỗi `auth:api → permission:<code> → data-scope middleware` được khai
  ngay tại route (bảng §2.1). Từ chối xảy ra **trước** service write, đúng "Enforcement
  standard" của §8.1.
- **Error**: sau F-01/F-02, bốn lớp 409 của §8.2 có đúng một nguồn phát
  (`app/Support/ConflictCode.php`); `412`/`428` giữ nguyên ở tầng document. Catalog
  `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md` được sinh từ source và nay phân
  giải được cả hằng `ConflictCode::*` (trước đó generator chỉ quét string literal nên sẽ
  **âm thầm báo thiếu** đúng bốn mã quan trọng nhất — đã sửa trong task này).
- **Audit**: `AuditLogger::record` ở mọi action đổi tồn kèm `request_id`, `before/after`;
  `posting_event` lưu `operation + idempotency_key + request digest + movement_ids`
  (`CurrentBaselineStockService:1011+`).

---

## 12. Findings register

| ID | Sev | Mô tả | Vị trí | Trạng thái |
|---|---|---|---|---|
| F-01 | P1 | Mất CAS balance/container/reversal trả mã ngoài §8.2 (`VERSION_CONFLICT`, `CONTAINER_VERSION_CONFLICT`, `REVERSAL_CONCURRENT_CONFLICT`); FE không map ⇒ request thua race không được hướng dẫn reload, trái AC-GWT-CONC-001 | 6 điểm phát (BE) | **FIXED** — `ConflictCode::CONCURRENCY` |
| F-02 | P1 | Cùng một tình huống "phải đảo dependency theo thứ tự ngược" phát 2 mã khác nhau: `DOWNSTREAM_DEPENDENCY_EXISTS` (nhánh quantity) vs `REVERSAL_DEPENDENCY_EXISTS` (nhánh item) — **trong cùng một file**. BR-REV-04/TC-REV-02 chỉ đích danh mã thứ nhất | `CurrentBaselineReversalService:535,538`; `CurrentBaselineStockService:826` | **FIXED** — `ConflictCode::DOWNSTREAM_DEPENDENCY` |
| F-03 | P1 | FE chỉ kiểm tra version **trước** khi gửi (pre-flight). Race thật nằm giữa lần đọc detail và `lockForUpdate` của BE ⇒ `412`/`409` rơi xuống đường lỗi chung, người dùng bấm Post lại | `inboundService.postInboundReceipt`, `shippingService.postOutboundIssue` | **FIXED** — `src/shared/api/concurrency-contract.ts` |
| F-04 | P2 | `inventory_balances` khoá theo thứ tự dòng chứng từ, không theo thứ tự toàn cục ⇒ deadlock chéo có thể xảy ra dưới tải (được retry 3 lần hấp thụ) | `CurrentBaselineStockService:147-171` | **OPEN** — đề xuất sort theo `product_id` ở pha `mutateBalance` |
| F-05 | P2 | `POST /warranty-component-issues/{id}/post` và `/component-issue-documents/{id}/post` là action **đổi tồn** nhưng route chỉ có `permission:warranty.component_issue`, **không có** data-scope middleware như inbound/outbound/reversal | `routes/api.php` (2 route) | **OPEN** — không khai thác được dưới MVP một kho (`REQ-SRS-001`), nhưng lệch "Enforcement standard" §8.1 |
| F-06 | P2 | `recordPrintResult` tính `version = $snapshot->version + 1` từ row đọc **ngoài** lock ⇒ lost update khi print/reprint đồng thời cùng label. Không ảnh hưởng tồn (label là stock-neutral, gate Post đọc `status` chứ không đọc `version`) | `CurrentBaselineStockFlowController:456-479` | **OPEN** |
| F-07 | P2 | SRS §13.3 nhảy số từ `TC-P1-016` sang `TC-P1-025`: `TC-P1-017..024` không tồn tại. Nhóm action `COMPONENT_ISSUE_POST` (A4) — một action đổi tồn thật — **không có** hàng nào trong ma trận TC | SRS §13.3 | **OPEN — BA** |
| F-08 | P2 | 31/32 TC của §13.3 không được test nào gọi tên ⇒ không thể trả lời "test nào chứng minh TC này" mà không đọc tay 120 file | repo test BE | **FIXED** — `TC_P1_TRACEABILITY.json` + verifier |

## 13. Thay đổi code thuộc task này

Xem `REVIEW_IDENTITY_PIN.json` → `code_changes[]` để có danh sách chính xác kèm commit.
Tóm tắt:

**BE**
- `app/Support/ConflictCode.php` — mới; nguồn duy nhất của bốn lớp 409 §8.2.
- `app/Support/IdempotencyStore.php` — hai hằng trỏ về `ConflictCode`.
- 5 file write path — 9 điểm phát mã lỗi được canonical hoá (F-01, F-02).
- `scripts/generate-error-code-catalog.mjs` — phân giải hằng `ConflictCode::*`.
- `tests/Feature/Wwhn234ConflictClassContractTest.php` — mới, 6 test / 51 assertion.
- 2 source-guard test cập nhật theo mã mới.

**FE**
- `src/shared/api/concurrency-contract.ts` — mới; ánh xạ bốn lớp §8.2 → hành động UI.
- `src/shared/api/concurrency-contract.test.ts` — mới, 13 test.
- `inboundService.ts`, `shippingService.ts` — bọc Post bằng `withConcurrencyContract`.

## 14. Điều task này **không** khẳng định

- Không khẳng định Stocktake đã được review — không có runtime để review (§2.2).
- Không khẳng định `DEC-STK-001` đã được phê duyệt hay bị bác; đó là `WWHN-25`.
- Không khẳng định F-04/F-05/F-06/F-07 đã được xử lý; chúng OPEN kèm owner đề xuất.
- Không khẳng định QA1 đã chạy độc lập — đó là `WWHN-235`, xem `QA1_HANDOFF_WWHN-235.md`.
