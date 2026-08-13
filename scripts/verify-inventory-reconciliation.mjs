import fs from 'node:fs';

const [inputPath, outputPath] = process.argv.slice(2);
if (!inputPath || !outputPath) {
  throw new Error('Thiếu tham số verify-inventory-reconciliation: <snapshot.json> <report.json>');
}

const snapshot = JSON.parse(fs.readFileSync(inputPath, 'utf8'));

const dimensionKey = (row) => `${row.warehouse_id}|${row.product_id}|${row.stock_status}`;

const balancesByDimension = new Map();
for (const row of snapshot.balances ?? []) {
  const key = dimensionKey(row);
  const onHandQty = Number(row.on_hand_qty ?? 0);
  const existing = balancesByDimension.get(key);
  balancesByDimension.set(key, {
    warehouse_id: row.warehouse_id,
    product_id: row.product_id,
    stock_status: row.stock_status,
    on_hand_qty: existing ? existing.on_hand_qty + onHandQty : onHandQty,
  });
}

const ledgerByDimension = new Map();
for (const row of snapshot.signed_ledger_by_dimension ?? []) {
  const key = dimensionKey(row);
  const signedQty = Number(row.signed_ledger_qty ?? 0);
  const movementCount = Number(row.movement_count ?? 0);
  const existing = ledgerByDimension.get(key);
  ledgerByDimension.set(key, {
    warehouse_id: row.warehouse_id,
    product_id: row.product_id,
    stock_status: row.stock_status,
    signed_ledger_qty: existing ? existing.signed_ledger_qty + signedQty : signedQty,
    movement_count: existing ? existing.movement_count + movementCount : movementCount,
  });
}

const dimensionKeys = new Set([...balancesByDimension.keys(), ...ledgerByDimension.keys()]);

const findings = [];
for (const key of dimensionKeys) {
  const balance = balancesByDimension.get(key) ?? null;
  const ledger = ledgerByDimension.get(key) ?? null;

  if (ledger && !balance) {
    findings.push({ ...ledger, on_hand_qty: null, mismatch_reason: 'BALANCE_MISSING_FOR_LEDGER' });
    continue;
  }

  if (balance && !ledger) {
    findings.push({ ...balance, signed_ledger_qty: null, movement_count: 0, mismatch_reason: 'LEDGER_MISSING_FOR_BALANCE' });
    continue;
  }

  if (balance.on_hand_qty !== ledger.signed_ledger_qty) {
    findings.push({
      warehouse_id: balance.warehouse_id,
      product_id: balance.product_id,
      stock_status: balance.stock_status,
      on_hand_qty: balance.on_hand_qty,
      signed_ledger_qty: ledger.signed_ledger_qty,
      movement_count: ledger.movement_count,
      mismatch_reason: 'BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM',
    });
  }
}

const output = {
  schema_version: '1.0.0',
  generated_at_utc: new Date().toISOString(),
  summary: {
    checked: dimensionKeys.size,
    passed: dimensionKeys.size - findings.length,
    failed: findings.length,
    status: findings.length === 0 ? 'PASS' : 'FAIL',
  },
  findings,
};

fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);

if (findings.length > 0) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}

console.log(`Inventory reconciliation PASS: ${output.summary.passed}/${output.summary.checked} dimension(s) khớp balance vs signed ledger.`);
