# WWHN-211 — BE closure evidence

This directory is a code/evidence control for WWHN-211. It does **not** fabricate BA, Tech Lead, PM, BE reviewer, QA1 or QA2 sign-off.

## Code-owned closure

The BE branch locks the six WWHN-211 read/trace endpoints, their effective permissions, response/error samples and server-side warehouse data-scope behavior. `scripts/wwhn-211-openapi-lock.php` validates those mappings against the generated OpenAPI artifact and writes an exact-SHA evidence manifest with SHA-256 hashes.

The dedicated workflow `.github/workflows/wwhn-211-inventory-closure.yml` runs the contract lock, formatting and data-scope regression tests and uploads `wwhn-211-be-<FULL_SHA>`.

## Scope boundary

WWHN-211 code scope is limited to inventory balance list/subledger and stock-movement/physical-code read/trace. Safe-stock configuration/alerts and scan mutation workflows are intentionally outside this task's code claim.

## External governance gates

Inventory/Stocktake/Reversal Source-of-Truth disposition in WWHN-108/WWHN-17, independent QA direct-API evidence, effective date and approver-authored outcomes remain external governance evidence. Code must not infer or synthesize those approvals.
