# P12/P13/P16 All-tab contract

Date: 2026-10-15 Asia/Saigon

## Decision

Use the official aggregate endpoint:

`GET /api/v1/mini-app/documents?source=ALL`

FE must not fetch and merge pages from inbound, outbound and warranty owner
lists. The authoritative JSON contract is:

`contracts/p12-p13-p16/all-documents-v1.json`

The runtime response contract is `p12-mixed-documents-v3`. P13 historical
RECORD evidence remains separate and never overwrites current status/readiness.

## Status ownership

- `canonical_status` is the unmodified status from the canonical owner.
- `mini_app_status` is mapped by source, never one shared approval state.
- `WAITING_APPROVAL` applies only to an INBOUND document in SCANNING with all
  planned lines fully scanned.
- An inbound row can still be BLOCKED by `INBOUND_SKU_INFO_INCOMPLETE`.
- OUTBOUND uses PICKING, READY_TO_ISSUE, COMPLETED or CANCELLED.
- WARRANTY preserves its canonical lifecycle status and reports
  `OWNER_STATUS_ONLY` readiness.

## Counts and failure behavior

- `meta.total` counts the full authorized filtered selected scope before cursor
  pagination.
- `meta.source_facets` counts every authorized source under the same filters.
- Any failed authorized source returns 503
  `DOCUMENTS_SOURCE_UNAVAILABLE`; no partial list or partial total is returned.
- A denied source has availability DENIED and total null. It is not treated as
  an empty source.
- Deduplication key is `source:id`; document numbers are never identity keys.

## Verification

Isolated SQLite tests passed:

- 36 tests
- 1,892 assertions
- Full-set fixture: ALL total 3, each source facet total 1
- Each source filter returned exactly its one canonical row
- Fail-closed source tests, cursor paging and timezone tests passed

Authenticated DEV/TEST source smoke at 2026-10-15 05:36 Asia/Saigon:

- INBOUND: HTTP 200, total 440
- OUTBOUND: HTTP 200, total 257
- WARRANTY: HTTP 200, total 1
- Current deployed aggregate v2: HTTP 200, total 698
- 440 + 257 + 1 = 698

The runtime smoke verifies the current deployed v2 data surface. The v3 status
mapping in this change remains pending release; this package does not claim it
is already deployed.

No migration, seed, CI workflow, production activation or business write was
performed.
