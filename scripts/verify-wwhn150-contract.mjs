import fs from 'node:fs';

const [
  openapiPath,
  routesPath,
  catalogPath,
  postmanPath,
  testCasesPath,
  sourceRegisterPath,
  controllerPath,
  servicePath,
  standaloneOpenapiPath,
  outputPath,
] = process.argv.slice(2);

if ([openapiPath, routesPath, catalogPath, postmanPath, testCasesPath, sourceRegisterPath,
  controllerPath, servicePath, standaloneOpenapiPath, outputPath].some((value) => !value)) {
  throw new Error('Thiếu tham số verify-wwhn150-contract');
}

const openapi = JSON.parse(fs.readFileSync(openapiPath, 'utf8'));
const routes = JSON.parse(fs.readFileSync(routesPath, 'utf8'));
const catalog = JSON.parse(fs.readFileSync(catalogPath, 'utf8'));
const postman = JSON.parse(fs.readFileSync(postmanPath, 'utf8'));
const testCases = JSON.parse(fs.readFileSync(testCasesPath, 'utf8'));
const sourceRegister = JSON.parse(fs.readFileSync(sourceRegisterPath, 'utf8'));
const controller = fs.readFileSync(controllerPath, 'utf8');
const service = fs.readFileSync(servicePath, 'utf8');
const standaloneOpenapi = fs.readFileSync(standaloneOpenapiPath, 'utf8');

const normalizeUri = (uri) => `/${String(uri).replace(/^\/+/, '')}`
  .replaceAll(/\/+/g, '/')
  .replace(/\?.*$/, '');
const toList = (value) => Array.isArray(value)
  ? value.map(String)
  : String(value ?? '').split(/[|,]/).map((item) => item.trim()).filter(Boolean);

const runtimeRoutes = routes.map((route) => ({
  methods: toList(route.method || route.methods).map((method) => method.toUpperCase()),
  uri: normalizeUri(route.uri),
  middleware: toList(route.middleware),
}));
const scanRoutes = runtimeRoutes.filter((route) => route.uri === '/api/v1/scan/classify' && route.methods.includes('POST'));
const spec = openapi.paths?.['/api/v1/scan/classify']?.post;
const responseCodes = new Set(Object.keys(spec?.responses ?? {}).map(String));
const requiredHeader = (name) => (spec?.parameters ?? []).some((parameter) =>
  String(parameter?.in).toLowerCase() === 'header'
  && String(parameter?.name).toLowerCase() === name.toLowerCase()
  && parameter?.required === true
);
const hasBearer = (spec?.security ?? []).some((entry) => Object.hasOwn(entry, 'bearerAuth'));

const permission = (catalog.permissions ?? []).find((item) => item.permission_key === 'scan.classify');
const matrix = (catalog.endpoint_permission_role_matrix ?? []).find((item) =>
  normalizeUri(item.uri) === '/api/v1/scan/classify'
  && toList(item.methods).map((method) => method.toUpperCase()).includes('POST')
);
const expectedRoles = ['WAREHOUSE_MANAGER'];

const flattenPostman = (items, output = []) => {
  for (const item of items ?? []) {
    if (item.request) {
      const rawUrl = typeof item.request.url === 'string' ? item.request.url : item.request.url?.raw;
      output.push({
        name: item.name,
        method: String(item.request.method ?? '').toUpperCase(),
        path: normalizeUri(String(rawUrl ?? '').replace(/^\{\{baseUrl\}\}/, '')),
        headers: (item.request.header ?? []).map((header) => String(header.key).toLowerCase()),
        scripts: (item.event ?? []).flatMap((event) => event.script?.exec ?? []).join('\n'),
      });
    }
    flattenPostman(item.item, output);
  }
  return output;
};
const postmanRows = flattenPostman(postman.item);
const scanRequests = postmanRows.filter((request) => request.path === '/api/v1/scan/classify' && request.method === 'POST');
const scanScripts = scanRequests.map((request) => request.scripts).join('\n');
const testCaseIds = new Set((testCases.test_cases ?? []).map((testCase) => testCase.id));
const requiredTestCases = [
  'SCAN-TC-001', 'SCAN-TC-002', 'SCAN-TC-003', 'SCAN-TC-004',
  'SCAN-TC-005', 'SCAN-TC-006', 'SCAN-TC-007', 'SCAN-TC-008',
];

const sourcePaths = new Set((sourceRegister.repository_sources ?? []).map((item) => item.path));
const requiredSources = [
  'Modules/Warehouse/app/Http/Controllers/ScanController.php',
  'Modules/Warehouse/app/Services/ScanClassificationService.php',
  'tests/Feature/ScanClassifyReadOnlyContractTest.php',
  'docs/postman/WWHN-150 Scan ReadOnly Contract.postman_collection.json',
  'docs/postman/WWHN-150 Scan ReadOnly CI.postman_environment.json',
  'docs/api-contract/WWHN-150-scan-openapi.yaml',
];

const bannedWritePatterns = [
  /IdempotencyStore/,
  /StockMovement::/,
  /InventoryBalance::/,
  /->insert\s*\(/,
  /->update\s*\(/,
  /->delete\s*\(/,
  /::create\s*\(/,
  /firstOrCreate\s*\(/,
  /updateOrCreate\s*\(/,
  /->save\s*\(/,
];
const implementationSource = `${controller}\n${service}`;
const matchedWritePatterns = bannedWritePatterns
  .filter((pattern) => pattern.test(implementationSource))
  .map((pattern) => pattern.toString());

const description = `${spec?.summary ?? ''} ${spec?.description ?? ''}`;
const checks = {
  runtime_route_unique: scanRoutes.length === 1,
  runtime_auth_middleware: Boolean(scanRoutes[0]?.middleware.some((item) => item === 'auth:api' || item.includes('auth:api'))),
  runtime_permission_middleware: Boolean(scanRoutes[0]?.middleware.some((item) => item === 'permission:scan.classify' || item.includes('permission:scan.classify'))),
  openapi_operation_present: Boolean(spec),
  openapi_bearer_security: hasBearer,
  openapi_responses_complete: ['200', '401', '403', '404', '409', '422'].every((code) => responseCodes.has(code)),
  openapi_idempotency_not_required: !requiredHeader('Idempotency-Key'),
  openapi_read_only_statement: /stock_effect.*NONE/i.test(description)
    && /không tạo.*(movement|posting_event|idempotency)/i.test(description),
  standalone_openapi_present: /\/api\/v1\/scan\/classify/.test(standaloneOpenapi)
    && /stock_effect/.test(standaloneOpenapi)
    && /posting_event_created/.test(standaloneOpenapi),
  permission_active: permission?.status === 'ACTIVE',
  permission_source_hash_pass: catalog.integrity?.status === 'PASS'
    && Boolean(catalog.source_hashes?.permission_seed?.sha256)
    && Boolean(catalog.source_hashes?.warehouse_routes?.sha256),
  endpoint_permission_matrix: matrix?.permission === 'scan.classify'
    && expectedRoles.every((role) => (matrix.roles ?? []).includes(role)),
  postman_success_and_replay_requests: scanRequests.length >= 5
    && scanRequests.some((request) => /same key/i.test(request.name))
    && scanRequests.some((request) => /unknown/i.test(request.name))
    && scanRequests.some((request) => /permission/i.test(request.name)),
  postman_read_only_assertions: /stock_effect/.test(scanScripts)
    && /movement_created/.test(scanScripts)
    && /posting_event_created/.test(scanScripts)
    && /idempotency_cache_written/.test(scanScripts),
  test_case_matrix_complete: requiredTestCases.every((id) => testCaseIds.has(id)),
  source_register_proposed: sourceRegister.status === 'PROPOSED'
    && sourceRegister.approval?.decision === 'PROPOSED'
    && Array.isArray(sourceRegister.approval?.reviewers)
    && sourceRegister.approval.reviewers.length === 0,
  source_register_complete: requiredSources.every((path) => sourcePaths.has(path)),
  implementation_static_write_guard: matchedWritePatterns.length === 0,
  implementation_explicit_flags: /stock_effect/.test(controller + service)
    && /posting_event_created/.test(controller + service)
    && /idempotency_cache_written/.test(controller + service),
};

const failedChecks = Object.entries(checks).filter(([, passed]) => !passed).map(([name]) => name);
const output = {
  schema_version: '1.0.0',
  contract: 'WWHN-150 BE1 scan read-only and evidence closure',
  generated_at_utc: new Date().toISOString(),
  approval_status: 'PENDING_INDEPENDENT_SIGN_OFF',
  summary: {
    checked: Object.keys(checks).length,
    passed: Object.keys(checks).length - failedChecks.length,
    failed: failedChecks.length,
    status: failedChecks.length === 0 ? 'PASS' : 'FAIL',
  },
  checks,
  failed_checks: failedChecks,
  matched_write_patterns: matchedWritePatterns,
};

fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);
if (failedChecks.length > 0) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}
console.log(`WWHN-150 contract verification PASS: ${output.summary.passed}/${output.summary.checked}`);
