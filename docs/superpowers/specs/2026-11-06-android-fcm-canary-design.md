# Android FCM One-Test Canary

Approved by the user on 2026-11-06: a 20-minute, one-owner/family/installation
Android canary, with business delivery globally OFF. This is not production,
native UAT, new-login, migration, seed, grant, or server-sent TEST approval.

## Approach

Use an external read-only manifest and existing registry, unique notification
dispatch key, self-test receipt and outbox tables. A global flag-only switch
cannot isolate old jobs; UI-only or POST-only filtering cannot fence the sender.
An isolated transport would duplicate the existing durable receipt protocol.
The chosen design fences existing admission, registry, enqueue, drain and sender.

## Identity And Activation

Keep the latest shared DEV ancestry. An opt-in diagnostic GET on the existing
installation route works while OFF, with the existing native JWT, stable family,
installation UUID and proof header. It does not obtain an FCM token, register,
request device permission or send. It exposes only that caller's identifiers and
a context-bound HMAC fingerprint, never raw proof, access token or refresh token.
An unbound installation is labelled client-proposed, not a verified registration
or device attestation. Do not infer native ownership from ADB, model or Web IDs.

Capabilities require those installation headers and the Android package to
advertise canary admission. Existing clients without them remain safely OFF.
The manifest pins actor, live family, installation, proof fingerprint, base and
target binding versions (and registration ID when already bound). Its window is
at most 1200 seconds, only DEV tier/origin/project/package, and both global
delivery/self-test flags must remain false. No fallback to global delivery when
a configured manifest is absent, malformed, closed, expired or mismatched.

Do not start the window before native diagnostics are returned and verified.
Manifest directory is outside Git, mounted read-only in API/queue/scheduler/
reverb. Re-read at every boundary; no stale in-process admission cache.

## One-Test Budget

Only system.notification.test from notification with no business subject and
the manifest's unique dispatch hash may enqueue. Lock user then installation
then binding. A unique dispatch hash per canary run plus the existing receipt
binds the first client key durably; a different key cannot create a second TEST.
Same-key replay is readback only. Do not replay pre-canary receipts.

Drain selects only that run/owner/family/installation/version/receipt, not all
due rows. The canary permits PENDING with attempts=0 only. It never retries
RETRYABLE, CONFIGURATION_ERROR or UNKNOWN. Expired selected SENDING leases may
become UNKNOWN but cannot resend. Unrelated outbox rows are not rewritten.
The real sender revalidates the same persisted scope, lease and token fingerprint
before OAuth and immediately before FCM. One transport attempt is the maximum,
not a promise of receipt, display or click. Closure cannot retract an HTTP
request already in flight; document this boundary rather than claim otherwise.

## Closure And Compatibility

Closing/expiry disables new registration/test/send. Authenticated original-owner
GET receipt reconciliation and exact-version opt-out remain available while OFF.
No mass registry cleanup, key rotation, ownership transfer or token extraction.
Legacy notification-delivery-v1 payload remains valid; additive canary revision
and diagnostic fields do not alter the FCM six-field hint payload.

## Verification And Release

Tests: allow, wrong owner/family/installation/proof/package, version drift,
rotation/rebinding, disabled/malformed/expiry, second key, existing Web TEST,
old due jobs, provider UNKNOWN/retryable, real-sender bypass, expiry during OAuth,
GET/opt-out after closure. Run SQLite, isolated PostgreSQL and route cache;
preserve strong ETags. Review before merge/deploy, audit root locks, no hosted CI.
Deploy with a closed manifest and unchanged DB/data; return raw GET proof and
App diagnostic instructions. Activation and native TEST remain pending until
actual native diagnostics; BE must not POST a TEST on the App's behalf.
