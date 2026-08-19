#!/usr/bin/env node
/**
 * WWHN-184 / DEV184-P1-01R — verifier tĩnh cho contract Postman luồng nhập kho.
 *
 * Reviewer đóng P1-01R ở trạng thái PARTIAL vì "Postman mutation version chaining
 * còn sai": bản cũ không bắt lại version sau Cancel/Post nên mọi state probe phía
 * sau gửi If-Match cũ. `cancelDocument()`/`postDocument()` gọi `assertVersion()`
 * TRƯỚC khi kiểm tra status, nên request đó dừng ở 412 STALE_VERSION và không bao
 * giờ chạm state guard — assertion 409 STATE_CONFLICT không thể pass.
 *
 * Newman chỉ phát hiện lỗi này khi có server chạy thật. Verifier này chặn ngay ở
 * tầng tĩnh để lỗi không quay lại lần nữa:
 *
 *   R1. Mọi mutation kỳ vọng 2xx phải set lại `inbound_version` từ `data.version`.
 *   R2. Mọi mutation kỳ vọng 2xx phải đối chiếu ETag với `data.version`.
 *   R3. Mọi probe kỳ vọng 409 STATE_CONFLICT phải gửi If-Match `{{inbound_version}}`
 *       (version hiện hành), không phải literal hay biến stale.
 *   R4. Probe kỳ vọng 412 STALE_VERSION phải gửi version cũ tường minh.
 *   R5. Sau mỗi mutation 2xx đổi trạng thái phải có một refetch GET trước probe kế.
 *   R6. Mã lỗi dùng trong assertion phải tồn tại trong source PHP (chống bịa mã,
 *       ví dụ IDEMPOTENCY_ERROR vốn thuộc luồng SKU chứ không phải luồng posting).
 *   R7. File environment không được commit giá trị bí mật.
 *
 * Usage: node scripts/verify-wwhn184-postman-contract.mjs <collection> <environment> <out.json>
 */
import fs from 'node:fs';
import path from 'node:path';

const [collectionPath, environmentPath, outPath] = process.argv.slice(2);
if (!collectionPath || !environmentPath || !outPath) {
  console.error('Usage: verify-wwhn184-postman-contract.mjs <collection> <environment> <out.json>');
  process.exit(2);
}

const repoRoot = process.cwd();
const collection = JSON.parse(fs.readFileSync(collectionPath, 'utf8'));
const environment = JSON.parse(fs.readFileSync(environmentPath, 'utf8'));

const failures = [];
const checks = [];
const fail = (rule, request, message) => failures.push({rule, request, message});
const pass = (rule, detail) => checks.push({rule, status: 'PASS', detail});

/* ------------------------------------------------------------------ *
 * Bảng mã lỗi hợp lệ: đọc thẳng từ source PHP, không hardcode ở đây.
 * ------------------------------------------------------------------ */
const SOURCE_FILES = [
  'app/Support/ConflictCode.php',
  'app/Support/IdempotencyKeyGuard.php',
  'app/Support/IdempotencyStore.php',
  'Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php',
  'Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php',
];
const knownErrorCodes = new Set();
for (const relative of SOURCE_FILES) {
  const absolute = path.join(repoRoot, relative);
  if (!fs.existsSync(absolute)) {
    fail('R6', relative, 'Source file dùng để trích mã lỗi không tồn tại.');
    continue;
  }
  const source = fs.readFileSync(absolute, 'utf8');
  for (const match of source.matchAll(/'([A-Z][A-Z0-9_]{4,})'/g)) {
    knownErrorCodes.add(match[1]);
  }
}

/* ------------------------------------------------------------------ *
 * Làm phẳng collection theo đúng thứ tự Newman sẽ chạy.
 * ------------------------------------------------------------------ */
const flat = [];
const walk = (items, folder) => {
  for (const item of items ?? []) {
    if (item.item) {
      walk(item.item, item.name);
      continue;
    }
    const script = (item.event ?? [])
      .filter(event => event.listen === 'test')
      .flatMap(event => event.script?.exec ?? [])
      .join('\n');
    const headers = Object.fromEntries(
      (item.request.header ?? []).map(header => [String(header.key).toLowerCase(), String(header.value)]),
    );
    const url = typeof item.request.url === 'string' ? item.request.url : (item.request.url?.raw ?? '');
    flat.push({
      name: item.name,
      folder,
      method: String(item.request.method).toUpperCase(),
      url,
      headers,
      script,
      expectedStatuses: [...script.matchAll(/to\.have\.status\((\d{3})\)/g)].map(m => Number(m[1])),
      expectedErrorCodes: [...script.matchAll(/error\.code\)\.to\.eql\('([A-Z0-9_]+)'\)/g)].map(m => m[1]),
      capturesVersion: /collectionVariables\.set\('inbound_version'/.test(script),
      assertsEtag: /headers\.get\('ETag'\)/.test(script),
      assertsStatusField: /data\.status|d\.status/.test(script),
    });
  }
};
walk(collection.item);

if (flat.length === 0) {
  fail('R0', collectionPath, 'Collection rỗng.');
}

/* R6 — mã lỗi phải có thật trong source. */
for (const request of flat) {
  for (const code of request.expectedErrorCodes) {
    if (!knownErrorCodes.has(code)) {
      fail('R6', request.name, `Assertion dùng error.code='${code}' nhưng mã này không tồn tại trong source PHP luồng nhập/posting.`);
    }
  }
}
if (!knownErrorCodes.has('IDEMPOTENCY_CONFLICT')) {
  fail('R6', 'ConflictCode.php', 'Không tìm thấy IDEMPOTENCY_CONFLICT trong source — bảng mã lỗi đã đổi, phải rà lại collection.');
}
for (const request of flat) {
  if (request.expectedErrorCodes.includes('IDEMPOTENCY_ERROR')) {
    fail('R6', request.name, 'IDEMPOTENCY_ERROR là mã của luồng SKU. Luồng posting dùng IDEMPOTENCY_CONFLICT (SRS §8.2 / ConflictCode::IDEMPOTENCY).');
  }
}

/* ------------------------------------------------------------------ *
 * Phân loại request theo tác động lên version.
 * ------------------------------------------------------------------ */
const MUTATION_PATHS = /\/inbound-documents(\/\{\{inbound_id\}\}\/(scan|unscan|cancel|post-receipt|reverse))?$/;
const isInboundMutation = request =>
  ['POST', 'PATCH'].includes(request.method) &&
  request.url.includes('/inbound-documents') &&
  MUTATION_PATHS.test(request.url.split('?')[0]);
const isRefetch = request => request.method === 'GET' && /\/inbound-documents\/\{\{inbound_id\}\}$/.test(request.url.split('?')[0]);
const expectsSuccess = request => request.expectedStatuses.some(status => status >= 200 && status < 300);

/* R1 + R2 — mutation thành công phải bắt lại version và đối chiếu ETag. */
for (const request of flat) {
  if (!isInboundMutation(request) || !expectsSuccess(request)) continue;
  if (!request.capturesVersion) {
    fail('R1', request.name, 'Mutation kỳ vọng 2xx nhưng không set lại collectionVariable `inbound_version` từ data.version. Mọi probe phía sau sẽ gửi If-Match cũ và bị 412 thay vì chạm state guard.');
  }
  if (!request.assertsEtag) {
    fail('R2', request.name, 'Mutation kỳ vọng 2xx nhưng không đối chiếu header ETag với data.version.');
  }
}
if (!failures.some(f => f.rule === 'R1')) pass('R1', 'Mọi mutation 2xx đều bắt lại inbound_version từ data.version.');
if (!failures.some(f => f.rule === 'R2')) pass('R2', 'Mọi mutation 2xx đều đối chiếu ETag với data.version.');

/* R3 — probe 409 STATE_CONFLICT phải dùng version hiện hành. */
let stateConflictProbes = 0;
for (const request of flat) {
  if (!request.expectedErrorCodes.includes('STATE_CONFLICT')) continue;
  stateConflictProbes += 1;
  const ifMatch = request.headers['if-match'];
  if (ifMatch === undefined) {
    fail('R3', request.name, 'Probe STATE_CONFLICT thiếu hẳn If-Match nên sẽ dừng ở 428 PRECONDITION_REQUIRED.');
  } else if (ifMatch !== '{{inbound_version}}') {
    fail('R3', request.name, `Probe STATE_CONFLICT gửi If-Match='${ifMatch}'. Phải là {{inbound_version}} (version vừa refresh) thì mới đi qua assertVersion() và chạm state guard.`);
  }
}
if (stateConflictProbes === 0) {
  fail('R3', collectionPath, 'Không có probe nào kỳ vọng 409 STATE_CONFLICT — contract state machine chưa được kiểm chứng.');
} else if (!failures.some(f => f.rule === 'R3')) {
  pass('R3', `${stateConflictProbes} probe STATE_CONFLICT đều dùng version hiện hành.`);
}

/* R4 — phải có probe chứng minh thứ tự guard: version trước state. */
const staleProbes = flat.filter(request => request.expectedErrorCodes.includes('STALE_VERSION'));
if (staleProbes.length === 0) {
  fail('R4', collectionPath, 'Thiếu probe 412 STALE_VERSION — không chứng minh được assertVersion() chạy trước state guard.');
}
const stalePrecedenceProbe = staleProbes.find(
  request => request.headers['if-match'] === '{{inbound_stale_version}}',
);
if (!stalePrecedenceProbe) {
  fail('R4', collectionPath, 'Thiếu probe gửi {{inbound_stale_version}} trên chứng từ đã ở trạng thái terminal. Đây là probe duy nhất chứng minh version guard đứng trước state guard.');
} else {
  pass('R4', `Probe thứ tự guard: "${stalePrecedenceProbe.name}".`);
}

/* R5 — mỗi mutation 2xx đổi trạng thái phải có refetch GET trước probe kế tiếp. */
for (let index = 0; index < flat.length; index += 1) {
  const request = flat[index];
  if (!isInboundMutation(request) || !expectsSuccess(request) || !request.capturesVersion) continue;
  const next = flat.slice(index + 1);
  const nextProbeIndex = next.findIndex(candidate => candidate.expectedErrorCodes.length > 0 || isInboundMutation(candidate));
  if (nextProbeIndex === -1) continue;
  const between = next.slice(0, nextProbeIndex);
  const changesState = /'(CANCELLED|POSTED|SCANNING)'/.test(request.script) || /cancel|post-receipt|scan$/.test(request.url);
  if (changesState && !between.some(isRefetch)) {
    fail('R5', request.name, `Sau mutation này không có refetch GET /inbound-documents/{{inbound_id}} trước probe kế tiếp ("${next[nextProbeIndex].name}"). Không chứng minh được version/status đã đồng bộ.`);
  }
}
if (!failures.some(f => f.rule === 'R5')) pass('R5', 'Mọi mutation đổi trạng thái đều có refetch GET trước probe kế tiếp.');

/* R7 — environment không được chứa secret đã commit. */
const environmentValues = environment.values ?? [];
const secretKeys = ['managerPassword', 'staffPassword', 'viewerPassword'];
for (const key of secretKeys) {
  const entry = environmentValues.find(value => value.key === key);
  if (!entry) {
    fail('R7', environmentPath, `Environment thiếu key '${key}' — Newman sẽ không inject được credential runtime.`);
    continue;
  }
  if (String(entry.value ?? '') !== '') {
    fail('R7', environmentPath, `Environment commit sẵn giá trị cho '${key}'. Credential chỉ được inject lúc chạy qua --env-var.`);
  }
}
const requiredEnvKeys = ['base_url', 'managerEmail', 'staffEmail', 'viewerEmail', ...secretKeys];
for (const key of requiredEnvKeys) {
  if (!environmentValues.some(value => value.key === key)) {
    fail('R7', environmentPath, `Environment thiếu key bắt buộc '${key}'.`);
  }
}
if (!failures.some(f => f.rule === 'R7')) pass('R7', 'Environment khai đủ key và không commit secret.');

/* ------------------------------------------------------------------ *
 * Kết quả
 * ------------------------------------------------------------------ */
const report = {
  schema_version: '1.0.0',
  jira_key: 'WWHN-184',
  register_id: 'DEV184-P1-01R',
  collection: path.basename(collectionPath),
  environment: path.basename(environmentPath),
  request_count: flat.length,
  mutation_count: flat.filter(isInboundMutation).length,
  refetch_count: flat.filter(isRefetch).length,
  state_conflict_probe_count: stateConflictProbes,
  stale_version_probe_count: staleProbes.length,
  checks,
  failures,
  summary: {status: failures.length === 0 ? 'PASS' : 'FAIL', failure_count: failures.length},
};

fs.mkdirSync(path.dirname(path.resolve(outPath)), {recursive: true});
fs.writeFileSync(outPath, `${JSON.stringify(report, null, 2)}\n`);

if (failures.length > 0) {
  console.error('WWHN-184 Postman contract verifier: FAIL');
  for (const failure of failures) {
    console.error(`  [${failure.rule}] ${failure.request}: ${failure.message}`);
  }
  process.exit(1);
}
console.log(`WWHN-184 Postman contract verifier: PASS (${flat.length} requests, ${report.mutation_count} mutations, ${report.refetch_count} refetches)`);
