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

php artisan route:list --path=api/v1 -vv --json > "$EVIDENCE_DIR/route-list.raw.json"
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
cp "$STOCKTAKE_COLLECTION" "$EVIDENCE_DIR/source/"
cp "$STOCKTAKE_TEST_CASES" "$EVIDENCE_DIR/source/"
cp "$STOCKTAKE_SOURCE_REGISTER" "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/StocktakeAdjustmentService.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/StocktakeService.php "$EVIDENCE_DIR/source/"
cp tests/Feature/StocktakeThreeLayerContractTest.php "$EVIDENCE_DIR/source/"

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
  --label=after-newman \
  --output="$EVIDENCE_DIR/db-after.json"

cleanup_server
trap - EXIT

SOURCE_TREE_SHA256="$(git archive --format=tar HEAD | sha256sum | awk '{print $1}')"
printf '%s  git-archive-%s.tar\n' "$SOURCE_TREE_SHA256" "$COMMIT_SHA" > "$EVIDENCE_DIR/source-tree.sha256"

cat > "$EVIDENCE_DIR/build-metadata.json" <<JSON
{
  "commit_sha": "$COMMIT_SHA",
  "source_tree_sha256": "$SOURCE_TREE_SHA256",
  "build_time_utc": "$BUILD_TIME",
  "php_version": "$(php -r 'echo PHP_VERSION;')",
  "node_version": "$(node --version)",
  "database_connection": "${DB_CONNECTION:-unknown}",
  "postman_collection_version": "2.0.0",
  "stocktake_postman_collection_version": "1.0.0",
  "stocktake_test_case_version": "1.0.0",
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
