import { randomUUID } from 'node:crypto';

const BASE = 'https://khohoanamdev.lptech.info.vn/api/v1';
const SHA = '898d447eba868f0fb2ea6362b2e4766534ddf76a';
const PRIVATE_KEY = /token|password|secret|authorization|cookie|name|email|phone|address|note|reason|raw|serial|actor.?id|auth.?session.?id|customer|receiver|contact|message|description/i;

export function redact(value) {
  if (Array.isArray(value)) return value.map(redact);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([key, item]) =>
      [key, PRIVATE_KEY.test(key) && item !== null ? '[REDACTED]' : redact(item)]));
  }
  return value;
}

export async function smoke({ email, password, writeTest = false, log = console.log }) {
  if (email !== 'admin@gmail.com' || !password) throw new Error('Approved TEST auth owner is not configured');
  let token;
  const cookies = new Map();
  const evidence = [];
  async function request(path, method = 'GET', body, extra = {}, capture = true) {
    const headers = { Accept: 'application/json', Origin: 'https://khohoanamfe.lptech.info.vn',
      'X-Request-ID': randomUUID(), ...extra };
    if (token) headers.Authorization = `Bearer ${token}`;
    if (cookies.size) headers.Cookie = [...cookies].map(([key, value]) => `${key}=${value}`).join('; ');
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const ownerWrite = method !== 'GET' && !path.startsWith('/auth/');
    if (ownerWrite) log(JSON.stringify({ event: 'planned_test_command', method, path,
      request_id: headers['X-Request-ID'], idempotency_key: headers['Idempotency-Key'],
      shift_id: headers['X-Work-Shift-ID'], version: headers['If-Match'], body: redact(body) }));
    let response;
    try {
      response = await fetch(BASE + path, { method, headers, redirect: 'error',
        signal: AbortSignal.timeout(45000), body: body === undefined ? undefined : JSON.stringify(body) });
    } catch (error) {
      if (ownerWrite) {
        let reconcile;
        const draft = path.match(/^\/mini-app\/inbound\/drafts\/([^/]+)(?:\/cancel)?$/);
        const end = path.match(/^\/mini-app\/work-shifts\/([^/]+)\/end$/);
        if (draft) reconcile = `/mini-app/inbound/drafts/${draft[1]}/requests/${headers['Idempotency-Key']}?warehouse_id=${body.warehouse_id}&scan_session_id=${body.scan_session_id}&command=${method === 'PUT' ? 'SAVE' : 'CANCEL'}`;
        if (end) reconcile = `/mini-app/work-shifts/${end[1]}/requests/${headers['Idempotency-Key']}?warehouse_id=${body.warehouse_id}`;
        if (reconcile) await request(reconcile, 'GET', undefined,
          headers['X-Work-Shift-ID'] ? { 'X-Work-Shift-ID': headers['X-Work-Shift-ID'] } : {});
        throw new Error('TEST write result requires CHECK_ONLY; no write retry, CANCEL or END follows');
      }
      throw error;
    }
    for (const value of response.headers.getSetCookie()) {
      const pair = value.split(';')[0];
      const delimiter = pair.indexOf('=');
      cookies.set(pair.slice(0, delimiter), pair.slice(delimiter + 1));
    }
    const json = await response.json();
    const result = { status: response.status, headers: response.headers, json };
    const record = { method, path, status: response.status,
      time: new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' }) + '+07:00',
      request_id: response.headers.get('x-request-id') ?? json.meta?.request_id,
      headers: Object.fromEntries([...response.headers].filter(([key]) => !PRIVATE_KEY.test(key))),
      response: capture ? redact(json) : '[AUTH RESPONSE OMITTED]' };
    evidence.push(record);
    log(JSON.stringify(record));
    return result;
  }
  function requireStatus(result, statuses = [200]) {
    if (!statuses.includes(result.status)) {
      throw new Error(`HTTP ${result.status}: ${result.json.error_code ?? result.json.error?.code ?? 'UNEXPECTED_STATUS'}`);
    }
    return result.json.data;
  }
  const health = requireStatus(await request('/health'));
  if (health.build.sha !== SHA) throw new Error('Runtime SHA does not match approved smoke candidate');
  const login = requireStatus(await request('/auth/login', 'POST', { email, password }, {}, false));
  token = login.access_token;
  if (!token) throw new Error('Browser login did not issue an access token');
  const me = requireStatus(await request('/auth/me', 'GET', undefined, {}, false));
  const warehouse = me.available_warehouses.find(row => (row.warehouse_code ?? row.code) === 'KHO-TONG-HN');
  const warehouseId = warehouse?.warehouse_id ?? warehouse?.id;
  if (!warehouseId) throw new Error('Approved KHO-TONG-HN is unavailable; never substitute another warehouse');
  const context = requireStatus(await request('/auth/switch-context', 'POST',
    { context_type: 'WAREHOUSE', context_id: warehouseId }, {}, false));
  if (context.access_token) token = context.access_token;
  requireStatus(await request('/auth/me', 'GET', undefined, {}, false));
  const query = `warehouse_id=${warehouseId}`;
  requireStatus(await request(`/mini-app/scan-sessions?${query}&per_page=1`));
  const p04 = requireStatus(await request('/mini-app/inbound/context'));
  const mixed = await request(`/mini-app/documents?${query}&source=ALL&cursor=start&per_page=1`);
  requireStatus(mixed);
  if (mixed.json.meta.contract !== 'p12-mixed-documents-v3'
    || !mixed.headers.get('vary')?.toLowerCase().split(',').map(value => value.trim()).includes('authorization')) {
    throw new Error('P12 authenticated read contract/header is not accepted');
  }
  if (!writeTest) return { status: 'READ_ONLY_PASS', source_sha: SHA, evidence };
  if (p04.permissions?.save === false || p04.permissions?.create === false) throw new Error('P04 SAVE permission is unavailable');
  const current = requireStatus(await request(`/mini-app/work-shifts/current?${query}`));
  if (current !== null) throw new Error('Existing shift must not be reused or closed by smoke');
  const startKey = randomUUID();
  const shift = requireStatus(await request('/mini-app/work-shifts/start', 'POST',
    { warehouse_id: warehouseId }, { 'Idempotency-Key': startKey }), [201]);
  if (shift.tracking_contract !== 'p14-shift-v1' || shift.lifecycle?.end_supported !== true) {
    throw new Error('Dedicated TEST shift has no approved END protocol');
  }
  const draftId = randomUUID();
  const scanId = randomUUID();
  const saveKey = randomUUID();
  const saveTrace = randomUUID();
  const identity = { warehouse_id: warehouseId, scan_session_id: scanId };
  const owner = { 'X-Work-Shift-ID': shift.shift_id };
  const saveHeaders = { ...owner, 'X-Request-ID': saveTrace, 'Idempotency-Key': saveKey, 'If-Match': '"0"' };
  const saveBody = { ...identity, inbound_type: 'receipt', name: 'DEV TEST P04 identity smoke',
    items: [], note: 'Approved empty SAVE/CANCEL only; no RECORD or stock posting' };
  const save = requireStatus(await request(`/mini-app/inbound/drafts/${draftId}`, 'PUT', saveBody, saveHeaders), [201]);
  if (save.document.version !== 1) throw new Error('Unexpected saved TEST draft version');
  const ownerQuery = `${query}&scan_session_id=${scanId}`;
  const savedReceipt = requireStatus(await request(
    `/mini-app/inbound/drafts/${draftId}/requests/${saveKey}?${ownerQuery}&command=SAVE`, 'GET', undefined, owner));
  if (savedReceipt.outcome !== 'SUCCEEDED') throw new Error('SAVE is UNKNOWN; CHECK_ONLY, never CANCEL or END');
  const replay = requireStatus(await request(`/mini-app/inbound/drafts/${draftId}`, 'PUT', saveBody, saveHeaders), [200, 201]);
  if (replay.document.version !== 1) throw new Error('Replay unexpectedly changed the draft version');
  const cancelKey = randomUUID();
  const cancelled = requireStatus(await request(`/mini-app/inbound/drafts/${draftId}/cancel`, 'POST',
    { ...identity, confirmed_request_id: saveKey, reason: 'Approved isolated smoke cleanup' },
    { ...owner, 'X-Request-ID': randomUUID(), 'Idempotency-Key': cancelKey, 'If-Match': '"1"' }));
  if (cancelled.document.status !== 'CANCELLED') throw new Error('TEST draft cancellation is not confirmed');
  const check = requireStatus(await request(`/mini-app/work-shifts/${shift.shift_id}/close-check?${query}`));
  if (check.can_end !== true || check.blockers.length !== 0 || !check.check_token
    || check.can_end_scope !== 'SERVER_CHECK_ONLY') throw new Error('Dedicated TEST shift is blocked; never force END');
  const endKey = randomUUID();
  const endBody = { warehouse_id: warehouseId, check_token: check.check_token,
    local_state: 'CLEAN', pending_request_ids: [] };
  const ended = requireStatus(await request(`/mini-app/work-shifts/${shift.shift_id}/end`, 'POST', endBody,
    { 'Idempotency-Key': endKey, 'If-Match': `"${check.expected_version}"` }));
  if (ended.outcome !== 'SUCCEEDED' || ended.shift_id !== shift.shift_id) throw new Error('END receipt is not confirmed');
  const reconciled = requireStatus(await request(`/mini-app/work-shifts/${shift.shift_id}/requests/${endKey}?${query}`));
  if (reconciled.outcome !== 'SUCCEEDED' || reconciled.shift_id !== shift.shift_id) throw new Error('END reconcile did not match');
  return { status: 'OWNER_SMOKE_PASS', source_sha: SHA, warehouse_id: warehouseId,
    shift_id: shift.shift_id, draft_id: draftId, draft_status: 'CANCELLED', shift_status: 'ENDED',
    record_or_post_called: false, save_key: saveKey, end_key: endKey, evidence };
}
