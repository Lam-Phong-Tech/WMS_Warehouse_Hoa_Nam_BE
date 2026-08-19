# DEC-IMP-001 — Hạn mức, hiệu năng, retention, idempotency, tương tranh và data-scope của import SKU

| Trường | Giá trị |
|---|---|
| Mã quyết định | `DEC-IMP-001` |
| Ngày lập | 18/08/2026 |
| Bản sửa đổi | **Rev.2 — 18/08/2026** (đóng WWHN-330 register R3) |
| Phạm vi | `POST /api/v1/skus/import-excel`, `GET /api/v1/skus/import-template`, `GET /api/v1/skus/import-reports[/{id}[/error-file]]`, lệnh `import:purge-error-files` |
| Ticket | WWHN-330 (BE), WWHN-331 (FE), WWHN-332 (QA), task cha WWHN-89 |
| Đóng mục review | FE331-P1-02R3, FE331-P1-03R3, FE331-P1-06R3 · **DEV330-P1-01R3, DEV330-P1-02R3, DEV330-P1-03R3, DEV330-P1-06R3, DEV330-P1-07R3** |
| Người duyệt | BA/BAS + Tech Lead (comment sign-off trên WWHN-330 / WWHN-331) |
| Nguồn sự thật kỹ thuật | `config/warehouse.php` → khoá `warehouse.sku_import` |

**Rev.1 → Rev.2 thay đổi gì:** Rev.1 chốt hạn mức, định danh idempotency và data-scope.
Rev.2 bổ sung bốn thứ mà register R3 chỉ ra là còn thiếu hoặc còn nằm trong code dưới
dạng hằng số chưa duyệt: **chính sách quét dòng vật lý** (§1), **mục tiêu hiệu năng**
(§4), **retention vật lý của file lỗi** tách khỏi hạn dùng logic (§5), và **chính sách
tương tranh khi hết thời gian chờ khoá** (§6). Rev.2 cũng sửa một lỗi của chính Rev.1:
§1 trích số benchmark (217 ms / 1,76 s) **không khớp** artifact đo được — nay tài liệu
không nhắc lại số đo nữa mà trỏ thẳng tới artifact, để hai nơi không thể lệch lần nữa.

---

## 1. Hạn mức đã chốt

| Hạng mục | Giá trị chốt | Khoá config | Nơi enforce |
|---|---:|---|---|
| Số dòng dữ liệu tối đa mỗi lần import (không tính header) | **1 000** | `max_rows` | `ImportService::readRows()` → 422 `SKU_IMPORT_ROW_LIMIT_EXCEEDED` |
| Kích thước file upload tối đa | **10 240 KB (10 MB)** | `max_file_size_kb` | rule `max:` của `ImportController::importExcel()` **và** `ImportService::assertFile()` |
| Định dạng file chấp nhận | **.xlsx, .xls** | `allowed_extensions` | rule `mimes:` **và** `ImportService::assertFile()` |
| **Bội số quét dòng vật lý** | **20×** (trần = 20 040 dòng vật lý) | `max_physical_scan_multiplier` | `ImportService::readRows()` → 422 `SKU_IMPORT_ROW_LIMIT_EXCEEDED` |
| Hạn tải file lỗi CSV (logic) | **24 giờ** kể từ lúc sinh | `error_file_expiry_hours` | `ImportErrorFileService::generate()`, `ImportReport::error_file_available` |
| Cửa sổ idempotent replay | **24 giờ** | `idempotency_window_hours` | `ImportService::findRecentDuplicateReport()` |

**Hai tầng enforce cùng một hạn mức là chủ ý, nhưng phải cùng một nguồn.** Rev.1 chuyển
validator HTTP sang đọc config nhưng để lại `10 * 1024 * 1024` và `['xlsx','xls']` viết
cứng trong `ImportService::assertFile()` — hai bản sao của cùng một hạn mức, chắc chắn sẽ
lệch khi ai đó nâng `max_file_size_kb`. Rev.2 cho cả hai tầng đọc chung hợp đồng. Giữ tầng
service vì `import()` còn được gọi từ CLI/job, nơi không đi qua request validation nào.

**Vì sao cần trần quét dòng vật lý (chính sách mới được văn bản hoá, không phải mới có).**
Hạn mức nghiệp vụ đếm theo *dòng dữ liệu*, nhưng một workbook có thể trải 1 000 dòng dữ
liệu đó ra hàng triệu dòng vật lý (dòng trống xen giữa, dimension "ma" của định dạng cũ).
Không có trần thì một file vài KB vẫn ép server quét vô hạn — đây là bề mặt DoS, không
phải chuyện tiện nghi. Bội số **20** đã chốt vì file "thưa" thật của người dùng đo được ở
mức ~2× (benchmark case `sparse dry_run/1000 rows`), nên 20× để lại biên an toàn gấp mười
lần mà vẫn chặn đứng file trải dài bất thường. Vượt trần **không bị bỏ qua im lặng** mà
trả 422 kèm hướng dẫn xoá dòng trống thừa, và trần này được công bố cho client trong
`constraints.max_physical_rows_to_scan` — người dùng phải biết vì sao file 300 dòng của
mình bị từ chối.

**Ranh giới cần nêu rõ:** NFR-SRS-017 (dry-run tối thiểu 10 000 dòng/file) thuộc luồng
**migration/opening balance** chạy bằng job/script có kiểm soát (REQ-SRS-021,
DEC-MIG-API-001), và NFR-SRS-019 (10 000 dòng Excel/file) thuộc luồng **toa yêu cầu xuất**
(REQ-SRS-014). **Không** NFR nào áp cho endpoint import SKU tương tác này — xem §9. Ba
luồng có hạn mức riêng là chủ ý, không phải mâu thuẫn.

**Cách đổi giá trị:** sửa quyết định này trước, rồi đổi `config/warehouse.php` (hoặc biến
môi trường `SKU_IMPORT_*` tương ứng). Không được đưa số cứng trở lại vào code hay vào
chuỗi hiển thị của FE.

---

## 2. Định danh một lần import (idempotency identity)

```
identity = file_sha256 + actor_id + options_fingerprint
options_fingerprint = sha256("update_existing=0")  hoặc  sha256("update_existing=1")
```

Hai lần gửi chỉ được coi là **cùng một thao tác** (replay → trả lại kết quả cũ, không ghi
SKU lần hai) khi trùng **đủ ba** thành phần trên và nằm trong cửa sổ 24 giờ.

Lý do `update_existing` phải nằm trong định danh: cùng một file nhưng khác tùy chọn là hai
hiệu lực nghiệp vụ khác nhau — `false` chỉ tạo mới và trả `DUPLICATE_CODE` nếu SKU đã tồn
tại, `true` cho phép ghi đè SKU đang tồn tại. Coi hai lần đó là trùng nhau sẽ **nuốt mất**
một thao tác người dùng thực sự muốn chạy: người dùng bật công tắc, bấm Import, và hệ
thống lặng lẽ trả lại kết quả cũ mà không cập nhật gì.

Lý do định danh theo **actor** chứ không theo toàn hệ thống: hai người khác nhau cùng nộp
một file giống hệt (ví dụ cùng nhận từ nhà cung cấp) vẫn là hai lần import độc lập; một
người không được "mượn" kết quả import của người khác.

Bản ghi tạo trước migration `2026_08_18_190000` có `options_fingerprint = NULL` (không thể
biết lúc đó chạy với tùy chọn nào) và được xử lý theo hướng bảo toàn hành vi cũ — vẫn coi
là trùng — thay vì đoán. Nhánh này tự hết hiệu lực sau một cửa sổ idempotent kể từ lúc
triển khai.

**QA dựng lại được giá trị mà không cần đọc code:** `sha256("update_existing=1")` phải
bằng `options_fingerprint` của một lần import chạy với `update_existing=true`.

**Quan hệ với `idempotency_records` của ERD.** ERD (bảng #6) có `scope` bao gồm giá trị
`IMPORT` và ngữ nghĩa chuẩn "UNIQUE(scope, idempotency_key); cùng key khác hash → 409".
Cơ chế đó dựa trên header `Idempotency-Key` do client gửi. Endpoint import SKU **không**
gắn middleware idempotency-key, và đó là một quyết định: định danh ở đây suy ra từ **nội
dung file** (sha256), nên nó bảo vệ được cả trường hợp client quên/gửi sai header — điều
thường xảy ra với upload multipart do người dùng bấm lại nút. Hai cơ chế phục vụ cùng một
bất biến ("một thao tác chỉ có hiệu lực một lần") ở hai lớp khác nhau và không xung đột;
nếu sau này bổ sung `Idempotency-Key` cho route này thì nó là lớp thứ hai, không thay thế.

---

## 3. Data-scope của Import báo cáo SKU

Permission `sku.manage` trả lời "được dùng chức năng import hay không". Nó **không** trả
lời "được đọc lần import của ai". Trước quyết định này, bất kỳ ai có `sku.manage` cũng
liệt kê được mọi Import báo cáo và tải được file lỗi CSV của người khác — mà file lỗi chứa
nguyên nội dung file người đó nộp (mã SKU, tên hàng, giá trị từng ô sai).

| Phạm vi | Điều kiện | Được đọc |
|---|---|---|
| `GLOBAL` | `User::warehouseScopeIds() === null` (SUPER_ADMIN, hoặc vai trò hệ thống không gắn `scope_ref`) | Mọi Import báo cáo SKU |
| `ACTOR_SCOPED` | Người gọi bị giới hạn kho | Chỉ báo cáo do chính mình tạo (`actor_id`) |

Mã trạng thái:

| Tình huống | Kết quả |
|---|---|
| Thiếu permission `sku.manage` | **403** `ACCESS_DENIED` |
| Có permission, báo cáo nằm trong scope | **200** |
| Có permission, báo cáo của người khác (ngoài scope) | **404** `RESOURCE_NOT_FOUND` — y hệt bản ghi không tồn tại |
| Có permission, báo cáo không tồn tại | **404** `RESOURCE_NOT_FOUND` |

Chọn 404 (không phải 403) cho bản ghi ngoài scope là chủ ý và khớp SRS §13.8: "404 được
phép dùng để mask resource ngoài data scope; 403 dùng cho action đã nhận diện nhưng thiếu
permission". 403 xác nhận "id này có tồn tại nhưng bạn không được xem", tức vẫn rò rỉ sự
tồn tại. Với `warehouse_id` truyền vào làm **tham số lọc** thì vẫn giữ 403 `ACCESS_DENIED`
như WWHN-328 — hai ca khác nhau.

Nguồn scope lấy đúng `scope_ref` mà `EnforceInventoryWarehouseScope` và
`/reports/dashboard-quick` đang dùng, nên **không phát sinh permission mới** và không có
nguồn scope thứ hai để lệch nhau. SKU là master data toàn cục (ERD không có `warehouse_id`
trên SKU) nên không thể áp scope theo kho lên chính báo cáo; thứ cần bảo vệ là nội dung
file của người nộp.

`meta.data_scope` (`GLOBAL` | `ACTOR_SCOPED`) được trả trong response danh sách để QA/BA
đọc được phạm vi đang áp dụng ngay trên response.

---

## 4. Mục tiêu hiệu năng (performance objective) — DEV330-P1-02R3

| Kịch bản | Ngân sách | Khoá config |
|---|---:|---|
| Dry-run ở đúng hạn mức (1 000 dòng) | **15 000 ms** | `performance_objective_ms.dry_run_at_max_rows` |
| Commit thật ở đúng hạn mức | **60 000 ms** | `performance_objective_ms.commit_at_max_rows` |
| Từ chối file gấp 20 lần hạn mức | **15 000 ms** | `performance_objective_ms.reject_oversize` |

**Đây là ngân sách phát hiện hồi quy, KHÔNG phải cam kết SLA** — và sự phân biệt này là
điều kiện để con số có ích. Benchmark chạy trên máy CI/dev dùng chung tài nguyên, số đo
dao động vài lần giữa các lần chạy; đặt ngưỡng sát số đo thực tế sẽ tạo ra test chớp tắt
mà không ai tin nữa. Ngân sách vì vậy đặt rộng, đủ để bắt thứ nó sinh ra để bắt: **thay
đổi bậc độ phức tạp** — quay lại nạp toàn bộ workbook vào memory, hoặc N+1 query khi ghi
SKU. Vượt ngân sách thì `Wwhn330ImportPerformanceBenchmarkTest` **đỏ** và bắt buộc điều
tra, thay vì để số liệu trôi dần qua các sprint mà không ai nhận ra.

**SLA production do QA1/DevOps đo lại** trên môi trường production-like (WWHN-332,
QA_RUNBOOK mục "Performance"). Baseline không có NFR hiệu năng nào cho luồng này (§9),
nên không có ngưỡng nào để tham chiếu ngoài quyết định này.

**Bằng chứng đo:** `docs/evidence/WWHN-330/BENCHMARK_RESULT.md` (bảng đọc được) và
`docs/evidence/WWHN-330/BENCHMARK_RAW.json` (số thô + môi trường, dạng máy đọc). Cả hai do
chính test sinh ra ở mỗi lần chạy; **tài liệu này cố ý không nhắc lại số đo** để không lặp
lại lỗi của Rev.1 (số trong tài liệu lệch số trong artifact). Mọi câu mô tả môi trường
trong artifact đều suy ra từ tiến trình thật (`DB::connection()->getDriverName()`,
`PHP_VERSION`, `memory_limit`), nên artifact không thể tự mâu thuẫn như bản trước.

Bộ case đo bao trùm đúng các biên mà register liệt kê: 100 / 500 / **1 000** (hạn mức) /
**1 001** (vượt đúng 1 dòng) / **sparse 1 000** (gấp đôi dòng vật lý) / **20 000** (gấp 20
lần) / **vượt trần kích thước**.

Riêng biên **10 MB**: benchmark kiểm bằng cách hạ trần qua đúng hợp đồng rồi nộp file vượt
trần mới — cùng một đường chặn, xác định, rẻ. Dựng một file .xlsx 10 MB thật cần ~25 MB
nội dung khó nén (xlsx là zip) và hàng trăm MB RAM trong CI chỉ để chứng minh một rule
`max:` đã chạy. Con số tuyệt đối 10 MB **do QA1 xác minh trên môi trường thật** bằng file
10 MB thật; quy trình dựng file nằm trong QA_RUNBOOK.

---

## 5. Retention của file lỗi CSV — DEV330-P1-01R3

Hai khái niệm khác nhau, trước Rev.2 chỉ có một cái được chốt:

| Khái niệm | Giá trị | Khoá config | Hiệu lực quan sát được |
|---|---:|---|---|
| **Hạn dùng LOGIC** | 24 giờ kể từ lúc sinh | `error_file_expiry_hours` | `GET .../error-file` trả **404**; `error_file_available = false` |
| **Khoảng đệm trước khi xoá vật lý** | 0 giờ | `error_file_purge_grace_hours` | Hết hạn logic là đủ điều kiện xoá ở lần chạy job kế tiếp |
| **Nhịp chạy job dọn** | hằng ngày **02:15** | `error_file_purge_daily_at` | `Schedule::command('import:purge-error-files')` |
| **Thời gian tồn tại tối đa trên đĩa** | **≤ 48 giờ** (= 24 + 0 + tối đa 24 giờ chờ tới lần chạy kế) | — | công bố ở `constraints.error_file_max_on_disk_hours` |

**Vì sao phải tách hai khái niệm.** "Hết hạn" ở tầng API chỉ nghĩa là *không tải được nữa*.
Nó không nói gì về đĩa. Nếu không chốt retention vật lý thì nội dung file người dùng nộp
(mã SKU, tên hàng, giá trị từng ô sai) nằm trên storage vô thời hạn dù hợp đồng đã tuyên
bố "hết hạn sau 24 giờ" — một lời hứa chỉ đúng ở lớp mà người ta nhìn thấy. Giờ chạy job
đọc từ **cùng khoá config** mà tài liệu này trích, nên con số "≤ 48 giờ" không thể sai lệch
so với lịch chạy thật.

**Quét file mồ côi (orphan sweep).** Vòng dọn theo `import_reports` không bao giờ chạm tới
file mà không bản ghi nào trỏ tới. Loại rác này phát sinh thật:
`ImportController::attachErrorFile()` ghi file **trước** rồi mới lưu path vào DB — bước lưu
thất bại (đã có nhánh log riêng) để lại một file không ai biết; khôi phục DB từ backup hoặc
xoá báo cáo thủ công cũng cho ra cùng kết quả. Lệnh purge vì vậy quét cả thư mục, dùng
`mtime` của chính file và **cùng mốc retention** với nhánh theo báo cáo, nên file mồ côi
không bao giờ bị xoá sớm hơn file có báo cáo hợp lệ, và file vừa sinh trong một lần import
đang chạy không bị xoá nhầm. Tắt được bằng `error_file_orphan_sweep = false` hoặc
`--skip-orphans`.

**Đối chiếu SRS §13.8 / NFR-SRS-018** (yêu cầu chung cho mọi purge job):

| Yêu cầu §13.8 | Trạng thái |
|---|---|
| idempotent | ✅ chạy lại không đổi kết quả — bản ghi đã null hoá không còn được chọn |
| dry-run / report | ✅ `--dry-run` liệt kê mà không xoá; mọi lần chạy đều in số liệu |
| retry | ✅ điều kiện chọn dựa trên dữ liệu, không dựa con trỏ → lần chạy sau gom nốt phần sót |
| audit số record đã purge | ✅ ghi `IMPORT_ERROR_FILE_PURGE` vào audit log (kể cả lần chạy 0 file — "đã chạy và không có gì để xoá" khác hẳn "job không chạy") |
| legal-hold exclusion | **Không áp dụng, có lý do**: legal hold gắn với hồ sơ bảo hành/PII khách hàng (DR-PRIV-001). Đối tượng ở đây là bản sao tạm của file do chính người dùng nộp, luôn dựng lại được bằng cách import lại |

**Không** xoá `import_reports` hay `row_errors`: audit trail của lần import giữ nguyên theo
NFR-SRS-014 ("không hard-delete ledger/bằng chứng nghiệp vụ"). Chỉ phần file tải về bị xoá.

---

## 6. Chính sách tương tranh khi hết thời gian chờ khoá — DEV330-P1-07R3

| Tham số | Giá trị | Khoá config |
|---|---:|---|
| TTL khoá | 120 giây | `lock.ttl_seconds` |
| Thời gian chờ tối đa | 15 giây | `lock.wait_seconds` |
| Hết thời gian chờ | **FAIL_CLOSED → 409 `CONCURRENCY_CONFLICT`** | `lock.on_timeout` |

Khoá `sku-import:{actor_id}:{file_sha256}:{options_fingerprint}` tồn tại để bước *kiểm tra
đã import file này chưa* và bước *ghi SKU + tạo Import báo cáo* là **một thao tác nguyên
tử**.

Hành vi trước Rev.2 là **fail-open**: hết 15 giây chờ thì chạy tiếp mà không giữ khoá. Nó
vô hiệu hoá đúng cái bất biến khoá sinh ra để giữ, và vô hiệu hoá đúng vào lúc nguy hiểm
nhất — hết thời gian chờ *nghĩa là* đang có một request khác giữ khoá cho cùng bộ định
danh, tức đúng kịch bản gửi trùng mà cơ chế idempotent phải chặn. Hậu quả: hai dòng
`import_reports` COMMITTED cho cùng một file, cả hai request trả 200, không ai biết tính
idempotent đã bị phá.

**Neo baseline** — đây không chỉ là lựa chọn kỹ thuật:
- **NFR-SRS-003**: "Concurrency prevents negative stock and duplicate reversal — Parallel
  requests: **one success; others conflict/insufficient**."
- **AC-GWT-CONC-001**: "Tối đa một request thành công; **request còn lại trả 409/412/422
  theo điểm phát hiện**."

Điểm phát hiện ở đây là tranh chấp khoá → **409**. Mã lỗi `CONCURRENCY_CONFLICT` là một
trong bốn mã 409 mà **SRS §8.2** công nhận (cùng `STATE_CONFLICT` / `IDEMPOTENCY_CONFLICT`
/ `DOWNSTREAM_DEPENDENCY_EXISTS`). Baseline **không định nghĩa** 423, 429 hay 503 (§9) nên
không được tự chế mã ngoài hợp đồng. Đúng cam kết §8.2 cho 409 không phải replay, request
bị từ chối **không** ghi SKU và **không** tạo Import báo cáo — exception ném ra trước khi
vào transaction.

**Đánh đổi đã cân nhắc:** nếu Redis hỏng thì import bị chặn thay vì chạy mất an toàn. Với
một hạng mục Data Integrity đây là đúng thứ tự ưu tiên, và sự cố hạ tầng lộ ra ngay thay vì
âm thầm sinh dữ liệu sai. Hướng ngược lại vẫn cấu hình được
(`lock.on_timeout = FAIL_OPEN`, có ghi cảnh báo vào log) nhưng phải là **một quyết định
được ghi ra**, không phải mặc định ẩn — và chỉ đúng chuỗi `FAIL_OPEN` mới đổi hành vi, để
một giá trị gõ sai không vô tình hạ tiêu chuẩn toàn vẹn dữ liệu.

`dry_run` không lấy khoá (không ghi gì nên không cần tuần tự hoá).

---

## 7. Hợp đồng file lỗi CSV — theo SRS §13.12

SRS §13.12 là nơi **duy nhất** trong 4 tài liệu baseline định nghĩa hình dạng một error
file. Rev.1 tự đặt 5 cột riêng (`row, field, error_code, message, value`): đọc được nhưng
lệch chuẩn, và người vận hành phải học hai định dạng file lỗi khác nhau cho cùng một hệ
thống. Rev.2 dùng đúng 9 cột của §13.12:

```
source_file, sheet, row_no, field, error_code, message, raw_value, severity, suggested_action
```

- **`severity`** ∈ `CRITICAL` | `ERROR`. Trong luồng all-or-nothing này **mọi** dòng lỗi
  đều chặn commit, nên severity không quyết định "có ghi hay không" mà nói cho người sửa
  file biết bản chất lỗi: `CRITICAL` = xung đột định danh/tham chiếu (`DUPLICATE_CODE`,
  `DUPLICATE_SKU_CODE_IN_FILE`, `INACTIVE_SKU_CONFLICT`, `UNKNOWN_MASTER_CODE` — phải
  quyết định nghiệp vụ mới sửa được), `ERROR` = sai định dạng một ô (sửa tại chỗ là xong).
  **Không dùng `WARNING`** vì ở đây không có lỗi nào không chặn; gán WARNING sẽ nói sai.
- **`raw_value`** được che nếu khớp dạng email/số điện thoại (§13.12: "Masked if PII").
  Template SKU của §13.12 không có cột PII nào, nhưng file do **người dùng** nộp: một ô dán
  nhầm liên hệ khách hàng vẫn lọt vào file lỗi rồi tải về được. Che theo dạng dữ liệu là
  hàng rào rẻ và không thể sai ở hướng nguy hiểm.
- **`suggested_action`** là chỉ dẫn sửa cụ thể cho từng mã lỗi, để file lỗi tự đủ nghĩa khi
  mở bằng Excel.
- Giữ nguyên: UTF-8 BOM (Excel mở tiếng Việt), chặn CSV/formula injection (`= + - @ \t \r`),
  cắt 500 ký tự mỗi ô.

**Sai khác đã biết với §13.12, cố ý giữ:** tập `error_code` của import SKU rộng hơn tập
liệt kê ở §13.12 (`DUPLICATE_CODE / UNKNOWN_SKU / INVALID_ENUM / REQUIRED / RECON_MISMATCH
/ CHECKSUM_CHANGED`) — ví dụ `INVALID_CODE_FORMAT`, `INACTIVE_SKU_CONFLICT`,
`DUPLICATE_SKU_CODE_IN_FILE`. Đổi tên mã lỗi là thay đổi phá vỡ hợp đồng với FE và với các
test đang xanh, trong khi mã hiện tại chính xác hơn cho người sửa file. Ghi nhận là sai
khác có kiểm soát, không phải bỏ sót; nếu BA muốn thống nhất tuyệt đối thì đó là một thay
đổi hợp đồng FE+BE riêng.

---

## 8. Công bố ra API (bỏ hằng số ở client)

Khối `constraints` được trả trong:

- `data.constraints` của **mọi** response `POST /skus/import-excel` (200, 409 tranh chấp
  khoá, 422 row lỗi, 422 vượt hạn mức, 422 file hỏng, và ca duplicate);
- `meta.constraints` của `GET /skus/import-reports` — endpoint đọc duy nhất mà màn hình
  import gọi được **trước** khi người dùng chọn file.

```json
{
  "decision_ref": "DEC-IMP-001",
  "max_rows": 1000,
  "max_file_size_kb": 10240,
  "max_file_size_mb": 10,
  "allowed_extensions": ["xlsx", "xls"],
  "max_physical_rows_to_scan": 20040,
  "error_file_expiry_hours": 24,
  "error_file_max_on_disk_hours": 48,
  "idempotency_window_hours": 24,
  "idempotency_identity": ["file_sha256", "actor_id", "update_existing"],
  "concurrent_import_policy": "FAIL_CLOSED_409"
}
```

**Không mở route mới cho việc này là chủ ý.** `/skus/import-excel`, `/skus/import-template`
và ba route Import báo cáo vốn đã nằm ngoài 67 operation Annex C (REQ-SRS-034,
DEC-MIG-API-001); thêm một endpoint công khai nữa là tự mở rộng bề mặt API ngoài baseline
đã duyệt. Hợp đồng vì vậy đi kèm các operation sẵn có.

FE hiển thị đúng khối này; khi BE không trả `constraints`, UI mô tả chung ("giới hạn do máy
chủ quy định") thay vì đoán một con số có thể đã lỗi thời.

---

## 9. Khoảng trống baseline mà quyết định này lấp — đã rà 4 tài liệu

Rà toàn văn `HN_SRS_FRD_v1.12_FULL.md`, `HoaNam_ERD_Moi_Nhat.md`,
`HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html`,
`WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`. Những mục dưới đây **không có** trong
baseline; chúng được quyết định ở đây chứ không phải suy diễn từ một điều khoản có sẵn:

| # | Khoảng trống | Quyết định tại |
|---|---|---|
| 1 | Toàn bộ luồng **import SKU bằng Excel** (endpoint, template, dry-run, all-or-nothing, tải file lỗi). Baseline chỉ có một hằng số `'/skus/import-excel'` trong Dev Guide, không kèm rule. Annex C 67 operation chỉ có `listSkus`/`createSku`/`updateSku` | Toàn bộ DEC-IMP-001 |
| 2 | Bảng **`import_reports` không tồn tại trong ERD** (59 bảng, không bảng nào có `options_fingerprint`, `error_file_path`, `error_file_expires_at`, `duplicate_of_replay`) | §2, §5 — **cần ERD amendment**, xem dưới |
| 3 | Giới hạn số dòng cho import SKU tương tác (NFR-SRS-017 áp cho migration, NFR-SRS-019 áp cho toa xuất) | §1 |
| 4 | Giới hạn dung lượng file / MIME; baseline không có mã **413**, **415** | §1 |
| 5 | Chính sách quét dòng vật lý | §1 |
| 6 | NFR hiệu năng cho luồng import tương tác | §4 |
| 7 | Retention/purge của file upload gốc, file lỗi, file export, file tạm | §5 |
| 8 | **Lịch chạy job nền / scheduler** cho bất kỳ job nào (baseline chỉ có "daily backup" ở NFR-SRS-011) | §5 |
| 9 | Lock timeout, retry/backoff, và mã **423 / 429 / 503** (không mã nào xuất hiện trong baseline) | §6 |
| 10 | Data-scope rule cho báo cáo import; permission riêng cho import SKU (baseline chỉ có `sku.view` / `sku.manage` / `catalog.manage`) | §3 |

**Hạng mục cần chuyển tiếp — ERD amendment (không tự sửa ở đây).** `import_reports` đang
tồn tại trong DB và trong code nhưng **không có trong ERD baseline**. Đây là cùng lớp việc
mà `DEC-DATA-001` đã xử lý cho defect catalogs. Đề nghị BA/Tech Lead đưa `import_reports`
(kèm 4 cột nêu trên) vào bản ERD kế tiếp; quyết định này ghi nhận khoảng trống chứ không
tự ý sửa tài liệu baseline.

---

## 10. Ảnh hưởng mã nguồn

| Tệp | Thay đổi |
|---|---|
| `config/warehouse.php` | Nguồn sự thật duy nhất: thêm `max_physical_scan_multiplier`, `performance_objective_ms`, `lock.*`, `error_file_purge_*`, `error_file_orphan_sweep` |
| `Modules/Warehouse/app/Support/SkuImportContract.php` | Thêm trần quét vật lý, ngân sách hiệu năng, tham số khoá, mốc retention vật lý; `constraints` công bố thêm 3 khoá |
| `Modules/Warehouse/app/Services/ImportService.php` | `assertFile()` bỏ 10 MB + danh sách đuôi file cứng; bội số quét đọc hợp đồng; `withImportLock()` **fail-closed**; ghi tên sheet cho file lỗi |
| `Modules/Warehouse/app/Exceptions/ImportConcurrencyException.php` | **Mới** — hết thời gian chờ khoá |
| `Modules/Warehouse/app/Http/Controllers/ImportController.php` | Bắt `ImportConcurrencyException` → 409 `CONCURRENCY_CONFLICT` kèm `constraints`; truyền `source_file`/`sheet` vào file lỗi; OpenAPI 409 |
| `Modules/Warehouse/app/Services/ImportErrorFileService.php` | Gỡ hằng `EXPIRY_HOURS` chết; 9 cột theo §13.12 + `severity` + `suggested_action` + che PII |
| `Modules/Warehouse/app/Http/Controllers/ImportReportController.php` | OpenAPI error-file: schema §13.12 + hợp đồng retention vật lý |
| `app/Console/Commands/PurgeExpiredImportErrorFiles.php` | Mốc purge theo grace; quét file mồ côi; audit `IMPORT_ERROR_FILE_PURGE`; `--skip-orphans` |
| `routes/console.php` | Giờ chạy đọc từ hợp đồng thay vì viết cứng |
| `tests/Feature/Wwhn330ImportR3ClosureTest.php` | **Mới** — 14 test khoá §1/§4/§5/§6/§7 |
| `tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php` | Thêm biên 1001/sparse/oversize; sinh `BENCHMARK_RAW.json`; mô tả môi trường suy từ tiến trình thật; assert ngân sách §4 |

---

## 11. Import báo cáo là output BẮT BUỘC, và mã 503 — DEV224-P1-05R (bổ sung 19/08)

### 11.1 Quyết định

`import_reports` **không** phải metadata tuỳ nghi. Với mọi lần workbook đã được xử lý
(dry-run, commit, và cả từ chối theo DÒNG), một Import báo cáo phải được ghi **trong cùng
transaction** với phần ghi SKU/audit. Không tồn tại trạng thái "đã ghi SKU nhưng không có
báo cáo", và không có response 200 nào thiếu `import_report_id`.

Không ghi được (hoặc không **đọc** được, khi kiểm tra trùng lặp) → transaction rollback →
API trả **HTTP 503 `SKU_IMPORT_REPORT_PERSISTENCE_FAILED`**, kèm header `Retry-After`,
`committed=false`, `partial_write=false`, `import_report_id=null`, `retryable=true`.

### 11.2 Vì sao không giữ hành vi cũ

Hành vi cũ (`persistReport()` nuốt lỗi, trả `null`, request vẫn 200) được biện minh bằng
"report chỉ là metadata; trả 500 thì client tưởng thất bại rồi gửi lại, gây DUPLICATE_CODE
giả". Lập luận đó tự phản bác: **chính bảng `import_reports` là nơi cơ chế idempotent đọc
để nhận diện replay** (§6, `findRecentDuplicateReport`). Mất bản ghi `COMMITTED` thì lần
gửi lại của người dùng **thật sự** chạy import lần hai — tức là hành vi nuốt lỗi tạo ra
đúng rủi ro mà nó viện dẫn để tự bào chữa, chỉ khác là im lặng.

### 11.3 Khoảng trống baseline (nối tiếp §9)

**SRS §8.2 không định nghĩa HTTP 503.** Hàng 500 `INTERNAL_ERROR` của §8.2 mô tả đúng cam
kết đang thực hiện ("transaction rolled back so state, stock and movement remain as before
request"), nhưng:

- Register review WWHN-224 ngày 18/08 (DEV224-P1-05R) **chỉ định tường minh mã 503** làm
  điều kiện đóng;
- 503 + `Retry-After` diễn đạt đúng bản chất hơn 500: phụ thuộc bắt buộc tạm không khả
  dụng, thử lại là an toàn vì đã rollback.

Đây là **yêu cầu bổ sung baseline**, không phải mã tự chế. Đề nghị BA thêm hàng 503
`SERVICE_DEPENDENCY_UNAVAILABLE` (hoặc tên tương đương) vào §8.2 ở bản SRS kế tiếp, với
ràng buộc "no partial write" giống hàng 500. Trước khi §8.2 được cập nhật, 503 chỉ áp cho
đúng điều kiện này của luồng import SKU.

Phân biệt với §6: 409 `CONCURRENCY_CONFLICT` là **tranh chấp nghiệp vụ** (có lần import
trùng định danh đang chạy); 503 ở đây là **sự cố khả dụng hạ tầng**. Hai tình huống khác
nhau nên không dùng chung một mã.

### 11.4 Ảnh hưởng mã nguồn (bổ sung cho §10)

| Tệp | Thay đổi |
|---|---|
| `Modules/Warehouse/app/Services/ImportReportWriter.php` | **Mới** — nơi duy nhất ghi `import_reports`; ném exception thay vì trả null; log không chứa giá trị ô dữ liệu (§13.8) |
| `Modules/Warehouse/app/Exceptions/ImportReportPersistenceException.php` | **Mới** |
| `Modules/Warehouse/app/Services/ImportService.php` | `withReport()` ghi báo cáo trong transaction; `findRecentDuplicateReport()` fail-closed khi không đọc được bảng |
| `Modules/Warehouse/app/Http/Controllers/ImportController.php` | Bỏ `persistReport()` (ghi sau khi transaction đã commit); bắt exception → 503 + `Retry-After`; OpenAPI công bố 503 và sửa `product.manage` → `sku.manage` |
| `docs/decision-records/DISP-IMP-224-sku-import-scope-mapping.json` | **Mới** — phạm vi SKU-only + mapping khoá theo field/type/required/default/owner (chờ BA/Data Owner duyệt tại WWHN-225) |
| `tests/Feature/Wwhn224P1ClosureTest.php` | **Mới** — 8 test: 4 ca bơm lỗi báo cáo, bất biến report id, 2 guard hợp đồng quyền, đối chiếu register |
| FE `src/modules/master-data/services/sku-import.service.ts` | **Mới** `SkuImportUnavailableError` — 503 mang khối `data` nên nếu không tách lớp lỗi, UI sẽ hiển thị "file có lỗi" cho một file hợp lệ |
