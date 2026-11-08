# Supplier permissions — 08/11/2026

## Current contract

| Permission | IAM label | API / UI |
| --- | --- | --- |
| supplier.view | Xem danh sách nhà cung cấp (NCC) | Supplier list page and GET directory |
| supplier.create | Thêm nhà cung cấp (NCC) | POST directory; Add supplier button |
| supplier.update | Sửa nhà cung cấp (NCC) | PATCH supplier UUID; Edit button |

GET additionally retains inbound.view for the existing inbound picker. POST/PATCH
no longer accept catalog.manage alone. All supplier APIs retain stable-session
and active-account checks. Give view alongside create/update for page-based work.
SUPER_ADMIN and existing IAM delegation scope / no-self-escalation rules remain.

The role matrix now has a Nhà cung cấp (NCC) group. The shared permission query
loads all server pages for both role permissions and individual account grants.

## Deployment prerequisite

Apply `database/migrations/2026_11_08_000100_register_supplier_permissions.php`
before activating the new API/frontend gates. This is an additive data migration,
not a table/column change; do not run a reset or broad fixture seeding operation.
It maps existing inbound.view grants to supplier.view, and catalog.manage grants
to all three supplier permissions, for roles and direct user grants. Existing
custom definitions are preserved; migration reentry does not restore removed
grants. User RBAC versions/list caches are invalidated. Fresh AuthDatabaseSeeder
uses the equivalent mapping for default roles. Refresh the browser profile after
deployment so the frontend sees the new permission keys.

PostgreSQL exposes permission/role_permission through compatibility views with
INSTEAD OF triggers. Registration uses existence checks and ordinary inserts,
not an outer ON CONFLICT that cannot handle errors inside those triggers.

## Evidence

- SQLite focused authorization / supplier / P04 / IAM suite: 72 tests, 1064
  assertions passed.
- PostgreSQL dedicated disposable database: 16 tests, 129 assertions passed after
  the compatibility-view correction. Covers migration fresh install, reentry,
  customized inactive definition, role/direct backfill, independent API rights,
  and actual IAM role assignment and direct-grant endpoints.
- Frontend focused permission/catalog/matrix/supplier tests: 22 tests passed;
  frontend production build passed. Additional integration verification runs on
  the release checkout based on latest origin/dev before push.
- No live migration, role grant, seed, deployment or production mutation was run.
