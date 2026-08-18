# WWHN-292 — Backend authorization/runtime handoff

Date: 2026-08-18 (ICT)  
Branch: `m1` only  
Scope: backend boundary supporting FE WWHN-292 RBAC/API-error verification

## Source review result

Starting BE `m1` identity reviewed for this handoff: `cc931c7077f69f7a0560cd0ed3a884c366fb043e`.

No justified WWHN-292 backend business-code change was identified during source review. Current routes already enforce backend authorization with `auth:api` + `permission:*`; inventory/outbound flows additionally apply warehouse/data-scope middleware where required. FE route/menu guards remain UX controls and are not treated as the security boundary.

Existing automated backend coverage includes:

- `tests/Feature/AuthorizationTest.php`
  - role/permission allow-vs-deny matrix;
  - direct API `403 ACCESS_DENIED` for users without permission;
  - standard 403 response contract;
  - `X-Request-ID` and request metadata.
- `tests/Feature/AuthRbacAuditHardeningTest.php`
  - expired-token 401 contract;
  - warehouse-scope list filtering;
  - detail/mutation outside warehouse scope -> `403 ACCESS_DENIED`;
  - security/audit hardening.
- `Modules/Warehouse/routes/api.php`
  - authoritative `permission:*` enforcement on protected routes;
  - dedicated inventory/outbound warehouse-scope middleware.

## Source verification commands

```bash
composer test -- --filter=AuthorizationTest
composer test -- --filter=AuthRbacAuditHardeningTest
```

These tests are source-level/backend-controlled evidence. They do not replace an independent QA2/QA1 request against the deployed DEV/UAT environment.

## Required independent DEV/UAT evidence

For each WWHN-292 RBAC defect/scenario that depends on backend enforcement, QA/BE owner must attach:

1. exact deployed BE SHA and FE SHA;
2. environment/base URL;
3. named test account and effective role/permission/warehouse scope;
4. raw direct API request with UI bypassed;
5. expected 401/403 or allowed result;
6. actual response status/body and `request_id` / `X-Request-ID`;
7. before/after evidence when tied to a concrete UAT defect;
8. reviewer PASS/FAIL disposition.

Until this deployed evidence exists, the correct status is **BACKEND SOURCE CONTROLS PRESENT / INDEPENDENT DEV-UAT EVIDENCE PENDING**. Do not use this handoff as a self-sign for QA or UAT closure.
