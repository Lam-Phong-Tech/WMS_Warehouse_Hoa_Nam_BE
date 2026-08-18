# 03 — Danh mục báo cáo (SRS §13.10 → endpoint)

Mọi báo cáo đọc từ ledger authoritative `stock_movements` và projection `inventory_balances`
(DEC-INV-001). Kỳ báo cáo tính theo **Asia/Ho_Chi_Minh**, hai đầu inclusive. Chỉ document
**POSTED** mới ảnh hưởng số liệu; DRAFT/SCANNING/CANCELLED là stock-neutral và không có partial
posting/remainder closure.

## 1. Bảng đối chiếu

| Mã §13.10 | Endpoint | Permission | Filter chính | Export |
| --- | --- | --- | --- | --- |
| RPT-INV-01 Tồn kho hiện tại | `GET /api/v1/reports/inventory-balance` | `report.view` | `warehouse_id`, `product_id`, `category_id`, `stock_status`, `as_of`, `keyword` | `/export-excel` (`report.export`) |
| RPT-XNT-01 Xuất-Nhập-Tồn | `GET /api/v1/reports/inventory-xnt` (+ alias `/inventory-xnt-enhanced`) | `report.view` | `from_date`*, `to_date`*, `warehouse_id`, `product_id`, `category_id` | `/export-excel` |
| RPT-IO-01 Lịch sử phiếu nhập/xuất | `GET /api/v1/reports/document-history` | `report.view` | `from_date`, `to_date`, `doc_type`, `status`, `product_id`, `agency_id` | `/export-excel` (bung theo dòng) |
| RPT-MOV-01 Lịch sử nhập/xuất/Reversal | `GET /api/v1/reports/movement-trace` | `report.view` | `from_date`, `to_date`, `warehouse_id`, `product_id`, `item_id`, `code_value`, `source_type`, `source_id`, `movement_type` | `/export-excel` |
| RPT-MOV-01 (sổ ledger) | `GET /api/v1/reports/inventory-ledger` | `report.view` | như movement-trace | qua `POST /reports/export` |
| RPT-RECIPIENT-01 Xuất theo nơi nhận | `GET /api/v1/reports/outbound-by-agency` | `report.view` | `from_date`, `to_date`, `agency_id`, `product_id` | `/export-excel` |
| RPT-OUT-MATCH-01 Đối chiếu toa xuất | `GET /api/v1/reports/import-match` | `report.view` | request/date/SKU/`error_status` | `/export-excel` |
| RPT-LABEL-01 Nhật ký in nhãn | `GET /api/v1/reports/label-log` | `report.view` | date, printer, result, `actor_id` | `/export-excel` |
| RPT-WAR-01 + RPT-WAR-COMP-01 Bảo hành / linh kiện | `GET /api/v1/reports/warranty-defects` | `report.view` | `from_date`, `to_date`, `status`, `defect_id`, `model`, `identification_status` | `/export-excel` (PII cần `warranty.pii.export` + `reason`) |

`*` bắt buộc.

Ngoài ra:

- `GET /api/v1/reports/hub` — danh sách báo cáo cho menu UI (8 mục). **`inventory-ledger` cố ý
  không nằm trong hub**: nó là lát cắt kỹ thuật cùng contract với RPT-MOV-01 cho đối soát ledger,
  không phải một mục menu riêng. `GET /reports/{report_code}` vẫn nhận `inventory-ledger`.
- `GET /api/v1/reports/{report_code}` — dispatcher Annex C, `report_code` lạ trả 404
  `RESOURCE_NOT_FOUND` kèm danh sách hợp lệ. Route này là catch-all nên **phải đăng ký sau** mọi
  route `reports/*` cụ thể.
- `POST /api/v1/reports/export` — tạo job xuất, **bắt buộc `Idempotency-Key`**; retry cùng key trả
  lại đúng job cũ kèm header `Idempotent-Replay: true`. `filters` trong body được merge vào input
  của builder; audit ghi lại `report_code`/`format`/`filters`.
- `GET /api/v1/reports/inventory-reconciliation` — 4 phép đối soát: ledger↔balance, serial
  subledger, quantity subledger, container integrity; trả `reconciliation_overall`.
- `GET /api/v1/reports/dashboard-quick` — KPI tổng hợp một lần gọi, chịu đúng warehouse data-scope
  của người gọi (`data_scope` = GLOBAL/WAREHOUSE_SCOPED). Ngoại lệ đã ghi nhận:
  `warranty_cases_by_status` luôn GLOBAL vì ERD `warranty_cases` không có `warehouse_id`.

## 2. Quy tắc tính đã hiện thực hoá

- **RPT-XNT-01**: `closing = opening + posted receipt − posted issue − component issue ± posted
  reversal`. Không có partial posting nên không có khái niệm số dư dở dang.
- **RPT-INV-01**: `as_of` inclusive. Khi `as_of` ở quá khứ, báo cáo là **ảnh chụp theo ledger** và
  `reconciliation_status = NOT_APPLICABLE` (trước đây mọi SKU có phát sinh sau `as_of` bị báo
  MISMATCH giả). `location_hint` chỉ để hiển thị, không tham gia `available_qty` (DR-LOC-001).
- **RPT-MOV-01**: hiển thị gross movement + reversal chain + net effect; **không bao giờ ẩn
  movement gốc**. `movement_id` là cột đầu của file xuất để truy ngược ledger.
- **RPT-IO-01**: mỗi phiếu kèm `rows[].lines[]` với expected/scanned/posted; `posted` lấy từ
  movement authoritative chứ không lấy từ cột trên dòng chứng từ.
- **RPT-OUT-MATCH-01**: trả **tầng dòng** (`source_request_no`, `source_version`, `sku`,
  `required/matched/scanned/posted`, `validation_status`); batch chưa parse được dòng nào vẫn sinh
  một dòng fallback (`row_no = null`) để không mất evidence.
- **RPT-RECIPIENT-01**: `agency` chỉ là chiều nơi nhận, **không bao giờ là chủ tồn**;
  `contact_masked` chỉ hiện đầy đủ khi actor có `warranty.pii.view`.
- **RPT-LABEL-01**: in nhãn là stock-neutral; mỗi outbound/lô có đúng một snapshot immutable kèm
  các print event. Định nghĩa ba cột đếm (WWHN-310): `total_attempts` = tổng số print event;
  `failed_count` = số event có status `FAILED`/`PRINT_FAILED`; `reprint_count` = số lần in **thành
  công** trừ một — retry sau khi in lỗi thuộc `failed_count`, không phải in lại. `attempt_no` và
  `event_type` (`PRINT`/`REPRINT`) được suy từ lịch sử của chính nhãn đó thay vì gán cứng.
  Bộ giá trị status của **sự kiện in** là `PENDING`/`SENT`/`PRINTED`/`FAILED` (ERD bảng 44), khác
  với bộ vòng đời của **nhãn** trên `label_snapshots`
  (`DRAFT`/`PREVIEWED`/`READY_TO_PRINT`/`PENDING_PRINT`/`PRINTED`/`PRINT_FAILED`) — không được áp
  CHECK của bảng này lên bảng kia.
- **RPT-WAR-01/COMP-01**: `customer_name/phone/address` **luôn có mặt nhưng đã mask**
  (`pii_masked = true`). Export ra PII thô yêu cầu `warranty.pii.export` **và** query `reason`
  ≥ 5 ký tự; `reason` được ghi vào audit (`pii_reason`) và bị loại khỏi `filters`. Có `reason`
  nhưng thiếu quyền ⇒ 403. TEMP_ONLY đếm riêng, không suy diễn liên kết Item.
- Mọi file xuất XLSX/PDF gắn dấu thời gian `-Ymd-His` (BR-EXP-03) qua
  `App\Support\ReportExportFileName::stamp()`; `Content-Disposition` được expose trong CORS.

## 3. Điểm cần biết khi đối soát

- Số liệu báo cáo phải khớp ledger: `Sum authoritative movements = posted/issued qty`. Lệch thì
  RPT-INV-01 báo `reconciliation_status = MISMATCH` và `dashboard-quick` đếm trong
  `reconciliation_warning_count` (danh sách mẫu tối đa 50 dòng; xem đầy đủ ở
  `/reports/inventory-balance`).
- `movement-trace` và `inventory-ledger` phân trang ở **tầng SQL**; summary được aggregate riêng
  trên toàn kỳ nên tổng không phụ thuộc trang đang xem.
- Báo cáo không phải là nơi sửa dữ liệu: không endpoint báo cáo nào ghi vào ledger hay balance.
- Contract Swagger của nhóm báo cáo chỉ đúng khi 4 controller báo cáo nằm trong danh sách
  `annotations` của `config/l5-swagger.php` — thiếu là mọi `#[OA\...]` không được quét và endpoint
  publish với `parameters: []`. Có test guard chống hồi quy cấu hình này.
