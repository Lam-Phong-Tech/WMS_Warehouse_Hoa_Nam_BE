#!/usr/bin/env bash
set -euo pipefail
node scripts/prepare-api-toolchain.mjs

EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/api-data}"
COLLECTION="docs/postman/WWHN-180 Posting Contract.postman_collection.json"
ENVIRONMENT="docs/postman/WWHN-180 Posting CI.postman_environment.json"
CONTRACT="docs/api-contract/WWHN-180-posting-openapi.yaml"
TASK_DOC="docs/docs_task_jira/WWHN-180.md"
SOURCE_REGISTER="docs/docs_task_jira/WWHN-180-source-register.json"
TEST_CASES="docs/testing/WWHN-180-posting-test-cases.json"
STATIC_PERMISSION_CATALOG="docs/evidence/WWHN-180/permission-catalog.json"
BE_FE_MAPPING="docs/evidence/WWHN-180/BE_FE_PERMISSION_MAPPING.json"
COMMIT_SHA="$(git rev-parse HEAD)"
BUILD_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
ARTIFACT_NAME="api-data-evidence-$COMMIT_SHA"
WWHN180_EMAIL="${WWHN180_EMAIL:?WWHN180_EMAIL is required at runtime}"
WWHN180_PASSWORD="${WWHN180_PASSWORD:?WWHN180_PASSWORD is required at runtime}"
WWHN180_NO_PERMISSION_EMAIL="${WWHN180_NO_PERMISSION_EMAIL:?WWHN180_NO_PERMISSION_EMAIL is required at runtime}"
WWHN180_NO_PERMISSION_PASSWORD="${WWHN180_NO_PERMISSION_PASSWORD:?WWHN180_NO_PERMISSION_PASSWORD is required at runtime}"
CI_DEMO_PASSWORD="${CI_DEMO_PASSWORD:?CI_DEMO_PASSWORD is required at runtime}"

if [[ "$WWHN180_PASSWORD" != "$CI_DEMO_PASSWORD" || "$WWHN180_NO_PERMISSION_PASSWORD" != "$CI_DEMO_PASSWORD" ]]; then
  echo 'WWHN-180 runtime credentials must match CI_DEMO_PASSWORD used by AuthDatabaseSeeder.' >&2
  exit 1
fi

rm -rf "$EVIDENCE_DIR"
mkdir -p "$EVIDENCE_DIR/source"

php artisan migrate:fresh --force 2>&1 | tee "$EVIDENCE_DIR/migrate-fresh.raw.log"
php artisan db:seed --force 2>&1 | tee "$EVIDENCE_DIR/database-seed.raw.log"
php artisan route:list --path=api/v1 -vv --json > "$EVIDENCE_DIR/route-list.raw.json"
php artisan evidence:permission-catalog --output="$EVIDENCE_DIR/permission-catalog.json"

php artisan test --do-not-cache-result 2>&1 | tee "$EVIDENCE_DIR/phpunit.raw.log"
php artisan test --do-not-cache-result --filter=Wwhn180IdempotencyAtomicityTest 2>&1 | tee "$EVIDENCE_DIR/wwhn180-atomicity-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=StockDocumentCanonicalFlowTest 2>&1 | tee "$EVIDENCE_DIR/stock-document-canonical-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=StockDocumentConcurrencyGuardTest 2>&1 | tee "$EVIDENCE_DIR/stock-document-concurrency-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=Wwhn180PermissionAliasContractTest 2>&1 | tee "$EVIDENCE_DIR/wwhn180-permission-alias-phpunit.raw.log"
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
cp "$STATIC_PERMISSION_CATALOG" "$EVIDENCE_DIR/permission-catalog.static.json"
cp "$BE_FE_MAPPING" "$EVIDENCE_DIR/be-fe-permission-mapping.json"

cp app/Support/IdempotencyKeyGuard.php "$EVIDENCE_DIR/source/"
cp app/Support/IdempotencyStore.php "$EVIDENCE_DIR/source/"
cp Modules/Auth/database/seeders/WmsPermissionCompatibilitySeeder.php "$EVIDENCE_DIR/source/"
cp database/seeders/DatabaseSeeder.php "$EVIDENCE_DIR/source/"
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
cp tests/Feature/Wwhn180PermissionAliasContractTest.php "$EVIDENCE_DIR/source/"
cp tests/Feature/InventoryReconciliationIntegrityTest.php "$EVIDENCE_DIR/source/"
cp scripts/verify-wwhn180-evidence.mjs "$EVIDENCE_DIR/source/"
cp scripts/verify-wwhn180-db-delta.mjs "$EVIDENCE_DIR/source/"
cp scripts/redact-wwhn180-evidence.mjs "$EVIDENCE_DIR/source/"
cp "$TASK_DOC" "$CONTRACT" "$COLLECTION" "$ENVIRONMENT" "$SOURCE_REGISTER" "$TEST_CASES" "$STATIC_PERMISSION_CATALOG" "$BE_FE_MAPPING" "$EVIDENCE_DIR/source/"

node scripts/verify-wwhn180-evidence.mjs \
  "$EVIDENCE_DIR/wwhn180.postman.collection.json" \
  "$EVIDENCE_DIR/wwhn180.postman.environment.json" \
  app/Support/IdempotencyStore.php \
  "$EVIDENCE_DIR/wwhn180-contract-verification.json"

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

# Readiness and business health are deliberately separated. A 503 health response
# proves the HTTP server is reachable but may represent a degraded business marker.
# Newman/API assertions, DB delta and reconciliation remain the release hard gates.
SERVER_READY=false
HEALTH_HTTP_CODE="000"
for _ in $(seq 1 30); do
  if ! kill -0 "$SERVER_PID" 2>/dev/null; then
    echo 'Laravel test server WWHN-180 đã dừng trước readiness gate.' >&2
    tail -100 "$EVIDENCE_DIR/server.raw.log" >&2 || true
    exit 1
  fi

  HEALTH_HTTP_CODE="$(curl --silent --show-error \
    --connect-timeout 2 --max-time 5 \
    --output "$EVIDENCE_DIR/health-response.json.tmp" \
    --write-out '%{http_code}' \
    http://127.0.0.1:8000/api/health || true)"

  if [[ "$HEALTH_HTTP_CODE" =~ ^[1-5][0-9][0-9]$ ]]; then
    mv "$EVIDENCE_DIR/health-response.json.tmp" "$EVIDENCE_DIR/health-response.json"
    SERVER_READY=true
    break
  fi

  rm -f "$EVIDENCE_DIR/health-response.json.tmp"
  sleep 1
done

printf '%s\n' "$HEALTH_HTTP_CODE" > "$EVIDENCE_DIR/health-http-status.txt"
if [[ "$SERVER_READY" != "true" ]]; then
  echo 'Laravel test server WWHN-180 không trả về HTTP response sau 30 giây.' >&2
  tail -100 "$EVIDENCE_DIR/server.raw.log" >&2 || true
  exit 1
fi

node - "$EVIDENCE_DIR/health-response.json" "$HEALTH_HTTP_CODE" "$EVIDENCE_DIR/health-probe-evidence.json" <<'NODE'
const fs = require('fs');
const [input, code, output] = process.argv.slice(2);
let body = null;
try {
  body = JSON.parse(fs.readFileSync(input, 'utf8'));
} catch {
  body = {parse_status: 'NON_JSON_RESPONSE'};
}
fs.writeFileSync(output, `${JSON.stringify({
  status: 'HTTP_SERVER_READY',
  http_status: Number(code),
  business_health_status: body?.data?.status ?? body?.status ?? 'unknown',
  response: body,
}, null, 2)}\n`);
NODE

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

node scripts/redact-wwhn180-evidence.mjs "$EVIDENCE_DIR"

SOURCE_TREE_SHA256="$(git archive --format=tar HEAD | sha256sum | awk '{print $1}')"
printf '%s  git-archive-%s.tar\n' "$SOURCE_TREE_SHA256" "$COMMIT_SHA" > "$EVIDENCE_DIR/source-tree.sha256"
OPENAPI_SHA256="$(sha256sum "$EVIDENCE_DIR/openapi.json" | awk '{print $1}')"
FOCUSED_OPENAPI_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn180-openapi.yaml" | awk '{print $1}')"
POSTMAN_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn180.postman.collection.json" | awk '{print $1}')"
ENVIRONMENT_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn180.postman.environment.json" | awk '{print $1}')"
PERMISSION_CATALOG_SHA256="$(sha256sum "$EVIDENCE_DIR/permission-catalog.json" | awk '{print $1}')"
STATIC_PERMISSION_CATALOG_SHA256="$(sha256sum "$EVIDENCE_DIR/permission-catalog.static.json" | awk '{print $1}')"
BE_FE_MAPPING_SHA256="$(sha256sum "$EVIDENCE_DIR/be-fe-permission-mapping.json" | awk '{print $1}')"
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
  "workflow_event": "${GITHUB_EVENT_NAME:-LOCAL}",
  "actor": "${GITHUB_ACTOR:-LOCAL}",
  "runner_os": "${RUNNER_OS:-LOCAL}",
  "php_version": "$(php -r 'echo PHP_VERSION;')",
  "node_version": "$(node --version)",
  "database_connection": "${DB_CONNECTION:-unknown}",
  "credential_mode": "EPHEMERAL_PER_WORKFLOW_RUN",
  "credential_persisted": false,
  "health_probe_http_status": $HEALTH_HTTP_CODE,
  "health_probe_semantics": "HTTP_READINESS_SEPARATE_FROM_BUSINESS_HEALTH",
  "openapi_contract_version": "1.0.0",
  "postman_collection_version": "1.1.0",
  "postman_request_count": 23,
  "openapi_sha256": "$OPENAPI_SHA256",
  "focused_openapi_sha256": "$FOCUSED_OPENAPI_SHA256",
  "postman_collection_sha256": "$POSTMAN_SHA256",
  "postman_environment_sha256": "$ENVIRONMENT_SHA256",
  "permission_catalog_sha256": "$PERMISSION_CATALOG_SHA256",
  "static_permission_catalog_sha256": "$STATIC_PERMISSION_CATALOG_SHA256",
  "be_fe_permission_mapping_sha256": "$BE_FE_MAPPING_SHA256",
  "route_list_sha256": "$ROUTE_LIST_SHA256",
  "document_status": "PROPOSED",
  "approval_status": "PENDING_INDEPENDENT_SIGN_OFF",
  "deployment_sha": null
}
JSON

cat > "$EVIDENCE_DIR/g1-closure-matrix.json" <<JSON
{
  "schema_version": "1.1.0",
  "generated_at_utc": "$BUILD_TIME",
  "branch": "${GITHUB_REF_NAME:-m1}",
  "exact_commit_sha": "$COMMIT_SHA",
  "artifact_name": "$ARTIFACT_NAME",
  "manifest": "SHA256SUMS",
  "manifest_digest": "SHA256SUMS.sha256",
  "document_status": "PROPOSED",
  "approval_status": "PENDING_INDEPENDENT_SIGN_OFF",
  "rows": [
    {"id":"BE1-P0-01","be_status":"READY_FOR_INDEPENDENT_SIGN_OFF","closure_condition":"PENDING_BA_FE1_QA2_SAME_SHA_REVIEW"},
    {"id":"BE1-P0-02","be_status":"IMPLEMENTED_AND_AUTOMATED","closure_condition":"PENDING_QA2_INDEPENDENT_RETEST"},
    {"id":"BE1-P1-01","be_status":"IMPLEMENTED","closure_condition":"VERIFY_OPENAPI_POSTMAN_AND_MANIFEST"},
    {"id":"BE1-P1-02","be_status":"CI_EXECUTED","closure_condition":"VERIFY_THIS_PASSING_RUN_AND_RAW_LOGS"},
    {"id":"BE1-P1-03","be_status":"BACKEND_ALIAS_COMPATIBILITY_IMPLEMENTED","closure_condition":"PENDING_FE1_BA_FORMAL_REVIEW"},
    {"id":"BE1-P1-04","be_status":"AUTOMATED","closure_condition":"PENDING_QA2_API_DB_RACE_RECONCILE_COMMENT"},
    {"id":"BE1-P1-05","be_status":"G1_MINUTES_INPUT_READY","closure_condition":"PENDING_BA_FINAL_DECISION"},
    {"id":"BE1-P2-01","be_status":"JIRA_COMMENT_READY","closure_condition":"POST_READABLE_MATRIX"}
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
Branch: ${GITHUB_REF_NAME:-m1} — direct update, no separate PR
Exact review SHA: $COMMIT_SHA
Commit: $COMMIT_URL
CI run: $CI_RUN_URL
Artifact: $ARTIFACT_NAME
Source-tree SHA-256: $SOURCE_TREE_SHA256
Manifest: SHA256SUMS; digest file: SHA256SUMS.sha256
OpenAPI JSON/YAML: INCLUDED
Focused OpenAPI SHA-256: $FOCUSED_OPENAPI_SHA256
Postman collection/environment: INCLUDED — v1.1.0, 23 requests, runtime-only ephemeral credentials
Permission catalog + BE/FE mapping: INCLUDED
Route list / PHPUnit / Newman / DB before-after / race / reconcile: INCLUDED
Health probe: HTTP $HEALTH_HTTP_CODE; readiness separated from business health
Credential hygiene: PASS — no ephemeral password persisted

BE1-P0-01: READY FOR SAME-SHA SIGN-OFF — BA=PENDING, FE1=PENDING, QA2=PENDING
BE1-P0-02: BE PASS / QA2 INDEPENDENT RETEST PENDING
BE1-P1-01: PASS IN ARTIFACT — OpenAPI + Postman + hashes
BE1-P1-02: PASS IN THIS CI RUN — metadata/raw logs/manifest
BE1-P1-03: BACKEND IMPLEMENTED — FE aliases mapped to canonical backend permissions; formal FE1/BA review pending
BE1-P1-04: AUTOMATED PASS IN ARTIFACT — QA2 independent comment pending
BE1-P1-05: INPUT READY — BA G1 decision pending
BE1-P2-01: READY — post readable matrix directly on Jira

Final: BLOCKED_PENDING_INDEPENDENT_SIGN_OFF
TEXT

cat > "$EVIDENCE_DIR/ci-summary.md" <<MARKDOWN
# WWHN-180 API/Data evidence

- Branch: \`${GITHUB_REF_NAME:-m1}\`
- Exact SHA: \`$COMMIT_SHA\`
- Artifact: \`$ARTIFACT_NAME\`
- Status: **PROPOSED — PENDING INDEPENDENT SIGN-OFF**
- Credential mode: **ephemeral per workflow run**; repository secrets are not required and no credential is persisted.
- Health probe: HTTP \`$HEALTH_HTTP_CODE\`; server readiness is separated from business health semantics.
- OpenAPI JSON/YAML, focused OpenAPI, Postman v1.1.0 (23 requests), route list, permission catalog, BE/FE compatibility mapping, full PHPUnit, focused concurrency tests, Newman, DB before/after and reconciliation are included.
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
