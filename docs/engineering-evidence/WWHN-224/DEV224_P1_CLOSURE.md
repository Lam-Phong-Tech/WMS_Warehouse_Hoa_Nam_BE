# WWHN-224 — DEV224 P1 Closure Register

Version: 1.0.0
Target branch: `m1`
Scope owner: DEV (technical implementation); BA/PM/Data Owner approval remains recorded at WWHN-225 and QA2 independent execution at WWHN-226.

## 1. Scope disposition — DEV224-P1-01

`POST /api/v1/skus/import-excel` is **SKU-only**.

The broad parent WWHN-56 mentions master/SKU/item, but that parent wording does not authorize this endpoint to mutate generic master data or item instances. The current baseline treats SKU/catalog administration as MVP functionality while migration/cutover data packages remain governed by mapping, checksum, dry-run, reconciliation and Data Owner sign-off. Therefore:

- In scope: SKU rows only, including optional references to ACTIVE Product Master values (`BRAND`, `CATEGORY`, `MODEL`).
- Not in scope: generic Product Master import, Item Instance import, customer/supplier/location/routing/recipe import.
- Expansion gate: no code expansion until WWHN-225 contains a BA/PM/Data Owner-approved mapping/disposition.
- Runtime permission: `sku.manage`.

This file is the DEV mapping/source statement. It is **not** a substitute for BA/Data Owner approval.

## 2. Versioned SKU mapping

Canonical template version: `WWHN-172-SKU-IMPORT-v1.1.0`.

| Input column | Required | Target | Rule |
|---|---:|---|---|
| `sku_code` / legacy `code` | Yes | `sku.sku_code` | Uppercase; valid code format; duplicate file/DB rejected unless active DB row + `update_existing=true` |
| `sku_name` / legacy `name` | Yes | `sku.sku_name` | Required, max 255 |
| `unit` | No | `sku.unit` | Defaults to `cái` |
| `sku_type` | No | `sku.sku_type` | `PRODUCT` or `COMPONENT`; default `PRODUCT` |
| `brand_code` | No | `sku.brand_id` | Resolve ACTIVE Product Master type `BRAND`; unknown/inactive rejected |
| `category_code` | No | `sku.category_id` | Resolve ACTIVE Product Master type `CATEGORY`; unknown/inactive rejected |
| `model_code` | No | `sku.model_id` | Resolve ACTIVE Product Master type `MODEL`; unknown/inactive rejected |
| `power_or_voltage` | No | `sku.power_or_voltage` | Text |
| `package_variant` | No | `sku.package_variant` | `BARE`, `KIT`, `SET`, `BODY_ONLY` |
| `version_code` / legacy `version` | No | `sku.version_code` | Max 30 |
| `published_to_app` | No | `sku.published_to_app` | strict boolean aliases only |
| `is_serial_tracked` | No | `sku.is_serial_tracked` | strict boolean aliases only |
| `status` | No | `sku.status` | `ACTIVE` or `INACTIVE`; default `ACTIVE` |

## 3. Atomic report contract — DEV224-P1-05

`import_reports` is a mandatory traceability output for accepted workbook processing, including dry-run and row-validation rejection.

- Normal response (`200` or row-validation `422`) must contain a non-null `import_report_id`.
- The controller owns an outer DB transaction around `ImportService + ImportReport` persistence.
- If report persistence fails, API returns `503` / `SKU_IMPORT_REPORT_PERSISTENCE_FAILED`.
- Failure response guarantees `committed=false`, `partial_write=false`, `import_report_id=null`, `retryable=true`.
- COMMIT mode: report failure rolls back SKU create/update and business audit records.
- DRY_RUN mode: report failure rolls back the dry-run audit marker as well.
- The error is logged without workbook row values/PII; log context is request/checksum/mode/exception class.

## 4. Permission contract — DEV224-P1-04

Canonical permission for template, import and import-report endpoints is `sku.manage`.

The previous Swagger text `product.manage` was documentation drift. Route middleware and authorization tests already used `sku.manage`; source OpenAPI annotations, focused OpenAPI artifact, Postman artifact and FE handoff are aligned to `sku.manage`.

## 5. QA2 independent reconciliation — DEV224-P1-02

Run `QA2_RECONCILIATION_RUNBOOK.md` on the exact PR/merge SHA. DEV tests provide deterministic executable coverage but do not impersonate QA2 approval.

Coverage includes valid, duplicate-in-file, duplicate-in-DB, invalid header, enum, boolean, inactive reference, exact row/field/code/message, uploaded-file SHA-256 reconciliation, report persistence failure, dry-run no business writes, and commit rollback.

## 6. Evidence lineage — DEV224-P1-03

The historical review SHA `a3fd779bc95ac029e376186c2205549b542444567` is not used as a false current sign-off. A GitHub combined-status lookup performed during this remediation returned `404 Ref not found`, so no retroactive workflow/status is claimed for that unresolved historical ref.

This closure is intentionally produced through a new PR from current `m1`, with an exact-SHA workflow that uploads:

- PHPUnit/JUnit output;
- route list;
- generated Swagger JSON;
- source/contract hashes (`SHA256SUMS`);
- runtime versions and exact Git SHA.

The remediation PR/workflow run and merge SHA supersede the unresolvable historical review snapshot as reproducible closure evidence.
