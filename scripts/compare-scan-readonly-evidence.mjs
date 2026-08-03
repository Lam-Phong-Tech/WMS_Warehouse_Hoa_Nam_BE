import crypto from 'node:crypto';
import fs from 'node:fs';

const [beforePath, afterPath, outputPath] = process.argv.slice(2);
if (!beforePath || !afterPath || !outputPath) {
  throw new Error('Usage: node compare-scan-readonly-evidence.mjs <before.json> <after.json> <output.json>');
}

const before = JSON.parse(fs.readFileSync(beforePath, 'utf8'));
const after = JSON.parse(fs.readFileSync(afterPath, 'utf8'));
const countKeys = ['inventory_balances', 'stock_movements', 'posting_events', 'idempotency_keys', 'scan_events'];
const sections = [
  'balances',
  'signed_ledger_by_dimension',
  'posting_events',
  'idempotency_keys',
  'scan_events',
  'physical_items',
  'physical_container_items',
];

const canonical = (value) => JSON.stringify(value);
const sha256 = (value) => crypto.createHash('sha256').update(canonical(value)).digest('hex');
const findings = [];

for (const key of countKeys) {
  const beforeValue = Number(before.counts?.[key] ?? 0);
  const afterValue = Number(after.counts?.[key] ?? 0);
  findings.push({
    check: `count:${key}`,
    before: beforeValue,
    after: afterValue,
    result: beforeValue === afterValue ? 'PASS' : 'FAIL',
  });
}

for (const section of sections) {
  const beforeHash = sha256(before[section] ?? []);
  const afterHash = sha256(after[section] ?? []);
  findings.push({
    check: `sha256:${section}`,
    before_sha256: beforeHash,
    after_sha256: afterHash,
    result: beforeHash === afterHash ? 'PASS' : 'FAIL',
  });
}

const failed = findings.filter((finding) => finding.result === 'FAIL');
const output = {
  schema_version: '1.0.0',
  contract: 'WWHN-150 scan/classify read-only',
  generated_at_utc: new Date().toISOString(),
  summary: {
    checked: findings.length,
    passed: findings.length - failed.length,
    failed: failed.length,
    status: failed.length === 0 ? 'PASS' : 'FAIL',
  },
  findings,
};

fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);
if (failed.length > 0) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}
console.log(`Scan read-only evidence PASS: ${findings.length}/${findings.length}`);
