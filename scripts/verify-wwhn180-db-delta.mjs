import fs from 'node:fs';

const [beforePath, afterPath, outputPath] = process.argv.slice(2);
if (!beforePath || !afterPath || !outputPath) {
  throw new Error('Thiếu tham số verify-wwhn180-db-delta');
}

const before = JSON.parse(fs.readFileSync(beforePath, 'utf8'));
const after = JSON.parse(fs.readFileSync(afterPath, 'utf8'));
const delta = (key) => Number(after.counts?.[key] ?? 0) - Number(before.counts?.[key] ?? 0);

const beforePostingIds = new Set((before.posting_events ?? []).map((row) => row.posting_event_id));
const newPostingEvents = (after.posting_events ?? []).filter((row) => !beforePostingIds.has(row.posting_event_id));
const beforeIdemBusinessKeys = new Set((before.idempotency_keys ?? []).map((row) => row.business_key));
const newIdempotencyRows = (after.idempotency_keys ?? []).filter((row) => !beforeIdemBusinessKeys.has(row.business_key));

const checks = {
  exactly_two_stock_movements: delta('stock_movements') === 2,
  exactly_two_posting_events: delta('posting_events') === 2,
  exactly_four_completed_idempotency_rows:
    delta('idempotency_keys') === 4
    && newIdempotencyRows.length === 4
    && newIdempotencyRows.every((row) => row.status === 'done' && Number(row.response_code) >= 200 && Number(row.response_code) < 300),
  posting_events_are_unique_and_committed:
    newPostingEvents.length === 2
    && new Set(newPostingEvents.map((row) => `${row.operation}|${row.idempotency_key}`)).size === 2
    && newPostingEvents.every((row) => row.status === 'COMMITTED'),
  receipt_and_issue_operations_present:
    newPostingEvents.some((row) => String(row.operation).startsWith('post_receipt_'))
    && newPostingEvents.some((row) => String(row.operation).startsWith('post_issue_')),
};

const failedChecks = Object.entries(checks)
  .filter(([, passed]) => !passed)
  .map(([name]) => name);

const output = {
  schema_version: '1.0.0',
  generated_at_utc: new Date().toISOString(),
  summary: {
    checked: Object.keys(checks).length,
    passed: Object.keys(checks).length - failedChecks.length,
    failed: failedChecks.length,
    status: failedChecks.length === 0 ? 'PASS' : 'FAIL',
  },
  count_delta: {
    stock_movements: delta('stock_movements'),
    posting_events: delta('posting_events'),
    idempotency_keys: delta('idempotency_keys'),
  },
  checks,
  failed_checks: failedChecks,
  new_posting_events: newPostingEvents,
  new_idempotency_rows: newIdempotencyRows,
};

fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);
if (failedChecks.length > 0) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}

console.log(`WWHN-180 DB delta PASS: ${output.summary.passed}/${output.summary.checked}`);
