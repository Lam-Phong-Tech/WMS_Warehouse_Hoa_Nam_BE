# DEV/TEST Timeline Code-Only Release

Candidate: `184bd43d95804a0c6f42d47ca64dfa39db40d9ae`.
Previous PHP: `a9053a21bc35ece367be0b76db727d4749b59855`.

The user requested deployment of the TC02/TC04 source handoff. Only the scoped,
opt-in read projection is implemented. This is not approval to invent a historical
job title, CHECK action, actor or description, and is not full TC02/TC04 UAT PASS.

## Verified Locally Before Deployment

- Candidate SQLite: 37 tests / 559 assertions; zero failures, errors or skips.
- Candidate PostgreSQL: 37 tests / 559 assertions; zero failures, errors or skips.
  Loopback `127.0.0.1:55434/p12_contract_test`, APP_ENV=testing, disposable local
  PostgreSQL cluster only. The owned cluster was stopped after testing.
- Isolated SQLite route cache: 14 tests / 251 assertions; counts overlap above.
- Swagger generated into an isolated output and the two header/error schemas verified.
- Five release guard tests plus ten existing P14 transport/tooling tests pass.
- These are targeted local tests, not a new CI run or the full application suite.

## Operation

Use the existing dispatch input `devtest_tc12_only=true`, with exactly one of:

- `TIMELINE-INSPECT-184bd43d`: read-only target/schema/hash/capacity validation.
- `TIMELINE-DEPLOY-184bd43d`: promote the pinned source after successful inspection.

All other operation booleans and deploy_uat/run_uat_seed remain false. The CI job
must be SKIPPED. The release and operations SHAs are reported separately.

The code-only helper preserves all fourteen reviewed PHP/runtime overlays, the
already-applied historical failed_jobs artifact, inner Nginx ETag templates and
outer DEV TLS vhost hash. It reuses locked dependencies and built assets only after
proving their source inputs unchanged. PSR-4 discovery of the new class is checked
in a network-isolated container with vendor mounted read-only.

Before switching, stop backend/queue/scheduler under maintenance, take a private
pg_dump, validate its catalog, and record complete public-table row/field hashes
and media-file hashes. Verify equality before reopening. Regenerate package,
config and route caches and Swagger; recreate backend/queue/scheduler and Nginx.
No migrate, seed, repair, restore, grant, auth login, RECORD, END or flag changes.

Expected schema remains 219 applied migrations, P11 receipt migration pending,
P11 OFF, P12/P14 ON, P19/recovery OFF. No production activation.

Public origin/proxy health and unauthenticated guards are deployment checks only.
Authenticated same-document GET and App adapter/device acceptance stay pending
until the approved existing TEST owner session/document is available. No new login
is performed to obtain evidence or replace the operator's session.
