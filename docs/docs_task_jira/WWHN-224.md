# WWHN-224 — Import báo cáo: kiểm tra thử không ghi dữ liệu (dry-run) cho import SKU

> Vai trò: **BE2** (Duy Khánh Nguyễn — placeholder `HN-BE-02`)
> Task cha: **WWHN-56 / HN-DATA-001** — [DATA] Import master/SKU/item kiểm tra thử không ghi dữ liệu
> Epic: **HN-EPIC-04**
> Dependency: **HN-MC-001**
> Nhánh triển khai: `m1`
> Base commit trước khi triển khai: `77e543d219f8a9f8b22df07818237ab0ce7152ac`

## 1. Trạng thái trước khi triển khai (audit lúc nhận task)

Trước khi làm, đã rà soát toàn bộ codebase và xác nhận:

- `docs/docs_task_jira/WWHN-224.md` **rỗng**, chưa có traceability nào — không có input/baseline nào được bàn giao trước cho BE2 ngoài mô tả task JIRA.
- Đã tồn tại endpoint `POST /api/v1/skus/import-excel` (WWHN-172/174, BE1 sở hữu — xem `docs/docs_task_jira/WWHN-172.md` mục 8 "Writer ownership") với cơ chế `ALL_OR_NOTHING`: validate toàn file trong 1 transaction, nếu không lỗi thì **luôn ghi SKU thật**. Không có bất kỳ tham số hay chế độ nào để "chỉ kiểm tra, không ghi dữ liệu".
- Response trả JSON report tạm thời (`total_rows`, `valid_rows`, `error_rows`, `row_errors`...) nhưng **không được lưu trữ lâu dài** — không có bảng nào lưu lại kết quả import để tra cứu/đối soát sau này.
- Không có test, OpenAPI param, hay Postman request nào mô tả dry-run.

Kết luận: chức năng "kiểm tra thử không ghi dữ liệu" theo đúng nghĩa JIRA yêu cầu **chưa tồn tại**. Theo boundary matrix WWHN-172 (BE1 sở hữu import contract/aggregate write; BE2 sở hữu shared schema/report/query boundary), phạm vi BE2 được xác định là: (a) thêm cờ dry-run vào endpoint sẵn có mà không đổi hành vi mặc định/contract cũ, và (b) xây dựng **Import báo cáo** (bảng lưu trữ + API tra cứu) làm output chính thức.

## 2. Thiết kế đã triển khai

### 2.1 Dry-run trong `ImportService`

File: [`Modules/Warehouse/app/Services/ImportService.php`](../../Modules/Warehouse/app/Services/ImportService.php)

- Thêm tham số `bool $dryRun = false` vào `import()`. Toàn bộ pipeline validate (format file, header, từng dòng, rule DB — duplicate/INACTIVE/enum/boolean) **chạy giống hệt** import thật, đảm bảo dry-run phản ánh đúng kết quả sẽ xảy ra nếu chạy thật.
- Khi `$dryRun === true` và không có lỗi: **không gọi** `Sku::create()`/`save()`, không ghi audit `SKU_IMPORT_CREATE`/`SKU_IMPORT_UPDATE`. Thay vào đó tính `wouldInsert`/`wouldUpdate` (đếm số dòng sẽ tạo mới / sẽ cập nhật) để trả về trong response, đúng tinh thần "biết trước điều gì sẽ xảy ra mà không cần ghi".
- Ghi 1 dòng audit riêng `SKU_IMPORT_DRY_RUN_CHECK` (thay vì `SKU_IMPORT_COMMIT`) để có dấu vết ai đã chạy thử, khi nào, với file nào — kể cả khi không có side-effect nghiệp vụ nào.
- Vẫn giữ `lockForUpdate()` trên các SKU code đang kiểm tra bên trong transaction — đảm bảo dry-run đọc đúng trạng thái nhất quán dưới tải đồng thời (concurrency guard), dù transaction cuối cùng không ghi gì và không cần rollback thủ công (không có gì để rollback).
- Response mở rộng thêm field: `dry_run`, `would_insert_rows`, `would_update_rows`; giữ nguyên toàn bộ field cũ (`committed`, `inserted_rows`, `updated_rows`, `row_errors`...) để **không phá vỡ hành vi/contract cũ** khi `dry_run` không được gửi (mặc định `false`).

### 2.2 Import báo cáo — bảng lưu trữ và API tra cứu (output chính của WWHN-224)

- Migration mới: [`2026_08_14_000200_create_import_reports_table.php`](../../Modules/Warehouse/database/migrations/2026_08_14_000200_create_import_reports_table.php) tạo bảng `import_reports` (UUID PK `import_report_id`, `import_type`, `mode` [`DRY_RUN`|`COMMIT`], `status` [`VALID`|`REJECTED`|`COMMITTED`], `file_name`, `file_sha256`, `template_version`, số liệu dòng, `row_errors` JSON, `actor_id`, `request_id`, timestamps). Có index theo `file_sha256`, theo `(import_type, mode, created_at)` và theo `actor_id` để phục vụ tra cứu/đối soát.
- Model mới: [`Modules/Warehouse/app/Models/ImportReport.php`](../../Modules/Warehouse/app/Models/ImportReport.php).
- `ImportController::importExcel()` ([`Modules/Warehouse/app/Http/Controllers/ImportController.php`](../../Modules/Warehouse/app/Http/Controllers/ImportController.php)) gọi `persistReport()` sau mỗi lần chạy import (dry-run lẫn thật) để ghi lại kết quả vào `import_reports`, và trả `import_report_id` trong response để client tra cứu lại.
  - **Quyết định thiết kế quan trọng**: nếu import thật đã COMMIT thành công SKU nhưng riêng bước ghi `import_reports` bị lỗi (ví dụ DB tạm thời gián đoạn), request **vẫn trả về thành công** (SKU đã ghi đúng là sự thật nghiệp vụ) — lỗi ghi report chỉ được `Log::error('IMPORT_REPORT_WRITE_FAILED', ...)`, không làm hỏng response. Lý do: nếu để lỗi này làm response thành 500, client có thể hiểu nhầm import thất bại và gửi lại request, gây `DUPLICATE_CODE` giả trên dữ liệu thực ra đã được tạo đúng. Report là metadata truy vết, không phải nguồn sự thật nghiệp vụ.
- Endpoint mới, controller mới [`ImportReportController.php`](../../Modules/Warehouse/app/Http/Controllers/ImportReportController.php):
  - `GET /api/v1/skus/import-reports` — danh sách phân trang, filter theo `mode`, `status`, `file_sha256`.
  - `GET /api/v1/skus/import-reports/{id}` — chi tiết 1 report, 404 chuẩn nếu không có.
  - Route mới trong [`Modules/Warehouse/routes/import.php`](../../Modules/Warehouse/routes/import.php), dùng lại permission `sku.manage` sẵn có (không tạo permission mới, vì đây là companion resource của import-excel, cùng nhóm actor SKU_MANAGER/WAREHOUSE_MANAGER).

### 2.3 Error handling

- Khi dry-run phát hiện lỗi (duplicate/invalid/inactive/enum sai...): HTTP 422, error code mới `SKU_IMPORT_DRY_RUN_REJECTED` (phân biệt với `SKU_IMPORT_VALIDATION_FAILED` của import thật để client biết rõ đây là kết quả kiểm tra thử), kèm `row_errors[]` cấu trúc `{row, code, field, message, value}` — không lộ SQL, path hay stack trace (kế thừa `ApiResponse::respondError()` sẵn có).
- Sửa 1 lỗi logic phát hiện trong lúc viết test: response cũ dùng `! $result['committed']` để quyết định 422 — với dry-run, `committed` **luôn là `false`** kể cả khi file hợp lệ 100% (đúng vì dry-run không bao giờ commit), nên logic cũ sẽ trả 422 sai cho dry-run hợp lệ. Đã sửa điều kiện thành dựa trên `row_errors !== []` (có lỗi thật sự hay không), độc lập với `committed`.

### 2.4 Idempotency & Concurrency (theo yêu cầu AC #2)

- **Không** thêm `IdempotencyKeyGuard`/`IdempotencyStore` (đã có sẵn trong `App\Support`, dùng ở `CurrentBaselineImportController`) vào endpoint `import-excel` — quyết định có chủ đích, vì đây sẽ là thay đổi hành vi/contract thuộc quyền BE1 và phá vỡ test WWHN-172/174 hiện có (không gửi header `Idempotency-Key`). Dry-run tự nhiên an toàn khi gọi lặp lại vì không ghi gì — đã có test xác nhận (mục 3, case 3).
- Concurrency: giữ nguyên `lockForUpdate()` sẵn có trong `ImportService`, áp dụng cho cả nhánh dry-run — đủ để đảm bảo đọc nhất quán dưới tải đồng thời. Không thêm job-level lock (ngoài phạm vi, task không yêu cầu import bất đồng bộ).

## 3. Test evidence

File mới: [`tests/Feature/Wwhn224SkuImportDryRunTest.php`](../../tests/Feature/Wwhn224SkuImportDryRunTest.php) — 8 test case:

1. `test_dry_run_with_all_new_valid_rows_writes_no_sku_but_persists_valid_report` — dry-run file hợp lệ 100%: `committed=false`, `dry_run=true`, **0 dòng SKU** được ghi, `import_reports.status=VALID`, đúng 1 dòng audit `SKU_IMPORT_DRY_RUN_CHECK`, **0** dòng `SKU_IMPORT_CREATE`.
2. `test_dry_run_with_invalid_rows_reports_errors_without_writing_sku` — dry-run file có lỗi (INACTIVE conflict, duplicate trong file): trả đúng `row_errors`, `import_reports.status=REJECTED`, không ghi SKU nào.
3. `test_running_dry_run_twice_in_a_row_never_writes_sku_and_creates_two_reports` — chạy dry-run 2 lần liên tiếp cùng file: cả 2 lần đều không ghi SKU, mỗi lần tạo 1 report riêng (không side-effect tích lũy).
4. `test_real_import_after_a_passing_dry_run_commits_successfully` — dry-run pass rồi import thật cùng SKU code: import thật vẫn commit đúng, chứng minh dry-run không để lại trạng thái cản trở.
5. `test_real_import_without_dry_run_flag_still_commits_and_records_report_as_before` — regression: import thật (không gửi `dry_run`) vẫn hoạt động y hệt trước, đồng thời có ghi `import_reports.mode=COMMIT`.
6. `test_import_report_list_and_detail_endpoints_return_persisted_report` — `GET .../import-reports/{id}` và `GET .../import-reports?mode=DRY_RUN` trả đúng dữ liệu đã lưu.
7. `test_import_report_detail_returns_404_for_unknown_id` — 404 chuẩn cho id không tồn tại.
8. `test_user_without_sku_manage_permission_is_blocked_from_dry_run_and_reports` — user thiếu quyền `sku.manage` bị chặn 403 ở cả 2 endpoint (dry-run và import-reports).

**Kết quả chạy**: `php artisan test --filter=Wwhn224SkuImportDryRunTest` → **8/8 pass, 56 assertions**.

**Regression**: `php artisan test --filter=Wwhn174SkuImportValidationTest` → **7/7 pass, 54 assertions** (không phá vỡ hành vi import thật WWHN-172/174).

**Full suite toàn repo**: `php artisan test` → **291/291 pass** (16 skipped theo baseline sẵn có, không liên quan WWHN-224), **4093 assertions**, không có regression phát sinh từ thay đổi này.

**Migration evidence**: `php artisan migrate` → apply sạch; `php artisan migrate:rollback --step=1` → rollback sạch; `php artisan migrate` (reapply) → sạch. Xác nhận `up()`/`down()` của migration mới đối xứng và an toàn.

## 4. Contract đã cập nhật (OpenAPI/Postman)

- **OpenAPI**: cập nhật `#[OA\Post(...)]` của `importExcel()` (thêm property `dry_run`, mô tả rõ hành vi WWHN-224) và thêm `#[OA\Get(...)]` cho 2 endpoint mới trong `ImportReportController.php`.
  - Phát hiện phụ trong lúc generate: `config/l5-swagger.php` (khóa `documentations.default.api.paths.annotations`) trước đây **không liệt kê** `Modules/Warehouse/app/Http/Controllers/ImportController.php` — nghĩa là mọi attribute OpenAPI viết tay trên file này (kể cả từ WWHN-172 trước đó) **chưa bao giờ được đọc bởi generator**; spec sinh ra chỉ là fallback generic từ route list (`RouteSyncProcessor`). Đã bổ sung `ImportController.php` và `ImportReportController.php` vào danh sách scan để attribute thực sự có hiệu lực. Đây là sửa lỗi cấu hình tồn tại từ trước, không phải thay đổi hành vi API.
  - Đã chạy `php artisan l5-swagger:generate`, diff `storage/api-docs/api-docs.yaml`/`.json` chỉ thêm nội dung mới (2 path mới + property `dry_run`) và làm rõ nội dung generic cũ của `import-excel`/`import-template` thành nội dung handwritten chính xác hơn — không có endpoint nào khác bị mất field.
- **Postman**: mở rộng `docs/postman/WWHN-172 SKU Container Contract.postman_collection.json` với folder mới **"SKU import (dry-run) — WWHN-224"** gồm 5 request: dry-run hợp lệ, dry-run lỗi, import thật (regression), GET report detail, GET report list — có test script `pm.test(...)` kiểm tra status code và field response cho từng case.

## 5. Baseline contract đồng bộ

Việc thêm 2 route mới làm thay đổi tổng số operation API của hệ thống (177 → 179). Đã cập nhật đồng bộ 2 nơi giữ baseline này để tránh drift:

- `docs/contracts/HoaNam_Current_Baseline_Contract.json` → `api.operation_count`: 177 → 179.
- `tests/Feature/InboundCanonicalListContractTest.php` → hard-coded assertion đồng bộ theo cùng giá trị 179.

`tests/Feature/DocumentationBaselineContractTest.php` (kiểm tra route parity + baseline error response cho toàn bộ swagger) đã pass lại sau khi đồng bộ.

## 6. Đối chiếu Acceptance Criteria (WWHN-224)

| # | Acceptance Criteria | Trạng thái | Ghi chú |
|---|---|---|---|
| 1 | Output "Import báo cáo" tồn tại, đầy đủ, có evidence | **Done** | Bảng `import_reports` + API `GET .../import-reports[/{id}]`; mỗi lần dry-run/thật đều persist; evidence = test case 1,2,6,7 |
| 2 | Permission/validation/transaction/audit/idempotency/concurrency áp dụng khi thuộc phạm vi | **Done** | Permission `sku.manage` (test case 8); validation tái dùng nguyên vẹn rule nghiệp vụ có sẵn; transaction giữ nguyên; audit thêm `SKU_IMPORT_DRY_RUN_CHECK`; idempotency chủ động không thêm (dry-run tự an toàn khi lặp lại — case 3); concurrency giữ `lockForUpdate()` |
| 3 | Lỗi trả error code rõ ràng; dry-run không tạo dữ liệu sai; không lộ secret/stack trace | **Done** | `SKU_IMPORT_DRY_RUN_REJECTED`/`SKU_IMPORT_DRY_RUN_CHECK`; 0 dòng SKU ghi khi dry-run (case 1,2,3); error envelope chuẩn kế thừa `ApiResponse` |
| 4 | Unit/integration test + API/DB evidence; OpenAPI/Postman cập nhật | **Done** | 8 test case mới, full suite xanh; OpenAPI + Postman đã cập nhật (mục 4) |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần BA/QA xác nhận** | Contract (OpenAPI/Postman/error code) đã sẵn sàng bàn giao; không có blocker kỹ thuật nào còn mở phía BE2. Việc formal sign-off là của BA/QA, không tự nhận thay (theo văn hoá `WWHN-172.md` mục 5/10) |

## 7. Định nghĩa Done — thực tế

### BE2 technical completion (mục này)

Đạt: code, migration, model, controller, route, test, OpenAPI, Postman, baseline contract đều hoàn tất và nhất quán trên nhánh `m1`; toàn bộ test suite (291/291) xanh; không có regression với WWHN-172/174.

### Còn lại — không thuộc phạm vi tự nhận của BE2

- QA xác nhận evidence (test log, API/DB evidence ở mục 3) đáp ứng đúng Acceptance Criteria.
- BA xác nhận "Import báo cáo" (cấu trúc field trong `import_reports`, response API) khớp kỳ vọng nghiệp vụ của HN-DATA-001/HN-MC-001 — file WWHN-56 gốc không có sẵn trong repo tại thời điểm triển khai, nên cấu trúc report được suy ra từ chính response JSON đã có sẵn ở WWHN-172/174 cộng thêm các field bắt buộc cho dry-run; nếu BA có baseline khác, cần đối chiếu lại.
- FE1 xác nhận `import_report_id` trong response và 2 endpoint tra cứu đủ để tích hợp UI.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE2 tự ghi trong file này.
