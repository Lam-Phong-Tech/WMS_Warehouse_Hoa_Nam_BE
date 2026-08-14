# Verification

Executed on the working tree after the schema/API changes:

```text
php artisan test tests/Feature/SchemaApiModulesTest.php
3 passed, 17 assertions

php artisan test tests/Feature/StockDocumentIdempotencyTest.php
14 passed, 82 assertions

vendor/bin/pint --test
passed

php artisan test tests/Feature/SchemaApiModulesTest.php tests/Feature/StockDocumentIdempotencyTest.php tests/Feature/InboundScanTest.php
20 passed, 125 assertions

composer test
154 passed, 971 assertions
```

`SchemaApiModulesTest` verifies the XNT report queries the active `sku` table, dashboard warranty aggregation, warranty case/event routes, TEMP_ONLY transaction/idempotency/audit behavior, and PV profile uniqueness. The test suite uses `RefreshDatabase`, so the migration runs on a clean schema before these API checks.

OpenAPI was regenerated and the Postman collection was generated from it. `npm run api:lint` validated the document; it reports three pre-existing recommendation warnings for API license metadata, localhost server URL, and the health endpoint's missing `4XX` response.
