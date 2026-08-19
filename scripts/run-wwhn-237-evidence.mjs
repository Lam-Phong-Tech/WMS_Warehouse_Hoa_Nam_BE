#!/usr/bin/env node
/**
 * WWHN-237 evidence runner — sinh bằng chứng cho `DEV237-P1-03` và `DEV237-P1-04`.
 *
 * Chạy thật từng lệnh gate, ghi raw log + exit code, rồi pin exact SHA của BE/FE và
 * digest baseline. QA1 dùng đúng file này để tái lập `WWHN-238`; không ai được chép
 * tay kết quả vào Jira mà không có log tương ứng ở đây.
 *
 *   node scripts/run-wwhn-237-evidence.mjs
 *
 * Lệnh nào thiếu công cụ (ví dụ không có PHP trên máy) được ghi `SKIPPED` kèm lý do
 * chứ không âm thầm bỏ qua — một gate vắng mặt phải nhìn thấy được.
 */
import { createHash } from "node:crypto";
import { execSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const FE_ROOT = resolve(ROOT, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE");
const OUT = join(ROOT, "docs/evidence/WWHN-237");
const LOGS = join(OUT, "logs");

mkdirSync(LOGS, { recursive: true });

const git = (args, cwd) => {
  try {
    return execSync(`git ${args}`, { cwd, encoding: "utf8" }).trim();
  } catch {
    return null;
  }
};

const sha256File = (abs) => (existsSync(abs) ? createHash("sha256").update(readFileSync(abs)).digest("hex") : null);

/** Cặp SHA đã đóng băng — nguồn định danh duy nhất, thay cho `rev-parse HEAD`. */
const PAIR_FILE = join(ROOT, "docs/evidence/WWHN-237/CANDIDATE_PAIR.json");
const frozenPair = existsSync(PAIR_FILE) ? JSON.parse(readFileSync(PAIR_FILE, "utf8")) : null;
const expectFlags = frozenPair
  ? ` --expect-be-sha=${frozenPair.pair.backend.commit_sha} --expect-fe-sha=${frozenPair.pair.frontend.commit_sha}`
  : "";

const COMMANDS = [
  {
    id: "be-candidate-pair",
    cwd: ROOT,
    command: "node scripts/build-wwhn237-candidate-pair.mjs --check",
    covers: "DEV237-P1-02R2 (cặp SHA đóng băng, giải lại khớp, m1-be-2 superseded)",
  },
  {
    id: "be-disposition-gate",
    cwd: ROOT,
    command: `node scripts/verify-wwhn237-variance-report-disposition.mjs --emit-lock${expectFlags}`,
    covers: "DEV237-P1-01R2, P1-02R2, P1-03R2, P1-05R2",
  },
  {
    id: "be-qa-matrix",
    cwd: ROOT,
    command: "node scripts/run-wwhn237-qa-matrix.mjs",
    covers: "DEV237-P1-04R2 (N1..N8, R1..R4 từng dòng + raw API/DB)",
  },
  {
    id: "be-regression-test",
    cwd: ROOT,
    command: 'php artisan test --filter="Wwhn237VarianceReportDeferredGateTest|Flow05InventoryControlAlignmentTest"',
    covers: "DEV237-P1-04R2 (chạy gộp, đối chiếu với ma trận từng dòng)",
  },
  {
    id: "be-sibling-disposition-gates",
    cwd: ROOT,
    command: "node scripts/verify-wwhn236-adjustment-disposition.mjs",
    covers: "không hồi quy disposition anh em (WWHN-236)",
  },
  {
    id: "fe-closure-gate",
    cwd: FE_ROOT,
    command: `node scripts/verify-wwhn-237-closure.mjs${expectFlags}`,
    covers: "DEV237-P1-02R2, DEV237-P1-05R2 (nửa FE)",
  },
  {
    id: "fe-guard-test",
    cwd: FE_ROOT,
    // Gọi thẳng entrypoint thay vì `npx vitest`: repo FE không có `node_modules/.bin`
    // (yarn PnP-style layout) nên npx đi tìm binary trên PATH rồi báo
    // "'vitest' is not recognized" — một FAIL giả làm hỏng chính bằng chứng.
    command: "node node_modules/vitest/vitest.mjs run src/test/wwhn-237-variance-report-guard.test.ts",
    covers: "DEV237-P1-04 (N8)",
  },
  {
    id: "fe-api-mapping",
    cwd: FE_ROOT,
    command: "npm run check:api-mapping",
    covers: "không hồi quy guard no-Stocktake của WWHN-296",
    // Lệnh này FAIL sẵn trước WWHN-237 và không thuộc phạm vi task: BE_CONTRACT_PIN.json
    // lệch swagger hiện hành do BE tiến thêm ở task khác. Bề mặt lệch không chứa
    // stocktake/variance. Ghi rõ owner để summary không đếm nó là hồi quy của task này,
    // nhưng raw log vẫn được giữ nguyên để reviewer tự kiểm.
    known_external_failure: { owner_ticket: "WWHN-309", reason: "docs/api-evidence/BE_CONTRACT_PIN.json lệch swagger-api.json hiện hành; cần npm run contract:sync bởi chủ artifact" },
  },
];

const results = [];
for (const entry of COMMANDS) {
  if (!existsSync(entry.cwd)) {
    results.push({ ...entry, status: "SKIPPED", reason: `cwd không tồn tại: ${entry.cwd}`, exit_code: null });
    console.log(`SKIP  ${entry.id} — cwd không tồn tại`);
    continue;
  }

  let output = "";
  let exitCode = 0;
  try {
    output = execSync(entry.command, { cwd: entry.cwd, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] });
  } catch (error) {
    output = `${error.stdout ?? ""}${error.stderr ?? ""}`;
    exitCode = typeof error.status === "number" ? error.status : 1;
  }

  // Đuôi `.txt` chứ không phải `.log`: `.gitignore` dòng 1 ignore `*.log` nên raw
  // log sẽ không bao giờ vào repo — đúng lý do bộ evidence của WWHN-296 chỉ có
  // register mà thiếu log gốc. QA1 cần chính raw output này để đối chiếu.
  const logName = `${entry.id}.txt`;
  writeFileSync(join(LOGS, logName), `$ ${entry.command}\n(cwd: ${entry.cwd})\n\n${output}\nEXIT_CODE=${exitCode}\n`);
  const status = exitCode === 0 ? "PASS" : entry.known_external_failure ? "FAIL_KNOWN_EXTERNAL" : "FAIL";
  results.push({
    ...entry,
    status,
    exit_code: exitCode,
    log: `docs/evidence/WWHN-237/logs/${logName}`,
  });
  console.log(`${status.padEnd(19)} ${entry.id} (exit ${exitCode})`);
}

const register = {
  schema_version: "1.0.0",
  jira_key: "WWHN-237",
  parent_key: "WWHN-60",
  qa_gate: "WWHN-238",
  disposition: "DISP-STK-237",
  disposition_result: "DEFERRED_NO_CODE",
  status: "PROPOSED",
  approval_status: "PENDING_APPROVER_AUTHORED_DISPOSITION",
  controlling_requirement: {
    id: "REQ-SRS-037",
    priority: "Must",
    statement:
      "Không expose Stocktake/Variance, menu/API/permission/entity/report hoặc action sửa tồn trực tiếp.",
    acceptance_criteria: "AC-SRS-037",
    test_case: "TC-SRS-037",
    business_rules: "BR-NOSTK-01..03",
    adoption_issue: "WWHN-334",
  },
  // Định danh **đọc từ bản pin**, không phải `rev-parse HEAD`. Đúng lỗi DEV237-P1-02R2:
  // HEAD của m1 trôi theo task khác, và BE/FE chạy lệch giờ nên hai file định danh từng
  // khai hai cặp SHA khác nhau. `head_at_run` giữ lại chỉ để truy vết môi trường chạy.
  identity_source: "docs/evidence/WWHN-237/CANDIDATE_PAIR.json",
  repositories: {
    backend: {
      branch: frozenPair?.pair?.backend?.branch ?? git("rev-parse --abbrev-ref HEAD", ROOT),
      commit_sha: frozenPair?.pair?.backend?.commit_sha ?? null,
      head_at_run: git("rev-parse HEAD", ROOT),
      working_tree_clean: git("status --porcelain", ROOT) === "",
    },
    frontend: {
      branch: frozenPair?.pair?.frontend?.branch ?? git("rev-parse --abbrev-ref HEAD", FE_ROOT),
      commit_sha: frozenPair?.pair?.frontend?.commit_sha ?? null,
      head_at_run: git("rev-parse HEAD", FE_ROOT),
      working_tree_clean: git("status --porcelain", FE_ROOT) === "",
    },
  },
  baselines: Object.fromEntries(
    [
      "docs/HN_SRS_FRD_v1.12_FULL.md",
      "docs/HoaNam_ERD_Moi_Nhat.md",
      "docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md",
      "docs/contracts/HoaNam_Current_Baseline_Contract.json",
      "docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md",
    ].map((rel) => [rel, sha256File(join(ROOT, rel))]),
  ),
  baseline_hash_rule:
    "Digest ghi tại thời điểm chạy, không dán tay. Guard xác minh bằng anchor text của REQ-SRS-037 và ERD §0.1; digest chỉ để QA1 tái lập đúng bản đã kiểm.",
  environment: {
    node: process.version,
    platform: process.platform,
    php_test_driver:
      "phpunit.xml ép DB_CONNECTION=sqlite :memory:. Muốn chạy PostgreSQL phải set env trước vì <env> không có force=true.",
  },
  commands: results,
  summary: {
    total: results.length,
    passed: results.filter((r) => r.status === "PASS").length,
    failed: results.filter((r) => r.status === "FAIL").length,
    failed_known_external: results.filter((r) => r.status === "FAIL_KNOWN_EXTERNAL").length,
    skipped: results.filter((r) => r.status === "SKIPPED").length,
  },
};
register.summary.status = register.summary.failed === 0 ? "PASS" : "FAIL";

writeFileSync(join(OUT, "SOURCE_REGISTER.json"), `${JSON.stringify(register, null, 2)}\n`);

// SHA256SUMS của chính bộ evidence, để reviewer verify được gói đã đọc.
const sums = [];
for (const dir of [OUT, LOGS]) {
  for (const name of readdirSync(dir)) {
    const abs = join(dir, name);
    if (name === "SHA256SUMS" || !existsSync(abs)) continue;
    try {
      const digest = sha256File(abs);
      if (digest) sums.push(`${digest}  ${dir === OUT ? name : `logs/${name}`}`);
    } catch {
      /* thư mục con: bỏ qua */
    }
  }
}
sums.sort((a, b) => a.slice(66).localeCompare(b.slice(66)));
writeFileSync(join(OUT, "SHA256SUMS"), `${sums.join("\n")}\n`);

console.log(`\nsummary: ${register.summary.status} — ${register.summary.passed}/${register.summary.total} pass`);
console.log(`register → docs/evidence/WWHN-237/SOURCE_REGISTER.json`);
console.log(`sha256   → docs/evidence/WWHN-237/SHA256SUMS`);

if (register.summary.failed > 0) process.exit(1);
