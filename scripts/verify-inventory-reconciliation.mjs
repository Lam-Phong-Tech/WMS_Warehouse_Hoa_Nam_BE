import fs from 'node:fs';

const [snapshotPath, outputPath] = process.argv.slice(2);
if (!snapshotPath || !outputPath) {
  throw new Error('Usage: node verify-inventory-reconciliation.mjs <snapshot.json> <output.json>');
}

const snapshot = JSON.parse(fs.readFileSync(snapshotPath, 'utf8'));
const dimensionKey = (row) => `${row.warehouse_id}|${row.product_id}|${row.stock_status}`;
const ledger = new Map((snapshot.signed_ledger_by_dimension ?? []).map((row) => [
  dimensionKey(row),
  Number(row.signed_ledger_qty),
]));

const findings = (snapshot.balances ?? []).map((balance) => {
  const key = dimensionKey(balance);
  const balanceQty = Number(balance.on_hand_qty);
  const ledgerQty = Number(ledger.get(key) ?? 0);
  const delta = Number((balanceQty - ledgerQty).toFixed(3));

  return {
    dimension_key: key,
    balance_qty: balanceQty,
    signed_ledger_qty: ledgerQty,
    delta,
    result: Math.abs(delta) < 0.0005 ? 'PASS' : 'FAIL',
  };
});

const failed = findings.filter((finding) => finding.result === 'FAIL');
const output = {
  schema_version: '1.0.0',
  generated_at_utc: new Date().toISOString(),
  formula: 'inventory_balances.on_hand_qty = SUM(stock_movements.qty_delta) by warehouse/product/stock_status',
  summary: {
    checked: findings.length,
    passed: findings.length - failed.length,
    failed: failed.length,
    status: failed.length === 0 ? 'PASS' : 'FAIL',
  },
  findings,
};

fs.writeFileSync(outputPath, `${JSON.stringify(output, null, 2)}\n`);
if (failed.length > 0) {
  console.error(JSON.stringify(output, null, 2));
  process.exit(1);
}
console.log(`Inventory reconciliation PASS: ${findings.length}/${findings.length}`);
