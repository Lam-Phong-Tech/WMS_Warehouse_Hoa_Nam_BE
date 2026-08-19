#!/usr/bin/env node
/**
 * WWHN-184 / DEV184-P1-02R — verifier cho candidate pin của luồng nhập kho.
 *
 * Reviewer để P1-02R ở OPEN vì "không có deployed candidate; branch đã trôi 2 commits
 * và có PG posting migration/service change". Vấn đề gốc không phải là quên deploy —
 * mà là `m1` được nhiều phiên dùng chung, nên mọi cách pin theo HEAD đều rot chỉ sau
 * vài phút: đến lúc DevOps deploy thì HEAD đã khác, và không ai chứng minh được bản
 * đang chạy có đúng là bản đã review hay không.
 *
 * Vì vậy pin theo **nội dung**, không theo HEAD (cùng mô hình đã được chấp nhận ở
 * HN-PIN-273): WWHN-184 sở hữu một tập file cụ thể; pin ghi sha256 của từng file đó.
 *   - Commit của ticket khác lên `m1` -> pin vẫn xanh (không rot).
 *   - Ai đó sửa đúng file của WWHN-184 -> pin đỏ ngay, phải review lại.
 *
 * DEV184-P1-03R3 (retest 2026-08-19): lời hứa "commit của ticket khác không làm đỏ
 * pin" bị vỡ cho đúng 1 file — `Modules/Warehouse/routes/api.php` — vì đây là file
 * route DÙNG CHUNG cho mọi module (inbound/outbound/warranty/component-issue...).
 * WWHN-195 và WWHN-234 sửa route outbound/component-issue trong CHÍNH file này ->
 * hash cả file đổi -> pin đỏ dù không dòng inbound nào bị đụng (verify bằng
 * `git diff <old> -- Modules/Warehouse/routes/api.php`, không có hunk nào chạm
 * `inbound-documents`). Áp dụng lại đúng mô hình đã dùng ở HN-PIN-328 ("pin mức đối
 * tượng thay vì hash cả tệp"): với file dùng chung, không hash toàn bộ nội dung mà
 * trích xuất đúng các statement `Route::verb('inbound-documents...')->middleware(...)`
 * (đại diện toàn bộ Annex C operation của WWHN-184 + alias/scan-entries), sắp xếp ổn
 * định rồi hash phần trích xuất đó — bất biến với mọi sửa đổi ở phần route khác trong
 * cùng file.
 *
 * Chế độ chạy:
 *   node scripts/verify-wwhn184-candidate-pin.mjs
 *       Kiểm tra nội dung working tree khớp pin.
 *   node scripts/verify-wwhn184-candidate-pin.mjs --expect-sha=<40-hex>
 *       Dùng ở cổng deploy: bắt buộc HEAD đúng bằng SHA mà DevOps tuyên bố đã deploy.
 *   node scripts/verify-wwhn184-candidate-pin.mjs --write
 *       Ghi lại bảng hash (chỉ dùng khi cố ý thay đổi candidate, kèm review).
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';

const PIN_PATH = 'docs/evidence/WWHN-184/RC_PIN.json';

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

/* ---- File dùng chung cần pin theo khối sở hữu, không hash cả tệp ---- */
const ROUTE_BLOCK_PATTERN = /Route::(?:get|post|patch)\(\s*'inbound-documents[^']*'[\s\S]*?;/g;
// DEV184-P1-05R4: 2 entry fail-forward mà WWHN-184 khai trong allowlist dùng chung —
// hash đúng 2 dòng khai báo đó, sửa entry của ticket khác không làm đỏ pin.
const ALLOWLIST_ENTRY_PATTERN =
  /'2026_08_19_(?:000002_fix_audit_log_pgsql_view_missing_event_catalog_fields|130000_sync_inbound_lines_mirror_deletes)'\s*=>\s*'[^']*'/g;
const EXTRACTORS = {
  'inbound-route-block': content => {
    const matches = content.match(ROUTE_BLOCK_PATTERN) ?? [];

    return [...matches].sort().join('\n---\n');
  },
  'golive-allowlist-wwhn184-entries': content => {
    const matches = content.match(ALLOWLIST_ENTRY_PATTERN) ?? [];

    return [...matches].sort().join('\n---\n');
  },
};

/* ---- 1. Nội dung file thuộc tập pin: sở hữu + critical dependency (P1-05R4) ---- */
const owned = pin.owned_artifacts ?? {};
const dependencies = pin.critical_dependencies ?? {};
const sections = [
  ['owned_artifacts', owned],
  ['critical_dependencies', dependencies],
];
const computedBySection = {owned_artifacts: {}, critical_dependencies: {}};
for (const [sectionName, entries] of sections) {
  const computed = computedBySection[sectionName];
  for (const [relative, expected] of Object.entries(entries)) {
    if (relative === 'note') continue; // metadata, không phải artifact
    const absolute = path.resolve(relative);
    if (!fs.existsSync(absolute)) {
      failures.push(`Artifact bắt buộc bị thiếu (${sectionName}): ${relative}`);
      continue;
    }
    const extractorId = expected && typeof expected === 'object' ? expected.extractor : null;
    if (extractorId) {
      const extractor = EXTRACTORS[extractorId];
      if (!extractor) {
        failures.push(`${relative}: không rõ extractor "${extractorId}" khai trong pin.`);
        continue;
      }
      computed[relative] = {extractor: extractorId, sha256: sha256text(extractor(fs.readFileSync(absolute, 'utf8')))};
    } else {
      computed[relative] = sha256(absolute);
    }
  }
}

if (write) {
  pin.owned_artifacts = computedBySection.owned_artifacts;
  if (Object.keys(dependencies).length > 0) pin.critical_dependencies = computedBySection.critical_dependencies;
  pin.pinned_at_head = git('rev-parse', 'HEAD');
  pin.pinned_at_branch = git('rev-parse', '--abbrev-ref', 'HEAD');
  fs.writeFileSync(PIN_PATH, `${JSON.stringify(pin, null, 2)}\n`);
  console.log(`WWHN-184 pin đã ghi lại ${Object.keys(computedBySection.owned_artifacts).length} artifact tại HEAD ${pin.pinned_at_head}.`);
  process.exit(failures.length === 0 ? 0 : 1);
}

for (const [sectionName, entries] of sections) {
  const computed = computedBySection[sectionName];
  for (const [relative, expected] of Object.entries(entries)) {
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
          ? `Khối sở hữu bởi WWHN-184 trong ${relative} (${sectionName}) đã đổi so với candidate đã pin (extractor "${expected.extractor}").\n` +
            `        pinned  : ${expectedHash}\n` +
            `        current : ${computedHash}`
          : `Nội dung ${relative} (${sectionName}) đã đổi so với candidate đã pin.\n` +
            `        pinned  : ${expectedHash}\n` +
            `        current : ${computedHash}`,
      );
    }
  }
}

/* ---- 2. Cổng deploy: HEAD phải đúng SHA mà DevOps tuyên bố ---- */
const head = git('rev-parse', 'HEAD');
if (expectSha !== null) {
  if (!/^[0-9a-f]{40}$/.test(expectSha)) {
    failures.push(`--expect-sha phải là SHA 40 ký tự hex, nhận được: ${expectSha}`);
  } else if (head !== expectSha) {
    failures.push(`HEAD (${head}) khác SHA được tuyên bố đã deploy (${expectSha}). Bằng chứng deploy không hợp lệ.`);
  }
}

/* ---- 3. Pin phải trỏ tới một commit có thật và nằm trên nhánh khai báo ---- */
const pinnedHead = pin.pinned_at_head ?? null;
if (pinnedHead !== null) {
  if (!/^[0-9a-f]{40}$/.test(pinnedHead)) {
    failures.push(`pinned_at_head không phải SHA hợp lệ: ${pinnedHead}`);
  } else if (git('cat-file', '-t', pinnedHead) !== 'commit') {
    failures.push(`pinned_at_head ${pinnedHead} không phải commit có thật trong repo.`);
  } else if (head !== null && head !== pinnedHead) {
    // Đây KHÔNG phải lỗi: m1 dùng chung nên HEAD trôi là bình thường. Chỉ cần
    // commit đã pin vẫn nằm trong lịch sử của HEAD hiện tại.
    const isAncestor = (() => {
      try {
        execFileSync('git', ['merge-base', '--is-ancestor', pinnedHead, head], {stdio: 'ignore'});

        return true;
      } catch {
        return false;
      }
    })();
    if (!isAncestor) {
      failures.push(
        `Commit đã pin ${pinnedHead} không còn nằm trong lịch sử của HEAD ${head}. ` +
        'Nhánh đã bị rewrite — phải review lại candidate trước khi deploy.',
      );
    }
  }
}

/* ---- 4. Post-fix exact candidate SHA (DEV184-P1-05R4) ----
 * QA report R3 ghi SHA đầu lượt test (dae5ff1) trong khi PASS cuối cùng phát sinh SAU
 * khi sửa defect — reviewer yêu cầu resolve MỘT candidate SHA hậu-fix duy nhất.
 *
 * Cách kiểm KHÔNG dùng `git log -1 -- <paths>`: hai file trong tập pin là file DÙNG
 * CHUNG (routes/api.php, golive allowlist) nên "commit cuối chạm path" rot theo mọi
 * commit của ticket khác — đúng căn bệnh mà pin mức đối tượng đã chữa. Thay vào đó
 * kiểm bằng NỘI DUNG: mọi pinned object đọc tại chính post_fix_candidate.sha
 * (`git show <sha>:<path>`, qua extractor nếu có) phải cho ra đúng hash đã pin.
 * Kết hợp với phần 1 (working tree khớp pin) suy ra: nội dung QA đã test tại candidate
 * == nội dung pin == nội dung hiện tại — commit của ticket khác lên phần không pin
 * của file dùng chung không làm đỏ, còn ai đổi đúng object đã pin thì đỏ ngay. */
const pinPaths = [...Object.keys(owned), ...Object.keys(dependencies)].filter(p => p !== 'note');
const resolvedCandidate = pinPaths.length > 0 ? git('log', '-1', '--format=%H', '--', ...pinPaths) : null;
const declaredCandidate = pin.post_fix_candidate?.sha ?? null;
if (declaredCandidate !== null) {
  if (!/^[0-9a-f]{40}$/.test(declaredCandidate)) {
    failures.push(`post_fix_candidate.sha không phải SHA hợp lệ: ${declaredCandidate}`);
  } else if (git('cat-file', '-t', declaredCandidate) !== 'commit') {
    failures.push(`post_fix_candidate.sha ${declaredCandidate} không phải commit có thật trong repo.`);
  } else {
    const isAncestor = (() => {
      try {
        execFileSync('git', ['merge-base', '--is-ancestor', declaredCandidate, head], {stdio: 'ignore'});

        return true;
      } catch {
        return false;
      }
    })();
    if (!isAncestor) {
      failures.push(`post_fix_candidate.sha ${declaredCandidate} không nằm trong lịch sử của HEAD ${head}.`);
    }
    for (const [sectionName, entries] of sections) {
      for (const [relative, expected] of Object.entries(entries)) {
        if (relative === 'note' || expected === null) continue;
        let shown;
        try {
          shown = execFileSync('git', ['show', `${declaredCandidate}:${relative}`], {maxBuffer: 64 * 1024 * 1024});
        } catch {
          failures.push(`${relative} không tồn tại tại post_fix_candidate ${declaredCandidate}.`);
          continue;
        }
        const isBlock = expected && typeof expected === 'object';
        const atCandidate = isBlock
          ? sha256text(EXTRACTORS[expected.extractor]?.(shown.toString('utf8')) ?? '')
          : sha256text(shown);
        const expectedHash = isBlock ? expected.sha256 : expected;
        if (atCandidate !== expectedHash) {
          failures.push(
            `Nội dung pinned của ${relative} (${sectionName}) tại post_fix_candidate ${declaredCandidate.slice(0, 8)} ` +
            `khác hash đã pin — candidate khai báo không phải bản đã được pin/QA.\n` +
            `        pinned       : ${expectedHash}\n` +
            `        at candidate : ${atCandidate}`,
          );
        }
      }
    }
  }
}

const report = {
  pin_id: pin.pin_id,
  jira: pin.jira,
  checked_at_head: head,
  pinned_at_head: pinnedHead,
  expect_sha: expectSha,
  artifact_count: Object.keys(owned).length,
  dependency_count: Object.keys(dependencies).filter(k => k !== 'note').length,
  declared_post_fix_candidate: declaredCandidate,
  resolved_post_fix_candidate: resolvedCandidate,
  failures,
  status: failures.length === 0 ? 'PASS' : 'FAIL',
};

const outIndex = args.findIndex(a => a === '--out');
if (outIndex !== -1 && args[outIndex + 1]) {
  fs.mkdirSync(path.dirname(path.resolve(args[outIndex + 1])), {recursive: true});
  fs.writeFileSync(args[outIndex + 1], `${JSON.stringify(report, null, 2)}\n`);
}

if (failures.length > 0) {
  console.error('WWHN-184 candidate pin: FAIL');
  for (const failure of failures) console.error(`  - ${failure}`);
  process.exit(1);
}
console.log(
  `WWHN-184 candidate pin: PASS (${report.artifact_count} artifact + ${report.dependency_count} dependency, ` +
  `candidate ${resolvedCandidate ? resolvedCandidate.slice(0, 8) : 'n/a'}, HEAD ${head})`,
);
