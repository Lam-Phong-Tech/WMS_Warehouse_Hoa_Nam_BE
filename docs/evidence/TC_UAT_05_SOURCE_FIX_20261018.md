# TC_UAT_05 Source Verification - 2026-10-18

Branch: `codex/uat05-session-replacement-20261018`.
Base: `d1b4063a` (operations-only descendants of application `898d447e`).
Input: `apps/wms-mobile/handoff/UAT_SCAN_20261017/TC_UAT_05/BE_HANDOFF.md`.
Contract: `docs/contracts/auth-session-replacement-v1.json`.
FE handoff: `docs/fe-handoff/TC_UAT_05_BE_FIX_20261018.md`.

## Implemented

- Account-wide replacement is an explicit server rollout, not a client/device
  claim. There are no role exemptions. Default flag is false.
- Fully authenticated login and token generation share a transaction with
  previous-family revocation and persisted replacement markers. Password failure,
  unfinished MFA and issuance failure do not commit replacement.
- Global credential checks reject replaced access JWTs. Replaced refresh
  credentials return a terminal error without revoking the successor login.
- Refresh preserves family identity; independent PostgreSQL workers exercise
  parallel login and login-versus-refresh serialization on the parent user row.
- The guard rejects legacy JWTs without `sid` when enabled. Existing token
  transport, HttpOnly cookies and browser-origin allowlists remain unchanged.
- Metadata survives flag rollback; schema rollback refuses to discard used
  markers. Missing schema fails closed before login/refresh/protected commands.
- A fixture with an active shift, a saved draft and a pending-owner journal is
  compared before/after replacement. No auth replacement command changes them.

## Local Results

| Group | Result |
| --- | --- |
| SQLite auth/replacement/MFA/P10 regressions | 54 PASS, 575 assertions |
| PostgreSQL auth/replacement/MFA/P10 + two real process races | 56 PASS, 596 assertions |
| SQLite P01/P14/P04/P11 owner regressions | 62 PASS, 2,241 assertions |
| SQLite refresh transport/SEC-09/Swagger regressions | 35 PASS, 207 assertions |
| GoLive baseline/owner inventory | 28 PASS, 167 assertions |
| Pint, PHP syntax, contract JSON, diff check | PASS |
| Known-credential scanner and scanner self-test | PASS |
| Local OpenAPI generation with in-memory SQLite | PASS; generated localhost artifact not committed |

Final totals across these disjoint SQLite groups: 179 PASS, 3,190 assertions.
PostgreSQL: 56 PASS, 596 assertions. Final groups have no failures, errors or
skips. Every final test command exits 0; this is local evidence, not hosted CI
or live two-device evidence. Final result recorded 2026-10-18 17:23 +07:00.

PostgreSQL is the owned local cluster on `127.0.0.1:55433`, database
`p12_contract_test`. It has been stopped after verification. Each process race
uses a disposable committed schema; it does not run historical migration
`down()` chains to clean up. The standard destructive-test DB-name guard is
retained. All final PostgreSQL tests use one PHPUnit configuration and exit 0.

Local XML evidence is in `.tmp/final-hardening-20261017/uat05-*.xml`. Earlier
diagnostic files are not the final results: a public health test fixture had no
seed markers; historical migration teardown could not reverse an unrelated
compatibility view; an old alternate XML omitted fixture env and passed a
second configuration option. These were test harness problems and were fixed
without changing serving schema, weakening auth assertions or hiding failures.

## Not Performed

- No CI dispatch for this auth source change, no claim of full-repository CI PASS.
- No DEV/TEST or production migration, seed, repair, restore or policy activation.
- No login/revoke of real warehouse accounts or shared admin account to obtain evidence.
- No two-device/native/idle-client runtime acceptance. No raw live response pair
  or Request-ID is fabricated from local feature tests.
- No change to the earlier deployed runtime `898d447e`; that deployment is not
  evidence that this later auth fix is live.

Status: `IMPLEMENTED_SOURCE_PENDING_RUNTIME_AND_TWO_DEVICE_ACCEPTANCE`.
TC_UAT_05 runtime remains OPEN until the approved schema/config rollout and
dedicated TEST-account A/B evidence are captured.
