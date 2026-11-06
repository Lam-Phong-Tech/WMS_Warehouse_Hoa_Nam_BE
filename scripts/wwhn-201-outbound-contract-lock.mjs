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

/**
 * Phiên bản TỐI THIỂU của hợp đồng Outbound sau WWHN-201. Cố ý KHÔNG phải một
 * version cố định: nhánh m1 có nhiều task cùng sửa file này (WWHN-198 nâng lên
 * 2.0.0-R2 cho Packing Label), nên ghi đè bằng một hằng số sẽ hạ cấp ngược công
 * việc của task khác. Quy tắc: giữ nguyên version mà spec đang khai, chỉ nâng khi
 * nó còn thấp hơn mức tối thiểu này.
 */
export const MIN_CONTRACT_VERSION = '1.1.0'

/** Version thực tế của hợp đồng = thứ spec đang khai (sau khi bảo đảm >= tối thiểu). */
export function contractVersionOf(spec) {
  const declared = String(spec?.info?.version ?? '')
  return compareVersion(declared, MIN_CONTRACT_VERSION) < 0 ? MIN_CONTRACT_VERSION : declared
}

/** So sánh version theo từng số; hậu tố (-R2, -rc1) chỉ dùng để phá hoà. */
export function compareVersion(a, b) {
  const parse = (v) => String(v).split('-')[0].split('.').map((x) => Number.parseInt(x, 10) || 0)
  const [pa, pb] = [parse(a), parse(b)]
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    if ((pa[i] ?? 0) !== (pb[i] ?? 0)) return (pa[i] ?? 0) < (pb[i] ?? 0) ? -1 : 1
  }
  return 0
}
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
  const contractVersion = contractVersionOf(spec)
  spec.info.version = contractVersion
  spec['x-document-version'] = contractVersion
  spec['x-contract-status'] = 'LOCKED_CANDIDATE'
  spec['x-approval-status'] = 'PENDING_BA_SIGN_OFF'
  // Không ghi đè x-ticket của task khác: gộp vào danh sách.
  spec['x-tickets'] = [...new Set([...(spec['x-tickets'] ?? []), spec['x-ticket'], 'WWHN-201'].filter(Boolean))]
  spec['x-ticket'] = spec['x-ticket'] ?? 'WWHN-201'
  spec['x-decision'] = DECISION_ID
  spec['x-error-contract'] = ERROR_CONTRACT
  const WWHN201_NOTE =
    'v1.1.0 (WWHN-201/DEV201-P1-04R): gỡ POST /outbound-documents và PATCH /outbound-documents/{id} vì §8.1 không cho tạo/sửa phiếu xuất trực tiếp (phiếu chỉ sinh từ Confirm toa) và routes thật không khai hai endpoint đó. Bổ sung x-error-contract theo DEC-OUT-STOCK-HTTP-001: không đủ tồn = 422 INSUFFICIENT_STOCK, không phải 409. Tập path/method trong file này được sinh và kiểm tra lại từ `php artisan route:list` nên không thể mô tả endpoint không tồn tại. Postman WWHN-195-outbound.postman_collection.json bind cùng revision; hash cả ba artifact nằm trong WWHN-201-outbound-contract-lock.json.'
  // Ghi chú của WWHN-201 chỉ nối thêm một lần, không đè ghi chú task khác.
  const existingNote = String(spec['x-note'] ?? '')
  spec['x-note'] = existingNote.includes('DEV201-P1-04R') ? existingNote : (existingNote ? existingNote + ' ' : '') + WWHN201_NOTE
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
    contract_version: contractVersion,
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

  console.log(JSON.stringify({ contract_version: contractVersion, removed, operations: lock.operations, artifacts: lock.artifacts }, null, 2))
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
      const humanCheck = op.operationId === 'outbound_human_check_confirm'
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
          header.push({ key: 'If-Match', value: humanCheck ? '"{{document_version}}"' : '{{document_version}}' })
        }
        if (has('idempotencykey')) {
          header.push({ key: 'Idempotency-Key', value: '{{idempotency_key}}' })
        }
      }
      const item = {
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
      }
      if (humanCheck) {
        item.request.body = {mode: 'raw', raw: JSON.stringify({warehouse_id: '{{warehouse_id}}', confirmed: true}, null, 2),
          options: {raw: {language: 'json'}}}
      }
      if (op.operationId === 'outbound_human_check_reconcile') {
        item.request.url.raw = item.request.url.raw.replace('{key}', '{{idempotency_key}}') + '?warehouse_id={{warehouse_id}}'
        item.request.url.path = item.request.url.path.map(part => part === '{key}' ? '{{idempotency_key}}' : part)
        item.request.url.query = [{key: 'warehouse_id', value: '{{warehouse_id}}'}]
      }
      if (op.operationId === 'outbound_item_nfc_history') {
        item.request.url.raw = item.request.url.raw.replace('{itemId}', '{{item_id}}')
        item.request.url.path = item.request.url.path.map(part => part === '{itemId}' ? '{{item_id}}' : part)
      }
      items.push(item)
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
      { key: 'warehouse_id', value: '' },
      { key: 'item_id', value: '' },
    ],
    item: items,
    'x-source-contract-version': spec.info.version,
    'x-decision': DECISION_ID,
  }
}

function postmanTestScript(op) {
  const allowed = Object.keys(op.responses ?? {}).filter((c) => /^\d+$/.test(c))
  const errors = op.operationId === 'outbound_human_check_confirm'
    ? {409: ['IDEMPOTENCY_CONFLICT', 'DOCUMENT_CHECK_VERSION_CONFLICT', 'DOCUMENT_CHECK_STATE_CONFLICT', 'AUTH_SESSION_REQUIRED'],
       422: ['DOCUMENT_CHECK_PAYLOAD_INVALID', 'BUSINESS_RULE_ERROR'],
       428: ['DOCUMENT_CHECK_IDENTITY_REQUIRED']}
    : ['outbound_human_check_reconcile', 'outbound_nfc_items', 'outbound_item_nfc_history'].includes(op.operationId)
      ? {422: ['BUSINESS_RULE_ERROR']} : ERROR_CONTRACT
  return [
    `const allowed = ${JSON.stringify(allowed)}.map(Number);`,
    'pm.test("HTTP status nằm trong hợp đồng WWHN-195", function () {',
    '  pm.expect(allowed).to.include(pm.response.code);',
    '});',
    '',
    '// DEC-OUT-STOCK-HTTP-001: mỗi HTTP status chỉ được mang đúng nhóm business code.',
    `const contract = ${JSON.stringify(errors)};`,
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
    '    pm.expect((body.meta && body.meta.request_id) || (body.error && body.error.request_id) || body.request_id || (pm.response.headers && pm.response.headers.get("X-Request-ID"))).to.be.ok;',
    '  });',
    '}',
  ]
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main()
}
