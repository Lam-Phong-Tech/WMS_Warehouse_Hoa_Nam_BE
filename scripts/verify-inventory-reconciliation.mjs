import fs from 'node:fs';

const [snapshotPath, outputPath] = process.argv.slice(2);
if (!snapshotPath || !outputPath) {
  throw new Error('Usage: node verify-inventory-reconciliation.mjs <snapshot.json> <output.json>');
}

const snapshot = JSON.parse(fs.readFileSync(snapshotPath, 'utf8'));
const dimensionKey = (row) => `${row.warehouse_id}|${row.product_id}|${row.stock_status}`;

const balances = new Map((snapshot.balances ?? []).map((row) => [dimensionKey(row), row]));
const ledger = new Map((snapshot.signed_ledger_by_dimension ?? []).map((row) => [dimensionKey(row), row]));
const dimensions = [...new Set([...balances.keys(), ...ledger.keys()])].sort();

const findings = dimensions.map((key) => {
  const balance = balances.get(key);
  const ledgerRow = ledger.get(key);
  const balanceQty = Number(balance?.on_hand_qty ?? 0);
  const ledgerQty = Number(ledgerRow?.signed_ledger_qty ?? 0);
  const movementCount = Number(ledgerRow?.movement_count ?? 0);
  const delta = Number((balanceQty - ledgerQty).toFixed(3));
  const result = Math.abs(delta) < 0.0005 ? 'PASS' : 'FAIL';

  let mismatchReason = null;
  if (result === 'FAIL') {
    if (!balance && movementCount > 0) {
      mismatchReason = 'BALANCE_MISSING_FOR_LEDGER';
    } else if (balance && movementCount === 0) {
      mismatchReason = 'LEDGER_MISSING_FOR_BALANCE';
    } else {
      mismatchReason = 'BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM';
    }
  }

  return {
    dimension_key: key,
    warehouse_id: balance?.warehouse_id ?? ledgerRow?.warehouse_id ?? null,
    product_id: balance?.product_id ?? ledgerRow?.product_id ?? null,
    stock_status: balance?.stock_status ?? ledgerRow?.stock_status ?? null,
    balance_exists: Boolean(balance),
    ledger_exists: Boolean(ledgerRow),
    movement_count: movementCount,
    balance_qty: balanceQty,
    signed_ledger_qty: ledgerQty,
    delta,
    result,
    mismatch_reason: mismatchReason,
  };
});

const failed = findings.filter((finding) => finding.result === 'FAIL');
const output = {
  schema_version: '1.1.0',
  generated_at_utc: new Date().toISOString(),
  formula: 'inventory_balances.on_hand_qty = SUM(stock_movements.qty_delta) by warehouse/product/stock_status',
  dimension_source: 'UNION(inventory_balances, stock_movements)',
  summary: {
    checked: findings.length,
    passed: findings.length - failed.length,
    failed: failed.length,
    status: failed.length === 0 ? 'PASS' : 'FAIL',
  },
  mismatch_reason_counts: failed.reduce((counts, finding) => {
    const reason = finding.mismatch_reason ?? 'UNKNOWN';
    counts[reason] = (counts[reason] ?? 0) + 1;
    return counts;
  }, {}),
  findings,
};

fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);
if (failed.length > 0) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}
console.log(`Inventory reconciliation PASS: ${findings.length}/${findings.length}`);
