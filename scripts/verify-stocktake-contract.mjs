#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';

const [openApiPath, routePath, postmanPath, testCasePath, sourceRegisterPath, outputPath] = process.argv.slice(2);
if (![openApiPath, routePath, postmanPath, testCasePath, sourceRegisterPath, outputPath].every(Boolean)) {
  console.error('Usage: verify-stocktake-contract.mjs <openapi> <routes> <postman> <test-cases> <source-register> <output>');
  process.exit(2);
}

const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'));
const readText = (file) => fs.readFileSync(path.resolve(file), 'utf8');
const openapi = readJson(openApiPath);
const routes = readJson(routePath);
const postman = readJson(postmanPath);
const testCases = readJson(testCasePath);
const sourceRegister = readJson(sourceRegisterPath);

const failures = [];
const passes = [];
const check = (condition, id, message, details = undefined) => {
  (condition ? passes : failures).push({ id, message, ...(details === undefined ? {} : { details }) });
};

const requiredOperations = [
  ['get', '/api/v1/stocktake-sessions', 'stocktake_session_list', 'inventory.view'],
  ['post', '/api/v1/stocktake-sessions', 'stocktake_session_create', 'stocktake.adjust'],
  ['get', '/api/v1/stocktake-sessions/{id}', 'stocktake_session_detail', 'inventory.view'],
  ['post', '/api/v1/stocktake-sessions/{id}/start', 'stocktake_session_start', 'stocktake.adjust'],
  ['post', '/api/v1/stocktake-sessions/{id}/counts', 'stocktake_count_record', 'stocktake.adjust'],
  ['post', '/api/v1/stocktake-sessions/{id}/review', 'stocktake_session_review', 'stocktake.adjust'],
  ['post', '/api/v1/stocktake-sessions/{id}/acknowledge-movements', 'stocktake_acknowledge_movements', 'stocktake.adjust'],
  ['get', '/api/v1/stocktake-sessions/{id}/variances', 'stocktake_variance_list', 'inventory.view'],
  ['post', '/api/v1/stocktake-sessions/{id}/apply-adjustment', 'stocktake_apply_adjustment', 'stocktake.adjust'],
];

for (const [method, apiPath, operationId] of requiredOperations) {
  const operation = openapi.paths?.[apiPath]?.[method];
  check(Boolean(operation), `openapi:${operationId}`, `${method.toUpperCase()} ${apiPath} exists`);
  if (operation) {
    check(operation.operationId === operationId, `operation-id:${operationId}`, `${apiPath} operationId is exact`);
    check(Boolean(operation.security?.length), `security:${operationId}`, `${apiPath} declares bearer security`);
  }
}

const applyOperation = openapi.paths?.['/api/v1/stocktake-sessions/{id}/apply-adjustment']?.post;
const idempotencyParameter = applyOperation?.parameters?.find((parameter) => parameter.name === 'Idempotency-Key');
check(idempotencyParameter?.in === 'header' && idempotencyParameter?.required === true, 'openapi:apply-idempotency-header', 'Apply declares required Idempotency-Key header');
check(idempotencyParameter?.schema?.maxLength === 100, 'openapi:apply-idempotency-max', 'Idempotency-Key maxLength is 100');
for (const status of ['200', '403', '409', '422', '428']) {
  check(Boolean(applyOperation?.responses?.[status]), `openapi:apply-response-${status}`, `Apply documents HTTP ${status}`);
}
const applySchema = applyOperation?.requestBody?.content?.['application/json']?.schema;
const applyRequired = new Set(applySchema?.required ?? []);
for (const field of ['session_version', 'variance_ids', 'reason']) {
  check(applyRequired.has(field), `openapi:apply-required-${field}`, `Apply requires ${field}`);
}
check(!('expected_qty' in (applySchema?.properties ?? {})), 'openapi:no-client-expected', 'Apply does not accept client expected_qty');
check(!('inventory_effect_qty' in (applySchema?.properties ?? {})), 'openapi:no-client-effect', 'Apply does not accept client inventory_effect_qty');

const normalizedRoutes = (Array.isArray(routes) ? routes : routes.routes ?? []).map((route) => ({
  uri: String(route.uri ?? '').replace(/^\//, ''),
  methods: Array.isArray(route.methods) ? route.methods : String(route.method ?? '').split('|'),
  middleware: Array.isArray(route.middleware) ? route.middleware.map(String) : String(route.middleware ?? '').split(','),
}));

for (const [method, apiPath, operationId, expectedPermission] of requiredOperations) {
  const uri = apiPath.replace(/^\//, '');
  const match = normalizedRoutes.find((route) => route.uri === uri && route.methods.includes(method.toUpperCase()));
  check(Boolean(match), `route:${operationId}`, `${method.toUpperCase()} ${uri} exists in runtime route list`);
  if (match) {
    check(match.middleware.some((value) => value.includes('auth:api')), `route-auth:${operationId}`, `${uri} uses auth:api`);
    check(
      match.middleware.some((value) => value.includes(`permission:${expectedPermission}`)),
      `route-permission:${operationId}`,
      `${uri} uses permission:${expectedPermission}`,
    );
  }
}
const legacyRoute = normalizedRoutes.find((route) => route.uri === 'api/v1/stocktake/sessions' && route.methods.includes('GET'));
check(Boolean(legacyRoute), 'route:legacy-list', 'Legacy stocktake/sessions alias exists');
if (legacyRoute) {
  check(legacyRoute.middleware.some((value) => value.includes('auth:api')), 'route:legacy-auth', 'Legacy alias uses auth:api');
  check(legacyRoute.middleware.some((value) => value.includes('permission:inventory.view')), 'route:legacy-permission', 'Legacy alias uses inventory.view');
}

const flattenPostman = (items, output = []) => {
  for (const item of items ?? []) {
    if (item.request) output.push(item);
    flattenPostman(item.item, output);
  }
  return output;
};
const postmanItems = flattenPostman(postman.item);
const postmanText = JSON.stringify(postmanItems);
for (const fragment of [
  '/stocktake-sessions',
  '/start',
  '/counts',
  '/review',
  '/apply-adjustment',
  'Idempotency-Key',
  'PRECONDITION_REQUIRED',
  'IDEMPOTENCY_ERROR',
  'request_hash',
]) {
  check(postmanText.includes(fragment), `postman:${fragment}`, `Postman contains ${fragment}`);
}
check(postman.info?.version === '1.0.0', 'postman:version', 'Postman collection version is 1.0.0');

const requiredCaseIds = Array.from({ length: 14 }, (_, index) => `STK-TC-${String(index + 1).padStart(3, '0')}`);
const actualCaseIds = new Set((testCases.cases ?? []).map((testCase) => testCase.id));
for (const id of requiredCaseIds) {
  check(actualCaseIds.has(id), `test-case:${id}`, `${id} is registered`);
}
check(testCases.version === '1.2.0', 'test-case:version', 'Test-case register version is 1.2.0');
check(testCases.status === 'PROPOSED', 'test-case:status', 'Test-case register remains PROPOSED pending independent approval');
check(testCases.signed_qty_delta_convention?.net_formula === 'SUM(stock_movements.qty_delta)', 'ledger:formula', 'Signed ledger formula is canonical SUM(qty_delta)');
check(
  String(testCases.signed_qty_delta_convention?.zero_effect_rule ?? '').includes('Không tạo stock movement'),
  'ledger:zero-effect',
  'Zero-effect rule forbids empty stock movements',
);
check(
  String(testCases.signed_qty_delta_convention?.pre_apply_reconciliation ?? '').includes('SUM(stock_movements.qty_delta)'),
  'ledger:pre-apply-reconciliation',
  'Pre-apply reconciliation requires signed ledger to equal balance',
);
check(
  testCases.independent_review_required?.protocol === 'docs/evidence/WWHN-131/INDEPENDENT_REVIEW_AND_DECISION_PROTOCOL.md',
  'test-case:review-protocol',
  'Independent review protocol is registered',
);

check(sourceRegister.register_id === 'WWHN-131-SOURCE-REGISTER', 'source-register:id', 'Source Register ID is exact');
check(sourceRegister.version === '1.2.0', 'source-register:version', 'Source Register version is 1.2.0');
check(sourceRegister.status === 'PROPOSED', 'source-register:status', 'Source Register remains PROPOSED');
check(sourceRegister.branch === 'm1', 'source-register:branch', 'Source Register is bound to m1');
check(sourceRegister.artifact_name_pattern === 'stocktake-evidence-<exact_commit_sha>', 'source-register:artifact', 'Artifact pattern is exact-SHA bound');
check(sourceRegister.exact_commit_source === 'build-metadata.json.commit_sha', 'source-register:exact-sha', 'Exact SHA source is build metadata');
check(sourceRegister.hash_manifest === 'SHA256SUMS', 'source-register:manifest', 'Source Register declares SHA256SUMS');
check(sourceRegister.hash_manifest_digest === 'SHA256SUMS.sha256', 'source-register:manifest-digest', 'Source Register declares manifest digest');

const requiredRegisterPaths = [
  '.github/workflows/wwhn-131-stocktake-contract.yml',
  'scripts/collect-wwhn131-evidence.sh',
  'scripts/verify-stocktake-contract.mjs',
  'scripts/verify-wwhn131-schema.php',
  'tests/Feature/StocktakeThreeLayerContractTest.php',
  'tests/Feature/StocktakeApplyLedgerIntegrityTest.php',
  'tests/Feature/StocktakeReviewGateTest.php',
  'docs/evidence/WWHN-131/INDEPENDENT_REVIEW_AND_DECISION_PROTOCOL.md',
];
const registerPaths = new Set((sourceRegister.repository_sources ?? []).map((source) => source.path));
for (const requiredPath of requiredRegisterPaths) {
  check(registerPaths.has(requiredPath), `source-register:path:${requiredPath}`, `Source Register contains ${requiredPath}`);
}

const sourceChecks = [
  ['Modules/Warehouse/app/Services/StocktakeAdjustmentService.php', 'stocktake_apply_adjustment_'],
  ['Modules/Warehouse/app/Services/StocktakeAdjustmentService.php', 'MOVEMENT_ACKNOWLEDGEMENT_REQUIRED'],
  ['Modules/Warehouse/app/Services/StocktakeAdjustmentService.php', 'LEDGER_BALANCE_MISMATCH'],
  ['Modules/Warehouse/app/Services/StocktakeAdjustmentService.php', 'zero_effect_dimensions'],
  ['Modules/Warehouse/app/Services/StocktakeAdjustmentService.php', "where('session_id', $session->session_id)"],
  ['Modules/Warehouse/app/Services/StocktakeService.php', 'SUM(qty_delta)'],
  ['Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php', 'uk_stocktake_count_session_grain'],
  ['Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php', 'uk_posting_event_operation_idem'],
  ['Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php', 'assertRequiredGrain'],
  ['tests/Feature/StocktakeThreeLayerContractTest.php', 'test_apply_rolls_back_balance_movement_and_item_when_container_layer_fails'],
  ['tests/Feature/StocktakeThreeLayerContractTest.php', 'test_warehouse_data_scope_blocks_create_read_and_apply'],
  ['tests/Feature/StocktakeApplyLedgerIntegrityTest.php', 'test_zero_effect_apply_does_not_create_empty_movement_or_increment_balance_version'],
  ['tests/Feature/StocktakeApplyLedgerIntegrityTest.php', 'test_apply_rejects_ledger_balance_mismatch_and_rolls_back_operation'],
  ['tests/Feature/StocktakeReviewGateTest.php', 'test_committed_replay_checks_warehouse_scope_before_returning_snapshot'],
  ['tests/Feature/StocktakeReviewGateTest.php', 'test_different_key_cannot_apply_same_reviewed_session_twice'],
  ['tests/Feature/StocktakeReviewGateTest.php', 'test_all_write_lifecycle_endpoints_reject_cross_warehouse_actor_without_state_change'],
  ['scripts/verify-wwhn131-schema.php', 'uk_posting_event_operation_idem'],
  ['scripts/collect-wwhn131-evidence.sh', 'SHA256SUMS.sha256'],
  ['.github/workflows/wwhn-131-stocktake-contract.yml', 'stocktake-evidence-${{ github.sha }}'],
  ['docs/evidence/WWHN-131/INDEPENDENT_REVIEW_AND_DECISION_PROTOCOL.md', 'DEC-STK-001'],
];
for (const [file, fragment] of sourceChecks) {
  const content = readText(file);
  check(content.includes(fragment), `source:${file}:${fragment}`, `${file} contains required guard ${fragment}`);
}

const adjustmentSource = readText('Modules/Warehouse/app/Services/StocktakeAdjustmentService.php');
const scopeLockPosition = adjustmentSource.indexOf('$session = $this->guard->lockScoped($sessionId, $user);');
const eventLookupPosition = adjustmentSource.indexOf("$event = DB::table('posting_event')");
check(
  scopeLockPosition >= 0 && eventLookupPosition >= 0 && scopeLockPosition < eventLookupPosition,
  'source:scope-before-replay',
  'Warehouse scope is enforced before committed response replay lookup',
  { scopeLockPosition, eventLookupPosition },
);
check(
  adjustmentSource.includes('$requestedIds !== $allIds'),
  'source:exact-variance-set',
  'Apply enforces exact requested variance set',
);
check(
  adjustmentSource.includes("->where('session_id', $session->session_id)") && adjustmentSource.includes("->where('status', StocktakeVariance::STATUS_APPROVED)"),
  'source:variance-session-status-filter',
  'Apply loads variances by exact session and APPROVED status',
);

const result = {
  schema_version: '1.2.0',
  document_status: 'PROPOSED',
  generated_at_utc: new Date().toISOString(),
  inputs: {
    openapi: openApiPath,
    routes: routePath,
    postman: postmanPath,
    test_cases: testCasePath,
    source_register: sourceRegisterPath,
  },
  summary: {
    passed: passes.length,
    failed: failures.length,
    status: failures.length === 0 ? 'PASS' : 'FAIL',
  },
  passes,
  failures,
};
fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`);
console.log(JSON.stringify(result.summary));
if (failures.length > 0) process.exit(1);
