# Reviewed additive migration receipts for DEV

The restricted GitHub receiver is installed by root at `/usr/local/sbin/hoanam-github-dev-receiver.py`. Source is `tools/deploy/github_dev_receiver.py`; regression tests are `python3 tools/deploy/test_github_dev_receiver.py`. The SSH forced command remains pinned to `be` or `fe`. Installing a reviewed receiver is a separate operator action; this change does not install itself or alter the authorized keys.

The backend receiver still rejects historical migration edits or deletions. New migration files are accepted only when a trusted operator has independently reviewed and already applied the exact complete added-file set, then recorded a receipt outside the artifact. The receiver never executes migrations, creates or updates receipts, changes `AUTO_MIGRATE=false`, or seeds data. Its deployment report continues to record `migration_run:false` and `seed_run:false`.

## Operator sequence

1. Compare the actual active release's full migration path/hash map with the candidate artifact. Do not use the branch base or latest Git remote as a substitute for the running release. An older active release may require reviewing more added files than the current feature's single migration. Existing changed/deleted files remain prohibited by this path.
2. Independently inspect every added migration and confirm the SQL is additive and compatible with the old running application. Record the exact SHA-256 of each file. Confirm database backup and recovery controls appropriate to the actual operation. No receiver flag bypasses this review.
3. Apply only that reviewed set through the separately authorized manual migration procedure, using the same exact file bytes. Verify successful migration records and the resulting concrete schema. A failed or partially completed operation must never receive an `APPLIED` receipt. Artifact unpacking or a successful `--pretend` is insufficient evidence of application.
4. For the final exact committed candidate SHA, root writes the receipt below. Confirm that its base SHA and resolved release path still match the active release. If the base changed, recompute the complete diff and review the new deployment situation before issuing another receipt.
5. Independently review and install the receiver source at its root-owned system path. GitHub deployment may then cut over the tested source after its normal archive, composer-lock, service-environment, backup and health checks. Deploy the frontend after the API and schema are ready. The migration receipt is retained as evidence and is not rewritten by deployment.

## Trusted receipt location and format

For backend base `/opt/wms-hoanam`, the only accepted location is:

`/opt/wms-hoanam/operations/reviewed-migrations/<exact-new-40-character-lowercase-SHA>.json`

Every directory ancestor from `/` to `reviewed-migrations` must be a real root-owned directory, with no group/world write permission and no symlink. The receipt must be a root-owned regular file, exactly mode `0600`, with one hard link. Use a root-only operations directory such as mode `0700`. An artifact-local file, symlink, non-root file, permissive mode, duplicate JSON key, changed inode at open, or missing receipt is rejected. JSON is UTF-8 and bounded to 64 KiB. Approval cannot be supplied in artifact content, environment variables or request headers.

The receipt has exactly these keys; additional keys are rejected:

```json
{
  "schema": "hoanam-reviewed-additive-migrations-v1",
  "release_sha": "<exact new committed release SHA>",
  "base_sha": "<exact SHA read from active release .ci-source-sha>",
  "base_release": "<resolved absolute active release path>",
  "status": "APPLIED",
  "added_migrations": {
    "Modules/Auth/database/migrations/2026_11_04_190000_add_self_service_profile_preferences_and_installations.php": "55d9337a47b5e3023fc385f04e9bbe67ba3929127fa8efe5b47562ae0ba754de"
  },
  "applied_at": "<actual successful manual verification timestamp in UTC ISO 8601>",
  "verification": "MANUAL_MIGRATION_SUCCEEDED"
}
```

This is a schema illustration, not an approval receipt. Replace the placeholders only after the operator's successful manual application and verification. The map must equal the **entire** actual added-file set, including any previously undeployed migration, with exact case-sensitive POSIX paths and lowercase SHA-256 values. It cannot omit or add a file. The receiver compares the base SHA with the active release's own `.ci-source-sha`, compares the exact resolved base path, and checks the candidate's build marker and incoming SHA. The timestamp must be valid UTC and not in the future beyond five minutes of clock skew.

When migration maps are identical, the normal deployment path needs no receipt. If any historical file changed or disappeared, a receipt cannot authorize it; use a separately reviewed database release process. This guard deliberately does not infer schema safety from a filename or attempt to parse PHP into SQL. The trusted root receipt acknowledges the concrete independently reviewed and already-applied operations; it does not replace operator verification.

## Profile schema candidate

The profile migration above creates three small tables (preferences/version JSON, bounded private avatar base64, and declared client installation metadata), then adds nullable installation and actual login-start columns plus an index to `refresh_tokens`. It neither changes existing session-policy defaults nor backfills identities from request headers. PostgreSQL foreign keys target the physical `app_users.id`; SQLite/MySQL target `app_user.user_id`. Its exact hash is shown above and must be recomputed if the file changes.

The deployment report includes the accepted receipt content hash, exact release/base SHAs, added-file map and applied timestamp. It records acknowledgement of the external manual application, never an automatic migration run.
