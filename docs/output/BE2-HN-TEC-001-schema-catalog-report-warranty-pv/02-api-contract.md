# API contract

## Report

`GET /api/v1/reports/inventory-xnt?from_date=YYYY-MM-DD&to_date=YYYY-MM-DD` requires `report.view`. Both dates are required and `to_date` must not precede `from_date`; invalid input returns `422`. Each line exposes `product_code`, `product_name`, `total_in`, and `total_out` from the active SKU schema.

`GET /api/v1/reports/dashboard-quick` requires `report.view` and returns `warranty_cases_by_status` from `warranty_case` alongside the existing inventory summary.

## Warranty

`GET /api/v1/warranty/cases` and `GET /api/v1/warranty/cases/{caseId}/events` require `inventory.view` and read `warranty_case`/`warranty_event`.

`POST /api/v1/warranty-cases/{id}/temp-only` requires `warranty.temp_only` and `Idempotency-Key`.

```json
{
  "item_id": "inventory_serials.id UUID",
  "defect_id": "defect_catalogs.defect_id UUID or null",
  "condition_status": "WAITING_CHECK",
  "note": "optional, maximum 1000 characters"
}
```

The operation accepts only `RECEIVED` or `WAITING_CHECK` cases. It returns `PRECONDITION_REQUIRED` (428), `STATE_CONFLICT` (409), `WARRANTY_ITEM_LOCATION_REQUIRED` (422), or standard validation/authorization errors without creating partial records.

PV has schema and integrity protection in this scope; no new PV endpoint is introduced.
