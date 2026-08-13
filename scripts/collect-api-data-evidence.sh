#!/usr/bin/env bash
set -euo pipefail

EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/WWHN-150}"
REPO_ROOT="$(git rev-parse --show-toplevel)"
EVIDENCE_ROOT="$(realpath -m "$REPO_ROOT/build/evidence")"
EVIDENCE_DIR="$(realpath -m "$EVIDENCE_DIR")"

if [[ "$EVIDENCE_DIR" != "$EVIDENCE_ROOT/"* ]]; then
  echo "REFUSED: EVIDENCE_DIR must be inside $EVIDENCE_ROOT." >&2
  exit 77
fi

php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$driver = (string) config("database.default");
$database = (string) config("database.connections.$driver.database");
if ($app->environment() !== "testing" || $driver !== "sqlite" || $database !== "/tmp/wms-wwhn150.sqlite") {
    fwrite(STDERR, "REFUSED: collector requires APP_ENV=testing and SQLite /tmp/wms-wwhn150.sqlite.\n");
    exit(78);
}
'

if [[ -n "$(git status --porcelain)" ]]; then
  echo "REFUSED: evidence must be generated from a clean exact-commit worktree." >&2
  exit 79
fi

: "${WWHN150_PASSWORD:?WWHN150_PASSWORD is required}"
: "${WWHN150_NO_PERMISSION_PASSWORD:?WWHN150_NO_PERMISSION_PASSWORD is required}"

rm -rf "$EVIDENCE_DIR"
mkdir -p "$EVIDENCE_DIR/source"

COMMIT_SHA="$(git rev-parse HEAD)"
BUILD_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
COLLECTION="docs/postman/WWHN-150 Scan ReadOnly Contract.postman_collection.json"
ENVIRONMENT="docs/postman/WWHN-150 Scan ReadOnly CI.postman_environment.json"
OPENAPI_SOURCE="docs/api-contract/WWHN-150-scan-openapi.yaml"
CHANGELOG="docs/api-contract/WWHN-150_OpenAPI_Changelog_BE1.md"
TEST_CASES="docs/testing/WWHN-150-scan-readonly-test-cases.json"
SOURCE_REGISTER="docs/docs_task_jira/WWHN-150-source-register.json"
PERMISSION_CATALOG="docs/permissions/WMS_PERMISSION_CATALOG_BE1.json"

php artisan route:list --path=api/v1 -vv --json > "$EVIDENCE_DIR/route-list.raw.json"

php artisan test --do-not-cache-result --filter=ScanClassifyReadOnlyContractTest \
  2>&1 | tee "$EVIDENCE_DIR/scan-readonly-phpunit.raw.log"

php artisan test --do-not-cache-result \
  --filter='StockDocumentConcurrencyGuardTest|StockDocumentIdempotencyTest|InventoryReconciliationIntegrityTest|InventoryReconciliationSignedStatusTest|WarrantyStatusOnlyContractTest|WarrantyIdempotencyKeyContractTest' \
  2>&1 | tee "$EVIDENCE_DIR/race-rollback-reconciliation-phpunit.raw.log"

php artisan test --do-not-cache-result \
  2>&1 | tee "$EVIDENCE_DIR/full-phpunit.raw.log"

php artisan l5-swagger:generate \
  2>&1 | tee "$EVIDENCE_DIR/openapi-generate.raw.log"
cp storage/api-docs/api-docs.json "$EVIDENCE_DIR/openapi.json"

if [[ -s storage/api-docs/api-docs.yaml ]]; then
  cp storage/api-docs/api-docs.yaml "$EVIDENCE_DIR/openapi.yaml"
else
  php -r '
  require "vendor/autoload.php";
  $json = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  file_put_contents(
      $argv[2],
      Symfony\Component\Yaml\Yaml::dump($json, 30, 2, Symfony\Component\Yaml\Yaml::DUMP_OBJECT_AS_MAP)
  );
  ' "$EVIDENCE_DIR/openapi.json" "$EVIDENCE_DIR/openapi.yaml"
fi

cp "$COLLECTION" "$EVIDENCE_DIR/wwhn150.postman.collection.json"
cp "$ENVIRONMENT" "$EVIDENCE_DIR/wwhn150.postman.environment.json"
cp "$OPENAPI_SOURCE" "$EVIDENCE_DIR/wwhn150-openapi.yaml"
cp "$CHANGELOG" "$EVIDENCE_DIR/wwhn150-openapi-changelog.md"
cp "$TEST_CASES" "$EVIDENCE_DIR/wwhn150-test-cases.json"
cp "$SOURCE_REGISTER" "$EVIDENCE_DIR/wwhn150-source-register.json"
cp "$PERMISSION_CATALOG" "$EVIDENCE_DIR/permission-catalog.repository.json"

source_files=(
  ".github/workflows/auth-rbac-audit-contract.yml"
  "scripts/collect-api-data-evidence.sh"
  "scripts/redact-wwhn150-evidence.mjs"
  "scripts/verify-wwhn150-contract.mjs"
  "scripts/verify-wwhn150-boundary.mjs"
  "scripts/compare-scan-readonly-evidence.mjs"
  "scripts/run-wwhn150-concurrent-scan.mjs"
  "scripts/verify-inventory-reconciliation.mjs"
  "Modules/Warehouse/routes/api.php"
  "Modules/Warehouse/app/Http/Controllers/ScanController.php"
  "Modules/Warehouse/app/Services/ScanClassificationService.php"
  "tests/Feature/ScanClassifyReadOnlyContractTest.php"
  "tests/Feature/BackendMigrationSeedHealthTest.php"
  "tests/Feature/StockDocumentConcurrencyGuardTest.php"
  "tests/Feature/StockDocumentIdempotencyTest.php"
  "tests/Feature/InventoryReconciliationIntegrityTest.php"
  "tests/Feature/InventoryReconciliationSignedStatusTest.php"
  "tests/Feature/WarrantyStatusOnlyContractTest.php"
  "Modules/Auth/database/seeders/AuthDatabaseSeeder.php"
  "Modules/Auth/app/Models/User.php"
  "app/Console/Commands/ExportPermissionCatalog.php"
  "app/Console/Commands/ExportStockSnapshot.php"
  "docs/permissions/WMS_PERMISSION_CATALOG_BE1.json"
  "docs/permissions/WMS_PERMISSION_CATALOG_BE1.md"
  "Modules/Warehouse/app/Http/Controllers/ReportController.php"
  "Modules/Warehouse/app/Http/Controllers/InventorySnapshotController.php"
  "Modules/Warehouse/app/Services/InventorySnapshotService.php"
  "Modules/Warehouse/app/Models/InventoryReportSnapshot.php"
  "Modules/Warehouse/app/Http/Controllers/WarrantyController.php"
  "Modules/Warehouse/app/Models/WarrantyCase.php"
  "Modules/Warehouse/app/Models/WarrantyEvent.php"
  "Modules/Warehouse/database/migrations/2026_01_01_000044_create_hoanam_erd_extension_tables.php"
  "Modules/Warehouse/database/seeders/WarehouseDatabaseSeeder.php"
  "docs/docs_task_jira/WWHN-150.md"
  "docs/docs_task_jira/WWHN-150-source-register.json"
  "docs/evidence/WWHN-150/README.md"
  "docs/evidence/WWHN-150/CLOSURE_MATRIX.md"
)
for file in "${source_files[@]}"; do
  test -s "$file"
  mkdir -p "$EVIDENCE_DIR/source/$(dirname "$file")"
  cp "$file" "$EVIDENCE_DIR/source/$file"
done

php artisan migrate:fresh --force \
  2>&1 | tee "$EVIDENCE_DIR/migrate-fresh.raw.log"
php artisan db:seed --force \
  2>&1 | tee "$EVIDENCE_DIR/database-seed.raw.log"

php artisan evidence:permission-catalog \
  --output="$EVIDENCE_DIR/permission-catalog.json" \
  --seed-diff-output="$EVIDENCE_DIR/permission-seed-diff.json" \
  2>&1 | tee "$EVIDENCE_DIR/permission-catalog.raw.log"

{
  echo "# exact_commit_sha=$COMMIT_SHA"
  echo "# source=Modules/Auth/database/seeders/AuthDatabaseSeeder.php"
  git show --format= --binary "$COMMIT_SHA" -- Modules/Auth/database/seeders/AuthDatabaseSeeder.php
} > "$EVIDENCE_DIR/permission-seed.git.diff"

node - "$EVIDENCE_DIR/permission-catalog.json" "$EVIDENCE_DIR/endpoint-permission-role-matrix.json" <<'NODE'
const fs = require('fs');
const [input, output] = process.argv.slice(2);
const catalog = JSON.parse(fs.readFileSync(input, 'utf8'));
const rows = (catalog.endpoint_permission_role_matrix ?? []).filter((row) => {
  const uri = `/${String(row.uri ?? '').replace(/^\/+/, '')}`;
  const methods = Array.isArray(row.methods) ? row.methods : String(row.methods ?? '').split('|');
  return uri === '/api/v1/scan/classify' && methods.map(String).map((value) => value.toUpperCase()).includes('POST');
});
if (rows.length !== 1 || rows[0].permission !== 'scan.classify') {
  throw new Error('Expected exactly one POST /api/v1/scan/classify permission matrix row.');
}
fs.writeFileSync(output, `${JSON.stringify({
  schema_version: '1.0.0',
  generated_at_utc: new Date().toISOString(),
  endpoint: 'POST /api/v1/scan/classify',
  permission: 'scan.classify',
  roles: rows[0].roles ?? [],
  source: 'permission-catalog.json',
  status: 'PASS',
}, null, 2)}\n`);
NODE

node scripts/verify-wwhn150-contract.mjs \
  "$EVIDENCE_DIR/openapi.json" \
  "$EVIDENCE_DIR/route-list.raw.json" \
  "$EVIDENCE_DIR/permission-catalog.json" \
  "$EVIDENCE_DIR/wwhn150.postman.collection.json" \
  "$EVIDENCE_DIR/wwhn150-test-cases.json" \
  "$EVIDENCE_DIR/wwhn150-source-register.json" \
  Modules/Warehouse/app/Http/Controllers/ScanController.php \
  Modules/Warehouse/app/Services/ScanClassificationService.php \
  "$EVIDENCE_DIR/wwhn150-openapi.yaml" \
  "$EVIDENCE_DIR/wwhn150-contract-verification.json"

node scripts/verify-wwhn150-boundary.mjs \
  "$EVIDENCE_DIR/stock-writer-boundary.json"

php artisan evidence:stock-snapshot \
  --label=before-wwhn150-http \
  --output="$EVIDENCE_DIR/scan-db-before.json"

PHP_CLI_SERVER_WORKERS=8 php artisan serve --host=127.0.0.1 --port=8000 \
  > "$EVIDENCE_DIR/server.raw.log" 2>&1 &
SERVER_PID=$!
cleanup_server() {
  kill "$SERVER_PID" 2>/dev/null || true
  wait "$SERVER_PID" 2>/dev/null || true
}
trap cleanup_server EXIT

SERVER_READY=false
for _ in $(seq 1 30); do
  if curl --silent --show-error http://127.0.0.1:8000/api/health \
    > "$EVIDENCE_DIR/health-response.json"; then
    SERVER_READY=true
    break
  fi
  sleep 1
done
if [[ "$SERVER_READY" != "true" ]]; then
  echo "Laravel evidence server was not ready after 30 seconds." >&2
  exit 1
fi

node scripts/run-wwhn150-concurrent-scan.mjs \
  "$EVIDENCE_DIR/wwhn150-concurrent-scan.json" 20 \
  2>&1 | tee "$EVIDENCE_DIR/wwhn150-concurrent-scan.raw.log"

npx newman run "$EVIDENCE_DIR/wwhn150.postman.collection.json" \
  -e "$EVIDENCE_DIR/wwhn150.postman.environment.json" \
  --env-var "password=$WWHN150_PASSWORD" \
  --env-var "noPermissionPassword=$WWHN150_NO_PERMISSION_PASSWORD" \
  --reporters cli,json \
  --reporter-json-export "$EVIDENCE_DIR/wwhn150-newman.result.json" \
  2>&1 | tee "$EVIDENCE_DIR/wwhn150-newman.raw.log"

php artisan evidence:stock-snapshot \
  --label=after-wwhn150-http \
  --output="$EVIDENCE_DIR/scan-db-after.json"

node scripts/compare-scan-readonly-evidence.mjs \
  "$EVIDENCE_DIR/scan-db-before.json" \
  "$EVIDENCE_DIR/scan-db-after.json" \
  "$EVIDENCE_DIR/scan-readonly-comparison.json"

node scripts/verify-inventory-reconciliation.mjs \
  "$EVIDENCE_DIR/scan-db-after.json" \
  "$EVIDENCE_DIR/reconciliation-report.json"

cleanup_server
trap - EXIT

node scripts/redact-wwhn150-evidence.mjs "$EVIDENCE_DIR"

SOURCE_TREE_SHA256="$(git archive --format=tar HEAD | sha256sum | awk '{print $1}')"
printf '%s  git-archive-%s.tar\n' "$SOURCE_TREE_SHA256" "$COMMIT_SHA" \
  > "$EVIDENCE_DIR/source-tree.sha256"

OPENAPI_SHA256="$(sha256sum "$EVIDENCE_DIR/openapi.json" | awk '{print $1}')"
FOCUSED_OPENAPI_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn150-openapi.yaml" | awk '{print $1}')"
POSTMAN_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn150.postman.collection.json" | awk '{print $1}')"
POSTMAN_ENV_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn150.postman.environment.json" | awk '{print $1}')"
PERMISSION_CATALOG_SHA256="$(sha256sum "$EVIDENCE_DIR/permission-catalog.json" | awk '{print $1}')"
PERMISSION_SEED_SHA256="$(sha256sum Modules/Auth/database/seeders/AuthDatabaseSeeder.php | awk '{print $1}')"

cat > "$EVIDENCE_DIR/build-metadata.json" <<JSON
{
  "schema_version": "1.0.0",
  "commit_sha": "$COMMIT_SHA",
  "source_tree_sha256": "$SOURCE_TREE_SHA256",
  "build_time_utc": "$BUILD_TIME",
  "ci_run_url": "${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE}/actions/runs/${GITHUB_RUN_ID:-local}",
  "artifact_name": "wwhn-150-evidence-$COMMIT_SHA",
  "document_version": "1.2.0",
  "contract_version": "1.1.0",
  "php_version": "$(php -r 'echo PHP_VERSION;')",
  "node_version": "$(node --version)",
  "database_connection": "sqlite",
  "openapi_sha256": "$OPENAPI_SHA256",
  "focused_openapi_sha256": "$FOCUSED_OPENAPI_SHA256",
  "postman_collection_sha256": "$POSTMAN_SHA256",
  "postman_environment_sha256": "$POSTMAN_ENV_SHA256",
  "permission_catalog_sha256": "$PERMISSION_CATALOG_SHA256",
  "permission_seed_sha256": "$PERMISSION_SEED_SHA256",
  "approval_status": "PENDING_INDEPENDENT_SIGN_OFF",
  "qa1_status": "PENDING",
  "be2_status": "PENDING",
  "fe1_ba_status": "PENDING"
}
JSON

(
  cd "$EVIDENCE_DIR"
  find . -type f ! -name 'SHA256SUMS' ! -name 'SHA256SUMS.sha256' -printf '%P\n' \
    | LC_ALL=C sort \
    | while IFS= read -r file; do sha256sum "$file"; done \
    > SHA256SUMS
  sha256sum SHA256SUMS > SHA256SUMS.sha256
  sha256sum --check SHA256SUMS
  sha256sum --check SHA256SUMS.sha256
)

cat "$EVIDENCE_DIR/SHA256SUMS"
