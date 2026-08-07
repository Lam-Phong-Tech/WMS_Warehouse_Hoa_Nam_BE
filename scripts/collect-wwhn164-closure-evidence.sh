#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

OUT="${1:-build/evidence/wwhn-164}"
rm -rf "$OUT"
mkdir -p "$OUT/contracts" "$OUT/tests" "$OUT/review"

COMMIT_SHA="${GITHUB_SHA:-$(git rev-parse HEAD)}"
BRANCH="${GITHUB_REF_NAME:-$(git branch --show-current)}"
RUN_ID="${GITHUB_RUN_ID:-local}"
RUN_ATTEMPT="${GITHUB_RUN_ATTEMPT:-1}"
GENERATED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
PHP_VERSION="$(php -r 'echo PHP_VERSION;')"
DB_DRIVER="${DB_CONNECTION:-unknown}"

# 1) Exact-SHA automated security regression evidence.
php artisan test \
  tests/Feature/AuthRbacAuditHardeningTest.php \
  tests/Feature/SecurityRbacAuditNegativeTest.php \
  --log-junit "$OUT/tests/phpunit-security.xml" \
  | tee "$OUT/tests/phpunit-security.log"

# 2) Full suite: prevents a localized fix from introducing regressions elsewhere.
php artisan test \
  --log-junit "$OUT/tests/phpunit-full.xml" \
  | tee "$OUT/tests/phpunit-full.log"

# 3) Canonical contract generated from runtime annotations, then linted.
php artisan l5-swagger:generate
cp storage/api-docs/api-docs.json "$OUT/contracts/openapi.json"
npx redocly lint "$OUT/contracts/openapi.json" \
  | tee "$OUT/contracts/openapi-lint.log"

# Keep Postman derived from the exact OpenAPI artifact, not from an older checked-in copy.
# openapi2postmanv2 may return non-zero after successfully writing a valid collection;
# validate the produced file as the source of truth and retain the raw exit code in the log.
set +e
npx openapi2postmanv2 \
  -s "$OUT/contracts/openapi.json" \
  -o "$OUT/contracts/postman.collection.json" \
  -p \
  -O folderStrategy=Tags \
  2>&1 | tee "$OUT/contracts/postman-conversion.log"
POSTMAN_CONVERSION_STATUS=${PIPESTATUS[0]}
set -e
printf '\nopenapi2postmanv2_exit_code=%s\n' "$POSTMAN_CONVERSION_STATUS" >> "$OUT/contracts/postman-conversion.log"

node - "$OUT" "$COMMIT_SHA" "$POSTMAN_CONVERSION_STATUS" <<'NODE'
import fs from 'node:fs';
import path from 'node:path';

const [out, sha, conversionExitCode] = process.argv.slice(2);
const openapi = JSON.parse(fs.readFileSync(path.join(out, 'contracts/openapi.json'), 'utf8'));
const postmanPath = path.join(out, 'contracts/postman.collection.json');
if (!fs.existsSync(postmanPath) || fs.statSync(postmanPath).size === 0) {
  throw new Error(`Postman conversion failed with exit code ${conversionExitCode} and produced no collection`);
}
const postman = JSON.parse(fs.readFileSync(postmanPath, 'utf8'));
const requiredPaths = ['/api/v1/auth/login', '/api/v1/auth/refresh', '/api/v1/auth/logout', '/api/v1/auth/me'];
const missing = requiredPaths.filter((p) => !openapi.paths?.[p]);
if (missing.length) throw new Error(`OpenAPI thiếu endpoint bắt buộc: ${missing.join(', ')}`);
if (!postman.info?._postman_id || !Array.isArray(postman.item) || postman.item.length === 0) {
  throw new Error('Postman collection không hợp lệ hoặc rỗng');
}
const report = {
  status: 'PASS',
  commit_sha: sha,
  openapi_version: openapi.info?.version ?? null,
  required_paths: requiredPaths,
  missing_paths: missing,
  postman_name: postman.info?.name ?? null,
  converter_exit_code: Number(conversionExitCode),
  converter_output_validated: true,
  note: 'Reviewer approval is deliberately not asserted by automation.'
};
fs.writeFileSync(path.join(out, 'contracts/contract-parity.json'), JSON.stringify(report, null, 2) + '\n');
NODE

# 4) Repository credential sanitation gate. Test fixtures/placeholders are permitted;
# live-looking values in tracked files fail the build. Never print matched values.
set +e
git grep -IlE '(BEGIN (RSA|OPENSSH|EC|DSA) PRIVATE KEY|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{30,}|xox[baprs]-[A-Za-z0-9-]{20,})' \
  -- ':!vendor' ':!node_modules' ':!build' > "$OUT/tests/credential-scan-files.txt"
SCAN_STATUS=$?
set -e
if [[ $SCAN_STATUS -eq 0 && -s "$OUT/tests/credential-scan-files.txt" ]]; then
  echo 'Phát hiện file có mẫu credential/private key. Nội dung đã bị ẩn.' >&2
  exit 1
fi
: > "$OUT/tests/credential-scan-files.txt"

# 5) Runtime permission catalog captured for BA/QA traceability review.
php artisan tinker --execute='echo json_encode(\Modules\Auth\Models\Permission::query()->orderBy("key")->pluck("key")->values(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);' \
  > "$OUT/tests/runtime-permission-keys.json"
node -e 'JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"))' "$OUT/tests/runtime-permission-keys.json"

cat > "$OUT/build-metadata.json" <<JSON
{
  "task": "WWHN-164",
  "commit_sha": "$COMMIT_SHA",
  "branch": "$BRANCH",
  "workflow_run_id": "$RUN_ID",
  "workflow_run_attempt": "$RUN_ATTEMPT",
  "generated_at_utc": "$GENERATED_AT",
  "php_version": "$PHP_VERSION",
  "db_connection": "$DB_DRIVER",
  "audit_write_policy": "${AUDIT_WRITE_POLICY:-unknown}",
  "artifact_name": "wwhn-164-closure-evidence-$COMMIT_SHA",
  "approval_state": "PENDING_HUMAN_REVIEW"
}
JSON

cat > "$OUT/review/signoff-register.json" <<JSON
{
  "schema_version": "1.0",
  "task": "WWHN-164",
  "commit_sha": "$COMMIT_SHA",
  "immutable_artifact": "wwhn-164-closure-evidence-$COMMIT_SHA",
  "signoffs": {
    "qa1_security_api_db": {"status": "PENDING", "jira": "WWHN-166", "reviewer": null, "reviewed_at": null, "evidence_link": null},
    "qa2_browser_viewport": {"status": "PENDING", "jira": "WWHN-167", "reviewer": null, "reviewed_at": null, "evidence_link": null},
    "openapi_postman": {"status": "PENDING", "reviewer_roles": ["Tech Lead", "FE", "QA"], "reviewed_at": null},
    "pr_ci": {"status": "PENDING", "reviewer_roles": ["Tech Lead", "QA1"], "reviewed_at": null},
    "data_scope": {"status": "PENDING", "reviewer_roles": ["QA1", "Security"], "reviewed_at": null},
    "audit_operations": {"status": "PENDING", "reviewer_roles": ["Tech Lead", "Security", "DevOps"], "reviewed_at": null},
    "credential_rotation": {"status": "PENDING_OR_NOT_APPLICABLE", "reviewer_roles": ["DevOps", "Security"], "reviewed_at": null},
    "permission_traceability": {"status": "PENDING", "reviewer_roles": ["BA", "QA1"], "reviewed_at": null}
  },
  "rule": "Automation must never change a human signoff to APPROVED."
}
JSON

find "$OUT" -type f ! -name SHA256SUMS -print0 \
  | sort -z \
  | xargs -0 sha256sum \
  | sed "s#  $OUT/#  #" > "$OUT/SHA256SUMS"
(cd "$OUT" && sha256sum --check SHA256SUMS)

echo "WWHN-164 closure evidence generated: $OUT"
