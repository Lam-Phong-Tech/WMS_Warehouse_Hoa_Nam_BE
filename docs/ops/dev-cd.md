# DEV CI and deployment

The backend and frontend repositories each have one primary workflow,
`.github/workflows/dev-ci-deploy.yml`. Pull requests into `dev` run checks only.
Pushes to `dev` run the same checks, then deliver the tested SHA to the DEV VPS.
Manual workflow dispatch runs checks without deployment.

Backend checks use `composer test:ci` against disposable SQLite. This is the
same named core regression command developers run locally; it covers
authentication, authorization, inbound/outbound posting, idempotency,
concurrency and migration/seed health. Five critical auth and stock tests
also run against disposable PostgreSQL 16. The full
`composer test` command remains available for broader manual regression.
Frontend checks use `pnpm lint`, `pnpm typecheck`, `pnpm test:ci`, `pnpm build`
and `pnpm health:check`. Deployment never runs after a failed check.

The deploy job rejects a SHA that is no longer the head of `dev`. Each repo
stores a separate SSH key in GitHub Secrets (`DEV_DEPLOY_SSH_KEY_BE` or
`DEV_DEPLOY_SSH_KEY_FE`) and a pinned host key (`DEV_DEPLOY_KNOWN_HOSTS`).
The server's root `authorized_keys` forces each key to run only the matching
component of `/usr/local/sbin/hoanam-github-dev-receiver.py`; it cannot open a
general SSH session. The versioned receiver source is
`tools/deploy/github_dev_receiver.py`. Reinstall it as a root-owned, mode 0700
file on the VPS after changing it.

The receiver updates the exact SHA in both `shared/app.env` and explicit PHP
service `environment` entries in `compose.json`. The P05 DEV rollout pinned
those entries, which override `env_file`. Until the installed receiver is
updated, a GitHub-tested backend artifact reports its verified `.ci-source-sha`
marker as the running build identity; malformed markers stop boot rather than
claiming an unverified SHA.

The receiver serializes both deployments with one lock. Frontend switches the
`/opt/wms-hoanam-fe/current` symlink only after validating the build manifest,
and restores the old target if live health fails. Backend backs up
`/opt/wms-hoanam/compose.json` and `shared/app.env`, recreates the five code
services against the new release, checks the API health SHA, and restores the
previous configuration if deployment fails. It never runs a migration or seed.
Changes to `composer.lock` or migration files are blocked until a separate
reviewed database/dependency release has prepared the VPS.

The successful deploy jobs verify the public frontend manifest or backend
health response after the receiver finishes. The previous release and backend
configuration backup remain on the VPS for rollback inspection.


## Reconcile an already deployed dependency release

The DEV release `4b02f4feb2e5b1f486443a3ada5aca5150591ba0-fcm-disabled-20261105`
contained an operations release that was missing from the `dev` source history.
The repository restores its exact Composer manifest/lock (including the deliberate
`google/auth` 1.55.3 pin and its already-serving Symfony updates), and these three
already-applied migration files, byte for byte:

- `2026_11_05_000001_create_notification_delivery_registry.php`
- `2026_11_05_000002_create_notification_delivery_outbox.php`
- `2026_11_05_000003_create_notification_delivery_test_receipts.php`

The lock SHA-256 is
`8bd13681743b850ab26cc246927e93319c74c55d02d1e42cdb8b11e4f9bee9a3`.
The migration SHA-256 values, in the order above, are
`75333eb5849fda55dc1ebbec44f8cbfdf1363c85cede8373ef2df918d212d08b`,
`a7144811c135d68aeb977dffafaf3d7a2cc37356212ea3f2db958a23a5ffc07d`, and
`3f49b87b1edf3e95ce0165157900bbfb0488427840a199974d37e2f544ae5a82`.

Keep the migration bytes unchanged: the receiver treats deployed migration history
as immutable. This reconciliation preserves the dependency and migration guards;
it does not replace the serving vendor volume or run a migration/seed on DEV.
Composer validation reports an informational exact-version constraint warning for
the intentional Google Auth pin; the manifest and lock are valid and consistent.
