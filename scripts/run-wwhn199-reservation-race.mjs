/**
 * DEV199-P1-01R — race thật cho quy tắc reservation của luồng "lấy hàng/quét mã".
 *
 * Khác biệt với test PHPUnit hiện có (Wwhn199ReservationConcurrencyGuardTest):
 * test đó chạy tuần tự trong MỘT process/MỘT connection nên chỉ chứng minh
 * "loser quan sát được gì SAU KHI winner commit". Script này bắn hai request
 * ĐỒNG THỜI tới HAI server process khác nhau (hai cổng, hai PDO connection tới
 * cùng PostgreSQL), nên hai transaction thật sự chồng lấn và phải tranh row lock
 * trên item_instances.
 *
 * Hai bất biến được kiểm:
 *   A. Item-level  — cùng một item vật lý không được match ACTIVE vào 2 phiếu xuất.
 *                    Winner 200, loser 409 ITEM_ALREADY_MATCHED / DUPLICATE_ITEM_*.
 *   B. Toa-level   — cùng một toa (outbound_request_import) chỉ sinh đúng 1 phiếu xuất.
 *                    Winner 200, loser 409.
 *
 * Dùng:
 *   node scripts/run-wwhn199-reservation-race.mjs \
 *     build/evidence/wwhn-199/race-fixture.json \
 *     build/evidence/wwhn-199/race-report.json \
 *     http://127.0.0.1:8199 http://127.0.0.1:8299
 */
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';

const fixturePath = process.argv[2] || 'build/evidence/wwhn-199/race-fixture.json';
const reportPath = process.argv[3] || 'build/evidence/wwhn-199/race-report.json';
const baseA = (process.argv[4] || 'http://127.0.0.1:8199').replace(/\/$/, '');
const baseB = (process.argv[5] || 'http://127.0.0.1:8299').replace(/\/$/, '');

const fixture = JSON.parse(await readFile(fixturePath, 'utf8'));
const rawLog = [];

function log(line) {
  rawLog.push(line);
  process.stdout.write(`${line}\n`);
}

/** Mốc thời gian tuyệt đối để chứng minh hai request chồng lấn, không phải nối đuôi. */
function nowMs() {
  return Number(process.hrtime.bigint() / 1000n) / 1000;
}

async function call(base, method, path, body, extraHeaders = {}) {
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    Authorization: `Bearer ${fixture.token}`,
    ...extraHeaders,
  };
  const startedAt = nowMs();
  const startedIso = new Date().toISOString();
  let response;
  let text = '';
  try {
    response = await fetch(`${base}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    text = await response.text();
  } catch (error) {
    return {
      base,
      method,
      path,
      status: 0,
      error_code: 'TRANSPORT_ERROR',
      message: String(error),
      started_at: startedAt,
      finished_at: nowMs(),
      started_iso: startedIso,
    };
  }
  const finishedAt = nowMs();
  let json = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = { non_json_body: text.slice(0, 400) };
  }

  return {
    base,
    method,
    path,
    status: response.status,
    error_code: json?.error?.code ?? json?.error_code ?? null,
    message: json?.message ?? null,
    data_id: json?.data?.id ?? null,
    import_id: json?.data?.import?.id ?? null,
    import_status: json?.data?.import?.status ?? null,
    import_version: json?.data?.import?.version ?? null,
    outbound_document_ids: json?.data?.outbound_document_ids ?? null,
    started_at: startedAt,
    finished_at: finishedAt,
    started_iso: startedIso,
    duration_ms: Number((finishedAt - startedAt).toFixed(3)),
  };
}

/** Hai khoảng [start,finish] giao nhau ⇒ hai request thật sự cùng bay. */
function overlapMs(left, right) {
  return Number(
    (Math.min(left.finished_at, right.finished_at) - Math.max(left.started_at, right.started_at)).toFixed(3),
  );
}

function classify(results, winnerStatus, acceptedLoserCodes) {
  const winners = results.filter((item) => item.status === winnerStatus);
  const losers = results.filter((item) => item.status === 409);
  return {
    winner_count: winners.length,
    loser_count: losers.length,
    loser_codes: losers.map((item) => item.error_code),
    ok:
      winners.length === 1 &&
      losers.length === 1 &&
      acceptedLoserCodes.includes(losers[0].error_code ?? ''),
  };
}

/** Server built-in của PHP boot lại mỗi request; warm-up để hai racer không lệch pha. */
async function warmUp() {
  const results = await Promise.all([
    call(baseA, 'GET', '/api/v1/health'),
    call(baseB, 'GET', '/api/v1/health'),
  ]);
  log(
    `[warm-up] ${results.map((item) => `${item.base} -> HTTP ${item.status} (${item.duration_ms}ms)`).join(' | ')}`,
  );
}

const roundReports = [];

await warmUp();
await warmUp();

log('');
log('=== A. RACE ITEM-LEVEL: cung 1 item vat ly, 2 phieu xuat khac nhau, 2 server process ===');

for (const round of fixture.rounds ?? []) {
  const requestA = {
    line_id: round.document_a.line_id,
    quantity: 1,
    item_id: round.item_id,
  };
  const requestB = {
    line_id: round.document_b.line_id,
    quantity: 1,
    item_id: round.item_id,
  };

  const [resultA, resultB] = await Promise.all([
    call(baseA, 'POST', `/api/v1/outbound-documents/${round.document_a.document_id}/scan`, requestA, {
      'If-Match': '1',
    }),
    call(baseB, 'POST', `/api/v1/outbound-documents/${round.document_b.document_id}/scan`, requestB, {
      'If-Match': '1',
    }),
  ]);

  const overlap = overlapMs(resultA, resultB);
  const verdict = classify([resultA, resultB], 200, [
    'ITEM_ALREADY_MATCHED',
    'DUPLICATE_ITEM_MATCH',
    'DUPLICATE_ITEM_IN_DOCUMENT',
  ]);

  log(
    `[A round ${round.round}] item=${round.item_id} overlap=${overlap}ms ` +
      `| ${baseA} doc=${round.document_a.document_id} -> HTTP ${resultA.status} ${resultA.error_code ?? 'OK'} ` +
      `(t0=${resultA.started_at.toFixed(3)} t1=${resultA.finished_at.toFixed(3)}) ` +
      `| ${baseB} doc=${round.document_b.document_id} -> HTTP ${resultB.status} ${resultB.error_code ?? 'OK'} ` +
      `(t0=${resultB.started_at.toFixed(3)} t1=${resultB.finished_at.toFixed(3)}) ` +
      `=> ${verdict.ok ? 'ONE WINNER / ONE CONFLICT' : 'INVARIANT VIOLATED'}`,
  );

  roundReports.push({
    scenario: 'ITEM_LEVEL_DOUBLE_MATCH',
    round: round.round,
    item_id: round.item_id,
    overlapped: overlap > 0,
    overlap_ms: overlap,
    results: [resultA, resultB],
    verdict,
  });
}

log('');
log('=== B. RACE TOA-LEVEL: 2 Confirm dong thoi cung 1 toa (outbound_request_import) ===');

const importRow = {
  request_no: `${fixture.prefix}-TOA-CONFIRM-${Date.now()}`,
  customer_name: 'Dai ly race',
  sku_id: fixture.sku_id,
  required_qty: 1,
};

const created = await call(baseA, 'POST', '/api/v1/outbound-request-imports', { rows: [importRow] });
log(
  `[B setup] POST /outbound-request-imports -> HTTP ${created.status} ` +
    `import_status=${created.import_status ?? created.error_code ?? 'n/a'} id=${created.import_id ?? 'n/a'} ` +
    `version=${created.import_version ?? 'n/a'}`,
);

let confirmReport = null;
if ((created.status === 201 || created.status === 200) && created.import_id) {
  const importId = created.import_id;
  const importVersion = Number(created.import_version ?? 0);
  const [confirmA, confirmB] = await Promise.all([
    call(baseA, 'POST', `/api/v1/outbound-request-imports/${importId}/confirm`, {}, {
      'If-Match': String(importVersion),
      'Idempotency-Key': `wwhn199-confirm-a-${importId}`,
    }),
    call(baseB, 'POST', `/api/v1/outbound-request-imports/${importId}/confirm`, {}, {
      'If-Match': String(importVersion),
      'Idempotency-Key': `wwhn199-confirm-b-${importId}`,
    }),
  ]);

  const overlap = overlapMs(confirmA, confirmB);
  const verdict = classify([confirmA, confirmB], 200, [
    'IMPORT_ALREADY_CONSUMED',
    'IMPORT_NOT_CONFIRMABLE',
    // Loser đọc lại import sau khi winner commit: import đã rời VALIDATED nên
    // guard trạng thái chặn trước guard toa — vẫn đúng bất biến "1 toa - 1 phiếu".
    'STATE_CONFLICT',
    'OUTBOUND_REQUEST_ALREADY_USED',
    'STALE_VERSION',
    'IDEMPOTENCY_IN_PROGRESS',
    'CONCURRENCY_CONFLICT',
  ]);

  log(
    `[B] import=${importId} overlap=${overlap}ms ` +
      `| ${baseA} -> HTTP ${confirmA.status} ${confirmA.error_code ?? 'OK'} docs=${JSON.stringify(confirmA.outbound_document_ids)} ` +
      `| ${baseB} -> HTTP ${confirmB.status} ${confirmB.error_code ?? 'OK'} docs=${JSON.stringify(confirmB.outbound_document_ids)} ` +
      `=> ${verdict.ok ? 'ONE WINNER / ONE CONFLICT' : 'INVARIANT VIOLATED'}`,
  );

  confirmReport = {
    scenario: 'REQUEST_LEVEL_DOUBLE_CONFIRM',
    import_id: importId,
    request_no: importRow.request_no,
    overlapped: overlap > 0,
    overlap_ms: overlap,
    results: [confirmA, confirmB],
    verdict,
  };
  roundReports.push(confirmReport);
} else {
  log(`[B] KHONG tao duoc import — bo qua kich ban B. Body: ${created.message ?? ''}`);
}

const itemRounds = roundReports.filter((item) => item.scenario === 'ITEM_LEVEL_DOUBLE_MATCH');
const summary = {
  schema_version: '1.0.0',
  task: 'WWHN-199',
  register_item: 'DEV199-P1-01R',
  fixture: {
    database: fixture.database,
    driver: fixture.driver,
    generated_at: fixture.generated_at,
    sku_code: fixture.sku_code,
  },
  endpoints: { racer_a: baseA, racer_b: baseB },
  item_level: {
    rounds: itemRounds.length,
    overlapped_rounds: itemRounds.filter((item) => item.overlapped).length,
    passed_rounds: itemRounds.filter((item) => item.verdict.ok).length,
  },
  request_level: confirmReport
    ? {
        executed: true,
        overlapped: confirmReport.overlapped,
        passed: confirmReport.verdict.ok,
      }
    : { executed: false, overlapped: false, passed: false },
  rounds: roundReports,
};

summary.result =
  summary.item_level.rounds > 0 &&
  summary.item_level.passed_rounds === summary.item_level.rounds &&
  summary.item_level.overlapped_rounds > 0 &&
  summary.request_level.executed &&
  summary.request_level.passed
    ? 'PASS'
    : 'FAIL';

log('');
log(
  `[summary] item-level ${summary.item_level.passed_rounds}/${summary.item_level.rounds} round dung bat bien, ` +
    `${summary.item_level.overlapped_rounds} round co chong lan thuc su; ` +
    `toa-level executed=${summary.request_level.executed} passed=${summary.request_level.passed} ` +
    `=> ${summary.result}`,
);

await mkdir(dirname(reportPath), { recursive: true });
await writeFile(reportPath, `${JSON.stringify(summary, null, 2)}\n`, 'utf8');
// Đuôi .txt chứ không phải .log: `.gitignore` của repo bỏ qua `*.log`, mà raw race log
// chính là bằng chứng mà register DEV199-P1-01R đòi phải commit được.
await writeFile(reportPath.replace(/\.json$/, '.raw.txt'), `${rawLog.join('\n')}\n`, 'utf8');

if (summary.result !== 'PASS') {
  process.exitCode = 1;
}
