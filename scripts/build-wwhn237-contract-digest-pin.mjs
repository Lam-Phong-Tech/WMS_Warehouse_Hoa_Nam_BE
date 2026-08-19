#!/usr/bin/env node
/**
 * WWHN-237 — pin digest hợp đồng cùng một version (đóng `DEV237-P1-05R2`).
 *
 * Register R2 đòi: *"Pin same-version OpenAPI/Postman/FE digest; Tech/BA/QA consumer xác
 * nhận cùng exact pair; FE self-confirmation của cùng DEV không tính independent."*
 *
 * Hai ràng buộc kéo ngược nhau, nên phân loại rõ:
 *
 * - `owned` / `immutable` — WWHN-237 sở hữu, hoặc pack đã đóng băng. Drift = **FAIL**.
 * - `consumed` — hợp đồng đang sống do task khác sở hữu (Postman DEV, OpenAPI runtime,
 *   FE contract baseline). Hard-pin digest của artifact task khác là đúng bẫy đã ghi ở
 *   `DISP-STK-236`: gate đỏ oan cho mọi PR không liên quan. Với nhóm này, digest được
 *   **ghi lại làm bằng chứng version mà consumer đã xác nhận**, còn gate chỉ FAIL khi
 *   *bề mặt* cấm quay lại; drift bytes thuần thì NOTE + yêu cầu refresh confirmation.
 *
 *   node scripts/build-wwhn237-contract-digest-pin.mjs
 */
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { PAIR_RELATIVE_PATH } from "./lib/wwhn237-candidate-pair.mjs";

/**
 * Đọc blob **nguyên byte**. Không dùng helper `git()` dùng chung vì nó `.trim()` output —
 * mất đúng một ký tự newline cuối là digest lệch, và cái lệch giả đó lại được ghi thẳng
 * vào bằng chứng dưới dạng "worktree != blob@pair".
 */
const gitShowRaw = (ref, cwd) => {
  try {
    return execFileSync("git", ["show", ref], { cwd, maxBuffer: 256 * 1024 * 1024 });
  } catch {
    return null;
  }
};

const BE_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const FE_ROOT = resolve(BE_ROOT, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE");
const OUT = join(BE_ROOT, "docs/evidence/WWHN-237/CONTRACT_DIGEST_PIN.json");

/** Bề mặt kiểm kê bị cấm — dùng làm bất biến cho nhóm `consumed`. */
const FORBIDDEN = ["stocktake", "apply-adjustment", "apply_adjustment", "variance_line", "stocktake_variance", "discrepancy"];

const ARTIFACTS = [
  {
    repo: "BE",
    path: "docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md",
    kind: "disposition",
    pin_class: "owned",
    owner_task: "WWHN-237",
  },
  {
    repo: "BE",
    path: "storage/api-docs/api-docs.json",
    kind: "openapi-runtime",
    pin_class: "consumed",
    owner_task: "WWHN-309 (BE contract handover)",
  },
  {
    repo: "BE",
    path: "docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json",
    kind: "postman-living",
    pin_class: "consumed",
    owner_task: "WWHN-267 / WWHN-309",
  },
  {
    repo: "BE",
    path: "docs/api-contract/WWHN-180-posting-openapi.json",
    kind: "openapi-immutable-pack",
    pin_class: "immutable",
    owner_task: "WWHN-180",
    note: "Historical, superseded bang decision record theo DISP-STK-236 §3 — khong sua noi dung. Bat bien be mat KHONG ap: pack nay con mo ta capability lich su.",
    skip_surface_invariant: true,
  },
  {
    repo: "FE",
    path: "docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json",
    kind: "fe-negative-contract",
    pin_class: "consumed",
    owner_task: "FE contract baseline (WWHN-296/309)",
  },
  {
    repo: "FE",
    path: "docs/api-evidence/STOCKTAKE_GOVERNANCE_STATUS.json",
    kind: "fe-governance-status",
    pin_class: "consumed",
    owner_task: "WWHN-230/233/236/237 chung",
  },
];

const rootOf = (repo) => (repo === "FE" ? FE_ROOT : BE_ROOT);
const sha256Of = (abs) => (existsSync(abs) ? createHash("sha256").update(readFileSync(abs)).digest("hex") : null);

const pairRaw = existsSync(join(BE_ROOT, PAIR_RELATIVE_PATH)) ? readFileSync(join(BE_ROOT, PAIR_RELATIVE_PATH), "utf8") : null;
if (!pairRaw) {
  console.error("FAIL: chua co CANDIDATE_PAIR.json — chay build-wwhn237-candidate-pair.mjs truoc.");
  process.exit(1);
}
const pair = JSON.parse(pairRaw);
const beSha = pair.pair.backend.commit_sha;
const feSha = pair.pair.frontend.commit_sha;

/** Version của artifact: đọc từ chính nội dung, không đặt tên tay. */
const versionOf = (item, abs) => {
  if (!existsSync(abs)) return null;
  try {
    if (item.kind === "openapi-runtime" || item.kind === "openapi-immutable-pack") {
      return JSON.parse(readFileSync(abs, "utf8"))?.info?.version ?? null;
    }
    if (item.kind === "postman-living") {
      const info = JSON.parse(readFileSync(abs, "utf8"))?.info ?? {};
      return info.version?.raw ?? info.version ?? info.name ?? null;
    }
    if (item.kind === "fe-negative-contract") {
      const j = JSON.parse(readFileSync(abs, "utf8"));
      return j.schemaVersion ? `schemaVersion ${j.schemaVersion} @ ${j.assessmentDate ?? "?"}` : null;
    }
    if (item.kind === "fe-governance-status") {
      const j = JSON.parse(readFileSync(abs, "utf8"));
      return j.schemaVersion ?? j.version ?? null;
    }
  } catch {
    return null;
  }
  return null;
};

const artifacts = ARTIFACTS.map((item) => {
  const root = rootOf(item.repo);
  const abs = join(root, item.path);
  const present = existsSync(abs);
  const pinCommit = item.repo === "FE" ? feSha : beSha;
  const blobAtPair = present ? gitShowRaw(`${pinCommit}:${item.path}`, root) : null;
  const blobDigest = blobAtPair === null ? null : createHash("sha256").update(blobAtPair).digest("hex");
  return {
    ...item,
    present,
    sha256: sha256Of(abs),
    blob_sha256_at_pair: blobDigest,
    blob_matches_worktree: blobDigest === null || !present ? null : sha256Of(abs) === blobDigest,
    // Consumer ký vào **nội dung tại commit**, không phải working tree: nhiều phiên chạy
    // song song trên cùng cây làm việc nên worktree có thể mang thay đổi chưa commit của
    // task khác. Đây mới là giá trị ba vai trò xác nhận.
    authoritative_digest_for_confirmation: blobDigest ?? sha256Of(abs),
    version: versionOf(item, abs),
    surface_invariant_forbidden: item.skip_surface_invariant ? [] : FORBIDDEN,
  };
});

const record = {
  schema_version: "1.0.0",
  record_id: "WWHN-237-CONTRACT-DIGEST-PIN",
  jira_key: "WWHN-237",
  register_item: "DEV237-P1-05R2",
  disposition: "DISP-STK-237",
  pinned_at_pair: { backend: beSha, frontend: feSha },
  digest_field_meaning: {
    sha256: "digest cua working tree luc chay build — quan sat duoc, co the mang thay doi chua commit cua phien song song.",
    blob_sha256_at_pair: "digest cua noi dung TAI commit da pin (`git show <pair>:<path>`) — tai lap duoc tu git.",
    authoritative_digest_for_confirmation:
      "Gia tri ba consumer ky vao. Bang blob_sha256_at_pair khi co, vi confirmation phai neo vao noi dung tai commit.",
  },
  pin_class_rule: {
    owned: "WWHN-237 so huu — digest drift = FAIL.",
    immutable: "Pack dong bang (WWHN-180/150) — digest drift = FAIL; khong ap bat bien be mat vi pack mo ta capability lich su.",
    consumed:
      "Hop dong dang song do task khac so huu — digest ghi lai lam bang chung version ma consumer da xac nhan. " +
      "Gate FAIL khi be mat cam quay lai; drift bytes thuan chi NOTE kem yeu cau refresh confirmation. " +
      "Ly do khong hard-pin: DISP-STK-236 da ghi ro hard-pin digest artifact cua task khac lam gate do oan.",
  },
  artifacts,
  independent_confirmations: [
    {
      role: "Tech Lead",
      scope: "Cap SHA BE/FE la mot cap duy nhat; supersede m1-be-2 → m1; digest OpenAPI/Postman dung version dang ban giao.",
      status: "PENDING",
      confirmed_by: null,
      jira_comment_ref: null,
      confirmed_at: null,
      pair_backend_sha: null,
      pair_frontend_sha: null,
    },
    {
      role: "BA",
      scope: "Hop dong FE khong con khai capability variance/kiem ke; AC WWHN-60/237/238 duoc rebaseline theo REQ-SRS-037.",
      status: "PENDING",
      confirmed_by: null,
      jira_comment_ref: null,
      confirmed_at: null,
      pair_backend_sha: null,
      pair_frontend_sha: null,
    },
    {
      role: "QA1",
      scope: "N1..N8 + R1..R4 chay tren dung cap da pin; raw log khop QA_EXECUTION_MATRIX.json.",
      status: "PENDING",
      confirmed_by: null,
      jira_comment_ref: null,
      confirmed_at: null,
      pair_backend_sha: null,
      pair_frontend_sha: null,
    },
  ],
  independence_rule:
    "FE self-confirmation cua chinh DEV viet code KHONG tinh la independent (register R2). Truong confirmed_by khong duoc chua 'DEV'.",
  refresh_procedure: "node scripts/build-wwhn237-contract-digest-pin.mjs  (chay lai sau khi cap SHA doi, roi xin lai 3 xac nhan)",
};

mkdirSync(dirname(OUT), { recursive: true });
writeFileSync(OUT, JSON.stringify(record, null, 2) + "\n");

console.log("WWHN-237 — contract digest pin (DEV237-P1-05R2)\n");
for (const a of artifacts) {
  console.log(
    "  " + (a.present ? "OK  " : "MISS") + " [" + a.pin_class + "] " + a.repo + " " + a.path +
      "  v=" + (a.version ?? "?") + "  " + (a.sha256 ? a.sha256.slice(0, 12) : "-") +
      (a.blob_matches_worktree === false ? "  (worktree != blob@pair)" : ""),
  );
}
console.log("\n  pin → docs/evidence/WWHN-237/CONTRACT_DIGEST_PIN.json");
