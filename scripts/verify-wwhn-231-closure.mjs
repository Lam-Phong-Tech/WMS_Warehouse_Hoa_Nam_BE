#!/usr/bin/env node
/**
 * WWHN-231 — verifier fail-closed cho phạm vi sau re-scope (DISP-STK-231).
 *
 * Verifier này KHÔNG chạy test; nó kiểm những mệnh đề mà một lần merge sai có thể phá âm thầm:
 *   1. Baseline SRS còn giữ REQ-SRS-037 là Must (nếu SRS đổi thì guard phải được review lại,
 *      chứ không im lặng tiếp tục chặn).
 *   2. OpenAPI runtime + route file không khai bề mặt kiểm kê/variance/sửa tồn trực tiếp.
 *   3. Baseline contract còn liệt đủ forbidden fragments.
 *   4. Aggregate ledger còn đúng MỘT implementation (service), report và command đều dùng nó.
 *   5. StockLedger không mọc lại entry point scan (scanReceipt/scanIssue của nhánh m1-be-2).
 *   6. Evidence pack có mặt đủ.
 *
 * Chạy:  node scripts/verify-wwhn-231-closure.mjs
 * Exit:  0 = PASS, 1 = FAIL
 */

import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const FE_DOCS = join(ROOT, '..', '..', 'HoaNam_FE', 'WMS_Warehouse_Hoa_Nam_FE', 'docs');

const failures = [];
const checks = [];

function check(name, condition, detail = '') {
  checks.push({ name, ok: Boolean(condition), detail });
  if (!condition) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
}

function read(path) {
  return existsSync(path) ? readFileSync(path, 'utf8') : '';
}

// 1. Controlling requirement còn hiệu lực.
const srsCandidates = [join(ROOT, 'docs', 'HN_SRS_FRD_v1.12_FULL.md'), join(FE_DOCS, 'HN_SRS_FRD_v1.12_FULL.md')];
const srsPath = srsCandidates.find((path) => existsSync(path));
const srs = read(srsPath ?? '');
check('SRS v1.12 đọc được', srs !== '', srsPath ? '' : `không thấy ở ${srsCandidates.join(' | ')}`);
check(
  'REQ-SRS-037 còn là Must',
  /REQ-SRS-037[\s\S]{0,600}?\|\s*Must\s*\|/.test(srs),
  'nếu SRS hạ requirement này thì phải review lại DISP-STK-231 thay vì giữ guard'
);
check('SRS §7.5 Reconciliation Controls còn nguyên', srs.includes('Reconciliation Controls'));

// 2. Bề mặt API.
const FORBIDDEN = ['stocktake', 'adjustment', 'variance'];
const openapiPath = join(ROOT, 'storage', 'api-docs', 'api-docs.json');
const openapi = read(openapiPath);
check('OpenAPI runtime tồn tại', openapi !== '', openapiPath);
if (openapi !== '') {
  const paths = Object.keys(JSON.parse(openapi).paths ?? {});
  const hits = paths.filter((path) => FORBIDDEN.some((fragment) => path.toLowerCase().includes(fragment)));
  check('OpenAPI runtime không khai path sửa tồn trực tiếp', hits.length === 0, hits.join(', '));
}

const routeFiles = [join(ROOT, 'routes', 'api.php'), join(ROOT, 'Modules', 'Warehouse', 'routes', 'api.php')];
for (const file of routeFiles) {
  const source = read(file);
  const offending = source
    .split('\n')
    .filter((line) => /Route::(get|post|put|patch|delete)/.test(line))
    .filter((line) => FORBIDDEN.some((fragment) => line.toLowerCase().includes(fragment)));
  check(`Route file không khai bề mặt bị cấm: ${file.replace(ROOT, '.')}`, offending.length === 0, offending.join(' | '));
}

// 3. Baseline contract.
const contract = read(join(ROOT, 'docs', 'contracts', 'HoaNam_Current_Baseline_Contract.json'));
check('Baseline contract đọc được', contract !== '');
if (contract !== '') {
  const fragments = JSON.parse(contract).api?.forbidden_path_fragments ?? [];
  for (const fragment of FORBIDDEN) {
    check(`forbidden_path_fragments chứa "${fragment}"`, fragments.includes(fragment));
  }
}

// 4. Một nguồn aggregate duy nhất.
const service = read(join(ROOT, 'Modules', 'Warehouse', 'app', 'Services', 'LedgerReconciliationService.php'));
check('LedgerReconciliationService tồn tại', service !== '');
check(
  'Service chỉ đọc (không insert/update/delete tồn kho)',
  !/->(insert|update|delete|upsert)\(/.test(service.replace(/\/\*[\s\S]*?\*\//g, '')),
  'service đối soát không được ghi lên inventory_balances / stock_movements'
);

const report = read(join(ROOT, 'Modules', 'Warehouse', 'app', 'Http', 'Controllers', 'OperationalReportController.php'));
check('Report dùng service chung', report.includes('LedgerReconciliationService'));
check(
  'Report không còn gom ledger theo hình dạng join-trước-gom',
  !/groupBy\('m\.warehouse_id', 'm\.product_id', 'm\.stock_status', 'w\.code'/.test(report)
);

const command = read(join(ROOT, 'app', 'Console', 'Commands', 'ReconcileInventoryBalances.php'));
check('Lệnh đối soát dùng service chung', command.includes('LedgerReconciliationService'));

// 5. Không mọc lại entry point scan ghi tồn.
const ledger = read(join(ROOT, 'Modules', 'Warehouse', 'app', 'Services', 'StockLedger.php'));
check('StockLedger không có scanReceipt/scanIssue', !/function\s+scan(Receipt|Issue)\s*\(/.test(ledger));

// 6. Evidence pack.
for (const file of [
  'docs/decision-records/DISP-STK-231-ledger-rescope.md',
  'docs/evidence/WWHN-231/BENCHMARK.md',
  'docs/evidence/WWHN-231/BENCHMARK_RESULT.json',
  'docs/evidence/WWHN-231/CONTRACT_NO_CHANGE.json',
  'docs/evidence/WWHN-231/RECONCILIATION_RUN.json',
  'docs/evidence/WWHN-231/RECONCILIATION_RUN_POPULATED_20260819.json',
  'docs/evidence/WWHN-231/RECONCILIATION_RUN_POPULATED_RETRIEVABILITY_20260819.json',
  'docs/evidence/WWHN-231/SHA256SUMS',
  'tests/Feature/Wwhn231StockNeutralLedgerTest.php',
]) {
  check(`Evidence có mặt: ${file}`, existsSync(join(ROOT, file)));
}

// 6b. Manifest completeness — bài học DEV231-P1-04R3: artifact có thật trên m1 nhưng
// SHA256SUMS chưa refresh nên reviewer soi theo manifest kết luận "không tồn tại".
// Fail-closed: mọi file trong evidence dir phải nằm trong manifest VÀ hash phải khớp.
const EVIDENCE_DIR = join(ROOT, 'docs', 'evidence', 'WWHN-231');
const sumsRaw = read(join(EVIDENCE_DIR, 'SHA256SUMS'));
check('SHA256SUMS đọc được', sumsRaw !== '');
if (sumsRaw !== '') {
  const manifest = new Map(
    sumsRaw
      .split('\n')
      .map((line) => line.trim())
      .filter(Boolean)
      .map((line) => {
        const [hash, ...rest] = line.split(/\s+\*?/);
        return [rest.join(' ').replace(/^\.\//, '').replaceAll('\\', '/'), hash.toLowerCase()];
      })
  );
  const walk = (dir, prefix = '') =>
    readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
      entry.isDirectory() ? walk(join(dir, entry.name), `${prefix}${entry.name}/`) : [`${prefix}${entry.name}`]
    );
  const actualFiles = walk(EVIDENCE_DIR).filter((file) => file !== 'SHA256SUMS');
  const missing = actualFiles.filter((file) => !manifest.has(file));
  check('Mọi file evidence đều nằm trong SHA256SUMS', missing.length === 0, missing.join(', '));
  const mismatched = actualFiles
    .filter((file) => manifest.has(file))
    .filter((file) => createHash('sha256').update(readFileSync(join(EVIDENCE_DIR, file))).digest('hex') !== manifest.get(file));
  check('Hash mọi file khớp SHA256SUMS', mismatched.length === 0, mismatched.join(', '));
  const orphaned = [...manifest.keys()].filter((file) => !actualFiles.includes(file));
  check('SHA256SUMS không trỏ file ma (đã bị xoá)', orphaned.length === 0, orphaned.join(', '));
}

// 6c. Populated reconciliation evidence — mệnh đề nghiệp vụ cốt lõi (§7.5) phải giữ nguyên.
const populatedRaw = read(join(EVIDENCE_DIR, 'RECONCILIATION_RUN_POPULATED_20260819.json'));
if (populatedRaw !== '') {
  const populated = JSON.parse(populatedRaw);
  check('Populated run: PostgreSQL 16.x', String(populated.postgres_version).startsWith('16.'));
  check('Populated run: stock_neutral = true', populated.stock_neutral === true);
  check('Populated run: 3 divergence → 3 incident', populated.incidents_created === 3 && populated.incident_rows?.length === 3);
  const types = new Set((populated.incident_rows ?? []).map((row) => row.divergence_type));
  check(
    'Populated run: đủ 3 loại divergence §7.5',
    ['QUANTITY_MISMATCH', 'BALANCE_MISSING_FOR_LEDGER', 'LEDGER_MISSING_FOR_BALANCE'].every((t) => types.has(t))
  );
}

// 6d. Disposition đã effective (DEV231-P1-01R3) — nếu ai đó revert về PROPOSED thì phải FAIL.
const disposition = read(join(ROOT, 'docs', 'decision-records', 'DISP-STK-231-ledger-rescope.md'));
check('DISP-STK-231 status EFFECTIVE', /\|\s*Status\s*\|\s*\*\*EFFECTIVE\*\*/.test(disposition));
check('DISP-STK-231 có effective date', /\|\s*Effective date\s*\|\s*`2026-08-19`/.test(disposition));

// 7. Migration index còn nguyên.
const migration = read(
  join(ROOT, 'Modules', 'Warehouse', 'database', 'migrations', '2026_08_19_010000_add_ledger_dimension_index_to_stock_movements.php')
);
check('Migration index tồn tại', migration !== '');
check('Index đúng thứ tự dimension §7.5', migration.includes('(warehouse_id, product_id, stock_status)'));

for (const item of checks) {
  console.log(`${item.ok ? 'PASS' : 'FAIL'}  ${item.name}${item.ok || !item.detail ? '' : ` — ${item.detail}`}`);
}
console.log(`\n${checks.filter((item) => item.ok).length}/${checks.length} check PASS`);

if (failures.length > 0) {
  console.error(`\nWWHN-231 closure FAILED:\n- ${failures.join('\n- ')}`);
  process.exit(1);
}
console.log('WWHN-231 closure PASS');
