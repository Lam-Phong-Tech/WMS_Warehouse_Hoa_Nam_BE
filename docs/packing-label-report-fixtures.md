# Packing Label report fixtures

`PackingLabelReportSeeder` is an explicit, additive fixture command for the packing label / reprint report. It is intentionally **not** called by `DatabaseSeeder`. It never resets a database or calls a printer agent.

Use a nonproduction environment (`local`, `testing`, `dev`, `development`, `uat`, or `demo`) and an existing ACTIVE actor UUID. Supply these variables for this one command only; do not persist the protected-database override:

```sh
WMS_PACKING_LABEL_REPORT_SEED=true \
WMS_PACKING_LABEL_REPORT_SEED_DATE=2026-11-05 \
WMS_PACKING_LABEL_REPORT_SEED_ACTOR_ID=<active-user-uuid> \
WMS_ALLOW_PROTECTED_DATABASE_SEED=CONFIRM_SEED_WRITE \
php artisan db:seed --class='Modules\Warehouse\Database\Seeders\PackingLabelReportSeeder' --force
```

Omit `WMS_PACKING_LABEL_REPORT_SEED_DATE` to use today's date in Asia/Ho_Chi_Minh. All snapshots and attempts are dated to that day (08:00–08:47), so they appear in the current-month report when run today. When configuration is cached, use a scoped bootstrap script that calls `config([...])` for this invocation, or rebuild the development configuration with the temporary values. Environment values alone cannot override a cached Laravel configuration.

The default path selects ten existing POSTED issue documents dated on or before the fixture date that have no document-level snapshot. It only adds labels, print events, two clearly named sample printers, and an audit manifest. It never updates the source documents or their stock.

If there are fewer than ten eligible documents, the command fails atomically. An explicit `WMS_PACKING_LABEL_REPORT_CREATE_SOURCES=true` enables an owned source graph instead: a new date-prefixed PRODUCT SKU copies master references from an existing active product; one receipt registers ten new serialized items through `CurrentBaselineStockService`; ten outbound documents scan those items through the same service. Only the five labels whose last result is PRINTED have their issues posted. The other five remain SCANNING, awaiting a successful print. This leaves five units on hand on the new fixture SKU, matching the movement ledger. Existing SKUs, balances, items and documents remain untouched. This mode adds real sample transactions; it does not fabricate POSTED rows or write stock projections directly.

Each date owns the prefix `PLRPT-YYYYMMDD`. Successful runs return and audit a manifest with source document, snapshot, event and printer UUIDs. Repeating the same date and actor verifies a digest and returns the same manifest without writes. Changed or foreign fixture rows cause a collision error and are never overwritten. To create a new set, use a different date. Never delete historical label events to rerun this seeder.

The ten snapshots contain thirteen attempts:

| Label suffix | Attempts | Snapshot result |
| --- | --- | --- |
| 01 | Printed | PRINTED |
| 02 | Printed → successful reprint | PRINTED |
| 03 | Paper out → printed retry | PRINTED |
| 04 | Paper out | PRINT_FAILED |
| 05 | Printer offline | PRINT_FAILED |
| 06 | PENDING | READY_TO_PRINT |
| 07 | SENT | READY_TO_PRINT |
| 08 | Printed, deliberately incomplete legacy payload | PRINTED |
| 09 | No event | READY_TO_PRINT |
| 10 | Printed → offline reprint → successful reprint | PRINTED |

Attempt totals: 7 PRINTED, 4 FAILED, 1 PENDING, 1 SENT. Reprint metrics remain derived from successful print count. The action filter also recognizes explicit failed or pending REPRINT attempts; combined event filters must match the same attempt. The paper-out retry on label 03 is not a successful reprint. Both sample printers point to `127.0.0.1:9`; events carry `simulated: true`, so this fixture command cannot contact a physical device. It preserves `/reports/label-log` (one row per snapshot) and `/label-print-events` (one row per attempt) API contracts.

Validation:

```sh
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test tests/Feature/PackingLabelReportSeederTest.php
```

Tests cover the event scenarios, source/stock preservation, guarded owned source creation, ledger balance, exact no-write replay, changed-fixture collision, insufficient-source rollback, environment, actor, date and protected-database guards. Always run tests against a disposable database, never a development deployment database.
