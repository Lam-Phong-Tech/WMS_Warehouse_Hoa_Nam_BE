#!/usr/bin/env node
/**
 * WWHN-201 / DEV201-P1-03R — pin candidate BẤT BIẾN cho release.
 *
 * Finding gốc: "Behavior SHA đã biết nhưng branch tiếp tục chạy; chưa có release
 * pin". Nhánh m1 nhận commit của nhiều task song song, nên pin một HEAD là pin một
 * thứ hết hạn ngay hôm sau ("pin rot"). Cách pin ở đây:
 *
 *   - **Không** pin HEAD. Pin *commit cuối chạm vào từng tập file* (behavior /
 *     harness / contract). Commit của task khác trên m1 không làm pin lệch.
 *   - Ghi kèm **SHA đã review** (`REVIEWED_BEHAVIOR_SHA` — bản mà register nhắc tới)
 *     và liệt kê **chính xác** các commit đã chạm behavior kể từ đó, để Tech Lead
 *     duyệt đúng phần delta thay vì duyệt lại toàn bộ.
 *   - Ghi digest file, nên "pin đúng SHA nhưng file bị sửa tay sau đó" cũng bị bắt.
 *
 * Chạy: `node scripts/wwhn-201-candidate-pin.mjs`   (chạy LẠI sau mỗi lần commit)
 * Kiểm tra: `node scripts/verify-wwhn-201-evidence.mjs`
 */
import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const PIN_PATH = path.join(ROOT, 'docs/evidence/WWHN-201/CANDIDATE_PIN.json')

/**
 * SHA mà register DEV201-P1-01R..03R đang nói tới. Giữ nguyên như hằng số lịch sử:
 * nó là điểm neo để đo drift, không phải candidate hiện tại.
 */
export const REVIEWED = {
  behavior: '74100825ac0c333e04a5758602595224ba438f19',
  harness: 'f615b6a727beb17e21175f8520426f9c8219b49f',
  superseded: '7241621b59a05388ba6915802ddc16c35e0bcc8b',
}

export const FILE_SETS = {
  behavior: [
    'Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php',
    'Modules/Warehouse/app/Services/CurrentBaselineStockService.php',
    'app/Support/IdempotencyStore.php',
    'Modules/Warehouse/routes/api.php',
  ],
  harness: [
    'scripts/wwhn201-runtime-concurrency.php',
    'scripts/wwhn219-concurrency-worker.php',
    'scripts/wwhn201-evidence-manifest.php',
    'tests/Feature/Wwhn201PostIssueErrorContractTest.php',
    'tests/Feature/Wwhn201PostIssueAtomicNonNegativeStockTest.php',
  ],
  contract: [
    'docs/api-contract/WWHN-195-outbound-openapi.json',
    'docs/api-contract/WWHN-195-outbound-openapi.yaml',
    'docs/api-contract/WWHN-201-outbound-contract-lock.json',
    'docs/postman/WWHN-195-outbound.postman_collection.json',
    'docs/decision-records/DEC-OUT-STOCK-HTTP-001-insufficient-stock-http-status.md',
    'scripts/wwhn-201-outbound-contract-lock.mjs',
    'scripts/verify-wwhn-201-contract.mjs',
  ],
}

const git = (args) => execFileSync('git', args, { cwd: ROOT }).toString().trim()

function pinSet(files) {
  const present = files.filter((f) => fs.existsSync(path.join(ROOT, f)))
  const missing = files.filter((f) => !present.includes(f))
  let lastCommit = null
  let uncommitted = []
  try {
    lastCommit = present.length === 0 ? null : git(['log', '-1', '--format=%H', '--', ...present]) || null
    uncommitted = git(['status', '--porcelain', '--', ...present])
      .split('\n')
      .map((l) => l.trim())
      .filter(Boolean)
  } catch {
    /* repo không sẵn sàng — để null, verifier sẽ báo. */
  }
  return {
    files,
    missing,
    last_commit: lastCommit,
    last_commit_date: lastCommit ? git(['log', '-1', '--format=%ad', '--date=short', lastCommit]) : null,
    last_commit_subject: lastCommit ? git(['log', '-1', '--format=%s', lastCommit]) : null,
    // Pin chỉ có nghĩa khi working tree sạch cho đúng tập file này.
    uncommitted_changes: uncommitted,
    digests: Object.fromEntries(
      present.map((f) => [
        f,
        createHash('sha256')
          .update(fs.readFileSync(path.join(ROOT, f), 'utf8').replace(/\r\n/g, '\n'), 'utf8')
          .digest('hex'),
      ]),
    ),
  }
}

function driftSince(sha, files) {
  try {
    const log = git(['log', '--oneline', `${sha}..HEAD`, '--', ...files])
    return log === '' ? [] : log.split('\n')
  } catch {
    return null
  }
}

const pin = {
  ticket: 'WWHN-201',
  finding: 'DEV201-P1-03R',
  decision: 'DEC-OUT-STOCK-HTTP-001',
  branch: git(['rev-parse', '--abbrev-ref', 'HEAD']),
  generated_from_head: git(['rev-parse', 'HEAD']),
  reviewed_shas: REVIEWED,
  superseded: {
    sha: REVIEWED.superseded,
    reason:
      'Bản 7241621… đã được register đánh dấu superseded; giữ lại trong pin để mọi comment cũ trỏ về nó đọc được lý do, không phải tra Jira.',
  },
  candidate: {
    behavior: pinSet(FILE_SETS.behavior),
    harness: pinSet(FILE_SETS.harness),
    contract: pinSet(FILE_SETS.contract),
  },
  behavior_drift_since_reviewed: {
    from: REVIEWED.behavior,
    commits: driftSince(REVIEWED.behavior, FILE_SETS.behavior),
    note:
      'Đây là delta Tech Lead cần duyệt. Danh sách RỖNG nghĩa là candidate y hệt bản đã review. Danh sách KHÔNG rỗng không phải lỗi — nó là lý do vì sao pin phải sinh lại, và vì sao gate WWHN-201 phải chạy lại ở candidate hiện tại chứ không dựa vào kết quả cũ.',
  },
  gate: {
    commands: [
      'php artisan test --filter=Wwhn201',
      'node scripts/verify-wwhn-201-contract.mjs',
      'DB_CONNECTION=pgsql php scripts/wwhn201-runtime-concurrency.php storage/app/wwhn-201-evidence',
      'DB_CONNECTION=pgsql php scripts/wwhn201-evidence-manifest.php storage/app/wwhn-201-evidence',
      'node scripts/verify-wwhn-201-evidence.mjs',
    ],
    note: 'Cả năm lệnh phải xanh TẠI ĐÚNG candidate ở trên thì mới đóng được DEV201-P1-01R/02R/03R.',
  },
}

fs.mkdirSync(path.dirname(PIN_PATH), { recursive: true })
fs.writeFileSync(PIN_PATH, JSON.stringify(pin, null, 2) + '\n')
console.log(
  JSON.stringify(
    {
      pin: 'docs/evidence/WWHN-201/CANDIDATE_PIN.json',
      behavior: pin.candidate.behavior.last_commit,
      harness: pin.candidate.harness.last_commit,
      contract: pin.candidate.contract.last_commit,
      behavior_drift_commits: pin.behavior_drift_since_reviewed.commits?.length ?? null,
      uncommitted: [
        ...pin.candidate.behavior.uncommitted_changes,
        ...pin.candidate.harness.uncommitted_changes,
        ...pin.candidate.contract.uncommitted_changes,
      ],
    },
    null,
    2,
  ),
)
