import { test } from 'node:test';
import assert from 'node:assert/strict';
import { redact, smoke } from './green-owner-smoke.mjs';

test('PII and secrets are redacted without replacing null', () => {
  assert.deepEqual(redact({ actor: { display_name: 'Private Person' }, rawCode: 'private-qr',
    access_token: 'private-token', note: null, count: 0, status: 'RESOLVED' }),
  { actor: { display_name: '[REDACTED]' }, rawCode: '[REDACTED]', access_token: '[REDACTED]',
    note: null, count: 0, status: 'RESOLVED' });
});

test('unapproved auth owner is rejected before any network access', async () => {
  await assert.rejects(smoke({ email: 'other@example.test', password: 'unused' }), /Approved TEST auth owner/);
});

test('read-only smoke does not start a shift or create documents', async () => {
  const original = globalThis.fetch;
  const calls = [];
  const logs = [];
  const responses = [
    { data: { build: { sha: '898d447eba868f0fb2ea6362b2e4766534ddf76a' } } },
    { data: { access_token: 'private-access-token' } },
    { data: { available_warehouses: [{ warehouse_code: 'KHO-TONG-HN', warehouse_id: 'warehouse-test' }] } },
    { data: { access_token: 'private-context-token' } },
    { data: { email: 'private@example.test' } },
    { data: [{ actor: { display_name: 'Private Person' }, rawCode: 'private-qr' }] },
    { data: { permissions: { create: true } } },
    { data: [], meta: { contract: 'p12-mixed-documents-v3' } },
  ];
  globalThis.fetch = async (url, options) => {
    calls.push({ url, method: options.method });
    return new Response(JSON.stringify(responses.shift()), { headers: { Vary: 'Authorization, Origin' } });
  };
  try {
    const result = await smoke({ email: 'admin@gmail.com', password: 'private-password', log: line => logs.push(line) });
    assert.equal(result.status, 'READ_ONLY_PASS');
    assert.equal(calls.length, 8);
    assert.ok(calls.every(call => !/work-shifts|drafts/.test(call.url)));
    for (const value of ['private-access-token', 'private-context-token', 'private-password', 'private@example.test', 'private-qr', 'Private Person']) {
      assert.ok(!logs.join('\n').includes(value));
    }
  } finally {
    globalThis.fetch = original;
  }
});
