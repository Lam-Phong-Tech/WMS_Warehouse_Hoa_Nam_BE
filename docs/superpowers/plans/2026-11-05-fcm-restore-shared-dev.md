# Restore FCM Into Shared DEV

> **For agentic workers:** Use superpowers:executing-plans; verify before every release claim.

**Goal:** Restore the reviewed FCM implementation on the latest shared DEV source, push the integrated source, and deploy DEV/TEST with delivery and self-test OFF.

**Architecture:** Merge current shared DEV rather than roll it back. Preserve the known-good FCM and narrow nginx strong-validator fix, all current timezone/report changes, dependency lock and migration history. Stage a source-only artifact over the actual runtime, run isolated SQLite/PostgreSQL and route-cache regressions, then activate a single source/config version across PHP services.

**Tech Stack:** Laravel/PHP, PostgreSQL, Docker Compose, nginx, FCM HTTP v1.

**Spec:** User confirmation on 2026-11-05 after review of `BE_FCM_RESTORE_ON_4743_20261105.zip`; that confirmation permits merge/test/push/deploy only, not another Android TEST or feature activation.

## Global Constraints

- DEV/TEST only; no production activation or configuration change.
- Delivery and self-test must both be OFF before restored workers/API start.
- No migration, seed, repair, grant, failed-job replay, UNKNOWN replay, token export or replacement login.
- Preserve the existing strong ETags and all current DEV changes; never force-push/reset shared DEV.
- Keep credentials outside source and mounted read-only; keep version fences and existing receipts/registrations.
- Run local isolated tests, not a new hosted CI workflow. Push reviewed commits with `[skip ci]`.

## Review Focus

- The restore must not start draining existing business outbox rows outside the approved scope.
- UNKNOWN must remain check-only, and the prior self-test lock ordering fix must survive.
- Latest PostgreSQL timestamp binding and report logic must remain byte-identical to shared DEV where untouched.
- No dependency or historical migration rollback; all four PHP services need coherent source/config caches.
- Shared DEV must contain FCM and the ETag fix so an ordinary later deployment cannot silently omit them again.

## Tasks

- [x] Verify incoming ZIP44/44 hashes, current runtime/remote `4743dfb1ab6594ed83f30802ebfc8cd52e25c88a`, missing FCM source/routes and registry/schema state.
- [x] Merge shared DEV into the preserved FCM/ETag branch without conflict; dependency and migration files match the current runtime baseline.
- [x] Independently review the integrated delta and verify source retention.
- [ ] Run isolated SQLite/PostgreSQL FCM, P13/profile, timestamp regressions and route-cache tests; run locked audits, Swagger and nginx transport checks.
- [ ] Push the integrated source fast-forward to shared DEV with hosted CI skipped, after a fresh remote drift check.
- [ ] Stage/recheck the source-only artifact, set both flags OFF in app.env and every PHP service override, rebuild private config/route caches and recreate DEV services.
- [ ] Verify exact SHA, routes/auth guards, credential readability, schema/registry/outbox unchanged, protected flags and production identities.
- [ ] Deliver checksum/evidence and App handoff. Authenticated read requires an existing owner session; no Android send is included in this restore.

## Initial Evidence

- ZIP SHA256 `9cb9c44b953e925a9542289fb9f222c94f2b4ea2be66472e52eeff615a8fdc76`; no private-key/Bearer-JWT markers.
- Supplied Windows SQLite evidence53 tests /614 assertions is historical, not a fresh run by this restore.
- Runtime/source and remote both4743; delivery env true but FCM controller/config absent, capabilities404 origin/proxy. Existing read-only credential mounts and nginx1cc overlay remain present.
- Live schema227, one binding and one PROVIDER_ACCEPTED delivery. No pending outbox rows observed. No writes performed during this preflight.
- Current composer.lock and installed vendor stamp both `8bd13681743b850ab26cc246927e93319c74c55d02d1e42cdb8b11e4f9bee9a3`.
- Fresh read-only reviewer completed the48-file merged delta after initial capacity errors and found no P1/P2 OFF-restore blockers. Unknown replay/lock ordering and preserved current timestamp/report source were explicitly checked. Retention pruning/owner opt-out remain available while delivery is OFF.
- Initial fresh QA: SQLite131/2794, SQLite cached44/383 and PostgreSQL FCM/profile83/2244 passed. Extended PostgreSQL P13/report group45 cases exposed one inherited invalid fixture: it UPDATEd label_payload after capture and correctly hit LABEL_SNAPSHOT_IMMUTABLE. Fix only the test to INSERT separate array/legacy payloads at capture; no trigger, migration or runtime report change. A fresh full matrix is required before push/deploy.
- On348c0980 the immutable fixture error is gone; PostgreSQL then exposed the new strict assertion's object-key-order assumption. JSONB object order is not semantic. Preserve the top-level array/string type assertion and compare the captured value without associative-key order. Re-run affected SQLite/P13 groups and remaining PostgreSQL route cache/Swagger/nginx; unchanged cached-SQLite and PostgreSQL FCM/profile groups may be reused ONLY after byte-verifying every other source/test input and recording their actual source SHA.
