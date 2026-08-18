# WWHN-231 / DEV231-P1-03 — Benchmark truy vấn aggregate ledger

| Field | Value |
|---|---|
| Task | `WWHN-231` |
| Register item | `DEV231-P1-03` |
| Script tái lập | `node scripts/wwhn231-ledger-benchmark.mjs` |
| Raw plans | `docs/evidence/WWHN-231/plans/A..J*.txt` |
| Machine-readable | `docs/evidence/WWHN-231/BENCHMARK_RESULT.json` |
| PostgreSQL | `16.14` (container `wms_warehouse_hoa_nam_be-postgres-1`, image Alpine) |
| Database đo | `wwhn231_bench` — tạo mới mỗi lần chạy, tách hoàn toàn khỏi dữ liệu khác |
| NFR neo | SRS §13.7 "Read/list API page_size≤50: P95 ≤2s; P99 ≤4s" |

## 1. Query được pin

Hai hình dạng dưới đây là **nguyên văn** SQL mà hai phiên bản code sinh ra.

### 1.1. `legacy` — hình dạng trước WWHN-231

Join `warehouses` / `products` / `product_categories` **trước** `GROUP BY`, và vì thế phải gom
theo 8 cột (4 trong đó là text):

```sql
SELECT m.warehouse_id, m.product_id, m.stock_status,
       w.code AS warehouse_code, p.code AS product_code, p.name AS product_name,
       p.category_id, pc.category_name,
       SUM(m.qty_delta) AS ledger_qty, MAX(m.occurred_at) AS movement_updated_at
FROM stock_movements m
LEFT JOIN warehouses w ON w.id = m.warehouse_id
LEFT JOIN products p ON p.id = m.product_id
LEFT JOIN product_categories pc ON pc.id = p.category_id
GROUP BY m.warehouse_id, m.product_id, m.stock_status, w.code, p.code, p.name, p.category_id, pc.category_name;
```

### 1.2. `optimized` — hình dạng sau WWHN-231 (`LedgerReconciliationService`)

Gom trên riêng `stock_movements` theo đúng 3 cột dimension của §7.5, rồi mới join master data
lên tập đã gom:

```sql
SELECT agg.warehouse_id, agg.product_id, agg.stock_status, agg.ledger_qty, agg.movement_updated_at,
       w.code AS warehouse_code, p.code AS product_code, p.name AS product_name,
       p.category_id, pc.category_name
FROM (
    SELECT warehouse_id, product_id, stock_status,
           SUM(qty_delta) AS ledger_qty, MAX(occurred_at) AS movement_updated_at
    FROM stock_movements
    GROUP BY warehouse_id, product_id, stock_status
) agg
LEFT JOIN warehouses w ON w.id = agg.warehouse_id
LEFT JOIN products p ON p.id = agg.product_id
LEFT JOIN product_categories pc ON pc.id = p.category_id;
```

Quan hệ `id → code/name` là hàm, nên hai hình dạng cho **đúng cùng tập dòng**; điều này được
khoá bằng test chứ không chỉ bằng lập luận:
`Wwhn231StockNeutralLedgerTest::test_optimized_ledger_aggregate_matches_legacy_group_by_shape`.

### 1.3. Biến thể có filter và biến thể XNT

Báo cáo thật luôn chạy trong data-scope kho và thường lọc thêm theo SKU, nên mỗi hình dạng
được đo thêm với `WHERE warehouse_id = … AND product_id = …`.

Báo cáo Nhập-Xuất-Tồn (RPT-XNT-01, §13.10) mắc đúng cùng bệnh — bản cũ gom theo 6 cột trong đó
3 cột lấy từ master data — nên cũng được đo ở cả hai hình dạng (cấu hình I và J).

## 2. Dataset

| Thuộc tính | Giá trị |
|---|---|
| `stock_movements` | **600.000** dòng |
| `products` | 2.000 SKU, 20 nhóm hàng |
| `warehouses` | 1 (đúng theo `REQ-SRS-001` — MVP chỉ có 01 kho tổng) |
| `stock_status` | 4 giá trị (`AVAILABLE`/`NON_SELLABLE`/`DEFECTIVE`/`QUARANTINE`) |
| Số dimension sau gom | 2.000 dòng kết quả |
| Tính tất định | Khoá `md5(seq)` + `generate_series`, **không dùng `random()`** → chạy lại ra đúng cùng dữ liệu |
| Chuẩn bị trước đo | `VACUUM ANALYZE stock_movements` (nếu thiếu, Index Only Scan phải quay lại heap và phép so sánh mất công bằng) |
| Số lần đo | 5 lần / cấu hình, lấy **median** `Execution Time` từ `EXPLAIN (ANALYZE, BUFFERS)` |

## 3. Kết quả đo

| # | Cấu hình | Median exec | Blocks (`shared hit`) | Scan node | Aggregate node |
|---|---|---:|---:|---|---|
| A | RPT-INV-01 legacy, index baseline | **1897,6 ms** | 607.339 | Index Scan | GroupAggregate (Incremental Sort 600k dòng) |
| B | RPT-INV-01 optimized, index baseline | **88,7 ms** | 8.847 | Seq Scan (parallel) | HashAggregate |
| C | RPT-INV-01 legacy, + `idx_sm_ledger_dimension` | 1257,1 ms | 12.298 | Index Only Scan | GroupAggregate |
| D | RPT-INV-01 optimized, + `idx_sm_ledger_dimension` | 96,9 ms | 8.495 | Seq Scan (parallel) | HashAggregate |
| E | RPT-INV-01 legacy + filter SKU, index baseline | 1,881 ms | 310 | Index Scan | HashAggregate |
| F | RPT-INV-01 optimized + filter SKU, index baseline | 1,972 ms | 311 | Index Scan | HashAggregate |
| G | RPT-INV-01 legacy + filter SKU, + index mới | 0,704 ms | 12 | Index Only Scan | HashAggregate |
| H | RPT-INV-01 optimized + filter SKU, + index mới | **0,266 ms** | 13 | Index Only Scan | GroupAggregate |
| I | RPT-XNT-01 legacy | **1633,3 ms** | 610.293 | Index Scan | GroupAggregate |
| J | RPT-XNT-01 optimized | **152,9 ms** | 8.846 | Seq Scan (parallel) | HashAggregate |

Số câu truy vấn: **1 trước, 1 sau** cho mỗi lần dựng báo cáo — thay đổi nằm ở *hình dạng plan*,
không ở số lượt gọi. Điều này được khoá bằng
`Wwhn231StockNeutralLedgerTest::test_inventory_balance_report_issues_a_single_ledger_aggregate_query`
(đếm query chứa `SUM(qty_delta)` trong một lần gọi `/api/v1/reports/inventory-balance`).

### 3.1. Đọc kết quả

- **Thắng lợi chính đến từ hình dạng query, không từ index**: A → B là **×21,4** thời gian và
  **×69** số block đọc (607.339 → 8.847). Báo cáo XNT cùng biến đổi: I → J là **×10,7** và
  610.293 → 8.846 block. Nguyên nhân nằm ở plan: bản legacy phải
  `Incremental Sort` toàn bộ 600.000 dòng theo 8 khoá rồi `GroupAggregate`; bản optimized gom
  bằng `HashAggregate` song song trên 3 cột rồi chỉ join 2.000 dòng kết quả.
- **Index chỉ có tác dụng ở nhánh có filter**, và ở đó tác dụng rất rõ: F → H là **×7,4** thời
  gian và **311 → 13** block (×24). Đây chính là nhánh chạy thường xuyên nhất: mọi request báo
  cáo đều đi kèm data-scope kho, phần lớn kèm filter SKU.
- **Index không giúp nhánh gom toàn bảng**: B (88,7 ms) và D (96,9 ms) có plan giống hệt nhau
  (cùng cost, cùng Seq Scan song song) — chênh lệch nằm trong nhiễu đo. Với phép gom toàn bảng,
  Seq Scan song song rẻ hơn Index Only Scan nên planner bỏ qua index; đó là hành vi đúng.

## 4. Index decision

**Quyết định: giữ `idx_sm_ledger_dimension`** —
`(warehouse_id, product_id, stock_status) INCLUDE (qty_delta, occurred_at)`.

| Tiêu chí | Số liệu |
|---|---|
| Lợi ích | Nhánh đọc có filter: 1,972 ms → 0,266 ms; 311 → 13 block đọc |
| Không hại | Nhánh gom toàn bảng: planner giữ nguyên plan cũ (B ≡ D) |
| Chi phí dung lượng | 49 MB cho 600.000 dòng (~85 B/dòng); so sánh: `idx_sm_wh_product_time` 34 MB, PK 23 MB |
| Chi phí ghi | `stock_movements` là append-only và chỉ ghi ở Post Receipt / Post Issue / Reversal — một dòng cho mỗi action nghiệp vụ, không phải đường ghi nóng |
| Vì sao `INCLUDE` chứ không đưa vào khoá | `qty_delta`/`occurred_at` không dùng để lọc hay sắp xếp, chỉ để đọc — để trong payload giữ khoá B-tree gọn |

**Vì sao không phải index `(product_id, stock_status)`:** cột dẫn `warehouse_id` gần như không
chọn lọc (`REQ-SRS-001` — MVP đúng 01 kho), nhưng nó là cột đầu của dimension §7.5 và là cột mà
`EnforceInventoryWarehouseScope` luôn ràng buộc; giữ đúng thứ tự dimension khiến index phục vụ
được cả `GROUP BY` lẫn `WHERE warehouse_id = ?`. Khi nào hệ thống mở nhiều kho, thứ tự này vẫn
đúng — nên không cần đổi lại.

**Vì sao không dùng materialized view / bảng tổng hợp:** `inventory_balances` đã chính là
projection của ledger; thêm một lớp tổng hợp nữa sẽ tạo nguồn số thứ ba phải đối soát, đi ngược
`DEC-INV-001`.

## 5. Giới hạn của phép đo

- Dataset là synthetic và phân bố đều (mỗi SKU ~300 movement). Ledger thật có phân bố lệch; hình
  dạng plan không đổi nhưng con số tuyệt đối sẽ khác.
- Máy đo là môi trường dev (Docker Desktop trên Windows), có tiến trình khác chạy song song →
  thời gian tường có nhiễu. Vì vậy bảng §3 kèm `shared hit` blocks: đó là số block thực đọc,
  không phụ thuộc tải máy, và nó xác nhận cùng kết luận.
- Benchmark đo *truy vấn*, không đo toàn bộ endpoint (endpoint còn cache `report` TTL ngắn +
  serialize). Mức P95 của endpoint theo §13.7 phải được đo lại ở khâu load test release.
