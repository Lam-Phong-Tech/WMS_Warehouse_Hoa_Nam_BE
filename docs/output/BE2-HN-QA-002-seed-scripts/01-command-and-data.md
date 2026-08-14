# Command and seeded data

## Run command

Set `UAT_SEED_PASSWORD` in the local environment, then run:

```text
php artisan wms:seed-uat
```

Alternatively, pass `--password` only in a secure local shell. The command exits with code `1` when no password source is supplied.

## UAT accounts and roles

| Username | Existing role |
|---|---|
| `uat.inbound` | `WAREHOUSE_STAFF` |
| `uat.approver` | `WAREHOUSE_APPROVER` |
| `uat.keeper` | `WAREHOUSE_KEEPER` |
| `uat.viewer` | `VIEWER` |

## Import traceability fixture

| Record | Stable identifier | Purpose |
|---|---|---|
| Import batch | `UAT-IMP-2026-001` | sample import source |
| Physical code | `UAT-QR-ITEM-0001` | QR for inbound scan |
| Item instance | `UAT-ITEM-0001` | `NEW` item linked to the QR and batch |
| Inventory serial | `UAT-INV-0001` | `IN_STOCK` serial at `KHO-TONG-HN` for warranty data |

The fixture references baseline `HN-PV-BASE-001` and does not alter its balances or movements.
