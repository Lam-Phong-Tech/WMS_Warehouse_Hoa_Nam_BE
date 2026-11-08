# Supplier permissions and delegation

User request: expose new supplier APIs in role permissions and individual grants.

- Register supplier.view, supplier.create, supplier.update with Vietnamese names.
- Gate mutations independently by create/update. Allow supplier.view on GET while
  retaining inbound.view lookup access for existing inbound workflows.
- Add a data-only migration: preserve existing role/direct grants, copy read
  access from inbound.view and full supplier rights from catalog.manage; invalidate
  affected users' RBAC versions and permission/role list caches. No schema change.
- Keep fresh seed defaults equivalent to the migration; do not seed/reset live DB.
- Expose Nhà cung cấp as an IAM module; load all permission pages for role editing
  and individual grants so new permissions cannot disappear past page 1.
- Update supplier page/menu/button gates, tests and API/handoff documentation.
- Verify independent rights, migration reentry and grant/delegation flows; run
  focused backend/frontend tests, authorization coverage and typecheck/build.
- User subsequently authorized pushing both repositories to dev. No live role
  changes, migration execution or deployment in this follow-up.
