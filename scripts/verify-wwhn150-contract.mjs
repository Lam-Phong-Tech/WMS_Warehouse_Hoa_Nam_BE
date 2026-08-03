import fs from 'node:fs';
import crypto from 'node:crypto';

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
const changelog = fs.readFileSync('docs/api-contract/WWHN-150_OpenAPI_Changelog_BE1.md', 'utf8');
const postmanEnvironment = JSON.parse(fs.readFileSync(
  'docs/postman/WWHN-150 Scan ReadOnly CI.postman_environment.json',
  'utf8',
));

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
const successSchema = spec?.responses?.['200']?.content?.['application/json']?.schema;
const successData = successSchema?.properties?.data;
const apiErrorSchema = openapi.components?.schemas?.Wwhn150ApiError;
const errorResponseRefs = ['401', '403', '404', '409', '422'].map(
  (code) => spec?.responses?.[code]?.content?.['application/json']?.schema?.$ref,
);
const requiredErrorFields = ['success', 'message', 'error_code', 'errors', 'error', 'meta'];
const acceptsObjectOrArray = (schema) => ['object', 'array'].every((type) =>
  (schema?.oneOf ?? []).some((candidate) => candidate?.type === type)
);

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
  'SCAN-TC-009', 'SCAN-TC-010', 'SCAN-TC-011', 'SCAN-TC-012',
  'SCAN-TC-013',
  'SCAN-TC-014',
];

const sourcePaths = new Set((sourceRegister.repository_sources ?? []).map((item) => item.path));
const requiredSources = [
  'Modules/Warehouse/app/Http/Controllers/ScanController.php',
  'Modules/Warehouse/app/Services/ScanClassificationService.php',
  'tests/Feature/ScanClassifyReadOnlyContractTest.php',
  'tests/Feature/BackendMigrationSeedHealthTest.php',
  'docs/postman/WWHN-150 Scan ReadOnly Contract.postman_collection.json',
  'docs/postman/WWHN-150 Scan ReadOnly CI.postman_environment.json',
  'docs/api-contract/WWHN-150-scan-openapi.yaml',
  'storage/api-docs/api-docs.json',
  'docs/api-contract/WWHN-150_OpenAPI_Changelog_BE1.md',
  '.github/workflows/auth-rbac-audit-contract.yml',
  'scripts/collect-api-data-evidence.sh',
  'scripts/verify-wwhn150-contract.mjs',
  'scripts/verify-wwhn150-boundary.mjs',
  'scripts/run-wwhn150-concurrent-scan.mjs',
  'Modules/Auth/database/seeders/AuthDatabaseSeeder.php',
  'app/Console/Commands/ExportPermissionCatalog.php',
  'Modules/Auth/app/Models/User.php',
  'docs/permissions/WMS_PERMISSION_CATALOG_BE1.json',
  'docs/permissions/WMS_PERMISSION_CATALOG_BE1.md',
  'Modules/Warehouse/app/Http/Controllers/ReportController.php',
  'Modules/Warehouse/app/Http/Controllers/InventorySnapshotController.php',
  'Modules/Warehouse/app/Services/InventorySnapshotService.php',
  'Modules/Warehouse/app/Models/InventoryReportSnapshot.php',
  'Modules/Warehouse/app/Http/Controllers/WarrantyController.php',
  'Modules/Warehouse/app/Models/WarrantyCase.php',
  'Modules/Warehouse/app/Models/WarrantyEvent.php',
  'Modules/Warehouse/database/migrations/2026_01_01_000044_create_hoanam_erd_extension_tables.php',
  'Modules/Warehouse/database/seeders/WarehouseDatabaseSeeder.php',
];
const passwordValues = (postmanEnvironment.values ?? [])
  .filter((item) => /password/i.test(String(item.key)))
  .map((item) => String(item.value ?? ''));
const catalogSourceHashesExact = Object.values(catalog.source_hashes ?? {}).every((source) =>
  typeof source?.path === 'string'
  && typeof source?.sha256 === 'string'
  && fs.existsSync(source.path)
  && crypto.createHash('sha256').update(fs.readFileSync(source.path)).digest('hex') === source.sha256
);

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
  openapi_permission_extension: spec?.['x-permission'] === 'scan.classify',
  openapi_success_schema_complete: ['raw_code', 'context', 'classification', 'stock_status',
    'resolved_source', 'contract_version', 'stock_effect',
    'movement_created', 'posting_event_created', 'idempotency_cache_written', 'current_location']
    .every((field) => (successData?.required ?? []).includes(field))
    && (successSchema?.required ?? []).includes('message')
    && successData?.properties?.contract_version?.enum?.includes('WWHN-150.1.1.0')
    && successData?.properties?.stock_effect?.enum?.includes('NONE')
    && successData?.properties?.movement_created?.enum?.includes(false)
    && successData?.properties?.posting_event_created?.enum?.includes(false)
    && successData?.properties?.idempotency_cache_written?.enum?.includes(false),
  openapi_error_envelope_complete: requiredErrorFields.every((field) =>
    (apiErrorSchema?.required ?? []).includes(field))
    && ['UNAUTHENTICATED', 'ACCESS_DENIED', 'WAREHOUSE_SCOPE_DENIED', 'WAREHOUSE_NOT_FOUND',
      'SCAN_CODE_NOT_FOUND', 'SCAN_WAREHOUSE_MISMATCH', 'SCAN_CONTEXT_CONFLICT', 'VALIDATION_ERROR']
      .every((code) => apiErrorSchema?.properties?.error_code?.enum?.includes(code))
    && acceptsObjectOrArray(apiErrorSchema?.properties?.errors)
    && acceptsObjectOrArray(apiErrorSchema?.properties?.error?.properties?.details)
    && errorResponseRefs.every((ref) => ref === '#/components/schemas/Wwhn150ApiError'),
  openapi_read_only_statement: /stock_effect.*NONE/i.test(description)
    && /không tạo.*(movement|posting_event|idempotency)/i.test(description),
  standalone_openapi_present: /\/api\/v1\/scan\/classify/.test(standaloneOpenapi)
    && /stock_effect/.test(standaloneOpenapi)
    && /posting_event_created/.test(standaloneOpenapi)
    && /x-permission:\s*scan\.classify/.test(standaloneOpenapi)
    && /version:\s*1\.1\.0/.test(standaloneOpenapi)
    && /required:\s*\[success, message, error_code, errors, error, meta\]/.test(standaloneOpenapi)
    && /required:\s*\[code, details\]/.test(standaloneOpenapi)
    && /required:\s*\[request_id, timestamp\]/.test(standaloneOpenapi),
  permission_active: permission?.status === 'ACTIVE',
  permission_source_hash_pass: catalog.integrity?.status === 'PASS'
    && catalogSourceHashesExact
    && Boolean(catalog.source_hashes?.permission_seed?.sha256)
    && Boolean(catalog.source_hashes?.warehouse_routes?.sha256)
    && Boolean(catalog.source_hashes?.permission_exporter?.sha256)
    && Boolean(catalog.source_hashes?.user_permission_model?.sha256),
  permission_seed_diff_pass: catalog.permission_seed_diff?.status === 'PASS'
    && catalog.permission_seed_diff?.actual?.permission_status === 'ACTIVE'
    && (catalog.permission_seed_diff?.actual?.roles ?? []).includes('WAREHOUSE_MANAGER')
    && (catalog.permission_seed_diff?.actual?.roles ?? []).includes('SUPER_ADMIN'),
  endpoint_permission_matrix: matrix?.permission === 'scan.classify'
    && expectedRoles.every((role) => (matrix.roles ?? []).includes(role)),
  postman_success_and_replay_requests: scanRequests.length >= 5
    && scanRequests.some((request) => /same key/i.test(request.name))
    && scanRequests.some((request) => /unknown/i.test(request.name))
    && scanRequests.some((request) => /permission/i.test(request.name))
    && scanRequests.some((request) => /mismatch.*409/i.test(request.name)),
  postman_read_only_assertions: /stock_effect/.test(scanScripts)
    && /contract_version/.test(scanScripts)
    && /resolved_source/.test(scanScripts)
    && /movement_created/.test(scanScripts)
    && /posting_event_created/.test(scanScripts)
    && /idempotency_cache_written/.test(scanScripts),
  test_case_matrix_complete: requiredTestCases.every((id) => testCaseIds.has(id)),
  version_alignment: testCases.version === '1.1.0'
    && sourceRegister.version === '1.1.0'
    && postman.info?.version?.major === 1
    && postman.info?.version?.minor === 1
    && /Version:\s*`1\.1\.0`/.test(changelog),
  postman_environment_secret_safe: passwordValues.length >= 2
    && passwordValues.every((value) => value === ''),
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
  schema_version: '1.1.0',
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
