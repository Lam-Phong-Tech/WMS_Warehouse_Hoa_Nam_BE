# P05 - PX Number At SAVE

Contract: `p05-save-numbering-v1`, revision `p05-save-numbering-r1`.
The immutable JSON contract is `docs/contracts/p05-save-numbering-v1.json`.
This is manual-create mode only; source-linked Web mode is unchanged.

## App Wiring

1. GET existing outbound context. Verify schema readiness and
   `data.document_number_assignment=WITH_DRAFT_SAVE`, `data.numbering.mode`,
   `data.numbering.allowed` and the declared contract/revision.
2. For a new manual run, use the original P03 draft/scan-session IDs and:

```http
PUT /api/v1/mini-app/drafts/{draft_id}
X-Outbound-Numbering-Contract: p05-save-numbering-v1
Idempotency-Key: <stable original UUID>
If-Match: "0"
```

```json
{
  "warehouse_id": "<confirmed warehouse UUID>",
  "operation": "OUTBOUND",
  "scan_session_id": "<original scan session UUID>",
  "title": null,
  "codes": [],
  "form": {"p05_create": {"name": "", "recipient": "", "address": "", "plannedInput": "1"}}
}
```

This body is an illustrative schema, not a live TEST identity. Keep actual P14
`X-Work-Shift-ID` and original stable `X-Request-ID` for tracked families.
Partial form is allowed. Existing P03 shape/size/security limits still apply.

3. Use only `data.document.document_no` as the official PX. Keep number separate
   from draft ID, scan-session ID, business UUID and optional name/note. Do not
   manufacture a name or PX from recipient, SKU, timestamp or a local UUID.
4. Later explicit SAVE uses current quoted version/new intended command key,
   retaining the same number even across midnight or metadata/address changes.
5. GET detail/list/reconcile with the same opt-in header and OUTBOUND query.
   Detail number: `data.document_no`; list: `data[].document_no`; reconcile
   current state: `data.document.document_no`. `data.response` is the original
   stored command response, not current state.
6. UNKNOWN is CHECK_ONLY: GET the original key/body identity. No blind mutation
   retry, replacement draft, changed key/context, or compensating document.
   P14 may return OWNER_RECONCILE_REQUIRED for a repeated tracked SAVE; GET the
   exact original receipt rather than relaxing the fence.
7. A legacy receipt without a number remains without one. No auto-backfill on
   GET/replay/resume. Number it only after explicit intent, current ownership
   and successful reconciliation using a new SAVE/current version.
8. RECORD uses the existing field matrix and existing endpoint. Keep original
   X-Draft-ID, X-Draft-Scan-Session-ID, X-Draft-Version, key and tracked request
   identity. Use the numbering header for the new mode. `data.doc_no` must
   equal the SAVE PX. RECORD does not Post Issue or decrease stock.
9. CANCEL retains the number; never reuse or locally recycle it.

## Compatibility And Permissions

- Selected Web source retains its own `source.doc_no`. Do not reserve another
  PX for that path or switch a numbered manual draft into source-linked mode.
- Header-free legacy contracts stay supported for old unnumbered objects.
  A known numbered object cannot bypass grant checks by dropping the header.
- Actual active outbound.scan plus manual_create/import grants are needed for
  new numbering/RECORD; outbound.view for reads; outbound.cancel for cancel.
  Role/Admin display labels do not replace grants. No grant is added by deploy.
- Address remains TEXT. UI snapshot dataset strings are not BE-validated
  province/ward codes and are never auto-migrated into historical records.

## Release And Acceptance

One additive migration creates only `outbound_daily_numbers` and
`outbound_draft_numbers`. No product/stock/document history is rewritten and
no seed is needed. Deployment runs only the allowlisted migration path;
pending P11 and production activation remain untouched.

After a number has escaped, old reservation-unaware code is not a safe
rollback: retain counters/reservations and use a reviewed forward fix.

Source tests, migration and health are not live write/App UAT acceptance.
Authenticated GET/TEST SAVE evidence needs a permitted existing TEST owner
and original session/shift context. Do not log credentials, create documents
solely for screenshots, Post Issue, or alter stock to obtain evidence.
The release evidence package records exact SHA, schema delta, tests, raw
read-only HTTP evidence and any acceptance steps still pending.
