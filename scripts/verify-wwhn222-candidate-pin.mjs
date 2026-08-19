#!/usr/bin/env node
/**
 * WWHN-222 / DEV222-P1-02R2 — verifier cho candidate pin của event/log catalog.
 *
 * Review R2 (75/100) để P1-02 ở OPEN vì "code fix có nhưng chưa pin full immutable
 * candidate". Nhánh `m1` được nhiều phiên dùng chung nên pin theo HEAD rot chỉ sau
 * vài phút; pin theo hash-cả-tệp cũng đỏ giả với file DÙNG CHUNG (AuditLogger.php,
 * config/audit.php, AuthController.php — nhiều ticket khác cùng sửa). Áp lại đúng
 * mô hình đã được chấp nhận ở HN-PIN-328/HN-PIN-184:
 *
 *   - File THUỘC SỞ HỮU WWHN-222 (migration event catalog, test):
 *     pin sha256 cả tệp.
 *   - File DÙNG CHUNG: pin sha256 của phần trích xuất đúng các ĐỐI TƯỢNG WWHN-222
 *     sở hữu (chữ ký + payload + resolveRequestId của AuditLogger; khối
 *     sensitive_keys; toàn bộ call site $this->auditLogger->log(...) của
 *     AuthController). Ticket khác sửa phần khác trong cùng tệp -> pin vẫn xanh;
 *     ai đụng đúng đối tượng của WWHN-222 -> pin đỏ, phải review lại.
 *   - implementation_range: các commit chạm candidate. Verifier kiểm mọi commit
 *     trong range là commit thật và là ancestor của HEAD (bất biến dải commit).
 *
 * Chế độ chạy:
 *   node scripts/verify-wwhn222-candidate-pin.mjs
 *   node scripts/verify-wwhn222-candidate-pin.mjs --expect-sha=<40-hex>   # cổng deploy
 *   node scripts/verify-wwhn222-candidate-pin.mjs --write                 # ghi lại pin (kèm review)
 *   node scripts/verify-wwhn222-candidate-pin.mjs --out <file.json>       # xuất report
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';

const PIN_PATH = 'docs/evidence/WWHN-222/RC_PIN.json';

const args = process.argv.slice(2);
const write = args.includes('--write');
const expectSha = (args.find(a => a.startsWith('--expect-sha=')) ?? '').split('=')[1] ?? null;

const pin = JSON.parse(fs.readFileSync(PIN_PATH, 'utf8'));
const failures = [];

const sha256text = text => crypto.createHash('sha256').update(text).digest('hex');
const sha256 = file => sha256text(fs.readFileSync(file));
const git = (...a) => {
  try {
    return execFileSync('git', a, {encoding: 'utf8'}).trim();
  } catch {
    return null;
  }
};

/* ---- Trích xuất đối tượng WWHN-222 trong file dùng chung ---- */
const method = (content, marker) => {
  // Thân method kết thúc ở dòng "    }" đầu tiên sau marker (mọi khối lồng bên
  // trong đều thụt sâu hơn 4 space theo chuẩn PSR-12 của repo).
  const start = content.indexOf(marker);
  if (start === -1) return null;
  const end = content.indexOf('\n    }', start);
  if (end === -1) return null;

  return content.slice(start, end + '\n    }'.length);
};

const EXTRACTORS = {
  'audit-logger-owned-objects': content => {
    const parts = [
      method(content, 'public static function record('),
      method(content, 'public function log('),
      method(content, 'private static function payload('),
      method(content, 'private static function resolveRequestId('),
    ];
    if (parts.some(p => p === null)) return 'EXTRACTOR_MISS';

    return parts.join('\n---\n');
  },
  'audit-sensitive-keys-block': content => {
    const match = content.match(/'sensitive_keys' => \[[\s\S]*?\n    \]/);

    return match ? match[0] : 'EXTRACTOR_MISS';
  },
  'auth-controller-audit-call-sites': content => {
    const matches = content.match(/\$this->auditLogger->log\([\s\S]*?\);/g) ?? [];
    if (matches.length === 0) return 'EXTRACTOR_MISS';

    return [...matches].sort().join('\n---\n');
  },
};

/* ---- 1. Nội dung artifact ---- */
const owned = pin.owned_artifacts ?? {};
const computed = {};
for (const [relative, expected] of Object.entries(owned)) {
  const absolute = path.resolve(relative);
  if (!fs.existsSync(absolute)) {
    failures.push(`Artifact bắt buộc bị thiếu: ${relative}`);
    continue;
  }
  const extractorId = expected && typeof expected === 'object' ? expected.extractor : null;
  if (extractorId) {
    const extractor = EXTRACTORS[extractorId];
    if (!extractor) {
      failures.push(`${relative}: không rõ extractor "${extractorId}" khai trong pin.`);
      continue;
    }
    const extracted = extractor(fs.readFileSync(absolute, 'utf8'));
    if (extracted === 'EXTRACTOR_MISS') {
      failures.push(`${relative}: extractor "${extractorId}" không tìm thấy đối tượng WWHN-222 — cấu trúc file đã đổi, phải review lại.`);
      continue;
    }
    computed[relative] = {extractor: extractorId, sha256: sha256text(extracted)};
  } else {
    computed[relative] = sha256(absolute);
  }
}

if (write) {
  pin.owned_artifacts = computed;
  pin.pinned_at_head = git('rev-parse', 'HEAD');
  pin.pinned_at_branch = git('rev-parse', '--abbrev-ref', 'HEAD');
  fs.writeFileSync(PIN_PATH, `${JSON.stringify(pin, null, 2)}\n`);
  console.log(`WWHN-222 pin đã ghi lại ${Object.keys(computed).length} artifact tại HEAD ${pin.pinned_at_head}.`);
  process.exit(failures.length === 0 ? 0 : 1);
}

for (const [relative, expected] of Object.entries(owned)) {
  if (computed[relative] === undefined) continue;
  if (expected === null) {
    failures.push(`Pin chưa ghi hash cho ${relative} — chạy --write kèm review trước khi dùng làm evidence.`);
    continue;
  }
  const isBlock = expected && typeof expected === 'object';
  const expectedHash = isBlock ? expected.sha256 : expected;
  const computedHash = isBlock ? computed[relative]?.sha256 : computed[relative];
  if (computedHash !== expectedHash) {
    failures.push(
      isBlock
        ? `Đối tượng WWHN-222 sở hữu trong ${relative} đã đổi so với candidate đã pin (extractor "${expected.extractor}").\n` +
          `        pinned  : ${expectedHash}\n` +
          `        current : ${computedHash}`
        : `Nội dung ${relative} đã đổi so với candidate đã pin.\n` +
          `        pinned  : ${expectedHash}\n` +
          `        current : ${computedHash}`,
    );
  }
}

/* ---- 2. Cổng deploy: HEAD phải đúng SHA được tuyên bố ---- */
const head = git('rev-parse', 'HEAD');
if (expectSha !== null) {
  if (!/^[0-9a-f]{40}$/.test(expectSha)) {
    failures.push(`--expect-sha phải là SHA 40 ký tự hex, nhận được: ${expectSha}`);
  } else if (head !== expectSha) {
    failures.push(`HEAD (${head}) khác SHA được tuyên bố đã deploy (${expectSha}). Bằng chứng deploy không hợp lệ.`);
  }
}

/* ---- 3. Bất biến dải commit: mọi commit của candidate phải thật + là ancestor HEAD ---- */
for (const rangeSha of pin.implementation_range ?? []) {
  if (!/^[0-9a-f]{40}$/.test(rangeSha)) {
    failures.push(`implementation_range chứa SHA không hợp lệ: ${rangeSha}`);
    continue;
  }
  if (git('cat-file', '-t', rangeSha) !== 'commit') {
    failures.push(`Commit ${rangeSha} trong implementation_range không tồn tại trong repo.`);
    continue;
  }
  if (head !== null && head !== rangeSha) {
    try {
      execFileSync('git', ['merge-base', '--is-ancestor', rangeSha, head], {stdio: 'ignore'});
    } catch {
      failures.push(
        `Commit ${rangeSha} trong implementation_range không còn nằm trong lịch sử của HEAD ${head}. ` +
        'Nhánh đã bị rewrite — phải review lại candidate trước khi deploy.',
      );
    }
  }
}

const report = {
  pin_id: pin.pin_id,
  jira: pin.jira,
  checked_at_head: head,
  pinned_at_head: pin.pinned_at_head ?? null,
  expect_sha: expectSha,
  artifact_count: Object.keys(owned).length,
  failures,
  status: failures.length === 0 ? 'PASS' : 'FAIL',
};

const outIndex = args.findIndex(a => a === '--out');
if (outIndex !== -1 && args[outIndex + 1]) {
  fs.mkdirSync(path.dirname(path.resolve(args[outIndex + 1])), {recursive: true});
  fs.writeFileSync(args[outIndex + 1], `${JSON.stringify(report, null, 2)}\n`);
}

if (failures.length > 0) {
  console.error('WWHN-222 candidate pin: FAIL');
  for (const failure of failures) console.error(`  - ${failure}`);
  process.exit(1);
}
console.log(`WWHN-222 candidate pin: PASS (${report.artifact_count} artifact, HEAD ${head})`);
