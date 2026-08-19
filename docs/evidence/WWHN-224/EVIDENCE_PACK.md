# WWHN-224 — Gói bằng chứng của lần đóng register 18/08

Ngày chạy: **19/08/2026**. Nhánh: `m1`. Máy: Windows 11, PHP 8.3 (laragon), Node 20.

| Vai trò | Repo | SHA |
|---|---|---|
| Code closure BE | `WMS_Warehouse_Hoa_Nam_BE` | `937a5c0` |
| Hồ sơ + cổng BE | `WMS_Warehouse_Hoa_Nam_BE` | `44d482f` |
| Code closure FE | `WMS_Warehouse_Hoa_Nam_FE` | `90e764e` |

---

## 1. Bơm lỗi Import báo cáo — bằng chứng chính của DEV224-P1-05R

```
$ php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn224P1ClosureTest.php
{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":69,"duration_ms":7238}
```

8 test, trong đó 4 test bơm lỗi thật:

| Test | Kết quả quan sát được |
|---|---|
| `…report_write_failure_after_sku_write_returns_503_and_rolls_back_everything` | HTTP 503, `error.code=SKU_IMPORT_REPORT_PERSISTENCE_FAILED`, `committed=false`, `partial_write=false`, `import_report_id=null`, `retryable=true`, có `Retry-After`. Sau request: **0** SKU `ATOMIC-224-001`, **0** audit `SKU_IMPORT_CREATE`, **0** audit `SKU_IMPORT_COMMIT`, **0** dòng `import_reports` |
| `…report_write_failure_in_dry_run_returns_503_and_rolls_back_the_dry_run_audit` | HTTP 503, `dry_run=true`; **0** audit `SKU_IMPORT_DRY_RUN_CHECK` |
| `…report_write_failure_on_row_error_path_returns_503_not_a_silent_422` | HTTP 503 (không phải 422 kèm phiếu tra cứu rỗng); **0** `import_reports` |
| `…report_lookup_failure_refuses_the_import_instead_of_losing_the_idempotency_guard` | `Schema::drop('import_reports')` → HTTP 503, `partial_write=false`, **0** SKU |

Điểm đáng chú ý về **chất lượng bằng chứng**: mock writer trong test đầu tiên tự khẳng
định vị trí của nó trong dòng thời gian — nó assert `Sku::query()->where('sku_code',
'ATOMIC-224-001')->count() === 1` **ngay tại lúc bị gọi**, rồi mới ném lỗi. Nghĩa là lần
ghi báo cáo thực sự nằm SAU khi SKU đã được ghi và VẪN trong transaction. Nếu ai đó kéo
việc ghi báo cáo ra ngoài transaction, assert này đỏ trước cả khi assert rollback chạy.

## 2. Regression toàn nhóm import SKU (sqlite)

```
$ php vendor/bin/phpunit --no-coverage \
    tests/Feature/Wwhn224P1ClosureTest.php \
    tests/Feature/Wwhn224SkuImportDryRunTest.php \
    tests/Feature/Wwhn174SkuImportValidationTest.php \
    tests/Feature/Wwhn330ImportHardeningTest.php \
    tests/Feature/Wwhn330ImportR3ClosureTest.php \
    tests/Feature/Wwhn330ImportResidualHardeningTest.php \
    tests/Feature/Wwhn331ImportContractClosureTest.php
{"tool":"phpunit","result":"passed","tests":60,"passed":60,"assertions":370,"duration_ms":35803}
```

Không hạng mục nào của WWHN-172/174/224/330/331 bị phá bởi thay đổi này, kể cả các bất
biến idempotent/khoá mà WWHN-330 vừa đóng.

## 3. Cổng hồ sơ + hợp đồng, có đối chứng âm

```
$ node scripts/verify-wwhn-224-closure.mjs
· SHA đang kiểm: c2bb9f1ae2cd1f9c17d95acf53873e7a9386e122
✓ WWHN-224 closure gate PASS — P1-01R/04R/05R và hồ sơ P1-02R/03R/06 đều đạt.
```

**Đối chứng âm** (bắt buộc, để chứng minh cổng không phải "xanh vô điều kiện"). Sửa tạm
register: `status → APPROVED`, tự điền tên vào ô ký của BA, bớt 1 cột template:

```
✗ WWHN-224 closure gate FAIL (3):
  - DEV224-P1-01R: trạng thái register phải là bản trình chờ BA/Data Owner, DEV không tự chuyển sang APPROVED.
  - DEV224-P1-01R: ô ký của BA/Data Owner phải để trống cho tới khi chính họ ký.
  - DEV224-P1-01R: cột template trong code (sku_code,…,status) đã trôi khỏi register.
```

Khôi phục register → PASS trở lại.

## 4. Hợp đồng OpenAPI đồng bộ với annotation

```
$ php artisan l5-swagger:generate            # sinh vào thư mục tạm
$ php scripts/sync-wwhn224-import-openapi.php --fresh-dir=<tạm> --check
path: /api/v1/skus/import-excel
path: /api/v1/skus/import-reports
path: /api/v1/skus/import-reports/{id}
path: /api/v1/skus/import-reports/{id}/error-file
path: /api/v1/skus/import-template
OK: artifact OpenAPI khớp annotation cho toàn bộ nhóm import SKU.
```

Delta đã đưa vào artifact (26 dòng thêm / 10 dòng bớt, đúng 2 tệp `storage/api-docs/*`):
`product.manage` → `sku.manage` ở 2 chỗ, thêm response 503, làm rõ 200/422 luôn kèm
`import_report_id`.

## 5. FE

```
$ npx vitest run src/modules/master-data/services/sku-import.service.test.ts \
                 src/modules/master-data/components/SkuImportModal.test.tsx
Test Files  2 passed (2)
     Tests  20 passed (20)
$ npx tsc --noEmit    → ✅ Typecheck pass
$ npx prettier --check … → All matched files use Prettier code style!
```

Ca mới: *"giữ nguyên kết quả kiểm tra thử và cho thử lại khi BE trả 503 mất Import báo
cáo"* — chặn đúng lỗi mà thay đổi BE này có thể gây ra ở FE nếu không xử lý: 503 mang khối
`data` nên nhánh 422 sẽ nuốt nó và hiển thị "Import bị từ chối" với bảng lỗi trống cho một
file hoàn toàn hợp lệ.

## 6. Những gì **chưa** chạy được ở máy local — nói thẳng

**PostgreSQL (DB tham chiếu theo HN-WMS-ERD-DD-001): chưa có bằng chứng local.**
Đã tạo DB cách ly `hoanam_wwhn224` trong container `wms_warehouse_hoa_nam_be-postgres-1`
và thử chạy **hai lần**; cả hai lần bị chặn bởi **một phiên làm việc song song trên cùng
working tree đang cài lại `vendor/`**:

- lần 1: `require(vendor/darkaonline/l5-swagger/src/../config/l5-swagger.php): Failed to
  open stream` — 57/60 test error;
- lần 2: `Class "Modules\AuditLog\Providers\AuditLogServiceProvider" not found` — autoload
  map đang được sinh lại.

Cả hai là sự cố môi trường, **không phải kết quả test**: cùng bộ test đó chạy sạch 60/60
trên sqlite trước và sau các lần đứt quãng đó. Không ghi nhận chúng thành "PASS trên
PostgreSQL" và cũng không ghi thành defect.

Bằng chứng PostgreSQL đúng chỗ của nó là **workflow `.github/workflows/wwhn-224-p1-closure.yml`**
(PostgreSQL 16, exact SHA, upload artifact) — cũng chính là thứ register đòi cho
DEV224-P1-02R/P1-03R: log CI truy được theo SHA, không phải log máy dev.

## 7. Ghi chú truy vết trung thực

Nội dung `storage/api-docs/api-docs.json|yaml` của lần đóng này **không nằm trong
`937a5c0`**: một phiên song song đã `git add` toàn cây và mang hai tệp đó vào commit
`bd5b5d6` (tiêu đề task khác) trước khi commit của tôi chạy. Nội dung đúng và kiểm lại
được bằng `--check` ở §4; chỉ là tác giả commit không phản ánh đúng task. Ghi ra đây để
người review không kết luận nhầm là artifact chưa được đồng bộ.
