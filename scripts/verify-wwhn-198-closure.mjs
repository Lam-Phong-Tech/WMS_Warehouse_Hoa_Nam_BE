#!/usr/bin/env node
/**
 * WWHN-198 — cổng đóng ticket: kiểm tra lại từng hạng mục P1/P2 của register bằng
 * artifact thật, không tin vào văn bản tự khai.
 *
 * Chạy: node scripts/verify-wwhn-198-closure.mjs
 * Tuỳ chọn: --expect-sha=<sha> để chốt đúng commit candidate (chống pin rot).
 */
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const rel = (p) => path.join(ROOT, p)
const REGISTER = rel('docs/evidence/WWHN-198/WWHN-198_CLOSURE_REGISTER.json')

const errors = []
const check = (ok, message) => {
  if (!ok) errors.push(message)
}
const exists = (p) => fs.existsSync(rel(p))
const readJson = (p) => JSON.parse(fs.readFileSync(rel(p), 'utf8'))

check(fs.existsSync(REGISTER), 'Thiếu register đóng ticket WWHN-198.')
if (errors.length > 0) {
  console.error('[WWHN-198] ' + errors.join('\n'))
  process.exit(1)
}
const register = JSON.parse(fs.readFileSync(REGISTER, 'utf8'))

// ---- 1. Mọi hạng mục register phải có evidence tồn tại thật -----------------
for (const item of register.items) {
  check(
    ['CLOSED', 'CLOSED_PENDING_SIGN_OFF'].includes(item.status),
    `${item.id} còn ở trạng thái ${item.status}.`,
  )
  for (const artifact of item.evidence ?? []) {
    check(exists(artifact), `${item.id}: evidence không tồn tại — ${artifact}`)
  }
}

// ---- 2. DEV198-P1-01R: nhánh canonical đúng như đã pin ----------------------
const branch = register.canonical_branch
check(branch?.name === 'm1', 'Nhánh canonical phải là m1 (không tạo nhánh riêng).')
try {
  const ancestor = execFileSync('git', ['merge-base', '--is-ancestor', branch.superseded_by_ancestor, 'origin/m1'], {
    cwd: ROOT,
    stdio: 'pipe',
  })
  void ancestor
} catch {
  errors.push(
    `${branch.superseded_by_ancestor} không còn là ancestor của origin/m1 — disposition DISP-OUT-198 phải xem lại trước khi đóng.`,
  )
}

// ---- 3. DEV198-P1-02R: race PostgreSQL phải PASS thật ----------------------
const raceSummaryPath = register.evidence_index.reservation_race_summary
check(exists(raceSummaryPath), `Thiếu summary race PostgreSQL: ${raceSummaryPath}`)
if (exists(raceSummaryPath)) {
  const race = readJson(raceSummaryPath)
  check(race.status === 'PASS', `Race reservation không PASS (status=${race.status}).`)
  check(race.failed_scenarios?.length === 0, 'Race reservation còn scenario fail.')
  for (const name of ['cross_request_same_item', 'same_request_same_item', 'unique_index_guard']) {
    const scenario = race.scenarios?.[name]
    check(scenario !== undefined, `Race thiếu scenario bắt buộc: ${name}`)
    if (scenario === undefined) continue
    check(scenario.passed === true, `Scenario ${name} không pass.`)
    check(scenario.distinct_backend_pid_count === 2, `Scenario ${name} không dùng 2 backend PostgreSQL tách rời.`)
    check(scenario.real_contention_proven === true, `Scenario ${name} không chứng minh được contention thật.`)
    check(scenario.active_match_count === 1, `Scenario ${name} tạo nhiều hơn 1 match ACTIVE.`)
  }
  // P2-01 dựa hẳn vào bằng chứng này: nếu mã lỗi đổi thì disposition phải xem lại.
  check(
    race.scenarios?.same_request_same_item?.checks?.conflict_is_duplicate_item_match === true,
    'DUPLICATE_ITEM_MATCH không còn là mã lỗi của loser — disposition DEV198-P2-01 (KEEP) phải xem lại.',
  )
}

// ---- 4. DEV198-P1-04R: hợp đồng reserved_qty ------------------------------
const reserved = readJson('docs/api-contract/WWHN-198-reserved-qty-contract.json')
check(reserved.semantics === 'COMPATIBILITY_FIELD', 'reserved_qty phải là COMPATIBILITY_FIELD.')
check(reserved.constant_value === 0, 'reserved_qty phải hằng 0.')
check(reserved.is_authoritative_for_availability === false, 'reserved_qty không được là nguồn khả dụng.')
check(reserved.reservation_model === 'IDENTITY_LEVEL', 'Mô hình giữ chỗ phải là IDENTITY_LEVEL.')
check(exists(reserved.decision_document), `Thiếu decision record: ${reserved.decision_document}`)

// ---- 5. DEV198-P1-06: hợp đồng Packing Label + không còn mã ma ------------
const spec = readJson('docs/api-contract/WWHN-195-outbound-openapi.json')
const label = spec['x-packing-label-contract']
check(label !== undefined, 'OpenAPI outbound thiếu x-packing-label-contract (controlling behavior máy đọc được).')
if (label !== undefined) {
  check(label.cardinality === 'ONE_PER_OUTBOUND_DOCUMENT', 'Nhãn phải là 01 cho mỗi outbound_document.')
  check(label.created_at === 'FULL_MATCH' && label.created_status === 'DRAFT', 'Nhãn phải sinh ở DRAFT khi full match.')
  check(label.auto_dispatch_print === false, 'Hợp đồng không được nói scan tự dispatch in.')
  check(label.frozen_from === 'READY_TO_PRINT', 'Snapshot phải đóng băng từ READY_TO_PRINT.')
}

const PHANTOM_CODES = ['OUTBOUND_MATCH_NOT_ACTIVE', 'PACKING_LABEL_FROZEN', 'IDEMPOTENCY_KEY_REUSED']
const specText = JSON.stringify(spec)
for (const code of PHANTOM_CODES) {
  check(!specText.includes(code), `Mã lỗi ma quay lại trong hợp đồng outbound: ${code}`)
}

// ---- 6. Chống pin rot: candidate SHA phải khớp -----------------------------
const expectFlag = process.argv.find((a) => a.startsWith('--expect-sha='))
if (expectFlag !== undefined) {
  const expected = expectFlag.split('=')[1]
  check(
    register.candidate.backend_sha === expected,
    `Register pin ${register.candidate.backend_sha} nhưng --expect-sha=${expected}.`,
  )
}
try {
  execFileSync('git', ['cat-file', '-e', register.candidate.backend_sha + '^{commit}'], { cwd: ROOT, stdio: 'pipe' })
} catch {
  errors.push(`Candidate SHA backend không tồn tại trong repo: ${register.candidate.backend_sha}`)
}

if (errors.length > 0) {
  console.error('[WWHN-198] ĐÓNG TICKET KHÔNG ĐẠT:')
  for (const e of errors) console.error('  - ' + e)
  process.exit(1)
}

console.log(
  JSON.stringify(
    {
      ticket: 'WWHN-198',
      status: 'PASS',
      canonical_branch: register.canonical_branch.name,
      candidate: register.candidate,
      items: register.items.map((i) => `${i.id}: ${i.status}`),
    },
    null,
    2,
  ),
)
