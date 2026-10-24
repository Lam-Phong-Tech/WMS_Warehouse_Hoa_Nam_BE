# DEV/TEST App Candidate Promotion - 2026-10-24

Application source/tested SHA: `a9053a21bc35ece367be0b76db727d4749b59855`.
Operations commit is separate and may change only deployment tooling, tests for that tooling and documentation. The application archive stays pinned to the tested source.

## Authorized Scope

The user explicitly authorized DEV/TEST deployment and creation of the additional table after the assistant identified the exact P13 migration. This is not P11 security activation, production approval, seed/backfill, data repair or destructive migration approval.

- Only `database/migrations/2026_10_23_000001_create_mini_app_web_post_handoffs.php` may run.
- The migration creates an empty P13 handoff table, its index and foreign keys. It does not UPDATE/DELETE existing products, inventory, documents or images and does not backfill historical submissions.
- `2026_10_24_000001_create_p11_security_command_receipts.php` remains pending; P11 stays disabled until separate security/schema/rollout acceptance.
- Real database remains protected and seed permission remains false. No reset/fresh/rollback/restore, grant, new operator login, inventory or NFC command is included.
- No CI rerun. The operational workflow builds locked assets and executes the existing npm thresholds (runtime high/full-tree critical) without changing thresholds. Eight dev-toolchain warnings remain; zero-advisory/full audit acceptance is not claimed.

## Safety And Verification

1. Pin the current green release, database, runtime image, dependency lock, 14 overlay hashes, flags and original schema. Fail before stopping service if any drift.
   Read-only inspection found one pre-existing migration-history artifact absent from the serving source: `2026_09_09_122500_create_failed_jobs_table`. Retain the byte-exact file from historical commit `ebfd201c8fff74558de5b1bd14b36718bec1813d` (SHA256 `752aa2914ab7338f83d06f9faa763d4064117bcfba0a4aa328e43676a6cd2242`) in the new release only after verifying the existing column/unique schema. This restores source history, not database data: do not rerun it, edit the migration row or drop/recreate the table. Any other orphan or schema mismatch still blocks promotion.
2. Three-way merge the one overlapping staging file from its official previous base, verified runtime hotfix and candidate. Conflicts fail closed; never copy an official file over the hotfix.
3. Preserve the current images/vendor for rollback. Build assets separately; Composer lock is unchanged, so no PHP dependency/image rebuild is necessary.
4. Enter maintenance, stop application writers, fingerprint every existing base table and local media, create a private custom-format PostgreSQL backup and validate its catalog. Catalog validation is not a full restore drill.
5. Run exactly the approved migration path. Do not execute all pending migrations. Verify only one migration row and one empty handoff table were added; every other table row/field fingerprint and every media file must match.
6. Refresh config/route caches and Swagger, verify nginx and routes, restart backend/queue/scheduler, verify build and flags on each.
7. Verify public origin/proxy health and unauthenticated deny behavior. These are not authenticated App acceptance. Preserve the raw safe response and Request-ID.

Before reopening, failures restore only the previous code/services, not the database. Any additive table already committed remains intact; no automatic drop/restore is permitted. Failures after reopening require explicit review because new user traffic may exist.

## Operations

Use the existing dispatch workflow, `devtest_tc12_only=true`, all other mode/seed/UAT inputs false, and one exact confirmation:

- `APP-INSPECT-a9053a21`: package/build and read-only target/merge inspection, no serving changes.
- `APP-DEPLOY-a9053a21-P13-ONLY`: execute the narrowly scoped promotion above.

Production activation remains OFF. P11 review, legacy UNKNOWN support/retention, full-seed clone replay, authenticated GET/write datasets and Android/NFC UAT remain independent open gates.

## Completed Promotion

- Read-only inspection `40053867775`: SUCCESS, exact schema/overlay/merge checks passed. Initial inspection `40053439426` stopped before serving changes on the missing historical migration artifact; it is not a successful deployment.
- Deployment `40054019438`: SUCCESS, operations `4a551f0e624531999b24b54ebc0a718a8c23d910`, runtime application `a9053a21bc35ece367be0b76db727d4749b59855`. The separate job `ci` was SKIPPED; this is not full CI PASS.
- PostgreSQL backup created and catalog validated; only P13 applied, migration count 219. P11 alone remains pending, with no orphaned migration history after retaining the exact historical file.
- Every existing non-migration base table (112) retained its row/field fingerprint before reopening. All 13 local media entries retained their hashes. SKU1156/published933/image rows974/IN_STOCK2759 unchanged; no seed or historical backfill.
- Config/route caches, Swagger, nginx and backend/queue/scheduler source/flags verified. P11 false, P12/P14 true, P19/P14 recovery false, database protected, seed permission false and refresh-token body-origin list unchanged.
- Origin/proxy new public checks at 09:29:26-09:29:29 +07:00: health200 with the exact application SHA, auth/P12 without credentials401. These are not authenticated UAT.
- Free disk after deployment: 6,612,738,048 bytes. No cleanup, automatic restore, production activation, new auth login, stock command or NFC mapping was performed.
- Details and the updated App delivery checksum are recorded in `docs/evidence/DEVTEST_APP_RELEASE_20261024.json`.
