# Schema and integrity

## Canonical tables

| Area | Tables | Integrity rule |
|---|---|---|
| Catalog | `product_masters`, `defect_catalogs`, `agencies` | unique business codes; SKU master references use `product_masters.master_id` |
| Report | `stock_movements`, `stock_documents`, `inventory_balances`, `inventory_report_snapshots`, `sku` | report joins `stock_movements.product_id` to `sku.sku_id` |
| Warranty | `warranty_case`, `warranty_event`, `inventory_serials`, `defect_catalogs` | case primary key is `warranty_case_id`; TEMP_ONLY writes a warranty event and audit record, never a stock ledger movement |
| PV | `pv_product_profile`, `pv_industry`, `pv_filter_tag`, mapping/rule/event tables | one `pv_product_profile` per SKU; mapping tables use composite primary keys |

## Migration gate

`2026_08_14_000001_add_catalog_report_warranty_pv_integrity_indexes.php` adds:

- `uk_pv_product_profile_product` on `pv_product_profile.product_id`.
- `idx_warranty_case_product_status` for product/status reporting.
- warranty `defect_id` foreign key targeting the API-backed `defect_catalogs` table.

Before adding the PV unique key, the migration counts duplicate `product_id` values and aborts without changing schema if duplicates exist. This prevents silently discarding profile data.

The migration copies any legacy singular defect rows to `defect_catalogs` before changing the warranty foreign key. Rollback copies catalog rows back before restoring the legacy key, preserving referenced defect IDs.

The task-local prior evidence was moved to `old_src/` and is excluded from submission. The active evidence set contains five files.
