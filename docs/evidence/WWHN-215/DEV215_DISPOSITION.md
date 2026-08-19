# WWHN-215 — Disposition: Khắc phục và debug lỗi ledger/quét mã

| | |
|---|---|
| Ticket | `WWHN-215` (parent `WWHN-52` → `HN-QA-003-ST01`) |
| Role | BE2 |
| Register đang đóng | `DEV215-P0-01`, `DEV215-P1-01..05`, `DEV215-P2-01` |
| Branch | `m1` (không tạo nhánh riêng) |
| Ngày | 2026-08-19 |
| Candidate SHA (commit cuối chạm tập artifact scan/ledger) | `0142b49ed11ac410136ca96993b3fb6ca2ac2971` |
| HEAD lúc kiểm (m1 nhiều phiên song song, chỉ tham chiếu, không dùng để pin) | `9b184439a7f25dbaed4b6c19af638d143b7b2061` |

## 1. Kết luận gốc

`DEV215-P0-01` mô tả vị trí lỗi là `m1-be-2@608164.../StockDocumentService::scanLine` +
`StockLedger::scanReceipt/scanIssue`. Xác minh trực tiếp trên `m1` HEAD hiện tại:

1. **`StockLedger` không còn `scanReceipt`/`scanIssue`.** Class hiện chỉ có `inbound()`,
   `outbound()`, `reverse()` — cả ba đều **chỉ được gọi từ `postStockDocument()`** (Post), không
   gọi từ đường scan. Xem `Modules/Warehouse/app/Services/StockLedger.php`.
2. **`StockDocumentService::scanLine` không còn tồn tại** dưới tên đó; phương thức tương đương
   `validateScanLine()` chỉ `UPDATE stock_document_lines.scanned_qty`, trả `stock_effect: 'NONE'`,
   `movement_created: false` — không gọi `StockLedger`, không ghi `inventory_balances`/
   `stock_movements`. Toàn bộ `StockDocumentService` + `StockLedger` **không được route nào trỏ
   tới** (`InboundDocumentController`/`OutboundDocumentController` chỉ còn `index`/`show`), nên
   đây là code không thể reach qua HTTP dù còn nằm trong source.
3. **Đường scan thật đang chạy** (route sống, xác minh bằng `php artisan route:list`) là
   `CurrentBaselineStockFlowController::inboundScan` → `CurrentBaselineStockService::
   scanStockDocument()` (nhập kho) và `AlignedOutboundFlowController::outboundScan` → cùng
   `scanStockDocument()` (xuất kho). Đọc trực tiếp source: `scanStockDocument()` chỉ
   `UPDATE stock_document_lines`, ghi `inbound_scan_entries`/`outbound_item_matches` (evidence/
   match) và scan-event; **không có câu lệnh nào ghi `inventory_balances` hay `stock_movements`**
   trong toàn bộ thân hàm. Stock effect (`mutateBalance` + `INSERT stock_movements`) chỉ nằm
   trong `postStockDocument()`, được gọi từ `POST .../post-receipt` và `POST .../post-issue`.
4. **Baseline nghiệp vụ khẳng định đúng thiết kế này**, không phải suy diễn từ code:
   `HN_SRS_FRD_v1.12_FULL.md` §5.1 "Scan không có stock effect", §5.2 "Scan/matching không tạo
   Packing_Label/snapshot/lệnh in và không đổi tồn... Post Issue... Chính action cuối này mới
   commit giảm tồn", §5.3 "Inventory authority dựa trên movement ledger... sai giao dịch dùng
   Reversal", §7.1 DEC-INV-001 (ledger = authoritative, balance = projection cùng transaction),
   §7.5 Reconciliation Controls ("never by direct ledger or Balance edit").

**Kết luận:** `DEV215-P0-01` là **P0 inherited** từ trạng thái cũ của nhánh `m1-be-2`
(commit `6081649`, rất cũ — trước cả `WWHN-188/195/198/201/204/231`), không phải defect đang tồn
tại trên `m1` candidate. Việc loại "stock effect khỏi Scan" **đã được thực hiện** qua chuỗi ticket
`WWHN-188` (idempotency), `WWHN-195` (gỡ per-item label khỏi scan), `WWHN-198`/`WWHN-201`
(outbound/Post Issue), `WWHN-204` (tách rebuild projection khỏi sửa tồn trực tiếp), và chốt minh
bạch bằng `WWHN-231`/`DISP-STK-231` — đúng cùng class `StockLedger::scanReceipt/scanIssue` được
nêu trong `DISP-STK-231.md` dòng 39 khi liệt kê bề mặt `m1-be-2` đã bị loại bỏ. Không có code mới
nào cần viết cho `DEV215-P0-01`; hạng mục còn lại là **traceability + regression thật** buộc chặt
bằng chứng đã có vào đúng khóa `WWHN-215`, giống mô hình đã dùng ở `WWHN-214`.

`m1-be-2 → m1`: `git rev-list --left-right --count origin/m1-be-2...HEAD` = `0  850` (đo lại
2026-08-19 cho riêng WWHN-215) — `m1-be-2` không có commit nào vượt trước `m1`; tái khẳng định
`DEC-MIG-SRC-001` và `DISP-OUT-198-CANONICAL-BRANCH.md`.

## 2. Bằng chứng chạy thật (`DEV215-P0-01`, `DEV215-P1-02`, `DEV215-P1-03`)

### sqlite (mặc định phpunit.xml)

30 file test phủ scan (inbound/outbound/component), idempotency/replay, concurrency (lock/race
guard), ledger projection/reconciliation, reversal (item-level, net-effect, scope) chạy tại
candidate SHA:

```
docs/evidence/WWHN-215/logs/sqlite-ledger-scan-regression.log
Tests: 182, Assertions: 1960, Failures: 0, Errors: 0, Skipped: 2
```

2 skip là `Wwhn199ReservationConcurrencyGuardTest`/nhóm race cần multi-connection thật — tự skip
trên sqlite, bằng chứng race 2-process PostgreSQL thật cho đúng nhóm này đã có sẵn ở
`docs/evidence/WWHN-198/` (`e0ef044`) và `docs/evidence/WWHN-199/` (`227a34a`), không chạy lại.

### PostgreSQL 16.14 (`hoa_nam_audit`, container `wms_warehouse_hoa_nam_be-postgres-1`)

```
docs/evidence/WWHN-215/logs/pgsql-stock-neutral-scan.log
Wwhn231StockNeutralLedgerTest: OK (9 tests, 54 assertions)
  ✔ inbound scan does not touch movement or balance   -> balance/movement snapshot before==after
  ✔ outbound scan and label preparation are stock neutral -> balance/movement snapshot before==after
  ✔ detected divergence creates incident without touching stock -> reconciliation ghi incident, không sửa ledger/balance
```

Đây chính là bằng chứng API/DB before/after mà `DEV215-P0-01` yêu cầu: scan qua đúng route sống
(`POST /inbound-documents/{id}/scan`, `POST /outbound-documents/{id}/scan`) với `balance_delta=0`,
`movement_delta=0` trên PostgreSQL thật; cùng test còn đối chứng Post-Receipt làm đổi tồn (nếu
không, "before==after" ở scan có thể chỉ vì fixture chưa từng chạm tầng tồn).

```
docs/evidence/WWHN-215/logs/pgsql-reconcile-dry-run.log
Đối soát hoàn tất: 0 dimension kiểm tra, 0 lệch phát hiện (DB chia sẻ hiện không có dữ liệu tồn dư —
mọi phiên khác dùng RefreshDatabase trong transaction nên không để lại state; xem §3 caveat)
```

### `DEV215-P2` — nhiễu môi trường đã gặp, không thuộc phạm vi WWHN-215

Chạy cùng lúc `Wwhn204LedgerReconciliationCoverageTest.php` trên PostgreSQL dính
`SQLSTATE[42703]: column "idempotency_key" of relation "audit_log" does not exist` — đúng bẫy đã
ghi ở `reference-be-test-drivers` memory: một phiên Claude khác đang thêm cột vào `audit_log`
nhưng migration chưa apply nhất quán trên DB dùng chung. Không sửa DB chung để "fix", ghi nhận là
drift môi trường không liên quan `WWHN-215` (log:
`docs/evidence/WWHN-215/logs/pgsql-known-drift-audit-log-column.log`).

## 3. `DEV215-P1-03` — Reconciliation mismatch/duplicate/orphan/no-negative

- **Mismatch = 0**: `Wwhn231StockNeutralLedgerTest::test_detected_divergence_creates_incident_
  without_touching_stock` (PG, seed lệch thật) chứng minh formula `variance = on_hand_qty -
  SUM(qty_delta)` phát hiện đúng lệch và chỉ ghi `reconciliation_incidents`, không sửa
  ledger/balance — khớp §7.5.
- **Duplicate movement = 0**: `business_key` unique trên `stock_movements` (migration
  `2026_01_01_000035`) + `PostIssueConcurrencyBehaviorTest`, `PostReceiptConcurrencyBehaviorTest`,
  `StockDocumentConcurrencyGuardTest`, `Wwhn184PostReceiptRuntimeConcurrencyTest` (đều PASS trong
  §2) chứng minh 2 request đồng thời Post cùng chứng từ chỉ tạo đúng 1 movement.
- **Orphan = 0**: `Wwhn205StockMovementItemIdInvariantTest` (PASS) khoá bất biến movement ITEM
  luôn có `item_id` hợp lệ trỏ về `Item_Instance`, không có movement rời rạc.
- **No-negative**: `Wwhn201PostIssueAtomicNonNegativeStockTest` (PASS) — Post Issue không cho
  `on_hand_qty`/`available_qty` âm, atomically rollback nếu vượt tồn.

Caveat: PostgreSQL DB dùng chung `hoa_nam_audit` hiện không có dữ liệu tồn dư ngoài transaction
test (mọi phiên dùng `RefreshDatabase`), nên lệnh `inventory:reconcile --dry-run` chạy trực tiếp
trả 0 dimension — không mang thêm thông tin ngoài các test trên. Không tạo dữ liệu giả trên DB
chia sẻ chỉ để lấy số "mismatch=0" đẹp hơn, vì có thể va chạm phiên khác đang test.

## 4. `DEV215-P1-01` — Canonical fix source

Đã có governance quyết định sẵn, tái dùng nguyên trạng cho WWHN-215:

- `DEC-MIG-SRC-001-migration-source-disposition.md`: `m1` là nhánh nguồn duy nhất, `m1-be-2`
  superseded.
- `DISP-OUT-198-CANONICAL-BRANCH.md`: `git merge-base --is-ancestor origin/m1-be-2 origin/m1` =
  true.
- `DISP-STK-231-ledger-rescope.md` §1, §6 (`DEV231-P0-02`): xác nhận đúng cặp
  `StockLedger::scanReceipt/scanIssue` này là bề mặt `m1-be-2` đã bị loại khỏi `m1`.

Không cần port fix ngược sang `m1-be-2` (nhánh đó không chạy production, không nhận thêm commit
theo `DEC-MIG-SRC-001`). Không cần formal supersede riêng cho `WWHN-215` vì `DEC-MIG-SRC-001` đã
là quyết định phạm vi toàn repo, áp dụng cho mọi ticket tham chiếu `m1-be-2`.

## 5. `DEV215-P1-05` — Contract impact

**NO CONTRACT CHANGE.** Không có commit code nào được tạo cho `WWHN-215` (chỉ đọc + chạy test +
viết evidence dưới `docs/evidence/WWHN-215/`), nên `storage/api-docs/api-docs.json` và Postman
collection không đổi. Response shape của `POST /inbound-documents/{id}/scan` và
`POST /outbound-documents/{id}/scan` giữ nguyên `stock_effect: "NONE"`, `movement_created: false`
như đã publish từ `WWHN-188`/`WWHN-195`/`WWHN-198`.

## 6. FE — không đụng

`Source & Traceability` của `WWHN-215` chỉ định Role BE2; FE1 là bên **nhận** contract/evidence,
không phải bên sửa code ở ticket này (giống `WWHN-214`). Vì không có contract nào đổi (§5), FE
không có việc gì phải làm cho `WWHN-215`.

## 7. `DEV215-P2-01` — Jira metadata

Description ghi owner "BE2 — Duy Khánh Nguyễn" nhưng live assignee là Quang. Đây là placeholder
lịch sử giống hệt case đã xử lý ở `DISP-STK-231` §7 — không phải lỗi code, cần PM cập nhật
Description/Assignee field trên Jira, DEV không tự sửa metadata Jira.

## 8. Còn chờ ngoài vai trò DEV (không tự ký)

- `DEV215-P1-04`: QA1 (WWHN-213 dependency) phải tự chạy independent suite trên candidate SHA
  `0142b49ed11ac410136ca96993b3fb6ca2ac2971` và post kết quả trực tiếp — DEV không tự sign-off hộ.
- `DEV215-P2-01`: PM cập nhật Description/Assignee.
- CI (`ci_run` nếu register có đòi): **cố ý không chạm** theo yêu cầu người dùng — ngân sách
  GitHub Actions đã hết 3000/3000 phút, không kích hoạt workflow mới cho ticket này (cùng quyết
  định đã áp dụng ở `WWHN-231`).
