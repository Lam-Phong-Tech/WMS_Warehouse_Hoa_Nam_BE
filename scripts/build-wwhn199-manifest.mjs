/**
 * WWHN-199 · DEV199-P1-03R — sinh MANIFEST pin bằng chứng vào ĐÚNG code đã commit.
 *
 * Register bác bản cũ (ATT-11849) vì nó pin theo "HEAD lúc bắt đầu rà soát": một mô tả
 * không kiểm chứng lại được, và sai ngay khi có commit tiếp theo. Manifest này pin bốn
 * thứ mà register liệt kê:
 *
 *   source commit      — commit CUỐI CÙNG chạm vào tập artifact của WWHN-199 (không phải
 *                        HEAD, vì HEAD trôi theo các task song song trên cùng nhánh m1);
 *   later modification — mọi commit sau candidate có chạm artifact (phải rỗng, nếu không
 *                        thì candidate đã hết hạn) + delta working tree lúc sinh evidence;
 *   candidate build    — SHA-256 của TỪNG artifact đọc từ blob đã commit (`git show`),
 *                        không đọc từ working tree;
 *   test result        — lệnh + kết quả, kèm SHA-256 của file evidence runtime.
 *
 * Dùng:
 *   node scripts/build-wwhn199-manifest.mjs --sha=<candidate-sha>
 *   node scripts/build-wwhn199-manifest.mjs            # tự suy candidate từ git log
 */
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const OUTPUT = 'docs/evidence/WWHN-199/MANIFEST.json'

/** Tập artifact của WWHN-199. Thứ tự cố định để manifest ổn định giữa các lần chạy. */
const ARTIFACTS = [
  'Modules/Warehouse/database/migrations/2026_08_19_120000_consolidate_outbound_active_item_unique_index.php',
  'tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php',
  'tests/Feature/Wwhn199ReservationIndexContractTest.php',
  'scripts/run-wwhn199-evidence.mjs',
  'scripts/run-wwhn199-reservation-race.mjs',
  'scripts/wwhn199-race-fixture.php',
  'scripts/wwhn199-recreate-candidate-database.php',
  'scripts/verify-wwhn199-reservation-invariants.php',
  'scripts/verify-wwhn199-closure.mjs',
  'scripts/build-wwhn199-manifest.mjs',
  'docs/decision-records/DISP-OUT-199-RESERVATION-INDEX.md',
  'docs/evidence/WWHN-199/PG_MIGRATION_AND_INDEX_EVIDENCE.md',
  'docs/evidence/WWHN-199/RACE_EVIDENCE.md',
  'docs/evidence/WWHN-199/SOURCE_BASELINE_PIN.md',
  'docs/evidence/WWHN-199/WWHN-199_QA1_RETEST_PACK.md',
  'docs/docs_task_jira/WWHN-199.md',
]

/** Evidence runtime: sinh ra bởi lần chạy thật, pin bằng hash nội dung working tree. */
const RUNTIME_EVIDENCE = [
  'build/evidence/wwhn-199/evidence-run.json',
  'build/evidence/wwhn-199/migrate-pgsql-candidate.raw.txt',
  'build/evidence/wwhn-199/race-fixture.json',
  'build/evidence/wwhn-199/race-report.json',
  'build/evidence/wwhn-199/race-report.raw.txt',
  'build/evidence/wwhn-199/db-invariants.json',
]

function git(args) {
  return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }).trim()
}

function gitBlob(sha, path) {
  return execFileSync('git', ['show', `${sha}:${path}`], {
    cwd: ROOT,
    encoding: 'buffer',
    maxBuffer: 64 * 1024 * 1024,
  })
}

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex')
}

const shaFlag = process.argv.find((a) => a.startsWith('--sha='))

/**
 * Candidate = commit CUỐI CÙNG chạm vào tập artifact. Pin theo HEAD là sai trên nhánh m1
 * vì nhiều task chạy song song: HEAD có thể là commit của task khác, không hề chứa thay
 * đổi nào của WWHN-199.
 */
const candidate = shaFlag
  ? shaFlag.split('=')[1]
  : git(['log', '-1', '--format=%H', '--', ...ARTIFACTS])

if (!candidate) {
  process.stderr.write('Khong xac dinh duoc candidate SHA (chua commit artifact nao?).\n')
  process.exit(2)
}

const artifacts = []
const missing = []
for (const path of ARTIFACTS) {
  try {
    const blob = gitBlob(candidate, path)
    artifacts.push({ path, sha256: sha256(blob), bytes: blob.length, source: 'git-blob' })
  } catch {
    missing.push(path)
  }
}

if (missing.length > 0) {
  process.stderr.write(
    `Artifact chua ton tai tai commit ${candidate}:\n  ${missing.join('\n  ')}\n` +
      'Commit day du artifact truoc khi sinh manifest.\n',
  )
  process.exit(3)
}

const runtimeEvidence = []
for (const path of RUNTIME_EVIDENCE) {
  const buffer = readFileSync(resolve(ROOT, path))
  runtimeEvidence.push({ path, sha256: sha256(buffer), bytes: buffer.length, source: 'working-tree' })
}

/**
 * "Later modification" theo đúng nghĩa register: commit nào SAU candidate còn chạm vào
 * artifact. Rỗng ⇒ candidate vẫn là bản mới nhất; khác rỗng ⇒ manifest đã hết hạn.
 */
const laterCommits = git([
  'log',
  '--format=%H %ci %s',
  `${candidate}..HEAD`,
  '--',
  ...ARTIFACTS,
])
  .split('\n')
  .filter((line) => line.trim() !== '')

/**
 * Delta working tree tại thời điểm sinh evidence: các file KHÔNG thuộc WWHN-199 đang bị
 * sửa bởi phiên khác trên cùng nhánh m1. Ghi lại để người review biết bằng chứng runtime
 * đã chạy trên cây nào, thay vì tuyên bố một sự trong sạch không có thật.
 */
const artifactSet = new Set(ARTIFACTS)
const foreignDirty = git(['status', '--porcelain'])
  .split('\n')
  .map((line) => line.trim())
  .filter((line) => line !== '')
  .map((line) => line.replace(/^\S+\s+/, ''))
  .filter((path) => !artifactSet.has(path) && !path.startsWith('build/evidence/wwhn-199/'))

const evidenceRun = JSON.parse(readFileSync(resolve(ROOT, 'build/evidence/wwhn-199/evidence-run.json'), 'utf8'))
const race = JSON.parse(readFileSync(resolve(ROOT, 'build/evidence/wwhn-199/race-report.json'), 'utf8'))
const invariants = JSON.parse(readFileSync(resolve(ROOT, 'build/evidence/wwhn-199/db-invariants.json'), 'utf8'))

const manifest = {
  schema_version: '1.0.0',
  task: 'WWHN-199',
  register_item: 'DEV199-P1-03R',
  supersedes_attachment: 'ATT-11849',
  generated_at: new Date().toISOString(),
  candidate: {
    repository: 'HoaNam_BE',
    branch: git(['rev-parse', '--abbrev-ref', 'HEAD']),
    commit: candidate,
    commit_date: git(['show', '-s', '--format=%ci', candidate]),
    commit_subject: git(['show', '-s', '--format=%s', candidate]),
    pin_rule:
      'commit CUOI CUNG cham vao tap artifact WWHN-199 — KHONG pin theo HEAD, vi nhanh m1 ' +
      'co nhieu task chay song song nen HEAD co the khong chua thay doi nao cua task nay.',
  },
  later_modification: {
    commits_touching_artifacts_after_candidate: laterCommits,
    expectation: 'Phai rong. Khac rong ⇒ manifest het han, phai sinh lai va pin SHA moi.',
    working_tree_files_outside_scope_at_evidence_time: foreignDirty,
    note:
      'Evidence runtime chay tren working tree dung chung tren nhanh m1. Danh sach tren la ' +
      'cac file KHONG thuoc WWHN-199 dang duoc phien khac sua tai thoi diem sinh evidence. ' +
      'Chung duoc liet ke tuong minh de nguoi review tu danh gia, thay vi bi giau di.',
  },
  candidate_build: {
    artifacts,
    runtime_evidence: runtimeEvidence,
  },
  test_result: {
    environment: {
      php: process.env.PHP_VERSION ?? null,
      node: process.version,
      postgres_server_version: invariants.server_version ?? null,
      candidate_database: invariants.database ?? null,
    },
    evidence_chain: {
      command: 'node scripts/run-wwhn199-evidence.mjs --database=hn_wwhn199 --port-a=8199 --port-b=8299',
      result: evidenceRun.result,
      steps: (evidenceRun.steps ?? []).map((s) => ({ name: s.name, ok: s.ok })),
    },
    race: {
      item_level_rounds: race.item_level?.rounds ?? null,
      item_level_overlapped_rounds: race.item_level?.overlapped_rounds ?? null,
      item_level_passed_rounds: race.item_level?.passed_rounds ?? null,
      request_level_executed: race.request_level?.executed ?? null,
      request_level_passed: race.request_level?.passed ?? null,
    },
    database_invariants: {
      result: invariants.result,
      outbound_active_item_index_count: invariants.checks?.outbound_active_item_index_count ?? null,
      inbound_active_item_index_count: invariants.checks?.inbound_active_item_index_count ?? null,
      duplicate_probe_blocked: invariants.checks?.duplicate_active_match_probe?.blocked ?? null,
      duplicate_probe_sqlstate: invariants.checks?.duplicate_active_match_probe?.sqlstate ?? null,
    },
    phpunit: [
      { command: 'php artisan test --filter=Wwhn199ReservationConcurrencyGuardTest', driver: 'sqlite' },
      { command: 'php artisan test --filter=Wwhn199ReservationIndexContractTest', driver: 'sqlite (skip) / pgsql (assert)' },
    ],
  },
  verification: {
    command: `node scripts/verify-wwhn199-closure.mjs --expect-sha=${candidate}`,
    what_it_proves:
      'Moi artifact van co dung SHA-256 tai commit da pin; moi bat bien race/DB van PASS; ' +
      'khong con cau "HEAD luc bat dau" trong ho so; khong co file evidence bi tro toi ma thieu.',
  },
}

writeFileSync(resolve(ROOT, OUTPUT), `${JSON.stringify(manifest, null, 2)}\n`, 'utf8')
process.stdout.write(`${OUTPUT} <- candidate ${candidate} (${artifacts.length} artifact)\n`)
if (laterCommits.length > 0) {
  process.stdout.write(`CANH BAO: co ${laterCommits.length} commit sau candidate cham artifact.\n`)
}
