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
| `uat.outbound` | `WAREHOUSE_STAFF` |
| `uat.manager` | `WAREHOUSE_MANAGER` |
| `uat.viewer` | `VIEWER` |

> Updated 2026-09-06 per BA document `taikhoantheorole_da_sua_v2`. `uat.approver`
> (`WAREHOUSE_APPROVER`) and `uat.keeper` (`WAREHOUSE_KEEPER`) were removed: both roles
> are retired from the production permission model, and the two-step "one person scans,
> another posts" flow they existed for no longer exists. `uat.inbound` and `uat.outbound`
> are two different accounts on the same `WAREHOUSE_STAFF` role — the setup BA retest
> item 12 asks for. `uat.manager` covers the supervision-only side.

## Import traceability fixture

| Record | Stable identifier | Purpose |
|---|---|---|
| Import batch | `UAT-IMP-2026-001` | sample import source |
| Physical code | `UAT-QR-ITEM-0001` | QR for inbound scan |
| Item instance | `UAT-ITEM-0001` | `NEW` item linked to the QR and batch |
| Inventory serial | `UAT-INV-0001` | `IN_STOCK` serial at `KHO-TONG-HN` for warranty data |

The fixture references baseline `HN-PV-BASE-001` and does not alter its balances or movements.
