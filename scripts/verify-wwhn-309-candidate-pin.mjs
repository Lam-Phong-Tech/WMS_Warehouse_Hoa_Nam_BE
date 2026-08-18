#!/usr/bin/env node
/**
 * WWHN-309 (DEV309-P1-01) - Cổng "pin không được mục".
 *
 * VẤN ĐỀ GỐC: gói bàn giao trước ghi một SHA vào Jira rồi nhánh `m1` đi tiếp.
 * Reviewer đọc SHA đã pin thì thấy tài liệu cũ; đọc `m1` thì thấy tài liệu khác.
 * Không ai cố tình sai - kiến trúc bằng chứng sai: "pin một SHA" là phát biểu
 * một lần, còn nhánh thì thay đổi liên tục.
 *
 * CÁCH SỬA: pin phải là **bất biến kiểm được lại bất cứ lúc nào**, không phải một
 * dòng chữ. Manifest khai candidate SHA + danh sách artifact bàn giao kèm SHA-256.
 * Script này kiểm 4 điều, fail-closed:
 *
 *   1. Candidate SHA có thật và vẫn là tổ tiên của HEAD (pin chưa bị rebase mất).
 *   2. Mọi artifact bàn giao đang tracked trong git và không bị .gitignore loại.
 *   3. Nội dung artifact hiện tại khớp SHA-256 đã pin.
 *   4. RANGE INVARIANT: không commit nào SAU candidate SHA chạm vào tập artifact.
 *      Nếu có, pin đã mục -> phải re-pin, không được tiếp tục trích dẫn SHA cũ.
 *
 * Nhờ (4), `m1` vẫn chạy tiếp cho task khác mà pin của WWHN-309 vẫn đúng; và
 * ngay khi ai đó chạm vào artifact bàn giao, cổng đỏ chứ không im lặng.
 *
 * Dùng:
 *   node scripts/verify-wwhn-309-candidate-pin.mjs            # kiểm
 *   node scripts/verify-wwhn-309-candidate-pin.mjs --refresh  # ghi lại manifest tại HEAD
 */
import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const manifestPath = join(root, "docs/evidence/WWHN-309/CANDIDATE_MANIFEST.json");
const refresh = process.argv.includes("--refresh");
const failures = [];
const notes = [];

// Tập artifact bàn giao của WWHN-309. Thêm tài liệu bàn giao mới thì thêm vào đây,
// nếu không nó nằm ngoài range invariant và có thể trôi mà không ai biết.
const HANDOVER_ARTIFACTS = [
  "README.md",
  "docs/architecture/HN-BE-ARCH-001_BACKEND_ARCHITECTURE.md",
  "docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md",
  "docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md",
  "docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.json",
  "docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md",
  "docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.json",
  "docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.md",
  "docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.json",
  "docs/ops/HN-BE-OPS-001_OPERATIONS_RUNBOOK.md",
  "docs/permissions/WMS_PERMISSION_CATALOG_BE1.md",
  "docs/permissions/WMS_PERMISSION_CATALOG_BE1.json",
  "docs/docs_task_jira/WWHN-309.md",
  "docs/docs_task_jira/WWHN-309_SIGNOFF_COMMENTS.md",
  "docs/decision-records/DEC-API-IFMATCH-001-remove-dead-route-middleware.md",
  "storage/api-docs/api-docs.json",
  "storage/api-docs/api-docs.yaml",
  "scripts/generate-error-code-catalog.mjs",
  "scripts/generate-endpoint-permission-matrix.mjs",
  "scripts/generate-api-contract-metrics.mjs",
  "scripts/verify-wwhn-309-handover-contract.mjs",
  "scripts/verify-wwhn-309-candidate-pin.mjs",
  ".github/workflows/wwhn-309-backend-handover-evidence.yml",
  "tests/Feature/Wwhn309PermissionCatalogEvidenceTest.php",
  "tests/Feature/Wwhn309ScheduledOperationsTest.php",
  "tests/Feature/CurrentBaselineIfMatchContractTest.php",
];

function git(args) {
  return execFileSync("git", args, { cwd: root, encoding: "utf8", maxBuffer: 16 * 1024 * 1024 }).trim();
}

function sha256(path) {
  return createHash("sha256").update(readFileSync(join(root, path))).digest("hex");
}

/**
 * Hash phải mô tả **nội dung đã commit tại candidate SHA**, không phải working tree.
 *
 * Lý do rất thực tế ở dự án này: nhánh `m1` thường có nhiều phiên làm việc song song,
 * nên working tree gần như luôn có thay đổi của task khác. Hash theo working tree sẽ
 * pin nhầm trạng thái không ai clone lại được; hash theo blob của commit thì reviewer
 * clone đúng SHA là tính ra đúng con số.
 */
function sha256AtCommit(ref, path) {
  const blob = execFileSync("git", ["show", ref + ":" + path], {
    cwd: root,
    maxBuffer: 64 * 1024 * 1024,
  });
  return createHash("sha256").update(blob).digest("hex");
}

if (refresh) {
  const head = git(["rev-parse", "HEAD"]);
  const missing = HANDOVER_ARTIFACTS.filter((path) => !existsSync(join(root, path)));
  if (missing.length) {
    missing.forEach((path) => console.error("[candidate-pin] ERROR: thiếu artifact " + path));
    process.exit(1);
  }
  const existing = existsSync(manifestPath) ? JSON.parse(readFileSync(manifestPath, "utf8")) : {};
  const manifest = {
    task: "WWHN-309",
    title: "Candidate manifest - gói bàn giao kiến trúc/API/vận hành backend",
    repository: "Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE",
    branch: git(["rev-parse", "--abbrev-ref", "HEAD"]),
    candidate_sha: head,
    candidate_subject: git(["log", "-1", "--format=%s", head]),
    candidate_committed_at: git(["log", "-1", "--format=%cI", head]),
    generator: "scripts/verify-wwhn-309-candidate-pin.mjs --refresh",
    pull_request: existing.pull_request ?? "N/A",
    independent_review: existing.independent_review ?? null,
    unrelated_scope: existing.unrelated_scope ?? [],
    artifacts: HANDOVER_ARTIFACTS.map((path) => ({ path, sha256: sha256AtCommit(head, path) })),
  };
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + "\n", "utf8");
  console.log("[candidate-pin] Đã ghi manifest tại " + head);
  process.exit(0);
}

if (!existsSync(manifestPath)) {
  console.error("[candidate-pin] FAIL: thiếu docs/evidence/WWHN-309/CANDIDATE_MANIFEST.json");
  console.error("[candidate-pin] Chạy: node scripts/verify-wwhn-309-candidate-pin.mjs --refresh");
  process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, "utf8"));
const candidate = String(manifest.candidate_sha ?? "");

if (!/^[0-9a-f]{40}$/.test(candidate)) {
  failures.push("candidate_sha không phải SHA đầy đủ 40 ký tự: " + JSON.stringify(manifest.candidate_sha));
} else {
  try {
    git(["cat-file", "-e", candidate + "^{commit}"]);
  } catch {
    failures.push("candidate_sha " + candidate + " không tồn tại trong repository");
  }

  // Pin phải nằm trên nhánh đang review; nếu bị rebase/amend thì SHA cũ vẫn "tồn
  // tại" nhưng không còn là lịch sử của m1 - đúng lớp lỗi đã gặp ở các task trước.
  try {
    execFileSync("git", ["merge-base", "--is-ancestor", candidate, "HEAD"], { cwd: root, stdio: "pipe" });
    notes.push("candidate SHA vẫn là tổ tiên của HEAD");
  } catch {
    failures.push(
      "candidate_sha " + candidate.slice(0, 12) + " không còn là tổ tiên của HEAD (nhánh đã rebase/amend) - phải re-pin",
    );
  }
}

const artifacts = Array.isArray(manifest.artifacts) ? manifest.artifacts : [];
const declared = new Set(artifacts.map((artifact) => artifact.path));
for (const path of HANDOVER_ARTIFACTS) {
  if (!declared.has(path)) failures.push("Manifest thiếu artifact bắt buộc: " + path);
}

for (const artifact of artifacts) {
  const path = artifact.path;
  if (!existsSync(join(root, path))) {
    failures.push("Artifact đã pin nhưng không còn tồn tại: " + path);
    continue;
  }

  // Tài liệu tồn tại trên đĩa mà bị git bỏ qua = reviewer clone theo SHA không thấy.
  let tracked = true;
  try {
    git(["ls-files", "--error-unmatch", "--", path]);
  } catch {
    tracked = false;
  }
  if (!tracked) failures.push("Artifact chưa được git tracked: " + path);

  // So với nội dung ĐÃ COMMIT tại candidate SHA - đây là thứ reviewer clone lại được.
  if (/^[0-9a-f]{40}$/.test(candidate)) {
    let committed = null;
    try {
      committed = sha256AtCommit(candidate, path);
    } catch {
      failures.push("Artifact không tồn tại tại candidate SHA: " + path);
    }
    if (committed !== null && committed !== artifact.sha256) {
      failures.push(
        "Artifact lệch nội dung so với pin (tại candidate SHA): " +
          path +
          "\n    pinned    " +
          artifact.sha256 +
          "\n    candidate " +
          committed,
      );
    }
  }

  // Working tree lệch commit là chuyện bình thường khi có phiên khác làm song song;
  // chỉ ghi chú, không fail - range invariant mới là thứ chặn trôi thật sự.
  if (sha256(path) !== artifact.sha256) {
    notes.push("working tree khác bản đã pin ở " + path + " (thay đổi chưa commit)");
  }
}

// RANGE INVARIANT - trái tim của cổng này.
if (/^[0-9a-f]{40}$/.test(candidate)) {
  let drifted = "";
  try {
    drifted = git(["log", "--oneline", candidate + "..HEAD", "--", ...HANDOVER_ARTIFACTS]);
  } catch (error) {
    failures.push("Không chạy được git log cho range invariant: " + String(error.message).split("\n")[0]);
  }
  if (drifted) {
    failures.push(
      "Có commit SAU candidate SHA chạm vào artifact bàn giao - pin đã mục, phải re-pin và chạy lại evidence:\n    " +
        drifted.split("\n").join("\n    "),
    );
  } else {
    notes.push("range invariant: không commit nào sau candidate chạm vào " + HANDOVER_ARTIFACTS.length + " artifact");
  }
}

if (manifest.pull_request === undefined || manifest.pull_request === null || manifest.pull_request === "") {
  failures.push("Manifest phải khai pull_request (URL PR hoặc 'N/A' kèm independent_review)");
}
if (manifest.pull_request === "N/A" && !manifest.independent_review) {
  failures.push(
    "pull_request = N/A thì bắt buộc khai independent_review (ai review, review cái gì, ở đâu) - " +
      "không có PR mà cũng không có bằng chứng review độc lập là handover tự ký",
  );
}

notes.forEach((note) => console.log("[candidate-pin] OK: " + note));
if (failures.length) {
  console.error("");
  failures.forEach((failure) => console.error("[candidate-pin] FAIL: " + failure));
  console.error("");
  console.error("[candidate-pin] Pin của WWHN-309 KHÔNG còn hợp lệ.");
  process.exit(1);
}
console.log(
  "[candidate-pin] PASS: candidate " +
    candidate.slice(0, 12) +
    " còn nguyên vẹn (" +
    artifacts.length +
    " artifact khớp SHA-256).",
);
