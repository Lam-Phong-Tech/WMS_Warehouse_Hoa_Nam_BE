# WWHN-150 OpenAPI Changelog — BE1

Version: `1.1.0`  
Evidence document version: `1.2.0`  
Status: `PROPOSED — PENDING INDEPENDENT SIGN-OFF`  
Branch: `m1`

## Contract changes in 1.1.0

- `POST /api/v1/scan/classify` is formally read-only.
- `Idempotency-Key` is not required. When supplied it is ignored and no idempotency cache row is written.
- Explicit `401`, `403`, `404`, `409`, and `422` response contracts.
- Warehouse data-scope enforcement and generic cross-warehouse `404` to prevent object-existence disclosure.
- Response invariants: `stock_effect=NONE`, `movement_created=false`, `posting_event_created=false`, `idempotency_cache_written=false`.
- Real resolution across `physical_code`, `inventory_serials`, `item_instance`, `container`, and `products`; no mock IDs.
- Machine-readable `x-permission: scan.classify` and complete error-code enum.
- Secret-safe Postman environment; runtime credentials are injected and redacted in CI.

## Evidence hardening in document 1.2.0

- Dedicated workflow `.github/workflows/auth-rbac-audit-contract.yml`.
- Focused collector no longer fails because of unrelated generic Postman assertions.
- Exact commit SHA, workflow run URL, source-tree SHA-256, artifact digest and internal `SHA256SUMS`.
- Raw full PHPUnit, targeted scan and race/rollback/reconciliation logs.
- HTTP/Newman, concurrent same-key probe, DB before/after and reconciliation evidence.
- Runtime permission catalog, seed diff/hash and endpoint → permission → role matrix.
- Report/snapshot/warranty/PV stock-writer boundary report.
- Credential-redaction and residual-secret gate.
- Approval remains pending until QA1, BE2, FE1 and BA sign independently on Jira.
