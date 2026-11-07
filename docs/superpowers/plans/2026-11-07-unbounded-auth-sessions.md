# Unbounded DEV Session Admission Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline; verify the whole security diff before deployment.

**Goal:** Remove the two-family admission limit on DEV/TEST without weakening replay containment, revocation or business ownership.

**Architecture:** Retain stable-family enforcement and the compatible auth-session-policy-v2 wire envelope. Add a server-only validated maximum: default 2 preserves existing installations, explicit 0 means unlimited. DEV uses 0; neither controlled mode nor single-family security is disabled.

**Tech Stack:** Laravel/PHP, SQLite/PostgreSQL, existing Docker DEV runtime.

**Spec:** User confirmation on 2026-11-07: unlimited simultaneous devices; preserve replay/revocation; test and deploy DEV only.

## Global Constraints

- No seed, migration, repair, historical changes or production operations.
- No logout, replay or new login using active operator credentials.
- No hosted or automatic CI activation; run isolated tests manually.
- Keep delivery ON, self-test/canary OFF and all other runtime flags unchanged.
- Preserve existing session families and source/runtime overlays.

## Review Focus

- Invalid maximum must fail closed, never silently become unlimited.
- Unlimited admission must still respect draining, tier/origin and MFA/account checks.
- Replay quarantines only the presented persisted family; other families remain usable.
- Logout stays family-scoped; password changes/revoke-all remain account-wide.
- Deployment rollback must never fall back to single-session replacement or restore DB.

## Task 1: Admission And Security Regression

- [x] Add failing tests to ControlledMultiSessionTest for 5+ families, null limit metadata, scoped replay/logout, global revocation, draining and invalid config.
- [x] Verify RED on isolated SQLite: 5 expected failures / 0 errors against c333bdf6; final local security class 28 tests / 406 assertions PASS.
- [x] Add validated max_active_families config and minimally change admission/metadata in AccountSessionPolicy; no token-service edits.
- [ ] Verify SQLite/PostgreSQL, normal and route-cache, Auth/owner/FCM regressions and PostgreSQL concurrency.

## Task 2: Contract And Release

- [ ] Update auth contract revision and App instructions: null maximum means unlimited, never zero slots. Wire v2 remains for compatibility; max2 policy is removed on DEV.
- [ ] Review the complete diff, validate formatting, audits and Swagger; report known governance gates honestly.
- [ ] Commit the exact tested source with [skip ci]; stage a fresh immutable release.
- [ ] Verify baseline SHA/overlays, schema, flags and sessions; deploy only hoa_nam_dev services with maximum 0 and preserved controlled protection.
- [ ] Verify all PHP services, origin/proxy health and existing authenticated GET if available, without replacing its session.
- [ ] Deliver checksummed App handoff and distinguish automated security proof from live login acceptance.
