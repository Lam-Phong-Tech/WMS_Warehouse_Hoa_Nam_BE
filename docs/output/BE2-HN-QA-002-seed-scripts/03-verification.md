# Verification

Executed on the working tree:

```text
php artisan test tests/Feature/UatSeedCommandTest.php
2 passed, 15 assertions

composer test
156 passed, 986 assertions

vendor/bin/pint --test
passed
```

The test runs `wms:seed-uat` twice against a fresh database and verifies password hashing, role membership, import batch, QR, item, inventory serial, and duplicate prevention. It also verifies that the command fails safely when neither password source is provided.

The task directory had no prior evidence files. `old_src/.gitkeep` is present for any later obsolete artifacts; the active evidence set contains four files and remains below the 20-file limit.
