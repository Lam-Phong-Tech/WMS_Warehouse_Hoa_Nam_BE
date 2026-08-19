-- WWHN-202 / WWHN-203 · Bộ truy vấn đối soát "Balance hỗ trợ" trên PostgreSQL 16.
--
-- Dùng cho DEV202-P1-02R2 (QA1 retest phải nộp kèm output thật của 6 truy vấn dưới đây,
-- chụp TRƯỚC và SAU mỗi bước đổi tồn trên đúng exact build) và DEV202-P1-03R2
-- (chứng minh mismatch = 0 trên PostgreSQL, không phải chỉ trên sqlite).
--
-- QUY ƯỚC ĐỌC KẾT QUẢ: MỌI truy vấn phải trả về **0 dòng**. Một dòng bất kỳ = FAIL,
-- không phải "cảnh báo". Không truy vấn nào ở đây được sửa dữ liệu.
--
-- Ghi chú tên bảng: trên PostgreSQL bảng vật lý là tên SỐ NHIỀU (app_users,
-- reversal_documents, scan_events...); tên số ít là VIEW tương thích do migration
-- 2026_08_10_000791 tạo. Trên sqlite thì NGƯỢC LẠI. File này viết cho PostgreSQL —
-- đúng DB tham chiếu của dự án theo Techstack/Dev Guide.

-- ---------------------------------------------------------------------------
-- Q1. LEDGER ≡ BALANCE = 0 dòng lệch.
-- Bất biến cốt lõi DEC-INV-001: stock_movements là ledger authoritative,
-- inventory_balances chỉ là projection. Đây chính là công thức mà
-- GET /reports/inventory-reconciliation công bố ở trường `formula`.
-- ---------------------------------------------------------------------------
SELECT b.warehouse_id,
       b.sku_id,
       b.stock_status,
       b.on_hand_qty,
       COALESCE(m.ledger_total, 0) AS ledger_total,
       b.on_hand_qty - COALESCE(m.ledger_total, 0) AS variance
FROM inventory_balances b
LEFT JOIN (
    SELECT warehouse_id, sku_id, stock_status, SUM(qty_delta) AS ledger_total
    FROM stock_movements
    GROUP BY warehouse_id, sku_id, stock_status
) m
  ON m.warehouse_id = b.warehouse_id
 AND m.sku_id       = b.sku_id
 AND m.stock_status = b.stock_status
WHERE b.on_hand_qty - COALESCE(m.ledger_total, 0) <> 0;

-- ---------------------------------------------------------------------------
-- Q2. KHÔNG ÂM TỒN = 0 dòng.
-- AC-SRS-011 / AC-GWT-OUT-001: Post Issue không bao giờ được để lại balance âm,
-- kể cả khi bị guard chặn giữa chừng (transaction phải rollback nguyên vẹn).
-- ---------------------------------------------------------------------------
SELECT id, warehouse_id, sku_id, stock_status, on_hand_qty, available_qty, quantity
FROM inventory_balances
WHERE on_hand_qty < 0
   OR available_qty < 0
   OR quantity < 0;

-- ---------------------------------------------------------------------------
-- Q3. BUSINESS KEY DUY NHẤT = 0 dòng.
-- mutateBalance() dựa vào business_key để chặn ghi trùng stock effect (409
-- DUPLICATE_STOCK_EFFECT — xem DEC-STK-202 §D-1 hàng 7). Nếu truy vấn này trả về
-- dòng nào thì guard đã bị vượt và tồn đã bị trừ hai lần.
-- ---------------------------------------------------------------------------
SELECT business_key, COUNT(*) AS movements
FROM stock_movements
WHERE business_key IS NOT NULL
GROUP BY business_key
HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------------------
-- Q4. IDEMPOTENT POST = 0 dòng.
-- REQ-SRS-012: một document chỉ được sinh đúng một movement cho mỗi line, dù
-- client replay bao nhiêu lần cùng Idempotency-Key.
-- ---------------------------------------------------------------------------
SELECT stock_document_id, stock_document_line_id, movement_type, COUNT(*) AS movements
FROM stock_movements
WHERE stock_document_id IS NOT NULL
  AND stock_document_line_id IS NOT NULL
  AND movement_type IN ('RECEIPT', 'ISSUE', 'COMPONENT_ISSUE')
GROUP BY stock_document_id, stock_document_line_id, movement_type
HAVING COUNT(*) > 1;

-- ---------------------------------------------------------------------------
-- Q5. ITEM ATTRIBUTION ĐÚNG DEC-STK-202 = 0 dòng.
-- Quy tắc: line có ĐÚNG 1 item thì movement.item_id phải trỏ item đó; line gộp
-- nhiều item thì item_id phải NULL. Truy vấn bắt vế thứ nhất bị bỏ sót — trường hợp
-- mà evidence ATT-11845 cũ mô tả sai là "luôn NULL".
-- ---------------------------------------------------------------------------
SELECT mv.id AS movement_id,
       mv.stock_document_line_id,
       mv.item_id,
       x.matched_items
FROM stock_movements mv
JOIN (
    SELECT outbound_line_id AS line_id, COUNT(DISTINCT item_id) AS matched_items
    FROM outbound_item_matches
    WHERE item_id IS NOT NULL
      AND (status IS NULL OR status <> 'REMOVED')
    GROUP BY outbound_line_id
) x ON x.line_id = mv.stock_document_line_id
WHERE mv.movement_type = 'ISSUE'
  AND (
        (x.matched_items = 1 AND mv.item_id IS NULL)
     OR (x.matched_items > 1 AND mv.item_id IS NOT NULL)
      );

-- ---------------------------------------------------------------------------
-- Q6. SUBLEDGER SERIAL ≡ BALANCE = 0 dòng.
-- §7.5 check "serial_subledger": với chiều serial-tracked AVAILABLE, số item
-- IN_STOCK phải bằng available_qty. Đây là check mà /reports/inventory-reconciliation
-- công bố ở `checks[1]`; chạy tay để không phải tin chính API đang được nghiệm thu.
-- ---------------------------------------------------------------------------
SELECT b.warehouse_id,
       b.sku_id,
       b.available_qty,
       COALESCE(i.in_stock_items, 0) AS in_stock_items
FROM inventory_balances b
LEFT JOIN (
    SELECT sku_id, COUNT(*) AS in_stock_items
    FROM item_instances
    WHERE item_status = 'IN_STOCK'
    GROUP BY sku_id
) i ON i.sku_id = b.sku_id
WHERE b.stock_status = 'AVAILABLE'
  AND COALESCE(i.in_stock_items, 0) > 0
  AND b.available_qty <> COALESCE(i.in_stock_items, 0);
