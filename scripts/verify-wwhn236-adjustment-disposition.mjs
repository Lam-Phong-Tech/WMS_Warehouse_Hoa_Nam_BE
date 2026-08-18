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
