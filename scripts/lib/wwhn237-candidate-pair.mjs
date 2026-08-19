/**
 * WWHN-237 — định nghĩa **frozen candidate pair** dùng chung (DEV237-P1-02R2).
 *
 * Module thuần: chỉ hằng số + helper, không side effect, để `verify-*` import mà không
 * kích hoạt việc ghi file của `build-*`.
 *
 * Luật một dòng: **candidate không phải HEAD**. Candidate là commit cuối cùng chạm vào
 * tập artifact do WWHN-237 sở hữu (`git log -1 -- <scope>`), nên `m1` chạy tiếp cho task
 * khác thì pin vẫn đúng, và pin tự kiểm được bằng cách giải lại rồi so.
 */
import { execFileSync } from "node:child_process";
import { existsSync } from "node:fs";

/**
 * Tập artifact do WWHN-237 **sở hữu**. Cố ý KHÔNG đưa vào đây file hợp đồng dùng chung
 * (`docs/postman/**`, FE `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json`): chúng thuộc
 * task khác, đưa vào scope là tự chuốc pin rot mỗi lần task khác chạm — đúng bẫy đã ghi
 * ở `DISP-STK-236` (hard-pin digest artifact của task khác). Chúng được canh bằng
 * `CONTRACT_DIGEST_PIN.json` ở tầng bất biến bề mặt, không phải bằng SHA commit.
 */
export const BE_ARTIFACT_SCOPE = [
  "docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md",
  "scripts/verify-wwhn237-variance-report-disposition.mjs",
  "scripts/run-wwhn-237-evidence.mjs",
  "scripts/run-wwhn237-qa-matrix.mjs",
  "scripts/build-wwhn237-candidate-pair.mjs",
  "scripts/build-wwhn237-contract-digest-pin.mjs",
  "scripts/lib/wwhn237-candidate-pair.mjs",
  "tests/Feature/Wwhn237VarianceReportDeferredGateTest.php",
];

export const FE_ARTIFACT_SCOPE = [
  "scripts/verify-wwhn-237-closure.mjs",
  "src/test/wwhn-237-variance-report-guard.test.ts",
];

/** Scope không được co lại để pin dễ thoả — verifier assert đúng bộ tối thiểu này. */
export const BE_SCOPE_MANDATORY = [
  "docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md",
  "scripts/verify-wwhn237-variance-report-disposition.mjs",
  "tests/Feature/Wwhn237VarianceReportDeferredGateTest.php",
];
export const FE_SCOPE_MANDATORY = [
  "scripts/verify-wwhn-237-closure.mjs",
  "src/test/wwhn-237-variance-report-guard.test.ts",
];

export const PAIR_RELATIVE_PATH = "docs/evidence/WWHN-237/CANDIDATE_PAIR.json";

export const git = (args, cwd) => {
  try {
    return execFileSync("git", args, { cwd, encoding: "utf8" }).trim();
  } catch {
    return null;
  }
};

/** Candidate = commit cuối chạm scope. Trả null nếu repo/scope không giải được. */
export const resolveCandidate = (root, scope) => {
  if (!existsSync(root)) return null;
  const sha = git(["log", "-1", "--format=%H", "--", ...scope], root);
  if (!sha || !/^[0-9a-f]{40}$/.test(sha)) return null;
  return {
    commit_sha: sha,
    committed_at: git(["show", "-s", "--format=%cI", sha], root),
    subject: git(["show", "-s", "--format=%s", sha], root),
    branch: git(["rev-parse", "--abbrev-ref", "HEAD"], root),
    on_branch_m1: git(["merge-base", "--is-ancestor", sha, "m1"], root) !== null,
  };
};
