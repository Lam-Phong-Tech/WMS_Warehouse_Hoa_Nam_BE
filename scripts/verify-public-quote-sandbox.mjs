/** Network smoke. Explicit write opt-in; loopback sandbox only, never live. */
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';

const [manifestPath, consent, evidencePath = 'public-quote-smoke.json'] = process.argv.slice(2);
if (!manifestPath || consent !== '--allow-sandbox-writes') {
  console.error('Usage: node scripts/verify-public-quote-sandbox.mjs manifest.json --allow-sandbox-writes [evidence.json]');
  process.exit(2);
}
const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
assert.equal(manifest.scenario, 'public-quote-21-v1');
assert.equal(manifest.synthetic, true);
const base = new URL(manifest.base_url);
assert(['localhost', '127.0.0.1', '[::1]'].includes(base.hostname), 'Use a verified sandbox through loopback/SSH tunnel, not a remote live API.');
assert(['http:', 'https:'].includes(base.protocol));
assert(!base.username && !base.password && !base.search && !base.hash);
assert.equal(base.pathname, '/api/v1');
assert.equal(new Set(manifest.product_ids).size, 21);
assert.equal(manifest.product_ids.length, 21);
assert.equal(manifest.quote_template.full_name, 'SYNTHETIC UAT ONLY');
assert.equal(manifest.quote_template.phone, '0000000000');
const results = [];
async function request(path, body, key) {
  const response = await fetch(`${base.href.replace(/\/$/, '')}/public${path}`, {
    method: body === undefined ? 'GET' : 'POST', redirect: 'error',
    headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'Idempotency-Key': key }) },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    signal: AbortSignal.timeout(15000),
  });
  const data = await response.json();
  results.push({ path, method: body === undefined ? 'GET' : 'POST', status: response.status,
    request_id: data.data?.request_id ?? null, trace_id: data.meta?.request_id ?? null, error_code: data.error_code });
  return { status: response.status, body: data };
}
const config = await request('/config');
assert.equal(config.status, 200);
assert.equal(config.body.data.contract_version, '1.2.0');
assert.equal(config.body.data.quote_request.max_items, 20);
assert.equal(config.body.data.privacy.version, manifest.quote_template.privacy_version);
// Verify that all IDs really belong to this test dataset BEFORE any POST.
const lookup = await request('/products?' + manifest.product_ids.map(id => `ids[]=${encodeURIComponent(id)}`).join('&'));
assert.equal(lookup.status, 200);
assert.equal(lookup.body.data.items.length, 21);
assert.deepEqual(lookup.body.data.missing_ids, []);
assert.deepEqual(lookup.body.data.items.map(item => item.id), manifest.product_ids);
lookup.body.data.items.forEach((item, i) => {
  assert.equal(item.primary_code, `UAT-QUOTE-${String(i + 1).padStart(3, '0')}`);
  assert.equal(item.name, `SYNTHETIC QUOTE TEST ${item.primary_code}`);
});
const first = await request('/products?q=UAT-QUOTE&limit=20');
assert.equal(first.status, 200);
assert.equal(first.body.data.page_info.total, 21);
assert.equal(first.body.data.page_info.has_more, true);
const second = await request('/products?q=UAT-QUOTE&limit=20&cursor=' + encodeURIComponent(first.body.data.page_info.next_cursor));
assert.equal(second.status, 200);
assert.equal(second.body.data.page_info.total, 21);
assert.equal(second.body.data.page_info.has_more, false);
assert.equal(second.body.data.page_info.next_cursor, null);
assert.equal(second.body.data.items.length, 1);

const key = `hn-uat-quote-${randomUUID()}`;
const body = manifest.quote_template;
const created = await request('/quote-requests', body, key);
assert.equal(created.status, 201);
assert.equal(created.body.data.status, 'RECEIVED');
const replay = await request('/quote-requests', body, key);
assert.equal(replay.status, 200);
assert.deepEqual(replay.body.data, created.body.data);
const conflict = await request('/quote-requests', { ...body, note: 'Changed sandbox note only.' }, key);
assert.equal(conflict.status, 409);
assert.equal(conflict.body.error_code, 'IDEMPOTENCY_CONFLICT');
const items = manifest.product_ids.map(id => ({ product_id: id }));
const twenty = await request('/quote-requests', { ...body, items: items.slice(0, 20) }, `hn-uat-quote-${randomUUID()}`);
assert.equal(twenty.status, 201);
const twentyOne = await request('/quote-requests', { ...body, items }, `hn-uat-quote-${randomUUID()}`);
assert.equal(twentyOne.status, 422);
assert.equal(twentyOne.body.error_code, 'VALIDATION_FAILED');
await writeFile(evidencePath, JSON.stringify({ scenario: manifest.scenario, tested_at: new Date().toISOString(),
  declared_build_sha: manifest.declared_build_sha, base_url: manifest.base_url, results }, null, 2) + '\n');
console.log(`PASS: 201/200/409, 20/21 items and cursor pagination. Evidence: ${evidencePath}`);
