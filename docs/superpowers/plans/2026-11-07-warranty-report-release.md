# Warranty Report And Auth/P15 DEV Release

> Execute directly with superpowers:executing-plans, TDD and one bounded independent review.

Goal: fix the OPEN warranty received-date report defect, then test and push/deploy
the reviewed P15 and max2-family Auth source to DEV/TEST under the user's renewed
2026-11-07 authorization. No seed, real migration/repair, history rewrite or production.

Architecture: reuse DocumentTimestamp parsing; normalize warranty received/created
source fields before COALESCE/filter/sort. Interpret query days in Asia/Ho_Chi_Minh
with inclusive start/exclusive next-day UTC bounds. Both on-screen and export/chart
queries use the same read-only helper; keep privacy/warehouse/owner guards intact.

## Tasks

- [ ] Freeze the existing failed case at a deterministic VN daytime; watch RED.
- [ ] Add day-boundary/offset/legacy/microsecond/null-received fallback regressions,
  table/summary/export parity and readonly domain snapshots in isolated databases.
- [ ] Add WarrantyReportTime helper and use it in BaM1 + Aligned warranty queries,
  including full-source summary/defect breakdown/component usage and normalized sorting.
- [ ] Preserve wire timestamp source strings; compute turnaround from actual instants.
  Update stale wall-clock assertion to compare instants, not re-label UTC as local.
- [ ] Bump only affected internal report cache revisions to avoid old result reuse.
- [ ] Run owner/Auth/report/realtime/route-cache SQLite+PostgreSQL matrices, core
  command and broad project survey, audits, Swagger and independent review.
- [ ] Confirm current remote/runtime ancestry; retain newer deployed realtime work.
  Fast-forward push only, commits [skip ci]; do not trigger hosted CI or force-push.
- [ ] Clear prior deployment HOLD only because of this explicit renewed instruction,
  retaining the old journal. Use verified atomic/source/config/recovery wrapper;
  source OFF first, then DEV controlled mode max2 ON. Keep delivery ON, test/canary OFF.
- [ ] Verify exact deployed SHA/policy/schema/routes and authenticated readback with
  the existing owner; no credential extraction/reset or replay of operator families.
- [ ] Publish checksummed App handoff with exact tests, raw redacted HTTP, policy v2
  adapter requirements, preserved history evidence and honest remaining live/device gates.

Review focus: aware vs naive source types; VN midnight and microsecond last second;
identical filtering before totals/paging/export; PII masking/scope/cache partition;
access-only quota expiry, exact-family revocation, rollback to DRAINING after exposure.
