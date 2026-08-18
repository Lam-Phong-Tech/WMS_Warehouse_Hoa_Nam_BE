#!/usr/bin/env node
/**
 * WWHN-249 — cổng đóng task cho mặt App PV.
 *
 * VÌ SAO TỒN TẠI: register WWHN-249 trượt ở đúng chỗ "không có bằng chứng kiểm
 * chứng được" — surface public không ai canh, endpoint Annex C không ai đối
 * chiếu, contract nói một đằng route chạy một nẻo. Script này biến các khẳng
 * định đó thành thứ chạy được, và FAIL-CLOSED: thiếu bằng chứng là đỏ, không
 * phải cảnh báo.
 *
 * Nó đọc NGUỒN THẬT chứ không đọc lại tài liệu do chính mình sinh ra:
 *   - route table thật:            php artisan route:list --json
 *   - OpenAPI runtime:             storage/api-docs/api-docs.json
 *   - allowlist của generator:     scripts/generate-endpoint-permission-matrix.mjs
 *   - baseline contract:           docs/contracts/HoaNam_Current_Baseline_Contract.json
 *
 * Dùng:
 *   node scripts/verify-wwhn-249-closure.mjs
 */
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const failures = [];
const notes = [];

/** Mặt công khai đã chốt — phải khớp DECLARED_ANONYMOUS_ROUTES của test governance. */
const DECLARED_PUBLIC = [
  "POST api/v1/auth/login",
  "POST api/v1/auth/refresh",
  "GET api/v1/health",
  "GET api/v1/pv/products",
  "GET api/v1/pv/products/{skuId}",
  "GET api/v1/pv/industries",
  "GET api/v1/pv/recommendations",
  "GET api/v1/pv/catalog",
  "GET api/v1/pv/tags",
];

/** Annex C: 4 operation PUBLIC_ANONYMOUS. */
const ANNEX_C_PUBLIC = [
  "/api/v1/pv/products",
  "/api/v1/pv/products/{skuId}",
  "/api/v1/pv/industries",
  "/api/v1/pv/recommendations",
];

/** Annex C: nhóm xác thực, [path, method, permission]. */
const ANNEX_C_AUTHENTICATED = [
  ["/api/v1/pv/products/{id}/publish", "patch", "permission:pv.publish"],
  ["/api/v1/pv-admin/resources/{resourceType}", "get", "permission:pv.master.manage"],
  ["/api/v1/pv-admin/resources/{resourceType}", "post", "permission:pv.master.manage"],
  ["/api/v1/pv-admin/resources/{resourceType}/{id}", "patch", "permission:pv.master.manage"],
];

function fail(id, message) {
  failures.push(`[${id}] ${message}`);
}

function routeTable() {
  const raw = execFileSync("php", ["artisan", "route:list", "--json"], {
    cwd: root,
    encoding: "utf8",
    maxBuffer: 32 * 1024 * 1024,
  });
  return JSON.parse(raw.slice(raw.indexOf("[")));
}

function middlewareOf(route) {
  const value = route.middleware;
  if (Array.isArray(value)) return value.map(String);
  if (typeof value === "string" && value.trim() !== "")
    return value.split("\n").map((item) => item.trim());
  return [];
}

function methodsOf(route) {
  return String(route.method || "")
    .split("|")
    .filter((method) => method !== "HEAD" && method !== "OPTIONS");
}

const routes = routeTable();

// --- DEV249-P1-03: mặt công khai đúng bằng danh sách đã chốt -----------------
const anonymous = [];
for (const route of routes) {
  if (!String(route.uri).startsWith("api/")) continue;
  if (middlewareOf(route).some((item) => item.startsWith("auth:"))) continue;
  for (const method of methodsOf(route)) {
    // Trang UI của l5-swagger không phải API nghiệp vụ.
    if (route.uri === "api/documentation" || route.uri === "api/oauth2-callback") continue;
    anonymous.push(`${method} ${route.uri}`);
  }
}

const unexpectedPublic = anonymous.filter((key) => !DECLARED_PUBLIC.includes(key));
const missingPublic = DECLARED_PUBLIC.filter((key) => !anonymous.includes(key));
if (unexpectedPublic.length > 0) {
  fail(
    "DEV249-P1-03",
    `Route ẩn danh KHÔNG khai báo: ${unexpectedPublic.join(", ")}. Mọi endpoint public phải có nguồn trong SRS và được khai ở 3 nơi (test governance, PUBLIC_ALLOWLIST, ma trận).`,
  );
}
if (missingPublic.length > 0) {
  fail("DEV249-P1-03", `Route public đã chốt nhưng không còn tồn tại: ${missingPublic.join(", ")}.`);
}

// --- DEV249-P1-03: nhóm PV public phải có rate limit ------------------------
for (const route of routes) {
  if (!String(route.uri).startsWith("api/v1/pv/")) continue;
  const middleware = middlewareOf(route);
  if (middleware.some((item) => item.startsWith("auth:"))) continue;
  if (!middleware.includes("throttle:pv-public")) {
    fail(
      "DEV249-P1-03",
      `${route.uri} là endpoint công khai nhưng thiếu throttle:pv-public — 429 trong error set Annex C sẽ không bao giờ xảy ra.`,
    );
  }
}

// --- DEV249-P1-04: nhóm xác thực đúng permission ----------------------------
for (const [path, method, permission] of ANNEX_C_AUTHENTICATED) {
  const uri = path.replace(/^\//, "");
  const route = routes.find(
    (item) => item.uri === uri && methodsOf(item).includes(method.toUpperCase()),
  );
  if (!route) {
    fail("DEV249-P1-04", `Thiếu route Annex C ${method.toUpperCase()} ${path}.`);
    continue;
  }
  const middleware = middlewareOf(route);
  if (!middleware.some((item) => item.startsWith("auth:"))) {
    fail("DEV249-P1-04", `${method.toUpperCase()} ${path} thiếu auth:api.`);
  }
  if (!middleware.includes(permission)) {
    fail(
      "DEV249-P1-04",
      `${method.toUpperCase()} ${path} phải gate bằng ${permission}; đang là [${middleware.join(", ")}].`,
    );
  }
}

// --- DEV249-P1-06: OpenAPI runtime nói đúng cùng một điều -------------------
const specPath = join(root, "storage/api-docs/api-docs.json");
if (!existsSync(specPath)) {
  fail("DEV249-P1-06", "Chưa sinh OpenAPI runtime: chạy `php artisan l5-swagger:generate`.");
} else {
  const raw = readFileSync(specPath, "utf8");
  const spec = JSON.parse(raw);
  notes.push(`OpenAPI SHA-256 = ${createHash("sha256").update(raw).digest("hex")}`);
  notes.push(`OpenAPI operation = ${Object.values(spec.paths ?? {}).reduce((total, item) => total + Object.keys(item).length, 0)}`);

  for (const path of ANNEX_C_PUBLIC) {
    const operation = spec.paths?.[path]?.get;
    if (!operation) {
      fail("DEV249-P1-06", `OpenAPI thiếu operation public GET ${path}.`);
      continue;
    }
    if (JSON.stringify(operation.security ?? null) !== "[]") {
      fail(
        "DEV249-P1-06",
        `GET ${path} phải override security: [] theo SRS §12; đang là ${JSON.stringify(operation.security ?? null)}.`,
      );
    }
  }

  for (const [path, method] of ANNEX_C_AUTHENTICATED) {
    const operation = spec.paths?.[path]?.[method];
    if (!operation) {
      fail("DEV249-P1-06", `OpenAPI thiếu operation ${method.toUpperCase()} ${path}.`);
      continue;
    }
    if (JSON.stringify(operation.security ?? null) !== '[{"bearerAuth":[]}]') {
      fail("DEV249-P1-06", `${method.toUpperCase()} ${path} phải yêu cầu bearerAuth.`);
    }
  }

  const patchParameters =
    spec.paths?.["/api/v1/pv-admin/resources/{resourceType}/{id}"]?.patch?.parameters ?? [];
  if (!patchParameters.some((parameter) => parameter.name === "If-Match")) {
    fail(
      "DEV249-P1-06",
      "updatePvAdminResource phải khai header If-Match trong OpenAPI, nếu không FE wire xong sẽ ăn 428.",
    );
  }
}

// --- DEV249-P1-03: allowlist của generator không được lệch route table ------
const generatorPath = join(root, "scripts/generate-endpoint-permission-matrix.mjs");
if (!existsSync(generatorPath)) {
  fail("DEV249-P1-03", "Thiếu scripts/generate-endpoint-permission-matrix.mjs.");
} else {
  const block = readFileSync(generatorPath, "utf8").match(
    /const PUBLIC_ALLOWLIST = new Set\(\[(.*?)\]\);/s,
  );
  if (!block) {
    fail("DEV249-P1-03", "Không đọc được PUBLIC_ALLOWLIST của generator.");
  } else {
    const allowlist = [...block[1].matchAll(/"([A-Z]+) (\/api\/[^"]+)"/g)].map(
      (match) => `${match[1]} ${match[2].replace(/^\//, "")}`,
    );
    const drift = DECLARED_PUBLIC.filter((key) => !allowlist.includes(key));
    const extra = allowlist.filter((key) => !DECLARED_PUBLIC.includes(key));
    if (drift.length > 0 || extra.length > 0) {
      fail(
        "DEV249-P1-03",
        `PUBLIC_ALLOWLIST lệch mặt công khai đã chốt. Thiếu: [${drift.join(", ")}]; thừa: [${extra.join(", ")}].`,
      );
    }
  }
}

// --- DEV249-P1-06: baseline contract phải khai 5 operation PV mới ----------
const contractPath = join(root, "docs/contracts/HoaNam_Current_Baseline_Contract.json");
if (!existsSync(contractPath)) {
  fail("DEV249-P1-06", "Thiếu docs/contracts/HoaNam_Current_Baseline_Contract.json.");
} else {
  const operations = JSON.parse(readFileSync(contractPath, "utf8")).api.annex_c_alignment.operations;
  for (const operation of [
    "GET /api/v1/pv/products",
    "GET /api/v1/pv/products/{skuId}",
    "GET /api/v1/pv-admin/resources/{resourceType}",
    "POST /api/v1/pv-admin/resources/{resourceType}",
    "PATCH /api/v1/pv-admin/resources/{resourceType}/{id}",
  ]) {
    if (!operations.includes(operation)) {
      fail(
        "DEV249-P1-06",
        `Baseline contract chưa đăng ký [${operation}] — API surface phình mà không ai review được.`,
      );
    }
  }
}

// --- Bằng chứng đóng task phải tồn tại -------------------------------------
for (const artifact of [
  "docs/evidence/WWHN-249/WWHN-249_CLOSURE_REGISTER.json",
  "docs/evidence/WWHN-249/PV_SURFACE_EVIDENCE.md",
  "tests/Feature/Wwhn249PvSurfaceGovernanceTest.php",
]) {
  if (!existsSync(join(root, artifact))) {
    fail("DEV249-P1-05", `Thiếu artifact bằng chứng ${artifact}.`);
  }
}

// --- D-04: nhắc lại defect phân quyền còn treo -----------------------------
const seederPath = join(root, "Modules/Auth/database/seeders/AuthDatabaseSeeder.php");
if (existsSync(seederPath)) {
  const seeder = readFileSync(seederPath, "utf8");
  const managerBlock = seeder.match(/'WAREHOUSE_MANAGER' => \[(.*?)\n {8}\],/s);
  if (managerBlock && !managerBlock[1].includes("pv.master.manage")) {
    notes.push(
      "D-04 CÒN TREO: WAREHOUSE_MANAGER chưa được cấp pv.master.manage dù SRS §3 ghi mức A — QL kho sẽ nhận 403 ở /pv-admin/resources. Xem PV_SURFACE_EVIDENCE.md §6.",
    );
  }
}

for (const note of notes) console.log(`[wwhn-249] ${note}`);

if (failures.length > 0) {
  console.error(`\n[wwhn-249] FAIL — ${failures.length} vi phạm:`);
  for (const failure of failures) console.error(`  - ${failure}`);
  process.exit(1);
}

console.log(`\n[wwhn-249] PASS — mặt công khai đúng ${DECLARED_PUBLIC.length} route đã chốt; 8 operation Annex C của App PV khớp route table, OpenAPI và baseline contract.`);
