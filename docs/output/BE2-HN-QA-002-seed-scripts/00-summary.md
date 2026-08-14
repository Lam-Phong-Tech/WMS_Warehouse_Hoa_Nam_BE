# Seed scripts evidence

## Traceability

- Parent Temp Key: `HN-QA-002`
- Epic Temp Key: `HN-EPIC-10`
- Task cha: `WWHN-34`
- Dependency: `HN-QA-002-ST01`
- Output: `Seed scripts`
- Role: `BE2`
- Assignee Placeholder: `HN-BE-02`

## Delivered scope

`php artisan wms:seed-uat` is an explicit, repeatable UAT seed entrypoint. It runs the existing baseline seed, then creates four UAT accounts using existing RBAC roles and one namespaced import traceability scenario.

The command does not print a password. It requires `--password` or `UAT_SEED_PASSWORD`; the repository only includes an empty `.env.example` placeholder.

Active evidence: `01-command-and-data.md`, `02-idempotency-and-security.md`, and `03-verification.md`.
