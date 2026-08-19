#!/usr/bin/env node
/**
 * WWHN-190 / DEV190-P1-01C — sinh + kiểm tra hợp đồng posting v1.8.0.
 *
 * Vì sao có file này thay vì sửa thẳng WWHN-180 v1.7.0:
 *   DISP-STK-236 §3 cấm sửa NỘI DUNG pack immutable WWHN-180
 *   (`docs/api-contract/WWHN-180-posting-openapi.{json,yaml}`,
 *    `docs/postman/WWHN-180 Canonical Contract.postman_*`). Pack đó chỉ được
 *   **superseded bằng decision record trỏ tới nó**. Nên bản đồng bộ runtime được
 *   phát hành thành release mới `docs/api-contract/releases/WWHN-190/1.8.0/`,
 *   kèm DEC-API-POSTING-002; v1.7.0 giữ nguyên byte-for-byte làm bản lịch sử.
 *
 * Chống pin rot (bài học WWHN-236 §bẫy 1 và WWHN-290): KHÔNG chép tay hash vào
 * markdown. Hash sinh tại đây vào RELEASE_MANIFEST.json + SHA256SUMS, và mọi
 * `x-permission` được đối chiếu với `php artisan route:list --json` thật.
 *
 * Dùng:
 *   node scripts/build-wwhn190-posting-contract.mjs            # sinh artifact
 *   node scripts/build-wwhn190-posting-contract.mjs --check    # gate: fail nếu lệch
 *   node scripts/build-wwhn190-posting-contract.mjs --routes <route-list.json>
 */

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import yaml from 'js-yaml';

const REPO = process.cwd();
const VERSION = '1.8.0';
const RELEASE_DIR = path.join(REPO, 'docs', 'api-contract', 'releases', 'WWHN-190', VERSION);
const SUPERSEDES = 'docs/api-contract/WWHN-180-posting-openapi.json';

const args = process.argv.slice(2);
const CHECK = args.includes('--check');
const routesArgIndex = args.indexOf('--routes');
const routesArg = routesArgIndex === -1 ? null : args[routesArgIndex + 1];

const errors = [];
const notes = [];

// ---------------------------------------------------------------------------
// 1. Bảng đặc tả — mỗi giá trị dưới đây đều trích từ runtime hiện hành, có địa chỉ.
// ---------------------------------------------------------------------------

/** Header If-Match: bắt buộc ở mọi mutation đi qua CurrentBaselineStockFlowController::expectedVersion(). */
const IF_MATCH = {
  name: 'If-Match',
  in: 'header',
  required: true,
  description:
    'Version chứng từ đang giữ (ETag của lần đọc/ghi trước). Bắt buộc; thiếu => 428 PRECONDITION_REQUIRED, sai định dạng => 422 INVALID_VERSION, lệch => 412 STALE_VERSION.',
  schema: { type: 'string', pattern: '^(W/)?"?\\d+"?$' },
};

const IDEMPOTENCY_KEY = {
  name: 'Idempotency-Key',
  in: 'header',
  required: true,
  description:
    'Khoá cho một hành động đổi tồn logic; trim; 8-100 ký tự. Thiếu => 428 PRECONDITION_REQUIRED; sai độ dài => 422 VALIDATION_ERROR.',
  schema: { type: 'string', minLength: 8, maxLength: 100 },
};

const DOCUMENT_ID = { $ref: '#/components/parameters/DocumentId' };

/**
 * Request body — trích đúng từ `$request->validate()` của handler runtime.
 * v1.7.0 khai thiếu body của TEMP_ONLY (FE đã ghi drift `BE-HANDOFF-WARRANTY-BODY-DRIFT`)
 * và khai sai enum `context` của scan/classify (còn `STOCKTAKE`, thiếu 3 giá trị thật).
 */
const BODY = {
  classify: {
    required: true,
    schema: {
      type: 'object',
      required: ['raw_code', 'context', 'warehouse_id'],
      properties: {
        raw_code: { type: 'string', minLength: 1, maxLength: 150 },
        context: {
          type: 'string',
          // ScanController@classify — enum runtime; KHÔNG còn STOCKTAKE (REQ-SRS-037).
          enum: ['INBOUND', 'OUTBOUND', 'INVENTORY', 'TRACE', 'WARRANTY_RECEIVE', 'WARRANTY_TEMP_ONLY'],
        },
        warehouse_id: { type: 'string', format: 'uuid' },
      },
    },
  },
  lineScan: {
    required: true,
    schema: {
      type: 'object',
      required: ['line_id'],
      description: 'Bắt buộc có `quantity` hoặc `qty` (required_without lẫn nhau).',
      properties: {
        line_id: { type: 'string', format: 'uuid' },
        quantity: { type: 'integer', minimum: 1 },
        qty: { type: 'integer', minimum: 1, description: 'Bí danh của `quantity`.' },
        code_value: { type: 'string', minLength: 1, maxLength: 150, nullable: true },
        item_id: { type: 'string', format: 'uuid', nullable: true },
        container_id: { type: 'string', format: 'uuid', nullable: true },
        printer_config_id: { type: 'string', format: 'uuid', nullable: true },
      },
    },
  },
  postNoBody: {
    required: false,
    schema: {
      type: 'object',
      description:
        'Không có field bắt buộc. Body vẫn tham gia request hash của Idempotency-Key: gửi lại cùng key với body khác => 409 IDEMPOTENCY_CONFLICT.',
      additionalProperties: true,
    },
  },
  reverse: {
    required: true,
    schema: {
      type: 'object',
      description: 'Phải chỉ rõ `lines` cần đảo, trừ khi tham chiếu `reversal_document_id` có sẵn.',
      properties: {
        reversal_document_id: { type: 'string', format: 'uuid', nullable: true },
        reason_code: { type: 'string', maxLength: 80, nullable: true },
        reason: { type: 'string', maxLength: 2000, description: 'Bắt buộc khi không có reversal_document_id.' },
        condition_status: {
          type: 'string',
          enum: ['SELLABLE', 'DEFECTIVE', 'NON_SELLABLE'],
          description: 'Bắt buộc cho reversal phiếu xuất khi không có reversal_document_id.',
        },
        physical_return_confirmed: { type: 'boolean', nullable: true },
        lines: {
          type: 'array',
          minItems: 1,
          items: {
            type: 'object',
            required: ['movement_id', 'quantity'],
            properties: {
              movement_id: { type: 'string', format: 'uuid' },
              quantity: { type: 'integer', minimum: 1 },
              item_id: { type: 'string', format: 'uuid', nullable: true },
            },
          },
        },
      },
    },
  },
  tempOnly: {
    required: true,
    schema: {
      type: 'object',
      required: ['temporary_item_code', 'missing_code_reason', 'manual_product_description', 'condition_status'],
      properties: {
        temporary_item_code: { type: 'string', maxLength: 150 },
        missing_code_reason: { type: 'string', maxLength: 500 },
        manual_product_description: { type: 'string', maxLength: 2000 },
        defect_id: { type: 'string', format: 'uuid', nullable: true },
        condition_status: { type: 'string', enum: ['WAITING_CHECK', 'INSPECTING'] },
        note: { type: 'string', maxLength: 1000, nullable: true },
      },
    },
  },
};

/**
 * Mỗi operation khai:
 *  - permission: PHẢI khớp middleware `permission:` thật trên route.
 *  - headers: If-Match / Idempotency-Key theo đúng runtime.
 *  - responses: HTTP + danh sách error code runtime có thể trả (SRS §8.2).
 *  - lifecycle: trạng thái vào/ra + stock effect.
 */
const OPERATIONS = [
  {
    key: 'scanClassify',
    body: BODY.classify,
    method: 'post',
    path: '/api/v1/scan/classify',
    operationId: 'wwhn190ScanClassify',
    tag: 'Scan',
    summary: 'Phân loại mã vật lý, không đổi tồn',
    permission: 'scan.execute|scan.classify',
    source: 'Modules/Warehouse/routes/api.php ScanController@classify',
    ifMatch: false,
    idempotency: false,
    stockEffect: 'NONE',
    lifecycle: { from: [], to: [], note: 'Read-only classification; không đổi trạng thái chứng từ.' },
    responses: {
      200: ['-'],
      401: ['UNAUTHENTICATED'],
      403: ['ACCESS_DENIED', 'WAREHOUSE_SCOPE_DENIED'],
      404: ['WAREHOUSE_NOT_FOUND'],
      409: ['WAREHOUSE_INACTIVE'],
      422: ['VALIDATION_ERROR'],
    },
  },
  {
    key: 'inboundScan',
    body: BODY.lineScan,
    method: 'post',
    path: '/api/v1/inbound-documents/{id}/scan',
    operationId: 'wwhn190InboundScan',
    tag: 'Scan',
    summary: 'Quét dòng phiếu nhập; stock_effect=NONE, version+1',
    permission: 'inbound.scan',
    source: 'CurrentBaselineStockFlowController@inboundScan',
    ifMatch: true,
    idempotency: false,
    stockEffect: 'NONE',
    lifecycle: { from: ['DRAFT', 'SCANNING'], to: ['SCANNING'], note: 'Ngoài DRAFT/SCANNING => 409 STATE_CONFLICT.' },
    responses: {
      200: ['-'],
      401: ['UNAUTHENTICATED'],
      403: ['ACCESS_DENIED', 'WAREHOUSE_SCOPE_DENIED'],
      404: ['RESOURCE_NOT_FOUND', 'DOCUMENT_LINE_NOT_FOUND'],
      409: ['STATE_CONFLICT', 'DUPLICATE_ITEM_IN_DOCUMENT', 'ITEM_ALREADY_RECEIVING'],
      412: ['STALE_VERSION'],
      422: [
        'INVALID_VERSION',
        'VALIDATION_ERROR',
        'SCAN_EXCEEDS_REQUIRED',
        'SKU_NOT_ACTIVE',
        'ITEM_REQUIRED',
        'SERIAL_QUANTITY_MUST_BE_ONE',
        'PHYSICAL_CODE_NOT_FOUND',
        'PHYSICAL_CODE_UNASSIGNED',
        'PHYSICAL_CODE_ITEM_MISMATCH',
        'ITEM_SKU_MISMATCH',
        'ITEM_NOT_RECEIVABLE',
      ],
      428: ['PRECONDITION_REQUIRED'],
    },
  },
  {
    key: 'postReceipt',
    body: BODY.postNoBody,
    method: 'post',
    path: '/api/v1/inbound-documents/{id}/post-receipt',
    operationId: 'wwhn190PostReceipt',
    tag: 'Stock Posting',
    summary: 'Post Receipt toàn phiếu, exactly-once',
    permission: 'inbound.post',
    source: 'CurrentBaselineStockFlowController@postReceipt -> CurrentBaselineStockService::postStockDocument',
    ifMatch: true,
    idempotency: true,
    stockEffect: 'RECEIPT_IN',
    lifecycle: {
      from: ['SCANNING'],
      to: ['POSTED'],
      note: 'DEC-FULL-POST-001: mọi dòng phải scanned_qty = qty_planned, nếu không => 422 FULL_POST_REQUIRED. Ngoài SCANNING => 409 STATE_CONFLICT.',
    },
    responses: {
      200: ['-'],
      401: ['UNAUTHENTICATED'],
      403: ['ACCESS_DENIED', 'WAREHOUSE_SCOPE_DENIED'],
      404: ['RESOURCE_NOT_FOUND'],
      409: ['STATE_CONFLICT', 'IDEMPOTENCY_CONFLICT', 'CONCURRENCY_CONFLICT', 'DUPLICATE_STOCK_EFFECT'],
      412: ['STALE_VERSION'],
      422: ['INVALID_VERSION', 'VALIDATION_ERROR', 'FULL_POST_REQUIRED', 'DOCUMENT_HAS_NO_LINES', 'WAREHOUSE_REQUIRED'],
      428: ['PRECONDITION_REQUIRED'],
    },
  },
  {
    key: 'outboundScan',
    body: BODY.lineScan,
    method: 'post',
    path: '/api/v1/outbound-documents/{id}/scan',
    operationId: 'wwhn190OutboundScan',
    tag: 'Scan',
    summary: 'Match item cho phiếu xuất; stock_effect=NONE, version+1',
    permission: 'outbound.scan',
    source: 'AlignedOutboundFlowController@outboundScan',
    ifMatch: true,
    idempotency: false,
    stockEffect: 'NONE',
    lifecycle: { from: ['DRAFT', 'SCANNING'], to: ['SCANNING'], note: 'Ngoài DRAFT/SCANNING => 409 STATE_CONFLICT.' },
    responses: {
      200: ['-'],
      401: ['UNAUTHENTICATED'],
      403: ['ACCESS_DENIED', 'WAREHOUSE_SCOPE_DENIED'],
      404: ['RESOURCE_NOT_FOUND', 'DOCUMENT_LINE_NOT_FOUND'],
      409: ['STATE_CONFLICT', 'ITEM_ALREADY_MATCHED', 'DUPLICATE_ITEM_MATCH', 'DUPLICATE_ITEM_IN_DOCUMENT'],
      412: ['STALE_VERSION'],
      422: ['INVALID_VERSION', 'VALIDATION_ERROR', 'PRODUCT_SKU_REQUIRED', 'OUTBOUND_REQUEST_REQUIRED', 'ITEM_NOT_AVAILABLE', 'INSUFFICIENT_STOCK'],
      428: ['PRECONDITION_REQUIRED'],
    },
  },
  {
    key: 'postIssue',
    body: BODY.postNoBody,
    method: 'post',
    path: '/api/v1/outbound-documents/{id}/post-issue',
    operationId: 'wwhn190PostIssue',
    tag: 'Stock Posting',
    summary: 'Post Issue toàn phiếu, exactly-once, sau khi nhãn đã PRINTED + đã dán',
    permission: 'outbound.post',
    source: 'AlignedOutboundFlowController@postIssue',
    ifMatch: true,
    idempotency: true,
    stockEffect: 'ISSUE_OUT',
    lifecycle: {
      from: ['SCANNING'],
      to: ['POSTED'],
      note: 'DEC-FULL-POST-001 + DEC-HN-20260811-02: đủ match, đúng 01 Packing Label PRINTED và đã xác nhận dán.',
    },
    responses: {
      200: ['-'],
      401: ['UNAUTHENTICATED'],
      403: ['ACCESS_DENIED', 'WAREHOUSE_SCOPE_DENIED'],
      404: ['RESOURCE_NOT_FOUND'],
      409: ['STATE_CONFLICT', 'IDEMPOTENCY_CONFLICT', 'CONCURRENCY_CONFLICT', 'LABEL_SNAPSHOT_STALE', 'DUPLICATE_STOCK_EFFECT'],
      412: ['STALE_VERSION'],
      422: [
        'INVALID_VERSION',
        'VALIDATION_ERROR',
        'FULL_POST_REQUIRED',
        'OUTBOUND_REQUEST_REQUIRED',
        'PACKING_LABEL_REQUIRED',
        'LABEL_NOT_PRINTED',
        'PACKING_LABEL_NOT_APPLIED',
        'INSUFFICIENT_STOCK',
        'PRODUCT_ITEM_MATCH_REQUIRED',
      ],
      428: ['PRECONDITION_REQUIRED'],
    },
  },
  {
    key: 'reverseInbound',
    body: BODY.reverse,
    method: 'post',
    path: '/api/v1/inbound-documents/{id}/reverse',
    operationId: 'wwhn190ReverseInbound',
    tag: 'Reversal',
    summary: 'Reversal append-only cho phiếu nhập đã POSTED',
    permission: 'inventory.reversal.post',
    source: 'CurrentBaselineStockFlowController@reverseInbound',
    ifMatch: true,
    idempotency: true,
    stockEffect: 'REVERSAL_OUT',
    lifecycle: { from: ['POSTED'], to: ['POSTED'], note: 'DEC-REV-001: movement gốc immutable; chỉ sinh movement đối ứng.' },
    responses: {
      200: ['-'],
      401: ['UNAUTHENTICATED'],
      403: ['ACCESS_DENIED', 'WAREHOUSE_SCOPE_DENIED'],
      404: ['RESOURCE_NOT_FOUND'],
      409: ['STATE_CONFLICT', 'IDEMPOTENCY_CONFLICT', 'CONCURRENCY_CONFLICT', 'ALREADY_FULLY_REVERSED', 'DOWNSTREAM_DEPENDENCY_EXISTS'],
      412: ['STALE_VERSION'],
      422: ['INVALID_VERSION', 'VALIDATION_ERROR', 'REVERSAL_LINES_REQUIRED', 'REVERSAL_QTY_EXCEEDED', 'NO_REVERSIBLE_MOVEMENT', 'INVALID_RETURN_CONDITION'],
      428: ['PRECONDITION_REQUIRED'],
    },
  },
  {
    key: 'reverseOutbound',
    body: BODY.reverse,
    method: 'post',
    path: '/api/v1/outbound-documents/{id}/reverse',
    operationId: 'wwhn190ReverseOutbound',
    tag: 'Reversal',
    summary: 'Reversal append-only cho phiếu xuất đã POSTED',
    permission: 'inventory.reversal.post',
    source: 'CurrentBaselineStockFlowController@reverseOutbound',
    ifMatch: true,
    idempotency: true,
    stockEffect: 'REVERSAL_IN',
    lifecycle: { from: ['POSTED'], to: ['POSTED'], note: 'DEC-REV-001: movement gốc immutable; chỉ sinh movement đối ứng.' },
    responses: {
      200: ['-'],
      401: ['UNAUTHENTICATED'],
      403: ['ACCESS_DENIED', 'WAREHOUSE_SCOPE_DENIED'],
      404: ['RESOURCE_NOT_FOUND'],
      409: ['STATE_CONFLICT', 'IDEMPOTENCY_CONFLICT', 'CONCURRENCY_CONFLICT', 'ALREADY_FULLY_REVERSED', 'DOWNSTREAM_DEPENDENCY_EXISTS'],
      412: ['STALE_VERSION'],
      422: ['INVALID_VERSION', 'VALIDATION_ERROR', 'REVERSAL_LINES_REQUIRED', 'REVERSAL_QTY_EXCEEDED', 'NO_REVERSIBLE_MOVEMENT', 'INVALID_RETURN_CONDITION'],
      428: ['PRECONDITION_REQUIRED'],
    },
  },
  {
    key: 'warrantyTempOnly',
    body: BODY.tempOnly,
    method: 'post',
    path: '/api/v1/warranty-cases/{id}/temp-only',
    operationId: 'wwhn190WarrantyTempOnly',
    tag: 'Warranty',
    summary: 'Ghi nhận TEMP_ONLY, không đổi tồn',
    permission: 'warranty.temp_only',
    source: 'WarrantyController@tempOnly',
    ifMatch: false,
    idempotency: true,
    stockEffect: 'NONE',
    lifecycle: { from: [], to: [], note: 'Warranty case; không đụng stock_documents nên không dùng If-Match.' },
    responses: {
      200: ['-'],
      401: ['UNAUTHENTICATED'],
      403: ['ACCESS_DENIED'],
      404: ['RESOURCE_NOT_FOUND'],
      409: ['STATE_CONFLICT', 'IDEMPOTENCY_CONFLICT', 'CONCURRENCY_CONFLICT'],
      422: ['VALIDATION_ERROR'],
      428: ['PRECONDITION_REQUIRED'],
    },
  },
  {
    key: 'inventoryReconciliation',
    method: 'get',
    path: '/api/v1/reports/inventory-reconciliation',
    operationId: 'wwhn190InventoryReconciliation',
    tag: 'Reconciliation',
    summary: 'Đối chiếu balance với ledger movement (RPT-INV-01)',
    permission: 'report.view',
    source: 'OperationalReportController@inventoryReconciliation',
    ifMatch: false,
    idempotency: false,
    stockEffect: 'NONE',
    lifecycle: { from: [], to: [], note: 'Read-only; PASS khi mismatch_count = 0.' },
    query: [
      { name: 'warehouse_id', in: 'query', required: false, schema: { type: 'string', format: 'uuid' } },
      { name: 'product_id', in: 'query', required: false, schema: { type: 'string', format: 'uuid' } },
      { name: 'stock_status', in: 'query', required: false, schema: { type: 'string' } },
    ],
    responses: {
      200: ['-'],
      401: ['UNAUTHENTICATED'],
      403: ['ACCESS_DENIED'],
      422: ['VALIDATION_ERROR'],
    },
  },
];

/** Bề mặt đã bị gỡ khỏi runtime — contract mới không được khai lại. */
const REMOVED_SURFACES = [
  {
    path: '/api/v1/stocktake/sessions/{id}/apply-adjustment',
    declared_in: 'WWHN-180 posting OpenAPI v1.7.0',
    reason:
      'REQ-SRS-037 + DEC-NO-STOCKTAKE-001 cấm Stocktake/adjust trong MVP; đóng chính thức bằng DISP-STK-236 (Apply Adjustment DEFERRED/NO-CODE). Route không tồn tại trong runtime.',
  },
];

const RESPONSE_DESCRIPTION = {
  200: 'Thành công. Với operation idempotent: lần gọi lại cùng key + cùng payload trả đúng body đã lưu, header Idempotent-Replay: true và data.replayed=true.',
  401: 'Chưa xác thực.',
  403: 'Thiếu permission hoặc ngoài warehouse data scope.',
  404: 'Không tìm thấy tài nguyên.',
  409: 'Xung đột trạng thái / idempotency / concurrency.',
  412: 'If-Match lệch version hiện tại. Không đổi trạng thái, tồn, movement.',
  422: 'Lỗi validation hoặc business rule.',
  428: 'Thiếu header bắt buộc (If-Match hoặc Idempotency-Key).',
};

// ---------------------------------------------------------------------------
// 2. Đối chiếu với route list thật
// ---------------------------------------------------------------------------

function loadRoutes() {
  if (routesArg) {
    return JSON.parse(fs.readFileSync(routesArg, 'utf8'));
  }
  const raw = execFileSync('php', ['artisan', 'route:list', '--json'], {
    cwd: REPO,
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });
  const start = raw.indexOf('[');
  return JSON.parse(raw.slice(start));
}

function assertRuntimeParity(routes) {
  const byUri = new Map();
  for (const r of routes) {
    for (const m of String(r.method).split('|')) {
      byUri.set(m + ' ' + r.uri, r);
    }
  }

  for (const op of OPERATIONS) {
    const uri = op.path.replace(/^\//, '');
    const key = op.method.toUpperCase() + ' ' + uri;
    const route = byUri.get(key);
    if (!route) {
      errors.push(`Route thiếu trong runtime: ${key}`);
      continue;
    }
    const middleware = String(route.middleware);
    const match = middleware.match(/permission:([^\s,]+)/);
    const actual = match ? match[1] : null;
    if (actual !== op.permission) {
      errors.push(`x-permission lệch runtime cho ${key}: contract=${op.permission} runtime=${actual}`);
    }
    if (!/auth:api/.test(middleware)) {
      errors.push(`Route ${key} không có auth:api.`);
    }
  }

  for (const removed of REMOVED_SURFACES) {
    const uri = removed.path.replace(/^\//, '');
    const hit = routes.find((r) => r.uri === uri);
    if (hit) {
      errors.push(`Bề mặt đã gỡ nhưng runtime vẫn expose: ${removed.path}`);
    } else {
      notes.push(`REMOVED-SURFACE-ABSENT: ${removed.path}`);
    }
  }
}

// ---------------------------------------------------------------------------
// 3. Sinh OpenAPI
// ---------------------------------------------------------------------------

function buildOpenApi() {
  const paths = {};
  for (const op of OPERATIONS) {
    const parameters = [];
    if (op.path.includes('{id}')) parameters.push(DOCUMENT_ID);
    if (op.ifMatch) parameters.push(IF_MATCH);
    if (op.idempotency) parameters.push(IDEMPOTENCY_KEY);
    for (const q of op.query ?? []) parameters.push(q);

    const responses = {};
    for (const [status, codes] of Object.entries(op.responses)) {
      const entry = {
        description: RESPONSE_DESCRIPTION[status] ?? 'Xem error catalog.',
      };
      if (status !== '200') {
        entry.content = { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } };
        entry['x-error-codes'] = codes;
      } else {
        entry.headers = {};
        if (op.ifMatch) {
          entry.headers.ETag = {
            description: 'Version mới của chứng từ, dùng làm If-Match cho request kế tiếp.',
            schema: { type: 'string' },
          };
        }
        if (op.idempotency) {
          entry.headers['Idempotent-Replay'] = {
            description: '"true" khi đây là replay của một request thành công trước đó.',
            schema: { type: 'string', enum: ['true', 'false'] },
          };
        }
        if (Object.keys(entry.headers).length === 0) delete entry.headers;
      }
      responses[status] = entry;
    }

    paths[op.path] ??= {};
    paths[op.path][op.method] = {
      operationId: op.operationId,
      tags: [op.tag],
      summary: op.summary,
      'x-canonical-scope': 'HOA_NAM_G1',
      'x-stock-effect': op.stockEffect,
      'x-permission': op.permission,
      'x-runtime-source': op.source,
      'x-requires-if-match': op.ifMatch,
      'x-requires-idempotency-key': op.idempotency,
      'x-lifecycle': op.lifecycle,
      ...(parameters.length ? { parameters } : {}),
      ...(op.body ? { requestBody: { required: op.body.required, content: { 'application/json': { schema: op.body.schema } } } } : {}),
      responses,
    };
  }

  return {
    openapi: '3.0.3',
    info: {
      title: 'WWHN-190 Hoa Nam Posting Contract (runtime-synchronised)',
      version: VERSION,
      description:
        'Bản đồng bộ runtime của bề mặt Scan/Post/Reversal/Reconciliation cho một kho tổng Hoa Nam. ' +
        'Supersede WWHN-180 posting OpenAPI v1.7.0 (giữ nguyên làm bản lịch sử, không sửa nội dung — DISP-STK-236 §3). ' +
        'Khác biệt chính so với v1.7.0: khai đủ header If-Match bắt buộc và 412 STALE_VERSION; ' +
        'sửa x-permission về đúng permission key runtime (inbound.post / outbound.post / inbound.scan / outbound.scan ...); ' +
        'đổi 409 IDEMPOTENCY_ERROR thành 409 IDEMPOTENCY_CONFLICT theo SRS §8.2; ' +
        'bổ sung 404 và lifecycle trạng thái; gỡ /stocktake/sessions/{id}/apply-adjustment theo REQ-SRS-037 + DISP-STK-236.',
    },
    servers: [{ url: 'http://127.0.0.1:8000', description: 'Local/CI; thay bằng URL môi trường đã duyệt' }],
    security: [{ bearerAuth: [] }],
    tags: [{ name: 'Scan' }, { name: 'Stock Posting' }, { name: 'Reversal' }, { name: 'Warranty' }, { name: 'Reconciliation' }],
    components: {
      securitySchemes: { bearerAuth: { type: 'http', scheme: 'bearer', bearerFormat: 'JWT' } },
      parameters: {
        DocumentId: { name: 'id', in: 'path', required: true, schema: { type: 'string', format: 'uuid' } },
      },
      schemas: {
        // Envelope thật do App\Support\ApiError::abort() sinh: có ĐỒNG THỜI
        // `error_code` phẳng và `error.code` lồng, request_id/timestamp nằm trong `meta`.
        // v1.7.0 khai thiếu `error_code` phẳng và đặt sai vị trí request_id.
        Error: {
          type: 'object',
          required: ['success', 'message', 'error_code', 'error', 'meta'],
          properties: {
            success: { type: 'boolean', enum: [false] },
            message: { type: 'string' },
            error_code: { type: 'string', description: 'Mã lỗi nghiệp vụ theo SRS §8.2.' },
            errors: { description: 'Chi tiết field/business, có thể là object hoặc mảng rỗng.' },
            error: {
              type: 'object',
              required: ['code'],
              properties: {
                code: { type: 'string' },
                details: { description: 'Bằng đúng giá trị `errors`.' },
              },
            },
            meta: {
              type: 'object',
              required: ['request_id', 'timestamp'],
              properties: {
                request_id: { type: 'string' },
                timestamp: { type: 'string', format: 'date-time' },
              },
            },
          },
        },
      },
    },
    paths,
    'x-document-version': VERSION,
    'x-contract-status': 'PROPOSED',
    'x-approval-status': 'PENDING_INDEPENDENT_SIGN_OFF',
    'x-supersedes': {
      artifact: SUPERSEDES,
      version: '1.7.0',
      decision_record: 'docs/decision-records/DEC-API-POSTING-002-WWHN190-RUNTIME-PARITY.md',
      immutability_rule: 'DISP-STK-236 §3 — v1.7.0 không bị sửa nội dung, chỉ bị superseded.',
    },
    'x-removed-surfaces': REMOVED_SURFACES,
    'x-error-catalog-authority': 'HN_SRS_FRD_v1.12 §8.2',
  };
}

// ---------------------------------------------------------------------------
// 4. Sinh Postman
// ---------------------------------------------------------------------------

function buildPostman(openapi) {
  const items = [];
  for (const op of OPERATIONS) {
    const header = [
      { key: 'Accept', value: 'application/json' },
      { key: 'Content-Type', value: 'application/json' },
      { key: 'Authorization', value: 'Bearer {{token}}' },
    ];
    if (op.ifMatch) header.push({ key: 'If-Match', value: '{{document_version}}' });
    if (op.idempotency) header.push({ key: 'Idempotency-Key', value: '{{idempotency_key}}' });

    const rawPath = op.path.replace('{id}', '{{document_id}}');
    items.push({
      name: `${op.operationId} — ${op.summary}`,
      request: {
        method: op.method.toUpperCase(),
        header,
        url: {
          raw: '{{base_url}}' + rawPath,
          host: ['{{base_url}}'],
          path: rawPath.split('/').filter(Boolean),
        },
        description:
          `permission: ${op.permission}\n` +
          `If-Match bắt buộc: ${op.ifMatch}\n` +
          `Idempotency-Key bắt buộc: ${op.idempotency}\n` +
          `stock_effect: ${op.stockEffect}\n` +
          `lifecycle: ${op.lifecycle.from.join('|') || 'n/a'} -> ${op.lifecycle.to.join('|') || 'n/a'} (${op.lifecycle.note})\n` +
          `error codes: ` +
          Object.entries(op.responses)
            .filter(([s]) => s !== '200')
            .map(([s, c]) => `${s}=${c.join(',')}`)
            .join('; '),
      },
      response: [],
    });
  }

  return {
    info: {
      name: `WWHN-190 Posting Contract v${VERSION}`,
      _postman_id: 'wwhn190-posting-contract-' + VERSION,
      description:
        'Sinh tự động từ scripts/build-wwhn190-posting-contract.mjs. Cùng version với OpenAPI ' +
        VERSION +
        '. Supersede "WWHN-180 Canonical Contract" v1.7.0 (không sửa file cũ — DISP-STK-236 §3).',
      schema: 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    },
    variable: [
      { key: 'base_url', value: 'http://127.0.0.1:8000' },
      { key: 'token', value: '' },
      { key: 'document_id', value: '' },
      { key: 'document_version', value: '1' },
      { key: 'idempotency_key', value: 'wwhn190-key-0001' },
    ],
    item: items,
    'x-document-version': VERSION,
    'x-openapi-title': openapi.info.title,
  };
}

// ---------------------------------------------------------------------------
// 5. Ghi / kiểm tra
// ---------------------------------------------------------------------------

const sha256 = (buf) => createHash('sha256').update(buf).digest('hex');
const stable = (obj) => JSON.stringify(obj, null, 2) + '\n';

function emit() {
  const routes = loadRoutes();
  assertRuntimeParity(routes);

  const openapi = buildOpenApi();
  const postman = buildPostman(openapi);

  const files = {
    'openapi.json': stable(openapi),
    'openapi.yaml': yaml.dump(openapi, { lineWidth: 120, noRefs: true, sortKeys: false }),
    'postman_collection.json': stable(postman),
  };

  const manifestEntries = Object.entries(files).map(([name, content]) => ({
    file: name,
    bytes: Buffer.byteLength(content, 'utf8'),
    sha256: sha256(Buffer.from(content, 'utf8')),
  }));

  const supersededPath = path.join(REPO, SUPERSEDES);
  const supersededSha = fs.existsSync(supersededPath) ? sha256(fs.readFileSync(supersededPath)) : null;

  const manifest = {
    jira_key: 'WWHN-190',
    register_item: 'DEV190-P1-01C',
    release: `WWHN-190/${VERSION}`,
    document_version: VERSION,
    contract_status: 'PROPOSED',
    approval_status: 'PENDING_INDEPENDENT_SIGN_OFF',
    generator: 'scripts/build-wwhn190-posting-contract.mjs',
    runtime_parity: {
      source: 'php artisan route:list --json',
      operations_checked: OPERATIONS.length,
      removed_surfaces_checked: REMOVED_SURFACES.length,
    },
    supersedes: {
      artifact: SUPERSEDES,
      version: '1.7.0',
      sha256: supersededSha,
      immutability_rule: 'DISP-STK-236 §3',
      decision_record: 'docs/decision-records/DEC-API-POSTING-002-WWHN190-RUNTIME-PARITY.md',
    },
    error_catalog_authority: 'HN_SRS_FRD_v1.12 §8.2',
    files: manifestEntries,
  };
  files['RELEASE_MANIFEST.json'] = stable(manifest);

  const sums = manifestEntries
    .concat([{ file: 'RELEASE_MANIFEST.json', sha256: sha256(Buffer.from(files['RELEASE_MANIFEST.json'], 'utf8')) }])
    .map((e) => `${e.sha256}  ${e.file}`)
    .join('\n') + '\n';
  files['SHA256SUMS'] = sums;

  return files;
}

function main() {
  const files = emit();

  if (CHECK) {
    for (const [name, content] of Object.entries(files)) {
      const target = path.join(RELEASE_DIR, name);
      if (!fs.existsSync(target)) {
        errors.push(`Thiếu artifact ${name} trong ${path.relative(REPO, RELEASE_DIR)}`);
        continue;
      }
      const onDisk = fs.readFileSync(target, 'utf8');
      if (onDisk !== content) {
        errors.push(`Artifact lệch generator: ${name} (chạy lại scripts/build-wwhn190-posting-contract.mjs)`);
      }
    }
  } else {
    fs.mkdirSync(RELEASE_DIR, { recursive: true });
    for (const [name, content] of Object.entries(files)) {
      fs.writeFileSync(path.join(RELEASE_DIR, name), content, 'utf8');
    }
  }

  const result = {
    check: CHECK,
    release_dir: path.relative(REPO, RELEASE_DIR).split(path.sep).join('/'),
    version: VERSION,
    status: errors.length === 0 ? 'PASS' : 'FAIL',
    errors,
    notes,
  };
  console.log(JSON.stringify(result, null, 2));
  process.exit(errors.length === 0 ? 0 : 1);
}

main();
