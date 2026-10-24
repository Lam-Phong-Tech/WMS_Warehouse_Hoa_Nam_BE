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
