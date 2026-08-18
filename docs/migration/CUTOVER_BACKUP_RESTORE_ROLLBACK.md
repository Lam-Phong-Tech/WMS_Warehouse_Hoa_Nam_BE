# Runbook backup / restore / rollback cho cutover

- Mã tài liệu: `HN-MIG-OPS-001`
- Ticket: `WWHN-260` (DEV260-P1-09), thực thi tại `WWHN-261` / `WWHN-298`
- Baseline: SRS §13.12, SRS §13.13 ("Migration/Cutover ticket"), `REQ-SRS-021`, `DEC-REV-001`
- Bổ sung cho `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md` (vòng đời job) — file này là phần
  **hạ tầng dữ liệu**: sao lưu, khôi phục, và quyết định rollback.

> Không có bước nào ở đây được bỏ qua vì "dry-run đã PASS". Dry-run chứng minh dữ liệu
> nguồn hợp lệ; nó không chứng minh có đường quay lại.

---

## 1. Điều kiện vào (gate trước cutover)

| # | Điều kiện | Bằng chứng phải có | Owner |
|---|---|---|---|
| 1 | Import Package v1.2 production đã duyệt, checksum đã pin | Attachment `WWHN-333` + `source_checksum` trong dry-run | BA / Data Owner |
| 2 | Mapping v1.0 đã ký | Comment duyệt tại `WWHN-260` | BAS / Data Owner |
| 3 | Dry-run trên **đúng** package production `PASSED` | `migration_runs.status = PASSED`, `reconciliation_status = PASS` | BE / DevOps |
| 4 | Backup full đã tạo **và đã restore thử** | Log ở §2 và §3 | DevOps |
| 5 | Cửa sổ cutover đã duyệt, hệ thống ở chế độ read-only/đóng | Thông báo vận hành | PM |
| 6 | Người ký duyệt opening balance khác người chạy job | `--approved-by` ≠ `--executed-by` (separation of duty) | QL kho |

---

## 2. Sao lưu trước khi nạp

```bash
# 1. Đóng ghi nghiệp vụ (maintenance mode) trước khi snapshot.
php artisan down --render="errors::503"

# 2. Backup logical đầy đủ, có schema và dữ liệu.
pg_dump --format=custom --no-owner --file=cutover_pre_load_$(date +%Y%m%d_%H%M).dump "$DATABASE_URL"

# 3. Ghi checksum của chính bản backup — bản dump không kiểm được thì không phải backup.
sha256sum cutover_pre_load_*.dump | tee cutover_pre_load_checksum.txt
```

Phải ghi lại vào evidence của `WWHN-261`: tên file dump, kích thước, sha256, thời điểm,
`SELECT count(*)` của `stock_movements` và `inventory_balances` ngay trước khi dump.

---

## 3. Diễn tập khôi phục (bắt buộc, trước lần nạp cuối)

Backup chưa restore thử là backup chưa tồn tại.

```bash
createdb wms_hoanam_restore_drill
pg_restore --no-owner --dbname=wms_hoanam_restore_drill cutover_pre_load_*.dump

# Đối chiếu số lượng bản ghi then chốt giữa bản gốc và bản khôi phục.
psql -d wms_hoanam_restore_drill -c "SELECT count(*) FROM stock_movements;"
psql -d wms_hoanam_restore_drill -c "SELECT count(*) FROM inventory_balances;"
psql -d wms_hoanam_restore_drill -c "SELECT count(*) FROM migration_runs;"

# Cổng kỹ thuật: migration chain phải chạy được trên bản khôi phục.
DB_DATABASE=wms_hoanam_restore_drill php artisan migrate --force
DB_DATABASE=wms_hoanam_restore_drill php artisan wms:golive-check
```

PASS khi: số bản ghi khớp bản gốc, `migrate` không lỗi, `wms:golive-check` exit 0.

---

## 4. Nạp dữ liệu cuối

Xem `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md`. Ba lệnh, đúng thứ tự, cùng một `--run-code`:

```bash
php artisan migration:final-load --mode=DRY_RUN --package=<pkg> --run-code=<code> --executed-by=<operator> --output=<evidence>/dry-run.json
php artisan migration:final-load --mode=COMMIT  --package=<pkg> --run-code=<code> --executed-by=<operator> --approved-by=<data-owner> --approval-ref=<mã biên bản> --output=<evidence>/commit.json
php artisan migration:verify-run --run-code=<code> --mode=COMMIT --package=<pkg> --error-file=<evidence>/rejected.csv --output=<evidence>/verify.json
```

Lệnh thứ ba là **cổng chặn**: exit ≠ 0 ⇒ coi như cutover chưa thành công, chuyển sang §5.

---

## 5. Quyết định rollback

| Tình huống | Hành động | Lý do |
|---|---|---|
| `COMMIT` trả `FAILED` | Không cần rollback dữ liệu | COMMIT nằm trọn trong một transaction — hoặc toàn bộ, hoặc không có gì |
| `COMMIT` `COMMITTED` nhưng `verify-run` FAIL, **chưa** có nghiệp vụ nào chạy | `migration:final-load --mode=ROLLBACK --run-code=<code>` | Rollback REVERSAL append-only (`DEC-REV-001`); PASS khi `residual_opening_qty = 0` |
| Đã có nghiệp vụ tiêu thụ tồn sau khi nạp | **Restore từ backup**, không rollback bằng job | Job sẽ dừng với `MIGRATION_ROLLBACK_NEGATIVE_STOCK`; đảo tồn đã bị tiêu thụ là sai nghiệp vụ |
| Nghi ngờ package sai nhưng chưa COMMIT | Sửa nguồn → **run code mới** → dry-run lại | File đổi ⇒ checksum đổi ⇒ `CHECKSUM_CHANGED` chặn COMMIT cũ |

Khôi phục từ backup:

```bash
php artisan down
dropdb wms_hoanam && createdb wms_hoanam
pg_restore --no-owner --dbname=wms_hoanam cutover_pre_load_*.dump
php artisan migrate --force
php artisan wms:golive-check
php artisan up
```

---

## 6. Nạp lại sau khi đã rollback

Cùng một package **chỉ được có tối đa một COMMIT còn hiệu lực**: chạy lại dưới run code khác
sẽ bị chặn bằng `PACKAGE_ALREADY_COMMITTED`. Sau khi rollback thành công, khoá đó tự mở và
lần nạp mới chạy được (đã diễn tập: `docs/evidence/WWHN-260/06-rollback.json` →
`07-recommit-after-rollback.json`).

---

## 7. Điều kiện ra

| # | Điều kiện | Bằng chứng |
|---|---|---|
| 1 | `migration_runs` có đúng một run `COMMIT/COMMITTED` cho package production | `verify-run` check `AT_MOST_ONE_LIVE_COMMIT_PER_CHECKSUM` |
| 2 | Đối soát PASS toàn bộ | `verify-run` verdict `PASS` |
| 3 | QL kho ký kết quả tồn đầu | `approved_by` + `approval_ref` trong run COMMIT |
| 4 | Backup trước nạp còn giữ và đã ghi checksum | `cutover_pre_load_checksum.txt` |
| 5 | Evidence đã gắn vào `WWHN-261` / `HN-DEVOPS-005` | Attachment Jira |
