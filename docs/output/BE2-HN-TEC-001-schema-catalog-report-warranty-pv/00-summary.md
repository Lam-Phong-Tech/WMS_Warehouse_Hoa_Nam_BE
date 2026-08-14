# Schema/API modules evidence

## Traceability

- Parent Temp Key: `HN-TEC-001`
- Epic Temp Key: `HN-EPIC-03`
- Task cha: `WWHN-31`
- Dependency: `HN-TEC-001-ST01`
- Output: `Schema/API modules`
- Role: `BE2`
- Assignee Placeholder: `HN-BE-02`

## Delivered scope

1. Catalog schema continues to use `product_masters`, `defect_catalogs`, and `agencies` for their CRUD APIs.
2. XNT report now joins the active `sku` schema (`sku_id`, `sku_code`, `sku_name`) rather than the retired `products` shape.
3. Warranty list/event APIs use the existing `warranty_case` and `warranty_event` tables. Temporary receipt locks the case, validates the item location, writes case/event/trace movement/audit atomically, and replays idempotent requests.
4. `pv_product_profile.product_id` is unique after migration `2026_08_14_000001_add_catalog_report_warranty_pv_integrity_indexes.php`; one SKU cannot acquire multiple PV profiles.
5. The dashboard returns warranty case counts by status without writing data.

Current evidence is in `01-schema-and-integrity.md`, `02-api-contract.md`, and `03-verification.md`.
