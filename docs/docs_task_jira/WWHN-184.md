# WWHN-184 — [BE2] Xây dựng và chạy kiểm tra thử không ghi dữ liệu

> Vai trò: **BE2** (Duy Khánh Nguyễn — placeholder `HN-BE-02`)
> Task cha: **WWHN-56 / HN-DATA-001** — [DATA] Import master/SKU/item kiểm tra thử không ghi dữ liệu
> Dependency: **HN-MC-001**
> Nhánh triển khai: `m1`
> Exact commit SHA (implementation + verify): `a3fd779c61af96f0b243da47eef848a9e0125058`

## 1. Quan hệ với WWHN-224

Nội dung task JIRA của WWHN-184 (mô tả, task cha `WWHN-56`, dependency `HN-MC-001`, vai trò BE2) khớp
100% với task đã được triển khai và commit trước đó dưới mã **WWHN-224** — commit
`a3fd779 [BE2] Xây dựng và chạy kiểm tra thử không ghi dữ liệu`, đang là `HEAD` của nhánh `m1` tại
thời điểm rà soát (2026-08-14). Đây là cùng một hạng mục công việc, không phải hai task độc lập.

Tài liệu kỹ thuật đầy đủ (thiết kế, contract, đối chiếu Acceptance Criteria) đã được ghi tại
[`docs/docs_task_jira/WWHN-224.md`](WWHN-224.md) và **không lặp lại toàn văn ở đây** để tránh hai
nguồn sự thật lệch nhau theo thời gian. File này đóng vai trò traceability cho đúng mã ticket
`WWHN-184`, cùng với evidence re-verify độc lập chạy lại tại thời điểm rà soát task này.

## 2. Rà soát trạng thái thực tế (audit lúc nhận task WWHN-184)

Trước khi kết luận, đã kiểm tra trực tiếp trên working tree tại `HEAD = a3fd779c61af96f0b243da47eef848a9e0125058`:

- `git status --short` → sạch, chỉ có `docs/docs_task_jira/WWHN-184.md` (file này, trước khi ghi) là untracked.
- Code dry-run tồn tại đầy đủ và đã rà soát lại từng phần:
  - [`Modules/Warehouse/app/Services/ImportService.php`](../../Modules/Warehouse/app/Services/ImportService.php) — tham số `bool $dryRun`, pipeline validate chạy đầy đủ, khi `dryRun=true` và không có lỗi thì không gọi `Sku::create()/save()`, chỉ tính `wouldInsert`/`wouldUpdate`, ghi audit riêng `SKU_IMPORT_DRY_RUN_CHECK` với `reason: 'WWHN-224 dry-run check, no business row written'`.
  - [`Modules/Warehouse/app/Http/Controllers/ImportController.php`](../../Modules/Warehouse/app/Http/Controllers/ImportController.php) — `importExcel()` nhận `dry_run` (boolean, mặc định `false`), map lỗi dựa trên `row_errors !== []` (không phụ thuộc `committed`, đúng như fix đã ghi ở WWHN-224 mục 2.3); `persistReport()` không làm hỏng response nếu ghi report lỗi (chỉ `Log::error`), giữ đúng nguyên tắc report là metadata truy vết chứ không phải nguồn sự thật nghiệp vụ.
  - [`Modules/Warehouse/app/Http/Controllers/ImportReportController.php`](../../Modules/Warehouse/app/Http/Controllers/ImportReportController.php) — `GET /api/v1/skus/import-reports` (phân trang, filter `mode`/`status`/`file_sha256`) và `GET /api/v1/skus/import-reports/{id}` (404 chuẩn), permission `sku.manage`.
  - [`Modules/Warehouse/database/migrations/2026_08_14_000200_create_import_reports_table.php`](../../Modules/Warehouse/database/migrations/2026_08_14_000200_create_import_reports_table.php) — bảng `import_reports` đúng cấu trúc đã mô tả, có index theo `file_sha256`, `(import_type, mode, created_at)`, `actor_id`.
  - [`Modules/Warehouse/routes/import.php`](../../Modules/Warehouse/routes/import.php) — 4 route (`import-template`, `import-excel`, `import-reports`, `import-reports/{id}`) đều dùng `auth:api` + `permission:sku.manage`, đúng thứ tự path (route tĩnh `import-reports` không xung đột với `{id}`).
- Không phát hiện thiếu sót, thiếu test, hay lệch giữa docs và code tại thời điểm rà soát này.

## 3. Evidence re-verify (chạy lại độc lập, không tái sử dụng số liệu cũ)

Chạy lại toàn bộ evidence trên đúng `HEAD` hiện tại:

| Lệnh | Kết quả |
|---|---|
| `php artisan test --filter=Wwhn224SkuImportDryRunTest` | **8/8 pass, 56 assertions** — khớp evidence gốc |
| `php artisan test --filter=Wwhn174SkuImportValidationTest` | **7/7 pass, 54 assertions** — regression import thật (WWHN-172/174) không bị phá vỡ |
| `php artisan test` (full suite) | **291/291 pass, 16 skipped (baseline có sẵn, không liên quan), 4093 assertions** — không có regression phát sinh |
| `php artisan migrate:status` | Migration `2026_08_14_000200_create_import_reports_table` ở trạng thái `Ran` — đã apply sạch trên môi trường test |

Không có test nào fail, không có assertion nào giảm so với số liệu đã ghi ở WWHN-224 mục 3 — xác nhận
implementation ổn định, không bị bit-rot bởi các thay đổi sau đó trên `m1`.

## 4. Đối chiếu Acceptance Criteria (theo đúng mô tả JIRA của WWHN-184)

| # | Acceptance Criteria (JIRA WWHN-184) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "Import báo cáo" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE2 | **Done (kỹ thuật, phía BE2)** | Bảng `import_reports` + API list/detail; test case 1, 2, 6, 7 của `Wwhn224SkuImportDryRunTest` |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** | Permission `sku.manage` (test case 8); transaction `DB::transaction(..., 3 retries)` giữ nguyên cho cả 2 nhánh; audit `SKU_IMPORT_DRY_RUN_CHECK`; `lockForUpdate()` giữ cho dry-run; idempotency chủ động không thêm — dry-run tự an toàn khi lặp lại (test case 3), có ghi rõ lý do ở WWHN-224 mục 2.4 |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** | `SKU_IMPORT_DRY_RUN_REJECTED` / `SKU_IMPORT_DRY_RUN_CHECK`; 0 dòng SKU ghi khi dry-run (test case 1, 2, 3); error envelope kế thừa `ApiResponse::respondError()` |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** | 8 test case `Wwhn224SkuImportDryRunTest`; `storage/api-docs/api-docs.{json,yaml}` cập nhật; Postman folder "SKU import (dry-run) — WWHN-224" trong `docs/postman/WWHN-172 SKU Container Contract.postman_collection.json` |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần BA/QA/FE xác nhận độc lập** | Contract/evidence đã sẵn sàng bàn giao phía BE2; không có blocker kỹ thuật nào còn mở. Sign-off là quyền của BA/QA/FE, BE2 không tự nhận thay |

## 5. Phạm vi đã xác nhận và điểm còn mở (không tự ý mở rộng)

- Dry-run hiện chỉ áp dụng cho **import SKU** qua `POST /api/v1/skus/import-excel`. Task cha
  WWHN-56/HN-DATA-001 nêu "import master/SKU/item" — đã rà soát và xác nhận **không tồn tại cơ chế
  import Excel nào cho Master data (Brand/Category/Model) hay Item instance** trong codebase hiện
  tại; các entity này chỉ có CRUD JSON thường qua `CurrentBaselineMasterController`. Vì vậy không có
  "import" nào khác để thêm dry-run — đây không phải thiếu sót của WWHN-184/224, mà là giới hạn thật
  của baseline hiện có. Nếu BA/PM xác nhận task cha yêu cầu import Excel cho master/item (không chỉ
  SKU), đó là một hạng mục mới, cần task riêng, không tự ý mở rộng scope trong task này.
- **HN-MC-001** (dependency) chưa có tài liệu bàn giao đầy đủ trong repo tại thời điểm rà soát — chỉ
  còn 2 dấu vết tham chiếu, không có nội dung gốc:
  - `Modules/Warehouse/database/migrations/2026_01_01_000031_create_products_table.php` (docblock) —
    ghi chú quyết định thiết kế đổi bảng `products` (stub WWHN-37) thành `sku`, với `category_id`/
    `brand_id` là FK tới bảng generic `product_masters(master_id)`.
  - `docs/testing/WWHN-172-test-cases.json` — field `"dependency": "HN-MC-001-ST01"` (task cha
    `WWHN-37`), kèm ghi chú execution_status yêu cầu evidence gắn exact SHA + CI run, không tự chứng
    nhận PASS.
  - Thư mục tài liệu gốc được tham chiếu trong comment code, `docs/output/BE2-HN-MC-001-sku-item-component/00-summary.md`,
    **không tồn tại trong repo** — nội dung gốc HN-MC-001 đã bị mất hoặc chưa từng được commit.
  - Cấu trúc `import_reports` được suy ra từ response JSON đã có sẵn ở WWHN-172/174 cộng các field
    bắt buộc cho dry-run. Nếu BA có baseline khác từ HN-MC-001, cần đối chiếu lại và đây sẽ là điều
    chỉnh có kiểm soát, không phải lỗi. Khuyến nghị: xin lại tài liệu gốc HN-MC-001/HN-MC-001-ST01 từ
    BA/Jira trước khi mở rộng phạm vi dry-run sang category/master.
- Không phát hiện blocker kỹ thuật P0/P1 nào còn mở phía BE2 tại thời điểm rà soát.

## 6. Định nghĩa Done — thực tế

### BE2 technical completion (mục này)

Đạt: code, migration, model, controller, route, test, OpenAPI, Postman, baseline contract đã tồn tại
và nhất quán trên `m1` tại SHA `a3fd779c61af96f0b243da47eef848a9e0125058`; toàn bộ test suite hiện
hành (291/291) xanh khi chạy lại độc lập cho việc rà soát WWHN-184; không có regression với
WWHN-172/174.

### Còn lại — không thuộc phạm vi tự nhận của BE2

- QA xác nhận evidence (mục 3 ở trên và mục 3 của WWHN-224.md) đáp ứng đúng Acceptance Criteria.
- BA xác nhận cấu trúc "Import báo cáo" khớp kỳ vọng nghiệp vụ của HN-DATA-001/HN-MC-001 khi tài liệu
  gốc của các dependency này có sẵn.
- FE1 xác nhận `import_report_id` trong response và 2 endpoint tra cứu đủ để tích hợp UI.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE2 tự ghi trong file này.
