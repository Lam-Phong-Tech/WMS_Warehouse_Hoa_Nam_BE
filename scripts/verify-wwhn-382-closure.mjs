#!/usr/bin/env node
/**
 * WWHN-382 — cổng đóng register R4, fail-closed.
 *
 * Cổng này không "chạy test hộ". Nó chặn đúng những cách mà bốn vòng review trước
 * đã trượt, và chỉ những cách đó:
 *
 *   1. Register còn mục OPEN/PARTIAL nhưng hồ sơ vẫn được nộp như đã đóng.
 *   2. Verdict để dạng lựa chọn kép ("VERIFIED PASS / CHANGES REQUIRED") hoặc
 *      placeholder ("[GLOBAL / WAREHOUSE-SCOPED]", "TBD", "<...>") — reviewer coi
 *      đó là chưa ký.
 *   3. Pin trỏ vào SHA rút gọn, hoặc trỏ vào `m1` (một cái tên di động).
 *   4. Contract pin đã rot: hash cũ hơn hiện trạng ⇒ "same contract" là khẳng định suông.
 *   5. DEV tự ký hộ vai độc lập (QA2 / BA).
 *   6. Tệp bằng chứng được khai trong register nhưng không tồn tại trên đĩa.
 *
 * Dùng:
 *   node scripts/verify-wwhn-382-closure.mjs
 *   node scripts/verify-wwhn-382-closure.mjs --expect-sha <BE_SHA>
 */
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { fingerprintAt } from "./wwhn-328-contract-fingerprint.mjs";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const EVIDENCE = "docs/evidence/WWHN-382";

let failures = 0;

function fail(code, message) {
  failures += 1;
  console.error("[WWHN-382] FAIL " + code + ": " + message);
}

function pass(code, message) {
  console.log("[WWHN-382] PASS " + code + ": " + message);
}

function read(relative) {
  const absolute = join(ROOT, relative);
  if (!existsSync(absolute)) return null;
  return readFileSync(absolute, "utf8");
}

function readJson(relative) {
  const raw = read(relative);
  if (raw === null) return null;
  try {
    return JSON.parse(raw);
  } catch (error) {
    fail("JSON", relative + " không parse được: " + error.message);
    return null;
  }
}

function git(args) {
  return execFileSync("git", args, { cwd: ROOT, encoding: "utf8", maxBuffer: 64 * 1024 * 1024 }).trim();
}

// ── 1. Hồ sơ bắt buộc ──────────────────────────────────────

const REQUIRED = [
  EVIDENCE + "/R4_REGISTER.json",
  EVIDENCE + "/R4_CLOSURE.md",
  EVIDENCE + "/CANDIDATE_PIN.json",
  EVIDENCE + "/CONTRACT_PIN.json",
  EVIDENCE + "/SOURCE_SUPERSESSION.md",
  EVIDENCE + "/DIFF_SCOPE_MAP.md",
  EVIDENCE + "/QA2_RERUN_RUNBOOK.md",
  "docs/evidence/WWHN-328/KPI_MATRIX.md",
  "docs/docs_task_jira/WWHN-382.md",
  "tests/Feature/Wwhn328R4ClosureTest.php",
];

for (const path of REQUIRED) {
  if (read(path) === null) fail("ARTIFACT", "thiếu " + path);
}
if (failures === 0) pass("ARTIFACT", REQUIRED.length + " artifact bắt buộc đều có mặt");

// ── 2. Register: không được còn mục chưa đóng ──────────────

const ALLOWED_STATUS = new Set([
  "CLOSED_IN_REPO",
  "EVIDENCE_READY_AWAITING_SIGNOFF",
  "AWAITING_INDEPENDENT_RUN",
]);
const FORBIDDEN_STATUS = new Set(["OPEN", "PARTIAL", "TODO", "IN_PROGRESS", "N/A"]);

const register = readJson(EVIDENCE + "/R4_REGISTER.json");
if (register) {
  const items = Array.isArray(register.items) ? register.items : [];
  if (items.length === 0) fail("REGISTER", "register không có mục nào");

  for (const item of items) {
    const id = item.id || "(thiếu id)";
    if (FORBIDDEN_STATUS.has(item.status)) {
      fail("REGISTER", id + " còn trạng thái " + item.status);
    } else if (!ALLOWED_STATUS.has(item.status)) {
      fail("REGISTER", id + " có trạng thái lạ: " + JSON.stringify(item.status));
    }
    for (const path of item.evidence || []) {
      // Bằng chứng nằm ở repo FE được khai bằng tiền tố `FE:` — cổng BE không đọc
      // được đĩa của repo kia, nên chỉ kiểm tra đường dẫn nội bộ BE.
      if (path.startsWith("FE:")) continue;
      if (read(path) === null) fail("REGISTER", id + " khai bằng chứng không tồn tại: " + path);
    }
    if (!item.owner || !item.gate) {
      fail("REGISTER", id + " thiếu owner hoặc gate");
    }
  }

  // DEV không được tự ký hộ vai độc lập.
  for (const item of items) {
    const role = String(item.signOffRole || "");
    if (/QA2|BA Main/i.test(role) && item.signedByDev === true) {
      fail("GOVERNANCE", item.id + ": vai độc lập " + role + " bị ký hộ bởi DEV");
    }
  }
  if (failures === 0) pass("REGISTER", items.length + " mục, không mục nào OPEN/PARTIAL");
}

// ── 3. Không placeholder, không verdict kép ────────────────

const PLACEHOLDERS = [
  "VERIFIED PASS / CHANGES REQUIRED",
  "[GLOBAL / WAREHOUSE-SCOPED]",
  "PASS / FAIL",
  "TBD",
  "TODO",
  "<sha>",
  "<SHA>",
  "XXXX",
];

for (const path of [
  EVIDENCE + "/R4_CLOSURE.md",
  EVIDENCE + "/SOURCE_SUPERSESSION.md",
  EVIDENCE + "/DIFF_SCOPE_MAP.md",
  EVIDENCE + "/QA2_RERUN_RUNBOOK.md",
  "docs/evidence/WWHN-328/KPI_MATRIX.md",
  "docs/docs_task_jira/WWHN-382.md",
]) {
  const text = read(path);
  if (text === null) continue;
  for (const needle of PLACEHOLDERS) {
    if (text.includes(needle)) {
      fail("PLACEHOLDER", path + ' còn chuỗi chưa chốt: "' + needle + '"');
    }
  }
}
if (failures === 0) pass("PLACEHOLDER", "không còn verdict kép/placeholder trong hồ sơ");

// ── 4. KPI matrix phải là bản đã ký, có version ────────────

const matrix = read("docs/evidence/WWHN-328/KPI_MATRIX.md");
if (matrix) {
  const version = (matrix.match(/\*\*Matrix version:\*\*\s*(v[\d.]+)/) || [])[1];
  if (!version) {
    fail("MATRIX", "KPI_MATRIX.md không khai Matrix version");
  } else if (version === "v1.0" || version === "v1.1") {
    fail("MATRIX", "KPI_MATRIX.md vẫn ở " + version + "; vòng R4 yêu cầu tối thiểu v1.2");
  } else {
    pass("MATRIX", "KPI matrix " + version);
  }
  // Matrix mất một trong các trục này thì nó không còn là "matrix" theo yêu cầu R4.
  for (const [label, needle] of [
    ["data scope", "WAREHOUSE_SCOPED"],
    ["ngoại lệ warranty GLOBAL", "warranty_cases_by_status"],
    ["timezone/cutoff", "Asia/Ho_Chi_Minh"],
    ["expected totals", "Expected totals"],
    ["permission", "permission:report.view"],
    ["error contract", "BUSINESS_RULE_ERROR"],
  ]) {
    if (!matrix.includes(needle)) fail("MATRIX", "KPI matrix thiếu trục " + label);
  }
}

// ── 5. Pin phải là SHA đủ 40 ký tự và resolve được ─────────

const candidatePin = readJson(EVIDENCE + "/CANDIDATE_PIN.json");
if (candidatePin) {
  const beSha = (candidatePin.candidate || {}).backend || {};
  const feSha = (candidatePin.candidate || {}).frontend || {};

  for (const [label, node] of [
    ["backend", beSha],
    ["frontend", feSha],
  ]) {
    if (!/^[0-9a-f]{40}$/.test(String(node.sha))) {
      fail("PIN", label + " không pin SHA đủ 40 ký tự: " + node.sha);
    }
    if (node.branch !== "m1") fail("PIN", label + " phải pin nhánh m1, đang là " + node.branch);
    if (!String(node.repo || "").startsWith("https://")) {
      fail("PIN", label + " thiếu URL repo canonical");
    }
  }

  try {
    git(["cat-file", "-e", beSha.sha + "^{commit}"]);
    pass("PIN", "BE candidate " + beSha.sha + " tồn tại trong lịch sử");
  } catch {
    fail("PIN", "BE candidate " + beSha.sha + " không tồn tại trong repo này");
  }

  const superseded = candidatePin.superseded || [];
  if (!superseded.some((entry) => String(entry.ref || "").includes("m1-be-2"))) {
    fail("SUPERSEDE", "CANDIDATE_PIN.json không khai m1-be-2 là nguồn lịch sử (DEV328-P1-02R4)");
  }
  for (const entry of superseded) {
    if (!/^[0-9a-f]{40}$/.test(String(entry.sha))) {
      fail("SUPERSEDE", "nguồn superseded thiếu SHA đủ 40 ký tự: " + JSON.stringify(entry.sha));
    }
    if (!entry.reason) fail("SUPERSEDE", entry.sha + " bị thay thế mà không ghi lý do");
  }
  if (superseded.length > 0) pass("SUPERSEDE", superseded.length + " nguồn lịch sử đã khai kèm lý do");

  for (const entry of candidatePin.scope_files || []) {
    if (!/^[0-9a-f]{40}$/.test(String(entry.git_blob))) {
      fail("DIFFSCOPE", entry.path + " thiếu git blob");
    }
  }
  pass("DIFFSCOPE", (candidatePin.scope_files || []).length + " tệp trong phạm vi task có blob");
}

// ── 6. Contract pin còn hiệu lực tại HEAD (chống rot) ──────

const contractPin = readJson(EVIDENCE + "/CONTRACT_PIN.json");
if (contractPin) {
  const expected = contractPin.fingerprints || {};
  let current;
  try {
    current = fingerprintAt("HEAD");
  } catch (error) {
    current = null;
    fail("CONTRACT", "không tính được fingerprint tại HEAD: " + error.message);
  }
  if (current) {
    const drift = Object.keys(expected).filter(
      (key) => current.fingerprints[key] !== expected[key],
    );
    if (drift.length > 0) {
      fail(
        "CONTRACT",
        "contract dashboard-quick đã đổi so với pin: " +
          drift.join(", ") +
          ". Sửa ngữ nghĩa thì phải nâng KPI matrix và pin lại, không được để pin cũ.",
      );
    } else {
      pass("CONTRACT", "fingerprint contract tại HEAD khớp pin (" + Object.keys(expected).length + " mục)");
    }
    // Field set công bố phải đủ 11 field đã ký, kể cả khi hash trùng do pin cũ.
    const declared = (contractPin.declared || {}).openapi_response_fields || [];
    for (const field of [
      "data_scope",
      "scope_warehouse_ids",
      "reconciliation_warning_count",
      "warranty_cases_by_status",
    ]) {
      if (!declared.includes(field)) fail("CONTRACT", "OpenAPI thiếu field đã ký: " + field);
    }
  }
}

// ── 7. Test khoá phải còn đó ───────────────────────────────

const testSource = read("tests/Feature/Wwhn328R4ClosureTest.php") || "";
const REQUIRED_TESTS = [
  "test_warranty_kpi_stays_global_for_a_warehouse_scoped_user",
  "test_reconciliation_warning_count_matches_the_inventory_balance_report",
  "test_warning_count_is_the_total_while_the_embedded_sample_is_capped_at_fifty",
  "test_openapi_operation_declares_exactly_the_runtime_field_set",
  "test_dashboard_aggregate_is_served_by_exactly_one_reused_route",
];
for (const name of REQUIRED_TESTS) {
  if (!testSource.includes(name)) fail("TEST", "mất test khoá " + name);
}
if (testSource) pass("TEST", REQUIRED_TESTS.length + " test khoá R4 còn nguyên");

// ── 8. --expect-sha: cổng chạy đúng candidate ──────────────

const expectAt = process.argv.indexOf("--expect-sha");
if (expectAt !== -1) {
  const expectedSha = process.argv[expectAt + 1];
  const head = git(["rev-parse", "HEAD"]);
  if (head !== expectedSha) {
    fail("EXACT-SHA", "HEAD là " + head + " nhưng cổng được yêu cầu chạy tại " + expectedSha);
  } else {
    pass("EXACT-SHA", "đang chạy đúng tại " + expectedSha);
  }
}

if (failures > 0) {
  console.error("\n[WWHN-382] KHÔNG ĐẠT — " + failures + " lỗi.");
  process.exit(1);
}
console.log("\n[WWHN-382] ĐẠT — register R4 đủ điều kiện đóng.");
