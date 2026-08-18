#!/usr/bin/env bash
# WWHN-300 — Backend GoLive check evidence (subtask of WWHN-80).
#
# WWHN-155 already proves migrate:fresh --seed + /api/v1/health on a clean
# database. This collector proves the cutover-specific property that was
# broken before this ticket: the migration chain unwinds completely and can be
# re-applied, so a cutover abort has a deterministic schema path back.
#
# Requires: PostgreSQL (the production driver). Run from the repository root.
set -Eeuo pipefail

EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/wwhn-300/postgresql}"
mkdir -p "$EVIDENCE_DIR"

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
  echo "jira=WWHN-300"
  echo "parent=WWHN-80"
  echo "output=Backend GoLive check"
  echo "commit_sha=${GITHUB_SHA:-$(git rev-parse HEAD)}"
  echo "source_tree=$(git rev-parse HEAD^{tree})"
  echo "ref=${GITHUB_REF:-local}"
  echo "run_id=${GITHUB_RUN_ID:-local}"
  echo "run_attempt=${GITHUB_RUN_ATTEMPT:-local}"
  echo "started_at=$(date --iso-8601=seconds)"
  php -v | head -n 1
  php artisan --version
} 2>&1 | tee "$EVIDENCE_DIR/execution-metadata.raw.log"

# 1. Forward: the state a cutover leaves behind.
log_command migrate-fresh-seed php artisan migrate:fresh --seed --force
log_command golive-check-after-migrate php artisan wms:golive-check --json="$EVIDENCE_DIR/golive-check.json"

# 2. Rollback: the cutover abort path. Before WWHN-300 this stopped mid-chain
#    (SQLSTATE 42809 / 2BP01 / 0A000) and left a schema that could neither serve
#    traffic nor migrate forward again.
log_command migrate-rollback php artisan migrate:rollback --force

php artisan tinker --execute="
\$rows = \Illuminate\Support\Facades\DB::select(\"SELECT relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relkind IN ('r','v','m','p') ORDER BY relname\");
echo 'leftover_relations=' . implode(',', array_map(fn (\$r) => \$r->relname, \$rows)) . PHP_EOL;
" 2>&1 | tee "$EVIDENCE_DIR/rollback-leftovers.raw.log"

# Only Laravel's own migrations ledger may survive a full rollback.
leftovers="$(sed -n 's/.*leftover_relations=//p' "$EVIDENCE_DIR/rollback-leftovers.raw.log" | tr -d '\r' | tail -n 1)"
if [[ "$leftovers" != "migrations" ]]; then
  echo "Rollback left unexpected relations behind: $leftovers" >&2
  exit 1
fi

# 3. Forward again: proves the rollback did not brick the database.
log_command migrate-forward-after-rollback php artisan migrate --force
log_command seed-after-rollback php artisan db:seed --force
log_command golive-check-after-roundtrip php artisan wms:golive-check --json="$EVIDENCE_DIR/golive-check-after-roundtrip.json"

# 4. Targeted regression tests for the defect class fixed here.
log_command focused-phpunit php artisan test --filter 'Wwhn300BackendGoLiveCheckTest|HoaNamErdSchemaDropRelationTest'

(
  cd "$EVIDENCE_DIR"
  sha256sum ./*.log ./*.json > SHA256SUMS
)

echo "WWHN-300 evidence written to $EVIDENCE_DIR"
