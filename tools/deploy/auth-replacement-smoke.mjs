import { randomUUID } from 'node:crypto';

const BASE = 'https://khohoanamdev.lptech.info.vn/api/v1';
const SHA = '9d9e87382eea39deb3f3d660b3955110b526ea3c';
const PRIVATE = /token|password|secret|cookie|authorization|name|email|phone|address|reason|raw|serial|message|actor.?id|auth.?session.?id|user.?id|created_by|updated_by|user_agent|last_login/i;
const HEADERS = new Set(['content-type', 'cache-control', 'vary', 'x-request-id', 'x-content-type-options', 'etag']);

export function sanitize(value) {
  if (Array.isArray(value)) return value.map(sanitize);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([key, item]) =>
      [key, PRIVATE.test(key) && item !== null ? '[REDACTED]' : sanitize(item)]));
  }
  return value;
}

export async function smoke({ email, password, log = console.log }) {
  if (email !== 'admin@gmail.com' || !password) throw new Error('Explicitly approved admin smoke credential is unavailable');
  const evidence = [];
  const a = { cookies: new Map(), token: null };
  const b = { cookies: new Map(), token: null };
  async function request(client, path, method = 'GET', body, extra = {}) {
    const headers = { Accept: 'application/json', Origin: 'https://khohoanamfe.lptech.info.vn',
      'X-Request-ID': randomUUID(), ...extra };
    if (client.token) headers.Authorization = `Bearer ${client.token}`;
    if (client.cookies.size) headers.Cookie = [...client.cookies].map(([key, value]) => `${key}=${value}`).join('; ');
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    let response;
    try {
      response = await fetch(BASE + path, { method, headers, redirect: 'error', signal: AbortSignal.timeout(40000),
        body: body === undefined ? undefined : JSON.stringify(body) });
    } catch {
      throw new Error('Auth smoke request has an unknown transport result; no automatic retry');
    }
    for (const cookie of response.headers.getSetCookie()) {
      const pair = cookie.split(';')[0];
      const delimiter = pair.indexOf('=');
      client.cookies.set(pair.slice(0, delimiter), pair.slice(delimiter + 1));
    }
    const json = await response.json();
    const record = { method, path, status: response.status,
      time: new Date().toLocaleString('sv-SE', { timeZone: 'Asia/Ho_Chi_Minh' }) + '+07:00',
      request_id: response.headers.get('x-request-id') ?? json.meta?.request_id,
      headers: Object.fromEntries([...response.headers].filter(([key]) => HEADERS.has(key.toLowerCase()))),
      response: sanitize(json) };
    log(JSON.stringify(record));
    evidence.push(record);
    return { status: response.status, json };
  }
  function expect(response, status = 200, errorCode) {
    if (response.status !== status || (errorCode && (response.json.error_code ?? response.json.error?.code) !== errorCode)) {
      throw new Error(`Auth smoke expected HTTP ${status}${errorCode ? ' ' + errorCode : ''}, got HTTP ${response.status}`);
    }
    return response.json.data;
  }
  const health = expect(await request(a, '/health'));
  if (health.build.sha !== SHA) throw new Error('Unapproved auth runtime SHA');
  const loginA = expect(await request(a, '/auth/login', 'POST', { email, password }));
  if (!loginA.access_token || loginA.mfa_required) throw new Error('Login A needs an approved MFA completion, not a bypass');
  a.token = loginA.access_token;
  const meA = expect(await request(a, '/auth/me'));
  if (meA.account_session_policy?.version !== 'auth-session-replacement-v1' || meA.account_session_policy.enabled !== true) {
    throw new Error('Runtime replacement policy is not enabled');
  }
  const loginB = expect(await request(b, '/auth/login', 'POST', { email, password }));
  if (!loginB.access_token || loginB.mfa_required) throw new Error('Login B did not complete');
  b.token = loginB.access_token;
  const meB = expect(await request(b, '/auth/me'));
  if (!meA.auth_session_id || !meB.auth_session_id || meA.auth_session_id === meB.auth_session_id) {
    throw new Error('Login B did not create a distinct stable family');
  }
  expect(await request(a, '/auth/me'), 401, 'AUTH_SESSION_REPLACED');
  expect(await request(a, '/auth/me', 'PATCH', {}, { 'If-Match': `"${meA.version}"` }), 401, 'AUTH_SESSION_REPLACED');
  a.token = null; // Refresh authenticates only the original A cookie, never a B bearer.
  expect(await request(a, '/auth/refresh', 'POST', {}), 401, 'AUTH_SESSION_REPLACED');
  expect(await request(b, '/auth/me'));
  const rotatedB = expect(await request(b, '/auth/refresh', 'POST', {}));
  if (!rotatedB.access_token) throw new Error('B refresh did not issue an access token');
  const stillB = expect(await request(b, '/auth/me'));
  if (stillB.auth_session_id !== meB.auth_session_id) throw new Error('B rotation changed its stable family');
  b.token = rotatedB.access_token;
  const sessions = await request(b, '/auth/sessions?per_page=10');
  expect(sessions);
  if (sessions.json.meta.total !== 1) throw new Error('Expected one active account family after replacement');
  const logout = await request(b, '/auth/logout', 'POST', {});
  expect(logout);
  if (logout.json.meta.auth_result?.work_shift_ended !== false || logout.json.meta.auth_result?.business_drafts_deleted !== false) {
    throw new Error('Logout did not confirm business/shift preservation');
  }
  expect(await request(b, '/auth/me'), 401);
  const summary = { status: 'AUTH_REPLACEMENT_HTTP_PASS', source_sha: SHA,
    two_independent_cookie_jars: true, old_access_rejected: true, old_refresh_rejected: true,
    successor_survives_old_refresh: true, successor_rotation_preserves_family: true,
    active_family_total_before_cleanup: 1, smoke_family_logged_out: true,
    work_shift_ended: false, business_drafts_deleted: false, physical_two_device_acceptance: 'PENDING' };
  log(JSON.stringify(summary));
  return { ...summary, evidence };
}
