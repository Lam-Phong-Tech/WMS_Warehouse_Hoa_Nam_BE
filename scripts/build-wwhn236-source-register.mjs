#!/usr/bin/env node
/**
 * WWHN-236 — sinh source register hiện hành + pin candidate identity.
 *
 * Đóng DEV236-P1-02 (source register), DEV236-P1-02R3 (rebaseline AC của WWHN-60)
 * và DEV236-P1-04R3 (pin exact SHA + exact file/blob set, PR = N/A vì đi thẳng m1).
 *
 * Hash phải đo lại được chứ không chép tay, nếu không thì vòng rà soát sau lại phải
 * tin vào một con số không ai kiểm chứng được.
 *
 *   node scripts/build-wwhn236-source-register.mjs
 *   node scripts/build-wwhn236-source-register.mjs --pin
 *   node scripts/build-wwhn236-source-register.mjs --record-signoff role=pmPo decision=APPROVED date=2026-08-19 url=<jira>
 *
 * Ghi docs/evidence/WWHN-236/SOURCE_REGISTER.json (+ cập nhật
 * docs/decision-records/DISP-STK-236-APPROVAL.json khi --pin / --record-signoff).
 *
 * ── Vì sao candidate SHA không phải HEAD ──────────────────────────────────────
 * Trên `m1` có nhiều phiên làm việc song song, nên HEAD chạy tới trước cả khi task
 * này commit xong. Pin HEAD nghĩa là pin một commit không liên quan và pin đó mục
 * ngay hôm sau. Candidate ở đây = **commit cuối cùng chạm vào artifact do WWHN-236
 * sở hữu**, tính trên các file KHÔNG phải pin carrier (register/SHA256SUMS/logs tự
 * chứa SHA nên không thể tự tham chiếu). Verifier assert candidate là ancestor của
 * HEAD và blob của từng governed file không đổi kể từ candidate.
 */
import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const BE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const FE_ROOT = resolve(BE_ROOT, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE");
const APPROVAL_REL = "docs/decision-records/DISP-STK-236-APPROVAL.json";
const DISPOSITION_REL = "docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md";

const argv = process.argv.slice(2);
const PIN = argv.includes("--pin");
const RECORD_SIGNOFF = argv.includes("--record-signoff");

const digest = (abs) =>
  existsSync(abs) ? createHash("sha256").update(readFileSync(abs)).digest("hex") : null;

const git = (root, args) => {
  try {
    // stderr bị nuốt có chủ đích: `git rev-parse HEAD:<path>` in "exists on disk, but
    // not in HEAD" cho file mới, và đó là trạng thái hợp lệ chứ không phải lỗi.
    return execFileSync("git", args, { cwd: root, encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] }).trim();
  } catch {
    return null;
  }
};

/**
 * Artifact do WWHN-236 sở hữu. `pin_carrier` = file tự chứa SHA/hash của chính đợt
 * pin (không thể dùng để tính candidate, sẽ tự tham chiếu). `shared` = file dùng
 * chung với nhánh khác: pin theo NỘI DUNG NGHIỆP VỤ (fragment còn đủ hay không) chứ
 * không pin blob, nếu không thì nhánh WWHN-233 commit là gate này đỏ oan.
 */
const GOVERNED = {
  BE: [
    { path: DISPOSITION_REL, role: "disposition" },
    { path: APPROVAL_REL, role: "approval-record", pin_carrier: true },
    { path: "scripts/verify-wwhn236-adjustment-disposition.mjs", role: "gate" },
    { path: "scripts/build-wwhn236-source-register.mjs", role: "gate" },
    { path: "scripts/qa-wwhn236-exact-sha-gate.mjs", role: "qa-runner" },
    { path: "tests/Feature/Wwhn236DeferredAdjustmentGateTest.php", role: "gate-test" },
    { path: "docs/evidence/WWHN-236/NO_CODE_HANDOFF.md", role: "evidence" },
    { path: "docs/evidence/WWHN-236/QA_DISPOSITION_WWHN-238.md", role: "evidence" },
    { path: "docs/evidence/WWHN-236/CHAIN_DISPOSITION.json", role: "evidence" },
    { path: "docs/evidence/WWHN-236/CONTRACT_NEGATIVE_LOCK.json", role: "evidence", pin_carrier: true },
    { path: "docs/evidence/WWHN-236/SOURCE_REGISTER.json", role: "evidence", pin_carrier: true },
    { path: "docs/evidence/WWHN-236/SHA256SUMS", role: "evidence", pin_carrier: true },
    { path: "docs/contracts/HoaNam_Current_Baseline_Contract.json", role: "guard-contract", shared: true },
  ],
  FE: [
    { path: "scripts/verify-wwhn-236-closure.mjs", role: "gate" },
    { path: "src/test/wwhn-236-adjustment-deferral-gate.test.ts", role: "gate-test" },
    { path: "docs/Task_docs_Jira/WWHN-236.md", role: "task-doc" },
    { path: "docs/Task_docs_Jira/WWHN-236_SIGNOFF_COMMENTS.md", role: "task-doc" },
    { path: "docs/evidence/WWHN-236/FE_CLOSURE_IDENTITY.json", role: "evidence", pin_carrier: true },
    { path: "docs/evidence/WWHN-236/SHA256SUMS", role: "evidence", pin_carrier: true },
  ],
};

/** Trạng thái git của một file governed: blob đã commit, blob trên đĩa, commit cuối chạm. */
const fileIdentity = (root, entry) => {
  const abs = join(root, entry.path);
  const present = existsSync(abs);
  const committedBlob = git(root, ["rev-parse", `HEAD:${entry.path}`]);
  const worktreeBlob = present ? git(root, ["hash-object", entry.path]) : null;
  const lastTouch = git(root, ["log", "-1", "--format=%H|%cI|%s", "--", entry.path]);
  const [last_commit = null, last_commit_date = null, last_commit_subject = null] = (lastTouch ?? "")
    .split("|");
  return {
    path: entry.path,
    role: entry.role,
    pin_carrier: entry.pin_carrier === true,
    shared: entry.shared === true,
    present,
    tracked: committedBlob !== null,
    blob_sha: committedBlob,
    worktree_blob_sha: worktreeBlob,
    uncommitted: committedBlob !== null && worktreeBlob !== null && committedBlob !== worktreeBlob,
    sha256: digest(abs),
    last_commit: last_commit || null,
    last_commit_date: last_commit_date || null,
    last_commit_subject: last_commit_subject || null,
  };
};

/**
 * Candidate = commit cuối cùng chạm vào artifact sở hữu, không tính pin carrier và
 * không tính file dùng chung với nhánh khác.
 */
const candidateFor = (root, files) => {
  const paths = files.filter((f) => !f.pin_carrier && !f.shared && f.tracked).map((f) => f.path);
  if (paths.length === 0) return null;
  const line = git(root, ["log", "-1", "--format=%H|%cI|%s", "--", ...paths]);
  if (!line) return null;
  const [sha, date, subject] = line.split("|");
  return { sha, date, subject, computed_from: paths };
};

const repoIdentity = (root, label) => {
  if (!existsSync(root)) return { repo: label, available: false };
  const status = git(root, ["status", "--porcelain"]) ?? "";
  const dirtyPaths = status
    .split("\n")
    .filter(Boolean)
    .map((l) => l.slice(3).trim().replace(/^"|"$/g, ""));

  const files = GOVERNED[label].map((e) => fileIdentity(root, e));
  const governedPaths = new Set(files.map((f) => f.path));
  // Pin carrier (register/SHA256SUMS/lock/approval) LUÔN bẩn ngay trước khi commit pin —
  // đó là bản chất của chúng, không phải bằng chứng thiếu. Chỉ file mang nội dung mới
  // được tính vào governed_working_tree.
  const governedDirty = files
    .filter((f) => !f.pin_carrier && (f.uncommitted || (f.present && !f.tracked)))
    .map((f) => f.path);
  const carrierDirty = files
    .filter((f) => f.pin_carrier && (f.uncommitted || (f.present && !f.tracked)))
    .map((f) => f.path);
  const foreignDirty = dirtyPaths.filter((p) => !governedPaths.has(p));
  const candidate = candidateFor(root, files);

  return {
    repo: label,
    branch: git(root, ["rev-parse", "--abbrev-ref", "HEAD"]),
    head_sha: git(root, ["rev-parse", "HEAD"]),
    candidate_sha: candidate?.sha ?? null,
    candidate_date: candidate?.date ?? null,
    candidate_subject: candidate?.subject ?? null,
    candidate_rule:
      "Commit cuối cùng chạm artifact do WWHN-236 sở hữu (bỏ pin carrier + file dùng chung). Không pin HEAD: m1 có nhiều phiên song song nên HEAD chạy tiếp mà không liên quan task này.",
    pull_request: "N/A — direct-to-m1",
    pull_request_note:
      "Dự án yêu cầu không tạo nhánh riêng. Vì không có PR để review, Tech Lead review exact diff bằng lệnh ở tech_lead_review.diff_command và ký trên Jira.",
    governed_working_tree: governedDirty.length === 0 ? "CLEAN" : "DIRTY",
    governed_dirty_files: governedDirty,
    pin_carrier_pending_commit: carrierDirty,
    pin_carrier_note:
      "File tự chứa hash/SHA của chính đợt pin. Chúng được commit ở commit thứ hai ngay sau khi pin, nên không tính vào governed_working_tree và không dùng để tính candidate.",
    foreign_dirty_files: foreignDirty,
    foreign_dirty_note:
      foreignDirty.length === 0
        ? null
        : "File bẩn thuộc phiên làm việc song song khác trên m1, không thuộc phạm vi WWHN-236. Evidence của task này chỉ đòi governed_working_tree = CLEAN.",
    governed_files: files,
  };
};

/** Nguồn hiện hành. `role` nói rõ nguồn nào thắng khi mâu thuẫn. */
const CONTROLLING = [
  {
    id: "SRS-1.12",
    root: FE_ROOT,
    path: "docs/HN_SRS_FRD_v1.12_FULL.md",
    version: "v1.12",
    adopted_at: "WWHN-334",
    role: "CONTROLLING",
    anchor:
      "REQ-SRS-037 (Must) / AC-SRS-037 / TC-SRS-037 / BR-NOSTK-01..03; §5.3; dòng 65, 180, 235, 338, 510, 1357",
  },
  {
    id: "ERD",
    root: FE_ROOT,
    path: "docs/HoaNam_ERD_Moi_Nhat.md",
    version: "latest",
    role: "CROSS-CONFIRM",
    anchor:
      "§0.1 — không tạo Stocktake_Session/Snapshot/Count/Variance_Line/Adjustment; bảng 32 reconciliation_incidents (dòng 1551, 1604) là nơi ghi nhận lệch",
  },
  {
    id: "WIREFRAME",
    root: FE_ROOT,
    path: "docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html",
    version: "v3.20 / v1.12",
    role: "CROSS-CONFIRM",
    anchor: "Zero occurrence stocktake/kiểm kê/adjustment — không có màn hình để build",
  },
  {
    id: "TECHSTACK",
    root: FE_ROOT,
    path: "docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md",
    version: "current",
    role: "CROSS-CONFIRM",
    anchor: "Dòng 568 — Stocktake/Variance/Adjustment ngoài MVP; correction path là Reversal",
  },
  {
    id: "BASELINE-CONTRACT",
    root: BE_ROOT,
    path: "docs/contracts/HoaNam_Current_Baseline_Contract.json",
    version: "schema hiện hành",
    role: "MACHINE-GATE",
    anchor: "forbidden_path_fragments + excluded_tables + movement_types",
  },
  {
    id: "OPENAPI-RUNTIME",
    root: BE_ROOT,
    path: "storage/api-docs/api-docs.yaml",
    version: "sinh từ route thật",
    role: "MACHINE-GATE",
    anchor: "Bề mặt API thật — zero path adjustment/stocktake/variance",
  },
  {
    id: "DISP-STK-236",
    root: BE_ROOT,
    path: DISPOSITION_REL,
    version: "1.1.0",
    role: "DISPOSITION",
    anchor: "Chốt authority + disposition DEFERRED cho WWHN-60/236/238 + HN-STK-002",
  },
  {
    id: "DISP-STK-236-APPROVAL",
    root: BE_ROOT,
    path: APPROVAL_REL,
    version: "1.0.0",
    role: "APPROVAL-RECORD",
    anchor: "DEV236-P1-01R3 — decision/version/hash/effective date + LIM-STK-236-01",
  },
];

/** Nguồn bị hạ cấp. Không được trích dẫn làm controlling contract. */
const HISTORICAL = [
  {
    id: "WWHN-180-OPENAPI-JSON",
    root: BE_ROOT,
    path: "docs/api-contract/WWHN-180-posting-openapi.json",
    role: "HISTORICAL",
    reason:
      "Task-scoped artifact, x-contract-status PROPOSED / x-approval-status PENDING_INDEPENDENT_SIGN_OFF. Immutable: chỉ được supersede bằng decision record, không sửa nội dung.",
  },
  {
    id: "WWHN-180-OPENAPI-YAML",
    root: BE_ROOT,
    path: "docs/api-contract/WWHN-180-posting-openapi.yaml",
    role: "HISTORICAL",
    reason: "Như trên.",
  },
  {
    id: "WWHN-150-SCAN-OPENAPI",
    root: BE_ROOT,
    path: "docs/api-contract/WWHN-150-scan-openapi.yaml",
    role: "HISTORICAL",
    reason:
      "Còn khai enum scan context STOCKTAKE. Runtime ScanController chỉ nhận INBOUND,OUTBOUND,INVENTORY,TRACE,WARRANTY_RECEIVE,WARRANTY_TEMP_ONLY — khớp SRS §5.3. Drift đã ghi nhận ở WWHN-181.",
  },
  {
    id: "CHANGELOG-BE1",
    root: BE_ROOT,
    path: "docs/api-contract/WMS_HoaNam_OpenAPI_Changelog_BE1.md",
    role: "HISTORICAL",
    reason:
      "v1.0.1 còn liệt POST /api/v1/stocktake-sessions/{id}/apply-adjustment. Đã bị HN-BE-API-001_API_CONTRACT_HANDOVER.md:192 hạ xuống historical.",
  },
  {
    id: "DEC-STK-001",
    root: BE_ROOT,
    path: "docs/decision-records/DEC-STK-001.md",
    role: "PROPOSED / NO EFFECTIVE DATE",
    reason:
      "Mô hình kiểm kê A (WWHN-25). Chưa bao giờ được duyệt, không có effective date ⇒ không phải authority ở MVP v1.12.",
  },
  {
    id: "BRD-HANDOFF-v3.9",
    root: null,
    path: null,
    role: "SUPERSEDED",
    reason:
      "Baseline mà AC của WWHN-60 còn trích dẫn. Bị BRD v3.20 / SRS v1.12 thay thế (SRS revision history dòng 659, mốc 04/08/2026). Reliance đã được gỡ bỏ tại DISP-STK-236 §3.",
  },
];

const resolveEntry = (entry) => {
  if (entry.root === null || entry.path === null) {
    return { ...entry, root: undefined, sha256: null, present: false };
  }
  const abs = join(entry.root, entry.path);
  const { root, ...rest } = entry;
  return {
    ...rest,
    repo: root === FE_ROOT ? "FE" : "BE",
    sha256: digest(abs),
    present: existsSync(abs),
  };
};

const controlling = CONTROLLING.map(resolveEntry);
const historical = HISTORICAL.map(resolveEntry);
const missing = [...controlling, ...historical].filter((e) => e.path && !e.present);

const be = repoIdentity(BE_ROOT, "BE");
const fe = repoIdentity(FE_ROOT, "FE");

const payload = {
  document: "SOURCE_REGISTER",
  task: "WWHN-236",
  register_item: ["DEV236-P1-02", "DEV236-P1-02R3", "DEV236-P1-04R3"],
  disposition: "DISP-STK-236",
  statement:
    "Reliance vào BRD/Handoff v3.9 đã được gỡ bỏ. Controlling source duy nhất cho phạm vi tồn kho/kiểm kê/sửa tồn là REQ-SRS-037 của HN_SRS_FRD_v1.12_FULL.md (adopt tại WWHN-334). Mọi nguồn còn khai Apply Adjustment đều là historical hoặc PROPOSED và không được trích dẫn làm controlling contract.",
  authority_ranking: [
    "REQ-SRS-037 (SRS v1.12, ADOPTED, Must)",
    "ERD §0.1 / Techstack Dev Guide / Wireframe MVP (cross-confirm)",
    "DEC-STK-001 (WWHN-25) — PROPOSED, no effective date",
    "WWHN-180 posting OpenAPI v1.7.0 — PROPOSED, pending independent sign-off",
    "BRD/Handoff v3.9 + Changelog BE1 v1.0.1 — SUPERSEDED / historical",
  ],
  parent_ac_disposition: {
    ticket: "WWHN-60",
    statement:
      "AC nào của WWHN-60 còn yêu cầu Apply Adjustment thay đổi tồn thì không còn hiệu lực và phải được BA rebaseline. Business action được map sang bề mặt Reversal canonical.",
    canonical_mapping: {
      "Điều chỉnh receipt đã post": "POST /api/v1/inbound-documents/{id}/reverse [inventory.reversal.post]",
      "Điều chỉnh issue đã post": "POST /api/v1/outbound-documents/{id}/reverse [inventory.reversal.post]",
      "Chứng từ điều chỉnh độc lập": "POST /api/v1/reversal-documents [inventory.reversal.post]",
      "Phát hiện lệch tồn": "GET /api/v1/reports/inventory-reconciliation [report.view]",
    },
  },
  parent_rebaseline: {
    register_item: "DEV236-P1-02R3",
    ticket: "WWHN-60",
    owner: "BA + BAS",
    current_source: {
      document_id: "HN-SRS-FRD-001",
      path: "docs/HN_SRS_FRD_v1.12_FULL.md",
      repo: "FE",
      version: "1.12",
      adoption_issue: "WWHN-334",
      requirement: "REQ-SRS-037",
      requirement_line: 338,
      priority: "Must",
      acceptance_criteria: "AC-SRS-037",
      business_rules: "BR-NOSTK-01..03",
      test_case: "TC-SRS-037",
    },
    superseded_source: {
      label: "BRD/Handoff v3.9",
      status: "HISTORICAL — chỉ dùng để tra cứu lịch sử, không được trích làm AC",
      superseded_by: "BRD v3.20 / SRS v1.12 (SRS revision history dòng 659, mốc 04/08/2026)",
    },
    ac_to_remove_from_current_mvp: [
      "AC yêu cầu implement Apply Adjustment / apply-adjustment endpoint",
      "AC yêu cầu tạo Stocktake_Session / Snapshot / Count / Variance_Line / Adjustment",
      "AC yêu cầu permission stocktake.* hoặc *.adjust",
      "AC yêu cầu màn hình hoặc report kiểm kê / variance",
      "AC yêu cầu movement_type ADJUST_IN / ADJUST_OUT",
    ],
    ac_replacement:
      "WWHN-60 giữ đúng một AC hiệu lực ở MVP v1.12: deployment không chứa capability sửa tồn trực tiếp (AC-SRS-037), correction path duy nhất là Reversal append-only theo WWHN-26, và lệch tồn chỉ được ghi nhận qua reconciliation_incidents (LIM-STK-236-01).",
    verification:
      "node scripts/verify-wwhn236-adjustment-disposition.mjs (check PARENT-REBASELINE) + php vendor/bin/phpunit --filter Wwhn236DeferredAdjustmentGateTest",
  },
  candidate_identity: [be, fe],
  tech_lead_review: {
    register_item: "DEV236-P1-04R3",
    pull_request: "N/A — direct-to-m1",
    diff_command: {
      BE: be.candidate_sha ? `git show --stat ${be.candidate_sha}` : null,
      FE: fe.candidate_sha ? `git show --stat ${fe.candidate_sha}` : null,
    },
    exact_file_set_note:
      "Tập file chính xác nằm ở candidate_identity[].governed_files (path + blob_sha + last_commit). Reviewer không cần tin vào mô tả: mỗi dòng đối chiếu được bằng `git rev-parse <sha>:<path>`.",
    reviewer: null,
    review_date: null,
    decision: null,
  },
  controlling_sources: controlling,
  historical_sources: historical,
  missing_sources: missing.map((e) => `${e.repo}:${e.path}`),
};

const outDir = join(BE_ROOT, "docs/evidence/WWHN-236");
mkdirSync(outDir, { recursive: true });
writeFileSync(join(outDir, "SOURCE_REGISTER.json"), `${JSON.stringify(payload, null, 2)}\n`);

// ── Cập nhật approval record: hash disposition + (tuỳ chọn) pin SHA / ký ──────
const approvalAbs = join(BE_ROOT, APPROVAL_REL);
if (existsSync(approvalAbs)) {
  const approval = JSON.parse(readFileSync(approvalAbs, "utf8"));
  approval.dispositionSha256 = digest(join(BE_ROOT, DISPOSITION_REL));

  if (PIN) {
    const blocked = [
      ...be.governed_dirty_files.map((p) => `BE:${p}`),
      ...fe.governed_dirty_files.map((p) => `FE:${p}`),
    ];
    if (blocked.length > 0) {
      console.error(
        `\nFAIL --pin: còn ${blocked.length} governed file chưa commit:\n  ${blocked.join("\n  ")}\n` +
          "Pin một SHA trong khi artifact còn nằm ngoài commit đó là pin một bằng chứng không tồn tại.",
      );
      process.exit(1);
    }
    approval.exactCommitSha.backend = be.candidate_sha;
    approval.exactCommitSha.frontend = fe.candidate_sha;
  }

  if (RECORD_SIGNOFF) {
    const kv = Object.fromEntries(
      argv
        .filter((a) => a.includes("=") && !a.startsWith("--"))
        .map((a) => [a.slice(0, a.indexOf("=")), a.slice(a.indexOf("=") + 1)]),
    );
    const role = kv.role;
    if (!role || !approval.signOff[role]) {
      console.error(
        `\nFAIL --record-signoff: role=[${role ?? ""}] không hợp lệ. Hợp lệ: ${Object.keys(approval.signOff).join(", ")}`,
      );
      process.exit(1);
    }
    approval.signOff[role].decision = kv.decision ?? null;
    approval.signOff[role].date = kv.date ?? null;
    approval.signOff[role].commentUrl = kv.url ?? null;

    if (role === "pmPo" && kv.decision === "APPROVED") {
      approval.limitationAccepted.acceptanceRecorded = true;
    }

    const entries = Object.values(approval.signOff);
    const decided = entries.filter((s) => s.decision);
    if (decided.length === entries.length) {
      const dates = decided.map((s) => s.date).filter(Boolean).sort();
      approval.effectiveDate = dates[dates.length - 1] ?? null;
      approval.status = decided.every((s) => s.decision === "APPROVED") ? "APPROVED" : "PROPOSED";
    }
    console.log(`  signOff.${role} = ${kv.decision ?? "null"} (${kv.date ?? "no date"})`);
  }

  writeFileSync(approvalAbs, `${JSON.stringify(approval, null, 2)}\n`);
}

console.log("WWHN-236 source register");
for (const e of controlling) console.log(`  ${e.role.padEnd(16)} ${e.id.padEnd(22)} ${e.sha256 ?? "MISSING"}`);
for (const e of historical) console.log(`  ${e.role.padEnd(16)} ${e.id.padEnd(22)} ${e.sha256 ?? "n/a"}`);

for (const r of [be, fe]) {
  console.log(
    `\n  ${r.repo}: HEAD ${r.head_sha?.slice(0, 7)} · candidate ${r.candidate_sha?.slice(0, 7) ?? "n/a"} · governed ${r.governed_working_tree}` +
      ` · ${r.governed_files.filter((f) => f.present).length}/${r.governed_files.length} file present` +
      ` · ${r.foreign_dirty_files.length} file bẩn của phiên khác`,
  );
  for (const f of r.governed_files.filter((f) => !f.present)) console.log(`    MISSING ${f.path}`);
  for (const f of r.governed_files.filter((f) => f.uncommitted)) console.log(`    UNCOMMITTED ${f.path}`);
}

if (missing.length > 0) {
  console.error(`\nFAIL: thiếu ${missing.length} nguồn: ${missing.map((e) => e.path).join(", ")}`);
  process.exit(1);
}
console.log("\n→ docs/evidence/WWHN-236/SOURCE_REGISTER.json");
if (PIN) console.log(`→ ${APPROVAL_REL} (exactCommitSha đã pin)`);
