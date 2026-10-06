#!/usr/bin/env python3
"""Prepare the exact candidate tree in CI. This script never changes a live database or pushes a ref."""
from pathlib import Path
import json
import runpy

if Path('Modules/Warehouse/app/Support/WarrantyWarehouseScope.php').exists():
    print('Implementation already present.')
    raise SystemExit(0)
state = runpy.run_path('scripts/apply_warranty_owner_contract.py')
write, edit, changed = state['write'], state['edit'], state['changed']

p = 'Modules/Warehouse/routes/mini_app.php'
edit(p, "['permission:inbound.view|outbound.view', EnforceInboundWarehouseScope::class, EnforceOutboundWarehouseScope::class]", "['permission:inbound.view|outbound.view|warranty.view|warranty.manage', EnforceInboundWarehouseScope::class, EnforceOutboundWarehouseScope::class]")

p = 'Modules/Warehouse/app/Support/WarrantyWarehouseScope.php'
edit(p, '    public static function fields(string $caseId): array', '''    public static function cacheContext(Request $request): array
    {
        $selection = self::readSelection($request);
        $revision = DB::table(self::TABLE)->selectRaw('COUNT(*) as total')->selectRaw('MAX(assigned_at) as latest')->first();
        return [self::VERSION, $selection, $revision->total, $revision->latest];
    }

    public static function fields(string $caseId): array''')

p = 'Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php'
edit(p, "'warehouse:report:warranty-defects',", "'warehouse:report:warranty-defects:warranty-owner-v1',")
edit(p, "array_merge($filters, ['include_pii' => $includePii]),", "array_merge($filters, ['include_pii' => $includePii, 'warranty_owner_context' => \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::cacheContext($request)]),")

p = 'Modules/Warehouse/app/Http/Controllers/Concerns/CachesReportPayload.php'
edit(p, '\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::readSelection(request());', '\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::readSelection(new Request($filters));')
p = 'Modules/Warehouse/app/Http/Controllers/OperationalReportController.php'
edit(p, '\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::query(request())', '\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::query($request)', count=5)
edit(p, '$warrantyReceived = function (string $windowFrom, string $windowTo): int {', '$warrantyReceived = function (string $windowFrom, string $windowTo) use ($request): int {')

p = 'app/Console/Commands/AssignWarrantyWarehouseOwners.php'
edit(p, '            // Report caches include the ownership table revision, so audited backfills cannot leave old totals cached.', '''            if ($apply && $count > 0) {
                app(\\App\\Support\\Cache\\CacheService::class)->bumpVersion('stock:version');
            }
            // Report caches also include the ownership table revision.''')

write('tests/Feature/WarrantyWarehouseScopeTest.php', Path('scripts/warranty_owner/WarrantyWarehouseScopeTest.php').read_text())

# This is the permanent, read-only regression workflow in the candidate. The currently-running
# preparation workflow can only create a detached candidate commit; the assistant updates dev.
write('.github/workflows/warranty-scope-contract.yml', '''name: Warranty Warehouse Contract
on:
  workflow_dispatch:
  push:
    branches: [dev]
    paths:
      - 'Modules/Warehouse/**'
      - 'Modules/Auth/**'
      - 'app/Console/Commands/AssignWarrantyWarehouseOwners.php'
      - 'bootstrap/app.php'
      - 'database/migrations/**'
      - 'tests/Feature/*Warranty*.php'
      - 'tests/Feature/P02ReadContractTest.php'
      - '.github/workflows/warranty-scope-contract.yml'
permissions:
  contents: read
jobs:
  contract:
    runs-on: ubuntu-24.04
    timeout-minutes: 20
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_USER: postgres
          POSTGRES_PASSWORD: disposable-ci-only
          POSTGRES_DB: warranty_owner_test
        ports: ['5432:5432']
        options: >-
          --health-cmd "pg_isready -U postgres -d warranty_owner_test"
          --health-interval 10s --health-timeout 5s --health-retries 5
    env:
      APP_ENV: testing
      APP_DEBUG: 'false'
      DB_HOST: '127.0.0.1'
      DB_PORT: '5432'
      DB_USERNAME: postgres
      DB_PASSWORD: disposable-ci-only
      DB_SSLMODE: disable
      DB_URL: ''
      CACHE_STORE: array
      SESSION_DRIVER: array
      QUEUE_CONNECTION: sync
      BROADCAST_CONNECTION: 'null'
      MAIL_MAILER: array
      JWT_SECRET: warranty-owner-contract-ci-only-not-a-production-secret
      CORS_ALLOWED_ORIGINS: http://localhost:5173
      CORS_SUPPORTS_CREDENTIALS: 'true'
      REFRESH_TOKEN_COOKIE_SECURE: 'false'
    steps:
      - uses: actions/checkout@v4
        with:
          persist-credentials: false
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, sqlite3, pdo_sqlite, pdo_pgsql, bcmath, gd, zip, intl
          coverage: none
          tools: composer:v2
      - run: composer install --no-interaction --prefer-dist --no-progress
      - name: Prepare isolated application
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan config:clear
      - name: SQLite ownership and auth regressions
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'
        run: php artisan test tests/Feature/WarrantyWarehouseScopeTest.php tests/Feature/P02ReadContractTest.php tests/Feature/AuthWarehouseContextTest.php tests/Feature/MiniAppWorkShiftTest.php tests/Feature/AuthSessionPersistenceTest.php tests/Feature/AuthRbacAuditHardeningTest.php --stop-on-error --stop-on-failure --log-junit warranty-sqlite.xml
      - name: PostgreSQL ownership and auth regressions
        env:
          DB_CONNECTION: pgsql
          DB_DATABASE: warranty_owner_test
        run: php artisan test tests/Feature/WarrantyWarehouseScopeTest.php tests/Feature/P02ReadContractTest.php tests/Feature/AuthWarehouseContextTest.php tests/Feature/MiniAppWorkShiftTest.php tests/Feature/AuthSessionPersistenceTest.php tests/Feature/AuthRbacAuditHardeningTest.php --stop-on-error --stop-on-failure --log-junit warranty-pgsql.xml
      - name: Publish test summaries without artifact storage
        if: always()
        run: |
          python3 - <<'PY'
          from pathlib import Path
          import xml.etree.ElementTree as ET
          for path in Path('.').glob('warranty-*.xml'):
              try:
                  print(path, [s.attrib for s in ET.parse(path).getroot().findall('./testsuite')])
              except ET.ParseError:
                  print(path, 'Incomplete test report; read the failed step.')
          PY
''')

# Remove staging-only files from the published candidate, not from its parent commit.
deleted = ['scripts/apply_warranty_owner_contract.py', 'scripts/finalize_warranty_owner_contract.py', 'scripts/warranty_owner/WarrantyWarehouseScopeTest.php']
for path in deleted:
    Path(path).unlink()
Path('/tmp/warranty-owner-changed.json').write_text(json.dumps({'changed': sorted(set(changed)), 'deleted': deleted}))
print('Candidate prepared:', len(set(changed)), 'changed paths; staging scripts removed.')
