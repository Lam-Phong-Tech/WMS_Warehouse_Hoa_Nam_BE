#!/usr/bin/env node
/**
 * WWHN-231 / DEV231-P1-03 — benchmark truy vấn aggregate ledger trên PostgreSQL thật.
 *
 * Đo 4 tổ hợp trên cùng một dataset cố định:
 *   A legacy    + index baseline (idx_sm_wh_product_time)
 *   B optimized + index baseline
 *   C legacy    + idx_sm_ledger_dimension
 *   D optimized + idx_sm_ledger_dimension
 *
 * "legacy" = hình dạng cũ của OperationalReportController: join warehouses/products/
 * product_categories TRƯỚC GROUP BY và gom theo 8 cột.
 * "optimized" = hình dạng LedgerReconciliationService: gom trên riêng stock_movements
 * theo 3 cột dimension rồi mới join master data.
 *
 * Dataset dựng bằng generate_series với khoá md5 tất định nên chạy lại cho đúng cùng dữ liệu.
 * Benchmark chạy trên database riêng (mặc định wwhn231_bench) để không đụng dữ liệu nào khác.
 *
 * Chạy:  node scripts/wwhn231-ledger-benchmark.mjs
 * Env:   WWHN231_PG_CONTAINER, WWHN231_PG_USER, WWHN231_BENCH_DB, WWHN231_MOVEMENTS, WWHN231_REPEAT
 */

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const CONTAINER = process.env.WWHN231_PG_CONTAINER ?? 'wms_warehouse_hoa_nam_be-postgres-1';
const PGUSER = process.env.WWHN231_PG_USER ?? 'postgres';
const BENCH_DB = process.env.WWHN231_BENCH_DB ?? 'wwhn231_bench';
const MOVEMENTS = Number(process.env.WWHN231_MOVEMENTS ?? 600000);
const PRODUCTS = Number(process.env.WWHN231_PRODUCTS ?? 2000);
const REPEAT = Number(process.env.WWHN231_REPEAT ?? 5);
const OUT_DIR = join(ROOT, 'docs', 'evidence', 'WWHN-231');
const PLAN_DIR = join(OUT_DIR, 'plans');

const BASELINE_INDEX = 'CREATE INDEX IF NOT EXISTS idx_sm_wh_product_time ON stock_movements (warehouse_id, product_id, occurred_at)';
const NEW_INDEX = 'CREATE INDEX IF NOT EXISTS idx_sm_ledger_dimension ON stock_movements (warehouse_id, product_id, stock_status) INCLUDE (qty_delta, occurred_at)';

const QUERY_LEGACY = `SELECT m.warehouse_id, m.product_id, m.stock_status,
       w.code AS warehouse_code, p.code AS product_code, p.name AS product_name,
       p.category_id, pc.category_name,
       SUM(m.qty_delta) AS ledger_qty, MAX(m.occurred_at) AS movement_updated_at
FROM stock_movements m
LEFT JOIN warehouses w ON w.id = m.warehouse_id
LEFT JOIN products p ON p.id = m.product_id
LEFT JOIN product_categories pc ON pc.id = p.category_id
GROUP BY m.warehouse_id, m.product_id, m.stock_status, w.code, p.code, p.name, p.category_id, pc.category_name`;

const QUERY_OPTIMIZED = `SELECT agg.warehouse_id, agg.product_id, agg.stock_status, agg.ledger_qty, agg.movement_updated_at,
       w.code AS warehouse_code, p.code AS product_code, p.name AS product_name,
       p.category_id, pc.category_name
FROM (
    SELECT warehouse_id, product_id, stock_status,
           SUM(qty_delta) AS ledger_qty, MAX(occurred_at) AS movement_updated_at
    FROM stock_movements
    GROUP BY warehouse_id, product_id, stock_status
) agg
LEFT JOIN warehouses w ON w.id = agg.warehouse_id
LEFT JOIN products p ON p.id = agg.product_id
LEFT JOIN product_categories pc ON pc.id = p.category_id`;

/**
 * Kịch bản lọc — đây mới là hình dạng mà index dimension có cơ hội phục vụ. Báo cáo tồn
 * thực tế luôn chạy trong data-scope kho (§ EnforceInventoryWarehouseScope) và người dùng
 * thường lọc thêm theo SKU, nên bản đo "gom toàn bảng" một mình không đủ để quyết định index.
 */
const FILTER_PREDICATE = "warehouse_id = md5('w1')::uuid AND product_id = md5('p7')::uuid";

const QUERY_OPTIMIZED_FILTERED = `SELECT agg.warehouse_id, agg.product_id, agg.stock_status, agg.ledger_qty, agg.movement_updated_at,
       w.code AS warehouse_code, p.code AS product_code, p.name AS product_name,
       p.category_id, pc.category_name
FROM (
    SELECT warehouse_id, product_id, stock_status,
           SUM(qty_delta) AS ledger_qty, MAX(occurred_at) AS movement_updated_at
    FROM stock_movements
    WHERE ${FILTER_PREDICATE}
    GROUP BY warehouse_id, product_id, stock_status
) agg
LEFT JOIN warehouses w ON w.id = agg.warehouse_id
LEFT JOIN products p ON p.id = agg.product_id
LEFT JOIN product_categories pc ON pc.id = p.category_id`;

const QUERY_LEGACY_FILTERED = `SELECT m.warehouse_id, m.product_id, m.stock_status,
       w.code AS warehouse_code, p.code AS product_code, p.name AS product_name,
       p.category_id, pc.category_name,
       SUM(m.qty_delta) AS ledger_qty, MAX(m.occurred_at) AS movement_updated_at
FROM stock_movements m
LEFT JOIN warehouses w ON w.id = m.warehouse_id
LEFT JOIN products p ON p.id = m.product_id
LEFT JOIN product_categories pc ON pc.id = p.category_id
WHERE m.warehouse_id = md5('w1')::uuid AND m.product_id = md5('p7')::uuid
GROUP BY m.warehouse_id, m.product_id, m.stock_status, w.code, p.code, p.name, p.category_id, pc.category_name`;

/**
 * RPT-XNT-01 (§13.10) — Nhập-Xuất-Tồn. Cùng bệnh với báo cáo tồn: bản cũ gom theo 6 cột
 * trong đó 3 cột lấy từ master data, và điều kiện thời gian chỉ chặn một đầu nên gần như
 * quét toàn ledger.
 */
const XNT_FROM = "'2026-08-01 00:00:00'";
const XNT_TO = "'2026-08-31 23:59:59'";
const XNT_SUMS = `
       SUM(CASE WHEN m.occurred_at < ${XNT_FROM} OR (m.occurred_at BETWEEN ${XNT_FROM} AND ${XNT_TO} AND m.movement_type = 'OPENING') THEN m.qty_delta ELSE 0 END) AS opening,
       SUM(CASE WHEN m.occurred_at BETWEEN ${XNT_FROM} AND ${XNT_TO} AND m.movement_type = 'RECEIPT' THEN m.qty_delta ELSE 0 END) AS receipt,
       SUM(CASE WHEN m.occurred_at BETWEEN ${XNT_FROM} AND ${XNT_TO} AND m.movement_type = 'ISSUE' THEN -m.qty_delta ELSE 0 END) AS issue,
       SUM(CASE WHEN m.occurred_at BETWEEN ${XNT_FROM} AND ${XNT_TO} AND m.movement_type = 'COMPONENT_ISSUE' THEN -m.qty_delta ELSE 0 END) AS component_issue,
       SUM(CASE WHEN m.occurred_at BETWEEN ${XNT_FROM} AND ${XNT_TO} AND m.movement_type = 'REVERSAL' THEN m.qty_delta ELSE 0 END) AS reversal_net`;

const QUERY_XNT_LEGACY = `SELECT m.warehouse_id, m.product_id, m.stock_status,
       w.code AS warehouse_code, p.code AS product_code, p.name AS product_name,${XNT_SUMS}
FROM stock_movements m
LEFT JOIN warehouses w ON w.id = m.warehouse_id
LEFT JOIN products p ON p.id = m.product_id
WHERE m.occurred_at <= ${XNT_TO}
GROUP BY m.warehouse_id, m.product_id, m.stock_status, w.code, p.code, p.name`;

const QUERY_XNT_OPTIMIZED = `SELECT agg.*, w.code AS warehouse_code, p.code AS product_code, p.name AS product_name
FROM (
    SELECT m.warehouse_id, m.product_id, m.stock_status,${XNT_SUMS}
    FROM stock_movements m
    WHERE m.occurred_at <= ${XNT_TO}
    GROUP BY m.warehouse_id, m.product_id, m.stock_status
) agg
LEFT JOIN warehouses w ON w.id = agg.warehouse_id
LEFT JOIN products p ON p.id = agg.product_id`;

function psql(sql, { db = BENCH_DB, quiet = true } = {}) {
  const args = ['exec', '-i', CONTAINER, 'psql', '-U', PGUSER, '-d', db, '-v', 'ON_ERROR_STOP=1'];
  if (quiet) args.push('-q');
  args.push('-c', sql);
  return execFileSync('docker', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
}

function explain(sql) {
  return psql(`EXPLAIN (ANALYZE, BUFFERS, VERBOSE OFF, FORMAT TEXT) ${sql}`, { quiet: false });
}

function executionMs(plan) {
  const match = plan.match(/Execution Time:\s*([\d.]+)\s*ms/);
  return match ? Number(match[1]) : Number.NaN;
}

function planningMs(plan) {
  const match = plan.match(/Planning Time:\s*([\d.]+)\s*ms/);
  return match ? Number(match[1]) : Number.NaN;
}

function median(values) {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
}

function buildDataset() {
  console.log(`[1/4] Dựng dataset: ${MOVEMENTS} movements / ${PRODUCTS} SKU trên ${BENCH_DB}`);
  psql(`DROP DATABASE IF EXISTS ${BENCH_DB}`, { db: 'postgres' });
  psql(`CREATE DATABASE ${BENCH_DB}`, { db: 'postgres' });

  psql(`
CREATE TABLE warehouses (id uuid PRIMARY KEY, code varchar(50));
CREATE TABLE product_categories (id uuid PRIMARY KEY, category_name varchar(100));
CREATE TABLE products (id uuid PRIMARY KEY, code varchar(50), name varchar(200), category_id uuid);
CREATE TABLE stock_movements (
    id uuid PRIMARY KEY,
    warehouse_id uuid NOT NULL,
    product_id uuid NOT NULL,
    stock_status varchar(20) NOT NULL,
    qty_delta bigint NOT NULL,
    movement_type varchar(30) NOT NULL,
    occurred_at timestamp(0) NOT NULL
);
INSERT INTO warehouses VALUES (md5('w1')::uuid, 'KHO-TONG-HN');
INSERT INTO product_categories
SELECT md5('c' || g)::uuid, 'NHOM-' || g FROM generate_series(1, 20) g;
INSERT INTO products
SELECT md5('p' || g)::uuid, 'SKU-' || g, 'San pham ' || g, md5('c' || ((g % 20) + 1))::uuid
FROM generate_series(1, ${PRODUCTS}) g;
INSERT INTO stock_movements
SELECT md5('m' || g)::uuid,
       md5('w1')::uuid,
       md5('p' || ((g % ${PRODUCTS}) + 1))::uuid,
       (ARRAY['AVAILABLE','NON_SELLABLE','DEFECTIVE','QUARANTINE'])[(g % 4) + 1],
       CASE WHEN g % 3 = 0 THEN -1 ELSE 2 END,
       (ARRAY['RECEIPT','ISSUE','COMPONENT_ISSUE','REVERSAL','OPENING'])[(g % 5) + 1],
       now() - ((g % 900000) || ' seconds')::interval
FROM generate_series(1, ${MOVEMENTS}) g;
`);
}

function measure(label, sql) {
  const plans = [];
  const executions = [];
  const plannings = [];
  for (let i = 0; i < REPEAT; i++) {
    const plan = explain(sql);
    plans.push(plan);
    executions.push(executionMs(plan));
    plannings.push(planningMs(plan));
  }
  const medianExec = median(executions);
  const representative = plans[executions.indexOf(executions.reduce((a, b) => (Math.abs(b - medianExec) < Math.abs(a - medianExec) ? b : a)))];
  writeFileSync(join(PLAN_DIR, `${label}.txt`), representative, 'utf8');
  console.log(`      ${label}: median ${medianExec.toFixed(1)} ms (runs: ${executions.map((v) => v.toFixed(1)).join(', ')})`);

  return {
    label,
    runs_ms: executions,
    median_execution_ms: medianExec,
    median_planning_ms: median(plannings),
    plan_file: `docs/evidence/WWHN-231/plans/${label}.txt`,
    // Buffers ổn định hơn thời gian tường khi máy đo đang bận: nó đếm số block thực đọc.
    shared_hit_blocks: Number((representative.match(/Buffers: shared hit=(\d+)/) ?? [0, 0])[1]),
    scan_node: /Index Only Scan/.test(representative)
      ? 'Index Only Scan'
      : /Index Scan/.test(representative)
        ? 'Index Scan'
        : /Seq Scan/.test(representative)
          ? 'Seq Scan'
          : 'unknown',
    aggregate_node: /GroupAggregate/.test(representative)
      ? 'GroupAggregate'
      : /HashAggregate/.test(representative)
        ? 'HashAggregate'
        : 'unknown',
  };
}

mkdirSync(PLAN_DIR, { recursive: true });

const version = psql('SHOW server_version', { db: 'postgres', quiet: false }).trim();
buildDataset();

console.log('[2/4] Index baseline (như production trước WWHN-231)');
psql(BASELINE_INDEX);
psql('VACUUM ANALYZE stock_movements');
psql('ANALYZE products; ANALYZE warehouses; ANALYZE product_categories;');
const beforeLegacy = measure('A-legacy-baseline-index', QUERY_LEGACY);
const beforeOptimized = measure('B-optimized-baseline-index', QUERY_OPTIMIZED);
const beforeLegacyFiltered = measure('E-legacy-filtered-baseline-index', QUERY_LEGACY_FILTERED);
const beforeOptimizedFiltered = measure('F-optimized-filtered-baseline-index', QUERY_OPTIMIZED_FILTERED);

console.log('[3/4] Thêm idx_sm_ledger_dimension');
psql(NEW_INDEX);
// VACUUM để visibility map đầy đủ — thiếu bước này Index Only Scan phải quay lại heap và
// phép so sánh trước/sau không còn công bằng.
psql('VACUUM ANALYZE stock_movements;');
const afterLegacy = measure('C-legacy-new-index', QUERY_LEGACY);
const afterOptimized = measure('D-optimized-new-index', QUERY_OPTIMIZED);
const afterLegacyFiltered = measure('G-legacy-filtered-new-index', QUERY_LEGACY_FILTERED);
const afterOptimizedFiltered = measure('H-optimized-filtered-new-index', QUERY_OPTIMIZED_FILTERED);
const xntLegacy = measure('I-xnt-legacy', QUERY_XNT_LEGACY);
const xntOptimized = measure('J-xnt-optimized', QUERY_XNT_OPTIMIZED);

const indexSizes = psql(
  `SELECT indexrelname, pg_size_pretty(pg_relation_size(indexrelid)) AS size
   FROM pg_stat_user_indexes WHERE relname = 'stock_movements' ORDER BY indexrelname`,
  { quiet: false }
);

const rowCount = Number(psql('SELECT count(*) FROM stock_movements', { quiet: false }).match(/\d+/)[0]);

const result = {
  task: 'WWHN-231',
  register_item: 'DEV231-P1-03',
  generated_by: 'scripts/wwhn231-ledger-benchmark.mjs',
  postgres_version: version,
  dataset: {
    database: BENCH_DB,
    stock_movements_rows: rowCount,
    products: PRODUCTS,
    warehouses: 1,
    stock_statuses: 4,
    dimension_cardinality: PRODUCTS * 4,
    deterministic: 'khoá md5(seq) + generate_series, không dùng random()',
  },
  repeat_per_measurement: REPEAT,
  queries: {
    legacy: QUERY_LEGACY,
    optimized: QUERY_OPTIMIZED,
    legacy_filtered: QUERY_LEGACY_FILTERED,
    xnt_legacy: QUERY_XNT_LEGACY,
    xnt_optimized: QUERY_XNT_OPTIMIZED,
    optimized_filtered: QUERY_OPTIMIZED_FILTERED,
  },
  indexes: { baseline: BASELINE_INDEX, added: NEW_INDEX },
  measurements: [
    beforeLegacy,
    beforeOptimized,
    afterLegacy,
    afterOptimized,
    beforeLegacyFiltered,
    beforeOptimizedFiltered,
    afterLegacyFiltered,
    afterOptimizedFiltered,
    xntLegacy,
    xntOptimized,
  ],
  index_sizes: indexSizes.trim(),
  verdict: {
    full_scan_legacy_ms: beforeLegacy.median_execution_ms,
    full_scan_optimized_ms: beforeOptimized.median_execution_ms,
    full_scan_speedup_x: Number((beforeLegacy.median_execution_ms / beforeOptimized.median_execution_ms).toFixed(2)),
    filtered_legacy_ms: beforeLegacyFiltered.median_execution_ms,
    filtered_optimized_before_index_ms: beforeOptimizedFiltered.median_execution_ms,
    filtered_optimized_after_index_ms: afterOptimizedFiltered.median_execution_ms,
    filtered_index_speedup_x: Number(
      (beforeOptimizedFiltered.median_execution_ms / afterOptimizedFiltered.median_execution_ms).toFixed(2)
    ),
    filtered_blocks_before_index: beforeOptimizedFiltered.shared_hit_blocks,
    filtered_blocks_after_index: afterOptimizedFiltered.shared_hit_blocks,
    xnt_legacy_ms: xntLegacy.median_execution_ms,
    xnt_optimized_ms: xntOptimized.median_execution_ms,
    xnt_speedup_x: Number((xntLegacy.median_execution_ms / xntOptimized.median_execution_ms).toFixed(2)),
    query_count_before: 1,
    query_count_after: 1,
    note: 'Số câu truy vấn không đổi (1 aggregate mỗi lần dựng báo cáo); thay đổi nằm ở hình dạng plan và ở index cho nhánh có filter.',
  },
};

writeFileSync(join(OUT_DIR, 'BENCHMARK_RESULT.json'), `${JSON.stringify(result, null, 2)}\n`, 'utf8');
console.log('[4/4] Đã ghi docs/evidence/WWHN-231/BENCHMARK_RESULT.json');
console.log(`      gom toàn bảng: ${result.verdict.full_scan_legacy_ms.toFixed(1)} ms → ${result.verdict.full_scan_optimized_ms.toFixed(1)} ms (x${result.verdict.full_scan_speedup_x})`);
console.log(`      có filter SKU: ${result.verdict.filtered_optimized_before_index_ms.toFixed(1)} ms → ${result.verdict.filtered_optimized_after_index_ms.toFixed(1)} ms (x${result.verdict.filtered_index_speedup_x}), blocks ${result.verdict.filtered_blocks_before_index} → ${result.verdict.filtered_blocks_after_index}`);
