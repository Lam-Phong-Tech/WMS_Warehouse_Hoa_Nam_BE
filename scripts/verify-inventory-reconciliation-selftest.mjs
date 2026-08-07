import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'wwhn180-reconcile-'));
const verifier = path.resolve('scripts/verify-inventory-reconciliation.mjs');

const runCase = (name, snapshot, expectedExit, expectedReason = null) => {
  const input = path.join(tempDir, `${name}.input.json`);
  const output = path.join(tempDir, `${name}.output.json`);
  fs.writeFileSync(input, `${JSON.stringify(snapshot, null, 2)}\n`);

  const result = spawnSync(process.execPath, [verifier, input, output], {
    encoding: 'utf8',
  });
  if (result.status !== expectedExit) {
    throw new Error(`${name}: expected exit ${expectedExit}, received ${result.status}\n${result.stdout}\n${result.stderr}`);
  }

  const report = JSON.parse(fs.readFileSync(output, 'utf8'));
  if (expectedExit === 0 && report.summary?.status !== 'PASS') {
    throw new Error(`${name}: expected PASS report`);
  }
  if (expectedExit !== 0) {
    if (report.summary?.status !== 'FAIL' || report.summary?.failed !== 1) {
      throw new Error(`${name}: expected exactly one failed dimension`);
    }
    if (report.findings?.[0]?.mismatch_reason !== expectedReason) {
      throw new Error(`${name}: expected ${expectedReason}, received ${report.findings?.[0]?.mismatch_reason}`);
    }
  }
};

const dimension = {
  warehouse_id: 'warehouse-1',
  product_id: 'product-1',
  stock_status: 'AVAILABLE',
};

try {
  runCase('matching', {
    balances: [{ ...dimension, on_hand_qty: 7 }],
    signed_ledger_by_dimension: [{ ...dimension, signed_ledger_qty: 7, movement_count: 2 }],
  }, 0);

  runCase('ledger-only', {
    balances: [],
    signed_ledger_by_dimension: [{ ...dimension, signed_ledger_qty: 5, movement_count: 1 }],
  }, 1, 'BALANCE_MISSING_FOR_LEDGER');

  runCase('balance-only', {
    balances: [{ ...dimension, on_hand_qty: 5 }],
    signed_ledger_by_dimension: [],
  }, 1, 'LEDGER_MISSING_FOR_BALANCE');

  runCase('quantity-mismatch', {
    balances: [{ ...dimension, on_hand_qty: 5 }],
    signed_ledger_by_dimension: [{ ...dimension, signed_ledger_qty: 3, movement_count: 1 }],
  }, 1, 'BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM');

  console.log('Inventory reconciliation verifier self-test PASS: 4/4');
} finally {
  fs.rmSync(tempDir, { recursive: true, force: true });
}
