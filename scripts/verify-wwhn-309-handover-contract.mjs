#!/usr/bin/env node
/**
 * WWHN-309 - Cổng tổng cho gói bàn giao kiến trúc/API/vận hành backend.
 *
 * Mục tiêu: tài liệu handover không được phép "đúng lúc viết, sai lúc đọc".
 * Script này fail-closed trên các lớp lỗi đã từng xảy ra thật ở dự án:
 *
 *   1. Tài liệu handover bị thiếu hoặc bị xoá.
 *   2. Catalog error code / ma trận endpoint lệch so với source (chạy --check của
 *      hai generator).
 *   3. OpenAPI thiếu endpoint so với route table, hoặc response không mô tả được
 *      payload (FE/QA không sinh type được).
 *   4. README ghi cổng/cấu hình khác .env.example (đúng lớp lỗi FE311-P1-02).
 *   5. Bất biến MVP bị tái nhập âm thầm: stocktake / sửa tồn trực tiếp.
 *   6. Secret bị chép thẳng vào tài liệu.
 *
 * Dùng: node scripts/verify-wwhn-309-handover-contract.mjs
 * Exit code 0 = PASS. Khác 0 = có mục đỏ, không được ký handover.
 */
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const failures = [];
const notes = [];

function rel(path) {
  return relative(root, path).replaceAll("\\", "/");
}

function read(path) {
  return readFileSync(join(root, path), "utf8");
}

// --- 1. Tài liệu handover bắt buộc -----------------------------------------
const REQUIRED_DOCS = [
  ["README.md", "Hướng dẫn build/run backend"],
  ["docs/architecture/HN-BE-ARCH-001_BACKEND_ARCHITECTURE.md", "Kiến trúc as-built"],
  ["docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md", "Contract API bàn giao"],
  ["docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md", "Catalog error code"],
  ["docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.json", "Catalog error code (JSON)"],
  ["docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md", "Ma trận endpoint/permission"],
  ["docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.json", "Ma trận endpoint/permission (JSON)"],
  ["docs/ops/HN-BE-OPS-001_OPERATIONS_RUNBOOK.md", "Runbook vận hành"],
  ["docs/docs_task_jira/WWHN-309.md", "Tài liệu task canonical"],
  ["storage/api-docs/api-docs.json", "OpenAPI JSON"],
  ["storage/api-docs/api-docs.yaml", "OpenAPI YAML"],
  ["docs/permissions/WMS_PERMISSION_CATALOG_BE1.md", "Danh mục permission"],
];

for (const [path, label] of REQUIRED_DOCS) {
  if (!existsSync(join(root, path))) failures.push(`Thiếu ${label}: ${path}`);
}

// --- 2. Generator phải khớp source -----------------------------------------
for (const [script, label] of [
  ["scripts/generate-error-code-catalog.mjs", "catalog error code"],
  ["scripts/generate-endpoint-permission-matrix.mjs", "ma trận endpoint/permission"],
]) {
  if (!existsSync(join(root, script))) {
    failures.push(`Thiếu generator ${script}`);
    continue;
  }
  try {
    execFileSync("node", [script, "--check"], { cwd: root, encoding: "utf8", stdio: "pipe" });
    notes.push(`${label}: khớp source`);
  } catch (error) {
    const output = [error.stdout, error.stderr].filter(Boolean).join("").trim();
    failures.push(`${label} lệch source code:\n    ${output.split("\n").join("\n    ")}`);
  }
}

// --- 3. Chất lượng OpenAPI --------------------------------------------------
if (existsSync(join(root, "storage/api-docs/api-docs.json"))) {
  const spec = JSON.parse(read("storage/api-docs/api-docs.json"));
  const HTTP = new Set(["get", "post", "put", "patch", "delete"]);
  const specOps = new Set();
  let responses = 0;
  let responsesWithContent = 0;

  for (const [path, item] of Object.entries(spec.paths ?? {})) {
    for (const [method, operation] of Object.entries(item ?? {})) {
      if (!HTTP.has(method)) continue;
      specOps.add(method.toUpperCase() + " " + path);
      for (const response of Object.values(operation.responses ?? {})) {
        responses += 1;
        if (response.content) responsesWithContent += 1;
      }
    }
  }

  for (const schema of ["ApiSuccessEnvelope", "ApiErrorEnvelope", "ApiErrorDetail", "ApiMeta", "ApiLinks"]) {
    if (!spec.components?.schemas?.[schema]) failures.push(`OpenAPI thiếu components.schemas.${schema}`);
  }

  if (responses > 0 && responsesWithContent !== responses) {
    failures.push(
      `OpenAPI có ${responses - responsesWithContent}/${responses} response không mô tả được payload (thiếu content schema)`,
    );
  } else if (responses > 0) {
    notes.push(`OpenAPI: ${responses}/${responses} response có schema envelope`);
  }

  // Route table thật phải nằm trọn trong spec.
  let routes = [];
  try {
    const raw = execFileSync("php", ["artisan", "route:list", "--json"], {
      cwd: root,
      encoding: "utf8",
      maxBuffer: 32 * 1024 * 1024,
    });
    routes = JSON.parse(raw.slice(raw.indexOf("[")));
  } catch (error) {
    failures.push("Không chạy được `php artisan route:list --json`: " + String(error.message).split("\n")[0]);
  }

  const missing = [];
  for (const route of routes) {
    if (!route.uri.startsWith("api/v1")) continue;
    for (const method of route.method.split("|")) {
      if (!HTTP.has(method.toLowerCase())) continue;
      const key = method + " /" + route.uri.replace(/^\//, "");
      if (!specOps.has(key)) missing.push(key);
    }
  }
  if (missing.length) {
    failures.push(`OpenAPI thiếu ${missing.length} operation so với route table: ${missing.slice(0, 10).join(", ")}`);
  } else if (routes.length) {
    notes.push("OpenAPI phủ 100% route /api/v1");
  }

  // Bất biến MVP: không stocktake trong route/spec.
  const stocktakeOps = [...specOps].filter((key) => /stocktake/i.test(key));
  if (stocktakeOps.length) {
    failures.push(`Bất biến MVP bị vi phạm - OpenAPI có endpoint stocktake: ${stocktakeOps.join(", ")}`);
  }
  const stocktakeRoutes = routes.filter((route) => /stocktake/i.test(route.uri));
  if (stocktakeRoutes.length) {
    failures.push(
      `Bất biến MVP bị vi phạm - route table có endpoint stocktake: ${stocktakeRoutes.map((r) => r.uri).join(", ")}`,
    );
  }
}

// --- 4. README phải khớp .env.example ---------------------------------------
if (existsSync(join(root, "README.md")) && existsSync(join(root, ".env.example"))) {
  const readme = read("README.md");
  const env = read(".env.example");

  if (/About Laravel|laravel\.com\/docs\/contributions/.test(readme)) {
    failures.push("README.md vẫn là README mặc định của Laravel, không phải hướng dẫn build/run của dự án");
  }

  const envValue = (key) => env.match(new RegExp("^" + key + "=(.*)$", "m"))?.[1]?.trim() ?? null;
  const checks = [
    ["APP_URL", envValue("APP_URL")],
    ["DB_PORT", envValue("DB_PORT")],
    ["REDIS_PORT", envValue("REDIS_PORT")],
    ["DB_CONNECTION", envValue("DB_CONNECTION")],
  ];
  for (const [key, value] of checks) {
    if (!value) continue;
    if (!readme.includes(value)) {
      failures.push(`README.md không nhắc tới giá trị ${key}=${value} của .env.example (rủi ro tài liệu lệch cấu hình)`);
    }
  }
}

// --- 5. Không rò secret trong tài liệu --------------------------------------
const SECRET_PATTERNS = [
  [/JWT_SECRET\s*=\s*["']?[A-Za-z0-9+/=]{16,}/, "JWT_SECRET có giá trị thật"],
  [/APP_KEY\s*=\s*base64:[A-Za-z0-9+/=]{20,}/, "APP_KEY có giá trị thật"],
  [/AWS_SECRET_ACCESS_KEY\s*=\s*["']?[A-Za-z0-9+/=]{16,}/, "AWS_SECRET_ACCESS_KEY có giá trị thật"],
];
for (const [path] of REQUIRED_DOCS) {
  if (!path.endsWith(".md") || !existsSync(join(root, path))) continue;
  const content = read(path);
  for (const [pattern, label] of SECRET_PATTERNS) {
    if (pattern.test(content)) failures.push(`${path}: ${label} - không được chép secret vào tài liệu`);
  }
}

// --- Kết quả ----------------------------------------------------------------
notes.forEach((note) => console.log("[wwhn-309] OK: " + note));

if (failures.length) {
  console.error("");
  failures.forEach((failure) => console.error("[wwhn-309] FAIL: " + failure));
  console.error("");
  console.error("[wwhn-309] Gói bàn giao WWHN-309 CHƯA đủ điều kiện ký.");
  process.exit(1);
}

console.log("[wwhn-309] PASS: gói bàn giao kiến trúc/API/vận hành đầy đủ và khớp source code.");
