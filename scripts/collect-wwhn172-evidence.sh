#!/usr/bin/env bash
set -euo pipefail

ENGINE="${DB_CONNECTION:-sqlite}"
EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/wwhn-172/$ENGINE}"
BASE_URL="${WWHN172_BASE_URL:-http://127.0.0.1:8000}"
TARGET_MIGRATION="2026_08_13_104828_relax_outbound_request_id_requirement"
mkdir -p "$EVIDENCE_DIR"

capture() {
  local output="$1"
  shift
  "$@" 2>&1 | tee "$output"
}

php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$driver = (string) config("database.default");
$database = (string) config("database.connections.$driver.database");
$ok = $app->environment() === "testing"
    && (($driver === "sqlite" && $database === "/tmp/wms-wwhn172.sqlite")
        || (in_array($driver, ["pgsql", "mysql"], true) && $database === "wms_wwhn172_ci"));
if (! $ok) { fwrite(STDERR, "REFUSED: dedicated WWHN-172 CI database required.\n"); exit(78); }
'

COMMIT_SHA="$(git rev-parse HEAD)"
SOURCE_TREE_SHA256="$(git archive --format=tar HEAD | sha256sum | awk '{print $1}')"
RUN_URL="${GITHUB_SERVER_URL:-https://github.com}/${GITHUB_REPOSITORY:-Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE}/actions/runs/${GITHUB_RUN_ID:-local}"

capture "$EVIDENCE_DIR/php-version.raw.log" php -v
capture "$EVIDENCE_DIR/laravel-version.raw.log" php artisan --version
capture "$EVIDENCE_DIR/composer-version.raw.log" composer --version
capture "$EVIDENCE_DIR/node-version.raw.log" node --version
capture "$EVIDENCE_DIR/npm-version.raw.log" npm --version
capture "$EVIDENCE_DIR/route-list.raw.log" php artisan route:list --path=api/v1

capture "$EVIDENCE_DIR/migration-fresh.raw.log" php artisan migrate:fresh --force
capture "$EVIDENCE_DIR/database-seed.raw.log" php artisan db:seed --force
capture "$EVIDENCE_DIR/migration-status-before-rollback.raw.log" php artisan migrate:status --no-ansi

TARGET_MIGRATION="$TARGET_MIGRATION" php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$target = getenv("TARGET_MIGRATION");
$latest = Illuminate\Support\Facades\DB::table("migrations")->orderByDesc("batch")->orderByDesc("migration")->value("migration");
if ($latest !== $target) { fwrite(STDERR, "Expected latest migration {$target}, got ".($latest ?: "<none>").".\n"); exit(78); }
'

capture "$EVIDENCE_DIR/migration-rollback.raw.log" php artisan migrate:rollback --step=1 --force
capture "$EVIDENCE_DIR/migration-status-after-rollback.raw.log" php artisan migrate:status --no-ansi
capture "$EVIDENCE_DIR/migration-pretend.raw.log" php artisan migrate --pretend --force
capture "$EVIDENCE_DIR/migration-reapply.raw.log" php artisan migrate --force
capture "$EVIDENCE_DIR/migration-status-after-reapply.raw.log" php artisan migrate:status --no-ansi

capture "$EVIDENCE_DIR/phpunit-doc-baseline.raw.log" \
  php artisan test --do-not-cache-result --filter=DocumentationBaselineContractTest \
  --log-junit "$EVIDENCE_DIR/phpunit-doc-baseline.junit.xml"
capture "$EVIDENCE_DIR/phpunit-swagger-erd.raw.log" \
  php artisan test --do-not-cache-result --filter='SwaggerRouteSyncTest|LatestErdBaselineSchemaTest' \
  --log-junit "$EVIDENCE_DIR/phpunit-swagger-erd.junit.xml"
capture "$EVIDENCE_DIR/phpunit-aggregate.raw.log" \
  php artisan test --do-not-cache-result --filter=Wwhn172SkuContainerTransactionTest \
  --log-junit "$EVIDENCE_DIR/phpunit-aggregate.junit.xml"
capture "$EVIDENCE_DIR/phpunit-audit.raw.log" \
  php artisan test --do-not-cache-result --filter=Wwhn172AuditFailClosedTest \
  --log-junit "$EVIDENCE_DIR/phpunit-audit.junit.xml"
capture "$EVIDENCE_DIR/phpunit-migration.raw.log" \
  php artisan test --do-not-cache-result --filter=Wwhn172MigrationSafetyTest \
  --log-junit "$EVIDENCE_DIR/phpunit-migration.junit.xml"
capture "$EVIDENCE_DIR/phpunit-import.raw.log" \
  php artisan test --do-not-cache-result --filter=Wwhn174SkuImportValidationTest \
  --log-junit "$EVIDENCE_DIR/phpunit-import.junit.xml"
capture "$EVIDENCE_DIR/phpunit-full.raw.log" \
  php artisan test --do-not-cache-result --log-junit "$EVIDENCE_DIR/phpunit-full.junit.xml"

capture "$EVIDENCE_DIR/openapi-generate.raw.log" php artisan l5-swagger:generate
cp storage/api-docs/api-docs.json "$EVIDENCE_DIR/openapi.generated.json"
cp docs/contracts/HoaNam_Current_Baseline_Contract.json "$EVIDENCE_DIR/hoanam.current-baseline.contract.json"
cp docs/api-contract/WWHN-172-sku-container-openapi.yaml "$EVIDENCE_DIR/wwhn172.openapi.yaml"
cp "docs/postman/WWHN-172 SKU Container Contract.postman_collection.json" "$EVIDENCE_DIR/wwhn172.postman.collection.json"
cp "docs/postman/WWHN-172 SKU Container CI.postman_environment.json" "$EVIDENCE_DIR/wwhn172.postman.environment.json"
cp docs/testing/WWHN-172-test-cases.json "$EVIDENCE_DIR/wwhn172.test-cases.json"
cp docs/docs_task_jira/WWHN-172-source-register.json "$EVIDENCE_DIR/wwhn172.source-register.json"

capture "$EVIDENCE_DIR/migration-runtime.raw.log" php artisan migrate:fresh --force
capture "$EVIDENCE_DIR/database-seed-runtime.raw.log" php artisan db:seed --force
EVIDENCE_DIR="$EVIDENCE_DIR" php scripts/prepare-wwhn172-evidence.php

DB_SERVER_VERSION="$(php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$driver = Illuminate\Support\Facades\DB::getDriverName();
$sql = $driver === "sqlite" ? "select sqlite_version() as version" : "select version() as version";
$row = Illuminate\Support\Facades\DB::selectOne($sql);
echo trim((string) ($row->version ?? "unknown"));
')"
printf '%s\n' "$DB_SERVER_VERSION" > "$EVIDENCE_DIR/database-version.raw.log"

WWHN172_TOKEN="$(php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = Modules\Auth\Models\User::query()->where("email", "test-super-admin@hoanam.com")->firstOrFail();
echo Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);
')"
echo "::add-mask::$WWHN172_TOKEN"

COMPONENT_ID="$(node -e 'const fs=require("fs");const j=JSON.parse(fs.readFileSync(process.argv[1],"utf8"));process.stdout.write(j.component_id)' "$EVIDENCE_DIR/fixture-metadata.json")"
PRODUCT_ID="$(node -e 'const fs=require("fs");const j=JSON.parse(fs.readFileSync(process.argv[1],"utf8"));process.stdout.write(j.product_id)' "$EVIDENCE_DIR/fixture-metadata.json")"
SERIAL_ID="$(node -e 'const fs=require("fs");const j=JSON.parse(fs.readFileSync(process.argv[1],"utf8"));process.stdout.write(j.serial_id)' "$EVIDENCE_DIR/fixture-metadata.json")"
SERIAL_PRODUCT_ID="$(node -e 'const fs=require("fs");const j=JSON.parse(fs.readFileSync(process.argv[1],"utf8"));process.stdout.write(j.serial_product_id)' "$EVIDENCE_DIR/fixture-metadata.json")"

SERVER_LOG="$EVIDENCE_DIR/laravel-server.raw.log"
PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8000 > "$SERVER_LOG" 2>&1 &
SERVER_PID=$!
cleanup_server() { kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; }
trap cleanup_server EXIT

ready=false
for _ in $(seq 1 60); do
  if curl --silent --show-error --fail "$BASE_URL/api/v1/health" > "$EVIDENCE_DIR/health-response.json"; then ready=true; break; fi
  sleep 1
done
if [[ "$ready" != true ]]; then echo 'Laravel API did not become ready.' >&2; exit 1; fi

npx newman run "docs/postman/WWHN-172 SKU Container Contract.postman_collection.json" \
  -e "docs/postman/WWHN-172 SKU Container CI.postman_environment.json" \
  --env-var "base_url=$BASE_URL" --env-var "token=$WWHN172_TOKEN" \
  --env-var "component_id=$COMPONENT_ID" --env-var "product_id=$PRODUCT_ID" \
  --reporters cli,junit --reporter-junit-export "$EVIDENCE_DIR/newman.junit.xml" \
  2>&1 | tee "$EVIDENCE_DIR/newman.raw.log"

if [[ "$ENGINE" == 'pgsql' || "$ENGINE" == 'mysql' ]]; then
  WWHN172_BASE_URL="$BASE_URL" WWHN172_TOKEN="$WWHN172_TOKEN" \
  WWHN172_SERIAL_ID="$SERIAL_ID" WWHN172_SERIAL_PRODUCT_ID="$SERIAL_PRODUCT_ID" \
  node scripts/run-wwhn172-concurrency.mjs "$EVIDENCE_DIR/concurrency-report.json" \
  2>&1 | tee "$EVIDENCE_DIR/concurrency.raw.log"
else
  printf '%s\n' '{"status":"SKIPPED","reason":"Concurrency gate runs on PostgreSQL/MySQL."}' > "$EVIDENCE_DIR/concurrency-report.json"
  printf '%s\n' 'SKIPPED: concurrency gate runs on PostgreSQL/MySQL.' > "$EVIDENCE_DIR/concurrency.raw.log"
fi

cleanup_server
trap - EXIT

php scripts/collect-wwhn172-db-snapshot.php before-service "$EVIDENCE_DIR/db-before.json"
php scripts/collect-wwhn172-service-proof.php "$EVIDENCE_DIR/service-proof.json"
php scripts/collect-wwhn172-db-snapshot.php after-service "$EVIDENCE_DIR/db-after.json"

cat > "$EVIDENCE_DIR/build-metadata.json" <<JSON
{
  "schema_version": "3.0.0",
  "jira_key": "WWHN-172",
  "branch": "m1",
  "commit_sha": "$COMMIT_SHA",
  "source_tree_sha256": "$SOURCE_TREE_SHA256",
  "ci_run_url": "$RUN_URL",
  "database_driver": "$ENGINE",
  "database_server_version": "$DB_SERVER_VERSION",
  "baseline_contract": "docs/contracts/HoaNam_Current_Baseline_Contract.json"
}
JSON

cat > "$EVIDENCE_DIR/evidence-index.json" <<JSON
{
  "schema_version": "3.0.0",
  "task": "WWHN-172",
  "exact_commit_sha": "$COMMIT_SHA",
  "database_driver": "$ENGINE",
  "ci_run_url": "$RUN_URL",
  "gates": ["documentation-baseline", "swagger-route-parity", "latest-erd", "full-phpunit", "newman", "concurrency", "db-before-after", "service-proof"]
}
JSON

(
  cd "$EVIDENCE_DIR"
  find . -type f ! -name SHA256SUMS ! -name SHA256SUMS.sha256 -printf '%P\n' | LC_ALL=C sort | xargs -r sha256sum > SHA256SUMS
  sha256sum SHA256SUMS > SHA256SUMS.sha256
  sha256sum --check SHA256SUMS
  sha256sum --check SHA256SUMS.sha256
)

if grep -RIE --binary-files=without-match '(JWT_SECRET=|CI_DEMO_PASSWORD=|WWHN172_PASSWORD=|Bearer [A-Za-z0-9._-]{20,})' "$EVIDENCE_DIR"; then
  echo 'Credential leak pattern detected in evidence.' >&2
  exit 1
fi
if [[ -n "${WWHN172_PASSWORD:-}" ]] && grep -RIF --binary-files=without-match "$WWHN172_PASSWORD" "$EVIDENCE_DIR" >/dev/null; then
  echo 'Temporary CI password leaked into evidence.' >&2
  exit 1
fi
if grep -RIF --binary-files=without-match "$WWHN172_TOKEN" "$EVIDENCE_DIR" >/dev/null; then
  echo 'JWT leaked into evidence.' >&2
  exit 1
fi

printf 'WWHN-172 evidence bundle completed: %s / %s\n' "$ENGINE" "$COMMIT_SHA"
