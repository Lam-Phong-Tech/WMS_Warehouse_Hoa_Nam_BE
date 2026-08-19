#!/usr/bin/env node
/**
 * WWHN-236 hard gate — DISP-STK-236 (Apply Adjustment / Stocktake DEFERRED).
 *
 * Bối cảnh: `apply-adjustment` đã được nêu lại ở WWHN-180, 219 và 236. Mỗi lần đều
 * đóng bằng văn bản, nên vòng rà soát sau lại mở ra một nguồn khác (DEC-STK-001,
 * WWHN-180 OpenAPI, BRD v3.9) và kết luận ngược lại. Script này biến quyết định
 * thành ràng buộc chạy được: chỉ REQ-SRS-037 là controlling, mọi nguồn còn lại là
 * historical, và bề mặt adjustment/stocktake phải bằng zero ở mọi tầng đang sống.
 *
 * Chạy độc lập, không boot Laravel/DB — phân tích nguồn nên dùng được cả trong CI
 * lẫn local trước khi commit.
 *
 *   node scripts/verify-wwhn236-adjustment-disposition.mjs
 *   node scripts/verify-wwhn236-adjustment-disposition.mjs --emit-lock
 *
 * `--emit-lock` ghi docs/evidence/WWHN-236/CONTRACT_NEGATIVE_LOCK.json (DEV236-P1-05).
 */
import { createHash } from "node:crypto";
import { readFileSync, existsSync, writeFileSync, mkdirSync, readdirSync, statSync } from "node:fs";
import { dirname, join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const EMIT_LOCK = process.argv.includes("--emit-lock");

const failures = [];
const checks = [];
const warnings = [];
const lock = { checks: {} };

const fail = (id, message) => failures.push(`[${id}] ${message}`);
const pass = (id, message) => checks.push(`[${id}] ${message}`);
const warn = (id, message) => warnings.push(`[${id}] ${message}`);

const readIfExists = (rel) => {
  const abs = join(ROOT, rel);
  return existsSync(abs) ? readFileSync(abs, "utf8") : null;
};

const sha256 = (rel) => {
  const abs = join(ROOT, rel);
  if (!existsSync(abs)) return null;
  return createHash("sha256").update(readFileSync(abs)).digest("hex");
};

const toPosix = (p) => p.replace(/\\/g, "/");

/**
 * Mảnh đường dẫn bị cấm. Trước DISP-STK-236 chỉ có "stocktake", nên một route sửa
 * tồn đặt tên khác (ví dụ /inventory/apply-adjustment) lọt qua toàn bộ gate.
 */
const FORBIDDEN_FRAGMENTS = ["stocktake", "adjustment", "variance"];

/** Bảng bị REQ-SRS-037 / ERD §0.1 loại khỏi MVP. */
const EXCLUDED_TABLES = [
  "stocktake_session",
  "stocktake_snapshot",
  "stocktake_count",
  "stocktake_variance",
];

/** movement_type hợp lệ duy nhất. ADJUST_IN/ADJUST_OUT phải vắng mặt. */
const CANONICAL_MOVEMENT_TYPES = ["OPENING", "RECEIPT", "ISSUE", "COMPONENT_ISSUE", "REVERSAL"];

/**
 * Artifact task-scoped đã được disposition hạ xuống HISTORICAL. Chúng được phép còn
 * dấu vết bề mặt cũ (WWHN-180 v1.7.0 khai apply-adjustment; WWHN-150 khai enum scan
 * context STOCKTAKE) vì một immutable handoff chỉ được superseded bằng decision record
 * trỏ tới nó, không bao giờ bằng cách sửa nội dung. Gate ghi chúng ra lock để reviewer
 * nhìn thấy, nhưng KHÔNG fail. Mọi spec NGOÀI danh sách này mà khai path bị cấm thì fail.
 */
const HISTORICAL_SPEC_ALLOWLIST = [
  "docs/api-contract/WWHN-180-posting-openapi.json",
  "docs/api-contract/WWHN-180-posting-openapi.yaml",
  "docs/api-contract/WWHN-150-scan-openapi.yaml",
];

/** Trích danh sách path từ một spec OpenAPI (JSON hoặc YAML phẳng). */
const extractPaths = (rel, raw) => {
  if (rel.endsWith(".json")) {
    try {
      return Object.keys(JSON.parse(raw).paths ?? {});
    } catch {
      return [];
    }
  }
  // YAML: path là các key thụt 2 space bắt đầu bằng '/'. Đủ cho spec sinh máy ở repo này.
  return (raw.match(/^ {2}(\/[^\s:]*):/gm) ?? []).map((l) => l.trim().replace(/:$/, ""));
};

// ── Check 1: disposition record tồn tại và neo đúng controlling source ────────
{
  const rel = "docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md";
  const doc = readIfExists(rel);
  if (doc === null) {
    fail("DISP-EXISTS", `${rel} không tồn tại — WWHN-236 bắt buộc có disposition bằng văn bản.`);
  } else {
    const required = [
      ["REQ-SRS-037", "phải neo controlling source vào REQ-SRS-037"],
      ["DEFERRED", "phải nêu rõ disposition DEFERRED"],
      ["WWHN-238", "phải nêu disposition của QA gate WWHN-238"],
      ["HN-STK-002", "phải nêu disposition dependency HN-STK-002"],
    ];
    const missing = required.filter(([needle]) => !doc.includes(needle));
    if (missing.length > 0) {
      fail("DISP-CONTENT", `${rel} thiếu: ${missing.map(([n, why]) => `${n} (${why})`).join("; ")}`);
    } else {
      pass("DISP-CONTENT", "DISP-STK-236 neo REQ-SRS-037 + DEFERRED + WWHN-238 + HN-STK-002");
    }
    lock.checks.disposition_sha256 = sha256(rel);
  }
}

// ── Check 2: DEC-STK-001 vẫn PROPOSED (không ai âm thầm nâng lên APPROVED) ────
{
  const rel = "docs/decision-records/DEC-STK-001.md";
  const doc = readIfExists(rel);
  if (doc === null) {
    fail("DEC-STK-001", `${rel} biến mất — bản nháp phải được giữ nguyên làm bản ghi lịch sử.`);
  } else if (!/\|\s*Status\s*\|\s*\*\*PROPOSED\*\*\s*\|/.test(doc)) {
    fail(
      "DEC-STK-001",
      `${rel} không còn ở trạng thái PROPOSED. Mô hình kiểm kê A chỉ được kích hoạt qua CR + WWHN-25 GO + rebaseline SRS (DISP-STK-236 §2).`,
    );
  } else {
    pass("DEC-STK-001", "DEC-STK-001 vẫn PROPOSED / no effective date");
  }
}

// ── Check 3: baseline contract — fragments, excluded tables, movement types ───
{
  const rel = "docs/contracts/HoaNam_Current_Baseline_Contract.json";
  const raw = readIfExists(rel);
  if (raw === null) {
    fail("BASELINE", `${rel} không tồn tại.`);
  } else {
    const contract = JSON.parse(raw);
    const fragments = contract?.api?.forbidden_path_fragments ?? [];
    const missingFragments = FORBIDDEN_FRAGMENTS.filter((f) => !fragments.includes(f));
    if (missingFragments.length > 0) {
      fail(
        "BASELINE-FRAGMENTS",
        `forbidden_path_fragments thiếu [${missingFragments.join(", ")}]. Thiếu mảnh nào thì route sửa tồn mang tên đó lọt gate.`,
      );
    } else {
      pass("BASELINE-FRAGMENTS", `forbidden_path_fragments phủ [${FORBIDDEN_FRAGMENTS.join(", ")}]`);
    }

    const excluded = contract?.excluded_tables ?? [];
    const missingTables = EXCLUDED_TABLES.filter((t) => !excluded.includes(t));
    if (missingTables.length > 0) {
      fail("BASELINE-TABLES", `excluded_tables thiếu [${missingTables.join(", ")}].`);
    } else {
      pass("BASELINE-TABLES", "excluded_tables phủ đủ 4 bảng stocktake_*");
    }

    const movementTypes = contract?.movement_types ?? [];
    const rogue = movementTypes.filter((t) => /ADJUST/i.test(t));
    if (rogue.length > 0) {
      fail(
        "BASELINE-MOVEMENT",
        `movement_types chứa [${rogue.join(", ")}] — REQ-SRS-037 cấm sửa tồn trực tiếp; correction path là REVERSAL.`,
      );
    } else if (CANONICAL_MOVEMENT_TYPES.some((t) => !movementTypes.includes(t))) {
      fail("BASELINE-MOVEMENT", `movement_types lệch canonical set [${CANONICAL_MOVEMENT_TYPES.join(", ")}].`);
    } else {
      pass("BASELINE-MOVEMENT", "movement_types = canonical set, không có ADJUST_*");
    }

    lock.checks.baseline_contract_sha256 = sha256(rel);
    lock.checks.forbidden_path_fragments = fragments;
    lock.checks.movement_types = movementTypes;
  }
}

// ── Check 4: runtime OpenAPI — zero path adjustment/stocktake/variance ────────
{
  const specs = ["storage/api-docs/api-docs.yaml", "storage/api-docs/api-docs.json"];
  const offending = [];
  const inspected = [];

  for (const rel of specs) {
    const raw = readIfExists(rel);
    if (raw === null) continue;
    inspected.push(rel);

    // Chỉ soi PATH, không soi description: câu "MVP has no Stocktake scope" là
    // mô tả phủ định hợp lệ và không được tính là vi phạm.
    const paths = extractPaths(rel, raw);
    for (const p of paths) {
      const lower = p.toLowerCase();
      const hit = FORBIDDEN_FRAGMENTS.find((f) => lower.includes(f));
      if (hit) offending.push(`${rel} → ${p} (fragment: ${hit})`);
    }
    const key = rel.replace(/[^a-z0-9]+/gi, "_");
    lock.checks[`${key}_path_count`] = paths.length;
    lock.checks[`${key}_sha256`] = sha256(rel);
  }

  if (inspected.length === 0) {
    fail(
      "OPENAPI-RUNTIME",
      "Không tìm thấy storage/api-docs/api-docs.{yaml,json} — chạy `php artisan l5-swagger:generate` trước khi verify.",
    );
  } else if (offending.length > 0) {
    fail("OPENAPI-RUNTIME", `Runtime OpenAPI expose bề mặt bị cấm:\n    ${offending.join("\n    ")}`);
  } else {
    pass("OPENAPI-RUNTIME", `${inspected.join(" + ")}: zero path adjustment/stocktake/variance`);
  }
}

// ── Check 5: permission matrix không khai permission stocktake/adjust ─────────
{
  const rel = "docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.json";
  const raw = readIfExists(rel);
  if (raw === null) {
    fail("PERMISSION-MATRIX", `${rel} không tồn tại.`);
  } else if (/stocktake|apply-adjustment|variance/i.test(raw)) {
    const hits = [...raw.matchAll(/[^"]*(?:stocktake|apply-adjustment|variance)[^"]*/gi)]
      .slice(0, 5)
      .map((m) => m[0]);
    fail("PERMISSION-MATRIX", `${rel} còn khai bề mặt bị cấm: ${hits.join(", ")}`);
  } else {
    pass("PERMISSION-MATRIX", "endpoint-permission matrix: zero stocktake/adjust/variance");
    lock.checks.permission_matrix_sha256 = sha256(rel);
  }
}

// ── Check 6: route source — không route nào khai mảnh bị cấm ──────────────────
{
  const routeFiles = [];
  const collect = (dir) => {
    const abs = join(ROOT, dir);
    if (!existsSync(abs)) return;
    for (const entry of readdirSync(abs)) {
      const full = join(abs, entry);
      if (statSync(full).isDirectory()) collect(toPosix(relative(ROOT, full)));
      else if (entry.endsWith(".php")) routeFiles.push(toPosix(relative(ROOT, full)));
    }
  };
  collect("routes");
  const modulesDir = join(ROOT, "Modules");
  for (const mod of existsSync(modulesDir) ? readdirSync(modulesDir) : []) {
    collect(`Modules/${mod}/routes`);
  }

  const offending = [];
  for (const rel of routeFiles) {
    const src = readFileSync(join(ROOT, rel), "utf8");
    // Chỉ soi chuỗi URI trong lời gọi route, nên comment giải thích không bị tính.
    const uris = [
      ...src.matchAll(
        /Route::(?:get|post|put|patch|delete|any|match)\s*\(\s*(?:\[[^\]]*\]\s*,\s*)?['"]([^'"]+)['"]/g,
      ),
    ].map((m) => m[1].toLowerCase());
    for (const uri of uris) {
      const hit = FORBIDDEN_FRAGMENTS.find((f) => uri.includes(f));
      if (hit) offending.push(`${rel} → ${uri} (fragment: ${hit})`);
    }
  }

  if (offending.length > 0) {
    fail("ROUTE-SOURCE", `Route khai bề mặt bị cấm:\n    ${offending.join("\n    ")}`);
  } else {
    pass("ROUTE-SOURCE", `${routeFiles.length} route file: zero URI adjustment/stocktake/variance`);
    lock.checks.route_files_scanned = routeFiles.length;
  }
}

// ── Check 7: migration — trạng thái schema cuối cùng phải zero ────────────────
{
  const migrationDirs = ["database/migrations"];
  const modulesDir = join(ROOT, "Modules");
  for (const mod of existsSync(modulesDir) ? readdirSync(modulesDir) : []) {
    migrationDirs.push(`Modules/${mod}/database/migrations`);
  }

  const created = [];
  const dropped = [];
  let constraintFound = false;

  for (const dir of migrationDirs) {
    const abs = join(ROOT, dir);
    if (!existsSync(abs)) continue;
    for (const entry of readdirSync(abs).filter((f) => f.endsWith(".php"))) {
      const rel = `${dir}/${entry}`;
      const src = readFileSync(join(abs, entry), "utf8");

      for (const table of EXCLUDED_TABLES) {
        // Migration lịch sử đã chạy production KHÔNG được sửa lại — sửa migration đã
        // apply là đường ngắn nhất tới brick DB khi rollback. Vì vậy gate không cấm
        // Schema::create trong quá khứ; nó đòi trạng thái schema CUỐI CÙNG bằng zero,
        // tức mỗi create phải có drop ở một migration timestamp lớn hơn.
        if (new RegExp(`Schema::create\\s*\\(\\s*['"]${table}['"]`).test(src)) {
          created.push({ table, file: rel, stamp: entry });
        }
        if (
          new RegExp(`['"]${table}['"]`).test(src) &&
          /drop(?:IfExists)?\s*\(|DROP TABLE/i.test(src)
        ) {
          dropped.push({ table, file: rel, stamp: entry });
        }
      }

      if (src.includes("chk_stock_movement_type_current")) {
        constraintFound = true;
        const declared = src.match(
          /chk_stock_movement_type_current CHECK \(movement_type IN \(([^)]+)\)\)/,
        );
        if (declared) {
          const listed = declared[1].split(",").map((s) => s.trim().replace(/'/g, ""));
          const rogue = listed.filter((t) => /ADJUST/i.test(t));
          const missing = CANONICAL_MOVEMENT_TYPES.filter((t) => !listed.includes(t));
          if (rogue.length > 0) {
            fail(
              "DB-CONSTRAINT",
              `${rel}: chk_stock_movement_type_current cho phép [${rogue.join(", ")}] — mở lại đường sửa tồn trực tiếp.`,
            );
          } else if (missing.length > 0) {
            fail("DB-CONSTRAINT", `${rel}: chk_stock_movement_type_current thiếu [${missing.join(", ")}].`);
          } else {
            pass("DB-CONSTRAINT", `chk_stock_movement_type_current = [${listed.join(", ")}], zero ADJUST_*`);
            lock.checks.movement_type_check_constraint = listed;
          }
        }
      }
    }
  }

  const orphanCreates = created.filter(
    (c) => !dropped.some((d) => d.table === c.table && d.stamp > c.stamp),
  );
  if (orphanCreates.length > 0) {
    fail(
      "MIGRATION-TABLES",
      "Bảng đã loại bỏ được tạo mà không có migration drop nào sau đó:\n    " +
        orphanCreates.map((c) => `${c.file} → ${c.table}`).join("\n    "),
    );
  } else {
    const tables = [...new Set(created.map((c) => c.table))];
    pass(
      "MIGRATION-TABLES",
      tables.length > 0
        ? `${tables.length} bảng stocktake_* do migration lịch sử tạo đều bị drop ở migration sau (trạng thái cuối = zero)`
        : "Không migration nào tạo bảng stocktake_*",
    );
    lock.checks.stocktake_tables_created_then_dropped = tables;
  }

  if (!constraintFound) {
    fail(
      "DB-CONSTRAINT",
      "Không tìm thấy chk_stock_movement_type_current trong migration — hàng rào chặn ADJUST_IN/ADJUST_OUT ở tầng DB đã biến mất.",
    );
  }
}

// ── Check 8: spec contract — chỉ artifact historical mới được còn dấu vết ─────
{
  const specDirs = ["docs/api-contract"];
  const specFiles = [];
  const walk = (dir) => {
    const abs = join(ROOT, dir);
    if (!existsSync(abs)) return;
    for (const entry of readdirSync(abs)) {
      const full = join(abs, entry);
      if (statSync(full).isDirectory()) walk(toPosix(relative(ROOT, full)));
      else if (/\.(json|yaml|yml)$/i.test(entry)) specFiles.push(toPosix(relative(ROOT, full)));
    }
  };
  for (const d of specDirs) walk(d);

  const liveOffenders = [];
  const historicalDrift = [];

  for (const rel of specFiles) {
    const raw = readFileSync(join(ROOT, rel), "utf8");
    const hits = extractPaths(rel, raw).filter((p) =>
      FORBIDDEN_FRAGMENTS.some((f) => p.toLowerCase().includes(f)),
    );
    // Enum scan-context STOCKTAKE là drift đã ghi nhận ở WWHN-181; tính là historical.
    const enumDrift = /enum:\s*\[[^\]]*STOCKTAKE[^\]]*\]|"STOCKTAKE"/.test(raw);
    if (hits.length === 0 && !enumDrift) continue;

    const detail = `${rel} → ${[...hits, enumDrift ? "enum:STOCKTAKE" : null].filter(Boolean).join(", ")}`;
    if (HISTORICAL_SPEC_ALLOWLIST.includes(rel)) historicalDrift.push(detail);
    else liveOffenders.push(detail);
  }

  if (liveOffenders.length > 0) {
    fail(
      "SPEC-SURFACE",
      `Spec contract ngoài danh sách historical còn khai bề mặt bị cấm:\n    ${liveOffenders.join("\n    ")}\n` +
        "    Muốn giữ, phải thêm vào HISTORICAL_SPEC_ALLOWLIST kèm disposition tường minh — không sửa im lặng.",
    );
  } else {
    pass(
      "SPEC-SURFACE",
      `${specFiles.length} spec contract: zero bề mặt bị cấm ngoài ${historicalDrift.length} artifact historical đã có disposition`,
    );
  }

  for (const d of historicalDrift) {
    warn("SPEC-HISTORICAL", `${d} — historical, superseded bởi DISP-STK-236 §3; không sửa nội dung.`);
  }
  lock.checks.historical_spec_drift = historicalDrift;
  lock.checks.historical_spec_allowlist = Object.fromEntries(
    HISTORICAL_SPEC_ALLOWLIST.map((rel) => [rel, sha256(rel)]),
  );
}

// ═══════════════════════════════════════════════════════════════════════════
// R3 — check 9..13: governance của chính đợt đóng (không phải bề mặt code).
// Vòng R2 trượt vì bề mặt code sạch nhưng disposition chưa có approver, chuỗi
// ticket chưa đồng bộ, và register pin một SHA của working tree bẩn. Bốn check
// dưới đây biến đúng bốn thiếu sót đó thành ràng buộc chạy được.
// ═══════════════════════════════════════════════════════════════════════════
const { execFileSync: _exec } = await import("node:child_process");
const REQUIRE_SIGNOFF = process.argv.includes("--require-signoff");
const FE_ROOT_R3 = resolve(ROOT, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE");

const gitAt = (root, args) => {
  try {
    return _exec("git", args, { cwd: root, encoding: "utf8" }).trim();
  } catch {
    return null;
  }
};
const gitOk = (root, args) => {
  try {
    _exec("git", args, { cwd: root, stdio: "ignore" });
    return true;
  } catch {
    return false;
  }
};

const readJsonIfExists = (root, rel) => {
  const abs = join(root, rel);
  if (!existsSync(abs)) return null;
  try {
    return JSON.parse(readFileSync(abs, "utf8"));
  } catch (e) {
    return { __parseError: String(e) };
  }
};

// ── Check 9: approval record — decision / version / hash / effective date ─────
{
  const rel = "docs/decision-records/DISP-STK-236-APPROVAL.json";
  const approval = readJsonIfExists(ROOT, rel);
  const dispRel = "docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md";
  const dispDoc = readIfExists(dispRel);

  if (approval === null) {
    fail(
      "APPROVAL-RECORD",
      `${rel} không tồn tại. DEV236-P1-01R3 đòi bản ghi phê duyệt có Decision/version/hash/effective date; ` +
        "một disposition không có nơi ghi chữ ký thì vòng rà soát sau lại hỏi 'ai duyệt?'.",
    );
  } else if (approval.__parseError) {
    fail("APPROVAL-RECORD", `${rel} không parse được: ${approval.__parseError}`);
  } else {
    const problems = [];
    if (approval.decision !== "DEFERRED_NO_CODE") problems.push(`decision=[${approval.decision}] != DEFERRED_NO_CODE`);
    if (approval.controllingRequirement !== "REQ-SRS-037")
      problems.push(`controllingRequirement=[${approval.controllingRequirement}] != REQ-SRS-037`);
    if (approval.controllingBaseline?.version !== "1.12")
      problems.push(`controllingBaseline.version=[${approval.controllingBaseline?.version}] != 1.12`);
    if (approval.controllingBaseline?.adoptionIssue !== "WWHN-334")
      problems.push(`adoptionIssue=[${approval.controllingBaseline?.adoptionIssue}] != WWHN-334`);

    // Hash của disposition phải đo lại được, không chép tay.
    const actual = sha256(dispRel);
    if (!approval.dispositionSha256) {
      problems.push("dispositionSha256=null — chạy `node scripts/build-wwhn236-source-register.mjs`");
    } else if (approval.dispositionSha256 !== actual) {
      problems.push(
        `dispositionSha256 lệch nội dung hiện tại (${approval.dispositionSha256.slice(0, 12)}… vs ${String(actual).slice(0, 12)}…) — chạy lại builder`,
      );
    }

    // Version trong approval phải khớp version ghi ở document control của disposition.
    const declared = dispDoc?.match(/\|\s*Version\s*\|\s*`([^`]+)`\s*\|/)?.[1] ?? null;
    if (declared && approval.dispositionVersion !== declared)
      problems.push(`dispositionVersion=[${approval.dispositionVersion}] != version của disposition [${declared}]`);

    // Limitation mà PM/PO phải chấp nhận tường minh — không được rút gọn thành một dòng chung chung.
    const lim = approval.limitationAccepted;
    if (!lim || lim.id !== "LIM-STK-236-01") problems.push("thiếu limitationAccepted.id = LIM-STK-236-01");
    else {
      const text = JSON.stringify(lim);
      for (const needle of ["reconciliation_incidents", "Reversal", "source transaction", "incident"]) {
        if (!text.includes(needle)) problems.push(`limitationAccepted không nhắc [${needle}]`);
      }
      if (!Array.isArray(lim.whatTheSystemCannotDo) || lim.whatTheSystemCannotDo.length === 0)
        problems.push("limitationAccepted.whatTheSystemCannotDo rỗng — chấp nhận giới hạn mà không liệt kê giới hạn");
    }

    // Không cho tự tuyên bố APPROVED.
    const roles = Object.entries(approval.signOff ?? {});
    const undecided = roles.filter(([, s]) => !s.decision).map(([k]) => k);
    if (approval.status === "APPROVED") {
      if (!approval.effectiveDate) problems.push("status=APPROVED nhưng effectiveDate=null");
      if (undecided.length > 0) problems.push(`status=APPROVED nhưng còn ${undecided.length} vai trò chưa ký: ${undecided.join(", ")}`);
      if (!approval.exactCommitSha?.backend) problems.push("status=APPROVED nhưng chưa pin exact BE SHA");
    }

    if (problems.length > 0) {
      fail("APPROVAL-RECORD", `${rel}:\n    ${problems.join("\n    ")}`);
    } else {
      pass(
        "APPROVAL-RECORD",
        `approval record hợp lệ: ${approval.decision} · disposition v${approval.dispositionVersion} · hash khớp · LIM-STK-236-01 tường minh`,
      );
    }

    if (REQUIRE_SIGNOFF) {
      const blockers = [];
      if (undecided.length > 0) blockers.push(`chưa ký: ${undecided.join(", ")}`);
      if (!approval.effectiveDate) blockers.push("effectiveDate=null");
      if (approval.status !== "APPROVED") blockers.push(`status=${approval.status}`);
      if (!approval.exactCommitSha?.backend) blockers.push("exactCommitSha.backend=null");
      if (!approval.exactCommitSha?.frontend) blockers.push("exactCommitSha.frontend=null");
      if (lim && lim.acceptanceRecorded !== true) blockers.push("PM/PO chưa ghi nhận chấp nhận LIM-STK-236-01");
      if (blockers.length > 0) fail("APPROVAL-SIGNOFF", `--require-signoff: ${blockers.join("; ")}`);
      else pass("APPROVAL-SIGNOFF", `đủ ${roles.length} chữ ký · effectiveDate=${approval.effectiveDate}`);
    } else if (undecided.length > 0) {
      warn(
        "APPROVAL-SIGNOFF",
        `còn ${undecided.length}/${roles.length} vai trò chưa ký (${undecided.join(", ")}). ` +
          "Guard vẫn active bất kể trạng thái phê duyệt; chạy với --require-signoff để biến việc này thành gate cứng.",
      );
    }
    lock.checks.approval_status = approval.status;
    lock.checks.approval_effective_date = approval.effectiveDate;
  }
}

// ── Check 10: chuỗi ticket — cùng một disposition, không ticket nào Blocked ───
{
  const rel = "docs/evidence/WWHN-236/CHAIN_DISPOSITION.json";
  const chain = readJsonIfExists(ROOT, rel);
  const dispDoc = readIfExists("docs/decision-records/DISP-STK-236-APPLY-ADJUSTMENT-DEFERRED.md");

  if (chain === null || chain.__parseError) {
    fail(
      "CHAIN-DISPOSITION",
      `${rel} không đọc được. DEV236-P1-03R3 đòi cùng MỘT disposition hình thức trên cả chuỗi ticket, ` +
        "và không để QA task Blocked vô thời hạn.",
    );
  } else {
    const problems = [];
    const listed = new Map((chain.tickets ?? []).map((t) => [t.ticket, t]));

    // Mọi ticket mà disposition nhắc tới ở §4 phải có disposition tường minh ở đây.
    const mentioned = new Set(
      [...(dispDoc ?? "").matchAll(/`(WWHN-\d+|HN-STK-\d+)`/g)].map((m) => m[1]),
    );
    // WWHN-25/26/48… chỉ được nhắc như tham chiếu; chỉ đòi đúng chuỗi bị DEFERRED.
    const REQUIRED_TICKETS = ["WWHN-25", "WWHN-60", "WWHN-236", "WWHN-238", "HN-STK-002", "WWHN-233", "WWHN-235"];
    for (const t of REQUIRED_TICKETS) {
      if (!listed.has(t)) problems.push(`thiếu ticket ${t}`);
    }

    const forbidden = chain.forbiddenStatuses ?? ["Blocked", "Pending"];
    for (const [ticket, t] of listed) {
      const status = String(t.targetStatus ?? "");
      const hit = forbidden.find((f) => status.toLowerCase().includes(f.toLowerCase()));
      if (hit) problems.push(`${ticket}: targetStatus [${status}] rơi vào trạng thái bị cấm [${hit}]`);
      if (!/DEFERRED/i.test(String(t.disposition ?? ""))) problems.push(`${ticket}: disposition [${t.disposition}] không phải DEFERRED*`);
      // Anti-dangling: disposition owner phải là file có thật.
      if (t.ownerDoc && !existsSync(join(ROOT, t.ownerDoc))) {
        if (t.crossBranch) warn("CHAIN-DISPOSITION", `${ticket}: ownerDoc ${t.ownerDoc} (nhánh khác) chưa có mặt trên checkout này.`);
        else problems.push(`${ticket}: ownerDoc ${t.ownerDoc} không tồn tại`);
      }
    }

    for (const qa of chain.qaTasksMustNotBeBlocked ?? []) {
      const t = listed.get(qa);
      if (!t) problems.push(`QA task ${qa} không có trong danh sách ticket`);
      else if (!/N\/A|DEFERRED/i.test(String(t.targetStatus)))
        problems.push(`QA task ${qa}: targetStatus [${t.targetStatus}] không phải DEFERRED / N/A`);
    }

    if (problems.length > 0) {
      fail("CHAIN-DISPOSITION", `${rel}:\n    ${problems.join("\n    ")}`);
    } else {
      const cross = [...listed.values()].filter((t) => t.crossBranch).length;
      pass(
        "CHAIN-DISPOSITION",
        `${listed.size} ticket cùng disposition DEFERRED (${cross} thuộc nhánh song song, chỉ đọc); zero ticket Blocked/Pending`,
      );
      const unmentioned = [...mentioned].filter((t) => /^(WWHN-(59|60|233|234|235|236|238)|HN-STK-002)$/.test(t) && !listed.has(t));
      for (const t of unmentioned) warn("CHAIN-DISPOSITION", `${t} được nhắc trong disposition nhưng chưa có dòng riêng ở chain.`);
    }
    lock.checks.chain_tickets = (chain.tickets ?? []).map((t) => `${t.ticket}=${t.disposition}`);
  }
}

// ── Check 11: candidate pin — SHA sạch, bất biến, đúng tập file ───────────────
{
  const rel = "docs/evidence/WWHN-236/SOURCE_REGISTER.json";
  const register = readJsonIfExists(ROOT, rel);

  if (register === null || register.__parseError) {
    fail("CANDIDATE-PIN", `${rel} không đọc được — chạy \`node scripts/build-wwhn236-source-register.mjs\`.`);
  } else {
    const problems = [];
    let checkedFiles = 0;

    for (const repo of register.candidate_identity ?? []) {
      const root = repo.repo === "BE" ? ROOT : FE_ROOT_R3;
      const label = repo.repo;

      if (!existsSync(root)) {
        warn("CANDIDATE-PIN", `${label}: repo không có mặt ở checkout này — bỏ qua đối chiếu.`);
        continue;
      }
      if (!repo.candidate_sha) {
        problems.push(`${label}: candidate_sha=null (register sinh khi artifact còn chưa commit)`);
        continue;
      }
      if (repo.governed_working_tree !== "CLEAN") {
        problems.push(
          `${label}: governed_working_tree=${repo.governed_working_tree} — [${(repo.governed_dirty_files ?? []).join(", ")}] chưa commit`,
        );
      }
      if (repo.pull_request !== "N/A — direct-to-m1") {
        problems.push(`${label}: pull_request=[${repo.pull_request}] — đi thẳng m1 thì phải ghi N/A tường minh`);
      }
      if (!gitOk(root, ["merge-base", "--is-ancestor", repo.candidate_sha, "HEAD"])) {
        problems.push(`${label}: candidate ${repo.candidate_sha.slice(0, 7)} không phải ancestor của HEAD (pin mục hoặc sai nhánh)`);
        continue;
      }

      for (const f of repo.governed_files ?? []) {
        if (!f.present) {
          problems.push(`${label}: governed file thiếu — ${f.path}`);
          continue;
        }
        if (f.pin_carrier || f.shared || !f.tracked) continue;
        const nowBlob = gitAt(root, ["rev-parse", `HEAD:${f.path}`]);
        if (nowBlob !== f.blob_sha) {
          problems.push(`${label}: ${f.path} đổi blob sau khi pin (${String(f.blob_sha).slice(0, 7)} → ${String(nowBlob).slice(0, 7)})`);
        }
        const diskBlob = gitAt(root, ["hash-object", f.path]);
        if (diskBlob !== nowBlob) {
          problems.push(`${label}: ${f.path} trên đĩa khác bản đã commit — evidence không tái lập được`);
        }
        checkedFiles += 1;
      }
    }

    if (problems.length > 0) {
      fail(
        "CANDIDATE-PIN",
        `${rel}:\n    ${problems.join("\n    ")}\n` +
          "    Pin phải trỏ tới commit CHỨA guard/evidence và tập file phải bất biến kể từ đó.",
      );
    } else {
      const be = (register.candidate_identity ?? []).find((r) => r.repo === "BE");
      const fe = (register.candidate_identity ?? []).find((r) => r.repo === "FE");
      pass(
        "CANDIDATE-PIN",
        `candidate BE ${be?.candidate_sha?.slice(0, 7)} / FE ${fe?.candidate_sha?.slice(0, 7)} · ${checkedFiles} governed file khớp blob · PR=N/A direct-to-m1`,
      );
      lock.checks.candidate_sha_be = be?.candidate_sha ?? null;
      lock.checks.candidate_sha_fe = fe?.candidate_sha ?? null;
    }
  }
}

// ── Check 12: rebaseline AC của parent WWHN-60 ────────────────────────────────
{
  const rel = "docs/evidence/WWHN-236/SOURCE_REGISTER.json";
  const register = readJsonIfExists(ROOT, rel);
  const rb = register?.parent_rebaseline;

  if (!rb) {
    fail(
      "PARENT-REBASELINE",
      `${rel} thiếu parent_rebaseline. DEV236-P1-02R3 đòi ghi rõ current source/version thay cho BRD v3.9 ` +
        "và liệt AC nào bị gỡ khỏi MVP hiện tại.",
    );
  } else {
    const problems = [];
    if (rb.ticket !== "WWHN-60") problems.push(`ticket=[${rb.ticket}] != WWHN-60`);
    if (rb.current_source?.version !== "1.12") problems.push(`current_source.version=[${rb.current_source?.version}] != 1.12`);
    if (rb.current_source?.requirement !== "REQ-SRS-037")
      problems.push(`current_source.requirement=[${rb.current_source?.requirement}] != REQ-SRS-037`);
    if (rb.current_source?.adoption_issue !== "WWHN-334") problems.push("current_source.adoption_issue != WWHN-334");
    if (!/v3\.9/.test(String(rb.superseded_source?.label))) problems.push("superseded_source không nêu BRD/Handoff v3.9");
    if (!/HISTORICAL/i.test(String(rb.superseded_source?.status))) problems.push("superseded_source.status không đánh dấu HISTORICAL");
    if (!Array.isArray(rb.ac_to_remove_from_current_mvp) || rb.ac_to_remove_from_current_mvp.length === 0)
      problems.push("ac_to_remove_from_current_mvp rỗng");
    if (!rb.ac_replacement) problems.push("thiếu ac_replacement — gỡ AC mà không nói AC mới là gì");

    // Nguồn hiện hành phải thật sự tồn tại và thật sự chứa requirement được viện dẫn.
    const srsAbs = join(FE_ROOT_R3, rb.current_source?.path ?? "");
    if (!existsSync(srsAbs)) {
      warn("PARENT-REBASELINE", `Không đọc được ${rb.current_source?.path} ở repo FE — bỏ qua đối chiếu nội dung.`);
    } else if (!readFileSync(srsAbs, "utf8").includes("REQ-SRS-037")) {
      problems.push(`${rb.current_source?.path} không chứa REQ-SRS-037 — nguồn viện dẫn sai`);
    }

    if (problems.length > 0) fail("PARENT-REBASELINE", `${rel}.parent_rebaseline:\n    ${problems.join("\n    ")}`);
    else
      pass(
        "PARENT-REBASELINE",
        `WWHN-60 rebaseline sang SRS v1.12/REQ-SRS-037 (WWHN-334); ${rb.ac_to_remove_from_current_mvp.length} nhóm AC bị gỡ; v3.9 = historical`,
      );
  }
}

// ── Check 13: QA rerun tại exact SHA có thật (DEV236-P1-07R3) ─────────────────
{
  const runner = "scripts/qa-wwhn236-exact-sha-gate.mjs";
  const qaDoc = readIfExists("docs/evidence/WWHN-236/QA_DISPOSITION_WWHN-238.md");
  const problems = [];

  if (!existsSync(join(ROOT, runner))) problems.push(`${runner} không tồn tại — QA không có lệnh chuẩn để chạy lại`);
  if (qaDoc === null) problems.push("QA_DISPOSITION_WWHN-238.md không tồn tại");
  else {
    if (!qaDoc.includes(runner)) problems.push(`QA_DISPOSITION_WWHN-238.md không chỉ tới ${runner}`);
    if (!/exact SHA/i.test(qaDoc)) problems.push("QA_DISPOSITION_WWHN-238.md không nêu yêu cầu chạy tại exact SHA");
    if (!/PASS \/ FAIL \/ DEFERRED/i.test(qaDoc) && !/PASS\/FAIL\/DEFERRED/i.test(qaDoc))
      problems.push("QA_DISPOSITION_WWHN-238.md không nêu QA1 phải ghi PASS / FAIL / DEFERRED-N/A");
  }

  const logDir = join(ROOT, "docs/evidence/WWHN-236/logs");
  const rawLogs = existsSync(logDir) ? readdirSync(logDir).filter((f) => /^qa-rerun-.*\.log$/.test(f)) : [];
  if (rawLogs.length === 0) problems.push("chưa có raw log docs/evidence/WWHN-236/logs/qa-rerun-*.log");

  if (problems.length > 0) fail("QA-RERUN", problems.join("\n    "));
  else pass("QA-RERUN", `${runner} + ${rawLogs.length} raw log qa-rerun-*; QA1 ghi kết quả trực tiếp trên WWHN-238`);
  lock.checks.qa_rerun_logs = rawLogs;
}


// ── Kết quả ──────────────────────────────────────────────────────────────────
console.log("WWHN-236 / DISP-STK-236 — Apply Adjustment disposition gate\n");
for (const c of checks) console.log(`  PASS ${c}`);
for (const w of warnings) console.log(`  NOTE ${w}`);

if (EMIT_LOCK) {
  const outDir = join(ROOT, "docs/evidence/WWHN-236");
  mkdirSync(outDir, { recursive: true });
  const payload = {
    document: "CONTRACT_NEGATIVE_LOCK",
    task: "WWHN-236",
    disposition: "DISP-STK-236",
    register_item: "DEV236-P1-05",
    controlling_source: "REQ-SRS-037 / HN_SRS_FRD_v1.12_FULL.md (WWHN-334)",
    statement:
      "Disposition là DEFERRED, nên nghĩa vụ contract của DEV236-P1-05 là nghĩa vụ PHỦ ĐỊNH: OpenAPI runtime, permission matrix, baseline contract và route source không được khai apply-adjustment/stocktake/variance. Artifact task-scoped historical được phép giữ nguyên dấu vết cũ và chỉ bị supersede bằng decision record.",
    forbidden_fragments: FORBIDDEN_FRAGMENTS,
    excluded_tables: EXCLUDED_TABLES,
    result: failures.length === 0 ? "PASS" : "FAIL",
    failures,
    notes: warnings,
    ...lock,
  };
  const out = join(outDir, "CONTRACT_NEGATIVE_LOCK.json");
  writeFileSync(out, `${JSON.stringify(payload, null, 2)}\n`);
  console.log(`\n  → ghi ${toPosix(relative(ROOT, out))}`);
}

if (failures.length > 0) {
  console.error(`\n${failures.length} FAILURE(S):\n`);
  for (const f of failures) console.error(`  FAIL ${f}`);
  console.error(
    "\nDISP-STK-236: bề mặt Apply Adjustment/Stocktake bị REQ-SRS-037 loại khỏi MVP v1.12.\n" +
      "Chỉ được re-enable sau approved CR + WWHN-25 GO + rebaseline SRS (DISP-STK-236 §2).\n",
  );
  process.exit(1);
}

console.log(`\n${checks.length}/${checks.length} check PASS — bề mặt adjustment/stocktake đang sống = zero.`);
