# WMS Permission Catalog — BE1

Version: `1.2.0-be1-wwhn309`
Status: `REGENERATED — PENDING_FE_BA_REVIEW`
Repository JSON: `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json`
Runtime evidence: `build/evidence/api-data/permission-catalog.json`
Task: WWHN-309 (BE handover kiến trúc/API/vận hành)

## 0. Vì sao bản này thay bản `1.1.0-be1-wwhn150`

Bản `1.1.0` (generated 2026-08-03) đã stale và sai ở hai điểm, cả hai đã được sửa tận gốc:

| Vấn đề | Bản 1.1.0 | Bản 1.2.0 |
|---|---|---|
| Độ phủ | 31 permission / 31 route | 83 permission / 226 route (`api/v1/*`) |
| Source hash | 6/7 hash lệch so với source hiện hành | Regenerate cùng lúc với source |
| Biểu thức `permission:A\|B` | Exporter so khớp cả chuỗi `"A\|B"` như một permission key ⇒ 31 endpoint dùng OR-alias bị trả sai `roles` và bị tính là route permission "lạ" | Exporter tách theo `\|` đúng semantics runtime của `CheckPermission`, thêm trường `accepted_permissions` |
| Nội dung Markdown | Còn liệt kê `stocktake.adjust`, `stock_document.*`, `warranty.temp_only` — không thuộc baseline MVP hiện hành | Chỉ liệt kê nhóm permission có thật trong seed/route |

## 1. Nguồn enforcement

| Source | Vai trò | Hash evidence |
|---|---|---|
| `Modules/Auth/database/seeders/AuthDatabaseSeeder.php` | Seed permission + role → permission | `source_hashes.permission_seed.sha256` |
| `Modules/Warehouse/routes/api.php` | Endpoint → permission middleware (nghiệp vụ kho) | `source_hashes.warehouse_routes.sha256` |
| `Modules/Auth/routes/api.php` | Endpoint → permission middleware (IAM) | `source_hashes.auth_routes.sha256` |
| `Modules/AuditLog/routes/api.php` | Endpoint → permission middleware (audit) | `source_hashes.audit_routes.sha256` |
| `Modules/Auth/app/Http/Middleware/CheckPermission.php` | Backend authorization enforcement | `source_hashes.permission_middleware.sha256` |
| `app/Console/Commands/ExportPermissionCatalog.php` | Catalog/seed-diff generator | `source_hashes.permission_exporter.sha256` |
| `Modules/Auth/app/Models/User.php` | Role permission + SUPER_ADMIN bypass + data scope | `source_hashes.user_permission_model.sha256` |

Regenerate:

```bash
php artisan evidence:permission-catalog --output=build/evidence/api-data/permission-catalog.json
```

Lệnh trả exit code khác 0 khi: route tham chiếu permission key không ACTIVE, hoặc role matrix của `scan classify` lệch baseline. Đây là gate fail-closed, không phải báo cáo thông tin.

## 2. Semantics bắt buộc khi đọc catalog

1. `permission` trong `route_permissions` / `endpoint_permission_role_matrix` là **biểu thức**, không phải key. Dạng `A|B|C` nghĩa là OR — user chỉ cần một trong các key.
2. `accepted_permissions` là danh sách key đã tách sẵn; FE/QA phải dùng trường này khi map quyền, không parse lại chuỗi.
3. `roles` trong matrix đã bao gồm `SUPER_ADMIN` do bypass ở tầng backend (`User::isSuperAdmin`), không phải do được gán permission — vì vậy `SUPER_ADMIN` có `permissions: []` trong mục `roles`.
4. `backend_enforced = true` nghĩa là route có middleware `permission:`. Backend luôn tự kiểm tra; FE ẩn nút không được coi là cơ chế bảo vệ.
5. Data scope (kho/chi nhánh) là tầng kiểm tra **độc lập** với permission (`EnforceInventoryWarehouseScope`); permission đúng vẫn có thể bị 403 do scope.

## 3. Baseline role hiện hành

| Role | Số permission | Ghi chú |
|---|---|---|
| `SUPER_ADMIN` | 0 | Bypass toàn bộ ở backend, không gán permission |
| `WAREHOUSE_MANAGER` | 71 | Rộng nhất trong nhóm vận hành |
| `ADMIN` | 61 | Quản trị hệ thống + master data |
| `WAREHOUSE_KEEPER` | 48 | Thủ kho: scan/match/put-away/label |
| `WAREHOUSE_STAFF` | 31 | Nhân viên kho theo SRS v1.12 §5 |
| `WAREHOUSE_APPROVER` | 22 | Duyệt/kiểm soát |
| `VIEWER` | 17 | Chỉ đọc |
| `WARRANTY_STAFF` | 12 | Bảo hành |
| `LEADERSHIP` | 11 | Báo cáo/điều hành |
| `MASTER_MANAGER` | 5 | Master data |
| `SKU_MANAGER` | 5 | SKU/catalog |
| `NO_PERMISSION` | 0 | Role kiểm thử deny-path |

## 4. Nhóm permission theo miền nghiệp vụ

| Nhóm | Key tiêu biểu |
|---|---|
| IAM | `iam.user.view/manage`, `iam.role.view/manage`, `iam.permission.view` |
| Master/catalog | `warehouse.*`, `catalog.*`, `sku.*`, `agency.*`, `defect.*` |
| Inbound | `inbound.view/create/scan/post/cancel` |
| Outbound | `outbound.view/import/create/scan/post/cancel`, `outbound.request.import/confirm` |
| Put-away / container | `putaway.view/manage`, `container.view/manage` |
| Inventory | `inventory.view`, `inventory.trace`, `inventory.reversal.post` |
| Scan | `scan.classify` (canonical) + `scan.execute` (Annex C alias) |
| Label / RFID | `label.print`, `label.reprint`, `physical_code.assign_rfid` |
| Warranty | `warranty.view/manage`, `warranty.component_issue`, `warranty.pii.view/export` |
| Report | `report.view`, `report.export` |
| Audit | `audit_log.view` |

MVP **không** có stocktake/adjustment trực tiếp; không tồn tại permission `stocktake.adjust` trong baseline. Correction đi qua reversal (`inventory.reversal.post`).

## 5. Seed diff có gate: scan classify

`permission-seed-diff.json` kiểm tra `POST /api/v1/scan/classify`:

- Permission canonical: `scan.classify`; alias Annex C: `scan.execute`; route khai báo `permission:scan.execute|scan.classify`.
- Baseline role được phép: `ADMIN`, `SUPER_ADMIN`, `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER`, `WAREHOUSE_STAFF`, `WARRANTY_STAFF`.
  - 4 role đầu theo contract WWHN-150.
  - `WAREHOUSE_KEEPER`/`WAREHOUSE_STAFF` vào baseline qua alias `scan.execute`, truy vết SRS v1.12 §5 (bảng vai trò: "Nhân viên kho … scan/match Item").
- Lệch theo **cả hai chiều** (`missing_roles` hoặc `unexpected_roles`) đều FAIL để bắt drift.
- `scan classify` không có stock effect và không được dùng thay cho `inbound.post`/`outbound.post`/`inventory.reversal.post`.

> Điểm cần BA xác nhận: bản 1.1.0 ghi expectation 4 role trong khi seed thực tế (đã duyệt) cấp thêm 2 role vận hành qua alias. Bản 1.2.0 pin baseline hợp nhất 6 role. Không có thay đổi quyền trong seed/route — chỉ sửa expectation của evidence cho khớp code đã duyệt và SRS.

## 6. Quy tắc review

FE/BA/QA sign-off phải dùng artifact sinh ra tại đúng commit đang review (`build/evidence/.../permission-catalog.json` + `SHA256SUMS`), không kết luận chỉ dựa trên Markdown này.
