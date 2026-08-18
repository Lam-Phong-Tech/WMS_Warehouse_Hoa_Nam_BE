#!/usr/bin/env node
/**
 * WWHN-201 — verifier hợp đồng Outbound (DEV201-P1-04R + DEV201-P1-05).
 *
 * Chạy được ở bất kỳ SHA nào, không cần DB, không cần server. Trả exit code 1 kèm
 * danh sách lỗi cụ thể. Đây là thứ QA/BA/FE chạy để XÁC NHẬN, thay cho việc đọc tay
 * ba file rồi tự suy diễn.
 *
 * Kiểm 8 điều:
 *   1. Lock file tồn tại và khai đúng version/decision.
 *   2. sha256 của OpenAPI JSON/YAML + Postman khớp lock (chưa ai sửa tay sau khi khoá).
 *   3. JSON và YAML mô tả CÙNG một cây dữ liệu.
 *   4. Tập operation trong OpenAPI == tập route outbound-document thật của Laravel.
 *   5. Postman phủ đúng 100% operation của OpenAPI, không thừa không thiếu.
 *   6. Mọi request đổi trạng thái trong Postman mang đủ header §8.2 mà OpenAPI đòi.
 *   7. DEC-OUT-STOCK-HTTP-001: INSUFFICIENT_STOCK/INSUFFICIENT_AVAILABLE_STOCK chỉ
 *      xuất hiện ở 422, và không HTTP status nào mang mã của nhóm khác.
 *   8. Error catalog HN-BE-API-002 map hai mã thiếu tồn về đúng 422.
 *
 * Chạy: `node scripts/verify-wwhn-201-contract.mjs`
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import yaml from 'js-yaml'
import {
  CONTRACT_VERSION,
  DECISION_ID,
  ERROR_CONTRACT,
  OUTBOUND_PATH_PREFIX,
  digest,
  realOutboundRoutes,
} from './wwhn-201-outbound-contract-lock.mjs'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const rel = (p) => path.join(ROOT, p)
const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete']

const errors = []
const check = (ok, message) => {
  if (!ok) errors.push(message)
}

// ---- 1. Lock ---------------------------------------------------------------
const LOCK_FILE = 'docs/api-contract/WWHN-201-outbound-contract-lock.json'
if (!fs.existsSync(rel(LOCK_FILE))) {
  console.error(`[WWHN-201] Thiếu ${LOCK_FILE}. Chạy: node scripts/wwhn-201-outbound-contract-lock.mjs`)
  process.exit(1)
}
const lock = JSON.parse(fs.readFileSync(rel(LOCK_FILE), 'utf8'))
check(lock.contract_version === CONTRACT_VERSION, `lock.contract_version=${lock.contract_version} ≠ ${CONTRACT_VERSION}`)
check(lock.decision === DECISION_ID, `lock.decision=${lock.decision} ≠ ${DECISION_ID}`)

// ---- 2. Digest -------------------------------------------------------------
for (const [file, expected] of Object.entries(lock.artifacts)) {
  if (!fs.existsSync(rel(file))) {
    errors.push(`Artifact đã khoá nhưng không tồn tại: ${file}`)
    continue
  }
  const actual = digest(fs.readFileSync(rel(file), 'utf8'))
  check(
    actual === expected,
    `${file} đã bị sửa sau khi khoá (sha256 ${actual.slice(0, 12)}… ≠ ${expected.slice(0, 12)}…). Chạy lại scripts/wwhn-201-outbound-contract-lock.mjs rồi cập nhật comment sign-off.`,
  )
}

const spec = JSON.parse(fs.readFileSync(rel('docs/api-contract/WWHN-195-outbound-openapi.json'), 'utf8'))
const specYaml = yaml.load(fs.readFileSync(rel('docs/api-contract/WWHN-195-outbound-openapi.yaml'), 'utf8'))
const postman = JSON.parse(fs.readFileSync(rel('docs/postman/WWHN-195-outbound.postman_collection.json'), 'utf8'))

// ---- 3. JSON == YAML -------------------------------------------------------
check(
  JSON.stringify(spec) === JSON.stringify(specYaml),
  'WWHN-195-outbound-openapi.json và .yaml không còn cùng nội dung — FE đọc bản này, QA đọc bản kia là nguồn xung đột kinh điển.',
)
check(spec.info.version === CONTRACT_VERSION, `OpenAPI info.version=${spec.info.version} ≠ ${CONTRACT_VERSION}`)
check(spec['x-decision'] === DECISION_ID, 'OpenAPI thiếu x-decision neo về DEC-OUT-STOCK-HTTP-001.')

// ---- 4. OpenAPI == routes thật --------------------------------------------
const specOps = new Set()
for (const [p, ops] of Object.entries(spec.paths)) {
  for (const m of Object.keys(ops)) {
    if (HTTP_METHODS.includes(m)) specOps.add(`${m.toUpperCase()} ${p}`)
  }
}
const routeOps = new Set()
for (const [uri, methods] of realOutboundRoutes()) {
  for (const m of methods) routeOps.add(`${m.toUpperCase()} ${uri}`)
}
// Chỉ so trong phạm vi hợp đồng WWHN-195: các route outbound-document phụ trợ
// (packing-labels, rfid, scans, reversals) thuộc hợp đồng khác nên loại khỏi phép so.
const inScope = (op) => {
  const uri = op.split(' ')[1]
  if (!uri.startsWith(OUTBOUND_PATH_PREFIX)) return false
  const tail = uri.slice(OUTBOUND_PATH_PREFIX.length)
  return ['', '/{id}', '/{id}/cancel', '/{id}/scan', '/{id}/unmatch', '/{id}/post-issue', '/{id}/reverse'].includes(tail)
}
for (const op of specOps) {
  check(routeOps.has(op), `OpenAPI mô tả endpoint KHÔNG tồn tại trong routes: ${op}`)
}
for (const op of [...routeOps].filter(inScope)) {
  check(specOps.has(op), `Route thật chưa được mô tả trong OpenAPI WWHN-195: ${op}`)
}

// ---- 5 & 6. Postman phủ đúng + header §8.2 --------------------------------
const postmanOps = new Map(postman.item.map((item) => [item.name, item]))
check(
  postman['x-source-contract-version'] === CONTRACT_VERSION,
  `Postman bind sai revision (${postman['x-source-contract-version']} ≠ ${CONTRACT_VERSION}).`,
)
for (const op of specOps) {
  const item = postmanOps.get(op)
  if (item === undefined) {
    errors.push(`Postman thiếu request cho ${op} — FE/QA không có gì để chạy thử.`)
    continue
  }
  const [method, p] = op.split(' ')
  if (method === 'GET') continue
  const node = spec.paths[p]
  const params = [...(node.parameters ?? []), ...(node[method.toLowerCase()].parameters ?? [])].map(
    (x) => String(x.$ref?.split('/').pop() ?? x.name).replace(/-/g, '').toLowerCase(),
  )
  const headers = new Set(item.request.header.map((h) => h.key))
  if (params.some((x) => x.startsWith('ifmatch'))) {
    check(headers.has('If-Match'), `Postman ${op} thiếu header If-Match dù OpenAPI khai bắt buộc (§8.2 → 428).`)
  }
  if (params.some((x) => x.startsWith('idempotencykey'))) {
    check(headers.has('Idempotency-Key'), `Postman ${op} thiếu header Idempotency-Key dù OpenAPI khai bắt buộc.`)
  }
}
for (const name of postmanOps.keys()) {
  check(specOps.has(name), `Postman có request thừa ngoài hợp đồng: ${name}`)
}

// ---- 7. DEC-OUT-STOCK-HTTP-001 --------------------------------------------
const CODE_TO_STATUS = new Map()
for (const [status, codes] of Object.entries(ERROR_CONTRACT)) {
  for (const code of codes) CODE_TO_STATUS.set(code, status)
}
for (const [p, ops] of Object.entries(spec.paths)) {
  for (const [method, op] of Object.entries(ops)) {
    if (!HTTP_METHODS.includes(method)) continue
    for (const [status, response] of Object.entries(op.responses ?? {})) {
      const text = String(response.description ?? '')
      for (const [code, expected] of CODE_TO_STATUS) {
        // Bắt token nguyên vẹn: INSUFFICIENT_STOCK không được khớp nhầm vào
        // INSUFFICIENT_AVAILABLE_STOCK và ngược lại.
        if (!new RegExp(`(^|[^A-Z_])${code}([^A-Z_]|$)`).test(text)) continue
        check(
          status === expected,
          `${method.toUpperCase()} ${p}: mã ${code} được khai ở HTTP ${status} nhưng ${DECISION_ID}/§8.2 quy định ${expected}.`,
        )
      }
    }
  }
}

// ---- 8. Error catalog ------------------------------------------------------
const catalog = JSON.parse(fs.readFileSync(rel('docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.json'), 'utf8'))
const entries = []
;(function walk(node) {
  if (Array.isArray(node)) return node.forEach(walk)
  if (node !== null && typeof node === 'object') {
    if (typeof node.code === 'string' && Array.isArray(node.http_status)) entries.push(node)
    Object.values(node).forEach(walk)
  }
})(catalog)
for (const code of ERROR_CONTRACT[422]) {
  const entry = entries.find((e) => e.code === code)
  if (entry === undefined) {
    errors.push(`Error catalog HN-BE-API-002 không có mã ${code}.`)
    continue
  }
  check(
    entry.http_status.length === 1 && entry.http_status[0] === 422,
    `Error catalog map ${code} → ${JSON.stringify(entry.http_status)}, phải là [422] theo ${DECISION_ID}.`,
  )
}

// ---- Kết luận --------------------------------------------------------------
if (errors.length > 0) {
  console.error('[WWHN-201] HỢP ĐỒNG OUTBOUND KHÔNG ĐẠT:')
  for (const e of errors) console.error('  - ' + e)
  process.exit(1)
}
console.log(
  JSON.stringify(
    {
      ticket: 'WWHN-201',
      findings_covered: ['DEV201-P1-04R', 'DEV201-P1-05'],
      status: 'PASS',
      contract_version: CONTRACT_VERSION,
      decision: DECISION_ID,
      operations: [...specOps].sort(),
      artifacts: lock.artifacts,
    },
    null,
    2,
  ),
)
