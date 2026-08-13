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

const paths = {
  workflow: '.github/workflows/wwhn-180-api-posting-contract.yml',
  collector: 'scripts/collect-wwhn180-evidence.sh',
  redactor: 'scripts/redact-wwhn180-evidence.mjs',
  ledger: 'Modules/Warehouse/app/Services/StockLedger.php',
  routes: 'Modules/Warehouse/routes/api.php',
  compatibilitySeeder: 'Modules/Auth/database/seeders/WmsPermissionCompatibilitySeeder.php',
  aliasTest: 'tests/Feature/Wwhn180PermissionAliasContractTest.php',
  staticCatalog: 'docs/evidence/WWHN-180/permission-catalog.json',
  mapping: 'docs/evidence/WWHN-180/BE_FE_PERMISSION_MAPPING.json',
  sourceRegister: 'docs/docs_task_jira/WWHN-180-source-register.json',
};

const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));
const environment = JSON.parse(fs.readFileSync(environmentPath, 'utf8'));
const idempotencySource = fs.readFileSync(idempotencySourcePath, 'utf8');
const focusedOpenApiSource = fs.readFileSync(focusedOpenApiPath, 'utf8');
const focusedOpenApiJsonPath = focusedOpenApiPath.replace(/\.(?:yaml|yml)$/i, '.json');
const focusedOpenApi = JSON.parse(fs.readFileSync(focusedOpenApiJsonPath, 'utf8'));
const workflowSource = fs.readFileSync(paths.workflow, 'utf8');
const collectorSource = fs.readFileSync(paths.collector, 'utf8');
const redactorSource = fs.readFileSync(paths.redactor, 'utf8');
const ledgerSource = fs.readFileSync(paths.ledger, 'utf8');
const routeSource = fs.readFileSync(paths.routes, 'utf8');
const compatibilitySeederSource = fs.readFileSync(paths.compatibilitySeeder, 'utf8');
const aliasTestSource = fs.readFileSync(paths.aliasTest, 'utf8');
const staticPermissionCatalog = JSON.parse(fs.readFileSync(paths.staticCatalog, 'utf8'));
const permissionMapping = JSON.parse(fs.readFileSync(paths.mapping, 'utf8'));
const sourceRegister = JSON.parse(fs.readFileSync(paths.sourceRegister, 'utf8'));

const flatten = (items, output = []) => {
  for (const item of items ?? []) {
    if (item.request) {
      const scripts = (item.event ?? [])
        .flatMap((event) => event.script?.exec ?? [])
        .join('\n');
      output.push({
        name: String(item.name ?? ''),
        method: String(item.request.method ?? '').toUpperCase(),
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

const operation = (apiPath, method) => focusedOpenApi.paths?.[apiPath]?.[method.toLowerCase()] ?? null;
const operationHasResponses = (candidate, codes) =>
  Boolean(candidate && codes.every((code) => Object.hasOwn(candidate.responses ?? {}, String(code))));
const operationHasParameterRef = (candidate, reference) =>
  Boolean(candidate?.parameters?.some((parameter) => parameter?.$ref === reference));
const operationHasContract = (apiPath, method, permission, stockEffect, responseCodes) => {
  const candidate = operation(apiPath, method);
  return Boolean(
    candidate
      && candidate['x-permission'] === permission
      && (stockEffect === null || candidate['x-stock-effect'] === stockEffect)
      && operationHasResponses(candidate, responseCodes),
  );
};

const environmentSecrets = (environment.values ?? [])
  .filter((item) => /password|token|secret/i.test(String(item.key ?? '')))
  .filter((item) => String(item.value ?? '').trim() !== '');

const serializedCollection = JSON.stringify(collection);
const collectionSecrets =
  serializedCollection.match(/Cntt@1234|Bearer\s+[A-Za-z0-9._-]{20,}|"password"\s*:\s*"(?!\{\{)[^"]+"/gi) ?? [];
const repositorySecretReferences =
  workflowSource.match(/secrets\.WWHN180_(?:NO_PERMISSION_)?PASSWORD/g) ?? [];
const literalPasswordAssignments =
  workflowSource.match(/^\s*WWHN180_(?:NO_PERMISSION_)?PASSWORD:\s*(?!\$\{\{|\$)[^\s#]+/gim) ?? [];

const permissionEntries = Array.isArray(staticPermissionCatalog.entries)
  ? staticPermissionCatalog.entries
  : [];
const permissionKeys = permissionEntries.map((entry) => `${entry.method} ${entry.path}`);
const criticalPermissionKeys = [
  'POST /api/v1/scan/classify',
  'POST /api/v1/inbound-documents/{id}/scan',
  'POST /api/v1/outbound-documents/{id}/scan',
  'POST /api/v1/inbound-documents/{id}/post-receipt',
  'POST /api/v1/outbound-documents/{id}/post-issue',
  'POST /api/v1/stocktake/sessions/{id}/apply-adjustment',
  'POST /api/v1/warranty-cases/{id}/temp-only',
  'GET /api/v1/reports/inventory-reconciliation',
];

// stocktake.count không còn trong danh sách alias bắt buộc: module stocktake
// đã bị defer khỏi baseline hiện tại (PENDING_WWHN_180_WWHN_182) — không còn
// route/permission canonical để alias trỏ tới.
const requiredAliases = [
  'inbound.post',
  'outbound.post',
  'scan.inbound',
  'scan.outbound',
  'scan.warranty',
  'inventory.trace',
  'warranty.manage',
  'report.export',
];

const redactorIndex = collectorSource.indexOf('redact-wwhn180-evidence.mjs');
const manifestIndex = collectorSource.indexOf('SHA256SUMS');
const idempotencyKeySchema = focusedOpenApi.components?.parameters?.IdempotencyKey?.schema ?? {};
const receiptOperation = operation('/api/v1/inbound-documents/{id}/post-receipt', 'post');
const issueOperation = operation('/api/v1/outbound-documents/{id}/post-issue', 'post');

const checks = {
  collection_version_1_1_or_newer:
    Number(collection.info?.version?.major ?? 0) > 1
    || (Number(collection.info?.version?.major ?? 0) === 1
      && Number(collection.info?.version?.minor ?? 0) >= 1),
  request_count_at_least_23: requests.length >= 23,
  environment_has_no_committed_secret: environmentSecrets.length === 0,
  collection_has_no_literal_secret: collectionSecrets.length === 0,

  workflow_has_no_repository_secret_dependency: repositorySecretReferences.length === 0,
  workflow_has_no_literal_password_assignment: literalPasswordAssignments.length === 0,
  workflow_generates_ephemeral_password_and_jwt:
    workflowSource.includes('openssl rand -hex 24')
    && workflowSource.includes('openssl rand -hex 32')
    && workflowSource.includes('CI_DEMO_PASSWORD=')
    && workflowSource.includes('JWT_SECRET='),
  workflow_masks_ephemeral_credentials:
    workflowSource.includes('::add-mask::$password')
    && /::add-mask::\$(?:jwt|jwt_secret)/.test(workflowSource),
  collector_requires_seed_and_newman_credential_identity:
    collectorSource.includes('CI_DEMO_PASSWORD')
    && collectorSource.includes('runtime credentials must match CI_DEMO_PASSWORD'),
  collector_redacts_before_manifest:
    redactorIndex >= 0
    && manifestIndex > redactorIndex,
  redactor_checks_runtime_secret_and_jwt_residuals:
    redactorSource.includes('residual_runtime_secret_count')
    && redactorSource.includes('residual_jwt_count')
    && redactorSource.includes("status !== 'PASS'"),

  focused_openapi_idempotency_key_bounds:
    idempotencyKeySchema.minLength === 8
    && idempotencyKeySchema.maxLength === 100,
  focused_openapi_scan_classify:
    operationHasContract('/api/v1/scan/classify', 'post', 'scan.classify', 'NONE', ['200', '401', '403', '409', '422']),
  focused_openapi_inbound_scan:
    operationHasContract('/api/v1/inbound-documents/{id}/scan', 'post', 'stock_document.scan', 'NONE', ['200', '401', '403', '409', '422']),
  focused_openapi_outbound_scan:
    operationHasContract('/api/v1/outbound-documents/{id}/scan', 'post', 'stock_document.scan', 'NONE', ['200', '401', '403', '409', '422']),
  focused_openapi_post_receipt:
    operationHasContract('/api/v1/inbound-documents/{id}/post-receipt', 'post', 'stock_document.post', 'RECEIPT_IN', ['200', '401', '403', '409', '422', '428'])
    && operationHasParameterRef(receiptOperation, '#/components/parameters/IdempotencyKey'),
  focused_openapi_post_issue:
    operationHasContract('/api/v1/outbound-documents/{id}/post-issue', 'post', 'stock_document.post', 'ISSUE_OUT', ['200', '401', '403', '409', '422', '428'])
    && operationHasParameterRef(issueOperation, '#/components/parameters/IdempotencyKey'),
  focused_openapi_reconciliation:
    operationHasContract('/api/v1/reports/inventory-reconciliation', 'get', 'report.view', 'NONE', ['200', '401', '403', '422']),

  static_permission_catalog_current_fe_source:
    staticPermissionCatalog.document_version === '1.7.0'
    && staticPermissionCatalog.frontend_observed_source?.exact_commit_sha === sourceRegister.frontend_observed_source?.exact_commit_sha
    && staticPermissionCatalog.frontend_observed_source?.baseline_git_blob_sha === sourceRegister.frontend_observed_source?.baseline_git_blob_sha
    && staticPermissionCatalog.frontend_observed_source?.review_task_key === 'WWHN-181',
  static_permission_catalog_integrity_pass:
    staticPermissionCatalog.integrity?.status === 'PASS'
    && staticPermissionCatalog.integrity?.required_entry_count === 8
    && staticPermissionCatalog.integrity?.actual_entry_count === 8,
  static_permission_catalog_has_all_critical_routes:
    criticalPermissionKeys.every((key) => permissionKeys.includes(key))
    && new Set(permissionKeys).size === permissionKeys.length,
  static_permission_catalog_keeps_independent_review_pending:
    staticPermissionCatalog.fe1_independent_review?.result === 'PENDING'
    && staticPermissionCatalog.ba_review?.result === 'PENDING',

  mapping_is_exact_fe_sha_bound:
    sourceRegister.version === '1.7.0'
    && permissionMapping.frontend_observed_source?.exact_commit_sha === sourceRegister.frontend_observed_source?.exact_commit_sha
    && permissionMapping.frontend_observed_source?.baseline_git_blob_sha === sourceRegister.frontend_observed_source?.baseline_git_blob_sha
    && permissionMapping.frontend_observed_source?.review_task_key === 'WWHN-181',
  mapping_integrity_pass:
    permissionMapping.integrity?.status === 'PASS'
    && permissionMapping.integrity?.unmapped_permission_rows === 0
    && permissionMapping.integrity?.canonical_middleware_replaced_by_alias === false,
  mapping_keeps_independent_review_pending:
    permissionMapping.independent_review?.FE1?.result === 'PENDING'
    && permissionMapping.independent_review?.BA?.result === 'PENDING',

  compatibility_seeder_contains_all_aliases:
    requiredAliases.every((permission) => compatibilitySeederSource.includes(`'${permission}'`)),
  compatibility_seeder_limits_role_assignments:
    compatibilitySeederSource.includes("'WAREHOUSE_KEEPER'")
    && compatibilitySeederSource.includes("'WAREHOUSE_MANAGER'")
    && !compatibilitySeederSource.includes("'NO_PERMISSION' =>"),
  stocktake_fe_route_alias_exists:
    routeSource.includes("Route::post('stocktake/sessions/{id}/apply-adjustment'")
    && routeSource.includes("[StocktakeController::class, 'applyAdjustment']")
    && routeSource.includes("middleware('permission:stocktake.adjust')"),
  alias_test_proves_no_permission_isolation:
    aliasTestSource.includes("where('role_code', 'NO_PERMISSION')")
    && aliasTestSource.includes('assertNotContains'),
  alias_test_proves_canonical_middleware:
    aliasTestSource.includes('permission:stock_document.post')
    && aliasTestSource.includes('permission:stock_document.scan')
    && aliasTestSource.includes('assertCanonicalMiddleware'),

  scan_none_assertion:
    hasScenario(/scan inbound has no stock effect/i, /stock_effect NONE[\s\S]*movement not created/i),
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
  reconciliation_rbac_denied:
    hasScenario(/reconciliation denied without permission/i, /403[\s\S]*ACCESS_DENIED/i),

  idempotency_outer_transaction: /DB::transaction\s*\(/.test(idempotencySource),
  idempotency_row_lock: /lockForUpdate\s*\(/.test(idempotencySource),
  idempotency_response_snapshot: /response_body/.test(idempotencySource),
  idempotency_canonical_sha256:
    /canonicalize/.test(idempotencySource) && /hash\(['"]sha256['"]/.test(idempotencySource),
  idempotency_v2_digest_comparator:
    /v2Digest/.test(idempotencySource)
    && /storedV2/.test(idempotencySource)
    && /requestV2/.test(idempotencySource)
    && /hash_equals\(\$storedV2,\s*\$requestV2\)/.test(idempotencySource),
  idempotency_lease_cas:
    /locked_until/.test(idempotencySource) && /processing/.test(idempotencySource),

  ledger_has_authoritative_posting_boundary:
    /lockAuthoritativePostingBoundary/.test(ledgerSource)
    && /StockDocument::query\(\)[\s\S]*lockForUpdate\(\)/.test(ledgerSource)
    && /STATUS_APPROVED/.test(ledgerSource),
  ledger_locks_current_document_line:
    /StockDocumentLine::query\(\)[\s\S]*where\('stock_document_id',[\s\S]*lockForUpdate\(\)/.test(ledgerSource),
  // Ledger hiện recompute input từ locked row kèm ép kiểu + validate integer
  // (integerQuantity) — chặt hơn phiên bản gán trực tiếp trước đây.
  ledger_recomputes_posting_inputs_from_locked_rows:
    /\$productId\s*=\s*\(string\)\s*\$line->product_id/.test(ledgerSource)
    && /\$quantity\s*=\s*\$this->integerQuantity\(\$line->qty_actual\)/.test(ledgerSource)
    && /\$businessKey\s*=\s*\$this->businessKey/.test(ledgerSource),
};

const failedChecks = Object.entries(checks)
  .filter(([, passed]) => !passed)
  .map(([name]) => name);

const output = {
  schema_version: '1.6.0',
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
    repository_secret_references: repositorySecretReferences,
    workflow_literal_password_matches: literalPasswordAssignments,
    focused_openapi_path: focusedOpenApiPath,
    focused_openapi_json_path: focusedOpenApiJsonPath,
    focused_openapi_source_has_refs: focusedOpenApiSource.includes('$ref:'),
    static_permission_catalog_entry_count: permissionEntries.length,
    source_register_version: sourceRegister.version ?? null,
    mapping_fe_exact_sha: permissionMapping.frontend_observed_source?.exact_commit_sha ?? null,
    mapping_fe_baseline_blob_sha: permissionMapping.frontend_observed_source?.baseline_git_blob_sha ?? null,
    mapping_row_count: permissionMapping.integrity?.critical_rows ?? null,
  },
};

fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);

if (failedChecks.length > 0) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}

console.log(`WWHN-180 evidence verification PASS: ${output.summary.passed}/${output.summary.checked}`);
