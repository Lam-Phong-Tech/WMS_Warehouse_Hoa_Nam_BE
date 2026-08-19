# WWHN-224 — DEV224-P1-03R: disposition của PR #5 / PR #6

> Yêu cầu register: *"Không tiếp tục coi PR closed/unmerged là fix; merge/cherry-pick
> approved fix vào canonical branch, independent review và CI trên merged SHA."*

## 1. Sự thật kiểm chứng được về hai PR

| | PR #5 | PR #6 |
|---|---|---|
| Tiêu đề | `fix(WWHN-224): close SKU import P1 contract and evidence gaps` | `fix(WWHN-224): close DEV224-P1-04R/P1-05R contract gaps` |
| Head SHA | `7a66ef3db69b0b785566ab5bdab50d7d070f526d` | `b9e6cae9b67e2499bfab7ae5c85f7e12449c8190` |
| Base nhánh | `m1` | `m1` |
| Trạng thái | **CLOSED, `mergedAt: null`** | **CLOSED, `mergedAt: null`** |
| Review | không có | **`reviews: []` — chưa từng có ai review** |
| Nhánh head | đã xoá khỏi remote (phải lấy qua `refs/pull/N/head`) | đã xoá khỏi remote |
| Merge-base với `m1` | `ea30992` | `92a9a41` |

Lệnh tái lập:

```bash
gh pr view 5 --json number,state,mergedAt,headRefOid,reviews
gh pr view 6 --json number,state,mergedAt,headRefOid,reviews
git fetch origin refs/pull/5/head:refs/wwhn224/pr5 refs/pull/6/head:refs/wwhn224/pr6
```

Kết luận: **không một dòng nào của hai PR này từng vào `m1` qua đường merge.** Coi chúng
là "đã fix" là sai sự thật — register bắt đúng.

## 2. Cách xử lý đã chọn, và vì sao không cherry-pick nguyên văn

Cherry-pick nguyên văn `b9e6cae` lên `m1` hôm nay sẽ **hỏng chức năng**, không chỉ xung
đột văn bản:

- PR #6 bọc toàn bộ `importExcel()` trong một `DB::transaction` NGOÀI, viết trên base
  `92a9a41` — thời điểm đó `ImportService` **chưa có** nhánh `duplicate` (idempotent
  replay, WWHN-330 P1-03) và **chưa có** khoá `withImportLock()` (DEV330-P1-07R3). Áp lên
  `m1` hiện tại, `persistReportOrFail()` sẽ chạy cả cho ca replay → ghi thêm một
  `import_reports` cho một lần import KHÔNG hề chạy, tức phá đúng bất biến idempotent mà
  WWHN-330 vừa đóng.
- PR #5 còn kèm migration `2026_08_15_193800_preserve_legacy_sku_version_code_on_postgres`
  cho lỗi view legacy hard-code `version_code = 'V1'` trên PostgreSQL. Lỗi đó **đã được
  sửa trên `m1`** bằng `2026_08_16_121000` và `2026_08_17_100000` với đúng cơ chế PR #5 đề
  xuất (`COALESCE(s.specifications->>'version_code','V1')` ở view đọc + trigger
  `INSTEAD OF` ghi ngược vào `specifications`). Thêm migration cũ vào lúc này là chạy lại
  một phép sửa đã có, trên schema đã khác.

Nên: **triển khai lại nội dung đã được register phê trên chính `m1`**, giữ nguyên hợp đồng
công khai của PR (`ImportReportPersistenceException`, mã lỗi
`SKU_IMPORT_REPORT_PERSISTENCE_FAILED`, HTTP 503, `partial_write=false`) để người review so
sánh được một-một, và đặt điểm ghi báo cáo vào đúng chỗ nguyên tử được với kiến trúc hiện
tại (trong transaction của `ImportService`, sau nhánh duplicate).

## 3. Ma trận phủ: từng tệp của PR → hiện trạng trên `m1`

| Tệp trong PR #5/#6 | Trạng thái trên `m1` | Nơi kiểm chứng |
|---|---|---|
| `Modules/Warehouse/app/Exceptions/ImportReportPersistenceException.php` | **Có, cùng tên cùng namespace** | commit `937a5c0` |
| `ImportController.php` — 403 `product.manage` → `sku.manage` (2 chỗ) | **Có** | annotation + `storage/api-docs/*` |
| `ImportController.php` — 503 khi mất Import báo cáo | **Có**, mã lỗi giữ nguyên `SKU_IMPORT_REPORT_PERSISTENCE_FAILED`, thêm `Retry-After` | `Wwhn224P1ClosureTest` |
| Ghi Import báo cáo nguyên tử với ghi SKU | **Có**, đặt trong transaction của `ImportService` thay vì transaction ngoài ở controller (lý do ở §2) | `ImportService::withReport()` |
| `tests/Feature/Wwhn224ImportReportFailureContractTest.php` | **Thay bằng `Wwhn224P1ClosureTest.php`** — phủ đủ 2 chế độ của PR, **thêm** nhánh 422, nhánh hỏng chiều ĐỌC, guard quyền tài liệu, đối chiếu register mapping | 8/8 pass |
| `storage/api-docs/api-docs.json|yaml` | **Có** (đồng bộ theo nhóm path import, xem `ARTIFACT_PIN.md`) | `scripts/sync-wwhn224-import-openapi.php --check` |
| `docs/engineering-evidence/WWHN-224/DEV224_P1_CLOSURE.md` | **Chuyển vào đường dẫn canonical** `docs/evidence/WWHN-224/DEV224_P1_CLOSURE_REGISTER.md` (`docs/engineering-evidence/` không tồn tại trên `m1`) | tệp hiện tại |
| `docs/engineering-evidence/WWHN-224/QA2_RECONCILIATION_RUNBOOK.md` | **Chuyển + mở rộng** → `docs/evidence/WWHN-224/QA2_RUNBOOK.md` | tệp hiện tại |
| `docs/postman/WWHN-224 SKU Import Closure.postman_collection.json` | **Gộp vào collection sẵn có** `WWHN-172 SKU Container Contract` (nơi WWHN-224/WWHN-330 vốn đã nằm) thay vì tạo collection thứ hai cho cùng một nhóm endpoint | folder *"SKU import — hợp đồng P1 đóng 18/08"* |
| `docs/api-contract/WWHN-224-sku-import-openapi.yaml` | **Cố ý không tạo.** Một artifact OpenAPI thứ hai cho cùng nhóm endpoint là thêm một nguồn sự thật nữa để lệch; thay vào đó pin hash của artifact canonical | `ARTIFACT_PIN.md` |
| `2026_08_15_193800_preserve_legacy_sku_version_code_on_postgres.php` | **Đã được thay thế bởi bản sửa có sẵn trên `m1`** (`2026_08_16_121000`, `2026_08_17_100000`) — cùng cơ chế | grep `COALESCE(s.specifications->>'version_code'` |
| `.github/workflows/wwhn-224-sku-import-closure.yml` | **Có, dựng lại theo chuẩn workflow của `m1`** | `.github/workflows/wwhn-224-p1-closure.yml` |

## 4. Trạng thái cần đạt để đóng hoàn toàn mục này

Phần DEV đã xong: nội dung nằm trên nhánh canonical `m1`, chạy được, có test.

Còn lại **không thuộc DEV** và không được tự nhận:

1. **Tech Lead review** commit `937a5c0` trên `m1` (independent review — register yêu cầu
   "approved review", mà cả hai PR cũ đều `reviews: []`).
2. **Đóng chính thức PR #5/#6 kèm comment supersede** trỏ tới `937a5c0` để lịch sử Jira/GitHub
   không còn hai PR treo mang tiếng "fix WWHN-224".
3. **CI xanh trên đúng SHA** — workflow `wwhn-224-p1-closure.yml` chạy tại `937a5c0` hoặc
   SHA hậu duệ, artifact đính vào WWHN-226.
