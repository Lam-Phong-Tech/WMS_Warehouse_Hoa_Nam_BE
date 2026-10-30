# DEV CI and deployment

The backend and frontend repositories each have one primary workflow,
`.github/workflows/dev-ci-deploy.yml`. Pull requests into `dev` run checks only.
Pushes to `dev` run the same checks, then deliver the tested SHA to the DEV VPS.
Manual workflow dispatch runs checks without deployment.

Backend checks use `composer test` against disposable SQLite, followed by the
auth, RBAC and stock concurrency tests against disposable PostgreSQL 16.
Frontend checks use `pnpm lint`, `pnpm typecheck`, `pnpm test`, `pnpm build`
and `pnpm health:check`. Deployment never runs after a failed check.

The deploy job rejects a SHA that is no longer the head of `dev`. Each repo
stores a separate SSH key in GitHub Secrets (`DEV_DEPLOY_SSH_KEY_BE` or
`DEV_DEPLOY_SSH_KEY_FE`) and a pinned host key (`DEV_DEPLOY_KNOWN_HOSTS`).
The server's root `authorized_keys` forces each key to run only the matching
component of `/usr/local/sbin/hoanam-github-dev-receiver.py`; it cannot open a
general SSH session. The versioned receiver source is
`tools/deploy/github_dev_receiver.py`. Reinstall it as a root-owned, mode 0700
file on the VPS after changing it.

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
