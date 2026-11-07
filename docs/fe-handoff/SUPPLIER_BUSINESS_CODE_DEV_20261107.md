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

### Supplier creation and editing

Both mutations require an active authenticated stable session and `catalog.manage`.
Use JSON bodies with no query parameters. Names are trimmed, nonblank and at most
200 characters. Unknown fields (including code, id, warehouse_id, version) are rejected.

`POST /api/v1/mini-app/inbound/suppliers`

```json
{"name":"Honda","status":"ACTIVE"}
```

Status is optional and defaults to ACTIVE. Returns 201 and `ETag: "1"`.
The `data` object has the same fields as one directory row. Codes are allocated
server-side from the greatest existing numeric NCC suffix, including inactive rows:
NCC-001, NCC-002, ..., NCC-999, NCC-1000. PostgreSQL allocation is serialized with a
transaction-scoped advisory lock. This POST is not idempotent: do not automatically
repeat it after an ambiguous network response; reconcile against the directory first.

`PATCH /api/v1/mini-app/inbound/suppliers/{supplier UUID}` with `If-Match: "1"`

```json
{"name":"Honda Viet Nam","status":"INACTIVE"}
```

Send at least one of name/status. Returns 200, the updated row and `ETag: "2"`.
The UUID, code, creation time and historical receipt snapshots remain unchanged.
Use the returned version on subsequent edits. Missing If-Match returns 428;
stale version returns 412 (reload before editing again); invalid payload/version
returns 422; absent supplier returns 404. Missing authentication returns 401,
missing permission/inactive actor returns 403, missing stable-session identity or
unavailable canonical warehouse returns 409. Allowed statuses: ACTIVE and INACTIVE.

No new DB columns are needed: apply the existing
`2026_11_07_235900_add_inbound_supplier_codes.php` migration if still pending.
These source changes alone do not establish deployment or migration status.

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
