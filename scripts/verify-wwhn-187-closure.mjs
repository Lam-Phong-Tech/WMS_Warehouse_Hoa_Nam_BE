#!/usr/bin/env node
/**
 * WWHN-187 — gate đóng register P1/P2 (DEV187-P1-01R..07, P2-01).
 *
 * Chạy được lại tại đúng candidate SHA, không phụ thuộc HEAD của `m1` (nhánh có nhiều
 * task song song commit liên tục). Bất biến được kiểm là **range invariant**:
 * candidate là ancestor của HEAD *và* không commit nào sau candidate chạm vào tập
 * artifact đã pin — chứ không phải "HEAD phải bằng candidate", vốn hỏng ngay trong ngày.
 *
 *   node scripts/verify-wwhn-187-closure.mjs
 *   node scripts/verify-wwhn-187-closure.mjs --fe-repo="C:/LAM_PHONG_TECH/HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE"
 *   node scripts/verify-wwhn-187-closure.mjs --write-pin      # sinh lại digest hợp đồng
 *
 * Exit 0 = mọi check PASS. Exit 1 = có FAIL (in rõ check nào, mong đợi gì, thấy gì).
 */

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const argv = process.argv.slice(2);
const flag = (name) => {
  const hit = argv.find((a) => a === `--${name}` || a.startsWith(`--${name}=`));
  if (hit === undefined) return null;
  return hit.includes('=') ? hit.slice(hit.indexOf('=') + 1) : true;
};

/**
 * Tập được bảo vệ CHẶT: quyết định, hợp đồng QA và chính cái gate. Đổi bất kỳ file nào
 * trong đây sau candidate là đổi điều kiện đóng register, phải pin lại.
 *
 * `docs/evidence/WWHN-187/**` cố ý KHÔNG nằm ở đây: chính hồ sơ evidence là nơi ghi
 * candidate, nên commit điền SHA luôn nằm *sau* candidate. Nếu bắt nó no-drift thì gate
 * tự đòi bằng chứng của chính nó và không bao giờ xanh được — đúng cái bẫy đã gặp ở
 * WWHN-191. Văn bản evidence được phép cập nhật; cái phải bất biến là quyết định + hợp
 * đồng + gate.
 */
const PINNED_ARTIFACTS = [
  'docs/decision-records/DEC-SCAN-AUDIT-001-scan-evidence-vs-audit-log.md',
  'docs/postman/WWHN-187 Inbound Scan Retry.postman_collection.json',
  'scripts/verify-wwhn-187-closure.mjs',
];

const PIN_PATH = join(repoRoot, 'docs/evidence/WWHN-187/WWHN-187_CONTRACT_PIN.json');
const OPENAPI_PATH = join(repoRoot, 'storage/api-docs/api-docs.json');
const SCAN_PATHS = [
  '/api/v1/inbound-documents/{id}/scan',
  '/api/v1/inbound-documents/{id}/scans',
];

const results = [];
const warnings = [];
const check = (id, ok, detail) => results.push({ id, ok: Boolean(ok), detail });
/**
 * Phát hiện thật nhưng chủ sở hữu nằm ngoài WWHN-187 — in loud, không đánh đỏ gate của
 * task này. Không dùng để hạ cấp một check thuộc phạm vi task.
 */
const warn = (id, detail, owner) => warnings.push({ id, detail, owner });

const git = (...args) => {
  try {
    return execFileSync('git', args, { cwd: repoRoot, encoding: 'utf8' }).trim();
  } catch {
    return null;
  }
};

/** JSON canonical (key đã sort) để digest không đổi khi generator xáo thứ tự khoá. */
const canonical = (value) => {
  if (Array.isArray(value)) return value.map(canonical);
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.keys(value)
        .sort()
        .map((k) => [k, canonical(value[k])]),
    );
  }
  return value;
};

const digestOf = (value) =>
  createHash('sha256').update(JSON.stringify(canonical(value))).digest('hex');

// ── 1. Hợp đồng OpenAPI của bước quét ────────────────────────────────────────
let scanContractDigest = null;
if (!existsSync(OPENAPI_PATH)) {
  check('CONTRACT-OPENAPI-EXISTS', false, `thiếu ${OPENAPI_PATH}`);
} else {
  const spec = JSON.parse(readFileSync(OPENAPI_PATH, 'utf8'));
  const subtree = {};
  let allFound = true;

  for (const p of SCAN_PATHS) {
    const op = spec.paths?.[p]?.post;
    if (!op) {
      allFound = false;
      check(`CONTRACT-PATH ${p}`, false, 'không có operation POST trong api-docs.json');
      continue;
    }
    subtree[p] = op;

    const params = op.parameters ?? [];
    const byName = (n) => params.find((x) => x.name === n);
    const ifMatch = byName('If-Match');
    const idem = byName('Idempotency-Key');

    // Cả hai alias phải khai If-Match và khai là BẮT BUỘC — runtime trả 428
    // PRECONDITION_REQUIRED khi thiếu, nên required=false là contract nói dối.
    // (Từng đỏ vì api-docs.json stale; xem WWHN-187_CONTRACT_LOCK.md mục 7.)
    check(`CONTRACT-IFMATCH-DECLARED ${p}`, ifMatch !== undefined, 'thiếu tham số header If-Match');
    check(
      `CONTRACT-IFMATCH-REQUIRED ${p}`,
      ifMatch?.required === true,
      'If-Match phải required=true; nếu vừa đổi annotation thì chạy lại php artisan l5-swagger:generate',
    );
    // Annex C dòng scanInboundDocument chỉ khai If-Match. Idempotency-Key được thêm
    // như header TÙY CHỌN (DEC-SCAN-IDEMP-001) — required=true sẽ là contract break
    // và biến mọi client cũ thành 428.
    check(
      `CONTRACT-IDEMPOTENCY ${p}`,
      idem !== undefined && idem.required !== true,
      idem === undefined
        ? 'thiếu tham số header Idempotency-Key'
        : 'Idempotency-Key phải là tùy chọn (required khác true)',
    );
    for (const status of ['409', '412', '422', '428']) {
      check(
        `CONTRACT-RESPONSE ${p} ${status}`,
        op.responses?.[status] !== undefined,
        `thiếu response ${status}`,
      );
    }
  }

  if (allFound) {
    scanContractDigest = digestOf(subtree);
    check('CONTRACT-DIGEST-COMPUTED', true, scanContractDigest);
  }
}

// ── 2. Pin hợp đồng + range invariant của candidate ──────────────────────────
if (flag('write-pin')) {
  if (scanContractDigest === null) {
    console.error('Không tính được digest — không ghi pin.');
    process.exit(1);
  }
  const head = git('rev-parse', 'HEAD');
  writeFileSync(
    PIN_PATH,
    `${JSON.stringify(
      {
        task: 'WWHN-187',
        note: 'Digest của riêng hai operation quét nhập trong api-docs.json (canonical JSON, sha256). Pin theo tập operation chứ không theo cả file, để thay đổi không liên quan ở nơi khác không làm hỏng gate.',
        openapi_scan_operations: SCAN_PATHS,
        openapi_scan_digest: scanContractDigest,
        candidate_sha_be: head,
        pinned_artifacts: PINNED_ARTIFACTS,
      },
      null,
      2,
    )}\n`,
    'utf8',
  );
  console.log(`Đã ghi ${PIN_PATH}`);
  process.exit(0);
}

if (!existsSync(PIN_PATH)) {
  check('PIN-FILE', false, `thiếu ${PIN_PATH} — chạy lại với --write-pin`);
} else {
  const pin = JSON.parse(readFileSync(PIN_PATH, 'utf8'));
  check(
    'PIN-OPENAPI-DIGEST',
    scanContractDigest !== null && pin.openapi_scan_digest === scanContractDigest,
    `pin=${pin.openapi_scan_digest} thực tế=${scanContractDigest}`,
  );

  const candidate = pin.candidate_sha_be;
  const head = git('rev-parse', 'HEAD');
  if (!candidate || !head) {
    check('PIN-RANGE', false, 'không đọc được candidate hoặc HEAD');
  } else {
    const isAncestor =
      git('merge-base', '--is-ancestor', candidate, head) !== null ||
      candidate === head;
    check('PIN-ANCESTOR', isAncestor, `${candidate.slice(0, 7)} phải là ancestor của HEAD`);

    // Bất biến chống pin rot: sau candidate không được có commit nào chạm artifact.
    const touched = git(
      'log',
      '--format=%h',
      `${candidate}..HEAD`,
      '--',
      ...(pin.pinned_artifacts ?? PINNED_ARTIFACTS),
    );
    check(
      'PIN-NO-DRIFT',
      touched === '' || touched === null,
      touched ? `commit sau candidate đã chạm artifact: ${touched.split('\n').join(', ')}` : 'ok',
    );
  }
}

// ── 3. Artifact đóng register phải tồn tại ───────────────────────────────────
const REQUIRED_FILES = [
  ['P1-01R', 'docs/evidence/WWHN-187/EVIDENCE_PACK.md'],
  ['P1-02R', 'docs/evidence/WWHN-187/WWHN-187_BRANCH_DISPOSITION.md'],
  ['P1-03R', 'docs/evidence/WWHN-187/WWHN-187_QA_EXECUTION_HANDOFF.md'],
  ['P1-04R', 'docs/evidence/WWHN-187/WWHN-187_MASTER_DATA_BASELINE.md'],
  ['P1-05R', 'docs/evidence/WWHN-187/WWHN-187_CONTRACT_LOCK.md'],
  ['P1-05R', 'docs/decision-records/DEC-SCAN-AUDIT-001-scan-evidence-vs-audit-log.md'],
  ['P1-06', 'docs/evidence/WWHN-187/WWHN-187_SOURCE_TRACEABILITY_SUPERSESSION.md'],
  ['P1-07', 'docs/postman/WWHN-187 Inbound Scan Retry.postman_collection.json'],
  ['REGISTER', 'docs/evidence/WWHN-187/WWHN-187_DEFECT_CLOSURE_REGISTER.md'],
];
for (const [id, rel] of REQUIRED_FILES) {
  check(`ARTIFACT ${id} ${rel}`, existsSync(join(repoRoot, rel)), 'không tìm thấy file');
}

// ── 4. Test bắt buộc phải tồn tại (không chỉ tồn tại file, mà đúng case) ─────
const REQUIRED_TESTS = [
  [
    'tests/Feature/Wwhn188ScanIdempotencyContractTest.php',
    [
      'test_lost_response_retry_with_same_key_replays_and_does_not_double_count',
      'test_refetch_retry_with_same_key_and_current_version_does_not_double_count',
      'test_same_key_with_different_quantity_is_rejected_with_409_idempotency_conflict',
      'test_different_key_same_payload_is_a_genuine_second_scan',
      'test_scan_without_idempotency_key_still_works_and_is_not_428',
    ],
  ],
  [
    'tests/Feature/InboundScanConcurrencyBehaviorTest.php',
    ['test_component_quantity_mode_refetch_retry_is_deduped_by_idempotency_key'],
  ],
  [
    'tests/Feature/Wwhn187InboundScanValidationTest.php',
    ['test_scan_writes_scan_event_evidence_row', 'test_scan_rejects_component_line_without_box'],
  ],
];
for (const [rel, cases] of REQUIRED_TESTS) {
  const abs = join(repoRoot, rel);
  if (!existsSync(abs)) {
    check(`TEST-FILE ${rel}`, false, 'không tìm thấy file test');
    continue;
  }
  const src = readFileSync(abs, 'utf8');
  for (const c of cases) {
    check(`TEST-CASE ${rel}::${c}`, src.includes(c), 'không tìm thấy test case');
  }
}

// ── 5. Consumer FE thật sự gửi Idempotency-Key (nửa còn lại của DEV187-P1-07) ─
const feRepo = flag('fe-repo');
if (typeof feRepo === 'string') {
  const svc = join(feRepo, 'src/modules/inbound/services/inboundService.ts');
  const screen = join(feRepo, 'src/modules/inbound/pages/MvpInboundScreens.tsx');
  if (!existsSync(svc) || !existsSync(screen)) {
    check('FE-CONSUMER', false, 'không tìm thấy source FE ở --fe-repo');
  } else {
    const svcSrc = readFileSync(svc, 'utf8');
    const screenSrc = readFileSync(screen, 'utf8');
    // Nửa BE chỉ có hiệu lực khi client gửi key; không có ba điều dưới đây thì
    // defect DEV187-P1-07 vẫn sống nguyên trên production.
    check(
      'FE-SENDS-KEY',
      /mutationHeaders\(\s*currentVersion\s*,\s*scanKey\s*\)/.test(svcSrc),
      'scanInboundDocument phải gửi Idempotency-Key kèm If-Match',
    );
    check(
      'FE-KEY-PER-CODE',
      svcSrc.includes('${results.length}'),
      'key phải tách theo từng mã trong một lần gọi',
    );
    check(
      'FE-REUSES-KEY-ON-RETRY',
      screenSrc.includes('scanKeyRef') && screenSrc.includes('shouldReuseScanKey'),
      'màn hình quét phải giữ và dùng lại đúng key khi gửi lại cùng một hành động',
    );
    check(
      'FE-SHOWS-REPLAY',
      screenSrc.includes('first.replayed'),
      'UI phải nói rõ replay để người dùng không quét bù thêm',
    );
  }
} else {
  check('FE-CONSUMER', true, 'bỏ qua (không truyền --fe-repo)');
}

// ── Kết quả ──────────────────────────────────────────────────────────────────
const failed = results.filter((r) => !r.ok);
for (const r of results) {
  console.log(`${r.ok ? 'PASS' : 'FAIL'}  ${r.id}${r.ok ? '' : ` — ${r.detail}`}`);
}
if (warnings.length > 0) {
  console.log('');
  for (const w of warnings) {
    console.log(`WARN  ${w.id} — ${w.detail}\n      chủ sở hữu: ${w.owner}`);
  }
}
console.log(
  `\n${results.length - failed.length}/${results.length} check PASS` +
    (warnings.length > 0 ? `, ${warnings.length} cảnh báo ngoài phạm vi WWHN-187` : ''),
);
process.exit(failed.length === 0 ? 0 : 1);
