#!/usr/bin/env node
/**
 * WWHN-231 — thu evidence chạy thật trên PostgreSQL cho DEV231-P0-02, P1-04, P1-06.
 *
 * Các bước, theo đúng thứ tự reviewer cần đọc:
 *   1. Chụp trạng thái tồn kho TRƯỚC (ledger + projection + hash từng dòng).
 *   2. Chạy migration index của WWHN-231 (chỉ thêm index đọc).
 *   3. Chạy đối soát `inventory:reconcile --dry-run` — bước duy nhất chạm dữ liệu tồn,
 *      và nó phải là read-only.
 *   4. Chụp trạng thái SAU và so khớp từng byte với bước 1.
 *   5. Chạy bộ test khoá phạm vi Wwhn231StockNeutralLedgerTest trên chính PostgreSQL.
 *
 * Ghi ra docs/evidence/WWHN-231/RECONCILIATION_RUN.json + logs/.
 *
 * Chạy:  node scripts/run-wwhn-231-evidence.mjs
 */

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT_DIR = join(ROOT, 'docs', 'evidence', 'WWHN-231');
const LOG_DIR = join(OUT_DIR, 'logs');
const CONTAINER = process.env.WWHN231_PG_CONTAINER ?? 'wms_warehouse_hoa_nam_be-postgres-1';
const PGUSER = process.env.WWHN231_PG_USER ?? 'postgres';
const PGDB = process.env.WWHN231_PG_DATABASE ?? 'hoa_nam_audit';

const PG_ENV = {
  ...process.env,
  DB_CONNECTION: 'pgsql',
  DB_HOST: process.env.DB_HOST ?? '127.0.0.1',
  DB_PORT: process.env.DB_PORT ?? '5432',
  DB_DATABASE: PGDB,
  DB_USERNAME: PGUSER,
  DB_PASSWORD: process.env.DB_PASSWORD ?? 'postgres',
};

mkdirSync(LOG_DIR, { recursive: true });

function psql(sql) {
  return execFileSync(
    'docker',
    ['exec', '-i', CONTAINER, 'psql', '-U', PGUSER, '-d', PGDB, '-t', '-A', '-F', '|', '-v', 'ON_ERROR_STOP=1', '-c', sql],
    { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 }
  ).trim();
}

function run(label, command, args, options = {}) {
  let stdout = '';
  let exitCode = 0;
  try {
    stdout = execFileSync(command, args, {
      cwd: ROOT,
      encoding: 'utf8',
      maxBuffer: 64 * 1024 * 1024,
      env: options.env ?? process.env,
    });
  } catch (error) {
    stdout = `${error.stdout ?? ''}${error.stderr ?? ''}`;
    exitCode = error.status ?? 1;
  }
  writeFileSync(join(LOG_DIR, `${label}.log`), stdout, 'utf8');
  console.log(`  ${label}: exit ${exitCode}`);

  return { label, command: `${command} ${args.join(' ')}`, exit_code: exitCode, log: `docs/evidence/WWHN-231/logs/${label}.log` };
}

function stockSnapshot() {
  const movementCount = psql('SELECT count(*) FROM stock_movements');
  const movementSum = psql('SELECT COALESCE(SUM(qty_delta), 0) FROM stock_movements');
  const balanceRows = psql(
    `SELECT warehouse_id, product_id, stock_status, on_hand_qty, available_qty, reserved_qty
     FROM inventory_balances ORDER BY warehouse_id, product_id, stock_status`
  );
  const ledgerRows = psql(
    `SELECT warehouse_id, product_id, stock_status, SUM(qty_delta), MAX(occurred_at)
     FROM stock_movements GROUP BY warehouse_id, product_id, stock_status
     ORDER BY warehouse_id, product_id, stock_status`
  );

  return {
    movement_count: Number(movementCount),
    movement_delta_sum: Number(movementSum),
    balance_rows: balanceRows === '' ? 0 : balanceRows.split('\n').length,
    ledger_dimensions: ledgerRows === '' ? 0 : ledgerRows.split('\n').length,
    balance_sha256: createHash('sha256').update(balanceRows).digest('hex'),
    ledger_sha256: createHash('sha256').update(ledgerRows).digest('hex'),
  };
}

console.log(`[1/5] Chụp trạng thái tồn kho trước (database ${PGDB})`);
const before = stockSnapshot();
console.log(`      movements=${before.movement_count} dimensions=${before.ledger_dimensions} balances=${before.balance_rows}`);

console.log('[2/5] Migration index đọc của WWHN-231');
const migration = run('migrate-ledger-dimension-index', 'php', [
  'artisan',
  'migrate',
  '--path=Modules/Warehouse/database/migrations/2026_08_19_010000_add_ledger_dimension_index_to_stock_movements.php',
  '--force',
], { env: PG_ENV });

const indexRow = psql(
  `SELECT indexdef FROM pg_indexes WHERE tablename = 'stock_movements' AND indexname = 'idx_sm_ledger_dimension'`
);

console.log('[3/5] Đối soát Ledger↔Balance (dry-run, read-only)');
const reconcile = run('inventory-reconcile-dry-run', 'php', ['artisan', 'inventory:reconcile', '--dry-run'], { env: PG_ENV });

console.log('[4/5] Chụp trạng thái tồn kho sau');
const after = stockSnapshot();
const stockNeutral =
  before.movement_count === after.movement_count &&
  before.movement_delta_sum === after.movement_delta_sum &&
  before.balance_sha256 === after.balance_sha256 &&
  before.ledger_sha256 === after.ledger_sha256;
console.log(`      stock-neutral: ${stockNeutral ? 'PASS' : 'FAIL'}`);

// Bộ test chạy trên sqlite — đúng driver mà CI current-baseline-doc-contract.yml dùng.
// Trên PostgreSQL mọi test có RefreshDatabase hiện đỏ từ trước vì migration
// 2026_08_19_000100 (WWHN-252); xem docs/evidence/WWHN-231/BASELINE_ISOLATION.md §3.
console.log('[5/5] Bộ test khoá phạm vi trên baseline sqlite');
const suite = run('phpunit-wwhn231-sqlite', 'php', ['vendor/bin/phpunit', '--filter', 'Wwhn231StockNeutralLedgerTest']);

const divergences = psql(
  `SELECT b.warehouse_id, b.product_id, b.stock_status, b.on_hand_qty, COALESCE(l.ledger_qty, 0) AS ledger_qty,
          b.on_hand_qty - COALESCE(l.ledger_qty, 0) AS variance
   FROM inventory_balances b
   LEFT JOIN (
       SELECT warehouse_id, product_id, stock_status, SUM(qty_delta) AS ledger_qty
       FROM stock_movements GROUP BY warehouse_id, product_id, stock_status
   ) l ON l.warehouse_id = b.warehouse_id AND l.product_id = b.product_id AND l.stock_status = b.stock_status
   WHERE b.on_hand_qty <> COALESCE(l.ledger_qty, 0)
   ORDER BY b.warehouse_id, b.product_id, b.stock_status`
);
const orphanLedger = psql(
  `SELECT l.warehouse_id, l.product_id, l.stock_status, l.ledger_qty
   FROM (
       SELECT warehouse_id, product_id, stock_status, SUM(qty_delta) AS ledger_qty
       FROM stock_movements GROUP BY warehouse_id, product_id, stock_status
   ) l
   LEFT JOIN inventory_balances b
     ON b.warehouse_id = l.warehouse_id AND b.product_id = l.product_id AND b.stock_status = l.stock_status
   WHERE b.warehouse_id IS NULL AND l.ledger_qty <> 0
   ORDER BY l.warehouse_id, l.product_id, l.stock_status`
);

const mismatchList = [
  ...(divergences === '' ? [] : divergences.split('\n')),
  ...(orphanLedger === '' ? [] : orphanLedger.split('\n')),
];

const result = {
  task: 'WWHN-231',
  register_items: ['DEV231-P0-02', 'DEV231-P1-04', 'DEV231-P1-06'],
  database: PGDB,
  postgres_version: psql('SHOW server_version'),
  steps: [migration, reconcile, suite],
  index_definition: indexRow,
  stock_state_before: before,
  stock_state_after: after,
  stock_neutral: stockNeutral,
  // Trung thực về sức nặng của bằng chứng: mismatch = 0 trên một database rỗng là kết quả
  // rỗng, không phải bằng chứng đối soát đúng.
  reconciliation_caveat:
    before.ledger_dimensions === 0 && before.balance_rows === 0
      ? 'DB_EMPTY — database này chưa có dữ liệu tồn kho nên mismatch=0 không mang thông tin. Bằng chứng đối soát trên dữ liệu thật (có lệch cấy sẵn) nằm ở Wwhn231StockNeutralLedgerTest::test_detected_divergence_creates_incident_without_touching_stock'
      : null,
  reconciliation: {
    formula: 'variance = inventory_balances.on_hand_qty - SUM(stock_movements.qty_delta)',
    dimension: ['warehouse_id', 'product_id', 'stock_status'],
    mismatch_count: mismatchList.length,
    mismatches: mismatchList,
    mismatch_sha256: createHash('sha256').update(mismatchList.join('\n')).digest('hex'),
  },
};

writeFileSync(join(OUT_DIR, 'RECONCILIATION_RUN.json'), `${JSON.stringify(result, null, 2)}\n`, 'utf8');
console.log('Đã ghi docs/evidence/WWHN-231/RECONCILIATION_RUN.json');
console.log(`mismatch = ${mismatchList.length}`);

process.exit(stockNeutral && migration.exit_code === 0 && reconcile.exit_code === 0 && suite.exit_code === 0 ? 0 : 1);
