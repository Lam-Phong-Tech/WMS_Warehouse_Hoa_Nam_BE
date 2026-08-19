#!/usr/bin/env node
/**
 * WWHN-237 / WWHN-238 — chạy ma trận QA **N1..N8 + R1..R4** (đóng `DEV237-P1-04R2`).
 *
 * Register R1 gộp cả bộ vào một dòng `php artisan test --filter=...` nên không ai đọc
 * được từng mục N/R đậu hay trượt, và không có raw API/DB nào ngoài stdout của phpunit.
 * R2 đòi: *"QA1 independent execute/review N1..N8 + R1..R4 trên exact frozen BE/FE pair;
 * ghi PASS/FAIL/N/A, env/DB driver, raw API/DB result."*
 *
 * Script này chạy **từng dòng một**, mỗi dòng một raw log riêng, cộng hai bộ raw
 * evidence trên **driver production PostgreSQL** (runtime container đang chạy):
 *
 *   - `RAW-API` — curl thật vào `http://localhost:8080`, ghi status + body nguyên văn.
 *   - `RAW-DB`  — psql read-only vào DB runtime, đếm permission/bảng/CHECK constraint.
 *
 * Read-only tuyệt đối với DB dùng chung: không migrate, không seed, không ghi. Nhiều
 * phiên đang dùng chung `wms_hoanam`; `RefreshDatabase` của phpunit chỉ chạm sqlite
 * `:memory:` theo `phunit.xml`.
 *
 *   node scripts/run-wwhn237-qa-matrix.mjs
 *   node scripts/run-wwhn237-qa-matrix.mjs --skip-live      # bỏ raw API/DB nếu container tắt
 */
import { execSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { PAIR_RELATIVE_PATH } from "./lib/wwhn237-candidate-pair.mjs";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const FE_ROOT = resolve(ROOT, "../../HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE");
const OUT = join(ROOT, "docs/evidence/WWHN-237");
const LOGS = join(OUT, "logs/qa");
const SKIP_LIVE = process.argv.includes("--skip-live");

const API_BASE = process.env.WWHN237_API_BASE ?? "http://localhost:8080";
const PG_CONTAINER = process.env.WWHN237_PG_CONTAINER ?? "wms_warehouse_hoa_nam_be-postgres-1";
const PG_DB = process.env.WWHN237_PG_DB ?? "wms_hoanam";

mkdirSync(LOGS, { recursive: true });

const pairPath = join(ROOT, PAIR_RELATIVE_PATH);
if (!existsSync(pairPath)) {
  console.error("FAIL: thieu CANDIDATE_PAIR.json — chay `node scripts/build-wwhn237-candidate-pair.mjs` truoc.");
  process.exit(1);
}
const pair = JSON.parse(readFileSync(pairPath, "utf8"));

const SQLITE_DRIVER = "sqlite :memory: (phpunit.xml ep DB_CONNECTION=sqlite)";
const PG_DRIVER = `pgsql ${PG_DB} @ ${PG_CONTAINER} (runtime container, READ-ONLY)`;
const NODE_DRIVER = `node ${process.version} / ${process.platform} (gate tinh, khong boot DB)`;

const phpunit = (filter) =>
  `php vendor/bin/phpunit --filter "Wwhn237VarianceReportDeferredGateTest::${filter}$" --no-coverage`;

/**
 * N1..N8 / R1..R4 theo đúng bảng `docs/evidence/WWHN-237/QA_DISPOSITION_WWHN-238.md §2`.
 * Đổi ở đây thì phải đổi ở đó — hai bản mô tả cùng một bộ.
 */
const ROWS = [
  { id: "N1", scope: "negative", check: "Route/API khong lo be mat kiem ke", cwd: ROOT, driver: SQLITE_DRIVER, command: phpunit("test_no_route_exposes_a_variance_or_stocktake_surface") },
  { id: "N2", scope: "negative", check: "Report code la khong routable; dispatcher tra 404 RESOURCE_NOT_FOUND", cwd: ROOT, driver: SQLITE_DRIVER, command: `php vendor/bin/phpunit --filter "Wwhn237VarianceReportDeferredGateTest::(test_variance_report_codes_are_not_routable|test_report_dispatcher_rejects_a_variance_report_code)$" --no-coverage` },
  { id: "N3", scope: "negative", check: "Registry/hub/export khong co ma variance", cwd: ROOT, driver: SQLITE_DRIVER, command: phpunit("test_report_registry_hub_and_export_paths_carry_no_variance_code") },
  { id: "N4", scope: "negative", check: "KPI dashboard khong co field stocktake_variance", cwd: ROOT, driver: SQLITE_DRIVER, command: phpunit("test_dashboard_quick_has_no_stocktake_variance_field") },
  { id: "N5", scope: "negative", check: "Khong seed permission stocktake.*/variance.*", cwd: ROOT, driver: SQLITE_DRIVER, command: phpunit("test_no_variance_permission_is_seeded") },
  { id: "N6", scope: "negative", check: "Khong con entity stocktake_*/variance_* sau migrate", cwd: ROOT, driver: SQLITE_DRIVER, command: phpunit("test_no_variance_entity_survives_migration") },
  { id: "N7", scope: "negative", check: "Contract ban giao hien hanh: gate BE fail-closed", cwd: ROOT, driver: NODE_DRIVER, command: `node scripts/verify-wwhn237-variance-report-disposition.mjs --expect-be-sha=${pair.pair.backend.commit_sha} --expect-fe-sha=${pair.pair.frontend.commit_sha}`, two_pass: true },
  { id: "N8", scope: "negative", check: "Hop dong FE: gate FE 17 check", cwd: FE_ROOT, driver: NODE_DRIVER, command: `node scripts/verify-wwhn-237-closure.mjs --expect-be-sha=${pair.pair.backend.commit_sha} --expect-fe-sha=${pair.pair.frontend.commit_sha}` },
  { id: "R1", scope: "regression", check: "Reconciliation variance ledger-vs-balance PHAI con song", cwd: ROOT, driver: SQLITE_DRIVER, command: phpunit("test_reconciliation_variance_between_balance_and_ledger_stays_available") },
  { id: "R2", scope: "regression", check: "Reversal append-only la duong sua sai duy nhat", cwd: ROOT, driver: SQLITE_DRIVER, command: phpunit("test_append_only_reversal_stays_the_only_correction_path") },
  { id: "R3", scope: "regression", check: "Khong hoi quy luong ton kho Flow05", cwd: ROOT, driver: SQLITE_DRIVER, command: 'php vendor/bin/phpunit --filter "Flow05InventoryControlAlignmentTest" --no-coverage' },
  { id: "R4", scope: "regression", check: "Neo governance: disposition + REQ-SRS-037 con truy duoc", cwd: ROOT, driver: SQLITE_DRIVER, command: phpunit("test_disposition_record_and_controlling_requirement_stay_traceable") },
];

const sha256Text = (t) => createHash("sha256").update(Buffer.from(t, "utf8")).digest("hex");

const runOne = (row, logName) => {
  if (!existsSync(row.cwd)) {
    return { status: "N/A", exit_code: null, na_reason: `cwd khong ton tai: ${row.cwd}`, raw_log: null };
  }
  let output = "";
  let exitCode = 0;
  try {
    output = execSync(row.command, { cwd: row.cwd, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"], maxBuffer: 64 * 1024 * 1024 });
  } catch (error) {
    output = `${error.stdout ?? ""}${error.stderr ?? ""}`;
    exitCode = typeof error.status === "number" ? error.status : 1;
  }
  // Duoi `.txt` chu khong phai `.log`: .gitignore dong 1 ignore *.log nen raw log se
  // khong bao gio vao repo — dung loi bo evidence WWHN-296 da mac.
  const body = `$ ${row.command}\n(cwd: ${row.cwd})\n(driver: ${row.driver})\n\n${output}\nEXIT_CODE=${exitCode}\n`;
  writeFileSync(join(LOGS, logName), body);
  return {
    status: exitCode === 0 ? "PASS" : "FAIL",
    exit_code: exitCode,
    raw_log: `docs/evidence/WWHN-237/logs/qa/${logName}`,
    log_sha256: sha256Text(body),
  };
};

// ── Raw API/DB tren driver production ────────────────────────────────────────
const rawEvidence = [];

const runRawApi = () => {
  const probes = [
    { uri: "/api/v1/stocktake-sessions", expect: "404 RESOURCE_NOT_FOUND — khong co route kiem ke" },
    { uri: "/api/v1/stocktake-sessions/1/apply-adjustment", expect: "404 RESOURCE_NOT_FOUND" },
    { uri: "/api/v1/inventory/apply-adjustment", expect: "404 RESOURCE_NOT_FOUND" },
    { uri: "/api/v1/variance-lines", expect: "404 RESOURCE_NOT_FOUND" },
    { uri: "/api/v1/reports/stocktake-variance", expect: "401 UNAUTHENTICATED — khop catch-all reports/{report_code}, auth chan truoc; viec dispatcher tu choi ma variance duoc chung minh o N2" },
    { uri: "/api/v1/reports/hub", expect: "401 UNAUTHENTICATED — hub ton tai, khong lo ma variance cho khach chua dang nhap" },
    { uri: "/api/v1/health", expect: "200 — chung minh runtime that dang phuc vu, khong phai server tat" },
  ];
  const lines = [
    "WWHN-237 — RAW API evidence (driver production, runtime container)",
    `base: ${API_BASE}`,
    "phuong phap: curl khong kem token. 404 = route vang mat that; 401 = route generic ton tai nhung",
    "khong lo be mat nao cho khach chua xac thuc. Khong dung tai khoan that de tranh dung account hygiene.",
    "",
  ];
  let ok = true;
  for (const probe of probes) {
    let out = "";
    try {
      out = execSync(
        `curl -s -m 20 -w "\\nHTTP_STATUS=%{http_code}\\n" -H "Accept: application/json" "${API_BASE}${probe.uri}"`,
        { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
      );
    } catch (error) {
      out = `${error.stdout ?? ""}${error.stderr ?? ""}`;
      ok = false;
    }
    lines.push(`GET ${probe.uri}`, `  ky vong: ${probe.expect}`, out.trim().split("\n").map((l) => `  ${l}`).join("\n"), "");
  }
  const body = lines.join("\n");
  writeFileSync(join(LOGS, "raw-api-pgsql.txt"), body);
  return { id: "RAW-API", driver: PG_DRIVER, status: ok ? "PASS" : "FAIL", log: "docs/evidence/WWHN-237/logs/qa/raw-api-pgsql.txt", log_sha256: sha256Text(body) };
};

const runRawDb = () => {
  const queries = [
    ["permission cam", "SELECT count(*) AS forbidden_permission_count FROM permissions WHERE permission_key ILIKE '%stocktake%' OR permission_key ILIKE '%variance%' OR permission_key ILIKE '%adjustment%';"],
    ["bang cam", "SELECT count(*) AS forbidden_table_count FROM information_schema.tables WHERE table_schema='public' AND (table_name ILIKE '%stocktake%' OR table_name ILIKE '%variance%' OR table_name ILIKE '%adjustment%');"],
    ["movement_type CHECK", "SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname LIKE '%movement_type%';"],
    ["duong thay the con song", "SELECT count(*) AS reconciliation_incident_table FROM information_schema.tables WHERE table_schema='public' AND table_name IN ('reconciliation_incidents','reconciliation_incident');"],
    ["reversal con song", "SELECT count(*) AS reversal_document_table FROM information_schema.tables WHERE table_schema='public' AND table_name IN ('reversal_documents','reversal_document');"],
  ];
  const lines = [
    "WWHN-237 — RAW DB evidence (PostgreSQL runtime, READ-ONLY)",
    `container: ${PG_CONTAINER}   db: ${PG_DB}`,
    "Chi SELECT. Khong migrate/seed/ghi: DB nay dung chung nhieu phien.",
    "",
  ];
  let ok = true;
  for (const [label, sql] of queries) {
    let out = "";
    try {
      out = execSync(`docker exec ${PG_CONTAINER} psql -U postgres -d ${PG_DB} -c "${sql.replace(/"/g, '\\"')}"`, {
        encoding: "utf8",
        stdio: ["ignore", "pipe", "pipe"],
      });
    } catch (error) {
      out = `${error.stdout ?? ""}${error.stderr ?? ""}`;
      ok = false;
    }
    lines.push(`-- ${label}`, `SQL: ${sql}`, out.trim(), "");
  }
  const body = lines.join("\n");
  writeFileSync(join(LOGS, "raw-db-pgsql.txt"), body);
  return { id: "RAW-DB", driver: PG_DRIVER, status: ok ? "PASS" : "FAIL", log: "docs/evidence/WWHN-237/logs/qa/raw-db-pgsql.txt", log_sha256: sha256Text(body) };
};

// ── Chay ──────────────────────────────────────────────────────────────────────
console.log("WWHN-237 / WWHN-238 — QA matrix N1..N8 + R1..R4 (DEV237-P1-04R2)\n");
console.log(`  pair BE ${pair.pair.backend.commit_sha}`);
console.log(`  pair FE ${pair.pair.frontend.commit_sha}\n`);

const results = [];
for (const row of ROWS) {
  // N7 chay hai lan co y: lan 1 la bootstrap (luc nay QA_EXECUTION_MATRIX.json chua ton
  // tai nen check QA-MATRIX cua chinh gate se do), lan 2 sau khi ma tran da duoc ghi moi
  // la ket qua co gia tri. Khong lam vay thi hai artifact tham chieu vong tron nhau.
  const outcome = runOne(row, `${row.id.toLowerCase()}${row.two_pass ? "-pass1" : ""}.txt`);
  results.push({ id: row.id, scope: row.scope, check: row.check, driver: row.driver, command: row.command, cwd: row.cwd, ...outcome });
  console.log(`  ${outcome.status.padEnd(4)} ${row.id}  ${row.check}`);
}

if (!SKIP_LIVE) {
  const api = runRawApi();
  const db = runRawDb();
  rawEvidence.push(api, db);
  console.log(`  ${api.status.padEnd(4)} RAW-API  curl that vao runtime`);
  console.log(`  ${db.status.padEnd(4)} RAW-DB   psql read-only vao runtime`);
} else {
  console.log("  SKIP RAW-API / RAW-DB (--skip-live)");
}

const buildMatrix = (rows) => ({
  schema_version: "1.0.0",
  record_id: "WWHN-237-QA-EXECUTION-MATRIX",
  jira_key: "WWHN-237",
  qa_gate: "WWHN-238",
  register_item: "DEV237-P1-04R2",
  disposition: "DISP-STK-237",
  source_of_truth: "docs/evidence/WWHN-237/QA_DISPOSITION_WWHN-238.md §2",
  executed_at_pair: { backend: pair.pair.backend.commit_sha, frontend: pair.pair.frontend.commit_sha },
  executed_by_role: "DEV (pre-run). QA1 phai chay lai doc lap va ky vao Jira — ban chay nay KHONG thay the QA1.",
  independence_note:
    "Register R2 doi QA1 independent execute/review. File nay la bo cong cu + ket qua chay tham chieu de QA1 tai lap trong mot lenh; " +
    "cot executed_by_role phai duoc QA1 doi thanh ten minh khi ky.",
  environment: { node: process.version, platform: process.platform, api_base: API_BASE, pg_container: PG_CONTAINER, pg_database: PG_DB },
  two_pass_note:
    "N7 la gate cua chinh bo evidence nay nen chay hai lan: pass1 bootstrap truoc khi ma tran ton tai, pass2 sau khi ma tran da ghi. " +
    "Ket qua co gia tri la pass2 (raw_log tro toi n7-pass2.txt).",
  rows,
  raw_api_db_evidence: rawEvidence,
  summary: {
    total: rows.length,
    pass: rows.filter((r) => r.status === "PASS").length,
    fail: rows.filter((r) => r.status === "FAIL").length,
    na: rows.filter((r) => r.status === "N/A").length,
  },
});

// Ghi lan 1 de N7 pass2 co ma tran that ma doc.
const provisional = buildMatrix(results);
writeFileSync(join(OUT, "QA_EXECUTION_MATRIX.json"), JSON.stringify(provisional, null, 2) + "\n");

const n7 = ROWS.find((r) => r.two_pass);
if (n7) {
  const outcome = runOne(n7, "n7-pass2.txt");
  const idx = results.findIndex((r) => r.id === n7.id);
  results[idx] = { ...results[idx], ...outcome, pass1_log: `docs/evidence/WWHN-237/logs/qa/n7-pass1.txt` };
  console.log(`  ${outcome.status.padEnd(4)} N7   (pass 2 — sau khi ma tran da duoc ghi)`);
}

const matrix = buildMatrix(results);
writeFileSync(join(OUT, "QA_EXECUTION_MATRIX.json"), JSON.stringify(matrix, null, 2) + "\n");

console.log(`\n  matrix → docs/evidence/WWHN-237/QA_EXECUTION_MATRIX.json`);
console.log(`  summary: ${matrix.summary.pass} PASS / ${matrix.summary.fail} FAIL / ${matrix.summary.na} N/A`);
if (matrix.summary.fail > 0) process.exit(1);
