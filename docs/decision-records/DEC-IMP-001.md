# DEC-IMP-001 — Hạn mức, định danh idempotency và data-scope của import SKU

| Trường | Giá trị |
|---|---|
| Mã quyết định | `DEC-IMP-001` |
| Ngày lập | 18/08/2026 |
| Phạm vi | `POST /api/v1/skus/import-excel`, `GET /api/v1/skus/import-template`, `GET /api/v1/skus/import-reports[/{id}[/error-file]]` |
| Ticket | WWHN-330 (BE), WWHN-331 (FE), WWHN-332 (QA), task cha WWHN-89 |
| Đóng mục review | FE331-P1-02R3, FE331-P1-03R3, FE331-P1-06R3 |
| Người duyệt | BA/BAS + Tech Lead (comment sign-off trên WWHN-331) |
| Nguồn sự thật kỹ thuật | `config/warehouse.php` → khoá `warehouse.sku_import` |

Quyết định này thay thế trạng thái trước đó, khi bốn giá trị hạn mức nằm rải rác thành
hằng số trong ba lớp PHP khác nhau, mỗi chỗ kèm ghi chú "giá trị tạm, cần BA/Tech Lead
chốt", và FE chép lại chính những con số đó vào một Alert hiển thị cho người dùng cuối
kèm cả câu "chưa chốt chính thức".

---

## 1. Hạn mức đã chốt

| Hạng mục | Giá trị chốt | Khoá config | Nơi enforce |
|---|---:|---|---|
| Số dòng dữ liệu tối đa mỗi lần import (không tính header) | **1 000** | `max_rows` | `ImportService::readRows()` → 422 `SKU_IMPORT_ROW_LIMIT_EXCEEDED` |
| Kích thước file upload tối đa | **10 240 KB (10 MB)** | `max_file_size_kb` | rule `max:` của `ImportController::importExcel()` |
| Định dạng file chấp nhận | **.xlsx, .xls** | `allowed_extensions` | rule `mimes:` |
| Hạn tải file lỗi CSV | **24 giờ** kể từ lúc sinh | `error_file_expiry_hours` | `ImportErrorFileService::generate()`, `ImportReport::error_file_available` |
| Cửa sổ idempotent replay | **24 giờ** | `idempotency_window_hours` | `ImportService::findRecentDuplicateReport()` |

Căn cứ giữ nguyên 1 000 dòng: đo thực tế tại `tests/Feature/Wwhn330ImportPerformanceBenchmarkTest.php`
(kết quả thô ở `docs/evidence/WWHN-330/BENCHMARK_RESULT.md`) cho thấy dry-run 1 000 dòng
~217 ms và commit ~1,76 s trên môi trường test, đồng thời chứng minh cơ chế bounded-read:
file gấp 20 lần hạn mức bị từ chối mà peak memory chỉ tăng ~1,6 lần. Đây là giá trị vận
hành an toàn cho luồng nhập master data thủ công theo lô.

**Ranh giới cần nêu rõ:** NFR-SRS-017 (dry-run tối thiểu 10 000 dòng/file) thuộc luồng
**migration/opening balance** chạy bằng job/script có kiểm soát (REQ-SRS-021,
DEC-MIG-API-001), **không** áp cho endpoint import SKU tương tác này. Hai luồng có hạn mức
riêng là chủ ý, không phải mâu thuẫn.

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

Chọn 404 (không phải 403) cho bản ghi ngoài scope là chủ ý: 403 xác nhận "id này có tồn
tại nhưng bạn không được xem", tức là vẫn rò rỉ sự tồn tại. Với `warehouse_id` truyền vào
làm **tham số lọc** thì vẫn giữ 403 `ACCESS_DENIED` như WWHN-328 — hai ca khác nhau.

Nguồn scope lấy đúng `scope_ref` mà `EnforceInventoryWarehouseScope` và
`/reports/dashboard-quick` đang dùng, nên **không phát sinh permission mới** và không có
nguồn scope thứ hai để lệch nhau. SKU là master data toàn cục (ERD không có `warehouse_id`
trên SKU) nên không thể áp scope theo kho lên chính báo cáo; thứ cần bảo vệ là nội dung
file của người nộp.

`meta.data_scope` (`GLOBAL` | `ACTOR_SCOPED`) được trả trong response danh sách để QA/BA
đọc được phạm vi đang áp dụng ngay trên response.

---

## 4. Công bố ra API (bỏ hằng số ở client)

Khối `constraints` được trả trong:

- `data.constraints` của **mọi** response `POST /skus/import-excel` (200, 422 row lỗi, 422
  vượt hạn mức, 422 file hỏng, và ca duplicate);
- `meta.constraints` của `GET /skus/import-reports` — endpoint đọc duy nhất mà màn hình
  import gọi được **trước** khi người dùng chọn file.

```json
{
  "decision_ref": "DEC-IMP-001",
  "max_rows": 1000,
  "max_file_size_kb": 10240,
  "max_file_size_mb": 10,
  "allowed_extensions": ["xlsx", "xls"],
  "error_file_expiry_hours": 24,
  "idempotency_window_hours": 24,
  "idempotency_identity": ["file_sha256", "actor_id", "update_existing"]
}
```

**Không mở route mới cho việc này là chủ ý.** `/skus/import-excel`, `/skus/import-template`
và ba route Import báo cáo vốn đã nằm ngoài 67 operation Annex C (REQ-SRS-034,
DEC-MIG-API-001); thêm một endpoint công khai nữa là tự mở rộng bề mặt API ngoài baseline
đã duyệt. Hợp đồng vì vậy đi kèm các operation sẵn có.

FE hiển thị đúng khối này; khi BE không trả `constraints`, UI mô tả chung ("giới hạn do máy
chủ quy định") thay vì đoán một con số có thể đã lỗi thời.

---

## 5. Ảnh hưởng mã nguồn

| Tệp | Thay đổi |
|---|---|
| `config/warehouse.php` | Thêm khối `sku_import` — nguồn sự thật duy nhất |
| `Modules/Warehouse/app/Support/SkuImportContract.php` | Mới: đọc config, dựng `constraints`, tính `options_fingerprint`, giải data-scope |
| `Modules/Warehouse/app/Services/ImportService.php` | 4 hằng số → đọc config; định danh replay + khoá chống đua gồm `options_fingerprint` |
| `Modules/Warehouse/app/Services/ImportErrorFileService.php` | Hạn dùng đọc từ hợp đồng (hằng số cũ để `@deprecated`) |
| `Modules/Warehouse/app/Http/Controllers/ImportController.php` | Rule upload theo hợp đồng; mọi response mang `constraints`; lưu `options_fingerprint` |
| `Modules/Warehouse/app/Http/Controllers/ImportReportController.php` | `scopedQuery()` áp data-scope cho cả 3 route; `meta.constraints` + `meta.data_scope` |
| `Modules/Warehouse/app/Models/ImportReport.php` | `options_fingerprint` vào `$fillable` |
| `Modules/Warehouse/database/migrations/2026_08_18_190000_*` | Cột `options_fingerprint` + index tra cứu replay |
| `tests/Feature/Wwhn331ImportContractClosureTest.php` | Mới: 8 test khoá cả ba mục |
| `tests/Feature/Wwhn330ImportResidualHardeningTest.php` | Cập nhật khoá chống đua theo định danh mới |
