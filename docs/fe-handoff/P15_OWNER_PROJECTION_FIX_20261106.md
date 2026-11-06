# P15 Owner Projection Correction - Source Only

Date: 2026-11-06, Asia/Ho_Chi_Minh.
Status: SOURCE_TESTING_DEPLOYMENT_HELD_BY_USER.

## Diagnosis Correction

The failing assertion in P15OwnerReadContractTest was line128,
`assertJsonMissingPath(data.document.actor_id)`. It was NOT the subsequent
`cancel_allowed=false` assertion, which had not executed. Earlier attribution to
cancel_allowed was incorrect and must not be used as a release exception or fix spec.

P04 native has emitted document.actor_id since af74e7f4 (2026-10-07), and the P04
canonical contract test requires it. P15 test/collection wrongly treated P04's
projection as the generic P03 projection. Removing the established P04 field would
break the owner contract rather than fix it.

## Source Hardening And Contract

- P04 current document.actor_id comes from mini_app_drafts.actor_id after checking
  authenticated actor, current stable family, document, warehouse, scan session
  and INBOUND operation. Never substitute the viewing user for an unverified owner.
- Missing/mismatched persisted owner identity returns404, not fabricated/null data.
- P03/P05 generic document projection continues to omit actor_id. Do not require
  that field globally or infer it from current directory/role/viewer metadata.
- Reconcile receipt.command/version identifies the original persisted command.
  Current document.version/state can differ. Keep these separate.
- data.response remains the unchanged stored transport response. Do not inject a
  current P04 actor, current version or current state into historical response data.
- cancel_allowed guard is unchanged: only the exact latest SAVE, same current
  version/last_request_id, owned DRAFT and effective cancel permission may allow it.
  UNKNOWN/old/wrong-command receipts cannot authorize a cancellation.
- The real CANCEL endpoint repeats owner/version/confirmed-receipt guards.
- New controlled parallel families cannot inherit the other family's P04 receipt
  or current draft, even when both belong to the same account.

Contract file: contracts/p15/owner-read-v1.json,
revision p15-owner-read-r2-persisted-p04-actor. Existing route/meta contracts remain
mini-app-p04-v1 and mini-app-p03-v1; no new endpoint or schema.

Postman collection now documents the distinction and checks P04's persisted actor
when the current document is present. All methods remain GET; no login, write,
automatic retry or credential export is added.

## Verification And Release Boundary

See the exact-SHA evidence bundle for final DB/cache/test results. Defensive
projection tests were watched RED before source hardening. Read tests compare
domain snapshots and stored response to detect accidental mutation.

User explicitly selected "fix P15 first, do not deploy yet". No deployment,
configuration activation, real seeder, migration, repair, RECORD/Post or production
action is authorized by this document. Auth max2 source remains unpublished to
shared dev and unactivated until a later explicit deployment instruction.

Warehouse realtime release 0a8998d9 was merged/preserved locally, not rolled back.
Its invalidation hint is separate from the Auth/P15 source-only results. App/native
live acceptance, WSS and original TEST-intent transition remain separate evidence.
