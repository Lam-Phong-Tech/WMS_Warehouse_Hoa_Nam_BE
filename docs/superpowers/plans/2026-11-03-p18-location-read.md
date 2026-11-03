# P18.S04 Authoritative Location Read Plan

**Goal:** Implement and deploy the requested read capability on DEV/TEST only.
**Spec:** BE_P18_S04_REQUEST_20261103.zip, SHA256 4d169bbf957fd5f890a2ec6a36317765f8c778768e706518d2e7b3f856622965.
**Architecture:** Opt-in versioned projection on existing item-instances/{id}
and containers/{id} GETs. Reuse persisted CONFIRMED putaway_events, actual TRAY
identity and current BOX parent; inherit ITEM location only from consistent
active container membership. No migration, placement writer or invented grid.
**Tech Stack:** Existing Laravel, P06 read snapshot, explicit-read-fields-v1.

## Constraints

- Old BOX/P06/Web responses remain unchanged without the new header.
- Exact IDs only. SKU is not an exact physical identity.
- Confirmed authenticated warehouse and organization scope before reads.
- Existing identity ACL plus sku.view; placement needs putaway.view/manage.
- Missing, denied, unavailable and inconsistent are separate; no fake values.
- Do not seed, repair, fabricate placement, mutate stock or touch production.
- Runtime overlays must be preserved; tests/source are not live/UAT proof.

## Tasks

- [x] Add HTTP tests; observe RED for new contract on existing detail routes.
- [x] Implement P18ComponentLocationRead, header dispatch and explicit projection.
- [x] Test valid BOX/ITEM, absent/ambiguous/stale source, membership, scope,
      tenant, permissions/revoke, timestamps, no writes and v1 compatibility.
- [x] Run SQLite/PostgreSQL, route-cache, OpenAPI and dependency audit.
- [x] Review changed source and publish contract/source mapping. Live acceptance
      remains distinct from the source tests and is tracked in the return file.
- [ ] Inspect DEV runtime and preserve release overlays; deploy exact candidate
      to DEV backend/queue/scheduler only. No general migrations or seed.
- [ ] Smoke authenticated GETs when a valid authorized session is available;
      report missing real placement dataset honestly, not as positive PASS.
