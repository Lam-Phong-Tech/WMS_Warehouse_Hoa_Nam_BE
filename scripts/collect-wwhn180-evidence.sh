#!/usr/bin/env bash
set -euo pipefail

EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/wwhn180}"
COLLECTION="docs/postman/WWHN-180 Posting Contract.postman_collection.json"
ENVIRONMENT="docs/postman/WWHN-180 Posting CI.postman_environment.json"
CONTRACT="docs/api-contract/WWHN-180-posting-openapi.yaml"
COMMIT_SHA="$(git rev-parse HEAD)"
BUILD_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
WWHN180_EMAIL="${WWHN180_EMAIL:?WWHN180_EMAIL is required}"
WWHN180_PASSWORD="${WWHN180_PASSWORD:?WWHN180_PASSWORD is required}"

rm -rf "$EVIDENCE_DIR"
mkdir -p "$EVIDENCE_DIR/source"

php artisan migrate:fresh --force 2>&1 | tee "$EVIDENCE_DIR/migrate-fresh.raw.log"
php artisan db:seed --force 2>&1 | tee "$EVIDENCE_DIR/database-seed.raw.log"
php artisan route:list --path=api/v1 -vv --json > "$EVIDENCE_DIR/route-list.raw.json"
php artisan test --do-not-cache-result --filter=Wwhn180IdempotencyAtomicityTest 2>&1 | tee "$EVIDENCE_DIR/wwhn180-atomicity-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=StockDocumentIdempotencyTest 2>&1 | tee "$EVIDENCE_DIR/stock-document-idempotency-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=StockDocumentConcurrencyGuardTest 2>&1 | tee "$EVIDENCE_DIR/stock-document-concurrency-phpunit.raw.log"
php artisan l5-swagger:generate 2>&1 | tee "$EVIDENCE_DIR/openapi-generate.raw.log"
cp storage/api-docs/api-docs.json "$EVIDENCE_DIR/openapi.generated.json"
cp "$CONTRACT" "$EVIDENCE_DIR/wwhn180-openapi.yaml"
cp "$COLLECTION" "$EVIDENCE_DIR/wwhn180.postman.collection.json"
cp "$ENVIRONMENT" "$EVIDENCE_DIR/wwhn180.postman.environment.json"
php artisan evidence:permission-catalog --output="$EVIDENCE_DIR/permission-catalog.json"

cp app/Support/IdempotencyStore.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Http/Controllers/OutboundDocumentController.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/StockDocumentService.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/StockLedger.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/routes/api.php "$EVIDENCE_DIR/source/warehouse-api-routes.php"
cp tests/Feature/Wwhn180IdempotencyAtomicityTest.php "$EVIDENCE_DIR/source/"
cp tests/Feature/StockDocumentIdempotencyTest.php "$EVIDENCE_DIR/source/"
cp tests/Feature/StockDocumentConcurrencyGuardTest.php "$EVIDENCE_DIR/source/"
cp "$CONTRACT" "$COLLECTION" "$ENVIRONMENT" "$EVIDENCE_DIR/source/"

# Test migrations may reset data. Rebuild deterministic seed for executable API evidence.
php artisan migrate:fresh --force 2>&1 | tee "$EVIDENCE_DIR/newman-migrate-fresh.raw.log"
php artisan db:seed --force 2>&1 | tee "$EVIDENCE_DIR/newman-database-seed.raw.log"
php artisan evidence:stock-snapshot --label=before-wwhn180-newman --output="$EVIDENCE_DIR/db-before.json"

php artisan serve --host=127.0.0.1 --port=8000 > "$EVIDENCE_DIR/server.raw.log" 2>&1 &
SERVER_PID=$!
cleanup_server() {
  kill "$SERVER_PID" 2>/dev/null || true
  wait "$SERVER_PID" 2>/dev/null || true
}
trap cleanup_server EXIT

for _ in $(seq 1 30); do
  if curl --fail --silent http://127.0.0.1:8000/api/health > "$EVIDENCE_DIR/health-response.json"; then
    break
  fi
  sleep 1
done
curl --fail --silent http://127.0.0.1:8000/api/health > /dev/null

npx newman run "$EVIDENCE_DIR/wwhn180.postman.collection.json" \
  -e "$EVIDENCE_DIR/wwhn180.postman.environment.json" \
  --env-var "email=$WWHN180_EMAIL" \
  --env-var "password=$WWHN180_PASSWORD" \
  --reporters cli,json \
  --reporter-json-export "$EVIDENCE_DIR/newman.result.json" \
  2>&1 | tee "$EVIDENCE_DIR/newman.raw.log"

php artisan evidence:stock-snapshot --label=after-wwhn180-newman --output="$EVIDENCE_DIR/db-after.json"
node scripts/verify-inventory-reconciliation.mjs "$EVIDENCE_DIR/db-after.json" "$EVIDENCE_DIR/reconciliation-report.json"

cleanup_server
trap - EXIT

SOURCE_TREE_SHA256="$(git archive --format=tar HEAD | sha256sum | awk '{print $1}')"
printf '%s  git-archive-%s.tar\n' "$SOURCE_TREE_SHA256" "$COMMIT_SHA" > "$EVIDENCE_DIR/source-tree.sha256"

cat > "$EVIDENCE_DIR/build-metadata.json" <<JSON
{
  "jira_key": "WWHN-180",
  "branch": "m1",
  "commit_sha": "$COMMIT_SHA",
  "source_tree_sha256": "$SOURCE_TREE_SHA256",
  "build_time_utc": "$BUILD_TIME",
  "artifact_name": "wwhn-180-evidence-$COMMIT_SHA",
  "php_version": "$(php -r 'echo PHP_VERSION;')",
  "node_version": "$(node --version)",
  "openapi_contract_version": "1.0.0",
  "postman_collection_version": "1.0.0",
  "document_status": "PROPOSED",
  "approval_status": "PENDING_INDEPENDENT_SIGN_OFF"
}
JSON

(
  cd "$EVIDENCE_DIR"
  find . -type f ! -name SHA256SUMS -printf '%P\n' | LC_ALL=C sort | while IFS= read -r file; do
    sha256sum "$file"
  done > SHA256SUMS
  sha256sum --check SHA256SUMS
)
