#!/usr/bin/env node
/**
 * WWHN-195 — verifier cho register R2 (DEV195-P1-05R2 + DEV195-P1-01R2).
 *
 * Chạy được độc lập, không cần DB, không cần server:
 *
 *   node scripts/verify-wwhn-195-outbound-contract.mjs
 *   node scripts/verify-wwhn-195-outbound-contract.mjs --json
 *
 * Kiểm 6 bất biến mà review R2 đã bắt lỗi:
 *
 *  V1  POST /outbound-documents vẫn không được đăng ký (direct create bị cấm); PATCH
 *      /outbound-documents/{id} tồn tại có kiểm soát cho phiếu DRAFT (AMD-API-052),
 *      không có PUT; annotation stock_out_create vắng mặt và stock_out_update có mặt.
 *  V2  Mỗi route outbound chỉ được đăng ký MỘT nơi. Đây là gốc của cả ba lệch R2:
 *      routes/api.php khai một handler rồi ServiceProvider override sang handler khác.
 *  V3  Contract khai đúng bằng tập route runtime (đối chiếu qua `php artisan route:list`
 *      khi có, ngược lại đối chiếu tĩnh với routes/api.php).
 *  V4  code_value maxLength giống nhau giữa contract và runtime = 150.
 *  V5  Packing Label dùng vòng đời §6 (DRAFT/PREVIEWED/READY_TO_PRINT/PRINTED/PRINT_FAILED),
 *      không còn PENDING_PRINT trong contract, và source không còn đường in per-item.
 *  V6  scan_source là field tường minh với đúng CHECK của ERD bảng 25, có mặt ở
 *      contract, ở runtime validation, và ở FE (camera vs nhập tay).
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const FE_ROOT = path.resolve(
  ROOT,
  '../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE',
);

const CONTRACT = 'docs/api-contract/WWHN-195-outbound-openapi.yaml';
const ROUTES = 'Modules/Warehouse/routes/api.php';
const PROVIDER = 'Modules/Warehouse/app/Providers/Flow06AlignmentServiceProvider.php';
const FLOW = 'Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php';
const LEGACY_FLOW = 'Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php';
const DOC_CONTROLLER = 'Modules/Warehouse/app/Http/Controllers/OutboundDocumentController.php';
const UPDATE_CONTROLLER = 'Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php';
const FE_SERVICE = 'src/modules/outbound/services/mvpOutboundService.ts';
const FE_MODAL = 'src/modules/outbound/components/AlignedOutboundDetailModal.tsx';

const jsonMode = process.argv.includes('--json');
const results = [];

function check(id, title, fn) {
  try {
    const detail = fn();
    results.push({ id, title, status: 'PASS', detail: detail ?? '' });
  } catch (error) {
    results.push({ id, title, status: 'FAIL', detail: String(error.message ?? error) });
  }
}

function read(relative, base = ROOT) {
  const full = path.join(base, relative);
  if (!fs.existsSync(full)) throw new Error(`Thiếu file: ${relative}`);
  return fs.readFileSync(full, 'utf8');
}

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

/** Đọc danh sách path/method của contract mà không cần thư viện YAML. */
function contractOperations(yaml) {
  const lines = yaml.split(/\r?\n/);
  const start = lines.findIndex((line) => line === 'paths:');
  assert(start >= 0, 'Contract không có khối paths:');

  const ops = [];
  let currentPath = null;
  for (const line of lines.slice(start + 1)) {
    if (/^\S/.test(line)) break;
    const pathMatch = line.match(/^ {2}(\/\S+):\s*$/);
    if (pathMatch) {
      currentPath = pathMatch[1];
      continue;
    }
    const methodMatch = line.match(/^ {4}(get|post|put|patch|delete):\s*$/);
    if (methodMatch && currentPath) {
      ops.push(`${methodMatch[1].toUpperCase()} ${currentPath.replace(/^\//, '')}`);
    }
  }
  return ops.sort();
}

/**
 * Mọi giá trị nằm trong một khối `enum:` của YAML — cả dạng inline `[A, B]` lẫn
 * block list `- A`. File contract bị formatter đổi qua lại giữa hai dạng nên
 * không được so bằng regex khoá cứng một dạng.
 */
function enumValues(yaml) {
  const values = [];
  const lines = yaml.split(/\r?\n/);
  for (let i = 0; i < lines.length; i += 1) {
    const inline = lines[i].match(/enum:\s*\[([^\]]*)\]/);
    if (inline) {
      values.push(...inline[1].split(',').map((v) => v.trim()));
      continue;
    }
    if (!/enum:\s*$/.test(lines[i])) continue;
    const indent = lines[i].search(/\S/);
    for (let j = i + 1; j < lines.length; j += 1) {
      const item = lines[j].match(/^(\s*)-\s*(\S+)\s*$/);
      if (!item || item[1].length <= indent) break;
      values.push(item[2]);
    }
  }
  return values;
}

/** Route runtime thật khi artisan chạy được; fallback về đọc tĩnh routes/api.php. */
function runtimeOperations() {
  try {
    const raw = execFileSync('php', ['artisan', 'route:list', '--json'], {
      cwd: ROOT,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
      maxBuffer: 32 * 1024 * 1024,
    });
    const rows = JSON.parse(raw).filter((row) => row.uri.startsWith('api/v1/outbound-documents'));
    const ops = [];
    for (const row of rows) {
      for (const method of String(row.method).split('|')) {
        if (method === 'HEAD' || method === 'OPTIONS') continue;
        ops.push(`${method} ${row.uri}`);
      }
    }
    return { source: 'artisan route:list', ops: [...new Set(ops)].sort() };
  } catch {
    const routes = read(ROUTES);
    const ops = [];
    const re = /Route::(get|post|put|patch|delete)\(\s*'(outbound-documents[^']*)'/g;
    let match;
    while ((match = re.exec(routes)) !== null) {
      ops.push(`${match[1].toUpperCase()} api/v1/${match[2]}`);
    }
    return { source: 'static routes/api.php', ops: [...new Set(ops)].sort() };
  }
}

check('V1', 'Direct create bị cấm; PATCH header phiếu DRAFT có kiểm soát', () => {
  const routes = read(ROUTES);
  const provider = read(PROVIDER);
  const controller = read(DOC_CONTROLLER);
  const updateController = read(UPDATE_CONTROLLER);

  assert(
    !/Route::post\(\s*'outbound-documents'/.test(routes + provider),
    'Vẫn còn đăng ký POST /outbound-documents (§8.1 cấm direct create).',
  );
  assert(
    /Route::patch\(\s*'outbound-documents\/\{id\}',\s*\[CurrentBaselineDocumentController::class,\s*'updateOutbound'\]/.test(routes + provider),
    'Thiếu Route::patch outbound-documents/{id} → updateOutbound.',
  );
  assert(
    !/Route::put\(\s*'outbound-documents\/\{id\}'/.test(routes + provider),
    'Không được đăng ký PUT /outbound-documents/{id}.',
  );
  assert(
    !controller.includes("operationId: 'stock_out_create'"),
    'Annotation Swagger vẫn khai stock_out_create.',
  );
  assert(
    updateController.includes("operationId: 'stock_out_update'"),
    'Annotation Swagger thiếu stock_out_update.',
  );
  return 'direct create sạch; PATCH DRAFT có annotation + handler';
});

check('V2', 'Mỗi route outbound chỉ đăng ký một nơi (không override kép)', () => {
  const routes = read(ROUTES);
  const provider = read(PROVIDER);
  const collect = (source) => {
    const found = [];
    const re = /Route::(get|post|put|patch|delete)\(\s*'(outbound-documents[^']*)'/g;
    let match;
    while ((match = re.exec(source)) !== null) found.push(`${match[1].toUpperCase()} ${match[2]}`);
    return found;
  };

  const inRoutes = collect(routes);
  const inProvider = collect(provider);
  const overlap = inProvider.filter((op) => inRoutes.includes(op));
  assert(
    overlap.length === 0,
    'Route bị khai ở cả routes/api.php lẫn ServiceProvider — đây chính là gốc của lệch contract R2: '
      + overlap.join(', '),
  );

  const duplicates = inRoutes.filter((op, index) => inRoutes.indexOf(op) !== index);
  assert(duplicates.length === 0, 'Route trùng trong routes/api.php: ' + duplicates.join(', '));
  return `${inRoutes.length} route trong routes/api.php, ${inProvider.length} override, 0 chồng lấn`;
});

check('V3', 'Contract khai đúng bằng tập route runtime', () => {
  const declared = contractOperations(read(CONTRACT));
  const { source, ops } = runtimeOperations();

  const missing = ops.filter((op) => !declared.includes(op));
  const extra = declared.filter((op) => !ops.includes(op));
  assert(missing.length === 0, 'Contract thiếu operation đang chạy: ' + missing.join(', '));
  assert(extra.length === 0, 'Contract khai operation không có route: ' + extra.join(', '));
  return `${declared.length} operation khớp 1-1 (nguồn route: ${source})`;
});

check('V4', 'code_value maxLength giống nhau giữa contract và runtime = 150', () => {
  const contract = read(CONTRACT);
  const block = contract.slice(contract.indexOf('        code_value:'));
  const declared = block.match(/maxLength:\s*(\d+)/);
  assert(declared, 'Không tìm thấy maxLength của code_value trong contract.');
  assert(declared[1] === '150', `Contract khai maxLength=${declared[1]}, phải là 150.`);

  const flow = read(FLOW);
  const runtime = flow.match(/'code_value'\s*=>\s*\['nullable',\s*'string',\s*'max:(\d+)'\]/);
  assert(runtime, 'Không tìm thấy validation code_value trong handler đang chạy.');
  assert(
    runtime[1] === '150',
    `Runtime nhận max:${runtime[1]} còn contract khai 150 — đúng lệch mà R2 đã bắt.`,
  );
  return 'contract 150 == runtime 150';
});

check('V5', 'Packing Label theo vòng đời §6, không còn model nhãn per-item', () => {
  const contract = read(CONTRACT);
  for (const state of ['DRAFT', 'PREVIEWED', 'READY_TO_PRINT', 'PRINTED', 'PRINT_FAILED']) {
    assert(contract.includes(state), `Contract thiếu trạng thái nhãn ${state} (SRS §6).`);
  }
  // Chỉ soi giá trị enum thật, không soi phần văn xuôi: contract cố ý ghi lại
  // model cũ (PENDING_PRINT) trong ghi chú để người đọc biết cái gì đã bị thay.
  const badEnum = enumValues(contract).filter((value) => value === 'PENDING_PRINT');
  assert(
    badEnum.length === 0,
    'Contract vẫn khai PENDING_PRINT như một giá trị enum hợp lệ của nhãn (§6 đã thay bằng DRAFT).',
  );

  const legacy = read(LEGACY_FLOW);
  assert(
    !legacy.includes('private function createAndDispatchLabel'),
    'Source vẫn còn đường tạo + in nhãn per-item lúc scan.',
  );
  assert(
    !legacy.includes("'status' => 'PENDING_PRINT'"),
    'Source vẫn khởi tạo nhãn ở PENDING_PRINT.',
  );

  const flow = read(FLOW);
  assert(
    flow.includes("'status' => 'DRAFT'"),
    'Handler đang chạy phải tạo Packing Label ở DRAFT khi full match.',
  );
  return 'DRAFT → PREVIEWED → READY_TO_PRINT → PRINTED; 0 nhãn per-item';
});

check('V6', 'scan_source tường minh, đúng CHECK của ERD bảng 25, có ở cả BE lẫn FE', () => {
  const flow = read(FLOW);
  assert(
    /SCAN_SOURCES\s*=\s*\['CAMERA',\s*'MANUAL',\s*'HARDWARE'\]/.test(flow),
    'Handler phải giới hạn scan_source đúng CHECK CAMERA/MANUAL/HARDWARE của ERD bảng 25.',
  );
  assert(
    flow.includes("'scan_source_origin'"),
    'Response phải phân biệt EXPLICIT và INFERRED để audit đọc được nguồn giá trị.',
  );

  // So theo nội dung khối chứ không theo cách xuống dòng: file YAML này bị
  // formatter đổi qua lại giữa inline `[A, B]` và block list `- A`.
  const contract = read(CONTRACT);
  const anchor = contract.indexOf('        scan_source:');
  assert(anchor >= 0, 'Contract chưa khai property scan_source trong OutboundScanRequest.');
  const block = contract.slice(anchor, anchor + 1200);
  for (const value of ['CAMERA', 'MANUAL', 'HARDWARE']) {
    assert(block.includes(value), `Contract thiếu giá trị scan_source ${value}.`);
  }
  assert(
    /enum:/.test(block),
    'scan_source phải khai enum để client biết đúng tập giá trị của ERD bảng 25.',
  );

  if (fs.existsSync(path.join(FE_ROOT, FE_MODAL))) {
    const modal = read(FE_MODAL, FE_ROOT);
    assert(
      modal.includes('scanSource: "CAMERA"'),
      'FE chưa khai CAMERA cho đường camera — server không suy luận được điều này.',
    );
    assert(
      modal.includes('scanSource: "MANUAL"'),
      'FE chưa khai MANUAL cho đường nhập tay.',
    );
    const service = read(FE_SERVICE, FE_ROOT);
    assert(
      service.includes('scan_source'),
      'FE service chưa gửi scan_source lên API.',
    );
    return 'BE + contract + FE đều khai tường minh';
  }
  return 'BE + contract khai tường minh (không tìm thấy repo FE để đối chiếu)';
});

const failed = results.filter((r) => r.status === 'FAIL');

if (jsonMode) {
  console.log(
    JSON.stringify(
      { task: 'WWHN-195', register: 'R2', checks: results, passed: results.length - failed.length, failed: failed.length },
      null,
      2,
    ),
  );
} else {
  console.log('WWHN-195 — verify outbound contract (register R2)\n');
  for (const r of results) {
    console.log(`[${r.status}] ${r.id} ${r.title}`);
    if (r.detail) console.log(`        ${r.detail}`);
  }
  console.log(`\n${results.length - failed.length}/${results.length} check đạt.`);
}

process.exit(failed.length === 0 ? 0 : 1);
