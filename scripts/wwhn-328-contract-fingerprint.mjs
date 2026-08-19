#!/usr/bin/env node
/**
 * WWHN-328 (vòng R4) — dấu vân tay contract của GET /api/v1/reports/dashboard-quick.
 *
 * Vì sao tồn tại: các vòng review trước đều mở lại cùng một nhóm lỗi "evidence
 * stale / candidate moved". Nguyên nhân không phải người pin ẩu mà là chính cách
 * pin: pin theo **hash cả tệp** (`storage/api-docs/api-docs.json`, Postman
 * collection, `OperationalReportController.php`). Ba tệp đó là tệp dùng chung —
 * mỗi task khác chạm route/permission là chúng được sinh lại, hash đổi, và bằng
 * chứng của dashboard "hết hạn" dù dashboard không hề đổi.
 *
 * Cách sửa tận gốc: pin ở mức **đối tượng** thay vì mức tệp.
 *   - OpenAPI: chỉ lấy paths["/api/v1/reports/dashboard-quick"].
 *   - Postman: chỉ lấy các request trỏ tới dashboard-quick.
 *   - Controller: chỉ lấy khối mã Dashboard Quick + helper period(), rồi bỏ chú
 *     thích và chuẩn hoá khoảng trắng (đổi comment không làm đỏ gate, đổi ngữ
 *     nghĩa thì có).
 *   - Route/permission: đúng một dòng khai báo route.
 *
 * Nhờ vậy một pin đứng vững khi `m1` chạy tiếp, và đỏ đúng lúc ai đó sửa ngữ
 * nghĩa dashboard. `--range` biến điều đó thành bằng chứng kiểm chứng được:
 * bất biến trên toàn dải commit từ candidate tới HEAD.
 *
 * Dùng:
 *   node scripts/wwhn-328-contract-fingerprint.mjs --ref HEAD
 *   node scripts/wwhn-328-contract-fingerprint.mjs --ref <sha> --out docs/evidence/WWHN-328/CONTRACT_PIN.json
 *   node scripts/wwhn-328-contract-fingerprint.mjs --check docs/evidence/WWHN-328/CONTRACT_PIN.json --ref HEAD
 *   node scripts/wwhn-328-contract-fingerprint.mjs --check docs/evidence/WWHN-328/CONTRACT_PIN.json --range <sha>..HEAD
 */
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { mkdirSync, writeFileSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");

export const ENDPOINT = "/api/v1/reports/dashboard-quick";

const SOURCES = {
  openapi: "storage/api-docs/api-docs.json",
  postman: "docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json",
  controller:
    "Modules/Warehouse/app/Http/Controllers/OperationalReportController.php",
  routes: "Modules/Warehouse/routes/api.php",
  kpiMatrix: "docs/evidence/WWHN-328/KPI_MATRIX.md",
  test: "tests/Feature/Wwhn328DashboardQuickContractTest.php",
};

function git(args) {
  return execFileSync("git", args, {
    cwd: ROOT,
    encoding: "utf8",
    maxBuffer: 256 * 1024 * 1024,
  });
}

function readAt(ref, path) {
  try {
    return git(["show", ref + ":" + path]);
  } catch {
    return null;
  }
}

function sha256(value) {
  return createHash("sha256").update(value, "utf8").digest("hex");
}

/** JSON canonical: khoá sắp xếp đệ quy để hash không phụ thuộc thứ tự serialise. */
function canonical(value) {
  if (Array.isArray(value)) return "[" + value.map(canonical).join(",") + "]";
  if (value && typeof value === "object") {
    return (
      "{" +
      Object.keys(value)
        .sort()
        .map((key) => JSON.stringify(key) + ":" + canonical(value[key]))
        .join(",") +
      "}"
    );
  }
  return JSON.stringify(value === undefined ? null : value);
}

/**
 * Bỏ chú thích PHP và gộp khoảng trắng. Mục tiêu là fingerprint phản ánh HÀNH VI:
 * WWHN-254 từng đổi tên `dashboardWarehouseScope` → `reportWarehouseScope` và viết
 * lại docblock; phần đổi tên phải làm đỏ (nó là ngữ nghĩa gọi hàm), phần docblock
 * thì không.
 */
function normalisePhp(source) {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, " ")
    .replace(/(^|\s)\/\/[^\n]*/g, "$1")
    .replace(/\s+/g, " ")
    .trim();
}

function sliceBetween(source, startNeedle, endNeedle, label) {
  if (source === null) throw new Error("Không đọc được nguồn cho " + label);
  const start = source.indexOf(startNeedle);
  if (start === -1)
    throw new Error('Không tìm thấy mốc mở "' + startNeedle + '" (' + label + ")");
  const end = source.indexOf(endNeedle, start);
  if (end === -1)
    throw new Error('Không tìm thấy mốc đóng "' + endNeedle + '" (' + label + ")");
  return source.slice(start, end);
}

/** Cắt trọn một method PHP theo dấu ngoặc nhọn cân bằng. */
function slicePhpMethod(source, signature, label) {
  if (source === null) throw new Error("Không đọc được nguồn cho " + label);
  const at = source.indexOf(signature);
  if (at === -1)
    throw new Error('Không tìm thấy method "' + signature + '" (' + label + ")");
  let depth = 0;
  let started = false;
  for (let i = at; i < source.length; i += 1) {
    if (source[i] === "{") {
      depth += 1;
      started = true;
    } else if (source[i] === "}") {
      depth -= 1;
      if (started && depth === 0) return source.slice(at, i + 1);
    }
  }
  throw new Error('Method "' + signature + '" không cân bằng ngoặc (' + label + ")");
}

function collectPostmanDashboardRequests(collection) {
  const found = [];
  const walk = (node) => {
    if (Array.isArray(node)) {
      node.forEach(walk);
      return;
    }
    if (!node || typeof node !== "object") return;
    const url = node.request && node.request.url;
    // Collection dùng dạng `path: ["api","v1","reports","dashboard-quick"]` chứ không
    // phải `raw`, nên nhận diện theo chuỗi path đã ghép — không phụ thuộc dạng URL.
    const joinedPath = Array.isArray(url && url.path)
      ? url.path.join("/")
      : typeof (url && url.raw) === "string"
        ? url.raw
        : "";
    if (joinedPath.includes("reports/dashboard-quick")) {
      found.push({
        name: node.name === undefined ? null : node.name,
        method: node.request.method === undefined ? null : node.request.method,
        path: joinedPath,
        query: url.query === undefined ? null : url.query,
        // Mô tả là nơi contract được viết ra chữ; đổi mô tả = đổi contract công bố.
        description:
          node.request.description === undefined
            ? null
            : node.request.description,
      });
    }
    if (node.item) walk(node.item);
  };
  walk(collection.item || []);
  return found.sort((a, b) => String(a.name).localeCompare(String(b.name)));
}

export function fingerprintAt(ref) {
  const openapiRaw = readAt(ref, SOURCES.openapi);
  if (openapiRaw === null) throw new Error("Thiếu " + SOURCES.openapi + " tại " + ref);
  const openapi = JSON.parse(openapiRaw);
  const operation = openapi.paths && openapi.paths[ENDPOINT];
  if (!operation) throw new Error("OpenAPI tại " + ref + " không có path " + ENDPOINT);

  const postmanRaw = readAt(ref, SOURCES.postman);
  if (postmanRaw === null) throw new Error("Thiếu Postman collection tại " + ref);
  const postmanRequests = collectPostmanDashboardRequests(JSON.parse(postmanRaw));
  if (postmanRequests.length === 0) {
    throw new Error("Postman tại " + ref + " không còn request dashboard-quick");
  }

  const controller = readAt(ref, SOURCES.controller);
  const dashboardBlock = sliceBetween(
    controller,
    "// ── Dashboard Quick",
    "//  PRIVATE BUILDERS",
    "controller",
  );
  const periodMethod = slicePhpMethod(
    controller,
    "private function period(Request $request",
    "controller",
  );

  const routes = readAt(ref, SOURCES.routes);
  if (routes === null) throw new Error("Thiếu " + SOURCES.routes + " tại " + ref);
  const routeLine = routes
    .split(/\r?\n/)
    .map((line) => line.trim())
    .find((line) => line.includes("reports/dashboard-quick"));
  if (!routeLine)
    throw new Error("routes/api.php tại " + ref + " không khai báo dashboard-quick");

  const matrix = readAt(ref, SOURCES.kpiMatrix);
  const matrixMatch = matrix
    ? matrix.match(/\*\*Matrix version:\*\*\s*(v[\d.]+)/)
    : null;

  const testSource = readAt(ref, SOURCES.test);

  const responseProps =
    ((((((operation.get || {}).responses || {})["200"] || {}).content || {})[
      "application/json"
    ] || {}).schema || {}).properties || {};

  return {
    endpoint: ENDPOINT,
    ref,
    commit: git(["rev-parse", ref]).trim(),
    fingerprints: {
      openapi_operation: sha256(canonical(operation)),
      postman_requests: sha256(canonical(postmanRequests)),
      controller_dashboard_block: sha256(normalisePhp(dashboardBlock)),
      controller_period_helper: sha256(normalisePhp(periodMethod)),
      route_permission_line: sha256(routeLine),
    },
    declared: {
      route_permission_line: routeLine,
      kpi_matrix_version: matrixMatch ? matrixMatch[1] : null,
      openapi_response_fields: Object.keys(
        (responseProps.data || {}).properties || {},
      ).sort(),
      openapi_parameters: ((operation.get || {}).parameters || [])
        .map((parameter) => parameter.name)
        .sort(),
      openapi_responses: Object.keys((operation.get || {}).responses || {}).sort(),
      postman_request_count: postmanRequests.length,
      contract_test_case_count:
        (testSource || "").split("public function test_").length - 1,
    },
    /**
     * Hash cả tệp giữ lại để đối chiếu lịch sử, nhưng KHÔNG dùng làm điều kiện
     * đạt/không đạt: chúng đổi mỗi khi task khác sinh lại tệp dùng chung.
     */
    whole_file_informational: {
      [SOURCES.openapi]: {
        git_blob: git(["rev-parse", ref + ":" + SOURCES.openapi]).trim(),
        sha256: sha256(openapiRaw),
      },
      [SOURCES.postman]: {
        git_blob: git(["rev-parse", ref + ":" + SOURCES.postman]).trim(),
        sha256: sha256(postmanRaw),
      },
      [SOURCES.controller]: {
        git_blob: git(["rev-parse", ref + ":" + SOURCES.controller]).trim(),
        sha256: sha256(controller),
      },
    },
  };
}

function compare(expected, actual, ref) {
  const problems = [];
  for (const [key, value] of Object.entries(expected.fingerprints)) {
    if (actual.fingerprints[key] !== value) {
      problems.push(
        key + ": pin=" + value + " nhưng tại " + ref + " là " + actual.fingerprints[key],
      );
    }
  }
  return problems;
}

/**
 * Commit XA NHẤT mà contract dashboard-quick vẫn giống hệt `ref`.
 *
 * Đây là câu trả lời cho "candidate moved": người review không phải tin một SHA khai
 * bằng tay, họ đọc được contract thành hình **từ lúc nào** và đã đứng yên qua bao nhiêu
 * commit. Đi lùi theo first-parent, dừng ngay khi fingerprint đổi.
 */
export function contractOrigin(ref, limit = 400) {
  const target = fingerprintAt(ref).fingerprints;
  const commits = git(["rev-list", "--first-parent", "--max-count=" + limit, ref])
    .trim()
    .split(/\r?\n/)
    .filter(Boolean);

  let origin = commits[0];
  let unchangedCommits = 0;
  for (const commit of commits) {
    let current;
    try {
      current = fingerprintAt(commit).fingerprints;
    } catch {
      break; // commit cũ chưa đủ nguồn (chưa có endpoint/collection) — dừng tại đây.
    }
    if (Object.keys(target).some((key) => current[key] !== target[key])) break;
    origin = commit;
    unchangedCommits += 1;
  }
  return { head: commits[0], origin, unchangedCommits };
}

function main() {
  const argv = process.argv.slice(2);
  const arg = (name) => {
    const at = argv.indexOf(name);
    return at === -1 ? null : argv[at + 1];
  };

  if (argv.includes("--origin")) {
    process.stdout.write(
      JSON.stringify(contractOrigin(arg("--ref") || "HEAD"), null, 2) + "\n",
    );
    return;
  }

  const checkPath = arg("--check");
  const range = arg("--range");
  const out = arg("--out");
  const ref = arg("--ref") || "HEAD";

  if (checkPath) {
    const pin = JSON.parse(readFileSync(join(ROOT, checkPath), "utf8"));
    const expected = pin.candidate || pin;
    const refs = range
      ? git(["rev-list", "--reverse", range]).trim().split(/\r?\n/).filter(Boolean)
      : [ref];
    // Dải rỗng nghĩa là candidate CHÍNH LÀ HEAD — vẫn phải đối chiếu một lượt.
    if (refs.length === 0) refs.push(ref);

    let failed = 0;
    for (const item of refs) {
      let problems;
      try {
        problems = compare(expected, fingerprintAt(item), item);
      } catch (error) {
        problems = ["không tính được fingerprint: " + error.message];
      }
      if (problems.length > 0) {
        failed += 1;
        console.error("[WWHN-328] DRIFT tại " + item + ":");
        problems.forEach((problem) => console.error("  - " + problem));
      }
    }
    if (failed > 0) {
      console.error(
        "[WWHN-328] FAIL: " +
          failed +
          "/" +
          refs.length +
          " commit lệch contract dashboard-quick.",
      );
      process.exit(1);
    }
    console.log(
      "[WWHN-328] PASS: contract dashboard-quick bất biến trên " +
        refs.length +
        " commit (" +
        refs[0] +
        ".." +
        refs[refs.length - 1] +
        ").",
    );
    return;
  }

  const result = fingerprintAt(ref);
  const json = JSON.stringify(result, null, 2) + "\n";
  if (out) {
    mkdirSync(dirname(join(ROOT, out)), { recursive: true });
    writeFileSync(join(ROOT, out), json, "utf8");
    console.log("[WWHN-328] Đã ghi fingerprint tại " + ref + " → " + out);
  } else {
    process.stdout.write(json);
  }
}

if (process.argv[1] && process.argv[1].endsWith("wwhn-328-contract-fingerprint.mjs")) {
  main();
}
