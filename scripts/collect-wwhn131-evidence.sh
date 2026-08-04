#!/usr/bin/env bash
set -euo pipefail

EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/wwhn-131}"
SOURCE_DIR="$EVIDENCE_DIR/source"
WWHN131_EMAIL="${WWHN131_EMAIL:-manager01@gmail.com}"
WWHN131_PASSWORD="${WWHN131_PASSWORD:-}"
SERVER_PID=""
EVIDENCE_REDACTED="false"

cleanup_server() {
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
    SERVER_PID=""
  fi
}

redact_diagnostics() {
  if [[ ! -d "$EVIDENCE_DIR" || "$EVIDENCE_REDACTED" == "true" ]]; then
    return 0
  fi

  if node scripts/redact-wwhn131-evidence.mjs \
    "$EVIDENCE_DIR" \
    "$EVIDENCE_DIR/credential-hygiene.json"; then
    EVIDENCE_REDACTED="true"
    return 0
  fi

  # A diagnostics package is never more important than credential safety.
  rm -rf "$EVIDENCE_DIR"
  mkdir -p "$EVIDENCE_DIR"
  cat > "$EVIDENCE_DIR/DIAGNOSTICS_REDACTION_FAILED.txt" <<'TEXT'
WWHN-131 diagnostics were discarded because credential redaction could not be verified.
Re-run the exact-SHA workflow after correcting the redaction gate.
TEXT
  return 1
}

finish() {
  local status=$?
  trap - EXIT
  cleanup_server

  if [[ $status -ne 0 ]]; then
    redact_diagnostics || true
  fi

  exit "$status"
}
trap finish EXIT

if [[ -z "$WWHN131_PASSWORD" || -z "${JWT_SECRET:-}" || -z "${CI_DEMO_PASSWORD:-}" ]]; then
  echo 'REFUSED: WWHN-131 collector requires ephemeral WWHN131_PASSWORD, CI_DEMO_PASSWORD and JWT_SECRET.' >&2
  exit 78
fi

# Collector runs migrate:fresh twice. It must only target the dedicated CI SQLite DB.
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$driver = (string) config("database.default");
$database = (string) config("database.connections.$driver.database");
if ($app->environment() !== "testing" || $driver !== "sqlite" || $database !== "/tmp/wms-wwhn131.sqlite") {
    fwrite(STDERR, "REFUSED: WWHN-131 collector requires APP_ENV=testing and SQLite /tmp/wms-wwhn131.sqlite.\n");
    exit(78);
}
'

rm -rf "$EVIDENCE_DIR"
mkdir -p "$SOURCE_DIR"

COMMIT_SHA="$(git rev-parse HEAD)"
BUILD_TIME="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
CI_RUN_URL="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE}/actions/runs/${GITHUB_RUN_ID:-local}"
COLLECTION="docs/postman/WWHN-131 Stocktake Contract.postman_collection.json"
ENVIRONMENT="docs/postman/WWHN-131 Stocktake CI.postman_environment.json"
TEST_CASES="docs/testing/WWHN-131-stocktake-test-cases.json"
SOURCE_REGISTER="docs/docs_task_jira/WWHN-131-source-register.json"
DOCUMENT="docs/docs_task_jira/WWHN-131.md"
REQUESTED_LOG="docs/docs_task_jira/WWHN-130.md"
DECISION_RECORD="docs/decision-records/DEC-STK-001.md"
REVIEW_PROTOCOL="docs/evidence/WWHN-131/INDEPENDENT_REVIEW_AND_DECISION_PROTOCOL.md"
MIGRATION="Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php"
IDEMPOTENCY_MIGRATION="database/migrations/2026_08_03_000200_allow_completed_idempotency_lease_release.php"

run_and_capture() {
  local output="$1"
  shift
  "$@" 2>&1 | tee "$output"
}

php artisan route:list --path=api/v1 -vv --json > "$EVIDENCE_DIR/route-list.raw.json"
run_and_capture "$EVIDENCE_DIR/migration-rehearsal.raw.log" php artisan migrate:fresh --force
run_and_capture "$EVIDENCE_DIR/database-seed-rehearsal.raw.log" php artisan db:seed --force
run_and_capture "$EVIDENCE_DIR/schema-verification.raw.log" php scripts/verify-wwhn131-schema.php "$EVIDENCE_DIR/schema-verification.json"

run_and_capture "$EVIDENCE_DIR/phpunit-stocktake-three-layer.raw.log" \
  php artisan test --do-not-cache-result --filter=StocktakeThreeLayerContractTest
run_and_capture "$EVIDENCE_DIR/phpunit-stocktake-ledger.raw.log" \
  php artisan test --do-not-cache-result --filter=StocktakeApplyLedgerIntegrityTest
run_and_capture "$EVIDENCE_DIR/phpunit-stocktake-review-gate.raw.log" \
  php artisan test --do-not-cache-result --filter=StocktakeReviewGateTest
run_and_capture "$EVIDENCE_DIR/phpunit-full.raw.log" php artisan test --do-not-cache-result

run_and_capture "$EVIDENCE_DIR/openapi-generate.raw.log" php artisan l5-swagger:generate
cp storage/api-docs/api-docs.json "$EVIDENCE_DIR/openapi.json"
if [[ -f storage/api-docs/api-docs.yaml ]]; then
  cp storage/api-docs/api-docs.yaml "$EVIDENCE_DIR/openapi.yaml"
else
  php -r 'require "vendor/autoload.php"; $json=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); file_put_contents($argv[2], Symfony\Component\Yaml\Yaml::dump($json, 20, 2, Symfony\Component\Yaml\Yaml::DUMP_OBJECT_AS_MAP));' \
    "$EVIDENCE_DIR/openapi.json" "$EVIDENCE_DIR/openapi.yaml"
fi

cp "$COLLECTION" "$EVIDENCE_DIR/stocktake.postman.collection.json"
cp "$ENVIRONMENT" "$EVIDENCE_DIR/stocktake.postman.environment.json"
cp "$TEST_CASES" "$EVIDENCE_DIR/stocktake-test-cases.json"
cp "$SOURCE_REGISTER" "$EVIDENCE_DIR/stocktake-source-register.json"
cp "$DOCUMENT" "$EVIDENCE_DIR/WWHN-131.md"
cp "$REQUESTED_LOG" "$EVIDENCE_DIR/WWHN-130.md"
cp "$DECISION_RECORD" "$EVIDENCE_DIR/DEC-STK-001.md"
cp "$REVIEW_PROTOCOL" "$EVIDENCE_DIR/independent-review-and-decision-protocol.md"

for source in \
  "$COLLECTION" "$ENVIRONMENT" "$TEST_CASES" "$SOURCE_REGISTER" "$DOCUMENT" \
  "$REQUESTED_LOG" "$DECISION_RECORD" "$REVIEW_PROTOCOL" "$MIGRATION" "$IDEMPOTENCY_MIGRATION" \
  Modules/Warehouse/routes/api.php \
  Modules/Warehouse/app/Http/Controllers/StocktakeController.php \
  Modules/Warehouse/app/Services/StocktakeAdjustmentService.php \
  Modules/Warehouse/app/Services/StocktakeService.php \
  Modules/Warehouse/app/Services/StocktakeSessionGuard.php \
  Modules/Warehouse/app/Services/StocktakeGrainService.php \
  tests/Feature/StocktakeThreeLayerContractTest.php \
  tests/Feature/StocktakeApplyLedgerIntegrityTest.php \
  tests/Feature/StocktakeReviewGateTest.php \
  scripts/verify-stocktake-contract.mjs \
  scripts/verify-wwhn131-schema.php \
  scripts/verify-wwhn131-evidence-pipeline.mjs \
  scripts/redact-wwhn131-evidence.mjs \
  scripts/collect-wwhn131-evidence.sh; do
  cp "$source" "$SOURCE_DIR/$(basename "$source")"
done

node scripts/verify-stocktake-contract.mjs \
  "$EVIDENCE_DIR/openapi.json" \
  "$EVIDENCE_DIR/route-list.raw.json" \
  "$EVIDENCE_DIR/stocktake.postman.collection.json" \
  "$EVIDENCE_DIR/stocktake-test-cases.json" \
  "$EVIDENCE_DIR/stocktake-source-register.json" \
  "$EVIDENCE_DIR/stocktake-contract-verification.json"

node scripts/verify-wwhn131-evidence-pipeline.mjs \
  "$EVIDENCE_DIR/evidence-pipeline-verification.json"

# Refresh canonical runtime DB because RefreshDatabase tests reset it.
run_and_capture "$EVIDENCE_DIR/migration-runtime.raw.log" php artisan migrate:fresh --force
run_and_capture "$EVIDENCE_DIR/database-seed-runtime.raw.log" php artisan db:seed --force
php artisan evidence:stock-snapshot \
  --label=before-wwhn131-newman \
  --output="$EVIDENCE_DIR/db-before.json"

php artisan serve --host=127.0.0.1 --port=8000 > "$EVIDENCE_DIR/server.raw.log" 2>&1 &
SERVER_PID=$!

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

npx newman run "$EVIDENCE_DIR/stocktake.postman.collection.json" \
  -e "$EVIDENCE_DIR/stocktake.postman.environment.json" \
  --env-var "email=$WWHN131_EMAIL" \
  --env-var "password=$WWHN131_PASSWORD" \
  --reporters cli,json \
  --reporter-json-export "$EVIDENCE_DIR/stocktake-newman.result.json" \
  2>&1 | tee "$EVIDENCE_DIR/stocktake-newman.raw.log"

php artisan evidence:stock-snapshot \
  --label=after-wwhn131-newman \
  --output="$EVIDENCE_DIR/db-after.json"
node scripts/verify-inventory-reconciliation.mjs \
  "$EVIDENCE_DIR/db-after.json" \
  "$EVIDENCE_DIR/reconciliation-report.json"

cleanup_server

SOURCE_TREE_SHA256="$(git archive --format=tar HEAD | sha256sum | awk '{print $1}')"
printf '%s  git-archive-%s.tar\n' "$SOURCE_TREE_SHA256" "$COMMIT_SHA" > "$EVIDENCE_DIR/source-tree.sha256"

hash_file() {
  sha256sum "$1" | awk '{print $1}'
}

cat > "$EVIDENCE_DIR/build-metadata.json" <<JSON
{
  "schema_version": "1.1.0",
  "jira_key": "WWHN-131",
  "parent_key": "WWHN-25",
  "decision_id": "DEC-STK-001",
  "branch": "m1",
  "commit_sha": "$COMMIT_SHA",
  "source_tree_sha256": "$SOURCE_TREE_SHA256",
  "build_time_utc": "$BUILD_TIME",
  "ci_run_url": "$CI_RUN_URL",
  "artifact_name": "stocktake-evidence-$COMMIT_SHA",
  "php_version": "$(php -r 'echo PHP_VERSION;')",
  "node_version": "$(node --version)",
  "database_driver": "${DB_CONNECTION:-unknown}",
  "credential_mode": "EPHEMERAL_PER_WORKFLOW_RUN",
  "document_version": "1.3.0",
  "document_status": "PROPOSED",
  "decision_record_version": "1.0.0",
  "decision_record_status": "PROPOSED",
  "source_register_version": "1.3.0",
  "source_register_status": "PROPOSED",
  "postman_version": "1.0.0",
  "test_case_version": "1.2.0",
  "contract_verifier_version": "1.2.0",
  "schema_verifier_version": "1.0.0",
  "pipeline_verifier_version": "1.0.0",
  "openapi_sha256": "$(hash_file "$EVIDENCE_DIR/openapi.json")",
  "postman_sha256": "$(hash_file "$EVIDENCE_DIR/stocktake.postman.collection.json")",
  "test_case_sha256": "$(hash_file "$EVIDENCE_DIR/stocktake-test-cases.json")",
  "source_register_sha256": "$(hash_file "$EVIDENCE_DIR/stocktake-source-register.json")",
  "decision_record_sha256": "$(hash_file "$EVIDENCE_DIR/DEC-STK-001.md")",
  "migration_sha256": "$(hash_file "$MIGRATION")",
  "idempotency_migration_sha256": "$(hash_file "$IDEMPOTENCY_MIGRATION")",
  "workflow_sha256": "$(hash_file .github/workflows/wwhn-131-stocktake-contract.yml)",
  "collector_sha256": "$(hash_file scripts/collect-wwhn131-evidence.sh)",
  "redactor_sha256": "$(hash_file scripts/redact-wwhn131-evidence.mjs)",
  "contract_verifier_sha256": "$(hash_file scripts/verify-stocktake-contract.mjs)",
  "schema_verifier_sha256": "$(hash_file scripts/verify-wwhn131-schema.php)",
  "pipeline_verifier_sha256": "$(hash_file scripts/verify-wwhn131-evidence-pipeline.mjs)",
  "approval_status": "PENDING_INDEPENDENT_REVIEW_AND_FORMAL_DECISION",
  "deployment_sha": null
}
JSON

cat > "$EVIDENCE_DIR/jira-handoff-comment.txt" <<TEXT
[WWHN-131 BE1 STRUCTURED HANDOFF]
Decision: docs/decision-records/DEC-STK-001.md v1.0.0 / PROPOSED
Task document: docs/docs_task_jira/WWHN-131.md v1.3.0 / PROPOSED
Requested log: docs/docs_task_jira/WWHN-130.md (traceability guard only; authoritative BE task remains WWHN-131)
Source Register: docs/docs_task_jira/WWHN-131-source-register.json v1.3.0 / PROPOSED
Branch: m1
Exact SHA: $COMMIT_SHA
CI run: $CI_RUN_URL
Artifact: stocktake-evidence-$COMMIT_SHA
Source-tree SHA-256: $SOURCE_TREE_SHA256
Manifest: SHA256SUMS
Manifest digest: see SHA256SUMS.sha256
Credential policy: EPHEMERAL_PER_WORKFLOW_RUN; evidence redacted before hashing/upload

Repository gates packaged:
- OpenAPI/Postman/runtime routes and hashes
- focused/full PHPUnit raw logs
- schema verifier and migration rehearsal
- idempotency/race/rollback/ledger/reconcile/data-scope negative coverage
- credential-hygiene verification
- DB before/after and reconciliation report
- exact-SHA source copies and decision/review protocol

Independent gates still required:
- QA1 + Tech Lead exact-SHA retest
- DBA target PostgreSQL rehearsal
- Security cross-session/cross-warehouse/replay-leak retest
- BAS BRD/SRS/BPD/DEC-INV attachment IDs and SHA-256
- BA/PM/Tech Lead formal DEC-STK-001 result on WWHN-25/WWHN-132

Impacted Jira: WWHN-25, WWHN-131, WWHN-132, WWHN-133; dependent WWHN-58/WWHN-230 remain governed by decision status.
TEXT

cat > "$EVIDENCE_DIR/ci-summary.md" <<TEXT
# WWHN-131 exact-SHA evidence

- Commit: \`$COMMIT_SHA\`
- Artifact: \`stocktake-evidence-$COMMIT_SHA\`
- Decision: \`DEC-STK-001 v1.0.0 / PROPOSED\`
- Task document: \`WWHN-131 v1.3.0 / PROPOSED\`
- Credential mode: \`EPHEMERAL_PER_WORKFLOW_RUN\`
- Source tree SHA-256: \`$SOURCE_TREE_SHA256\`
- Formal approval: **PENDING independent review and Jira decision**

The workflow may establish \`CI_VERIFIED\`; it must not self-assign \`APPROVED\` or Jira Done.
TEXT

redact_diagnostics

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

cat "$EVIDENCE_DIR/build-metadata.json"
cat "$EVIDENCE_DIR/credential-hygiene.json"
cat "$EVIDENCE_DIR/SHA256SUMS.sha256"
