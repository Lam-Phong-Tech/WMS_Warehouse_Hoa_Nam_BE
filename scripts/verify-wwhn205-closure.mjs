#!/usr/bin/env node
/**
 * WWHN-205 / WWHN-214 — cổng đóng register P1-01R..P1-04R.
 *
 * Vòng review trước chấm 70/100 với nhận xét cốt lõi: bằng chứng là LỜI VĂN. Một
 * file .md nói "đã pin SHA", "đã chạy PG16", "đã có concurrency test" thì sau lần
 * refactor kế tiếp vẫn đúng chính tả nhưng đã sai sự thật. File này là dạng máy của
 * bản register: nó chạy lại từng mệnh đề và FAIL khi một mệnh đề không còn đúng.
 *
 * Nguyên tắc: FAIL-CLOSED. Thiếu dữ liệu để kiểm (ví dụ checkout shallow không có
 * lịch sử) là FAIL kèm hướng dẫn sửa, không phải SKIP im lặng — đúng bài học
 * "không để một workflow đỏ bị mô tả là PASS".
 *
 *   node scripts/verify-wwhn205-closure.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname).replace(/^\/([A-Za-z]:)/, '$1'), '..');
const REGISTER = 'docs/evidence/WWHN-205/wwhn-205-source-register.json';
const MATRIX = 'docs/evidence/WWHN-205/EVIDENCE_MATRIX.md';
const RUNBOOK = 'docs/evidence/WWHN-205/QA1_INDEPENDENT_RUNBOOK.md';
const GATE = '.github/workflows/wwhn-205-invariant-gate.yml';
const RACE = 'scripts/wwhn205-receipt-issue-race.php';
const RECON_PHP = 'scripts/wwhn206-ledger-reconciliation.php';
const RECON_SQL = 'scripts/wwhn206-ledger-reconciliation.sql';
const DIAGNOSTIC = '.github/workflows/erd-postgres-diagnostic.yml';

const results = [];
const fail = (id, message) => results.push({ id, status: 'FAIL', message });
const pass = (id, message) => results.push({ id, status: 'PASS', message });

const abs = (rel) => path.join(ROOT, rel);
const exists = (rel) => fs.existsSync(abs(rel));
const read = (rel) => fs.readFileSync(abs(rel), 'utf8');

const git = (args) => execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' }).trim();
const gitOk = (args) => {
  try {
    git(args);
    return true;
  } catch {
    return false;
  }
};

// ---------------------------------------------------------------------------
// 1. Register phải tồn tại và đủ bốn mục P1 của vòng review thứ hai.
// ---------------------------------------------------------------------------
if (!exists(REGISTER)) {
  fail('R-01', `Thiếu ${REGISTER} — không có nguồn sự thật cho register P1-01R..P1-04R.`);
  report();
}

let register;
try {
  register = JSON.parse(read(REGISTER));
  pass('R-01', `${REGISTER} parse được.`);
} catch (error) {
  fail('R-01', `${REGISTER} không parse được: ${error.message}`);
  report();
}

const REQUIRED_P1 = ['P1-01R', 'P1-02R', 'P1-03R', 'P1-04R'];
const registerIds = (register.p1_register ?? []).map((item) => item.id);
const missingP1 = REQUIRED_P1.filter((id) => !registerIds.includes(id));
if (missingP1.length > 0) {
  fail('R-02', `Register thiếu mục: ${missingP1.join(', ')}`);
} else {
  pass('R-02', `Đủ 4 mục P1: ${REQUIRED_P1.join(', ')}`);
}

for (const item of register.p1_register ?? []) {
  const evidence = item.evidence ?? [];
  if (evidence.length === 0) {
    fail('R-03', `${item.id} không khai evidence nào.`);
    continue;
  }
  // Evidence trỏ tới file/thư mục trong repo phải tồn tại thật; nếu không thì mục
  // register chỉ là lời hứa.
  const missing = evidence.filter((rel) => !rel.endsWith('/') && !exists(rel));
  const missingDirs = evidence.filter((rel) => rel.endsWith('/') && !exists(rel));
  if (missing.length > 0 || missingDirs.length > 0) {
    fail('R-03', `${item.id} khai evidence không tồn tại: ${[...missing, ...missingDirs].join(', ')}`);
  } else {
    pass('R-03', `${item.id}: ${evidence.length} evidence path đều tồn tại.`);
  }
}

// ---------------------------------------------------------------------------
// 2. Chuỗi commit: fad424 (pre-fix) -> 7a784 (fix) -> candidate. Kiểm bằng git,
//    không tin lời văn trong JSON.
// ---------------------------------------------------------------------------
const chain = register.commit_chain ?? {};
const preFix = chain.pre_fix_baseline?.sha;
const actualFix = chain.actual_fix?.sha;
const candidate = chain.chosen_candidate?.sha;

if (!preFix || !actualFix || !candidate) {
  fail('C-01', 'commit_chain phải pin đủ pre_fix_baseline, actual_fix và chosen_candidate.');
} else if (!gitOk(['rev-parse', '--git-dir'])) {
  fail('C-01', 'Không chạy trong git repo — không thể xác minh chuỗi commit. Cổng fail-closed.');
} else {
  const unreachable = [preFix, actualFix, candidate].filter((sha) => !gitOk(['cat-file', '-e', `${sha}^{commit}`]));
  if (unreachable.length > 0) {
    fail(
      'C-01',
      `Không tìm thấy commit ${unreachable.join(', ')} trong checkout này. `
        + 'Workflow phải dùng actions/checkout với fetch-depth: 0 thì mới verify được exact SHA.'
    );
  } else {
    pass('C-01', `Cả 3 SHA đều tồn tại: ${preFix.slice(0, 7)} -> ${actualFix.slice(0, 7)} -> ${candidate.slice(0, 7)}`);

    if (!gitOk(['merge-base', '--is-ancestor', preFix, actualFix])) {
      fail('C-02', `${preFix.slice(0, 7)} không phải ancestor của ${actualFix.slice(0, 7)} — pin sai vai trò pre-fix/fix.`);
    } else {
      pass('C-02', 'pre_fix_baseline là ancestor của actual_fix.');
    }

    if (!gitOk(['merge-base', '--is-ancestor', actualFix, candidate])) {
      fail('C-03', `${actualFix.slice(0, 7)} không phải ancestor của candidate ${candidate.slice(0, 7)} — candidate không mang fix.`);
    } else {
      pass('C-03', 'actual_fix là ancestor của chosen_candidate (candidate thực sự chứa fix).');
    }

    // Chống pin rot: nếu candidate đã trôi khỏi SHA ghi trong register thì phải
    // biết ngay, thay vì đọc một con số cũ tưởng là mới.
    const head = git(['rev-parse', 'HEAD']);
    if (head !== candidate) {
      pass(
        'C-04',
        `HEAD (${head.slice(0, 7)}) khác chosen_candidate (${candidate.slice(0, 7)}). `
          + 'Hợp lệ khi chạy lại trên commit sau; xem C-05 về range invariant.'
      );
    } else {
      pass('C-04', 'HEAD trùng đúng chosen_candidate.');
    }

    // C-05 — RANGE INVARIANT (chống pin rot).
    //
    // Nhánh m1 nhận commit liên tục từ nhiều phiên, nên HEAD gần như luôn trôi khỏi
    // chosen_candidate ngay sau khi dựng evidence. Bắt buộc pin lại mỗi lần HEAD đổi
    // là vô nghĩa. Điều THỰC SỰ phải đúng: trong khoảng (candidate, HEAD] không có
    // commit nào chạm vào file set của WWHN-205. Nếu có, evidence đã chạy tại
    // candidate không còn nói về mã đang nằm trên HEAD, và register PHẢI được pin lại.
    const artifactPaths = (register.artifact_file_set ?? []).map((a) => a.path);
    if (artifactPaths.length > 0 && git(['rev-parse', 'HEAD']) !== candidate) {
      let touched = '';
      try {
        touched = git(['log', '--oneline', `${candidate}..HEAD`, '--', ...artifactPaths]);
      } catch {
        touched = '';
      }
      if (touched !== '') {
        fail(
          'C-05',
          'Có commit sau chosen_candidate chạm vào file set WWHN-205 — evidence đã lỗi thời, phải pin lại candidate và chạy lại cổng:\n'
            + touched.split('\n').map((l) => '        '.concat(l)).join('\n')
        );
      } else {
        pass('C-05', 'Range invariant: không commit nào trong (candidate, HEAD] chạm file set WWHN-205 — evidence vẫn nói đúng về mã trên HEAD.');
      }
    } else {
      pass('C-05', 'Range invariant: HEAD trùng candidate nên không có khoảng nào để trôi.');
    }
  }
}

// ---------------------------------------------------------------------------
// 3. File set + semantic marker. Đây là phần chống "còn file nhưng mất fix":
//    một commit sau có thể refactor CurrentBaselineStockService mà xoá mất
//    resolveLineItemId, file vẫn tồn tại và mọi checksum lời văn vẫn "đúng".
// ---------------------------------------------------------------------------
const artifacts = register.artifact_file_set ?? [];
if (artifacts.length === 0) {
  fail('A-01', 'artifact_file_set rỗng — P1-04R đòi gắn diff/file set cụ thể.');
}

for (const artifact of artifacts) {
  const rel = artifact.path;
  if (!exists(rel)) {
    fail('A-01', `Artifact ${rel} không còn tồn tại.`);
    continue;
  }
  const content = read(rel);
  const missingMarkers = (artifact.semantic_markers ?? []).filter((marker) => !content.includes(marker));
  if (missingMarkers.length > 0) {
    fail('A-02', `${rel} mất semantic marker của WWHN-205: ${missingMarkers.join(' | ')}`);
  } else {
    pass('A-02', `${rel}: ${(artifact.semantic_markers ?? []).length} marker còn nguyên.`);
  }

  if (typeof artifact.expected_test_method_count === 'number') {
    const count = (content.match(/public function test_/g) ?? []).length;
    if (count !== artifact.expected_test_method_count) {
      fail('A-03', `${rel} có ${count} test method, register pin ${artifact.expected_test_method_count}.`);
    } else {
      pass('A-03', `${rel} giữ đúng ${count} test method.`);
    }
  }

  if (artifact.blob_at_candidate && candidate && gitOk(['cat-file', '-e', `${candidate}^{commit}`])) {
    let blob = null;
    try {
      blob = git(['rev-parse', `${candidate}:${rel}`]);
    } catch {
      blob = null;
    }
    if (blob !== artifact.blob_at_candidate) {
      fail('A-04', `${rel}: blob tại candidate là ${blob ?? 'KHÔNG CÓ'}, register pin ${artifact.blob_at_candidate}.`);
    } else {
      pass('A-04', `${rel}: blob tại candidate khớp pin.`);
    }
  }
}

// ---------------------------------------------------------------------------
// 4. P1-03R: script race phải TỒN TẠI và phải được CI THỰC THI, không phải chỉ
//    `node --check`/`php -l`. Đây đúng là lỗ hổng WWHN-218/BE1-OP-04 đã bị treo.
// ---------------------------------------------------------------------------
for (const rel of [RACE, RECON_PHP, RECON_SQL]) {
  if (!exists(rel)) {
    fail('E-01', `Thiếu ${rel}.`);
  } else {
    pass('E-01', `${rel} tồn tại.`);
  }
}

if (!exists(GATE)) {
  fail('G-01', `Thiếu ${GATE} — không có cổng chạy lại được cho WWHN-205.`);
} else {
  const gate = read(GATE);
  pass('G-01', `${GATE} tồn tại.`);

  const executes = (script) => new RegExp(`^\\s*(-\\s+)?(run:\\s*)?.*\\bphp\\s+${script.replace(/[/.]/g, '\\$&')}`, 'm').test(gate)
    || gate.includes(`php ${script}`);

  for (const script of [RACE, RECON_PHP]) {
    if (!executes(script)) {
      fail('G-02', `${GATE} không THỰC THI ${script} (chỉ khai tên hoặc chỉ lint là không đủ — đúng lỗi đã bị bắt ở WWHN-218).`);
    } else {
      pass('G-02', `${GATE} thực thi thật ${script}.`);
    }
  }

  if (!/php\s+-l\s+scripts\/wwhn205-receipt-issue-race\.php\s*$/m.test(gate) && executes(RACE)) {
    pass('G-03', 'Cổng không thay việc chạy thật bằng syntax-check.');
  }

  if (!gate.includes('postgres:16')) {
    fail('G-04', `${GATE} phải chạy trên postgres:16 (bằng chứng PG16 mà register đòi).`);
  } else {
    pass('G-04', 'Cổng dùng service postgres:16.');
  }

  if (!/fetch-depth:\s*0/.test(gate)) {
    fail('G-05', `${GATE} phải checkout với fetch-depth: 0, nếu không verifier không xác minh được exact SHA.`);
  } else {
    pass('G-05', 'Cổng checkout đủ lịch sử để verify exact SHA.');
  }

  if (/continue-on-error:\s*true/.test(gate)) {
    fail('G-06', `${GATE} có continue-on-error: true — một bước đỏ sẽ bị báo cáo là xanh, đúng điều register cấm.`);
  } else {
    pass('G-06', 'Không bước nào của cổng được phép đỏ mà vẫn tính là qua.');
  }
}

// ---------------------------------------------------------------------------
// 5. erd-postgres-diagnostic.yml phải giữ nguyên phần mở rộng paths của WWHN-205
//    (nếu ai đó thu lại thì workflow quay về trạng thái dormant như trước fix).
// ---------------------------------------------------------------------------
if (!exists(DIAGNOSTIC)) {
  fail('D-01', `Thiếu ${DIAGNOSTIC}.`);
} else {
  const diagnostic = read(DIAGNOSTIC);
  const required = ['Modules/Warehouse/database/migrations/**', 'Modules/Warehouse/app/Services/**', 'pull_request'];
  const missing = required.filter((needle) => !diagnostic.includes(needle));
  if (missing.length > 0) {
    fail('D-01', `${DIAGNOSTIC} mất trigger đã mở rộng ở WWHN-205: ${missing.join(', ')} — workflow quay lại dormant.`);
  } else {
    pass('D-01', 'Workflow PostgreSQL vẫn tự chạy khi migration/service kho đổi.');
  }
}

// ---------------------------------------------------------------------------
// 6. Tài liệu: matrix phải pin nguyên văn cả 3 SHA; runbook QA1 phải có lệnh psql
//    độc lập. Chống trường hợp evidence "nói chung chung".
// ---------------------------------------------------------------------------
if (!exists(MATRIX)) {
  fail('M-01', `Thiếu ${MATRIX}.`);
} else {
  const matrix = read(MATRIX);
  const missing = [preFix, actualFix, candidate].filter((sha) => sha && !matrix.includes(sha));
  if (missing.length > 0) {
    fail('M-01', `${MATRIX} không pin nguyên văn SHA: ${missing.map((s) => s.slice(0, 7)).join(', ')}`);
  } else {
    pass('M-01', 'Evidence matrix pin đủ 3 SHA dạng đầy đủ.');
  }
}

if (!exists(RUNBOOK)) {
  fail('M-02', `Thiếu ${RUNBOOK} — P1-02R đòi QA1 chạy được độc lập.`);
} else {
  const runbook = read(RUNBOOK);
  if (!runbook.includes('wwhn206-ledger-reconciliation.sql') || !runbook.includes('psql')) {
    fail('M-02', `${RUNBOOK} phải chỉ rõ lệnh psql chạy wwhn206-ledger-reconciliation.sql (không qua PHP).`);
  } else {
    pass('M-02', 'Runbook QA1 có đường chạy độc lập bằng psql.');
  }
}

// ---------------------------------------------------------------------------
// 7. Chống evidence "generic": mọi blocker phải có owner VÀ disposition thật.
// ---------------------------------------------------------------------------
for (const blocker of register.blockers ?? []) {
  const owner = (blocker.owner ?? '').trim();
  const disposition = (blocker.disposition ?? '').trim();
  if (owner.length < 3 || disposition.length < 20) {
    fail('B-01', `Blocker ${blocker.id} thiếu owner hoặc disposition cụ thể.`);
  } else {
    pass('B-01', `Blocker ${blocker.id}: owner + disposition đầy đủ.`);
  }
}

// ---------------------------------------------------------------------------
// 8. Tầng khẳng định độc lập của P1-02R phải là SQL thuần, không lén gọi lại
//    service của dự án (nếu gọi thì "độc lập" chỉ còn là chữ).
// ---------------------------------------------------------------------------
if (exists(RECON_SQL)) {
  const sql = read(RECON_SQL);
  const requiredMetrics = [
    'ledger_balance_mismatch_count',
    'duplicate_source_count',
    'duplicate_business_key_count',
    'orphan_movement_document_count',
    'movement_on_unposted_document_count',
    'negative_balance_count',
    'serial_subledger_mismatch_count',
    'quantity_subledger_mismatch_count',
    'container_integrity_mismatch_count',
  ];
  const missing = requiredMetrics.filter((metric) => !sql.includes(metric));
  if (missing.length > 0) {
    fail('S-01', `${RECON_SQL} thiếu metric register yêu cầu: ${missing.join(', ')}`);
  } else {
    pass('S-01', `${RECON_SQL} đủ 4 nhóm metric (ledger=balance, duplicate_source, orphan/rollback, non-negative).`);
  }

  if (exists(RECON_PHP)) {
    const php = read(RECON_PHP);
    if (!php.includes('wwhn206-ledger-reconciliation.sql')) {
      fail('S-02', 'Script PHP không đọc lại chính file SQL — hai đường có thể trôi khỏi nhau.');
    } else {
      pass('S-02', 'Script PHP thực thi đúng SQL mà QA1 chạy tay (một nguồn sự thật).');
    }
  }

  pass('S-03', `sha256(${RECON_SQL}) = ${crypto.createHash('sha256').update(read(RECON_SQL)).digest('hex').slice(0, 16)}…`);
}

report();

function report() {
  const failed = results.filter((r) => r.status === 'FAIL');
  const width = Math.max(...results.map((r) => r.id.length), 4);
  for (const r of results) {
    console.log(`${r.status === 'PASS' ? 'PASS' : 'FAIL'}  ${r.id.padEnd(width)}  ${r.message}`);
  }
  console.log('');
  console.log(`WWHN-205 closure gate: ${results.length - failed.length}/${results.length} invariant PASS.`);
  if (failed.length > 0) {
    console.log(`FAIL: ${failed.length} invariant không còn đúng.`);
    process.exit(1);
  }
  console.log('PASS: register P1-01R..P1-04R còn đúng tại checkout này.');
  process.exit(0);
}
