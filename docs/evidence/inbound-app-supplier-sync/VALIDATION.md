# Inbound app supplier synchronization — 2026-11-17

User authorized full implementation, DEV deployment and historical supplier backfill. Base runtime/source: `ccb26e4342c454a26bf9504c3af5d9d5ef1a54a8`.

## Isolated validation

Tests run in dedicated containers with network disabled, independent source copies, SQLite in memory and a separate PostgreSQL 16 test server accessed through a private Unix socket. No tests used the running application database.

- Baseline unmodified P04 + inbound supplier tests: **42 tests, 629 assertions passed**.
- RED: missing canonical supplier and missing legacy fallback reproduced; missing command reproduced.
- Final SQLite inbound/app/canonical/concurrency regression: **77 tests, 1,004 assertions, 1 PostgreSQL-only test skipped**.
- Final PostgreSQL inbound/app/concurrency regression: **69 tests, 947 assertions passed**.
- Report-failure regression observed RED, then GREEN: postcommit outcome-write failure preserves the prepared before/after manifest and does not misreport database rollback.
- PostgreSQL POSTED backfill failure reproduced against original guard. New guard regression observed RED, then GREEN: **17 assertions** exercise scoped exact fill and prohibit unsanctioned supplier changes, notes, versions, status and DELETE.
- Independent review covered controller transactions/replay, fallback precedence, command concurrency/report durability and the narrow PostgreSQL migration; material report issue corrected and rereviewed, no remaining material findings.
- PHP lint and diff whitespace checks pass. Pint applied to changed PHP. Canonical stock write-path verification passes.

## Broader-suite limits

The complete 3,120-test suite was started on a frozen initial implementation copy and is recorded in the external operation directory. It contains failures beyond this change. A baseline run of the original source independently reproduces `AuthorizationCoverageInvariantTest::test_moi_route_can_dang_nhap_deu_co_permission_hoac_duoc_khai_la_tu_scope` for the two existing trusted-device routes. Baseline unit suite: 212 tests, 5,136 assertions, 2 skips, no failures. Some broad-suite evidence files are not writable in the deployed-artifact copy. Full details are retained with operation logs; no claim that the whole project suite is green.

Full run completed: 3,120 tests / 53,536 assertions, 234 errors, 37 failures, 64 skips, 6 risky. `full-suite-summary.json` names each reported issue with its first detail; no P04InboundContractTest or InboundSupplierFieldTest issue was reported. A separate unchanged-source representative run (Docker runtime permissions, outbound flow, P13 notification, go-live checks) reproduces 14 failures across 48 tests. These failures were not silently treated as a green project suite.

## DEV operation record

Private server operation directory: `/opt/wms-hoanam/operations/inbound-app-supplier-sync-20261117`.

Read-only preflight identified 25 eligible legacy receipts, including `PN-20261117072446` (NCC-007) and `PN-20261117001922` (NCC-001). The cancelled receipt `PN-20260925002826-NFIC` has no supplier evidence and must remain unassigned.

An encrypted full logical backup was produced and its complete decryption and restore catalogue verified. Encryption key is kept outside the operation directory in the server secrets hierarchy. No plaintext database dump or key is stored in this repository.

Deployment and actual apply/verification results are recorded separately after the tested source is released. Recovery instructions: `docs/ops/INBOUND_APP_SUPPLIER_SYNC.md`.

First tested release `d88f131ccdae3ad54179aa70e932684e73c3d155` passed DEV CI and deployment (Actions run 44105453182). First historical apply encountered the existing POSTED legacy mirror guard and rolled back entirely (updated=0, status=rolled_back). Read-only investigation identified a historical timestamp timezone projection difference. The follow-up migration adds only a transaction-scoped supplier-only early exit to four unchanged mirror bodies. A PostgreSQL regression reproduces the original failure using UTC-to-Asia/Ho_Chi_Minh session changes and verifies canonical supplier fill plus unchanged mirror values.

Follow-up final regression: **78 SQLite tests / 1,004 assertions / 2 PostgreSQL-only skips; 70 PostgreSQL tests / 959 assertions passed**. Mirror-timezone regression observed RED then GREEN (12 assertions). Independent review found no material findings in the follow-up migration.

The encrypted DEV backup was fully restored into a separate database `wms_hoanam_supplier_restore_testing` in the network-isolated test PostgreSQL container. Both reviewed migrations and the actual operation/verification script were executed on that clone: **25 updated, 0 remaining eligible, 3 conflicts preserved, 2 unselected suppliers left unchanged**. Hashes of all other receipt header fields, metadata, receipt lines, stock movements, balances and the entire inbound mirror remained identical. Original sample list/detail read-controller responses were correct both before and after canonical backfill. This rehearsal used no live test receipt or user login session.
