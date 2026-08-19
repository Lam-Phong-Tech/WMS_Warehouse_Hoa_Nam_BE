#!/usr/bin/env node
/**
 * WWHN-237 — dựng **frozen candidate pair** (đóng `DEV237-P1-02R2`).
 *
 * Vòng R1 ghi định danh bằng `git rev-parse HEAD` ở hai thời điểm khác nhau, hai repo
 * chạy lệch nhau nên `SOURCE_REGISTER.json` (BE) và `FE_CLOSURE_IDENTITY.json` (FE) khai
 * **hai cặp SHA khác nhau** — reviewer không có một cặp duy nhất để tái lập. Đó là lỗi
 * thật, không phải thiếu tài liệu.
 *
 * Cách sửa tận gốc: candidate **không phải HEAD**. Candidate là *commit cuối cùng chạm
 * vào tập artifact do WWHN-237 sở hữu*, giải bằng `git log -1 -- <scope>`. Nhánh `m1`
 * chạy tiếp cho task khác thì pin vẫn đúng, và pin tự kiểm được (re-resolve rồi so).
 *
 * Vòng gà-trứng được cắt đúng cách của `WWHN-290` (`excluded_from_digest`): chính file
 * pin và toàn bộ `docs/evidence/WWHN-237/**` nằm NGOÀI scope, nên commit ghi pin không
 * làm pin tự lệch.
 *
 *   node scripts/build-wwhn237-candidate-pair.mjs
 *   node scripts/build-wwhn237-candidate-pair.mjs --check          # chỉ kiểm, không ghi
 *   node scripts/build-wwhn237-candidate-pair.mjs --expect-be-sha=<sha> --expect-fe-sha=<sha>
 *
 * Ghi ra **hai bản byte-identical**: BE + FE `docs/evidence/WWHN-237/CANDIDATE_PAIR.json`.
 */
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import {
  BE_ARTIFACT_SCOPE,
  FE_ARTIFACT_SCOPE,
  PAIR_RELATIVE_PATH,
  git,
  resolveCandidate,
} from "./lib/wwhn237-candidate-pair.mjs";

const BE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const FE_ROOT = resolve(BE_ROOT, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE");

const argv = process.argv.slice(2);
const CHECK_ONLY = argv.includes("--check");
const argValue = (name) => {
  const hit = argv.find((a) => a.startsWith(name + "="));
  return hit ? hit.slice(name.length + 1) : null;
};

const sha256Text = (text) => createHash("sha256").update(Buffer.from(text, "utf8")).digest("hex");

const be = resolveCandidate(BE_ROOT, BE_ARTIFACT_SCOPE);
const fe = resolveCandidate(FE_ROOT, FE_ARTIFACT_SCOPE);

if (!be) {
  console.error("FAIL: khong giai duoc candidate BE tu scope artifact WWHN-237.");
  process.exit(1);
}
if (!fe) {
  console.error("FAIL: khong giai duoc candidate FE (repo: " + FE_ROOT + ").");
  process.exit(1);
}

// ── Supersede m1-be-2 → m1, đo bằng git chứ không chép lại kết luận task cũ ──
const m1be2Tip = git(["rev-parse", "origin/m1-be-2"], BE_ROOT);
const counts = m1be2Tip ? git(["rev-list", "--left-right", "--count", "origin/m1-be-2...m1"], BE_ROOT) : null;
const [aheadOfM1, m1Ahead] = counts ? counts.split(/\s+/).map((n) => Number.parseInt(n, 10)) : [null, null];
const isAncestor = m1be2Tip ? git(["merge-base", "--is-ancestor", "origin/m1-be-2", "m1"], BE_ROOT) !== null : null;

const record = {
  schema_version: "1.0.0",
  record_id: "WWHN-237-CANDIDATE-PAIR",
  jira_key: "WWHN-237",
  parent_key: "WWHN-60",
  qa_gate: "WWHN-238",
  disposition: "DISP-STK-237",
  register_item: "DEV237-P1-02R2",
  canonical_branch: "m1",
  resolution_rule:
    "candidate = `git log -1 --format=%H -- <artifact_scope>` (commit cuoi cham tap artifact do task so huu). " +
    "TUYET DOI KHONG dung `git rev-parse HEAD`: HEAD cua m1 chay tiep cho task khac nen pin se troi, va hai repo " +
    "chay lech thoi diem se sinh hai cap khac nhau — dung loi DEV237-P1-02R2 ghi nhan o vong R1.",
  pair: {
    backend: {
      repository: "Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE",
      branch: be.branch,
      commit_sha: be.commit_sha,
      committed_at: be.committed_at,
      subject: be.subject,
      artifact_scope: BE_ARTIFACT_SCOPE,
    },
    frontend: {
      repository: "Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE",
      branch: fe.branch,
      commit_sha: fe.commit_sha,
      committed_at: fe.committed_at,
      subject: fe.subject,
      artifact_scope: FE_ARTIFACT_SCOPE,
    },
  },
  excluded_from_scope: ["docs/evidence/WWHN-237/**"],
  excluded_reason:
    "Cat vong ga-trung: file pin khong duoc nam trong tap no pin, neu khong moi lan ghi pin lai lam pin tu lech " +
    "(cung co che `excluded_from_digest` cua WWHN-290).",
  branch_supersession: {
    superseded_branch: "m1-be-2",
    canonical_branch: "m1",
    m1_be_2_tip: m1be2Tip,
    m1_be_2_commits_ahead_of_m1: aheadOfM1,
    m1_commits_ahead_of_m1_be_2: m1Ahead,
    is_pure_ancestor: isAncestor,
    measurement:
      "git rev-list --left-right --count origin/m1-be-2...m1 ; git merge-base --is-ancestor origin/m1-be-2 m1",
    prior_decision_records: [
      "docs/decision-records/DISP-OUT-198-CANONICAL-BRANCH.md",
      "docs/decision-records/DEC-MIG-SRC-001-migration-source-disposition.md",
    ],
    jira_action:
      "Moi tham chieu `m1-be-2` tren WWHN-60 / WWHN-237 / WWHN-238 phai duoc thay bang `m1` + dung cap SHA tren. " +
      "m1-be-2 la ancestor thuan (0 commit vuot truoc), khong phai nguon thay the.",
  },
  reproduce: {
    backend:
      "node scripts/verify-wwhn237-variance-report-disposition.mjs --expect-be-sha=" +
      be.commit_sha +
      " --expect-fe-sha=" +
      fe.commit_sha,
    frontend:
      "node scripts/verify-wwhn-237-closure.mjs --expect-be-sha=" +
      be.commit_sha +
      " --expect-fe-sha=" +
      fe.commit_sha,
    qa_matrix: "node scripts/run-wwhn237-qa-matrix.mjs",
    rebuild_pin: "node scripts/build-wwhn237-candidate-pair.mjs",
  },
  copies_must_be_byte_identical: ["BE  " + PAIR_RELATIVE_PATH, "FE  " + PAIR_RELATIVE_PATH],
  consumers: [
    "BE docs/evidence/WWHN-237/SOURCE_REGISTER.json",
    "BE docs/evidence/WWHN-237/QA_EXECUTION_MATRIX.json",
    "BE docs/evidence/WWHN-237/CONTRACT_DIGEST_PIN.json",
    "FE docs/evidence/WWHN-237/FE_CLOSURE_IDENTITY.json",
  ],
};

const serialized = JSON.stringify(record, null, 2) + "\n";
const digest = sha256Text(serialized);

const expectBe = argValue("--expect-be-sha");
const expectFe = argValue("--expect-fe-sha");
const problems = [];
if (expectBe && expectBe !== be.commit_sha) {
  problems.push("BE candidate " + be.commit_sha + " != --expect-be-sha " + expectBe);
}
if (expectFe && expectFe !== fe.commit_sha) {
  problems.push("FE candidate " + fe.commit_sha + " != --expect-fe-sha " + expectFe);
}
if (be.on_branch_m1 === false) problems.push("BE candidate " + be.commit_sha + " khong nam tren m1");
if (fe.on_branch_m1 === false) problems.push("FE candidate " + fe.commit_sha + " khong nam tren m1");
if (isAncestor === false) {
  problems.push("m1-be-2 KHONG con la ancestor thuan cua m1 — supersession phai duoc BA/Tech Lead xet lai");
}
if (aheadOfM1 !== null && aheadOfM1 !== 0) {
  problems.push("m1-be-2 di truoc m1 " + aheadOfM1 + " commit — khong duoc coi la superseded");
}

console.log("WWHN-237 — frozen candidate pair (DEV237-P1-02R2)\n");
console.log("  BE  " + be.commit_sha + "  " + be.committed_at + "  " + be.subject);
console.log("  FE  " + fe.commit_sha + "  " + fe.committed_at + "  " + fe.subject);
console.log(
  "  m1-be-2 tip " + (m1be2Tip ?? "?") + " — ahead " + (aheadOfM1 ?? "?") + " / m1 ahead " + (m1Ahead ?? "?") +
    " / ancestor " + isAncestor,
);
console.log("  record sha256 " + digest);

if (problems.length > 0) {
  console.error("\nFAIL:");
  for (const p of problems) console.error("  " + p);
  process.exit(1);
}

/**
 * `m1_commits_ahead_of_m1_be_2` là **số đếm biết trôi**: mỗi commit mới trên `m1` của bất kỳ
 * task nào cũng làm nó tăng. Đưa nó vào phép so byte biến bản pin thành đồ hết hạn sau vài
 * phút — đúng kiểu pin rot mà vòng review trước đã bắt. Nó vẫn được **ghi lại** làm số đo tại
 * thời điểm pin; chỉ bị loại khỏi phép so. Bất biến thật (`ahead = 0`, `is_pure_ancestor`)
 * được kiểm **sống** ở phần `problems` phía trên, không phải bằng cách so chuỗi.
 */
const VOLATILE_FIELDS = ["m1_commits_ahead_of_m1_be_2"];
const comparable = (obj) => {
  const clone = JSON.parse(JSON.stringify(obj));
  for (const field of VOLATILE_FIELDS) delete clone.branch_supersession?.[field];
  return JSON.stringify(clone, null, 2) + "\n";
};

if (CHECK_ONLY) {
  const expected = comparable(record);
  const targets = [join(BE_ROOT, PAIR_RELATIVE_PATH), join(FE_ROOT, PAIR_RELATIVE_PATH)];
  const missing = targets.filter((p) => !existsSync(p));
  if (missing.length > 0) {
    console.error("\nFAIL: thieu ban pin:\n  " + missing.join("\n  "));
    process.exit(1);
  }
  const contents = targets.map((p) => readFileSync(p, "utf8"));
  const drift = targets.filter((p, i) => comparable(JSON.parse(contents[i])) !== expected);
  if (drift.length > 0) {
    console.error("\nFAIL: ban pin lech voi ket qua giai lai:\n  " + drift.join("\n  "));
    process.exit(1);
  }
  if (contents[0] !== contents[1]) {
    console.error("\nFAIL: hai ban pin BE/FE khong byte-identical voi nhau.");
    process.exit(1);
  }
  console.log("\nOK — hai ban pin khop nhau va khop ket qua giai lai (bo qua so dem biet troi).");
  process.exit(0);
}

for (const root of [BE_ROOT, FE_ROOT]) {
  const target = join(root, PAIR_RELATIVE_PATH);
  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, serialized);
  console.log("  pin → " + target);
}
console.log("\nOK — hai ban pin byte-identical da ghi.");
