#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';

const [openApiPath, routePath, postmanPath, testCasePath, outputPath] = process.argv.slice(2);
if (![openApiPath, routePath, postmanPath, testCasePath, outputPath].every(Boolean)) {
  console.error('Usage: verify-stocktake-contract.mjs <openapi> <routes> <postman> <test-cases> <output>');
  process.exit(2);
}

const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'));
const openapi = readJson(openApiPath);
const routes = readJson(routePath);
const postman = readJson(postmanPath);
const testCases = readJson(testCasePath);

const failures = [];
const passes = [];
const check = (condition, id, message) => {
  (condition ? passes : failures).push({ id, message });
};

const requiredOperations = [
  ['post', '/api/v1/stocktake-sessions', 'stocktake_session_create'],
  ['post', '/api/v1/stocktake-sessions/{id}/start', 'stocktake_session_start'],
  ['post', '/api/v1/stocktake-sessions/{id}/counts', 'stocktake_count_record'],
  ['post', '/api/v1/stocktake-sessions/{id}/review', 'stocktake_session_review'],
  ['post', '/api/v1/stocktake-sessions/{id}/acknowledge-movements', 'stocktake_acknowledge_movements'],
  ['get', '/api/v1/stocktake-sessions/{id}/variances', 'stocktake_variance_list'],
  ['post', '/api/v1/stocktake-sessions/{id}/apply-adjustment', 'stocktake_apply_adjustment'],
];

for (const [method, apiPath, operationId] of requiredOperations) {
  const operation = openapi.paths?.[apiPath]?.[method];
  check(Boolean(operation), `openapi:${operationId}`, `${method.toUpperCase()} ${apiPath} exists`);
  if (operation) {
    check(operation.operationId === operationId, `operation-id:${operationId}`, `${apiPath} operationId is exact`);
    check(Boolean(operation.security?.length), `security:${operationId}`, `${apiPath} declares bearer security`);
  }
}

const normalizedRoutes = (Array.isArray(routes) ? routes : routes.routes ?? []).map((route) => ({
  uri: String(route.uri ?? '').replace(/^\//, ''),
  methods: Array.isArray(route.methods) ? route.methods : String(route.method ?? '').split('|'),
  middleware: Array.isArray(route.middleware) ? route.middleware.map(String) : String(route.middleware ?? '').split(','),
}));

for (const [method, apiPath, operationId] of requiredOperations) {
  const uri = apiPath.replace(/^\//, '').replaceAll('{id}', '{id}');
  const match = normalizedRoutes.find((route) => route.uri === uri && route.methods.includes(method.toUpperCase()));
  check(Boolean(match), `route:${operationId}`, `${method.toUpperCase()} ${uri} exists in runtime route list`);
  if (match) {
    check(match.middleware.some((value) => value.includes('auth:api')), `route-auth:${operationId}`, `${uri} uses auth:api`);
    const expectedPermission = method === 'get' ? 'inventory.view' : 'stocktake.adjust';
    check(
      match.middleware.some((value) => value.includes(`permission:${expectedPermission}`)),
      `route-permission:${operationId}`,
      `${uri} uses permission:${expectedPermission}`,
    );
  }
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
]) {
  check(postmanText.includes(fragment), `postman:${fragment}`, `Postman contains ${fragment}`);
}
check(postman.info?.version === '1.0.0', 'postman:version', 'Postman collection version is 1.0.0');

const requiredCaseIds = Array.from({ length: 11 }, (_, index) => `STK-TC-${String(index + 1).padStart(3, '0')}`);
const actualCaseIds = new Set((testCases.cases ?? []).map((testCase) => testCase.id));
for (const id of requiredCaseIds) {
  check(actualCaseIds.has(id), `test-case:${id}`, `${id} is registered`);
}
check(testCases.version === '1.1.0', 'test-case:version', 'Test-case register version is 1.1.0');
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

const sourceChecks = [
  ['Modules/Warehouse/app/Services/StocktakeAdjustmentService.php', 'stocktake_apply_adjustment_'],
  ['Modules/Warehouse/app/Services/StocktakeAdjustmentService.php', 'MOVEMENT_ACKNOWLEDGEMENT_REQUIRED'],
  ['Modules/Warehouse/app/Services/StocktakeAdjustmentService.php', 'LEDGER_BALANCE_MISMATCH'],
  ['Modules/Warehouse/app/Services/StocktakeAdjustmentService.php', 'zero_effect_dimensions'],
  ['Modules/Warehouse/app/Services/StocktakeService.php', 'SUM(qty_delta)'],
  ['Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php', 'uk_stocktake_count_session_grain'],
  ['Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php', 'uk_posting_event_operation_idem'],
  ['Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php', 'assertRequiredGrain'],
  ['tests/Feature/StocktakeThreeLayerContractTest.php', 'test_apply_rolls_back_balance_movement_and_item_when_container_layer_fails'],
  ['tests/Feature/StocktakeThreeLayerContractTest.php', 'test_warehouse_data_scope_blocks_create_read_and_apply'],
  ['tests/Feature/StocktakeApplyLedgerIntegrityTest.php', 'test_zero_effect_apply_does_not_create_empty_movement_or_increment_balance_version'],
  ['tests/Feature/StocktakeApplyLedgerIntegrityTest.php', 'test_apply_rejects_ledger_balance_mismatch_and_rolls_back_operation'],
];
for (const [file, fragment] of sourceChecks) {
  const content = fs.readFileSync(path.resolve(file), 'utf8');
  check(content.includes(fragment), `source:${file}:${fragment}`, `${file} contains required guard ${fragment}`);
}

const result = {
  schema_version: '1.1.0',
  document_status: 'PROPOSED',
  generated_at_utc: new Date().toISOString(),
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
