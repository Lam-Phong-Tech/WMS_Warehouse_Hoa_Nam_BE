#!/usr/bin/env node
/**
 * WWHN-266 · DEV266-P1-06 (BE266-D01) — bộ Postman DEV phải mang header
 * `If-Match` cho đúng những operation bắt buộc header đó.
 *
 *   node scripts/sync-wwhn-266-postman-if-match.mjs           # bổ sung header còn thiếu
 *   node scripts/sync-wwhn-266-postman-if-match.mjs --check    # chỉ báo lệch, exit 1 nếu thiếu
 *
 * Vì sao cần: collection DEV có mô hình header bắt buộc — đo được 15 request mang
 * `Idempotency-Key` kèm mô tả "(Required)" — nhưng **0** request mang `If-Match`,
 * trong khi 47 operation ném 428 PRECONDITION_REQUIRED nếu thiếu header đó. Nguyên
 * nhân là collection được xuất từ OpenAPI ở thời điểm `If-Match` còn bị
 * `RouteSyncProcessor` khai cứng `required: false`, mà bộ xuất chỉ phát header nào
 * required. QA1 import collection rồi bấm bất kỳ endpoint mutation nào sẽ ăn 428 —
 * "QA dùng cùng một hợp đồng" trở thành tuyên bố không thực hiện được.
 *
 * Sau khi `RouteSyncProcessor` được sửa (cùng ticket), OpenAPI đã nói đúng; script
 * này chỉ kéo phần đã đúng đó sang collection.
 *
 * Bất biến: CHỈ THÊM header còn thiếu vào request đã có. Không tạo/xoá request,
 * không sắp xếp lại, không đụng header khác. Collection được ghi lại bằng
 * `JSON.stringify(_, null, 4)` đúng định dạng gốc nên diff chỉ chứa phần thêm mới,
 * và chạy lại lần hai không sinh diff.
 */

import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const SPEC_PATH = join(ROOT, 'storage/api-docs/api-docs.json')
const COLLECTION_PATH = join(ROOT, 'docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json')
const CHECK_ONLY = process.argv.includes('--check')

/** Path Postman `api/v1/x/:id` → path OpenAPI `/api/v1/x/{id}`. */
export const toSpecPath = (segments) => '/' + segments.map((s) => (s.startsWith(':') ? `{${s.slice(1)}}` : s)).join('/')

/**
 * Tập "method path" mà OpenAPI khai `If-Match` là bắt buộc.
 *
 * @returns {Map<string, {description: string}>}
 */
export function requiredIfMatchOperations(spec) {
  const required = new Map()
  for (const [specPath, operations] of Object.entries(spec.paths ?? {})) {
    for (const [method, operation] of Object.entries(operations ?? {})) {
      if (!operation || typeof operation !== 'object' || !Array.isArray(operation.parameters)) continue
      const header = operation.parameters.find((p) => p?.in === 'header' && p?.name === 'If-Match')
      if (header?.required === true) {
        required.set(`${method.toUpperCase()} ${specPath}`, { description: header.description ?? '' })
      }
    }
  }
  return required
}

/** Duyệt đệ quy mọi request trong collection (folder lồng nhau). */
export function walkRequests(items, visit) {
  for (const entry of items ?? []) {
    if (entry.item) walkRequests(entry.item, visit)
    else if (entry.request) visit(entry)
  }
}

function main() {
  const spec = JSON.parse(readFileSync(SPEC_PATH, 'utf8'))
  const collection = JSON.parse(readFileSync(COLLECTION_PATH, 'utf8'))
  const required = requiredIfMatchOperations(spec)

  if (required.size === 0) {
    console.error('WWHN-266 Postman: FAIL — OpenAPI không khai operation nào bắt buộc If-Match; kiểm tra lại RouteSyncProcessor.')
    process.exit(1)
  }

  const added = []

  walkRequests(collection.item, (entry) => {
    const segments = entry.request?.url?.path
    if (!Array.isArray(segments)) return
    const key = `${String(entry.request.method ?? '').toUpperCase()} ${toSpecPath(segments)}`
    const contract = required.get(key)
    if (!contract) return

    entry.request.header ??= []
    if (entry.request.header.some((h) => h?.key === 'If-Match')) return

    // Chèn ngay trước Content-Type để khối header bắt buộc nằm liền nhau, giống
    // cách Idempotency-Key đang được đặt.
    const at = entry.request.header.findIndex((h) => h?.key === 'Content-Type')
    const header = {
      disabled: false,
      description: { content: `(Required) ${contract.description}`.trim(), type: 'text/plain' },
      key: 'If-Match',
      value: '<integer>',
    }
    if (at === -1) entry.request.header.push(header)
    else entry.request.header.splice(at, 0, header)

    added.push(key)
  })

  if (added.length === 0) {
    console.log(`WWHN-266 Postman: PASS — ${required.size} operation bắt buộc If-Match đều đã có header trong collection.`)
    return
  }

  if (CHECK_ONLY) {
    console.error(`WWHN-266 Postman: FAIL — ${added.length} request thiếu header If-Match bắt buộc:`)
    for (const key of added) console.error(`  - ${key}`)
    console.error('Chạy `node scripts/sync-wwhn-266-postman-if-match.mjs` để bổ sung.')
    process.exit(1)
  }

  writeFileSync(COLLECTION_PATH, `${JSON.stringify(collection, null, 4)}\n`, 'utf8')
  console.log(`WWHN-266 Postman: đã bổ sung header If-Match cho ${added.length} request.`)
  for (const key of added) console.log(`  + ${key}`)
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url))) {
  main()
}
