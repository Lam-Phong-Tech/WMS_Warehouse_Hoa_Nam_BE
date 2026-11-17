# Inbound supplier compatibility and synchronization

Approved scope: the user selected full synchronization and authorized implementation and deployment on the DEV system serving khohoanamfe.lptech.info.vn, including historical data.

## Intent and constraints

Show supplier information consistently for app and web receipts. Preserve the running app's URLs, request and response contracts, supplier snapshots, recording/replay/reconciliation, scanning, approval, warehouse scope and stock behavior. No frontend change is needed.

## Approaches

Read-only fallback alone fixes the display but leaves consumers reading different sources. The selected approach combines fallback, transactional writes on new P04 receipts and an explicit historical reconciliation.

## Design

- Web list and detail prefer stock_documents.supplier_id. Only when absent, resolve the supplier from mini_app_inbound_record_metadata by document_id. Return the resolved supplier_id and supplier without writing during GET. Batch lookups; do not filter out historical INACTIVE suppliers.
- P04 record writes the verified supplier_id to the newly created stock document inside the existing outer transaction, retaining metadata and snapshots exactly. Replay continues to use the existing stored receipt and creates no additional mutations.
- PostgreSQL investigation found that historical POSTED headers reject all updates. A reviewed additive migration therefore replaces only the existing guard function: it permits an exact metadata-backed NULL-to-supplier-only update when a transaction-local `hoanam.inbound_supplier_sync` flag is on. JSON equality excluding supplier_id protects every other field; non-NULL replacements, wrong suppliers, deletes, status/version changes and ordinary app updates remain blocked. The reconciliation command is the only code enabling this flag. No trigger disabling, replication-role changes or table/column additions.
- An explicit dry-run-first console command reconciles receipt headers from exact metadata links. Apply requires an exclusive local report file and writes a durable before/after manifest before committing. That prepared manifest is immutable after commit; a separate `.result.json` records completion and the manifest checksum. Only eligible rows are locked and reread. It only fills NULL header values referencing existing suppliers, preserves all other fields and reports existing matches, conflicts, absent suppliers and missing selections. No guessing or overwriting.
- Deploy tested backend source through the existing DEV pipeline, retain rollback evidence, run reconciliation dry-run, back up DEV data, then apply and verify original sample documents and API responses. Production environments are outside scope.

## Verification

Regression coverage: new P04 supplier persistence and idempotency; legacy list/detail fallback including INACTIVE/renamed directory entries; header precedence on conflict; no-supplier compatibility; dry-run/apply/rerun and failed report safety; unchanged versions, statuses, timestamps, metadata, lines and inventory.
