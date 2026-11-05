# FCM DEV Disabled Rollout Plan

> **For agentic workers:** Use superpowers:executing-plans for this verification and deployment run.

**Goal:** Review, test, and deploy the approved FCM candidate to DEV/TEST with both delivery switches OFF and exactly three additive FCM migrations.

**Architecture:** Preserve the live DEV source overlays, dependencies/assets, private storage, and protected configuration. Build the locked dependency tree in a separate artifact, verify the candidate against isolated SQLite/PostgreSQL databases, and activate only after migration/rollback safeguards are verified.

**Tech Stack:** Laravel/PHP, Composer, PostgreSQL, Docker Compose, Firebase HTTP v1.

**Spec:** `D:/ScannerHNApp_Handoff/NOTIFICATIONS_FCM_20261105/BE_FCM_DEPLOY_PROMPT_20261105.md` plus the user's explicit 2026-11-05 approval; that approval overrides the prompt's later enablement step.

## Global Constraints

- DEV/TEST only; no production container, configuration, database, DNS, or activation changes.
- `NOTIFICATION_DELIVERY_ENABLED=false` and `NOTIFICATION_DELIVERY_SELF_TEST_ENABLED=false` throughout rollout and handoff.
- No seed, repair, backfill, stock mutation, user login/revocation, or FCM send/validate request.
- Migration allowlist: `2026_11_05_000001_create_notification_delivery_registry`, `2026_11_05_000002_create_notification_delivery_outbox`, `2026_11_05_000003_create_notification_delivery_test_receipts`.
- Credentials remain outside Git, release source, logs, and handoff; read-only mounts, least-privilege readability.
- Do not overwrite concurrent source/config changes or mutate the rollback vendor tree; do not trigger hosted CI implicitly.

## Review Focus

- OFF flags prevent registration/delivery/self-test side effects even when scheduler jobs run.
- Binding/session/version ownership and UNKNOWN semantics do not regress across token refresh or replacement.
- Additive DDL and nullable dispatch keys preserve historical notification and business rows.
- Locked dependency installation, route cache, and existing P13/P10 routes remain compatible.
- Runtime source overlays and all non-FCM protected flags survive artifact activation.

## Task 1: Source and Live Preflight

- [ ] Verify manifest hashes, candidate ancestry, clean source, current runtime/compose identity, and existing overlays.
- [ ] Independently review the 47-file FCM delta, migrations, and OFF behavior; fix blocking defects with focused RED/GREEN tests if found.
- [ ] Record baseline migration set, schema/data fingerprints, protected config, production container identities, disk capacity, and rollback source/vendor.

## Task 2: Isolated Artifact Verification

- [ ] Install strictly from `composer.lock` into a new artifact/vendor tree; run Composer and npm audits without changing the live dependency tree.
- [ ] Run FCM registry/outbox/prune/self-test/transport plus P13/P10 regressions on SQLite and the dedicated PostgreSQL test cluster; repeat affected routes with route cache.
- [ ] Verify additive migrations on isolated PostgreSQL and check CLI scheduling, lint/format, OpenAPI generation, and explicit disabled capability semantics.

## Task 3: Controlled DEV Rollout

- [ ] Recheck baseline drift; create a protected encrypted backup before DDL and preserve release/config rollback artifacts.
- [ ] Keep both switches OFF, mount the existing approved credential read-only, and apply only each allowlisted migration path after verifying no unrelated pending migration is run.
- [ ] Activate one coherent source/vendor/config artifact for API/queue/scheduler/reverb; rebuild caches through the privileged startup process and keep auto-migration disabled.
- [ ] Verify exact runtime SHA, schema count/delta, routes, flags, credential readability, unchanged historical data, and unchanged production identities.

## Task 4: Evidence and App Handoff

- [ ] Collect read-only HTTP evidence with Request-ID and Asia/Ho_Chi_Minh timestamps; do not create or replace an authenticated session.
- [ ] Package source/runtime state, migration result, tests/audits, headers, OFF flags, checksums, and remaining authenticated/device gates without secrets.
- [ ] Report deployment separately from live push acceptance; no provider delivery claim while delivery is OFF.

## Progress

- Preflight: ZIP manifest verified 47/47; no private-key marker; candidate `065fad598285f9b46010b9a021660d3bdab8b199`, base/remote dev `8f2b2ec3dd01f50f26c9339d0f8b8df80b39e0ff` at initial read. Worktree clean before this plan was added.
- Live preflight: DEV source matches the base, 224 migrations, no pending historical migration. Old vendor and production service identities are preserved. Approximately 78.7 GiB free.
- Independent source review found no OFF-rollout blocker; two activation bugs were verified and fixed in scope: automatic UNKNOWN resend and reversed self-test row-lock order. PostgreSQL regression proof: unchanged candidate failed all three assertions (3 tests / 15 assertions), then the two minimal fixes passed (3 tests / 29 assertions), with no provider request.
- Initial isolated SQLite compatibility run: 94 tests / 2513 assertions; only the existing Swagger human-summary gate failed for nine upstream P10 and six FCM routes. Add only explicit summaries, not routes, privileges, or generic gate exceptions. Full final verification remains required.
- Build ruling: allow only the existing locked Composer merge plugin while suppressing scripts; `--no-plugins` omitted module autoload mappings and is not a valid release build. Composer's warning about the explicitly approved `google/auth=1.55.3` pin is retained, not treated as a schema error. Composer audit and npm audit returned zero advisories/vulnerabilities.
