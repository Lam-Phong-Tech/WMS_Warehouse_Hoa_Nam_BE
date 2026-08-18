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

// Tài liệu tồn tại trên đĩa nhưng bị .gitignore = reviewer clone đúng closure SHA
// KHÔNG thấy nó. Đây là lỗi thật đã xảy ra: `/docs/` từng bị ignore toàn bộ rồi
// whitelist từng file, nên SRS baseline, decision record và runbook mà README trỏ
// tới đều nằm ngoài git trong khi vẫn "tồn tại" trên máy người viết.
for (const [path, label] of REQUIRED_DOCS) {
  if (!existsSync(join(root, path))) continue;
  try {
    execFileSync("git", ["check-ignore", "-q", "--", path], { cwd: root, stdio: "pipe" });
    failures.push(`${label} (${path}) đang bị .gitignore loại trừ - reviewer clone theo SHA sẽ không thấy tài liệu này`);
  } catch (error) {
    // exit code 1 = không bị ignore (trường hợp mong muốn). Exit code khác = git lỗi.
    if (error.status !== 1) notes.push(`Không kiểm tra được git check-ignore cho ${path}`);
  }
}

// --- 2. Generator phải khớp source -----------------------------------------
for (const [script, label] of [
  ["scripts/generate-error-code-catalog.mjs", "catalog error code"],
  ["scripts/generate-endpoint-permission-matrix.mjs", "ma trận endpoint/permission"],
  ["scripts/generate-api-contract-metrics.mjs", "số liệu contract HN-BE-API-004"],
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
  //
  // WWHN309_ARTISAN cho phép chỉ định cách gọi artisan khi runtime PHP không nằm
  // cùng chỗ với source (máy dev dùng Docker: vendor/ là volume trong container).
  // Đây KHÔNG phải cửa hậu làm yếu cổng: gate vẫn phải thực thi artisan thật rồi
  // đọc route table thật; chỉ khác ở chỗ gọi lệnh nào.
  //   WWHN309_ARTISAN="docker exec <container> php artisan" node scripts/verify-...
  const artisanCommand = (process.env.WWHN309_ARTISAN ?? "php artisan").trim().split(/\s+/);
  let routes = [];
  try {
    const raw = execFileSync(artisanCommand[0], [...artisanCommand.slice(1), "route:list", "--json"], {
      cwd: root,
      encoding: "utf8",
      maxBuffer: 32 * 1024 * 1024,
    });
    routes = JSON.parse(raw.slice(raw.indexOf("[")));
  } catch (error) {
    failures.push(
      `Không chạy được \`${artisanCommand.join(" ")} route:list --json\`: ` + String(error.message).split("\n")[0],
    );
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

// --- 6. Số liệu trong tài liệu phải khớp HN-BE-API-004 -----------------------
//
// DEV309-P1-02: gói bàn giao từng ghi "2267/2267 response" ở HN-BE-API-001 nhưng
// "2268/2268" ở WWHN-309.md, trong khi spec thật đã sang con số khác. Nguyên nhân
// gốc không phải cẩu thả mà là kiến trúc tài liệu: con số sống dưới dạng văn xuôi,
// không ai kiểm được. Từ đây mọi phát biểu số liệu trong tài liệu bàn giao đều bị
// đối chiếu ngược với artifact sinh tự động HN-BE-API-004.
//
// Dòng nào cố ý mô tả trạng thái LỊCH SỬ (ví dụ finding "trước khi sửa còn
// 2265/2267 response thiếu content") phải đánh dấu `[historical]` ngay trên dòng
// đó - đánh dấu tường minh, grep được, không phải ngoại lệ ngầm.
const METRICS_PATH = "docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.json";
if (existsSync(join(root, METRICS_PATH))) {
  const metrics = JSON.parse(read(METRICS_PATH));

  // [regex, [khoá metric cho từng capture group], nhãn]
  const CLAIM_PATTERNS = [
    [/(\d+)\s*path\s*\/\s*(\d+)\s*operation/gi, ["openapi_paths", "openapi_operations"], "path/operation OpenAPI"],
    [/(\d+)\s*\/\s*(\d+)\s*response/gi, ["openapi_responses_with_schema", "openapi_responses"], "response có schema"],
    [/route\s*\/api\/v1\s*\((\d+)\s*\/\s*(\d+)\)/gi, ["openapi_operations", "openapi_operations"], "độ phủ route"],
    [/(\d+)\s*operation\s*khai\s*`?Idempotency-Key`?/gi, ["idempotency_key_operations"], "operation Idempotency-Key"],
    [/`?Idempotency-Key`?\s*\((\d+)\s*operation/gi, ["idempotency_key_operations"], "operation Idempotency-Key"],
    [/(\d+)\s*operation\s*khai\s*`?If-Match`?/gi, ["if_match_operations"], "operation If-Match"],
    [/`?If-Match`?\s*\((\d+)\s*operation\)/gi, ["if_match_operations"], "operation If-Match"],
    [
      /\((\d+)\s*bắt buộc,\s*(\d+)\s*tuỳ chọn/gi,
      ["idempotency_key_required", "idempotency_key_optional"],
      "Idempotency-Key bắt buộc/tuỳ chọn",
    ],
    [/(\d+)\s*error code/gi, ["error_codes"], "số error code"],
    [
      /(\d+)\s*operation\s*[x×]\s*(\d+)\s*permission/gi,
      ["matrix_operations", "matrix_permissions"],
      "ma trận operation × permission",
    ],
    [/endpoint public đúng\s*(\d+)/gi, ["public_operations"], "số endpoint public"],
  ];

  const SCANNED_DOCS = [
    "docs/docs_task_jira/WWHN-309.md",
    "docs/docs_task_jira/WWHN-309_SIGNOFF_COMMENTS.md",
    "docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md",
    "docs/architecture/HN-BE-ARCH-001_BACKEND_ARCHITECTURE.md",
    "docs/ops/HN-BE-OPS-001_OPERATIONS_RUNBOOK.md",
    "README.md",
  ];

  let claimsChecked = 0;
  for (const docPath of SCANNED_DOCS) {
    if (!existsSync(join(root, docPath))) continue;
    const lines = read(docPath).split("\n");
    lines.forEach((line, index) => {
      if (line.includes("[historical]")) return;
      for (const [pattern, keys, label] of CLAIM_PATTERNS) {
        pattern.lastIndex = 0;
        let match;
        while ((match = pattern.exec(line)) !== null) {
          keys.forEach((key, position) => {
            const claimed = Number(match[position + 1]);
            if (!Number.isFinite(claimed)) return;
            claimsChecked += 1;
            if (claimed !== metrics[key]) {
              failures.push(
                `${docPath}:${index + 1} ghi ${label} = ${claimed} nhưng ${METRICS_PATH}.${key} = ${metrics[key]}` +
                  ` (sửa tài liệu, hoặc đánh dấu \`[historical]\` nếu đang mô tả trạng thái cũ)`,
              );
            }
          });
        }
      }
    });
  }
  notes.push(`số liệu tài liệu bàn giao: ${claimsChecked} phát biểu khớp HN-BE-API-004`);
} else {
  failures.push(`Thiếu ${METRICS_PATH} - chạy node scripts/generate-api-contract-metrics.mjs`);
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
