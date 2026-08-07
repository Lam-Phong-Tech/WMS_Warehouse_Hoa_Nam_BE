import fs from 'node:fs';
import path from 'node:path';

const [outputPath, concurrencyArg = '20', baseUrl = 'http://127.0.0.1:8000'] = process.argv.slice(2);
if (!outputPath) {
  throw new Error('Usage: node run-wwhn150-concurrent-scan.mjs <output.json> [concurrency] [base-url]');
}

const concurrency = Number.parseInt(concurrencyArg, 10);
if (!Number.isInteger(concurrency) || concurrency < 2 || concurrency > 100) {
  throw new Error('Concurrency must be an integer between 2 and 100.');
}

const email = process.env.WWHN150_EMAIL ?? 'manager01@gmail.com';
const password = process.env.WWHN150_PASSWORD;
if (!password) {
  throw new Error('WWHN150_PASSWORD is required.');
}

const requestJson = async (url, options) => {
  const response = await fetch(url, options);
  const raw = await response.text();
  let body = null;
  try {
    body = raw === '' ? null : JSON.parse(raw);
  } catch {
    body = { parse_error: true };
  }
  return { status: response.status, body };
};

const login = await requestJson(`${baseUrl}/api/v1/auth/login`, {
  method: 'POST',
  headers: { 'content-type': 'application/json', accept: 'application/json' },
  body: JSON.stringify({ email, password }),
});
const token = login.body?.data?.access_token;
if (login.status !== 200 || typeof token !== 'string' || token === '') {
  throw new Error(`Concurrent scan setup login failed with HTTP ${login.status}.`);
}

const authHeaders = { authorization: `Bearer ${token}`, accept: 'application/json' };
const warehouses = await requestJson(`${baseUrl}/api/v1/warehouses`, { headers: authHeaders });
const warehouse = warehouses.body?.data?.find((row) => row.code === 'KHO-TONG-HN');
if (warehouses.status !== 200 || typeof warehouse?.id !== 'string') {
  throw new Error(`Concurrent scan warehouse setup failed with HTTP ${warehouses.status}.`);
}

const idempotencyKey = `WWHN-150-CONCURRENT-${Date.now()}`;
const payload = {
  raw_code: 'HN-PV-2026-00001',
  context: 'STOCKTAKE',
  warehouse_id: warehouse.id,
};
const startedAt = Date.now();
const results = await Promise.all(Array.from({ length: concurrency }, async (_, index) => {
  try {
    const response = await requestJson(`${baseUrl}/api/v1/scan/classify`, {
      method: 'POST',
      headers: {
        ...authHeaders,
        'content-type': 'application/json',
        'idempotency-key': idempotencyKey,
      },
      body: JSON.stringify(payload),
    });
    const data = response.body?.data;
    const passed = response.status === 200
      && data?.classification === 'ITEM'
      && data?.stock_effect === 'NONE'
      && data?.movement_created === false
      && data?.posting_event_created === false
      && data?.idempotency_cache_written === false
      && data?.current_location?.warehouse_id === warehouse.id;

    return {
      request: index + 1,
      http_status: response.status,
      item_id: data?.item_id ?? null,
      result: passed ? 'PASS' : 'FAIL',
    };
  } catch (error) {
    return {
      request: index + 1,
      http_status: 0,
      item_id: null,
      result: 'FAIL',
      transport_error: error instanceof Error ? error.message : String(error),
    };
  }
}));

const itemIds = [...new Set(results.map((result) => result.item_id).filter(Boolean))];
const failed = results.filter((result) => result.result === 'FAIL');
const sameResolvedItem = itemIds.length === 1;
const status = failed.length === 0 && sameResolvedItem ? 'PASS' : 'FAIL';
const output = {
  schema_version: '1.0.0',
  contract: 'WWHN-150 concurrent same-key scan/classify read-only probe',
  generated_at_utc: new Date().toISOString(),
  concurrency,
  elapsed_ms: Date.now() - startedAt,
  request: {
    raw_code: payload.raw_code,
    context: payload.context,
    warehouse_id: payload.warehouse_id,
    idempotency_key: idempotencyKey,
  },
  summary: {
    checked: concurrency + 1,
    passed: concurrency - failed.length + (sameResolvedItem ? 1 : 0),
    failed: failed.length + (sameResolvedItem ? 0 : 1),
    same_resolved_item: sameResolvedItem,
    status,
  },
  results,
};

fs.mkdirSync(path.dirname(outputPath), { recursive: true });
fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);
if (status !== 'PASS') {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}
console.log(`WWHN-150 concurrent scan PASS: ${concurrency} requests + stable resolution`);
