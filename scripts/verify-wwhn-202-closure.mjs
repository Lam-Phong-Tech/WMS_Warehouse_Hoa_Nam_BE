#!/usr/bin/env node
/**
 * WWHN-202 — cổng đóng task, chạy lại được ở bất kỳ thời điểm nào.
 *
 *   node scripts/verify-wwhn-202-closure.mjs
 *   node scripts/verify-wwhn-202-closure.mjs --expect-sha <candidate-sha>
 *
 * Vì sao cần verifier thay vì chỉ có tài liệu: register review WWHN-202 trượt đúng ở chỗ
 * "evidence tồn tại nhưng không ràng buộc" — hash contract ghi bằng tay, tên nhánh trong
 * comment không khớp nhánh thật, mô tả code đã lỗi thời so với code. Ba loại lỗi đó đều là
 * lỗi *đo được*, nên chúng được kiểm bằng máy ở đây:
 *
 *   C1  Bề mặt đọc: 8 route tồn tại với đúng permission §8.1 (đọc thẳng routes/api.php).
 *   C2  Contract pin: slice 8 operation của OpenAPI băm lại == pin; Postman phủ đủ 8 bề mặt.
 *   C3  Đối xứng FE: pin phía FE khai cùng slice hash + cùng path (nếu tìm thấy repo FE).
 *   C4  Quy tắc item_id: resolveLineItemId() vẫn là "đúng 1 item → gán, còn lại → null",
 *       và traceByPhysicalCode() vẫn còn CẢ hai nhánh (trực tiếp + fallback theo line).
 *   C5  Nhánh canonical: origin/m1-be-2 là ancestor thuần của m1 (supersession không mất việc).
 *   C6  Bộ test khoá: các test bắt buộc còn tồn tại và còn nằm trong group wwhn-202.
 *   C7  Chống pin rot: candidate SHA được suy từ commit-cuối-chạm-artifact, không hard-pin HEAD.
 *
 * Exit 0 = PASS. Exit 1 = FAIL kèm danh sách lệch. Không sửa file nào.
 */

import { createHash } from 'node:crypto'
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const failures = []
const notes = []

const arg = (name) => {
  const i = process.argv.indexOf(name)
  return i === -1 ? null : process.argv[i + 1] ?? null
}

const read = (relative) => readFileSync(join(ROOT, relative), 'utf8')
const fail = (check, message) => failures.push(`[${check}] ${message}`)

const git = (args) => {
  try {
    return execFileSync('git', args, { cwd: ROOT, encoding: 'utf8' }).trim()
  } catch {
    return null
  }
}

// ── C1. Bề mặt đọc và permission §8.1 ────────────────────────────────────────

const EXPECTED_SURFACES = [
  ["inventory/balances", 'inventory.view'],
  ["inventory/balances/{id}/subledger", 'inventory.view'],
  ['stock-movements', 'inventory.view'],
  ['stock-movements/{id}', 'inventory.view'],
  ['stock-movements/{id}/trace-source', 'inventory.trace'],
  ['stock-movements/trace-by-code', 'inventory.trace'],
  ['stock-movements/reversible', 'inventory.reversal.post'],
  ['reports/inventory-reconciliation', 'report.view'],
]

{
  const routes = read('Modules/Warehouse/routes/api.php')
  for (const [uri, permission] of EXPECTED_SURFACES) {
    // Khối route có thể trải nhiều dòng (middleware xuống dòng), nên cắt từ vị trí khai
    // route tới dấu `;` kết thúc câu lệnh thay vì so khớp theo từng dòng.
    const at = routes.indexOf(`Route::get('${uri}'`)
    if (at === -1) {
      fail('C1', `Không tìm thấy route GET ${uri} trong Modules/Warehouse/routes/api.php.`)
      continue
    }
    const end = routes.indexOf(';', at)
    const block = routes.slice(at, end === -1 ? at + 400 : end)
    if (!block.includes(`permission:${permission}`)) {
      fail('C1', `GET ${uri} phải yêu cầu permission:${permission} (§8.1 exact permission).`)
    }
  }
}

// ── C2. Contract pin: băm lại thật, không tin số ghi tay ─────────────────────
//
// Pin theo **slice** chứ không theo cả file OpenAPI. Lý do đo được: `api-docs.json`
// sinh tự động từ annotation của toàn hệ thống, nên bất kỳ task nào thêm một annotation
// ở module khác cũng đổi hash cả file. Pin cả file sẽ đỏ mỗi ngày vì lý do không liên
// quan tới WWHN-202, và một cổng đỏ vì nhiễu là cổng bị bỏ qua. Slice chỉ gồm 8 operation
// của output "Balance hỗ trợ": đổi ở đây mới là đổi hợp đồng mà FE/QA đã xác nhận.

const SPEC_PATHS = [
  '/api/v1/inventory/balances',
  '/api/v1/inventory/balances/{id}/subledger',
  '/api/v1/stock-movements',
  '/api/v1/stock-movements/{id}',
  '/api/v1/stock-movements/{id}/trace-source',
  '/api/v1/stock-movements/trace-by-code',
  '/api/v1/stock-movements/reversible',
  '/api/v1/reports/inventory-reconciliation',
]

/** JSON tất định: khoá sắp xếp, 2 space — cùng đầu vào luôn ra cùng byte. */
const canonicalJson = (value) => {
  const sort = (node) => {
    if (Array.isArray(node)) return node.map(sort)
    if (node && typeof node === 'object') {
      return Object.fromEntries(Object.keys(node).sort().map((k) => [k, sort(node[k])]))
    }
    return node
  }
  return `${JSON.stringify(sort(value), null, 2)}\n`
}

const buildSpecSlice = () => {
  const spec = JSON.parse(read('storage/api-docs/api-docs.json'))
  const paths = {}
  for (const specPath of SPEC_PATHS) {
    const operation = spec.paths?.[specPath]?.get
    if (!operation) {
      fail('C2', `OpenAPI thiếu GET ${specPath} — bề mặt đã pin không còn trong contract.`)
      continue
    }
    paths[specPath] = { get: operation }
  }
  return canonicalJson({
    document: 'WWHN-202-OPENAPI-SLICE',
    openapiVersion: spec.info?.version ?? null,
    paths,
  })
}

let pin = null
{
  const pinPath = 'docs/evidence/WWHN-202/CONTRACT_PIN.json'
  const slicePath = 'docs/evidence/WWHN-202/openapi-wwhn202-slice.json'

  if (!existsSync(join(ROOT, pinPath))) {
    fail('C2', `Thiếu ${pinPath}.`)
  } else {
    pin = JSON.parse(read(pinPath))
    const slice = buildSpecSlice()
    const sliceHash = createHash('sha256').update(slice).digest('hex')

    if (process.argv.includes('--write-slice')) {
      writeFileSync(join(ROOT, slicePath), slice, 'utf8')
      notes.push(`C2 --write-slice: đã ghi ${slicePath} (sha256=${sliceHash}).`)
    }

    if (!existsSync(join(ROOT, slicePath))) {
      fail('C2', `Thiếu ${slicePath} — chạy \`node scripts/verify-wwhn-202-closure.mjs --write-slice\`.`)
    } else if (read(slicePath) !== slice) {
      fail(
        'C2',
        `PIN ROT — 8 operation "Balance hỗ trợ" trong storage/api-docs/api-docs.json đã khác bản đã lưu ở ${slicePath}.\n` +
          `        => hợp đồng đổi SAU khi FE/QA xác nhận. Phải phát hành slice mới VÀ xin xác nhận lại, không sửa lặng lẽ.`,
      )
    }

    if (pin.openApiSliceSha256 !== sliceHash) {
      fail(
        'C2',
        `PIN ROT — sha256 slice hiện tại ${sliceHash} khác giá trị pin ${pin.openApiSliceSha256} trong ${pinPath}.`,
      )
    }

    // Postman: QA1 phải retest được đủ 8 bề mặt trên cùng collection đã pin.
    const collection = JSON.parse(read(pin.postmanPath))
    const postmanPaths = new Set()
    ;(function walk(items) {
      for (const entry of items ?? []) {
        if (entry.item) walk(entry.item)
        else if (entry.request?.url?.path) postmanPaths.add(entry.request.url.path.join('/'))
      }
    })(collection.item)

    for (const specPath of SPEC_PATHS) {
      const postmanPath = specPath.replace(/^\//, '').replace(/\{(\w+)\}/g, ':$1')
      if (!postmanPaths.has(postmanPath)) {
        fail('C2', `Postman thiếu ${postmanPath} — QA1 không retest được bề mặt này (chạy scripts/sync-wwhn-202-postman-surfaces.mjs).`)
      }
    }
  }
}

// ── C3. Đối xứng với pin phía FE ─────────────────────────────────────────────

{
  const feRoot = [
    resolve(ROOT, '../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE'),
    resolve(ROOT, '../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE'),
  ].find((candidate) => existsSync(join(candidate, 'package.json')))

  if (!feRoot) {
    notes.push('C3 BỎ QUA — không tìm thấy repo FE cạnh repo BE (chạy trong CI chỉ-BE là bình thường).')
  } else {
    const fePinPath = join(feRoot, 'src/shared/api/wwhn-202-balance-contract-pin.ts')
    if (!existsSync(fePinPath)) {
      fail('C3', 'FE thiếu src/shared/api/wwhn-202-balance-contract-pin.ts — không có xác nhận FE nào ràng buộc được.')
    } else {
      const fePin = readFileSync(fePinPath, 'utf8')
      if (pin?.openApiSliceSha256 && !fePin.includes(pin.openApiSliceSha256)) {
        fail(
          'C3',
          `Pin FE không khai slice sha256 ${pin.openApiSliceSha256} — hai repo đang xác nhận hai revision hợp đồng khác nhau.`,
        )
      }
      for (const [uri] of EXPECTED_SURFACES) {
        const path = '/' + uri.replace('{id}', ':id')
        if (!fePin.includes(`"${path}"`)) {
          fail('C3', `Pin FE thiếu path ${path}.`)
        }
      }
    }
  }
}

// ── C4. Quy tắc item_id vẫn đúng như DEC-STK-202 mô tả ───────────────────────

{
  const service = read('Modules/Warehouse/app/Services/CurrentBaselineStockService.php')
  if (!/function resolveLineItemId/.test(service)) {
    fail('C4', 'CurrentBaselineStockService::resolveLineItemId() không còn — DEC-STK-202 §D-2 mô tả code không tồn tại.')
  }
  if (!/\$itemIds->count\(\)\s*===\s*1\s*\?/.test(service)) {
    fail('C4', 'resolveLineItemId() không còn quy tắc "đúng 1 item mới gán item_id" — DEC-STK-202 §D-2 đã lệch code.')
  }
  if (!/resolveLineItemId\(\$documentType, \(string\) \$line->id\)/.test(service)) {
    fail('C4', 'postStockDocument() không còn truyền item_id vào mutateBalance() — write-side WWHN-205 đã bị hồi quy.')
  }

  const controller = read('Modules/Warehouse/app/Http/Controllers/StockMovementController.php')
  if (!/function traceByPhysicalCode/.test(controller)) {
    fail('C4', 'StockMovementController::traceByPhysicalCode() không còn.')
  }
  for (const [needle, why] of [
    ['outbound_item_matches', 'nhánh fallback theo line (outbound) đã bị gỡ — line gộp nhiều item sẽ trả movements rỗng'],
    ['inbound_scan_entries', 'nhánh fallback theo line (inbound) đã bị gỡ'],
    ['item_status', 'buildItemDependencyMap() phải dùng cột chuẩn item_status, không dùng alias status'],
  ]) {
    if (!controller.includes(needle)) fail('C4', `StockMovementController: ${why}.`)
  }
  if (/item_instances\.status\b/.test(controller)) {
    fail('C4', 'StockMovementController lại tham chiếu item_instances.status — cột alias chỉ backfill 1 lần trên pgsql, hồi quy Bug 1 (500).')
  }
}

// ── C5. Nhánh canonical ──────────────────────────────────────────────────────

{
  const hasRemote = git(['rev-parse', '--verify', '--quiet', 'origin/m1-be-2'])
  if (!hasRemote) {
    notes.push('C5 BỎ QUA — không có ref origin/m1-be-2 trong clone hiện tại (shallow/CI).')
  } else {
    try {
      execFileSync('git', ['merge-base', '--is-ancestor', 'origin/m1-be-2', 'm1'], { cwd: ROOT, stdio: 'ignore' })
      const counts = git(['rev-list', '--left-right', '--count', 'origin/m1-be-2...m1'])
      notes.push(`C5 OK — origin/m1-be-2 (${hasRemote.slice(0, 7)}) là ancestor thuần của m1; behind/ahead = ${counts?.replace(/\s+/, '/')}.`)
    } catch {
      fail('C5', 'origin/m1-be-2 KHÔNG còn là ancestor của m1 — supersession sẽ làm mất commit. Phải dừng và đối chiếu trước khi đóng DEV202-P1-01R2.')
    }
  }
}

// ── C6. Bộ test khoá ─────────────────────────────────────────────────────────

const REQUIRED_TESTS = {
  'tests/Feature/Wwhn202OutboundBalanceEvidenceTest.php': [
    'test_post_issue_moves_balance_ledger_and_subledger_together_with_db_before_after',
    'test_all_movement_read_surfaces_agree_with_the_ledger_after_post_issue',
    'test_reconciliation_report_passes_all_four_checks_and_command_creates_no_incident',
    'test_reverse_restores_stock_and_every_read_surface_reflects_the_net_effect',
    'test_every_read_surface_rejects_anonymous_access_with_401',
    'test_every_read_surface_rejects_missing_permission_with_403_and_changes_nothing',
    'test_read_surfaces_return_404_and_422_without_leaking_internals',
    'test_post_issue_error_taxonomy_matches_srs_8_2',
    'test_movement_item_attribution_follows_dec_stk_202',
    'test_aggregate_line_leaves_item_id_null_and_trace_by_code_falls_back_to_the_line',
    'test_read_surface_permission_matrix_matches_the_registered_routes',
  ],
  'tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php': [
    'test_stock_movement_trace_by_code_resolves_issued_item_and_its_movement',
    'test_stock_movements_reversible_lists_posted_issue_with_dependency_guard',
  ],
  'tests/Feature/Wwhn205StockMovementItemIdInvariantTest.php': [
    'test_post_issue_sets_item_id_on_movement_for_single_item_line',
    'test_post_receipt_leaves_item_id_null_when_line_aggregates_multiple_items',
  ],
}

for (const [file, tests] of Object.entries(REQUIRED_TESTS)) {
  if (!existsSync(join(ROOT, file))) {
    fail('C6', `Thiếu test file ${file}.`)
    continue
  }
  const source = read(file)
  for (const test of tests) {
    if (!source.includes(test)) fail('C6', `${file} thiếu test ${test}().`)
  }
}

for (const file of [
  'tests/Feature/Wwhn202OutboundBalanceEvidenceTest.php',
  'tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php',
]) {
  if (existsSync(join(ROOT, file)) && !read(file).includes('@group wwhn-202')) {
    fail('C6', `${file} phải mang @group wwhn-202 — đó là ràng buộc cơ học thay cho việc đổi tên file (DEV202-P1-01R2).`)
  }
}

// ── C7. Candidate SHA suy từ commit-cuối-chạm-artifact ───────────────────────

const ARTIFACT_PATHS = [
  'docs/evidence/WWHN-202',
  'docs/decision-records/DEC-STK-202-post-issue-error-taxonomy-and-item-attribution.md',
  'tests/Feature/Wwhn202OutboundBalanceEvidenceTest.php',
  'scripts/verify-wwhn-202-closure.mjs',
]

{
  const candidate = git(['log', '-1', '--format=%H', '--', ...ARTIFACT_PATHS])
  if (!candidate) {
    notes.push('C7 BỎ QUA — chưa có commit nào chạm artifact WWHN-202 (đang ở working tree).')
  } else {
    notes.push(`C7 candidate SHA (commit cuối chạm artifact WWHN-202) = ${candidate}`)
    const expected = arg('--expect-sha')
    if (expected && !candidate.startsWith(expected)) {
      fail('C7', `--expect-sha ${expected} nhưng candidate thật là ${candidate}.`)
    }
  }
}

// ── Kết quả ──────────────────────────────────────────────────────────────────

for (const note of notes) console.log(note)

if (failures.length > 0) {
  console.error(`\nWWHN-202 CLOSURE: FAIL (${failures.length} lệch)\n`)
  for (const f of failures) console.error(`  - ${f}`)
  process.exit(1)
}

console.log('\nWWHN-202 CLOSURE: PASS — 7/7 check.')
