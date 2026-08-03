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

const workflowPath = '.github/workflows/wwhn-180-api-posting-contract.yml';
const ledgerSourcePath = 'Modules/Warehouse/app/Services/StockLedger.php';
const staticPermissionCatalogPath = 'docs/evidence/WWHN-180/permission-catalog.json';

const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));
const environment = JSON.parse(fs.readFileSync(environmentPath, 'utf8'));
const idempotencySource = fs.readFileSync(idempotencySourcePath, 'utf8');
const focusedOpenApi = fs.readFileSync(focusedOpenApiPath, 'utf8');
const workflowSource = fs.readFileSync(workflowPath, 'utf8');
const ledgerSource = fs.readFileSync(ledgerSourcePath, 'utf8');
const staticPermissionCatalog = JSON.parse(fs.readFileSync(staticPermissionCatalogPath, 'utf8'));

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
const workflowLiteralPasswords = workflowSource.match(/Cntt@1234|WWHN180_(?:NO_PERMISSION_)?PASSWORD:\s*(?!\$\{\{\s*secrets\.)[^\s#]+/gi) ?? [];

const permissionEntries = Array.isArray(staticPermissionCatalog.entries)
  ? staticPermissionCatalog.entries
  : [];
const permissionKeys = permissionEntries.map((entry) => `${entry.method} ${entry.path}`);
const expectedPermissionKeys = [
  'POST /api/v1/scan/classify',
  'POST /api/v1/inbound-documents/{id}/scan',
  'POST /api/v1/outbound-documents/{id}/scan',
  'POST /api/v1/inbound-documents/{id}/post-receipt',
  'POST /api/v1/outbound-documents/{id}/post-issue',
  'GET /api/v1/reports/inventory-reconciliation',
];

const checks = {
  collection_version_1_1_or_newer:
    Number(collection.info?.version?.major ?? 0) > 1
    || (Number(collection.info?.version?.major ?? 0) === 1
      && Number(collection.info?.version?.minor ?? 0) >= 1),
  request_count_at_least_23: requests.length >= 23,
  environment_has_no_committed_secret: environmentSecrets.length === 0,
  collection_has_no_literal_secret: collectionSecrets.length === 0,
  workflow_has_no_literal_password: workflowLiteralPasswords.length === 0,
  workflow_uses_runtime_secrets:
    workflowSource.includes('secrets.WWHN180_PASSWORD')
    && workflowSource.includes('secrets.WWHN180_NO_PERMISSION_PASSWORD'),
  workflow_has_secret_preflight:
    workflowSource.includes('Validate runtime-only Newman credentials')
    && workflowSource.includes('Missing repository secret WWHN180_PASSWORD')
    && workflowSource.includes('Missing repository secret WWHN180_NO_PERMISSION_PASSWORD'),

  focused_openapi_idempotency_key_bounds:
    /IdempotencyKey:[\s\S]*minLength:\s*8[\s\S]*maxLength:\s*100/.test(focusedOpenApi),
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

  static_permission_catalog_integrity_pass:
    staticPermissionCatalog.integrity?.status === 'PASS'
    && staticPermissionCatalog.integrity?.required_entry_count === 6
    && staticPermissionCatalog.integrity?.actual_entry_count === 6,
  static_permission_catalog_has_all_critical_routes:
    expectedPermissionKeys.every((key) => permissionKeys.includes(key))
    && new Set(permissionKeys).size === permissionKeys.length,
  static_permission_catalog_keeps_independent_review_pending:
    staticPermissionCatalog.fe1_independent_review?.result === 'PENDING'
    && staticPermissionCatalog.ba_review?.result === 'PENDING',

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
  idempotency_v2_digest_comparator:
    /v2Digest/.test(idempotencySource)
    && /storedV2/.test(idempotencySource)
    && /requestV2/.test(idempotencySource)
    && /hash_equals\(\$storedV2,\s*\$requestV2\)/.test(idempotencySource),
  idempotency_lease_cas: /locked_until/.test(idempotencySource) && /processing/.test(idempotencySource),

  ledger_has_authoritative_posting_boundary:
    /lockAuthoritativePostingBoundary/.test(ledgerSource)
    && /StockDocument::query\(\)[\s\S]*lockForUpdate\(\)/.test(ledgerSource)
    && /STATUS_APPROVED/.test(ledgerSource),
  ledger_locks_current_document_line:
    /StockDocumentLine::query\(\)[\s\S]*where\('stock_document_id',[\s\S]*lockForUpdate\(\)/.test(ledgerSource),
  ledger_recomputes_posting_inputs_from_locked_rows:
    /\$productId\s*=\s*\$line->product_id/.test(ledgerSource)
    && /\$qty\s*=\s*\$line->qty_actual/.test(ledgerSource)
    && /\$businessKey\s*=\s*\$this->businessKey/.test(ledgerSource),
};

const failedChecks = Object.entries(checks)
  .filter(([, passed]) => !passed)
  .map(([name]) => name);

const output = {
  schema_version: '1.3.0',
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
    workflow_literal_password_matches: workflowLiteralPasswords,
    focused_openapi_path: focusedOpenApiPath,
    workflow_path: workflowPath,
    ledger_source_path: ledgerSourcePath,
    static_permission_catalog_path: staticPermissionCatalogPath,
    static_permission_catalog_entry_count: permissionEntries.length,
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
