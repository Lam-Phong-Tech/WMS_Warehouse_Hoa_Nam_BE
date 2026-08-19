# DEC-STK-202 — Phân lớp mã lỗi Post Issue (409 vs 422) và quy tắc gán `stock_movements.item_id`

| Field | Value |
|---|---|
| ID | `DEC-STK-202` |
| Jira | `WWHN-202` (DEV, output "Balance hỗ trợ" của HN-OUT-003) · liên quan `WWHN-201`, `WWHN-203`, `WWHN-205`, `WWHN-47` |
| Register | `DEV202-P1-03R2` (409 vs 422), `DEV202-P1-04R2` (item attribution) |
| Repository / branch | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` · `m1` (không tạo nhánh riêng) |
| Baseline điều khiển | SRS/FRD v1.12 §8.2 (HTTP/Error Contract), REQ-SRS-011/012 + AC-SRS-011/012, AC-GWT-OUT-001, DEC-INV-001; ERD `stock_movements` (append-only) / `inventory_balances` (projection) |
| Trạng thái | **DEV-DECIDED · chờ BA + Tech Lead sign-off** (không tự đóng trên Jira) |
| Ngày lập | 2026-08-19 |

Decision record này tồn tại vì hai lý do đo được, không phải để bổ sung tài liệu:

1. SRS §8.2 chỉ liệt kê **ví dụ** mã lỗi cho từng HTTP status. Post Issue có 6 tình huống
   thất bại khác nhau và trước đây không có văn bản nào nói tình huống nào rơi vào 409, tình
   huống nào rơi vào 422 — dẫn tới review lặp lại cùng một câu hỏi.
2. Evidence WWHN-202/WWHN-207 (viết ở thời điểm trước `WWHN-205`) mô tả `stock_movements.item_id`
   **luôn** null. Code hiện tại không còn như vậy. Cần một quy tắc chính thức thay cho mô tả
   đã lệch, để việc sửa evidence không phải là "sửa chữ" mà là "chốt hợp đồng".

---

## D-1. Phân lớp mã lỗi của `POST /api/v1/outbound-documents/{id}/post-issue`

Nguyên tắc phân lớp (bám đúng §8.2, không phát minh thêm tầng nghĩa):

- **422** = yêu cầu *tự nó* không hợp lệ theo quy tắc nghiệp vụ/validation. Gửi lại y hệt vẫn sai
  cho tới khi **dữ liệu nghiệp vụ** đổi.
- **409** = yêu cầu hợp lệ nhưng *va chạm với trạng thái/lần gọi khác*. Gửi lại y hệt có thể đúng
  khi **trạng thái** đổi (hoặc chính nó là replay của một lần thành công).
- **412 / 428** = tầng optimistic-lock, đứng trước cả hai — không được nuốt vào 409.

| # | Tình huống | HTTP | `error_code` | Nguồn phát | Bất biến bắt buộc |
|---|---|---|---|---|---|
| 1 | Thiếu `If-Match` | 428 | `PRECONDITION_REQUIRED` | middleware If-Match | stock/movement/state không đổi |
| 2 | `If-Match` lệch version hiện tại | 412 | `STALE_VERSION` | middleware If-Match | stock/movement/state không đổi |
| 3 | Cùng `Idempotency-Key`, **cùng** payload | 200 | — (replay bản ghi cũ) | `IdempotencyStore` | **không** ghi movement thứ hai |
| 4 | Cùng `Idempotency-Key`, **khác** payload | 409 | `IDEMPOTENCY_CONFLICT` (`IdempotencyStore::CONFLICT_PAYLOAD_DIFFERS`) | `IdempotencyStore` | stock/movement không đổi |
| 5 | Lease idempotency đang bận (2 request song song cùng key) | 409 | `CONCURRENCY_CONFLICT` (`IdempotencyStore::CONFLICT_LEASE_BUSY`) | `IdempotencyStore` | đúng một request commit |
| 6 | Document không ở trạng thái cho phép Post (đã POSTED/CANCELLED, chưa full match, label chưa PRINTED, chưa xác nhận đã dán) | 409 `STATE_CONFLICT` **nếu là xung đột trạng thái chứng từ**; 422 `BUSINESS_RULE_ERROR` **nếu là điều kiện nghiệp vụ chưa thoả** | như cột trái | flow controller | stock/movement không đổi |
| 7 | `business_key` của stock effect đã tồn tại (double-post đi vòng qua đường khác) | 409 | `DUPLICATE_STOCK_EFFECT` | `CurrentBaselineStockService::mutateBalance()` | stock/movement không đổi |
| 8 | Không đủ tồn khả dụng tại thời điểm commit | 422 | `INSUFFICIENT_STOCK` | `CurrentBaselineStockService::mutateBalance()` | **không âm tồn**, toàn bộ transaction rollback |
| 9 | `quantity <= 0` | 422 | `INVALID_QUANTITY` | `mutateBalance()` | không ghi gì |
| 10 | Ngoài data scope kho / thiếu permission | 403 | `ACCESS_DENIED` | `permission:*` + `EnforceOutboundWarehouseScope` | không ghi gì, có `request_id` |

### Ranh giới hay bị nhầm — ghi rõ để không phải tranh luận lại

- **Hết tồn là 422, không phải 409.** Đây là quyết định. Lý do: §8.2 liệt kê
  `INSUFFICIENT_STOCK` đích danh ở hàng 422, và về ngữ nghĩa nó là vi phạm quy tắc nghiệp vụ
  ("không âm tồn" — AC-SRS-011), không phải va chạm phiên bản/đồng thời. Việc lần Post trước
  của người khác đã lấy hết tồn không biến nó thành lỗi concurrency: hệ thống vẫn đang cho câu
  trả lời đúng và ổn định cho *chính yêu cầu này*. Bằng chứng chạy:
  `Wwhn201PostIssueAtomicNonNegativeStockTest::test_second_post_issue_is_rejected_with_insufficient_stock_when_first_post_already_consumed_the_shared_balance`.
- **`DUPLICATE_STOCK_EFFECT` là mã 409 hợp lệ.** §8.2 ghi "Error code **examples**", nên tập mã
  409 không đóng. `DUPLICATE_STOCK_EFFECT` thuộc họ `STATE_CONFLICT` (một stock effect đã tồn tại)
  và **phải** được liệt kê trong `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md` — catalog
  là nơi §8.2 uỷ quyền chi tiết. Client không được coi đây là mã lạ.
- **Quan hệ với WWHN-267.** `docs/evidence/WWHN-267/HTTP_ERROR_CODE_PAIRING.json` khoá cặp
  (mã dành riêng của §8.2 ↔ status) cho **toàn hệ thống**: ở đó `INSUFFICIENT_STOCK → 422`,
  `IDEMPOTENCY_CONFLICT/CONCURRENCY_CONFLICT/STATE_CONFLICT → 409`, `STALE_VERSION → 412`,
  `PRECONDITION_REQUIRED → 428`. DEC-STK-202 **không** định nghĩa lại các cặp đó; nó chỉ trả lời
  câu hỏi mà pairing pin không trả lời được: *tình huống nghiệp vụ nào của Post Issue rơi vào mã
  nào*. Nếu hai tài liệu lệch nhau, WWHN-267 thắng ở tầng (mã ↔ status), DEC-STK-202 thắng ở tầng
  (tình huống → mã). `DUPLICATE_STOCK_EFFECT` không nằm trong `reservedPairings` của WWHN-267 nên
  không bị ràng buộc kép — vẫn phải có mặt trong error catalog.
- **Không được hạ 412 xuống 409.** `ApiConventionMiddleware` phân lớp 409 theo nội dung message;
  nếu một ngày nào đó message của stale-version chứa chữ "conflict" thì mã sẽ trôi. Test
  `Wwhn202OutboundBalanceEvidenceTest::test_post_issue_error_taxonomy_matches_srs_8_2` khoá cả 4
  nhánh (412 / 200-replay / 409 / 422) trong một lượt chạy để chống đúng kiểu trôi đó.

### Enforcement

| Bất biến | Test khoá |
|---|---|
| 412 stale, 200 replay, 409 khác payload, 422 hết tồn — cùng một luồng | `Wwhn202OutboundBalanceEvidenceTest::test_post_issue_error_taxonomy_matches_srs_8_2` |
| Không âm tồn + rollback nguyên vẹn khi bị chặn | `Wwhn201PostIssueAtomicNonNegativeStockTest` |
| Cùng key khác payload không ghi lần hai; replay quyết định trước optimistic lock | `Wwhn234PostIssueIdempotencyPayloadTest` |
| 401/403 trên toàn bộ bề mặt đọc + DB không đổi sau 403 | `Wwhn202OutboundBalanceEvidenceTest::test_every_read_surface_rejects_{anonymous_access_with_401,missing_permission_with_403_and_changes_nothing}` |

---

## D-2. Quy tắc gán `stock_movements.item_id` (thay cho mô tả đã lệch ở ATT-11845)

**Quy tắc chính thức.**

> `CurrentBaselineStockService::postStockDocument()` ghi **đúng một** `stock_movements` cho mỗi
> `stock_document_line`, với `quantity = qty_planned`.
> `resolveLineItemId($documentType, $lineId)` gán `item_id` **khi và chỉ khi** line đó được back
> bởi **đúng một** item (`inbound_scan_entries` cho receipt / `outbound_item_matches` cho issue,
> loại các bản ghi `status = 'REMOVED'`).
> Line gộp **nhiều** item (`qty_planned > 1`, serial-tracked) hoặc **không** item nào
> (COMPONENT / SKU quantity-mode) → `item_id` **là null một cách hợp lệ**, không phải khiếm khuyết:
> một dòng ledger không thể trung thực quy về nhiều hơn một serial vật lý.

**Hệ quả bắt buộc ở tầng đọc.** `StockMovementController::traceByPhysicalCode()` phải giữ **cả hai**
nhánh, theo đúng thứ tự:

1. Nhánh trực tiếp: `stock_movements.item_id = :resolvedItemId`.
2. Nhánh fallback: resolve line mà item đã được scan/match vào
   (`inbound_scan_entries.item_id` / `outbound_item_matches.item_id` → `*_line_id`), rồi lấy
   `stock_movements` theo `stock_document_line_id` của (các) line đó.

Bỏ nhánh 2 sẽ làm `trace-by-code` trả rỗng cho mọi item thuộc line gộp — đúng lỗi P1 mà WWHN-202
đã ghi nhận. Bỏ nhánh 1 sẽ làm mất độ chính xác cho trường hợp phổ biến nhất (line 1 item).

**`item_id` là thuộc tính bất biến sau khi ghi.** `stock_movements` có trigger append-only
(`HN-WMS-ERD-DD-001`) chặn mọi `UPDATE`. Vì vậy không tồn tại kịch bản "backfill `item_id` sau khi
post"; muốn đổi quy kết phải đi qua Reversal. Bất kỳ evidence/đề xuất nào giả định có thể UPDATE
`stock_movements.item_id` đều **không thực hiện được** trên schema hiện tại — đây là lý do kỹ thuật,
không phải lựa chọn thiết kế mềm.

### Enforcement

| Vế | Test khoá | Task |
|---|---|---|
| Line 1 item (issue) → `item_id` = item đó | `Wwhn205StockMovementItemIdInvariantTest::test_post_issue_sets_item_id_on_movement_for_single_item_line` | WWHN-205 (write side) |
| Line 1 item (receipt, serial-tracked) → `item_id` = item đó | `Wwhn205...::test_post_receipt_sets_item_id_on_movement_for_single_item_serial_tracked_line` | WWHN-205 |
| Line gộp >= 2 item → `item_id` null | `Wwhn205...::test_post_receipt_leaves_item_id_null_when_line_aggregates_multiple_items` | WWHN-205 |
| SKU quantity-mode (COMPONENT) → `item_id` null | `Wwhn205...::test_component_receipt_leaves_item_id_null_for_quantity_mode_sku` | WWHN-205 |
| Đọc: line 1 item dùng nhánh trực tiếp | `Wwhn202OutboundBalanceEvidenceTest::test_movement_item_attribution_follows_dec_stk_202` | WWHN-202 (read side) |
| Đọc: line gộp 2 item → `item_id` null **và** trace-by-code vẫn resolve qua fallback | `Wwhn202OutboundBalanceEvidenceTest::test_aggregate_line_leaves_item_id_null_and_trace_by_code_falls_back_to_the_line` | WWHN-202 |

---

## D-3. Việc còn lại (không thuộc DEV)

| Ai | Xác nhận điều gì |
|---|---|
| BA Main | D-1 (đặc biệt: hết tồn = 422) và D-2 là quy tắc nghiệp vụ chính thức, thay cho mô tả cũ ở ATT-11845 |
| Tech Lead | D-1 khớp §8.2 + `HN-BE-API-002_ERROR_CODE_CATALOG`; D-2 khớp ERD `stock_movements` append-only |
| QA1 (WWHN-203) | Dùng đúng bảng D-1 làm expected khi retest, không tự suy mã lỗi từ HTTP status |
| FE1 | Không hardcode giả định "409 = hết tồn"; đọc `error_code`, không đọc `message` |
