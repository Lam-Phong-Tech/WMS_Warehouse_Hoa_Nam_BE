#!/usr/bin/env bash
set -Eeuo pipefail

EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/wwhn-155/postgresql}"
BASE_URL="${BASE_URL:-http://127.0.0.1:18080}"
mkdir -p "$EVIDENCE_DIR"

server_pid=""
cleanup() {
  if [[ -n "$server_pid" ]] && kill -0 "$server_pid" 2>/dev/null; then
    kill "$server_pid" 2>/dev/null || true
    wait "$server_pid" 2>/dev/null || true
  fi
}
trap cleanup EXIT

log_command() {
  local name="$1"
  shift
  {
    printf '$'
    printf ' %q' "$@"
    printf '\n'
    "$@"
    local exit_code=$?
    printf '\n[exit_code]=%s\n' "$exit_code"
    return "$exit_code"
  } 2>&1 | tee "$EVIDENCE_DIR/$name.raw.log"
}

{
  echo "jira=WWHN-155"
  echo "contract_id=WWHN-156-health-v1"
  echo "contract_version=1.0.0"
  echo "commit_sha=${GITHUB_SHA:-$(git rev-parse HEAD)}"
  echo "source_tree=$(git rev-parse HEAD^{tree})"
  echo "ref=${GITHUB_REF:-local}"
  echo "run_id=${GITHUB_RUN_ID:-local}"
  echo "run_attempt=${GITHUB_RUN_ATTEMPT:-local}"
  echo "started_at=$(date --iso-8601=seconds)"
  php -v | head -n 1
  php artisan --version
  composer --version
  php artisan tinker --execute="echo 'db_driver=' . \\Illuminate\\Support\\Facades\\DB::connection()->getDriverName() . PHP_EOL; echo 'db_version=' . \\Illuminate\\Support\\Facades\\DB::selectOne('select version() as version')->version . PHP_EOL;"
} 2>&1 | tee "$EVIDENCE_DIR/execution-metadata.raw.log"

log_command route-list php artisan route:list --path=api/v1/health --json
log_command focused-phpunit php artisan test --filter BackendMigrationSeedHealthTest
log_command focused-phpunit-junit php artisan test --filter BackendMigrationSeedHealthTest --log-junit "$EVIDENCE_DIR/phpunit-junit.xml"

# Build a migrated but intentionally unseeded state to produce a real sanitized 503 sample.
log_command migrate-fresh-unseeded php artisan migrate:fresh
php artisan serve --host=127.0.0.1 --port=18080 >"$EVIDENCE_DIR/server.raw.log" 2>&1 &
server_pid=$!
for _ in $(seq 1 40); do
  if curl -fsS "$BASE_URL/up" >/dev/null 2>&1; then break; fi
  sleep 1
done
kill -0 "$server_pid"

status_503=$(curl -sS -D "$EVIDENCE_DIR/health-503.headers.txt" -o "$EVIDENCE_DIR/health-503.body.json" -w '%{http_code}' -H 'Accept: application/json' -H 'X-Request-ID: WWHN-155-CI-503' "$BASE_URL/api/v1/health")
test "$status_503" = "503"
jq -e '.success == false and .error_code == "HEALTH_CHECK_DEGRADED" and .errors.status == "degraded" and .errors.request_id == "WWHN-155-CI-503" and .meta.request_id == "WWHN-155-CI-503"' "$EVIDENCE_DIR/health-503.body.json" >/dev/null
if grep -Eiq 'password|secret|token|dsn|sqlstate|pdoexception|stack trace|vendor/|connection refused|db_host|db_password' "$EVIDENCE_DIR/health-503.body.json"; then
  echo 'Sensitive diagnostic fragment found in 503 body' >&2
  exit 1
fi

grep -Eiq '^cache-control:.*no-store' "$EVIDENCE_DIR/health-503.headers.txt"
grep -Eiq '^x-request-id: WWHN-155-CI-503' "$EVIDENCE_DIR/health-503.headers.txt"

# Required exact migration/seed command and raw output.
log_command migrate-fresh-seed php artisan migrate:fresh --seed

status_200=$(curl -sS -D "$EVIDENCE_DIR/health-200.headers.txt" -o "$EVIDENCE_DIR/health-200.body.json" -w '%{http_code}' -H 'Accept: application/json' -H 'X-Request-ID: WWHN-155-CI-200' "$BASE_URL/api/v1/health")
test "$status_200" = "200"
jq -e '.success == true and .data.status == "ok" and .data.version == "1.0.0" and .data.request_id == "WWHN-155-CI-200" and .meta.request_id == "WWHN-155-CI-200" and .data.checks.database.status == "ok" and .data.checks.migrations.status == "ok" and .data.checks.seed.status == "ok" and .data.checks.seed.markers.admin_user == true and .data.checks.seed.markers.baseline_warehouse == true and .data.checks.seed.markers.baseline_product == true' "$EVIDENCE_DIR/health-200.body.json" >/dev/null

grep -Eiq '^cache-control:.*no-store' "$EVIDENCE_DIR/health-200.headers.txt"
grep -Eiq '^x-request-id: WWHN-155-CI-200' "$EVIDENCE_DIR/health-200.headers.txt"

legacy_status=$(curl -sS -o "$EVIDENCE_DIR/legacy-health.body.json" -w '%{http_code}' -H 'Accept: application/json' "$BASE_URL/api/health")
test "$legacy_status" = "404"

php artisan tinker --execute="echo json_encode([
  'admin_user' => ['count' => \\Illuminate\\Support\\Facades\\DB::table('app_user')->where('username', 'admin')->count(), 'row' => \\Illuminate\\Support\\Facades\\DB::table('app_user')->where('username', 'admin')->select('username', 'status')->first()],
  'baseline_warehouse' => ['count' => \\Illuminate\\Support\\Facades\\DB::table('warehouses')->where('code', 'KHO-TONG-HN')->count(), 'row' => \\Illuminate\\Support\\Facades\\DB::table('warehouses')->where('code', 'KHO-TONG-HN')->select('code', 'status')->first()],
  'baseline_product' => ['count' => \\Illuminate\\Support\\Facades\\DB::table('products')->where('code', 'HN-PV-BASE-001')->count(), 'row' => \\Illuminate\\Support\\Facades\\DB::table('products')->where('code', 'HN-PV-BASE-001')->select('code', 'published_to_app')->first()]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;" 2>&1 | tee "$EVIDENCE_DIR/db-seed-markers.raw.log"

test "$(grep -c '"count": 1' "$EVIDENCE_DIR/db-seed-markers.raw.log")" -eq 3

cp docs/api-contract/releases/WWHN-155/1.0.0/SHA256SUMS "$EVIDENCE_DIR/release-SHA256SUMS"
(
  cd docs/api-contract/releases/WWHN-155/1.0.0
  sha256sum --check SHA256SUMS
) 2>&1 | tee "$EVIDENCE_DIR/release-sha256-check.raw.log"

cat > "$EVIDENCE_DIR/RESULT.json" <<JSON
{
  "jira": "WWHN-155",
  "status": "PASS",
  "commit_sha": "${GITHUB_SHA:-$(git rev-parse HEAD)}",
  "database": "postgresql",
  "focused_test": "PASS",
  "migrate_fresh_seed": "PASS",
  "health_503": "PASS",
  "health_200": "PASS",
  "legacy_404": "PASS",
  "seed_markers": "PASS",
  "release_hashes": "PASS",
  "finished_at": "$(date --iso-8601=seconds)"
}
JSON

cleanup
server_pid=""
(
  cd "$EVIDENCE_DIR"
  find . -maxdepth 1 -type f ! -name 'SHA256SUMS' ! -name 'artifact-sha256-check.raw.log' -printf '%P\n' | LC_ALL=C sort | xargs -r sha256sum > SHA256SUMS
  sha256sum --check SHA256SUMS
) > "$EVIDENCE_DIR/artifact-sha256-check.raw.log" 2>&1
cat "$EVIDENCE_DIR/artifact-sha256-check.raw.log"
