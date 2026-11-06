# Controlled Multi-Session For Web And Android

Date: 2026-11-06, Asia/Ho_Chi_Minh.
Status: USER_AUTHORIZED_DEV_IMPLEMENTATION_SECURITY_REVIEW_AND_TESTS_IN_PROGRESS_NOT_ENABLED.
Baseline: deployed DEV/TEST 5a97f58d00b0688ea7dac50427e6e3cca4bfd241.

## Implementation Authorization And Resolutions

On 2026-11-06 the user explicitly requested security review, replay/revocation
completion, tests and DEV/TEST deployment with max2, no seed or production. This
authorizes the bounded engineering implementation, not a fabricated organizational
BA/security signoff. Independent code review and verified runtime evidence remain gates.

Resolution: reject every revoked refresh token and quarantine only its persisted
exact family in controlled mode, without guessing the historical revocation reason.
Unknown/expired tokens cannot revoke other live families. Existing explicit global
security/password/account-lock revocation remains account-wide. No backfill or new
schema is necessary for this uniform exact-family containment rule.

Actual route correction: P10ProfileController overrides logout and already performs
parent locking, presented-token validation and exact-family revocation. The base
AuthController revoke-all fallback is not the active logout handler. Reuse and
regression-test P10 rather than adding another logout implementation.

Proposed implementation uses an OFF-by-default DEV-only controlled switch and an
explicit draining switch; max2 is not caller-controlled. Legacy mode's wire
contract stays v1; controlled metadata is auth-session-policy-v2. These resolutions
supersede the corresponding open alternatives below, without changing owner
identity, production or the separate realtime/Android TEST-intent blockers.

## Decision And Scope

The user selected **controlled multi-session design**, rather than keeping the
single-session acceptance model, and confirmed the proposed two-family limit with
"duyet" on 2026-11-06. This approves that numeric limit and design direction, not
security-policy approval, new APIs, schema changes, activation or production.

Goal: the same account can keep an independently authenticated Web session and
Android session alive, with separate owner identity, warehouse context, refresh
rotation and notification binding. A new admitted login must not displace the other.

User-approved limit: at most **2 active authentication families per account**,
irrespective of client platform. It is approved for the design, not deployed.
Two browser profiles could consume both slots. It is NOT a guarantee of exactly
one physical browser and one physical Android device.

Non-goals: token sharing, session/shift/draft takeover, automatic eviction, automatic
END, new grants, mandatory MFA activation, P11 recovery activation, old TEST replay,
App data deletion, historical repair, production or a promise of full warehouse sync.

## Approaches

- Single active session: existing behavior; does not meet simultaneous Web/Android.
- Controlled independent families: selected direction; propose a bounded family
  quota of two and reuse existing P10 ownership/refresh/installations infrastructure.
- Platform-reserved slots are not the recommended first implementation: current
  X-Device-Id/platform labels are client-declared, not verified physical identity.
  Counting them as security proof would allow quota manipulation. Verified-device
  enrollment would be a separate design and security approval.

Do not implement this by setting AUTH_SINGLE_ACTIVE_SESSION_ENABLED=false alone.
The existing boolean also controls persisted families for access-only logins and
rejecting sid-less legacy access. Preserve those protections in both policy modes.

## Current Source Constraints

- AccountSessionPolicy::enabled() exposes the single-session boolean.
- RefreshTokenService::issueSession() holds the parent-user row lock, marks older
  rows replaced and revokes all prior credentials on a new login in that mode.
- JWT sid currently identifies a refresh row, not necessarily the stable family.
  Resolve it through the existing P10SessionFamily/P10ProfilePolicy logic; do not
  silently redefine sid or auth_session_id on the wire.
- ClientInstallations::activeFamilies() already groups COALESCE(auth_session_id,id).
  The device identity explicitly says CLIENT_DECLARED_INSTALLATION_NOT_VERIFIED_PHYSICAL_DEVICE.
- AuthController::logout() currently falls back to revokeAllForUser() without a
  presented refresh credential. RefreshTokenService::rotate() also revokes all
  families for a revoked-token replay. These need explicit policy treatment.
- P10 exposes own devices/families and targeted revocation, but its current mutation
  responses explicitly have request_receipt_supported=false. Do not pretend these
  are durable P11 command receipts.
- P14 shifts and draft/command owners include auth_session_id. New authentication
  must never transfer those owners or convert UNKNOWN to failure/success.
- FCM binding ownership already includes user + family + installation proof/version.
- Reverb currently uses private-user.<user_id>; this does not provide independent
  warehouse synchronization or proven per-family socket revocation.

## Admission And Quota

1. Complete existing password/MFA requirements. No active family or quota eviction
   on a failed password, an uncompleted challenge or denied transport.
2. Under the existing parent-user lock, reload ACTIVE account/security state and
   count distinct live families, not refresh rows or claimed device IDs.
3. An expiry/revocation is authoritative from persisted state. Rotated ancestors
   must not consume additional slots or become active again.
4. If below the proposed limit, create one new stable family and issue its tokens
   atomically. Credentials/token issuance failure rolls back the admission.
5. At the limit, deny the new admission without revoking another family, changing
   its version or updating a shift/draft. Proposed error: HTTP409
   AUTH_SESSION_LIMIT_REACHED, disclosed only after full authentication.
6. The owner can explicitly manage another own session using the reviewed P10/P11
   owner protocol. No automatic oldest-session eviction or role-name exemption.
7. A missing/untrusted device label cannot bypass the family count. Multiple Web
   tabs sharing a family count once; independent profiles/families count separately.
8. Refresh rotates credentials inside the same family and consumes no new slot.
   Concurrent login/refresh/revoke/password changes use the same lock order.

Keep current refresh expiry semantics initially. Idle limits and absolute lifetime
are separate policy decisions; do not silently impose a new TTL in this release.

## Logout, Revocation And Replay

Proposed normal-operation behavior:

| Action | Intended Scope |
| --- | --- |
| Ordinary logout | Current verified family only |
| Logout without refresh credential | Resolve signed sid to the live family; no revoke-all fallback |
| Explicit revoke one own family/device | Exactly the authorized target; preserve current-family protection |
| Explicit revoke other sessions | All other families, preserve the current family |
| Password reset/change, account lock, security revoke-all | All affected account families as required by existing owner policy |
| Warehouse/permission removal | Rights removed across all families; no stale grant/subscription reuse |

Never accept a caller user/family/device identifier in place of the presented owner.
Logout must not end a work shift, delete a draft, erase a journal or mark a pending
command completed. A family that is REVOKED/REPLACED remains terminal after rollback.

**Security decision required:** distinguish an already logged-out/revoked family
from credential replay indicating compromise. The existing revoked_at field alone
does not reliably encode why a historical token was revoked.

- Proposed treatment of known ordinary ended-family credentials: deny them without
  destroying another valid family; retain an audited terminal reason.
- Proposed rotated-token reuse containment: revoke the affected family and raise
  an audited security event. Account-wide revocation remains available for confirmed
  compromise or explicit policy/owner action.
- This changes the current account-wide replay response and MUST receive explicit
  security approval before implementation/activation. It is not approved by choosing
  the multi-session direction alone.
- Historical rows with missing reason remain UNKNOWN_LEGACY, not backfilled by
  guessing. The security owner must choose their fail-closed treatment before gate opens.

If durable terminal reasons/new policy records require schema, propose an additive
migration after inspection. No table deletion, fresh migration, historical rewrite
or claim of zero migration is part of this design approval.

## Unknown Login And Security Commands

Request-ID is correlation unless an owner contract explicitly provides a durable
receipt. Never auto-retry login after a lost response to allocate another family.
Do not store or return plaintext password, access/refresh tokens or MFA secrets in
generic operation receipts or a session-list GET.

An already authenticated owner can inspect their session list and deliberately
resolve an orphan admission using the approved owner flow. A list entry does not
prove a specific lost command succeeded. UNKNOWN security commands keep the original
identity and CHECK_ONLY rules; do not enable P11 recovery just to make this work.

## Warehouse, Shift And Business Ownership

- Preserve family-scoped warehouse context. Switching warehouse on family B must
  not change A; both still require current user rights and tenant/warehouse checks.
- Preserve shift_id + actor_id + auth_session_id + warehouse_id and owner versions.
  Family B may read authorized data but cannot RECORD/END/reconcile as family A.
- Do not auto-create a replacement draft/shift or copy an UNKNOWN request to B.
- Existing one-active-shift constraints remain; multiple authentication families
  do not authorize multiple active shifts. Review source/database constraints and
  surface the owner conflict instead of weakening them.
- Refresh within a family retains the original stable identity and installation.
- No forced MFA enrollment or bypass of enrolled MFA/step-up. Requirements for
  admitting an extra family need a separate security decision; existing MFA gates
  and P11/P14/P19 recovery flags stay unchanged until reviewed release approval.

## Push And Realtime

Each installation consents and registers through its own live user/family/proof
and canonical version. Never copy a Web binding to Android or reactivate the old
canary TEST binding. Original TEST keys/journals remain immutable and check-only.

FCM may fan out an authorized recipient event to multiple valid bindings; recheck
account/family/warehouse/permission/category/current binding/TTL at drain time.
Deduplicate per delivery/binding version, not globally by user so one device does
not consume another's event. Provider acceptance is not received/displayed/read.

Current notification audience can exclude the actor and honor bell preferences.
Multi-session auth alone therefore cannot synchronize another device of that actor.
Warehouse data sync must be a separate approved stream, independent of bell settings,
with post-commit publication, scope, version, dedup and reconnect/snapshot rules.

Do not promise immediate socket termination from the existing contract, whose
push_disconnect_supported=false. Before sensitive multi-session warehouse streaming,
specify and verify family-scoped subscription lease/revocation and permission changes.
A user-only channel authorized once is insufficient evidence for that guarantee.

The existing public DEV WSS404, Android retained-TEST transition, client bridge build
and future warehouse stream/ACK contracts remain separate blockers. This design
does not mark any of them fixed or approve new endpoints.

## Contract And Rollout

- Add an explicit reviewed policy mode instead of disabling all single-mode guards.
  Policy names/fields here are design proposals, not released .env/API contracts.
- Keep the existing mode for non-opted accounts and older rollout scope. The server,
  not a caller header/role name, determines eligibility for controlled multi-session.
- Version the policy contract returned in existing auth/profile reads; publish mode,
  family-count unit, actual quota and denial behavior without device secrets/PII.
- Existing sid/family/warehouse/FCM semantics remain unchanged. Compatibility tests
  must prove how current clients handle the new quota response and metadata.
- Default rollout OFF, DEV-only approved TEST owners first. No production activation.
- Add schema only if required, review fail-forward/backup plan before applying it;
  source design alone does not authorize migration or changes to the real DB.
- Audit admissions, targeted revocation, limit denial and policy change without
  logging credentials, tokens, installation proof, raw user-agent/IP or business PII.
- Rollback closes new multi-session admission, not silently revoking live owners.
  Define a draining state for existing families; do not re-enable replacement on the
  next login in a way that unexpectedly destroys owners/UNKNOWN journals. Explicit
  emergency revoke-all remains a separately authorized security operation.
- Delivery business ON / self-test OFF / canary OFF remains unchanged during design.

## Acceptance Matrix — Planned, Not Executed

- A Web login and B Android login both retain valid owner access without a
  replacement broadcast; wrong password/incomplete MFA cannot consume capacity.
- Third concurrent login denied; parallel admissions cannot exceed two families
  on PostgreSQL, with matching SQLite contract tests.
- Refresh/duplicate Web tabs keep one slot; expired/revoked rows do not gain a slot;
  malicious reused device labels cannot collapse multiple families into one.
- Logout A (with/without refresh token) preserves B. Targeted revoke and current
  family protection are exact; unknown/foreign identifiers do not mutate anything.
- Password change/reset, account lock, permission/warehouse removal and approved
  compromise response invalidate precisely the reviewed scope on all transports.
- Exercise the approved replay policy, ordinary ended-token replay and unknown
  legacy reasons. Replaced/revoked history is never revived by mode rollback.
- B cannot take over A's P04/P05 draft, P14 shift, P19 command or NFC/upload receipt.
- Warehouse switch/late response on B cannot change A's confirmed context or data.
- Two approved FCM bindings can independently receive a new authorized business
  event; stale binding, expired/revoked family and old TEST cannot send/rearm.
- Independent stream includes authorized actor devices even with bell disabled;
  scope change/revoke/reconnect and stale/out-of-order events require fresh resync.
- Lost login/revoke response is not blindly retried and never exposes credentials
  in a recovery receipt or GET response.
- Route/config cache, schema compatibility, normal/deny/expiry/concurrency and
  per-family live cases on DEV must pass before rollout; do not use fixture mocks
  or a healthy WebSocket as end-to-end/device acceptance.

## Approval Record And Remaining Gates

1. User/owner: **CONFIRMED 2026-11-06**, maximum two active families/account, following
   the explicit question about that limit. No physical platform reservation is
   promised. Review of the proposed denial/owner-management flow remains separate.
2. Security: extra-session authentication/step-up, replay containment and missing
   historical reason handling; legitimate revoke-all conditions.
3. BE/App: versioned policy compatibility, unknown-command behavior and independent
   family-scoped workspace/shift/installation semantics.
4. Ops/QA: scoped DEV rollout/draining/rollback and exact source/schema/test/live
   evidence. Production remains outside scope.

No feature implementation, config update, migration, seed, push, deploy or real
session operation has been performed for this design.
