#!/usr/bin/env python3
"""Prepare the candidate in isolated CI; never change a live database or push a ref."""
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

    /** Dashboard builders already receive their validated effective warehouse set, not an HTTP request. */
    public static function dashboardQuery(?array $warehouseIds): Builder
    {
        $query = self::query(new Request());
        if ($warehouseIds !== null) {
            $query->whereExists(function (Builder $owners) use ($warehouseIds): void {
                $owners->selectRaw('1')->from(self::TABLE.' as dashboard_owner')
                    ->whereColumn('dashboard_owner.warranty_case_id', 'warranty_case.warranty_case_id')
                    ->whereIn('dashboard_owner.warehouse_id', $warehouseIds);
            });
        }
        return $query;
    }

    public static function fields(string $caseId): array''')

p = 'Modules/Warehouse/app/Http/Controllers/AlignedWarrantyReportController.php'
edit(p, "'warehouse:report:warranty-defects',", "'warehouse:report:warranty-defects:warranty-owner-v1',")
edit(p, "array_merge($filters, ['include_pii' => $includePii]),", "array_merge($filters, ['include_pii' => $includePii, 'warranty_owner_context' => \\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::cacheContext($request)]),")

p = 'Modules/Warehouse/app/Http/Controllers/Concerns/CachesReportPayload.php'
edit(p, '\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::readSelection(request());', '\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::readSelection(new Request($filters));')
p = 'Modules/Warehouse/app/Http/Controllers/OperationalReportController.php'
edit(p, '\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::query(request())', '\\Modules\\Warehouse\\Support\\WarrantyWarehouseScope::dashboardQuery($warehouseIds)', count=5)
edit(p, '$warrantyReceived = function (string $windowFrom, string $windowTo): int {', '$warrantyReceived = function (string $windowFrom, string $windowTo) use ($warehouseIds): int {')

p = 'app/Console/Commands/AssignWarrantyWarehouseOwners.php'
edit(p, '            // Report caches include the ownership table revision, so audited backfills cannot leave old totals cached.', '''            if ($apply && $count > 0) {
                app(\\App\\Support\\Cache\\CacheService::class)->bumpVersion('stock:version');
            }
            // Report caches also include the ownership table revision.''')

p = 'tests/Feature/WarrantyWarehouseScopeTest.php'
write(p, Path('scripts/warranty_owner/WarrantyWarehouseScopeTest.php').read_text())
edit(p, "'warranty.temp_only', 'report.view',", "'warranty.temp_only', 'report.view', 'report.warranty.view', 'report.export',")
edit(p, "['/api/v1/reports/warranty', '/api/v1/reports/warranty-defects', '/api/v1/reports/dashboard', '/api/v1/dashboard/overview']", "['/api/v1/reports/warranty-defects', '/api/v1/reports/dashboard-quick', '/api/v1/reports/dashboard-overview']")
anchor = "            $this->assertStringNotContainsString($idB, $response->getContent());"
edit(p, anchor, anchor + '''
            if ($path === '/api/v1/reports/warranty-defects') {
                $response->assertJsonPath('data.summary.total', 1)->assertJsonPath('data.summary.processing', 1)
                    ->assertJsonPath('data.summary.temp_only', 1);
            } elseif ($path === '/api/v1/reports/dashboard-quick') {
                $response->assertJsonPath('data.warranty_cases_by_status.RECEIVED', 1);
            } else {
                $response->assertJsonPath('data.warranty.by_status.RECEIVED', 1)
                    ->assertJsonPath('data.warranty_received.today', 1);
            }''')
# Exercise the actual aligned XLSX export builder, not a made-up report route.
edit(p, '    public function test_unauthenticated_and_empty_role_scope_cannot_read_warranty(): void', '''    public function test_export_payload_uses_request_warehouse_scope_including_temp_only(): void
    {
        [$a, $b, $token, $idA, $idB] = $this->twoWarehouses();
        [$user, $scoped] = $this->actor((string) $a->id);
        $this->getJson(self::BASE, $this->headers($scoped))->assertOk();
        $request = \\Illuminate\\Http\\Request::create('/', 'GET', ['warehouse_id' => (string) $a->id]);
        $request->setUserResolver(fn () => $user);
        $payload = app(\\Modules\\Warehouse\\Http\\Controllers\\AlignedWarrantyReportController::class)->exportPayload($request, 'xlsx');
        $this->assertSame(1, $payload['rowCount']);
        $this->assertNotEmpty($payload['bytes']);
        $this->getJson('/api/v1/reports/warranty-defects/export-excel?warehouse_id='.$b->id, $this->headers($scoped))->assertForbidden();
    }

    public function test_unauthenticated_and_empty_role_scope_cannot_read_warranty(): void''')

# Published candidate contains only a read-only regression workflow.
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

deleted = ['scripts/apply_warranty_owner_contract.py', 'scripts/finalize_warranty_owner_contract.py', 'scripts/warranty_owner/WarrantyWarehouseScopeTest.php']
for path in deleted:
    Path(path).unlink()
Path('/tmp/warranty-owner-changed.json').write_text(json.dumps({'changed': sorted(set(changed)), 'deleted': deleted}))
print('Candidate prepared:', len(set(changed)), 'changed paths; staging scripts removed.')
