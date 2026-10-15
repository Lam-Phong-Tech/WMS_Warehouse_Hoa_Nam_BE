# P23 persisted history metadata

Date: 2026-10-15 Asia/Saigon
Contract: `mini-app-p23-read-v3`
Endpoint: `GET /api/v1/mini-app/scan-sessions`

## Persisted projection

The projection reads only the two persisted batch/event adapters:

- `scan_batch` + `scan_event`
- `scan_batches` + `scan_record_events`

Batch fields are `scan_batch_id`, `warehouse_id`, `source_type`, `source_id`,
`created_by`, `created_at`, plus `started_at`, `ended_at`, `status` only when
the source schema actually contains them. Event fields are `raw_code`,
`result_status`, `message/reason`, `created_at`, and optional persisted
`actor_id`, `serial_number`, `sku_code`, `issued_quantity`, `event_type`.

Missing columns are returned as `null` with an explicit `NOT_RECORDED` or
`SOURCE_UNAVAILABLE` status. No `item_instances`, `sku`, `physical_codes` or
other current lookup is used to repair a historical row.

`latestEvent` is selected from the complete persisted event source for each
selected session, before page slicing, using `created_at DESC` then event ID
DESC. It is not the last event in the current page.

`source_type/source_id` is returned as a persisted reference only. No URL or
business target is fabricated. `issuedQuantity` is null unless an authoritative
persisted event field exists.

## Status/count rules

- `RESOLVED` remains `RESOLVED_NOT_ACCEPTED`.
- `OTHER` remains `OTHER_NOT_REJECTED`.
- `REJECTED` is exposed as `explicitRejected`; aggregate `rejected` remains null.
- When any authorized event source is unavailable, `meta.total` is null and
  `meta.availableTotal` contains only the readable persisted batch scope.
- Permission guard is `auth:api`, `history.read`, `inventory.view`, stable auth
  session, confirmed warehouse context, warehouse ACL and tenant/org checks.

## Verification

Isolated `P23ReadContractTest`: 7 tests, 112 assertions, PASS.

DEV/TEST read-only smoke at 2026-10-15 08:48:05 Asia/Saigon:

- HTTP 200
- Request-ID `35b5a59d-32a5-4458-965b-3a69547aa0c9`
- Current deployed contract `mini-app-p23-read-v2`
- Current total/availableTotal `6198/6198`
- Candidate v3 deployed: no
- No mutation, seed, migration or repair performed

The v3 candidate is source-only until deployed; the DEV result above is not
claimed as v3 acceptance.
