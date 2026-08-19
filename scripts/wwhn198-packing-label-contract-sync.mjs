#!/usr/bin/env node
/**
 * WWHN-198 / DEV198-P1-06 — đồng bộ MỘT controlling behavior về Packing Label
 * giữa OpenAPI outbound và runtime AlignedOutboundFlowController.
 *
 * Bối cảnh: hợp đồng outbound đã được nâng lên v2.0.0-R2 (WWHN-309) và bản đó đã
 * mô tả đúng phần hành vi ("Scan KHÔNG tạo lệnh in", label_status có NOT_CREATED/
 * DRAFT). Phần còn thiếu — và là phần khiến FE/QA vẫn có thể hiểu sai — gồm hai thứ:
 *
 *   1. Không có khối máy đọc được nào chốt hành vi nhãn, nên "controlling behavior"
 *      vẫn nằm rải rác trong văn xuôi và không thể verify tự động.
 *   2. Một số mã lỗi được khai trong hợp đồng KHÔNG tồn tại trong code (mã ma):
 *        - `OUTBOUND_MATCH_NOT_ACTIVE` -> runtime trả `MATCH_NOT_ACTIVE`
 *        - `PACKING_LABEL_FROZEN`      -> runtime trả `PACKING_LABEL_ALREADY_CREATED`
 *        - `IDEMPOTENCY_KEY_REUSED`    -> runtime dùng `IDEMPOTENCY_CONFLICT`
 *      FE bắt lỗi theo tên mã sẽ không bao giờ khớp; QA viết assertion theo hợp đồng
 *      sẽ đỏ oan hoặc tệ hơn là xanh giả.
 *
 * Script này sửa cả hai, idempotent.
 * Chạy: node scripts/wwhn198-packing-label-contract-sync.mjs
 */
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const JSON_PATH = path.join(ROOT, 'docs/api-contract/WWHN-195-outbound-openapi.json')

export const LABEL_LIFECYCLE = ['DRAFT', 'PREVIEWED', 'READY_TO_PRINT', 'PRINTED', 'PRINT_FAILED']
/** `NOT_CREATED` là giá trị runtime khi phiếu chưa full match nên chưa có nhãn nào. */
export const SCAN_LABEL_STATUS = ['NOT_CREATED', ...LABEL_LIFECYCLE]
export const CONTROLLING_DECISION = 'DR-HN-20260817-OUTBOUND-FLOW03'

/** Mã ma trong hợp đồng -> mã thật mà runtime ném ra. */
export const PHANTOM_CODE_FIXES = {
  OUTBOUND_MATCH_NOT_ACTIVE: 'MATCH_NOT_ACTIVE',
  PACKING_LABEL_FROZEN: 'PACKING_LABEL_ALREADY_CREATED',
  IDEMPOTENCY_KEY_REUSED: 'IDEMPOTENCY_CONFLICT',
}

function replacePhantomCodes(spec) {
  const replaced = []
  for (const [phantom, real] of Object.entries(PHANTOM_CODE_FIXES)) {
    const pattern = new RegExp(`(^|[^A-Z_])${phantom}([^A-Z_]|$)`, 'g')
    for (const [p, ops] of Object.entries(spec.paths)) {
      for (const [method, op] of Object.entries(ops)) {
        for (const [status, response] of Object.entries(op.responses ?? {})) {
          const text = String(response.description ?? '')
          if (!pattern.test(text)) continue
          pattern.lastIndex = 0
          response.description = text.replace(pattern, (m, a, b) => `${a}${real}${b}`)
          replaced.push(`${method.toUpperCase()} ${p} ${status}: ${phantom} -> ${real}`)
        }
      }
    }
  }
  return replaced
}

function main() {
  const spec = JSON.parse(fs.readFileSync(JSON_PATH, 'utf8'))

  const replaced = replacePhantomCodes(spec)

  // Khối máy đọc được: MỘT nguồn duy nhất cho hành vi nhãn, để test/verifier soi được.
  spec['x-packing-label-contract'] = {
    cardinality: 'ONE_PER_OUTBOUND_DOCUMENT',
    created_at: 'FULL_MATCH',
    created_status: 'DRAFT',
    auto_dispatch_print: false,
    lifecycle: LABEL_LIFECYCLE,
    scan_label_status_enum: SCAN_LABEL_STATUS,
    frozen_from: 'READY_TO_PRINT',
    unfrozen_discard_audit_action: 'PACKING_LABEL_DISCARDED',
    unmatch_after_freeze_code: 'PACKING_LABEL_ALREADY_CREATED',
    post_issue_gate: ['PRINTED', 'APPLIED', 'SNAPSHOT_CURRENT'],
    controlling_decision: CONTROLLING_DECISION,
    runtime_handler: 'AlignedOutboundFlowController@outboundScan',
    superseded_behavior:
      'CurrentBaselineStockFlowController@outboundScan (nhãn mỗi item + auto dispatch in, PENDING_PRINT) — không còn được route tới sau Flow06AlignmentServiceProvider.',
    ticket: 'WWHN-198',
    finding: 'DEV198-P1-06',
  }

  fs.writeFileSync(JSON_PATH, JSON.stringify(spec, null, 2) + '\n')

  console.log(
    JSON.stringify(
      {
        ticket: 'WWHN-198',
        finding: 'DEV198-P1-06',
        status: 'SYNCED',
        contract_version: spec.info.version,
        phantom_codes_replaced: replaced,
        controlling_decision: CONTROLLING_DECISION,
      },
      null,
      2,
    ),
  )
}

main()
