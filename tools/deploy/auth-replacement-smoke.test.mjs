import { test } from 'node:test';
import assert from 'node:assert/strict';
import { sanitize, smoke } from './auth-replacement-smoke.mjs';

test('mask auth credentials and PII without replacing null or business values', () => {
  assert.deepEqual(sanitize({ access_token: 'private', auth_session_id: 'private', user_id: 'private',
    email: 'private', display_name: 'private', note: null, total: 1, code: 'AUTH_SESSION_REPLACED' }),
  { access_token: '[REDACTED]', auth_session_id: '[REDACTED]', user_id: '[REDACTED]', email: '[REDACTED]',
    display_name: '[REDACTED]', note: null, total: 1, code: 'AUTH_SESSION_REPLACED' });
});

test('only the explicitly approved smoke account is accepted', async () => {
  await assert.rejects(smoke({ email: 'other@example.test', password: 'unused' }), /approved admin/);
});

test('exercise separate A/B cookies, terminal refusal and safe current-family logout', async () => {
  const original = globalThis.fetch;
  const logs = [];
  const calls = [];
  const policy = { version: 'auth-session-replacement-v1', enabled: true };
  const json = (data, extra = {}) => ({ success: true, data, ...extra });
  const me = family => json({ auth_session_id: family, version: 1, account_session_policy: policy,
    email: 'private-person@example.test', display_name: 'Private Person' });
  const responses = [
    [200, json({ build: { sha: '9d9e87382eea39deb3f3d660b3955110b526ea3c' } })],
    [200, json({ access_token: 'private-token-A' }), 'wms_refresh_token=private-cookie-A; HttpOnly'],
    [200, me('family-A')],
    [200, json({ access_token: 'private-token-B' }), 'wms_refresh_token=private-cookie-B; HttpOnly'],
    [200, me('family-B')],
    [401, { error_code: 'AUTH_SESSION_REPLACED' }],
    [401, { error_code: 'AUTH_SESSION_REPLACED' }],
    [401, { error_code: 'AUTH_SESSION_REPLACED' }],
    [200, me('family-B')],
    [200, json({ access_token: 'private-token-B2' }), 'wms_refresh_token=private-cookie-B2; HttpOnly'],
    [200, me('family-B')],
    [200, json([], { meta: { total: 1 } })],
    [200, json(null, { meta: { auth_result: { work_shift_ended: false, business_drafts_deleted: false } } })],
    [401, { error_code: 'AUTH_SESSION_EXPIRED' }],
  ];
  globalThis.fetch = async (url, options) => {
    calls.push({ url, ...options });
    const [status, body, cookie] = responses.shift();
    return new Response(JSON.stringify(body), { status, headers: cookie ? { 'Set-Cookie': cookie } : {} });
  };
  try {
    const result = await smoke({ email: 'admin@gmail.com', password: 'private-password', log: line => logs.push(line) });
    assert.equal(result.status, 'AUTH_REPLACEMENT_HTTP_PASS');
    assert.equal(calls[7].headers.Cookie, 'wms_refresh_token=private-cookie-A');
    assert.equal(calls[7].headers.Authorization, undefined);
    assert.equal(calls[8].headers.Cookie, 'wms_refresh_token=private-cookie-B');
    assert.equal(calls[12].headers.Cookie, 'wms_refresh_token=private-cookie-B2');
    for (const secret of ['private-token', 'private-cookie', 'private-password', 'private-person', 'Private Person']) {
      assert.ok(!logs.join('\n').includes(secret));
    }
    assert.ok(calls.every(call => !/work-shifts|drafts|inventory/.test(call.url)));
    assert.equal(responses.length, 0);
  } finally {
    globalThis.fetch = original;
  }
});
