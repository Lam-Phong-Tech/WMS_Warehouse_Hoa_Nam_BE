import fs from 'node:fs';

const [openapiPath, routesPath, catalogPath, outputPath] = process.argv.slice(2);
if (!openapiPath || !routesPath || !catalogPath || !outputPath) {
  throw new Error('Thiếu tham số verify-api-data-contract');
}

const openapi = JSON.parse(fs.readFileSync(openapiPath, 'utf8'));
const routes = JSON.parse(fs.readFileSync(routesPath, 'utf8'));
const catalog = JSON.parse(fs.readFileSync(catalogPath, 'utf8'));

const critical = [
  ['post', '/api/v1/inbound-documents/{id}/scan', 'stock_document.scan'],
  ['post', '/api/v1/outbound-documents/{id}/scan', 'stock_document.scan'],
  ['post', '/api/v1/inbound-documents/{id}/post-receipt', 'stock_document.post'],
  ['post', '/api/v1/outbound-documents/{id}/post-issue', 'stock_document.post'],
  ['get', '/api/v1/reports/inventory-reconciliation', 'report.view'],
];

const normalizeUri = (uri) => `/${uri}`.replaceAll(/\/+/g, '/');
const routeRows = routes.map((route) => ({
  methods: (route.method || route.methods || '').toString().split('|').map((m) => m.toUpperCase()),
  uri: normalizeUri(route.uri),
  middleware: Array.isArray(route.middleware) ? route.middleware : [],
}));
const permissionKeys = new Set(catalog.permissions.map((item) => item.permission_key));
const findings = [];

for (const [method, path, permission] of critical) {
  const runtime = routeRows.find((route) => route.uri === path && route.methods.includes(method.toUpperCase()));
  const spec = openapi.paths?.[path]?.[method];
  const catalogRoute = catalog.route_permissions.find((route) => normalizeUri(route.uri) === path && route.methods.includes(method.toUpperCase()));

  findings.push({
    method: method.toUpperCase(),
    path,
    expected_permission: permission,
    runtime_route_present: Boolean(runtime),
    openapi_operation_present: Boolean(spec),
    permission_catalog_present: permissionKeys.has(permission),
    route_permission_matches: catalogRoute?.permission === permission,
    result: Boolean(runtime && spec && permissionKeys.has(permission) && catalogRoute?.permission === permission) ? 'PASS' : 'FAIL',
  });
}

const failed = findings.filter((item) => item.result === 'FAIL');
const output = {
  schema_version: '1.0.0',
  generated_at_utc: new Date().toISOString(),
  approval_status: 'PENDING_INDEPENDENT_SIGN_OFF',
  summary: { checked: findings.length, passed: findings.length - failed.length, failed: failed.length },
  findings,
};

fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);
if (failed.length > 0) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}
console.log(`Contract verification PASS: ${findings.length}/${findings.length}`);
