#!/usr/bin/env bash
set -euo pipefail

EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/api-data}"
mkdir -p "$EVIDENCE_DIR/source"

COMMIT_SHA="$(git rev-parse HEAD)"
BUILD_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
POSTMAN_COLLECTION="docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json"
POSTMAN_ENVIRONMENT="docs/postman/WMS_HoaNam - CI.postman_environment.json"
STOCKTAKE_COLLECTION="docs/postman/WWHN-131 Stocktake Contract.postman_collection.json"
STOCKTAKE_ENVIRONMENT="docs/postman/WWHN-131 Stocktake CI.postman_environment.json"
STOCKTAKE_TEST_CASES="docs/testing/WWHN-131-stocktake-test-cases.json"
STOCKTAKE_SOURCE_REGISTER="docs/docs_task_jira/WWHN-131-source-register.json"
WWHN150_COLLECTION="docs/postman/WWHN-150 Scan ReadOnly Contract.postman_collection.json"
WWHN150_ENVIRONMENT="docs/postman/WWHN-150 Scan ReadOnly CI.postman_environment.json"
WWHN150_TEST_CASES="docs/testing/WWHN-150-scan-readonly-test-cases.json"
WWHN150_SOURCE_REGISTER="docs/docs_task_jira/WWHN-150-source-register.json"
WWHN150_OPENAPI="docs/api-contract/WWHN-150-scan-openapi.yaml"

php artisan route:list --path=api/v1 -vv --json > "$EVIDENCE_DIR/route-list.raw.json"
php artisan test --do-not-cache-result --filter=ScanClassifyReadOnlyContractTest 2>&1 | tee "$EVIDENCE_DIR/scan-readonly-phpunit.raw.log"
php artisan test --do-not-cache-result 2>&1 | tee "$EVIDENCE_DIR/phpunit.raw.log"
php artisan l5-swagger:generate 2>&1 | tee "$EVIDENCE_DIR/openapi-generate.raw.log"
cp storage/api-docs/api-docs.json "$EVIDENCE_DIR/openapi.json"

if [[ -f storage/api-docs/api-docs.yaml ]]; then
  cp storage/api-docs/api-docs.yaml "$EVIDENCE_DIR/openapi.yaml"
else
  php -r 'require "vendor/autoload.php"; $j=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); file_put_contents($argv[2], Symfony\Component\Yaml\Yaml::dump($j, 20, 2, Symfony\Component\Yaml\Yaml::DUMP_OBJECT_AS_MAP));' "$EVIDENCE_DIR/openapi.json" "$EVIDENCE_DIR/openapi.yaml"
fi

cp "$POSTMAN_COLLECTION" "$EVIDENCE_DIR/postman.collection.json"
cp "$POSTMAN_ENVIRONMENT" "$EVIDENCE_DIR/postman.environment.json"
cp "$STOCKTAKE_COLLECTION" "$EVIDENCE_DIR/stocktake.postman.collection.json"
cp "$STOCKTAKE_ENVIRONMENT" "$EVIDENCE_DIR/stocktake.postman.environment.json"
cp "$STOCKTAKE_TEST_CASES" "$EVIDENCE_DIR/stocktake-test-cases.json"
cp "$STOCKTAKE_SOURCE_REGISTER" "$EVIDENCE_DIR/stocktake-source-register.json"
cp "$WWHN150_COLLECTION" "$EVIDENCE_DIR/wwhn150.postman.collection.json"
cp "$WWHN150_ENVIRONMENT" "$EVIDENCE_DIR/wwhn150.postman.environment.json"
cp "$WWHN150_TEST_CASES" "$EVIDENCE_DIR/wwhn150-test-cases.json"
cp "$WWHN150_SOURCE_REGISTER" "$EVIDENCE_DIR/wwhn150-source-register.json"
cp "$WWHN150_OPENAPI" "$EVIDENCE_DIR/wwhn150-openapi.yaml"

cp "$STOCKTAKE_COLLECTION" "$EVIDENCE_DIR/source/"
cp "$STOCKTAKE_TEST_CASES" "$EVIDENCE_DIR/source/"
cp "$STOCKTAKE_SOURCE_REGISTER" "$EVIDENCE_DIR/source/"
cp "$WWHN150_COLLECTION" "$EVIDENCE_DIR/source/"
cp "$WWHN150_ENVIRONMENT" "$EVIDENCE_DIR/source/"
cp "$WWHN150_TEST_CASES" "$EVIDENCE_DIR/source/"
cp "$WWHN150_SOURCE_REGISTER" "$EVIDENCE_DIR/source/"
cp "$WWHN150_OPENAPI" "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/routes/api.php "$EVIDENCE_DIR/source/warehouse-api-routes.php"
cp Modules/Auth/database/seeders/AuthDatabaseSeeder.php "$EVIDENCE_DIR/source/"
cp Modules/Auth/app/Http/Middleware/CheckPermission.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Http/Controllers/ScanController.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/ScanClassificationService.php "$EVIDENCE_DIR/source/"
cp tests/Feature/ScanClassifyReadOnlyContractTest.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Http/Controllers/StocktakeController.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/StocktakeAdjustmentService.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/StocktakeService.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/StocktakeSessionGuard.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/StocktakeGrainService.php "$EVIDENCE_DIR/source/"
cp tests/Feature/StocktakeThreeLayerContractTest.php "$EVIDENCE_DIR/source/"
cp tests/Feature/StocktakeApplyLedgerIntegrityTest.php "$EVIDENCE_DIR/source/"

php artisan migrate:fresh --force 2>&1 | tee "$EVIDENCE_DIR/migrate-fresh.raw.log"
php artisan db:seed --force 2>&1 | tee "$EVIDENCE_DIR/database-seed.raw.log"
php artisan evidence:permission-catalog --output="$EVIDENCE_DIR/permission-catalog.json"

node scripts/verify-api-data-contract.mjs \
  "$EVIDENCE_DIR/openapi.json" \
  "$EVIDENCE_DIR/route-list.raw.json" \
  "$EVIDENCE_DIR/permission-catalog.json" \
  "$EVIDENCE_DIR/postman.collection.json" \
  "$EVIDENCE_DIR/contract-verification.json"

node scripts/verify-stocktake-contract.mjs \
  "$EVIDENCE_DIR/openapi.json" \
  "$EVIDENCE_DIR/route-list.raw.json" \
  "$EVIDENCE_DIR/stocktake.postman.collection.json" \
  "$EVIDENCE_DIR/stocktake-test-cases.json" \
  "$EVIDENCE_DIR/stocktake-contract-verification.json"

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

php artisan evidence:stock-snapshot \
  --label=before-newman \
  --output="$EVIDENCE_DIR/db-before.json"

php artisan serve --host=127.0.0.1 --port=8000 > "$EVIDENCE_DIR/server.raw.log" 2>&1 &
SERVER_PID=$!
cleanup_server() {
  kill "$SERVER_PID" 2>/dev/null || true
  wait "$SERVER_PID" 2>/dev/null || true
}
trap cleanup_server EXIT

SERVER_READY=false
for _ in $(seq 1 30); do
  if curl --fail --silent --show-error http://127.0.0.1:8000/api/health > "$EVIDENCE_DIR/health-response.json"; then
    SERVER_READY=true
    break
  fi
  sleep 1
done
if [[ "$SERVER_READY" != "true" ]]; then
  echo 'Laravel test server không sẵn sàng sau 30 giây.' >&2
  exit 1
fi

npx newman run "$EVIDENCE_DIR/postman.collection.json" \
  -e "$EVIDENCE_DIR/postman.environment.json" \
  --env-var 'email=manager01@gmail.com' \
  --env-var 'password=Cntt@1234' \
  --env-var 'noPermissionEmail=test-no-permission@hoanam.com' \
  --env-var 'noPermissionPassword=Cntt@1234' \
  --reporters cli,json \
  --reporter-json-export "$EVIDENCE_DIR/newman.result.json" \
  2>&1 | tee "$EVIDENCE_DIR/newman.raw.log"

npx newman run "$EVIDENCE_DIR/stocktake.postman.collection.json" \
  -e "$EVIDENCE_DIR/stocktake.postman.environment.json" \
  --env-var 'email=manager01@gmail.com' \
  --env-var 'password=Cntt@1234' \
  --reporters cli,json \
  --reporter-json-export "$EVIDENCE_DIR/stocktake-newman.result.json" \
  2>&1 | tee "$EVIDENCE_DIR/stocktake-newman.raw.log"

php artisan evidence:stock-snapshot \
  --label=before-wwhn150-scan-newman \
  --output="$EVIDENCE_DIR/scan-db-before.json"

npx newman run "$EVIDENCE_DIR/wwhn150.postman.collection.json" \
  -e "$EVIDENCE_DIR/wwhn150.postman.environment.json" \
  --reporters cli,json \
  --reporter-json-export "$EVIDENCE_DIR/wwhn150-newman.result.json" \
  2>&1 | tee "$EVIDENCE_DIR/wwhn150-newman.raw.log"

php artisan evidence:stock-snapshot \
  --label=after-wwhn150-scan-newman \
  --output="$EVIDENCE_DIR/scan-db-after.json"

node scripts/compare-scan-readonly-evidence.mjs \
  "$EVIDENCE_DIR/scan-db-before.json" \
  "$EVIDENCE_DIR/scan-db-after.json" \
  "$EVIDENCE_DIR/scan-readonly-comparison.json"

php artisan evidence:stock-snapshot \
  --label=after-newman \
  --output="$EVIDENCE_DIR/db-after.json"

node scripts/verify-inventory-reconciliation.mjs \
  "$EVIDENCE_DIR/db-after.json" \
  "$EVIDENCE_DIR/reconciliation-report.json"

cleanup_server
trap - EXIT

SOURCE_TREE_SHA256="$(git archive --format=tar HEAD | sha256sum | awk '{print $1}')"
printf '%s  git-archive-%s.tar\n' "$SOURCE_TREE_SHA256" "$COMMIT_SHA" > "$EVIDENCE_DIR/source-tree.sha256"

OPENAPI_SHA256="$(sha256sum "$EVIDENCE_DIR/openapi.json" | awk '{print $1}')"
WWHN150_POSTMAN_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn150.postman.collection.json" | awk '{print $1}')"
PERMISSION_SEED_SHA256="$(sha256sum Modules/Auth/database/seeders/AuthDatabaseSeeder.php | awk '{print $1}')"
WAREHOUSE_ROUTES_SHA256="$(sha256sum Modules/Warehouse/routes/api.php | awk '{print $1}')"
COMPOSER_LOCK_SHA256="$(sha256sum composer.lock | awk '{print $1}')"
PACKAGE_LOCK_SHA256="$(sha256sum package-lock.json | awk '{print $1}')"

cat > "$EVIDENCE_DIR/build-metadata.json" <<JSON
{
  "commit_sha": "$COMMIT_SHA",
  "source_tree_sha256": "$SOURCE_TREE_SHA256",
  "build_time_utc": "$BUILD_TIME",
  "artifact_name": "wwhn-150-evidence-$COMMIT_SHA",
  "php_version": "$(php -r 'echo PHP_VERSION;')",
  "node_version": "$(node --version)",
  "database_connection": "${DB_CONNECTION:-unknown}",
  "openapi_sha256": "$OPENAPI_SHA256",
  "wwhn150_postman_sha256": "$WWHN150_POSTMAN_SHA256",
  "permission_seed_sha256": "$PERMISSION_SEED_SHA256",
  "warehouse_routes_sha256": "$WAREHOUSE_ROUTES_SHA256",
  "composer_lock_sha256": "$COMPOSER_LOCK_SHA256",
  "package_lock_sha256": "$PACKAGE_LOCK_SHA256",
  "postman_collection_version": "2.0.0",
  "stocktake_postman_collection_version": "1.0.0",
  "wwhn150_postman_collection_version": "1.0.0",
  "stocktake_test_case_version": "1.1.0",
  "wwhn150_test_case_version": "1.0.0",
  "stocktake_contract_verifier_version": "1.1.0",
  "wwhn150_contract_verifier_version": "1.0.0",
  "document_status": "PROPOSED",
  "approval_status": "PENDING_FORMAL_JIRA_APPROVAL",
  "deployment_sha": null
}
JSON

(
  cd "$EVIDENCE_DIR"
  find . -type f ! -name 'SHA256SUMS' -printf '%P\n' | LC_ALL=C sort | while IFS= read -r file; do
    sha256sum "$file"
  done > SHA256SUMS
)

cat "$EVIDENCE_DIR/SHA256SUMS"
