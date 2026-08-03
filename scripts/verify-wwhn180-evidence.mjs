import fs from 'node:fs';

const args = process.argv.slice(2);
let collectionPath;
let environmentPath;
let idempotencySourcePath;
let focusedOpenApiPath;
let outputPath;

if (args.length === 4) {
  [collectionPath, environmentPath, idempotencySourcePath, outputPath] = args;
  focusedOpenApiPath = 'docs/api-contract/WWHN-180-posting-openapi.yaml';
} else {
  [collectionPath, environmentPath, idempotencySourcePath, focusedOpenApiPath, outputPath] = args;
}

if (!collectionPath || !environmentPath || !idempotencySourcePath || !focusedOpenApiPath || !outputPath) {
  throw new Error('Thiếu tham số verify-wwhn180-evidence');
}

const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));
const environment = JSON.parse(fs.readFileSync(environmentPath, 'utf8'));
const idempotencySource = fs.readFileSync(idempotencySourcePath, 'utf8');
const focusedOpenApi = fs.readFileSync(focusedOpenApiPath, 'utf8');

const flatten = (items, output = []) => {
  for (const item of items ?? []) {
    if (item.request) {
      const scripts = (item.event ?? [])
        .flatMap((event) => event.script?.exec ?? [])
        .join('\n');
      const headers = (item.request.header ?? [])
        .map((header) => String(header.key ?? '').toLowerCase());
      output.push({
        name: String(item.name ?? ''),
        method: String(item.request.method ?? '').toUpperCase(),
        headers,
        scripts,
      });
    }
    flatten(item.item, output);
  }
  return output;
};

const requests = flatten(collection.item);
const byName = (pattern) => requests.find((request) => pattern.test(request.name));
const hasScenario = (pattern, scriptPattern) => {
  const request = byName(pattern);
  return Boolean(request && (!scriptPattern || scriptPattern.test(request.scripts)));
};

const openApiPathBlock = (path) => {
  const marker = `  ${path}:`;
  const start = focusedOpenApi.indexOf(marker);
  if (start < 0) return '';
  const next = focusedOpenApi.indexOf('\n  /api/', start + marker.length);
  return focusedOpenApi.slice(start, next < 0 ? focusedOpenApi.length : next);
};
const openApiHasPathContract = (path, permission, requiredFragments = []) => {
  const block = openApiPathBlock(path);
  return block.length > 0
    && block.includes(`x-permission: ${permission}`)
    && requiredFragments.every((fragment) => block.includes(fragment));
};

const environmentSecrets = (environment.values ?? [])
  .filter((item) => /password|token|secret/i.test(String(item.key ?? '')))
  .filter((item) => String(item.value ?? '').trim() !== '');
const collectionSecrets = JSON.stringify(collection).match(/Cntt@1234|Bearer\s+[A-Za-z0-9._-]{20,}|"password"\s*:\s*"(?!\{\{)[^"]+"/gi) ?? [];

const checks = {
  collection_version_1_1_or_newer:
    Number(collection.info?.version?.major ?? 0) > 1
    || (Number(collection.info?.version?.major ?? 0) === 1
      && Number(collection.info?.version?.minor ?? 0) >= 1),
  request_count_at_least_23: requests.length >= 23,
  environment_has_no_committed_secret: environmentSecrets.length === 0,
  collection_has_no_literal_secret: collectionSecrets.length === 0,

  focused_openapi_scan_classify:
    openApiHasPathContract('/api/v1/scan/classify', 'scan.classify', ['stock_effect=NONE']),
  focused_openapi_inbound_scan:
    openApiHasPathContract('/api/v1/inbound-documents/{id}/scan', 'stock_document.scan', [
      'stock_effect=NONE', "$ref: '#/components/schemas/DocumentLineScanResult'",
    ]),
  focused_openapi_outbound_scan:
    openApiHasPathContract('/api/v1/outbound-documents/{id}/scan', 'stock_document.scan', [
      'stock_effect=NONE', "$ref: '#/components/schemas/DocumentLineScanResult'",
    ]),
  focused_openapi_post_receipt:
    openApiHasPathContract('/api/v1/inbound-documents/{id}/post-receipt', 'stock_document.post', [
      "$ref: '#/components/parameters/IdempotencyKey'", "'409':", "'428':",
    ]),
  focused_openapi_post_issue:
    openApiHasPathContract('/api/v1/outbound-documents/{id}/post-issue', 'stock_document.post', [
      "$ref: '#/components/parameters/IdempotencyKey'", "'409':", "'428':",
    ]),
  focused_openapi_reconciliation:
    openApiHasPathContract('/api/v1/reports/inventory-reconciliation', 'report.view', ["'200':", "'403':"]),

  scan_none_assertion: hasScenario(/scan inbound has no stock effect/i, /stock_effect NONE[\s\S]*movement not created/i),
  receipt_missing_key_428: hasScenario(/post receipt missing key/i, /428/),
  receipt_happy_path: hasScenario(/^10 post receipt$/i, /received/),
  receipt_exact_replay: hasScenario(/replay receipt exact snapshot/i, /same receipt movement/i),
  receipt_payload_conflict: hasScenario(/same receipt key different payload/i, /IDEMPOTENCY_ERROR/),
  receipt_different_key_double_post: hasScenario(/different receipt key double-post/i, /STATE_CONFLICT/),
  issue_missing_key_428: hasScenario(/post issue missing key/i, /428/),
  issue_happy_path: hasScenario(/^18 post issue$/i, /issued/),
  issue_exact_replay: hasScenario(/replay issue exact snapshot/i, /same issue movement/i),
  issue_payload_conflict: hasScenario(/same issue key different payload/i, /IDEMPOTENCY_ERROR/),
  issue_different_key_double_post: hasScenario(/different issue key double-post/i, /STATE_CONFLICT/),
  reconciliation_pass: hasScenario(/reconciliation manager/i, /reconcile PASS[\s\S]*no mismatch/i),
  reconciliation_rbac_denied: hasScenario(/reconciliation denied without permission/i, /403[\s\S]*ACCESS_DENIED/i),

  idempotency_outer_transaction: /DB::transaction\s*\(/.test(idempotencySource),
  idempotency_row_lock: /lockForUpdate\s*\(/.test(idempotencySource),
  idempotency_response_snapshot: /response_body/.test(idempotencySource),
  idempotency_canonical_sha256: /canonicalize/.test(idempotencySource) && /hash\(['"]sha256['"]/.test(idempotencySource),
  idempotency_lease_cas: /locked_until/.test(idempotencySource) && /processing/.test(idempotencySource),
};

const failedChecks = Object.entries(checks)
  .filter(([, passed]) => !passed)
  .map(([name]) => name);

const output = {
  schema_version: '1.1.0',
  generated_at_utc: new Date().toISOString(),
  document_status: 'PROPOSED',
  approval_status: 'PENDING_INDEPENDENT_SIGN_OFF',
  summary: {
    checked: Object.keys(checks).length,
    passed: Object.keys(checks).length - failedChecks.length,
    failed: failedChecks.length,
    status: failedChecks.length === 0 ? 'PASS' : 'FAIL',
  },
  checks,
  failed_checks: failedChecks,
  diagnostics: {
    request_count: requests.length,
    environment_secret_keys: environmentSecrets.map((item) => item.key),
    collection_secret_matches: collectionSecrets,
    focused_openapi_path: focusedOpenApiPath,
    focused_openapi_paths: [
      '/api/v1/scan/classify',
      '/api/v1/inbound-documents/{id}/scan',
      '/api/v1/outbound-documents/{id}/scan',
      '/api/v1/inbound-documents/{id}/post-receipt',
      '/api/v1/outbound-documents/{id}/post-issue',
      '/api/v1/reports/inventory-reconciliation',
    ].filter((path) => openApiPathBlock(path).length > 0),
  },
};

fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);
if (failedChecks.length > 0) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}

console.log(`WWHN-180 evidence verification PASS: ${output.summary.passed}/${output.summary.checked}`);
