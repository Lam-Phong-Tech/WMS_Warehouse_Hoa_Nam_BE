# Migration and API contract

## Migration

The item trace migration is additive: it adds one nullable column, its foreign key, and an index. The `down()` path removes the foreign key, index, and column in reverse order. Existing SKU-level stock lines remain valid because `item_instance_id` is nullable; only lines that identify a serialized item participate in item-specific trace results.

## Contract change

The following payloads now support this optional field on every line:

```json
{
  "product_id": "uuid",
  "item_instance_id": "uuid",
  "qty_planned": 1
}
```

Endpoints: `POST /api/v1/inbound-documents`, `PATCH /api/v1/inbound-documents/{id}`, `POST /api/v1/outbound-documents`, and `PATCH /api/v1/outbound-documents/{id}`. The item must belong to `product_id`; otherwise the API returns validation failure and creates no document.

`storage/api-docs/api-docs.json` was regenerated from the PHP OpenAPI attributes after this contract update.
