import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const specPath = path.join(root, 'storage', 'api-docs', 'api-docs.json');
const expectedTag = '22. Mini App';

const expectedOperations = new Map([
  ['/api/v1/mini-app/printer-configs', ['get']],

  ['/api/v1/mini-app/inbound/resolve-code', ['post']],
  ['/api/v1/mini-app/inbound/record', ['post']],
  ['/api/v1/mini-app/inbound-documents', ['get', 'post']],
  ['/api/v1/mini-app/inbound-documents/{id}', ['get']],
  ['/api/v1/mini-app/inbound-documents/{id}/cancel', ['post']],
  ['/api/v1/mini-app/inbound-documents/{id}/boxes', ['post']],
  ['/api/v1/mini-app/inbound-documents/{id}/scan', ['post']],
  ['/api/v1/mini-app/inbound-documents/{id}/unscan', ['post']],
  ['/api/v1/mini-app/inbound-documents/{id}/scan-entries', ['get']],
  ['/api/v1/mini-app/inbound-documents/{id}/cartons/{containerId}/complete-scan', ['post']],
  ['/api/v1/mini-app/inbound-documents/{id}/post-receipt', ['post']],

  ['/api/v1/mini-app/outbound-documents', ['get']],
  ['/api/v1/mini-app/outbound-documents/{id}', ['get']],
  ['/api/v1/mini-app/outbound-documents/{id}/cancel', ['post']],
  ['/api/v1/mini-app/outbound-documents/{id}/scan', ['post']],
  ['/api/v1/mini-app/outbound-documents/{id}/unmatch', ['post']],
  ['/api/v1/mini-app/outbound-documents/{id}/scan-entries', ['get']],
  ['/api/v1/mini-app/outbound-documents/{id}/packing-label', ['get']],
  ['/api/v1/mini-app/outbound-documents/{id}/packing-labels/{labelId}/preview', ['post']],
  ['/api/v1/mini-app/outbound-documents/{id}/packing-labels/{labelId}/ready', ['post']],
  ['/api/v1/mini-app/outbound-documents/{id}/packing-labels/{labelId}/print', ['post']],
  ['/api/v1/mini-app/outbound-documents/{id}/packing-labels/{labelId}/reprint', ['post']],
  ['/api/v1/mini-app/outbound-documents/{id}/packing-labels/{labelId}/apply', ['post']],
  ['/api/v1/mini-app/outbound-documents/{id}/post-issue', ['post']],
]);

function fail(message) {
  console.error(`[mini-app-swagger] FAIL: ${message}`);
  process.exitCode = 1;
}

if (!fs.existsSync(specPath)) {
  fail(`Missing ${specPath}. Run: php artisan l5-swagger:generate`);
  process.exit();
}

let spec;
try {
  spec = JSON.parse(fs.readFileSync(specPath, 'utf8'));
} catch (error) {
  fail(`Cannot parse ${specPath}: ${error.message}`);
  process.exit();
}

const tags = Array.isArray(spec.tags) ? spec.tags : [];
if (!tags.some((tag) => tag?.name === expectedTag)) {
  fail(`Top-level Swagger tag "${expectedTag}" is missing.`);
}

const paths = spec.paths ?? {};

for (const [apiPath, methods] of expectedOperations) {
  const pathItem = paths[apiPath];
  if (!pathItem) {
    fail(`Missing path ${apiPath}`);
    continue;
  }

  for (const method of methods) {
    const operation = pathItem[method];
    if (!operation) {
      fail(`Missing ${method.toUpperCase()} ${apiPath}`);
      continue;
    }

    if (!Array.isArray(operation.tags) || !operation.tags.includes(expectedTag)) {
      fail(`${method.toUpperCase()} ${apiPath} is not grouped under "${expectedTag}"; tags=${JSON.stringify(operation.tags ?? [])}`);
    }
  }
}

for (const [apiPath, pathItem] of Object.entries(paths)) {
  if (!apiPath.startsWith('/api/v1/mini-app/')) {
    continue;
  }

  for (const method of ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace']) {
    const operation = pathItem?.[method];
    if (!operation) {
      continue;
    }

    if (!Array.isArray(operation.tags) || operation.tags.length !== 1 || operation.tags[0] !== expectedTag) {
      fail(`Unexpected tag for ${method.toUpperCase()} ${apiPath}: ${JSON.stringify(operation.tags ?? [])}`);
    }
  }
}

if (!process.exitCode) {
  console.log(`[mini-app-swagger] PASS: ${expectedOperations.size} Mini App paths are published under "${expectedTag}".`);
}
