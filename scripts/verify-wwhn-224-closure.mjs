#!/usr/bin/env node
/**
 * WWHN-224 — cổng đóng register P1 (re-review 18/08).
 *
 * Kiểm những bất biến mà phpunit KHÔNG kiểm được vì chúng nằm ở tầng hồ sơ/hợp đồng chứ
 * không ở tầng runtime:
 *
 *  1. DEV224-P1-04R — artifact OpenAPI đang commit nêu ĐÚNG quyền mà route thực thi, và
 *     không còn chuỗi `product.manage` ở nhóm import; hợp đồng công bố có mã 503.
 *  2. DEV224-P1-05R — code không còn nhánh "nuốt lỗi ghi Import báo cáo": không nơi nào
 *     trả null thay cho báo cáo, và việc ghi báo cáo nằm trong ImportService (cùng
 *     transaction) chứ không phải trong controller sau khi transaction đã commit.
 *  3. DEV224-P1-01R — register mapping tồn tại, còn ở trạng thái "chờ BA duyệt" (không ai
 *     tự ký hộ), và khớp với hằng số cột trong ImportService.
 *  4. DEV224-P1-02R/03R/06 — hồ sơ bắt buộc tồn tại đúng đường dẫn canonical.
 *
 * Chạy: node scripts/verify-wwhn-224-closure.mjs
 * Exit code 0 = PASS. Mọi lỗi in ra kèm lý do đọc được, không chỉ "assertion failed".
 */
import { readFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const root = process.cwd();
const failures = [];
const notes = [];

function check(condition, message) {
  if (!condition) failures.push(message);
}

function read(relative) {
  const full = path.join(root, relative);
  if (!existsSync(full)) {
    failures.push(`Thiếu tệp bắt buộc: ${relative}`);
    return null;
  }
  return readFileSync(full, 'utf8');
}

// ── 1. Hợp đồng công bố khớp quyền route ────────────────────────────────────────
const specRaw = read('storage/api-docs/api-docs.json');
if (specRaw) {
  const spec = JSON.parse(specRaw);
  const importPaths = Object.keys(spec.paths ?? {}).filter((key) =>
    key.startsWith('/api/v1/skus/import'),
  );

  check(
    importPaths.length === 5,
    `Phải có đúng 5 path nhóm import SKU trong spec, đang thấy ${importPaths.length}: ${importPaths.join(', ')}`,
  );

  for (const key of importPaths) {
    for (const [method, operation] of Object.entries(spec.paths[key])) {
      const forbidden = operation?.responses?.['403']?.description ?? '';
      check(
        !forbidden.includes('product.manage'),
        `DEV224-P1-04R: ${method.toUpperCase()} ${key} còn mô tả quyền cũ product.manage.`,
      );
      check(
        forbidden.includes('sku.manage'),
        `DEV224-P1-04R: ${method.toUpperCase()} ${key} không nêu quyền sku.manage mà route thực thi.`,
      );
    }
  }

  const importExcel = spec.paths?.['/api/v1/skus/import-excel']?.post;
  check(
    Boolean(importExcel?.responses?.['503']),
    'DEV224-P1-05R: hợp đồng công bố của POST /skus/import-excel chưa có mã 503.',
  );
  check(
    (importExcel?.responses?.['503']?.description ?? '').includes(
      'SKU_IMPORT_REPORT_PERSISTENCE_FAILED',
    ),
    'DEV224-P1-05R: mô tả 503 không nêu error code SKU_IMPORT_REPORT_PERSISTENCE_FAILED.',
  );
}

// Route thật phải gate sku.manage — đọc thẳng tệp route, không phụ thuộc app boot.
const routes = read('Modules/Warehouse/routes/import.php');
if (routes) {
  const gates = [...routes.matchAll(/permission:([a-z.]+)/g)].map((m) => m[1]);
  check(
    gates.length === 5 && gates.every((gate) => gate === 'sku.manage'),
    `DEV224-P1-04R: route import phải gate đúng 5 lần bằng sku.manage, đang thấy [${gates.join(', ')}].`,
  );
}

// ── 2. Không còn nhánh nuốt lỗi ghi Import báo cáo ──────────────────────────────
const controller = read('Modules/Warehouse/app/Http/Controllers/ImportController.php');
if (controller) {
  check(
    !/private function persistReport\s*\(/.test(controller),
    'DEV224-P1-05R: ImportController vẫn còn persistReport() riêng — việc ghi báo cáo phải nằm trong transaction của ImportService.',
  );
  check(
    controller.includes('ImportReportPersistenceException'),
    'DEV224-P1-05R: ImportController không bắt ImportReportPersistenceException.',
  );
  check(
    controller.includes('SKU_IMPORT_REPORT_PERSISTENCE_FAILED') && controller.includes('503'),
    'DEV224-P1-05R: ImportController không trả 503 SKU_IMPORT_REPORT_PERSISTENCE_FAILED.',
  );
  check(
    controller.includes("'partial_write' => false"),
    'DEV224-P1-05R: response 503 phải khẳng định partial_write=false.',
  );
}

const writer = read('Modules/Warehouse/app/Services/ImportReportWriter.php');
if (writer) {
  check(
    !/return null;/.test(writer),
    'DEV224-P1-05R: ImportReportWriter còn nhánh trả null — đó chính là hành vi fail-open cũ.',
  );
  check(
    writer.includes('throw new ImportReportPersistenceException'),
    'DEV224-P1-05R: ImportReportWriter phải ném exception khi không ghi được báo cáo.',
  );
}

const service = read('Modules/Warehouse/app/Services/ImportService.php');
if (service) {
  check(
    service.includes('private function withReport('),
    'DEV224-P1-05R: ImportService thiếu withReport() — nơi gắn báo cáo vào cùng transaction.',
  );
  // Bất biến vị trí: lời gọi ghi báo cáo của nhánh commit phải nằm TRƯỚC dấu đóng
  // DB::transaction(..., 3) — nếu ai đó kéo nó ra ngoài, tính nguyên tử mất mà test
  // runtime vẫn có thể xanh trên sqlite.
  const commitBranch = service.indexOf("$this->result(count($rows), count($records), [], $inserted, $updated, true");
  const transactionEnd = service.indexOf('}, 3);');
  check(
    commitBranch > 0 && transactionEnd > commitBranch,
    'DEV224-P1-05R: nhánh commit không còn nằm trong DB::transaction — kiểm lại thứ tự ghi báo cáo.',
  );
  check(
    service.includes('ImportReportPersistenceException'),
    'DEV224-P1-05R: ImportService phải fail-closed khi không truy vấn được import_reports.',
  );
}

// ── 3. Register mapping SKU-only ───────────────────────────────────────────────
const registerRaw = read('docs/decision-records/DISP-IMP-224-sku-import-scope-mapping.json');
if (registerRaw && service) {
  const register = JSON.parse(registerRaw);

  check(
    register.in_scope_dataset === 'SKU',
    'DEV224-P1-01R: register phải khẳng định phạm vi đúng một dataset SKU.',
  );
  check(
    register.status === 'PROPOSED_BY_DEV_PENDING_BA_DATA_OWNER_APPROVAL',
    'DEV224-P1-01R: trạng thái register phải là bản trình chờ BA/Data Owner, DEV không tự chuyển sang APPROVED.',
  );
  check(
    register.approval?.ba?.name == null && register.approval?.data_owner?.name == null,
    'DEV224-P1-01R: ô ký của BA/Data Owner phải để trống cho tới khi chính họ ký.',
  );
  for (const dataset of ['PHYSICAL_CODE', 'ITEM', 'CONTAINER', 'OPENING_BALANCE', 'AGENCY']) {
    check(
      Boolean(register.out_of_scope_datasets?.[dataset]?.reason),
      `DEV224-P1-01R: dataset ${dataset} của SRS §13.12 phải có lý do ngoài phạm vi.`,
    );
  }

  const constant = (name) => {
    const match = service.match(new RegExp(`const ${name} = \\[([\\s\\S]*?)\\];`));
    return match
      ? [...match[1].matchAll(/'([a-z_]+)'/g)].map((m) => m[1])
      : [];
  };
  const template = constant('TEMPLATE_COLUMNS');
  const accepted = constant('ACCEPTED_COLUMNS');

  check(
    JSON.stringify(template) === JSON.stringify(register.template_columns),
    `DEV224-P1-01R: cột template trong code (${template.join(',')}) đã trôi khỏi register.`,
  );
  check(
    JSON.stringify([...accepted].sort()) === JSON.stringify([...register.accepted_columns].sort()),
    'DEV224-P1-01R: tập cột được chấp nhận trong code đã trôi khỏi register.',
  );
  check(
    register.field_mapping?.length === template.length,
    `DEV224-P1-01R: register phải mô tả đủ ${template.length} cột template, đang có ${register.field_mapping?.length}.`,
  );
  for (const field of register.field_mapping ?? []) {
    check(
      field.type != null && field.required != null && 'default' in field && field.owner != null,
      `DEV224-P1-01R: cột ${field.column} thiếu một trong type/required/default/owner.`,
    );
  }
}

// ── 4. Hồ sơ bắt buộc ──────────────────────────────────────────────────────────
for (const file of [
  'docs/evidence/WWHN-224/DEV224_P1_CLOSURE_REGISTER.md',
  'docs/evidence/WWHN-224/QA2_RUNBOOK.md',
  'docs/evidence/WWHN-224/PR_DISPOSITION.md',
  'docs/evidence/WWHN-224/SOURCE_PIN.md',
  'docs/evidence/WWHN-224/ARTIFACT_PIN.md',
  'tests/Feature/Wwhn224P1ClosureTest.php',
  'scripts/sync-wwhn224-import-openapi.php',
]) {
  check(existsSync(path.join(root, file)), `Thiếu hồ sơ đóng: ${file}`);
}

// ── 5. Ngữ cảnh (in ra để đính vào evidence, không phải điều kiện pass) ─────────
try {
  const sha = execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
  notes.push(`SHA đang kiểm: ${sha}`);
} catch {
  notes.push('Không đọc được git SHA (checkout không có .git?).');
}

for (const note of notes) console.log(`· ${note}`);

if (failures.length > 0) {
  console.error(`\n✗ WWHN-224 closure gate FAIL (${failures.length}):`);
  for (const failure of failures) console.error(`  - ${failure}`);
  process.exit(1);
}

console.log('\n✓ WWHN-224 closure gate PASS — P1-01R/04R/05R và hồ sơ P1-02R/03R/06 đều đạt.');
