/**
 * WWHN-199 — cổng đóng register DEV199 (P1-01R..P1-06, P2-01).
 *
 * Cổng này tồn tại vì hồ sơ vòng trước đúng ở thời điểm viết rồi mục ruỗng dần: bằng
 * chứng gắn với "HEAD lúc bắt đầu", tên index đổi ở nơi khác, file evidence bị trỏ tới
 * mà không tồn tại. Mỗi check dưới đây là một cách hồ sơ đã hoặc có thể mục.
 *
 * Dùng:
 *   node scripts/verify-wwhn199-closure.mjs
 *   node scripts/verify-wwhn199-closure.mjs --expect-sha=<candidate-sha>
 *
 * `--expect-sha` là chế độ chống pin rot: nó đọc lại từng artifact TỪ COMMIT ĐÃ PIN
 * (không phải từ working tree) và so SHA-256 với manifest. Sai một byte ⇒ đỏ.
 */
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')

let failures = 0
let checks = 0

function check(condition, message) {
  checks++
  if (condition) {
    process.stdout.write(`  ok   ${message}\n`)
  } else {
    failures++
    process.stdout.write(`  FAIL ${message}\n`)
  }
  return condition
}

function section(title) {
  process.stdout.write(`\n${title}\n`)
}

function exists(path) {
  return existsSync(resolve(ROOT, path))
}

function readJson(path) {
  try {
    return JSON.parse(readFileSync(resolve(ROOT, path), 'utf8'))
  } catch {
    return null
  }
}

function readText(path) {
  try {
    return readFileSync(resolve(ROOT, path), 'utf8')
  } catch {
    return null
  }
}

// ---- 1. DEV199-P1-01R: race thật ------------------------------------------
section('1. DEV199-P1-01R — race thật trên PostgreSQL (2 process, 2 connection)')

const race = readJson('build/evidence/wwhn-199/race-report.json')
if (check(race !== null, 'Có build/evidence/wwhn-199/race-report.json')) {
  check(race.item_level?.rounds >= 5, `Race item-level chạy đủ vòng (có ${race.item_level?.rounds})`)
  check(
    race.item_level?.overlapped_rounds === race.item_level?.rounds,
    'Mọi vòng item-level đều CHỒNG LẤN thật (không phải nối đuôi nhau)',
  )
  check(
    race.item_level?.passed_rounds === race.item_level?.rounds,
    'Mọi vòng item-level đều đúng bất biến một-winner-một-conflict',
  )
  check(race.request_level?.executed === true, 'Vòng toa-level (2 Confirm đồng thời cùng 1 toa) đã chạy')
  check(race.request_level?.overlapped === true, 'Vòng toa-level chồng lấn thật')
  check(race.request_level?.passed === true, 'Vòng toa-level đúng bất biến một-winner-một-conflict')
  check(
    race.endpoints?.racer_a !== race.endpoints?.racer_b,
    'Hai racer bắn vào HAI endpoint khác nhau (hai server process)',
  )
  const codes = new Set(
    (race.rounds ?? []).flatMap((r) => (r.results ?? []).map((x) => x.error_code).filter(Boolean)),
  )
  check(
    codes.has('ITEM_ALREADY_MATCHED') || codes.has('DUPLICATE_ITEM_MATCH') || codes.has('DUPLICATE_ITEM_IN_DOCUMENT'),
    `Loser nhận đúng mã nghiệp vụ chống trùng item (thấy: ${[...codes].join(', ') || 'không có'})`,
  )
}

const evidenceRun = readJson('build/evidence/wwhn-199/evidence-run.json')
if (check(evidenceRun !== null, 'Có build/evidence/wwhn-199/evidence-run.json (chuỗi dựng bằng chứng)')) {
  check(evidenceRun.result === 'PASS', `Chuỗi dựng bằng chứng PASS (đang là ${evidenceRun.result})`)
  check(
    (evidenceRun.steps ?? []).every((s) => s.ok === true),
    'Mọi bước của chuỗi dựng bằng chứng đều xanh',
  )
}

check(exists('scripts/run-wwhn199-evidence.mjs'), 'Chuỗi bằng chứng chạy lại được bằng một lệnh')

// ---- 2. DEV199-P1-02R: migration + pg_indexes + duplicate ------------------
section('2. DEV199-P1-02R — migration PostgreSQL, pg_indexes, duplicate behaviour')

const invariants = readJson('build/evidence/wwhn-199/db-invariants.json')
if (check(invariants !== null, 'Có build/evidence/wwhn-199/db-invariants.json')) {
  check(invariants.driver === 'pgsql', `Bằng chứng sinh trên PostgreSQL (driver=${invariants.driver})`)
  check(invariants.result === 'PASS', `db-invariants.json = PASS (đang là ${invariants.result})`)
  check((invariants.failures ?? []).length === 0, 'Không có invariant nào fail')
  check(
    invariants.checks?.duplicate_active_match_probe?.executed === true,
    'Duplicate probe ĐÃ chạy (không phải bỏ qua)',
  )
  check(
    invariants.checks?.duplicate_active_match_probe?.blocked === true,
    `DB chặn INSERT trùng ở tầng dưới ứng dụng (sqlstate=${invariants.checks?.duplicate_active_match_probe?.sqlstate})`,
  )
  const indexes = invariants.checks?.reservation_unique_indexes ?? []
  check(indexes.length > 0, 'Có dump pg_indexes thật trong evidence')
  check(
    indexes.some((i) => i.indexname === 'ux_erd_outbound_active_item'),
    'pg_indexes xác nhận ux_erd_outbound_active_item tồn tại',
  )
}

const migrateLog = readText('build/evidence/wwhn-199/migrate-pgsql-candidate.raw.txt')
if (check(migrateLog !== null, 'Có raw log của php artisan migrate --force')) {
  check(
    (migrateLog.match(/DONE/g) ?? []).length > 100,
    'Log chứng minh toàn bộ migration chạy trên DB dựng mới, không phải vài bước lẻ',
  )
  check(
    migrateLog.includes('2026_08_10_000770_enforce_erd_1_0_keys_relationships_and_constraints'),
    'Log có 000770 (migration bị register nêu đích danh)',
  )
  check(
    migrateLog.includes('2026_08_10_000670_align_identity_container_and_scan_lifecycle'),
    'Log có 000670 (migration bị register nêu đích danh)',
  )
  check(!/\bERROR\b|\bFAIL\b/i.test(migrateLog), 'Log migrate không có ERROR/FAIL')
}

const migrationEvidence = readText('docs/evidence/WWHN-199/PG_MIGRATION_AND_INDEX_EVIDENCE.md')
if (check(migrationEvidence !== null, 'Có PG_MIGRATION_AND_INDEX_EVIDENCE.md')) {
  check(
    migrationEvidence.includes('pg_restore') && migrationEvidence.includes('pg_dump'),
    'Có chiến lược RESTORE vật lý tường minh (không giả định rollback migration)',
  )
  check(
    /migrate:rollback/.test(migrationEvidence),
    'Nói thẳng về migrate:rollback thay vì lờ đi',
  )
  check(
    /fail-forward/i.test(migrationEvidence),
    'Ghi rõ 000670/000770 là fail-forward (down() rỗng)',
  )
}

// ---- 3. DEV199-P2-01: hợp nhất index dư thừa ------------------------------
section('3. DEV199-P2-01 — hợp nhất unique index tương đương')

if (invariants !== null) {
  check(
    invariants.checks?.outbound_active_item_index_count === 1,
    `outbound_item_matches có ĐÚNG 1 unique index ACTIVE-item (đang là ${invariants.checks?.outbound_active_item_index_count})`,
  )
  check(
    invariants.checks?.inbound_active_item_index_count === 1,
    `inbound_scan_entries có ĐÚNG 1 unique index ACTIVE-item (đang là ${invariants.checks?.inbound_active_item_index_count})`,
  )
  check(
    !(invariants.checks?.reservation_unique_indexes ?? []).some(
      (i) => i.tablename === 'outbound_item_matches' && i.indexname === 'uk_outbound_match_active_item',
    ),
    'Bản sao uk_outbound_match_active_item đã được hợp nhất',
  )
}

check(exists('docs/decision-records/DISP-OUT-199-RESERVATION-INDEX.md'), 'Có disposition DISP-OUT-199')
check(
  exists(
    'Modules/Warehouse/database/migrations/2026_08_19_120000_consolidate_outbound_active_item_unique_index.php',
  ),
  'Có migration tiến hợp nhất (không sửa 000670/000770 đã chạy)',
)
check(exists('tests/Feature/Wwhn199ReservationIndexContractTest.php'), 'Có cổng hồi quy hợp đồng index')

// ---- 4. DEV199-P1-04R: ngữ nghĩa reserved_qty -----------------------------
section('4. DEV199-P1-04R — quyết định ngữ nghĩa reserved_qty')

const decision = readText('docs/decision-records/DEC-INV-RESERVED-001-reserved-qty-semantics.md')
if (check(decision !== null, 'Có DEC-INV-RESERVED-001')) {
  check(/Decision date:/.test(decision), 'Decision record có ngày hiệu lực')
  check(/WWHN-199/.test(decision), 'Decision record có neo tới WWHN-199')
  check(
    !/`ux_erd_outbound_active_item` \/ `uk_outbound_match_active_item`/.test(decision),
    'Decision record không còn nêu index đã bị hợp nhất như thể vẫn tồn tại',
  )
}

const reservedContract = readJson('docs/api-contract/WWHN-198-reserved-qty-contract.json')
if (check(reservedContract !== null, 'Có hợp đồng máy đọc được cho reserved_qty')) {
  check(reservedContract.constant_value === 0, 'reserved_qty hằng 0')
  check(
    reservedContract.is_authoritative_for_availability === false,
    'reserved_qty không phải nguồn tính khả dụng',
  )
  check(
    (reservedContract.reservation_source_of_truth?.database_guards ?? []).length === 1,
    'Danh sách guard DB chỉ còn đúng một index canonical',
  )
  check(
    (reservedContract.reservation_source_of_truth?.database_guards ?? []).includes(
      'ux_erd_outbound_active_item',
    ),
    'Guard canonical đúng là ux_erd_outbound_active_item',
  )
}

// ---- 5. DEV199-P1-06: nguồn baseline + mapping REQ/AC ---------------------
section('5. DEV199-P1-06 — current controlled source và mapping REQ/BR/AC')

const sourcePin = readText('docs/evidence/WWHN-199/SOURCE_BASELINE_PIN.md')
if (check(sourcePin !== null, 'Có SOURCE_BASELINE_PIN.md')) {
  check(/BRD v3\.20/.test(sourcePin) && /SRC-002/.test(sourcePin), 'Chỉ đích danh nguồn controlling hiện hành')
  check(/SRS\/FRD v1\.12|SRS v1\.12/.test(sourcePin), 'Chỉ đích danh SRS/FRD v1.12')
  check(/AC-GWT-CONC-001/.test(sourcePin), 'Map tới AC concurrency hiện hành')
  check(/REQ-SRS-014/.test(sourcePin) && /REQ-SRS-012/.test(sourcePin), 'Map tới REQ Outbound/concurrency hiện hành')
  check(/HISTORICAL \/ SUPERSEDED/.test(sourcePin), 'Trích trạng thái supersession nguyên văn từ baseline')
}

// ---- 6. DEV199-P1-05R: gói retest cho QA1 ---------------------------------
section('6. DEV199-P1-05R — gói retest QA1 gỡ chặn WWHN-200')

const qaPack = readText('docs/evidence/WWHN-199/WWHN-199_QA1_RETEST_PACK.md')
if (check(qaPack !== null, 'Có WWHN-199_QA1_RETEST_PACK.md')) {
  check(/WWHN-200/.test(qaPack), 'Gói retest nói rõ quan hệ với WWHN-200')
  check(/run-wwhn199-evidence\.mjs/.test(qaPack), 'QA chạy lại được bằng đúng lệnh của DEV')
  check(/--expect-sha/.test(qaPack), 'QA kiểm chứng được đúng exact build')
}

// ---- 7. DEV199-P1-03R: manifest pin đúng code đã commit -------------------
section('7. DEV199-P1-03R — bằng chứng pin vào code đã commit')

const taskDoc = readText('docs/docs_task_jira/WWHN-199.md')
if (check(taskDoc !== null, 'Có docs/docs_task_jira/WWHN-199.md')) {
  // Kiểm sự VẮNG MẶT của lời khẳng định cũ, không phải sự vắng mặt của chữ "HEAD":
  // hồ sơ mới vẫn phải được phép trích lại câu cũ để giải thích nó sai ở đâu.
  check(
    !/\*\*Exact commit SHA khi bắt đầu rà soát\*\*\s*:/.test(taskDoc),
    'Hồ sơ KHÔNG còn khẳng định pin theo "HEAD lúc bắt đầu" (đúng lỗi mà DEV199-P1-03R bác)',
  )
  check(
    /docs\/evidence\/WWHN-199\/MANIFEST\.json/.test(taskDoc),
    'Hồ sơ trỏ tới manifest làm nguồn pin thay thế',
  )
  check(/ATT-11849/.test(taskDoc), 'Hồ sơ nêu rõ attachment cũ bị thay thế')
}

const manifest = readJson('docs/evidence/WWHN-199/MANIFEST.json')
if (check(manifest !== null, 'Có docs/evidence/WWHN-199/MANIFEST.json')) {
  check(/^[0-9a-f]{40}$/.test(manifest.candidate?.commit ?? ''), 'Manifest pin một commit SHA đầy đủ')
  check(
    (manifest.candidate_build?.artifacts ?? []).length > 0,
    'Manifest liệt kê artifact kèm SHA-256',
  )
  check(
    (manifest.candidate_build?.artifacts ?? []).every((a) => /^[0-9a-f]{64}$/.test(a.sha256)),
    'Mọi artifact đều có SHA-256 hợp lệ',
  )
  check(
    (manifest.later_modification?.commits_touching_artifacts_after_candidate ?? []).length === 0,
    'Không có commit nào sau candidate còn chạm artifact (manifest chưa hết hạn)',
  )
  check(
    manifest.supersedes_attachment === 'ATT-11849',
    'Manifest tuyên bố rõ nó thay thế attachment nào',
  )

  // ANTI-DANGLING: mọi đường dẫn được manifest trỏ tới phải tồn tại thật.
  const dangling = [
    ...(manifest.candidate_build?.artifacts ?? []),
    ...(manifest.candidate_build?.runtime_evidence ?? []),
  ]
    .map((a) => a.path)
    .filter((p) => !exists(p))
  check(dangling.length === 0, `Không có đường dẫn treo trong manifest${dangling.length ? `: ${dangling.join(', ')}` : ''}`)

  // Evidence runtime phải khớp hash đã pin — chống "sinh manifest rồi sửa evidence".
  // So với BLOB tại commit đã pin, không so với working tree: trên Windows file .txt nằm
  // ở dạng CRLF trong working tree còn blob là LF, nên so working tree sẽ đỏ oan trên CI.
  const drifted = (manifest.candidate_build?.runtime_evidence ?? []).filter((entry) => {
    try {
      const blob = execFileSync('git', ['show', `${manifest.candidate.commit}:${entry.path}`], {
        cwd: ROOT,
        encoding: 'buffer',
        maxBuffer: 64 * 1024 * 1024,
      })
      return createHash('sha256').update(blob).digest('hex') !== entry.sha256
    } catch {
      return true
    }
  })
  check(drifted.length === 0, `Evidence runtime khớp hash đã pin${drifted.length ? `: lệch ${drifted.map((d) => d.path).join(', ')}` : ''}`)
}

// ---- 8. Chống pin rot: đọc lại artifact từ commit đã pin ------------------
const expectFlag = process.argv.find((a) => a.startsWith('--expect-sha='))
if (expectFlag !== undefined && manifest !== null) {
  section('8. Chống pin rot — đọc lại artifact TỪ COMMIT ĐÃ PIN')

  const expected = expectFlag.split('=')[1]
  check(
    manifest.candidate?.commit === expected,
    `Manifest pin đúng commit được yêu cầu (${manifest.candidate?.commit} vs ${expected})`,
  )

  let mismatched = 0
  let unreadable = 0
  for (const artifact of [
    ...(manifest.candidate_build?.artifacts ?? []),
    ...(manifest.candidate_build?.runtime_evidence ?? []),
  ]) {
    try {
      const blob = execFileSync('git', ['show', `${expected}:${artifact.path}`], {
        cwd: ROOT,
        encoding: 'buffer',
        maxBuffer: 64 * 1024 * 1024,
      })
      if (createHash('sha256').update(blob).digest('hex') !== artifact.sha256) {
        mismatched++
        process.stdout.write(`       lệch hash: ${artifact.path}\n`)
      }
    } catch {
      unreadable++
      process.stdout.write(`       không đọc được tại commit: ${artifact.path}\n`)
    }
  }
  check(unreadable === 0, 'Mọi artifact đọc được từ commit đã pin')
  check(mismatched === 0, 'Mọi artifact khớp SHA-256 đã pin (nội dung không trôi)')
}

// ---- Kết luận -------------------------------------------------------------
process.stdout.write(`\n${failures === 0 ? 'PASS' : 'FAIL'} — ${checks - failures}/${checks} check xanh\n`)
process.exit(failures === 0 ? 0 : 1)
