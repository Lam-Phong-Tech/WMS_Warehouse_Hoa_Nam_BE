#!/usr/bin/env bash
set -euo pipefail

EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/api-data}"
COLLECTION="docs/postman/WWHN-180 Posting Contract.postman_collection.json"
ENVIRONMENT="docs/postman/WWHN-180 Posting CI.postman_environment.json"
CONTRACT="docs/api-contract/WWHN-180-posting-openapi.yaml"
TASK_DOC="docs/docs_task_jira/WWHN-180.md"
SOURCE_REGISTER="docs/docs_task_jira/WWHN-180-source-register.json"
TEST_CASES="docs/testing/WWHN-180-posting-test-cases.json"
COMMIT_SHA="$(git rev-parse HEAD)"
BUILD_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
ARTIFACT_NAME="api-data-evidence-$COMMIT_SHA"
WWHN180_EMAIL="${WWHN180_EMAIL:?WWHN180_EMAIL is required at runtime}"
WWHN180_PASSWORD="${WWHN180_PASSWORD:?WWHN180_PASSWORD is required at runtime}"
WWHN180_NO_PERMISSION_EMAIL="${WWHN180_NO_PERMISSION_EMAIL:?WWHN180_NO_PERMISSION_EMAIL is required at runtime}"
WWHN180_NO_PERMISSION_PASSWORD="${WWHN180_NO_PERMISSION_PASSWORD:?WWHN180_NO_PERMISSION_PASSWORD is required at runtime}"

rm -rf "$EVIDENCE_DIR"
mkdir -p "$EVIDENCE_DIR/source"

php artisan migrate:fresh --force 2>&1 | tee "$EVIDENCE_DIR/migrate-fresh.raw.log"
php artisan db:seed --force 2>&1 | tee "$EVIDENCE_DIR/database-seed.raw.log"
php artisan route:list --path=api/v1 -vv --json > "$EVIDENCE_DIR/route-list.raw.json"
php artisan evidence:permission-catalog --output="$EVIDENCE_DIR/permission-catalog.json"

# Full regression trước khi thu API evidence; log này là nguồn kiểm chứng chính, không dùng test count viết tay.
php artisan test --do-not-cache-result 2>&1 | tee "$EVIDENCE_DIR/phpunit.raw.log"
php artisan test --do-not-cache-result --filter=Wwhn180IdempotencyAtomicityTest 2>&1 | tee "$EVIDENCE_DIR/wwhn180-atomicity-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=StockDocumentCanonicalFlowTest 2>&1 | tee "$EVIDENCE_DIR/stock-document-canonical-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=StockDocumentConcurrencyGuardTest 2>&1 | tee "$EVIDENCE_DIR/stock-document-concurrency-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=InventoryReconciliationIntegrityTest 2>&1 | tee "$EVIDENCE_DIR/reconciliation-integrity-phpunit.raw.log"

php artisan l5-swagger:generate 2>&1 | tee "$EVIDENCE_DIR/openapi-generate.raw.log"
cp storage/api-docs/api-docs.json "$EVIDENCE_DIR/openapi.json"
php -r 'require "vendor/autoload.php"; $j=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); file_put_contents($argv[2], Symfony\Component\Yaml\Yaml::dump($j, 20, 2, Symfony\Component\Yaml\Yaml::DUMP_OBJECT_AS_MAP));' \
  "$EVIDENCE_DIR/openapi.json" "$EVIDENCE_DIR/openapi.yaml"
cp "$CONTRACT" "$EVIDENCE_DIR/wwhn180-openapi.yaml"
cp "$COLLECTION" "$EVIDENCE_DIR/wwhn180.postman.collection.json"
cp "$ENVIRONMENT" "$EVIDENCE_DIR/wwhn180.postman.environment.json"
cp "$SOURCE_REGISTER" "$EVIDENCE_DIR/wwhn180-source-register.json"
cp "$TEST_CASES" "$EVIDENCE_DIR/wwhn180-test-cases.json"

cp app/Support/IdempotencyStore.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Http/Controllers/Concerns/InteractsWithStockDocuments.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Http/Controllers/OutboundDocumentController.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/StockDocumentService.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Services/StockLedger.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Models/StockDocument.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/routes/api.php "$EVIDENCE_DIR/source/warehouse-api-routes.php"
cp tests/Feature/Wwhn180IdempotencyAtomicityTest.php "$EVIDENCE_DIR/source/"
cp tests/Feature/StockDocumentCanonicalFlowTest.php "$EVIDENCE_DIR/source/"
cp tests/Feature/StockDocumentConcurrencyGuardTest.php "$EVIDENCE_DIR/source/"
cp tests/Feature/InventoryReconciliationIntegrityTest.php "$EVIDENCE_DIR/source/"
cp scripts/verify-wwhn180-evidence.mjs "$EVIDENCE_DIR/source/"
cp scripts/verify-wwhn180-db-delta.mjs "$EVIDENCE_DIR/source/"
cp "$TASK_DOC" "$CONTRACT" "$COLLECTION" "$ENVIRONMENT" "$SOURCE_REGISTER" "$TEST_CASES" "$EVIDENCE_DIR/source/"

node scripts/verify-wwhn180-evidence.mjs \
  "$EVIDENCE_DIR/wwhn180.postman.collection.json" \
  "$EVIDENCE_DIR/wwhn180.postman.environment.json" \
  app/Support/IdempotencyStore.php \
  "$EVIDENCE_DIR/wwhn180-contract-verification.json"

# Các test dùng RefreshDatabase; dựng lại seed xác định trước API/Newman evidence.
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

SERVER_READY=false
for _ in $(seq 1 30); do
  if curl --fail --silent --show-error http://127.0.0.1:8000/api/health > "$EVIDENCE_DIR/health-response.json"; then
    SERVER_READY=true
    break
  fi
  sleep 1
done
if [[ "$SERVER_READY" != "true" ]]; then
  echo 'Laravel test server WWHN-180 không sẵn sàng sau 30 giây.' >&2
  exit 1
fi

npx newman run "$EVIDENCE_DIR/wwhn180.postman.collection.json" \
  -e "$EVIDENCE_DIR/wwhn180.postman.environment.json" \
  --env-var "email=$WWHN180_EMAIL" \
  --env-var "password=$WWHN180_PASSWORD" \
  --env-var "noPermissionEmail=$WWHN180_NO_PERMISSION_EMAIL" \
  --env-var "noPermissionPassword=$WWHN180_NO_PERMISSION_PASSWORD" \
  --reporters cli,json \
  --reporter-json-export "$EVIDENCE_DIR/newman.result.json" \
  2>&1 | tee "$EVIDENCE_DIR/newman.raw.log"

php artisan evidence:stock-snapshot --label=after-wwhn180-newman --output="$EVIDENCE_DIR/db-after.json"
node scripts/verify-wwhn180-db-delta.mjs \
  "$EVIDENCE_DIR/db-before.json" \
  "$EVIDENCE_DIR/db-after.json" \
  "$EVIDENCE_DIR/wwhn180-db-delta.json"
node scripts/verify-inventory-reconciliation.mjs \
  "$EVIDENCE_DIR/db-after.json" \
  "$EVIDENCE_DIR/reconciliation-report.json"

cleanup_server
trap - EXIT

SOURCE_TREE_SHA256="$(git archive --format=tar HEAD | sha256sum | awk '{print $1}')"
printf '%s  git-archive-%s.tar\n' "$SOURCE_TREE_SHA256" "$COMMIT_SHA" > "$EVIDENCE_DIR/source-tree.sha256"
OPENAPI_SHA256="$(sha256sum "$EVIDENCE_DIR/openapi.json" | awk '{print $1}')"
POSTMAN_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn180.postman.collection.json" | awk '{print $1}')"
ENVIRONMENT_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn180.postman.environment.json" | awk '{print $1}')"
PERMISSION_CATALOG_SHA256="$(sha256sum "$EVIDENCE_DIR/permission-catalog.json" | awk '{print $1}')"
ROUTE_LIST_SHA256="$(sha256sum "$EVIDENCE_DIR/route-list.raw.json" | awk '{print $1}')"
CI_RUN_ID="${GITHUB_RUN_ID:-LOCAL}"
CI_RUN_URL="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE}/actions/runs/$CI_RUN_ID"
COMMIT_URL="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE}/commit/$COMMIT_SHA"

cat > "$EVIDENCE_DIR/build-metadata.json" <<JSON
{
  "jira_key": "WWHN-180",
  "repository": "${GITHUB_REPOSITORY:-Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE}",
  "branch": "${GITHUB_REF_NAME:-m1}",
  "commit_sha": "$COMMIT_SHA",
  "commit_url": "$COMMIT_URL",
  "source_tree_sha256": "$SOURCE_TREE_SHA256",
  "build_time_utc": "$BUILD_TIME",
  "artifact_name": "$ARTIFACT_NAME",
  "workflow_run_id": "$CI_RUN_ID",
  "workflow_run_attempt": "${GITHUB_RUN_ATTEMPT:-LOCAL}",
  "workflow_run_url": "$CI_RUN_URL",
  "workflow_ref": "${GITHUB_WORKFLOW_REF:-LOCAL}",
  "actor": "${GITHUB_ACTOR:-LOCAL}",
  "php_version": "$(php -r 'echo PHP_VERSION;')",
  "node_version": "$(node --version)",
  "database_connection": "${DB_CONNECTION:-unknown}",
  "openapi_contract_version": "1.0.0",
  "postman_collection_version": "1.1.0",
  "postman_request_count": 23,
  "openapi_sha256": "$OPENAPI_SHA256",
  "postman_collection_sha256": "$POSTMAN_SHA256",
  "postman_environment_sha256": "$ENVIRONMENT_SHA256",
  "permission_catalog_sha256": "$PERMISSION_CATALOG_SHA256",
  "route_list_sha256": "$ROUTE_LIST_SHA256",
  "document_status": "PROPOSED",
  "approval_status": "PENDING_INDEPENDENT_SIGN_OFF",
  "deployment_sha": null
}
JSON

cat > "$EVIDENCE_DIR/g1-closure-matrix.json" <<JSON
{
  "schema_version": "1.0.0",
  "generated_at_utc": "$BUILD_TIME",
  "branch": "${GITHUB_REF_NAME:-m1}",
  "exact_commit_sha": "$COMMIT_SHA",
  "artifact_name": "$ARTIFACT_NAME",
  "manifest": "SHA256SUMS",
  "manifest_digest": "SHA256SUMS.sha256",
  "document_status": "PROPOSED",
  "approval_status": "PENDING_INDEPENDENT_SIGN_OFF",
  "rows": [
    {"id":"BE1-P0-01","be_status":"BE_IMPLEMENTED","closure_condition":"PENDING_BA_FE1_QA2_INDEPENDENT_SIGN_OFF"},
    {"id":"BE1-P0-02","be_status":"BE_IMPLEMENTED_AND_AUTOMATED","closure_condition":"PENDING_QA2_INDEPENDENT_RETEST"},
    {"id":"BE1-P1-01","be_status":"IMPLEMENTED","closure_condition":"VERIFY_OPENAPI_POSTMAN_AND_MANIFEST"},
    {"id":"BE1-P1-02","be_status":"IMPLEMENTED","closure_condition":"VERIFY_CI_RUN_AND_RAW_LOGS"},
    {"id":"BE1-P1-03","be_status":"IMPLEMENTED","closure_condition":"PENDING_FE1_AND_BA_MAPPING_REVIEW"},
    {"id":"BE1-P1-04","be_status":"AUTOMATED","closure_condition":"PENDING_QA2_API_DB_RECONCILE_EVIDENCE"},
    {"id":"BE1-P1-05","be_status":"READY","closure_condition":"PENDING_BA_G1_MINUTES"},
    {"id":"BE1-P2-01","be_status":"READY","closure_condition":"PENDING_JIRA_COMMENT_POSTING"}
  ],
  "reviewers": {
    "BA": {"result":"PENDING","reviewer":null,"reviewed_at":null,"evidence_url":null},
    "FE1": {"result":"PENDING","reviewer":null,"reviewed_at":null,"evidence_url":null},
    "QA2": {"result":"PENDING","reviewer":null,"reviewed_at":null,"evidence_url":null}
  },
  "final_decision": "BLOCKED_PENDING_INDEPENDENT_SIGN_OFF"
}
JSON

cat > "$EVIDENCE_DIR/jira-closure-comment.txt" <<TEXT
[WWHN-180 API/DATA CLOSURE MATRIX]
Branch: ${GITHUB_REF_NAME:-m1} — direct commits, no separate PR
Exact review SHA: $COMMIT_SHA
Commit: $COMMIT_URL
CI run: $CI_RUN_URL
Artifact: $ARTIFACT_NAME
Manifest: SHA256SUMS; digest: SHA256SUMS.sha256
OpenAPI JSON/YAML: INCLUDED
Postman collection/environment: INCLUDED — WWHN-180 v1.1.0, 23 requests, no committed credential
Route list / permission catalog / PHPUnit / Newman / DB before-after / reconcile: INCLUDED

BE1-P0-01: BE IMPLEMENTED / PENDING BA+FE1+QA2
BE1-P0-02: BE IMPLEMENTED / PENDING QA2 INDEPENDENT RETEST
BE1-P1-01: IMPLEMENTED / VERIFY MANIFEST
BE1-P1-02: IMPLEMENTED / VERIFY CI RUN
BE1-P1-03: IMPLEMENTED / PENDING FE1+BA REVIEW
BE1-P1-04: AUTOMATED / PENDING QA2 RETEST
BE1-P1-05: READY / PENDING BA G1 MINUTES
BE1-P2-01: READY TO POST

BA G1: PENDING
FE1: PENDING
QA2: PENDING
Final: BLOCKED_PENDING_INDEPENDENT_SIGN_OFF
TEXT

cat > "$EVIDENCE_DIR/ci-summary.md" <<MARKDOWN
# WWHN-180 API/Data evidence

- Branch: \`${GITHUB_REF_NAME:-m1}\`
- Exact SHA: \`$COMMIT_SHA\`
- Artifact: \`$ARTIFACT_NAME\`
- Status: **PROPOSED — PENDING INDEPENDENT SIGN-OFF**
- OpenAPI JSON/YAML, Postman v1.1.0 (23 requests), route list, permission catalog, full PHPUnit, focused concurrency tests, Newman, DB before/after and reconciliation are included.
- Failed workflow artifacts are diagnostics only and must not be used as closure evidence.
MARKDOWN

rm -f "$EVIDENCE_DIR/SHA256SUMS" "$EVIDENCE_DIR/SHA256SUMS.sha256"
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

cat "$EVIDENCE_DIR/SHA256SUMS.sha256"
