#!/usr/bin/env bash
set -euo pipefail

ENGINE="${DB_CONNECTION:-sqlite}"
EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/wwhn-172/$ENGINE}"
mkdir -p "$EVIDENCE_DIR"

php -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$driver=(string) config("database.default");
$db=(string) config("database.connections.$driver.database");
$ok=$app->environment()==="testing" && (($driver==="sqlite" && $db==="/tmp/wms-wwhn172.sqlite") || (in_array($driver,["pgsql","mysql"],true) && $db==="wms_wwhn172_ci"));
if(!$ok){fwrite(STDERR,"REFUSED: dedicated CI database required.\n");exit(78);}
'

capture(){ out="$1"; shift; "$@" 2>&1 | tee "$out"; }
COMMIT_SHA="$(git rev-parse HEAD)"
RUN_URL="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE}/actions/runs/${GITHUB_RUN_ID:-local}"

capture "$EVIDENCE_DIR/migration-pretend.raw.log" php artisan migrate --pretend --force
capture "$EVIDENCE_DIR/migration-fresh.raw.log" php artisan migrate:fresh --force
capture "$EVIDENCE_DIR/database-seed.raw.log" php artisan db:seed --force
capture "$EVIDENCE_DIR/migration-preflight.raw.log" php artisan wwhn172:migration-preflight --output="$EVIDENCE_DIR/migration-preflight.json"
capture "$EVIDENCE_DIR/migration-rollback.raw.log" php artisan migrate:rollback --step=1 --force
capture "$EVIDENCE_DIR/migration-reapply.raw.log" php artisan migrate --force
capture "$EVIDENCE_DIR/migration-postcheck.raw.log" php artisan wwhn172:migration-preflight --output="$EVIDENCE_DIR/migration-postcheck.json"

capture "$EVIDENCE_DIR/phpunit-aggregate.raw.log" php artisan test --do-not-cache-result --filter=Wwhn172SkuContainerTransactionTest
capture "$EVIDENCE_DIR/phpunit-audit.raw.log" php artisan test --do-not-cache-result --filter=Wwhn172AuditFailClosedTest
capture "$EVIDENCE_DIR/phpunit-migration.raw.log" php artisan test --do-not-cache-result --filter=Wwhn172MigrationSafetyTest
capture "$EVIDENCE_DIR/phpunit-import.raw.log" php artisan test --do-not-cache-result --filter=Wwhn174SkuImportValidationTest
capture "$EVIDENCE_DIR/phpunit-full.raw.log" php artisan test --do-not-cache-result --log-junit "$EVIDENCE_DIR/phpunit-full.junit.xml"

capture "$EVIDENCE_DIR/openapi-generate.raw.log" php artisan l5-swagger:generate
cp storage/api-docs/api-docs.json "$EVIDENCE_DIR/openapi.generated.json"
cp docs/api-contract/WWHN-172-sku-container-openapi.yaml "$EVIDENCE_DIR/wwhn172.openapi.yaml"
cp "docs/postman/WWHN-172 SKU Container Contract.postman_collection.json" "$EVIDENCE_DIR/wwhn172.postman.collection.json"
cp docs/testing/WWHN-172-test-cases.json "$EVIDENCE_DIR/wwhn172.test-cases.json"
cp docs/docs_task_jira/WWHN-172.md "$EVIDENCE_DIR/WWHN-172.md"

capture "$EVIDENCE_DIR/migration-runtime.raw.log" php artisan migrate:fresh --force
capture "$EVIDENCE_DIR/database-seed-runtime.raw.log" php artisan db:seed --force
EVIDENCE_DIR="$EVIDENCE_DIR" php scripts/prepare-wwhn172-evidence.php
php scripts/collect-wwhn172-db-snapshot.php before-service "$EVIDENCE_DIR/db-before.json"
php scripts/collect-wwhn172-service-proof.php "$EVIDENCE_DIR/service-proof.json"
php scripts/collect-wwhn172-db-snapshot.php after-service "$EVIDENCE_DIR/db-after.json"

SOURCE_TREE_SHA256="$(git archive --format=tar HEAD | sha256sum | awk '{print $1}')"
cat > "$EVIDENCE_DIR/build-metadata.json" <<JSON
{
  "schema_version":"1.0.0",
  "jira_key":"WWHN-172",
  "branch":"m1",
  "commit_sha":"$COMMIT_SHA",
  "source_tree_sha256":"$SOURCE_TREE_SHA256",
  "ci_run_url":"$RUN_URL",
  "database_driver":"$ENGINE",
  "php_version":"$(php -r 'echo PHP_VERSION;')",
  "contract_version":"1.1.0",
  "approval_status":"PENDING_INDEPENDENT_QA1_QA2_BE2_DBA_DEVOPS_SIGNOFF"
}
JSON

cat > "$EVIDENCE_DIR/jira-handoff-comment.txt" <<TEXT
[WWHN-172 BE1 STRUCTURED HANDOFF]
Branch: m1
Exact SHA: $COMMIT_SHA
Engine: $ENGINE
CI run: $RUN_URL
Artifact: wwhn-172-evidence-$ENGINE-$COMMIT_SHA
Source tree SHA-256: $SOURCE_TREE_SHA256
Evidence: full/focused PHPUnit raw logs, DB matrix, migration pretend/rollback/reapply, DB before-after, audit fail-closed, OpenAPI/Postman/test-case copies and SHA256SUMS.
Independent review required: WWHN-171 BE2, WWHN-173 QA1, WWHN-174 QA2, Tech Lead, DBA/DevOps.
TEXT

(
 cd "$EVIDENCE_DIR"
 find . -type f ! -name SHA256SUMS ! -name SHA256SUMS.sha256 -printf '%P\n' | LC_ALL=C sort | xargs -r sha256sum > SHA256SUMS
 sha256sum SHA256SUMS > SHA256SUMS.sha256
 sha256sum --check SHA256SUMS
)

if grep -RIE --binary-files=without-match '(JWT_SECRET=|CI_DEMO_PASSWORD=|WWHN172_PASSWORD=|Bearer [A-Za-z0-9._-]{20,})' "$EVIDENCE_DIR"; then
  echo 'Credential leak detected in evidence.' >&2
  exit 1
fi
