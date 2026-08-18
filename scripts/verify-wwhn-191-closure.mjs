#!/usr/bin/env node
/**
 * WWHN-191 — cổng đóng task cho output "Movement/balance hỗ trợ".
 *
 * VẤN ĐỀ GỐC (review 67/100): mọi bằng chứng của task này đều là **câu chữ trong
 * file .md** — "SHA này là ancestor", "OpenAPI hash này", "CI run kia cùng scope",
 * "positive test nằm ở task khác". Câu chữ không kiểm lại được: nhánh `m1` đi tiếp,
 * OpenAPI tái sinh mỗi lần boot, run CI thuộc ticket khác. Reviewer đọc lại thì
 * không cách nào phân biệt "đúng" với "đã mục".
 *
 * CÁCH SỬA: mỗi mục trong register P1/P2 phải trở thành một phép kiểm chạy được,
 * fail-closed. Script kiểm 5 nhóm:
 *
 *   DEV191-P1-01R  provenance: source SHA / merge SHA / candidate SHA có thật, còn là
 *                  tổ tiên của HEAD; nhánh bị supersede đã nằm trọn trong m1 (chứng
 *                  minh bằng merge-base, không phải bằng lời).
 *   DEV191-P1-02R  CI run phải gắn ĐÚNG candidate SHA. Run của ticket khác, hoặc run
 *                  ở SHA khác, bị từ chối — đúng lỗi "mượn evidence" reviewer đã bắt.
 *   DEV191-P1-03R  QA retest plan tồn tại và phủ đủ 8 kịch bản register yêu cầu.
 *   DEV191-P1-04R  Mọi endpoint khai trong CONTRACT_PIN.json phải có thật trong
 *                  OpenAPI với đúng operationId, và phải khai đủ 5 vai trò sign-off.
 *   DEV191-P2-01R  Các positive test bắt buộc phải tồn tại theo tên trong đúng file
 *                  test của task này.
 *
 * Cộng thêm PIN INVARIANT: artifact đã pin phải tracked, khớp SHA-256, và không
 * commit nào SAU candidate được chạm vào chúng — nếu chạm thì pin mục, phải re-pin.
 *
 * Dùng:
 *   node scripts/verify-wwhn-191-closure.mjs            # kiểm
 *   node scripts/verify-wwhn-191-closure.mjs --refresh  # ghi lại manifest tại HEAD
 */
import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const evidenceDir = "docs/evidence/WWHN-191";
const manifestPath = join(root, evidenceDir, "CANDIDATE_MANIFEST.json");
const contractPinPath = join(root, evidenceDir, "CONTRACT_PIN.json");
const qaPlanPath = join(root, evidenceDir, "QA_RETEST_PLAN.md");
const openApiPath = "storage/api-docs/api-docs.json";
const testFile = "tests/Feature/Wwhn191BalanceMovementProjectionTest.php";
const refresh = process.argv.includes("--refresh");
const failures = [];
const notes = [];

// Tập artifact bị khoá bởi pin. Thêm file mới vào phạm vi WWHN-191 thì thêm vào đây,
// nếu không nó nằm ngoài range invariant và có thể trôi mà không ai biết.
const PINNED_ARTIFACTS = [
  "Modules/Warehouse/app/Http/Controllers/InventoryBalanceController.php",
  "Modules/Warehouse/app/Http/Controllers/StockMovementController.php",
  "Modules/Warehouse/routes/api.php",
  testFile,
  evidenceDir + "/CONTRACT_PIN.json",
  evidenceDir + "/QA_RETEST_PLAN.md",
  "scripts/verify-wwhn-191-closure.mjs",
  ".github/workflows/wwhn-191-movement-balance-gate.yml",
];

// DEV191-P2-01R: positive test bắt buộc. Trước remediation này, file test chỉ có
// nhánh negative 404 cho trace-by-code và không có test nào cho /reversible.
const REQUIRED_TESTS = [
  "test_inventory_balances_list_reflects_ledger_after_post_receipt_and_matches",
  "test_inventory_balance_subledger_lists_item_instances_and_containers",
  "test_stock_movements_list_show_and_trace_source_reflect_posted_receipt",
  "test_stock_movement_trace_by_code_returns_404_for_unknown_physical_code",
  "test_stock_movement_trace_by_code_resolves_sku_code_to_posted_receipt_movement",
  "test_stock_movement_trace_by_code_resolves_container_code_of_received_box",
  "test_annex_c_inventory_trace_path_returns_same_payload_as_trace_by_code",
  "test_stock_movements_reversible_lists_posted_receipt_with_full_reversible_qty",
  "test_stock_movements_endpoints_require_inventory_permissions",
  "test_stock_movements_endpoint_requires_authentication",
  "test_stock_movement_is_append_only_and_rejects_update_and_delete",
];

// DEV191-P1-03R: 8 kịch bản register yêu cầu QA1 chạy trên independent fixed-build.
const REQUIRED_QA_SCENARIOS = [
  "Post Receipt",
  "Same-key replay",
  "Different-payload conflict",
  "Concurrent retry",
  "Timeout/retry",
  "Rollback / no half-post",
  "Permission / data scope",
  "DB reconciliation",
];

const REQUIRED_SIGNOFF_ROLES = ["BA", "Tech Lead", "FE1", "QA1", "DevOps"];

function git(args) {
  return execFileSync("git", args, { cwd: root, encoding: "utf8", maxBuffer: 32 * 1024 * 1024 }).trim();
}

function gitOk(args) {
  try {
    execFileSync("git", args, { cwd: root, stdio: "pipe" });
    return true;
  } catch {
    return false;
  }
}

function sha256(path) {
  return createHash("sha256").update(readFileSync(join(root, path))).digest("hex");
}

function isSha(value) {
  return /^[0-9a-f]{40}$/.test(String(value ?? ""));
}

if (refresh) {
  const missing = PINNED_ARTIFACTS.filter((path) => !existsSync(join(root, path)));
  if (missing.length) {
    missing.forEach((path) => console.error("[wwhn-191] ERROR: thiếu artifact " + path));
    process.exit(1);
  }
  // Candidate KHÔNG phải HEAD. `m1` là nhánh dùng chung, nhiều task commit xen kẽ
  // nhau; pin vào HEAD sẽ gán nhầm commit của task khác làm candidate của WWHN-191.
  // Candidate đúng = commit CUỐI CÙNG chạm vào tập artifact của task này.
  const head = git(["log", "-1", "--format=%H", "--", ...PINNED_ARTIFACTS]);
  if (!/^[0-9a-f]{40}$/.test(head)) {
    console.error("[wwhn-191] ERROR: chưa có commit nào chạm vào tập artifact — commit trước rồi refresh");
    process.exit(1);
  }
  const existing = existsSync(manifestPath) ? JSON.parse(readFileSync(manifestPath, "utf8")) : {};
  const manifest = {
    task: "WWHN-191",
    title: 'Candidate manifest — output "Movement/balance hỗ trợ" (projection ledger / hỗ trợ đối soát)',
    repository: "Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE",
    branch: git(["rev-parse", "--abbrev-ref", "HEAD"]),
    canonical_branch: existing.canonical_branch ?? "m1",
    provenance: existing.provenance ?? {},
    candidate_sha: head,
    candidate_subject: git(["log", "-1", "--format=%s", head]),
    candidate_committed_at: git(["log", "-1", "--format=%cI", head]),
    generator: "scripts/verify-wwhn-191-closure.mjs --refresh",
    pull_request: existing.pull_request ?? "N/A",
    independent_review: existing.independent_review ?? null,
    ci_run: existing.ci_run ?? null,
    artifacts: PINNED_ARTIFACTS.map((path) => ({ path, sha256: sha256(path) })),
  };
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + "\n", "utf8");
  console.log("[wwhn-191] Đã ghi manifest tại " + head);
  process.exit(0);
}

if (!existsSync(manifestPath)) {
  console.error("[wwhn-191] FAIL: thiếu " + evidenceDir + "/CANDIDATE_MANIFEST.json");
  console.error("[wwhn-191] Chạy: node scripts/verify-wwhn-191-closure.mjs --refresh");
  process.exit(1);
}
const manifest = JSON.parse(readFileSync(manifestPath, "utf8"));

// ── DEV191-P1-01R — provenance ────────────────────────────────────────────────
const provenance = manifest.provenance ?? {};
const candidate = String(manifest.candidate_sha ?? "");

for (const [key, label] of [
  ["source_fix_sha", "commit fix gốc của task"],
  ["absorbing_merge_sha", "merge hấp thụ nhánh song song"],
]) {
  const value = provenance[key];
  if (!isSha(value)) {
    failures.push("P1-01R: provenance." + key + " phải là SHA 40 ký tự (" + label + "), đang là " + JSON.stringify(value));
    continue;
  }
  if (!gitOk(["cat-file", "-e", value + "^{commit}"])) {
    failures.push("P1-01R: provenance." + key + " = " + value + " không tồn tại trong repository");
  } else if (!gitOk(["merge-base", "--is-ancestor", value, "HEAD"])) {
    failures.push(
      "P1-01R: provenance." + key + " = " + value.slice(0, 12) +
        " không còn là tổ tiên của HEAD — lịch sử đã bị viết lại, phải re-pin",
    );
  } else {
    notes.push("P1-01R: " + key + " " + value.slice(0, 12) + " là tổ tiên hợp lệ (" + label + ")");
  }
}

// Supersession phải chứng minh được: tip của nhánh bị supersede nằm trọn trong m1.
const superseded = provenance.superseded_branch;
if (!superseded || !superseded.name || !isSha(superseded.tip_sha)) {
  failures.push(
    "P1-01R: phải khai provenance.superseded_branch { name, tip_sha, statement } — " +
      "reviewer đang coi m1 và m1-be-2 là hai nguồn còn tách biệt",
  );
} else if (!gitOk(["cat-file", "-e", superseded.tip_sha + "^{commit}"])) {
  failures.push("P1-01R: tip_sha " + superseded.tip_sha + " của " + superseded.name + " không tồn tại trong repository");
} else if (!gitOk(["merge-base", "--is-ancestor", superseded.tip_sha, "HEAD"])) {
  failures.push(
    "P1-01R: " + superseded.name + "@" + superseded.tip_sha.slice(0, 12) +
      " KHÔNG nằm trong HEAD — supersession chưa thành lập, không được khai m1 là canonical duy nhất",
  );
} else {
  notes.push(
    "P1-01R: supersession thành lập — " + superseded.name + "@" + superseded.tip_sha.slice(0, 12) +
      " đã nằm trọn trong " + (manifest.canonical_branch ?? "m1"),
  );
}

if (!isSha(candidate)) {
  failures.push("P1-01R: candidate_sha không phải SHA đầy đủ 40 ký tự: " + JSON.stringify(manifest.candidate_sha));
} else if (!gitOk(["cat-file", "-e", candidate + "^{commit}"])) {
  failures.push("P1-01R: candidate_sha " + candidate + " không tồn tại trong repository");
} else if (!gitOk(["merge-base", "--is-ancestor", candidate, "HEAD"])) {
  failures.push("P1-01R: candidate_sha " + candidate.slice(0, 12) + " không còn là tổ tiên của HEAD — phải re-pin");
} else {
  notes.push("P1-01R: candidate " + candidate.slice(0, 12) + " vẫn là tổ tiên của HEAD");
}

if (manifest.pull_request === undefined || manifest.pull_request === null || manifest.pull_request === "") {
  failures.push("P1-01R: manifest phải khai pull_request (URL PR hoặc 'N/A' kèm independent_review)");
} else if (manifest.pull_request === "N/A") {
  // Không có PR thì bắt buộc phải nói rõ ai review, review cái gì, ở đâu — kể cả khi
  // review chưa xảy ra. Cấm bỏ trống, vì bỏ trống chính là handover tự ký.
  const review = manifest.independent_review;
  if (!review || !review.reviewer || !review.scope || !review.status) {
    failures.push(
      "P1-01R: pull_request = N/A thì bắt buộc khai independent_review { reviewer, scope, status, recorded_at } — " +
        "status = 'PENDING' cũng được, nhưng không được bỏ trống",
    );
  } else if (review.status !== "APPROVED") {
    notes.push(
      "P1-01R: independent_review đang " + review.status + " — reviewer " + review.reviewer +
        " (" + review.scope + "). Chưa đủ để đóng task, nhưng đã có owner rõ ràng.",
    );
  } else {
    notes.push("P1-01R: independent_review APPROVED bởi " + review.reviewer);
  }
}

// ── PIN INVARIANT ─────────────────────────────────────────────────────────────
const artifacts = Array.isArray(manifest.artifacts) ? manifest.artifacts : [];
const declaredPaths = new Set(artifacts.map((artifact) => artifact.path));
for (const path of PINNED_ARTIFACTS) {
  if (!declaredPaths.has(path)) failures.push("PIN: manifest thiếu artifact bắt buộc " + path);
}
for (const artifact of artifacts) {
  const path = artifact.path;
  if (!existsSync(join(root, path))) {
    failures.push("PIN: artifact đã pin nhưng không còn tồn tại: " + path);
    continue;
  }
  if (!gitOk(["ls-files", "--error-unmatch", "--", path])) {
    failures.push("PIN: artifact chưa được git tracked (reviewer clone theo SHA sẽ không thấy): " + path);
  }
  const actual = sha256(path);
  if (actual !== artifact.sha256) {
    failures.push(
      "PIN: artifact lệch nội dung so với pin: " + path + "\n    pinned  " + artifact.sha256 + "\n    current " + actual,
    );
  }
}
if (isSha(candidate)) {
  const drifted = git(["log", "--oneline", candidate + "..HEAD", "--", ...PINNED_ARTIFACTS]);
  if (drifted) {
    failures.push(
      "PIN: có commit SAU candidate chạm vào artifact đã pin — pin mục, phải re-pin và chạy lại evidence:\n    " +
        drifted.split("\n").join("\n    "),
    );
  } else {
    notes.push("PIN: range invariant sạch trên " + PINNED_ARTIFACTS.length + " artifact");
  }
}

// ── DEV191-P1-02R — CI run gắn đúng candidate SHA ─────────────────────────────
const ciRun = manifest.ci_run;
const insideCi = process.env.GITHUB_ACTIONS === "true";
if (insideCi) {
  // Deadlock nếu không tách nhánh này: gate chạy TRONG chính CI run đang tạo ra
  // evidence, nên không thể đòi run đó đã tồn tại. Trong CI, phép kiểm tương đương
  // và chặt hơn là: run này phải đang chạy đúng trên candidate SHA đã pin.
  const runSha = String(process.env.GITHUB_SHA ?? "");
  if (runSha !== candidate) {
    failures.push(
      "P1-02R: CI run đang chạy tại " + runSha.slice(0, 12) + " nhưng candidate_sha đã pin là " +
        candidate.slice(0, 12) + " — pin đã mục, chạy `node scripts/verify-wwhn-191-closure.mjs --refresh` rồi commit lại",
    );
  } else {
    notes.push("P1-02R: CI run " + (process.env.GITHUB_RUN_ID ?? "?") + " đang chạy đúng tại candidate SHA");
  }
} else if (!ciRun) {
  failures.push(
    "P1-02R: chưa khai ci_run. Chạy gate riêng của task rồi ghi lại:\n" +
      "    gh workflow run wwhn-191-movement-balance-gate.yml --ref <candidate_sha>\n" +
      "    gh run list --workflow=wwhn-191-movement-balance-gate.yml --json databaseId,headSha,conclusion\n" +
      "    rồi điền manifest.ci_run = { run_id, workflow, head_sha, conclusion, url }",
  );
} else {
  if (String(ciRun.head_sha ?? "") !== candidate) {
    failures.push(
      "P1-02R: ci_run.head_sha (" + String(ciRun.head_sha ?? "-").slice(0, 12) + ") ≠ candidate_sha (" +
        candidate.slice(0, 12) + ") — đây đúng là lỗi 'mượn evidence từ SHA/ticket khác' mà review đã bắt",
    );
  }
  if (ciRun.conclusion !== "success") {
    failures.push("P1-02R: ci_run.conclusion phải là 'success', đang là " + JSON.stringify(ciRun.conclusion));
  }
  if (!ciRun.run_id || !ciRun.url) {
    failures.push("P1-02R: ci_run phải khai đủ run_id và url để reviewer mở lại log");
  }
  if (failures.every((failure) => !failure.startsWith("P1-02R"))) {
    notes.push("P1-02R: CI run " + ciRun.run_id + " xanh tại đúng candidate SHA");
  }
}

// ── DEV191-P1-03R — QA retest plan ────────────────────────────────────────────
if (!existsSync(qaPlanPath)) {
  failures.push("P1-03R: thiếu " + evidenceDir + "/QA_RETEST_PLAN.md");
} else {
  const plan = readFileSync(qaPlanPath, "utf8");
  const missingScenarios = REQUIRED_QA_SCENARIOS.filter((scenario) => !plan.includes(scenario));
  if (missingScenarios.length) {
    failures.push("P1-03R: QA retest plan thiếu kịch bản bắt buộc: " + missingScenarios.join(", "));
  } else {
    notes.push("P1-03R: QA retest plan phủ đủ " + REQUIRED_QA_SCENARIOS.length + " kịch bản register");
  }
}

// ── DEV191-P1-04R — contract pin ──────────────────────────────────────────────
if (!existsSync(contractPinPath)) {
  failures.push("P1-04R: thiếu " + evidenceDir + "/CONTRACT_PIN.json");
} else if (!existsSync(join(root, openApiPath))) {
  failures.push("P1-04R: thiếu " + openApiPath + " — chạy `php artisan l5-swagger:generate`");
} else {
  const pin = JSON.parse(readFileSync(contractPinPath, "utf8"));
  const openApi = JSON.parse(readFileSync(join(root, openApiPath), "utf8"));
  for (const endpoint of pin.endpoints ?? []) {
    const operation = openApi.paths?.[endpoint.path]?.[endpoint.method];
    if (!operation) {
      failures.push(
        "P1-04R: OpenAPI không có " + endpoint.method.toUpperCase() + " " + endpoint.path +
          " — contract pin trỏ vào endpoint không tồn tại",
      );
      continue;
    }
    if (operation.operationId !== endpoint.operation_id) {
      failures.push(
        "P1-04R: operationId lệch cho " + endpoint.path + ": pin '" + endpoint.operation_id +
          "' vs OpenAPI '" + operation.operationId + "'",
      );
    }
  }
  const roles = new Set((pin.role_signoff_required ?? []).map((entry) => entry.role));
  const missingRoles = REQUIRED_SIGNOFF_ROLES.filter((role) => !roles.has(role));
  if (missingRoles.length) {
    failures.push("P1-04R: contract pin thiếu vai trò sign-off: " + missingRoles.join(", "));
  }
  if (failures.every((failure) => !failure.startsWith("P1-04R"))) {
    notes.push(
      "P1-04R: " + (pin.endpoints ?? []).length + " endpoint khớp OpenAPI, đủ " +
        REQUIRED_SIGNOFF_ROLES.length + " vai trò sign-off",
    );
  }
}

// ── DEV191-P2-01R — positive test coverage ────────────────────────────────────
if (!existsSync(join(root, testFile))) {
  failures.push("P2-01R: thiếu " + testFile);
} else {
  const source = readFileSync(join(root, testFile), "utf8");
  const missingTests = REQUIRED_TESTS.filter((name) => !source.includes("function " + name + "("));
  if (missingTests.length) {
    failures.push("P2-01R: thiếu test bắt buộc trong " + testFile + ":\n    " + missingTests.join("\n    "));
  } else {
    notes.push(
      "P2-01R: đủ " + REQUIRED_TESTS.length +
        " test bắt buộc (gồm positive trace-by-code SKU/container, Annex C parity, positive /reversible)",
    );
  }
}

notes.forEach((note) => console.log("[wwhn-191] OK: " + note));
if (failures.length) {
  console.error("");
  failures.forEach((failure) => console.error("[wwhn-191] FAIL: " + failure));
  console.error("");
  console.error("[wwhn-191] Cổng đóng WWHN-191 CHƯA hợp lệ (" + failures.length + " mục).");
  process.exit(1);
}
console.log(
  "[wwhn-191] PASS: candidate " + candidate.slice(0, 12) +
    " — provenance, pin, CI, QA plan, contract và coverage đều hợp lệ.",
);
