# SKU Excel import atomicity

## Fail-closed parsing

`ImportService` accepts only these explicit values:

- `sku_type`: `PRODUCT`, `COMPONENT`
- `status`: `ACTIVE`, `INACTIVE`, `DRAFT`
- `package_variant`: `BARE`, `KIT`, `SET`, `BODY_ONLY`
- Booleans: `true/false`, `1/0`, `yes/no`, `y/n`

An unrecognised nonblank value produces a row error such as `INVALID_SKU_TYPE` or `INVALID_PUBLISHED_TO_APP`; it is never silently converted to a default. Empty enum fields retain their documented defaults and empty boolean fields remain `false`.

## Transaction and audit behavior

The service rolls back when any row error is collected, including duplicate codes already in the database. Successful rows from the same file are not persisted after rollback. `ImportController` writes an `IMPORT` audit event only when `error_rows` is zero; failed imports therefore have no SKU or audit side effect.

## Automated proof

`tests/Feature/SkuImportTest.php` covers mixed valid/invalid rows, duplicate database codes, explicit inactive status, invalid booleans, and a failed multipart request with no `IMPORT` audit record.
