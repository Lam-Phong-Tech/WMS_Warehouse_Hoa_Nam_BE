\echo '--- A. Bon bang stocktake_* co ton tai o END-STATE khong? (ky vong: 0 dong)'
SELECT table_name FROM information_schema.tables
 WHERE table_schema='public' AND table_name IN ('stocktake_session','stocktake_snapshot','stocktake_count','stocktake_variance');
\echo '--- B. stock_movements con cot stocktake_variance_id khong? (ky vong: 0 dong)'
SELECT column_name FROM information_schema.columns
 WHERE table_schema='public' AND table_name='stock_movements' AND column_name LIKE '%stocktake%';
\echo '--- C. Rang buoc movement_type hien hanh (ky vong: chi 5 gia tri canonical)'
SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conname='chk_stock_movement_type_current';
\echo '--- D. Trigger append-only tren stock_movements (ky vong: co)'
SELECT tgname FROM pg_trigger WHERE NOT tgisinternal AND tgrelid='stock_movements'::regclass ORDER BY tgname;
\echo ''
\echo '=== E. THU GHI TON TRUC TIEP. Toan bo nam trong BEGIN...ROLLBACK, khong de lai du lieu. ==='
\echo '=== E. THU GHI TON TRUC TIEP. Moi buoc boc SAVEPOINT nen mot loi khong abort ca transaction. ==='
\echo '=== Toan bo nam trong BEGIN...ROLLBACK: khong de lai du lieu. ==='
BEGIN;
\echo '--- E1 (CONTROL): payload y het, movement_type canonical REVERSAL -> ky vong INSERT 0 1'
SAVEPOINT s1;
INSERT INTO stock_movements (id, movement_no, business_key, movement_type, warehouse_id, product_id,
       stock_status, qty_before, qty_delta, qty_after, created_by, occurred_at, created_at, quantity)
SELECT gen_random_uuid(), 'PROBE-233-CTRL', 'PROBE-233-CTRL', 'REVERSAL', warehouse_id, product_id,
       stock_status, qty_after, 1, qty_after + 1, created_by, now(), now(), 1
  FROM stock_movements ORDER BY created_at LIMIT 1;
ROLLBACK TO SAVEPOINT s1;
\echo '--- E2 (BIEN DUY NHAT = movement_type): ADJUST_IN -> ky vong BI CHECK CONSTRAINT TU CHOI'
SAVEPOINT s2;
INSERT INTO stock_movements (id, movement_no, business_key, movement_type, warehouse_id, product_id,
       stock_status, qty_before, qty_delta, qty_after, created_by, occurred_at, created_at, quantity)
SELECT gen_random_uuid(), 'PROBE-233-ADJIN', 'PROBE-233-ADJIN', 'ADJUST_IN', warehouse_id, product_id,
       stock_status, qty_after, 1, qty_after + 1, created_by, now(), now(), 1
  FROM stock_movements ORDER BY created_at LIMIT 1;
ROLLBACK TO SAVEPOINT s2;
\echo '--- E3: ADJUST_OUT -> ky vong BI TU CHOI'
SAVEPOINT s3;
INSERT INTO stock_movements (id, movement_no, business_key, movement_type, warehouse_id, product_id,
       stock_status, qty_before, qty_delta, qty_after, created_by, occurred_at, created_at, quantity)
SELECT gen_random_uuid(), 'PROBE-233-ADJOUT', 'PROBE-233-ADJOUT', 'ADJUST_OUT', warehouse_id, product_id,
       stock_status, qty_after, 1, qty_after + 1, created_by, now(), now(), 1
  FROM stock_movements ORDER BY created_at LIMIT 1;
ROLLBACK TO SAVEPOINT s3;
\echo '--- E4: STOCKTAKE -> ky vong BI TU CHOI'
SAVEPOINT s4;
INSERT INTO stock_movements (id, movement_no, business_key, movement_type, warehouse_id, product_id,
       stock_status, qty_before, qty_delta, qty_after, created_by, occurred_at, created_at, quantity)
SELECT gen_random_uuid(), 'PROBE-233-STK', 'PROBE-233-STK', 'STOCKTAKE', warehouse_id, product_id,
       stock_status, qty_after, 1, qty_after + 1, created_by, now(), now(), 1
  FROM stock_movements ORDER BY created_at LIMIT 1;
ROLLBACK TO SAVEPOINT s4;
\echo '--- E5: COUNT -> ky vong BI TU CHOI'
SAVEPOINT s5;
INSERT INTO stock_movements (id, movement_no, business_key, movement_type, warehouse_id, product_id,
       stock_status, qty_before, qty_delta, qty_after, created_by, occurred_at, created_at, quantity)
SELECT gen_random_uuid(), 'PROBE-233-COUNT', 'PROBE-233-COUNT', 'COUNT', warehouse_id, product_id,
       stock_status, qty_after, 1, qty_after + 1, created_by, now(), now(), 1
  FROM stock_movements ORDER BY created_at LIMIT 1;
ROLLBACK TO SAVEPOINT s5;
ROLLBACK;
\echo '--- F. Sau ROLLBACK: khong con dau vet probe nao (ky vong: 0 dong)'
SELECT movement_no FROM stock_movements WHERE movement_no LIKE 'PROBE-233-%';
\echo '--- G. Doi chieu movement_type dang ton tai trong du lieu'
SELECT movement_type, count(*) FROM stock_movements GROUP BY movement_type ORDER BY 1;
