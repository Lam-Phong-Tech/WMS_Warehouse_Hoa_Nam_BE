# Automated verification

Executed from the repository root:

```text
php artisan test tests/Feature/ItemInstanceTraceTest.php tests/Feature/ItemInstanceTest.php tests/Feature/SkuImportTest.php
Result: 13 passed, 49 assertions

vendor/bin/pint --test
Result: passed

npm.cmd run api:lint
Result: valid OpenAPI document (3 existing recommended-rule warnings)

git fetch origin --prune
git rev-list --left-right --count HEAD...origin/m1-be-2
Result: 0 0
```

The targeted test set verifies both business changes: item-specific movement selection and all-or-nothing SKU import behavior. `git diff --check` reported no whitespace errors for the delivered changes.
