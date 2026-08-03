# WMS HoaNam OpenAPI Changelog - BE1 WWHN-150

Version: 1.0.1-be1-scan-none-reconcile
Generated at: 2026-08-02 19:05 Asia/Saigon
Source artifact: storage/api-docs/api-docs.json
Postman artifact: docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json

## Changes

- Locked inbound/outbound document scan contract to stock_effect=NONE.
- Scan now records only stock_document_lines.scanned_qty state and returns scanned_qty_before, scanned_qty_after, remaining_qty.
- Removed scanReceipt/scanIssue stock mutation entrypoints from StockLedger.
- Added GET /api/v1/reports/inventory-reconciliation for balance-versus-ledger reconciliation evidence.
- Regenerated OpenAPI JSON from l5-swagger annotations.
- Regenerated Postman collection from the OpenAPI JSON artifact.

## Baseline Rule

Only these actions may mutate real stock:

- POST /api/v1/inbound-documents/{id}/post-receipt
- POST /api/v1/outbound-documents/{id}/post-issue
- POST /api/v1/stocktake-sessions/{id}/apply-adjustment
- reverse/correction actions that create approved reversal movements

Scan/classify/mapping endpoints must not create stock_movements and must not update inventory_balances.
