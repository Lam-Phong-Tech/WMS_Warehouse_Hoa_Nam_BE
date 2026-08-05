#!/usr/bin/env node
import fs from 'node:fs';

const [collectionPath, environmentPath, openapiPath, sourceRegisterPath, outputPath] = process.argv.slice(2);
for (const value of [collectionPath, environmentPath, openapiPath, sourceRegisterPath, outputPath]) {
  if (!value) throw new Error('Usage: verify-wwhn180-canonical-handoff.mjs <collection> <environment> <openapi-json> <source-register> <output>');
}

const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));
const environment = JSON.parse(fs.readFileSync(environmentPath, 'utf8'));
const openapi = JSON.parse(fs.readFileSync(openapiPath, 'utf8'));
const sourceRegister = JSON.parse(fs.readFileSync(sourceRegisterPath, 'utf8'));
const expectedVersion = sourceRegister.version;

const requests = [];
const walk = items => {
  for (const item of items ?? []) {
    if (item.request) requests.push({ name: item.name, method: item.request.method, url: String(item.request.url?.raw ?? item.request.url ?? '') });
    walk(item.item);
  }
};
walk(collection.item);

const versionString = version => `${version?.major ?? 0}.${version?.minor ?? 0}.${version?.patch ?? 0}`;
const forbidden = /\/(?:submit|approve|reject)(?:[/?]|$)/i;
const forbiddenRequests = requests.filter(row => forbidden.test(row.url));
const forbiddenPaths = Object.keys(openapi.paths ?? {}).filter(path => forbidden.test(path));
const requiredPaths = [
  '/api/v1/scan/classify',
  '/api/v1/inbound-documents/{id}/scan',
  '/api/v1/outbound-documents/{id}/scan',
  '/api/v1/inbound-documents/{id}/post-receipt',
  '/api/v1/outbound-documents/{id}/post-issue',
  '/api/v1/stocktake/sessions/{id}/apply-adjustment',
  '/api/v1/warranty-cases/{id}/temp-only',
  '/api/v1/reports/inventory-reconciliation'
];
const missingPaths = requiredPaths.filter(path => !openapi.paths?.[path]);
const secretRows = (environment.values ?? []).filter(row =>
  /(password|secret|token)/i.test(row.key) && String(row.value ?? '').trim() !== ''
);

const environmentVersion = String(environment._postman_exported_using ?? '').match(/([0-9]+\.[0-9]+\.[0-9]+)/)?.[1] ?? null;

const errors = [];
if (collection.info?.name !== 'WWHN-180 Canonical Contract') errors.push('Unexpected canonical collection name');
if (versionString(collection.info?.version) !== expectedVersion) errors.push(`Canonical collection version must be ${expectedVersion}`);
if (environmentVersion !== expectedVersion) errors.push(`Canonical environment version must be ${expectedVersion}`);
if (openapi.info?.version !== expectedVersion) errors.push(`OpenAPI version must be ${expectedVersion}`);
if (openapi['x-document-version'] !== expectedVersion) errors.push(`OpenAPI x-document-version must be ${expectedVersion}`);
if (requests.length !== 15) errors.push(`Canonical collection request count must be 15, got ${requests.length}`);
if (forbiddenRequests.length) errors.push(`Forbidden workflow requests: ${forbiddenRequests.map(row => row.url).join(', ')}`);
if (forbiddenPaths.length) errors.push(`Forbidden OpenAPI workflow paths: ${forbiddenPaths.join(', ')}`);
if (missingPaths.length) errors.push(`Missing canonical OpenAPI paths: ${missingPaths.join(', ')}`);
if (secretRows.length) errors.push(`Committed environment contains secret values: ${secretRows.map(row => row.key).join(', ')}`);
if (expectedVersion !== '1.7.0') errors.push('Source register must be version 1.7.0');
if (sourceRegister.contracts?.canonical_postman_collection !== collectionPath) errors.push('Source register canonical Postman path mismatch');
if (sourceRegister.contracts?.focused_openapi_json !== openapiPath) errors.push('Source register focused OpenAPI JSON path mismatch');
if (sourceRegister.canonical_scope?.legacy_approval_routes !== 'OUT_OF_SCOPE_NOT_IN_HANDOFF') errors.push('Legacy approval route disposition is not explicit');

const result = {
  schema_version: '1.1.0',
  jira_key: 'WWHN-180',
  document_version: expectedVersion,
  status: errors.length ? 'FAIL' : 'PASS',
  request_count: requests.length,
  required_path_count: requiredPaths.length,
  forbidden_request_count: forbiddenRequests.length,
  forbidden_openapi_path_count: forbiddenPaths.length,
  committed_secret_value_count: secretRows.length,
  versions: {
    source_register: expectedVersion,
    openapi: openapi.info?.version ?? null,
    postman: versionString(collection.info?.version),
    environment: environmentVersion,
  },
  errors
};
fs.writeFileSync(outputPath, `${JSON.stringify(result, null, 2)}\n`);
if (errors.length) {
  console.error(JSON.stringify(result, null, 2));
  process.exit(1);
}
console.log(JSON.stringify(result, null, 2));
