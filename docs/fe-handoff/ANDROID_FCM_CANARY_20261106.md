# Android FCM Canary - App Handoff

User approved one native TEST and the 20-minute fenced design on 2026-11-06.
Do not ask for that same approval again. Do not interpret it as permission to
enable global delivery, send another TEST, replace a login, or mutate business.

## Current Release Boundary

Source is based on shared DEV ee8039f3 and preserves its SKU/report fixes.
This document is a source/runbook contract, not deployment or Android PASS proof.
Consult the release evidence ZIP for exact pushed/deployed SHA and execution data.
No migration or seed is required. Existing schema227 is retained.

Global delivery/self-test stay false. NOTIFICATION_DELIVERY_CANARY_FILE points
to an operator-owned file outside Git, mounted directory read-only in all four
PHP services. Closed default contents are {"enabled":false}. Missing/invalid/
expired files deny; no fallback to global even if a global flag is accidentally ON.

## First: Native Read-Only Diagnostic While OFF

The current R8 client does not send installation context on capabilities and
does not expose this diagnostic while disabled. It will correctly stay OFF.
App must wire an explicit diagnostic action with the existing API client and
existing platform identity; do not export private preferences, tokens or proof.
Do not obtain an FCM token or request POST_NOTIFICATIONS for this read-only step.

1. Capture existing delivery owner/scope and verify DEV origin/profile/tier as
   today. Load the existing installation identity through the platform port.
   If none exists, a local identity may be proposed; it is not server registration.
2. GET /api/v1/notification-delivery/installations/{installation_id}, using
   the normal authenticated client (never a new login). Headers:
   X-Installation-Proof from private identity; X-Notification-Installation-ID;
   X-Notification-App-Package=vn.info.lptech.wmshoanam;
   X-Notification-Canary-Diagnostic=notification-delivery-android-canary-v1.
3. Save a sanitized diagnostic report outside Git: HTTP status, Request-ID,
   meta timestamp, exact runtime SHA/tier/origin, installation_id, registration_id,
   binding_version, ETag, ownership/active and data.canary_diagnostic. No request
   auth/proof headers or FCM token. Its context-bound proof_fingerprint cannot
   authenticate an API request and is not raw proof. Send the local report path.
4. UNBOUND_CLIENT_PROPOSED means no matching installation persisted yet, not a
   successful native registration. SERVER_PROOF_VERIFIED verifies persisted
   proof, not device hardware/model. ADB serial never establishes ownership.

BE checks that report against the live owner family/account and registry in
read-only mode. A stale/replaced/other-session report must not open a canary.
No owner/installation IDs are guessed from the existing Web TEST.

## Capabilities And Registration

Add the same installation ID, proof and package headers to capabilities.
Without them canary capabilities stay DISABLED by design. Keep existing parser,
contract notification-delivery-v1, project/package and build-profile checks.
data.canary is additive and is not permission for a second TEST.

Once BE opens the verified manifest, the exact owner gets enabled=true,
configuration_status=CONFIGURED, platforms=[ANDROID], self_test_enabled=true,
plus canary mode/expiry/budget. Other clients remain OFF. Global flags stay OFF.

Obtain user0 notification consent through normal Android UI only. Use the
existing GET/PUT registration with proof and expected version, Android package
header and current scope; strong ETag must be preserved. Keep returned binding
ID/version. UNKNOWN registration means GET the same installation; no blind PUT.
A changed token or binding version cannot silently replace the target in a run.

## Exactly One Client TEST

Keep one durable client request_key, body/context/version. Add installation ID,
proof and package headers to test-self as well. BE does not send this POST.
Only the first key may create a receipt. Same-key replay never creates another
notification/provider send. Different key conflicts even after rate-limit expiry.
No retry of UNKNOWN, RETRYABLE or CONFIGURATION_ERROR in this one-attempt canary.
GET test-self/{original_key} with original owner/proof is the only reconciliation.
No key changes, re-registration, clearing intent or compensating TEST to get PASS.

The existing six-field FCM data hint is unchanged; no title/PII in transport.
Observe provider accepted vs received/displayed/clicked/read independently.
Do not PATCH notification read merely to create evidence.

## Close And Opt-Out

Window is at most1200 seconds. Operator can atomically close the file earlier.
Policy is read at admission, enqueue, drain and sender, including after OAuth.
Closure cannot retract a request already in flight. Original-owner receipt GET
and exact-version DELETE installation remain available after OFF/expiry.
Use current strong If-Match; never delete other installations or bindings.
Keep native UNKNOWN intent for support/reconciliation, not automatic resend.

## Operator Manifest (Private, No Example Live Identity)

Required keys: contract, enabled=true, run_id(UUID), user_id, auth_session_id,
installation_id(UUIDv4), proof_fingerprint(from diagnostic), base_version,
binding_version, registration_id, starts_at/expires_at(RFC3339),
source=system.notification.test, max_messages=1, max_provider_attempts=1.
Unbound fresh install: base0, target1, registration_id=null. Already active OWN:
pin the exact registration ID and equal base/target version. Do not rotate or
take over another binding. One run has a unique durable dispatch key; changing
run_id to get another message is outside approval.

Before opening, all PHP processes must show identical manifest digest, tier,
origin, project/package and both global flags false. Check pending jobs/outbox;
canary worker query cannot select unrelated rows. Never drain globally to prove it.

## Release/QA Evidence

Return exact source/deployed SHA and ancestry, schema/routes, Composer/npm audits,
SQLite/isolatedPG/route-cache results and scoped negative/UNKNOWN cases. Keep
default-branch Dependabot and production/GoLive warnings separate and OPEN.
No source/config/GET200 is Android UAT. Native positive diagnostic, registration,
one TEST and opt-out are pending until observed from the existing client.
