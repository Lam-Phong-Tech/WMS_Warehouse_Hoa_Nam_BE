# Supplier Business Code: Additive P04 Directory

This change adds display codes to current supplier master rows, not to old documents.
Release/migration status and the exact SHA must be read from the accompanying release evidence.

## Read

`GET /api/v1/mini-app/inbound/suppliers?status=ACTIVE&page=1&per_page=20&q=NCC-001`

The existing authentication, stable family and inbound read permission remain mandatory.
Global directory scope, sort/paging and existing `mini-app-p04-v1` envelope stay unchanged.
`meta.supplier_code_contract` is `supplier-business-code-v1`.

```json
{
  "id": "69397ebd-8183-41b3-8f97-fa2fb85043b6",
  "code": "NCC-001",
  "name": "Honda Vi\u1ec7t Nam",
  "display_name": "NCC-001 - Honda Vi\u1ec7t Nam",
  "code_status": "ASSIGNED",
  "status": "ACTIVE",
  "version": 1
}
```

`updated_at` is still the original existing directory value, not the code-allocation time.
Code initialization deliberately preserves old name/status/version/timestamps and UUIDs.
Rows introduced later without an allocated code return `code=null`, `NOT_ASSIGNED` and
plain name as display_name. GET never writes or invents a code.

## App Wiring

Extend `P04Supplier` with the additive fields. Use `display_name` in the supplier
picker and keep `name` as the unmodified raw supplier name. The current App source
reads `s.name`; adding server fields alone does not prove the current UI displays codes.
Do not concatenate the code into saved `name`, note, or historical source snapshots.
SAVE/RECORD still uses the existing supplier UUID/version fields and existing request
identity; do not send code/display_name as new write fields.

Existing P04/P12 document snapshots are unchanged. Their missing historical code is
not permission to backfill from today's directory. New current-directory codes must
not be represented as historical snapshot evidence.

## Data Safety

One additive migration, no reset or seed. Before real DEV migration: private encrypted
backup, actual restore into an isolated PostgreSQL clone, migration and field/table
fingerprint comparison. Run only the exact migration path. Code rollback keeps the
new column and codes; never run database restore or destructive down automatically.
Production, Auth v1, native sessions, delivery/self-test/canary and other flags remain
outside this change. No push TEST is part of supplier-code acceptance.
