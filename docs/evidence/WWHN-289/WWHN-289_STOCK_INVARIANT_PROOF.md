# WWHN-289 · `DEV289-P1-03R` — Stock invariant proof (7 chiều, PostgreSQL 16)

Register 73/100 liệt kê đúng bảy chiều phải chạy lại trên build đã pin: **retry, same-key-different-payload, injected rollback, negative stock, true concurrency/race, reversal, reconcile** — kèm `mismatch = 0`. Closure evidence phải là *"Raw API/DB logs + SHA + PostgreSQL env + mismatch=0 + PASS"*.

Đây cũng là **AC #3 của parent WWHN-77**: "Stock-changing Khắc phục chạy lại invariant/idempotency/concurrency/rollback suite".

- Producer: `tests/Feature/Wwhn289StockInvariantProofTest.php`
- Số liệu thô do chính test sinh: `build/evidence/wwhn-289/stock-invariant-proof.json`
- Exact SHA: `WWHN-289_PINS.json` → `change_range.final`
- Môi trường đo: **PostgreSQL 16** (`meta.driver = pgsql` nằm trong chính artifact, không phải lời khai trong tài liệu)

## Vì sao bản này khác bản vòng trước

Vòng trước tuyên bố 5 chiều nhưng docblock khai một chiều `negative` **không có test method nào chạy**, và chiều `concurrent` chỉ bấm Reverse hai lần tuần tự — bằng chứng nói nhiều hơn nó chứng minh. Đó chính là lỗ mà review 73/100 chỉ ra. Bản này bổ sung hai chiều thật (`negative_stock`, `true_concurrency`) và đổi tên chiều cũ cho khớp đúng thứ nó đo (`reversal`).

Ngoài ra, số liệu thô vòng trước đo trên **sqlite** trong khi DB tham chiếu là PostgreSQL. Bản này đo trên PostgreSQL 16.

## Ánh xạ chiều đo ↔ điều khoản baseline

| Chiều | Điều khoản | Kịch bản lái qua HTTP thật | Kết quả đo |
|---|---|---|---|
| `retry` | NFR-SRS-001 / TC-NFR-001 — *"Retry 3 times cùng key/payload = một movement/balance change"* | 3× `POST /inbound-documents/{id}/post-receipt` cùng `Idempotency-Key` | lần 1 `Idempotent-Replay: false`, lần 2–3 `true`; `stock_movements` = **1**; balance cộng đúng một lần |
| `idempotency_conflict` | AC-GWT-IDEMP-001 / TC-P1-030 | cùng key: payload H1 → commit; retry H1 → replay; H2 khác payload → xung đột | H2 = **409 `IDEMPOTENCY_CONFLICT`**; movement/balance không đổi |
| `rollback` | NFR-SRS-002 / TC-NFR-002 — *"Injected failure rollback all"* | ném lỗi ngay **sau** `INSERT INTO stock_movements` (điểm muộn nhất của giao dịch) | HTTP **500**, body **không** lộ `insert into` / tên bảng / stack frame; movement + balance rollback; document giữ `SCANNING`; **retry sau sự cố Post được** |
| `negative_stock` | NFR-SRS-003 nửa *"prevents negative stock"* + AC-GWT-OUT-001 | (1) đảo 6 trong khi chỉ nhập 4; (2) đảo tiếp khi tồn đã về 0; (3) bất biến toàn cục | cả hai chân bị chặn **422 `REVERSAL_QTY_EXCEEDED`**, tồn không đổi; `min(quantity, on_hand_qty, available_qty)` toàn DB = **0**, `negative_observed = false` |
| `true_concurrency` | NFR-SRS-003 nửa *"parallel requests: one success; others conflict"* | (A) bắt SQL của lần Post thắng để chứng minh có row lock thật; (B) người thua lease idempotency; (C) người thua optimistic lock (`If-Match` cũ) | trên PostgreSQL quan sát được `SELECT … FOR UPDATE` trên `inventory_balances`; người thua lease = **409 `CONCURRENCY_CONFLICT`**; người thua optimistic lock bị chặn; **không ai sinh stock effect thứ hai** |
| `reversal` | NFR-SRS-003 *"duplicate reversal"* + BR-REV-08 | reverse cùng một `movement_id` hai lần (key khác nhau = hai người bấm song song) | lần 2 = **422 `REVERSAL_QTY_EXCEEDED`**; không sinh movement; tồn = 0, **không âm** |
| `reconcile` | §7.5 Reconciliation Controls | vòng đời chỉ bằng stock effect thật (nhập, nhập, đảo) | `rows_checked = 2`, **`mismatch_count = 0`**, `report_status = PASS`; đối soát SQL thô độc lập cũng **0 lệch** |

## Vì sao phần reconcile này đáng tin

1. **Không seed balance bằng tay.** Fixture `readyToPostIssueDocument()` `INSERT` thẳng `inventory_balances` mà không có movement tương ứng, nên đối soát ra `mismatch = 1` — đó là **lỗi của bằng chứng, không phải lỗi của sản phẩm**. Vòng đời trong bài này được dựng lại để mọi số dư đều đến từ ledger thật.
2. **Đối soát hai nguồn.** Một qua `GET /api/v1/reports/inventory-reconciliation`, một qua truy vấn SQL thô `SUM(qty_delta) GROUP BY warehouse/sku/status` so với `on_hand_qty`. Reviewer không phải tin một nguồn duy nhất.
3. **`mismatch = 0` là số đo, không phải lời khẳng định.** Nó nằm trong artifact JSON do test ghi ra; cổng CI **xoá artifact đã commit trước khi chạy** và bắt buộc lần chạy phải sinh lại — nếu không, cổng đỏ. Điều đó chặn đúng kiểu "cổng tự xác nhận bằng file tĩnh".

## Ràng buộc DB đỡ phía dưới (kiểm chứng bằng migration, không phải niềm tin)

- `stock_movements.business_key` **UNIQUE** — `2026_01_01_000035_create_stock_movements_table.php`.
- `inventory_balances` UNIQUE `(warehouse_id, sku_id, stock_status)` — `2026_08_10_000600` + `ux_erd_inventory_balance_bucket`.
- `mutateBalance()` dùng `lockForUpdate()`, chặn `quantity < 0` và `available_qty < 0`, cập nhật có điều kiện `version` (mất optimistic lock → 409).

## Giới hạn của bằng chứng này

Đây là bằng chứng **DEV**, đo trên DB tham chiếu PostgreSQL 16 tại đúng SHA đã pin. Nó **không** thay thế retest của QA1 trên `WWHN-291`; DEV không tự ký cổng đó.

Lưu ý vận hành khi retest: chạy trên **database riêng**. Hai tiến trình cùng `migrate:fresh` trên một database PostgreSQL sẽ deadlock (SQLSTATE 40P01) — đó là xung đột môi trường, không phải defect sản phẩm.
