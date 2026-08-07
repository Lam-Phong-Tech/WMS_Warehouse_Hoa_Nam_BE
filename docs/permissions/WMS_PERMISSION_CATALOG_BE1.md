# WMS Permission Catalog — BE1

Version: `1.1.0-be1-wwhn150`
Status: `PROPOSED`
Repository JSON: `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json`
Runtime evidence: `build/evidence/api-data/permission-catalog.json`

## Enforcement sources

| Source | Purpose | Hash evidence |
|---|---|---|
| `Modules/Auth/database/seeders/AuthDatabaseSeeder.php` | Permission and role seed | `permission-catalog.json.source_hashes.permission_seed.sha256` |
| `Modules/Warehouse/routes/api.php` | Endpoint → permission middleware | `permission-catalog.json.source_hashes.warehouse_routes.sha256` |
| `Modules/Auth/app/Http/Middleware/CheckPermission.php` | Backend authorization enforcement | `permission-catalog.json.source_hashes.permission_middleware.sha256` |
| `app/Console/Commands/ExportPermissionCatalog.php` | Catalog/seed-diff generator | `permission-catalog.json.source_hashes.permission_exporter.sha256` |
| `Modules/Auth/app/Models/User.php` | Role permission + SUPER_ADMIN/data-scope enforcement | `permission-catalog.json.source_hashes.user_permission_model.sha256` |
| Generated route list | Runtime route/middleware truth | `route-list.raw.json` + `SHA256SUMS` |

The CI command `php artisan evidence:permission-catalog` fails when a runtime route references a permission key that is not active, or when `scan.classify` status/role/middleware differs from the expected boundary. It also emits `permission-seed-diff.json` with missing and unexpected roles.

## WWHN-150 endpoint matrix

| Endpoint | Method | Permission | Seeded roles | FE behavior | Backend rule |
|---|---|---|---|---|---|
| `/api/v1/scan/classify` | POST | `scan.classify` | `WAREHOUSE_MANAGER`; `SUPER_ADMIN` bypass | Hide/disable classify action when permission is absent; still handle `403` | Permission middleware and warehouse data-scope check are mandatory |

`scan.classify` authorizes read-only classification only. It does not grant any posting permission and must never be used as a substitute for `stock_document.post`, `stocktake.adjust`, or transfer posting permissions.

## Critical endpoint groups

| Endpoint group | Permission |
|---|---|
| Warehouse read/manage | `warehouse.view`, `warehouse.manage` |
| Product/SKU/master | `product.view`, `product.manage`, `sku.manage`, `master.manage` |
| Stock document lifecycle | `stock_document.view/create/submit/approve/post/scan/cancel/reverse` |
| Inventory and reports | `inventory.view`, `report.view` |
| Stocktake adjustment | `stocktake.adjust` |
| Warranty temporary receipt | `warranty.temp_only` |
| Scan classify | `scan.classify` |
| Stock requests | `stock_request.*` |
| Transfers | `transfer.*` |
| Audit log | `audit_log.view` |

## Review rule

The generated catalog contains:

- active permission rows;
- role → permission assignments;
- runtime route → permission mappings;
- endpoint → permission → role matrix;
- SHA-256 of seed, routes, middleware, exporter and user permission model sources;
- integrity status, missing route permissions and the focused `scan.classify` seed/role diff.

FE/BA sign-off must use the generated catalog from the exact reviewed commit artifact, not this branch-relative Markdown alone.
