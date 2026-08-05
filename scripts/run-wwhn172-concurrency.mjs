import { mkdir, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';

const baseUrl = (process.env.WWHN172_BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const token = process.env.WWHN172_TOKEN || '';
const serialId = process.env.WWHN172_SERIAL_ID || '';
const serialProductId = process.env.WWHN172_SERIAL_PRODUCT_ID || '';
const output = process.argv[2] || 'build/evidence/wwhn-172/concurrency-report.json';
const suffix = `${process.env.DB_CONNECTION || 'db'}-${process.env.GITHUB_RUN_ID || process.pid}`
  .replace(/[^a-zA-Z0-9-]/g, '-')
  .slice(-32)
  .toUpperCase();

if (!token) {
  throw new Error('WWHN172_TOKEN is required.');
}

const headers = (idempotencyKey) => ({
  Accept: 'application/json',
  Authorization: `Bearer ${token}`,
  'Content-Type': 'application/json',
  'Idempotency-Key': idempotencyKey,
});

async function request(method, path, body, idempotencyKey) {
  const response = await fetch(`${baseUrl}${path}`, {
    method,
    headers: headers(idempotencyKey),
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  const text = await response.text();
  let json = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = { non_json_body: text.slice(0, 500) };
  }

  return {
    status: response.status,
    error_code: json?.error?.code || json?.error_code || null,
    id: json?.data?.id || json?.data?.container_id || null,
    version: json?.data?.version || null,
    name: json?.data?.name || null,
  };
}

function exactStatusPair(results, acceptedLoserCodes) {
  const statuses = results.map((item) => item.status).sort((a, b) => a - b);
  const loser = results.find((item) => item.status === 409);
  return statuses.length === 2
    && statuses[0] === 200
    && statuses[1] === 409
    && loser
    && acceptedLoserCodes.includes(loser.error_code);
}

function createStatusPair(results, acceptedLoserCodes) {
  const statuses = results.map((item) => item.status).sort((a, b) => a - b);
  const loser = results.find((item) => item.status === 409);
  return statuses.length === 2
    && statuses[0] === 201
    && statuses[1] === 409
    && loser
    && acceptedLoserCodes.includes(loser.error_code);
}

const report = {
  schema_version: '1.0.0',
  task: 'WWHN-172',
  database_driver: process.env.DB_CONNECTION || null,
  started_at: new Date().toISOString(),
  scenarios: {},
};

const versionSeed = await request(
  'POST',
  '/api/v1/skus',
  { code: `W172-RACE-V-${suffix}`, name: 'WWHN-172 optimistic race seed' },
  `w172-race-version-seed-${suffix}`,
);
if (versionSeed.status !== 201 || !versionSeed.id) {
  throw new Error(`Cannot create optimistic race seed: HTTP ${versionSeed.status}`);
}

const versionResults = await Promise.all([
  request(
    'PATCH',
    `/api/v1/skus/${versionSeed.id}`,
    { version: 1, name: 'Optimistic race winner A' },
    `w172-race-version-a-${suffix}`,
  ),
  request(
    'PATCH',
    `/api/v1/skus/${versionSeed.id}`,
    { version: 1, name: 'Optimistic race winner B' },
    `w172-race-version-b-${suffix}`,
  ),
]);
report.scenarios.optimistic_version = {
  results: versionResults,
  pass: exactStatusPair(versionResults, ['SKU_VERSION_CONFLICT']),
};

const physicalCode = `W172-RACE-PC-${suffix}`;
const physicalResults = await Promise.all([
  request(
    'POST',
    '/api/v1/skus',
    {
      code: `W172-RACE-PC-A-${suffix}`,
      name: 'Physical-code race A',
      physical_codes: [{ code_value: physicalCode, code_type: 'BARCODE', carrier_type: 'UNIT' }],
    },
    `w172-race-pc-a-${suffix}`,
  ),
  request(
    'POST',
    '/api/v1/skus',
    {
      code: `W172-RACE-PC-B-${suffix}`,
      name: 'Physical-code race B',
      physical_codes: [{ code_value: physicalCode, code_type: 'BARCODE', carrier_type: 'UNIT' }],
    },
    `w172-race-pc-b-${suffix}`,
  ),
]);
report.scenarios.physical_code_ownership = {
  results: physicalResults,
  pass: createStatusPair(physicalResults, [
    'PHYSICAL_CODE_ALREADY_ASSIGNED',
    'PHYSICAL_CODE_CONFLICT',
    'UNIQUE_CONSTRAINT_CONFLICT',
  ]),
};

if (serialId && serialProductId) {
  const serialResults = await Promise.all([
    request(
      'POST',
      '/api/v1/containers',
      {
        container_code: `W172-RACE-CTN-A-${suffix}`,
        container_type: 'BOX',
        primary_product_id: serialProductId,
        items: [{ inventory_serial_id: serialId, product_id: serialProductId, quantity: 1 }],
      },
      `w172-race-serial-a-${suffix}`,
    ),
    request(
      'POST',
      '/api/v1/containers',
      {
        container_code: `W172-RACE-CTN-B-${suffix}`,
        container_type: 'BOX',
        primary_product_id: serialProductId,
        items: [{ inventory_serial_id: serialId, product_id: serialProductId, quantity: 1 }],
      },
      `w172-race-serial-b-${suffix}`,
    ),
  ]);
  report.scenarios.serial_ownership = {
    results: serialResults,
    pass: createStatusPair(serialResults, [
      'SERIAL_ALREADY_CONTAINERIZED',
      'SERIAL_CONTAINER_CONFLICT',
      'UNIQUE_CONSTRAINT_CONFLICT',
    ]),
  };
}

report.finished_at = new Date().toISOString();
report.status = Object.values(report.scenarios).every((scenario) => scenario.pass) ? 'PASS' : 'FAIL';
await mkdir(dirname(output), { recursive: true });
await writeFile(output, `${JSON.stringify(report, null, 2)}\n`, 'utf8');

if (report.status !== 'PASS') {
  console.error(JSON.stringify(report, null, 2));
  process.exit(1);
}

console.log(`WWHN-172 concurrency evidence: ${report.status}`);
