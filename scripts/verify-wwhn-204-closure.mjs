#!/usr/bin/env node
/**
 * WWHN-204 — verifier fail-closed cho register khắc phục DEV204-P1-01..P1-05.
 *
 * Verifier KHÔNG chạy test (QA1 chạy riêng theo docs/evidence/WWHN-204/QA1_RUNBOOK.md).
 * Nó kiểm những mệnh đề mà một lần merge/rebase sai có thể phá âm thầm:
 *
 *   P1-01  ancestry m1 ↔ m1-be-2 và reachability của commit hiện thực WWHN-204
 *   P1-02  evidence pack + runbook + log kết quả suite có mặt
 *   P1-03  cơ chế rebuild tồn tại, dựng từ ledger, KHÔNG ghi ledger, không lên HTTP
 *   P1-04  hai migration append-only tồn tại cho cả hai driver + log bằng chứng PG có mốc PASS
 *   P1-05  decision record + source pin + baseline SRS còn giữ điều khoản neo vào
 *
 * Mọi SHA lấy từ docs/evidence/WWHN-204/WWHN-204_PINS.json — đúng một nguồn duy nhất, nên pin
 * lệch sẽ làm đỏ verifier thay vì âm thầm sai (bài học pin rot của WWHN-290).
 *
 * Chạy:  node scripts/verify-wwhn-204-closure.mjs
 * Exit:  0 = PASS, 1 = FAIL
 */

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const FE_DOCS = join(ROOT, '..', '..', 'HoaNam_FE', 'WMS_Warehouse_Hoa_Nam_FE', 'docs');
const EV = join(ROOT, 'docs', 'evidence', 'WWHN-204');

const failures = [];
const checks = [];

function check(name, condition, detail = '') {
  checks.push({ name, ok: Boolean(condition), detail });
  if (!condition) failures.push(`${name}${detail ? ` — ${detail}` : ''}`);
}

function read(path) {
  return existsSync(path) ? readFileSync(path, 'utf8') : '';
}

function git(args) {
  try {
    return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' }).trim();
  } catch {
    return null;
  }
}

function gitOk(args) {
  try {
    execFileSync('git', args, { cwd: ROOT, stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

// ---------------------------------------------------------------------------
// Pin source — đúng một nguồn cho mọi SHA
// ---------------------------------------------------------------------------
const pinsRaw = read(join(EV, 'WWHN-204_PINS.json'));
check('PINS.json đọc được', pinsRaw !== '', join(EV, 'WWHN-204_PINS.json'));
let pins = null;
try {
  pins = JSON.parse(pinsRaw || '{}');
} catch (error) {
  check('PINS.json parse được', false, String(error));
}
const IMPL_SHA = pins?.immutable_commits?.wwhn204_implementation?.sha ?? '';
const ANCESTOR_SHA = pins?.immutable_commits?.m1_be_2_head?.sha ?? '';
check('PINS.json có SHA hiện thực WWHN-204', /^[0-9a-f]{40}$/.test(IMPL_SHA), IMPL_SHA);
check('PINS.json có SHA HEAD của m1-be-2', /^[0-9a-f]{40}$/.test(ANCESTOR_SHA), ANCESTOR_SHA);
check('PINS.json chốt candidate branch = m1', pins?.candidate_branch?.branch === 'm1');

// ---------------------------------------------------------------------------
// P1-01 — branch candidate
// ---------------------------------------------------------------------------
const hasAncestorRef = gitOk(['rev-parse', '--verify', '--quiet', `${ANCESTOR_SHA}^{commit}`]);
if (!hasAncestorRef) {
  // Không có object m1-be-2 trong clone (ví dụ shallow fetch) — báo rõ thay vì PASS giả.
  check(
    'INV-BRANCH: có object của m1-be-2 để dựng lại ancestry',
    false,
    `chạy: git fetch origin m1-be-2 (thiếu ${ANCESTOR_SHA})`
  );
} else {
  const mergeBase = git(['merge-base', 'HEAD', ANCESTOR_SHA]);
  check(
    'INV-BRANCH-01 merge-base(HEAD, m1-be-2) == HEAD của m1-be-2',
    mergeBase === ANCESTOR_SHA,
    `merge-base=${mergeBase}`
  );
  const behind = git(['rev-list', '--count', `HEAD..${ANCESTOR_SHA}`]);
  check('INV-BRANCH-02 nhánh hiện tại không behind m1-be-2', behind === '0', `behind=${behind}`);
  check(
    'INV-BRANCH-04 commit WWHN-204 KHÔNG có trên m1-be-2 (nên m1-be-2 không thể là candidate)',
    !gitOk(['merge-base', '--is-ancestor', IMPL_SHA, ANCESTOR_SHA])
  );
}
check(
  'INV-BRANCH-03 commit hiện thực WWHN-204 nằm trên nhánh đang đứng',
  gitOk(['merge-base', '--is-ancestor', IMPL_SHA, 'HEAD']),
  `${IMPL_SHA} không phải ancestor của HEAD`
);
check('Đang đứng trên nhánh m1', git(['rev-parse', '--abbrev-ref', 'HEAD']) === 'm1');

// ---------------------------------------------------------------------------
// P1-03 — cơ chế rebuild
// ---------------------------------------------------------------------------
const SERVICE = join(ROOT, 'Modules', 'Warehouse', 'app', 'Services', 'InventoryProjectionRebuildService.php');
const service = read(SERVICE);
check('Service rebuild tồn tại', service !== '', SERVICE);
check(
  'Rebuild lấy giá trị đích từ ledger (LedgerReconciliationService)',
  /LedgerReconciliationService/.test(service) && /ledgerTotals\(/.test(service)
);
check(
  'Rebuild KHÔNG ghi stock_movements (không insert/update/delete lên ledger)',
  !/table\('stock_movements'\)[\s\S]{0,300}?->(insert|update|delete|truncate)\(/.test(service),
  'phát hiện lệnh ghi lên ledger trong service rebuild'
);
check(
  'Rebuild có bất biến fingerprint ledger trước/sau',
  /ledgerFingerprint\(/.test(service) && /LEDGER_MUTATED_DURING_REBUILD/.test(service)
);
check('Rebuild ghi audit_log', /INVENTORY_PROJECTION_REBUILT/.test(service));
check('Rebuild đóng incident kèm resolution_ref', /resolution_ref/.test(service) && /RESOLVED/.test(service));

const COMMAND = join(ROOT, 'app', 'Console', 'Commands', 'ReconcileInventoryBalances.php');
const command = read(COMMAND);
check('Lệnh inventory:reconcile có cờ --rebuild', /\{--rebuild/.test(command));
check('Lệnh có --dry-run và --reason', /\{--dry-run/.test(command) && /\{--reason/.test(command));
check(
  'Bước phát hiện chạy TRƯỚC rebuild (giữ dấu vết divergence)',
  command.indexOf("option('rebuild')") > command.indexOf('recordIncident'),
  'nếu rebuild chạy trước thì §7.5 mất dấu vết đã từng lệch'
);

// Không được phơi rebuild lên HTTP (REQ-SRS-037 / AC-SRS-037).
const routeFiles = [
  join(ROOT, 'routes', 'api.php'),
  join(ROOT, 'Modules', 'Warehouse', 'routes', 'api.php'),
];
const routes = routeFiles.map(read).join('\n');
check('Route file đọc được', routes !== '');
for (const fragment of ['rebuild', 'stocktake', 'apply-adjustment']) {
  check(`Không có route chứa "${fragment}"`, !new RegExp(`['"\`][^'"\`]*${fragment}[^'"\`]*['"\`]`, 'i').test(routes));
}

const TEST = join(ROOT, 'tests', 'Feature', 'Wwhn204LedgerProjectionRebuildTest.php');
const testFile = read(TEST);
check('Test suite rebuild tồn tại', testFile !== '', TEST);
for (const testName of [
  'test_stock_movements_rejects_update_and_delete_at_database_level',
  'test_rebuild_restores_projection_to_ledger_after_intentional_drift',
  'test_rebuild_is_idempotent_on_a_consistent_system',
  'test_rebuild_dry_run_reports_plan_without_writing',
  'test_rebuild_handles_missing_and_orphan_projection_rows',
  'test_rebuild_is_not_exposed_as_any_http_route',
]) {
  check(`Test case có mặt: ${testName}`, testFile.includes(testName));
}

// ---------------------------------------------------------------------------
// P1-04 — append-only ở tầng DB trên cả hai driver
// ---------------------------------------------------------------------------
const MIG = join(ROOT, 'Modules', 'Warehouse', 'database', 'migrations');
const pgMigration = read(join(MIG, '2026_08_15_000100_enforce_stock_movements_append_only_trigger.php'));
const sqliteMigration = read(join(MIG, '2026_08_19_050100_enforce_stock_movements_append_only_on_sqlite.php'));
check('Migration append-only cho pgsql tồn tại', pgMigration !== '');
check('Migration append-only cho sqlite tồn tại', sqliteMigration !== '');
check(
  'Hai migration dùng CÙNG thông điệp bất biến',
  pgMigration.includes('append-only under HN-WMS-ERD-DD-001') &&
    sqliteMigration.includes('append-only under HN-WMS-ERD-DD-001')
);
check(
  'Migration sqlite chặn cả UPDATE lẫn DELETE',
  /'UPDATE'/.test(sqliteMigration) && /'DELETE'/.test(sqliteMigration)
);
check(
  'Migration sqlite chỉ gắn khi stock_movements là bảng vật lý',
  /sqlite_master[\s\S]{0,120}type\s*=\s*'table'/.test(sqliteMigration)
);

const pgLog = read(join(EV, 'logs', 'pg-append-only.log'));
check('Log bằng chứng PostgreSQL có mặt', pgLog !== '');
const PG_MARKERS = [
  ['PG engine/version được ghi', /PostgreSQL \d+\.\d+/],
  ['migration append-only ở trạng thái Ran', /2026_08_15_000100_enforce_stock_movements_append_only_trigger \.\. \[\d+\] Ran/],
  ['raw SQL UPDATE bị từ chối', /ERROR:\s+stock_movements is append-only under HN-WMS-ERD-DD-001/],
  ['mismatch về 0 sau rebuild', /balance_qty \| ledger_qty \| mismatch[\s\S]{0,200}?\|\s+0\s*$/m],
  ['incident chuyển RESOLVED kèm resolution_ref', /RESOLVED \| REBUILD:[0-9a-f-]{36}/],
  ['ledger bất biến trước-sau', /Ledger bất biến: \d+ dòng \/ tổng delta [\d.]+ \(không đổi trước-sau\)/],
];
for (const [name, pattern] of PG_MARKERS) {
  check(`Log PG — ${name}`, pattern.test(pgLog));
}

// ---------------------------------------------------------------------------
// P1-05 — baseline / decision / source pin
// ---------------------------------------------------------------------------
const DR = join(ROOT, 'docs', 'decision-records', 'DISP-INV-204-projection-rebuild.md');
const dr = read(DR);
check('Decision record DISP-INV-204 tồn tại', dr !== '', DR);
check('DISP-INV-204 neo vào §7.5 và REQ-SRS-037', /7\.5/.test(dr) && /REQ-SRS-037/.test(dr));
check('DISP-INV-204 khẳng định không hồi sinh stocktake/apply-adjustment', /DISP-STK-236/.test(dr));

const sourcePin = read(join(EV, 'SOURCE_BASELINE_PIN.md'));
check('SOURCE_BASELINE_PIN.md tồn tại', sourcePin !== '');
check('Source pin nêu rõ BRD v3.9 SUPERSEDED bởi v3.20', /v3\.9[\s\S]{0,200}v3\.20/.test(sourcePin));
check('Source pin dẫn Source Register hiện hành (SRC-002/SRC-008)', /SRC-002/.test(sourcePin) && /SRC-008/.test(sourcePin));

const srsPath = [join(ROOT, 'docs', 'HN_SRS_FRD_v1.12_FULL.md'), join(FE_DOCS, 'HN_SRS_FRD_v1.12_FULL.md')].find(existsSync);
const srs = read(srsPath ?? '');
check('SRS v1.12 đọc được', srs !== '', srsPath ? '' : 'không thấy ở BE lẫn FE docs');
check('SRS còn §7.5 Reconciliation Controls', srs.includes('Reconciliation Controls'));
check('SRS còn §7.1 DEC-INV-001 Source of Truth', /DEC-INV-001 Source of Truth/.test(srs));
check(
  'SRS §1.6 vẫn ghi BRD v3.9 là SUPERSEDED (nếu đổi thì source pin phải review lại)',
  /BRD v3\.9[\s\S]{0,200}?SUPERSEDED/.test(srs)
);
check(
  'REQ-SRS-037 còn là Must (guard no-stocktake còn hiệu lực)',
  /REQ-SRS-037[\s\S]{0,600}?\|\s*Must\s*\|/.test(srs)
);

// ---------------------------------------------------------------------------
// P1-02 — evidence pack
// ---------------------------------------------------------------------------
for (const file of [
  'BRANCH_CANDIDATE_PIN.md',
  'QA1_RUNBOOK.md',
  'PG_APPEND_ONLY_EVIDENCE.md',
  'SOURCE_BASELINE_PIN.md',
  'DEFECT_CLOSURE_REGISTER.md',
  'WWHN-204_PINS.json',
  'logs/pg-append-only.log',
  'logs/pg-suite.log',
  'logs/sqlite-focused.log',
]) {
  check(`Evidence có mặt: ${file}`, existsSync(join(EV, file)));
}
for (const [name, file] of [
  ['suite sqlite PASS', 'logs/sqlite-focused.log'],
  ['suite PostgreSQL PASS', 'logs/pg-suite.log'],
]) {
  const log = read(join(EV, file));
  check(`Log — ${name}`, /"result"\s*:\s*"passed"/.test(log) || /OK \(/.test(log), log.slice(0, 160));
}

// ---------------------------------------------------------------------------
const passed = checks.filter((c) => c.ok).length;
for (const c of checks) {
  console.log(`${c.ok ? 'PASS' : 'FAIL'}  ${c.name}${c.ok || !c.detail ? '' : ` — ${c.detail}`}`);
}
console.log('');
console.log(`WWHN-204 CLOSURE: ${failures.length === 0 ? 'PASS' : 'FAIL'} (${passed}/${checks.length} check)`);
if (failures.length > 0) {
  console.log('');
  for (const f of failures) console.log(`  - ${f}`);
  process.exit(1);
}
