# P06 Metadata And BOX Review

Status: SOURCE CANDIDATE, NOT DEPLOYED. DEV/TEST only. No live migration, seed, stock posting, master repair, BOX reassignment or production change is authorized by this review.

## P06 Source Fix

The runtime source handed off at `cdda3076a950be1f3860083768d9c9de6c7783ad` did not select/project category or brand labels. The candidate adds `category_name`, `brand_name`, `description` and a per-field `metadata_status` to the existing four card locations. The base contract remains `mini-app-p06-v1`; `meta.card_metadata_revision=p06-card-master-r1` identifies the additive extension on all nine existing reads. See `docs/contracts/p06-card-master-r1.json` and `app/Swagger/P06CatalogOpenApi.php`.

No label is hardcoded from DCJZ2090, a screenshot or another variant. LEFT JOIN uses canonical PK/FK relations and runs only in the row query after original counts. No N+1, gallery/ledger fan-out, alternate master lookup, paging/sort/permission changes or GET side effects. A missing FK or join row keeps the parent; the exact reason is in `metadata_status`.

An existing INACTIVE master still has a current label, so the candidate preserves it and marks `CURRENT_INACTIVE_MASTER`. This is display provenance, not active eligibility. Description remains null/SOURCE_NOT_DEFINED because no canonical SKU description source has been verified; specification is not reinterpreted. No additional master IDs/serial flag or mutation capability is exposed.

P06 already bypasses internal result caching and uses a per-response database snapshot. Next GET reads current labels. SKU/ITEM `version` remains the entity version, and stock `version` remains the quantity fingerprint: neither is a complete master-label version. No new ETag shortcut or global flush is introduced.

## Master Data Review

No live exact UUID/FK comparison was possible without a verified TEST session. The source defect is confirmed, but the current FK correctness of DCJZ2090 versus DCJZ2090i/DCJZ2090I is NOT claimed. No master data was changed. After approved rollout, use the same exact UUID across Web, P06 SKU and ITEM reads and preserve their raw response strings/Request-ID. A search result is not an identity proof.

SQLite retains its legacy product_masters FK while PostgreSQL uses canonical master tables and a mirror trigger. Tests populate same-ID canonical/legacy fixture sources where needed on SQLite; no constraint or trigger is disabled and no migration is modified.

## BOX Safety Fix And Remaining Decision

Source inspection found a metadata PATCH could change primary_sku_id while active contents still belonged to the old SKU. The candidate adds a fail-closed guard under the existing container row lock: changing or clearing the primary SKU with any active content returns 409 CONTAINER_REPACK_REQUIRED before writes. Same-primary metadata, empty containers, original version/ACL and lifecycle fences are unchanged. This guard does NOT implement an atomic repack or authorize a remove/PATCH/add sequence.

BOX-SEED-0005 remains BLOCKED_OWNER_REPACK_FLOW_REQUIRED. Its reported 8 cai and proposed DCA-BITSET bo are different SKU/unit identities. A warehouse total of 71/60 does not prove loose, unallocated quantity available for that BOX. Quantity 1 bo is an E2E proposal, not confirmed physical packing.

See `docs/contracts/box-repack-review-proposal-v1.json` for the deliberately unregistered atomic owner/receipt design. Required decisions include old-content destination, exact new packing quantity/provenance, allocation conservation, current references/holds/journals, deterministic locking with issue/putaway, scope/version/idempotency, durable receipt and CHECK_ONLY recovery. No new endpoint/table/flag is implemented or registered for this proposal.

## Review Gates

Source tests, security audits, deployment, authenticated live API and physical-device UI are separate outcomes. The output packet records actual tests and audits. Any failed dependency audit remains a release-review blocker; it is not bypassed by passing feature tests. No CI/deployment workflow is triggered by this source-only review.

After source/release approval, preserve the 14 runtime overlays, current strong-ETag/TLS configuration and existing data. This candidate needs no migration or seed. Rollout and live repack need separate approval and fresh owner evidence. App acceptance stays open until exact-UUID GETs and new device screenshots verify the candidate.
