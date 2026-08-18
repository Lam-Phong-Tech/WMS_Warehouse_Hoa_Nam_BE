# Disposition — `apply-adjustment`

| Field | Value |
|---|---|
| Disposition ID | `DISP-ADJ-001` |
| Ticket | WWHN-219 (closes WWHN-218 open action `BE1-OP-03`) |
| Canonical repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Canonical branch | `m1` |
| Status | PROPOSED — requires BA formal accept before WWHN-219 closure |
| Option chosen | **B — business action name, no independent endpoint** |

## Decision

`apply-adjustment` is a **business action name**, not an API endpoint. It maps onto the
existing append-only Reversal/Correction surface. No route, controller or service named
`apply-adjustment` will be created, and no second stock-mutation path will exist.

QA must test the adjustment business action through the canonical endpoints below.

## Why Option B and not Option A

Option A (create the route as a thin orchestration layer over Reversal) was rejected
because the controlling baseline does not merely omit the endpoint — it forbids it.
Adding the route would clear a "missing route" flag while creating a new P0 governance
breach.

Evidence, in order of authority:

1. **`docs/HN_SRS_FRD_v1.12_FULL.md` REQ-SRS-013** (line 314) — "Reversal append-only cho
   Receipt/Issue; không có action sửa tồn trực tiếp." Priority `Must`.
2. **BR-REV-01** (line 398) — "Mọi sửa sai sau Post dùng append-only reversal; không
   UPDATE/DELETE movement gốc và không sửa Balance trực tiếp."
3. **SRS line 65** — "No stocktake: không có entity, API, permission, screen, report hoặc
   hành động sửa tồn trực tiếp trong MVP."
4. **`docs/contracts/HoaNam_Current_Baseline_Contract.json`** — lists `stocktake` under
   `forbidden_path_fragments`, and the `stocktake_*` tables under `excluded_tables`.
5. **`tests/Feature/Wwhn180PermissionAliasContractTest.php:98-99`** — actively asserts that
   matching `POST /api/v1/stocktake/sessions/{id}/apply-adjustment` throws
   `NotFoundHttpException`. Implementing the route would fail this test.
6. **Live route set** — `scripts/wwhn219-permission-catalog.php` regenerates the
   route-permission matrix from the booted router on every evidence build and fails closed
   if any route URI contains `apply-adjustment` or `stocktake`.

The only artifact defining `apply-adjustment` as a path is the task-scoped
`docs/api-contract/WWHN-180-posting-openapi.{json,yaml}`, whose FE1/BA sign-off is recorded
as `PENDING` in `docs/evidence/WWHN-180/FE1_MAPPING_SIGNOFF.md`. It is superseded by the
SRS/FRD baseline and by the current-baseline contract. The generated runtime spec
`storage/api-docs/api-docs.yaml` contains zero occurrences of either term.

**Do not edit that OpenAPI artifact to reflect this disposition.** Both files are
SHA256-pinned in `docs/evidence/WWHN-180/IMMUTABLE_HANDOFF_MANIFEST.json`
(`8922b027cdfbd02fd59c80a2c42900765451cb74e0f9edecc6675b47f7cfec4b`), and
`scripts/verify-wwhn180-immutable-handoff.mjs` verifies those digests. An immutable
handoff artifact is superseded by a later decision record that points at it — this
document — never by mutating it. Rewriting the file would silently invalidate the
WWHN-180 evidence chain.

## Canonical mapping

| Business action | Canonical endpoint | Permission |
|---|---|---|
| Adjust a posted inbound receipt | `POST /api/v1/inbound-documents/{id}/reverse` | `inventory.reversal.post` |
| Adjust a posted outbound issue | `POST /api/v1/outbound-documents/{id}/reverse` | `inventory.reversal.post` |
| Create a standalone correction document | `POST /api/v1/reversal-documents` | `inventory.reversal.post` |

All three require `Idempotency-Key` and `If-Match`, write append-only movements, and emit
audit entries, per BR-REV-02.

## Consequences

- `stocktake.adjust` remains absent from the permission catalog. Any reappearance is a
  regression and fails the WWHN-219 gate.
- The WWHN-180 posting OpenAPI artifact is **historical**. It must not be cited as the
  controlling contract for WWHN-219 acceptance.
- Locked by `tests/Feature/Wwhn219ApplyAdjustmentDispositionTest.php`.

## Required sign-off

This disposition is not closed until BA posts a single consolidated Jira comment on
WWHN-219 accepting Option B. Record the comment URL in the evidence manifest field
`apply_adjustment_disposition`.
