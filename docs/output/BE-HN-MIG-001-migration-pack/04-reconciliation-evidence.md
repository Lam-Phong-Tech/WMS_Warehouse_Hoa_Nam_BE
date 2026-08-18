# 4. Bằng chứng đối soát — DEV260-P1-05

## 4.1 Năm nhóm check chạy trong transaction COMMIT

| Check | Ý nghĩa §13.12 | Nguồn so sánh |
|---|---|---|
| `SOURCE_QTY_EQUALS_LEDGER` | 100% lượng SKU của nguồn đã duyệt khớp ledger | từng dòng OPENING_BALANCE vs `stock_movements` của run |
| `BALANCE_EQUALS_LEDGER` | Balance khớp ledger | `inventory_balances.on_hand_qty` vs tổng `qty_delta` |
| `SERIAL_TRACKED_QTY_EQUALS_ITEM_COUNT` | Lượng serial-tracked = số Item hợp lệ duy nhất | OPENING_BALANCE vs dataset ITEM |
| `CONTAINER_MAPPING_NO_EXTRA_STOCK` | Container mapping không sinh thêm tồn | tổng lượng nguồn vs tổng `qty_delta` của run |
| `RUN_WRITES_ONLY_OPENING_MOVEMENTS` | Run chỉ ghi movement `OPENING` | đếm movement khác `OPENING` của run |

Hai check cuối là phần **mới** của WWHN-260: trước đó tiêu chí "container mapping creates no
extra stock" chỉ nằm trong tài liệu, không có ai kiểm.

FAIL bất kỳ check nào ⇒ ném `MigrationReconciliationException` **bên trong** transaction ⇒
toàn bộ master/opening/movement bị rollback, run ghi `FAILED` + `reconciliation_status = FAIL`
+ dòng lỗi `RECON_MISMATCH`. Không tồn tại trạng thái "nạp một phần".

## 4.2 Đối soát lại từ ngoài

`migration:verify-run` dựng lại cùng bộ kết luận **từ dữ liệu đã ghi**, không đọc
`reconciliation_result` đã lưu:

- ledger vs balance tính lại bằng SQL trên `stock_movements` / `inventory_balances`;
- lượng serial-tracked so với số `item_instances` mang `migration_run_id` của run;
- container: đếm movement khác `OPENING` của run, phải bằng 0.

Kết quả diễn tập: `docs/evidence/WWHN-260/04-verify-commit.json` — `verdict = PASS`.

## 4.3 Rollback chứng minh không còn tồn dư

`docs/evidence/WWHN-260/06-rollback.json`:

```
status = ROLLED_BACK    reversed_movements = 2    residual_opening_qty = 0
```

Diễn giải cần Tech Lead xác nhận một lần: `stock_movements` là append-only theo `DEC-REV-001`,
nên "no subset remains" được chứng minh bằng (a) COMMIT nằm trọn trong một transaction và
(b) hiệu ứng tồn của run bị đưa về 0 bằng movement `REVERSAL` đối ứng, master do run tạo bị
đóng và trace bằng `migration_run_id` — thay vì xoá cứng bản ghi.
