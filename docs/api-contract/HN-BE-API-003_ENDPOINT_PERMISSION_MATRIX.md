# HN-BE-API-003 - Ma trận endpoint / permission / OpenAPI (API v1)

> **Tệp sinh tự động - không sửa tay.** Nguồn: `php artisan route:list --json` + `storage/api-docs/api-docs.json`.
> Chạy lại: `node scripts/generate-endpoint-permission-matrix.mjs`. CI chạy `--check` và fail khi lệch.

Task: WWHN-309 - [BE] Hoàn thiện tài liệu kiến trúc/API/vận hành.

## 1. Tổng quan

| Chỉ số | Giá trị |
| --- | --- |
| Số operation `/api/v1` | 231 |
| Số permission code đang gác route | 57 |
| Operation có mặt trong OpenAPI | 231/231 |
| Operation có optimistic locking If-Match | 58 |
| Operation bị siết data scope theo kho | 53 |
| Endpoint public (không cần JWT) | 9 |

Endpoint public được allowlist tường minh trong generator:

- `GET /api/v1/health`
- `GET /api/v1/pv/catalog`
- `GET /api/v1/pv/industries`
- `GET /api/v1/pv/products`
- `GET /api/v1/pv/products/{skuId}`
- `GET /api/v1/pv/recommendations`
- `GET /api/v1/pv/tags`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/refresh`

Thêm route public mới mà không khai vào allowlist sẽ làm generator fail - đây là contract bảo mật, không phải tài liệu mô tả.

## 2. Ma trận theo nhóm nghiệp vụ

### 00. Platform

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/health` | _public_ | - | - | Public readiness health check |

### 01. Authentication

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| POST | `/api/v1/auth/change-password` | _chỉ JWT_ | - | - | Đổi mật khẩu tài khoản hiện tại (thu hồi toàn bộ refresh token) |
| POST | `/api/v1/auth/login` | _public_ | - | - | Đăng nhập bằng email và password |
| POST | `/api/v1/auth/logout` | _chỉ JWT_ | - | - | Đăng xuất, thu hồi refresh token và huỷ access token hiện tại |
| GET | `/api/v1/auth/me` | _chỉ JWT_ | - | - | Thông tin tài khoản, role, scope và permission hiện hành |
| POST | `/api/v1/auth/refresh` | _public_ | - | - | Xoay vòng refresh token từ JSON hoặc HttpOnly browser cookie |
| POST | `/api/v1/auth/switch-context` | _chỉ JWT_ | - | - | Chuyển ngữ cảnh (warehouse scope) và cấp lại access token mới |

### 02. Users

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/users` | `iam.user.view` | - | - | Danh sách người dùng (phân trang, filter theo keyword/status) |
| POST | `/api/v1/users` | `iam.user.manage` | - | - | Tạo người dùng mới |
| GET | `/api/v1/users/{id}` | `iam.user.view` | - | - | Chi tiết người dùng |
| PATCH | `/api/v1/users/{id}` | `iam.user.manage` | có | - | Sửa người dùng |
| POST | `/api/v1/users/{id}/roles` | `iam.user.manage` | - | - | Gán danh sách role cho người dùng (thay thế toàn bộ role hiện có) |

### 03. Roles

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/roles` | `iam.role.view` | - | - | Danh sách role (phân trang, filter theo keyword/status/scope_type) |
| POST | `/api/v1/roles` | `iam.role.manage` | - | - | Tạo role mới |
| DELETE | `/api/v1/roles/{id}` | `iam.role.manage` | - | - | Xoá role (bị chặn nếu đang gán cho user) |
| GET | `/api/v1/roles/{id}` | `iam.role.view` | - | - | Chi tiết role |
| PATCH | `/api/v1/roles/{id}` | `iam.role.manage` | - | - | Sửa role |
| POST | `/api/v1/roles/{id}/permissions` | `iam.role.manage` | - | - | Gán danh sách permission cho role (thay thế toàn bộ permission hiện có) |
| PUT | `/api/v1/roles/{id}/permissions` | `iam.role.manage` | - | - | Thay thế toàn bộ tập permission của role (Annex C replaceRolePermissions) |

### 04. Permissions

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/permissions` | `iam.permission.view` | - | - | Danh sách permission (phân trang, filter theo keyword/status/scope_type) |
| GET | `/api/v1/permissions/{id}` | `iam.permission.view` | - | - | Chi tiết permission |

### 05. Product

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/product-masters` | `catalog.view` | - | - | Danh sách product master (phân trang, filter theo keyword/master_type/status) |
| POST | `/api/v1/product-masters` | `catalog.manage` | - | - | Tạo product master mới |
| GET | `/api/v1/product-masters/{id}` | `catalog.view` | - | - | Chi tiết product master |
| PATCH | `/api/v1/product-masters/{id}` | `catalog.manage` | - | - | Sửa product master |
| GET | `/api/v1/products` | `sku.view` | - | - | Danh sách SKU (phân trang, filter theo keyword/status) |
| POST | `/api/v1/products` | `sku.manage` | - | - | Tạo SKU mới qua product compatibility façade |
| GET | `/api/v1/products/{id}` | `sku.view` | - | - | Chi tiết SKU |
| PATCH | `/api/v1/products/{id}` | `sku.manage` | có | - | Sửa SKU (brand/category/model/package/specification...) |
| PATCH | `/api/v1/products/{id}/disable` | `sku.manage` | - | - | Ngừng kích hoạt SKU |
| PATCH | `/api/v1/products/{id}/enable` | `sku.manage` | - | - | Kích hoạt SKU |
| GET | `/api/v1/skus` | `sku.view` | - | - | Danh sách SKU |
| POST | `/api/v1/skus` | `sku.manage` | - | - | Tạo SKU mới (governance aggregate) |
| GET | `/api/v1/skus/{id}` | `sku.view` | - | - | Chi tiết SKU |
| PATCH | `/api/v1/skus/{id}` | `sku.manage` | có | - | Sửa SKU (optimistic locking bằng If-Match version) |
| POST | `/api/v1/skus/{id}/physical-codes` | `physical_code.assign_rfid` | - | - | Gán định danh vật lý (physical code) cho item của SKU, stock-neutral |
| DELETE | `/api/v1/skus/{id}/physical-codes/{physicalCodeId}` | `physical_code.assign_rfid` | có | - | Xoá SKU |
| POST | `/api/v1/skus/import-excel` | `sku.manage` | - | - | Import SKUs từ Excel theo cơ chế all-or-nothing, hỗ trợ dry-run |
| GET | `/api/v1/skus/import-reports` | `sku.manage` | - | - | Danh sách Import báo cáo SKU (dry-run và commit) — WWHN-224 |
| GET | `/api/v1/skus/import-reports/{id}` | `sku.manage` | - | - | Chi tiết một Import báo cáo SKU — WWHN-224 |
| GET | `/api/v1/skus/import-reports/{id}/error-file` | `sku.manage` | - | - | Tải file lỗi (CSV) của một Import báo cáo SKU — WWHN-330 |
| GET | `/api/v1/skus/import-template` | `sku.manage` | - | - | Tải template Excel SKU chuẩn WWHN-172 |

### 06. Warehouse

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/warehouses` | `warehouse.view` | - | - | Danh sách kho theo data scope hiện hành |
| POST | `/api/v1/warehouses` | `warehouse.manage` | - | - | Tạo kho tổng (kho ACTIVE thứ hai bị chặn 409) |
| GET | `/api/v1/warehouses/{id}` | `warehouse.view` | - | - | Chi tiết kho trong data scope |
| PATCH | `/api/v1/warehouses/{id}` | `warehouse.manage` | - | - | Sửa tên/địa chỉ/thông tin liên hệ kho trong data scope |

### 07. Inventory

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/document-status-history` | `inventory.view` | - | - | Lịch sử chuyển trạng thái chứng từ (inbound/outbound documents) |
| GET | `/api/v1/inventory` | `inventory.view` | - | có | Annex C queryInventory — tồn kho theo SKU/stock_status kèm đối soát ledger authoritative |
| GET | `/api/v1/inventory/balances` | `inventory.view` | - | có | Tồn kho hiện tại theo kho và SKU |
| GET | `/api/v1/inventory/balances/{id}/subledger` | `inventory.view` | - | có | Subledger drill-down: item_instances + container_items cho một balance |
| GET | `/api/v1/inventory/trace/{code}` | `inventory.trace` | - | có | Truy vết tồn kho theo mã vật lý (Annex C) |
| GET | `/api/v1/reversal-documents` | `inventory.view` | - | có | Danh sách phiếu reversal và correction |
| POST | `/api/v1/reversal-documents` | `inventory.reversal.post` | có | có | Tạo Reversal document từ chứng từ gốc (RECEIPT/ISSUE/COMPONENT_ISSUE) |
| GET | `/api/v1/reversal-documents/{id}` | `inventory.view` | - | có | Chi tiết phiếu reversal và correction chain |
| POST | `/api/v1/reversal-documents/{id}/cancel` | `inventory.reversal.post` | có | có | Huỷ Reversal khi còn DRAFT (stock-neutral) |
| POST | `/api/v1/reversal-documents/{id}/confirm` | `inventory.reversal.post` | có | có | Xác nhận hàng vật lý quay lại kho cho Reversal (stock-neutral) |
| GET | `/api/v1/reversal-documents/{id}/lines` | `inventory.view` | - | có | Danh sách dòng movement được reversal |
| POST | `/api/v1/reversal-documents/{id}/post` | `inventory.reversal.post` | có | có | Post Reversal và ghi movement append-only |
| GET | `/api/v1/stock-movements` | `inventory.view` | - | có | Lịch sử biến động tồn (phân trang, filter theo warehouse_id/product_id/movement_type) |
| GET | `/api/v1/stock-movements/{id}` | `inventory.view` | - | có | Chi tiết 1 movement |
| GET | `/api/v1/stock-movements/{id}/trace-source` | `inventory.trace` | - | có | Truy vết chứng từ gốc, correction chain đầy đủ và net effect của một movement |
| GET | `/api/v1/stock-movements/reversible` | `inventory.reversal.post` | - | có | Lookup posted movements that can still be reversed, with dependency guard preflight |
| GET | `/api/v1/stock-movements/trace-by-code` | `inventory.trace` | - | có | Truy vết từ mã vật lý (physical code) → item/container/SKU → movement chain |

### 08. Catalog Master

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/brands` | `catalog.view` | - | - | Danh sách thương hiệu |
| POST | `/api/v1/brands` | `catalog.manage` | - | - | Tạo brand mới (catalog master) |
| PATCH | `/api/v1/brands/{id}` | `catalog.manage` | có | - | Sửa brand (deactivate thay vì xoá; mã immutable sau khi SKU tham chiếu) |
| GET | `/api/v1/catalog-masters/{masterType}` | `catalog.view` | - | - | Danh sách catalog master theo loại (Annex C) |
| POST | `/api/v1/catalog-masters/{masterType}` | `catalog.manage` | - | - | Tạo catalog master entry theo master_type (Annex C createCatalogMasterEntry) |
| PATCH | `/api/v1/catalog-masters/{masterType}/{id}` | `catalog.manage` | có | - | Sửa catalog master entry theo master_type (Annex C updateCatalogMasterEntry) |
| GET | `/api/v1/package-variants` | `catalog.view` | - | - | Danh sách quy cách đóng gói |
| POST | `/api/v1/package-variants` | `catalog.manage` | - | - | Tạo package variant mới (catalog master) |
| PATCH | `/api/v1/package-variants/{id}` | `catalog.manage` | có | - | Sửa package variant (deactivate thay vì xoá; mã immutable sau khi SKU tham chiếu) |
| GET | `/api/v1/power-sources` | `catalog.view` | - | - | Danh sách nguồn điện |
| POST | `/api/v1/power-sources` | `catalog.manage` | - | - | Tạo power source mới (catalog master) |
| PATCH | `/api/v1/power-sources/{id}` | `catalog.manage` | có | - | Sửa power source (deactivate thay vì xoá; mã immutable sau khi SKU tham chiếu) |
| GET | `/api/v1/product-categories` | `catalog.view` | - | - | Danh sách danh mục sản phẩm |
| POST | `/api/v1/product-categories` | `catalog.manage` | - | - | Tạo product category mới (catalog master) |
| PATCH | `/api/v1/product-categories/{id}` | `catalog.manage` | có | - | Sửa product category (deactivate thay vì xoá; mã immutable sau khi SKU tham chiếu) |
| GET | `/api/v1/product-models` | `catalog.view` | - | - | Danh sách model sản phẩm |
| POST | `/api/v1/product-models` | `catalog.manage` | - | - | Tạo product model mới (catalog master; brand tham chiếu phải ACTIVE) |
| PATCH | `/api/v1/product-models/{id}` | `catalog.manage` | có | - | Sửa product model (deactivate thay vì xoá; mã immutable sau khi SKU tham chiếu) |

### 09. Stock In (Inbound)

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/inbound-documents` | `inbound.view` | - | có | Danh sách phiếu nhập kho (phân trang, tìm kiếm, lọc trạng thái) |
| POST | `/api/v1/inbound-documents` | `inbound.create` | - | có | Tạo phiếu nhập kho DRAFT |
| GET | `/api/v1/inbound-documents/{id}` | `inbound.view` | - | có | Chi tiết phiếu nhập kho và các dòng Expected/Scanned |
| PATCH | `/api/v1/inbound-documents/{id}` | `inbound.create` | có | có | Sửa phiếu DRAFT/SCANNING và hiệu chỉnh Expected an toàn |
| POST | `/api/v1/inbound-documents/{id}/boxes` | `inbound.scan` | - | có | Đăng ký BOX cho dòng nhập SKU COMPONENT (stock-neutral, tái sử dụng BOX EMPTY hợp lệ) |
| POST | `/api/v1/inbound-documents/{id}/cancel` | `inbound.cancel` hoặc `inbound.create` | có | có | Cancel phiếu DRAFT/SCANNING |
| POST | `/api/v1/inbound-documents/{id}/post-receipt` | `inbound.post` | có | có | Full Post Receipt SCANNING -> POSTED |
| POST | `/api/v1/inbound-documents/{id}/reversals` | `inventory.reversal.post` | có | có | Tạo reversal cho phiếu nhập POSTED (chứng từ gốc giữ nguyên POSTED) |
| POST | `/api/v1/inbound-documents/{id}/reverse` | `inventory.reversal.post` | có | có | Reversal/Correction cho phiếu nhập POSTED |
| POST | `/api/v1/inbound-documents/{id}/scan` | `inbound.scan` | có | có | Quét hàng cho phiếu DRAFT/SCANNING (stock-neutral) |
| GET | `/api/v1/inbound-documents/{id}/scan-entries` | `inbound.view` | - | có | Danh sách mã đã quét của phiếu nhập |
| POST | `/api/v1/inbound-documents/{id}/scans` | `inbound.scan` | có | có | Quét hàng vào phiếu nhập DRAFT/SCANNING (stock-neutral, chưa đổi tồn) |
| POST | `/api/v1/inbound-documents/{id}/unscan` | `inbound.scan` | có | có | Gỡ một dòng đã quét khỏi phiếu DRAFT/SCANNING (stock-neutral) |

### 10. Stock Out (Outbound)

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/outbound-documents` | `outbound.view` | - | có | Danh sách phiếu xuất kèm tiến độ matching/packing label |
| GET | `/api/v1/outbound-documents/{id}` | `outbound.view` | - | có | Chi tiết phiếu xuất |
| POST | `/api/v1/outbound-documents/{id}/cancel` | `outbound.cancel` | có | có | Huỷ phiếu xuất DRAFT/SCANNING (terminal, stock-neutral; toa nguồn được trả về OPEN nếu không còn phiếu active) |
| PUT | `/api/v1/outbound-documents/{id}/items/{itemId}/rfid` | `physical_code.assign_rfid` | - | có | Gắn RFID cho item đã match trong phiếu xuất (stock-neutral, idempotent theo hardware_uid) |
| POST | `/api/v1/outbound-documents/{id}/packing-labels/{labelId}/preview` | `packing_label.preview` hoặc `label.print` | có | có | Preview Packing Label trước khi hoàn tất |
| POST | `/api/v1/outbound-documents/{id}/packing-labels/{labelId}/print` | `packing_label.print` hoặc `label.print` | có | có | In Packing Label của phiếu xuất (Annex C printPackingLabel; READY_TO_PRINT/PRINT_FAILED → PRINTED) |
| POST | `/api/v1/outbound-documents/{id}/packing-labels/{labelId}/ready` | `packing_label.ready` hoặc `label.print` | có | có | Đóng băng Packing Label sẵn sàng in |
| POST | `/api/v1/outbound-documents/{id}/packing-labels/{labelId}/reprint` | `packing_label.reprint` hoặc `label.reprint` | có | có | In lại Packing Label đã PRINTED của phiếu xuất (Annex C reprintPackingLabel; stock-neutral) |
| POST | `/api/v1/outbound-documents/{id}/post-issue` | `outbound.post` | có | có | Ghi nhận xuất kho cho phiếu xuất |
| POST | `/api/v1/outbound-documents/{id}/reversals` | `inventory.reversal.post` | có | có | Tạo reversal cho phiếu xuất POSTED (chứng từ gốc giữ nguyên POSTED) |
| POST | `/api/v1/outbound-documents/{id}/reverse` | `inventory.reversal.post` | có | có | Hoàn tác xuất kho cho phiếu xuất |
| POST | `/api/v1/outbound-documents/{id}/scan` | `outbound.scan` | có | có | Quét hàng cho phiếu xuất |
| POST | `/api/v1/outbound-documents/{id}/scans` | `outbound.scan` | có | có | Quét & match item vào dòng phiếu xuất (stock-neutral; full match tự tạo 01 Packing Label DRAFT) |
| POST | `/api/v1/outbound-documents/{id}/unmatch` | `outbound.scan` | - | có | Gỡ item đã match khỏi phiếu xuất trước Post (match ACTIVE → REMOVED, stock-neutral) |
| GET | `/api/v1/outbound-request-imports` | `outbound.request.import` hoặc `outbound.import` | - | - | Danh sách import yêu cầu xuất |
| POST | `/api/v1/outbound-request-imports` | `outbound.request.import` hoặc `outbound.import` | - | - | Upload/validate toa yêu cầu xuất (Excel hoặc rows chuẩn hoá) |
| GET | `/api/v1/outbound-request-imports/{id}` | `outbound.request.import` hoặc `outbound.import` | - | - | Chi tiết import yêu cầu xuất |
| POST | `/api/v1/outbound-request-imports/{id}/confirm` | `outbound.request.confirm` hoặc `outbound.import` | có | - | Xác nhận (consume) import toa yêu cầu xuất — sinh toa CONSUMED + phiếu xuất DRAFT |
| POST | `/api/v1/outbound-request-imports/{id}/consume` | `outbound.request.confirm` hoặc `outbound.import` | có | - | Tiêu thụ dữ liệu import yêu cầu xuất |
| GET | `/api/v1/outbound-request-imports/{id}/errors` | `outbound.request.import` hoặc `outbound.import` | - | - | Danh sách lỗi row/field của import yêu cầu xuất |
| GET | `/api/v1/outbound-request-imports/{id}/preview` | `outbound.request.import` hoặc `outbound.import` | - | - | Preview kết quả validate import yêu cầu xuất |
| POST | `/api/v1/outbound-request-imports/manual` | `outbound.request.manual_create` hoặc `outbound.import` | - | - | Import toa xuất thủ công (file Excel xlsx/xls hoặc rows JSON đã chuẩn hoá) |
| GET | `/api/v1/outbound-request-imports/template` | `outbound.request.import` hoặc `outbound.import` | - | - | Tải file mẫu Excel toa yêu cầu xuất |
| GET | `/api/v1/outbound-requests` | `outbound.view` | - | - | Danh sách toa yêu cầu xuất (phân trang, filter status/keyword) |
| GET | `/api/v1/outbound-requests/{id}` | `outbound.view` | - | - | Chi tiết toa yêu cầu xuất kèm lines và outbound documents liên kết |

### 11. Scan

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| POST | `/api/v1/scan-batches/classify` | `scan.execute` hoặc `scan.classify` | - | - | Phân loại lô mã quét (Annex C) |
| GET | `/api/v1/scan/batches` | `inventory.view` | - | - | Danh sách lô quét (scan evidence) |
| POST | `/api/v1/scan/classify` | `scan.execute` hoặc `scan.classify` | - | - | Phân loại và validate mã scan, không thay đổi tồn kho |
| GET | `/api/v1/scan/events` | `inventory.view` | - | - | Danh sách sự kiện quét (scan evidence) |

### 12. Dashboard / Report

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/reports/{report_code}` | `report.view` | - | - | Annex C getReportByCode — trả báo cáo theo mã |
| GET | `/api/v1/reports/dashboard-quick` | `report.view` | - | - | Số liệu nhanh cho dashboard (WWHN-328: endpoint tổng hợp KPI, tái sử dụng — không tạo route mới) |
| GET | `/api/v1/reports/document-history` | `report.view` | - | - | RPT-IO-01 lịch sử phiếu nhập/xuất kèm tầng dòng |
| GET | `/api/v1/reports/document-history/export-excel` | `report.export` | - | - | Xuất XLSX/PDF lịch sử phiếu (bung theo dòng) |
| POST | `/api/v1/reports/export` | `report.export` | - | - | Annex C createReportExport — tạo job xuất báo cáo |
| GET | `/api/v1/reports/hub` | `report.view` | - | - | Danh mục báo cáo vận hành Flow 06 |
| GET | `/api/v1/reports/import-match` | `report.view` | - | - | RPT-OUT-MATCH-01 đối soát toa yêu cầu xuất |
| GET | `/api/v1/reports/import-match/export-excel` | `report.export` | - | - | Xuất XLSX/PDF đối soát toa |
| GET | `/api/v1/reports/inventory-balance` | `report.view` | - | - | RPT-INV-01 tồn kho hiện tại kèm đối soát ledger |
| GET | `/api/v1/reports/inventory-balance/export-excel` | `report.export` | - | - | Xuất XLSX/PDF tồn kho hiện tại |
| GET | `/api/v1/reports/inventory-ledger` | `report.view` | - | - | Annex C getInventoryLedgerReport — sổ ledger authoritative |
| GET | `/api/v1/reports/inventory-reconciliation` | `report.view` | - | - | Đối soát tồn kho với sổ giao dịch |
| GET | `/api/v1/reports/inventory-xnt` | `report.view` | - | - | RPT-XNT-01 Nhập-Xuất-Tồn theo authoritative ledger |
| GET | `/api/v1/reports/inventory-xnt-enhanced` | `report.view` | - | - | RPT-XNT-01 Nhập-Xuất-Tồn chi tiết theo movement type |
| GET | `/api/v1/reports/inventory-xnt-enhanced/export-excel` | `report.export` | - | - | Xuất XLSX/PDF Nhập-Xuất-Tồn chi tiết |
| GET | `/api/v1/reports/inventory-xnt/export-excel` | `report.export` | - | - | Xuất XLSX/PDF Nhập-Xuất-Tồn |
| GET | `/api/v1/reports/label-log` | `report.view` | - | - | RPT-LABEL-01 nhật ký nhãn Packing Label |
| GET | `/api/v1/reports/label-log/export-excel` | `report.export` | - | - | Xuất XLSX/PDF nhật ký nhãn |
| GET | `/api/v1/reports/movement-trace` | `report.view` | - | - | RPT-MOV-01 lịch sử nhập/xuất/Reversal |
| GET | `/api/v1/reports/movement-trace/export-excel` | `report.export` | - | - | Xuất XLSX/PDF movement trace |
| GET | `/api/v1/reports/outbound-by-agency` | `report.view` | - | - | RPT-RECIPIENT-01 xuất kho theo nơi nhận |
| GET | `/api/v1/reports/outbound-by-agency/export-excel` | `report.export` | - | - | Xuất XLSX/PDF báo cáo theo nơi nhận |
| GET | `/api/v1/reports/warranty-defects` | `report.view` | - | - | Annex C getWarrantyDefectReport — RPT-WAR-01/RPT-WAR-COMP-01 |
| GET | `/api/v1/reports/warranty-defects/export-excel` | `report.export` | - | - | Xuất XLSX/PDF báo cáo bảo hành & linh kiện |

### 13. Warranty

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| DELETE | `/api/v1/warranty-attachments/{attachmentId}` | `warranty.manage` | - | - | Xoá (logical) tệp đính kèm bảo hành |
| GET | `/api/v1/warranty-attachments/{attachmentId}/download` | `warranty.view` hoặc `warranty.manage` | - | - | Tải xuống tệp đính kèm bảo hành |
| GET | `/api/v1/warranty-cases` | `warranty.view` hoặc `warranty.manage` | - | - | Danh sách hồ sơ bảo hành |
| POST | `/api/v1/warranty-cases` | `warranty.manage` | - | - | Tiếp nhận hồ sơ bảo hành mới (RECEIVED, stock_effect=NONE) |
| GET | `/api/v1/warranty-cases/{id}` | `warranty.view` hoặc `warranty.manage` | - | - | Chi tiết hồ sơ bảo hành |
| PATCH | `/api/v1/warranty-cases/{id}` | `warranty.manage` | có | - | Cập nhật / chuyển trạng thái hồ sơ bảo hành (cùng handler với POST /warranty-cases/{id}/status) |
| GET | `/api/v1/warranty-cases/{id}/attachments` | `warranty.view` hoặc `warranty.manage` | - | - | Danh sách tệp đính kèm của hồ sơ bảo hành |
| POST | `/api/v1/warranty-cases/{id}/attachments` | `warranty.manage` | - | - | Tải lên tệp đính kèm cho hồ sơ bảo hành (stock-neutral) |
| GET | `/api/v1/warranty-cases/{id}/events` | `warranty.view` hoặc `warranty.manage` | - | - | Lịch sử sự kiện của hồ sơ bảo hành |
| POST | `/api/v1/warranty-cases/{id}/status` | `warranty.manage` | có | - | Annex C transitionWarrantyStatus — chuyển trạng thái / hiệu chỉnh case bảo hành |
| POST | `/api/v1/warranty-cases/{id}/temp-only` | `warranty.temp_only` | - | - | Tiếp nhận bảo hành chưa định danh, không tác động tồn kho |

### 14. Component Issue

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/component-issue-documents` | `warranty.component_issue` | - | - | Danh sách phiếu xuất linh kiện bảo hành |
| POST | `/api/v1/component-issue-documents` | `warranty.component_issue` | - | có | Tạo phiếu xuất linh kiện bảo hành (DRAFT, stock-neutral) |
| GET | `/api/v1/component-issue-documents/{id}` | `warranty.component_issue` | - | có | Chi tiết phiếu xuất linh kiện bảo hành |
| POST | `/api/v1/component-issue-documents/{id}/cancel` | `warranty.component_issue` | có | có | Huỷ phiếu xuất linh kiện bảo hành (chỉ DRAFT/SCANNING) |
| POST | `/api/v1/component-issue-documents/{id}/post` | `warranty.component_issue` | có | có | Ghi nhận phiếu xuất linh kiện bảo hành |
| POST | `/api/v1/component-issue-documents/{id}/scan` | `warranty.component_issue` | có | có | Quét linh kiện cho phiếu xuất bảo hành (stock-neutral) |
| GET | `/api/v1/warranty-component-issues` | `warranty.component_issue` | - | - | Danh sách phiếu xuất linh kiện bảo hành (Annex C) |
| POST | `/api/v1/warranty-component-issues` | `warranty.component_issue` | - | có | Tạo phiếu xuất linh kiện bảo hành (Annex C) |
| GET | `/api/v1/warranty-component-issues/{id}` | `warranty.component_issue` | - | có | Chi tiết phiếu xuất linh kiện bảo hành (Annex C alias) |
| POST | `/api/v1/warranty-component-issues/{id}/cancel` | `warranty.component_issue` | có | có | Huỷ phiếu xuất linh kiện bảo hành (Annex C) |
| POST | `/api/v1/warranty-component-issues/{id}/post` | `warranty.component_issue` | có | có | Ghi nhận phiếu xuất linh kiện bảo hành (Annex C) |
| POST | `/api/v1/warranty-component-issues/{id}/scans` | `warranty.component_issue` | có | có | Quét linh kiện cho phiếu xuất bảo hành (Annex C) |

### 15. Audit Log

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/audit-logs` | `audit_log.view` | - | - | Nhật ký thao tác hệ thống (chỉ đọc — ghi chỉ qua AuditLogger::record() nội bộ, không có endpoint ghi) |

### 16. Agency

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/agencies` | `agency.view` | - | - | Danh sách đại lý (phân trang, filter theo keyword/status) |
| POST | `/api/v1/agencies` | `agency.manage` | - | - | Tạo đại lý mới |
| GET | `/api/v1/agencies/{id}` | `agency.view` | - | - | Chi tiết đại lý |
| PATCH | `/api/v1/agencies/{id}` | `agency.manage` | có | - | Sửa đại lý |

### 17. Defect Catalog

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/defect-catalogs` | `defect.view` | - | - | Danh sách lỗi/bệnh máy (phân trang, filter theo keyword/status) |
| POST | `/api/v1/defect-catalogs` | `defect.manage` | - | - | Tạo danh mục lỗi/bệnh máy mới |
| GET | `/api/v1/defect-catalogs/{id}` | `defect.view` | - | - | Chi tiết lỗi/bệnh máy |
| PATCH | `/api/v1/defect-catalogs/{id}` | `defect.manage` | có | - | Sửa lỗi/bệnh máy |
| GET | `/api/v1/defects` | `defect.view` | - | - | Danh sách bệnh/lỗi bảo hành (Annex C) |
| POST | `/api/v1/defects` | `defect.manage` | - | - | Tạo bệnh/lỗi bảo hành (Annex C createDefect — cùng handler với POST /defect-catalogs) |
| GET | `/api/v1/defects/{id}` | `defect.view` | - | - | Chi tiết bệnh/lỗi bảo hành (Annex C) |
| PATCH | `/api/v1/defects/{id}` | `defect.manage` | có | - | Cập nhật bệnh/lỗi bảo hành (Annex C updateDefect — cùng handler với PATCH /defect-catalogs/{id}) |

### 18. Physical Inventory

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/containers` | `container.view` | - | - | Danh sách container (phân trang, filter theo keyword/container_type/status/zone_code) |
| POST | `/api/v1/containers` | `container.manage` | - | - | Tạo container (vỏ chứa) rỗng — KHÔNG phải đường tạo putaway |
| GET | `/api/v1/containers/{id}` | `container.view` | - | - | Chi tiết container (kèm danh sách item bên trong) |
| PATCH | `/api/v1/containers/{id}` | `container.manage` | có | - | Cập nhật container (bắt buộc If-Match) |
| POST | `/api/v1/containers/{id}/items` | `container.manage` | có | - | Thêm hàng vào container (bắt buộc If-Match) |
| DELETE | `/api/v1/containers/{id}/items/{containerItemId}` | `container.manage` | có | - | Xoá hàng khỏi container (bắt buộc If-Match) |
| GET | `/api/v1/import-batches` | `import_batch.view` | - | - | Danh sách lô nhập khẩu (phân trang, filter theo keyword/status) |
| POST | `/api/v1/import-batches` | `import_batch.manage` | - | - | Tạo lô nhập khẩu mới |
| GET | `/api/v1/import-batches/{id}` | `import_batch.view` | - | - | Chi tiết lô nhập khẩu |
| PATCH | `/api/v1/import-batches/{id}` | `import_batch.manage` | - | - | Sửa lô nhập khẩu |
| GET | `/api/v1/item-instances` | `item_instance.view` | - | - | Danh sách item instance (phân trang, filter theo sku_id/item_status/item_condition/container_id) |
| POST | `/api/v1/item-instances` | `item_instance.manage` | - | - | Tạo item instance mới (máy/linh kiện có serial) |
| GET | `/api/v1/item-instances/{id}` | `item_instance.view` | - | - | Chi tiết item instance |
| PATCH | `/api/v1/item-instances/{id}` | `item_instance.manage` | - | - | Sửa item instance (optimistic locking qua version) |
| GET | `/api/v1/item-instances/{id}/trace` | `inventory.trace` | - | - | Truy vết item qua stock document line tới stock movements |
| GET | `/api/v1/physical-codes` | `inventory.trace` | - | - | Danh sách mã vật lý (phân trang, filter theo keyword/code_type/carrier_type/status) |
| POST | `/api/v1/physical-codes` | `physical_code.assign_rfid` | - | - | Đăng ký mã vật lý mới |
| GET | `/api/v1/physical-codes/{id}` | `inventory.trace` | - | - | Chi tiết mã vật lý |
| PATCH | `/api/v1/physical-codes/{id}` | `physical_code.assign_rfid` | có | - | Sửa trạng thái mã vật lý (LOST/REPLACED/INACTIVE) |

### 19. Label / Printer

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/label-print-events` | `label.print` | - | - | Danh sách sự kiện in nhãn |
| GET | `/api/v1/label-snapshots` | `label.print` | - | - | Danh sách snapshot nhãn |
| POST | `/api/v1/label-snapshots` | `label.print` | - | - | Tạo/đảm bảo đúng 01 Packing Label DRAFT cho phiếu xuất đã full match (bổ sung SĐT nếu toa thiếu) |
| GET | `/api/v1/label-snapshots/{id}` | `label.print` | - | - | Chi tiết snapshot nhãn |
| POST | `/api/v1/label-snapshots/{id}/apply` | `label.print` | có | - | Tạo snapshot nhãn mới |
| POST | `/api/v1/label-snapshots/{id}/print` | `label.print` | có | - | In label snapshot (READY_TO_PRINT/PRINT_FAILED → PRINTED, stock_effect=NONE) |
| POST | `/api/v1/label-snapshots/{id}/reprint` | `label.reprint` | có | - | In lại label snapshot đã PRINTED (stock-neutral, dùng lại chính snapshot cũ) |
| GET | `/api/v1/printer-configs` | `label.print` | - | - | Danh sách cấu hình máy in |
| POST | `/api/v1/printer-configs` | `label.print` | - | - | Tạo cấu hình máy in nhãn (printer agent endpoint) |
| PATCH | `/api/v1/printer-configs/{id}` | `label.print` | - | - | Cập nhật/vô hiệu hoá cấu hình máy in (SCR-LBL-01) |

### 21. Public Catalog

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/pv-admin/resources/{resourceType}` | `pv.master.manage` | - | - | PV Admin — danh sách master App PV (industry/tag/rule/profile) |
| POST | `/api/v1/pv-admin/resources/{resourceType}` | `pv.master.manage` | - | - | PV Admin — tạo master App PV |
| PATCH | `/api/v1/pv-admin/resources/{resourceType}/{id}` | `pv.master.manage` | có | - | PV Admin — cập nhật master App PV (bắt buộc If-Match) |
| GET | `/api/v1/pv/catalog` | _public_ | - | - | Danh mục PV công khai |
| GET | `/api/v1/pv/industries` | _public_ | - | - | App PV — danh sách ngành nghề ACTIVE dùng cho filter/gợi ý |
| GET | `/api/v1/pv/products` | _public_ | - | - | App PV — danh sách sản phẩm đã publish (ẩn danh, read-only) |
| PATCH | `/api/v1/pv/products/{id}/publish` | `pv.publish` | có | - | Bật/tắt publish SKU lên App PV (quyền pv.publish, If-Match theo version hồ sơ App PV) |
| GET | `/api/v1/pv/products/{skuId}` | _public_ | - | - | App PV — chi tiết sản phẩm đã publish (ẩn danh, read-only) |
| GET | `/api/v1/pv/recommendations` | _public_ | - | - | App PV — gợi ý sản phẩm theo ngành nghề (rule ACTIVE, theo priority) |
| GET | `/api/v1/pv/tags` | _public_ | - | - | Danh sách tag PV |

### 24. Putaway

| Method | Path | Permission | If-Match | Data scope | Mô tả |
| --- | --- | --- | --- | --- | --- |
| GET | `/api/v1/putaway-tasks` | `putaway.view` hoặc `putaway.manage` hoặc `container.view` | - | - | Danh sách BOX chờ xếp khay (Annex C) |
| POST | `/api/v1/putaway-tasks` | `putaway.manage` hoặc `container.manage` | - | - | Tạo tác vụ putaway DRAFT (BOX COMPONENT -> TRAY, stock-neutral) |
| POST | `/api/v1/putaway-tasks/{id}/cancel` | `putaway.manage` hoặc `container.manage` | có | - | Huỷ tác vụ xếp BOX vào TRAY (Annex C) |
| POST | `/api/v1/putaway-tasks/{id}/confirm` | `putaway.manage` hoặc `container.manage` | có | - | Xác nhận xếp BOX vào TRAY (Annex C) |
| GET | `/api/v1/putaway/history` | `putaway.view` hoặc `putaway.manage` hoặc `container.view` | - | - | Lịch sử putaway CONFIRMED |
| GET | `/api/v1/putaway/tasks` | `putaway.view` hoặc `putaway.manage` hoặc `container.view` | - | - | PTW-00 - Danh sách BOX chờ xếp khay và DRAFT đang xử lý |
| POST | `/api/v1/putaway/tasks` | `putaway.manage` hoặc `container.manage` | - | - | Tạo tác vụ xếp BOX vào TRAY |
| POST | `/api/v1/putaway/tasks/{id}/cancel` | `putaway.manage` hoặc `container.manage` | có | - | Hủy putaway DRAFT |
| POST | `/api/v1/putaway/tasks/{id}/confirm` | `putaway.manage` hoặc `container.manage` | có | - | Xác nhận putaway; cập nhật BOX->TRAY, không tạo stock movement |

## 3. Mức độ sử dụng permission

| Permission | Số operation |
| --- | --- |
| `agency.manage` | 2 |
| `agency.view` | 2 |
| `audit_log.view` | 1 |
| `catalog.manage` | 14 |
| `catalog.view` | 8 |
| `container.manage` | 10 |
| `container.view` | 5 |
| `defect.manage` | 4 |
| `defect.view` | 4 |
| `iam.permission.view` | 2 |
| `iam.role.manage` | 5 |
| `iam.role.view` | 2 |
| `iam.user.manage` | 3 |
| `iam.user.view` | 2 |
| `import_batch.manage` | 2 |
| `import_batch.view` | 2 |
| `inbound.cancel` | 1 |
| `inbound.create` | 3 |
| `inbound.post` | 1 |
| `inbound.scan` | 4 |
| `inbound.view` | 3 |
| `inventory.reversal.post` | 9 |
| `inventory.trace` | 6 |
| `inventory.view` | 11 |
| `item_instance.manage` | 2 |
| `item_instance.view` | 2 |
| `label.print` | 12 |
| `label.reprint` | 2 |
| `outbound.cancel` | 1 |
| `outbound.import` | 9 |
| `outbound.post` | 1 |
| `outbound.request.confirm` | 2 |
| `outbound.request.import` | 6 |
| `outbound.request.manual_create` | 1 |
| `outbound.scan` | 3 |
| `outbound.view` | 4 |
| `packing_label.preview` | 1 |
| `packing_label.print` | 1 |
| `packing_label.ready` | 1 |
| `packing_label.reprint` | 1 |
| `physical_code.assign_rfid` | 5 |
| `putaway.manage` | 9 |
| `putaway.view` | 3 |
| `pv.master.manage` | 3 |
| `pv.publish` | 1 |
| `report.export` | 10 |
| `report.view` | 14 |
| `scan.classify` | 2 |
| `scan.execute` | 2 |
| `sku.manage` | 11 |
| `sku.view` | 4 |
| `warehouse.manage` | 2 |
| `warehouse.view` | 2 |
| `warranty.component_issue` | 12 |
| `warranty.manage` | 10 |
| `warranty.temp_only` | 1 |
| `warranty.view` | 5 |

Danh mục permission chuẩn (mô tả + nhóm) nằm ở `docs/permissions/WMS_PERMISSION_CATALOG_BE1.md`.
FE dùng cùng bộ mã tại `src/shared/constants/wms-permissions.ts`; route guard của FE chỉ là UX guard, BE vẫn là biên authorization thật.
