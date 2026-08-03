# BE1 DB before-after and reconciliation evidence

Date: 2026-08-02 Asia/Saigon
Commit SHA before local remediation: `9fa64ef3f8878060cdff5be0053102ef8d8a0db2`

## Scenario A - inbound scan has no stock effect

Source test: `tests/Feature/StockDocumentIdempotencyTest.php::test_scan_receipt_validates_without_stock_effect`

Before scan:

- `stock_documents.status = approved`
- `stock_document_lines.scanned_qty = 0`
- No `inventory_balances` row for `(warehouse_id, product_id)`
- No `stock_movements` for the document
- No `posting_event` for scan

After scan qty 4 then qty 6:

- API returns `stock_effect=NONE`
- API returns `movement_created=false`
- `stock_document_lines.scanned_qty` moves `0 -> 4 -> 10`
- `stock_documents.status` remains `approved`
- `inventory_balances` remains absent for the product/warehouse
- `stock_movements` count remains `0`
- `posting_event` count for scan remains `0`
- Extra `Idempotency-Key` header on scan is ignored; `idempotency_keys` count for that key remains `0`

Raw log:

- `docs/evidence/be1/php-artisan-test-StockDocumentIdempotencyTest.log`

## Scenario B - post-receipt is the stock-changing inbound action

Source test: `tests/Feature/StockDocumentIdempotencyTest.php::test_inbound_full_lifecycle_and_post_is_idempotent`

Before post:

- `stock_documents.status = approved`
- No stock movement has been committed for the document

After first `post-receipt`:

- `stock_documents.status = received`
- `stock_movements` count for the document is `1`
- `posting_event` exists for operation `post_receipt_{document_id}`
- `inventory_balances.available_qty = 10`

After retry with the same `Idempotency-Key`:

- Response returns the same movement id
- `stock_movements` count remains `1`
- `inventory_balances.available_qty` remains `10`

Raw log:

- `docs/evidence/be1/php-artisan-test-StockDocumentIdempotencyTest.log`

## Scenario C - reconciliation passes after canonical post flow

Source test: `tests/Feature/StockDocumentIdempotencyTest.php::test_inventory_balances_and_stock_movements_endpoints`

After `post-receipt` qty 7:

- `GET /api/v1/inventory/balances?product_id={product_id}` returns `available_qty = 7`
- `GET /api/v1/stock-movements?product_id={product_id}` returns one movement
- `GET /api/v1/reports/inventory-reconciliation?product_id={product_id}` returns:
  - `data.summary.status = PASS`
  - `data.summary.mismatch_count = 0`
  - `data.rows.0.status = MATCH`
  - `data.rows.0.expected_qty = 7`

Raw log:

- `docs/evidence/be1/php-artisan-test-StockDocumentIdempotencyTest.log`
