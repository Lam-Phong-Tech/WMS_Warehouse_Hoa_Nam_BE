#!/usr/bin/env node
/**
 * WWHN-309 (DEV309-P1-02) - Sinh bộ SỐ LIỆU CHÍNH THỨC của hợp đồng API.
 *
 * LÝ DO TỒN TẠI: tài liệu bàn giao từng chép tay các con số ("226 operation",
 * "2267/2267 response", "243 error code", "41 operation khai If-Match"). Mỗi lần
 * thêm route thì tài liệu lệch âm thầm, và reviewer bắt đúng lớp lỗi đó: cùng một
 * gói bàn giao ghi 2267 ở tài liệu contract nhưng 2268 ở task doc, trong khi spec
 * thật đã sang con số khác. Không ai sai "logic" - sai vì con số là văn xuôi, không
 * phải artifact sinh ra từ nguồn.
 *
 * Script này biến toàn bộ số liệu contract thành MỘT artifact sinh tự động:
 *   docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.json  (máy đọc)
 *   docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.md    (người đọc)
 *
 * Cổng tổng `verify-wwhn-309-handover-contract.mjs` đối chiếu MỌI con số được phát
 * biểu trong tài liệu handover với artifact này -> tài liệu không thể lệch mà CI
 * vẫn xanh.
 *
 * Dùng:
 *   node scripts/generate-api-contract-metrics.mjs
 *   node scripts/generate-api-contract-metrics.mjs --check
 */
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const specPath = join(root, "storage/api-docs/api-docs.json");
const errorCatalogPath = join(root, "docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.json");
const matrixPath = join(root, "docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.json");
const jsonPath = join(root, "docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.json");
const mdPath = join(root, "docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.md");
const checkOnly = process.argv.includes("--check");
const HTTP = new Set(["get", "post", "put", "patch", "delete"]);

function readJson(path, label) {
  if (!existsSync(path)) {
    console.error("[contract-metrics] ERROR: thiếu " + label + ": " + relative(root, path).replaceAll("\\", "/"));
    process.exit(1);
  }
  return JSON.parse(readFileSync(path, "utf8"));
}

const spec = readJson(specPath, "OpenAPI");
const errorCatalog = readJson(errorCatalogPath, "catalog error code");
const matrix = readJson(matrixPath, "ma trận endpoint/permission");

let operations = 0;
let responses = 0;
let responsesWithSchema = 0;
let idempotencyOperations = 0;
let idempotencyRequired = 0;
let ifMatchOperations = 0;

for (const item of Object.values(spec.paths ?? {})) {
  for (const [method, operation] of Object.entries(item ?? {})) {
    if (!HTTP.has(method)) continue;
    operations += 1;
    for (const response of Object.values(operation.responses ?? {})) {
      responses += 1;
      if (response.content) responsesWithSchema += 1;
    }
    const headers = (operation.parameters ?? []).filter((parameter) => parameter.in === "header");
    const idempotency = headers.find((parameter) => String(parameter.name).toLowerCase() === "idempotency-key");
    if (idempotency) {
      idempotencyOperations += 1;
      if (idempotency.required === true) idempotencyRequired += 1;
    }
    if (headers.some((parameter) => String(parameter.name).toLowerCase() === "if-match")) ifMatchOperations += 1;
  }
}

const metrics = {
  document: "HN-BE-API-004",
  title: "Số liệu chính thức của hợp đồng API backend",
  task: "WWHN-309",
  generator: "scripts/generate-api-contract-metrics.mjs",
  note: "Sinh tự động. KHÔNG sửa tay và KHÔNG chép số này vào tài liệu khác dưới dạng văn xuôi tự do - cổng verify-wwhn-309-handover-contract.mjs đối chiếu ngược lại.",
  openapi_version: spec.info?.version ?? null,
  openapi_paths: Object.keys(spec.paths ?? {}).length,
  openapi_operations: operations,
  openapi_responses: responses,
  openapi_responses_with_schema: responsesWithSchema,
  openapi_component_schemas: Object.keys(spec.components?.schemas ?? {}).length,
  idempotency_key_operations: idempotencyOperations,
  idempotency_key_required: idempotencyRequired,
  idempotency_key_optional: idempotencyOperations - idempotencyRequired,
  if_match_operations: ifMatchOperations,
  error_codes: errorCatalog.total_codes ?? (errorCatalog.codes ?? []).length,
  matrix_operations: matrix.total_operations ?? (matrix.operations ?? []).length,
  matrix_permissions: matrix.total_permissions ?? null,
  matrix_tags: new Set((matrix.operations ?? []).map((operation) => operation.tag)).size,
  data_scope_operations: (matrix.operations ?? []).filter((operation) => operation.data_scope === true).length,
  public_operations: Array.isArray(matrix.public_allowlist) ? matrix.public_allowlist.length : null,
};

const BT = String.fromCharCode(96);
const code = (value) => BT + value + BT;
const rows = [
  ["Phiên bản OpenAPI", metrics.openapi_version],
  ["Path trong OpenAPI", metrics.openapi_paths],
  ["Operation trong OpenAPI", metrics.openapi_operations],
  ["Response trong OpenAPI", metrics.openapi_responses],
  ["Response có schema envelope", metrics.openapi_responses_with_schema + "/" + metrics.openapi_responses],
  ["Schema trong components.schemas", metrics.openapi_component_schemas],
  ["Operation khai " + code("Idempotency-Key"), metrics.idempotency_key_operations],
  ["Trong đó bắt buộc / tuỳ chọn", metrics.idempotency_key_required + " / " + metrics.idempotency_key_optional],
  ["Operation khai " + code("If-Match"), metrics.if_match_operations],
  ["Error code trong catalog", metrics.error_codes],
  ["Operation trong ma trận endpoint", metrics.matrix_operations],
  ["Permission trong ma trận endpoint", metrics.matrix_permissions],
  ["Nhóm nghiệp vụ (tag)", metrics.matrix_tags],
  ["Operation có data scope theo kho", metrics.data_scope_operations],
  ["Operation public (không JWT)", metrics.public_operations],
];

const md = [
  "# HN-BE-API-004 - Số liệu chính thức của hợp đồng API",
  "",
  "> **Sinh tự động** bởi " +
    code("node scripts/generate-api-contract-metrics.mjs") +
    " từ " +
    code("storage/api-docs/api-docs.json") +
    ", " +
    code("HN-BE-API-002") +
    " và " +
    code("HN-BE-API-003") +
    ".",
  "> Không sửa tay. CI chạy " +
    code("--check") +
    " và cổng tổng WWHN-309 đối chiếu mọi con số trong tài liệu bàn giao với bảng này.",
  "",
  "Lý do tài liệu này tồn tại: các con số contract trước đây được chép tay vào nhiều tài liệu khác nhau,",
  "nên cùng một gói bàn giao có thể ghi hai giá trị khác nhau cho cùng một phép đếm (2267 vs 2268) trong khi",
  "spec thật đã sang giá trị thứ ba. Từ nay chỉ có **một** nguồn số liệu và nó được sinh từ artifact thật.",
  "",
  "| Chỉ số | Giá trị |",
  "| --- | --- |",
  ...rows.map(([label, value]) => "| " + label + " | " + value + " |"),
  "",
  "## Cách dùng",
  "",
  "1. FE/QA cần số liệu contract thì đọc bảng này, không đọc văn xuôi ở tài liệu khác.",
  "2. Sau khi đổi route/annotation: chạy " +
    code("php artisan l5-swagger:generate") +
    ", hai generator " +
    code("HN-BE-API-002/003") +
    ", rồi " +
    code("node scripts/generate-api-contract-metrics.mjs") +
    ".",
  "3. Cổng tổng " +
    code("node scripts/verify-wwhn-309-handover-contract.mjs") +
    " sẽ FAIL nếu bất kỳ tài liệu bàn giao nào còn phát biểu con số cũ.",
  "",
].join("\n");

const json = JSON.stringify(metrics, null, 2) + "\n";

if (checkOnly) {
  const stale = [];
  for (const [path, expected] of [
    [jsonPath, json],
    [mdPath, md],
  ]) {
    const relPath = relative(root, path).replaceAll("\\", "/");
    if (!existsSync(path)) stale.push(relPath + " chưa tồn tại");
    else if (readFileSync(path, "utf8") !== expected) stale.push(relPath + " đã lệch so với OpenAPI/catalog/ma trận");
  }
  if (stale.length) {
    stale.forEach((item) => console.error("[contract-metrics] ERROR: " + item));
    console.error("[contract-metrics] Chạy: node scripts/generate-api-contract-metrics.mjs");
    process.exit(1);
  }
  console.log("[contract-metrics] PASS: số liệu contract khớp artifact nguồn.");
} else {
  writeFileSync(jsonPath, json, "utf8");
  writeFileSync(mdPath, md, "utf8");
  console.log("[contract-metrics] Đã sinh " + relative(root, jsonPath).replaceAll("\\", "/") + " và .md");
}
