#!/usr/bin/env node
/**
 * WWHN-188 — sinh `docs/evidence/WWHN-188/WWHN-188_CANDIDATE_PIN.json`.
 *
 * Đóng reviewer finding `DEV188-P1-05R2`:
 *
 *   "Pin exact WWHN-188 candidate commit/PR/build. Không dùng floating m1 HEAD
 *    hoặc unrelated WWHN-296 HEAD."
 *
 * Nguyên tắc chống "pin rot" (đã trả giá ở WWHN-191/WWHN-295):
 *
 * 1. Candidate KHÔNG phải HEAD của nhánh. Nhánh `m1` có nhiều phiên làm song song,
 *    nên HEAD hôm nay đã khác HEAD lúc review. Candidate là **commit cuối cùng chạm
 *    vào đúng tập artifact của WWHN-188** (`git log -1 -- <paths>`).
 * 2. Pin hai tầng: commit SHA **và** blob SHA của từng artifact. Commit SHA trả lời
 *    "đọc ở đâu"; blob SHA trả lời "nội dung có đúng bản đã review không" — commit
 *    sau đó của phiên khác không làm hỏng blob SHA nếu họ không chạm file của mình.
 * 3. Ghi luôn SHA-256 của bản trích OpenAPI để `DEV188-P1-03R2` có "OpenAPI hash"
 *    đúng nghĩa, không phải hash của cả file spec (file spec bị mọi task khác chạm).
 *
 * Chạy: `node scripts/pin-wwhn-188-candidate.mjs`
 */

import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { readFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const OUT = "docs/evidence/WWHN-188/WWHN-188_CANDIDATE_PIN.json";

/** Artifact thuộc WWHN-188. Thêm đường dẫn ở đây là đủ — verifier đọc lại từ pin. */
const CODE_ARTIFACTS = [
  "app/Support/ScanRequestFingerprint.php",
  "Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php",
  "Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php",
  "tests/Feature/Wwhn188ScanIdempotencyContractTest.php",
  "tests/Feature/InboundScanConcurrencyBehaviorTest.php",
  "tests/Feature/InboundScanRuntimeConcurrencyTest.php",
  "scripts/wwhn188-inbound-scan-runtime-concurrency.php",
  "scripts/wwhn188-inbound-scan-worker.php",
];

const DOC_ARTIFACTS = [
  "docs/docs_task_jira/WWHN-188.md",
  "docs/decision-records/DEC-SCAN-IDEMP-001-inbound-scan-request-idempotency.md",
  "docs/evidence/WWHN-188/WWHN-188_TRACE_SUPERSESSION.md",
  "docs/evidence/WWHN-188/WWHN-188_SOURCE_REGISTER.md",
  "docs/evidence/WWHN-188/WWHN-188_QA_TEST_MATRIX.md",
  "docs/evidence/WWHN-188/WWHN-188_CLOSURE_REGISTER.md",
  "docs/evidence/WWHN-188/openapi/stock_in_scan.operation.json",
  "docs/evidence/WWHN-188/runtime/runtime-concurrency-summary.json",
];

function git(args) {
  return execFileSync("git", ["-C", ROOT, ...args], { encoding: "utf8" }).trim();
}

function blobSha(path) {
  return git(["hash-object", path]);
}

/** Commit cuối cùng chạm vào đúng tập đường dẫn này — KHÔNG phải HEAD của nhánh. */
function lastCommitTouching(paths) {
  const out = git(["log", "-1", "--format=%H%x09%cI%x09%s", "--", ...paths]);
  if (out === "") return null;
  const [sha, committedAt, subject] = out.split("\t");
  return { sha, committed_at: committedAt, subject };
}

function sha256OfCanonicalJson(path) {
  const parsed = JSON.parse(readFileSync(resolve(ROOT, path), "utf8"));
  return createHash("sha256").update(JSON.stringify(parsed)).digest("hex");
}

const allArtifacts = [...CODE_ARTIFACTS, ...DOC_ARTIFACTS];
const candidate = lastCommitTouching(allArtifacts);
const codeCandidate = lastCommitTouching(CODE_ARTIFACTS);

if (candidate === null) {
  console.error(
    "WWHN-188: chưa có commit nào chạm vào artifact của task — commit trước rồi chạy lại.",
  );
  process.exit(2);
}

const artifacts = {};
for (const path of allArtifacts) {
  artifacts[path] = {
    blob_sha: blobSha(path),
    last_commit: lastCommitTouching([path])?.sha ?? null,
  };
}

const pin = {
  ticket: "WWHN-188",
  title: "[DEV] Rà soát giao dịch/khóa đồng thời (HN-IN-002, BE1)",
  generated_at: new Date().toISOString(),
  review_items_closed: [
    "DEV188-P1-01R2",
    "DEV188-P1-02R2",
    "DEV188-P1-03R2",
    "DEV188-P1-04R2",
    "DEV188-P1-05R2",
    "DEV188-P1-06R2",
    "DEV188-P2-01",
  ],
  branch: git(["rev-parse", "--abbrev-ref", "HEAD"]),
  candidate: {
    // Commit CUỐI CÙNG chạm artifact của WWHN-188 — reviewer đọc tại đây.
    commit_sha: candidate.sha,
    committed_at: candidate.committed_at,
    subject: candidate.subject,
    // Tách riêng phần mã: nếu sau này chỉ tài liệu đổi, phần mã vẫn pin đúng chỗ.
    code_commit_sha: codeCandidate?.sha ?? null,
    code_committed_at: codeCandidate?.committed_at ?? null,
  },
  // HEAD chỉ để tham chiếu — TUYỆT ĐỐI không dùng làm bằng chứng.
  branch_head_at_pin_time: {
    sha: git(["rev-parse", "HEAD"]),
    note: "Chỉ ghi để đối chiếu. Nhánh m1 có nhiều phiên làm song song nên HEAD không phải candidate.",
  },
  openapi: {
    operation_extract: "docs/evidence/WWHN-188/openapi/stock_in_scan.operation.json",
    sha256_canonical: sha256OfCanonicalJson(
      "docs/evidence/WWHN-188/openapi/stock_in_scan.operation.json",
    ),
    operation_ids: ["stock_in_scan"],
    paths: [
      "/api/v1/inbound-documents/{id}/scan",
      "/api/v1/inbound-documents/{id}/scans",
    ],
  },
  artifacts,
  verifier: {
    command: "node scripts/verify-wwhn-188-closure.mjs",
    exact_sha_command:
      "node scripts/verify-wwhn-188-closure.mjs --expect-sha=<candidate.commit_sha>",
  },
};

writeFileSync(resolve(ROOT, OUT), `${JSON.stringify(pin, null, 2)}\n`);
console.log(
  JSON.stringify(
    {
      written: OUT,
      candidate_commit: pin.candidate.commit_sha,
      code_commit: pin.candidate.code_commit_sha,
      openapi_sha256: pin.openapi.sha256_canonical,
      artifact_count: Object.keys(artifacts).length,
    },
    null,
    2,
  ),
);
