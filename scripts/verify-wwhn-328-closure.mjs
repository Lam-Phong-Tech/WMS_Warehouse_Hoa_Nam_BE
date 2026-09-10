#!/usr/bin/env node
/**
 * WWHN-328 — cổng đóng vòng R4 cho `GET /api/v1/reports/dashboard-quick`.
 *
 * Cổng này tồn tại vì hồ sơ WWHN-328 đã bị trả lại ba vòng liên tiếp với cùng một nhóm
 * lý do: bằng chứng hết hạn, candidate trôi, verdict để dạng lựa chọn kép. Đó là những
 * lỗi **văn bản**, mà văn bản thì không có CI nào bắt — nên chúng quay lại. Cổng này
 * biến chính các điều kiện của register thành assert chạy được:
 *
 *   1. Đủ bộ artifact R4 (matrix, register, diff map, pin, giao thức QA2, index).
 *   2. Matrix đúng version đang được ký, và không còn dual verdict/placeholder.
 *   3. Mọi tên test mà matrix/register viện dẫn đều tồn tại thật trong bộ test.
 *   4. Candidate là SHA đầy đủ 40 ký tự, có thật, nằm trên `m1`, contract tại đó khớp
 *      pin, và bất biến trên toàn dải candidate..HEAD.
 *   5. Đúng một route aggregate (AC "tái sử dụng, không tạo route mới").
 *   6. Cặp SHA BE/FE xuất hiện nguyên văn trong hồ sơ để không ai phải suy đoán.
 *
 * Dùng:  node scripts/verify-wwhn-328-closure.mjs [--range-base <sha>]
 */
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { fingerprintAt } from "./wwhn-328-contract-fingerprint.mjs";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const EVIDENCE = "docs/evidence/WWHN-328";
const SIGNED_MATRIX_VERSION = "v1.2";

const problems = [];
const notes = [];
const fail = (message) => problems.push(message);
const ok = (message) => notes.push(message);

function read(relative) {
  const absolute = join(ROOT, relative);
  if (!existsSync(absolute)) {
    fail("Thiếu artifact bắt buộc: " + relative);
    return null;
  }
  return readFileSync(absolute, "utf8");
}

function git(args) {
  return execFileSync("git", args, { cwd: ROOT, encoding: "utf8" }).trim();
}

// ── 1. Bộ artifact ──────────────────────────────────────────
const files = {
  matrix: EVIDENCE + "/KPI_MATRIX.md",
  closure: EVIDENCE + "/R4_CLOSURE.md",
  diffMap: EVIDENCE + "/DIFF_MAP.md",
  index: EVIDENCE + "/EVIDENCE_INDEX.md",
  qa2: EVIDENCE + "/QA2_RERUN_PROTOCOL.md",
  pack: EVIDENCE + "/EVIDENCE_PACK.md",
  pin: EVIDENCE + "/CONTRACT_PIN.json",
  taskDoc: "docs/docs_task_jira/WWHN-328.md",
  contractTest: "tests/Feature/Wwhn328DashboardQuickContractTest.php",
  r4Test: "tests/Feature/Wwhn328R4ClosureTest.php",
  routes: "Modules/Warehouse/routes/api.php",
};
const source = Object.fromEntries(
  Object.entries(files).map(([key, path]) => [key, read(path)]),
);

// ── 2. Không còn chỗ trống / verdict kép ────────────────────
// Đây chính xác là thứ đã làm ba comment sign-off trước bị đọc là "chưa ký".
const FORBIDDEN = [
  { pattern: /__[A-Z0-9_]{4,}__/, why: "token thay thế chưa điền" },
  { pattern: /\bTBD\b|\bTODO\b|\bFIXME\b/, why: "chỗ trống chưa điền" },
  { pattern: /VERIFIED PASS\s*\/\s*CHANGES REQUIRED/, why: "verdict kép" },
  { pattern: /\[GLOBAL\s*\/\s*WAREHOUSE[- ]SCOPED\]/, why: "lựa chọn kép chưa chốt" },
  { pattern: /<[A-Z_]{3,}>/, why: "placeholder dạng <CHỮ HOA>" },
];
for (const key of ["matrix", "closure", "diffMap", "index", "qa2"]) {
  const text = source[key];
  if (text === null) continue;
  for (const { pattern, why } of FORBIDDEN) {
    const hit = text.match(pattern);
    if (hit) fail(files[key] + ": còn " + why + " — " + JSON.stringify(hit[0]));
  }
}

// ── 3. Version matrix và tên test được viện dẫn ─────────────
if (source.matrix) {
  const version = source.matrix.match(/\*\*Matrix version:\*\*\s*(v[\d.]+)/);
  if (!version) {
    fail(files.matrix + ": không khai Matrix version");
  } else if (version[1] !== SIGNED_MATRIX_VERSION) {
    fail(
      files.matrix +
        ": version " +
        version[1] +
        " khác version đang được ký (" +
        SIGNED_MATRIX_VERSION +
        "). Đổi matrix thì phải ký lại, không được đổi lặng lẽ.",
    );
  } else {
    ok("KPI matrix " + SIGNED_MATRIX_VERSION);
  }
}

const declaredTests = new Set(
  ((source.matrix || "").match(/test_[a-z0-9_]+/g) || []).concat(
    (source.closure || "").match(/test_[a-z0-9_]+/g) || [],
  ),
);
const contractCases =
  (source.contractTest || "").match(/public function (test_[a-z0-9_]+)/g) || [];
const r4Cases = (source.r4Test || "").match(/public function (test_[a-z0-9_]+)/g) || [];
const known = new Set(
  contractCases.concat(r4Cases).map((line) => line.replace("public function ", "")),
);
let missingTests = 0;
for (const name of declaredTests) {
  if (!known.has(name)) {
    missingTests += 1;
    fail(
      "Matrix/register viện dẫn test không tồn tại: " +
        name +
        " — văn bản đang mô tả một bảo chứng không có thật.",
    );
  }
}
if (declaredTests.size > 0 && missingTests === 0) {
  ok(declaredTests.size + " tên test được viện dẫn đều tồn tại trong bộ test");
}
if (r4Cases.length < 5) {
  fail("Wwhn328R4ClosureTest phải giữ đủ 5 case R4, đang có " + r4Cases.length);
}
if (contractCases.length < 17) {
  fail(
    "Wwhn328DashboardQuickContractTest phải giữ đủ 17 case đã pin, đang có " +
      contractCases.length,
  );
}

// ── 4. Candidate pin + bất biến trên dải ────────────────────
let pin = null;
if (source.pin) {
  try {
    pin = JSON.parse(source.pin);
  } catch (error) {
    fail(files.pin + ": không parse được JSON — " + error.message);
  }
}

if (pin) {
  const candidate = (pin.candidate || {}).commit;
  if (!/^[0-9a-f]{40}$/.test(candidate || "")) {
    fail(
      files.pin + ": candidate.commit phải là SHA đầy đủ 40 ký tự, đang là " + candidate,
    );
  } else {
    let branches = "";
    try {
      branches = git(["branch", "--contains", candidate, "--format=%(refname:short)"]);
    } catch {
      fail("Candidate " + candidate + " không tồn tại trong repo này.");
    }
    if (branches && !branches.split(/\r?\n/).includes("m1")) {
      fail("Candidate " + candidate + " không nằm trên nhánh m1 (đang: " + branches + ").");
    }

    const baseIndex = process.argv.indexOf("--range-base");
    const base = baseIndex === -1 ? candidate : process.argv[baseIndex + 1];
    let refs = [];
    try {
      refs = git(["rev-list", "--reverse", base + "..HEAD"])
        .split(/\r?\n/)
        .filter(Boolean);
    } catch {
      fail("Không liệt kê được dải " + base + "..HEAD");
    }
    // Dải rỗng nghĩa là candidate chính là HEAD — vẫn phải đối chiếu một lượt.
    refs.unshift(candidate);

    let drifted = 0;
    for (const ref of refs) {
      let actual;
      try {
        actual = fingerprintAt(ref).fingerprints;
      } catch (error) {
        fail("Không tính được fingerprint tại " + ref + ": " + error.message);
        drifted += 1;
        continue;
      }
      for (const [key, value] of Object.entries(pin.candidate.fingerprints || {})) {
        if (actual[key] !== value) {
          fail("DRIFT contract dashboard-quick tại " + ref + " — " + key);
          drifted += 1;
          break;
        }
      }
    }
    if (drifted === 0) {
      ok(
        "contract bất biến trên " +
          refs.length +
          " commit từ candidate tới HEAD (không phụ thuộc m1 chạy tiếp)",
      );
    }
  }

  for (const key of ["superseded", "frontendCandidate", "contractOrigin"]) {
    if (pin[key] === undefined) fail(files.pin + ": thiếu trường " + key);
  }
  if (Array.isArray(pin.superseded) && pin.superseded.length === 0) {
    fail(files.pin + ": superseded rỗng — phải khai rõ SHA nào đã bị thay thế.");
  }
  if (!/^[0-9a-f]{40}$/.test(((pin.frontendCandidate || {}).commit) || "")) {
    fail(files.pin + ": frontendCandidate.commit phải là SHA FE đầy đủ 40 ký tự.");
  }
  if (pin.kpiMatrixVersion !== SIGNED_MATRIX_VERSION) {
    fail(
      files.pin +
        ": kpiMatrixVersion phải là " +
        SIGNED_MATRIX_VERSION +
        ", đang là " +
        pin.kpiMatrixVersion,
    );
  }
}

// ── 5. Route aggregate = route gốc + amendment đã ghi ───────
// AC gốc: tái sử dụng đúng 1 route. dashboard-overview (b6d9a34) được ghi bù thành
// WWHN-328-AMD-001 trong AGGREGATE_ROUTE_AMENDMENTS.json; route dashboard ngoài tập đã
// pin vẫn đỏ ở đây.
if (source.routes) {
  const registerPath = EVIDENCE + "/AGGREGATE_ROUTE_AMENDMENTS.json";
  const registerText = read(registerPath);
  let register = null;
  if (registerText !== null) {
    try {
      register = JSON.parse(registerText);
    } catch (error) {
      fail(registerPath + ": không parse được JSON — " + error.message);
    }
  }
  const amendments = (register && register.amendments) || [];
  const pinnedUris = [register ? register.originalRoute : "GET|HEAD api/v1/reports/dashboard-quick"]
    .concat(amendments.map((amendment) => amendment.route))
    .map((key) => String(key).replace(/^[A-Z|]+ api\/v1\//, ""))
    .sort();
  const dashboardRoutes = source.routes
    .split(/\r?\n/)
    .filter((line) => line.includes("reports/dashboard"));
  const declaredUris = dashboardRoutes
    .map((line) => (line.match(/Route::get\('([^']*reports\/dashboard[^']*)'/) || [])[1])
    .filter(Boolean)
    .sort();
  const quickLine = dashboardRoutes.find((line) => line.includes("'reports/dashboard-quick'")) || "";
  const incomplete = amendments.filter(
    (amendment) => !Array.isArray(amendment.owners) || amendment.owners.length === 0 || !amendment.signOff,
  );
  if (declaredUris.length !== dashboardRoutes.length || JSON.stringify(declaredUris) !== JSON.stringify(pinnedUris)) {
    fail(
      "routes/api.php phải khai đúng tập route dashboard aggregate đã pin (route gốc + amendment trong " +
        registerPath +
        "), đang có " +
        JSON.stringify(declaredUris),
    );
  } else if (!quickLine.includes("permission:report.view")) {
    fail("Route dashboard-quick phải gate bằng permission:report.view.");
  } else if (incomplete.length > 0) {
    fail("Amendment route dashboard thiếu owners/signOff: " + incomplete.map((a) => a.id).join(", "));
  } else {
    ok(
      declaredUris.length +
        " route aggregate khớp tập đã pin (" +
        amendments.map((a) => a.id + " sign-off " + a.signOff).join(", ") +
        "); dashboard-quick gate permission:report.view",
    );
  }
}

// ── 6. Cặp SHA phải nằm nguyên văn trong hồ sơ ──────────────
if (pin && pin.candidate) {
  const be = pin.candidate.commit;
  const fe = (pin.frontendCandidate || {}).commit;
  for (const [label, text] of [
    ["R4_CLOSURE.md", source.closure],
    ["EVIDENCE_INDEX.md", source.index],
    ["QA2_RERUN_PROTOCOL.md", source.qa2],
  ]) {
    if (!text) continue;
    if (be && !text.includes(be)) fail(label + ": không nhắc SHA candidate BE đầy đủ.");
    if (fe && !text.includes(fe)) fail(label + ": không nhắc SHA candidate FE đầy đủ.");
  }
}

// ── Kết quả ─────────────────────────────────────────────────
if (problems.length > 0) {
  console.error("[WWHN-328] FAIL — " + problems.length + " điều kiện đóng chưa đạt:");
  problems.forEach((problem) => console.error("  x " + problem));
  process.exit(1);
}
notes.forEach((note) => console.log("  + " + note));
console.log("[WWHN-328] PASS — hồ sơ đóng R4 đầy đủ và nhất quán với mã nguồn.");
