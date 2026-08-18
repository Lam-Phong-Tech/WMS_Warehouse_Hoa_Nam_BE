# WWHN-289 · `DEV289-P1-03` — Stock invariant proof

Review yêu cầu: *"Chạy fixed-build negative/concurrent/retry/idempotency/rollback + movement/balance reconcile"*, closure evidence là *"Raw API/DB logs + mismatch=0 + exact SHA"*.

Đây cũng là **AC #3 của parent WWHN-77**: "Stock-changing Khắc phục chạy lại invariant/idempotency/concurrency/rollback suite".

- Producer: `tests/Feature/Wwhn289StockInvariantProofTest.php`
- Số liệu thô do chính test sinh: `build/evidence/wwhn-289/stock-invariant-proof.json`
- Exact SHA: xem `WWHN-289_PINS.json` → `change_range.final`

## Ánh xạ chiều đo ↔ điều khoản baseline

| Chiều | Điều khoản | Kịch bản lái qua HTTP thật | Kết quả đo |
|---|---|---|---|
| retry | NFR-SRS-001 / TC-NFR-001 — *"Retry 3 times cùng key/payload = một movement/balance change"* | 3× `POST /inbound-documents/{id}/post-receipt` cùng `Idempotency-Key` | lần 1 `Idempotent-Replay: false`, lần 2–3 `true`; `stock_movements` = **1**; `quantity` = 5 (cộng đúng một lần) |
| idempotency | AC-GWT-IDEMP-001 / TC-P1-030 | cùng key: payload H1 (quantity 2) → commit; retry H1 → replay; H2 (quantity 3) → xung đột | H2 = **409 `IDEMPOTENCY_CONFLICT`**; movement không đổi; balance không đổi |
| rollback | NFR-SRS-002 / TC-NFR-002 — *"Injected failure rollback all"* | ném lỗi ngay **sau** `INSERT INTO stock_movements` (điểm muộn nhất của giao dịch) | HTTP **500** `INTERNAL_SERVER_ERROR`, body **không** lộ `insert into` / tên bảng / `RuntimeException` / stack frame; movement rollback; balance rollback; document giữ `SCANNING` version 2; **retry sau sự cố Post được** (lease idempotency đã nhả) |
| concurrent | NFR-SRS-003 — *"prevents negative stock and duplicate reversal"* | reverse cùng một `movement_id` hai lần (key khác nhau = hai người bấm song song) | lần 2 = **422 `REVERSAL_QTY_EXCEEDED`**; không sinh movement; tồn = 0, **không âm** |
| negative | NFR-SRS-003 vế "không âm tồn" | đã được `Wwhn201PostIssueAtomicNonNegativeStockTest` khoá (2 document cùng SKU, tồn chỉ đủ 1) — WWHN-289 **không dựng lại**, chỉ giữ trong bộ suite bắt buộc | Post thứ hai bị chặn `INSUFFICIENT_STOCK`, không sinh movement |
| reconcile | §7.5 Reconciliation Controls | vòng đời chỉ bằng stock effect thật: nhập 5 + nhập 8 + đảo 2 | `rows_checked = 2`, **`mismatch_count = 0`**, `status = PASS`; đối soát SQL thô độc lập cũng **0 lệch**; tồn còn đúng 3 |

## Vì sao phần reconcile này đáng tin

1. **Không seed balance bằng tay.** Bản nháp đầu tiên dùng fixture `readyToPostIssueDocument()` — fixture đó `INSERT` thẳng `inventory_balances` mà không có movement tương ứng, và đối soát ra `mismatch_count = 1`. Đó là **lỗi của bằng chứng, không phải lỗi của sản phẩm**, nhưng nếu để nguyên thì hoặc là ta công bố số sai, hoặc là ta phải "châm chước" con số — cả hai đều làm hỏng giá trị của gate. Vòng đời được dựng lại để mọi số dư đều đến từ ledger thật.
2. **Đối soát hai nguồn.** Một qua `GET /api/v1/reports/inventory-reconciliation` (chính thứ đang được kiểm chứng), một qua truy vấn SQL thô `SUM(qty_delta) GROUP BY warehouse/sku/status` so với `on_hand_qty`. Reviewer không phải tin một nguồn duy nhất.
3. **`mismatch = 0` là số đo, không phải lời khẳng định.** Nó nằm trong `build/evidence/wwhn-289/stock-invariant-proof.json` do test ghi ra, và CI ở exact SHA sinh lại file này trên PostgreSQL 16.

## Ràng buộc DB đỡ phía dưới (kiểm chứng bằng migration, không phải niềm tin)

- `stock_movements.business_key` **UNIQUE** — `2026_01_01_000035_create_stock_movements_table.php`.
- `inventory_balances` UNIQUE `(warehouse_id, sku_id, stock_status)` — `2026_08_10_000600` + `ux_erd_inventory_balance_bucket`.
- `mutateBalance()` dùng `lockForUpdate()`, chặn `quantity < 0` và `available_qty < 0`, cập nhật có điều kiện `version` (mất optimistic lock → 409 `VERSION_CONFLICT`).

## Giới hạn của bằng chứng này

DEV chạy trên sqlite `:memory:`. Kết quả trên **DB tham chiếu PostgreSQL 16** do CI `.github/workflows/wwhn-289-closure.yml` sinh ở exact SHA. Bằng chứng DEV **không** thay thế retest của QA1 (`WWHN-291`).
