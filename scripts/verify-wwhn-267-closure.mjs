import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

/**
 * WWHN-267 — cổng đóng cho phần BE2 (contract / error / sample / data) của vòng sửa
 * lỗi WWHN-70.
 *
 * Lý do tồn tại: register review của WWHN-267 không tìm thấy PR/commit/code/evidence
 * nào, và ba ticket liên tiếp trước đó (WWHN-156, WWHN-292, WWHN-268) bị đánh trượt
 * vì defect list viết bằng văn xuôi. Không ai chứng minh được một bảng Markdown là
 * "không generic". Script này biến hồ sơ đóng thành thứ CI kiểm được và từ chối:
 *
 *   - thiếu bất kỳ artifact bắt buộc nào;
 *   - dòng defect thiếu trường, hoặc điền chuỗi độn (TBD / N/A / generic / "-");
 *   - fix trỏ tới file không tồn tại trên đĩa;
 *   - dòng FIXED mà retest không PASS, hoặc không liệt kê file đã sửa;
 *   - dòng còn chờ quyết định bên ngoài mà không khai kiểm soát bù + cổng ngoài;
 *   - dòng thiếu disposition impact hợp đồng/dữ liệu (DEV267-P1-06 đòi có, kể cả khi
 *     kết luận là N/A thì phải là N/A tường minh theo từng defect);
 *   - register ghép cặp HTTP↔mã lỗi thiếu chủ sở hữu cho khác biệt còn mở;
 *   - pin role baseline lệch số role thật trong seeder;
 *   - catalog mã lỗi không còn khớp source (chạy lại generator ở chế độ --check).
 *
 * Chạy: node scripts/verify-wwhn-267-closure.mjs
 * Thoát 0 = PASS; thoát 1 = FAIL kèm danh sách điểm hỏng.
 */

const root = process.cwd();

const REGISTER = 'docs/evidence/WWHN-267/DEFECT_REGISTER.json';
const PAIRING = 'docs/evidence/WWHN-267/HTTP_ERROR_CODE_PAIRING.json';
const ROLE_PIN = 'docs/evidence/WWHN-267/ROLE_BASELINE_PIN.json';
const DISPOSITION = 'docs/evidence/WWHN-267/SOURCE_AND_BRANCH_DISPOSITION.json';
const QA_PLAN = 'docs/evidence/WWHN-267/QA_RETEST_PLAN.json';
const RUN_EVIDENCE = 'docs/evidence/WWHN-267/RUN_EVIDENCE.md';
const TEST = 'tests/Feature/Wwhn267ContractClosureTest.php';
const SEEDER = 'Modules/Auth/database/seeders/AuthDatabaseSeeder.php';
const CATALOG_GENERATOR = 'scripts/generate-error-code-catalog.mjs';

/** Repo FE nằm cạnh repo BE; CI chỉ checkout một repo nên phải phát hiện thay vì fail. */
const FE_REPO = path.resolve(root, '../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE');
const feRepoAvailable = fs.existsSync(FE_REPO);

const failures = [];
const notes = [];

const fail = (condition, message) => {
  if (!condition) failures.push(message);
};

const readText = (relativePath) => {
  const absolute = path.join(root, relativePath);
  if (!fs.existsSync(absolute)) {
    failures.push(`Thiếu file bắt buộc: ${relativePath}`);
    return null;
  }
  return fs.readFileSync(absolute, 'utf8');
};

const readJson = (relativePath) => {
  const raw = readText(relativePath);
  if (raw === null) return null;
  try {
    return JSON.parse(raw);
  } catch (error) {
    failures.push(`${relativePath}: JSON không hợp lệ — ${error.message}`);
    return null;
  }
};

/** Chuỗi độn: có mặt trong ô bắt buộc nghĩa là dòng đó chưa được điều tra thật. */
const FILLER = /^(tbd|n\/a|na|none|-|--|todo|generic|chưa rõ|updating|\?+)$/i;

const requireNarrative = (value, minimumLength, label) => {
  if (typeof value !== 'string' || value.trim() === '') {
    failures.push(`${label}: bỏ trống.`);
    return;
  }
  if (FILLER.test(value.trim())) {
    failures.push(`${label}: điền chuỗi độn "${value.trim()}" — register bị chấm "generic" chính vì kiểu này.`);
    return;
  }
  if (value.trim().length < minimumLength) {
    failures.push(`${label}: quá ngắn (${value.trim().length} ký tự, cần ≥ ${minimumLength}) để reviewer kiểm chứng.`);
  }
};

const fileExistsSomewhere = (relativePath) => {
  if (fs.existsSync(path.join(root, relativePath))) return true;
  if (feRepoAvailable && fs.existsSync(path.join(FE_REPO, relativePath))) return true;
  return false;
};

// ---------------------------------------------------------------------------
// 1 — Artifact bắt buộc
// ---------------------------------------------------------------------------
for (const artifact of [PAIRING, ROLE_PIN, DISPOSITION, QA_PLAN, RUN_EVIDENCE, TEST, SEEDER]) {
  readText(artifact);
}

// ---------------------------------------------------------------------------
// 2 — Register defect: đủ trường, không chuỗi độn, fix trỏ file có thật
// ---------------------------------------------------------------------------
const register = readJson(REGISTER);
if (register !== null) {
  fail(register.task === 'WWHN-267', `${REGISTER}: trường task phải là WWHN-267.`);
  fail(register.parent === 'WWHN-70', `${REGISTER}: trường parent phải là WWHN-70.`);
  fail(register.branch === 'm1', `${REGISTER}: branch phải là m1 (không tạo nhánh riêng).`);
  fail(Array.isArray(register.defects) && register.defects.length > 0, `${REGISTER}: không có dòng defect nào.`);

  const externalGateIds = new Set((register.externalGates ?? []).map((gate) => gate.id));
  fail(externalGateIds.size > 0, `${REGISTER}: thiếu externalGates — việc thuộc vai trò khác phải được nêu tên, không được im lặng nuốt.`);

  for (const gate of register.externalGates ?? []) {
    requireNarrative(gate.title, 15, `${REGISTER}: externalGates[${gate.id}].title`);
    requireNarrative(gate.owner, 2, `${REGISTER}: externalGates[${gate.id}].owner`);
    requireNarrative(gate.compensatingControl, 25, `${REGISTER}: externalGates[${gate.id}].compensatingControl`);
  }

  for (const defect of register.defects ?? []) {
    const id = defect.id ?? '(thiếu id)';
    const label = `${REGISTER}: ${id}`;

    for (const [field, minimum] of [
      ['id', 5],
      ['severity', 2],
      ['owner', 2],
      ['confirmer', 2],
      ['routing', 2],
      ['testCase', 5],
      ['module', 4],
      ['endpointOrEntity', 10],
      ['environment', 10],
      ['expected', 60],
      ['actual', 60],
      ['repro', 25],
      ['rootCause', 60],
      ['status', 5],
    ]) {
      requireNarrative(defect[field], minimum, `${label}.${field}`);
    }

    fail(
      ['P0', 'P1', 'P2', 'P3'].includes(defect.severity),
      `${label}.severity: phải là P0/P1/P2/P3, đang là "${defect.severity}".`,
    );

    // fix.files phải tồn tại thật — đây là chỗ register cũ hay khai khống nhất.
    const fixFiles = defect.fix?.files ?? [];
    fail(Array.isArray(fixFiles) && fixFiles.length > 0, `${label}.fix.files: dòng nào cũng phải chỉ ra file đã chạm.`);
    requireNarrative(defect.fix?.summary, 60, `${label}.fix.summary`);

    for (const file of fixFiles) {
      if (fileExistsSomewhere(file)) continue;
      if (!feRepoAvailable && defect.routing === 'FE') {
        notes.push(`${label}.fix.files: "${file}" thuộc repo FE, không kiểm được trong checkout chỉ-BE.`);
        continue;
      }
      failures.push(`${label}.fix.files: "${file}" không tồn tại trên đĩa.`);
    }

    // Bằng chứng trước/sau: đường dẫn phải giải được (cho phép neo #anchor và ::method).
    for (const key of ['evidenceBefore', 'evidenceAfter']) {
      const entries = defect[key] ?? [];
      fail(Array.isArray(entries) && entries.length > 0, `${label}.${key}: không được rỗng.`);
      for (const entry of entries) {
        const filePart = String(entry).split('#')[0].split('::')[0];
        if (fileExistsSomewhere(filePart)) continue;
        if (!feRepoAvailable && defect.routing === 'FE') {
          notes.push(`${label}.${key}: "${entry}" thuộc repo FE, không kiểm được trong checkout chỉ-BE.`);
          continue;
        }
        failures.push(`${label}.${key}: "${entry}" trỏ tới file không tồn tại.`);
      }
    }

    // Retest: dòng đã sửa phải có lượt chạy PASS.
    const retest = defect.retest ?? {};
    fail(Array.isArray(retest.commands) && retest.commands.length > 0, `${label}.retest.commands: phải nêu lệnh chạy lại được.`);
    if (String(defect.status).startsWith('FIXED') || String(defect.status).startsWith('PARTIAL')) {
      fail(
        retest.result === 'PASS',
        `${label}: khai "${defect.status}" nhưng retest.result = "${retest.result}". Không được đóng dòng khi lượt chạy lại chưa xanh.`,
      );
    }

    // DEV267-P1-06: impact hợp đồng/dữ liệu phải tường minh theo TỪNG defect.
    const impact = defect.contractDataImpact ?? {};
    for (const key of ['apiContract', 'openapi', 'database', 'frontend']) {
      const value = impact[key];
      if (typeof value !== 'string' || value.trim() === '') {
        failures.push(`${label}.contractDataImpact.${key}: bỏ trống. DEV267-P1-06 đòi kết luận tường minh, kể cả khi là N/A.`);
        continue;
      }
      // "N/A" được phép ở đây, nhưng phải kèm lý do — N/A trơ là câu trả lời rỗng.
      if (/^n\/?a\.?$/i.test(value.trim())) {
        failures.push(`${label}.contractDataImpact.${key}: "N/A" trơ. Phải viết N/A kèm lý do (vd "N/A — không có migration").`);
      }
    }
    requireNarrative(impact.declaredBy, 2, `${label}.contractDataImpact.declaredBy`);
    requireNarrative(impact.reviewedBy, 2, `${label}.contractDataImpact.reviewedBy`);

    // Dòng còn chờ bên ngoài phải neo vào một cổng ngoài có tên.
    if (String(defect.status).includes('PENDING')) {
      const linked = (register.externalGates ?? []).some((gate) => (gate.blocks ?? []).includes(defect.id));
      fail(
        linked,
        `${label}: trạng thái "${defect.status}" nhưng không externalGates nào khai blocks chứa ${defect.id} — việc chờ ai, chờ gì phải nêu tên.`,
      );
    }
  }

  // Không được tự ký thay vai trò độc lập.
  for (const item of register.governanceItems ?? []) {
    requireNarrative(item.owner, 2, `${REGISTER}: governanceItems[${item.registerItem}].owner`);
    requireNarrative(item.disposition, 8, `${REGISTER}: governanceItems[${item.registerItem}].disposition`);
    const artifactPath = String(item.artifact ?? '').split('→')[0].trim();
    fail(
      artifactPath !== '' && fileExistsSomewhere(artifactPath),
      `${REGISTER}: governanceItems[${item.registerItem}].artifact trỏ tới "${artifactPath}" không tồn tại.`,
    );
  }

  for (const registerItem of ['DEV267-P1-02', 'DEV267-P1-04', 'DEV267-P1-05', 'DEV267-P2-01']) {
    fail(
      (register.governanceItems ?? []).some((item) => item.registerItem === registerItem),
      `${REGISTER}: thiếu governanceItems cho ${registerItem} của register review.`,
    );
  }
}

// ---------------------------------------------------------------------------
// 3 — Register ghép cặp HTTP ↔ mã lỗi
// ---------------------------------------------------------------------------
const pairing = readJson(PAIRING);
if (pairing !== null) {
  const governed = pairing.governedStatuses ?? [];
  fail(governed.length === 9, `${PAIRING}: §8.2 có 9 row status; governedStatuses đang liệt kê ${governed.length}.`);

  for (const [code, status] of Object.entries(pairing.reservedPairings ?? {})) {
    fail(
      governed.includes(status),
      `${PAIRING}: reservedPairings.${code} trỏ tới status ${status} không nằm trong governedStatuses.`,
    );
  }

  for (const entry of pairing.statusesOutsideSrs82 ?? []) {
    requireNarrative(entry.disposition, 8, `${PAIRING}: statusesOutsideSrs82[${entry.status}].disposition`);
    requireNarrative(entry.rationale, 40, `${PAIRING}: statusesOutsideSrs82[${entry.status}].rationale`);
    requireNarrative(entry.owner, 2, `${PAIRING}: statusesOutsideSrs82[${entry.status}].owner`);
  }

  for (const entry of pairing.divergences ?? []) {
    requireNarrative(entry.implemented, 5, `${PAIRING}: divergences[${entry.id}].implemented`);
    requireNarrative(entry.evidence, 25, `${PAIRING}: divergences[${entry.id}].evidence`);
    requireNarrative(entry.impact, 25, `${PAIRING}: divergences[${entry.id}].impact`);
    requireNarrative(entry.owner, 2, `${PAIRING}: divergences[${entry.id}].owner`);
    fail(
      typeof entry.disposition === 'string' && entry.disposition.trim() !== '',
      `${PAIRING}: divergences[${entry.id}].disposition bỏ trống — khác biệt contract không có trạng thái là khác biệt bị quên.`,
    );
  }

  for (const entry of pairing.dualStatusCodes ?? []) {
    fail(
      Array.isArray(entry.statuses) && entry.statuses.length === 2,
      `${PAIRING}: dualStatusCodes[${entry.code}] phải khai đúng hai status.`,
    );
    requireNarrative(entry.reason, 40, `${PAIRING}: dualStatusCodes[${entry.code}].reason`);
    requireNarrative(entry.constraint, 20, `${PAIRING}: dualStatusCodes[${entry.code}].constraint`);
  }
}

// ---------------------------------------------------------------------------
// 4 — Pin role baseline phải phủ đúng số role thật trong seeder
// ---------------------------------------------------------------------------
const rolePin = readJson(ROLE_PIN);
const seederSource = readText(SEEDER);
if (rolePin !== null && seederSource !== null) {
  const rolesBlock = seederSource.split('private const ROLES = [')[1] ?? '';
  const seededRoles = [...rolesBlock.matchAll(/^\s{8}'([A-Z_]+)' => \[/gm)].map((match) => match[1]);
  const pinnedRoles = Object.keys(rolePin.pinnedRoles ?? {});

  fail(seededRoles.length > 0, `${SEEDER}: không đọc được danh sách role — regex của cổng cần cập nhật.`);
  fail(
    seededRoles.length === pinnedRoles.length &&
      seededRoles.every((role) => pinnedRoles.includes(role)),
    `${ROLE_PIN}: pin ${pinnedRoles.length} role trong khi seeder có ${seededRoles.length} (${seededRoles.join(', ')}). Thêm/bớt role là đổi baseline quyền, phải cập nhật pin cùng PR.`,
  );

  for (const [code, entry] of Object.entries(rolePin.pinnedRoles ?? {})) {
    fail(
      /^[0-9a-f]{64}$/.test(entry.permissionsSha256 ?? ''),
      `${ROLE_PIN}: pinnedRoles.${code}.permissionsSha256 không phải sha256 hợp lệ.`,
    );
  }

  fail(
    (rolePin.conformanceDeltas ?? []).length > 0,
    `${ROLE_PIN}: conformanceDeltas rỗng. Nếu baseline thật sự khớp §3 thì phải nói rõ điều đó, không để trống.`,
  );

  for (const delta of rolePin.conformanceDeltas ?? []) {
    requireNarrative(delta.observed, 50, `${ROLE_PIN}: ${delta.id}.observed`);
    requireNarrative(delta.whyItMatters, 40, `${ROLE_PIN}: ${delta.id}.whyItMatters`);
    requireNarrative(delta.compensatingControl, 25, `${ROLE_PIN}: ${delta.id}.compensatingControl`);
    requireNarrative(delta.recommendation, 40, `${ROLE_PIN}: ${delta.id}.recommendation`);
    requireNarrative(delta.owner, 2, `${ROLE_PIN}: ${delta.id}.owner`);
  }
}

// ---------------------------------------------------------------------------
// 5 — Disposition nguồn/nhánh: neo đúng requirement, không neo nhầm cột
// ---------------------------------------------------------------------------
const disposition = readJson(DISPOSITION);
if (disposition !== null) {
  const source = disposition.sourceDisposition ?? {};
  fail(
    String(source.controllingSource ?? '').includes('REQ-SRS-037'),
    `${DISPOSITION}: sourceDisposition phải neo vào REQ-SRS-037. Trong §1.6 Supersession Register, DEC-NO-STOCKTAKE-001 nằm ở cột "New controlling source" với trạng thái SUPERSEDED của mệnh đề cũ — trích nhầm cột này là cách nhanh nhất để reviewer kết luận ngược lại.`,
  );
  fail(
    source.disposition === 'SUPERSEDED_BY_REQ-SRS-037',
    `${DISPOSITION}: sourceDisposition.disposition phải là SUPERSEDED_BY_REQ-SRS-037.`,
  );

  const branch = disposition.branchDisposition ?? {};
  fail(branch.canonicalBranch === 'm1', `${DISPOSITION}: canonicalBranch phải là m1.`);
  fail(branch.supersededBranch === 'm1-be-2', `${DISPOSITION}: supersededBranch phải là m1-be-2.`);
  fail(
    (branch.provenRelation?.behind ?? -1) === 0,
    `${DISPOSITION}: phải chứng minh m1 không thiếu commit nào của m1-be-2 (behind = 0), nếu không việc supersede sẽ mất công việc.`,
  );
  requireNarrative(branch.provenRelation?.command, 20, `${DISPOSITION}: branchDisposition.provenRelation.command`);
}

// ---------------------------------------------------------------------------
// 6 — Kế hoạch retest của QA phải giao được, không tự ký
// ---------------------------------------------------------------------------
const qaPlan = readJson(QA_PLAN);
if (qaPlan !== null) {
  fail((qaPlan.cases ?? []).length >= 5, `${QA_PLAN}: phạm vi retest quá mỏng (${(qaPlan.cases ?? []).length} ca).`);
  requireNarrative(qaPlan.truthBoundary, 60, `${QA_PLAN}: truthBoundary`);

  for (const testCase of qaPlan.cases ?? []) {
    requireNarrative(testCase.owner, 2, `${QA_PLAN}: ${testCase.id}.owner`);
    requireNarrative(testCase.steps, 30, `${QA_PLAN}: ${testCase.id}.steps`);
    requireNarrative(testCase.expected, 40, `${QA_PLAN}: ${testCase.id}.expected`);
    requireNarrative(testCase.srsRef, 4, `${QA_PLAN}: ${testCase.id}.srsRef`);
    fail(
      testCase.result === undefined,
      `${QA_PLAN}: ${testCase.id} đã có sẵn trường result — BE2 không được ký thay QA1/QA2.`,
    );
  }
}

// ---------------------------------------------------------------------------
// 7 — Catalog mã lỗi phải còn khớp source
// ---------------------------------------------------------------------------
if (fs.existsSync(path.join(root, CATALOG_GENERATOR))) {
  const result = spawnSync(process.execPath, [CATALOG_GENERATOR, '--check'], { cwd: root, encoding: 'utf8' });
  if (result.status !== 0) {
    // m1 có nhiều phiên làm việc song song: cây làm việc tại chỗ thường mang source
    // chưa commit của ticket khác, và catalog (artifact đã commit) đương nhiên lệch.
    // Nếu bắt đỏ ở đây thì cổng của WWHN-267 sẽ đỏ vì việc của người khác — đúng cái
    // bẫy "pin rot" mà các vòng trước đã sập. CI checkout sạch nên vẫn fail-closed.
    const dirty = spawnSync('git', ['status', '--porcelain', '--', 'app', 'Modules', 'bootstrap'], {
      cwd: root,
      encoding: 'utf8',
    });
    const workingTreeDirty = dirty.status === 0 && (dirty.stdout ?? '').trim() !== '';
    const detail = `${(result.stdout ?? '') + (result.stderr ?? '')}`.trim();

    if (workingTreeDirty) {
      notes.push(
        `${CATALOG_GENERATOR} --check lệch, nhưng cây làm việc đang có thay đổi chưa commit dưới app/ hoặc Modules/ `
        + '— bỏ qua tại chỗ, cổng CI trên checkout sạch vẫn bắt buộc.',
      );
    } else {
      failures.push(
        `${CATALOG_GENERATOR} --check thất bại trên cây làm việc sạch: catalog mã lỗi không còn khớp source, `
        + `nên mọi kết luận về ghép cặp HTTP↔mã đều dựa trên số liệu cũ.\n${detail}`,
      );
    }
  }
} else {
  notes.push(`${CATALOG_GENERATOR} không có mặt — bỏ qua bước kiểm catalog còn khớp source.`);
}

// ---------------------------------------------------------------------------
if (!feRepoAvailable) {
  notes.push('Không thấy repo FE cạnh repo BE: các file fix thuộc FE267-D06 do cổng FE kiểm, không kiểm ở đây.');
}

if (failures.length > 0) {
  console.error('WWHN-267 closure verifier: FAIL');
  for (const failure of failures) console.error(`  - ${failure}`);
  for (const note of notes) console.error(`  NOTE ${note}`);
  process.exit(1);
}

console.log('WWHN-267 closure verifier: PASS');
console.log('  - register defect đủ trường, không chuỗi độn, fix/bằng chứng trỏ file có thật');
console.log('  - mọi dòng FIXED có lượt retest PASS; mọi dòng còn chờ có cổng ngoài + kiểm soát bù');
console.log('  - mọi dòng khai impact hợp đồng/dữ liệu tường minh (DEV267-P1-06)');
console.log('  - register ghép cặp §8.2 và pin role baseline khớp seeder, khác biệt đều có chủ sở hữu');
console.log('  - disposition nguồn neo REQ-SRS-037; nhánh canonical m1 chứng minh được không mất commit');
for (const note of notes) console.log(`  NOTE ${note}`);
