# P05 Numbering Authorization Parity

User requirement: an account authorized to create an outbound draft must receive
its PX on explicit SAVE. Do not edit IAM grants or Post permissions.

Baseline shared/source/runtime: fdf4b9cb5d5ee24f387087c2ffa9d07ca933464d.
Root cause: P03 effective policy recognizes the existing active SUPER_ADMIN owner;
P05 numbering ignores that policy and requires explicit role/direct permissions.
The result is permissions.save=true but numbering.allowed=false for the same actor.

Decision: recognize the canonical active SUPER_ADMIN authority for the numbering
owner as for P03. Do not infer authority from a display label or ADMIN role name.
Keep ACTIVE scoped grants for ordinary roles and all actor/session/warehouse/version/
idempotency/stock guards. Do not turn outbound.scan alone into manual-create rights.
Wire contract/revision stay p05-save-numbering-v1 / p05-save-numbering-r1 because
the current App checks exact wire revision. Add separate authorization-policy
metadata p05-numbering-authorization-r2; do not break existing parser semantics.
No GET allocation, fake title, history/backfill, migration, seed or production writes.

Confirmed user decision 08/11/2026: use the existing create authorization for SAVE
and PX numbering including canonical SUPER_ADMIN, with no additional IAM grants.
Shared dev advanced separately to 7ff24f3b with a historical notification-content
migration. Do not include or run that migration in this P05-only release; preserve
the shared branch and publish the candidate on its own release branch if needed.

## Execution

- [x] Add regression for canonical SUPER_ADMIN save/number parity, read-only deny,
      role revocation and misleading role label; observe RED before implementation.
- [x] Implement the policy parity and versioned contract/handoff, keeping legacy
      receipt/idempotency/cancel/source-linked behavior.
- [ ] Test SQLite/PostgreSQL, route cache, P03/P05 owner regressions, audit/Swagger.
- [ ] Review full diff/security; deploy only exact tested source if source/DB/flags
      and production isolation checks pass. No grant updates or real SAVE/RECORD.
- [ ] Return runtime/source SHA, policy readback and App integration guidance;
      do not label CLI policy readback as authenticated live SAVE evidence.
