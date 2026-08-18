# DEC-DATA-001 — Tên entity `defect_catalogs` và các sửa đổi Data Dictionary của WWHN-310

| Thuộc tính | Giá trị |
| --- | --- |
| Ngày | 18/08/2026 |
| Ticket | WWHN-310 `[BE] Hoàn thiện tài liệu dữ liệu/migration/báo cáo` (parent WWHN-83) |
| Owner đề xuất | BE2 |
| Cần sign-off | Tech Lead (WWHN-31), BA/BAS (baseline ERD), QA (evidence) |
| Tài liệu bị ảnh hưởng | `docs/HoaNam_ERD_Moi_Nhat.md` (HN-WMS-ERD-DD-001), `docs/contracts/HoaNam_Current_Baseline_Contract.json` |
| Trạng thái | PROPOSED — đã hiện thực hoá trong code/migration, chờ sign-off tài liệu |

## 1. Bối cảnh

`HN-WMS-ERD-DD-001` mô tả 59 entity vật lý. 58 entity dùng tên số nhiều và trùng đúng tên bảng
canonical trên PostgreSQL. Riêng entity 15 được đặt tên `defect_catalog` (số ít). Trên PostgreSQL,
migration `2026_08_10_000791_retire_remaining_singular_legacy_tables` biến tên số ít thành
compatibility view, còn bảng vật lý canonical là `defect_catalogs` — chính là bảng mà
`Modules\Warehouse\Models\DefectCatalog`, `DefectCatalogController` và
`CurrentBaselineWarrantyController` đang đọc/ghi.

Hệ quả: cổng parity `ErdPhysicalSchemaParityTest` giải entity 15 về **view** thay vì kho lưu trữ
thật, nên mọi lệch của bảng thật đi lọt suốt thời gian dài.

## 2. Lệch đã đo được trên `defect_catalogs`

| # | Lệch | Ảnh hưởng |
| --- | --- | --- |
| 1 | Không có cột định danh `id`; PK vẫn là `defect_id` | Không tạo được FK theo quan hệ tài liệu; khác 58 entity còn lại |
| 2 | `warranty_cases.defect_id` KHÔNG có FK nào | Danh mục lỗi mồ côi vẫn ghi được vào ca bảo hành rồi chảy vào RPT-WAR-01/RPT-WAR-COMP-01 |
| 3 | `created_at`/`updated_at` là `timestamp without time zone` | ERD §0 quy định timestamptz; §13.10 chốt mọi báo cáo theo Asia/Ho_Chi_Minh |
| 4 | `version` DEFAULT 1 | Lệch baseline optimistic locking/If-Match của mọi master khác (DEFAULT 0) |
| 5 | `defect_code` varchar(50), `defect_name` varchar(255) | Data Dictionary ghi varchar(80)/varchar(200); API cũng đang validate 50/255 |
| 6 | `created_by`/`updated_by` không có FK; view `defect_catalog` chiếu hai cột thành `NULL` literal | Mất traceability actor khi đọc qua view — cùng nhóm lỗi view-layer đã xử lý ở `reversal_documents` |

## 3. Phương án đã cân nhắc

| Phương án | Nội dung | Đánh giá |
| --- | --- | --- |
| A | Đổi bảng vật lý thành `defect_catalog` (số ít), `defect_catalogs` thành view | Đảo ngược quy ước của 58 entity còn lại và của Eloquent; phải sửa model/controller/truy vấn; rủi ro cao, không thêm giá trị nghiệp vụ |
| B (chọn) | Đổi **tên entity trong ERD** thành `defect_catalogs`, giữ `defect_catalog` làm compatibility view, và đưa bảng thật về đúng field/constraint đã ghi trong Data Dictionary | Trùng cả runtime lẫn quy ước tài liệu; không đổi business semantics; mở đường cho FK và cổng parity kiểm đúng kho lưu trữ thật |

## 4. Quyết định

1. **ERD-AMD-005** — entity 15 đổi tên thành `defect_catalogs`. Tham chiếu
   `warranty_cases.defect_id → FK defect_catalogs.id` cập nhật theo. Field, constraint và semantics
   giữ nguyên. `defect_catalog` (số ít) tiếp tục tồn tại dưới dạng view đọc tương thích và nay
   chiếu đúng `created_by`/`updated_by` thay vì NULL literal.
2. **ERD-AMD-006** — ba mệnh đề `UNIQUE(...)` viết bằng bí danh văn xuôi (`document`, `request`)
   được viết lại bằng tên cột vật lý thật: `UNIQUE(inbound_document_id,line_no)`,
   `UNIQUE(outbound_request_id,line_no)`, `UNIQUE(component_issue_document_id,line_no)`. Đây đúng
   ràng buộc đã ghi ở cột "Index khuyến nghị" của cùng entity — không thêm/bớt ràng buộc nào.
3. Hình dạng vật lý của `defect_catalogs` được đưa về đúng Data Dictionary bằng migration
   `2026_08_18_193000_align_defect_catalogs_to_erd_entity_15`.
4. Validation API `defect_code`/`defect_name` đổi từ `max:50`/`max:255` sang `max:80`/`max:200`
   cho khớp Data Dictionary; OpenAPI cập nhật theo.

## 5. Hệ quả cần biết khi nghiệm thu

- `defect_catalogs.id` và `defect_catalogs.defect_id` luôn bằng nhau, được giữ đồng bộ bằng trigger
  `trg_erd_normalize_runtime_defect_catalogs` (cùng mẫu đã dùng cho `agencies`, `containers`,
  `item_instances`). Client cũ dùng `defect_id` không phải sửa gì.
- Bản ghi mới có `version = 0` thay vì `1`. Luồng If-Match không đổi vì luôn so với giá trị hiện
  tại do API trả về.
- `defect_name` dài hơn 200 ký tự sẽ bị API từ chối (422) thay vì được lưu. Migration **không tự
  cắt dữ liệu**: nếu tồn tại dòng dài hơn 200 ký tự, migration dừng fail-closed kèm thông điệp
  hướng dẫn xử lý.
- Trên SQLite (nhánh CI) `defect_catalogs` vẫn giữ khoá chính `defect_id` và không có cột `id`
  — đúng chủ trương đã ghi trong `2026_08_17_000100_materialize_erd_columns_on_sqlite`: không thêm
  cột định danh luôn NULL. Cổng parity vật lý chỉ chạy trên PostgreSQL.

## 6. Điều KHÔNG thay đổi

- Không đổi business rule của danh mục lỗi: `defect_code` vẫn immutable sau khi đã gắn vào
  warranty case; INACTIVE vẫn giữ lịch sử và không hard-delete.
- Không đổi permission (`defect_catalog.view` / `defect_catalog.manage` giữ nguyên tên).
- Không đổi contract của bất kỳ báo cáo nào trong §13.10.
