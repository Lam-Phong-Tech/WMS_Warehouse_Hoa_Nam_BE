import fs from 'node:fs';

const [openapiPath, routesPath, catalogPath, postmanPath, outputPath] = process.argv.slice(2);
if (!openapiPath || !routesPath || !catalogPath || !postmanPath || !outputPath) {
  throw new Error('Thiếu tham số verify-api-data-contract');
}

const openapi = JSON.parse(fs.readFileSync(openapiPath, 'utf8'));
const routes = JSON.parse(fs.readFileSync(routesPath, 'utf8'));
const catalog = JSON.parse(fs.readFileSync(catalogPath, 'utf8'));
const postman = JSON.parse(fs.readFileSync(postmanPath, 'utf8'));

const critical = [
  { method: 'post', path: '/api/v1/inbound-documents/{id}/scan', permission: 'stock_document.scan', stockEffectNone: true },
  { method: 'post', path: '/api/v1/outbound-documents/{id}/scan', permission: 'stock_document.scan', stockEffectNone: true },
  { method: 'post', path: '/api/v1/inbound-documents/{id}/post-receipt', permission: 'stock_document.post', idempotencyRequired: true },
  { method: 'post', path: '/api/v1/outbound-documents/{id}/post-issue', permission: 'stock_document.post', idempotencyRequired: true },
  { method: 'get', path: '/api/v1/reports/inventory-reconciliation', permission: 'report.view' },
];

const normalizeUri = (uri) => `/${String(uri).replace(/^\/+/, '')}`
  .replaceAll(/\/+/g, '/')
  .replace(/\?.*$/, '')
  .replace(/\{\{[^}]*docid[^}]*\}\}/gi, '{id}');

const toList = (value) => Array.isArray(value)
  ? value.map(String)
  : String(value ?? '').split(/[|,]/).map((item) => item.trim()).filter(Boolean);

const routeRows = routes.map((route) => ({
  methods: toList(route.method || route.methods).map((method) => method.toUpperCase()),
  uri: normalizeUri(route.uri),
  middleware: toList(route.middleware),
}));

const permissionRows = new Map(catalog.permissions.map((item) => [item.permission_key, item]));
const catalogRoutes = catalog.route_permissions.map((route) => ({
  ...route,
  methods: toList(route.methods).map((method) => method.toUpperCase()),
  uri: normalizeUri(route.uri),
}));

const flattenPostman = (items, output = []) => {
  for (const item of items ?? []) {
    if (item.request) {
      const rawUrl = typeof item.request.url === 'string' ? item.request.url : item.request.url?.raw;
      const scripts = (item.event ?? [])
        .flatMap((event) => event.script?.exec ?? [])
        .join('\n');
      output.push({
        name: item.name,
        method: String(item.request.method ?? '').toUpperCase(),
        path: normalizeUri(String(rawUrl ?? '').replace(/^\{\{baseUrl\}\}/, '')),
        headers: (item.request.header ?? []).map((header) => String(header.key).toLowerCase()),
        scripts,
      });
    }
    flattenPostman(item.item, output);
  }
  return output;
};

const postmanRows = flattenPostman(postman.item);
const responseCodes = (spec) => new Set(Object.keys(spec?.responses ?? {}).map(String));
const requiredHeader = (spec, name) => (spec?.parameters ?? []).some((parameter) =>
  String(parameter?.in).toLowerCase() === 'header'
  && String(parameter?.name).toLowerCase() === name.toLowerCase()
  && parameter?.required === true
);
const hasBearerSecurity = (spec) => (spec?.security ?? []).some((entry) => Object.hasOwn(entry, 'bearerAuth'));

const findings = [];
for (const rule of critical) {
  const method = rule.method.toUpperCase();
  const runtimeMatches = routeRows.filter((route) => route.uri === rule.path && route.methods.includes(method));
  const runtime = runtimeMatches[0];
  const spec = openapi.paths?.[rule.path]?.[rule.method];
  const catalogMatches = catalogRoutes.filter((route) => route.uri === rule.path && route.methods.includes(method));
  const catalogRoute = catalogMatches[0];
  const postmanMatches = postmanRows.filter((request) => request.path === rule.path && request.method === method);
  const permission = permissionRows.get(rule.permission);
  const responses = responseCodes(spec);
  const requiredResponses = rule.idempotencyRequired ? ['200', '403', '409', '422', '428'] : ['200', '403'];
  const openapiDescription = `${spec?.summary ?? ''} ${spec?.description ?? ''}`;
  const postmanScripts = postmanMatches.map((request) => request.scripts).join('\n');
  const postmanHasIdempotencyHeader = postmanMatches.some((request) => request.headers.includes('idempotency-key'));

  const checks = {
    runtime_route_unique: runtimeMatches.length === 1,
    runtime_auth_middleware: Boolean(runtime?.middleware.some((item) => item === 'auth:api' || item.includes('auth:api'))),
    runtime_permission_middleware: Boolean(runtime?.middleware.some((item) => item === `permission:${rule.permission}` || item.includes(`permission:${rule.permission}`))),
    openapi_operation_present: Boolean(spec),
    openapi_bearer_security: hasBearerSecurity(spec),
    openapi_required_responses: requiredResponses.every((code) => responses.has(code)),
    permission_catalog_active: permission?.status === 'ACTIVE',
    catalog_route_unique: catalogMatches.length === 1,
    catalog_route_permission: catalogRoute?.permission === rule.permission,
    postman_request_present: postmanMatches.length > 0,
    postman_contract_assertions: rule.stockEffectNone
      ? /stock_effect/.test(postmanScripts) && /movement_created/.test(postmanScripts)
      : rule.idempotencyRequired
        ? postmanHasIdempotencyHeader && /428/.test(postmanScripts) && /IDEMPOTENCY_ERROR/.test(postmanScripts)
        : /403/.test(postmanScripts) && /reconcil/i.test(postmanScripts),
    idempotency_header_contract: rule.idempotencyRequired
      ? requiredHeader(spec, 'Idempotency-Key') && postmanHasIdempotencyHeader
      : !requiredHeader(spec, 'Idempotency-Key'),
    scan_stock_effect_contract: rule.stockEffectNone
      ? /stock_effect\s*=\s*NONE|stock_effect.*NONE|không (ghi|tạo).*movement/i.test(openapiDescription)
      : true,
  };

  const failedChecks = Object.entries(checks).filter(([, passed]) => !passed).map(([name]) => name);
  findings.push({
    method,
    path: rule.path,
    expected_permission: rule.permission,
    checks,
    failed_checks: failedChecks,
    result: failedChecks.length === 0 ? 'PASS' : 'FAIL',
  });
}

const failed = findings.filter((item) => item.result === 'FAIL');
const output = {
  schema_version: '2.0.0',
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
