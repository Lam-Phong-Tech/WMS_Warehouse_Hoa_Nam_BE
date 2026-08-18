#!/usr/bin/env node
/**
 * WWHN-201 / DEV201-P1-04R — khoá hợp đồng Outbound và bind Postman cùng revision.
 *
 * Finding gốc: OpenAPI WWHN-195 còn ở `PROPOSED / PENDING_BA_SIGN_OFF`, và bộ
 * Postman không có một request outbound-document nào, nên FE/QA không có "cùng một
 * revision" để xác nhận. Ngoài ra bản v1.0.0 còn mô tả hai endpoint KHÔNG tồn tại
 * trong routes (`POST /outbound-documents`, `PATCH /outbound-documents/{id}`) — đã
 * bị gỡ theo §8.1 (phiếu xuất chỉ sinh từ Confirm toa). Không thể khoá một hợp đồng
 * mô tả endpoint ma.
 *
 * Script này (idempotent) làm ba việc:
 *   1. Cắt các path không có route thật, bump `info.version` và điền x-metadata
 *      (trạng thái duyệt, decision neo, ngày).
 *   2. Ghi lại JSON + YAML từ CÙNG một cây dữ liệu, nên hai file không thể lệch nhau.
 *   3. Sinh `docs/postman/WWHN-195-outbound.postman_collection.json` từ chính
 *      OpenAPI đó và ghi `docs/api-contract/WWHN-201-outbound-contract-lock.json`
 *      chứa sha256 của cả ba artifact.
 *
 * Chạy: `node scripts/wwhn-201-outbound-contract-lock.mjs`
 * Kiểm tra: `node scripts/verify-wwhn-201-contract.mjs`
 */
import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import yaml from 'js-yaml'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const JSON_PATH = path.join(ROOT, 'docs/api-contract/WWHN-195-outbound-openapi.json')
const YAML_PATH = path.join(ROOT, 'docs/api-contract/WWHN-195-outbound-openapi.yaml')
const POSTMAN_PATH = path.join(ROOT, 'docs/postman/WWHN-195-outbound.postman_collection.json')
const LOCK_PATH = path.join(ROOT, 'docs/api-contract/WWHN-201-outbound-contract-lock.json')

export const CONTRACT_VERSION = '1.2.0'
export const DECISION_ID = 'DEC-OUT-STOCK-HTTP-001'

/** Prefix của các path thuộc phạm vi hợp đồng Outbound Document (WWHN-195). */
export const OUTBOUND_PATH_PREFIX = '/api/v1/outbound-documents'

/**
 * Bảng HTTP ↔ business code do DEC-OUT-STOCK-HTTP-001 chốt. Verifier dùng đúng bảng
 * này nên OpenAPI, error catalog và test không thể trôi khỏi nhau.
 */
export const ERROR_CONTRACT = {
  422: ['INSUFFICIENT_STOCK', 'INSUFFICIENT_AVAILABLE_STOCK'],
  409: ['STATE_CONFLICT', 'IDEMPOTENCY_CONFLICT', 'CONCURRENCY_CONFLICT', 'DOWNSTREAM_DEPENDENCY_EXISTS'],
  412: ['STALE_VERSION'],
  428: ['PRECONDITION_REQUIRED'],
}

/** sha256 của nội dung file, chuẩn hoá CRLF -> LF để hash ổn định trên Windows/CI. */
export function digest(content) {
  return createHash('sha256').update(String(content).replace(/\r\n/g, '\n'), 'utf8').digest('hex')
}

/** Các route outbound-document THẬT, đọc từ Laravel chứ không đoán từ file routes. */
export function realOutboundRoutes() {
  const raw = execFileSync('php', ['artisan', 'route:list', '--json'], { cwd: ROOT, maxBuffer: 64 * 1024 * 1024 })
  const routes = JSON.parse(raw.toString())
  const found = new Map()
  for (const route of routes) {
    const uri = '/' + String(route.uri).replace(/^\//, '')
    if (!uri.startsWith(OUTBOUND_PATH_PREFIX)) continue
    // Laravel dùng {id}; OpenAPI cũng vậy nên so khớp trực tiếp được.
    for (const method of String(route.method).split('|')) {
      if (method === 'HEAD') continue
      if (!found.has(uri)) found.set(uri, new Set())
      found.get(uri).add(method.toLowerCase())
    }
  }
  return found
}

function canonicalJson(value) {
  return JSON.stringify(value, null, 2) + '\n'
}

function main() {
  const spec = JSON.parse(fs.readFileSync(JSON_PATH, 'utf8'))
  const real = realOutboundRoutes()

  // ---- 1. Cắt endpoint ma -------------------------------------------------
  const removed = []
  for (const [p, ops] of Object.entries(spec.paths)) {
    // Chỉ xét path thuộc phạm vi Outbound Document; các path khác (nếu có) giữ nguyên.
    if (!p.startsWith(OUTBOUND_PATH_PREFIX)) continue
    const methods = real.get(p)
    for (const method of Object.keys(ops)) {
      if (!['get', 'post', 'put', 'patch', 'delete'].includes(method)) continue
      if (methods === undefined || !methods.has(method)) {
        removed.push(`${method.toUpperCase()} ${p}`)
        delete ops[method]
      }
    }
    if (Object.keys(ops).length === 0) delete spec.paths[p]
  }

  // ---- 2. Metadata khoá ---------------------------------------------------
  spec.info.version = CONTRACT_VERSION
  spec['x-document-version'] = CONTRACT_VERSION
  spec['x-contract-status'] = 'LOCKED_CANDIDATE'
  spec['x-approval-status'] = 'PENDING_BA_SIGN_OFF'
  spec['x-ticket'] = 'WWHN-201'
  // WWHN-198/DEV198-P1-06 sửa phần mô tả Packing Label trong cùng hợp đồng này,
  // nên revision 1.2.0 có hai ticket nguồn chứ không phải một.
  spec['x-tickets'] = ['WWHN-201', 'WWHN-198']
  spec['x-decision'] = DECISION_ID
  spec['x-error-contract'] = ERROR_CONTRACT
  spec['x-note'] =
    'v1.1.0 (WWHN-201/DEV201-P1-04R): gỡ POST /outbound-documents và PATCH /outbound-documents/{id} vì §8.1 không cho tạo/sửa phiếu xuất trực tiếp (phiếu chỉ sinh từ Confirm toa) và routes thật không khai hai endpoint đó. Bổ sung x-error-contract theo DEC-OUT-STOCK-HTTP-001: không đủ tồn = 422 INSUFFICIENT_STOCK, không phải 409. Tập path/method trong file này được sinh và kiểm tra lại từ `php artisan route:list` nên không thể mô tả endpoint không tồn tại. Postman WWHN-195-outbound.postman_collection.json bind cùng revision; hash cả ba artifact nằm trong WWHN-201-outbound-contract-lock.json. v1.2.0 (WWHN-198/DEV198-P1-06): đồng bộ MỘT controlling behavior về Packing Label theo DR-HN-20260817 + SRS §5.2/§6/§15 — nhãn cấp lô (01/outbound_document), chỉ sinh khi full match và sinh ở DRAFT, KHÔNG auto-dispatch in, lifecycle DRAFT/PREVIEWED/READY_TO_PRINT/PRINTED/PRINT_FAILED, đóng băng từ READY_TO_PRINT; bản 1.1.0 còn mô tả hành vi cũ (nhãn mỗi item + tự in, PENDING_PRINT) của controller đã bị Flow06AlignmentServiceProvider override. Chi tiết máy đọc được nằm ở x-packing-label-contract; sinh lại bằng scripts/wwhn198-packing-label-contract-sync.mjs.'
  if (removed.length > 0) spec['x-removed-in-1.1.0'] = removed

  const specJson = canonicalJson(spec)
  fs.writeFileSync(JSON_PATH, specJson)
  const specYaml = yaml.dump(spec, { lineWidth: 100, noRefs: true, sortKeys: false })
  fs.writeFileSync(YAML_PATH, specYaml)

  // ---- 3. Postman bind cùng revision -------------------------------------
  const postman = buildPostman(spec)
  const postmanJson = canonicalJson(postman)
  fs.writeFileSync(POSTMAN_PATH, postmanJson)

  const lock = {
    ticket: 'WWHN-201',
    finding: 'DEV201-P1-04R',
    decision: DECISION_ID,
    contract_version: CONTRACT_VERSION,
    contract_status: spec['x-contract-status'],
    approval_status: spec['x-approval-status'],
    error_contract: ERROR_CONTRACT,
    operations: Object.entries(spec.paths).flatMap(([p, ops]) =>
      Object.keys(ops)
        .filter((m) => ['get', 'post', 'put', 'patch', 'delete'].includes(m))
        .map((m) => `${m.toUpperCase()} ${p}`),
    ).sort(),
    removed_from_previous_version: removed,
    artifacts: {
      'docs/api-contract/WWHN-195-outbound-openapi.json': digest(specJson),
      'docs/api-contract/WWHN-195-outbound-openapi.yaml': digest(specYaml),
      'docs/postman/WWHN-195-outbound.postman_collection.json': digest(postmanJson),
    },
  }
  fs.writeFileSync(LOCK_PATH, canonicalJson(lock))

  console.log(JSON.stringify({ removed, operations: lock.operations, artifacts: lock.artifacts }, null, 2))
}

/**
 * Sinh Postman v2.1 từ OpenAPI. Mỗi request mang sẵn header bắt buộc theo §8.2
 * (If-Match / Idempotency-Key cho mọi lệnh đổi trạng thái) và một test script chốt
 * đúng bảng lỗi của DEC-OUT-STOCK-HTTP-001, để QA chạy là ra kết luận chứ không
 * phải tự đọc và suy diễn.
 */
export function buildPostman(spec) {
  const items = []
  for (const [p, ops] of Object.entries(spec.paths)) {
    for (const [method, op] of Object.entries(ops)) {
      if (!['get', 'post', 'put', 'patch', 'delete'].includes(method)) continue
      const mutating = method !== 'get'
      const header = [{ key: 'Accept', value: 'application/json' }]
      if (mutating) {
        header.push({ key: 'Content-Type', value: 'application/json' })
        const params = [...(ops.parameters ?? []), ...(op.parameters ?? [])].map(
          (x) => x.$ref?.split('/').pop() ?? x.name,
        )
        // Tên component parameter có biến thể (IdempotencyKeyRequired…) nên khớp theo
        // tiền tố, không khớp tuyệt đối — nếu không header bắt buộc sẽ âm thầm thiếu.
        const has = (needle) => params.some((x) => String(x).replace(/-/g, '').toLowerCase().startsWith(needle))
        if (has('ifmatch')) {
          header.push({ key: 'If-Match', value: '{{document_version}}' })
        }
        if (has('idempotencykey')) {
          header.push({ key: 'Idempotency-Key', value: '{{idempotency_key}}' })
        }
      }
      items.push({
        name: `${method.toUpperCase()} ${p}`,
        request: {
          method: method.toUpperCase(),
          header,
          url: {
            raw: '{{base_url}}' + p.replace('{id}', '{{document_id}}'),
            host: ['{{base_url}}'],
            path: p.replace(/^\//, '').replace('{id}', '{{document_id}}').split('/'),
          },
          description: `${op.summary ?? ''} — sinh từ WWHN-195 v${spec.info.version} (${DECISION_ID}). Response hợp lệ: ${Object.keys(op.responses ?? {}).join(', ')}.`,
        },
        event: [
          {
            listen: 'test',
            script: {
              type: 'text/javascript',
              exec: postmanTestScript(op),
            },
          },
        ],
      })
    }
  }

  return {
    info: {
      name: `WWHN-195 Outbound Document API Contract v${spec.info.version}`,
      description: `Sinh tự động từ docs/api-contract/WWHN-195-outbound-openapi.json v${spec.info.version} bởi scripts/wwhn-201-outbound-contract-lock.mjs. KHÔNG sửa tay: chạy lại script sau mỗi lần đổi OpenAPI, nếu không verify-wwhn-201-contract.mjs sẽ đỏ. Neo quyết định: ${DECISION_ID} (không đủ tồn = 422 INSUFFICIENT_STOCK).`,
      schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    },
    auth: { type: 'bearer', bearer: [{ key: 'token', value: '{{access_token}}', type: 'string' }] },
    variable: [
      { key: 'base_url', value: 'http://127.0.0.1:8000' },
      { key: 'access_token', value: '' },
      { key: 'document_id', value: '' },
      { key: 'document_version', value: '1' },
      { key: 'idempotency_key', value: '' },
    ],
    item: items,
    'x-source-contract-version': spec.info.version,
    'x-decision': DECISION_ID,
  }
}

function postmanTestScript(op) {
  const allowed = Object.keys(op.responses ?? {}).filter((c) => /^\d+$/.test(c))
  return [
    `const allowed = ${JSON.stringify(allowed)}.map(Number);`,
    'pm.test("HTTP status nằm trong hợp đồng WWHN-195", function () {',
    '  pm.expect(allowed).to.include(pm.response.code);',
    '});',
    '',
    '// DEC-OUT-STOCK-HTTP-001: mỗi HTTP status chỉ được mang đúng nhóm business code.',
    `const contract = ${JSON.stringify(ERROR_CONTRACT)};`,
    'if (pm.response.code >= 400) {',
    '  const body = pm.response.json();',
    '  const code = body.error_code || (body.error && body.error.code);',
    '  const expected = contract[String(pm.response.code)];',
    '  if (expected && code) {',
    '    pm.test("business code khớp HTTP status theo §8.2", function () {',
    '      pm.expect(expected).to.include(code);',
    '    });',
    '  }',
    '  pm.test("lỗi luôn có request_id để truy vết", function () {',
    '    pm.expect(body.error && body.error.request_id ? body.error.request_id : body.request_id).to.be.ok;',
    '  });',
    '}',
  ]
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main()
}
