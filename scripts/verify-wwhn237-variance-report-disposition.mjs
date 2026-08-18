#!/usr/bin/env node
/**
 * WWHN-237 hard gate — DISP-STK-237 (Variance query/report DEFERRED).
 *
 * `DISP-STK-236` khoá *hành động* Apply Adjustment. Gate này khoá phần còn lại mà
 * `REQ-SRS-037` cũng cấm nhưng chưa có ai canh: **truy vấn và báo cáo variance**.
 * Một report code, một mục trong /reports/hub, một cột export hay một field KPI
 * dashboard mang ngữ nghĩa chênh lệch kiểm kê đều là "report" theo đúng nghĩa của
 * REQ-SRS-037 — và trước gate này không tầng nào chặn chúng.
 *
 * Chạy độc lập, không boot Laravel/DB nên dùng được cả trong CI lẫn local:
 *
 *   node scripts/verify-wwhn237-variance-report-disposition.mjs
 *   node scripts/verify-wwhn237-variance-report-disposition.mjs --emit-lock
 *
 * `--emit-lock` ghi docs/evidence/WWHN-237/CONTRACT_NEGATIVE_LOCK.json (DEV237-P1-05).
 */
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from "node:fs";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const EMIT_LOCK = process.argv.includes("--emit-lock");

const failures = [];
const checks = [];
const warnings = [];
const lock = { jira_key: "WWHN-237", disposition: "DISP-STK-237", checks: {} };

const fail = (id, message) => failures.push(`[${id}] ${message}`);
const pass = (id, message) => checks.push(`[${id}] ${message}`);
const warn = (id, message) => warnings.push(`[${id}] ${message}`);

const abs = (rel) => join(ROOT, rel);
const readIfExists = (rel) => (existsSync(abs(rel)) ? readFileSync(abs(rel), "utf8") : null);
const sha256 = (rel) => (existsSync(abs(rel)) ? createHash("sha256").update(readFileSync(abs(rel))).digest("hex") : null);
const toPosix = (p) => p.replace(/\\/g, "/");

/**
 * Ranh giới quan trọng nhất của gate này: **variance của reconciliation là hợp lệ**,
 * variance của kiểm kê thì không.
 *
 * `AC-SRS-037` nói "reconciliation chỉ tạo incident", tức RPT-INV-01 *được phép* và
 * *bắt buộc* tính `variance = balance.on_hand_qty - SUM(stock_movements.qty_delta)`
 * — đó là chênh lệch giữa số dư chiếu và sổ ledger, không phải giữa đếm tay và hệ
 * thống. Cấm cả chữ `variance` sẽ giết luôn cơ chế thay thế mà chính REQ-SRS-037
 * chỉ định. Vì vậy tách hai danh sách.
 */

/** Áp cho *mã báo cáo / key hub / đường export* — nơi một cái tên là một capability. */
const FORBIDDEN_REPORT_CODE_FRAGMENTS = [
  "stocktake",
  "variance",
  "adjustment",
  "discrepancy",
  "kiem-ke",
  "kiemke",
];

/**
 * Áp cho route, OpenAPI, contract artifact. Chỉ chặn ngữ nghĩa kiểm kê, để
 * reconciliation variance và trường `variance` của RPT-INV-01 đi qua được.
 */
const FORBIDDEN_SURFACE_FRAGMENTS = [
  "stocktake",
  "variance_line",
  "variance-line",
  "stocktake_variance",
  "apply-adjustment",
  "apply_adjustment",
  "stock-adjustment",
  "stock_adjustment",
  "discrepancy",
];

/**
 * Pack bàn giao WWHN-180 + WWHN-150 là artifact immutable đã bị `DISP-STK-236 §3`
 * hạ xuống historical: một immutable handoff chỉ được superseded bằng decision
 * record trỏ tới nó, không bao giờ bằng cách sửa nội dung. Gate ghi digest của
 * chúng ra lock để reviewer nhìn thấy chính xác cái gì được miễn, nhưng KHÔNG fail.
 * Mọi artifact NGOÀI danh sách này còn bề mặt variance thì fail.
 */
const HISTORICAL_ARTIFACT_ALLOWLIST = [
  "docs/api-contract/WWHN-180-posting-openapi.json",
  "docs/api-contract/WWHN-180-posting-openapi.yaml",
  "docs/api-contract/WWHN-150-scan-openapi.yaml",
  "docs/postman/WWHN-180 Canonical Contract.postman_collection.json",
  "docs/postman/WWHN-180 Canonical Contract.postman_environment.json",
  "docs/evidence/WWHN-180/permission-catalog.json",
  "docs/evidence/WWHN-180/BE_FE_PERMISSION_MAPPING.json",
  // DEC-REV-001 `1.3.0-proposed` là immutable release (RELEASE_MANIFEST.json +
  // SHA256SUMS, `release_status: PROPOSED`) của WWHN-26/WWHN-134, không phải của
  // WWHN-237. `CreateReversalRequest.original_document_type` còn enum `ADJUSTMENT`
  // trong khi runtime chỉ sinh `INBOUND`/`OUTBOUND` — chính manifest đã ghi nhận
  // `runtime_divergence`. Đây là finding chuyển cho owner của DEC-REV-001, không
  // được sửa ở đây vì reviewer verify theo digest trong manifest.
  "docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/openapi.yaml",
  "docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/reversal.schema.json",
];

const walkFiles = (relDir, predicate) => {
  const out = [];
  const root = abs(relDir);
  if (!existsSync(root)) return out;
  const stack = [root];
  while (stack.length > 0) {
    const current = stack.pop();
    for (const name of readdirSync(current)) {
      const full = join(current, name);
      if (statSync(full).isDirectory()) {
        stack.push(full);
        continue;
      }
      const rel = toPosix(relative(ROOT, full));
      if (predicate(rel)) out.push(rel);
    }
  }
  return out.sort();
};

// ── Check 1: disposition record tồn tại và neo đúng controlling source ────────
{
  const rel = "docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md";
  const doc = readIfExists(rel);
  if (doc === null) {
    fail("DISP-EXISTS", `${rel} không tồn tại — WWHN-237 bắt buộc có disposition bằng văn bản.`);
  } else {
    const required = [
      ["REQ-SRS-037", "phải neo controlling source vào REQ-SRS-037"],
      ["DEFERRED", "phải nêu rõ disposition DEFERRED"],
      ["WWHN-238", "phải nêu disposition của QA gate WWHN-238"],
      ["WWHN-60", "phải nêu disposition AC của parent WWHN-60"],
      ["WWHN-25", "phải nêu điều kiện re-enable qua WWHN-25 / DEC-STK-001"],
    ];
    const missing = required.filter(([needle]) => !doc.includes(needle));
    if (missing.length > 0) {
      fail("DISP-CONTENT", `${rel} thiếu: ${missing.map(([n, why]) => `${n} (${why})`).join("; ")}`);
    } else {
      pass("DISP-CONTENT", "DISP-STK-237 neo REQ-SRS-037 + DEFERRED + WWHN-238 + WWHN-60 + WWHN-25");
    }
    lock.checks.disposition_sha256 = sha256(rel);
  }
}

// ── Check 2: controlling baseline vẫn thật sự nói điều gate đang enforce ──────
{
  const rel = "docs/HN_SRS_FRD_v1.12_FULL.md";
  const srs = readIfExists(rel);
  if (srs === null) {
    fail("BASELINE", `${rel} không tồn tại — không được enforce một rule không truy được về baseline.`);
  } else if (!srs.includes("REQ-SRS-037") || !srs.includes("Không expose Stocktake/Variance")) {
    fail("BASELINE", `${rel} không còn phát biểu REQ-SRS-037 — gate phải được rà lại trước khi tiếp tục chặn.`);
  } else {
    pass("BASELINE", "REQ-SRS-037 còn hiệu lực trong SRS/FRD v1.12");
    lock.checks.srs_sha256 = sha256(rel);
  }

  const erd = readIfExists("docs/HoaNam_ERD_Moi_Nhat.md");
  if (erd === null || !/Variance_Line/u.test(erd)) {
    fail("BASELINE-ERD", "ERD §0.1 không còn loại Variance_Line khỏi baseline.");
  } else {
    pass("BASELINE-ERD", "ERD §0.1 vẫn loại Variance_Line/Stocktake_* khỏi baseline");
    lock.checks.erd_sha256 = sha256("docs/HoaNam_ERD_Moi_Nhat.md");
  }
}

// ── Check 3: DEC-STK-001 vẫn PROPOSED, không ai âm thầm nâng lên APPROVED ─────
{
  const rel = "docs/decision-records/DEC-STK-001.md";
  const doc = readIfExists(rel);
  if (doc === null) {
    fail("DEC-STK-001", `${rel} biến mất — bản nháp phải được giữ nguyên làm bản ghi lịch sử.`);
  } else if (/^\s*[-|]?\s*\**\s*status\s*\**\s*[:|]\s*\**\s*approved/im.test(doc)) {
    fail("DEC-STK-001", `${rel} tự tuyên bố APPROVED trong khi WWHN-25 chưa đóng.`);
  } else {
    pass("DEC-STK-001", "DEC-STK-001 vẫn là bản nháp chưa có effective date");
  }
}

// ── Check 4: report registry / hub / export không có mã variance ──────────────
{
  const rel = "Modules/Warehouse/app/Http/Controllers/OperationalReportController.php";
  const source = readIfExists(rel);
  if (source === null) {
    fail("REPORT-REGISTRY", `${rel} không tồn tại.`);
  } else {
    // Soi đúng ba nơi đăng ký báo cáo, không soi cả file: `variance` xuất hiện hợp
    // lệ trong builder reconciliation của RPT-INV-01 và không được tính là vi phạm.
    const registryBlocks = [
      ["REPORT_BUILDERS", /const REPORT_BUILDERS = \[(.*?)\];/s],
      ["ALIGNED_REPORTS", /const ALIGNED_REPORTS = \[(.*?)\];/s],
      ["EXPORT_DOWNLOAD_PATHS", /const EXPORT_DOWNLOAD_PATHS = \[(.*?)\];/s],
      ["hub()", /public function hub\(\)\s*\{(.*?)\n    \}/s],
    ];
    const registryOffenders = [];
    const reportCodes = [];
    for (const [name, pattern] of registryBlocks) {
      const block = source.match(pattern)?.[1];
      if (block === undefined) {
        fail("REPORT-REGISTRY", `${rel}: không đọc được khối ${name} — gate phải được cập nhật theo source.`);
        continue;
      }
      for (const literal of block.match(/'[^']*'/g) ?? []) {
        const value = literal.slice(1, -1);
        reportCodes.push(value);
        const hit = FORBIDDEN_REPORT_CODE_FRAGMENTS.find((fragment) => value.toLowerCase().includes(fragment));
        if (hit) registryOffenders.push(`${name} → ${value} (${hit})`);
      }
    }
    if (registryOffenders.length > 0) {
      fail("REPORT-REGISTRY", `${rel} khai report code/label bị cấm:\n    ${registryOffenders.join("\n    ")}`);
    } else {
      pass(
        "REPORT-REGISTRY",
        `report registry + hub + export path: ${reportCodes.length} literal, zero mã variance/kiểm kê`,
      );
    }
    lock.checks.report_registry_literals = [...new Set(reportCodes)].sort();

    // Dashboard KPI: DEV237-P1-03 nêu đích danh `stocktake_variance`.
    const dashboard = source.match(/public function dashboardQuick\(.*?\n    \}/s)?.[0] ?? "";
    if (/stocktake_variance|variance_qty|variance_count|count_variance/i.test(dashboard)) {
      fail("REPORT-KPI", `${rel}: dashboardQuick còn field KPI mang ngữ nghĩa kiểm kê.`);
    } else {
      pass("REPORT-KPI", "dashboardQuick không có field stocktake_variance");
    }

    // Nghĩa vụ dương: đường thay thế mà REQ-SRS-037 chỉ định phải còn sống.
    const reconciliationAlive =
      /reconciliation_status/.test(source) &&
      /variance = COALESCE\(balance\.on_hand_qty, 0\) - SUM\(stock_movements\.qty_delta\)/.test(source);
    if (!reconciliationAlive) {
      fail(
        "RECONCILIATION-ALIVE",
        `${rel}: mất reconciliation_status hoặc công thức variance của RPT-INV-01 — AC-SRS-037 yêu cầu reconciliation là cơ chế thay thế, không được gỡ theo.`,
      );
    } else {
      pass("RECONCILIATION-ALIVE", "RPT-INV-01 vẫn tính variance ledger-vs-balance và trả reconciliation_status");
    }
    lock.checks.report_controller_sha256 = sha256(rel);
  }
}

// ── Check 5: route table nguồn không có bề mặt variance ──────────────────────
{
  const rel = "Modules/Warehouse/routes/api.php";
  const source = readIfExists(rel);
  if (source === null) {
    fail("ROUTE", `${rel} không tồn tại.`);
  } else {
    const routeLines = source.split("\n").filter((line) => /Route::(get|post|put|patch|delete)/.test(line));
    const offenders = routeLines.filter((line) =>
      FORBIDDEN_SURFACE_FRAGMENTS.some((fragment) => line.toLowerCase().includes(fragment)),
    );
    if (offenders.length > 0) {
      fail("ROUTE", `route table còn bề mặt bị cấm:\n    ${offenders.map((l) => l.trim()).join("\n    ")}`);
    } else {
      pass("ROUTE", `${routeLines.length} route: zero bề mặt variance/stocktake/adjustment`);
    }
    lock.checks.route_source_sha256 = sha256(rel);
  }
}

// ── Check 6: OpenAPI runtime (sinh từ route thật) sạch ───────────────────────
{
  const rel = "storage/api-docs/api-docs.json";
  const raw = readIfExists(rel);
  if (raw === null) {
    warn("OPENAPI-RUNTIME", `${rel} chưa được sinh — bỏ qua; CI phải chạy l5-swagger trước gate này.`);
  } else {
    let spec = null;
    try {
      spec = JSON.parse(raw);
    } catch (error) {
      fail("OPENAPI-RUNTIME", `${rel} không phải JSON hợp lệ: ${error.message}`);
    }
    if (spec) {
      const paths = Object.keys(spec.paths ?? {}).filter((p) =>
        FORBIDDEN_SURFACE_FRAGMENTS.some((fragment) => p.toLowerCase().includes(fragment)),
      );
      const tags = (spec.tags ?? [])
        .map((t) => String(t?.name ?? ""))
        .filter((name) => FORBIDDEN_SURFACE_FRAGMENTS.some((fragment) => name.toLowerCase().includes(fragment)));
      const enums = /"STOCKTAKE"/.test(raw) ? ['enum value "STOCKTAKE"'] : [];
      const offenders = [...paths, ...tags, ...enums];
      if (offenders.length > 0) {
        fail("OPENAPI-RUNTIME", `${rel} còn bề mặt bị cấm: ${offenders.join(", ")}`);
      } else {
        pass("OPENAPI-RUNTIME", `OpenAPI runtime: ${Object.keys(spec.paths ?? {}).length} path, zero bề mặt variance`);
      }
      lock.checks.openapi_runtime_sha256 = sha256(rel);
    }
  }
}

// ── Check 7: bộ contract bàn giao hiện hành (trừ pack immutable) ─────────────
{
  const artifacts = [
    ...walkFiles("docs/postman", (rel) => /\.json$/i.test(rel)),
    ...walkFiles("docs/api-contract/releases", () => true),
  ];
  const liveOffenders = [];
  const historicalDrift = [];

  for (const rel of artifacts) {
    const raw = readFileSync(abs(rel), "utf8").toLowerCase();
    const nameHit = FORBIDDEN_SURFACE_FRAGMENTS.filter((fragment) => rel.toLowerCase().includes(fragment));
    const bodyHit = FORBIDDEN_SURFACE_FRAGMENTS.filter((fragment) => raw.includes(fragment));
    const hits = [...new Set([...nameHit, ...bodyHit])];
    if (hits.length === 0) continue;

    const detail = `${rel} → ${hits.join(", ")}`;
    if (HISTORICAL_ARTIFACT_ALLOWLIST.includes(rel)) historicalDrift.push(detail);
    else liveOffenders.push(detail);
  }

  if (liveOffenders.length > 0) {
    fail(
      "CONTRACT-SURFACE",
      `Contract artifact bàn giao hiện hành còn bề mặt bị cấm:\n    ${liveOffenders.join("\n    ")}\n` +
        "    Muốn giữ, phải thêm vào HISTORICAL_ARTIFACT_ALLOWLIST kèm disposition tường minh — không sửa im lặng.",
    );
  } else {
    pass(
      "CONTRACT-SURFACE",
      `${artifacts.length} contract artifact: zero bề mặt bị cấm ngoài ${historicalDrift.length} artifact historical đã có disposition`,
    );
  }
  for (const detail of historicalDrift) {
    warn("CONTRACT-HISTORICAL", `${detail} — historical, superseded bởi DISP-STK-236 §3 / DISP-STK-237 §4; không sửa nội dung.`);
  }
  lock.checks.historical_artifact_drift = historicalDrift;
  lock.checks.historical_artifact_allowlist = Object.fromEntries(
    HISTORICAL_ARTIFACT_ALLOWLIST.map((rel) => [rel, sha256(rel)]),
  );
}

// ── Check 8: baseline contract vẫn khai forbidden fragment + excluded table ───
{
  const rel = "docs/contracts/HoaNam_Current_Baseline_Contract.json";
  const raw = readIfExists(rel);
  if (raw === null) {
    fail("BASELINE-CONTRACT", `${rel} không tồn tại.`);
  } else {
    const contract = JSON.parse(raw);
    const fragments = JSON.stringify(contract).toLowerCase();
    const missing = ["stocktake", "variance"].filter((fragment) => !fragments.includes(fragment));
    if (missing.length > 0) {
      fail("BASELINE-CONTRACT", `${rel} không còn khai mảnh cấm: ${missing.join(", ")}`);
    } else {
      pass("BASELINE-CONTRACT", "baseline contract vẫn khai forbidden fragment + excluded table kiểm kê");
    }
    lock.checks.baseline_contract_sha256 = sha256(rel);
  }
}

// ── Kết quả ──────────────────────────────────────────────────────────────────
console.log("WWHN-237 / DISP-STK-237 — Variance query/report disposition gate\n");
for (const c of checks) console.log(`  PASS ${c}`);
for (const w of warnings) console.log(`  NOTE ${w}`);

if (EMIT_LOCK) {
  const outDir = abs("docs/evidence/WWHN-237");
  mkdirSync(outDir, { recursive: true });
  lock.status = failures.length > 0 ? "FAIL" : "PASS";
  lock.controlling_requirement = "REQ-SRS-037";
  lock.forbidden_report_code_fragments = FORBIDDEN_REPORT_CODE_FRAGMENTS;
  lock.forbidden_surface_fragments = FORBIDDEN_SURFACE_FRAGMENTS;
  lock.failures = failures;
  writeFileSync(join(outDir, "CONTRACT_NEGATIVE_LOCK.json"), `${JSON.stringify(lock, null, 2)}\n`);
  console.log("\n  lock → docs/evidence/WWHN-237/CONTRACT_NEGATIVE_LOCK.json");
}

if (failures.length > 0) {
  console.error("\nFAIL:");
  for (const f of failures) console.error(`  ${f}`);
  process.exit(1);
}
console.log(`\nOK — ${checks.length} check pass, ${warnings.length} note.`);
