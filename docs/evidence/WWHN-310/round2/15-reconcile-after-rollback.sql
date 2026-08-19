\pset pager off
\echo == ledger cua package sau rollback: OPENING phai bi trung hoa boi REVERSAL ==
SELECT m.movement_type, SUM(m.qty_delta) AS qty_delta, COUNT(*) AS movements
  FROM stock_movements m JOIN skus s ON s.id = m.sku_id
 WHERE s.sku_code LIKE 'HN-%-310-%'
 GROUP BY 1 ORDER BY 1;
\echo == residual_opening_qty = tong rong cua run (phai = 0) ==
SELECT COALESCE(SUM(m.qty_delta),0) AS residual_opening_qty
  FROM stock_movements m JOIN skus s ON s.id = m.sku_id
 WHERE s.sku_code LIKE 'HN-%-310-%';
\echo == ledger van la append-only: khong dong nao bi xoa ==
SELECT COUNT(*) AS total_movements_of_package
  FROM stock_movements m JOIN skus s ON s.id = m.sku_id WHERE s.sku_code LIKE 'HN-%-310-%';
\echo == master con lai (SKU bi ledger ghim => INACTIVE, khong xoa duoc) ==
SELECT 'skus' AS t, COUNT(*) FROM skus WHERE sku_code LIKE 'HN-%-310-%'
UNION ALL SELECT 'skus_active', COUNT(*) FROM skus WHERE sku_code LIKE 'HN-%-310-%' AND status = 'ACTIVE'
UNION ALL SELECT 'agencies', COUNT(*) FROM agencies WHERE agency_code LIKE 'AG-310-%'
UNION ALL SELECT 'item_instances', COUNT(*) FROM item_instances WHERE serial_number LIKE 'SN310%'
UNION ALL SELECT 'containers', COUNT(*) FROM containers WHERE container_code LIKE 'CTN-310-%'
UNION ALL SELECT 'physical_codes', COUNT(*) FROM physical_codes WHERE code_value LIKE 'HN-%310%';
\echo == balance con lai cua package (phai rong) ==
SELECT b.sku_id, b.stock_status, b.quantity FROM inventory_balances b
  JOIN skus s ON s.id = b.sku_id WHERE s.sku_code LIKE 'HN-%-310-%' AND b.quantity <> 0;
\echo == balance vs ledger tren TOAN BO kho sau rollback (phai rong) ==
SELECT b.warehouse_id, b.sku_id, b.stock_status, b.quantity AS balance_qty, COALESCE(l.qty,0) AS ledger_qty
  FROM inventory_balances b
  LEFT JOIN (SELECT warehouse_id, sku_id, stock_status, SUM(qty_delta) qty FROM stock_movements GROUP BY 1,2,3) l
    ON l.warehouse_id = b.warehouse_id AND l.sku_id = b.sku_id AND l.stock_status = b.stock_status
 WHERE b.quantity <> COALESCE(l.qty,0);
\echo == migration_runs sau rollback ==
SELECT migration_run_code, mode, status FROM migration_runs ORDER BY created_at;
\echo == audit MIGRATION_* ==
SELECT action, COUNT(*) FROM audit_logs WHERE action LIKE 'MIGRATION%' GROUP BY 1 ORDER BY 1;
