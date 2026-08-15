# Verification

Executed on the remediation source revision named in `04-release-manifest.json`:

```text
php artisan test tests/Feature/SchemaApiModulesTest.php
4 passed, 18 assertions

php artisan test tests/Feature/StockDocumentIdempotencyTest.php
14 passed, 82 assertions

vendor/bin/pint --test
passed

php artisan test tests/Feature/SchemaApiModulesTest.php tests/Feature/StockDocumentIdempotencyTest.php tests/Feature/InboundScanTest.php
20 passed, 125 assertions

DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan migrate --pretend
passed; includes the PV duplicate count, unique index, and warranty reporting index
```

`SchemaApiModulesTest` verifies the XNT report queries the active `sku` table, dashboard warranty aggregation, warranty case/event routes, TEMP_ONLY transaction/idempotency/audit behavior with no `stock_movements` write, PV profile uniqueness, and migration rollback/re-apply. The test suite uses `RefreshDatabase`, so migration checks run against a clean isolated schema.

OpenAPI was regenerated and the Postman collection was generated from it. `npm run api:lint` validated the document; it reports three pre-existing recommendation warnings for API license metadata, localhost server URL, and the health endpoint's missing `4XX` response.
