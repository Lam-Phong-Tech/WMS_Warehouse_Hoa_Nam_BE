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
import { dirname, extname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import yaml from "js-yaml";
import {
  BE_ARTIFACT_SCOPE,
  BE_SCOPE_MANDATORY,
  FE_SCOPE_MANDATORY,
  PAIR_RELATIVE_PATH,
  resolveCandidate,
} from "./lib/wwhn237-candidate-pair.mjs";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const FE_ROOT = resolve(ROOT, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE");
const EMIT_LOCK = process.argv.includes("--emit-lock");
const argValue = (name) => {
  const hit = process.argv.slice(2).find((a) => a.startsWith(name + "="));
  return hit ? hit.slice(name.length + 1) : null;
};
const EXPECT_BE_SHA = argValue("--expect-be-sha");
const EXPECT_FE_SHA = argValue("--expect-fe-sha");

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

/**
 * WWHN-190/DISP-STK-236 §3 xác lập khuôn mẫu: release pack mới ghi lại surface đã gỡ
 * bằng `x-removed-surfaces` (+ `x-supersedes`, `description`, `reason`, `declared_in`)
 * — đây LÀ bằng chứng tuân thủ REQ-SRS-037, không phải capability sống, nên phải loại
 * khỏi vùng quét trước khi so needle. Cùng danh sách khoá mà
 * `Flow05InventoryControlAlignmentTest::stripStocktakeNarrativeFields` (PHP) đã dùng,
 * để hai gate không lệch nhau. Path/tag/enum thật (nằm ngoài các khoá tường thuật này)
 * vẫn bị bắt.
 */
const NARRATIVE_KEYS = ["description", "summary", "reason", "declared_in", "notes", "title"];
const stripNarrativeFields = (node) => {
  if (Array.isArray(node)) return node.map(stripNarrativeFields);
  if (node && typeof node === "object") {
    const out = {};
    for (const [key, value] of Object.entries(node)) {
      if (key === "x-removed-surfaces" || key === "x-supersedes") continue;
      if (NARRATIVE_KEYS.includes(key)) continue;
      out[key] = stripNarrativeFields(value);
    }
    return out;
  }
  return node;
};

/**
 * `.md` dưới release pack là văn xuôi thuật lại lịch sử (CHANGELOG), không phải hợp
 * đồng máy đọc được — bỏ qua toàn bộ thân bài, giống guard PHP. `.json`/`.yaml` được
 * parse rồi gỡ khoá tường thuật trước khi so needle; parse lỗi thì giữ nguyên raw để
 * không bỏ lọt (fail-closed).
 */
const scanTargetFor = (rel, raw) => {
  const ext = extname(rel).toLowerCase();
  if (ext === ".md") return "";
  if (ext === ".json") {
    try {
      const decoded = JSON.parse(raw);
      if (decoded && typeof decoded === "object") return JSON.stringify(stripNarrativeFields(decoded)).toLowerCase();
    } catch {
      // không parse được: giữ nguyên raw bên dưới.
    }
  }
  if (ext === ".yaml" || ext === ".yml") {
    try {
      const decoded = yaml.load(raw);
      if (decoded && typeof decoded === "object") return JSON.stringify(stripNarrativeFields(decoded)).toLowerCase();
    } catch {
      // YAML không parse được: giữ nguyên raw bên dưới.
    }
  }
  return raw.toLowerCase();
};

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
    const raw = readFileSync(abs(rel), "utf8");
    const scanTarget = scanTargetFor(rel, raw);
    const nameHit = FORBIDDEN_SURFACE_FRAGMENTS.filter((fragment) => rel.toLowerCase().includes(fragment));
    const bodyHit = FORBIDDEN_SURFACE_FRAGMENTS.filter((fragment) => scanTarget.includes(fragment));
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

// ── Check 9: frozen candidate pair — DEV237-P1-02R2 ──────────────────────────
/**
 * Lỗi thật của vòng R1: `SOURCE_REGISTER.json` và `FE_CLOSURE_IDENTITY.json` đều ghi
 * `git rev-parse HEAD` tại thời điểm chạy, hai repo chạy lệch giờ nên khai **hai cặp
 * SHA khác nhau**. Check này bắt buộc đúng **một** cặp, giải lại được và tự kiểm.
 */
{
  const rel = PAIR_RELATIVE_PATH;
  const raw = readIfExists(rel);
  if (raw === null) {
    fail("CANDIDATE-PAIR", `${rel} không tồn tại — chạy \`node scripts/build-wwhn237-candidate-pair.mjs\`.`);
  } else {
    let pinned = null;
    try {
      pinned = JSON.parse(raw);
    } catch (error) {
      fail("CANDIDATE-PAIR", `${rel} không phải JSON hợp lệ: ${error.message}`);
    }

    if (pinned) {
      const beSha = pinned?.pair?.backend?.commit_sha ?? null;
      const feSha = pinned?.pair?.frontend?.commit_sha ?? null;
      const problems = [];

      for (const [label, sha] of [["backend", beSha], ["frontend", feSha]]) {
        if (!/^[0-9a-f]{40}$/.test(sha ?? "")) problems.push(`pair.${label}.commit_sha không phải SHA đầy đủ 40 ký tự`);
      }

      // Scope không được co lại cho pin dễ thoả.
      const beScope = pinned?.pair?.backend?.artifact_scope ?? [];
      const feScope = pinned?.pair?.frontend?.artifact_scope ?? [];
      const missingBe = BE_SCOPE_MANDATORY.filter((p) => !beScope.includes(p));
      const missingFe = FE_SCOPE_MANDATORY.filter((p) => !feScope.includes(p));
      if (missingBe.length > 0) problems.push(`scope BE thiếu file bắt buộc: ${missingBe.join(", ")}`);
      if (missingFe.length > 0) problems.push(`scope FE thiếu file bắt buộc: ${missingFe.join(", ")}`);

      // Anti-dangling: mọi path trong scope phải tồn tại thật.
      const danglingBe = beScope.filter((p) => !existsSync(abs(p)));
      if (danglingBe.length > 0) problems.push(`scope BE trỏ file không tồn tại: ${danglingBe.join(", ")}`);

      // Giải lại candidate BE từ chính scope đã khai — pin phải tự kiểm được.
      const live = resolveCandidate(ROOT, beScope.length > 0 ? beScope : BE_ARTIFACT_SCOPE);
      if (live === null) {
        warn("CANDIDATE-PAIR", "không giải lại được candidate BE (không có git trong môi trường này) — bỏ qua đối chiếu live.");
      } else if (live.commit_sha !== beSha) {
        problems.push(
          `pin BE ${beSha} ≠ commit cuối chạm scope ${live.commit_sha} — artifact WWHN-237 đã đổi sau khi pin. ` +
            "Chạy lại `node scripts/build-wwhn237-candidate-pair.mjs` rồi xin lại consumer confirmation.",
        );
      }

      // Nửa FE: nếu repo FE với tới được thì kiểm cả bản sao byte-identical.
      if (existsSync(FE_ROOT)) {
        const feCopy = join(FE_ROOT, PAIR_RELATIVE_PATH);
        if (!existsSync(feCopy)) problems.push(`bản sao FE ${PAIR_RELATIVE_PATH} không tồn tại`);
        else if (readFileSync(feCopy, "utf8") !== raw) problems.push("bản sao FE của CANDIDATE_PAIR.json KHÔNG byte-identical với bản BE");

        const liveFe = resolveCandidate(FE_ROOT, feScope.length > 0 ? feScope : []);
        if (liveFe && liveFe.commit_sha !== feSha) {
          problems.push(`pin FE ${feSha} ≠ commit cuối chạm scope FE ${liveFe.commit_sha}`);
        }
      } else {
        warn("CANDIDATE-PAIR", `repo FE không với tới được (${FE_ROOT}) — chỉ kiểm được nửa BE.`);
      }

      // Supersede m1-be-2 → m1 phải là số đo, không phải câu khẳng định.
      const sup = pinned?.branch_supersession ?? {};
      if (sup.superseded_branch !== "m1-be-2" || sup.canonical_branch !== "m1") {
        problems.push("branch_supersession phải khai rõ m1-be-2 → m1");
      }
      if (sup.is_pure_ancestor !== true || sup.m1_be_2_commits_ahead_of_m1 !== 0) {
        problems.push("branch_supersession chưa chứng minh m1-be-2 là ancestor thuần (ahead = 0)");
      }

      // Cờ dòng lệnh để reviewer khoá đúng cặp mình đang review.
      if (EXPECT_BE_SHA && EXPECT_BE_SHA !== beSha) problems.push(`--expect-be-sha ${EXPECT_BE_SHA} ≠ pin ${beSha}`);
      if (EXPECT_FE_SHA && EXPECT_FE_SHA !== feSha) problems.push(`--expect-fe-sha ${EXPECT_FE_SHA} ≠ pin ${feSha}`);

      if (problems.length > 0) {
        fail("CANDIDATE-PAIR", `frozen pair không hợp lệ:\n    ${problems.join("\n    ")}`);
      } else {
        pass("CANDIDATE-PAIR", `một cặp duy nhất BE ${beSha.slice(0, 8)} / FE ${feSha.slice(0, 8)}, giải lại khớp, m1-be-2 superseded`);
      }
      lock.checks.candidate_pair = { backend: beSha, frontend: feSha, record_sha256: sha256(rel) };
    }
  }
}

// ── Check 10: authority của approval — DEV237-P1-01R2 ────────────────────────
/**
 * `DISP-STK-237` là bản ghi DEV-authored. Register R2 nói thẳng: **không dùng nó thay
 * approval**. Check này fail-closed đúng chỗ đó — không ai flip được sang APPROVED nếu
 * thiếu approver thật, thiếu tham chiếu comment Jira, thiếu effective date, hoặc nếu
 * người duyệt lại chính là vai trò DEV.
 */
{
  const rel = "docs/evidence/WWHN-237/APPROVAL_RECORD.json";
  const raw = readIfExists(rel);
  if (raw === null) {
    fail("APPROVAL-AUTHORITY", `${rel} không tồn tại — DEV237-P1-01R2 bắt buộc có bản ghi approval tách khỏi disposition.`);
  } else {
    const rec = JSON.parse(raw);
    const problems = [];
    const STATES = ["PENDING", "APPROVED", "REJECTED"];
    if (!STATES.includes(rec.approval_state)) problems.push(`approval_state phải thuộc ${STATES.join("/")}`);

    const slots = Array.isArray(rec.required_approvals) ? rec.required_approvals : [];
    if (slots.length < 3) problems.push("phải có đủ 3 slot: Process Owner/Client, PM/PO, BA Main");
    const forbidden = (rec.self_approval_forbidden_roles ?? []).map((r) => r.toLowerCase());

    for (const slot of slots) {
      if (slot.status === "APPROVED" || slot.status === "REJECTED") {
        const missing = (rec.required_fields_when_approved ?? []).filter((f) => !slot[f]);
        if (missing.length > 0) problems.push(`slot ${slot.slot} khai ${slot.status} nhưng thiếu: ${missing.join(", ")}`);
        const role = String(slot.approver_role ?? "").toLowerCase();
        if (role && forbidden.some((f) => role.includes(f))) {
          problems.push(`slot ${slot.slot} bị duyệt bởi vai trò DEV (${slot.approver_role}) — self-approval không hợp lệ`);
        }
        if (slot.approver && rec.authored_by && String(slot.approver).trim() === String(rec.authored_by).trim()) {
          problems.push(`slot ${slot.slot}: approver trùng authored_by (${rec.authored_by}) — self-approval không hợp lệ`);
        }
      }
    }

    const allApproved = slots.length > 0 && slots.every((s) => s.status === "APPROVED");
    if (rec.approval_state === "APPROVED") {
      if (!allApproved) problems.push("approval_state = APPROVED nhưng còn slot chưa APPROVED");
      if (!rec.effective_date) problems.push("approval_state = APPROVED nhưng effective_date rỗng");
      if (!rec.effective_disposition) problems.push("approval_state = APPROVED nhưng effective_disposition rỗng");
    } else if (rec.effective_date || rec.effective_disposition) {
      problems.push("chưa APPROVED mà đã điền effective_date/effective_disposition");
    }

    // ATT-11898 là input authority bắt buộc phải được nêu tên trong bản ghi.
    if (!JSON.stringify(rec).includes("ATT-11898")) problems.push("không nêu WWHN-132 ATT-11898 trong source_authority_inputs");

    // Disposition DEV-authored không được tự tuyên bố APPROVED khi approval còn PENDING.
    const disp = readIfExists("docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md") ?? "";
    const selfApproved = /\|\s*Status\s*\|\s*\*\*?APPROVED/i.test(disp);
    if (selfApproved && rec.approval_state !== "APPROVED") {
      problems.push("DISP-STK-237 tự khai Status APPROVED trong khi APPROVAL_RECORD còn PENDING");
    }

    if (problems.length > 0) {
      fail("APPROVAL-AUTHORITY", `bản ghi approval không hợp lệ:\n    ${problems.join("\n    ")}`);
    } else {
      pass(
        "APPROVAL-AUTHORITY",
        `approval_state=${rec.approval_state}, ${slots.length} slot approver-authored, không self-approval, ATT-11898 được nêu`,
      );
    }
    lock.checks.approval_state = rec.approval_state;
    lock.checks.approval_record_sha256 = sha256(rel);
  }
}

// ── Check 11: ma trận QA N1..N8 + R1..R4 — DEV237-P1-04R2 ────────────────────
/**
 * Anti-dangling theo đúng luật của `DISP-STK-233`: một dòng khai PASS mà không có raw
 * log tồn tại là dòng chép tay. Check này từ chối nó.
 */
{
  const rel = "docs/evidence/WWHN-237/QA_EXECUTION_MATRIX.json";
  const raw = readIfExists(rel);
  const REQUIRED_ROWS = [...Array(8)].map((_, i) => `N${i + 1}`).concat([...Array(4)].map((_, i) => `R${i + 1}`));
  if (raw === null) {
    fail("QA-MATRIX", `${rel} không tồn tại — chạy \`node scripts/run-wwhn237-qa-matrix.mjs\`.`);
  } else {
    const matrix = JSON.parse(raw);
    const rows = Array.isArray(matrix.rows) ? matrix.rows : [];
    const byId = new Map(rows.map((r) => [r.id, r]));
    const problems = [];

    const missingRows = REQUIRED_ROWS.filter((id) => !byId.has(id));
    if (missingRows.length > 0) problems.push(`thiếu dòng: ${missingRows.join(", ")}`);

    for (const row of rows) {
      if (!["PASS", "FAIL", "N/A"].includes(row.status)) problems.push(`${row.id}: status phải là PASS/FAIL/N/A`);
      if (!row.driver) problems.push(`${row.id}: thiếu driver/env`);
      if (row.status === "N/A" && !row.na_reason) problems.push(`${row.id}: N/A phải kèm na_reason`);
      if (row.status !== "N/A") {
        if (!row.raw_log) problems.push(`${row.id}: thiếu raw_log`);
        else if (!existsSync(abs(row.raw_log))) problems.push(`${row.id}: raw_log không tồn tại (${row.raw_log})`);
        else if (statSync(abs(row.raw_log)).size === 0) problems.push(`${row.id}: raw_log rỗng (${row.raw_log})`);
      }
    }

    const failed = rows.filter((r) => r.status === "FAIL");
    if (failed.length > 0) problems.push(`còn dòng FAIL: ${failed.map((r) => r.id).join(", ")}`);

    // Ma trận phải chạy trên đúng cặp đã pin, không phải một HEAD nào khác.
    const pinnedRaw = readIfExists(PAIR_RELATIVE_PATH);
    if (pinnedRaw) {
      const pinned = JSON.parse(pinnedRaw);
      if (matrix.executed_at_pair?.backend !== pinned.pair?.backend?.commit_sha) {
        problems.push("executed_at_pair.backend ≠ CANDIDATE_PAIR — ma trận chạy trên bản khác với bản đã pin");
      }
      if (matrix.executed_at_pair?.frontend !== pinned.pair?.frontend?.commit_sha) {
        problems.push("executed_at_pair.frontend ≠ CANDIDATE_PAIR");
      }
    }

    // Raw API/DB là yêu cầu tường minh của register R2, không phải tuỳ chọn.
    const rawEvidence = matrix.raw_api_db_evidence ?? [];
    if (rawEvidence.length === 0) problems.push("thiếu raw_api_db_evidence — register R2 đòi raw API/DB result");
    for (const item of rawEvidence) {
      if (!item.log || !existsSync(abs(item.log))) problems.push(`raw_api_db_evidence [${item.id}] thiếu log thật`);
    }

    if (problems.length > 0) {
      fail("QA-MATRIX", `ma trận QA không hợp lệ:\n    ${problems.join("\n    ")}`);
    } else {
      const na = rows.filter((r) => r.status === "N/A").length;
      pass("QA-MATRIX", `${rows.length} dòng N1..N8/R1..R4 có raw log thật (${na} N/A có lý do), chạy đúng cặp đã pin`);
    }
    lock.checks.qa_matrix_sha256 = sha256(rel);
  }
}

// ── Check 12: pin digest hợp đồng cùng version — DEV237-P1-05R2 ──────────────
/**
 * Ranh giới quan trọng: artifact **owned** (do WWHN-237 sở hữu) thì drift = FAIL.
 * Artifact **consumed** (Postman/OpenAPI/FE baseline — thuộc task khác, còn sống) thì
 * drift digest chỉ FAIL khi *bề mặt* đổi; bằng không chỉ NOTE. Hard-pin digest artifact
 * của task khác là đúng bẫy đã ghi tại `DISP-STK-236` — gate đỏ oan cho mọi PR khác.
 */
{
  const rel = "docs/evidence/WWHN-237/CONTRACT_DIGEST_PIN.json";
  const raw = readIfExists(rel);
  if (raw === null) {
    fail("CONTRACT-DIGEST-PIN", `${rel} không tồn tại — DEV237-P1-05R2 đòi pin cùng version cho OpenAPI/Postman/FE.`);
  } else {
    const pin = JSON.parse(raw);
    const problems = [];
    const notes = [];
    const artifacts = Array.isArray(pin.artifacts) ? pin.artifacts : [];
    if (artifacts.length === 0) problems.push("artifacts rỗng");

    for (const item of artifacts) {
      const root = item.repo === "FE" ? FE_ROOT : ROOT;
      const target = join(root, item.path);
      if (!existsSync(target)) {
        if (item.repo === "FE" && !existsSync(FE_ROOT)) {
          notes.push(`${item.path}: repo FE không với tới được, bỏ qua`);
          continue;
        }
        problems.push(`${item.path}: không tồn tại`);
        continue;
      }
      const current = createHash("sha256").update(readFileSync(target)).digest("hex");
      if (current === item.sha256) continue;

      if (item.pin_class === "owned" || item.pin_class === "immutable") {
        problems.push(
          `${item.path}: digest đổi (${item.sha256.slice(0, 12)} → ${current.slice(0, 12)}) trên artifact ${item.pin_class.toUpperCase()}`,
        );
      } else {
        // Bất biến bề mặt vẫn phải giữ dù bytes đổi.
        const body = readFileSync(target, "utf8").toLowerCase();
        const surfaceHits = (item.surface_invariant_forbidden ?? []).filter((f) => body.includes(f));
        if (surfaceHits.length > 0) {
          problems.push(`${item.path}: artifact CONSUMED đã đổi VÀ tái xuất hiện bề mặt cấm: ${surfaceHits.join(", ")}`);
        } else {
          notes.push(
            `${item.path}: digest drift ${item.sha256.slice(0, 12)} → ${current.slice(0, 12)} (artifact consumed, ` +
              "bất biến bề mặt còn nguyên) — consumer confirmation cần refresh trước khi đóng vòng sau",
          );
        }
      }
    }

    // Consumer confirmation phải độc lập: FE self-confirm của cùng DEV không tính.
    const confirmations = Array.isArray(pin.independent_confirmations) ? pin.independent_confirmations : [];
    const roles = new Set(confirmations.map((c) => c.role));
    for (const required of ["Tech Lead", "BA", "QA1"]) {
      if (!roles.has(required)) problems.push(`thiếu slot xác nhận độc lập cho vai trò: ${required}`);
    }
    for (const c of confirmations) {
      if (c.status === "CONFIRMED") {
        for (const field of ["confirmed_by", "jira_comment_ref", "confirmed_at", "pair_backend_sha", "pair_frontend_sha"]) {
          if (!c[field]) problems.push(`xác nhận của ${c.role} khai CONFIRMED nhưng thiếu ${field}`);
        }
        if (String(c.confirmed_by ?? "").toLowerCase().includes("dev")) {
          problems.push(`xác nhận của ${c.role} do chính DEV ký — không tính là independent`);
        }
      }
    }

    if (problems.length > 0) {
      fail("CONTRACT-DIGEST-PIN", `pin hợp đồng không hợp lệ:\n    ${problems.join("\n    ")}`);
    } else {
      pass("CONTRACT-DIGEST-PIN", `${artifacts.length} artifact pin cùng version, ${confirmations.length} slot xác nhận độc lập`);
    }
    for (const n of notes) warn("CONTRACT-DIGEST-PIN", n);
    lock.checks.contract_digest_pin_sha256 = sha256(rel);
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
