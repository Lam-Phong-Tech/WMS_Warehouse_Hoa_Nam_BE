#!/usr/bin/env node
/**
 * WWHN-382 — sinh CANDIDATE_PIN.json + CONTRACT_PIN.json tại một commit cụ thể.
 *
 * Mọi hash trong hồ sơ WWHN-382 đều do script này sinh ra, không gõ tay. Ba vòng
 * review trước đều vấp "evidence stale": hash gõ tay không có cách nào tự phát hiện
 * mình đã cũ, còn hash sinh ra thì `verify-wwhn-382-closure.mjs` tính lại được và
 * đỏ ngay khi lệch.
 *
 * Dùng (chạy SAU khi đã commit, để pin trỏ vào đúng commit chứa hồ sơ):
 *   node scripts/wwhn-382-pin-candidate.mjs --be <BE_SHA> --fe <FE_SHA>
 */
import { execFileSync } from "node:child_process";
import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { fingerprintAt } from "./wwhn-328-contract-fingerprint.mjs";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const OUT_DIR = "docs/evidence/WWHN-382";

/**
 * Tập tệp thuộc WWHN-328/WWHN-382 bên trong lịch sử bundled. DEV328-P1-03R4 đòi
 * đúng danh sách này: commit `gỡ cờ N` gộp nhiều task nên "diff của commit" không
 * phải "diff của task".
 */
const SCOPE_FILES = [
  "Modules/Warehouse/app/Http/Controllers/OperationalReportController.php",
  "Modules/Warehouse/routes/api.php",
  "tests/Feature/Wwhn328DashboardQuickContractTest.php",
  "tests/Feature/Wwhn328R4ClosureTest.php",
  "storage/api-docs/api-docs.json",
  "storage/api-docs/api-docs.yaml",
  "docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json",
  "docs/evidence/WWHN-328/KPI_MATRIX.md",
  "docs/evidence/WWHN-328/EVIDENCE_PACK.md",
  "docs/evidence/WWHN-382/R4_CLOSURE.md",
  "docs/evidence/WWHN-382/R4_REGISTER.json",
  "docs/evidence/WWHN-382/SOURCE_SUPERSESSION.md",
  "docs/evidence/WWHN-382/DIFF_SCOPE_MAP.md",
  "docs/evidence/WWHN-382/QA2_RERUN_RUNBOOK.md",
  "docs/docs_task_jira/WWHN-382.md",
  "scripts/wwhn-328-contract-fingerprint.mjs",
  "scripts/wwhn-382-pin-candidate.mjs",
  "scripts/verify-wwhn-382-closure.mjs",
  ".github/workflows/wwhn-382-dashboard-closure.yml",
];

/**
 * Nguồn bị thay thế. Ghi ĐỦ 40 ký tự và ghi lý do: DEV328-P2-01R4 yêu cầu chuyển
 * narrative `m1-be-2` / BE2 xuống mục historical thay vì im lặng bỏ đi.
 */
const SUPERSEDED = [
  {
    scope: "backend-branch",
    ref: "refs/heads/m1-be-2",
    sha: "60816499066f9f493596e326dc41116ef32f5c4b",
    reason:
      "Nhánh lịch sử của luồng BE2. Không chứa data-scope dashboard, không chứa KPI matrix. Không dùng làm nguồn đánh giá WWHN-382.",
  },
  {
    scope: "backend-commit",
    sha: "b8cfcf01eb0ef01661bb58a7c1bb08825e35e8f3",
    reason:
      "Sau SHA này dashboard runtime đổi hai lần (Valkey caching 0b32578, rồi data-scope + cache key). Mọi kết quả test gắn với nó phải chạy lại.",
  },
  {
    scope: "backend-commit",
    sha: "6db0965940a6ae8b22a591d50705f258326b172d",
    reason: "Candidate vòng R3. Bị thay bởi 127c573 (sửa fixture đỏ trên PostgreSQL).",
  },
  {
    scope: "backend-commit",
    sha: "127c573a7236ad1c25829eb92011a4914360b840",
    reason:
      "Candidate vòng R4 đầu vòng. Bị thay vì (a) là commit gộp không mang mã Jira, (b) WWHN-254 đã đổi reportWarehouseScope sau đó, (c) chưa có test khoá ngoại lệ warranty GLOBAL và bất biến đối soát.",
  },
  {
    scope: "frontend-commit",
    sha: "3ddce525e2e3b3af29723d1ec97e3fc9eff175b3",
    reason: "Candidate FE vòng R3, trước khi UI khai ngoại lệ warranty GLOBAL.",
  },
  {
    scope: "frontend-commit",
    sha: "99dff42fb4272ed8930e8fb9bf4da6368f4cfd96",
    reason:
      "Output WWHN-327 được tiêu thụ (ngoại lệ warranty + gate closure FE). Là NGUỒN ĐẦU VÀO đã hợp nhất, không phải candidate cuối.",
  },
  {
    scope: "frontend-commit",
    sha: "4d11989a1b9499dd388ca1e75f408bfc27b183cc",
    reason: "Candidate FE vòng R4 đầu vòng, commit gộp không mang mã Jira.",
  },
];

function git(args, cwd = ROOT) {
  return execFileSync("git", args, { cwd, encoding: "utf8", maxBuffer: 128 * 1024 * 1024 }).trim();
}

function arg(name, fallback = null) {
  const at = process.argv.indexOf(name);
  return at === -1 ? fallback : process.argv[at + 1];
}

const beSha = git(["rev-parse", arg("--be", "HEAD")]);
const feSha = arg("--fe");
if (!feSha || !/^[0-9a-f]{40}$/.test(feSha)) {
  console.error("[WWHN-382] Cần --fe <FE_SHA đủ 40 ký tự>. Pin rút gọn là nguồn của pin rot.");
  process.exit(1);
}

const scope = SCOPE_FILES.map((path) => {
  let blob = null;
  try {
    blob = git(["rev-parse", beSha + ":" + path]);
  } catch {
    blob = null;
  }
  const lastTouch = git([
    "log",
    "-1",
    "--format=%H|%ad|%s",
    "--date=short",
    beSha,
    "--",
    path,
  ]);
  const [commit, date, subject] = lastTouch ? lastTouch.split("|") : [null, null, null];
  return { path, git_blob: blob, last_touched: { commit, date, subject } };
});

const missing = scope.filter((entry) => entry.git_blob === null);
if (missing.length > 0) {
  console.error(
    "[WWHN-382] Thiếu tệp trong scope tại " +
      beSha +
      ":\n" +
      missing.map((entry) => "  - " + entry.path).join("\n"),
  );
  process.exit(1);
}

/**
 * Commit cuối cùng chạm **ngữ nghĩa** dashboard (không phải commit cuối chạm tệp).
 * Đây là mốc để chứng minh bất biến: từ mốc này tới candidate, fingerprint không đổi.
 */
const semanticAnchor = (() => {
  const target = fingerprintAt(beSha).fingerprints.controller_dashboard_block;
  const touches = git([
    "log",
    "--format=%H",
    beSha,
    "--",
    "Modules/Warehouse/app/Http/Controllers/OperationalReportController.php",
  ])
    .split(/\r?\n/)
    .filter(Boolean);
  let anchor = beSha;
  for (const sha of touches) {
    let value;
    try {
      value = fingerprintAt(sha).fingerprints.controller_dashboard_block;
    } catch {
      break;
    }
    if (value !== target) break;
    anchor = sha;
  }
  const [commit, date, subject] = git(["log", "-1", "--format=%H|%ad|%s", "--date=short", anchor]).split("|");
  return { commit, date, subject };
})();

const candidatePin = {
  task: "WWHN-382",
  register: "P0/P1/P2 EXACT FIX ACTION REGISTER — R4",
  generated_by: "scripts/wwhn-382-pin-candidate.mjs",
  candidate: {
    backend: {
      repo: "https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE.git",
      branch: "m1",
      sha: beSha,
      subject: git(["log", "-1", "--format=%s", beSha]),
    },
    frontend: {
      repo: "https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE.git",
      branch: "m1",
      sha: feSha,
    },
  },
  semantic_anchor: semanticAnchor,
  scope_files: scope,
  superseded: SUPERSEDED,
  /**
   * Cách chứng minh pin còn hiệu lực khi `m1` đã chạy tiếp — chính là điều
   * DEV328-P1-02R4 đòi ("không dùng moving m1 làm evidence cuối").
   */
  invariance_check:
    "node scripts/wwhn-328-contract-fingerprint.mjs --check docs/evidence/WWHN-382/CONTRACT_PIN.json --range " +
    semanticAnchor.commit +
    "..HEAD",
};

mkdirSync(join(ROOT, OUT_DIR), { recursive: true });
writeFileSync(
  join(ROOT, OUT_DIR, "CANDIDATE_PIN.json"),
  JSON.stringify(candidatePin, null, 2) + "\n",
  "utf8",
);
writeFileSync(
  join(ROOT, OUT_DIR, "CONTRACT_PIN.json"),
  JSON.stringify(fingerprintAt(beSha), null, 2) + "\n",
  "utf8",
);

console.log("[WWHN-382] BE candidate  : " + beSha);
console.log("[WWHN-382] FE candidate  : " + feSha);
console.log(
  "[WWHN-382] Semantic anchor: " + semanticAnchor.commit + " (" + semanticAnchor.subject + ")",
);
console.log("[WWHN-382] Đã ghi " + OUT_DIR + "/CANDIDATE_PIN.json + CONTRACT_PIN.json");
