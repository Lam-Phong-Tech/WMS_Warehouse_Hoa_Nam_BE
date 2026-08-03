# WMS Permission Catalog - BE1

Version: 1.0.1-be1-scan-none-reconcile
Generated at: 2026-08-02 19:05 Asia/Saigon
Seed source: Modules/Auth/database/seeders/AuthDatabaseSeeder.php
Route source: Modules/Warehouse/routes/api.php, Modules/AuditLog/routes/api.php, Modules/Auth/routes/api.php

## Endpoint Permission Map

| Endpoint group | Permission | Default roles seeded |
| --- | --- | --- |
| Auth login/refresh/logout/me/change-password/switch-context | authenticated | all active users |
| GET /api/v1/warehouses, GET /api/v1/warehouses/{id} | warehouse.view | WAREHOUSE_STAFF, WAREHOUSE_APPROVER, WAREHOUSE_KEEPER, VIEWER, WAREHOUSE_MANAGER |
| PATCH /api/v1/warehouses/{id}, POST /api/v1/inventory-snapshots/generate, PATCH /api/v1/document-status-transitions/{id}/toggle | warehouse.manage | WAREHOUSE_MANAGER |
| GET /api/v1/products, GET /api/v1/products/{id} | product.view | WAREHOUSE_STAFF, WAREHOUSE_APPROVER, WAREHOUSE_KEEPER, VIEWER, WAREHOUSE_MANAGER |
| POST /api/v1/products, PATCH /api/v1/products/{id}/enable, PATCH /api/v1/products/{id}/disable | product.manage | WAREHOUSE_MANAGER |
| GET/POST /api/v1/skus, GET /api/v1/skus/{id}, POST /api/v1/skus/import-excel | sku.manage | SKU_MANAGER |
| GET product-categories/uoms/suppliers | master.manage | MASTER_MANAGER |
| GET inbound/outbound documents, document-status-history | stock_document.view | WAREHOUSE_STAFF, WAREHOUSE_APPROVER, WAREHOUSE_KEEPER, VIEWER, WAREHOUSE_MANAGER |
| POST/PATCH inbound/outbound documents | stock_document.create | WAREHOUSE_STAFF, WAREHOUSE_MANAGER |
| POST inbound/outbound submit | stock_document.submit | WAREHOUSE_STAFF, WAREHOUSE_MANAGER |
| POST inbound/outbound approve/reject | stock_document.approve | WAREHOUSE_APPROVER, WAREHOUSE_MANAGER |
| POST inbound/outbound post-receipt/post-issue/close-remainder | stock_document.post | WAREHOUSE_KEEPER, WAREHOUSE_MANAGER |
| POST inbound/outbound scan | stock_document.scan | WAREHOUSE_KEEPER, WAREHOUSE_MANAGER |
| POST inbound/outbound cancel | stock_document.cancel | WAREHOUSE_KEEPER, WAREHOUSE_MANAGER |
| POST inbound/outbound reverse, GET reversal-documents | stock_document.reverse | WAREHOUSE_KEEPER, WAREHOUSE_MANAGER |
| GET inventory balances, stock movements, reservations, items, physical-codes, scan batches/events, stocktake sessions, warranty cases/events | inventory.view | WAREHOUSE_STAFF, WAREHOUSE_APPROVER, WAREHOUSE_KEEPER, VIEWER, WAREHOUSE_MANAGER |
| GET safe-stock configs/alerts/history | safe_stock.view | WAREHOUSE_STAFF, WAREHOUSE_APPROVER, WAREHOUSE_KEEPER, VIEWER, WAREHOUSE_MANAGER |
| POST/PATCH/DELETE safe-stock configs | safe_stock.manage | WAREHOUSE_KEEPER, WAREHOUSE_MANAGER |
| GET reports/dashboard-quick, inventory-xnt, inventory-xnt/export-excel, inventory-reconciliation | report.view | WAREHOUSE_STAFF, WAREHOUSE_APPROVER, WAREHOUSE_KEEPER, VIEWER, WAREHOUSE_MANAGER |
| GET audit-logs | audit_log.view | VIEWER, WAREHOUSE_MANAGER |
| Stock request read/create/submit/approve endpoints | stock_request.* | WAREHOUSE_MANAGER |
| Transfer read/create/submit/approve/post endpoints | transfer.* | WAREHOUSE_MANAGER |
| POST /api/v1/stocktake-sessions/{id}/apply-adjustment | stocktake.adjust | WAREHOUSE_MANAGER |
| POST /api/v1/warranty-cases/{id}/temp-only | warranty.temp_only | WAREHOUSE_MANAGER |
| POST /api/v1/scan/classify | scan.classify | WAREHOUSE_MANAGER |

## FE ROUTE_PERMISSIONS Notes

- FE routes for inbound/outbound scan must use stock_document.scan and must not assume stock mutation.
- FE routes for reconciliation should use report.view.
- FE routes for audit log should use audit_log.view.
- SUPER_ADMIN bypasses permission middleware by role_code; seeded permission rows still exist for non-admin roles.

## Backend Enforcement Baseline

- Runtime enforcement is backend-owned in `Modules/Auth/app/Http/Middleware/CheckPermission.php`; FE hidden/disabled buttons are not trusted as authorization.
- Permission resolution uses only active role assignments from `Modules/Auth/app/Models/User.php::activeRoles()`.
- A role grants permissions only when:
  - `role.status = ACTIVE`.
  - `user_role.effective_from` is null or already reached.
  - `user_role.effective_to` is null or still in the future.
- `SUPER_ADMIN` bypass also requires an active/effective `SUPER_ADMIN` role assignment.
- Regression evidence: `tests/Feature/SecurityRbacAuditNegativeTest.php::test_backend_denies_permissions_from_inactive_expired_and_future_roles`.
- Positive control: `tests/Feature/SecurityRbacAuditNegativeTest.php::test_backend_allows_permissions_from_effective_active_role_assignment`.
- Latest local evidence log: `docs/evidence/fe1/php-artisan-test-SecurityRbacAuditNegativeTest.log`.
