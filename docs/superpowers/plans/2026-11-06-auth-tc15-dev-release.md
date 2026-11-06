# Auth Owner Inventory And TC15 DEV Release

> **For agentic workers:** Use superpowers:executing-plans; continue the user-approved inline implementation method.

**Goal:** Close the eleven Auth owner-inventory gaps, ship P08 v2 to DEV/TEST,
and enable authorized business delivery without canary/self-test or historical replay.

**Architecture:** Existing P10 handlers already implement personal actor/family
guards through a container binding. Make eight route targets explicit and declare
eleven exact P10/P11 actions in IN_CODE_GUARDED_ROUTES. No prefix exemption, grant,
new permission, endpoint, migration or owner-guard change.

**Tech Stack:** Laravel/PHP; disposable SQLite/PostgreSQL; existing DEV Docker deployment.

**Spec:** Existing P08 Reversal design + current user request of 2026-11-06;
subsequent explicit choice enables business delivery on DEV/TEST only.

## Constraints
- Preserve all newer remote dev work, P08 v1 and opt-in mini-app-p08-v2.
- Canary OFF, self-test OFF. Business delivery ON only after release checks.
- Remove the configured canary selector for normal delivery mode without deleting
  the closed policy, spent TEST budget, receipts or the revoked native binding.
- No seed, repair, real migration, real account login/revoke, production or TEST resend.
- Refuse activation if any old PENDING/RETRYABLE/CONFIGURATION_ERROR/SENDING backlog
  exists; UNKNOWN is check-only. Do not repurpose old TEST bindings/keys.
- Web/native must register legitimately with their current owner and installation.
- Authenticated runtime readback is not replaced by anonymous health or config.

## Review Focus
- Exact controller actions, including route cache; no broad self-scope bypass.
- Personal endpoints reject caller actor overrides and foreign session/device IDs.
- P11 receipt/step-up protocol remains OFF and never allows revoked-token access.
- Combined release preserves latest inventory/warranty changes and FCM cleanup.
- Empty backlog and unchanged old terminal/UNKNOWN rows; no business broadcast spam.

## Tasks
- [ ] Write failing exact-owner tests and reproduce the generic eleven-route gate.
- [ ] Route the eight P10 actions directly; add eleven exact owner declarations.
- [ ] Run Auth/P10/P11/P08/FCM regressions on both disposable databases + route cache.
- [ ] Run default project suite, audits and independent review; record all residuals.
- [ ] Commit and fast-forward push only after checking current remote ancestry.
- [ ] Deploy source-only DEV release, preserving data/media/secrets/config flags;
  refresh caches and restart PHP/queue/scheduler/reverb, without live migration/seed.
- [ ] Audit the DEV-only config transition to business delivery ON, canary selector
  unset, self-test OFF; recheck sender/platform readiness, registry and backlog.
- [ ] Authenticated P08 v2 reads and business-notification web/native readiness;
  publish checksummed App handoff with exact runtime and remaining hardware/network gaps.
