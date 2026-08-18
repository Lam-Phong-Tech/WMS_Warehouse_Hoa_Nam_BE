#!/usr/bin/env node
/**
 * WWHN-236 / DEV236-P1-02 — sinh source register hiện hành.
 *
 * Register yêu cầu "Current source register + version/hash + AC disposition". Hash phải
 * đo lại được chứ không chép tay, nếu không thì vòng rà soát sau lại phải tin vào một
 * con số không ai kiểm chứng được.
 *
 *   node scripts/build-wwhn236-source-register.mjs
 *
 * Ghi docs/evidence/WWHN-236/SOURCE_REGISTER.json.
 */
import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const BE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const FE_ROOT = resolve(BE_ROOT, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE");

const digest = (abs) =>
  existsSync(abs) ? createHash("sha256").update(readFileSync(abs)).digest("hex") : null;

const git = (root, args) => {
  try {
    return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim();
  } catch {
    return null;
  }
};

const repoIdentity = (root, label) => {
  if (!existsSync(root)) return { repo: label, available: false };
  const status = git(root, ["status", "--porcelain"]);
  return {
    repo: label,
    branch: git(root, ["rev-parse", "--abbrev-ref", "HEAD"]),
    head_sha: git(root, ["rev-parse", "HEAD"]),
    working_tree: status === "" ? "CLEAN" : "DIRTY",
    working_tree_note:
      status === ""
        ? null
        : "Working tree có thay đổi chưa commit tại thời điểm sinh register. Exact SHA của candidate phải được pin lại sau khi commit — xem SHA256SUMS.",
    dirty_file_count: status === "" ? 0 : status.split("\n").filter(Boolean).length,
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
    anchor: "REQ-SRS-037 (Must) / AC-SRS-037 / TC-SRS-037 / BR-NOSTK-01..03; §5.3; dòng 65, 180, 235",
  },
  {
    id: "ERD",
    root: FE_ROOT,
    path: "docs/HoaNam_ERD_Moi_Nhat.md",
    version: "latest",
    role: "CROSS-CONFIRM",
    anchor: "§0.1 dòng 95 — không tạo Stocktake_Session/Snapshot/Count/Variance_Line/Adjustment",
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
    path: "docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md",
    version: "1.0.0",
    role: "DISPOSITION",
    anchor: "Chốt authority + disposition DEFERRED cho WWHN-60/236/238 + HN-STK-002",
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

const payload = {
  document: "SOURCE_REGISTER",
  task: "WWHN-236",
  register_item: "DEV236-P1-02",
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
  candidate_identity: [repoIdentity(BE_ROOT, "BE"), repoIdentity(FE_ROOT, "FE")],
  controlling_sources: controlling,
  historical_sources: historical,
  missing_sources: missing.map((e) => `${e.repo}:${e.path}`),
};

const outDir = join(BE_ROOT, "docs/evidence/WWHN-236");
mkdirSync(outDir, { recursive: true });
writeFileSync(join(outDir, "SOURCE_REGISTER.json"), `${JSON.stringify(payload, null, 2)}\n`);

console.log("WWHN-236 source register");
for (const e of controlling) console.log(`  ${e.role.padEnd(14)} ${e.id.padEnd(20)} ${e.sha256 ?? "MISSING"}`);
for (const e of historical) console.log(`  ${e.role.padEnd(14)} ${e.id.padEnd(20)} ${e.sha256 ?? "n/a"}`);

if (missing.length > 0) {
  console.error(`\nFAIL: thiếu ${missing.length} nguồn: ${missing.map((e) => e.path).join(", ")}`);
  process.exit(1);
}
console.log("\n→ docs/evidence/WWHN-236/SOURCE_REGISTER.json");
