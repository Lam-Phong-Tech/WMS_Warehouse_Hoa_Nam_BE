-- WWHN-239 / WWHN-241 · Bộ truy vấn đối soát Reversal trên PostgreSQL 16.
--
-- Dùng cho DEV239-P1-03 (chứng minh schema/immutability/no-negative/append-only/
-- reconcile) và DEV239-P1-04 (QA1 phải nộp kèm output thật của 6 truy vấn dưới đây,
-- chụp SAU khi chạy hết kịch bản trên đúng exact build).
--
-- Quy ước đọc kết quả: MỌI truy vấn phải trả về 0 dòng, TRỪ Q6 (bảng đối soát tồn) —
-- Q6 phải trả 0 dòng ở cột lệch. Bất kỳ dòng nào xuất hiện đều là FAIL, không phải
-- "cảnh báo".
--
-- Ghi chú tên bảng: trên PostgreSQL bảng vật lý là tên SỐ NHIỀU
-- (reversal_documents / reversal_lines / app_users); tên số ít là VIEW tương thích do
-- migration 2026_08_10_000791 tạo. Trên sqlite thì ngược lại. Các truy vấn dưới đây
-- viết cho PostgreSQL — đúng DB tham chiếu của dự án.

-- ---------------------------------------------------------------------------
-- Q1. DUPLICATE MOVEMENT = 0 (grain ITEM).
-- Mỗi serial vật lý chỉ được đảo đúng một lần trên một movement gốc. Đây là bất biến
-- mà WWHN-239 mở (defect D-239-01) và unique index
-- ux_reversal_line_posted_item_per_movement bảo vệ ở tầng DB.
-- ---------------------------------------------------------------------------
SELECT rl.original_movement_id,
       rl.item_id,
       COUNT(*) AS posted_reversal_lines
FROM reversal_lines rl
WHERE rl.item_id IS NOT NULL
  AND rl.posted_movement_id IS NOT NULL
GROUP BY rl.original_movement_id, rl.item_id
HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------------------
-- Q2. DUPLICATE MOVEMENT = 0 (grain LEDGER).
-- Hai movement REVERSAL trở lên cho cùng một item là dấu hiệu double-reverse lọt lưới
-- kể cả khi bảng reversal_lines đã sạch.
-- ---------------------------------------------------------------------------
SELECT mv.item_id,
       mv.original_movement_id,
       COUNT(*) AS reversal_movements
FROM stock_movements mv
WHERE mv.movement_type = 'REVERSAL'
  AND mv.item_id IS NOT NULL
GROUP BY mv.item_id, mv.original_movement_id
HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------------------
-- Q3. ORPHAN = 0.
-- 3a: movement REVERSAL không trỏ về movement gốc còn tồn tại.
-- 3b: reversal document POSTED nhưng có dòng chưa sinh movement đối ứng.
-- 3c: movement REVERSAL không trỏ về reversal document nào.
-- ---------------------------------------------------------------------------
SELECT 'Q3a_orphan_reversal_movement' AS check_name, mv.id AS offending_id
FROM stock_movements mv
         LEFT JOIN stock_movements origin ON origin.id = mv.original_movement_id
WHERE mv.movement_type = 'REVERSAL'
  AND (mv.original_movement_id IS NULL OR origin.id IS NULL)
UNION ALL
SELECT 'Q3b_posted_line_without_movement', rl.id
FROM reversal_lines rl
         JOIN reversal_documents rd ON rd.id = rl.reversal_document_id
WHERE rd.status = 'POSTED'
  AND rl.posted_movement_id IS NULL
UNION ALL
SELECT 'Q3c_reversal_movement_without_document', mv.id
FROM stock_movements mv
         LEFT JOIN reversal_documents rd ON rd.id = mv.source_id
WHERE mv.movement_type = 'REVERSAL'
  AND rd.id IS NULL;

-- ---------------------------------------------------------------------------
-- Q4. APPEND-ONLY / IMMUTABILITY.
-- 4a: reversal không được vượt lượng gốc theo từng movement (BR-REV-05).
-- 4b: không được reverse trực tiếp một movement REVERSAL (BR-REV-08).
-- 4c: document gốc phải giữ nguyên POSTED sau khi bị đảo.
-- ---------------------------------------------------------------------------
SELECT 'Q4a_over_reversed_movement' AS check_name, origin.id AS offending_id
FROM stock_movements origin
         JOIN (SELECT rl.original_movement_id, SUM(COALESCE(rl.quantity_to_reverse, rl.quantity)) AS reversed_qty
               FROM reversal_lines rl
                        JOIN reversal_documents rd ON rd.id = rl.reversal_document_id
               WHERE rd.status = 'POSTED'
               GROUP BY rl.original_movement_id) agg ON agg.original_movement_id = origin.id
WHERE agg.reversed_qty > ABS(origin.quantity)
UNION ALL
SELECT 'Q4b_reversal_of_reversal', rl.id
FROM reversal_lines rl
         JOIN stock_movements origin ON origin.id = rl.original_movement_id
WHERE origin.movement_type NOT IN ('RECEIPT', 'ISSUE')
UNION ALL
SELECT 'Q4c_original_document_not_posted', sd.id
FROM stock_documents sd
WHERE sd.id IN (SELECT rd.original_document_id FROM reversal_documents rd WHERE rd.status = 'POSTED')
  AND sd.status <> 'POSTED';

-- ---------------------------------------------------------------------------
-- Q5. NO-NEGATIVE.
-- Không bucket tồn nào được âm, và không movement nào để lại qty_after âm.
-- ---------------------------------------------------------------------------
SELECT 'Q5a_negative_balance' AS check_name, ib.id AS offending_id
FROM inventory_balances ib
WHERE ib.quantity < 0
   OR ib.on_hand_qty < 0
   OR ib.available_qty < 0
UNION ALL
SELECT 'Q5b_negative_qty_after', mv.id
FROM stock_movements mv
WHERE mv.qty_after < 0;

-- ---------------------------------------------------------------------------
-- Q6. LEDGER = BALANCE.
-- Tồn hiện hành phải bằng tổng qty_delta CÓ DẤU của ledger theo từng bucket
-- (warehouse, sku, stock_status). Truy vấn trả về CHỈ các bucket lệch → phải rỗng.
-- ---------------------------------------------------------------------------
SELECT COALESCE(b.warehouse_id, l.warehouse_id)   AS warehouse_id,
       COALESCE(b.sku_id, l.sku_id)               AS sku_id,
       COALESCE(b.stock_status, l.stock_status)   AS stock_status,
       COALESCE(b.quantity, 0)                    AS balance_qty,
       COALESCE(l.ledger_qty, 0)                  AS ledger_qty,
       COALESCE(b.quantity, 0) - COALESCE(l.ledger_qty, 0) AS delta
FROM (SELECT warehouse_id, sku_id, stock_status, SUM(quantity) AS quantity
      FROM inventory_balances
      GROUP BY warehouse_id, sku_id, stock_status) b
         FULL OUTER JOIN
     (SELECT warehouse_id, sku_id, stock_status, SUM(qty_delta) AS ledger_qty
      FROM stock_movements
      GROUP BY warehouse_id, sku_id, stock_status) l
     ON l.warehouse_id = b.warehouse_id
         AND l.sku_id = b.sku_id
         AND l.stock_status = b.stock_status
WHERE COALESCE(b.quantity, 0) <> COALESCE(l.ledger_qty, 0);

-- ---------------------------------------------------------------------------
-- Q7. Bằng chứng guard đã tồn tại ở tầng DB (không phải chỉ ở tầng ứng dụng).
-- Phải trả về ĐÚNG 1 dòng: unique index theo item của WWHN-239.
-- ---------------------------------------------------------------------------
SELECT indexname, indexdef
FROM pg_indexes
WHERE tablename = 'reversal_lines'
  AND indexname = 'ux_reversal_line_posted_item_per_movement';
