'use strict';

// Pure Postman preflight tests. No network, tokens, business fixtures or hardware.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const root = path.resolve(__dirname, '../..');
const contract = JSON.parse(fs.readFileSync(path.join(root, 'contracts/p15/owner-read-v1.json'), 'utf8'));
const collection = JSON.parse(fs.readFileSync(path.join(root, 'contracts/p15/P15-system-read.postman_collection.json'), 'utf8'));
const script = collection.event.find(event => event.listen === 'prerequest').script.exec.join('\n');
const uuid = n => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;
const defaults = {
  api_origin: 'https://p15.invalid', approved_api_origin: 'https://p15.invalid',
  live_read_authorized: 'true', owner_gate_authorized: 'true',
  access_token: 'ISOLATED_GUARD_TEST_NOT_A_CREDENTIAL', expected_http_status: '200',
  expected_native_outcome: 'UNKNOWN', actor_id: uuid(1), auth_session_id: uuid(2),
  current_auth_session_id: uuid(2), warehouse_id: uuid(3), draft_id: uuid(4),
  scan_session_id: uuid(5), operation_key: uuid(6), business_document_id: uuid(7),
  source_document_id: uuid(7), item_id: uuid(8), physical_code_id: uuid(9), command: 'SAVE',
};

function run(name, changes = {}, method = 'GET', body) {
  const item = collection.item.find(value => value.name === name);
  const ownerMode = name.startsWith('P04_') ? 'P04_SCOPED' : name.startsWith('P05_') ? 'P05_SOURCE_LINKED' : 'P07_CONFIRM';
  const values = { ...defaults, owner_mode: ownerMode, read_id: name, ...changes };
  const writes = [];
  const skipped = Symbol('skipRequest');
  const replace = text => text.replace(/\{\{([^}]+)\}\}/g, (all, key) => key === '$guid' ? uuid(100) : values[key] ?? all);
  const pm = {
    info: { requestName: name },
    request: { method, body, url: { toString: () => item.request.url } },
    variables: { get: key => values[key], replaceIn: replace, set: (key, value) => { writes.push(key); values[key] = value; } },
    execution: { skipRequest: () => { throw skipped; } },
  };
  try {
    vm.runInNewContext(script, { pm }, { timeout: 1000 });
    assert.deepEqual(writes, ['p15_read_trace']);
    assert.notEqual(values.p15_read_trace, values.operation_key);
    return true;
  } catch (error) {
    if (error === skipped) return false;
    throw error;
  }
}

for (const name of Object.keys(contract.reads)) {
  test(`preflight permits only the explicitly selected valid read: ${name}`, () => assert.equal(run(name), true));
  test(`preflight skips unselected read: ${name}`, () => assert.equal(run(name, { read_id: 'ANOTHER_READ' }), false));
}

for (const [label, changes] of Object.entries({
  'no live authorization': { live_read_authorized: 'false' },
  'owner gate still pending': { owner_gate_authorized: 'false' },
  'wrong trusted origin': { approved_api_origin: 'https://another.invalid' },
  'HTTP origin': { api_origin: 'http://p15.invalid', approved_api_origin: 'http://p15.invalid' },
  'legacy mode': { owner_mode: 'P04_LEGACY' },
  'missing operation key': { operation_key: '' },
  'display number instead of UUID': { draft_id: 'PN-20261009-1234' },
  'old session after reauthentication': { current_auth_session_id: uuid(99) },
  'new unapproved outcome': { expected_native_outcome: 'NOT_RECORDED' },
  'unapproved command': { command: 'RETRY' },
  'no expected HTTP status': { expected_http_status: '' },
})) {
  test(`preflight blocks ${label}`, () => assert.equal(run('P04_RECONCILE', changes), false));
}

test('standalone outbound is never routed to P03 linked recovery', () => assert.equal(run('P05_RECONCILE', { owner_mode: 'P05_STANDALONE' }), false));
test('linked recovery requires the original source identity', () => assert.equal(run('P05_RECONCILE', { source_document_id: '' }), false));
test('linked current document must be the selected source', () => assert.equal(run('P05_CURRENT_DOCUMENT', { business_document_id: uuid(99) }), false));
test('P07 confirmation requires the original PATCH key', () => assert.equal(run('P07_CONFIRMATION', { operation_key: '' }), false));
test('P07 PROCESSING stays native', () => assert.equal(run('P07_CONFIRMATION', { expected_native_outcome: 'PROCESSING' }), true));
test('P04 cannot borrow P07 PROCESSING', () => assert.equal(run('P04_RECONCILE', { expected_native_outcome: 'PROCESSING' }), false));
test('preflight cannot dispatch a writer', () => assert.equal(run('P04_RECONCILE', {}, 'POST'), false));
test('preflight forbids a GET body', () => assert.equal(run('P04_RECONCILE', {}, 'GET', { raw: '{}' }), false));

test('every collection request exactly matches the contract path and query', () => {
  for (const item of collection.item) {
    const spec = contract.reads[item.name];
    assert.equal(item.request.method, 'GET');
    assert.equal(item.request.body, undefined);
    let expected = spec.path;
    for (const [parameter, variable] of Object.entries(spec.path_vars || {})) expected = expected.replace(`{${parameter}}`, `{{${variable}}}`);
    const [url, rawQuery = ''] = item.request.url.split('?');
    assert.equal(url, `{{api_origin}}${expected}`);
    const query = new URLSearchParams(rawQuery);
    assert.deepEqual([...query.keys()].sort(), [...spec.query].sort());
    for (const key of spec.query) assert.equal(query.get(key), spec.fixed_query?.[key] || `{{${key}}}`);
    const headers = Object.fromEntries(item.request.header.map(header => [header.key, header.value]));
    assert.equal(headers['If-Match'], undefined);
    assert.equal(headers['Idempotency-Key'], spec.headers ? '{{operation_key}}' : undefined);
  }
});
