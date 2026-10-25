import fs from 'node:fs';
import crypto from 'node:crypto';

const BASE = 'https://khohoanamdev.lptech.info.vn/api/v1';
const SHA = '184bd43d95804a0c6f42d47ca64dfa39db40d9ae';
const CASE_CODE = 'TC12-TEST-WARRANTY-20261025';
const RAW_CODE = 'TC11-TC12-TEST-QR-20261025';
const PRIVATE = /authorization|cookie|set-cookie|token|password|secret|customer|phone|address|note|reason|raw_code|serial/i;
const redact = value => Array.isArray(value) ? value.map(redact) : value && typeof value === 'object'
  ? Object.fromEntries(Object.entries(value).map(([k, v]) => [k, PRIVATE.test(k) && v !== null ? '[REDACTED]' : redact(v)])) : value;

const email = process.env.SMOKE_EMAIL || 'admin@gmail.com';
const password = process.env.SMOKE_PASSWORD;
if (!password) throw new Error('SMOKE_PASSWORD secret is required');
let token;
const evidence = [];
async function request(path, method = 'GET', body) {
  const requestId = crypto.randomUUID();
  const headers = {Accept: 'application/json', Origin: 'https://khohoanamfe.lptech.info.vn', 'X-Request-ID': requestId};
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const response = await fetch(BASE + path, {method, headers, redirect: 'error', signal: AbortSignal.timeout(30000),
    body: body === undefined ? undefined : JSON.stringify(body)});
  const raw = await response.text();
  let json; try { json = JSON.parse(raw); } catch { json = {raw: '[NON_JSON]'}; }
  evidence.push({method, path, status: response.status, request_id: response.headers.get('x-request-id') || requestId,
    observed_at_vietnam: new Intl.DateTimeFormat('sv-SE', {timeZone: 'Asia/Ho_Chi_Minh', dateStyle: 'short', timeStyle: 'long'}).format(new Date()) + '+07:00',
    headers: Object.fromEntries([...response.headers].filter(([k]) => !PRIVATE.test(k))), response: redact(json)});
  return {response, json};
}
function data(result, allowed = [200]) {
  if (!allowed.includes(result.response.status)) throw new Error(`Unexpected HTTP ${result.response.status}: ${result.json.error?.code || result.json.error_code || 'UNKNOWN'}`);
  return result.json.data;
}

data(await request('/health'));
const health = evidence.at(-1).response;
if (health.data?.build?.sha !== SHA) throw new Error('Runtime SHA mismatch');
const login = data(await request('/auth/login', 'POST', {email, password}));
token = login.access_token;
if (!token) throw new Error('No access token');
const me = data(await request('/auth/me'));
const warehouse = (me.available_warehouses || []).find(w => (w.warehouse_code || w.code) === 'KHO-TONG-HN');
if (!warehouse) throw new Error('KHO-TONG-HN is not available to smoke owner');
const warehouseId = warehouse.warehouse_id || warehouse.id;
const switched = data(await request('/auth/switch-context', 'POST', {context_type: 'WAREHOUSE', context_id: warehouseId}));
if (switched.access_token) token = switched.access_token;
data(await request('/auth/me'));

const tc11 = data(await request('/mini-app/outbound/resolve-code', 'POST', {warehouse_id: warehouseId, raw_code: RAW_CODE}));
if (tc11.eligible_for_outbound !== true || tc11.available_qty < 1 || tc11.stock_effect !== 'NONE'
  || tc11.wms_document_created !== false || tc11.match_created !== false) throw new Error('TC11 resolve contract mismatch');

const list = data(await request(`/mini-app/warranty-cases?warehouse_id=${warehouseId}&search=${encodeURIComponent(CASE_CODE)}&per_page=20`));
const row = (Array.isArray(list) ? list : []).find(item => item.warranty_case_code === CASE_CODE || item.case_code === CASE_CODE);
if (!row) throw new Error('TC12 fixture is not visible in authorized warranty list');
const caseId = row.warranty_case_id || row.id;
const tc12 = data(await request(`/mini-app/warranty-cases/${caseId}?warehouse_id=${warehouseId}`));
if (tc12.warranty_case_id !== caseId || tc12.item_id === null || tc12.product_id === null
  || tc12.identification_status !== 'IDENTIFIED' || tc12.status !== 'CHECKING') throw new Error('TC12 source projection mismatch');
data(await request(`/mini-app/warranty-cases/${caseId}/events?warehouse_id=${warehouseId}&per_page=20`));

const output = {status: 'TC11_TC12_LIVE_SMOKE_PASS', source_sha: SHA, warehouse_id: warehouseId,
  tc11: {raw_code: '[REDACTED]', eligible_for_outbound: tc11.eligible_for_outbound, available_qty: tc11.available_qty,
    stock_effect: tc11.stock_effect, wms_document_created: tc11.wms_document_created, match_created: tc11.match_created},
  tc12: {case_id: caseId, case_code: CASE_CODE, status: tc12.status, identification_status: tc12.identification_status,
    item_id: tc12.item_id, product_id: tc12.product_id, source_persisted: true},
  business_commands_called: [], fixture_only_write: true, evidence};
fs.writeFileSync(process.env.GITHUB_WORKSPACE + '/tc11-tc12-live-evidence.json', JSON.stringify(output, null, 2));
console.log(JSON.stringify({...output, evidence: evidence.map(({response, ...rest}) => rest)}, null, 2));
