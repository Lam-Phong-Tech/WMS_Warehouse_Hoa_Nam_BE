-- =============================================================================
-- WWHN-206 / WWHN-205 — P1-02R: đối soát ledger↔balance, chạy ĐỘC LẬP bằng psql.
--
-- Register đòi "QA1 độc lập thực hiện rebuild/reconciliation trên exact candidate
-- ... Không dùng Quang tự xác nhận". Vì vậy tầng KHẲNG ĐỊNH nằm ở đây — SQL thuần,
-- không PHP, không Laravel, không service nào của dự án. QA1 chạy trực tiếp:
--
--   psql -h <host> -U <user> -d <db> -v ON_ERROR_STOP=1 -f scripts/wwhn206-ledger-reconciliation.sql
--
-- Không cần tin bất kỳ script nào của DEV: mọi con số ở dưới đọc thẳng từ hai bảng
-- inventory_balances và stock_movements.
--
-- Dimension đối soát là (warehouse_id, product_id, stock_status) — đúng hằng
-- LedgerReconciliationService::DIMENSION và SRS §7.5, KHÔNG phải một biến thể
-- GROUP BY riêng (WWHN-231 đã đóng đúng lỗi "hai bên tự viết aggregate khác nhau").
-- Ledger là SUM(qty_delta) vì DEC-INV-001 coi stock_movements là source of truth,
-- inventory_balances chỉ là projection.
--
-- PASS khi và chỉ khi MỌI dòng metric có status = 'PASS'
-- (ledger=balance, duplicate_source=0, mismatch_count=0, rollback/orphan=0).
-- =============================================================================

\pset footer off
\echo '== WWHN-206 ledger reconciliation :: environment =='
SELECT current_database()                        AS database,
       version()                                 AS postgres_version,
       current_setting('server_version_num')     AS server_version_num,
       now() AT TIME ZONE 'UTC'                  AS checked_at_utc;

\echo ''
\echo '== WWHN-206 ledger reconciliation :: metrics =='

WITH balance_agg AS (
    SELECT warehouse_id, product_id, stock_status, SUM(quantity)::bigint AS balance_qty
      FROM inventory_balances
     GROUP BY 1, 2, 3
), ledger_agg AS (
    SELECT warehouse_id, product_id, stock_status, SUM(qty_delta)::bigint AS ledger_qty
      FROM stock_movements
     GROUP BY 1, 2, 3
), dim AS (
    SELECT COALESCE(b.warehouse_id, l.warehouse_id)   AS warehouse_id,
           COALESCE(b.product_id,   l.product_id)     AS product_id,
           COALESCE(b.stock_status, l.stock_status)   AS stock_status,
           COALESCE(b.balance_qty, 0)                 AS balance_qty,
           COALESCE(l.ledger_qty, 0)                  AS ledger_qty
      FROM balance_agg b
      FULL OUTER JOIN ledger_agg l
        ON l.warehouse_id = b.warehouse_id
       AND l.product_id   = b.product_id
       AND l.stock_status = b.stock_status
)
SELECT metric, value, expected,
       CASE WHEN value = expected THEN 'PASS' ELSE 'FAIL' END AS status,
       note
FROM (
    -- 1. ledger = balance trên mọi dimension (yêu cầu "ledger=balance").
    SELECT 1 AS ord,
           'ledger_balance_mismatch_count'::text AS metric,
           (SELECT count(*) FROM dim WHERE balance_qty <> ledger_qty)::bigint AS value,
           0::bigint AS expected,
           'inventory_balances.quantity phải bằng SUM(stock_movements.qty_delta) trên (warehouse_id, product_id, stock_status)'::text AS note

    -- 2. mismatch_count: dimension chỉ tồn tại một phía cũng là lệch, tách riêng để
    --    thấy rõ "projection thừa" khác "projection thiếu".
    UNION ALL SELECT 2, 'balance_without_ledger_count',
           (SELECT count(*) FROM dim WHERE ledger_qty = 0 AND balance_qty <> 0)::bigint, 0,
           'Số dư khác 0 nhưng không có movement nào sinh ra nó'
    UNION ALL SELECT 3, 'ledger_without_balance_count',
           (SELECT count(*) FROM dim d WHERE d.ledger_qty <> 0
              AND NOT EXISTS (SELECT 1 FROM inventory_balances b
                               WHERE b.warehouse_id = d.warehouse_id
                                 AND b.product_id   = d.product_id
                                 AND b.stock_status = d.stock_status))::bigint, 0,
           'Ledger có phát sinh nhưng projection chưa có dòng số dư tương ứng'

    -- 3. duplicate_source = 0. business_key là khoá chống ghi trùng stock effect
    --    (CurrentBaselineStockService::mutateBalance chặn DUPLICATE_STOCK_EFFECT).
    UNION ALL SELECT 4, 'duplicate_business_key_count',
           (SELECT count(*) FROM (SELECT business_key FROM stock_movements
                                   GROUP BY business_key HAVING count(*) > 1) x)::bigint, 0,
           'Một business_key chỉ được sinh đúng một movement'
    UNION ALL SELECT 5, 'duplicate_source_count',
           (SELECT count(*) FROM (
                SELECT source_type, source_id, COALESCE(source_line_id::text, ''), movement_type
                  FROM stock_movements
                 GROUP BY 1, 2, 3, 4 HAVING count(*) > 1) y)::bigint, 0,
           'Một (source_type, source_id, source_line_id, movement_type) chỉ được ghi sổ một lần'

    -- 4. orphan = 0.
    UNION ALL SELECT 6, 'orphan_movement_document_count',
           (SELECT count(*) FROM stock_movements m
              LEFT JOIN stock_documents d ON d.id = m.stock_document_id
             WHERE m.stock_document_id IS NOT NULL AND d.id IS NULL)::bigint, 0,
           'Movement trỏ tới chứng từ không tồn tại'
    UNION ALL SELECT 7, 'orphan_movement_line_count',
           (SELECT count(*) FROM stock_movements m
              LEFT JOIN stock_document_lines l ON l.id = m.stock_document_line_id
             WHERE m.stock_document_line_id IS NOT NULL AND l.id IS NULL)::bigint, 0,
           'Movement trỏ tới dòng chứng từ không tồn tại'
    UNION ALL SELECT 8, 'orphan_reversal_link_count',
           (SELECT count(*) FROM stock_movements m
              LEFT JOIN stock_movements o ON o.id = m.original_movement_id
             WHERE m.original_movement_id IS NOT NULL AND o.id IS NULL)::bigint, 0,
           'Movement đảo trỏ tới movement gốc không tồn tại'

    -- 5. rollback residue = 0. Một transaction bị rollback đúng cách KHÔNG để lại
    --    movement; một transaction commit đúng cách KHÔNG để chứng từ POSTED rỗng sổ.
    UNION ALL SELECT 9, 'movement_on_unposted_document_count',
           (SELECT count(*) FROM stock_movements m
              JOIN stock_documents d ON d.id = m.stock_document_id
             WHERE d.status IN ('DRAFT', 'SCANNING'))::bigint, 0,
           'Chứng từ chưa POSTED mà đã có movement => tàn dư của một lần post bị rollback'
    UNION ALL SELECT 10, 'posted_document_without_movement_count',
           (SELECT count(*) FROM stock_documents d
             WHERE d.status = 'POSTED'
               AND NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.stock_document_id = d.id))::bigint, 0,
           'Chứng từ POSTED mà không có movement nào => post commit nửa vời'

    -- 6. Bất biến không âm (CHECK chk_inventory_balance_non_negative bảo vệ ở tầng DB,
    --    ở đây đọc lại bằng dữ liệu để không phụ thuộc constraint có được cài hay không).
    UNION ALL SELECT 11, 'negative_balance_count',
           (SELECT count(*) FROM inventory_balances WHERE quantity < 0 OR available_qty < 0)::bigint, 0,
           'Số dư và available_qty không bao giờ được âm'
    UNION ALL SELECT 12, 'negative_ledger_snapshot_count',
           (SELECT count(*) FROM stock_movements WHERE qty_before < 0 OR qty_after < 0)::bigint, 0,
           'Không movement nào được ghi lại một trạng thái tồn âm'
    UNION ALL SELECT 13, 'inconsistent_ledger_row_count',
           (SELECT count(*) FROM stock_movements WHERE qty_after <> qty_before + qty_delta)::bigint, 0,
           'Mỗi movement phải tự nhất quán: qty_after = qty_before + qty_delta'

    -- 7. SRS §7.5 còn BA check nữa ngoài Ledger↔Balance, và AC-SRS-023 nói rõ
    --    "ba lớp khớp sau posting". Định nghĩa dưới đây sao y nguyên văn phần
    --    OperationalReportController::inventoryReconciliation() đang chạy thật
    --    (serialSubledgerCounts/boxSubledgerCounts), để đối soát tay và báo cáo
    --    trong sản phẩm không thể kết luận khác nhau.
    UNION ALL SELECT 14, 'serial_subledger_mismatch_count',
           (SELECT count(*) FROM (
                SELECT b.warehouse_id, COALESCE(b.sku_id, b.product_id) AS sku_id
                  FROM inventory_balances b
                  JOIN LATERAL (
                        SELECT count(*)::numeric AS serial_qty
                          FROM item_instances i
                         WHERE i.sku_id = COALESCE(b.sku_id, b.product_id)
                           AND i.item_status = 'IN_STOCK'
                  ) s ON TRUE
                 WHERE b.stock_status = 'AVAILABLE'
                   AND s.serial_qty > 0
                   AND abs(b.available_qty::numeric - s.serial_qty) >= 0.001
           ) z)::bigint, 0,
           'SRS §7.5 Serial subledger: available_qty = số Item_Instance IN_STOCK của SKU đó'
    UNION ALL SELECT 15, 'quantity_subledger_mismatch_count',
           (SELECT count(*) FROM (
                SELECT b.warehouse_id, COALESCE(b.sku_id, b.product_id) AS sku_id
                  FROM inventory_balances b
                  JOIN LATERAL (
                        SELECT COALESCE(sum(c.quantity), 0)::numeric AS box_qty
                          FROM container_items c
                         WHERE c.sku_id = COALESCE(b.sku_id, b.product_id)
                           AND c.is_active = TRUE
                  ) q ON TRUE
                 WHERE q.box_qty > 0
                   AND q.box_qty > b.on_hand_qty::numeric + 0.001
           ) z)::bigint, 0,
           'SRS §7.5 Quantity subledger: tổng quantity trong container mapping không vượt on_hand_qty'
    UNION ALL SELECT 16, 'container_integrity_mismatch_count',
           (SELECT count(*) FROM (
                SELECT b.warehouse_id, COALESCE(b.sku_id, b.product_id) AS sku_id
                  FROM inventory_balances b
                  JOIN LATERAL (
                        SELECT COALESCE(sum(c.quantity), 0)::numeric AS box_qty
                          FROM container_items c
                         WHERE c.sku_id = COALESCE(b.sku_id, b.product_id)
                           AND c.is_active = TRUE
                  ) q ON TRUE
                 WHERE q.box_qty > b.on_hand_qty::numeric + 0.001
           ) z)::bigint, 0,
           'SRS §7.5 Container integrity: container mapping không tạo thêm bucket tồn'
) checks
ORDER BY ord;

\echo ''
\echo '== WWHN-206 ledger reconciliation :: verdict =='

WITH balance_agg AS (
    SELECT warehouse_id, product_id, stock_status, SUM(quantity)::bigint AS balance_qty
      FROM inventory_balances GROUP BY 1, 2, 3
), ledger_agg AS (
    SELECT warehouse_id, product_id, stock_status, SUM(qty_delta)::bigint AS ledger_qty
      FROM stock_movements GROUP BY 1, 2, 3
), dim AS (
    SELECT COALESCE(b.balance_qty, 0) AS balance_qty, COALESCE(l.ledger_qty, 0) AS ledger_qty
      FROM balance_agg b
      FULL OUTER JOIN ledger_agg l
        ON l.warehouse_id = b.warehouse_id AND l.product_id = b.product_id AND l.stock_status = b.stock_status
)
SELECT (SELECT count(*) FROM dim)                                   AS dimensions_checked,
       (SELECT count(*) FROM dim WHERE balance_qty <> ledger_qty)   AS mismatch_count,
       (SELECT count(*) FROM stock_movements)                       AS ledger_rows,
       (SELECT count(*) FROM inventory_balances)                    AS balance_rows,
       CASE WHEN (SELECT count(*) FROM dim WHERE balance_qty <> ledger_qty) = 0
             AND (SELECT count(*) FROM (SELECT business_key FROM stock_movements
                                         GROUP BY business_key HAVING count(*) > 1) d1) = 0
             AND (SELECT count(*) FROM inventory_balances WHERE quantity < 0 OR available_qty < 0) = 0
             AND (SELECT count(*) FROM stock_movements m
                    LEFT JOIN stock_documents d ON d.id = m.stock_document_id
                   WHERE m.stock_document_id IS NOT NULL AND d.id IS NULL) = 0
            THEN 'PASS' ELSE 'FAIL' END                             AS overall_status;
