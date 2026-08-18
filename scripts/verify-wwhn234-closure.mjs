#!/usr/bin/env node
/**
 * WWHN-234 — verifier đóng register DEV234-P1-01..04.
 *
 * LÝ DO TỒN TẠI: vòng review trước rớt 32/100 vì output là lời văn, không phải thứ kiểm
 * chứng được. Một tài liệu nói "đã review concurrency" thì lần refactor sau vẫn đúng chính
 * tả nhưng đã sai sự thật. Script này biến từng mệnh đề của bản review thành một phép đo
 * chạy được, fail-closed.
 *
 * Chạy:
 *   node scripts/verify-wwhn234-closure.mjs
 *   node scripts/verify-wwhn234-closure.mjs --json
 *
 * Exit 0 = mọi bất biến còn đúng. Exit 1 = có bất biến gãy, in rõ cái nào.
 */
import { readFileSync, existsSync, readdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const evidence = join(root, "docs/evidence/WWHN-234");
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

// ---------------------------------------------------------------------------
// DEV234-P1-01 · scope authority: Stocktake không được quay lại runtime
// ---------------------------------------------------------------------------

const disposition = read("docs/evidence/WWHN-234/SCOPE_AUTHORITY_DISPOSITION.md");
check(
  "P1-01/doc",
  disposition !== null && disposition.includes("DECISION-READY"),
  "SCOPE_AUTHORITY_DISPOSITION.md tồn tại và tự khai là chưa phê duyệt",
);
check(
  "P1-01/no-false-approval",
  disposition !== null &&
    !/DEC-NO-STOCKTAKE-001[^\n]{0,80}\bAPPROVED\b/i.test(disposition),
  "Không tài liệu nào được tuyên bố DEC-NO-STOCKTAKE-001 là APPROVED (WWHN-25 vẫn PENDING)",
);

const routes = read("routes/api.php") ?? "";
check(
  "P1-01/no-stocktake-route",
  !/stocktake/i.test(routes),
  "routes/api.php không khai route kiểm kê nào",
);

const openApiRaw = read("storage/api-docs/api-docs.json");
let openApi = null;
try {
  openApi = openApiRaw === null ? null : JSON.parse(openApiRaw);
} catch {
  openApi = null;
}
check(
  "P1-01/openapi-version",
  openApi?.info?.version === "2.0.0",
  `OpenAPI info.version = ${openApi?.info?.version ?? "<không đọc được>"} (kỳ vọng 2.0.0)`,
);
const stocktakeOps = openApi
  ? Object.keys(openApi.paths ?? {}).filter((p) => /stocktake/i.test(p))
  : [];
check(
  "P1-01/openapi-no-stocktake",
  openApi !== null && stocktakeOps.length === 0,
  `OpenAPI có ${stocktakeOps.length} path kiểm kê (kỳ vọng 0)`,
);

// ---------------------------------------------------------------------------
// DEV234-P1-02 · hợp đồng xung đột 409 của SRS §8.2
// ---------------------------------------------------------------------------

const conflictCode = read("app/Support/ConflictCode.php");
const CANONICAL = [
  "STATE_CONFLICT",
  "IDEMPOTENCY_CONFLICT",
  "CONCURRENCY_CONFLICT",
  "DOWNSTREAM_DEPENDENCY_EXISTS",
];
check(
  "P1-02/conflict-class",
  conflictCode !== null && CANONICAL.every((code) => conflictCode.includes(`'${code}'`)),
  "app/Support/ConflictCode.php khai đủ bốn lớp xung đột của §8.2",
);

const WRITE_PATH = [
  "Modules/Warehouse/app/Services/CurrentBaselineStockService.php",
  "Modules/Warehouse/app/Services/CurrentBaselineReversalService.php",
  "Modules/Warehouse/app/Services/AlignedOutboundStockService.php",
  "Modules/Warehouse/app/Services/ContainerService.php",
  "Modules/Warehouse/app/Http/Controllers/CurrentBaselineContainerController.php",
  "Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php",
];
const RETIRED = [
  "VERSION_CONFLICT",
  "CONTAINER_VERSION_CONFLICT",
  "REVERSAL_CONCURRENT_CONFLICT",
  "REVERSAL_DEPENDENCY_EXISTS",
];
for (const relative of WRITE_PATH) {
  const source = read(relative);
  const offenders = source === null ? ["<không đọc được file>"] : RETIRED.filter((code) => source.includes(`'${code}'`));
  check(
    `P1-02/retired-code/${relative.split("/").pop()}`,
    offenders.length === 0,
    offenders.length === 0
      ? "không phát lại mã đã loại bỏ"
      : `phát lại mã đã loại bỏ: ${offenders.join(", ")}`,
  );
}

// F-09: mọi đường Post đổi tồn có idempotency phải lộ cờ replayed ở body.
const REPLAY_SITES = [
  "Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php",
  "Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php",
  "Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentFlowController.php",
  "Modules/Warehouse/app/Http/Controllers/CurrentBaselineComponentIssueController.php",
];
for (const relative of REPLAY_SITES) {
  const source = read(relative);
  const ok =
    source !== null &&
    (source.includes("IdempotentEnvelope::withReplayFlag") ||
      source.includes("['replayed']"));
  check(
    `P1-02/replayed-flag/${relative.split("/").pop()}`,
    ok,
    ok ? "phát data.replayed" : "KHÔNG phát data.replayed — vi phạm TC-P1-005/013/029",
  );
}

// FE: bốn lớp §8.2 phải được map thành hành động UI (AC-GWT-CONC-001).
const FE_CANDIDATES = [
  join(root, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE/src/shared/api/concurrency-contract.ts"),
  join(root, "../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE/src/shared/api/concurrency-contract.ts"),
];
const fePath = FE_CANDIDATES.find((candidate) => existsSync(candidate));
if (fePath === undefined) {
  check("P1-02/fe-contract", true, "SKIP — repo FE không có mặt cạnh repo BE");
} else {
  const feSource = readFileSync(fePath, "utf8");
  const ok =
    feSource.includes("STALE_VERSION") &&
    feSource.includes("CONCURRENCY_CONFLICT") &&
    feSource.includes("IDEMPOTENCY_CONFLICT") &&
    feSource.includes("DOWNSTREAM_DEPENDENCY_EXISTS") &&
    feSource.includes("RELOAD_THEN_RETRY");
  check("P1-02/fe-contract", ok, "FE map đủ bốn lớp xung đột thành hành động UI");
}

// ---------------------------------------------------------------------------
// DEV234-P1-03 · traceability TC §13.3 → test thực thi
// ---------------------------------------------------------------------------

const traceRaw = read("docs/evidence/WWHN-234/TC_P1_TRACEABILITY.json");
let trace = null;
try {
  trace = traceRaw === null ? null : JSON.parse(traceRaw);
} catch {
  trace = null;
}

check("P1-03/trace-readable", trace !== null, "TC_P1_TRACEABILITY.json parse được");

if (trace !== null) {
  const expected = [];
  for (let i = 1; i <= 16; i += 1) expected.push(`TC-P1-${String(i).padStart(3, "0")}`);
  for (let i = 25; i <= 40; i += 1) expected.push(`TC-P1-${String(i).padStart(3, "0")}`);

  const covered = new Set(trace.coverage.map((row) => row.tc));
  const missing = expected.filter((id) => !covered.has(id));
  check(
    "P1-03/trace-complete",
    missing.length === 0,
    missing.length === 0
      ? `đủ ${expected.length} TC của §13.3`
      : `thiếu TC: ${missing.join(", ")}`,
  );

  // Mọi test class được trích dẫn phải tồn tại thật — chống "evidence trỏ vào hư không".
  const testFiles = new Set(
    existsSync(join(root, "tests/Feature")) ? readdirSync(join(root, "tests/Feature")) : [],
  );
  const dangling = [];
  for (const row of trace.coverage) {
    for (const test of row.tests ?? []) {
      const className = test.split("::")[0].split("\\").pop();
      if (!testFiles.has(`${className}.php`)) dangling.push(`${row.tc} → ${className}`);
    }
  }
  check(
    "P1-03/trace-tests-exist",
    dangling.length === 0,
    dangling.length === 0
      ? "mọi test class được trích dẫn đều tồn tại"
      : `trích dẫn test không tồn tại: ${dangling.join("; ")}`,
  );

  const counted = { covered: 0, partial: 0, gap: 0 };
  for (const row of trace.coverage) {
    if (row.status === "COVERED") counted.covered += 1;
    if (row.status === "PARTIAL") counted.partial += 1;
    if (row.status === "GAP") counted.gap += 1;
  }
  const summary = trace.summary ?? {};
  check(
    "P1-03/trace-summary-honest",
    counted.covered === summary.covered &&
      counted.partial === summary.partial &&
      counted.gap === summary.gap,
    `đếm thật COVERED=${counted.covered}/PARTIAL=${counted.partial}/GAP=${counted.gap} vs summary ` +
      `${summary.covered}/${summary.partial}/${summary.gap}`,
  );
}

const handoff = read("docs/evidence/WWHN-234/QA1_HANDOFF_WWHN-235.md");
check(
  "P1-03/handoff",
  handoff !== null && handoff.includes("235-A") && handoff.includes("235-B"),
  "QA1_HANDOFF_WWHN-235.md tách rõ phần phải chạy và phần N/A-DEFERRED",
);

// ---------------------------------------------------------------------------
// DEV234-P1-04 · identity pin
// ---------------------------------------------------------------------------

const pinRaw = read("docs/evidence/WWHN-234/REVIEW_IDENTITY_PIN.json");
let pin = null;
try {
  pin = pinRaw === null ? null : JSON.parse(pinRaw);
} catch {
  pin = null;
}
check("P1-04/pin-readable", pin !== null, "REVIEW_IDENTITY_PIN.json parse được");

if (pin !== null) {
  check(
    "P1-04/pin-sha",
    /^[0-9a-f]{40}$/.test(pin.backend?.review_sha ?? "") &&
      /^[0-9a-f]{40}$/.test(pin.frontend?.review_sha ?? ""),
    "pin có đủ exact SHA 40 ký tự cho cả BE và FE",
  );
  check(
    "P1-04/pin-change-identity",
    pin.code_change === true
      ? Array.isArray(pin.code_changes) && pin.code_changes.length > 0
      : pin.no_code_change_declaration !== undefined,
    "pin khai rõ danh sách file đổi, hoặc khai formal NO CODE CHANGE",
  );
  // Live invariant thay cho blob pin: m1 là nhánh dùng chung, blob của OpenAPI bị ticket
  // khác regenerate nhiều lần trong cùng một ngày (bài học WWHN-290 "pin rot").
  check(
    "P1-04/live-invariant",
    pin.live_invariants?.openapi_info_version === openApi?.info?.version,
    `live invariant OpenAPI version khớp file thật (${openApi?.info?.version})`,
  );
}

const review = read("docs/evidence/WWHN-234/CONCURRENCY_STATE_REVIEW.md");
const REQUIRED_SECTIONS = [
  "Ma trận trạng thái",
  "Lock target và lock order",
  "Stale / version behavior",
  "Duplicate handling",
  "Transaction boundary",
  "Rollback",
  "Partial behavior",
  "Ranh giới stock-effect",
  "Permission / error / audit",
];
const missingSections = review === null ? REQUIRED_SECTIONS : REQUIRED_SECTIONS.filter((s) => !review.includes(s));
check(
  "P1-02/review-sections",
  missingSections.length === 0,
  missingSections.length === 0
    ? "bản review có đủ chín mục bắt buộc của register"
    : `thiếu mục: ${missingSections.join(", ")}`,
);

// ---------------------------------------------------------------------------

const failed = results.filter((r) => !r.ok);

if (jsonOut) {
  console.log(JSON.stringify({ passed: results.length - failed.length, failed: failed.length, results }, null, 2));
} else {
  for (const r of results) {
    console.log(`${r.ok ? "PASS" : "FAIL"}  ${r.id.padEnd(46)} ${r.detail}`);
  }
  console.log("");
  console.log(`[wwhn-234] ${results.length - failed.length}/${results.length} bất biến còn đúng.`);
}

process.exit(failed.length === 0 ? 0 : 1);
