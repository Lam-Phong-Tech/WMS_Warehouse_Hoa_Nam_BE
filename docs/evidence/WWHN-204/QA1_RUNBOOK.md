# WWHN-204 — DEV204-P1-02: Runbook QA1 chạy ledger / balance / rebuild suite

**Người thực hiện:** QA1 · **Nghiệm thu:** Tech Lead + BA · **Ticket QA:** `WWHN-206`

Register yêu cầu QA1 *"thực thi ledger/balance/rebuild suite đúng candidate SHA"* với evidence
*"DB before/after; raw SQL/API; PASS/FAIL; exact SHA"*. Runbook này để QA1 tự chạy lại từ đầu,
không cần DEV ngồi cạnh.

---

## 0. Chuẩn bị và ghi exact SHA

```bash
cd /c/LAM_PHONG_TECH/HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE
git rev-parse --abbrev-ref HEAD        # phải in: m1
git rev-parse HEAD                     # GHI LẠI — đây là exact SHA của lần chạy này
git merge-base --is-ancestor 114cf34d6323c85d46ecab2bfa6e03d92b9596a1 HEAD; echo $?  # phải 0
```

> `m1` là nhánh dùng chung, HEAD trôi theo ngày. Cứ ghi SHA tại thời điểm chạy vào biên bản —
> điều kiện nghiệm thu là **`114cf34d` (commit code cuối của ticket) phải là ancestor của SHA đó** (dòng thứ 3 trả `0`),
> không phải là "HEAD bằng một hằng số nào đó".

## 1. Cổng tự động — chạy trước, hỏng thì dừng

```bash
node scripts/verify-wwhn-204-closure.mjs
```

PASS = exit 0 và in `WWHN-204 CLOSURE: PASS`. Script tự dựng lại: ancestry m1 ↔ m1-be-2,
reachability của commit WWHN-204, sự tồn tại của hai migration append-only, bất biến
"rebuild không ghi ledger", và sự vắng mặt của route rebuild trên HTTP.

## 2. Suite trên driver mặc định (sqlite)

```bash
php vendor/bin/phpunit tests/Feature/Wwhn204LedgerProjectionRebuildTest.php tests/Feature/Wwhn204LedgerReconciliationCoverageTest.php tests/Feature/Wwhn219InventoryReconcileCommandTest.php tests/Feature/Wwhn231StockNeutralLedgerTest.php tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php tests/Feature/InventoryReconciliationIntegrityTest.php tests/Feature/InventoryReconciliationSignedStatusTest.php
```

Kết quả tham chiếu của DEV (2026-08-19): **35 test / 733 assertion / 0 fail** —
`docs/evidence/WWHN-204/logs/sqlite-focused.log`.

## 3. Suite trên PostgreSQL mục tiêu

`phpunit.xml` ép `DB_CONNECTION=sqlite` nhưng thẻ `<env>` **không có** `force="true"`, nên biến
môi trường của shell thắng. Dùng một database RIÊNG để không đụng dữ liệu ticket khác:

```bash
docker exec wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -c "DROP DATABASE IF EXISTS hn_wwhn204_test;"
docker exec wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -c "CREATE DATABASE hn_wwhn204_test;"
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hn_wwhn204_test DB_USERNAME=postgres DB_PASSWORD=postgres php vendor/bin/phpunit tests/Feature/Wwhn204LedgerProjectionRebuildTest.php tests/Feature/Wwhn204LedgerReconciliationCoverageTest.php tests/Feature/Wwhn219InventoryReconcileCommandTest.php tests/Feature/Wwhn231StockNeutralLedgerTest.php
```

Kết quả tham chiếu: `docs/evidence/WWHN-204/logs/pg-suite.log`.

## 4. Bằng chứng DB before/after + raw SQL (bắt buộc theo register)

```bash
bash scripts/collect-wwhn204-pg-evidence.sh
```

Script dựng database sạch `hn_wwhn204`, chạy `artisan migrate`, rồi đi hết 16 bước. Log thô:
`docs/evidence/WWHN-204/logs/pg-append-only.log`. QA1 đối chiếu **đúng 6 mốc** sau:

| Mốc | Bước trong log | Kỳ vọng PASS |
|---|---|---|
| M1 — migration đã chạy | `[4]` | `2026_08_15_000100_enforce_stock_movements_append_only_trigger .. Ran` **và** `2026_08_19_050100_enforce_stock_movements_append_only_on_sqlite .. Ran` |
| M2 — raw SQL UPDATE bị chặn | `[7]` | `ERROR: stock_movements is append-only under HN-WMS-ERD-DD-001` |
| M3 — raw SQL DELETE bị chặn | `[8]` | cùng thông điệp trên; bước `[9]` cho thấy dòng ledger **vẫn còn**, `qty_delta = 10` |
| M4 — DB **before**: drift có chủ đích | `[10]` → `[11]` | projection 99, ledger 10, `variance = 89`, `divergence_type = QUANTITY_MISMATCH`, **1 incident mới** |
| M5 — DB **after**: rebuild | `[12]` → `[13]` | `balance_qty = 10`, `ledger_qty = 10`, **`mismatch = 0`**; incident chuyển `RESOLVED` với `resolution_ref = REBUILD:<run_id>` |
| M6 — idempotent + ledger bất biến | `[14]`, `[16]` | lần chạy thứ hai `0 dimension đưa về ledger`; ledger vẫn `1 dòng / tổng delta 10` |

## 5. Kiểm tra qua API (không chỉ CLI)

Sau bước 4, so số của báo cáo với ledger qua HTTP (token có `report.view` / `inventory.view`):

```bash
curl -s -H "Authorization: Bearer $TOKEN" "http://localhost/api/v1/reports/inventory-reconciliation" | head -40
curl -s -H "Authorization: Bearer $TOKEN" "http://localhost/api/v1/inventory/balances" | head -40
```

Kỳ vọng: sau rebuild, `inventory-reconciliation` **không còn dòng lệch nào**. (Cả báo cáo và
lệnh CLI cùng dùng một `LedgerReconciliationService` — WWHN-231 — nên hai bên không thể kết
luận khác nhau trên cùng dữ liệu.)

## 6. Test âm bắt buộc — chống hồi quy mở lại capability đã bị gỡ

| Kiểm tra | Cách chạy | Kỳ vọng |
|---|---|---|
| Rebuild **không** có route HTTP | `Wwhn204LedgerProjectionRebuildTest::test_rebuild_is_not_exposed_as_any_http_route` | PASS (REQ-SRS-037) |
| `--dry-run` không ghi gì | `::test_rebuild_dry_run_reports_plan_without_writing` | PASS |
| Không có endpoint sửa tồn trực tiếp / stocktake | `php artisan route:list \| grep -iE "stocktake\|adjust\|rebuild"` | **không dòng nào** |

## 7. Biên bản QA1 cần nộp

1. Exact SHA `git rev-parse HEAD` + kết quả `git merge-base --is-ancestor 114cf34d… HEAD`.
2. Exit code + output cuối của `verify-wwhn-204-closure.mjs`.
3. Số liệu PASS/FAIL của mục 2 và mục 3.
4. Log thô mục 4 kèm nhận định PASS/FAIL cho **từng mốc M1..M6**.
5. Kết luận cho `WWHN-206` và `WWHN-48` AC#2: ĐẠT / KHÔNG ĐẠT.

> Nếu bất kỳ mốc nào FAIL: **không** tự sửa dữ liệu để cho qua. Ghi lại nguyên trạng, gắn
> owner và ETA, trả lại DEV. Đặc biệt, nếu `[16]` cho thấy ledger đổi số ⇒ có đường ghi ledger
> nằm ngoài kiểm soát, đây là lỗi P0 chứ không phải sai lệch bằng chứng.
