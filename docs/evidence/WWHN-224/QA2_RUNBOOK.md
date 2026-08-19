# WWHN-226 / WWHN-224 — Runbook QA2 chạy độc lập

> DEV224-P1-02R. **QA2 tự ghi PASS/FAIL của mình trên WWHN-226. DEV không ký thay và
> không được coi test tự động của DEV là kết quả QA2.**
> Test tự động chỉ bảo đảm hồi quy có thể lặp lại; nó không thay việc QA2 quan sát dữ liệu
> thật bằng API/DB.

## 0. Ghi lại trước khi chạy (bắt buộc, đi kèm mọi bằng chứng)

```bash
git rev-parse HEAD                       # SHA chính xác đang test
git log -1 --format='%H %ci %s'
php -v | head -1
php artisan --version
php artisan tinker --execute="echo DB::connection()->getDriverName().' '.DB::selectOne('select version() as v')->v ?? '';"
```

Ghi thêm: base URL môi trường, tài khoản/vai trò dùng để test, giờ bắt đầu/kết thúc.

**SHA tối thiểu**: `937a5c0` (BE) hoặc bất kỳ hậu duệ nào của nó trên `m1`. Chạy trên SHA
trước đó là đang test bản chưa có phần sửa P1.

## 1. Cổng tự động (chạy trước, nếu đỏ thì dừng và báo defect)

```bash
php vendor/bin/phpunit --no-coverage \
  tests/Feature/Wwhn224P1ClosureTest.php \
  tests/Feature/Wwhn224SkuImportDryRunTest.php \
  tests/Feature/Wwhn174SkuImportValidationTest.php \
  tests/Feature/Wwhn330ImportHardeningTest.php \
  tests/Feature/Wwhn330ImportR3ClosureTest.php \
  tests/Feature/Wwhn331ImportContractClosureTest.php
```

```bash
node scripts/verify-wwhn-224-closure.mjs
```

Cổng thứ hai kiểm phần **không** kiểm được bằng phpunit: hợp đồng công bố khớp quyền route,
register mapping khớp code, và các tệp hồ sơ tồn tại đúng chỗ.

## 2. Ma trận đối soát — QA2 thực hiện thủ công qua API + DB

Mỗi ca ghi đủ: `case id | HTTP | error.code | data.import_report_id | số dòng DB trước/sau |
số dòng audit trước/sau | tester | thời điểm | PASS/FAIL | defect link`.

Truy vấn đối chiếu dùng chung (đổi tên bảng theo driver: xem §4):

```sql
SELECT count(*) FROM sku            WHERE sku_code = :code;
SELECT count(*) FROM import_reports WHERE file_sha256 = :sha;
SELECT action, count(*) FROM audit_log
 WHERE action LIKE 'SKU_IMPORT%' GROUP BY action;
```

| # | Ca | Gửi gì | HTTP + mã lỗi mong đợi | Đối soát DB/audit |
|---|---|---|---|---|
| 1 | Hợp lệ, dry-run | file sạch, `dry_run=true` | 200, `dry_run=true`, `committed=false`, `import_report_id` **khác null** | 0 dòng SKU mới; 1 `import_reports` `DRY_RUN/VALID`; 1 audit `SKU_IMPORT_DRY_RUN_CHECK`; **0** `SKU_IMPORT_CREATE` |
| 2 | Hợp lệ, commit | cùng file, `dry_run=false` | 200, `committed=true`, `import_report_id` khác null | số SKU tạo/cập nhật khớp `inserted_rows`/`updated_rows`; 1 `import_reports` `COMMIT/COMMITTED`; có `SKU_IMPORT_COMMIT` |
| 3 | Trùng file — replay (idempotent) | gửi lại **đúng file, đúng actor, đúng `update_existing`** trong cửa sổ idempotent | 200, `duplicate=true`, `import_report_id` = id của lần **gốc** | **không** thêm `import_reports`; **không** thêm SKU; bản ghi gốc có `duplicate_of_replay=true` |
| 4 | Trùng file nhưng ĐỔI `update_existing` | cùng file, `update_existing` ngược lại | 200 và **chạy thật** (không phải replay) | có bản ghi `import_reports` mới với `options_fingerprint` khác |
| 5 | Trùng mã trong file | 2 dòng cùng `sku_code` | 422 `SKU_IMPORT_VALIDATION_FAILED` (hoặc `SKU_IMPORT_DRY_RUN_REJECTED` nếu dry-run), row error `DUPLICATE_SKU_CODE_IN_FILE` | 0 SKU; 1 `import_reports` `REJECTED`; `import_report_id` khác null |
| 6 | Trùng mã trong DB, `update_existing=false` | SKU đã tồn tại ACTIVE | 422, row error `DUPLICATE_CODE` | SKU cũ **không đổi** (so `updated_at`) |
| 7 | SKU đang INACTIVE | file đặt `status=ACTIVE` cho SKU INACTIVE | 422, row error `INACTIVE_SKU_CONFLICT` | SKU vẫn INACTIVE — import không được tự kích hoạt |
| 8 | Header sai / workbook hỏng | file thiếu cột bắt buộc hoặc không phải xlsx hợp lệ | 422 `SKU_IMPORT_FILE_INVALID` | 0 SKU, 0 audit nghiệp vụ (ca này **không** sinh báo cáo — có chủ ý, xem §3) |
| 9 | Enum sai | `sku_type=ABC` / `status=XYZ` / `package_variant` lạ | 422, row error `INVALID_ENUM` kèm `row/field/message/value` | 0 SKU |
| 10 | Boolean sai | `is_serial_tracked=maybe` | 422, row error `INVALID_BOOLEAN` | 0 SKU |
| 11 | Tham chiếu master không tồn tại/không ACTIVE | `brand_code`/`category_code`/`model_code` lạ | 422, row error `UNKNOWN_MASTER_CODE` | 0 SKU; **không** tự tạo Product Master mới |
| 12 | Đối soát checksum | bất kỳ ca nào ở trên | `data.file_sha256` == `sha256sum` của chính file đã upload | `import_reports.file_sha256` trùng cùng giá trị |
| 13 | Thiếu quyền | token không có `sku.manage` | 403 `ACCESS_DENIED` trên **cả 5** endpoint nhóm import | 0 thay đổi, 0 báo cáo |

### Ca 14–15 — mất Import báo cáo (503), cần bơm lỗi

Không kích hoạt được từ ngoài bằng HTTP thuần. Hai cách QA2 làm được:

- **Cách A (khuyến nghị, môi trường test riêng):** tạm đổi tên bảng `import_reports` rồi
  gửi request, sau đó đổi lại.
  ```sql
  ALTER TABLE import_reports RENAME TO import_reports_qa2_tmp;   -- bơm lỗi
  -- gửi 1 request commit và 1 request dry-run
  ALTER TABLE import_reports_qa2_tmp RENAME TO import_reports;   -- khôi phục
  ```
- **Cách B:** chạy lại 5 test bơm lỗi trong `tests/Feature/Wwhn224P1ClosureTest.php` và
  đính log thô.

| # | Ca | HTTP + mã | Đối soát |
|---|---|---|---|
| 14 | Mất báo cáo, chế độ commit | **503** `SKU_IMPORT_REPORT_PERSISTENCE_FAILED`, `committed=false`, `partial_write=false`, `import_report_id=null`, `retryable=true`, có header `Retry-After` | **0** SKU mới, **0** audit `SKU_IMPORT_CREATE`/`SKU_IMPORT_UPDATE`/`SKU_IMPORT_COMMIT`, **0** `import_reports` |
| 15 | Mất báo cáo, chế độ dry-run | 503 cùng mã, `dry_run=true` | **0** audit `SKU_IMPORT_DRY_RUN_CHECK` (dấu vết kiểm tra thử cũng phải rollback) |

Ca 14 là ca quan trọng nhất của lần review này: nó phân biệt "có rollback thật" với "chỉ
đổi mã lỗi trong response".

## 3. Điểm dễ hiểu nhầm khi chấm

- **Ca 8 không sinh Import báo cáo là đúng thiết kế.** File hỏng/không đọc được bị từ chối
  ở tầng cổng vào, trước khi có "một lần chạy" để báo cáo. Chỉ những lần workbook đã được
  xử lý (kể cả bị từ chối theo dòng) mới có báo cáo. Nếu BA muốn báo cáo cho cả ca này thì
  đó là **thay đổi phạm vi**, ghi vào WWHN-225 chứ không phải defect của WWHN-224.
- **`import_report_id` trong ca replay là id của lần GỐC**, không phải id mới — đúng ý
  nghĩa idempotent, không phải lỗi.
- **503 không có trong SRS §8.2.** Đây là yêu cầu bổ sung do register 18/08 chỉ định, đã
  ghi ở DEC-IMP-001 §10. QA2 chấm theo register; nếu BA quyết định khác, sửa cả hai nơi.

## 4. Bảng theo driver

`sku` và `audit_log` đổi vai trò bảng/view giữa hai driver:

- **sqlite** (mặc định của `phpunit.xml`): `sku`, `audit_log` là bảng vật lý.
- **PostgreSQL** (DB tham chiếu theo HN-WMS-ERD-DD-001): `skus`, `audit_logs` là bảng vật
  lý, tên số ít là view tương thích. Đọc qua view vẫn ra đúng số liệu.

Khi báo cáo kết quả, **ghi rõ driver** — hai nhánh schema không so sánh chéo được.

## 5. Đầu ra QA2 cần nộp lên WWHN-226

1. Bảng 15 ca với cột PASS/FAIL và bằng chứng thô (request/response đã che token, truy vấn
   DB trước/sau).
2. SHA + môi trường + driver + phiên bản PHP/DB.
3. Log thô của cổng tự động (§1).
4. Danh sách defect (nếu có) kèm mức độ, hoặc câu khẳng định "không có defect" do chính
   QA2 ký.
