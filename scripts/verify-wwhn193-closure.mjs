#!/usr/bin/env node
/**
 * WWHN-193 — verifier đóng register 72/100 (DEV193-P1-01R..05R + EXT-DEP-01R).
 *
 * Lý do tồn tại: vòng trước bị bác vì "exact-build evidence chain chưa sạch" — SHA ghi tay
 * trong artifact không thể là SHA của chính revision chứa nó, và số liệu đọc từ file đã
 * commit thì cổng tự xác nhận chính mình. Verifier này kiểm ba thứ máy kiểm được:
 *
 *   1. RANGE INVARIANT: `base` trong PINS là ancestor của revision đang xét, và revision đó
 *      đúng bằng `--expect-sha` (SHA chính xác được khẳng định TỪ NGOÀI, vì không commit nào
 *      chứa được hash của chính nó).
 *   2. CONTENT PIN: mọi file thuộc ticket còn tồn tại và không rỗng; danh sách file là hợp
 *      đồng, thiếu file nào là đỏ.
 *   3. EVIDENCE FRESHNESS: artifact JSON phải do lần chạy đang xét sinh ra và mang đúng
 *      verdict/engine — không chấp nhận bản sqlite cho hạng mục đòi PostgreSQL.
 *
 * Dùng:
 *   node scripts/verify-wwhn193-closure.mjs [--expect-sha <sha>] [--require-evidence]
 *
 * `--require-evidence` chỉ bật trong CI (sau khi test đã chạy và ghi artifact). Chạy tay
 * không có cờ đó thì bỏ qua phần 3 để dev kiểm nhanh phần 1/2.
 */

import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const PINS = 'docs/evidence/WWHN-193/WWHN-193_PINS.json';

const problems = [];
const notes = [];

function argValue(flag) {
  const index = process.argv.indexOf(flag);
  return index !== -1 ? process.argv[index + 1] : null;
}

function git(...args) {
  return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' }).trim();
}

function readJson(relative) {
  const path = resolve(ROOT, relative);
  if (!existsSync(path)) {
    problems.push(`Thiếu file bắt buộc: ${relative}`);
    return null;
  }
  try {
    return JSON.parse(readFileSync(path, 'utf8'));
  } catch (error) {
    problems.push(`${relative} không phải JSON hợp lệ: ${error.message}`);
    return null;
  }
}

function requireNonEmpty(relative) {
  const path = resolve(ROOT, relative);
  if (!existsSync(path) || statSync(path).size === 0) {
    problems.push(`Thiếu hoặc rỗng: ${relative}`);
    return false;
  }
  return true;
}

// ── Hợp đồng file thuộc ticket ────────────────────────────────────────────────
// Thiếu bất kỳ dòng nào = fix không còn nguyên vẹn tại revision đang xét.
const CODE_PATHS = [
  'Modules/Warehouse/app/Http/Controllers/PutawayController.php',
  'Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php',
  'Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php',
];

const TEST_PATHS = [
  'tests/Feature/Concerns/PreparesPutawayFixtures.php',
  'tests/Feature/Wwhn193PutawayLifecycleTest.php',
  'tests/Feature/Wwhn193PutawayAuditFailClosedTest.php',
  'tests/Feature/Wwhn193PutawayRetryRecoveryContractTest.php',
  'tests/Feature/Wwhn193ContainerPutawayBypassRegressionTest.php',
  'tests/Feature/Wwhn193PutawayDependencyChainTest.php',
];

const DOC_PATHS = [
  PINS,
  'docs/evidence/WWHN-193/WWHN-193_DEFECT_FIX_REGISTER.md',
  'docs/evidence/WWHN-193/WWHN-193_QA1_HANDOFF.md',
  'docs/decision-records/DEC-PTW-RETRY-001-putaway-retry-recovery-contract.md',
];

// Dấu hiệu fix còn sống trong code, không chỉ "file có tồn tại".
const CODE_MARKERS = [
  ['Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php', 'PUTAWAY_MUST_USE_TASK_FLOW'],
  ['Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php', 'CONTAINER_STATUS_NOT_CREATABLE'],
  ['Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php', 'CONTAINER_STATUS_LIFECYCLE_MANAGED'],
  ['Modules/Warehouse/app/Http/Controllers/CurrentBaselinePutawayTaskController.php', 'assertPostedReceiptLinkage'],
  ['Modules/Warehouse/app/Http/Controllers/PutawayController.php', 'DEC-PTW-RETRY-001'],
  ['Modules/Warehouse/app/Http/Controllers/PutawayController.php', 'ALREADY_APPLIED_BY_CALLER'],
  ['Modules/Warehouse/app/Http/Controllers/PutawayController.php', 'assertReceiptLinkageStillValid'],
];

// Đường bypass đã bị xoá thì không được quay lại.
const FORBIDDEN = [
  ['Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerFlowController.php', 'recordPutaway'],
];

for (const relative of [...CODE_PATHS, ...TEST_PATHS, ...DOC_PATHS]) {
  requireNonEmpty(relative);
}

for (const [relative, marker] of CODE_MARKERS) {
  const path = resolve(ROOT, relative);
  if (existsSync(path) && !readFileSync(path, 'utf8').includes(marker)) {
    problems.push(`${relative} mất dấu hiệu fix "${marker}" — thay đổi WWHN-193 đã bị hoàn tác?`);
  }
}

for (const [relative, marker] of FORBIDDEN) {
  const path = resolve(ROOT, relative);
  if (existsSync(path) && readFileSync(path, 'utf8').includes(marker)) {
    problems.push(`${relative} lại xuất hiện "${marker}" — đường bypass putaway đã quay lại.`);
  }
}

// ── 1. Range invariant ────────────────────────────────────────────────────────
const pins = readJson(PINS);
const expectSha = argValue('--expect-sha');

if (pins) {
  const base = pins.change_range?.base;
  if (!base || !/^[0-9a-f]{40}$/.test(base)) {
    problems.push('PINS.change_range.base phải là SHA đầy đủ 40 ký tự.');
  } else {
    let head;
    try {
      head = git('rev-parse', 'HEAD');
    } catch (error) {
      problems.push(`Không đọc được HEAD: ${error.message}`);
    }

    if (head) {
      try {
        git('merge-base', '--is-ancestor', base, head);
        notes.push(`base ${base.slice(0, 12)} là ancestor của HEAD ${head.slice(0, 12)}`);
      } catch {
        problems.push(`base ${base} KHÔNG phải ancestor của HEAD ${head} — pin đã mục hoặc sai nhánh.`);
      }

      if (expectSha) {
        if (!/^[0-9a-f]{40}$/.test(expectSha)) {
          problems.push('--expect-sha phải là SHA đầy đủ 40 ký tự.');
        } else if (expectSha !== head) {
          problems.push(`--expect-sha ${expectSha} khác HEAD ${head}: đang kiểm nhầm revision.`);
        } else {
          notes.push(`exact SHA khớp: ${head}`);
        }
      } else {
        notes.push('Không truyền --expect-sha: bỏ qua khẳng định SHA chính xác (CI luôn phải truyền).');
      }
    }
  }

  const disposition = pins.source_conflict_disposition ?? {};
  if (disposition.decision === undefined || !String(disposition.decision).includes('RE-PIN')) {
    problems.push('PINS thiếu disposition RE-PIN cho DEV193-P1-01R (m1-be-2 vs m1).');
  }
  if (pins.canonical_source?.be_branch !== 'm1') {
    problems.push('canonical_source.be_branch phải là m1 sau khi re-pin.');
  }
}

// ── 2. Register phải nêu đủ mọi ID và giữ fail-closed cho mục của QA/BA ────────
const registerPath = resolve(ROOT, 'docs/evidence/WWHN-193/WWHN-193_DEFECT_FIX_REGISTER.md');
if (existsSync(registerPath)) {
  const register = readFileSync(registerPath, 'utf8');
  for (const id of [
    'DEV193-P1-01R', 'DEV193-P1-02R', 'DEV193-P1-03R',
    'DEV193-P1-04R', 'DEV193-P1-05R', 'EXT-DEP-01R', 'DEV193-P2-OLD-DEADCODE',
  ]) {
    if (!register.includes(id)) {
      problems.push(`Register thiếu mục ${id}.`);
    }
  }
  if (!register.includes('FAIL-CLOSED / NEEDS OWNER DISPOSITION')) {
    problems.push('Register phải giữ FAIL-CLOSED cho mục thuộc QA1/BA — DEV không tự ký.');
  }
}

// ── 3. Artifact phải do lần chạy đang xét sinh ra ─────────────────────────────
if (process.argv.includes('--require-evidence')) {
  const retry = readJson('build/evidence/wwhn-193/retry-recovery-proof.json');
  if (retry) {
    for (const dimension of [
      'response_loss_replay',
      'same_key_different_payload',
      'concurrent_same_operation',
      'recovery_without_key_same_actor',
      'recovery_without_key_other_actor',
      'cancel_after_confirm',
      'cancel_replay',
      'idempotency_key_optional',
    ]) {
      if (!retry[dimension]) {
        problems.push(`retry-recovery-proof.json thiếu chiều: ${dimension}`);
      } else if (!String(retry[dimension].verdict ?? '').startsWith('PASS')) {
        problems.push(`Chiều retry không PASS: ${dimension}`);
      }
    }
    if (retry.response_loss_replay?.retry?.replayed !== true) {
      problems.push('Chiều mất-response không chứng minh được replayed=true.');
    }
    if (retry.recovery_without_key_other_actor?.leaks_other_actor_id !== false) {
      problems.push('Envelope recovery làm lộ id của actor khác.');
    }
  }

  const chain = readJson('build/evidence/wwhn-193/dependency-chain-proof.json');
  if (chain) {
    const reconcile = chain.dependency_chain?.reconcile ?? {};
    if (Number(reconcile.mismatch_count) !== 0) {
      problems.push(`EXT-DEP-01R: đối soát mismatch_count = ${reconcile.mismatch_count}, phải là 0.`);
    }
    if (!(Number(reconcile.rows_checked) > 0)) {
      problems.push('EXT-DEP-01R: rows_checked = 0 — đối soát không kiểm gì cả.');
    }
    if (chain.dependency_chain?.same_build !== true) {
      problems.push('EXT-DEP-01R: chuỗi phụ thuộc không được khẳng định chạy trên cùng build.');
    }
    if (!chain.negative_dependency) {
      problems.push('EXT-DEP-01R: thiếu bài ngược (chưa POSTED thì không được Putaway).');
    }
  }

  // DEV193-P1-04R đòi ĐÍCH DANH PostgreSQL. Bản sqlite không thay thế được.
  const pg = readJson('build/evidence/wwhn-193/audit-fail-closed-proof-pgsql.json');
  if (pg) {
    if (pg.engine?.driver !== 'pgsql') {
      problems.push(`DEV193-P1-04R: artifact khai driver ${pg.engine?.driver}, phải là pgsql.`);
    }
    if (pg.audit_write_policy !== 'fail_closed') {
      problems.push('DEV193-P1-04R: policy không phải fail_closed.');
    }
    if (pg.http_status !== 500 || pg.error_code !== 'INTERNAL_SERVER_ERROR') {
      problems.push('DEV193-P1-04R: response lỗi không đúng contract §8.2.');
    }
    if (JSON.stringify(pg.db_before) !== JSON.stringify(pg.db_after)) {
      problems.push('DEV193-P1-04R: DB before/after khác nhau — rollback không toàn phần.');
    }
    if (Number(pg.audit_log_rows_delta) !== 0 || Number(pg.audit_outbox_rows) !== 0) {
      problems.push('DEV193-P1-04R: còn sót audit_log/audit_outbox sau rollback.');
    }
    if (Number(pg.db_after?.stock_movements) !== 0) {
      problems.push('DEV193-P1-04R: putaway sinh stock movement — vi phạm AC-SRS-009.');
    }
    const raw = JSON.stringify(pg.raw_error_response ?? {});
    for (const leak of ['trace', '/vendor/', 'SQLSTATE']) {
      if (raw.includes(leak)) {
        problems.push(`DEV193-P1-04R: envelope lỗi lộ "${leak}".`);
      }
    }
  }
}

if (notes.length > 0) {
  console.log('Ghi nhận:');
  for (const note of notes) console.log(`  · ${note}`);
}

if (problems.length > 0) {
  console.error('\nWWHN-193 closure verifier FAIL:');
  for (const problem of problems) console.error(`  - ${problem}`);
  process.exit(1);
}

console.log('\nWWHN-193 closure verifier PASS');
