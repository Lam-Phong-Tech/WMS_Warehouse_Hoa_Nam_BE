#!/usr/bin/env node
/**
 * WWHN-202 · DEV202-P1-05R2 — bảo đảm bộ Postman DEV phủ đủ 8 bề mặt đọc của output
 * "Balance hỗ trợ", sinh trực tiếp từ OpenAPI để hai artifact không mô tả hai thứ khác nhau.
 *
 *   node scripts/sync-wwhn-202-postman-surfaces.mjs           # bổ sung request còn thiếu
 *   node scripts/sync-wwhn-202-postman-surfaces.mjs --check    # chỉ báo lệch, exit 1 nếu thiếu
 *
 * Vì sao cần: register đòi "QA uses same contract". Đo thực tế tại thời điểm lập hồ sơ,
 * bộ Postman chỉ có 4/8 bề mặt (balances, stock-movements list/detail/trace-source) —
 * thiếu subledger, trace-by-code, reversible và inventory-reconciliation. QA1 không thể
 * retest 4 bề mặt không có trong collection, nên "cùng hợp đồng" là tuyên bố không thực
 * hiện được chứ không phải chỉ là thiếu tài liệu.
 *
 * Bất biến giữ nguyên định dạng: collection được ghi lại bằng `JSON.stringify(_, null, 4)`,
 * đúng định dạng gốc (đã kiểm round-trip byte-identical), nên diff chỉ chứa phần thêm mới.
 * `id` của request sinh tất định từ operationId — chạy lại nhiều lần không tạo diff giả.
 */

import { createHash } from 'node:crypto'
import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const SPEC_PATH = join(ROOT, 'storage/api-docs/api-docs.json')
const COLLECTION_PATH = join(ROOT, 'docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json')
const CHECK_ONLY = process.argv.includes('--check')

/** 8 bề mặt của WWHN-202 và folder Postman tương ứng. */
export const WWHN_202_POSTMAN_TARGETS = [
  { specPath: '/api/v1/inventory/balances', folder: '07. Inventory' },
  { specPath: '/api/v1/inventory/balances/{id}/subledger', folder: '07. Inventory' },
  { specPath: '/api/v1/stock-movements', folder: '07. Inventory' },
  { specPath: '/api/v1/stock-movements/{id}', folder: '07. Inventory' },
  { specPath: '/api/v1/stock-movements/{id}/trace-source', folder: '07. Inventory' },
  { specPath: '/api/v1/stock-movements/trace-by-code', folder: '07. Inventory' },
  { specPath: '/api/v1/stock-movements/reversible', folder: '07. Inventory' },
  { specPath: '/api/v1/reports/inventory-reconciliation', folder: '12. Dashboard / Report' },
]

/** Path dạng Postman: `/api/v1/x/{id}` → `api/v1/x/:id`. */
export const toPostmanPath = (specPath) => specPath.replace(/^\//, '').replace(/\{(\w+)\}/g, ':$1')

/** Tập path đang có trong collection (đệ quy qua folder lồng nhau). */
export function collectExistingPaths(items, sink = new Set()) {
  for (const entry of items ?? []) {
    if (entry.item) collectExistingPaths(entry.item, sink)
    else if (entry.request?.url?.path) sink.add(entry.request.url.path.join('/'))
  }
  return sink
}

/** id tất định để lần chạy thứ hai không sinh diff. */
const deterministicId = (seed) => {
  const h = createHash('sha1').update(`wwhn-202:${seed}`).digest('hex')
  const variant = ((parseInt(h[16], 16) & 0x3) | 0x8).toString(16)
  return [h.slice(0, 8), h.slice(8, 12), `4${h.slice(13, 16)}`, variant + h.slice(17, 20), h.slice(20, 32)].join('-')
}

const paramEntry = (p) => ({
  disabled: false,
  description: { content: p.description ?? '', type: 'text/plain' },
  key: p.name,
  value: `<${p.schema?.format ?? p.schema?.type ?? 'string'}>`,
})

function buildRequestItem(operation, postmanPath) {
  const parameters = operation.parameters ?? []
  const name = operation.summary ?? operation.operationId

  return {
    id: deterministicId(operation.operationId),
    name,
    request: {
      name,
      description: {
        content:
          `WWHN-202 (DEV202-P1-05R2): bổ sung để QA1 retest đủ 8 bề mặt đọc của output "Balance hỗ trợ". ` +
          `Sinh từ OpenAPI operationId=${operation.operationId}; xem docs/evidence/WWHN-202/CONTRACT_PIN.json.`,
        type: 'text/plain',
      },
      url: {
        path: postmanPath.split('/'),
        host: ['{{baseUrl}}'],
        query: parameters.filter((p) => p.in === 'query').map(paramEntry),
        variable: parameters.filter((p) => p.in === 'path').map(paramEntry),
      },
      method: 'GET',
      body: {},
      auth: { type: 'bearer', bearer: [{ key: 'token', value: '{{bearerToken}}' }] },
    },
    response: [],
  }
}

function main() {
  const spec = JSON.parse(readFileSync(SPEC_PATH, 'utf8'))
  const collection = JSON.parse(readFileSync(COLLECTION_PATH, 'utf8'))
  const existing = collectExistingPaths(collection.item)

  const missing = []
  const problems = []

  for (const { specPath, folder: folderName } of WWHN_202_POSTMAN_TARGETS) {
    const operation = spec.paths?.[specPath]?.get
    if (!operation) {
      problems.push(`OpenAPI thiếu GET ${specPath} — không thể sinh request Postman tương ứng.`)
      continue
    }
    const postmanPath = toPostmanPath(specPath)
    if (existing.has(postmanPath)) continue

    const folder = collection.item.find((f) => f.name === folderName)
    if (!folder) {
      problems.push(`Collection thiếu folder "${folderName}" cho ${postmanPath}.`)
      continue
    }
    missing.push({ folder, operation, postmanPath })
  }

  if (problems.length > 0) {
    for (const p of problems) console.error(`  - ${p}`)
    process.exit(1)
  }

  if (missing.length === 0) {
    console.log('WWHN-202 Postman: PASS — đủ 8/8 bề mặt đọc.')
    return
  }

  if (CHECK_ONLY) {
    console.error(`WWHN-202 Postman: FAIL — thiếu ${missing.length}/8 bề mặt, QA1 không retest được:`)
    for (const m of missing) console.error(`  - ${m.postmanPath}`)
    console.error('Chạy `node scripts/sync-wwhn-202-postman-surfaces.mjs` để bổ sung.')
    process.exit(1)
  }

  for (const { folder, operation, postmanPath } of missing) {
    folder.item.push(buildRequestItem(operation, postmanPath))
    console.log(`+ ${folder.name} <- ${postmanPath}`)
  }

  writeFileSync(COLLECTION_PATH, `${JSON.stringify(collection, null, 4)}\n`, 'utf8')
  console.log(`WWHN-202 Postman: đã bổ sung ${missing.length} request.`)
}

main()
