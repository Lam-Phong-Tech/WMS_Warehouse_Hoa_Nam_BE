# WWHN-330 — Evidence Pack (review 47/100 → P1-01/02/03/04/05 root-cause fix)

Ngày: 2026-08-18. Nhánh: `m1` duy nhất (không tạo nhánh riêng). Không tự
push — chờ chủ tài khoản repo tự review/push.

## Nguồn review chấm điểm

Jira `WWHN-330` (parent `WWHN-89`, dependency `HN-DATA-001`), comment
"Chưa đạt yêu cầu - Đánh giá: 47/100 (47%)" của Wuangg, đăng lúc review gần
nhất. Bảng P0/P1/P2 Exact Fix Action Register gồm DEV330-P1-01..05 và
DEV330-P2-01 (không có mục P0 nào trong ticket này).

## Exact commit SHA (đã commit bởi chủ repo, không phải bởi phiên hỗ trợ này)

| Repo | Nhánh | HEAD SHA | Commit message |
|---|---|---|---|
| `WMS_Warehouse_Hoa_Nam_BE` | `m1` | `b8cfcf01eb0ef01661bb58a7c1bb08825e35e8f3` | "gỡ cờ 11" |
| `WMS_Warehouse_Hoa_Nam_FE` | `m1` | `7ebf09692e248c0628dca807820342128aaf117e` | "gỡ cờ 11" |

Cả hai commit này gộp chung phần việc của chủ repo (BE2/FE1 — Quang Nguyễn
Thanh, theo phân công "CẬP NHẬT PHÂN CÔNG FE/BE" trên WWHN-89) **và** phần
việc bổ sung của phiên hỗ trợ này (xem "Phần đóng góp của phiên này" bên
dưới) — cả hai được review/investigate/test và commit trong cùng một cửa sổ
làm việc trên `m1`.

## DEV330-P1-01 — Error file thật cho rejected rows

**Root cause:** trước review, `ImportController::importExcel` chỉ trả
`row_errors` inline trong JSON 422, không có file tải về nào cả.

**Fix (root cause, không phải workaround):**
- `ImportErrorFileService::generate()` (Modules/Warehouse/app/Services/ImportErrorFileService.php)
  sinh CSV thật (cột `row,field,error_code,message,value`, UTF-8 BOM, sanitize
  control-char + chặn CSV/formula injection `=+-@\t\r`, cắt 500 ký tự/ô) ghi
  vào `storage/app/import-error-files/{report_id}.csv`.
- `ImportController::attachErrorFile()` gọi service trên sau khi phát hiện
  row lỗi, ghi `error_file_path`/`error_file_expires_at` (24h, TẠM — cần
  BA/Tech Lead chốt chính thức) vào `import_reports`.
- `GET /api/v1/skus/import-reports/{id}/error-file`
  (`ImportReportController::downloadErrorFile`) đọc ĐÚNG file đã lưu (không
  sinh lại lần 2 — tránh bug duplicate-generator phát hiện lúc rà soát giữa
  chừng), permission `sku.manage`, 404 thống nhất cho "không có file lỗi",
  "hết hạn" và "không tìm thấy" (không phân biệt để tránh lộ existence).
- **Gap độc lập phát hiện thêm và đã vá trong phiên này:** file CSV vật lý
  không có tiến trình dọn dẹp nào — API coi "hết hạn" là 404 nhưng file trên
  đĩa tồn tại vĩnh viễn. Đã thêm:
  - `app/Console/Commands/PurgeExpiredImportErrorFiles.php`
    (`import:purge-error-files`) — xoá file hết hạn + null hoá
    `error_file_path`/`error_file_expires_at`.
  - Lịch chạy hàng ngày 02:15 trong `routes/console.php`.
  - Test `tests/Feature/Wwhn330ImportErrorFilePurgeTest.php` (3 test: xoá file
    hết hạn + null hoá cột, giữ nguyên file chưa hết hạn, `--dry-run` không
    xoá gì).

**Đóng gói FE (WWHN-331, cùng commit m1):** `SkuImportModal.tsx` có bảng
row-error, khu vực tải file lỗi CSV với đủ trạng thái loading/403 (ẩn khi
không đủ quyền)/404-chưa-có-file/hết hạn/retry — không hard-code giả định
lộ dữ liệu ngoài phạm vi report.

## DEV330-P1-02 — Large-file limit & performance

**Root cause:** `IOFactory::load($filePath)->toArray()` nạp TOÀN BỘ workbook
vào memory trước khi kiểm tra số dòng — file rất lớn (vd. 100k dòng) vẫn bị
đọc hết trước khi bị từ chối.

**Fix (root cause):** `ImportService::readRows()` dùng
`IReadFilter` của PhpSpreadsheet, chỉ đọc tối đa `MAX_ROWS + 2` dòng vật lý
(1002 dòng) bất kể file lớn cỡ nào — file 100k dòng chỉ đọc 1002 dòng đầu vào
memory rồi bị từ chối ở bước đếm dòng, không nạp toàn bộ. `MAX_ROWS=1000`
được đặt tên hằng số rõ ràng (trước đây là magic number `1000` rải rác) và
lỗi vượt hạn mức có exception/error code riêng
(`ImportRowLimitExceededException` → `SKU_IMPORT_ROW_LIMIT_EXCEEDED`, 422,
không 500, không lộ stack trace).

**Giới hạn còn lại (đã ghi rõ, không giấu):** đây là bounded-read, không phải
true streaming/chunked-write-side. Với đúng `MAX_ROWS=1000` hiện tại là đủ —
nếu tương lai cần nâng hạn mức lên rất lớn (vd. >50k dòng) thì cách tiếp cận
này cần đổi sang xử lý theo lô ghi DB, đó là quyết định phạm vi cần BA/Tech
Lead, không tự ý mở rộng ở đây.

**Test:** `test_import_over_row_limit_returns_422_with_standard_error_code_not_500`
— 1001 dòng → 422 `SKU_IMPORT_ROW_LIMIT_EXCEEDED`, `max_rows=1000`,
`actual_rows=1001`, không có SKU nào được ghi (assertDatabaseMissing).

## DEV330-P1-03 — Idempotent repeat-import

**Root cause:** SHA-256 được tính và lưu (`import_reports.file_sha256`)
nhưng không có gì kiểm tra trước khi ghi — 2 lần import cùng file vẫn chạy
lại toàn bộ, chỉ khác nhau ở việc `update_existing=false` có thể trả lỗi
`DUPLICATE_CODE`.

**Fix (root cause):** `ImportService::findRecentDuplicateReport()` — file
trùng sha256 với 1 lần **COMMIT** gần đây (24h, TẠM) của **cùng actor** thì
trả lại kết quả cũ (`duplicate=true`, `duplicate_of_import_report_id`),
KHÔNG đọc/parse lại file, KHÔNG ghi SKU/report lần 2. Actor khác nhau hoặc
`dry_run=true` không bị coi là trùng.

**Quyết định TẠM cần BA/Tech Lead chốt chính thức (đã ghi rõ trong code, không
tự quyết):** cửa sổ 24h và phạm vi theo actor (không phải toàn hệ thống).

**Test:** cùng actor + cùng file (byte-for-byte, cùng sha256) → lần 2 trả
`duplicate=true`, không tạo `import_reports` row thứ 2, không tạo SKU trùng
(`assertSame(1, ...)`). Actor khác + cùng file → không bị coi trùng, chạy
thật.

## DEV330-P1-04 — Reconcile code source baseline (m1 vs m1-be-2)

**Phát hiện:** review hỏi liệu implementation hiện tại nằm trên `m1` hay
`m1-be-2`. Comment "BE2 code baseline for review" (Wuangg, 02/08/2026) từng
trỏ `m1-be-2` làm nguồn đánh giá; comment sau đó (cùng ngày, 14:18) đã CẬP
NHẬT baseline, ghi rõ "Repository/branch: WMS_Warehouse_Hoa_Nam_BE — m1-be-2
... Thay thế baseline cũ: comment trỏ tới Hoa_Nam_Backend/m1-be-2 không còn
là nguồn code dùng để đánh giá ticket này" — nội dung mâu thuẫn nội tại
(comment vẫn ghi tên nhánh `m1-be-2` trong khi tuyên bố thay thế baseline
cũ trỏ `m1-be-2`), cần Tech Lead làm rõ trực tiếp trên Jira.

**Đối chiếu với evidence có sẵn trong repo (docs/docs_task_jira/WWHN-190.md,
WWHN-191.md, docs/evidence/WWHN-219/README.md,
scripts/wwhn219-evidence-manifest.php):** `m1-be-2` đã được xác nhận là
**superseded branch, fully merged/ancestor của `m1`**, chính thức nằm trong
`LEGACY_SOURCES_EXCLUDED.txt` của CI gate WWHN-219. Toàn bộ code liên quan
đến WWHN-330 (ImportService/ImportController/ImportReportController/
ImportErrorFileService) chỉ tồn tại trên `m1`, không có dấu vết `m1-be-2`
trong code hay comment.

**Disposition:** `m1` là nguồn chuẩn duy nhất cho code hiện tại (đúng theo
yêu cầu người dùng "chỉ làm ở nhánh m1"), khớp với governance đã có từ
WWHN-219. Đề nghị Tech Lead **re-pin chính thức trên Jira WWHN-330** rằng
review dùng repo/branch/SHA ở bảng SHA phía trên, không dùng `m1-be-2` —
đây là văn bản hoá lại quyết định đã ngầm định trong repo, KHÔNG tự ý đổi
baseline.

## DEV330-P1-05 — Handoff cho FE1 (WWHN-331) & QA1 (WWHN-332)

FE1 (WWHN-331, review 33/100) tại thời điểm review chưa có bất kỳ UI import
SKU nào — điều tra ban đầu của phiên này còn phát hiện `SKU_IMPORT_EXCEL`
(constant cũ) trỏ NHẦM sang `/import-batches` (một resource ERD hoàn toàn
khác — ImportBatch/item_instance traceability, bị CHẶN có chủ đích theo
REQ-SRS-034/DEC-MIG-API-001 và cổng CI `scripts/verify-master-sku-closure.mjs`
của WWHN-178). Trong lúc điều tra, chủ repo (FE1) đã tự sửa đúng gốc: đổi
`SKU_IMPORT_EXCEL` trỏ sang path thật `/skus/import-excel`
(`Modules/Warehouse/routes/import.php` phía BE), tách riêng constant
`IMPORT_BATCHES` (đánh dấu `@deprecated`) giữ nguyên giá trị cũ để
`/import-batches` vẫn không có nơi nào gọi tới (đã grep xác nhận — 0 call
site) → **không vi phạm REQ-SRS-034/DEC-MIG-API-001**, `master-sku:verify`
vẫn PASS sau khi đổi. Đã xây `SkuImportModal.tsx` đầy đủ luồng upload →
dry-run preview → sửa lỗi → import chính thức → tải file lỗi CSV, RBAC
`sku.manage`, xử lý duplicate/idempotent-replay, loading/error/403/404/hết
hạn — wired vào `ApiMasterScreens.tsx` (nút "Import Excel" chỉ hiện khi
`canManage`).

QA1 (WWHN-332) vẫn ở trạng thái Blocked/Pending trên Jira — chưa có input
độc lập từ QA, không tự nhận thay QA.

## DEV330-P2-01 — Đồng bộ narrative owner

WWHN-330 Description vẫn ghi "Người chịu trách nhiệm chính: BE2 — Duy Khánh
Nguyễn" trong khi comment "CẬP NHẬT PHÂN CÔNG FE/BE" trên WWHN-89 (tuần
trước) đã chuyển giao unified ownership cho Quang Nguyễn Thanh. Đây là
governance/PM item — không tự sửa Jira description, chỉ nêu trong role
comment PM/DEV bên dưới.

## Test evidence (chạy thật, không suy đoán)

| Lệnh | Kết quả |
|---|---|
| BE `php vendor/bin/phpunit --filter Wwhn330ImportHardeningTest` | 8/8 pass, 32-33 assertions (chạy 2 lần, ổn định) |
| BE `php vendor/bin/phpunit tests/Feature/Wwhn330ImportErrorFilePurgeTest.php` | 3/3 pass, 11 assertions |
| BE `php vendor/bin/phpunit tests/Feature/Wwhn174SkuImportValidationTest.php tests/Feature/Wwhn224SkuImportDryRunTest.php tests/Feature/ImportBatchTest.php` (regression) | 18/18 pass, 117 assertions |
| BE `php vendor/bin/phpunit` (full suite, sqlite mặc định) | 578 tests, 556 passed, **0 failed**, 22 skipped, 6656 assertions |
| FE `npx vitest run src/modules/master-data` | 3 file, 11/11 pass |
| FE `npx vitest run` (full suite) | 64 file, **487/487 pass**, 0 fail |
| FE `npm run lint` | 0 error, 24 warning (toàn bộ pre-existing, không nằm trong file WWHN-330/331 thay đổi) |
| FE `npm run typecheck` | pass, không lỗi |
| FE `npm run build` | pass, build thành công |
| FE `npm run master-sku:verify` (cổng CI WWHN-178) | PASS — xác nhận đổi `SKU_IMPORT_EXCEL` không phá gate |

Ghi chú: 1 lần chạy full suite BE giữa phiên báo 1 fail
(`error_file_available` null) trùng đúng lúc file đang được chủ repo chỉnh
sửa trực tiếp (repo được 2 bên cùng làm việc song song) — chạy lại ngay sau
đó và chạy lại lần nữa đều xanh 100% (0 fail), kết luận đó là flake do trùng
thời điểm ghi file, không phải lỗi thật; không dùng lần chạy đó làm evidence
chính thức.

## Residual findings — ĐÃ ĐÓNG (bổ sung 18/08/2026, cùng nhánh `m1`)

Hai residual bên dưới trước đây để mở chờ Tech Lead chọn hướng. Đã tìm được hướng sửa
**không phải đánh đổi 1 trong 2 rủi ro** và đã vá tận gốc:

### residual-01 — cắt âm thầm dòng dữ liệu sau dòng vật lý 1002 → ĐÃ SỬA

`ImportService::readRows()` đọc workbook theo **lô dòng vật lý** (mỗi lô
`MAX_PHYSICAL_ROWS_TO_READ` = 1002) và **lọc dòng trống ngay trong vòng lặp đọc**, nên
hạn mức được đếm theo **dòng dữ liệu thật**, không theo dòng vật lý:

- File hợp lệ (≤ 1000 dòng dữ liệu) có dòng trống xen giữa → đọc đủ, không cắt.
- File > 1000 dòng dữ liệu → ném `ImportRowLimitExceededException` ngay trong lô đầu;
  memory vẫn chỉ giữ tối đa 1001 dòng (giữ nguyên bảo đảm P1-02 cho file 100k dòng).
- `listWorksheetInfo()->totalRows` **chỉ** dùng để biết khi nào dừng quét, **không** dùng
  để từ chối file → không còn rủi ro từ chối nhầm file có dimension bloat.
- Phần đuôi vượt trần quét `MAX_PHYSICAL_ROWS_TO_SCAN` (= 20 × 1002 = 20 040 dòng vật lý)
  được **báo lỗi tường minh** (422, error code `SKU_IMPORT_ROW_LIMIT_EXCEEDED`, thông điệp
  hướng dẫn xoá dòng trống thừa), không bỏ qua im lặng.

### residual-02 — race window của idempotency → ĐÃ SỬA

Bọc **cả bước kiểm tra trùng lẫn bước chạy import** trong một atomic lock theo
`(actor_id, sha256)` — `ImportService::withImportLock()` dùng `Cache::lock()`
(`CACHE_STORE=redis` ở môi trường thật, TTL 120s, chờ tối đa 15s rồi fail-open để sự cố
cache không chặn nghiệp vụ). Hai request COMMIT giống hệt nhau gửi đồng thời bị tuần tự
hoá; request thứ hai thấy report `COMMITTED` của request thứ nhất và trả `duplicate=true`,
không tạo dòng `import_reports` thứ hai.

**Không dùng unique index DB** (phương án nêu ở bản ghi cũ) vì unique index chặn vĩnh
viễn, mâu thuẫn với ngữ nghĩa cửa sổ `IDEMPOTENCY_WINDOW_HOURS = 24h` — sau 24h nghiệp vụ
vẫn cho import lại cùng file. Lock cấp ứng dụng giữ đúng ngữ nghĩa, không đổi schema,
không cần migration. `dry_run` không lấy lock (không ghi gì nên không cần tuần tự hoá).

### Test bổ sung — `tests/Feature/Wwhn330ImportResidualHardeningTest.php` (4 test)

| Test | Kiểm chứng |
|---|---|
| `test_blank_rows_interleaved_do_not_silently_truncate_valid_data_rows` | 520 dòng dữ liệu xen dòng trống (dòng cuối ở dòng vật lý 1040 > trần cũ 1002) → import đủ 520, DB có đủ 520 SKU |
| `test_rows_beyond_physical_scan_cap_are_rejected_explicitly_not_dropped` | dữ liệu ở dòng vật lý 20 100 (> trần quét) → 422 `SKU_IMPORT_ROW_LIMIT_EXCEEDED`, 0 SKU được ghi |
| `test_commit_import_is_serialized_by_actor_and_file_checksum_lock` | COMMIT lấy đúng lock key `sku-import:{actor}:{sha256}` TTL 120s và **release** sau khi xong |
| `test_dry_run_does_not_take_the_commit_lock` | dry-run không lấy lock, không ghi SKU |

Kết quả: 4/4 pass, 15 assertions. Regression (`Wwhn330ImportHardeningTest`,
`Wwhn330ImportErrorFilePurgeTest`, `Wwhn174SkuImportValidationTest`,
`Wwhn224SkuImportDryRunTest`, `ImportBatchTest`): 29/29 pass, 160 assertions.

### Sửa kèm ngoài phạm vi WWHN-330 (test đang đỏ trên `m1`)

`tests/Unit/NoStoreApiResponsesTest::test_non_api_response_keeps_existing_cache_policy`
(thuộc WWHN-325, commit `0a7bd39`) assert **nguyên văn** chuỗi `'public, max-age=600'`
trong khi `Symfony\ResponseHeaderBag` chuẩn hoá directive thành `'max-age=600, public'`
→ test đỏ trên `m1`. Đã đổi sang assert **ngữ nghĩa** (có `public`, có `max-age=600`,
KHÔNG có `no-store`, không set `Pragma`) — kiểm đúng điều middleware phải bảo đảm, không
phụ thuộc thứ tự chuỗi. **Không đổi code middleware.**

## Residual findings gốc khi self-review (bản ghi lịch sử — đã xử lý ở mục trên)

Hai điểm dưới đây được phát hiện khi rà lại DEV330-P1-02/03 sau khi test đã
xanh; KHÔNG tự sửa vì mỗi hướng sửa đều đánh đổi rủi ro khác, cần Tech Lead
chọn hướng thay vì tự quyết ở đây:

1. **`readRows()` dùng physical-row cap (1002 dòng vật lý) trước khi lọc dòng
   trống.** Nếu file có nhiều dòng trống xen giữa dữ liệu (vd. định dạng
   Excel để trống nhiều dòng), dòng dữ liệu hợp lệ nằm SAU physical row 1002
   có thể bị cắt bỏ ÂM THẦM (không lỗi, không cảnh báo) thay vì báo đúng số
   dòng thật hoặc đọc đủ. Cách sửa an toàn nhất — dùng
   `$reader->listWorksheetInfo()` để lấy `totalRows` thật trước khi đọc cell
   — lại có rủi ro khác: Excel thường báo `totalRows` phình to hơn số dòng có
   dữ liệu thật (dimension "ma" do định dạng cũ để lại), có thể làm FE/BE từ
   chối nhầm file hợp lệ. Đây là đánh đổi giữa "có thể cắt âm thầm dòng cuối
   file có nhiều dòng trống" và "có thể từ chối nhầm file có dimension bloat"
   — cần Tech Lead chọn hướng, không tự đổi hành vi validate đã có test pass.
2. **`findRecentDuplicateReport()` (idempotency) có race window lý thuyết:**
   2 request COMMIT giống hệt nhau (cùng actor, cùng file) gửi gần như đồng
   thời có thể cùng không thấy report COMMIT nào của nhau (report đầu chưa
   kịp `COMMITTED` khi report hai check) → cả hai đều chạy thật, không chỉ 1
   lần. Do `Sku::query()->lockForUpdate()` khoá theo `sku_code` nên sẽ không
   sinh SKU trùng dữ liệu (transaction 2 sẽ thấy SKU đã tồn tại và theo
   `update_existing` mà báo `DUPLICATE_CODE` hoặc ghi đè) nhưng SẼ tạo 2 dòng
   `import_reports` COMMITTED riêng biệt cho cùng 1 file — phá vỡ đúng nghĩa
   "idempotent" nếu 2 request tới cùng lúc micro-giây. Not covered bởi test
   hiện có (test tuần tự, không đồng thời). Cần Tech Lead xác nhận đây có
   phải rủi ro cần khoá thêm (unique index `(file_sha256, actor_id, status)`
   partial theo COMMIT trong DB) hay chấp nhận là edge case hiếm.

## Còn lại cần BA/Tech Lead/QA (không tự quyết định)

1. Chốt chính thức: cửa sổ idempotency 24h + phạm vi theo actor (DEV330-P1-03).
2. Chốt chính thức: `MAX_ROWS=1000` là giới hạn approved cuối cùng, hay cần
   benchmark để nâng lên (DEV330-P1-02) — hiện tại giữ nguyên hành vi cũ để
   không đổi business rule ngoài thẩm quyền.
3. Chốt chính thức: expiry 24h cho file lỗi CSV tải về (DEV330-P1-01).
4. Tech Lead re-pin `m1`/SHA thật làm nguồn đánh giá WWHN-330 trên Jira
   (DEV330-P1-04).
5. QA1 (WWHN-332) chạy performance/security/error-file/RBAC/retry tests độc
   lập trên đúng SHA ở bảng trên — chưa có, không tự nhận thay QA.
6. PM đồng bộ label/description owner trên WWHN-330 (DEV330-P2-01).

---

# Bổ sung 18/08/2026 — rà lại cùng WWHN-331 (FE331-P1-03/04)

Ba mục dưới đây được phát hiện và vá tận gốc khi đối chiếu contract BE với UI
của `WWHN-331`. Vẫn nhánh `m1`, không tạo nhánh riêng.

## 1. API rò đường dẫn lưu trữ nội bộ ra ngoài (security / data-scope)

`GET /skus/import-reports` và `.../{id}` trả nguyên `error_file_path` — đường dẫn
trên disk private của server — cho mọi client có `sku.manage`. Register FE331-P1-03
yêu cầu rõ "không lộ data ngoài scope".

**Vá:** `ImportReport` thêm `$hidden = ['error_file_path']`. Việc tải file vẫn chỉ đi
qua `GET .../{id}/error-file` (đã gate `sku.manage`).

## 2. Hạn dùng file lỗi bị đánh giá ở sai phía (client clock)

FE phải tự so `error_file_expires_at` với đồng hồ máy client để đoán file còn tải
được không. Đồng hồ client không đáng tin: lệch giờ/timezone làm UI hiện nút dẫn
thẳng tới 404, hoặc ẩn nút trong khi file vẫn tải được.

**Vá:** `ImportReport` thêm accessor `error_file_available` (appended vào JSON) =
*đã sinh file* **và** *chưa quá hạn*, tính tại server — **đúng bằng** điều kiện
`ImportReportController::downloadErrorFile()` dùng để trả 200 hay 404. Một điều kiện
duy nhất, hai nơi không thể lệch. `ImportController::importExcel()` trả luôn field
này trong response 422 để UI hiển thị đúng ngay lần render đầu. OpenAPI của cả 3
route Import báo cáo đã ghi hợp đồng này.

## 3. Cột `duplicate_of_replay` là schema chết

Migration `2026_08_18_180000` tạo cột, model khai `fillable`/`cast`, nhưng **không
nơi nào ghi hoặc đọc**.

**Vá:** `ImportService::duplicateResult()` đánh dấu bản ghi **gốc** khi nó bị phát
lại. Đây chính là bằng chứng reconcile mà DEV330-P1-03 yêu cầu ("same file repeat …
counts/checksum/reconciliation"): truy vấn `import_reports` là thấy ngay báo cáo nào
đã được trả lại cho một lần gửi trùng, không phải đọc log ứng dụng. Marker truy vết,
không đụng số liệu nghiệp vụ của lần import gốc.

## 4. DEV330-P1-02 — benchmark đã CHẠY THẬT (trước đây NOT RUN)

`tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php` vừa chặn regression vừa
sinh evidence thô vào `docs/evidence/WWHN-330/BENCHMARK_RESULT.md`. Chạy lại:

```bash
php vendor/bin/phpunit tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php
```

Kết quả chứng minh bounded-read: file 20 000 dòng (gấp 20 lần hạn mức) bị từ chối với
peak memory chỉ nhỉnh hơn ~1,6 lần so với xử lý trọn vẹn file 1 000 dòng — không tăng
tỉ lệ theo số dòng. Số đo trên sqlite in-memory, **không phải cam kết SLA production**.

## Test bổ sung

`Wwhn330ImportHardeningTest`:

- `test_import_report_never_exposes_internal_storage_path_and_states_availability`
- `test_error_file_availability_flips_to_false_once_expired_and_download_matches`
- `test_replay_marks_the_original_report_as_duplicate_of_replay`

`Wwhn330ImportPerformanceBenchmarkTest`: 3 test (dry-run theo cỡ file, commit đúng hạn
mức, từ chối file 20 000 dòng + khẳng định bounded-read).

## Kết quả chạy thật (18/08/2026)

| Lệnh | Kết quả |
|---|---|
| `phpunit` — suite import (hardening + residual + purge + benchmark + dry-run + validation + batch) | **PASS 39 test / 218 assertion** |
| `phpunit` — contract/schema (Swagger route sync, documentation baseline, schema modules, ERD parity) | **PASS** 20 pass / 6 skip (skip do gate môi trường PostgreSQL) |
| `pint --test` — toàn bộ file thuộc WWHN-330/331 | **PASS** |

**Ghi chú trung thực về gate Pint toàn repo:** `pint --test app Modules tests config
database routes` hiện vẫn fail ở **18 file không liên quan tới WWHN-330/331** (debt có
sẵn: `ItemInstanceController`, `StockDocumentService`, một số migration/seeder/test cũ).
Lần rà này **không** đụng vào chúng để tránh diff lớn ngoài phạm vi ticket; cần một task
dọn Pint riêng.
