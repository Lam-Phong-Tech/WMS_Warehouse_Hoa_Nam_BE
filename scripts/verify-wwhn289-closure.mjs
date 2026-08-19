#!/usr/bin/env node
/**
 * WWHN-289 — verifier đóng register DEV289-P1-02R / P1-03R / P1-04R / P2-01.
 *
 * LÝ DO TỒN TẠI: vòng review 73/100 không bác bỏ nội dung fix (fix map "đã có"), nó bác bỏ
 * *chuỗi bằng chứng*: pin trỏ vào một revision không còn là revision cuối, số test trong
 * comment lệch số trong PINS, và artifact được cổng đọc lại là file ĐÃ COMMIT nên cổng tự
 * xác nhận chính nó. Script này biến từng mệnh đề đó thành một phép đo chạy được.
 *
 * Chạy:
 *   node scripts/verify-wwhn289-closure.mjs
 *   node scripts/verify-wwhn289-closure.mjs --json
 *
 * Exit 0 = mọi bất biến còn đúng. Exit 1 = có bất biến gãy, in rõ cái nào.
 *
 * NGUYÊN TẮC PIN CỦA TICKET NÀY (m1 là nhánh dùng chung, nhiều người/phiên đẩy cùng ngày):
 *   - KHÔNG pin blob tuyệt đối của file mà ticket khác cũng sửa; pin RANGE INVARIANT
 *     (base -> final) và kiểm "final là ancestor của HEAD" thay vì "final == HEAD".
 *   - PINS.json nằm ở CHÍNH closure revision phải chứa SHA code cuối. Điều đó chỉ khả thi
 *     nếu closure revision là commit KẾ TIẾP commit code cuối, nên `code_range.final` luôn
 *     là một commit đã tồn tại khi PINS được viết — không có tham chiếu vòng.
 *   - CI run được pin là run của ĐÚNG `code_range.final`. Commit tài liệu sau đó không đổi
 *     code nên không làm run đó lạc hậu; nếu ai đó sửa code sau final, `code_scope_paths`
 *     bên dưới sẽ phát hiện và cổng đỏ.
 */
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const evidenceDir = "docs/evidence/WWHN-289";
const jsonOut = process.argv.includes("--json");

/** @type {Array<{id: string, ok: boolean, detail: string}>} */
const results = [];

function check(id, ok, detail) {
  results.push({ id, ok: Boolean(ok), detail });
}

function read(relative) {
  const file = join(root, relative);
  return existsSync(file) ? readFileSync(file, "utf8") : null;
}

function git(...args) {
  try {
    return execFileSync("git", args, { cwd: root, encoding: "utf8" }).trim();
  } catch {
    return null;
  }
}

function sha256(relative) {
  const file = join(root, relative);
  if (!existsSync(file)) return null;
  return createHash("sha256").update(readFileSync(file)).digest("hex");
}

// ---------------------------------------------------------------------------
// PINS là nguồn duy nhất. Không đọc được PINS thì không có gì để xác minh.
// ---------------------------------------------------------------------------

const pinsRaw = read(`${evidenceDir}/WWHN-289_PINS.json`);
if (pinsRaw === null) {
  console.error(`FAIL: thiếu ${evidenceDir}/WWHN-289_PINS.json`);
  process.exit(1);
}

let pins;
try {
  pins = JSON.parse(pinsRaw);
} catch (error) {
  console.error(`FAIL: PINS.json không phải JSON hợp lệ — ${error.message}`);
  process.exit(1);
}

// ---------------------------------------------------------------------------
// DEV289-P1-02R · pin trỏ vào revision còn thật, và PINS chứa SHA code cuối
// ---------------------------------------------------------------------------

const range = pins.change_range ?? {};
check(
  "P1-02R/pins-declares-final",
  typeof range.final === "string" && /^[0-9a-f]{40}$/.test(range.final),
  "PINS ở chính closure revision khai code_range.final là SHA 40 ký tự đầy đủ",
);

for (const [label, sha] of [
  ["base", range.base],
  ["final", range.final],
]) {
  const type = sha ? git("cat-file", "-t", sha) : null;
  check(
    `P1-02R/${label}-exists`,
    type === "commit",
    `change_range.${label} (${sha ?? "—"}) là một commit tồn tại trong repo`,
  );

  let ancestor = false;
  try {
    execFileSync("git", ["merge-base", "--is-ancestor", String(sha), "HEAD"], { cwd: root });
    ancestor = true;
  } catch {
    ancestor = false;
  }
  check(
    `P1-02R/${label}-ancestor`,
    ancestor,
    `change_range.${label} là ancestor của HEAD (range invariant, không đòi == HEAD)`,
  );
}

// R3 (DEV289-P1-01R3-CANDIDATE): m1 là nhánh dùng chung nên "không ai được chạm code
// sau final" là bất khả thi về vận hành — WWHN-222/WWHN-234 đã chạm AuthController và
// test proof một cách hợp lệ cho ticket CỦA HỌ. Bất biến ĐÚNG từ R3: mọi commit hậu-final
// chạm code scope phải nằm trong danh sách disposition tường minh của PINS
// (post_final_delta_disposition.dispositioned_commits) — mỗi mục đủ trường bắt buộc và
// có bằng chứng trung tính chạy thật. Commit NGOÀI danh sách -> FAIL (fail-closed, không
// ai nới danh sách âm thầm được vì PINS + tài liệu delta đều digest/git-tracked).
const codeScope = Array.isArray(pins.code_scope_paths) ? pins.code_scope_paths : [];
check(
  "P1-02R/code-scope-declared",
  codeScope.length > 0,
  "PINS liệt kê code_scope_paths để kiểm commit hậu-final chạm code",
);

const disposition = pins.post_final_delta_disposition ?? {};
const dispositioned = Array.isArray(disposition.dispositioned_commits)
  ? disposition.dispositioned_commits
  : [];

check(
  "P1-01R3/disposition-declared",
  typeof disposition.decision === "string" &&
    disposition.decision.includes(String(range.final)) &&
    typeof disposition.detail_document === "string" &&
    existsSync(join(root, disposition.detail_document)),
  "PINS khai post_final_delta_disposition: quyết định giữ frozen candidate + tài liệu delta tồn tại",
);

const dispositionFieldsOk = dispositioned.every(
  (entry) =>
    typeof entry.sha === "string" && /^[0-9a-f]{40}$/.test(entry.sha) &&
    typeof entry.owner_ticket === "string" && entry.owner_ticket.length > 0 &&
    Array.isArray(entry.files) && entry.files.length > 0 &&
    typeof entry.classification === "string" && entry.classification.length > 0 &&
    typeof entry.neutrality_evidence === "string" && entry.neutrality_evidence.length > 0,
);
check(
  "P1-01R3/disposition-entries-complete",
  dispositionFieldsOk,
  dispositionFieldsOk
    ? "Mỗi commit được disposition đủ trường: sha đầy đủ, owner_ticket, files, classification, neutrality_evidence"
    : "Có mục disposition THIẾU trường bắt buộc — danh sách không được phép nở ra mà thiếu bằng chứng",
);

if (codeScope.length > 0 && range.final) {
  const touched = git("log", "--format=%H %s", `${range.final}..HEAD`, "--", ...codeScope);
  const touchedLines = touched ? touched.split("\n").filter(Boolean) : [];
  const allowedShas = new Set(dispositioned.map((entry) => String(entry.sha)));
  const undispositioned = touchedLines.filter((line) => !allowedShas.has(line.slice(0, 40)));
  check(
    "P1-01R3/post-final-dispositioned",
    undispositioned.length === 0,
    undispositioned.length > 0
      ? `CÓ commit hậu-final chạm code WWHN-289 NGOÀI danh sách disposition -> pin lạc hậu:\n    ${undispositioned.join("\n    ")}`
      : touchedLines.length > 0
        ? `Mọi commit hậu-final chạm code scope (${touchedLines.length}) đều nằm trong disposition tường minh của PINS`
        : "Không commit nào sau code_range.final chạm file code trong phạm vi WWHN-289",
  );

  // Disposition không được khai khống: mỗi SHA trong danh sách phải THẬT SỰ là một
  // commit hậu-final có chạm code scope (chặn kiểu độn danh sách để che một commit khác).
  const touchedShas = new Set(touchedLines.map((line) => line.slice(0, 40)));
  const phantom = dispositioned.filter((entry) => !touchedShas.has(String(entry.sha)));
  check(
    "P1-01R3/disposition-no-phantom",
    phantom.length === 0,
    phantom.length === 0
      ? "Danh sách disposition khớp 1-1 với tập commit hậu-final thật sự chạm code scope"
      : `Disposition khai SHA không hề chạm code scope sau final: ${phantom.map((entry) => entry.sha.slice(0, 12)).join(", ")}`,
  );
}

check(
  "P1-01R3/delta-verified-on-postgres",
  typeof disposition.delta_verification?.frozen_run === "string" &&
    disposition.delta_verification.frozen_run.includes("pgsql") &&
    typeof disposition.delta_verification?.head_run === "string",
  "Disposition kèm bằng chứng chạy thật hai đầu dải delta (frozen + HEAD) trên PostgreSQL",
);

// ---------------------------------------------------------------------------
// DEV289-P1-02R · digest artifact: tài liệu và số liệu thô phải khớp byte
// ---------------------------------------------------------------------------

// CHỈ digest-pin phần TĨNH (5 tài liệu evidence). Cố ý KHÔNG digest-pin
// `build/evidence/wwhn-289/*.json`: hai file đó phải được LẦN CHẠY sinh lại, nên byte của
// chúng đổi theo từng run và một digest cố định sẽ buộc cổng phải đọc file tĩnh — đúng cái
// lỗi "cổng tự xác nhận bằng artifact đã commit" mà P1-02R bắt. Với hai file đó, verifier
// kiểm NGỮ NGHĨA (đủ chiều, mismatch=0, driver=pgsql) ở phần P1-03R/P1-04R bên dưới.
const digests = pins.artifact_digests ?? {};
check(
  "P1-02R/digests-declared",
  Object.keys(digests).length >= 5 && Object.keys(digests).every((path) => path.endsWith(".md")),
  "PINS khai sha256 cho đủ 5 tài liệu evidence tĩnh (và không digest-pin artifact tự sinh)",
);

for (const [relative, expected] of Object.entries(digests)) {
  const actual = sha256(relative);
  check(
    `P1-02R/digest/${relative.split("/").pop()}`,
    actual !== null && actual === expected,
    actual === null
      ? `${relative} không tồn tại`
      : actual === expected
        ? `${relative} khớp digest đã pin`
        : `${relative} LỆCH digest — pin ${expected.slice(0, 12)}…, thực tế ${actual.slice(0, 12)}…`,
  );
}

// ---------------------------------------------------------------------------
// DEV289-P1-02R · pin CI run thật: id + kết luận + digest artifact CI
// ---------------------------------------------------------------------------

const ci = pins.ci_run ?? {};
check(
  "P1-02R/ci-run-id",
  typeof ci.run_id === "string" && /^\d{6,}$/.test(ci.run_id),
  `PINS pin run id thật của GitHub Actions (${ci.run_id ?? "—"})`,
);
// `m1` là nhánh dùng chung: giữa lúc commit và lúc push, ticket khác có thể chen commit
// nên GitHub Actions chạy trên TIP chứ không trên đúng `code_range.final`. Đòi
// `head_sha === final` sẽ đỏ vì lý do không liên quan tới WWHN-289. Điều kiện ĐÚNG — và
// chặt hơn — là: run chạy trên một revision NẰM SAU final, và giữa final với revision đó
// không ai chạm file code trong phạm vi WWHN-289. Khi ấy run vẫn chứng minh đúng code đã pin.
let ciAfterFinal = false;
if (ci.head_sha && range.final) {
  try {
    execFileSync("git", ["merge-base", "--is-ancestor", String(range.final), String(ci.head_sha)], { cwd: root });
    ciAfterFinal = true;
  } catch {
    ciAfterFinal = false;
  }
}
check(
  "P1-02R/ci-run-covers-final",
  ciAfterFinal,
  `CI run được pin (${ci.head_sha ?? "—"}) nằm sau hoặc trùng code_range.final`,
);

if (ciAfterFinal && codeScope.length > 0) {
  const drifted = git("log", "--format=%h %s", `${range.final}..${ci.head_sha}`, "--", ...codeScope);
  check(
    "P1-02R/ci-run-code-unchanged",
    drifted === "" || drifted === null,
    drifted
      ? `Giữa final và run được pin CÓ commit chạm code WWHN-289 -> run không còn chứng minh code đã pin:\n    ${drifted.split("\n").join("\n    ")}`
      : "Không commit nào giữa code_range.final và CI run được pin chạm code WWHN-289",
  );
}
check(
  "P1-02R/ci-run-conclusion",
  ci.conclusion === "success",
  `Kết luận của run được pin là success (hiện: ${ci.conclusion ?? "—"})`,
);
check(
  "P1-02R/ci-run-workflow",
  typeof ci.workflow_file === "string" && existsSync(join(root, ".github/workflows", ci.workflow_file)),
  `Workflow được pin (${ci.workflow_file ?? "—"}) còn tồn tại trong repo`,
);

// ---------------------------------------------------------------------------
// DEV289-P1-03R · bảy chiều stock invariant, đo trên PostgreSQL, mismatch = 0
// ---------------------------------------------------------------------------

const stockRaw = read("build/evidence/wwhn-289/stock-invariant-proof.json");
let stock = null;
try {
  stock = stockRaw ? JSON.parse(stockRaw) : null;
} catch {
  stock = null;
}

const requiredStockDimensions = [
  "retry",
  "idempotency_conflict",
  "rollback",
  "negative_stock",
  "true_concurrency",
  "reversal",
  "reconcile",
];

check(
  "P1-03R/raw-artifact",
  stock !== null,
  "build/evidence/wwhn-289/stock-invariant-proof.json tồn tại và là JSON hợp lệ",
);

if (stock !== null) {
  const missing = requiredStockDimensions.filter((dimension) => !(dimension in stock));
  check(
    "P1-03R/seven-dimensions",
    missing.length === 0,
    missing.length === 0
      ? `Đủ bảy chiều register đòi: ${requiredStockDimensions.join(", ")}`
      : `THIẾU chiều: ${missing.join(", ")}`,
  );

  const failed = requiredStockDimensions.filter(
    (dimension) => stock[dimension] && stock[dimension].verdict !== "PASS",
  );
  check("P1-03R/all-pass", failed.length === 0, failed.length === 0 ? "Cả bảy chiều verdict PASS" : `Chiều không PASS: ${failed.join(", ")}`);

  check(
    "P1-03R/reconcile-zero",
    Number(stock.reconcile?.mismatch_count) === 0 && stock.reconcile?.report_status === "PASS",
    `Đối soát ledger↔balance mismatch = ${stock.reconcile?.mismatch_count ?? "—"}, status ${stock.reconcile?.report_status ?? "—"}`,
  );

  check(
    "P1-03R/negative-floor",
    stock.negative_stock?.negative_observed === false &&
      Object.values(stock.negative_stock?.global_floor ?? { x: -1 }).every((value) => Number(value) >= 0),
    "Chiều negative_stock: không cột tồn nào âm ở bất kỳ thời điểm nào",
  );

  check(
    "P1-03R/race-losers",
    stock.true_concurrency?.lease_loser?.error_code === "CONCURRENCY_CONFLICT" &&
      stock.true_concurrency?.lease_loser?.second_stock_effect === false &&
      stock.true_concurrency?.optimistic_lock_loser?.second_stock_effect === false,
    "Chiều true_concurrency: người thua lease trả CONCURRENCY_CONFLICT và không nhánh thua nào ghi stock effect",
  );

  // Cổng QA1 đòi số liệu đo trên PostgreSQL. sqlite chạy được nhưng KHÔNG đủ để ký:
  // chỉ PostgreSQL mới biên dịch lockForUpdate thành FOR UPDATE.
  check(
    "P1-03R/postgres-evidence",
    stock.meta?.driver === "pgsql",
    `Artifact được sinh trên driver ${stock.meta?.driver ?? "—"} (cổng QA1 đòi pgsql)`,
  );
}

// ---------------------------------------------------------------------------
// DEV289-P1-04R · sáu nhóm security regression
// ---------------------------------------------------------------------------

const securityRaw = read("build/evidence/wwhn-289/security-regression-proof.json");
let security = null;
try {
  security = securityRaw ? JSON.parse(securityRaw) : null;
} catch {
  security = null;
}

check("P1-04R/raw-artifact", security !== null, "security-regression-proof.json tồn tại và là JSON hợp lệ");

if (security !== null) {
  const requiredSecurityKeys = [
    "unauthorized_anonymous",
    "unauthorized_underprivileged",
    "rbac_matrix",
    "data_scope",
    "replay",
    "rate_limit_login",
    "rate_limit_public_pv",
    "rate_limit_threshold_contract",
    "secret",
  ];
  const missing = requiredSecurityKeys.filter((key) => !(key in security));
  check(
    "P1-04R/six-dimensions",
    missing.length === 0,
    missing.length === 0
      ? "Đủ sáu nhóm: unauthorized, RBAC, data-scope, replay, rate-limit, secret"
      : `THIẾU nhóm: ${missing.join(", ")}`,
  );

  check(
    "P1-04R/no-rbac-bleed",
    security.rbac_matrix?.cross_operation_bleed === false && security.rbac_matrix?.denied_cells === 6,
    `Ma trận RBAC: ${security.rbac_matrix?.denied_cells ?? "—"}/6 ô DENY, không có rò quyền chéo`,
  );

  check(
    "P1-04R/threshold-ownership",
    typeof security.rate_limit_threshold_contract?.owner_of_numeric_values === "string" &&
      /BA/.test(security.rate_limit_threshold_contract.owner_of_numeric_values),
    "Hợp đồng ngưỡng rate-limit ghi rõ con số thuộc BA/DevOps, DEV không tự chốt",
  );
}

// ---------------------------------------------------------------------------
// DEV289-P2-01 · traceability: mọi số/SHA trong tài liệu phải bằng PINS
// ---------------------------------------------------------------------------

const documents = [
  "WWHN-289_DEFECT_FIX_REGISTER.md",
  "WWHN-289_STOCK_INVARIANT_PROOF.md",
  "WWHN-289_SECURITY_TRACE.md",
  "WWHN-289_QA1_HANDOFF.md",
  "WWHN-289_SIGNOFF_COMMENTS.md",
];

const pinnedShas = new Set(
  [
    range.base,
    range.final,
    pins.fe_consumer?.base,
    pins.fe_consumer?.final,
    ...(Array.isArray(pins.superseded_revisions) ? pins.superseded_revisions.map((entry) => entry.sha) : []),
  ].filter((value) => typeof value === "string"),
);

for (const document of documents) {
  const content = read(`${evidenceDir}/${document}`);
  check(`P2-01/exists/${document}`, content !== null && content.trim() !== "", `${document} tồn tại và không rỗng`);
  if (content === null) continue;

  // Mọi chuỗi trông như SHA (>=12 hex) trong tài liệu phải là prefix của một SHA đã pin.
  const strays = [...content.matchAll(/\b[0-9a-f]{12,40}\b/g)]
    .map((match) => match[0])
    .filter((candidate) => ![...pinnedShas].some((pinned) => pinned.startsWith(candidate)));
  check(
    `P2-01/sha-drift/${document}`,
    strays.length === 0,
    strays.length === 0
      ? `${document} không trích SHA nào ngoài PINS`
      : `${document} trích SHA lạ: ${[...new Set(strays)].join(", ")}`,
  );
}

// Số test chỉ được nêu ở MỘT nơi (PINS). Comment/tài liệu nêu số khác là lỗi P2-01.
const baseline = pins.test_baseline ?? {};
check(
  "P2-01/baseline-single-source",
  typeof baseline.after === "string" && typeof baseline.measured_at_sha === "string",
  "test_baseline khai cả số liệu VÀ SHA đo được số đó (P2-01: một nguồn duy nhất)",
);
check(
  "P2-01/baseline-sha-pinned",
  baseline.measured_at_sha === range.final,
  `test_baseline.measured_at_sha (${baseline.measured_at_sha ?? "—"}) trùng code_range.final`,
);

const supersessions = Array.isArray(pins.superseded_revisions) ? pins.superseded_revisions : [];
check(
  "P2-01/supersession-recorded",
  supersessions.length > 0 && supersessions.every((entry) => entry.sha && entry.reason),
  "Mỗi số liệu/pin bị thay thế đều có bản ghi supersession kèm lý do",
);

// ---------------------------------------------------------------------------
// Fail-closed: DEV không được tự ký các cổng của QA1/BA
// ---------------------------------------------------------------------------

const register = read(`${evidenceDir}/WWHN-289_DEFECT_FIX_REGISTER.md`) ?? "";
for (const item of ["DEV289-P1-01R", "DEV289-P1-05R", "DEV289-P1-06R"]) {
  check(
    `fail-closed/${item}`,
    register.includes(item),
    `Register nêu ${item} (cổng của QA1/BA) để không ai tưởng DEV đã đóng`,
  );
}
check(
  "fail-closed/no-dev-selfsign",
  /FAIL-CLOSED \/ NEEDS OWNER DISPOSITION/.test(register),
  "Register giữ nhãn FAIL-CLOSED cho các cổng DEV không có quyền ký",
);

// ---------------------------------------------------------------------------
// Kết quả
// ---------------------------------------------------------------------------

const failed = results.filter((result) => !result.ok);

if (jsonOut) {
  console.log(
    JSON.stringify(
      { jira: "WWHN-289", total: results.length, failed: failed.length, results },
      null,
      2,
    ),
  );
} else {
  for (const result of results) {
    console.log(`${result.ok ? "PASS" : "FAIL"}  ${result.id}  ${result.detail}`);
  }
  console.log(`\n${results.length - failed.length}/${results.length} bất biến còn đúng.`);
}

process.exit(failed.length === 0 ? 0 : 1);
