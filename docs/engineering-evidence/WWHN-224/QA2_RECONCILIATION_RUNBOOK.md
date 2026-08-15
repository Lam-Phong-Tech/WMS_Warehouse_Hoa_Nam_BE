# WWHN-226 / WWHN-224 — QA2 Independent Reconciliation Runbook

This is an execution-ready handoff. QA2 must record its own PASS/FAIL on WWHN-226; DEV must not pre-sign that result.

## Exact-build precondition

Record:

- Git SHA: `git rev-parse HEAD`
- Branch/PR number
- DB engine/version
- PHP version
- API base URL/environment
- Artifact name and SHA256 manifest from workflow `WWHN-224 SKU Import P1 Closure`

## Automated gate

```bash
php artisan test \
  tests/Feature/Wwhn174SkuImportValidationTest.php \
  tests/Feature/Wwhn224SkuImportDryRunTest.php \
  tests/Feature/Wwhn224ImportReportFailureContractTest.php
```

## Reconciliation matrix

| Case | Expected API | DB/audit reconciliation |
|---|---|---|
| Valid dry-run | 200, `dry_run=true`, `committed=false`, non-null report ID | no SKU create/update; one DRY_RUN report; dry-run audit only |
| Valid commit | 200, `committed=true`, non-null report ID | SKU/audit/report counts agree |
| Duplicate in file | 422 with `DUPLICATE_SKU_CODE_IN_FILE` | no business mutation; rejected report |
| Duplicate ACTIVE in DB, update off | 422 `DUPLICATE_CODE` | existing SKU unchanged; rejected report |
| Invalid header | 422 `SKU_IMPORT_FILE_INVALID` | no SKU/business audit |
| Invalid enum | 422 `INVALID_ENUM` with row/field/message/value | no SKU/business audit; rejected report |
| Invalid boolean | 422 `INVALID_BOOLEAN` with row/field/message/value | no SKU/business audit; rejected report |
| Inactive master reference | 422 `UNKNOWN_MASTER_CODE` | no SKU/business audit; rejected report |
| Inactive SKU conflict | 422 `INACTIVE_SKU_CONFLICT` | inactive SKU unchanged |
| SHA-256 reconciliation | response `file_sha256` equals SHA-256 of uploaded XLSX | same checksum in `import_reports.file_sha256` |
| Forced report failure, dry-run | 503 `SKU_IMPORT_REPORT_PERSISTENCE_FAILED` | no SKU, no report, no dry-run audit after rollback |
| Forced report failure, commit | 503 same code | no SKU, no report, no `SKU_IMPORT_CREATE/UPDATE/COMMIT` audit |
| User without `sku.manage` | 403 `ACCESS_DENIED` | no mutation/report |

## QA2 evidence fields

For each sample capture: case ID, exact SHA, request ID, request/response body (mask secrets), HTTP status, file checksum, import_report_id (when expected), DB before/after counts, audit before/after counts, tester, timestamp, environment, PASS/FAIL, defect link if failed.
