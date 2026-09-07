# DATABASE_PERFORMANCE_REPORT — Hoà Nam WMS (07/09/2026)

> **Mức tin cậy.** Các phát hiện dưới đây do một luồng audit chỉ-đọc lập, có bằng
> chứng file:dòng / câu SQL / EXPLAIN. Vòng phản biện độc lập chỉ chạy được 1/42
> mục trước khi phiên chạm hạn mức, nên **trừ những mục ghi rõ "ĐÃ SỬA" (đã có số
> đo trước/sau), phần còn lại là CHƯA ĐƯỢC XÁC NHẬN ĐỘC LẬP** — hãy đọc như đầu
> mối điều tra, không phải kết luận.

## 1. Tổng quan

Database lens, read-only, on the live dev DB `wms_hoanam` (PG 16.14, 330 MB, 7,350 stock_movements / 4,549 item_instances / 3,162 physical_codes / 818 SKUs; perf compose defaults to this same DB, `wms_hoanam_perf` has zero activity). Tables are far too small for EXPLAIN to show production behaviour, so every plan was also run with `enable_seqscan=off` to prove whether ANY usable index exists; pg_stat counters (reset ~06/09, covering the k6 runs) were used as the per-request multiplier evidence. Headline: (1) `stock_movements` has no index leading with `occurred_at`, so the movement list, both dashboards, XNT, trace and all period KPIs are full scans + sort (1,411 seq scans, 10.4M tuples); (2) the public/PV catalog runs a correlated `inventory_balances` subquery filtered on `product_id`, for which no leading index exists — 8.9M seq scans / 2.6B tuple reads on a 293-row table plus 20.7M subplan probes from the `sku` view's `published_to_app`; (3) 189 of 594 indexes are exact duplicates (physical_codes 20 indexes/9 redundant, inventory_balances 10/4) and `inventory_balances.updated_at` is indexed, so every post is a non-HOT update across 10 btrees; (4) trace-by-code uses `LOWER(CAST(col))=?` with no expression index (cascading seq scans over 4 tables); (5) one global `stock:version` cache namespace is bumped by every movement, so under write load the heaviest read queries are effectively uncached; (6) reports fetch full result sets and paginate in PHP; (7) audit list orders by an md5-computed view column (unindexable seq scan + sort, uncached by design); (8) document/warranty lists sort on `created_at` without an index; (9) `inbound_lines`/`outbound_lines` are 99.7% bloat and have never been vacuumed; (10) posting lock order is sound (global SKU order, BitmapOr on two unique indexes) but locks are held through audit/posting-event inserts with no lock/idle timeouts. pg_stat_statements is preloaded but never created, so top-N statement stats are NOT MEASURED. Positives verified: exact-code scan path uses the unique index; repository lists order by UUIDv7 `id` via PK; notification unread-count, document-history date range, warranty status+received_at and warehouse+product movement lookups all hit good indexes; no N+1 in the paths read.

## 2. Kiểm kê bảng / chỉ mục / truy vấn

### Tables (pg_stat_user_tables / pg_class / pg_indexes, wms_hoanam, counters since last stats reset ≈ 06/09)

| Table | live rows | heap | index size | #indexes | redundant dup indexes | seq_scan | seq_tup_read | idx_scan | Hot path(s) |
|---|---|---|---|---|---|---|---|---|---|
| stock_movements | 7,350 | 2.4 MB | 3.3 MB | 10 | 0 | 1,411 | 10,370,850 | 7,625 | /stock-movements list, dashboards, XNT, trace, Post (insert) |
| inventory_balances | 293 | 80 kB | 392 kB | 10 | 4 ((wh,sku,status) ×4, id ×2) | **8,924,965** | **2,615,014,745** | 6,201,297 (6.2M on uk_wh_product_status) | /inventory list, catalog subquery, Post `FOR UPDATE` |
| pv_product_profiles | 818 | 136 kB | 376 kB | 10 | 5 (id ×3, sku_id ×3, seo_slug ×2) | 13,174 | 10,776,381 | **20,690,439** (uk_pv_product_profile_product) | `sku` view `published_to_app` subplan, PV catalog |
| skus (view `sku`, `products`) | 818 | 560 kB | 552 kB | 9 | 3 (id ×3, sku_code ×2) | 1,553 | 1,270,465 | 457,061 | /skus, /products, every `sku` join |
| item_instances | 4,549 | 1.3 MB | 3.4 MB | 14 | 3 (id, item_code, serial ×2 each) | 91 | 413,959 | 18,783 | scan, subledger, trace-by-code |
| physical_codes | 3,162 | 744 kB | 2.4 MB | **20** | **9** | 148 | 450,806 | 5,126 | scan resolve, trace-by-code |
| stock_documents | 374 | <1 MB | <1 MB | 7 | 0 | 27,120 | 10,142,880 | 10,092 | /inbound-, /outbound-documents lists, document-history |
| stock_document_lines | 4,328 | 688 kB | 648 kB | 5 | 0 | 9,147 | 39,588,216 | 126,579 | eager `with('lines')`, Post `FOR UPDATE` |
| warranty_cases (view `warranty_case`) | 186 | 136 kB | 208 kB | 13 | ≥2 (id ×3) | 13,828 | 2,572,008 | 504 | /warranty-cases list, dashboards |
| audit_logs (view `audit_log`) | 4,924 | 2.4 MB | 896 kB | 6 | 1 (id ×2) | 21 | 101,992 | 4,283 | /audit-logs (uncached by design) |
| scan_events | 5,010 | 2.1 MB | 1.7 MB | 7 | 2 (id ×3) | 42 | 210,420 | 0 | scan write path |
| notification_recipients | 17,195 | 1.4 MB | 2.7 MB | 4 | 0 | 0 | 0 | 2,646 | /notifications/unread-count (k6 weight 8) |
| app_users (view `app_user`) | 77 | 16 kB | ~150 kB | 9 | 4 (id ×3, email ×2, username ×2) | 263,923 | 15,406,712 | 250,277 | auth per request (planner prefers seq scan on 2 pages; not a finding at this size) |
| inbound_lines (legacy) | 2,923 | **146 MB** | 40 MB | 7 | 3 | 1 | 2,923 | 3 | none (legacy ERD table) |
| outbound_lines (legacy) | 1,405 | **57 MB** | 13 MB | 6 | 2 | 1 | 1,405 | 3 | none |
| migrations | 189 | — | — | 1 | 0 | 49,538 | 9,362,682 | 0 | HealthController.php:139 `count()` on every /health |

Catalog totals: **594 indexes, 139 duplicate groups, 189 redundant indexes, 84 MB of indexes** (query: pg_index grouped by indrelid, indkey, indpred, indexprs). DB settings (perf profile active): max_connections 120, shared_buffers 512 MB, work_mem 8 MB, effective_cache_size 1.5 GB, random_page_cost 1.1, jit on, track_io_timing **off**, log_min_duration_statement 500 ms, statement_timeout / lock_timeout / idle_in_transaction_session_timeout **0**, autovacuum_vacuum_scale_factor 0.2. `pg_stat_statements` in shared_preload_libraries but `CREATE EXTENSION` never run in any DB.

### Hot endpoints (k6 mix weight / 120) → handler → query shape → index verdict (EXPLAIN, dev data + `enable_seqscan=off` probe) → cache

| Endpoint (weight) | Handler (file:line) | SQL shape | Index verdict | Cache |
|---|---|---|---|---|
| GET /inventory, /inventory/balances (8+7) | InventoryBalanceController.php:60-170 | `inventory_balances ORDER BY product_id, stock_status LIMIT/OFFSET` + `count(*)` + 4 page-bounded helpers (ledger SUM via idx_sm_ledger_dimension, subledger counts, container hints, incidents) | Seq+Sort (no index leading with product_id; fine at 293 rows) | rememberStampede `stock_short` 20 s, ns `stock:version` |
| GET /stock-movements (6) | StockMovementController.php:65-102 | `ORDER BY occurred_at DESC` + `count(*)`; optional wh/product/type; `ilike '%kw%'` on movement_no/business_key/source_type (Controller.php:28-42) | **Seq Scan + Sort; no usable index even with seqscan off** (DB-01) | `stock_short`, `stock:version` |
| GET /reports/dashboard-quick (8), /dashboard-overview (7) | OperationalReportController.php:627-1106 (754-761, 831-838, 953-979, 994-1003, 1028-1034) | ~6 `stock_movements WHERE occurred_at BETWEEN … GROUP BY` + warranty/balance sums (26 queries measured in PERF_AUDIT §4) | **Seq Scan** on movements (DB-01) | rememberStampede `dashboard` 60 s |
| GET /reports/inventory-balance, -xnt, -ledger, movement-trace, document-history (12) | :1217-1300, 1640-1703, 2107-2213, 2381-2450, paginateReport 2543-2566 | full `->get()` then in-memory `forPage()`; XNT = `occurred_at <= to GROUP BY wh,sku,status` over whole ledger | seq scans by construction (DB-06) | rememberStampede `report` 300 s |
| GET /skus, /products (6+4) | ProductController@index → BaseRepository.php:74-136, 426-470 | `products` view `ORDER BY id DESC LIMIT` + `count(*)` | Index Scan Backward on PK (OK); `sku` view subplan only on 20 projected rows (EXPLAIN C) | `sku` 900 s |
| GET /item-instances, /physical-codes (4) | repositories via BaseRepository | `ORDER BY <table>.id DESC` | PK Index Scan Backward (OK) | uncached (live ops, correct) |
| GET /inbound-documents, /outbound-documents (6+6) | OutboundDocumentController.php:252-261 (shared base) | `stock_documents WHERE doc_type [AND status] ORDER BY created_at DESC` + `count(*)` + `with('lines')` | Bitmap/Seq + Sort, no created_at index (DB-08) | uncached |
| GET /warranty-cases (3) | CanonicalMvpController@listWarrantyCases → tablePage :536-560, keyword ext :373-382 | `warranty_case ORDER BY created_at DESC`; `ilike '%kw%'` ×5 cols + 2 `orWhereIn(item_id, subselect ilike)` | Seq + Sort, no created_at index (DB-08) | uncached |
| GET /audit-logs (1) | AuditLogController.php:58-70 → BaseRepository default sort | `audit_log` view `ORDER BY id DESC` where id = md5 expression | **Seq Scan + Sort on expression, unindexable** (DB-07) | uncached by design (PERF_AUDIT §4b C) |
| GET /notifications/unread-count (8) | NotificationController.php:103-120 | `count(*) WHERE user_id=? AND read_at IS NULL` | Index Scan (user_id, created_at) (OK) | `stock_short`, actor-scoped |
| GET /public/products, /pv/products, /public/home (2+1+2) | PublicCatalogReadModel.php:360-380, PvCatalogReadModel.php:172-198, 248; CanonicalMvpController.php:465-475 | `sku` view + correlated `(SELECT SUM(available_qty) FROM inventory_balances b WHERE b.product_id = s.sku_id …)` per row | **Full-index scan of inventory_balances per SKU row** (DB-02) | not inspected |
| POST …/scan, /post-receipt, /post-issue, /reversals | CurrentBaselineStockService.php:379-470, 1527-1645; AlignedOutboundStockService.php:25-122, 147-227; CurrentBaselineReversalService.php:160-200, 521-600, 662-690 | `FOR UPDATE` doc → lines → balances in global SKU order → inserts | BitmapOr on `idx_erd_inventory_balances_sku_id_stock_status` + `uk_inventory_balances_wh_product_status` (OK); lock scope DB-10 | bumps `stock:version` after commit |
| GET /stock-movements/trace?code= | StockMovementController.php:662-707 | `LOWER(CAST(code_value AS TEXT)) = ?` cascade over physical_codes → item_instances → containers → sku | **Seq Scan, no expression index** (DB-04); exact `code_value = ?` (ScanResolver.php:15) uses unique index (OK) | uncached |
| GET /putaway/tasks | PutawayController.php:67-98 | containers `WHERE container_type='BOX' AND (status=… OR EXISTS putaway DRAFT) ORDER BY container_code`, `like '%kw%'` | (container_type,status) + container_code unique serve it (OK at scale for filter; LIKE unindexed) | uncached |

## 3. Thay đổi đã thực hiện và EXPLAIN trước/sau

**Migration `2026_09_07_000000_add_stock_movements_time_indexes`** — thêm hai chỉ mục,
không đổi cột/ràng buộc/nghiệp vụ:

```
idx_sm_occurred_at_id  ON stock_movements (occurred_at DESC, id DESC)
idx_sm_wh_occurred_at  ON stock_movements (warehouse_id, occurred_at DESC)
```

| Truy vấn | EXPLAIN TRƯỚC | EXPLAIN SAU |
|---|---|---|
| `select * from stock_movements order by occurred_at desc limit 20` | `Limit → Sort (Sort Key: occurred_at DESC) → Seq Scan` | `Limit → Index Scan using idx_sm_occurred_at_id` (**không còn Sort**) |
| `select movement_type, sum(qty_delta) … where occurred_at between … group by` | `HashAggregate → Seq Scan (Filter: occurred_at …)` | `HashAggregate → Index Scan using idx_sm_wh_occurred_at (Index Cond: occurred_at >= … <= …)` |
| `… where warehouse_id = ? order by occurred_at desc limit 20` | Seq Scan + Sort | `Index Scan using idx_sm_wh_occurred_at (Index Cond: warehouse_id = …)` |

Chi phí ghi: bảng chỉ ghi thêm với `occurred_at` tăng dần ⇒ chèn vào lá phải cùng
của B-tree; không đụng `business_key`/`movement_no` (chốt idempotency).

**`CREATE EXTENSION pg_stat_statements`** — trước đó thư viện đã preload nhưng view
chưa tạo nên không thể xếp hạng truy vấn. Sau khi bật, top theo tổng thời gian:

| Tổng | Số lần | TB | Truy vấn |
|---|---|---|---|
| 39 s | 9.641 | 4 ms | `select a.attname … from pg_attribute` (introspection cột) |
| 35 s | 14.458 | 2 ms | introspection cột (dạng thứ hai) |
| 31 s | 2.024 | 16 ms | `select m.*, w.code …` (sổ cái biến động) |
| 25 s | 3.104 | 8 ms | facet `count(distinct s.sku_id)` của catalog công khai |
| 14 s | 200.110 | 0 ms | `select * from app_user where user_id = $1` (2 lần/request — xem API-02) |

Hai dòng đầu là hệ quả của `pm.max_requests` thấp: mỗi child php-fpm mới phải hỏi
lại catalog cột (`BaseRepository::getTableColumns`/`nonNullableColumns` chỉ nhớ
theo tiến trình). Đã nâng mặc định của hồ sơ chịu tải lên 5000.

## 4. Phát hiện

| ID | Mức | Thành phần | Vấn đề | Trạng thái 07/09 |
|---|---|---|---|---|
| DB-01 | P0 | PostgreSQL / Modules/Warehouse StockMovementCo | stock_movements has no index leading with occurred_at: movement list, dashboards, XNT and trace are full scans + sort | **ĐÃ SỬA** (index + EXPLAIN) |
| DB-02 | P0 | PublicCatalogReadModel / PvCatalogReadModel /  | Public/PV catalog runs a correlated inventory_balances subquery on product_id with no leading index — O(SKUs × balances) per request, already 8.9M seq scans / 2.6B tuple reads on a 293-row table | chưa xử lý |
| DB-03 | P1 | PostgreSQL schema (ERD mirror migrations) | 189 redundant duplicate indexes (139 groups) — hottest write tables carry 2–4 copies of the same btree; inventory_balances.updated_at index defeats HOT updates on the most contended rows | không sửa — phá hợp đồng ERD |
| DB-04 | P1 | StockMovementController::traceByPhysicalCode / | Trace-by-code resolves codes with LOWER(CAST(col AS TEXT)) = ? — no expression index, so a miss cascades sequential scans over physical_codes, item_instances, containers and skus | chưa xử lý |
| DB-05 | P1 | app/Support/Cache (CacheVersion namespaces) +  | Single global cache namespace stock:version is bumped by every movement, so under any write load the heaviest inventory/movement/dashboard queries hit PostgreSQL almost uncached | chưa xử lý |
| DB-06 | P1 | OperationalReportController (5 report builders | Report endpoints load the full result set and paginate in PHP; list endpoints run count(*) + OFFSET on every page | chưa xử lý |
| DB-07 | P2 | Modules/AuditLog (AuditLogRepository via BaseR | audit_log view derives id from an md5 expression and the repository default orders by it — every audit page is a full seq scan + sort, on an endpoint that is uncached by design | chưa xử lý |
| DB-08 | P2 | OutboundDocumentController/InboundDocumentCont | Document and warranty listings sort on created_at with no matching index and search with ilike '%kw%' — sort of the whole filtered set per page | chưa xử lý |
| DB-09 | P2 | PostgreSQL maintenance / autovacuum | inbound_lines/outbound_lines are ~99.7% dead space (146 MB + 57 MB for 4,328 rows) and have never been vacuumed; autovacuum defaults are too lax for the hot UPDATE tables | chưa xử lý |
| DB-10 | P2 | CurrentBaselineStockService / AlignedOutboundS | Post/Issue/Reversal lock scope: ordering is deadlock-safe, but per-SKU balance locks are held through subledger, posting-event and audit inserts with no lock/idle timeouts, and the OR-predicate doubles index work | chưa xử lý |
| DB-11 | P2 | docker-compose.perf.yml postgres service | pg_stat_statements is preloaded but never created and track_io_timing is off — statement-level hotspots cannot be measured on the perf stack | **ĐÃ SỬA** (pg_stat_statements) |


### DB-01 — stock_movements has no index leading with occurred_at: movement list, dashboards, XNT and trace are full scans + sort

**Bằng chứng:** StockMovementController.php:89 `->orderByDesc('occurred_at')` + :102 `paginate()` → `select count(*) from stock_movements` + `select * from stock_movements order by occurred_at desc limit 20 offset 0`. OperationalReportController.php:754-761, 831-838, 938-940, 994-999, 1028-1030, 1870-1877, 1922-1928, 2391-2395 all filter `occurred_at BETWEEN`. pg_indexes for stock_movements (10 indexes): idx_sm_document(stock_document_id), idx_sm_ledger_dimension(warehouse_id,product_id,stock_status) INCLUDE(qty_delta,occurred_at), idx_sm_wh_product_time(warehouse_id,product_id,occurred_at), correction_chain, original, source, business_key, movement_no, pkey, stock_status — none leads with occurred_at. EXPLAIN (default): `Limit -> Sort (Sort Key: occurred_at DESC) -> Seq Scan on stock_movements` for unfiltered, `movement_type='RECEIPT'`, and `warehouse_id=<real id>` variants; `SET enable_seqscan=off` still yields Seq Scan (cost 1e10) = no usable index. Period aggregate with seqscan off degrades to `Index Scan using idx_sm_wh_product_time Index Cond: occurred_at >= … <= …` (non-leading column = whole-index scan). pg_stat_user_tables: stock_movements seq_scan=1,411, seq_tup_read=10,370,850 (= 1,411 × 7,350: every scan reads the whole ledger).

**Nguyên nhân:** Ledger indexes were designed for the (warehouse, product) dimension (WWHN-231 aggregate-then-join) and never for time ordering; the list endpoint and all KPI/period reports order or range-filter on occurred_at alone.

**Ở 10k người dùng:** stock_movements is append-only and is the largest table by design. At 10k users (≈250–500 req/s by the LOAD_TEST_2026-09-06 §4 arithmetic, ~30% of the k6 mix hitting these shapes) every list page and every dashboard KPI scans and top-N-sorts the entire ledger; with work_mem=8 MB sorts of a multi-million-row table spill to disk. Combined with DB-05 (cache invalidated on every post) this becomes the dominant PG CPU/IO consumer. Exact latency NOT MEASURED (dev has 7,350 rows).

**Sửa thế nào:** Add `CREATE INDEX CONCURRENTLY idx_sm_occurred_at_id ON stock_movements (occurred_at DESC, id DESC);` (serves ORDER BY + the paginator's stable secondary key and all `occurred_at BETWEEN` ranges) and `CREATE INDEX CONCURRENTLY idx_sm_wh_occurred_at ON stock_movements (warehouse_id, occurred_at DESC);` for warehouse-scoped lists/dashboards. Optional: `(occurred_at) INCLUDE (movement_type, qty_delta, warehouse_id)` to make dashboard sums index-only. Expected plan: `Index Scan Backward using idx_sm_occurred_at_id … Limit 20` (Sort node disappears); period aggregates become range Index/Bitmap scans instead of Seq Scan. Write amplification: +1–2 btrees on an insert-only table with a monotonically increasing key (right-most leaf appends, ~40–60 bytes/row each) — cheap. Keep idx_sm_wh_product_time (serves warehouse+product lists: EXPLAIN shows Index Scan Backward). Do NOT touch business_key/movement_no uniqueness (idempotency backstops, CurrentBaselineStockService.php:1546).

**Kiểm chứng:** Before/after EXPLAIN (ANALYZE, BUFFERS) on the three list variants and on the dashboard-quick totals query against a copy of the DB seeded to ≥1M movements (HoaNamBulkDemoSeeder); pg_stat_user_tables.seq_scan / seq_tup_read delta for stock_movements per 1,000 k6 requests (baseline profile) should drop to ~0; k6 `inventory` and `dashboard` group p95 before/after.


### DB-02 — Public/PV catalog runs a correlated inventory_balances subquery on product_id with no leading index — O(SKUs × balances) per request, already 8.9M seq scans / 2.6B tuple reads on a 293-row table

**Bằng chứng:** PublicCatalogReadModel.php:369-373: `->where('s.published_to_app', true)->whereRaw('(SELECT COALESCE(SUM(b.available_qty),0) FROM inventory_balances b WHERE b.product_id = s.sku_id AND b.stock_status = 'AVAILABLE') > 0')`; PvCatalogReadModel.php:174-176 (two such subqueries in SELECT) and :248 (`in_stock` filter); CanonicalMvpController.php:469 `leftJoin('inventory_balances as b','b.product_id','=','s.sku_id')`. `sku` view definition (pg_get_viewdef): `published_to_app = COALESCE((SELECT p.published FROM pv_product_profiles p WHERE p.sku_id = s.id), false)`. Indexes on inventory_balances: only `idx_erd_inventory_balances_sku_id_stock_status` leads with the SKU column; `(warehouse_id, product_id, stock_status)` needs warehouse first. EXPLAIN of the catalog query: `Index Scan using idx_erd_skus_sku_type_status on skus s … Filter: (COALESCE((SubPlan 1), false) AND ((SubPlan 2) > 0))` with `SubPlan 2 -> Aggregate -> Index Scan using uk_inventory_balances_wh_product_status Index Cond: ((product_id = s.id) AND (stock_status='AVAILABLE'))` — a non-leading-column condition, i.e. a whole-index scan executed once per ACTIVE SKU row. pg_stat: inventory_balances seq_scan=8,924,965, seq_tup_read=2,615,014,745; uk_inventory_balances_wh_product_status idx_scan=6,201,083 (a 293-row table); pv_product_profiles.uk_pv_product_profile_product idx_scan=20,690,299 (SubPlan 1). Data check: `sku_id IS DISTINCT FROM product_id` = 0 of 293 balances and 0 of 7,350 movements.

**Nguyên nhân:** The catalog filters on the `product_id` mirror column (no leading index) instead of `sku_id` (indexed), and pushes IN_STOCK + PUBLISHED into per-row scalar subqueries via the `sku` view; PostgreSQL cannot decorrelate them.

**Ở 10k người dùng:** Per-row cost grows with the size of inventory_balances (whole index per SKU); request cost = ACTIVE SKUs × balance-index size. With e.g. 50k SKUs and 100k balance rows that is ~5×10^9 index-entry visits per catalog page. These endpoints are unauthenticated (only per-IP throttle `pv-public` 120/min, LOAD_TEST_2026-09-07 §2), so this is also an amplification lever for a single attacker. Latency NOT MEASURED at that volume.

**Sửa thế nào:** (a) Change the three subqueries to filter on `b.sku_id = s.sku_id` (existing `idx_erd_inventory_balances_sku_id_stock_status`; a migration should first assert `sku_id = product_id` everywhere — currently true) or add `CREATE INDEX CONCURRENTLY ON inventory_balances (product_id, stock_status) INCLUDE (available_qty)`; expected plan: SubPlan becomes a 1-row Index Scan with leading-column Index Cond. (b) Better: compute stock once per query — `LEFT JOIN LATERAL (SELECT SUM(available_qty) qty FROM inventory_balances b WHERE b.sku_id = s.sku_id AND stock_status='AVAILABLE')` or a `GROUP BY sku_id` CTE joined once — so the IN_STOCK rule is evaluated N times, not N × |balances|. (c) Replace `s.published_to_app = true` in WHERE with an inner JOIN on `pv_product_profiles.published = true` (EXPLAIN C shows the view subplan is only deferred to projected rows when it is not in WHERE). Keep both business rules (PUBLISHED and IN_STOCK mandatory) and keep stock out of the presentation SELECT as the comment at :355-359 requires. Write amplification of the optional index: +1 btree on the 293-row-per-warehouse balance table, negligible.

**Kiểm chứng:** EXPLAIN (ANALYZE) before/after on `/public/products?page=1` SQL: SubPlan loops should show `Index Cond: (sku_id = s.id)` with rows=1 and total buffers dropping by orders of magnitude; pg_stat_user_tables.seq_scan on inventory_balances per 100 catalog requests → 0; k6 `public` group p95.


### DB-03 — 189 redundant duplicate indexes (139 groups) — hottest write tables carry 2–4 copies of the same btree; inventory_balances.updated_at index defeats HOT updates on the most contended rows

**Bằng chứng:** Catalog query grouping pg_index by (indrelid, indkey, indpred, indexprs): total_indexes=594, dup_groups=139, redundant_indexes=189, all_idx_size=84 MB of a 330 MB DB. Hot examples: inventory_balances 10 indexes, 4 identical UNIQUE on (warehouse_id, sku_id, stock_status) [uk_inventory_balances_wh_sku_status, ux_erd_doc_inventory_balances_a7d7fe67bf, ux_erd_inventory_balance_bucket, ux_erd_inventory_balances_a7d7fe67] + 2 on (id); physical_codes 20 indexes / 9 redundant (code_value ×3, hardware_uid ×3, (item_id,status) ×2, (container_id,status) ×2, id ×2, partial primary-carrier ×2 each); item_instances 14 / 3 (serial_number ×2, item_code ×2, id ×2); pv_product_profiles 10 / 5; scan_events id ×3 (672 kB); posting_events id ×3 + (resource_type,resource_id,posting_action) ×2; inbound_lines id ×3 (8.3 MB). pg_stat_user_indexes: the copies sit at idx_scan=0 (uk_inventory_balances_wh_sku_status 0, ux_erd_inventory_balances_a7d7fe67 0, ux_erd_physical_codes_code_value 0, physical_codes_code_value_unique 0 — planner picks ux_erd_doc_physical_codes_40f937d566). `idx_erd_inventory_balances_updated_at` exists and every balance write sets `updated_at` (CurrentBaselineStockService.php:1593-1601, AlignedOutboundStockService.php:184-194, CurrentBaselineReversalService.php:578-586) → update of an indexed column = non-HOT update touching all 10 indexes. HOT ratio NOT MEASURED (n_tup_upd=0 since stats reset).

**Nguyên nhân:** Three generations of index naming (`*_unique`/`uk_*`, `ux_erd_doc_*`, `ux_erd_*`/`ux_erd_mirror_*`/`ux_erd_canonical_key_*`) were added by successive ERD-alignment migrations without dropping predecessors.

**Ở 10k người dùng:** Every scan inserts into physical_codes + item_instances + scan_events (~47 btrees maintained instead of ~30) and every post updates inventory_balances rows through 10 indexes with no HOT path, roughly doubling WAL and index-page churn on the exact rows that are row-locked (DB-10) — lock hold time grows with index maintenance. Autovacuum work and shared_buffers pressure scale with the redundant 84 MB → hundreds of MB at production volume.

**Sửa thế nào:** One migration using `DROP INDEX CONCURRENTLY` for each duplicate group, keeping exactly one index per definition: prefer the one referenced by a constraint (check `pg_constraint.conindid` — dropping a constraint-backed index needs `ALTER TABLE … DROP CONSTRAINT`) so uniqueness/idempotency backstops (code_value, serial_number, business_key, movement_no, (posting_action, idempotency_key)) are preserved. Drop `idx_erd_inventory_balances_updated_at` unless a query needs it (only user found: `MAX(b2.updated_at)` per SKU in PvCatalogReadModel.php:176, which is per-sku anyway) to restore HOT updates; alternatively set `fillfactor=70` on inventory_balances. Do not drop `stock_movements_business_key_unique` / `movement_no_unique` (0 scans but they enforce DUPLICATE_STOCK_EFFECT).

**Kiểm chứng:** Re-run the duplicate-group catalog query → 0 groups; pg_stat_user_indexes idx_scan unchanged for surviving indexes after a k6 baseline run; `n_tup_hot_upd / n_tup_upd` on inventory_balances after a k6 write profile (ENABLE_WRITES) should rise from 0 toward >0.9; WAL bytes per posted document (`pg_stat_wal` delta) before/after.


### DB-04 — Trace-by-code resolves codes with LOWER(CAST(col AS TEXT)) = ? — no expression index, so a miss cascades sequential scans over physical_codes, item_instances, containers and skus

**Bằng chứng:** StockMovementController.php:662-707: `whereRaw('LOWER(CAST(code_value AS TEXT)) = ?')` / `LOWER(CAST(hardware_uid …))` on physical_codes, then `LOWER(CAST(item_code …))` / `LOWER(CAST(serial_number …))` on item_instances, then containers.container_code, then sku.sku_code. pg_indexes: only plain btrees on these columns (code_value ×3, hardware_uid ×3, serial_number ×2, item_code ×2, container_code ×3, sku_code ×2) — no `lower()` expression index. EXPLAIN: `Seq Scan on physical_codes Filter: (lower((code_value)::text) = 'box-abc-0001')`; with `enable_seqscan=off` still Seq Scan (cost 1e10) → no index matches; same for item_instances (`Filter: lower(item_code) = … OR lower(serial_number) = …`). Contrast: ScanResolver.php:15 exact `where('code_value', trim($rawCode))` → `Index Scan using ux_erd_doc_physical_codes_40f937d566`. pg_stat: physical_codes seq_scan=148 (450,806 tuples), item_instances seq_scan=91 (413,959).

**Nguyên nhân:** Case-insensitive matching implemented as a runtime expression instead of normalised storage or an expression index; the existing unique indexes cannot be used.

**Ở 10k người dùng:** Trace is an operator screen used per incident, not per scan, but each unresolved or SKU-level lookup reads four full tables; with ~1M physical codes and ~1M items that is 4 full heap scans (hundreds of MB) per trace request, holding a php-fpm worker and a PG backend for seconds. Not measured at volume.

**Sửa thế nào:** Codes are already generated upper-case (BOX-<SKU>-<seq>, MV-…); normalise the needle to the stored case and use exact equality (reuses existing unique indexes, zero write cost), falling back to `lower()` only if a legacy mixed-case population is proven. If case-insensitivity must stay: `CREATE INDEX CONCURRENTLY ON physical_codes (lower(code_value)); … (lower(hardware_uid)); ON item_instances (lower(item_code)); (lower(serial_number)); ON containers (lower(container_code)); ON skus (lower(sku_code));` Expected plan: `Index Scan … Index Cond: (lower(code_value) = $1)`. Write amplification: +1 small btree per table on insert paths (physical_codes/item_instances are written on every scan — prefer the exact-match option). Keep the resolution order and the 404/409 semantics.

**Kiểm chứng:** EXPLAIN (ANALYZE) of the four resolve statements before/after with a non-existent code (worst case); pg_stat_user_tables.seq_scan delta on physical_codes/item_instances during trace usage → 0.


### DB-05 — Single global cache namespace stock:version is bumped by every movement, so under any write load the heaviest inventory/movement/dashboard queries hit PostgreSQL almost uncached

**Bằng chứng:** CurrentBaselineStockService.php:1638 `DB::afterCommit(fn () => $this->cacheService()->bumpVersion('stock:version'))` inside `mutateBalance()` — called once per document line for receipt, issue, reversal and component issue (comment :1633-1637: 'điểm chốt DUY NHẤT'). Consumers: InventoryBalanceController.php:80 `currentVersion('stock:version')`; StockMovementController.php:70-77 `'stock:version'`; ReadCache trait `cachedReadPayload()` default `$versionNamespace = 'stock:version'` (app/Support/Cache/Concerns, :125-143) used by every controller that does not override it. TTLs config/wms_cache.php:12-19: stock_short 20 s, dashboard 60 s, report 300 s, search 120 s. LOAD_TEST_2026-09-06 §5: measured hit ratio ~35% even with zero writes (version-key GET always misses). rememberStampede uses `Cache::lock` with wait 5 s / ttl 10 s (config/wms_cache.php:26-28).

**Nguyên nhân:** Invalidation granularity is global (one integer for all warehouses, all SKUs, all read models) while the write rate is per-line; correctness is guaranteed by PG anyway (post/issue checks read PG with FOR UPDATE), so the cache is doing less work than its TTLs suggest.

**Ở 10k người dùng:** At 10k users a few posts per second are inevitable; each bump orphans every inventory/movement/report entry system-wide, so lists (DB-01), balances and dashboards are rebuilt from PG within milliseconds of every post, and concurrent readers of the same key serialise on the stampede lock (5 s wait) — the exact 'workers waiting on something that is not SQL' signature in LOAD_TEST_2026-09-07 §1. DB load for these endpoints scales with write rate × read rate instead of read rate / TTL.

**Sửa thế nào:** Partition the namespace: `stock:version:{warehouse_id}` bumped only for the warehouse mutated (mutateBalance already has $warehouseId), with readers composing their key from the warehouses in scope (readCacheScopeIds already exists); keep a coarse `stock:version` only for GLOBAL-scope reports. Decouple dashboards from versioning (fixed 60 s TTL is already the accepted staleness). Do not add cache to post/issue availability checks, RBAC or idempotency paths — they must keep reading PG. This is a cache-keying change, no response shape or business rule change.

**Kiểm chứng:** Valkey `keyspace_hits/keyspace_misses` and `pg_stat_user_tables.seq_scan` on stock_movements/inventory_balances per 1,000 requests during a mixed k6 run (baseline reads + ENABLE_WRITES posts at ~2/s) before/after; count of `cache.rebuild` log lines per minute.


### DB-06 — Report endpoints load the full result set and paginate in PHP; list endpoints run count(*) + OFFSET on every page

**Bằng chứng:** OperationalReportController.php:2543-2566 `paginateReport()` slices `collect($report[$rowsKey])->forPage()` in memory; builders materialise everything first: buildInventoryBalance :1279-1283 (`$balanceQuery->get()` + full `ledgerAggregateWithMasterQuery()->get()`), buildXnt :1640-1703 (`stock_movements WHERE occurred_at <= to GROUP BY wh,sku,status` over the whole ledger then `$query->get()`; EXPLAIN: `HashAggregate -> Seq Scan Filter: occurred_at <= …`), buildDocumentHistory :2150 (`->get()` all documents in range, then :2204-2213 all their lines), buildMovementTrace :2450 (`->get()` all movements in period unless per_page is sent; SQL paging exists only at :2431-2449). PERF_AUDIT §6: 5,956 rows = 4.3 MB payload today. Lists: StockMovementController.php:102, InventoryBalanceController.php:~150, OutboundDocumentController.php:261, BaseRepository.php:136 all use `paginate()` → `select count(*) as aggregate …` per page (EXPLAIN: `Index Only Scan using stock_movements_stock_status_index` over all 7,350 rows) + `OFFSET`. pg_stat: stock_document_lines seq_tup_read=39,588,216 (9,147 scans) and stock_documents 10,142,880 (27,120 scans).

**Nguyên nhân:** Reports were written as whole-period snapshots (invariant: table, timeline and KPIs describe the same set), and XNT derives opening balances by re-summing the entire ledger on every call.

**Ở 10k người dùng:** Memory and transfer per request are proportional to rows in the period (php-fpm memory_limit × 48 workers → OOM risk; PERF_AUDIT §6 flags this as remaining risk), and PG performs a full-ledger aggregate per XNT/inventory-balance call every 300 s per filter combination (or every bump, see DB-05). count(*) on a multi-million-row ledger per list page is a full index-only scan.

**Sửa thế nào:** (1) Push pagination into SQL for document-history and inventory-balance the way movement-trace already does (`forPage` + separate bounded aggregate for KPIs), keeping the response contract (`pagination` block, KPI totals from a dedicated aggregate). (2) Keyset pagination `(occurred_at, id) < (?, ?)` for /stock-movements (needs DB-01 index) and drop or cache `total` (or use `count_estimate` from pg_class.reltuples for unfiltered pages). (3) XNT/opening balance: add a periodic opening-balance snapshot table (per warehouse/sku/status per period close) so the query scans only the period, not `occurred_at <= to` since the beginning — a data-model change to plan, not an index. Do not change KPI semantics (DEC-INV-001: ledger is authoritative) or the movement-trace-api.ts same-set invariant.

**Kiểm chứng:** Peak PHP memory (`memory_get_peak_usage`) and response bytes per report call at 100k-row periods on a seeded copy; EXPLAIN (ANALYZE, BUFFERS) of XNT aggregate before/after snapshotting; PG `tup_returned` per request from pg_stat_database delta.


### DB-07 — audit_log view derives id from an md5 expression and the repository default orders by it — every audit page is a full seq scan + sort, on an endpoint that is uncached by design

**Bằng chứng:** pg_get_viewdef('audit_log'): `id = COALESCE(NULLIF(metadata->>'legacy_id','')::uuid, (substr(md5('audit_log:'||id),1,8)||'-'…)::uuid)`, `object_id = COALESCE(metadata->>'legacy_object_id', entity_id::text)`. BaseRepository.php:466-467: `elseif (in_array('id', $columns)) $query->orderBy(table.'.id','desc')`; AuditLogRepository.php has no ordering override; AuditLogController.php:58-70 and :91-105 (`where('object_type','user')->where('object_id',$userId)`). EXPLAIN `SELECT * FROM audit_log ORDER BY id DESC LIMIT 20`: `Limit -> Sort (Sort Key: COALESCE(… md5(…) …) DESC) -> Seq Scan on audit_logs`; unchanged with enable_seqscan=off. The chronological index `idx_erd_audit_logs_created_at` exists and works: `ORDER BY created_at DESC LIMIT 20` → `Index Scan Backward using idx_erd_audit_logs_created_at`. myHistory: `Index Scan using idx_erd_audit_logs_entity_type_entity_id Index Cond: entity_type='user'` then `Filter: COALESCE(metadata->>'legacy_object_id', entity_id::text) = …` (entity_id not usable as Index Cond). PERF_AUDIT §4b C: /audit-logs deliberately uncached.

**Nguyên nhân:** The legacy-compat view synthesises UUIDs for a bigint-keyed append-only table; the generic repository picks `id` as the sort key, which is an expression on the view.

**Ở 10k người dùng:** audit_logs is the fastest-growing table (every write and every RBAC deny is recorded); at tens of millions of rows each admin page computes md5 for every row and sorts them, uncached. Ordering by md5 is also non-chronological, so the page is not even the newest entries.

**Sửa thế nào:** In AuditLogRepository override sort to `orderBy('created_at','desc')->orderBy(...)` (index-backed, expected plan `Index Scan Backward using idx_erd_audit_logs_created_at … Limit`), and in myHistory filter on the base column `entity_id` (uuid) instead of the view's `object_id` expression so `idx_erd_audit_logs_entity_type_entity_id` covers both columns. Append-only guarantee and RBAC module filter (`whereIn('module', …)`) unchanged. Longer term stop exposing md5-synthesised ids via the view.

**Kiểm chứng:** EXPLAIN before/after on the paginate SQL; response time of /audit-logs?page=1 on a seeded 1M-row audit_logs; pg_stat_user_tables.seq_scan on audit_logs stays flat under k6 iam group.


### DB-08 — Document and warranty listings sort on created_at with no matching index and search with ilike '%kw%' — sort of the whole filtered set per page

**Bằng chứng:** OutboundDocumentController.php:252-261 `StockDocument::query()->with('lines')->where('doc_type', …)[->where('status')]->orderByDesc('created_at')->paginate()`; stock_documents indexes: idx_sd_doc_type_status(doc_type,status), idx_sd_doc_date(doc_date), agency, outbound_request, doc_no, pkey, (doc_type,create_idempotency_key) — none contains created_at. EXPLAIN: `Limit -> Sort (created_at DESC) -> Seq Scan Filter: deleted_at IS NULL AND doc_type='receipt'` (seqscan off: Bitmap on idx_sd_doc_type_status + Sort). CanonicalMvpController.php:536-560 `tablePage()` default `orderByDesc('created_at')` for `warranty_case`; :373-382 keyword adds `ilike '%kw%'` on 5 columns (:669) plus two `orWhereIn('item_id', subselect … ilike)`; warranty_cases has (status, received_at), (sku_id, status) but no created_at index → EXPLAIN `Sort (created_at DESC) -> Seq Scan on warranty_cases` even with seqscan off. Controller.php:28-42 `applyKeyword` = `ilike '%kw%'` everywhere (also stock-movements movement_no/business_key/source_type). pg_stat: stock_documents seq_scan=27,120 (10.1M tuples), warranty_cases seq_scan=13,828 (2.57M), warranty_case_defects 6,586.

**Nguyên nhân:** List ordering columns were never indexed; keyword search uses leading-wildcard LIKE which no btree can serve.

**Ở 10k người dùng:** At ~1M documents / ~500k cases each page sorts the entire doc_type partition (uncached endpoints, k6 weight 6+6+3); keyword searches become full scans with per-row pattern matching across 5–7 columns.

**Sửa thế nào:** `CREATE INDEX CONCURRENTLY ON stock_documents (doc_type, created_at DESC) WHERE deleted_at IS NULL` (expected: `Index Scan Backward … Index Cond: doc_type=…` with no Sort; add `(doc_type, status, created_at DESC)` only if the status-filtered list is common). For warranty_case either pass `received_at` as tablePage's `$orderBy` (already indexed with status) or add `warranty_cases (created_at DESC)`. For keyword search on high-cardinality text (customer_phone, customer_name, warranty_case_code, movement_no) add `pg_trgm` GIN indexes if the search is frequent; otherwise cap keyword search to prefix match. PII masking rule (§13.8, :536-541) and status filters unchanged. Write amplification: one btree each on moderate-write tables; GIN trigram indexes are heavier on insert — only where justified.

**Kiểm chứng:** EXPLAIN before/after for `/inbound-documents?page=1` and `/warranty-cases?page=1` SQL; pg_stat_user_tables.seq_scan delta on stock_documents/warranty_cases per 1,000 list requests → ~0.


### DB-09 — inbound_lines/outbound_lines are ~99.7% dead space (146 MB + 57 MB for 4,328 rows) and have never been vacuumed; autovacuum defaults are too lax for the hot UPDATE tables

**Bằng chứng:** pg_class: inbound_lines relpages=18,709, reltuples=2,923 → 52,434 bytes/row for a 13-column scalar row (toast 8 kB); outbound_lines relpages=7,264 / 1,405 rows → 42,354 bytes/row; pg_stat_user_tables: vacuum_count=0, autovacuum_count=0, last_autovacuum NULL, n_dead_tup=0 (stats were reset, so autovacuum's trigger is blind to the existing garbage). Index side: inbound_lines 40 MB of indexes incl. id ×3 (8.3 MB). Usage is low today (seq_scan=1, idx_scan=3; legacy `*__legacy_projection` views). Settings: autovacuum_vacuum_scale_factor=0.2, autovacuum_vacuum_threshold=50, track_io_timing=off.

**Nguyên nhân:** Repeated seed/delete cycles (bulk demo seeders) without VACUUM; stats reset hid the dead tuples; default scale factor means large tables accumulate 20% dead rows before cleanup.

**Ở 10k người dùng:** Direct impact is on backups/replica copies and any accidental seq scan (200 MB of garbage); the systemic risk is that the same pattern on inventory_balances (updated on every post, non-HOT per DB-03), stock_documents and item_instances will bloat under load while autovacuum waits for 20% dead tuples (2M dead rows on a 10M-row ledger), inflating the seq scans of DB-01/DB-08.

**Sửa thế nào:** Ops: `VACUUM (VERBOSE, ANALYZE) inbound_lines, outbound_lines` then `VACUUM FULL`/`pg_repack` in a window (or drop the legacy tables if the projection views are unused — confirm with grep of `inbound_lines`/`outbound_lines` writers first). Config: per-table `ALTER TABLE inventory_balances, stock_documents, stock_document_lines, item_instances, physical_codes SET (autovacuum_vacuum_scale_factor=0.02, autovacuum_analyze_scale_factor=0.02, autovacuum_vacuum_cost_delay=2)`; enable `track_io_timing=on`. No code change.

**Kiểm chứng:** pg_class.relpages of the two tables after VACUUM FULL (expect ≤ ~60 pages); `n_dead_tup` trend on inventory_balances during a k6 write run; `pg_stat_progress_vacuum` shows autovacuum firing within minutes rather than hours.


### DB-10 — Post/Issue/Reversal lock scope: ordering is deadlock-safe, but per-SKU balance locks are held through subledger, posting-event and audit inserts with no lock/idle timeouts, and the OR-predicate doubles index work

**Bằng chứng:** CurrentBaselineStockService.php:387-470 `DB::transaction`: lockDocument (:1679-1684 `stock_documents … lockForUpdate`) → all lines `lockForUpdate()` (:393-397) → registerCandidateItems (:1240-1252, item/physical_code inserts) → per line `mutateBalance()` in global sku order (:431-436, :1221-1227 `sortLinesForBalanceLock`) → `inventory_balances … FOR UPDATE` (:1550-1556) + optimistic `version` check (:1593-1603) → movement insert → applyReceiptSubledger → status update → recordDocumentStatus/recordPostingEvent/AuditLogger::record all before COMMIT. AlignedOutboundStockService.php:37-122 same shape with `DB::transaction(…, 3)` (deadlock retry). Reversal: assertQuantityDependency :671-677 locks balances in phase 2 sorted by sku (:260-265). EXPLAIN of the lock statement with real ids: `LockRows -> Bitmap Heap Scan -> BitmapOr (idx_erd_inventory_balances_sku_id_stock_status, uk_inventory_balances_wh_product_status)` — the `(sku_id = ? OR product_id = ?)` predicate needs two index probes although 0 of 293 rows differ. Server settings: lock_timeout=0, idle_in_transaction_session_timeout=0, statement_timeout=0, deadlock_timeout=1 s. `business_key` pre-check :1546 uses the unique index (good). Lock waits under concurrent posting NOT MEASURED (k6 writes disabled).

**Nguyên nhân:** Correct but coarse transactional design: a document's whole post is one transaction that pins every touched SKU's balance row for its full duration; no server-side guard against a stalled worker holding those rows.

**Ở 10k người dùng:** Throughput on a hot SKU is bounded by 1/(transaction duration); a multi-line document holding N balance locks serialises every other post sharing any of those SKUs (and mini-app scanners posting the same SKU). Without idle/lock timeouts a hung php-fpm worker (e.g. stuck on Valkey or S3 inside the request) blocks those SKUs indefinitely and other workers pile up to `pm.max_children`.

**Sửa thế nào:** Keep lock order, FOR UPDATE scope, version check and posting_events idempotency exactly as is. Add server-side guards: `ALTER ROLE <app> SET lock_timeout='5s', idle_in_transaction_session_timeout='30s', statement_timeout='30s'` (surface as 409/503 with retry). Shorten the critical section without changing semantics: simplify the balance predicate to `sku_id = ?` once a migration asserts `sku_id = product_id` (single index probe), and consider moving `AuditLogger::record` for POST_* to `DB::afterCommit` only if the audit contract allows non-atomic audit (flag as a product decision, not a perf change). Rely on the existing `DB::transaction(…, 3)` retry.

**Kiểm chứng:** k6 write profile (ENABLE_WRITES) posting documents that share SKUs while sampling `pg_stat_activity` (`wait_event_type='Lock'`) and `pg_locks` every second; `pg_stat_database.deadlocks` and posts/s per hot SKU before/after; distribution of transaction duration from log_min_duration_statement.


### DB-11 — pg_stat_statements is preloaded but never created and track_io_timing is off — statement-level hotspots cannot be measured on the perf stack

**Bằng chứng:** `SHOW shared_preload_libraries` = pg_stat_statements; `SELECT extname FROM pg_extension` in wms_hoanam, wms_hoanam_perf and postgres = plpgsql only; docker-compose.perf.yml:248-252 sets `shared_preload_libraries`, `pg_stat_statements.max=5000`, `.track=top` but no init SQL runs `CREATE EXTENSION`; `track_io_timing=off`; `log_min_duration_statement=500` (only >500 ms statements are logged). Consequence: the requested top-15 by total_exec_time/calls is NOT MEASURED in this audit.

**Nguyên nhân:** Extension creation is a per-database DDL step that the compose overlay does not perform.

**Ở 10k người dùng:** No way to attribute PG CPU (4.5 cores at saturation per LOAD_TEST_2026-09-06 §2) to statements during load tests, so DB-01/02/05 cannot be ranked by measured cost and regressions will go unnoticed.

**Sửa thế nào:** Add an initdb script (docker/postgres/initdb/*.sql: `CREATE EXTENSION IF NOT EXISTS pg_stat_statements;`) or a one-off `docker compose exec postgres psql -c 'CREATE EXTENSION pg_stat_statements'` on the perf DB, set `track_io_timing=on`, and include `SELECT … FROM pg_stat_statements ORDER BY total_exec_time DESC LIMIT 15` in load/summarize.mjs output. Read-only observability; no application change.

**Kiểm chứng:** After a k6 baseline run, `pg_stat_statements` returns rows; compare top-15 by total_exec_time and by calls before/after DB-01/DB-02 fixes.


## 5. Chưa đo được

- pg_stat_statements top-15 by total_exec_time / calls — extension not created in any database (only preloaded); see DB-11.
- EXPLAIN ANALYZE at production volume — dev DB has 7,350 stock_movements / 4,549 item_instances / 293 balances; all plans are shape evidence on small tables, with enable_seqscan=off used to prove index (non-)availability. Scale markers such as '10M movements' or '50k SKUs' in impact statements are hypothetical, not measured.
- HOT-update ratio and dead-tuple growth on inventory_balances/stock_documents under write load — n_tup_upd=0 since the stats reset; k6 writes were disabled (ENABLE_WRITES=false).
- Lock waits, deadlock count and per-SKU post throughput under concurrent posting — no write load test run; pg_stat_activity/pg_locks not sampled during a write profile.
- Cache hit ratio under mixed read+write traffic (DB-05) — only the read-only 06/09 figure (~35%) exists.
- Whether /public/products, /pv/products, /public/home responses are cached at the controller layer (PublicCatalogController/PublicAppController not inspected).
- Index bloat — pgstattuple not installed; only heap bloat via relpages/reltuples was estimated.
- Latency contribution of the `sku` view `published_to_app` subplan on /skus and /products at scale (EXPLAIN shows it is deferred to projected rows in those paths, so likely small, but not timed).
- Actual per-request statement counts per endpoint (PERF_AUDIT §4 figure of 26 for dashboard-overview reused; not re-measured).
