#!/usr/bin/env node
/**
 * WWHN-266 — cổng đóng ticket, fail-closed.
 *
 *   node scripts/verify-wwhn-266-closure.mjs
 *
 * Vì sao tồn tại: ba ticket liên tiếp (WWHN-156, WWHN-292, WWHN-268) bị reviewer
 * chấm "generic" vì defect register viết bằng văn xuôi nên không ai kiểm chứng
 * được. Vòng review WWHN-266 lặp lại đúng lời đó ("Không có defect input",
 * "0 attachment, 0 implementation comment, 0 task SHA"). Cách chặn tận gốc đã
 * dùng ở WWHN-268 và tái dùng ở đây: register là JSON có schema, và script này
 * từ chối mọi dòng thiếu trường, độn chữ, trỏ tới file không tồn tại, hoặc khai
 * FIXED mà retest không PASS.
 *
 * Script KHÔNG tự chạy test. Nó kiểm tính toàn vẹn của hồ sơ và tính nhất quán
 * giữa hồ sơ với artifact do test sinh ra — phần chạy thật là việc của CI ở
 * exact SHA và của QA1 (WWHN-264).
 */

import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const REGISTER_PATH = join(ROOT, 'docs/evidence/WWHN-266/DEFECT_REGISTER.json')
const PIN_PATH = join(ROOT, 'docs/evidence/WWHN-266/CANDIDATE_PIN.json')
const TRACEABILITY_PATH = join(ROOT, 'docs/evidence/WWHN-266/TC_P1_017_024_TRACEABILITY.json')
const RAW_EVIDENCE_PATH = join(ROOT, 'build/evidence/wwhn-266/component-issue-core-transaction.json')
const OPENAPI_PATH = join(ROOT, 'storage/api-docs/api-docs.json')

/** Chuỗi độn: có mặt là register chưa được điền thật. */
const FILLER = [/^tbd$/i, /^n\/?a$/i, /^\.{3}$/, /^-+$/, /^chưa có$/i, /^todo$/i, /^\?+$/]

/** Tám chiều bắt buộc của SRS §13.3 cho một action đổi tồn. */
const REQUIRED_DIMENSIONS = [
  'TC-P1-017',
  'TC-P1-018',
  'TC-P1-019',
  'TC-P1-020',
  'TC-P1-021',
  'TC-P1-022',
  'TC-P1-023',
  'TC-P1-024',
]

/** Ba action đổi tồn phải khai đủ hai header bắt buộc trong contract. */
const STOCK_CHANGING_OPERATIONS = [
  ['post', '/api/v1/inbound-documents/{id}/post-receipt'],
  ['post', '/api/v1/outbound-documents/{id}/post-issue'],
  ['post', '/api/v1/reversal-documents/{id}/post'],
  ['post', '/api/v1/component-issue-documents/{id}/post'],
]

const errors = []
const notes = []

const fail = (message) => errors.push(message)

function readJson(path, label) {
  if (!existsSync(path)) {
    fail(`${label}: thiếu file ${path.replace(ROOT, '.')}`)
    return null
  }
  try {
    return JSON.parse(readFileSync(path, 'utf8'))
  } catch (error) {
    fail(`${label}: không parse được JSON — ${error.message}`)
    return null
  }
}

function assertFilled(value, label) {
  if (typeof value !== 'string' || value.trim() === '') {
    fail(`${label}: trống`)
    return false
  }
  if (FILLER.some((pattern) => pattern.test(value.trim()))) {
    fail(`${label}: là chuỗi độn ("${value.trim()}"), không phải nội dung thật`)
    return false
  }
  if (value.trim().length < 20) {
    fail(`${label}: quá ngắn (${value.trim().length} ký tự) để là mô tả kiểm chứng được`)
    return false
  }
  return true
}

// ── 1. Defect register ────────────────────────────────────────────────────
const register = readJson(REGISTER_PATH, 'DEFECT_REGISTER')

if (register) {
  if (register.task !== 'WWHN-266') fail(`DEFECT_REGISTER: task phải là WWHN-266, đang là "${register.task}"`)
  if (register.parent !== 'WWHN-70') fail(`DEFECT_REGISTER: parent phải là WWHN-70, đang là "${register.parent}"`)
  if (!Array.isArray(register.defects) || register.defects.length === 0) {
    fail('DEFECT_REGISTER: không có defect nào — đúng lời review "Không có defect input"')
  }

  const seen = new Set()
  for (const defect of register.defects ?? []) {
    const id = defect.id ?? '(thiếu id)'
    if (seen.has(id)) fail(`DEFECT_REGISTER: trùng id ${id}`)
    seen.add(id)

    for (const field of ['severity', 'owner', 'confirmer', 'registerItem', 'module', 'route', 'method', 'environment']) {
      if (typeof defect[field] !== 'string' || defect[field].trim() === '') {
        fail(`${id}: thiếu trường bắt buộc "${field}"`)
      }
    }
    for (const field of ['expected', 'actual', 'rootCause', 'contractImpact']) {
      assertFilled(defect[field], `${id}.${field}`)
    }

    if (!defect.fix || typeof defect.fix !== 'object') {
      fail(`${id}: thiếu khối "fix"`)
    } else {
      assertFilled(defect.fix.summary, `${id}.fix.summary`)
      if (!Array.isArray(defect.fix.files)) {
        fail(`${id}.fix.files: phải là mảng`)
      } else {
        for (const file of defect.fix.files) {
          if (!existsSync(join(ROOT, file))) fail(`${id}.fix.files: "${file}" không tồn tại trong repo`)
        }
      }
    }

    if (!defect.beforeAfter || typeof defect.beforeAfter !== 'object') {
      fail(`${id}: thiếu khối "beforeAfter"`)
    } else {
      assertFilled(defect.beforeAfter.before, `${id}.beforeAfter.before`)
      assertFilled(defect.beforeAfter.after, `${id}.beforeAfter.after`)
      assertFilled(defect.beforeAfter.measuredBy, `${id}.beforeAfter.measuredBy`)
    }

    const status = defect.status
    const retestStatus = defect.retest?.status
    if (!['FIXED', 'OPEN_NEEDS_DISPOSITION', 'DEFERRED'].includes(status)) {
      fail(`${id}.status: giá trị lạ "${status}"`)
    }
    if (status === 'FIXED') {
      if (retestStatus !== 'PASS') {
        fail(`${id}: khai FIXED nhưng retest.status = "${retestStatus}" (phải là PASS)`)
      }
      if (!Array.isArray(defect.fix?.files) || defect.fix.files.length === 0) {
        fail(`${id}: khai FIXED nhưng fix.files rỗng — không có mã nào để review`)
      }
    }
    if (status === 'OPEN_NEEDS_DISPOSITION' && defect.owner === 'BE1') {
      fail(`${id}: OPEN_NEEDS_DISPOSITION nhưng owner vẫn là BE1 — phải chỉ đích danh vai trò quyết định`)
    }
  }

  if (!Array.isArray(register.externalItems) || register.externalItems.length === 0) {
    fail('DEFECT_REGISTER: thiếu externalItems — mục thuộc QA1/DevOps/PM phải được ghi rõ là việc của vai trò khác')
  }
}

// ── 2. Traceability TC-P1-017..024 ────────────────────────────────────────
const traceability = readJson(TRACEABILITY_PATH, 'TC_TRACEABILITY')

if (traceability) {
  const covered = new Map((traceability.coverage ?? []).map((row) => [row.tc, row]))
  for (const tc of REQUIRED_DIMENSIONS) {
    const row = covered.get(tc)
    if (!row) {
      fail(`TC_TRACEABILITY: thiếu ${tc} — ma trận §13.3 đòi đủ 8 chiều cho action đổi tồn`)
      continue
    }
    if (row.status !== 'COVERED') fail(`TC_TRACEABILITY: ${tc} có status "${row.status}", phải là COVERED`)
    assertFilled(row.measured, `TC_TRACEABILITY.${tc}.measured`)
    if (typeof row.test !== 'string' || !row.test.includes('::')) {
      fail(`TC_TRACEABILITY: ${tc} không trỏ tới một test method cụ thể`)
    }
  }

  const proof = traceability.ledgerBalanceProof?.measured
  if (!proof) {
    fail('TC_TRACEABILITY: thiếu ledgerBalanceProof.measured (DEV266-P1-04)')
  } else {
    if (proof.variance !== 0) fail(`TC_TRACEABILITY: variance phải = 0, đang là ${proof.variance}`)
    if (proof.reconcile_mismatch_count !== 0) {
      fail(`TC_TRACEABILITY: mismatch_count phải = 0, đang là ${proof.reconcile_mismatch_count}`)
    }
    if (proof.ledger_sum_qty_delta !== proof.balance_on_hand) {
      fail(`TC_TRACEABILITY: ledger (${proof.ledger_sum_qty_delta}) khác balance (${proof.balance_on_hand})`)
    }
  }
}

// ── 3. Số liệu thô do test sinh, không soạn tay ───────────────────────────
const raw = readJson(RAW_EVIDENCE_PATH, 'RAW_EVIDENCE')

if (raw) {
  if (raw.ticket !== 'WWHN-266') fail(`RAW_EVIDENCE: ticket phải là WWHN-266, đang là "${raw.ticket}"`)
  for (const tc of REQUIRED_DIMENSIONS) {
    if (!raw.dimensions?.[tc]) fail(`RAW_EVIDENCE: thiếu số đo cho ${tc} — test chưa chạy đủ 8 chiều`)
  }
  const reconcile = raw.dimensions?.LEDGER_BALANCE_RECONCILE
  if (!reconcile) {
    fail('RAW_EVIDENCE: thiếu khối LEDGER_BALANCE_RECONCILE')
  } else if (reconcile.reconcile_mismatch_count !== 0 || reconcile.variance !== 0) {
    fail(`RAW_EVIDENCE: đối soát chưa sạch (mismatch=${reconcile.reconcile_mismatch_count}, variance=${reconcile.variance})`)
  }

  // Số trong hồ sơ phải khớp số do test ghi ra — chặn việc sửa tay tài liệu.
  const rawPositive = raw.dimensions?.['TC-P1-017']
  if (rawPositive && traceability) {
    const documented = traceability.coverage?.find((row) => row.tc === 'TC-P1-017')?.measured ?? ''
    if (rawPositive.balance_before !== undefined && !documented.includes(String(rawPositive.balance_before))) {
      fail('TC_TRACEABILITY: mô tả TC-P1-017 không chứa con số balance_before mà test thực sự ghi ra')
    }
  }

  if (raw.driver && raw.driver !== 'pgsql') {
    notes.push(`RAW_EVIDENCE sinh trên driver "${raw.driver}" — bằng chứng trích ra ngoài phải lấy từ lượt CI trên PostgreSQL tại exact SHA.`)
  }
}

// ── 4. Contract: header bắt buộc phải được công bố đúng ───────────────────
const openapi = readJson(OPENAPI_PATH, 'OPENAPI')

if (openapi) {
  for (const [method, path] of STOCK_CHANGING_OPERATIONS) {
    const operation = openapi.paths?.[path]?.[method]
    if (!operation) {
      fail(`OPENAPI: thiếu ${method.toUpperCase()} ${path}`)
      continue
    }
    const headers = Object.fromEntries(
      (operation.parameters ?? []).filter((p) => p?.in === 'header').map((p) => [p.name, p.required === true])
    )
    if (headers['If-Match'] !== true) {
      fail(`OPENAPI: ${method.toUpperCase()} ${path} bắt buộc If-Match lúc chạy nhưng contract khai required=${headers['If-Match']}`)
    }
    if (headers['Idempotency-Key'] !== true) {
      fail(`OPENAPI: ${method.toUpperCase()} ${path} phải khai Idempotency-Key bắt buộc`)
    }
  }
}

// ── 5. Pin candidate: chống pin rot ───────────────────────────────────────
const pin = readJson(PIN_PATH, 'CANDIDATE_PIN')

if (pin) {
  const sha = pin.codeCandidateSha
  if (typeof sha !== 'string' || !/^[0-9a-f]{40}$/.test(sha)) {
    fail(`CANDIDATE_PIN: codeCandidateSha phải là SHA 40 ký tự, đang là "${sha}"`)
  } else {
    const git = (args) => execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' }).trim()
    let pinnedExists = true
    try {
      git(['cat-file', '-e', `${sha}^{commit}`])
    } catch {
      pinnedExists = false
      // Chỉ shallow clone mới được miễn. Một SHA 40 ký tự hợp lệ mà không phải
      // commit có thật trong repo này thì chính là pin mục — hạ xuống NOTE ở đây
      // là fail-open, đúng thứ mà cổng phải chặn.
      let shallow = false
      try {
        shallow = git(['rev-parse', '--is-shallow-repository']) === 'true'
      } catch {
        shallow = false
      }
      if (shallow) {
        notes.push(`CANDIDATE_PIN: commit ${sha.slice(0, 7)} không nằm trong shallow clone — bỏ qua kiểm range invariant (checkout với fetch-depth: 0 để kiểm đầy đủ).`)
      } else {
        fail(`CANDIDATE_PIN: ${sha.slice(0, 7)} không phải commit có thật trong repo — pin đã mục hoặc gõ sai.`)
      }
    }

    if (pinnedExists) {
      // Pin phải là ancestor của nhánh, nếu không thì nó trỏ vào một commit đã bị
      // rebase/amend mất — đúng lớp lỗi "pin rot" của WWHN-191/WWHN-290.
      try {
        git(['merge-base', '--is-ancestor', sha, 'HEAD'])
      } catch {
        fail(`CANDIDATE_PIN: ${sha.slice(0, 7)} không phải ancestor của HEAD — pin đã mục.`)
      }

      // Không commit nào SAU pin được chạm artifact được chứng thực mà pin vẫn
      // đứng yên. Tập này CỐ Ý không gồm chính hồ sơ chứng thực — xem
      // rangeInvariantScopeNote trong CANDIDATE_PIN.json.
      const files = Array.isArray(pin.rangeInvariantPaths) ? pin.rangeInvariantPaths : []
      if (files.length === 0) {
        fail('CANDIDATE_PIN: rangeInvariantPaths rỗng — không có gì để kiểm range invariant.')
      } else {
        let touchedAfter = ''
        try {
          touchedAfter = git(['log', '--oneline', `${sha}..HEAD`, '--', ...files])
        } catch {
          touchedAfter = ''
        }
        if (touchedAfter !== '') {
          fail(
            `CANDIDATE_PIN: có commit sau ${sha.slice(0, 7)} chạm artifact WWHN-266 nhưng pin chưa cập nhật:\n      ${touchedAfter.split('\n').join('\n      ')}`
          )
        }
      }
    }
  }

  if (pin.ciGate?.status && !String(pin.ciGate.status).startsWith('CHƯA CHẠY') && !pin.ciGate.runUrl) {
    fail('CANDIDATE_PIN: ciGate khai đã chạy nhưng thiếu runUrl — không dẫn chứng được.')
  }
}

// ── Kết luận ──────────────────────────────────────────────────────────────
for (const note of notes) console.log(`[wwhn-266] NOTE: ${note}`)

if (errors.length > 0) {
  console.error(`\n[wwhn-266] FAIL — ${errors.length} vấn đề:`)
  for (const error of errors) console.error(`  - ${error}`)
  console.error('\n[wwhn-266] Hồ sơ đóng WWHN-266 CHƯA đủ điều kiện ký.')
  process.exit(1)
}

console.log('\n[wwhn-266] PASS — register đầy đủ, 8/8 chiều CORE_TRANSACTION có số đo thật, đối soát ledger/balance delta = 0, contract công bố đúng header bắt buộc.')
console.log('[wwhn-266] Vẫn còn phụ thuộc vai trò khác: QA1 retest (WWHN-264), DevOps pin CI tại exact SHA, BA disposition BE266-D03/BE266-D04.')
