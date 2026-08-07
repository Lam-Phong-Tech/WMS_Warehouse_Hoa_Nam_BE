# Review and approval protocol — WWHN-155 1.0.0

## Immutable review target

Review the exact Git commit containing this release directory. Do not review a branch-only URL without recording the resolved SHA.

## BE1 verification gate

BE1 is complete only when the dedicated workflow records all of the following on the same exact SHA:

1. OpenAPI lint and JSON/static verification pass.
2. `php artisan test --filter BackendMigrationSeedHealthTest` exits `0`.
3. `php artisan migrate:fresh --seed` exits `0` on PostgreSQL.
4. Pre-seed `/api/v1/health` sample is HTTP `503` and sanitized.
5. Post-seed `/api/v1/health` sample is HTTP `200` with all required fields.
6. `/api/health` is HTTP `404`.
7. DB marker report shows one admin, one baseline warehouse and one baseline product.
8. Artifact `SHA256SUMS` verifies successfully.

## External gates

- QA1 / WWHN-157: DEV and UAT runtime smoke, including deployed SHA, health, login and master API evidence.
- DevOps / WWHN-32: environment URLs, build/deploy manifest, config/secret baseline, backup/restore/rollback and smoke evidence.

External gates may block final Done, but they must not be reported as a BE1 code failure after the BE1 exact-SHA gate passes.

## Disposition vocabulary

Use exactly one: `PASS`, `FAIL`, `BLOCKED_EXTERNAL`, or `SUPERSEDED`.
