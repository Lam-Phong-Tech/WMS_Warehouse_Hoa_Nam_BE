\pset pager off
\echo == migration_runs ==
SELECT migration_run_code, mode, status, total_rows, valid_rows, error_rows, duplicate_rows,
       source_checksum, executed_by IS NOT NULL AS has_operator, approved_by IS NOT NULL AS has_approver, note
  FROM migration_runs ORDER BY created_at;
\echo == migration_errors (phai rong) ==
SELECT migration_run_id, dataset, row_no, field_name, error_code, severity, raw_value_masked
  FROM migration_errors ORDER BY dataset, row_no, field_name;
\echo == ledger OPENING movements cua run ==
SELECT sm.stock_status, s.sku_code, SUM(sm.qty_delta) AS qty_delta, COUNT(*) AS movements
  FROM stock_movements sm JOIN skus s ON s.id = sm.sku_id
 WHERE sm.movement_type = 'OPENING'
 GROUP BY 1,2 ORDER BY 2,1;
\echo == balance vs ledger tren TOAN BO kho (phai rong) ==
SELECT b.warehouse_id, b.sku_id, b.stock_status, b.quantity AS balance_qty, COALESCE(l.qty,0) AS ledger_qty
  FROM inventory_balances b
  LEFT JOIN (SELECT warehouse_id, sku_id, stock_status, SUM(qty_delta) qty FROM stock_movements GROUP BY 1,2,3) l
    ON l.warehouse_id = b.warehouse_id AND l.sku_id = b.sku_id AND l.stock_status = b.stock_status
 WHERE b.quantity <> COALESCE(l.qty,0);
\echo == serial-tracked: opening qty vs so Item (SRS 13.12) ==
SELECT s.sku_code, s.tracking_mode,
       (SELECT COALESCE(SUM(sm.qty_delta),0) FROM stock_movements sm WHERE sm.sku_id = s.id AND sm.movement_type='OPENING') AS opening_qty,
       (SELECT COUNT(*) FROM item_instances i WHERE i.sku_id = s.id) AS item_count
  FROM skus s WHERE s.sku_code LIKE 'HN-%-310-%' ORDER BY 1;
\echo == audit MIGRATION_* ==
SELECT action, COUNT(*) FROM audit_logs WHERE action LIKE 'MIGRATION%' GROUP BY 1 ORDER BY 1;
\echo == audit id cua tung buoc ==
SELECT id, action, created_at FROM audit_logs WHERE action LIKE 'MIGRATION%' ORDER BY created_at;
