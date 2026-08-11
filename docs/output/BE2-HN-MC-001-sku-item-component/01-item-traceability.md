# Item-level movement traceability

## Data model

Migration `2026_08_11_000001_add_item_instance_trace_to_stock_document_lines.php` adds nullable `stock_document_lines.item_instance_id`, a foreign key to `item_instances.item_id`, and `idx_sdl_item_instance`.

`StockDocumentLine` exposes `itemInstance()` and `StockMovement` exposes `documentLine()`. `StockDocumentService::syncLines()` persists the supplied item ID, so a movement created by `StockLedger` retains its document-line link.

## API contract and guard

Each inbound/outbound line accepts optional `item_instance_id`. The Store and Update FormRequests require a real item, reject repeated item IDs in the same lines array, and reject an item whose `sku_id` differs from `product_id`.

`GET /api/v1/item-instances/{id}/trace` uses this predicate:

```php
->whereHas('documentLine', fn ($query) =>
    $query->where('item_instance_id', $item->item_id)
)
```

It has no `where('product_id', ...)` or `import_batch_id` movement filter. The response still includes SKU, physical code, container, and import batch as descriptive context.

## Automated proof

`tests/Feature/ItemInstanceTraceTest.php` creates two items with the same SKU and separate document lines/movements. Tracing item A returns only movement A. `tests/Feature/ItemInstanceTest.php` verifies the complete trace payload and its linked movement.
