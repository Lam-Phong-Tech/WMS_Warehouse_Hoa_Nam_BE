#!/usr/bin/env node
/**
 * WWHN-201 — verifier bằng chứng + pin (DEV201-P1-01R / P1-02R / P1-03R).
 *
 * Trả lời đúng câu hỏi mà QA1/Tech Lead phải trả lời khi đóng gate:
 *   "Bộ bằng chứng này có thật, có nguyên vẹn, và có thuộc về đúng candidate không?"
 *
 * Mặc định chạy ở chế độ **STRUCTURE**: kiểm cấu trúc/digest/pin — chạy được ở mọi
 * máy, không cần PostgreSQL. Thêm `--gate` để bật chế độ **CLOSURE**: đòi thêm bằng
 * chứng runtime đã chạy THẬT trên PostgreSQL (db_evidence_status=OK, summary=PASS,
 * hai scenario đều chứng minh được contention thật). Chế độ CLOSURE là điều kiện
 * đóng DEV201-P1-02R; chế độ STRUCTURE không bao giờ được coi là đủ để đóng gate.
 */
import { createHash } from 'node:crypto'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const DIR = path.join(ROOT, 'docs/evidence/WWHN-201')
const GATE = process.argv.includes('--gate')

const errors = []
const warnings = []
const check = (ok, message) => {
  if (!ok) errors.push(message)
}
const sha256 = (text) => createHash('sha256').update(String(text).replace(/\r\n/g, '\n'), 'utf8').digest('hex')

for (const file of ['MANIFEST.json', 'MANIFEST.sha256', 'CANDIDATE_PIN.json']) {
  if (!fs.existsSync(path.join(DIR, file))) {
    console.error(`[WWHN-201] Thiếu docs/evidence/WWHN-201/${file}. Chạy scripts/wwhn201-evidence-manifest.php và scripts/wwhn-201-candidate-pin.mjs.`)
    process.exit(1)
  }
}

const manifestRaw = fs.readFileSync(path.join(DIR, 'MANIFEST.json'), 'utf8')
const manifest = JSON.parse(manifestRaw)
const pin = JSON.parse(fs.readFileSync(path.join(DIR, 'CANDIDATE_PIN.json'), 'utf8'))

// ---- 1. MANIFEST chưa bị sửa tay -------------------------------------------
const declared = fs.readFileSync(path.join(DIR, 'MANIFEST.sha256'), 'utf8').trim().split(/\s+/)[0]
check(
  sha256(manifestRaw) === declared,
  `MANIFEST.json đã bị sửa sau khi phát hành (sha256 ${sha256(manifestRaw).slice(0, 12)}… ≠ ${String(declared).slice(0, 12)}…).`,
)

// ---- 2. Từng file bằng chứng còn nguyên -------------------------------------
const files = Object.entries(manifest.evidence_files ?? {})
check(files.length > 0, 'MANIFEST không liệt kê file bằng chứng nào.')
for (const [rel, digest] of files) {
  const full = path.join(DIR, rel)
  if (!fs.existsSync(full)) {
    errors.push(`Bằng chứng đã khai nhưng không tồn tại: ${rel}`)
    continue
  }
  check(sha256(fs.readFileSync(full, 'utf8')) === digest, `Bằng chứng bị sửa sau khi đóng gói: ${rel}`)
}

// Bốn nhóm artifact mà review gọi đích danh phải có mặt, không được "gói cho có".
const required = [
  { label: 'runtime-concurrency-summary.json', match: /runtime-concurrency-summary\.json$/ },
  { label: 'worker results', match: /worker-\d+\.json$/ },
  { label: 'pg-lock-samples', match: /pg-lock-samples\.json$/ },
]
for (const { label, match } of required) {
  check(
    files.some(([rel]) => match.test(rel)),
    `Bộ bằng chứng thiếu ${label} — DEV201-P1-02R gọi đích danh artifact này.`,
  )
}

// ---- 3. Ánh xạ behavior SHA ↔ harness SHA -----------------------------------
const mapping = manifest.sha_mapping ?? {}
check(Boolean(mapping.behavior?.last_commit), 'MANIFEST thiếu behavior SHA.')
check(Boolean(mapping.harness?.last_commit), 'MANIFEST thiếu harness SHA.')
check(
  mapping.behavior?.last_commit === pin.candidate?.behavior?.last_commit,
  `behavior SHA trong MANIFEST (${mapping.behavior?.last_commit?.slice(0, 8)}) khác pin (${pin.candidate?.behavior?.last_commit?.slice(0, 8)}) — bằng chứng và candidate không phải cùng một bản.`,
)
check(
  mapping.harness?.last_commit === pin.candidate?.harness?.last_commit,
  `harness SHA trong MANIFEST khác pin — sinh lại MANIFEST sau khi commit harness.`,
)

// ---- 4. Pin còn hiệu lực ----------------------------------------------------
for (const [group, entry] of Object.entries(pin.candidate ?? {})) {
  check(entry.missing.length === 0, `Pin nhóm ${group} trỏ tới file không tồn tại: ${entry.missing.join(', ')}`)
  check(
    entry.uncommitted_changes.length === 0,
    `Pin nhóm ${group} không hợp lệ: còn thay đổi CHƯA COMMIT (${entry.uncommitted_changes.join('; ')}). Pin phải trỏ tới commit, không trỏ tới working tree.`,
  )
  for (const [file, digest] of Object.entries(entry.digests)) {
    const full = path.join(ROOT, file)
    if (!fs.existsSync(full)) {
      errors.push(`Pin nhóm ${group}: thiếu file ${file}`)
      continue
    }
    check(sha256(fs.readFileSync(full, 'utf8')) === digest, `Pin nhóm ${group}: ${file} đã đổi so với lúc pin.`)
  }
}
if ((pin.behavior_drift_since_reviewed?.commits ?? []).length > 0) {
  warnings.push(
    `Behavior đã đổi ${pin.behavior_drift_since_reviewed.commits.length} commit kể từ SHA đã review (${String(pin.reviewed_shas?.behavior).slice(0, 8)}). Tech Lead phải duyệt đúng phần delta này; gate WWHN-201 phải chạy lại ở candidate hiện tại.`,
  )
}

// ---- 5. Chế độ CLOSURE ------------------------------------------------------
if (GATE) {
  check(
    manifest.db_evidence_status === 'OK',
    `db_evidence_status=${manifest.db_evidence_status}: chưa có bằng chứng DB version/migration/index. Chạy lại manifest với DB_CONNECTION=pgsql và credential thật.`,
  )
  check(
    manifest.database?.driver === 'pgsql',
    `Bằng chứng runtime phải chạy trên PostgreSQL (driver hiện tại: ${manifest.database?.driver}). SQLite :memory: không chứng minh được contention.`,
  )
  check(manifest.runtime_summary_status === 'PASS', `runtime summary = ${manifest.runtime_summary_status}, phải là PASS.`)
  const scenarios = Object.entries(manifest.runtime_summary_scenarios ?? {})
  check(scenarios.length >= 2, 'Phải có tối thiểu 2 scenario: same-document và shared-balance non-negative.')
  for (const [name, scenario] of scenarios) {
    check(scenario.passed === true, `Scenario ${name} chưa PASS.`)
    // "Có chạy song song" khác "có tranh chấp thật". Không có hai điều kiện này thì
    // một lần chạy tuần tự trá hình cũng cho ra summary xanh.
    check(scenario.checks?.two_distinct_backend_connections === true, `Scenario ${name}: chưa chứng minh 2 connection PostgreSQL riêng biệt.`)
    check(scenario.checks?.real_contention_proven === true, `Scenario ${name}: chưa quan sát được lock wait/critical section chồng nhau.`)
    check(scenario.checks?.balance_never_negative === true, `Scenario ${name}: bất biến không-âm-tồn chưa được khẳng định.`)
  }
  for (const table of ['inventory_balances', 'stock_documents', 'idempotency_keys']) {
    check(
      Array.isArray(manifest.indexes?.[table]) && manifest.indexes[table].length > 0,
      `Thiếu bằng chứng index của bảng ${table}.`,
    )
  }
}

for (const w of warnings) console.warn('[WWHN-201][WARN] ' + w)
if (errors.length > 0) {
  console.error(`[WWHN-201] BẰNG CHỨNG KHÔNG ĐẠT (chế độ ${GATE ? 'CLOSURE' : 'STRUCTURE'}):`)
  for (const e of errors) console.error('  - ' + e)
  process.exit(1)
}
console.log(
  JSON.stringify(
    {
      ticket: 'WWHN-201',
      mode: GATE ? 'CLOSURE' : 'STRUCTURE',
      status: 'PASS',
      warnings,
      behavior_sha: mapping.behavior?.last_commit,
      harness_sha: mapping.harness?.last_commit,
      contract_sha: pin.candidate?.contract?.last_commit,
      db_evidence_status: manifest.db_evidence_status,
      runtime_summary_status: manifest.runtime_summary_status,
      evidence_file_count: manifest.evidence_file_count,
    },
    null,
    2,
  ),
)
