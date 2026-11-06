# Controlled Multi-Session Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans for the user-requested
> direct implementation; TDD per task and one independent whole-branch security review.

**Goal:** Deploy DEV-only bounded two-family authentication without cross-family logout/replay damage.
**Architecture:** Reuse parent-user serialization and stable-family resolution. Add one OFF-by-default DEV policy switch, count distinct live families under that lock, and quarantine only the exact replay family in controlled mode. Preserve single-mode behavior and global security revocation.
**Tech Stack:** Laravel/PHP, isolated SQLite/PostgreSQL, existing immutable DEV receiver.
**Spec:** docs/superpowers/specs/2026-11-06-controlled-multi-session-design.md

## Global Constraints

- User explicitly requested security review, tests and DEV deployment on 2026-11-06.
- Max 2 active authentication families, not physical devices or refresh rows.
- No automatic eviction, account/session takeover, seed, real migration or production.
- Preserve delivery business ON, self-test/canary OFF, secrets, existing live owners and historical tombstones.
- TC15 remains excluded. Branch from deployed 5a97f58d; carry only existing exact Auth-owner and password-epoch fixes.
- No hosted CI or shared-dev push is implied; use isolated local/container checks and exact source identity for manual DEV deployment.
- Existing unrelated repository failures must be reported, not hidden or labeled PASS.

## Security Rulings

- Every revoked credential is rejected. In controlled mode quarantine its exact persisted family, independent of unknown historical reason; never infer an old revocation reason or select another family by user alone. No schema change needed.
- Unknown/random token hashes and expired tokens cannot revoke a live family.
- Single-mode replay behavior and explicit account-wide compromise/password/lock/revoke-all remain unchanged.
- Controlled mode is valid only on local/testing with localhost or the exact DEV origin; production/mis-scoped activation fails closed.
- Controlled policy uses a versioned auth-session-policy-v2 payload; v1 remains exact for legacy mode. Existing App parser ignores unknown policy metadata, so App handoff must explicitly add v2 to pre-command/foreground policy handling; server guards remain authoritative.

## Review Focus

- Forged/mismatched refresh credential on logout must not revoke the token owner's family.
- Access-only and sid-less legacy tokens cannot bypass persisted-family admission.
- Parent lock must cover count plus issuance; refresh/failed issuance cannot consume a third slot.
- Historical replaced rows and unknown-reason revoked rows never regain validity after policy changes.
- No claim that auth concurrency fixes WSS404, warehouse streaming or Android retained TEST intent.

## Task 1: Admission And Contract

Files: config/refresh_token.php; Modules/Auth/app/Support/AccountSessionPolicy.php;
Modules/Auth/app/Support/RefreshTokenService.php; tests/Feature/ControlledMultiSessionTest.php.

- [ ] Write HTTP tests: A/B survive; C gets409 AUTH_SESSION_LIMIT_REACHED; wrong password/incomplete MFA consumes no slot; access-only gets a persisted family; rotation keeps family; production request fails503.
- [ ] Run SQLite tests RED and preserve JUnit.
- [ ] Add controlledMode(), replaceExistingSessions() and assertAdmissionAllowed(User) policy methods; preserve enabled() as enforced-family gate. Limit is constant2, no caller-controlled quota.
- [ ] Keep parent-user locking and atomic credentials issuance; enforce DEV guard before any write; expose v2 contract only for controlled mode.
- [ ] Run admission tests GREEN and legacy AuthSessionReplacementTest.

## Task 2: Exact Logout And Replay Quarantine

Files: Modules/Auth/app/Http/Controllers/AuthController.php;
Modules/Auth/app/Support/RefreshTokenService.php; tests/Feature/ControlledMultiSessionTest.php.

- [ ] Write RED tests: logout with/without refresh preserves B; foreign/mismatched credential409 mutates neither family; replay A's rotated ancestor invalidates A successor but not B; old inactive/unknown legacy token never harms B; password change still revokes all.
- [ ] Resolve signed sid to current live family and validate any presented raw token before revocation. Preserve old-mode fallback unchanged.
- [ ] Contain replay with P10SessionFamily::revoke on the persisted exact family; audit without claiming attacker identity or exposing credentials.
- [ ] Run all new and legacy session/profile/security tests GREEN.

## Task 3: Security And Isolated Verification

Files: tests/Feature/ControlledMultiSessionTest.php; isolated private QA scripts;
docs/contracts/auth-controlled-multi-session-v1.json.

- [ ] Add PostgreSQL concurrency proof: two admissions racing for one remaining slot yield one success and one limit denial; same parent lock/order as rotation.
- [ ] Test rollback on issuance failure, legacy mode, swapped ownership, replaced history, account lock/global revoke, family-scoped warehouse and live FCM eligibility.
- [ ] Run new/Auth/P10/P11/FCM cohorts on SQLite/PostgreSQL and route cache. Test only disposable DB/network/storage; no live seeder or sender.
- [ ] Run project core command, Composer/npm audit, formatting, Swagger generation and a broad repository survey with correct writable QA-only artifacts. Report every non-green cohort and baseline attribution.
- [ ] Independent whole-branch security review; fix important findings RED-to-GREEN before release.

## Task 4: DEV Release And App Handoff

Files: private pinned deploy/config receiver wrapper; docs/fe-handoff controlled multi-session release report.

- [ ] Commit exact tested source, verify baseline ancestry and no migration/dependency change. Do not include TC15 or overwrite newer remote/runtime work.
- [ ] Back up current DEV compose/env, retain key digests and existing FCM cleanup evidence. Use canonical source receiver, no migration/seed.
- [ ] Source deploy with controlled mode OFF, verify runtime/caches/route gate; then activate only the new DEV switch and recreate PHP services with preserved existing secrets/config.
- [ ] Verify same SHA/policy/max2 on backend/queue/scheduler/reverb, migrations unchanged and normal delivery ON with test/canary OFF.
- [ ] Authenticated smoke requires owner-supplied TEST credentials or owner-driven login; supplied account names alone do not authorize credential extraction/reset. Never replay operator families to produce evidence.
- [ ] Return exact source/runtime SHA, schema/route/status, tests with honest residuals, raw redacted response/RIDs, App v2 adapter requirements and live/native OPEN_BLOCKERS.

No mutation to real session data is authorized merely by writing this plan; live
operations use the user-approved TEST owners and do not alter business/shift/history.
