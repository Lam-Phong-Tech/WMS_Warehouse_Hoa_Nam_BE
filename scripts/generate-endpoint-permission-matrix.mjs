#!/usr/bin/env node
/**
 * WWHN-309 - Sinh ma trận endpoint x permission x OpenAPI từ route table thật.
 *
 * LÝ DO: FE/QA cần biết mỗi endpoint đòi permission nào, có bị optimistic locking
 * (If-Match) hay data-scope không, và endpoint nào là public. Trước đây thông tin
 * này nằm rải rác trong routes/*.php và chỉ được chép tay vào tài liệu, nên luôn
 * lệch sau mỗi lần thêm route. Script này lấy trực tiếp từ
 * `php artisan route:list --json` + `storage/api-docs/api-docs.json`.
 *
 * Dùng:
 *   node scripts/generate-endpoint-permission-matrix.mjs
 *   node scripts/generate-endpoint-permission-matrix.mjs --check
 */
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const specPath = join(root, "storage/api-docs/api-docs.json");
const mdPath = join(root, "docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md");
const jsonPath = join(root, "docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.json");
const checkOnly = process.argv.includes("--check");
const HTTP_METHODS = new Set(["GET", "POST", "PUT", "PATCH", "DELETE"]);
const BT = String.fromCharCode(96);
const code = (value) => BT + value + BT;

// Endpoint được phép không cần JWT. Danh sách này là contract bảo mật: thêm một
// route public mới mà không cập nhật ở đây sẽ làm generator fail.
//
// - health/login/refresh: bootstrap của phiên làm việc.
// - pv/*: SRS v1.12 SRC-008 + DR-PLAT-001 chốt "App PV anonymous" - catalog PV là
//   kênh tra cứu read-only, không JWT. Chúng chỉ đọc dữ liệu đã publish và không
//   bao giờ chạm stock ledger.
const PUBLIC_ALLOWLIST = new Set([
  "GET /api/v1/health",
  "POST /api/v1/auth/login",
  "POST /api/v1/auth/refresh",
  // WWHN-249 (DEV249-P1-03): 4 operation PUBLIC_ANONYMOUS theo Annex C là
  // listPvProducts / getPvProduct / listPvIndustries / listPvRecommendations;
  // SRS §12 (bảng Security) khai đích danh chúng phải override security: [].
  "GET /api/v1/pv/products",
  "GET /api/v1/pv/products/{skuId}",
  "GET /api/v1/pv/industries",
  "GET /api/v1/pv/recommendations",
  // Alias lịch sử NGOÀI Annex C, giữ để client cũ không vỡ; cùng read model,
  // cùng DTO chỉ-đọc. Không thêm alias mới vào đây nếu chưa có disposition của
  // BA — danh sách này chính là hợp đồng "mặt công khai" của hệ thống.
  "GET /api/v1/pv/catalog",
  "GET /api/v1/pv/tags",
]);

function routeTable() {
  const raw = execFileSync("php", ["artisan", "route:list", "--json"], {
    cwd: root,
    encoding: "utf8",
    maxBuffer: 32 * 1024 * 1024,
  });
  return JSON.parse(raw.slice(raw.indexOf("[")));
}

function middlewareList(route) {
  const value = route.middleware;
  if (Array.isArray(value)) return value.map(String);
  if (typeof value === "string" && value.trim() !== "") return value.split("\n").map((item) => item.trim());
  return [];
}

const spec = existsSync(specPath) ? JSON.parse(readFileSync(specPath, "utf8")) : { paths: {} };
const specIndex = new Map();
for (const [path, operations] of Object.entries(spec.paths ?? {})) {
  for (const [method, operation] of Object.entries(operations ?? {})) {
    if (!HTTP_METHODS.has(method.toUpperCase())) continue;
    specIndex.set(method.toUpperCase() + " " + path, operation);
  }
}

function operationDeclaresIfMatch(operation) {
  return (operation?.parameters ?? []).some(
    (parameter) => String(parameter?.name ?? "").toLowerCase() === "if-match",
  );
}

const rows = [];
const problems = [];
for (const route of routeTable()) {
  if (!route.uri.startsWith("api/v1")) continue;
  const middleware = middlewareList(route);
  const permissions = middleware
    .filter((item) => item.startsWith("permission:"))
    .flatMap((item) => item.replace("permission:", "").split("|"))
    .map((item) => item.trim())
    .filter(Boolean);
  const requiresAuth = middleware.some((item) => item === "auth:api" || item.startsWith("auth:"));
  // If-Match được enforce trong controller (ApiError::abort(412, STALE_VERSION)), KHÔNG qua
  // middleware — AppHttpMiddlewareCurrentBaselineIfMatch hiện không được gắn vào route nào.
  // Chỉ dò middleware thì ma trận báo 0 operation có optimistic locking, trái với OpenAPI đã
  // publish (41 operation khai parameter If-Match) và trái với hành vi runtime.
  const ifMatchMiddleware = middleware.some((item) => item.includes("CurrentBaselineIfMatch") || item.startsWith("if-match"));
  const dataScope = middleware.some((item) => /WarehouseScope|EnforceInventoryWarehouseScope/.test(item));

  for (const method of route.method.split("|")) {
    if (!HTTP_METHODS.has(method)) continue;
    const path = "/" + route.uri.replace(/^\//, "");
    const key = method + " " + path;
    const operation = specIndex.get(key);
    if (!operation) problems.push(key + " chưa có trong OpenAPI (storage/api-docs/api-docs.json)");
    if (!requiresAuth && !PUBLIC_ALLOWLIST.has(key)) {
      problems.push(key + " không yêu cầu auth nhưng không nằm trong PUBLIC_ALLOWLIST");
    }
    if (requiresAuth && permissions.length === 0 && !key.startsWith("POST /api/v1/auth") && !key.startsWith("GET /api/v1/auth")) {
      problems.push(key + " đã auth nhưng không gắn middleware permission:*");
    }
    rows.push({
      method,
      path,
      tag: operation?.tags?.[0] ?? "(chưa gắn tag)",
      summary: operation?.summary ?? "",
      auth: requiresAuth ? "JWT" : "public",
      permissions,
      if_match: ifMatchMiddleware || operationDeclaresIfMatch(operation),
      data_scope: dataScope,
      in_openapi: Boolean(operation),
    });
  }
}

rows.sort((a, b) => a.tag.localeCompare(b.tag) || a.path.localeCompare(b.path) || a.method.localeCompare(b.method));

const permissionUsage = new Map();
for (const row of rows) {
  for (const permission of row.permissions) {
    permissionUsage.set(permission, (permissionUsage.get(permission) ?? 0) + 1);
  }
}

const byTag = new Map();
for (const row of rows) {
  if (!byTag.has(row.tag)) byTag.set(row.tag, []);
  byTag.get(row.tag).push(row);
}

const json =
  JSON.stringify(
    {
      document: "HN-BE-API-003",
      title: "WMS Hoa Nam - Endpoint / permission / OpenAPI matrix (API v1)",
      task: "WWHN-309",
      generator: "scripts/generate-endpoint-permission-matrix.mjs",
      openapi_version: spec.info?.version ?? null,
      total_operations: rows.length,
      total_permissions: permissionUsage.size,
      public_allowlist: [...PUBLIC_ALLOWLIST].sort(),
      operations: rows,
    },
    null,
    2,
  ) + "\n";

const md = [
  "# HN-BE-API-003 - Ma trận endpoint / permission / OpenAPI (API v1)",
  "",
  "> **Tệp sinh tự động - không sửa tay.** Nguồn: " +
    code("php artisan route:list --json") +
    " + " +
    code("storage/api-docs/api-docs.json") +
    ".",
  "> Chạy lại: " +
    code("node scripts/generate-endpoint-permission-matrix.mjs") +
    ". CI chạy " +
    code("--check") +
    " và fail khi lệch.",
  "",
  "Task: WWHN-309 - [BE] Hoàn thiện tài liệu kiến trúc/API/vận hành.",
  "",
  "## 1. Tổng quan",
  "",
  "| Chỉ số | Giá trị |",
  "| --- | --- |",
  "| Số operation " + code("/api/v1") + " | " + rows.length + " |",
  "| Số permission code đang gác route | " + permissionUsage.size + " |",
  "| Operation có mặt trong OpenAPI | " + rows.filter((row) => row.in_openapi).length + "/" + rows.length + " |",
  "| Operation có optimistic locking If-Match | " + rows.filter((row) => row.if_match).length + " |",
  "| Operation bị siết data scope theo kho | " + rows.filter((row) => row.data_scope).length + " |",
  "| Endpoint public (không cần JWT) | " + rows.filter((row) => row.auth === "public").length + " |",
  "",
  "Endpoint public được allowlist tường minh trong generator:",
  "",
  ...[...PUBLIC_ALLOWLIST].sort().map((item) => "- " + code(item)),
  "",
  "Thêm route public mới mà không khai vào allowlist sẽ làm generator fail - đây là contract bảo mật, không phải tài liệu mô tả.",
  "",
  "## 2. Ma trận theo nhóm nghiệp vụ",
  "",
  ...[...byTag.keys()]
    .sort()
    .flatMap((tag) => [
      "### " + tag,
      "",
      "| Method | Path | Permission | If-Match | Data scope | Mô tả |",
      "| --- | --- | --- | --- | --- | --- |",
      ...byTag
        .get(tag)
        .map(
          (row) =>
            "| " +
            row.method +
            " | " +
            code(row.path) +
            " | " +
            (row.permissions.length ? row.permissions.map(code).join(" hoặc ") : row.auth === "public" ? "_public_" : "_chỉ JWT_") +
            " | " +
            (row.if_match ? "có" : "-") +
            " | " +
            (row.data_scope ? "có" : "-") +
            " | " +
            (row.summary || "-") +
            " |",
        ),
      "",
    ]),
  "## 3. Mức độ sử dụng permission",
  "",
  "| Permission | Số operation |",
  "| --- | --- |",
  ...[...permissionUsage.entries()]
    .sort((a, b) => a[0].localeCompare(b[0]))
    .map(([permission, count]) => "| " + code(permission) + " | " + count + " |"),
  "",
  "Danh mục permission chuẩn (mô tả + nhóm) nằm ở " + code("docs/permissions/WMS_PERMISSION_CATALOG_BE1.md") + ".",
  "FE dùng cùng bộ mã tại " + code("src/shared/constants/wms-permissions.ts") + "; route guard của FE chỉ là UX guard, BE vẫn là biên authorization thật.",
  "",
].join("\n");

if (problems.length) {
  problems.forEach((problem) => console.error("[endpoint-matrix] ERROR: " + problem));
  process.exit(1);
}

if (checkOnly) {
  const stale = [];
  for (const [path, expected] of [
    [mdPath, md],
    [jsonPath, json],
  ]) {
    const rel = relative(root, path).replaceAll("\\", "/");
    if (!existsSync(path)) stale.push(rel + " chưa tồn tại");
    else if (readFileSync(path, "utf8") !== expected) stale.push(rel + " đã lệch so với route table/OpenAPI");
  }
  if (stale.length) {
    stale.forEach((item) => console.error("[endpoint-matrix] ERROR: " + item));
    console.error("[endpoint-matrix] Chạy: node scripts/generate-endpoint-permission-matrix.mjs");
    process.exit(1);
  }
  console.log("[endpoint-matrix] PASS: ma trận khớp route table (" + rows.length + " operation).");
} else {
  writeFileSync(mdPath, md, "utf8");
  writeFileSync(jsonPath, json, "utf8");
  console.log("[endpoint-matrix] Đã sinh " + rows.length + " operation -> " + relative(root, mdPath));
}
