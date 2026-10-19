# Two-Part UAT Verification - 2026-10-19

Input: `BE_2_PARTS_UAT_20261019.zip` from the mobile handoff directory.
Branch: `codex/uat12-projection-handoff-20261019`.
Base: `a23bce83709d302f8d064fec6b9e7e92e08766c0`, matching the remote dev head inspected before edits.
No unrelated changes in the primary worktree were reset or included.

## TC12: Source Fixed, Acceptance Partial

- Existing P04 typed detail has `data.inbound_metadata` in source; P12 canonical stock details had no supplier/creator projection. No duplicate endpoint was added.
- Added `data.p12.owner_projection` only on the two existing stock detail GET/HEAD routes, with contract `p12-owner-projection-v1`, revision `p12-owner-projection-r1`.
- Supplier ID/name/version/time come only from the exact P04 RECORD metadata. Current directory changes, source_name/source_reference, document title and caller identity do not fill historical gaps.
- Creator ID comes from the fresh canonical parent's created_by. Historical name/role are null because the canonical owner does not persist those snapshots. A separately named current directory label is optional and requires iam.user.view; that lookup does not establish historical name or role.
- Projection rechecks live parent identity/type/deletion/warehouse and owner read permission even after a shared parent-cache hit. It is never written into that cache. Optional storage errors remain SOURCE_UNAVAILABLE, not missing legacy data or an empty parent.
- PostgreSQL's recorded_at column has zero fractional precision; serialization preserves that precision and does not invent microseconds. SQLite retains the explicitly stored fractional fixture text.
- PostgreSQL compatibility-view soft deletion maps users to INACTIVE with a null deleted_at projection; eligible current directory labels therefore require both ACTIVE and not-deleted, without changing the view or account state.
- Existing IDs, versions, statuses, P04/P05 receipts, attachments/download implementation and source-reference semantics remain unchanged.

On 2026-10-19, the user confirmed using the current creator name with explicit source attribution. FE should render `creator.current_display_name` with its current-directory status and `current_display_name_source=app_user.display_name`, clearly labelled as a current directory name, not a historical snapshot. This resolves only the display-source choice. The existing iam.user.view guard, null historical name/role and supplier snapshot rules are unchanged; remaining owner/IAM/privacy review and live acceptance are not signed off by this decision. TC12 is not a full UAT PASS.

## Final Local Test Results

| Group | Database | Tests | Assertions | Result |
| --- | --- | --- | --- | --- |
| New projection + P12 read/v2/parent-safety regressions | SQLite memory | 34 | 541 | PASS |
| P04/P12 mixed/P13/Swagger regressions | SQLite memory | 70 | 1979 | PASS |
| Owner guard inventory + document timestamp HTTP | SQLite memory | 11 | 379 | PASS |
| New projection + P12 read/v2/parent-safety regressions | PostgreSQL | 34 | 547 | PASS |
| P04/P12 mixed/P13/Swagger regressions | PostgreSQL | 70 | 1979 | PASS |

SQLite total: 115 PASS / 2899 assertions. PostgreSQL total: 104 PASS / 2526 assertions.
These tests were executed against source commit
`1375dfaa58dc212f4ca4ff52fe8ca68a2fb28a43`. The subsequent user-decision update
changes only the contract description and handoff/evidence documents, not runtime
PHP, schema or tests. Existing JUnit results are reused, not reported as a new run.
All listed final commands exited 0, with no failures/errors/skips. Earlier diagnostic
projection-only reports are not acceptance reports and are excluded from delivery.
The global-reader fixture explicitly bumps its RBAC version after changing scope;
the scope guard itself was not weakened to make the test pass.

Additional checks: Pint, PHP/contract JSON parsing, diff check, known-credential
scanner and its self-test PASS. Local OpenAPI generation PASS. The locally
generated localhost Swagger artifact was restored to its original tracked value;
only the relevant source annotations are changed.

PostgreSQL tests ran on the owned cluster at 127.0.0.1:55433, DB
`p12_contract_test`, behind the existing disposable-test DB guard. No serving
database was used. The owned cluster was stopped after the tests.

## TC05: Existing Evidence Supplied, No Reimplementation

- Existing application SHA: `9d9e87382eea39deb3f3d660b3955110b526ea3c`.
- Existing operations SHA: `1b1fb69d93c7a056879333405e7c7b0bddd0481b`.
- Existing release run: `39124139975`, SUCCESS, not full-repository CI PASS.
- Existing A/B HTTP observations: 2026-10-18 18:43:39-18:43:42 +07:00. Raw redacted JSON contains 14 responses with Request-ID and selected safe security headers.
- Existing source auth evidence: SQLite 54 PASS / 575 assertions; PostgreSQL 56 PASS / 596 assertions, including two independent-process login/refresh races. Auth source/tests are unchanged by this TC12 patch.
- The new delivery includes the previously referenced raw redacted JSON, auth contract, release report and final auth JUnit evidence. It does not replay admin login or revoke any current session.
- A no-credential public health GET on 2026-10-19 10:44:36 +07:00 returned HTTP 200, application SHA 9d9e8738, migration count 218, Request-ID `ba550a51-7551-4bd8-a1fd-954793a8b38b`. This is identity/health evidence only, not authenticated TC12 or a new TC05 smoke.

TC05 remains BE_DEPLOYED_PENDING_UI_DEVICE_RETEST, not BLOCKED_BE/NOT_DEPLOYED.
Expired/invalid JWTs share the existing UNAUTHENTICATED envelope; the published
replacement error is distinct, but the server does not invent a new expired-token
code. No push-disconnect event exists: next protected request detects replacement;
foreground checking/heartbeat and late-response generation handling remain FE work.

## Not Run Or Authorized In This Fix

- No CI dispatch, deployment, feature/config activation, serving migration, seed, repair or restore.
- No live authenticated TC12 request or supplier/creator response pair; no new Request-ID is fabricated for the new fields.
- No new login/revoke on admin or another real account; no START/END, RECORD/POST or upload outside disposable tests.
- No bytes/checksum validation of the user's downloaded web file without a supplied file path.
- No physical-device/foreground UI acceptance and no external email/share action.
- No owner/BA approval was signed by the coding agent.
