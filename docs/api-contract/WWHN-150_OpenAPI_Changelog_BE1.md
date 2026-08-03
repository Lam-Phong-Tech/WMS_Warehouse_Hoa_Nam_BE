# WWHN-150 OpenAPI Changelog — BE1

Version: `1.0.0`  
Status: `PROPOSED`  
Branch: `m1`

## Contract changes

- `POST /api/v1/scan/classify` is formally read-only.
- Removed the required `Idempotency-Key` contract from scan classification. A supplied key is accepted but ignored; no idempotency cache row is written.
- Added explicit `401`, `403`, `404`, `409`, and `422` response contracts.
- Added warehouse data-scope enforcement.
- Added response invariants: `stock_effect=NONE`, `movement_created=false`, `posting_event_created=false`, and `idempotency_cache_written=false`.
- Replaced mock prefix-based IDs with real lookups across `physical_code`, `inventory_serials`, `item_instance`, `container`, and `products`.

The generated full OpenAPI JSON/YAML, generation log, exact commit SHA and SHA-256 manifest are produced by the GitHub Actions evidence artifact for the reviewed commit.
