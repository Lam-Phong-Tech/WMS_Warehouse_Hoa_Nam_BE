#!/usr/bin/env bash
# WWHN-184 — thu bằng chứng exact-SHA cho luồng chứng từ/trạng thái nhập kho.
#
# Đóng đúng những gì register yêu cầu:
#   DEV184-P1-01R : chạy FULL Newman collection + pin raw output; verifier tĩnh khoá
#                   bất biến version-chaining; parity scoped OpenAPI / Postman / runtime.
#   DEV184-P1-02R : build/migration/health/smoke + metadata gắn exact SHA để DevOps
#                   deploy đúng candidate (script này KHÔNG deploy — nó tạo hồ sơ pin).
#   DEV184-P1-03R : DB before/after quanh Newman + reconciliation, làm input cho QA1.
set -euo pipefail

EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/wwhn-184}"
COLLECTION="docs/postman/WWHN-184 Inbound Contract.postman_collection.json"
ENVIRONMENT="docs/postman/WWHN-184 Inbound CI.postman_environment.json"
SCOPED_OPENAPI_JSON="docs/api-contract/WWHN-184-inbound-openapi.json"
SCOPED_OPENAPI_YAML="docs/api-contract/WWHN-184-inbound-openapi.yaml"
TASK_DOC="docs/docs_task_jira/WWHN-184.md"
COMMIT_SHA="$(git rev-parse HEAD)"
BUILD_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
ARTIFACT_NAME="wwhn-184-inbound-evidence-$COMMIT_SHA"

WWHN184_MANAGER_EMAIL="${WWHN184_MANAGER_EMAIL:?WWHN184_MANAGER_EMAIL is required at runtime}"
WWHN184_STAFF_EMAIL="${WWHN184_STAFF_EMAIL:?WWHN184_STAFF_EMAIL is required at runtime}"
WWHN184_VIEWER_EMAIL="${WWHN184_VIEWER_EMAIL:?WWHN184_VIEWER_EMAIL is required at runtime}"
CI_DEMO_PASSWORD="${CI_DEMO_PASSWORD:?CI_DEMO_PASSWORD is required at runtime}"

rm -rf "$EVIDENCE_DIR"
mkdir -p "$EVIDENCE_DIR/source"

# ---------------------------------------------------------------------------
# 1. Gate tĩnh — chặn regression bất biến version-chaining TRƯỚC khi tốn công chạy
#    server. Đây là lớp bảo vệ mà lần review trước không có.
# ---------------------------------------------------------------------------
node scripts/verify-wwhn184-postman-contract.mjs \
  "$COLLECTION" "$ENVIRONMENT" "$EVIDENCE_DIR/postman-contract-verification.json" \
  2>&1 | tee "$EVIDENCE_DIR/postman-contract-verification.raw.log"

# ---------------------------------------------------------------------------
# 2. Schema + spec
# ---------------------------------------------------------------------------
php artisan migrate:fresh --force 2>&1 | tee "$EVIDENCE_DIR/migrate-fresh.raw.log"
php artisan db:seed --force 2>&1 | tee "$EVIDENCE_DIR/database-seed.raw.log"
php artisan migrate:status 2>&1 | tee "$EVIDENCE_DIR/migrate-status.raw.log"
php artisan route:list --path=api/v1/inbound-documents -vv --json > "$EVIDENCE_DIR/inbound-route-list.raw.json"

php artisan l5-swagger:generate 2>&1 | tee "$EVIDENCE_DIR/openapi-generate.raw.log"
cp storage/api-docs/api-docs.json "$EVIDENCE_DIR/openapi.json"

# ---------------------------------------------------------------------------
# 3. Test — parity Postman/runtime/OpenAPI là bằng chứng cốt lõi của P1-01R.
# ---------------------------------------------------------------------------
php artisan test --do-not-cache-result --filter=Wwhn184InboundContractParityTest 2>&1 \
  | tee "$EVIDENCE_DIR/wwhn184-parity-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=Flow02InboundPutawayAlignmentTest 2>&1 \
  | tee "$EVIDENCE_DIR/flow02-alignment-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=PostReceiptConcurrencyBehaviorTest 2>&1 \
  | tee "$EVIDENCE_DIR/post-receipt-concurrency-phpunit.raw.log"
php artisan test --do-not-cache-result --filter=SwaggerRouteSyncTest 2>&1 \
  | tee "$EVIDENCE_DIR/swagger-route-sync-phpunit.raw.log"
php artisan test --do-not-cache-result 2>&1 | tee "$EVIDENCE_DIR/phpunit-full.raw.log"

# ---------------------------------------------------------------------------
# 4. Newman — chạy FULL collection trên server thật, pin raw output.
# ---------------------------------------------------------------------------
php artisan migrate:fresh --force 2>&1 | tee "$EVIDENCE_DIR/newman-migrate-fresh.raw.log"
php artisan db:seed --force 2>&1 | tee "$EVIDENCE_DIR/newman-database-seed.raw.log"
php artisan evidence:stock-snapshot --label=before-wwhn184-newman --output="$EVIDENCE_DIR/db-before.json"

php artisan serve --host=127.0.0.1 --port=8184 > "$EVIDENCE_DIR/server.raw.log" 2>&1 &
SERVER_PID=$!
cleanup_server() {
  kill "$SERVER_PID" 2>/dev/null || true
  wait "$SERVER_PID" 2>/dev/null || true
}
trap cleanup_server EXIT

# Readiness tách khỏi business health: 503 vẫn chứng minh HTTP server sống.
SERVER_READY=false
HEALTH_HTTP_CODE="000"
for _ in $(seq 1 30); do
  if ! kill -0 "$SERVER_PID" 2>/dev/null; then
    echo 'Laravel test server WWHN-184 đã dừng trước readiness gate.' >&2
    tail -100 "$EVIDENCE_DIR/server.raw.log" >&2 || true
    exit 1
  fi
  HEALTH_HTTP_CODE="$(curl --silent --show-error \
    --connect-timeout 2 --max-time 5 \
    --output "$EVIDENCE_DIR/health-response.json.tmp" \
    --write-out '%{http_code}' \
    http://127.0.0.1:8184/api/health || true)"
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
  echo 'Laravel test server WWHN-184 không trả HTTP response sau 30 giây.' >&2
  tail -100 "$EVIDENCE_DIR/server.raw.log" >&2 || true
  exit 1
fi

npx newman run "$COLLECTION" \
  -e "$ENVIRONMENT" \
  --env-var "base_url=http://127.0.0.1:8184" \
  --env-var "managerEmail=$WWHN184_MANAGER_EMAIL" \
  --env-var "managerPassword=$CI_DEMO_PASSWORD" \
  --env-var "staffEmail=$WWHN184_STAFF_EMAIL" \
  --env-var "staffPassword=$CI_DEMO_PASSWORD" \
  --env-var "viewerEmail=$WWHN184_VIEWER_EMAIL" \
  --env-var "viewerPassword=$CI_DEMO_PASSWORD" \
  --reporters cli,json \
  --reporter-json-export "$EVIDENCE_DIR/newman.result.json" \
  2>&1 | tee "$EVIDENCE_DIR/newman.raw.log"

php artisan evidence:stock-snapshot --label=after-wwhn184-newman --output="$EVIDENCE_DIR/db-after.json"
node scripts/verify-inventory-reconciliation.mjs \
  "$EVIDENCE_DIR/db-after.json" \
  "$EVIDENCE_DIR/reconciliation-report.json"

cleanup_server
trap - EXIT

# ---------------------------------------------------------------------------
# 5. Cổng Newman: zero failure, và phải chạy ĐỦ số request của collection.
#    Reviewer đã từng nhận evidence "pass" của một lần chạy chỉ một phần.
# ---------------------------------------------------------------------------
node - "$EVIDENCE_DIR/newman.result.json" "$COLLECTION" <<'NODE'
const fs = require('fs');
const [resultPath, collectionPath] = process.argv.slice(2);
const result = JSON.parse(fs.readFileSync(resultPath, 'utf8'));
const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));

let declared = 0;
const walk = items => (items || []).forEach(i => (i.item ? walk(i.item) : declared++));
walk(collection.item);

const failures = result.run?.failures ?? [];
const executed = result.run?.stats?.requests?.total ?? 0;
const assertions = result.run?.stats?.assertions ?? {};

if (failures.length) {
  console.error(`Newman FAIL: ${failures.length} assertion(s) đỏ`);
  for (const f of failures) {
    console.error(`  - ${f.source?.name ?? '?'}: ${f.error?.test ?? ''} :: ${f.error?.message ?? ''}`);
  }
  process.exit(1);
}
if (executed !== declared) {
  console.error(`Newman chạy ${executed}/${declared} request — lần chạy một phần không được tính là bằng chứng closure.`);
  process.exit(1);
}
if ((assertions.total ?? 0) === 0) {
  console.error('Newman không chạy assertion nào.');
  process.exit(1);
}
console.log(`Newman gate PASS: ${executed}/${declared} request, ${assertions.total} assertion, 0 failure`);
NODE

# ---------------------------------------------------------------------------
# 6. Đóng gói + băm
# ---------------------------------------------------------------------------
cp "$COLLECTION" "$EVIDENCE_DIR/wwhn184.postman.collection.json"
cp "$ENVIRONMENT" "$EVIDENCE_DIR/wwhn184.postman.environment.json"
cp "$SCOPED_OPENAPI_JSON" "$EVIDENCE_DIR/wwhn184-openapi.json"
cp "$SCOPED_OPENAPI_YAML" "$EVIDENCE_DIR/wwhn184-openapi.yaml"
cp "$TASK_DOC" "$EVIDENCE_DIR/source/"
cp scripts/verify-wwhn184-postman-contract.mjs "$EVIDENCE_DIR/source/"
cp scripts/wwhn184-post-receipt-runtime-concurrency.php "$EVIDENCE_DIR/source/"
cp scripts/wwhn184-post-receipt-worker.php "$EVIDENCE_DIR/source/"
cp tests/Feature/Wwhn184InboundContractParityTest.php "$EVIDENCE_DIR/source/"
cp tests/Feature/Wwhn184PostReceiptRuntimeConcurrencyTest.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php "$EVIDENCE_DIR/source/"
cp Modules/Warehouse/routes/api.php "$EVIDENCE_DIR/source/warehouse-api-routes.php"

SOURCE_TREE_SHA256="$(git archive --format=tar HEAD | sha256sum | awk '{print $1}')"
printf '%s  git-archive-%s.tar\n' "$SOURCE_TREE_SHA256" "$COMMIT_SHA" > "$EVIDENCE_DIR/source-tree.sha256"
POSTMAN_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn184.postman.collection.json" | awk '{print $1}')"
OPENAPI_SHA256="$(sha256sum "$EVIDENCE_DIR/openapi.json" | awk '{print $1}')"
SCOPED_OPENAPI_SHA256="$(sha256sum "$EVIDENCE_DIR/wwhn184-openapi.json" | awk '{print $1}')"
NEWMAN_REQUEST_COUNT="$(node -e "const r=require('./$EVIDENCE_DIR/newman.result.json');console.log(r.run.stats.requests.total)")"
NEWMAN_ASSERTION_COUNT="$(node -e "const r=require('./$EVIDENCE_DIR/newman.result.json');console.log(r.run.stats.assertions.total)")"
CI_RUN_ID="${GITHUB_RUN_ID:-LOCAL}"
CI_RUN_URL="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE}/actions/runs/$CI_RUN_ID"
COMMIT_URL="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE}/commit/$COMMIT_SHA"

cat > "$EVIDENCE_DIR/build-metadata.json" <<JSON
{
  "jira_key": "WWHN-184",
  "parent_key": "WWHN-41",
  "register_ids": ["DEV184-P1-01R", "DEV184-P1-02R", "DEV184-P1-03R"],
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
  "runner_os": "${RUNNER_OS:-LOCAL}",
  "php_version": "$(php -r 'echo PHP_VERSION;')",
  "node_version": "$(node --version)",
  "database_connection": "${DB_CONNECTION:-unknown}",
  "credential_mode": "EPHEMERAL_PER_WORKFLOW_RUN",
  "credential_persisted": false,
  "health_probe_http_status": $HEALTH_HTTP_CODE,
  "health_probe_semantics": "HTTP_READINESS_SEPARATE_FROM_BUSINESS_HEALTH",
  "postman_collection_version": "2.0.0",
  "postman_request_count": $NEWMAN_REQUEST_COUNT,
  "newman_assertion_count": $NEWMAN_ASSERTION_COUNT,
  "newman_failure_count": 0,
  "postman_collection_sha256": "$POSTMAN_SHA256",
  "openapi_sha256": "$OPENAPI_SHA256",
  "scoped_openapi_sha256": "$SCOPED_OPENAPI_SHA256",
  "document_status": "PROPOSED",
  "approval_status": "PENDING_INDEPENDENT_SIGN_OFF",
  "deployment_sha": null
}
JSON

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
