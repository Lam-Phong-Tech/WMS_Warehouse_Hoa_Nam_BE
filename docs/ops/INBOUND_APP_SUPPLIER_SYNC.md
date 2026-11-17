# App receipt supplier reconciliation

`inbound:sync-suppliers` reconciles existing receipt header suppliers using exact app metadata. It does not infer suppliers, alter app snapshots, or update status/version/timestamps/stock. Existing header values take precedence and conflicting non-NULL metadata is reported without modification. Historical inactive suppliers remain valid references.

## Review and apply

Run against the explicitly selected environment only. For the 2026-11-17 DEV operation, the target is `hoa_nam_dev-backend-1`, never a production container.

1. Take an encrypted logical backup outside source control and verify its restore catalogue. Apply only the reviewed `2026_11_17_000100_allow_verified_posted_inbound_supplier_repair` migration and create the trusted DEV migration receipt for the exact target build SHA.
2. Deploy the tested backend build via the existing DEV release pipeline. Verify the live health build SHA.
3. Run `php artisan inbound:sync-suppliers` (dry-run, no changes). Inspect `summary` and eligible/conflicting rows.
4. Create a private operator-owned report directory, then run `php artisan inbound:sync-suppliers --apply --report=/absolute/private/path/apply.json`.
5. Verify `apply.json.result.json` reports `committed`, `updated` matches the reviewed eligible count and its SHA-256 matches `apply.json`. The primary report intentionally remains `prepared`: it is the immutable pre-commit recovery evidence.
6. Rerun dry-run; expect `eligible: 0`. Compare changed receipt fields, metadata snapshots and stock counts/fingerprints before/after. Verify the web list and detail supplier response for the original sample IDs.

Report creation/flush failure before commit rolls back all changes. A postcommit sidecar/cache error returns failure with an explicit `Database committed` message; inspect the primary prepared report and current database before retrying. Never assume failure means rollback. A rerun uses a new report filename and never overwrites headers or prior evidence.

POSTED receipts remain protected by their database trigger. The command sets a transaction-local repair flag; the guard permits only a NULL-to-exact-metadata supplier change with all other row fields identical. The flag alone cannot replace existing suppliers, alter notes/quantities/status/versions or delete posted receipts. Never disable the trigger for reconciliation.

## Recovery

Code rollback can use the standard previous-release deployment record; supplier IDs are backward-compatible nullable columns already present in that release.

For a targeted data reversal of unposted receipts, first verify the result sidecar checksum. In a transaction, lock only rows whose manifest outcome is `eligible`. Require current `supplier_id` to match the recorded `after.supplier_id` and current `version`, `status`, `updated_at` to match the manifest. Restore only `before.supplier_id` with the query builder; do not modify operational fields or touch metadata. Stop and review any changed row rather than overriding subsequent user activity. POSTED receipts remain immutable after repair, including reversal to NULL: any exceptional reversal requires a separately reviewed correction scoped to the verified manifest; this repair flag cannot perform it. Save a separate reversal manifest. A full-database restore is not appropriate for a field-only repair while business activity continues.

## Verification coverage

`P04InboundContractTest` covers new supplier persistence, existing replay/reconcile/transaction rollback, legacy web list/detail fallback, inactive/renamed suppliers, header precedence, explicit dry-run/apply/idempotency, unavailable/existing reports and postcommit outcome-write failure. `InboundSupplierFieldTest` retains web creation/edit/read behavior; mini-app staging, handoff and canonical receipt suites guard existing app stock behavior.
