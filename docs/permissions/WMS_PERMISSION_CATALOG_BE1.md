# WMS Permission Catalog — BE1

Version: `1.3.0-be1-wwhn309`
Status: `REGENERATED — PENDING_FE_BA_REVIEW`
Repository JSON: `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json`
Runtime evidence: `build/evidence/api-data/permission-catalog.json`
Task: WWHN-309 (BE handover kiến trúc/API/vận hành)

## 0. Bản 1.3.0 (10/09/2026) — vì sao sinh lại

Bản `1.2.0` (sinh 07/09/2026 tại `acc971c`) làm cổng `Wwhn309PermissionCatalogEvidenceTest` đỏ 6/6 vì hai lý do,
**không** do ai đổi quyền:

| Vấn đề | Bản 1.2.0 | Bản 1.3.0 |
|---|---|---|
| Hợp đồng WWHN-150 (scan classify) | Exporter so role của route với danh sách production cứng, nên role kiểm thử `INBOUND_ONLY_TEST` (chỉ seed ở local/testing/demo) bị tính là `unexpected_roles` → FAIL | Hợp đồng chỉ đánh giá trên role production. Role fixture (`AuthDatabaseSeeder::NON_PRODUCTION_ROLES`) được báo cáo riêng ở `permission_seed_diff.fixture_roles`, top-level `fixture_roles` và cờ `roles[].fixture_only` — không bị bỏ im lặng |
| Source hash | 3/7 hash lệch source (seeder, route kho, route auth) | Sinh lại cùng lúc với source |
| Danh sách thay đổi | Không có | `changes_since_previous`: route thêm/bớt/đổi permission, permission thêm/bớt/đổi status, role đổi tập quyền — so với bản đã publish trước (`--previous`) |

`changes_since_previous` của bản này so với bản 07/09: **26 route mới, 0 route bị gỡ, 0 route đổi permission,
0 permission thêm/bớt/đổi status, 0 role đổi tập quyền.** 26 route mới:

| Nhóm | Route | Permission | Commit |
|---|---|---|---|
| MFA (WWHN-SEC-08) | `GET auth/mfa/status`, `POST auth/mfa/enroll`, `POST auth/mfa/confirm`, `POST auth/mfa/disable` | không có — SELF_SERVICE (chỉ tác động tài khoản của chính người gọi) | `2d9864f` |
| MFA (WWHN-SEC-08) | `POST auth/mfa/verify` | không có — công khai theo thiết kế (bước 2 đăng nhập) | `2d9864f` |
| Xuất linh kiện bảo hành | 20 route `lines` (thêm/sửa/xoá), `unscan`, `record`, `resolve-code` cho `component-issue-documents`, `warranty-component-issues` và alias `mini-app/*` | `warranty.component_issue` | `2d9864f` |
| Nhập kho | `PATCH inbound-documents/{id}/lines/{lineId}/sku` (bổ sung tên + ĐVT cho SKU mới trước Post) | `inbound.scan` | `38b51a6` |

Rà soát AppSec của các route này nằm ở `docs/security/ROUTE_CONTRACT_DELTA_20260910.md` (route `lines/{lineId}/sku`
có một CONCERN về phạm vi — mục E3).

## 1. Nguồn enforcement

| Source | Vai trò | Hash evidence |
|---|---|---|
| `Modules/Auth/database/seeders/AuthDatabaseSeeder.php` | Seed permission + role → permission; `NON_PRODUCTION_ROLES` | `source_hashes.permission_seed.sha256` |
| `Modules/Warehouse/routes/api.php` | Endpoint → permission middleware (nghiệp vụ kho) | `source_hashes.warehouse_routes.sha256` |
| `Modules/Auth/routes/api.php` | Endpoint → permission middleware (IAM) | `source_hashes.auth_routes.sha256` |
| `Modules/AuditLog/routes/api.php` | Endpoint → permission middleware (audit) | `source_hashes.audit_routes.sha256` |
| `Modules/Auth/app/Http/Middleware/CheckPermission.php` | Backend authorization enforcement | `source_hashes.permission_middleware.sha256` |
| `app/Console/Commands/ExportPermissionCatalog.php` | Catalog/seed-diff generator | `source_hashes.permission_exporter.sha256` |
| `Modules/Auth/app/Models/User.php` | Role permission + SUPER_ADMIN bypass + data scope | `source_hashes.user_permission_model.sha256` |

Regenerate (bản publish, kèm danh sách thay đổi so với bản đang publish):

```bash
php artisan evidence:permission-catalog \
  --output=docs/permissions/WMS_PERMISSION_CATALOG_BE1.json \
  --seed-diff-output=build/evidence/api-data/permission-seed-diff.json \
  --previous=docs/permissions/WMS_PERMISSION_CATALOG_BE1.json
```

Chạy trên DB đã migrate và seed `AuthDatabaseSeeder` ở môi trường `testing` (cùng trạng thái role/permission mà
cổng WWHN-309 seed). Lệnh trả exit code khác 0 khi: route tham chiếu permission key không ACTIVE, hoặc role matrix
production của `scan classify` lệch baseline. Đây là gate fail-closed, không phải báo cáo thông tin.

## 2. Semantics bắt buộc khi đọc catalog

1. `permission` trong `route_permissions` / `endpoint_permission_role_matrix` là **biểu thức**, không phải key. Dạng `A|B|C` nghĩa là OR — user chỉ cần một trong các key.
2. `accepted_permissions` là danh sách key đã tách sẵn; FE/QA phải dùng trường này khi map quyền, không parse lại chuỗi.
3. `roles` trong matrix đã bao gồm `SUPER_ADMIN` do bypass ở tầng backend (`User::isSuperAdmin`), không phải do được gán permission — vì vậy `SUPER_ADMIN` có `permissions: []` trong mục `roles`.
4. `backend_enforced = true` nghĩa là route có middleware `permission:`. Backend luôn tự kiểm tra; FE ẩn nút không được coi là cơ chế bảo vệ.
5. Data scope (kho/chi nhánh) là tầng kiểm tra **độc lập** với permission (`EnforceInventoryWarehouseScope`); permission đúng vẫn có thể bị 403 do scope.
6. `roles[].fixture_only = true` đánh dấu role chỉ tồn tại ở môi trường kiểm thử (`SKU_MANAGER`, `MASTER_MANAGER`, `NO_PERMISSION`, `INBOUND_ONLY_TEST`). Chúng vẫn xuất hiện trong matrix vì catalog mô tả đúng DB đang kiểm, nhưng không bao giờ được seed ở production.

## 3. Role trong catalog (73 permission ACTIVE, 13 role, 370 route `api/v1/*`, 332 route có middleware permission)

| Role | Số permission | Loại |
|---|---|---|
| `SUPER_ADMIN` | 0 | Production — bypass toàn bộ ở backend, không gán permission |
| `ADMIN` | 69 | Production |
| `WAREHOUSE_STAFF` | 35 | Production |
| `WAREHOUSE_MANAGER` | 26 | Production — vai giám sát (DELTA-06) |
| `VIEWER` | 23 | Production |
| `LEADERSHIP` | 21 | Production |
| `WARRANTY_STAFF` | 18 | Production |
| `WAREHOUSE_KEEPER` | 50 | Ngừng dùng — chỉ seed ở dev/test (`DEPRECATED_ROLES`) |
| `WAREHOUSE_APPROVER` | 28 | Ngừng dùng — chỉ seed ở dev/test (`DEPRECATED_ROLES`) |
| `INBOUND_ONLY_TEST` | 33 | Fixture kiểm thử |
| `SKU_MANAGER` | 3 | Fixture kiểm thử |
| `MASTER_MANAGER` | 2 | Fixture kiểm thử |
| `NO_PERMISSION` | 0 | Fixture kiểm thử deny-path |

## 4. Nhóm permission theo miền nghiệp vụ

| Nhóm | Key |
|---|---|
| IAM | `iam.user.view/manage`, `iam.role.view/manage`, `iam.permission.view` |
| Master/catalog | `warehouse.view/manage`, `catalog.view/manage`, `sku.view/manage`, `agency.view/manage`, `defect.view/manage` |
| Inbound | `inbound.view/create/scan/post/cancel` |
| Outbound | `outbound.view/import/scan/post/cancel`, `outbound.request.import/manual_create/confirm` |
| Put-away / container / hiện vật / batch | `putaway.view/manage`, `container.view/manage`, `item_instance.view/manage`, `import_batch.view/manage` |
| Inventory | `inventory.view`, `inventory.trace`, `inventory.reversal.post` |
| Scan | `scan.classify` (canonical), `scan.execute` (Annex C alias), `scan.inbound`, `scan.outbound`, `scan.warranty` |
| Label / RFID / mã vật lý | `label.print`, `label.reprint`, `packing_label.preview/ready/print/reprint`, `physical_code.assign_rfid`, `physical_code.create` |
| Warranty | `warranty.view/manage`, `warranty.component_issue`, `warranty.temp_only`, `warranty.pii.view/export` |
| Report | `report.view`, `report.export`, 9 mã màn `report.{inventory_flow,outbound_flow,stock_reconcile,traceability,import_quality,packing_label,warranty,recipient,io_history}.view` |
| App PV | `pv.publish`, `pv.master.manage` |
| Audit | `audit_log.view`, `audit_log.view_all` |

MVP **không** có stocktake/adjustment trực tiếp; không tồn tại permission `stocktake.adjust` trong baseline. Correction đi qua reversal (`inventory.reversal.post`). Các mã đã thu hồi ngày 27/08/2026 (`stock_document.*`, `outbound.create`, `product.*`, …) không còn trong catalog.

## 5. Seed diff có gate: scan classify

`permission-seed-diff.json` kiểm tra `POST /api/v1/scan/classify`:

- Permission canonical: `scan.classify`; alias Annex C: `scan.execute`; route khai báo `permission:scan.execute|scan.classify`.
- Baseline role production được phép (`evaluation_scope = PRODUCTION_ROLES`): `ADMIN`, `SUPER_ADMIN`, `WAREHOUSE_KEEPER`, `WAREHOUSE_STAFF`, `WARRANTY_STAFF`.
  - WWHN-150 + SRS v1.12 §5 qua alias `scan.execute`.
  - `WAREHOUSE_MANAGER` rời baseline từ 06/09/2026 (vai giám sát, không thao tác quét).
- Lệch theo **cả hai chiều** (`missing_roles` hoặc `unexpected_roles`) giữa các role production đều FAIL để bắt drift.
- Role fixture đang giữ quyền scan được liệt kê ở `fixture_roles.holding_scan_permission` (bản này: `INBOUND_ONLY_TEST`) và **không** tính vào hai danh sách trên.
- `scan classify` không có stock effect và không được dùng thay cho `inbound.post`/`outbound.post`/`inventory.reversal.post`.

## 6. Quy tắc review

FE/BA/QA sign-off phải dùng artifact sinh ra tại đúng commit đang review (`build/evidence/.../permission-catalog.json` + `SHA256SUMS`), không kết luận chỉ dựa trên Markdown này. Trạng thái duyệt của bản 1.3.0: **PENDING_FE_BA_REVIEW**.
