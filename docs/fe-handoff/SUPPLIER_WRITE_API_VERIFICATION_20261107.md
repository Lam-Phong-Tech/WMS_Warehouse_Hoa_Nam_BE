# Supplier write API verification

## Delivered behavior

- POST `/api/v1/mini-app/inbound/suppliers`: automatic global NCC code, trimmed name, optional ACTIVE/INACTIVE status; 201 and ETag.
- PATCH `/api/v1/mini-app/inbound/suppliers/{id}`: name/status only, required If-Match, row lock and version increment; 412 for stale edits.
- Active authenticated stable session and catalog.manage required. GET authorization unchanged.
- UUID/code/creation time and recorded receipt snapshots preserved by edits.
- No additional DB columns/migrations. Existing supplier-code migration must already be applied.

## Verification evidence

- Test-first run: 8 tests, 7 expected missing-route failures (POST 405/PATCH 404); implementation followed that run.
- Existing supplier business-code baseline: 12 tests, 109 assertions, passed.
- Final SQLite focused run: `php vendor/phpunit/phpunit/phpunit tests/Feature/SupplierWriteApiTest.php tests/Feature/SupplierBusinessCodeTest.php tests/Feature/P04InboundContractTest.php tests/Feature/AuthorizationCoverageInvariantTest.php --no-progress` — **62 tests, 1007 assertions, passed**. Includes invalid/immutable fields, auth/permissions, revoked sessions, inactive actors, stale versions, overflow, NCC-1000, and actual recorded-receipt snapshot preservation.
- PostgreSQL feature run: 10 supplier write tests passed; the original concurrency fixture failed only during DatabaseMigrations teardown because the existing supplier-code down migration intentionally protects assigned identities. Replaced rollback teardown with explicitly guarded disposable database rebuild.
- Final PostgreSQL concurrency run: `SupplierWritePostgresConcurrencyTest` — **1 test, 22 assertions, passed** against dedicated local `127.0.0.1:55439 / supplier_api_test`. Four independent authenticated workers created NCC-001..004; two same-version PATCH workers returned 200 and 412, leaving version 2. No live application DB used.
- PHP syntax: all six changed/new PHP source/test files passed.
- Pint: passed on all six changed/new PHP source/test files.
- OpenAPI generation passed; tracked JSON updated only for supplier GET/POST/PATCH and required response schemas. Route inventory confirms GET/POST/PATCH and catalog.manage on both writes.
- Independent review found no controller correctness issues. Identified and corrected a missing referenced schema in the selectively updated generated JSON.
- Full repository PHPUnit suite started separately; result pending at initial commit. Focused results do not imply the entire repository suite passed.

## Operational status

Source-only change. No deployment, live migration, seed/reset or production API calls performed. POST is not idempotent; clients must reconcile ambiguous creation responses before retrying.
