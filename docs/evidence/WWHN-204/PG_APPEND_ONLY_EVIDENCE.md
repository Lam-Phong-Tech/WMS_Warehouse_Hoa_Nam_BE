# WWHN-204 — DEV204-P1-04: Bằng chứng append-only + reconciliation trên PostgreSQL mục tiêu

Log thô đầy đủ: [`logs/pg-append-only.log`](logs/pg-append-only.log) ·
Suite trên PG: [`logs/pg-suite.log`](logs/pg-suite.log) ·
Tái lập: `bash scripts/collect-wwhn204-pg-evidence.sh`

---

## 1. Môi trường

| Mục | Giá trị |
|---|---|
| PG engine/version | `PostgreSQL 16.14 on x86_64-pc-linux-musl, compiled by gcc (Alpine 15.2.0) 15.2.0, 64-bit` |
| Container | `wms_warehouse_hoa_nam_be-postgres-1` |
| Database bằng chứng | `hn_wwhn204` (tạo mới sạch mỗi lần chạy) |
| Database chạy test | `hn_wwhn204_test` |
| PHP | 8.3.30 |
| Nhánh / SHA | `m1` — ghi ở đầu file log mỗi lần chạy |

> Dùng database riêng thay vì `hoa_nam_audit` dùng chung: trên container này đang có ≥17 DB của
> các ticket khác chạy song song, bằng chứng phải tái lập được mà không phụ thuộc trạng thái
> của ai.

## 2. Migration list — hai migration append-only ở trạng thái Ran

```
2026_08_10_000620_remove_superseded_fields_and_lock_append_only_history .. [1] Ran
2026_08_15_000100_enforce_stock_movements_append_only_trigger ........... [1] Ran
2026_08_19_010000_add_ledger_dimension_index_to_stock_movements ......... [1] Ran
2026_08_19_050100_enforce_stock_movements_append_only_on_sqlite ......... [1] Ran
```

Trigger thật trên **bảng** (`relkind = 'r'`, không phải view):

```
                        tgname                          |     relname     | relkind |              proname
--------------------------------------------------------+-----------------+---------+-----------------------------------
 trg_erd_mirror_inventory_movements_from_stock_movements | stock_movements | r       | hn_erd_mirror_inventory_movements
 trg_stock_movements_append_only                         | stock_movements | r       | hn_erd_reject_append_only_change
```

## 3. RAW SQL REJECTION — UPDATE và DELETE trực tiếp đều bị chặn

```sql
UPDATE stock_movements SET qty_delta = 999 WHERE id = '3333…';
ERROR:  stock_movements is append-only under HN-WMS-ERD-DD-001
CONTEXT:  PL/pgSQL function hn_erd_reject_append_only_change() line 3 at RAISE

DELETE FROM stock_movements WHERE id = '3333…';
ERROR:  stock_movements is append-only under HN-WMS-ERD-DD-001
CONTEXT:  PL/pgSQL function hn_erd_reject_append_only_change() line 3 at RAISE
```

Ledger còn nguyên sau hai lần bị từ chối:

```
                  id                  | qty_delta
--------------------------------------+-----------
 33333333-3333-4333-8333-333333333333 |        10
```

Đây là **raw SQL từ psql**, không đi qua ứng dụng — nên nó chứng minh bất biến nằm ở tầng DB,
không phải ở tầng code có thể bị đi vòng.

## 4. DB BEFORE → AFTER: intentional drift rồi rebuild

| Bước | Trạng thái |
|---|---|
| **BEFORE** — drift có chủ đích, chỉ chạm projection | `on_hand_qty = 99`, `quantity = 99`, `available_qty = 99`; ledger vẫn `10` |
| `inventory:reconcile` | `1 dimension kiểm tra, 1 lệch phát hiện, 1 incident mới` — `variance = 89`, `divergence_type = QUANTITY_MISMATCH` |
| `inventory:reconcile --rebuild` | `1 dimension đưa về ledger, 1 incident chuyển RESOLVED`; `Ledger bất biến: 1 dòng / tổng delta 10.000 (không đổi trước-sau)` |
| **AFTER** | `balance_qty = 10 · ledger_qty = 10 · mismatch = 0` |

Incident được đóng có truy vết:

```
      incident_no      |  divergence_type  |  status  |                resolution_ref
-----------------------+-------------------+----------+----------------------------------------------
 REC-20260819-ACV54LQG | QUANTITY_MISMATCH | RESOLVED | REBUILD:ff1c44c8-1f07-4900-85a5-05dfe2c28091
```

Audit trail (`audit_logs` trên PG dùng cột `entity_type`):

```
            action            |    entity_type    |          derived_from
------------------------------+-------------------+--------------------------------
 INVENTORY_PROJECTION_REBUILT | inventory_balance | stock_movements.SUM(qty_delta)
```

Cột `derived_from` là bằng chứng tự mô tả: con số ghi vào projection đến từ ledger, không từ
đầu vào của con người.

## 5. Idempotent + ledger bất biến xuyên suốt

```
# lần chạy thứ hai
Rebuild hoàn tất: run_id=891a0b63-…, 0 dimension đưa về ledger, 0 incident chuyển RESOLVED.

# ledger cuối phiên
 rows | delta_sum
------+-----------
    1 |        10
```

## 6. Kết quả suite tự động

| Driver | Lệnh | Kết quả |
|---|---|---|
| PostgreSQL 16.14 | 4 test class ledger/balance/rebuild trên `hn_wwhn204_test` | **24 test / 637 assertion / 0 fail** |
| sqlite (mặc định, đúng driver CI) | 7 test class ledger/balance/reconcile/rebuild | **35 test / 733 assertion / 0 fail** |

## 7. Ghi chú vận hành phát sinh khi thu bằng chứng

1. **Tên bảng vật lý khác nhau theo driver** — trên PostgreSQL `skus` / `app_users` /
   `audit_logs` là bảng, còn `sku` / `app_user` / `audit_log` là **view**; `audit_logs` dùng cột
   `entity_type` / `entity_id`. Script seed bằng chứng phải viết theo tên vật lý của PG, nếu
   không sẽ nhận `ON CONFLICT` không có unique constraint và FK `fk_erd_inventory_balances_sku_id`.
2. **Nhánh dùng chung gây nhiễu bằng chứng** — lần chạy đầu, `artisan migrate` dừng giữa chừng
   với `FileNotFoundException` cho `2026_08_19_000050_cast_erd_mirror_projection_columns.php`:
   một phiên làm việc song song **đổi tên file migration đó** (thành
   `…_repair_erd_mirror_projection_casts.php`) đúng lúc migrate đang chạy, nên `glob()` thấy
   file cũ còn `require` thì không. Không phải lỗi của migration. Chạy lại khi cây làm việc ổn
   định thì toàn bộ migration Ran sạch. **Bài học cho QA1:** nếu gặp lỗi này, kiểm tra
   `git status` trước khi kết luận migration hỏng.
3. **`vendor/` biến mất giữa chừng** — một phiên song song chạy `composer install` làm
   `vendor/autoload.php` mất, mọi lệnh artisan fatal, và script vẫn ghi ra một log *trông như đã
   chạy* nhưng rỗng số liệu. Script nay **fail-closed**: kiểm `vendor/autoload.php` **và** chạy
   thử `php artisan --version` **và** `pg_isready` TRƯỚC khi xoá log cũ — chỉ kiểm sự tồn tại của
   file là chưa đủ, vì có lúc file đã có nhưng autoloader còn dở. Bước `[17]` là phần tự kiểm:
   log tự tuyên bố nó có đủ mốc bằng chứng hay không, và script thoát khác 0 khi thiếu.
   `bash scripts/collect-wwhn204-pg-evidence.sh --recheck` chấm lại phần `[17]` trên log sẵn có
   mà không chạy lại gì — dùng khi cần chấm lại mà môi trường chưa dựng được.
