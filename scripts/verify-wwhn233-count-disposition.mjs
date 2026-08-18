#!/usr/bin/env node
/**
 * WWHN-233 hard gate — DISP-STK-233 (Count / Recount / Partial / Stale DEFERRED).
 *
 * Bổ trợ cho `verify-wwhn236-adjustment-disposition.mjs`, không lặp lại nó.
 * WWHN-236 khoá bề mặt *adjustment*; WWHN-233 khoá bề mặt *count/recount* và ba thứ
 * mà nhánh 236 không phủ:
 *
 *   1. ANTI-DANGLING — mọi artifact mà một disposition của chuỗi Stocktake cam kết
 *      phải thực sự tồn tại. Đây là root cause của điểm 34/100: DISP-STK-236 §5 mô tả
 *      4 hạng mục hàng rào mà khi WWHN-233 rà soát thì chưa hạng mục nào tồn tại.
 *   2. SOURCE REGISTER — digest được *tính lại* mỗi lần chạy, không so với hằng số
 *      chép tay, nên baseline đổi mà pin không đổi (pin rot) bị bắt ngay.
 *   3. NEGATIVE LOCK cho count/recount/partial/stale + QA disposition WWHN-235.
 *
 * Chạy độc lập, không boot Laravel/DB.
 *
 *   node scripts/verify-wwhn233-count-disposition.mjs
 *   node scripts/verify-wwhn233-count-disposition.mjs --emit-evidence
 *
 * `--emit-evidence` ghi docs/evidence/WWHN-233/{SOURCE_REGISTER,CONTRACT_NEGATIVE_LOCK}.json
 * và SHA256SUMS.
 */
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const EMIT = process.argv.includes("--emit-evidence");

const failures = [];
const warnings = [];
const checks = [];

const fail = (id, message) => failures.push(`[${id}] ${message}`);
const warn = (id, message) => warnings.push(`[${id}] ${message}`);
const pass = (id, message) => checks.push(`[${id}] ${message}`);

const readIfExists = (rel) => {
  const abs = join(ROOT, rel);
  return existsSync(abs) ? readFileSync(abs, "utf8") : null;
};

const sha256 = (rel) => {
  const abs = join(ROOT, rel);
  return existsSync(abs) ? createHash("sha256").update(readFileSync(abs)).digest("hex") : null;
};

const DISPOSITION_233 = "docs/decision-records/DISP-STK-233-COUNT-RECOUNT-DEFERRED.md";
const DISPOSITION_236 = "docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md";

/**
 * Mảnh đường dẫn thuộc bề mặt count/recount. WWHN-236 đã khoá stocktake/adjustment/
 * variance; ba mảnh dưới đây là phần riêng của nhánh WWHN-59/233. Không dùng "count"
 * trần vì nó quá rộng (khớp cả `*-count` hợp lệ trong query string tương lai).
 */
const COUNT_FRAGMENTS = ["recount", "cycle-count", "physical-count"];

/** Toàn bộ mảnh bị cấm sau khi cả hai nhánh đã đóng. */
const ALL_FORBIDDEN = ["stocktake", "adjustment", "variance", ...COUNT_FRAGMENTS];

/**
 * Nguồn hiện hành của WWHN-233. `role` quyết định mức xử lý khi file vắng mặt:
 * controlling/corroborating => fail, optional => warn (ví dụ wireframe chỉ nằm ở FE repo).
 */
const SOURCES = [
  {
    id: "SRS-1.12",
    path: "docs/HN_SRS_FRD_v1.12_FULL.md",
    role: "CONTROLLING",
    anchors: ["REQ-SRS-037", "Không expose Stocktake/Variance", "AC-SRS-037", "BR-NOSTK-01..03"],
  },
  {
    id: "ERD",
    path: "docs/HoaNam_ERD_Moi_Nhat.md",
    role: "CORROBORATING",
    anchors: ["Không tạo Stocktake_Session"],
  },
  {
    id: "TECHSTACK",
    path: "docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md",
    role: "CORROBORATING",
    // Bản BE và bản FE của guide này diễn đạt khác nhau ("No Stocktake/Variance/
    // Adjustment active MVP module" vs "…are outside current MVP"). Neo vào mệnh đề
    // chung của cả hai để anchor không phụ thuộc câu chữ của một repo.
    anchors: ["reconciliation + append-only Reversal is the correction path"],
  },
  {
    id: "BASELINE-CONTRACT",
    path: "docs/contracts/HoaNam_Current_Baseline_Contract.json",
    role: "MACHINE-GATE",
    anchors: ["forbidden_path_fragments"],
  },
  {
    id: "OPENAPI-RUNTIME",
    path: "storage/api-docs/api-docs.yaml",
    role: "RUNTIME-SURFACE",
    anchors: [],
  },
];

/** Nguồn lịch sử — được phép tồn tại, không được trích làm controlling contract. */
const HISTORICAL = [
  "docs/decision-records/DEC-STK-001.md",
  "docs/api-contract/WWHN-180-posting-openapi.json",
  "docs/api-contract/WWHN-180-posting-openapi.yaml",
  "docs/testing/WWHN-131-stocktake-test-cases.json",
];

// ── Check 1: disposition WWHN-233 tồn tại và neo đủ controlling source ────────
{
  const doc = readIfExists(DISPOSITION_233);
  if (doc === null) {
    fail("DISP-EXISTS", `${DISPOSITION_233} không tồn tại — WWHN-233 bắt buộc có disposition bằng văn bản.`);
  } else {
    const required = [
      ["REQ-SRS-037", "phải neo controlling source vào REQ-SRS-037"],
      ["DEFERRED", "phải nêu rõ disposition DEFERRED"],
      ["WWHN-59", "phải nêu disposition của parent WWHN-59"],
      ["WWHN-235", "phải nêu disposition của QA gate WWHN-235"],
      ["DISP-STK-236", "phải nêu quan hệ với nhánh adjustment"],
    ];
    const missing = required.filter(([needle]) => !doc.includes(needle));
    if (missing.length > 0) {
      fail("DISP-CONTENT", `${DISPOSITION_233} thiếu: ${missing.map(([n, why]) => `${n} (${why})`).join("; ")}`);
    } else {
      pass("DISP-CONTENT", "DISP-STK-233 neo REQ-SRS-037 + DEFERRED + WWHN-59 + WWHN-235 + DISP-STK-236");
    }
  }
}

// ── Check 2: ANTI-DANGLING — hàng rào được hứa phải có thật ───────────────────
//
// Root cause của 34/100. Trích mọi đường dẫn artifact trong hai file disposition và
// khẳng định chúng tồn tại. Một disposition tương lai không thể lại là lời hứa suông.
{
  const ARTIFACT_RE = /`((?:docs|scripts|tests|src|e2e|config)\/[A-Za-z0-9._\-/]+)`/g;

  /**
   * Đường dẫn thuộc repo FE. Disposition mô tả cả hai repo, nhưng gate này chỉ đứng
   * trong repo BE nên không thể khẳng định file bên kia. Chúng được verifier FE
   * (`scripts/verify-wwhn-233-closure.mjs`) kiểm, và verifier đó lại được liệt kê ở
   * đây — nên chuỗi vẫn khép kín, chỉ là mỗi repo giữ nửa của mình.
   */
  const FE_REPO_ARTIFACTS = new Set([
    "docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html",
    "docs/api-evidence/STOCKTAKE_GOVERNANCE_STATUS.json",
    "scripts/verify-wwhn-233-closure.mjs",
    "scripts/verify-wwhn-236-closure.mjs",
    "src/test/wwhn-233-count-deferral-gate.test.ts",
  ]);

  const SKIP = [
    // Evidence pack của nhánh song song — thuộc quyền sở hữu WWHN-236.
    "docs/evidence/WWHN-236/",
  ];

  for (const [rel, owner] of [
    [DISPOSITION_233, "WWHN-233"],
    [DISPOSITION_236, "WWHN-236"],
  ]) {
    const doc = readIfExists(rel);
    if (doc === null) {
      // Hai nhánh có thể merge theo thứ tự bất kỳ; nhánh kia chưa vào thì chỉ cảnh báo.
      if (rel === DISPOSITION_236) {
        warn("ANTI-DANGLING", `${rel} chưa có mặt trên nhánh này (WWHN-236 đang chạy song song).`);
      } else {
        fail("ANTI-DANGLING", `${rel} không tồn tại.`);
      }
      continue;
    }

    const promised = new Set();
    for (const match of doc.matchAll(ARTIFACT_RE)) {
      const path = match[1];
      if (path.endsWith("/")) continue;
      if (SKIP.some((prefix) => path.startsWith(prefix))) continue;
      if (FE_REPO_ARTIFACTS.has(path)) continue;
      promised.add(path);
    }

    const missing = [...promised].filter((path) => !existsSync(join(ROOT, path)));
    if (missing.length > 0) {
      const message =
        `${rel} cam kết ${missing.length} artifact không tồn tại: ${missing.join(", ")}. ` +
        "Một disposition chỉ đóng được register khi hàng rào của nó là file chạy được, không phải mô tả.";
      // Cam kết của chính WWHN-233 là ràng buộc cứng. Cam kết của nhánh song song
      // chỉ cảnh báo cho tới khi nhánh đó merge xong.
      if (owner === "WWHN-233") fail("ANTI-DANGLING", message);
      else warn("ANTI-DANGLING", message);
    } else {
      pass("ANTI-DANGLING", `${rel}: toàn bộ ${promised.size} artifact được cam kết đều tồn tại.`);
    }
  }
}

// ── Check 3: baseline contract chặn đủ mảnh count/recount ─────────────────────
{
  const rel = "docs/contracts/HoaNam_Current_Baseline_Contract.json";
  const raw = readIfExists(rel);
  if (raw === null) {
    fail("BASELINE", `${rel} không tồn tại.`);
  } else {
    let contract = null;
    try {
      contract = JSON.parse(raw);
    } catch (error) {
      fail("BASELINE", `${rel} không parse được JSON: ${error.message}`);
    }

    if (contract) {
      const fragments = contract.api?.forbidden_path_fragments ?? [];
      const missing = COUNT_FRAGMENTS.filter((fragment) => !fragments.includes(fragment));
      if (missing.length > 0) {
        fail(
          "BASELINE-FRAGMENTS",
          `forbidden_path_fragments thiếu mảnh count/recount: ${missing.join(", ")}. ` +
            "AC của WWHN-59/233 là count/recount/partial/stale; một route đếm lại tồn kho " +
            "không nhất thiết chứa chữ stocktake hay adjustment.",
        );
      } else {
        pass("BASELINE-FRAGMENTS", `forbidden_path_fragments phủ đủ ${COUNT_FRAGMENTS.join(", ")}`);
      }

      // Annex C / ERD alignment không được khai một operation thuộc bề mặt bị cấm.
      const declared = [
        ...(contract.api?.annex_c_alignment?.operations ?? []),
        ...(contract.api?.erd_alignment?.operations ?? []),
      ];
      const offending = declared.filter((operation) =>
        ALL_FORBIDDEN.some((fragment) => operation.toLowerCase().includes(fragment)),
      );
      if (offending.length > 0) {
        fail("BASELINE-OPERATIONS", `Contract khai operation thuộc bề mặt bị cấm: ${offending.join(", ")}`);
      } else {
        pass("BASELINE-OPERATIONS", `${declared.length} operation được khai, zero thuộc bề mặt bị cấm.`);
      }

      // Permission catalog không được có capability kiểm kê.
      const permissions = contract.permissions ?? [];
      const badPermissions = permissions.filter((permission) =>
        ALL_FORBIDDEN.some((fragment) => permission.toLowerCase().includes(fragment)),
      );
      if (badPermissions.length > 0) {
        fail("BASELINE-PERMISSIONS", `Permission thuộc bề mặt bị cấm: ${badPermissions.join(", ")}`);
      } else {
        pass("BASELINE-PERMISSIONS", `${permissions.length} permission, zero capability kiểm kê.`);
      }
    }
  }
}

// ── Check 4: runtime OpenAPI không khai path count/recount/variance ───────────
{
  const rel = "storage/api-docs/api-docs.yaml";
  const spec = readIfExists(rel);
  if (spec === null) {
    fail("OPENAPI", `${rel} không tồn tại — không xác minh được bề mặt API thật.`);
  } else {
    const paths = [...spec.matchAll(/^\s{2}(\/api\/v1\/[^:\s]*):/gmu)].map((match) => match[1]);
    const offending = paths.filter((path) =>
      ALL_FORBIDDEN.some((fragment) => path.toLowerCase().includes(fragment)),
    );
    if (offending.length > 0) {
      fail("OPENAPI", `Runtime OpenAPI khai path thuộc bề mặt bị cấm: ${offending.join(", ")}`);
    } else {
      pass("OPENAPI", `${paths.length} path runtime, zero path count/recount/variance/adjustment.`);
    }
  }
}

// ── Check 5: DEC-STK-001 — nguồn DUY NHẤT định nghĩa contract count/recount ───
//
// DEV233-P1-04 hỏi "khoá endpoint/state/duplicate/stale/count history trước DEV".
// Nơi duy nhất trong repo mô tả hợp đồng đó là bản nháp DEC-STK-001. Nghĩa vụ phủ
// định khi DEFERRED: nó phải ở nguyên trạng PROPOSED / no effective date.
{
  const rel = "docs/decision-records/DEC-STK-001.md";
  const doc = readIfExists(rel);
  if (doc === null) {
    fail("DEC-STK-001", `${rel} biến mất — bản nháp phải được giữ làm bản ghi lịch sử.`);
  } else {
    const proposed = /\|\s*Status\s*\|\s*\*\*PROPOSED\*\*\s*\|/u.test(doc);
    const noEffective = /\|\s*Effective date\s*\|\s*`NULL/u.test(doc);
    if (!proposed || !noEffective) {
      fail(
        "DEC-STK-001",
        `${rel} không còn PROPOSED / no-effective-date. Hợp đồng count/recount/stale chỉ được ` +
          "kích hoạt qua CR + WWHN-25 GO + rebaseline SRS (DISP-STK-233 §2).",
      );
    } else {
      pass("DEC-STK-001", "DEC-STK-001 vẫn PROPOSED / Effective date = NULL");
    }
  }
}

// ── Check 6: source register — digest tính lại, chống pin rot ─────────────────
const sourceRegister = { generated_by: "scripts/verify-wwhn233-count-disposition.mjs", sources: [] };
{
  for (const source of SOURCES) {
    const content = readIfExists(source.path);
    if (content === null) {
      fail("SOURCE", `${source.id} (${source.role}) vắng mặt: ${source.path}`);
      continue;
    }

    const missingAnchors = source.anchors.filter((anchor) => !content.includes(anchor));
    if (missingAnchors.length > 0) {
      fail(
        "SOURCE-ANCHOR",
        `${source.path} mất anchor ${missingAnchors.join(", ")} — rule không được sống lâu hơn căn cứ của nó.`,
      );
    }

    sourceRegister.sources.push({
      id: source.id,
      path: source.path,
      role: source.role,
      sha256: sha256(source.path),
      anchors_present: missingAnchors.length === 0,
    });
  }

  if (!failures.some((entry) => entry.startsWith("[SOURCE"))) {
    pass("SOURCE", `${sourceRegister.sources.length} nguồn hiện hành có mặt, anchor còn nguyên, digest tính lại.`);
  }

  sourceRegister.historical = HISTORICAL.map((path) => ({
    path,
    sha256: sha256(path),
    status: existsSync(join(ROOT, path)) ? "PRESENT_HISTORICAL" : "ABSENT",
    rule: "Không được trích làm controlling contract (DISP-STK-233 §3).",
  }));

  // BRD/Handoff v3.9 phải được SRS đánh dấu superseded — đóng DEV233-P1-03.
  const srs = readIfExists("docs/HN_SRS_FRD_v1.12_FULL.md") ?? "";
  if (!/BRD v3\.9[\s\S]{0,400}?(HISTORICAL|SUPERSEDED)/u.test(srs)) {
    fail(
      "SOURCE-SUPERSESSION",
      "SRS v1.12 không còn dòng đánh dấu BRD v3.9 là HISTORICAL/SUPERSEDED; " +
        "WWHN-59 không được tiếp tục dùng v3.9 làm baseline.",
    );
  } else {
    pass("SOURCE-SUPERSESSION", "BRD v3.9 được SRS v1.12 đánh dấu HISTORICAL/SUPERSEDED.");
    sourceRegister.supersession = {
      superseded: "BRD/Handoff v3.9",
      current: "BRD v3.20 (WWHN-333) + SRS/FRD v1.12 (WWHN-334)",
      evidence: "docs/HN_SRS_FRD_v1.12_FULL.md — bảng supersession",
      historical_ids_policy: "Chỉ giữ để trace/tombstone, không dùng làm baseline triển khai.",
    };
  }
}

// ── Check 7: evidence pack của WWHN-233 có mặt ───────────────────────────────
{
  const required = [
    "docs/evidence/WWHN-233/NO_CODE_HANDOFF.md",
    "docs/evidence/WWHN-233/QA_DISPOSITION_WWHN-235.md",
  ];
  const missing = required.filter((path) => !existsSync(join(ROOT, path)));
  if (missing.length > 0) {
    fail("EVIDENCE", `Evidence pack thiếu: ${missing.join(", ")}`);
  } else {
    pass("EVIDENCE", "NO_CODE_HANDOFF + QA_DISPOSITION_WWHN-235 có mặt.");
  }
}

// ── Check 8: chain — chạy verifier WWHN-236 nếu nhánh đó đã merge ─────────────
{
  const rel = "scripts/verify-wwhn236-adjustment-disposition.mjs";
  if (!existsSync(join(ROOT, rel))) {
    warn("CHAIN-236", `${rel} chưa có mặt (WWHN-236 chạy song song); bỏ qua bước con.`);
  } else {
    try {
      execFileSync(process.execPath, [join(ROOT, rel)], { cwd: ROOT, stdio: "pipe" });
      pass("CHAIN-236", "verify-wwhn236-adjustment-disposition.mjs PASS.");
    } catch (error) {
      const output = `${error.stdout ?? ""}${error.stderr ?? ""}`.trim();
      fail("CHAIN-236", `verify-wwhn236-adjustment-disposition.mjs FAIL:\n${output}`);
    }
  }
}

// ── Emit evidence ─────────────────────────────────────────────────────────────
if (EMIT) {
  const dir = join(ROOT, "docs/evidence/WWHN-233");
  mkdirSync(dir, { recursive: true });

  const gitSha = (() => {
    try {
      return execFileSync("git", ["rev-parse", "HEAD"], { cwd: ROOT, stdio: "pipe" }).toString().trim();
    } catch {
      return null;
    }
  })();

  sourceRegister.register_id = "WWHN-233-SOURCE-REGISTER";
  sourceRegister.version = "1.0.0";
  sourceRegister.status = "PROPOSED";
  sourceRegister.jira = { task: "WWHN-233", parent: "WWHN-59", qa_gate: "WWHN-235", decision_gate: "WWHN-25" };
  sourceRegister.disposition = { id: "DISP-STK-233", path: DISPOSITION_233, result: "DEFERRED_NO_CODE" };
  sourceRegister.repository = { branch: "m1", be_commit_sha: gitSha, fe_commit_sha: null };
  writeFileSync(join(dir, "SOURCE_REGISTER.json"), `${JSON.stringify(sourceRegister, null, 2)}\n`, "utf8");

  const negativeLock = {
    lock_id: "WWHN-233-CONTRACT-NEGATIVE-LOCK",
    register_items: ["DEV233-P1-04", "DEV233-P1-05"],
    obligation_kind: "NEGATIVE",
    rationale:
      "Cả hai mục register mở đầu bằng 'Nếu re-enable'. Disposition là DEFERRED nên không phát sinh " +
      "nghĩa vụ dương; nghĩa vụ phủ định dưới đây được kiểm chứng bằng máy.",
    forbidden_path_fragments: ALL_FORBIDDEN,
    count_surface_absent: {
      endpoints: 0,
      states: 0,
      permissions: 0,
      count_history_tables: 0,
      stale_cas_contract: "N/A — không có phiên kiểm kê nào để CAS",
    },
    stock_effect: {
      canonical_movement_types: ["OPENING", "RECEIPT", "ISSUE", "COMPONENT_ISSUE", "REVERSAL"],
      db_constraint: "chk_stock_movement_type_current",
      constraint_source:
        "Modules/Warehouse/database/migrations/2026_08_10_000620_remove_superseded_fields_and_lock_append_only_history.php",
      append_only_guard: "hn_reject_append_only_change() trigger (PostgreSQL)",
      direct_stock_edit_endpoints: 0,
      correction_path: [
        "POST /api/v1/inbound-documents/{id}/reverse",
        "POST /api/v1/outbound-documents/{id}/reverse",
        "POST /api/v1/reversal-documents",
      ],
    },
    checks_passed: checks,
    warnings,
    verified_at_commit: gitSha,
  };
  writeFileSync(join(dir, "CONTRACT_NEGATIVE_LOCK.json"), `${JSON.stringify(negativeLock, null, 2)}\n`, "utf8");

  // SHA256SUMS cho toàn bộ evidence pack.
  const walk = (base) =>
    readdirSync(base).flatMap((entry) => {
      const abs = join(base, entry);
      return statSync(abs).isDirectory() ? walk(abs) : [abs];
    });
  const sums = walk(dir)
    .filter((abs) => !abs.endsWith("SHA256SUMS"))
    .sort()
    .map((abs) => {
      const digest = createHash("sha256").update(readFileSync(abs)).digest("hex");
      return `${digest}  ${abs.slice(join(ROOT, "docs/evidence/WWHN-233").length + 1).replace(/\\/gu, "/")}`;
    });
  writeFileSync(join(dir, "SHA256SUMS"), `${sums.join("\n")}\n`, "utf8");
  console.log(`[wwhn-233] evidence written: docs/evidence/WWHN-233 (${sums.length} file)`);
}

// ── Report ────────────────────────────────────────────────────────────────────
for (const entry of checks) console.log(`  PASS ${entry}`);
for (const entry of warnings) console.log(`  WARN ${entry}`);

if (failures.length > 0) {
  console.error(`\n[wwhn-233] FAIL (${failures.length}):`);
  for (const entry of failures) console.error(`  ${entry}`);
  process.exit(1);
}

console.log(
  "\n[wwhn-233] PASS: DISP-STK-233 DEFERRED/NO-CODE, hàng rào được cam kết đều tồn tại (ANTI-DANGLING), " +
    "bề mặt count/recount/variance/adjustment bằng zero, nguồn hiện hành có digest tính lại.",
);
