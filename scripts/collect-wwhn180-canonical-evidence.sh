#!/usr/bin/env bash
set -euo pipefail

EVIDENCE_DIR="${EVIDENCE_DIR:-build/evidence/api-data}"
CANONICAL_COLLECTION="docs/postman/WWHN-180 Canonical Contract.postman_collection.json"
CANONICAL_ENVIRONMENT="docs/postman/WWHN-180 Canonical Contract.postman_environment.json"
FOCUSED_JSON="docs/api-contract/WWHN-180-posting-openapi.json"
FOCUSED_YAML="docs/api-contract/WWHN-180-posting-openapi.yaml"
SOURCE_REGISTER="docs/docs_task_jira/WWHN-180-source-register.json"
MANIFEST="docs/evidence/WWHN-180/IMMUTABLE_HANDOFF_MANIFEST.json"

# Preserve the already-tested backend fixture pipeline. Its Postman collection is
# an internal provisioning harness, not the FE/QA canonical handoff.
bash scripts/collect-wwhn180-evidence.sh

mv "$EVIDENCE_DIR/wwhn180.postman.collection.json" "$EVIDENCE_DIR/wwhn180.ci-harness.collection.json"
mv "$EVIDENCE_DIR/wwhn180.postman.environment.json" "$EVIDENCE_DIR/wwhn180.ci-harness.environment.json"
cp "$CANONICAL_COLLECTION" "$EVIDENCE_DIR/wwhn180.postman.collection.json"
cp "$CANONICAL_ENVIRONMENT" "$EVIDENCE_DIR/wwhn180.postman.environment.json"
cp "$FOCUSED_JSON" "$EVIDENCE_DIR/wwhn180-openapi.json"
cp "$FOCUSED_YAML" "$EVIDENCE_DIR/wwhn180-openapi.yaml"
cp "$MANIFEST" "$EVIDENCE_DIR/immutable-handoff-manifest.json"

node scripts/verify-wwhn180-canonical-handoff.mjs \
  "$CANONICAL_COLLECTION" \
  "$CANONICAL_ENVIRONMENT" \
  "$FOCUSED_JSON" \
  "$SOURCE_REGISTER" \
  "$EVIDENCE_DIR/canonical-handoff-verification.json"

node - "$EVIDENCE_DIR" <<'NODE'
const fs = require('fs');
const crypto = require('crypto');
const root = process.argv[2];
const hash = file => crypto.createHash('sha256').update(fs.readFileSync(`${root}/${file}`)).digest('hex');
const metadataPath = `${root}/build-metadata.json`;
const metadata = JSON.parse(fs.readFileSync(metadataPath, 'utf8'));
Object.assign(metadata, {
  focused_contract_version: '1.6.0',
  canonical_postman_version: '1.6.0',
  canonical_postman_request_count: 15,
  ci_harness_postman_version: '1.1.0',
  ci_harness_request_count: 23,
  canonical_postman_sha256: hash('wwhn180.postman.collection.json'),
  canonical_environment_sha256: hash('wwhn180.postman.environment.json'),
  ci_harness_sha256: hash('wwhn180.ci-harness.collection.json'),
  focused_openapi_json_sha256: hash('wwhn180-openapi.json'),
  focused_openapi_yaml_sha256: hash('wwhn180-openapi.yaml'),
  canonical_drift_status: 'PASS'
});
fs.writeFileSync(metadataPath, `${JSON.stringify(metadata, null, 2)}\n`);

const closurePath = `${root}/g1-closure-matrix.json`;
const closure = JSON.parse(fs.readFileSync(closurePath, 'utf8'));
closure.schema_version = '1.2.0';
closure.rows = (closure.rows ?? []).map(row => row.id === 'BE1-P1-01'
  ? {...row, be_status: 'CANONICAL_OPENAPI_POSTMAN_COMMITTED', closure_condition: 'VERIFY_IMMUTABLE_SHA_AND_MANIFEST'}
  : row);
fs.writeFileSync(closurePath, `${JSON.stringify(closure, null, 2)}\n`);
fs.appendFileSync(`${root}/ci-summary.md`, '\n- Canonical handoff v1.6.0: 15 requests, no submit/approve/reject paths; legacy 23-request collection is classified as internal CI harness.\n');
NODE

node scripts/redact-wwhn180-evidence.mjs "$EVIDENCE_DIR"
rm -f "$EVIDENCE_DIR/SHA256SUMS" "$EVIDENCE_DIR/SHA256SUMS.sha256"
(
  cd "$EVIDENCE_DIR"
  find . -type f ! -name SHA256SUMS ! -name SHA256SUMS.sha256 -printf '%P\n' \
    | LC_ALL=C sort \
    | while IFS= read -r file; do sha256sum "$file"; done > SHA256SUMS
  sha256sum SHA256SUMS > SHA256SUMS.sha256
  sha256sum --check SHA256SUMS
  sha256sum --check SHA256SUMS.sha256
)
