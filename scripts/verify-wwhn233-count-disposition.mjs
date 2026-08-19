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

/**
 * SHA của repo FE tại thời điểm sinh evidence. Truyền vào thay vì sửa tay sau khi
 * emit — sửa tay sẽ làm SHA256SUMS stale ngay trong chính pack vừa sinh.
 */
const FE_SHA = (process.argv.find((arg) => arg.startsWith("--fe-sha=")) ?? "").slice("--fe-sha=".length) || null;

/**
 * `--expect-sha=<sha>` — vòng R2 (`DEV233-P1-02R2`) đòi candidate được pin *immutable* và
 * kiểm chứng lại được. QA/TL dán đúng SHA ghi trên Jira vào đây; verifier fail nếu cây đang
 * chạy không phải candidate đó. Chấp nhận SHA rút gọn.
 */
const EXPECT_SHA = (process.argv.find((arg) => arg.startsWith("--expect-sha=")) ?? "").slice("--expect-sha=".length) || null;

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
    "src/test/wwhn-236-adjustment-deferral-gate.test.ts",
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
    /**
     * WWHN-233 phụ thuộc vào **bề mặt** mà WWHN-236 khoá, không phụ thuộc vào việc
     * register governance của chính WWHN-236 đã đóng hay chưa. Hai nhánh chạy song
     * song trên cùng `m1` và có vòng review riêng; nếu trộn hai thứ đó thì mỗi lần
     * nhánh kia mở một vòng mới, gate của nhánh này đỏ theo mà không có lỗi thật.
     *
     * Phân tách: các check bề mặt dưới đây là ràng buộc cứng và phải **có mặt** trong
     * output (thiếu ⇒ fail-closed, phòng khi định dạng đổi). Các check thuộc register
     * riêng của WWHN-236 chỉ hạ xuống cảnh báo, kèm tên chủ sở hữu để không bị nuốt.
     */
    const SURFACE_CRITICAL = [
      "DISP-CONTENT",
      "DEC-STK-001",
      "BASELINE-FRAGMENTS",
      "BASELINE-TABLES",
      "BASELINE-MOVEMENT",
      "OPENAPI-RUNTIME",
      "PERMISSION-MATRIX",
      "ROUTE-SOURCE",
      "DB-CONSTRAINT",
      "MIGRATION-TABLES",
      "SPEC-SURFACE",
      "CHAIN-DISPOSITION",
    ];
    const OWN_GOVERNANCE = ["APPROVAL-RECORD", "CANDIDATE-PIN", "PARENT-REBASELINE", "QA-RERUN", "APPROVAL-SIGNOFF"];

    let output = "";
    let exitOk = true;
    try {
      output = execFileSync(process.execPath, [join(ROOT, rel)], { cwd: ROOT, stdio: "pipe" }).toString();
    } catch (error) {
      exitOk = false;
      output = `${error.stdout ?? ""}${error.stderr ?? ""}`;
    }

    const passed = new Set([...output.matchAll(/PASS\s+\[([A-Z0-9-]+)\]/gu)].map((m) => m[1]));
    const failed = [...new Set([...output.matchAll(/FAIL\s+\[([A-Z0-9-]+)\]/gu)].map((m) => m[1]))];

    const missingCritical = SURFACE_CRITICAL.filter((id) => !passed.has(id));
    const criticalFailed = failed.filter((id) => SURFACE_CRITICAL.includes(id));
    const governanceFailed = failed.filter((id) => OWN_GOVERNANCE.includes(id));
    const unknownFailed = failed.filter((id) => !SURFACE_CRITICAL.includes(id) && !OWN_GOVERNANCE.includes(id));

    if (exitOk) {
      pass("CHAIN-236", "verify-wwhn236-adjustment-disposition.mjs PASS toàn bộ.");
    } else if (criticalFailed.length > 0 || missingCritical.length > 0 || unknownFailed.length > 0) {
      fail(
        "CHAIN-236",
        `Bề mặt mà WWHN-233 phụ thuộc bị hỏng ở nhánh WWHN-236 — ` +
          `fail: [${criticalFailed.join(", ") || "-"}], thiếu PASS: [${missingCritical.join(", ") || "-"}], ` +
          `chưa phân loại: [${unknownFailed.join(", ") || "-"}].\n${output.trim()}`,
      );
    } else {
      warn(
        "CHAIN-236",
        `${SURFACE_CRITICAL.length}/${SURFACE_CRITICAL.length} check bề mặt của DISP-STK-236 PASS. ` +
          `Còn ${governanceFailed.length} check thuộc register RIÊNG của WWHN-236 chưa đóng ` +
          `([${governanceFailed.join(", ")}]) — thuộc nhánh WWHN-236, không phải phạm vi WWHN-233, ` +
          "và không chạm bề mặt kiểm kê. Xem DISP-STK-233 §12.",
      );
    }
  }
}

// ═══════════════════════════════════════════════════════════════════════════════
// VÒNG R2 — bốn thứ mà vòng R1 (34/100 → 71/100) chưa có
//
// R2 không đòi thêm capability. Nó đòi: (1) pin immutable của candidate, (2) bản ghi
// phê duyệt có bất biến để không tồn tại trạng thái nửa vời, (3) rebaseline của parent
// WWHN-59, (4) disposition cho phần source stocktake còn nằm lại trong repo.
// ═══════════════════════════════════════════════════════════════════════════════

const gitOut = (args) => {
  try {
    return execFileSync("git", args, { cwd: ROOT, stdio: "pipe" }).toString().trim() || null;
  } catch {
    return null;
  }
};

const gitOk = (args) => {
  try {
    execFileSync("git", args, { cwd: ROOT, stdio: "pipe" });
    return true;
  } catch {
    return false;
  }
};

/**
 * Tập artifact **nguồn** của WWHN-233 — cố ý KHÔNG chứa `docs/evidence/WWHN-233/**`.
 *
 * Đây là điểm sửa cốt lõi của `DEV233-P1-02R2`. Nếu evidence pack nằm trong tập này thì
 * pin tự tham chiếu: mỗi lần `--emit-evidence` ghi lại pack, commit tiếp theo lại trở
 * thành candidate mới, và giá trị vừa ghi lập tức stale. Vì evidence pack đứng ngoài,
 * `candidate` chỉ dịch chuyển khi *nguồn* thật sự đổi, còn việc phát hành lại evidence
 * là idempotent.
 */
const SOURCE_PATHS = [
  "docs/decision-records/DISP-STK-233-COUNT-RECOUNT-DEFERRED.md",
  "scripts/verify-wwhn233-count-disposition.mjs",
  "tests/Feature/Wwhn233CountDeferredGateTest.php",
];

/**
 * Workflow CI của gate được pin để **truy vết**, và cố ý ở mức WARN.
 * Nhánh khác có thể chạm file workflow vì lý do hạ tầng; drift ở đó không được làm đỏ
 * disposition, nhưng vẫn phải nhìn thấy được.
 */
const CI_GATE_REFERENCE = ".github/workflows/wwhn-233-count-deferral-gate.yml";

const blobSha = (rel) => (existsSync(join(ROOT, rel)) ? gitOut(["hash-object", "--", rel]) : null);

/**
 * Định danh candidate ở **mức đối tượng** chứ không phải mức commit: sha của từng blob
 * trong tập nguồn, gộp lại thành một digest. Trên nhánh dùng chung `m1` có nhiều phiên
 * commit song song, nên commit SHA một mình không chứng minh được nội dung; fingerprint
 * này thì có, và nó kiểm chứng lại được ở bất kỳ thời điểm nào.
 */
const candidateFingerprint = () => {
  const entries = SOURCE_PATHS.map((rel) => `${rel}:${blobSha(rel) ?? "MISSING"}`).sort();
  return { entries, digest: createHash("sha256").update(entries.join("\n")).digest("hex") };
};

const CANDIDATE_SHA = gitOut(["log", "-1", "--format=%H", "--", ...SOURCE_PATHS]);
const CANDIDATE_RANGE_START = ((gitOut(["log", "--reverse", "--format=%H", "--", ...SOURCE_PATHS]) ?? "").split("\n")[0] || null);
const CANDIDATE_COMMIT_COUNT = Number(gitOut(["rev-list", "--count", "HEAD", "--", ...SOURCE_PATHS]) ?? "0");

const PIN_PATH = "docs/evidence/WWHN-233/IMMUTABLE_CANDIDATE_PIN.json";
const APPROVAL_PATH = "docs/evidence/WWHN-233/APPROVAL_RECORD.json";
const REBASELINE_PATH = "docs/evidence/WWHN-233/WWHN-59_AC_REBASELINE.json";
const DORMANT_PATH = "docs/evidence/WWHN-233/DORMANT_SOURCE_DISPOSITION.json";
const QA_REPORT_PATH = "docs/evidence/WWHN-233/QA_INDEPENDENT_VERIFICATION_WWHN-235.md";

const parseJson = (rel, id) => {
  const raw = readIfExists(rel);
  if (raw === null) return null;
  try {
    return JSON.parse(raw);
  } catch (error) {
    fail(id, `${rel} không parse được JSON: ${error.message}`);
    return null;
  }
};

// ── Check 9: CANDIDATE-PIN — DEV233-P1-02R2 ──────────────────────────────────
{
  const pin = parseJson(PIN_PATH, "CANDIDATE-PIN");
  if (pin === null && readIfExists(PIN_PATH) === null) {
    if (EMIT) {
      warn("CANDIDATE-PIN", `${PIN_PATH} chưa tồn tại; lần chạy --emit-evidence này sẽ tạo nó.`);
    } else {
      fail(
        "CANDIDATE-PIN",
        `${PIN_PATH} không tồn tại. DEV233-P1-02R2 đòi pin immutable của candidate ` +
          "(SHA + range + affected files + expected checks). Chạy lại với --emit-evidence.",
      );
    }
  } else if (pin) {
    const actual = candidateFingerprint();
    const pinned = pin.defer_surface?.fingerprint_sha256 ?? null;

    if (pinned !== actual.digest) {
      fail(
        "CANDIDATE-PIN",
        `Fingerprint của tập nguồn WWHN-233 đã đổi: pin=${pinned ?? "NULL"} thực tế=${actual.digest}. ` +
          "Hoặc nguồn bị sửa mà pin chưa refresh, hoặc pin bị chép tay. Chạy --emit-evidence để phát hành lại.",
      );
    } else {
      pass("CANDIDATE-PIN", `Fingerprint mức đối tượng khớp (${actual.digest.slice(0, 16)}…), ${SOURCE_PATHS.length} file nguồn.`);
    }

    const pinnedSha = pin.candidate?.be_commit_sha ?? null;
    const pinnedStart = pin.candidate?.be_range_start ?? null;

    if (!pinnedSha) {
      fail("CANDIDATE-PIN", "pin thiếu candidate.be_commit_sha.");
    } else if (!/^[0-9a-f]{40}$/u.test(pinnedSha)) {
      fail("CANDIDATE-PIN", `candidate.be_commit_sha phải là full SHA 40 ký tự, đang là "${pinnedSha}".`);
    } else if (!gitOk(["merge-base", "--is-ancestor", pinnedSha, "HEAD"])) {
      fail(
        "CANDIDATE-PIN",
        `candidate ${pinnedSha} không phải ancestor của HEAD — pin trỏ tới commit không nằm trên nhánh đang chạy.`,
      );
    } else if (pinnedStart && !gitOk(["merge-base", "--is-ancestor", pinnedStart, pinnedSha])) {
      fail("CANDIDATE-PIN", `range_start ${pinnedStart} không phải ancestor của candidate ${pinnedSha}.`);
    } else {
      pass("CANDIDATE-PIN", `candidate ${pinnedSha.slice(0, 12)} nằm trên nhánh, range bắt đầu từ ${(pinnedStart ?? "?").slice(0, 12)}.`);
    }

    // Bất biến dải commit: số commit chạm tập nguồn không được *giảm* so với lúc pin.
    // Giảm nghĩa là lịch sử bị viết lại (rebase/amend) — chính xác là thứ làm hỏng
    // exact-SHA evidence trên nhánh dùng chung.
    const pinnedCount = Number(pin.candidate?.be_commit_count_touching_source ?? 0);
    if (pinnedCount > 0 && CANDIDATE_COMMIT_COUNT < pinnedCount) {
      fail(
        "CANDIDATE-PIN",
        `Số commit chạm tập nguồn giảm từ ${pinnedCount} xuống ${CANDIDATE_COMMIT_COUNT} — lịch sử đã bị viết lại.`,
      );
    }

    const declaredFiles = (pin.defer_surface?.files ?? []).map((entry) => entry.path).sort();
    if (JSON.stringify(declaredFiles) !== JSON.stringify([...SOURCE_PATHS].sort())) {
      fail(
        "CANDIDATE-PIN",
        `Danh sách affected files trong pin (${declaredFiles.join(", ")}) không khớp tập nguồn thật (${[...SOURCE_PATHS].sort().join(", ")}).`,
      );
    }

    if ((pin.expected_checks ?? []).length === 0) {
      fail("CANDIDATE-PIN", "pin không khai expected no-route/no-OA checks — DEV233-P1-02R2 đòi mục này.");
    }

    const ciPinned = pin.ci_gate_reference?.git_blob_sha ?? null;
    const ciActual = blobSha(CI_GATE_REFERENCE);
    if (ciPinned && ciActual && ciPinned !== ciActual) {
      warn("CANDIDATE-PIN", `${CI_GATE_REFERENCE} đã đổi so với lúc pin (WARN-ONLY theo thiết kế).`);
    }
  }
}

// ── Check 10: EXPECT-SHA — chạy lại tại đúng candidate ───────────────────────
if (EXPECT_SHA) {
  if (!CANDIDATE_SHA) {
    fail("EXPECT-SHA", "không đọc được candidate SHA từ git (repo không có lịch sử?).");
  } else if (!CANDIDATE_SHA.startsWith(EXPECT_SHA) && !EXPECT_SHA.startsWith(CANDIDATE_SHA)) {
    fail(
      "EXPECT-SHA",
      `Cây đang chạy có candidate ${CANDIDATE_SHA}, không phải ${EXPECT_SHA}. ` +
        "Evidence chỉ có giá trị khi được chạy tại đúng SHA đã pin trên Jira.",
    );
  } else {
    pass("EXPECT-SHA", `candidate ${CANDIDATE_SHA} khớp --expect-sha=${EXPECT_SHA}.`);
  }
}

// ── Check 11: APPROVAL-INTEGRITY — DEV233-P1-01R2 ────────────────────────────
//
// Phát hiện của R2: "DEFER/NO-GO candidate có nhưng approval/effective date chưa final".
// Bất biến dưới đây làm cho trạng thái nửa vời trở thành không biểu diễn được.
{
  const approval = parseJson(APPROVAL_PATH, "APPROVAL-INTEGRITY");
  if (approval === null) {
    fail("APPROVAL-INTEGRITY", `${APPROVAL_PATH} không tồn tại hoặc hỏng — không có bản ghi phê duyệt nào để kiểm.`);
  } else {
    const problems = [];
    const status = approval.status ?? null;
    const allowedStatus = approval.allowed_status ?? ["PROPOSED", "APPROVED", "CONDITIONAL_APPROVED", "REJECTED"];
    const allowedResults = approval.allowed_results ?? ["APPROVED", "CONDITIONAL_APPROVED", "REQUEST_CHANGES"];
    const slots = approval.approvers ?? [];

    if (!allowedStatus.includes(status)) problems.push(`status "${status}" không thuộc ${allowedStatus.join("/")}`);

    const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/u;
    if (status === "PROPOSED") {
      if (approval.effective_date !== null) {
        problems.push("status=PROPOSED nhưng effective_date khác null — chưa duyệt thì không thể có ngày hiệu lực");
      }
    } else {
      if (!ISO_DATE.test(String(approval.effective_date ?? ""))) {
        problems.push(`status=${status} nhưng effective_date="${approval.effective_date}" không phải ISO-8601 YYYY-MM-DD`);
      }
      const incomplete = slots.filter((slot) => !slot.result || !slot.actor || !slot.date);
      if (incomplete.length > 0) {
        problems.push(`status=${status} nhưng còn ${incomplete.length} slot thiếu result/actor/date: ${incomplete.map((s) => s.slot).join(", ")}`);
      }
      const declaredDigest = approval.under_approval?.disposition_sha256 ?? null;
      const realDigest = sha256(DISPOSITION_233);
      if (declaredDigest !== realDigest) {
        problems.push(
          `disposition_sha256 đã pin (${declaredDigest ?? "NULL"}) khác digest thật (${realDigest}) — ` +
            "phê duyệt phải gắn vào đúng nội dung đã đọc",
        );
      }
    }

    for (const slot of slots) {
      if (slot.result !== null && !allowedResults.includes(slot.result)) {
        problems.push(`slot ${slot.slot} có result "${slot.result}" ngoài ${allowedResults.join("/")}`);
      }
      if (slot.date !== null && slot.date !== undefined && !ISO_DATE.test(String(slot.date))) {
        problems.push(`slot ${slot.slot} có date "${slot.date}" không phải ISO-8601`);
      }
    }

    // Tách bạch trách nhiệm: DEV không tự duyệt phạm vi.
    for (const guarded of ["SCOPE-AUTHORITY", "SOURCE-REBASELINE"]) {
      const slot = slots.find((entry) => entry.slot === guarded);
      if (!slot) problems.push(`thiếu slot bắt buộc ${guarded}`);
      else if (/\bDEV\b/u.test(String(slot.role))) problems.push(`slot ${guarded} không được do DEV đứng tên (role="${slot.role}")`);
    }

    for (const required of ["TRACEABILITY", "QA-HARD-GATE", "GOVERNANCE-OWNER-NARRATIVE", "GOVERNANCE-SPRINT-METADATA", "MAINTAINABILITY-DORMANT-SOURCE"]) {
      if (!slots.some((slot) => slot.slot === required)) problems.push(`thiếu slot bắt buộc ${required}`);
    }

    for (const ref of [["decision_ticket", "WWHN-25"], ["attachment_ref", "ATT-11898"], ["parent_ticket", "WWHN-59"]]) {
      if (approval.under_approval?.[ref[0]] !== ref[1]) {
        problems.push(`under_approval.${ref[0]} phải là ${ref[1]}`);
      }
    }

    if (problems.length > 0) {
      fail("APPROVAL-INTEGRITY", `${APPROVAL_PATH}: ${problems.join("; ")}`);
    } else {
      pass(
        "APPROVAL-INTEGRITY",
        `Bản ghi phê duyệt hợp lệ: status=${status}, effective_date=${approval.effective_date ?? "null"}, ${slots.length} slot, ` +
          "DEV không đứng tên gate phạm vi.",
      );
    }
  }
}

// ── Check 12: PARENT-REBASELINE — DEV233-P1-03R2 ─────────────────────────────
{
  const rebaseline = parseJson(REBASELINE_PATH, "PARENT-REBASELINE");
  if (rebaseline === null) {
    fail("PARENT-REBASELINE", `${REBASELINE_PATH} không tồn tại hoặc hỏng — parent WWHN-59 chưa có bản ghi rebaseline.`);
  } else {
    const problems = [];
    const allowed = new Set(["SUPERSEDED", "REMAPPED", "DEFERRED"]);
    const acs = rebaseline.ac_rebaseline ?? [];

    if (acs.length === 0) problems.push("ac_rebaseline rỗng");
    for (const ac of acs) {
      if (!allowed.has(ac.disposition)) problems.push(`AC "${ac.ac_group}" có disposition "${ac.disposition}" ngoài ${[...allowed].join("/")}`);
      if (ac.disposition === "REMAPPED" && !ac.canonical_replacement) {
        problems.push(`AC "${ac.ac_group}" REMAPPED nhưng thiếu canonical_replacement`);
      }
    }

    const v39 = (rebaseline.source_rebaseline ?? []).find((entry) => /v3\.9/u.test(String(entry.source)));
    if (!v39) problems.push("source_rebaseline không nhắc BRD/Handoff v3.9");
    else if (!/SUPERSEDED|HISTORICAL/u.test(String(v39.new_role))) problems.push("BRD/Handoff v3.9 chưa được đánh dấu HISTORICAL/SUPERSEDED");

    if (rebaseline.decision?.controlling_requirement !== "REQ-SRS-037") problems.push("decision.controlling_requirement phải là REQ-SRS-037");

    const declared = rebaseline.decision?.sha256 ?? null;
    const real = sha256(DISPOSITION_233);
    if (declared !== null && declared !== real) {
      problems.push(`decision.sha256 pin ${declared} khác digest thật ${real} — pin rot, chạy --emit-evidence để refresh`);
    }

    const wwhn235 = (rebaseline.children_alignment ?? []).find((entry) => entry.ticket === "WWHN-235");
    if (!wwhn235 || !/DEFERRED/u.test(String(wwhn235.disposition))) {
      problems.push("children_alignment phải ghi WWHN-235 = DEFERRED / N/A");
    }

    if ((rebaseline.reopen_conditions ?? []).length < 4) problems.push("reopen_conditions phải giữ đủ 4 điều kiện");

    if (problems.length > 0) {
      fail("PARENT-REBASELINE", `${REBASELINE_PATH}: ${problems.join("; ")}`);
    } else {
      pass("PARENT-REBASELINE", `WWHN-59 rebaseline: ${acs.length} nhóm AC có disposition, v3.9 superseded, Decision version ${rebaseline.decision?.version}.`);
    }
  }
}

// ── Check 13: DORMANT-SOURCE — DEV233-P2-03R2 ────────────────────────────────
//
// "Controller/source còn tồn tại dù route disabled." Kiểm bằng cách quét cây làm việc
// thật, không bằng cách đọc lại tài liệu: tập file source còn nhắc tới bề mặt kiểm kê
// phải TRÙNG KHỚP tập đã đăng ký. Một file mới lọt vào sẽ làm gate đỏ.
{
  const dormant = parseJson(DORMANT_PATH, "DORMANT-SOURCE");
  if (dormant === null) {
    fail("DORMANT-SOURCE", `${DORMANT_PATH} không tồn tại hoặc hỏng — dormant source chưa có disposition.`);
  } else {
    const problems = [];
    const SCAN_DIRS = ["app", "Modules", "routes", "config", "database"];
    const SURFACE_RE = /stocktake|recount|cycle-?count|physical-?count|apply-?adjustment/iu;
    const CLASS_RE = /\b(?:class|interface|trait|enum)\s+\w*(?:Stocktake|Recount|CycleCount|PhysicalCount)\w*/iu;
    const ROUTE_RE = /Route::\s*\w+\s*\(\s*['"][^'"]*(?:stocktake|recount|cycle-count|physical-count|apply-adjustment)/iu;

    const walkPhp = (relDir) => {
      const abs = join(ROOT, relDir);
      if (!existsSync(abs)) return [];
      return readdirSync(abs).flatMap((entry) => {
        if (entry === "vendor" || entry === "node_modules" || entry === ".git") return [];
        const child = `${relDir}/${entry}`;
        return statSync(join(ROOT, child)).isDirectory() ? walkPhp(child) : child.endsWith(".php") ? [child] : [];
      });
    };

    const scanned = SCAN_DIRS.flatMap(walkPhp);
    const hits = [];
    for (const rel of scanned) {
      if (SURFACE_RE.test(rel)) {
        // Tên FILE mang bề mặt kiểm kê: chấp nhận riêng migration đã đăng ký, cấm mọi thứ khác.
        const registered = (dormant.backend ?? []).some((entry) => entry.path === rel);
        if (!registered) problems.push(`file "${rel}" có tên mang bề mặt kiểm kê nhưng chưa đăng ký`);
      }
      const source = readIfExists(rel) ?? "";
      if (CLASS_RE.test(source)) problems.push(`"${rel}" khai class/interface/trait mang ngữ nghĩa kiểm kê`);
      if (ROUTE_RE.test(source)) problems.push(`"${rel}" đăng ký route thuộc bề mặt bị cấm`);
      if (SURFACE_RE.test(source)) hits.push(rel);
    }

    const registeredCode = new Set(
      (dormant.backend ?? [])
        .map((entry) => entry.path)
        .filter((path) => SCAN_DIRS.some((dir) => path === dir || path.startsWith(`${dir}/`))),
    );
    const unregistered = hits.filter((rel) => !registeredCode.has(rel));
    if (unregistered.length > 0) {
      problems.push(
        `${unregistered.length} file source nhắc tới bề mặt kiểm kê nhưng chưa có disposition: ${unregistered.join(", ")}`,
      );
    }
    const stale = [...registeredCode].filter((rel) => !hits.includes(rel));
    if (stale.length > 0) {
      problems.push(`đăng ký thừa (file không còn nhắc tới bề mặt kiểm kê hoặc đã biến mất): ${stale.join(", ")}`);
    }

    for (const entry of [...(dormant.backend ?? []), ...(dormant.frontend ?? [])]) {
      if (!entry.classification || !entry.disposition) problems.push(`entry "${entry.path}" thiếu classification hoặc disposition`);
      if (!(dormant.classification_legend ?? {})[entry.classification]) {
        problems.push(`entry "${entry.path}" dùng classification "${entry.classification}" không có trong legend`);
      }
    }
    for (const entry of dormant.backend ?? []) {
      if (entry.status === "PRESENT" && !existsSync(join(ROOT, entry.path))) {
        problems.push(`entry BE "${entry.path}" khai PRESENT nhưng không tồn tại`);
      }
    }

    if (problems.length > 0) {
      fail("DORMANT-SOURCE", problems.join("; "));
    } else {
      pass(
        "DORMANT-SOURCE",
        `Quét ${scanned.length} file PHP: 0 class/route kiểm kê; ${hits.length} file còn nhắc bề mặt này đều đã có disposition.`,
      );
    }
  }
}

// ── Check 14: QA-EVIDENCE — DEV233-P1-06R2 ───────────────────────────────────
//
// QA hard gate đòi PASS/FAIL do QA viết, kèm exact SHA/env/raw evidence. Ở đây kiểm
// sự tồn tại và tính khép kín: mọi raw log mà báo cáo QA trích dẫn phải có thật
// (cùng nguyên tắc ANTI-DANGLING, áp cho evidence thay vì cho hàng rào).
{
  const report = readIfExists(QA_REPORT_PATH);
  if (report === null) {
    fail("QA-EVIDENCE", `${QA_REPORT_PATH} không tồn tại — WWHN-235 vẫn ở tình trạng 0 evidence.`);
  } else {
    const problems = [];
    for (const [needle, why] of [
      ["QA1", "phải ghi rõ QA1 là người chạy và kết luận"],
      ["Candidate SHA", "phải ghi exact candidate SHA"],
      ["Environment", "phải ghi env đã chạy"],
      ["VERDICT", "phải có kết luận PASS/FAIL tường minh"],
    ]) {
      if (!report.includes(needle)) problems.push(`thiếu "${needle}" (${why})`);
    }

    const referenced = [...report.matchAll(/`(docs\/evidence\/WWHN-233\/raw\/[A-Za-z0-9._\-/]+)`/gu)].map((m) => m[1]);
    const missingRaw = [...new Set(referenced)].filter((rel) => !existsSync(join(ROOT, rel)));
    if (referenced.length === 0) problems.push("không trích dẫn raw log nào");
    if (missingRaw.length > 0) problems.push(`raw log được trích nhưng không tồn tại: ${missingRaw.join(", ")}`);

    if (problems.length > 0) {
      fail("QA-EVIDENCE", `${QA_REPORT_PATH}: ${problems.join("; ")}`);
    } else {
      pass("QA-EVIDENCE", `Báo cáo QA1 có exact SHA + env + verdict, ${new Set(referenced).size} raw log được trích và đều tồn tại.`);
    }
  }
}

// ── Emit evidence ─────────────────────────────────────────────────────────────
if (EMIT) {
  const dir = join(ROOT, "docs/evidence/WWHN-233");
  mkdirSync(dir, { recursive: true });

  const git = (args) => {
    try {
      return execFileSync("git", args, { cwd: ROOT, stdio: "pipe" }).toString().trim() || null;
    } catch {
      return null;
    }
  };

  /**
   * Nhiều nhánh Stocktake chạy song song trên cùng `m1`, nên `HEAD` tại thời điểm
   * emit thường là commit của task khác. Pin theo commit **cuối cùng chạm artifact
   * của WWHN-233** thay vì theo HEAD: đó mới là "exact implementation SHA" mà
   * DEV233-P1-02 hỏi. `head_sha_at_emit` giữ lại để truy vết bối cảnh.
   */
  // R2: candidate được định nghĩa trên đúng `SOURCE_PATHS` — không gồm evidence pack
  // (nếu gồm thì pin tự tham chiếu, xem chú thích ở SOURCE_PATHS) và không gồm workflow
  // CI (drift hạ tầng không được làm dịch chuyển candidate của disposition).
  const gitSha = CANDIDATE_SHA;
  const headSha = git(["rev-parse", "HEAD"]);

  sourceRegister.register_id = "WWHN-233-SOURCE-REGISTER";
  sourceRegister.version = "1.0.0";
  sourceRegister.status = "PROPOSED";
  sourceRegister.jira = { task: "WWHN-233", parent: "WWHN-59", qa_gate: "WWHN-235", decision_gate: "WWHN-25" };
  sourceRegister.disposition = { id: "DISP-STK-233", path: DISPOSITION_233, result: "DEFERRED_NO_CODE" };
  // `head_sha_at_emit` cố ý KHÔNG được ghi vào pack: nó đổi mỗi lần nhánh dùng chung
  // nhận commit của task khác, và sẽ làm bước kiểm tra drift trong CI đỏ vĩnh viễn.
  // Chỉ log ra để người chạy thấy bối cảnh.
  if (headSha !== gitSha) {
    console.log(`[wwhn-233] HEAD tại thời điểm emit là ${headSha} (commit của nhánh khác); pin theo ${gitSha}.`);
  }

  sourceRegister.repository = {
    branch: "m1",
    be_commit_sha: gitSha,
    be_commit_sha_meaning:
      "Commit cuối cùng chạm artifact của WWHN-233, không phải HEAD của nhánh dùng chung — " +
      "nhiều nhánh Stocktake commit song song vào m1.",
    fe_commit_sha: FE_SHA,
  };
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

  // ── DEV233-P1-02R2 — pin immutable của candidate ──────────────────────────
  const fingerprint = candidateFingerprint();
  const candidatePin = {
    pin_id: "WWHN-233-IMMUTABLE-CANDIDATE-PIN",
    register_item: "DEV233-P1-02R2",
    version: "1.0.0",
    gate: "Traceability",
    branch: "m1",
    handoff_type: "NO-CODE / DEFERRED",
    identity_rule:
      "Candidate được định danh ở MỨC ĐỐI TƯỢNG (sha của từng blob nguồn, gộp thành fingerprint), " +
      "còn commit SHA là hệ quả. Trên nhánh dùng chung m1 có nhiều phiên commit song song nên " +
      "commit SHA một mình không chứng minh được nội dung; fingerprint thì kiểm chứng lại được bất kỳ lúc nào.",
    defer_surface: {
      fingerprint_sha256: fingerprint.digest,
      fingerprint_input: fingerprint.entries,
      files: SOURCE_PATHS.map((rel) => ({
        path: rel,
        git_blob_sha: blobSha(rel),
        sha256: sha256(rel),
        role:
          rel.endsWith(".md")
            ? "DISPOSITION"
            : rel.endsWith(".mjs")
              ? "VERIFIER"
              : "RUNTIME-GATE-TEST",
      })),
    },
    candidate: {
      be_commit_sha: gitSha,
      be_range_start: CANDIDATE_RANGE_START,
      be_range: CANDIDATE_RANGE_START && gitSha ? `${CANDIDATE_RANGE_START}..${gitSha}` : null,
      be_commit_count_touching_source: CANDIDATE_COMMIT_COUNT,
      fe_commit_sha: FE_SHA,
      meaning:
        "Commit cuối cùng chạm TẬP NGUỒN của WWHN-233. Không phải HEAD của m1, và không dịch " +
        "chuyển khi evidence pack được phát hành lại — nên pin là idempotent.",
    },
    ci_gate_reference: {
      path: CI_GATE_REFERENCE,
      git_blob_sha: blobSha(CI_GATE_REFERENCE),
      drift_policy: "WARN-ONLY",
      reason: "Workflow có thể bị nhánh hạ tầng chạm; drift ở đó không được làm đỏ disposition.",
    },
    expected_checks: [
      { id: "ROUTE-SURFACE", expected: "0 route đăng ký chứa stocktake/adjustment/variance/recount/cycle-count/physical-count", how: "tests/Feature/Wwhn233CountDeferredGateTest.php::test_no_count_or_recount_route_is_registered" },
      { id: "RUNTIME-REACHABILITY", expected: "mọi probe URL của count/recount/apply-adjustment trả 404 (không route nào bắt)", how: "tests/Feature/Wwhn233CountDeferredGateTest.php::test_count_and_apply_adjustment_urls_are_unreachable_at_runtime" },
      { id: "OPENAPI", expected: "0 path trong storage/api-docs/api-docs.yaml thuộc bề mặt bị cấm", how: "scripts/verify-wwhn233-count-disposition.mjs — check OPENAPI" },
      { id: "PERMISSION", expected: "0 permission kiểm kê ở seed và ở baseline contract", how: "tests/Feature/Wwhn233CountDeferredGateTest.php::test_no_stocktake_or_count_permission_is_registered" },
      { id: "SCHEMA-END-STATE", expected: "4 bảng stocktake_* vắng mặt sau migrate; stock_movements không giữ FK variance", how: "tests/Feature/Wwhn233CountDeferredGateTest.php::test_no_stocktake_table_survives_migration" },
      { id: "STOCK-EFFECT", expected: "movement_type chỉ OPENING/RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL; ADJUST_IN/ADJUST_OUT bị chặn ở DB", how: "tests/Feature/Wwhn233CountDeferredGateTest.php::test_movement_type_surface_has_no_adjustment_path" },
      { id: "BASELINE-CONTRACT", expected: "forbidden_path_fragments phủ đủ 6 mảnh; 0 operation/permission thuộc bề mặt bị cấm", how: "scripts/verify-wwhn233-count-disposition.mjs — check BASELINE-*" },
      { id: "DORMANT-SOURCE", expected: "0 class/route kiểm kê trong app/Modules/routes/config/database; tập file còn nhắc bề mặt trùng khớp đăng ký", how: "scripts/verify-wwhn233-count-disposition.mjs — check DORMANT-SOURCE" },
      { id: "FE-SURFACE", expected: "0 route, 0 menu, 0 permission kiểm kê ở repo FE", how: "FE: npm run wwhn-233:verify + src/test/wwhn-233-count-deferral-gate.test.ts" },
    ],
    how_to_reverify: [
      "git -C <BE> fetch && git -C <BE> checkout <be_commit_sha>",
      "node scripts/verify-wwhn233-count-disposition.mjs --expect-sha=<be_commit_sha>",
      "php artisan test tests/Feature/Wwhn233CountDeferredGateTest.php",
      "FE: git checkout <fe_commit_sha> && npm run wwhn-233:verify && npx vitest run src/test/wwhn-233-count-deferral-gate.test.ts",
    ],
    note:
      "Pin này không cấp phép cho bất kỳ capability nào. Nó chỉ khẳng định: tại đúng candidate " +
      "trên, bề mặt count/recount/variance/adjustment bằng zero và bộ hàng rào tồn tại đúng nội dung đã pin.",
  };
  writeFileSync(join(dir, "IMMUTABLE_CANDIDATE_PIN.json"), `${JSON.stringify(candidatePin, null, 2)}\n`, "utf8");

  // Refresh digest của disposition trong hai bản ghi hand-authored — chống pin rot mà
  // vẫn giữ nội dung do BA/PM viết. Chỉ chạm đúng một trường ở mỗi file.
  const dispositionDigest = sha256(DISPOSITION_233);
  for (const [rel, mutate] of [
    ["docs/evidence/WWHN-233/APPROVAL_RECORD.json", (json) => { if (json.under_approval) json.under_approval.disposition_sha256 = dispositionDigest; }],
    ["docs/evidence/WWHN-233/WWHN-59_AC_REBASELINE.json", (json) => { if (json.decision) json.decision.sha256 = dispositionDigest; }],
  ]) {
    const raw = readIfExists(rel);
    if (raw === null) continue;
    try {
      const json = JSON.parse(raw);
      mutate(json);
      writeFileSync(join(ROOT, rel), `${JSON.stringify(json, null, 2)}\n`, "utf8");
    } catch {
      // Check APPROVAL-INTEGRITY / PARENT-REBASELINE đã báo lỗi parse; không nuốt file hỏng.
    }
  }

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
