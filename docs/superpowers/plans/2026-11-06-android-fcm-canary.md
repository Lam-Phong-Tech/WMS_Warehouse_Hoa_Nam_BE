# Android FCM Canary Implementation Plan

> For agentic workers: use superpowers:executing-plans, native execution with one fresh final review. User approved the 20-minute design and implementation on 2026-11-06; do not request the same TEST approval again.

**Goal:** Admit at most one native owner-scoped TEST without enabling business delivery.

**Architecture:** An external manifest is re-read by one canary policy, composed
with existing auth/proof/version checks. Existing unique dispatch, receipt and
outbox rows provide the durable one-key/one-attempt budget. Default is closed.

**Tech Stack:** Laravel/PHP, existing SQL registry/outbox, SQLite/PostgreSQL.

**Spec:** docs/superpowers/specs/2026-11-06-android-fcm-canary-design.md

## Global Constraints

- Latest shared DEV ancestry; no source rollback, dependency churn or hosted CI.
- Global delivery/self-test OFF, 1200-second maximum, TEST-only Android scope.
- No real migration/seed/repair/grant/login/stock/production mutations.
- No raw credential/proof/token in source, logs, evidence or chat.
- No Android POST TEST from BE; safe diagnostics precede activation.

## Review Focus

- Same-user second binding or rotated family must not inherit canary admission.
- A stale worker job or direct sender call must not bypass the manifest.
- Closure during OAuth must stop a not-yet-issued FCM request.
- Same key replays only; different key and retryable outcomes cannot send twice.
- Unbound diagnostics must not claim persisted native identity or require permission.

## Task 1: Admission And Diagnostic

Files: new Delivery/AndroidTestCanary.php; config/notification_delivery.php;
DeliveryConfig.php; InstallationRegistry.php; NotificationDeliveryController.php;
tests/Feature/NotificationAndroidCanaryTest.php.

- [ ] Write failing HTTP tests for closed diagnostics, target capabilities,
  registration and wrong owner/family/installation/package/proof/version.
- [ ] Run SQLite test; record expected RED assertions.
- [ ] Implement manifest parsing/fail-closed policy and additive diagnostic.
- [ ] Keep legacy global behavior when no canary file is configured.
- [ ] Run focused admission tests to GREEN; commit with [skip ci].

## Task 2: Durable Budget And Transport Fence

Files: NotificationDeliverySelfTestController.php; NotificationOutbox.php;
FcmHttpSender.php; GoogleFcmAccessTokenSource.php; canary policy; tests.

- [ ] Write failing one-key/one-attempt, old-outbox and direct-sender tests.
- [ ] Observe RED without network/provider calls.
- [ ] Use per-run unique notification dispatch hash, receipt-bound exact target,
  scoped worker queries, CAS claim and sender recheck; no schema change.
- [ ] Allow authenticated GET reconcile and exact opt-out after closure.
- [ ] Run all FCM/notification/profile regressions and route-cache tests; commit.

## Task 3: Release And App Handoff

Files: versioned canary contract/runbook; private operations/evidence scripts.

- [ ] Fresh review; test fixes RED to GREEN; SQLite and isolated PG verification.
- [ ] Audit Composer/npm root locks and nginx strong-validator regression.
- [ ] Recheck remote ancestry; fast-forward push with [skip ci], no force.
- [ ] Deploy source-only DEV with closed RO manifest; retain previous release.
- [ ] Verify all four services policy hash/config, schema227 and data fingerprints.
- [ ] GET origin/proxy, no TEST; package source/tests/evidence/checksums for App.
- [ ] Mark activation/native acceptance pending actual native diagnostic.
