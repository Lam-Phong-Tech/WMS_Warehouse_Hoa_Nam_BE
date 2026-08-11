# SKU/item backend evidence

## Traceability

- Parent Temp Key: `HN-MC-001`
- Epic Temp Key: `HN-EPIC-04`
- Task cha: `WWHN-37`
- Output: `SKU/item backend`
- Role: `BE2`
- Assignee Placeholder: `HN-BE-02`
- Source baseline: `136ae3cb042ad7a9efec62e05668514a69920318` plus the working-tree changes documented here.

## Delivered scope

1. Stock document lines can carry an optional `item_instance_id` with a database FK and index.
2. Inbound/outbound create and update validate that an item belongs to the line SKU; duplicate item IDs in one request are rejected.
3. Item trace returns movements only through `stock_movements.stock_document_line_id` and `stock_document_lines.item_instance_id`; SKU and import batch do not widen the result.
4. Excel imports reject invalid enums and booleans, rollback mixed or duplicate batches, and create no import audit record for a failed batch.
5. OpenAPI was regenerated with `item_instance_id` on inbound/outbound line payloads.

## Evidence index

- `01-item-traceability.md` — schema, API behavior, and negative test.
- `02-import-atomicity.md` — parser/error behavior and import tests.
- `03-api-and-migration.md` — migration and API contract changes.
- `04-verification.md` — executed automated checks.

Archived material is in `old_src/` and is not part of this evidence set.
