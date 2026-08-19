# WWHN-224 — Sổ đóng register P0/P1/P2 (re-review 18/08, 72/100)

> Nhánh canonical: `m1` (không mở nhánh riêng).
> Commit code của lần đóng này: **`937a5c0`** — `fix(WWHN-224): Import bao cao la output bat buoc + hop dong quyen dung voi route`.
> Không có hạng mục P0 trong register.

Tài liệu này chỉ ghi phần **DEV** đã làm và bằng chứng kiểm chứng lại được. Các mục do
BA/QA2/PM sở hữu được ghi rõ là **chờ chủ sở hữu**, DEV không tự ký thay.

---

## 1. Bảng đóng theo từng ID

| ID | Sev | Trạng thái | Nơi kiểm chứng |
|---|---|---|---|
| DEV224-P1-01R | P1 | **DEV đã trình, chờ BA/Data Owner duyệt tại WWHN-225** | `docs/decision-records/DISP-IMP-224-sku-import-scope-mapping.json` + test `test_sku_import_mapping_matches_the_locked_disposition_register` |
| DEV224-P1-02R | P1 | **Runbook + gate sẵn sàng, chờ QA2 chạy độc lập** | `docs/evidence/WWHN-224/QA2_RUNBOOK.md`, workflow `wwhn-224-p1-closure.yml` |
| DEV224-P1-03R | P1 | **Đóng** — nội dung PR #5/#6 đã nằm trên `m1`, PR được disposition tường minh | `docs/evidence/WWHN-224/PR_DISPOSITION.md` |
| DEV224-P1-04R | P1 | **Đóng** | `ImportController` annotation + `storage/api-docs/*` + Postman + 2 test guard |
| DEV224-P1-05R | P1 | **Đóng** | `ImportService::withReport()` + `ImportReportWriter` + 5 test bơm lỗi |
| DEV224-P1-06 | P1 | **DEV đã dựng bằng chứng, chờ PM/Tech Lead chốt trên Jira** | `docs/evidence/WWHN-224/SOURCE_PIN.md` |
| DEV224-P2-01 | P2 | **Đóng** (phần tài liệu trong repo); phần Description trên Jira do PM/BAS sửa | `docs/docs_task_jira/WWHN-224.md` §0 |

---

## 2. DEV224-P1-05R — Import báo cáo là output bắt buộc

### 2.1 Lỗi thật là gì

`ImportController::persistReport()` cũ bắt `Throwable`, ghi log rồi `return null`. Hệ quả
xếp theo mức nghiêm trọng tăng dần:

1. Mất truy vết của một lần **đã ghi dữ liệu thật** — đúng thứ mà WWHN-224 sinh ra để có.
2. Response `200 success` nhưng `import_report_id = null`: hợp đồng nói "luôn trả
   import_report_id để tra cứu lại" bị phá ngay trong ca thành công.
3. **Nặng nhất:** cửa sổ idempotent mất bản ghi `COMMITTED`. `findRecentDuplicateReport()`
   đọc chính bảng đó để nhận diện replay. Không có bản ghi thì lần người dùng bấm gửi lại
   (rất tự nhiên khi màn hình báo bất thường) sẽ **import lần hai** trên dữ liệu đã ghi
   đúng — tạo `DUPLICATE_CODE` hàng loạt hoặc ghi đè nếu `update_existing=true`.

Lý do ghi trong tài liệu cũ ("report chỉ là metadata, để 500 thì client tưởng thất bại rồi
gửi lại") đảo ngược thực tế: chính hành vi nuốt lỗi mới là cái làm lần gửi lại trở nên
nguy hiểm, vì nó phá luôn cơ chế chống gửi lại.

### 2.2 Sửa tận gốc

Không vá bằng cách "thử ghi lại report" hay "trả 500 rồi thôi". Gốc của vấn đề là **ranh
giới transaction**: report được ghi SAU khi `ImportService` đã commit, nên hai việc không
thể nguyên tử.

- `Modules/Warehouse/app/Services/ImportReportWriter.php` (mới) — nơi duy nhất ghi
  `import_reports`; ném `ImportReportPersistenceException` thay vì trả null.
- `ImportService::withReport()` gọi writer **bên trong** `DB::transaction()` đang ghi
  SKU/audit, và là hành động cuối trước commit. Ghi hỏng → exception → **DB tự rollback**
  SKU + audit + báo cáo. Không có bồi thường thủ công, không có trạng thái ở giữa.
- Fail-closed cả chiều **đọc**: `findRecentDuplicateReport()` không truy vấn được thì ném
  cùng exception thay vì chạy tiếp không còn guard idempotent — đây đúng là dạng fail-open
  mà DEV330-P1-07R3 đã phải bỏ ở phía khoá, không được để quay lại qua cửa sau.
- `ImportController` bắt exception → **503 `SKU_IMPORT_REPORT_PERSISTENCE_FAILED`** kèm
  `Retry-After`, `committed=false`, `partial_write=false`, `import_report_id=null`,
  `retryable=true`.

### 2.3 Vì sao 503 chứ không 409/500

| Mã | Ngữ nghĩa | Vì sao không dùng ở đây |
|---|---|---|
| 409 `CONCURRENCY_CONFLICT` | Tranh chấp nghiệp vụ: có lần import trùng định danh đang chạy (DEV330-P1-07R3) | Ở đây không có ai tranh chấp; phụ thuộc hạ tầng chết |
| 500 `INTERNAL_ERROR` | Lỗi không lường trước | Ta lường trước và biết chính xác nguyên nhân; 500 không hứa được "thử lại là an toàn" |
| **503** | Phụ thuộc bắt buộc không khả dụng, tự hết khi phụ thuộc trở lại | **Được DEV224-P1-05R chỉ định tường minh** |

Ghi chú baseline trung thực: **SRS §8.2 không liệt kê 503**. Hàng 500 của §8.2 mô tả đúng
cam kết ta đang thực hiện ("transaction rolled back so state, stock and movement remain as
before request"), nhưng register 18/08 yêu cầu chính xác mã 503, và 503 + `Retry-After`
diễn đạt đúng bản chất hơn. Đây là **yêu cầu bổ sung baseline**, đã ghi vào
`docs/decision-records/DEC-IMP-001.md` §10 để BA đưa vào §8.2 ở phiên bản SRS kế tiếp,
không phải mã tự chế trong code.

### 2.4 Bằng chứng bơm lỗi

`tests/Feature/Wwhn224P1ClosureTest.php`:

| Test | Bơm lỗi ở đâu | Chứng minh điều gì |
|---|---|---|
| `test_report_write_failure_after_sku_write_returns_503_and_rolls_back_everything` | mock writer ném lỗi, **và tự khẳng định SKU đã nhìn thấy được ngay lúc đó** | Lời gọi nằm SAU ghi SKU và TRONG transaction; sau request: 0 SKU, 0 audit `SKU_IMPORT_CREATE`/`SKU_IMPORT_COMMIT`, 0 `import_reports`; 503 + `Retry-After` |
| `test_report_write_failure_in_dry_run_returns_503_and_rolls_back_the_dry_run_audit` | như trên, chế độ dry-run | Không còn dấu `SKU_IMPORT_DRY_RUN_CHECK` ma |
| `test_report_write_failure_on_row_error_path_returns_503_not_a_silent_422` | nhánh 422 lỗi dòng | Không có báo cáo REJECTED thì không trả 422 kèm phiếu tra cứu rỗng |
| `test_report_lookup_failure_refuses_the_import_instead_of_losing_the_idempotency_guard` | `Schema::drop('import_reports')` — hỏng chiều ĐỌC | Fail-closed cả khi mất guard idempotent, không ghi SKU |
| `test_every_processed_run_returns_a_real_report_id` | — | Bất biến chiều ngược: 200 và 422-dòng đều có report id thật, đúng status |

---

## 3. DEV224-P1-04R — quyền trong tài liệu = quyền route thực thi

### 3.1 Lỗi thật là gì

`ImportController` khai `403: Missing product.manage permission` ở 2 chỗ, trong khi
`Modules/Warehouse/routes/import.php` gate `permission:sku.manage` cho cả 5 route.

Đáng chú ý: artifact `storage/api-docs/api-docs.json` đang commit lại **đã ghi
`sku.manage`** — vì trước đây `config/l5-swagger.php` không quét `ImportController` nên
spec sinh ra từ fallback `RouteSyncProcessor` (đọc thẳng middleware). Nghĩa là artifact và
nguồn sinh ra nó nói khác nhau, và **lần regenerate kế tiếp sẽ âm thầm nhả lại chuỗi sai**.
Sửa mỗi chuỗi trong artifact là vá triệu chứng.

### 3.2 Sửa tận gốc

- Sửa annotation trong `ImportController` (nguồn) → `sku.manage`; thêm 503 vào hợp đồng
  công bố; nói rõ 200/422 luôn kèm `import_report_id`.
- Đồng bộ artifact bằng `scripts/sync-wwhn224-import-openapi.php`, **chỉ chạm nhóm path
  `/api/v1/skus/import*`**. Lý do không commit trọn gói bản `l5-swagger:generate`: tại
  thời điểm chạy, bản regenerate đầy đủ lệch 604 dòng, phần lớn đến từ annotation
  health/readiness của một task song song đang làm dở — commit trọn gói vừa gộp việc chưa
  xong của người khác vào closure này, vừa tạo artifact không tái lập được từ repo. Script
  có chế độ `--check` để CI phát hiện lệch về sau.
- **Guard chống tái diễn** (đây mới là phần "tận gốc"):
  `test_generated_openapi_documents_the_permission_the_route_actually_enforces` đọc quyền
  THẬT từ bảng route rồi soi chính artifact FE/QA/Postman dùng. Bất kỳ route import nào
  đổi middleware mà quên đồng bộ hợp đồng đều đỏ tại CI.
- Postman: thêm folder *"SKU import — hợp đồng P1 đóng 18/08 (WWHN-224)"* vào
  `docs/postman/WWHN-172 SKU Container Contract.postman_collection.json`, có request đọc
  `/docs` để khẳng định spec công bố nêu `sku.manage` và có 503.

### 3.3 Pin hash artifact

Xem `docs/evidence/WWHN-224/ARTIFACT_PIN.md` (SHA-256 của `api-docs.json`, `api-docs.yaml`,
collection Postman, register mapping, tại đúng SHA đóng).

---

## 4. DEV224-P1-01R — phạm vi SKU-only và bảng mapping

Bản trình: `docs/decision-records/DISP-IMP-224-sku-import-scope-mapping.json`
(trạng thái `PROPOSED_BY_DEV_PENDING_BA_DATA_OWNER_APPROVAL`, hai ô ký để trống có chủ ý).

- **Trong phạm vi:** đúng một dataset `SKU`, quyền `sku.manage`, 13 cột template + 3 alias
  legacy (`code`, `name`, `version`), mapping ghi đủ `field / type / required / default /
  rule / error_codes / owner`.
- **Ngoài phạm vi (5 dataset còn lại của SRS §13.12):** `PHYSICAL_CODE`, `ITEM`,
  `CONTAINER`, `OPENING_BALANCE`, `AGENCY` — mỗi cái kèm lý do và cổng mở rộng. Neo vào
  câu chuẩn tắc của §13.12: *"No public/admin import endpoint exists in MVP"* cho gói
  migration/cutover, và tồn đầu kỳ chỉ vào hệ thống qua controlled job có QL kho ký.
  Nói cách khác: **SKU-only là có chủ ý, không phải thiếu sót**, và mọi mở rộng phải qua
  WWHN-225.
- Test `test_sku_import_mapping_matches_the_locked_disposition_register` đối chiếu register
  với `ImportService::TEMPLATE_COLUMNS`/`ACCEPTED_COLUMNS` — thêm/bớt cột mà quên sửa
  register là đỏ CI. Test **không** thay BA duyệt; nó chỉ chặn code trôi khỏi bản đã trình.

Khoảng trống baseline đã ghi (không giấu): bốn tệp baseline không mô tả luồng import SKU
tương tác; điều này đã nằm ở DEC-IMP-001 §9 và chính là lý do phải có disposition thay vì
suy diễn từ mô tả task cha WWHN-56.

---

## 5. Phần chờ chủ sở hữu khác

| Mục | Chủ sở hữu | DEV đã chuẩn bị sẵn cái gì |
|---|---|---|
| DEV224-P1-01R phê duyệt | BA + Data Owner (WWHN-225) | Register JSON đầy đủ field/type/required/default/owner + lý do out-of-scope |
| DEV224-P1-02R thực thi | QA2 (WWHN-226) | `QA2_RUNBOOK.md` 13 ca + workflow exact-SHA sinh artifact |
| DEV224-P1-06 chốt nguồn | PM + Tech Lead | `SOURCE_PIN.md` với bằng chứng ancestry `m1-be-2 ⊂ m1` |
| DEV224-P2-01 Description | PM/BAS | Ghi chú ownership đã đặt ở đầu `docs/docs_task_jira/WWHN-224.md` |
