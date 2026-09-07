# PERFORMANCE_BOTTLENECKS — Hoà Nam WMS

**Ngày:** 07/09/2026 · **Phạm vi:** `C:\LAM_PHONG_TECH` (BE Laravel 13 + FE React 19) ·
**Mục tiêu đang hướng tới:** 10.000 người dùng đồng thời.

Tài liệu này là SỔ ĐĂNG KÝ điểm nghẽn, không phải danh sách khuyến nghị chung
chung: mỗi mục có bằng chứng ở mức file:dòng / câu SQL / EXPLAIN / số đo, và có
trạng thái xử lý trong đợt 07/09.

## Cách các mục này được tìm ra

Tám luồng audit chỉ-đọc chạy song song trên cả hai repo (API, DB, cache, đường
ghi, FE-mạng, FE-render, chuỗi năng lực, tìm kiếm/báo cáo), tổng **74 phát hiện**.
Bước phản biện (mỗi phát hiện P0/P1 bị hai agent khác cố gắng BÁC BỎ) **chỉ chạy
được 1/42 mục** trước khi phiên chạm giới hạn sử dụng — vì vậy bảng dưới phân
biệt rõ ba mức tin cậy, và **không mục nào được coi là đã kiểm chứng nếu chưa có
người/máy thứ hai xác nhận**.

| Mức tin cậy | Số mục | Nghĩa là gì |
|---|---:|---|
| Đã phản biện và giữ lại | 5 | qua được vòng bác bỏ độc lập |
| Đã bị bác bỏ | 1 | phản biện chỉ ra sai cơ chế/quá lời |
| Chưa phản biện (P1 xếp hàng) | 36 | phiên hết hạn mức trước khi kiểm; bằng chứng file:dòng có sẵn nhưng CHƯA được xác nhận độc lập |
| Chưa phản biện (P2) | 32 | mức thấp, không đưa vào hàng đợi kiểm |


## Nhóm A — đã phản biện và giữ lại (5)

### API-01 — Five report endpoints return the whole filtered set with no page cap and the FE deliberately calls them without per_page

**Thành phần:** BE OperationalReportController + FE report screens (io-history, inventory-flow, movement-trace, recipient-agency)

**Bằng chứng:** OperationalReportController.php:2367-2378 (key excludes page/per_page), :2434-2450 (SQL forPage ONLY if $request->filled('per_page')), :2542-2565 paginateReport returns $report unchanged when per_page absent; same for document-history :2080-2160 and outbound-by-agency :2239-2295 (->get() on whole period). FE: movement-trace-api.ts:24 ('KHONG gui per_page'), io-history-api.ts:197, inventory-flow-api.ts:307, report-data.ts useOutboundByAgency (no per_page). Measured (build/perf/bench-be-after-all.json): /reports/inventory-ledger and /reports/movement-trace 4,511,814 bytes, warm 188-209 ms; /reports/document-history 1,022,303 bytes; /reports/outbound-by-agency 543,706 bytes; /reports/inventory-reconciliation 365,771 bytes. DB: 6,190 stock_movements in last 30 days (dev); EXPLAIN of the movement-trace SELECT = Seq Scan on stock_movements with Filter occurred_at BETWEEN (no occurred_at index; only composite idx_sm_wh_product_time (warehouse_id, product_id, occurred_at)).

**Nguyên nhân gốc:** API contract couples KPI/timeline/table to one payload so pagination is optional; payload size and SQL cost grow linearly with ledger size; cache entry per (filters, scope) holds the entire 4.5 MB array in Valkey and json_encode/decode of it happens on every hit (plainReportPayload).

**Ảnh hưởng ở 10.000 người dùng:** If 5% of 10k users open a report screen per minute: ~8 req/s x 4.5 MB = 36 MB/s egress plus ~200 ms PHP CPU per hit even when cached (json decode of 4.5 MB); a 12-month or high-volume warehouse (production ledgers of 10^6+ rows) turns this into multi-second seq scans and Valkey values of tens of MB (maxmemory 256 MB, volatile-lru will evict them constantly). This is the single largest per-request cost in the API surface.

**Hướng sửa đề xuất:** Split the contract: (a) server-side summary + by_movement_type + a bounded timeline (e.g. daily buckets) in one cached payload; (b) rows paged in SQL with a hard cap (MAX_PER_PAGE=200 already exists) and keyset pagination on (occurred_at, id); (c) add index stock_movements (occurred_at DESC, id DESC) or (warehouse_id, occurred_at) to make the period filter index-driven. Keep business rules intact: KPIs must still be computed over the FULL filtered set (do it in SQL aggregates, as the per_page branch at :2434 already does), scope must stay resolved before cache (keep reportWarehouseScope() ordering), export-excel keeps the 10,000-row cap. Do NOT simply cap rows silently on the un-paged branch - FE KPIs would silently diverge from the table.

**Cách kiểm chứng:** Before/after with scripts/perf/bench-be.mjs on /reports/movement-trace and /reports/document-history: bytes, warm/cold ms; EXPLAIN (not ANALYZE) showing Index Scan on occurred_at; k6 flows.js 'reports' group p95 at baseline profile; Valkey `INFO memory` used_memory during the report mix.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### API-03 — FE fan-out: one screen open issues 10-30+ list/aggregate requests (fetchAllPages, per-SKU balance lookups, per-page aggregate recomputation)

**Thành phần:** FE report-data.ts / recipient-agency-api.ts / packing-label-api.ts / mvpOutboundService.ts + BE BaM1ReportController

**Bằng chứng:** report-data.ts:603-604 WARRANTY_PAGE_SIZE=100, WARRANTY_MAX_PAGES=10 used by useWarrantyDefects (:639), useWarrantyCases (:665), useComponentIssueDocuments (:693), useOutboundByRecipient (:810) ; recipient-agency-api.ts:594 COMPARISON_MAX_PAGES=30 for the comparison tab; packing-label-api.ts:221 fetchAllPages(LABEL_PRINT_EVENTS); mvpOutboundService.ts:815-830 fetchAvailableQtyBySku fires one GET /inventory/balances?product_id= per unique SKU (Promise.all, no limit) from AlignedOutboundDetailModal.tsx:231, OutboundIntakeModal.tsx:393, ComponentIssueModal.tsx:232 - avg 11.6 lines/doc, max 101 (psql). BE: BaM1ReportController.php:39 caches outbound-by-recipient WITH page in key and recomputes count + sum + distinct count + by_sku + by_recipient aggregates (5 aggregate queries) for EVERY page (:118-140); /warranty-cases and /warranty-component-issues are uncached (cache-coverage.json false; CanonicalMvpController.php:362, 331). paginate-all.ts caps concurrency at 6 so 10 pages = 1 + 2 waves.

**Nguyên nhân gốc:** Report/list contracts return only what a table needs, so the FE reconstructs the full set client-side; per-page cache keys duplicate whole-set aggregates.

**Ảnh hưởng ở 10.000 người dùng:** A single warranty report open = up to 30 requests (3 endpoints x 10 pages) each costing 2 SQL + RBAC; an outbound detail modal on a 100-line document = 101 requests. With 10k users, 1% opening such a screen per minute adds 50-170 req/s of list traffic that no cache dedups (warranty lists uncached, 20 s TTL on balances). None of these authenticated endpoints has a rate limiter.

**Hướng sửa đề xuất:** BE: add batch/summary endpoints - `GET /inventory/balances?product_ids[]=` (single query, keep scope + 20 s cache) and a `summary=1` mode or a separate `/reports/outbound-by-recipient/summary` so by_sku/by_recipient/totals are computed once per (filters, scope) with page rows cached separately; give /warranty-cases and /warranty-component-issues the same cachedReadPayload(search/stock_short) treatment (scope + actor rules per CachesReadPayload). FE: replace fetchAllPages callers with the summary endpoints and keep the 6-concurrency cap. Keep PII masking (warranty.pii.view) and data-scope checks identical; do not raise per_page caps above 200.

**Cách kiểm chứng:** Browser network log on /reports/warranty and outbound detail modal before/after (request count, total bytes); k6 flow for the same sequence measuring server req/s per screen-open; cache-coverage.mjs must show new keys for warranty lists.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### DB-01 — stock_movements has no index leading with occurred_at: movement list, dashboards, XNT and trace are full scans + sort

**Thành phần:** PostgreSQL / Modules/Warehouse StockMovementController + OperationalReportController

**Bằng chứng:** StockMovementController.php:89 `->orderByDesc('occurred_at')` + :102 `paginate()` → `select count(*) from stock_movements` + `select * from stock_movements order by occurred_at desc limit 20 offset 0`. OperationalReportController.php:754-761, 831-838, 938-940, 994-999, 1028-1030, 1870-1877, 1922-1928, 2391-2395 all filter `occurred_at BETWEEN`. pg_indexes for stock_movements (10 indexes): idx_sm_document(stock_document_id), idx_sm_ledger_dimension(warehouse_id,product_id,stock_status) INCLUDE(qty_delta,occurred_at), idx_sm_wh_product_time(warehouse_id,product_id,occurred_at), correction_chain, original, source, business_key, movement_no, pkey, stock_status — none leads with occurred_at. EXPLAIN (default): `Limit -> Sort (Sort Key: occurred_at DESC) -> Seq Scan on stock_movements` for unfiltered, `movement_type='RECEIPT'`, and `warehouse_id=<real id>` variants; `SET enable_seqscan=off` still yields Seq Scan (cost 1e10) = no usable index. Period aggregate with seqscan off degrades to `Index Scan using idx_sm_wh_product_time Index Cond: occurred_at >= … <= …` (non-leading column = whole-index scan). pg_stat_user_tables: stock_movements seq_scan=1,411, seq_tup_read=10,370,850 (= 1,411 × 7,350: every scan reads the whole ledger).

**Nguyên nhân gốc:** Ledger indexes were designed for the (warehouse, product) dimension (WWHN-231 aggregate-then-join) and never for time ordering; the list endpoint and all KPI/period reports order or range-filter on occurred_at alone.

**Ảnh hưởng ở 10.000 người dùng:** stock_movements is append-only and is the largest table by design. At 10k users (≈250–500 req/s by the LOAD_TEST_2026-09-06 §4 arithmetic, ~30% of the k6 mix hitting these shapes) every list page and every dashboard KPI scans and top-N-sorts the entire ledger; with work_mem=8 MB sorts of a multi-million-row table spill to disk. Combined with DB-05 (cache invalidated on every post) this becomes the dominant PG CPU/IO consumer. Exact latency NOT MEASURED (dev has 7,350 rows).

**Hướng sửa đề xuất:** Add `CREATE INDEX CONCURRENTLY idx_sm_occurred_at_id ON stock_movements (occurred_at DESC, id DESC);` (serves ORDER BY + the paginator's stable secondary key and all `occurred_at BETWEEN` ranges) and `CREATE INDEX CONCURRENTLY idx_sm_wh_occurred_at ON stock_movements (warehouse_id, occurred_at DESC);` for warehouse-scoped lists/dashboards. Optional: `(occurred_at) INCLUDE (movement_type, qty_delta, warehouse_id)` to make dashboard sums index-only. Expected plan: `Index Scan Backward using idx_sm_occurred_at_id … Limit 20` (Sort node disappears); period aggregates become range Index/Bitmap scans instead of Seq Scan. Write amplification: +1–2 btrees on an insert-only table with a monotonically increasing key (right-most leaf appends, ~40–60 bytes/row each) — cheap. Keep idx_sm_wh_product_time (serves warehouse+product lists: EXPLAIN shows Index Scan Backward). Do NOT touch business_key/movement_no uniqueness (idempotency backstops, CurrentBaselineStockService.php:1546).

**Cách kiểm chứng:** Before/after EXPLAIN (ANALYZE, BUFFERS) on the three list variants and on the dashboard-quick totals query against a copy of the DB seeded to ≥1M movements (HoaNamBulkDemoSeeder); pg_stat_user_tables.seq_scan / seq_tup_read delta for stock_movements per 1,000 k6 requests (baseline profile) should drop to ~0; k6 `inventory` and `dashboard` group p95 before/after.

**Trạng thái 07/09/2026:** **ĐÃ SỬA** — migration `2026_09_07_000000_add_stock_movements_time_indexes`. EXPLAIN trước: `Limit → Sort (occurred_at DESC) → Seq Scan`; sau: `Limit → Index Scan using idx_sm_occurred_at_id` (nút Sort biến mất). Tổng hợp theo kỳ trước: `Seq Scan + Filter`; sau: `Index Scan … Index Cond: occurred_at >= … <= …`.

---

### API-04 — /inventory and /inventory/balances cache key omits warehouse scope while the model applies ScopesWarehouseData - cross-scope cache sharing

**Thành phần:** Modules/Warehouse/app/Http/Controllers/InventoryBalanceController.php

**Bằng chứng:** InventoryBalanceController.php:78-96 key = CacheKey::of('warehouse:inventory-balance:list', ['f'=>hash(filters),'per_page','page'], stock:version) with no scope/actor part; cache-coverage.json sample key `warehouse:inventory-balance:list:f-f512...:per_page-10:page-1:v1` (no scope segment, compare stock-movements `...:scope-global:v1`). InventoryBalance.php:8,12 `use ScopesWarehouseData` whose global scope adds whereIn(warehouse_id, $user->warehouseScopeIds()) (ScopesWarehouseData.php:19-36). EnforceInventoryWarehouseScope only validates an explicit warehouse_id param (middleware :46-52).

**Nguyên nhân gốc:** Controller predates CachesReadPayload trait (which mandates scope in key, rule 1) and was never migrated.

**Ảnh hưởng ở 10.000 người dùng:** Functionally: a scoped user can be served a GLOBAL user's page (rows from warehouses outside their scope) or vice versa within the 20 s TTL - a data-scope violation, not just staleness. Performance-wise it also blocks the obvious fix for this hot endpoint (k6 weight 8+7, highest in the inventory group) which is raising the TTL/using stampede lock safely.

**Hướng sửa đề xuất:** Migrate index() to CachesReadPayload::cachedReadPayload('warehouse:inventory-balance:list', readFiltersWithPaging($request), builder, 'stock_short', 'stock:version') so scope signature is in the key (the trait already does this); keep validation and EnforceInventoryWarehouseScope 403 before the cache read. Do not remove the global scope or change the response shape.

**Cách kiểm chứng:** Integration test: user A (global) warms page 1, user B (scoped to one warehouse) requests same filters -> must not receive A's rows; cache-coverage.mjs sample key must contain scope-<sig>.

**Trạng thái 07/09/2026:** **ĐÃ SỬA** — khoá cache của `/inventory` nay mang `scope-<chữ ký phạm vi kho>` (InventoryBalanceController::index + helper `warehouseScopeIds()`), cùng quy ước với `CachesReadPayload`. Đây là lỗi RÒ DỮ LIỆU giữa các phạm vi kho, không chỉ là hiệu năng.

---

### API-05 — Cache-coverage figure is inflated by throttle keys: anonymous PV/public catalog endpoints are NOT payload-cached and run 2 correlated subqueries per row

**Thành phần:** PvCatalogReadModel / PublicCatalogReadModel + scripts/perf/cache-coverage.mjs

**Bằng chứng:** cache-coverage.json for /pv/products, /pv/catalog, /public/products, /public/home: cached=true, keysAdded=2, sample `wms-hoanam-database-wms62d896aee3697c0289d2214eb361eb8c` = Laravel cache prefix + sha1 throttle key, not a payload key; grep for rememberStampede/remember in PvPublicCatalogController.php, PvCatalogReadModel.php, PublicApi/* returns nothing. So 13 of the '55 cached' endpoints (all under throttle:pv-public / throttle:public-api) are uncached: real coverage 42/84. PvCatalogReadModel.php:172-195 selects `(SELECT COALESCE(SUM(b.available_qty),0) FROM inventory_balances b WHERE b.product_id = s.sku_id AND stock_status='AVAILABLE')` and a MAX(updated_at) twin per row; EXPLAIN shows SubPlan 1/2 executed per output row via uk_inventory_balances_wh_product_status plus a third SubPlan on pv_product_profiles for the published filter; PublicCatalogReadModel.php:33 uses the same pattern. pg_stat_user_tables: pv_product_profiles idx_scan 20,690,439 and inventory_balances seq_scan 8,924,965 (attribution NOT MEASURED - pg_stat_statements not installed). NoStoreApiResponses.php forces `Cache-Control: no-store, private` on these anonymous responses too.

**Nguyên nhân gốc:** Public read models were built for freshness (available_qty inline) without an app-cache layer; the coverage script counts any new Valkey key as 'cached'.

**Ảnh hưởng ở 10.000 người dùng:** These are the only endpoints reachable without login, so they see bot/crawler and storefront traffic on top of WMS users; per-IP throttling does not cap aggregate load. Each page costs 1 count + 1 query with 3 subplans x 20 rows; there is no CDN/browser caching either.

**Hướng sửa đề xuất:** Wrap paginateProducts/findPublishedProduct/home/facets in CacheService::rememberStampede with a 'search' or 'reference' policy keyed by normalized filters+page (no scope needed - anonymous), bump a `pv:version` namespace from publish/unpublish and from mutateBalance; consider LEFT JOIN LATERAL or a pre-aggregated available_qty column refreshed by mutateBalance. Relax NoStoreApiResponses for /api/v1/public/* and /api/v1/pv/* to `public, max-age=30-60` so a CDN/browser can absorb repeats (keep no-store for authenticated routes - WWHN-325). Fix cache-coverage.mjs to ignore keys matching the throttle pattern. Keep the 404-masking rule for unpublished SKUs and the per-IP throttles.

**Cách kiểm chứng:** cache-coverage.mjs after filtering throttle keys should list these endpoints with a real namespaced key; bench-be.mjs warm ms for /pv/products (currently 110 ms) should drop to the framework floor; k6 'public' group p95.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

## Nhóm B — đã bị bác bỏ khi phản biện (1)

### API-02 — Every authenticated request loads the user twice (auth:api guard + CheckPermission re-authenticate) and resolves RBAC on two different User instances

> The file:line evidence is accurate and the extra work is real, but the claim's central mechanism ("two different User instances, RBAC memos not shared, is-super-admin + warehouse-scopes GETs repeated in the controller") is wrong, and the impact is overstated by ~3x on Redis and by an order of magnitude in relevance to the 10k target.
> 
> What is real: CheckPermission.php:27-35 calls JWTAuth::setToken(bearer)->authenticate() on every permission route, all of which sit inside auth:api groups (Warehouse/routes/api.php:84-85, Auth/routes/api.php:28, mini_app.php:33, import.php:8, governance.php:10, AuditLog:6, Notification:7; priority Authenticate→CheckPermission at bootstrap/app.php:79-82). JWTAuth.php:61-69 authenticate() → Providers/Auth/Illuminate.php:54-57 byId() → JWTGuard.php:215-224 onceUsingId() → EloquentUserProvider.php:52-58 retrieveById → second `select * from "app_user" where "user_id" = $1 and "app_user"."deleted_at" is null limit $2`. Because blacklist is enabled (config/jwt.php:224, no .env override) the second decode also costs one Valkey GET (Manager.php:96-110 → Blacklist.php:128-130 → Storage/Illuminate.php:95-98) plus one HS256 verify. So per request: +1 PK SELECT, +1 Redis GET, +1 signature check.
> 
> What is wrong: Providers/Auth/Illuminate.php:14,32 is constructed with Illuminate\Contracts\Auth\Guard, which Laravel aliases to `auth.driver` = `$app['auth']->guard()` = the DEFAULT guard (vendor AuthServiceProvider.php:39, Application.php:1648); config/auth.php:14 default guard is `api` (no AUTH_GUARD in .env, no app rebinding of Guard::class — grep empty). AuthManager caches guards, so Illuminate::$auth IS the same JWTGuard object auth('api') returns, and onceUsingId() → setUser($userB) (GuardHelpers.php:85-90) REPLACES the guard's user. Everything after CheckPermission — route middlewares, controllers, CachesReadPayload::readCacheScopeIds() (line 64), ScopesWarehouseData global scope (line 20) — receives instance B, the same one hasPermission() ran on, so isSuperAdminMemo (User.php:103-105) IS shared and is-super-admin is not re-fetched. The only code that sees instance A is the global middleware stack (bootstrap/app.php:66-73); EnforceActiveAccountStatus/EnforcePasswordChangeRequired read plain columns, and only EnforceInventoryWarehouseScope (6 WWHN-211 read paths, lines 142-161) computes RBAC on A, losing 1 is-super-admin GET (+1 permissions GET on trace paths) on those 6 endpoints only. The warehouse-scopes GET is not a repeat caused by the swap either: User::warehouseScopeIds() (User.php:156-175) has no instance memo at all, so every scoped query and every readCacheScopeIds() call issues its own GET for non-super-admins regardless — the proposed fix would not change that.
> 
> Measured magnitude: pg_stat_statements (reset 2026-09-03 07:24Z) shows the lookup at 255,962 calls / mean 0.105 ms in wms_hoanam plus 106,271 calls / 0.035 ms in wms_hoanam_perf: 21.6% of all 1,674,214 statements but only 6.75% (30.5 s of 452.2 s) of total DB exec time. Removing one of the two lookups saves ~0.1 ms of DB time and one ~0.1-0.2 ms Redis round trip (LOAD_TEST_2026-09-07.md:42) per request — roughly 0.3-0.6 ms wall-clock (end-to-end NOT MEASURED) against the perf-profile p50 37 ms / p95 98 ms (LOAD_TEST_2026-09-07.md:107), i.e. ~1% of p50. At 1,250 req/s that is ~0.13 core of Postgres CPU and ~0.6 of one php-fpm worker out of 96 (2×48), not a throughput lever; the documented bottleneck at saturation is the PHP request path with Postgres at 0.8 core and Valkey at 7% (LOAD_TEST_2026-09-07.md:18-21), and the stack tops out at 116.6 req/s with 2 replicas (line 109), ~10x short of 1,250 req/s. Realistic think time (20-60 s per LOAD_TEST_2026-09-06.md:96) further shrinks the absolute op counts the claim projects.
> 
> Fix semantics: in production (php-fpm, fresh Application per request) using auth('api')->user() first is behaviour-neutral — auth:api already yields the canonical 401 (bootstrap/app.php:99), the guard path is stricter than authenticate() (validateSubject/lock_subject, JWTGuard.php:389, config/jwt.php:195), and DenyAuditRecorder/AuthCache bumps are untouched. However this exact change was already attempted and reverted: PERF_TUNING.md:142-150 records 8 security/RBAC tests going red (403 DATA_SCOPE_DENIED instead of 428). Root cause is a test-harness artifact, not a production rule: fixtures call auth('api')->login() (81 occurrences across 62 test files) → JWTGuard::login (JWTGuard.php:134-140) leaves a stale user on the guard across requests within one test, and today's JWTAuth::authenticate() implicitly re-syncs the guard to the request's Bearer via setUser; Wwhn202OutboundBalanceEvidenceTest.php:367-372 documents this and works around it with unsetToken()+forgetGuards(). The claim ignores this documented rejection and the fixture work the fix requires.
> 
> Net: a real but ~1% per-request trim that cannot move p95/p99 or the throughput ceiling, with a known test-suite cost. P2 at most. | - `app_user` is a VIEW (pg_class relkind 'v') over table `app_users` (`SELECT id AS user_id, ..., NULL::timestamptz AS deleted_at ... FROM app_users`), 77 live rows, 208 kB; EXPLAIN of the lookup: `Index Scan using ux_erd_canonical_key_app_users on app_users (cost=0.14..2.36 rows=1)`; `app_users` carries three redundant unique btree indexes on `id` (pk_erd_app_users, ux_erd_mirror_app_users_id, ux_erd_canonical_key_app_users) — harmless for reads, extra write cost only.
> - pg_stat_statements rows for `select * from "app_user" where "user_id" = $1 and "app_user"."deleted_at" is null limit $2`: dbid 16384 (wms_hoanam) 255,962 calls, mean 0.105 ms, min 0.014, max 217.77, 2 shared_blks_hit/call; dbid 4104197 (wms_hoanam_perf) 106,271 calls, mean 0.035 ms, 1 blk/call. Cluster totals 1,674,214 calls / 452,197.6 ms. pg_stat_user_tables app_users: seq_scan 265,245, idx_scan 306,161.
> - Saved k6 results (load/results/*.json) sum to 145,497 http_reqs (baseline-perf-1replica 13,682; baseline-perf-local-1replica 21,305; -2replica 21,305; plateau150-instrumented 33,026; smoke 76; target-think20-local-2replica 56,103); 362k lookups vs ≥145k requests (+unsaved 09-06 runs, dev traffic) is consistent with ~2 lookups/request but not a proof.
> - tymon/jwt-auth 2.3.0 (composer.lock:8720-8721); `tymon.jwt` (guard's JWT) and `tymon.jwt.auth` (facade's JWTAuth) are distinct singletons (AbstractServiceProvider.php:246-270) — the "hidden side effect" the tests depend on is setUser() on the shared api guard, not the token on the singleton.
> - JWTGuard::user() (JWTGuard.php:69-81) memoizes $this->user, so the first resolution happens in global EnforceActiveAccountStatus (ApiAuthContext.php:65-74) and Authenticate/EnforcePasswordChangeRequired/EnforceInventoryWarehouseScope reuse it; exactly one swap occurs, at CheckPermission.
> - Genuine residual Redis churn the claim misattributes: User::warehouseScopeIds() (User.php:156-175) has no per-instance memo, so for non-SUPER_ADMIN users every query on StockMovement/InventoryBalance (ScopesWarehouseData.php:17-38, 2 models) and every readCacheScopeIds() call performs a `warehouse-scopes` GET via CacheService::remember → Cache::get (CacheService.php:64-81, 169-192). Adding an instance memo there (same invalidation semantics as isSuperAdminMemo) would remove more GETs per request than the CheckPermission change and touches no auth flow — but it is also sub-millisecond per GET and not a 10k lever.
> - Per-request wall-clock cost of one PK SELECT round trip through Docker: NOT MEASURED (only server-side exec time above); per-request effect of the fix on p95/p99: NOT MEASURED.


## Nhóm C — CHƯA phản biện, mức P1 (36)

### DB-02 — Public/PV catalog runs a correlated inventory_balances subquery on product_id with no leading index — O(SKUs × balances) per request, already 8.9M seq scans / 2.6B tuple reads on a 293-row table

**Thành phần:** PublicCatalogReadModel / PvCatalogReadModel / sku view (PostgreSQL)

**Bằng chứng:** PublicCatalogReadModel.php:369-373: `->where('s.published_to_app', true)->whereRaw('(SELECT COALESCE(SUM(b.available_qty),0) FROM inventory_balances b WHERE b.product_id = s.sku_id AND b.stock_status = 'AVAILABLE') > 0')`; PvCatalogReadModel.php:174-176 (two such subqueries in SELECT) and :248 (`in_stock` filter); CanonicalMvpController.php:469 `leftJoin('inventory_balances as b','b.product_id','=','s.sku_id')`. `sku` view definition (pg_get_viewdef): `published_to_app = COALESCE((SELECT p.published FROM pv_product_profiles p WHERE p.sku_id = s.id), false)`. Indexes on inventory_balances: only `idx_erd_inventory_balances_sku_id_stock_status` leads with the SKU column; `(warehouse_id, product_id, stock_status)` needs warehouse first. EXPLAIN of the catalog query: `Index Scan using idx_erd_skus_sku_type_status on skus s … Filter: (COALESCE((SubPlan 1), false) AND ((SubPlan 2) > 0))` with `SubPlan 2 -> Aggregate -> Index Scan using uk_inventory_balances_wh_product_status Index Cond: ((product_id = s.id) AND (stock_status='AVAILABLE'))` — a non-leading-column condition, i.e. a whole-index scan executed once per ACTIVE SKU row. pg_stat: inventory_balances seq_scan=8,924,965, seq_tup_read=2,615,014,745; uk_inventory_balances_wh_product_status idx_scan=6,201,083 (a 293-row table); pv_product_profiles.uk_pv_product_profile_product idx_scan=20,690,299 (SubPlan 1). Data check: `sku_id IS DISTINCT FROM product_id` = 0 of 293 balances and 0 of 7,350 movements.

**Nguyên nhân gốc:** The catalog filters on the `product_id` mirror column (no leading index) instead of `sku_id` (indexed), and pushes IN_STOCK + PUBLISHED into per-row scalar subqueries via the `sku` view; PostgreSQL cannot decorrelate them.

**Ảnh hưởng ở 10.000 người dùng:** Per-row cost grows with the size of inventory_balances (whole index per SKU); request cost = ACTIVE SKUs × balance-index size. With e.g. 50k SKUs and 100k balance rows that is ~5×10^9 index-entry visits per catalog page. These endpoints are unauthenticated (only per-IP throttle `pv-public` 120/min, LOAD_TEST_2026-09-07 §2), so this is also an amplification lever for a single attacker. Latency NOT MEASURED at that volume.

**Hướng sửa đề xuất:** (a) Change the three subqueries to filter on `b.sku_id = s.sku_id` (existing `idx_erd_inventory_balances_sku_id_stock_status`; a migration should first assert `sku_id = product_id` everywhere — currently true) or add `CREATE INDEX CONCURRENTLY ON inventory_balances (product_id, stock_status) INCLUDE (available_qty)`; expected plan: SubPlan becomes a 1-row Index Scan with leading-column Index Cond. (b) Better: compute stock once per query — `LEFT JOIN LATERAL (SELECT SUM(available_qty) qty FROM inventory_balances b WHERE b.sku_id = s.sku_id AND stock_status='AVAILABLE')` or a `GROUP BY sku_id` CTE joined once — so the IN_STOCK rule is evaluated N times, not N × |balances|. (c) Replace `s.published_to_app = true` in WHERE with an inner JOIN on `pv_product_profiles.published = true` (EXPLAIN C shows the view subplan is only deferred to projected rows when it is not in WHERE). Keep both business rules (PUBLISHED and IN_STOCK mandatory) and keep stock out of the presentation SELECT as the comment at :355-359 requires. Write amplification of the optional index: +1 btree on the 293-row-per-warehouse balance table, negligible.

**Cách kiểm chứng:** EXPLAIN (ANALYZE) before/after on `/public/products?page=1` SQL: SubPlan loops should show `Index Cond: (sku_id = s.id)` with rows=1 and total buffers dropping by orders of magnitude; pg_stat_user_tables.seq_scan on inventory_balances per 100 catalog requests → 0; k6 `public` group p95.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### DB-03 — 189 redundant duplicate indexes (139 groups) — hottest write tables carry 2–4 copies of the same btree; inventory_balances.updated_at index defeats HOT updates on the most contended rows

**Thành phần:** PostgreSQL schema (ERD mirror migrations)

**Bằng chứng:** Catalog query grouping pg_index by (indrelid, indkey, indpred, indexprs): total_indexes=594, dup_groups=139, redundant_indexes=189, all_idx_size=84 MB of a 330 MB DB. Hot examples: inventory_balances 10 indexes, 4 identical UNIQUE on (warehouse_id, sku_id, stock_status) [uk_inventory_balances_wh_sku_status, ux_erd_doc_inventory_balances_a7d7fe67bf, ux_erd_inventory_balance_bucket, ux_erd_inventory_balances_a7d7fe67] + 2 on (id); physical_codes 20 indexes / 9 redundant (code_value ×3, hardware_uid ×3, (item_id,status) ×2, (container_id,status) ×2, id ×2, partial primary-carrier ×2 each); item_instances 14 / 3 (serial_number ×2, item_code ×2, id ×2); pv_product_profiles 10 / 5; scan_events id ×3 (672 kB); posting_events id ×3 + (resource_type,resource_id,posting_action) ×2; inbound_lines id ×3 (8.3 MB). pg_stat_user_indexes: the copies sit at idx_scan=0 (uk_inventory_balances_wh_sku_status 0, ux_erd_inventory_balances_a7d7fe67 0, ux_erd_physical_codes_code_value 0, physical_codes_code_value_unique 0 — planner picks ux_erd_doc_physical_codes_40f937d566). `idx_erd_inventory_balances_updated_at` exists and every balance write sets `updated_at` (CurrentBaselineStockService.php:1593-1601, AlignedOutboundStockService.php:184-194, CurrentBaselineReversalService.php:578-586) → update of an indexed column = non-HOT update touching all 10 indexes. HOT ratio NOT MEASURED (n_tup_upd=0 since stats reset).

**Nguyên nhân gốc:** Three generations of index naming (`*_unique`/`uk_*`, `ux_erd_doc_*`, `ux_erd_*`/`ux_erd_mirror_*`/`ux_erd_canonical_key_*`) were added by successive ERD-alignment migrations without dropping predecessors.

**Ảnh hưởng ở 10.000 người dùng:** Every scan inserts into physical_codes + item_instances + scan_events (~47 btrees maintained instead of ~30) and every post updates inventory_balances rows through 10 indexes with no HOT path, roughly doubling WAL and index-page churn on the exact rows that are row-locked (DB-10) — lock hold time grows with index maintenance. Autovacuum work and shared_buffers pressure scale with the redundant 84 MB → hundreds of MB at production volume.

**Hướng sửa đề xuất:** One migration using `DROP INDEX CONCURRENTLY` for each duplicate group, keeping exactly one index per definition: prefer the one referenced by a constraint (check `pg_constraint.conindid` — dropping a constraint-backed index needs `ALTER TABLE … DROP CONSTRAINT`) so uniqueness/idempotency backstops (code_value, serial_number, business_key, movement_no, (posting_action, idempotency_key)) are preserved. Drop `idx_erd_inventory_balances_updated_at` unless a query needs it (only user found: `MAX(b2.updated_at)` per SKU in PvCatalogReadModel.php:176, which is per-sku anyway) to restore HOT updates; alternatively set `fillfactor=70` on inventory_balances. Do not drop `stock_movements_business_key_unique` / `movement_no_unique` (0 scans but they enforce DUPLICATE_STOCK_EFFECT).

**Cách kiểm chứng:** Re-run the duplicate-group catalog query → 0 groups; pg_stat_user_indexes idx_scan unchanged for surviving indexes after a k6 baseline run; `n_tup_hot_upd / n_tup_upd` on inventory_balances after a k6 write profile (ENABLE_WRITES) should rise from 0 toward >0.9; WAL bytes per posted document (`pg_stat_wal` delta) before/after.

**Trạng thái 07/09/2026:** **KHÔNG SỬA — CÓ CHỦ ĐÍCH.** 189 chỉ mục trùng (139 nhóm) là thật (vd `inventory_balances` có 4 chỉ mục unique giống hệt trên `(warehouse_id, sku_id, stock_status)`), nhưng chúng do migration ERD tạo và được các test hợp đồng khẳng định (`ErdBusinessInvariantParityTest`, `Wwhn199ReservationIndexContractTest`, `Wwhn289ErdMirrorTypeSafetyTest`). Bỏ chúng sẽ phá hợp đồng schema — cần quyết định của chủ ERD trước, không phải việc của một đợt tối ưu.

---

### DB-04 — Trace-by-code resolves codes with LOWER(CAST(col AS TEXT)) = ? — no expression index, so a miss cascades sequential scans over physical_codes, item_instances, containers and skus

**Thành phần:** StockMovementController::traceByPhysicalCode / resolve helpers

**Bằng chứng:** StockMovementController.php:662-707: `whereRaw('LOWER(CAST(code_value AS TEXT)) = ?')` / `LOWER(CAST(hardware_uid …))` on physical_codes, then `LOWER(CAST(item_code …))` / `LOWER(CAST(serial_number …))` on item_instances, then containers.container_code, then sku.sku_code. pg_indexes: only plain btrees on these columns (code_value ×3, hardware_uid ×3, serial_number ×2, item_code ×2, container_code ×3, sku_code ×2) — no `lower()` expression index. EXPLAIN: `Seq Scan on physical_codes Filter: (lower((code_value)::text) = 'box-abc-0001')`; with `enable_seqscan=off` still Seq Scan (cost 1e10) → no index matches; same for item_instances (`Filter: lower(item_code) = … OR lower(serial_number) = …`). Contrast: ScanResolver.php:15 exact `where('code_value', trim($rawCode))` → `Index Scan using ux_erd_doc_physical_codes_40f937d566`. pg_stat: physical_codes seq_scan=148 (450,806 tuples), item_instances seq_scan=91 (413,959).

**Nguyên nhân gốc:** Case-insensitive matching implemented as a runtime expression instead of normalised storage or an expression index; the existing unique indexes cannot be used.

**Ảnh hưởng ở 10.000 người dùng:** Trace is an operator screen used per incident, not per scan, but each unresolved or SKU-level lookup reads four full tables; with ~1M physical codes and ~1M items that is 4 full heap scans (hundreds of MB) per trace request, holding a php-fpm worker and a PG backend for seconds. Not measured at volume.

**Hướng sửa đề xuất:** Codes are already generated upper-case (BOX-<SKU>-<seq>, MV-…); normalise the needle to the stored case and use exact equality (reuses existing unique indexes, zero write cost), falling back to `lower()` only if a legacy mixed-case population is proven. If case-insensitivity must stay: `CREATE INDEX CONCURRENTLY ON physical_codes (lower(code_value)); … (lower(hardware_uid)); ON item_instances (lower(item_code)); (lower(serial_number)); ON containers (lower(container_code)); ON skus (lower(sku_code));` Expected plan: `Index Scan … Index Cond: (lower(code_value) = $1)`. Write amplification: +1 small btree per table on insert paths (physical_codes/item_instances are written on every scan — prefer the exact-match option). Keep the resolution order and the 404/409 semantics.

**Cách kiểm chứng:** EXPLAIN (ANALYZE) of the four resolve statements before/after with a non-existent code (worst case); pg_stat_user_tables.seq_scan delta on physical_codes/item_instances during trace usage → 0.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### DB-05 — Single global cache namespace stock:version is bumped by every movement, so under any write load the heaviest inventory/movement/dashboard queries hit PostgreSQL almost uncached

**Thành phần:** app/Support/Cache (CacheVersion namespaces) + mutateBalance

**Bằng chứng:** CurrentBaselineStockService.php:1638 `DB::afterCommit(fn () => $this->cacheService()->bumpVersion('stock:version'))` inside `mutateBalance()` — called once per document line for receipt, issue, reversal and component issue (comment :1633-1637: 'điểm chốt DUY NHẤT'). Consumers: InventoryBalanceController.php:80 `currentVersion('stock:version')`; StockMovementController.php:70-77 `'stock:version'`; ReadCache trait `cachedReadPayload()` default `$versionNamespace = 'stock:version'` (app/Support/Cache/Concerns, :125-143) used by every controller that does not override it. TTLs config/wms_cache.php:12-19: stock_short 20 s, dashboard 60 s, report 300 s, search 120 s. LOAD_TEST_2026-09-06 §5: measured hit ratio ~35% even with zero writes (version-key GET always misses). rememberStampede uses `Cache::lock` with wait 5 s / ttl 10 s (config/wms_cache.php:26-28).

**Nguyên nhân gốc:** Invalidation granularity is global (one integer for all warehouses, all SKUs, all read models) while the write rate is per-line; correctness is guaranteed by PG anyway (post/issue checks read PG with FOR UPDATE), so the cache is doing less work than its TTLs suggest.

**Ảnh hưởng ở 10.000 người dùng:** At 10k users a few posts per second are inevitable; each bump orphans every inventory/movement/report entry system-wide, so lists (DB-01), balances and dashboards are rebuilt from PG within milliseconds of every post, and concurrent readers of the same key serialise on the stampede lock (5 s wait) — the exact 'workers waiting on something that is not SQL' signature in LOAD_TEST_2026-09-07 §1. DB load for these endpoints scales with write rate × read rate instead of read rate / TTL.

**Hướng sửa đề xuất:** Partition the namespace: `stock:version:{warehouse_id}` bumped only for the warehouse mutated (mutateBalance already has $warehouseId), with readers composing their key from the warehouses in scope (readCacheScopeIds already exists); keep a coarse `stock:version` only for GLOBAL-scope reports. Decouple dashboards from versioning (fixed 60 s TTL is already the accepted staleness). Do not add cache to post/issue availability checks, RBAC or idempotency paths — they must keep reading PG. This is a cache-keying change, no response shape or business rule change.

**Cách kiểm chứng:** Valkey `keyspace_hits/keyspace_misses` and `pg_stat_user_tables.seq_scan` on stock_movements/inventory_balances per 1,000 requests during a mixed k6 run (baseline reads + ENABLE_WRITES posts at ~2/s) before/after; count of `cache.rebuild` log lines per minute.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### DB-06 — Report endpoints load the full result set and paginate in PHP; list endpoints run count(*) + OFFSET on every page

**Thành phần:** OperationalReportController (5 report builders) + Laravel paginate() on ledger lists

**Bằng chứng:** OperationalReportController.php:2543-2566 `paginateReport()` slices `collect($report[$rowsKey])->forPage()` in memory; builders materialise everything first: buildInventoryBalance :1279-1283 (`$balanceQuery->get()` + full `ledgerAggregateWithMasterQuery()->get()`), buildXnt :1640-1703 (`stock_movements WHERE occurred_at <= to GROUP BY wh,sku,status` over the whole ledger then `$query->get()`; EXPLAIN: `HashAggregate -> Seq Scan Filter: occurred_at <= …`), buildDocumentHistory :2150 (`->get()` all documents in range, then :2204-2213 all their lines), buildMovementTrace :2450 (`->get()` all movements in period unless per_page is sent; SQL paging exists only at :2431-2449). PERF_AUDIT §6: 5,956 rows = 4.3 MB payload today. Lists: StockMovementController.php:102, InventoryBalanceController.php:~150, OutboundDocumentController.php:261, BaseRepository.php:136 all use `paginate()` → `select count(*) as aggregate …` per page (EXPLAIN: `Index Only Scan using stock_movements_stock_status_index` over all 7,350 rows) + `OFFSET`. pg_stat: stock_document_lines seq_tup_read=39,588,216 (9,147 scans) and stock_documents 10,142,880 (27,120 scans).

**Nguyên nhân gốc:** Reports were written as whole-period snapshots (invariant: table, timeline and KPIs describe the same set), and XNT derives opening balances by re-summing the entire ledger on every call.

**Ảnh hưởng ở 10.000 người dùng:** Memory and transfer per request are proportional to rows in the period (php-fpm memory_limit × 48 workers → OOM risk; PERF_AUDIT §6 flags this as remaining risk), and PG performs a full-ledger aggregate per XNT/inventory-balance call every 300 s per filter combination (or every bump, see DB-05). count(*) on a multi-million-row ledger per list page is a full index-only scan.

**Hướng sửa đề xuất:** (1) Push pagination into SQL for document-history and inventory-balance the way movement-trace already does (`forPage` + separate bounded aggregate for KPIs), keeping the response contract (`pagination` block, KPI totals from a dedicated aggregate). (2) Keyset pagination `(occurred_at, id) < (?, ?)` for /stock-movements (needs DB-01 index) and drop or cache `total` (or use `count_estimate` from pg_class.reltuples for unfiltered pages). (3) XNT/opening balance: add a periodic opening-balance snapshot table (per warehouse/sku/status per period close) so the query scans only the period, not `occurred_at <= to` since the beginning — a data-model change to plan, not an index. Do not change KPI semantics (DEC-INV-001: ledger is authoritative) or the movement-trace-api.ts same-set invariant.

**Cách kiểm chứng:** Peak PHP memory (`memory_get_peak_usage`) and response bytes per report call at 100k-row periods on a seeded copy; EXPLAIN (ANALYZE, BUFFERS) of XNT aggregate before/after snapshotting; PG `tup_returned` per request from pg_stat_database delta.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CACHE-01 — One coarse `stock:version` namespace orphans ~42 read endpoints on every stock write (self-inflicted stampede)

**Thành phần:** app/Support/Cache/Concerns/CachesReadPayload.php, Modules/Warehouse/app/Http/Controllers/Concerns/CachesReportPayload.php, Modules/Warehouse/app/Services/CurrentBaselineStockService.php

**Bằng chứng:** CachesReadPayload.php:596 default `$versionNamespace = 'stock:version'`; CachesReportPayload.php:76-79 all 12 report namespaces keyed on `currentVersion('stock:version')`; OperationalReportController dashboard-quick/overview keys use `currentVersion('stock:version')`; InventoryBalanceController.php:82; grep count of `bumpReadCache('stock:version')|bumpVersion('stock:version')` outside tests = 36 sites, incl. CurrentBaselineStockService.php:1640 (`DB::afterCommit(bumpVersion('stock:version'))` inside mutateBalance(), i.e. per balance mutation), PutawayController.php:293, CurrentBaselineContainerFlowController.php:179/289/410/489, MiniAppInboundStagingController.php:262, ImportController.php:195, WarehouseController.php:41, DefectCatalogController.php:38; ClearWmsCache.php:47-51 documents that reports share it. Live Valkey: keyspace hit ratio 35.8% on a READ-ONLY k6 mix (LOAD_TEST_2026-09-06 §5), cmdstat_incrby=3,987 vs cmdstat_setex=14,708 in a 98-min window (INCR includes rate-limiter counters, so this is indicative, not a bump-rate measurement).

**Nguyên nhân gốc:** Invalidation granularity is one global counter for stock, documents, SKU master, mini-app, dashboards and all reports. Correct-by-construction (never stale) but every INCR makes every existing key in the namespace unreachable, so cache lifetime = time between any two stock writes anywhere in the system, not the configured TTL.

**Ảnh hưởng ở 10.000 người dùng:** With 10k users a fraction of whom are scanning/posting continuously, `stock:version` increments many times per second; the effective TTL of dashboard (26 queries), all 12 reports (movement-trace 4.3 MB rebuild) and all list endpoints drops to sub-second. Hit rate for ~42 endpoints tends to 0; every read becomes lock SET + full SQL rebuild + SETEX, and concurrent readers of the same key block 250 ms per poll (CACHE-07). The cache stops offloading the DB exactly when load is highest; PHP CPU (documented bottleneck) rebuilds and re-serializes MB payloads per request.

**Hướng sửa đề xuất:** 1) Split the namespace by aggregate and bump only what the write touches: `stock:balance`, `stock:movement`, `document:inbound`, `document:outbound`, `document:reversal`, `master:sku`, `master:warehouse`, `report:*`; a write path lists the namespaces it dirties. 2) For dashboards/reports accept TTL-bounded staleness instead of version-keying: key on (period, scope, floor(now/60s)) with 'dashboard'/'report' TTL and NO version, or keep version but bump it from a scheduler every N seconds (coalesced) rather than per write. 3) Coalesce bumps: collect dirty namespaces in a request-scoped set and INCR each once in a single afterCommit (mutateBalance currently bumps once per line). 4) Keep: afterCommit ordering (never bump before commit), scope/actor in keys, plain-array payloads, RBAC per-user keys. Do NOT reintroduce KEYS/FLUSH. Do NOT relax the 403-before-cache scope resolution.

**Cách kiểm chứng:** Add a mixed read/write k6 flow (load/k6/lib/flows.js is read-only today: include post-receipt/scan/putaway at 5-10% of iterations). Before/after: Valkey keyspace_hits/(hits+misses) per run, count of `cache.version.bump` and `cache.miss` log lines per minute for `warehouse:report:*` and `dashboard-*`, p95 of /reports/dashboard-overview and /reports/movement-trace during the mixed run, and pg_stat_statements calls/min for the dashboard aggregate queries. Target: report/dashboard hit ratio > 80% under mixed load.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CACHE-02 — Non-persistent Redis connections: one TCP connect + AUTH + SELECT per PHP request

**Thành phần:** config/database.php (redis.options.persistent), docker-compose.perf.yml backend environment

**Bằng chứng:** config/database.php:295 `'persistent' => env('REDIS_PERSISTENT', false)`; no REDIS_PERSISTENT in .env, .env.example, .env.perf.example, docker-compose*.yml or perf-entrypoint.sh (grep); bootstrap/cache/config.php:493 `'persistent' => false`; vendor PhpRedisConnector.php:167-185 uses `connect()` unless persistent. Live Valkey INFO: total_connections_received 164,387 vs cmdstat_select 164,391 (one SELECT per connection), total_commands_processed 978,915 -> 5.96 commands per connection, cmdstat_get 642,962 -> 3.91 GET per connection; connected_clients only 6 at rest (connections are short-lived). In-container Redis command latency measured 0.1-0.2 ms (LOAD_TEST_2026-09-07 §1).

**Nguyên nhân gốc:** Laravel defaults to non-persistent phpredis sockets; the perf profile switched to phpredis (PERF_AUDIT §3.2) but never enabled pconnect, so every php-fpm request pays TCP handshake + AUTH + SELECT before its first GET.

**Ảnh hưởng ở 10.000 người dùng:** At ~500 req/s (10k users, 20 s think time) this is ~500 connect/AUTH/SELECT sequences per second on Valkey and 3 extra round-trips (~0.3-0.6 ms) on every request, plus TIME_WAIT churn on the backend hosts. Not a Valkey capacity problem (maxclients 10,000 vs <=128 workers) but pure waste on the PHP-CPU-bound tier; also adds to the per-request Redis RTT count of 9 (hit) / 13 (miss).

**Hướng sửa đề xuất:** Set `REDIS_PERSISTENT=true` in docker-compose.perf.yml for backend/queue/reverb (php-fpm keeps one socket per child; with pm.max_requests 1000 the socket is reused across requests). Keep DB 0/1 split - phpredis keys persistent sockets by host:port:db so session/queue and cache do not share a socket. Keep max_retries/backoff. Do not enable persistent for CLI one-offs (irrelevant).

**Cách kiểm chứng:** Before/after on the perf stack under the same k6 baseline: INFO total_connections_received delta per 1,000 requests (expect ~0 instead of ~1,000), connected_clients ~= active php-fpm children, cmdstat_select delta, and /api/v1/products warm p50 (bench-be.mjs).

**Trạng thái 07/09/2026:** **ĐÃ SỬA (hồ sơ chịu tải)** — `REDIS_PERSISTENT=true` + `REDIS_TIMEOUT`/`REDIS_READ_TIMEOUT` = 2 s cho backend/queue/scheduler; `config/database.php` nhận hai khoá timeout mới, mặc định 0 để stack dev không đổi hành vi.

---

### CACHE-03 — JWT blacklist and rate-limit counters share the volatile-lru cache DB with multi-MB report payloads; tag ZSET never pruned

**Thành phần:** config/jwt.php, config/cache.php, docker-compose.yml/perf.yml valkey command

**Bằng chứng:** config/jwt.php:224 `blacklist_enabled` default true, :301 storage = Tymon Illuminate (Cache::tags('tymon.jwt')); .env JWT_TTL=43200 (30 days, memory note says temporary); SCAN sample in DB 1: key `wms-hoanam-database-wmsca7581d6d4749d8fd143ea956aa654aa02abe0ec:y1ooxIeOVSaXBCbK` ttl=2,581,751 s (sha1('tag:tymon.jwt:entries') = ca7581d6... verified) and `tag:tymon.jwt:entries` ttl=-1; valkey `maxmemory 268435456`, `maxmemory-policy volatile-lru` (docker-compose.yml:213-218, perf.yml:265-270); `cache:prune-stale-tags` not scheduled (grep routes/app/bootstrap = 0); report payloads up to 4.3 MB (PERF_AUDIT §6) and ~101 KB average per hit (INFO net_output/hits); evicted_keys currently 0.

**Nguyên nhân gốc:** Security state (token revocation) is stored as an evictable cache entry in the same keyspace and memory budget as bulk read-through cache. volatile-lru evicts the least-recently-READ volatile keys first; blacklist entries are only read when the revoked token is presented, so under memory pressure they are among the first to go.

**Ảnh hưởng ở 10.000 người dùng:** 10k users x report caches (100 KB-4 MB x filter combinations x scopes) will reach 256 MB (dev/perf default) quickly; once eviction starts, logged-out or admin-revoked tokens silently become valid again until their 30-day exp. Separately, `tag:tymon.jwt:entries` grows by one member per logout forever (no TTL, not evictable, never pruned).

**Hướng sửa đề xuất:** Move the JWT blacklist off the LRU cache: dedicated redis connection/store (separate Valkey instance or the `database` cache store) with `noeviction`, or disable the blacklist and cut JWT_TTL to the intended 60 min + refresh (memory note already flags 43200 as temporary). Schedule `cache:prune-stale-tags` hourly. Size `VALKEY_MAXMEMORY` from measured payload volume (CACHE-06) rather than 256 MB. Keep volatile-lru for the bulk cache DB so queue keys are never evicted.

**Cách kiểm chứng:** Fill DB 1 past maxmemory in staging (or set VALKEY_MAXMEMORY low), then: `INFO stats` evicted_keys > 0, present a logged-out token and observe 200 instead of 401; `ZCARD tag:tymon.jwt:entries` growth over a day of logins/logouts before/after scheduling prune.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CACHE-04 — Cache-per-keystroke: undebounced `keyword` fills 30-min/15-min caches with one key per typed prefix

**Thành phần:** FE src/modules/master-data/pages/CatalogMasterScreen.tsx; BE CurrentBaselineMasterController.php, BaseRepository.php (ProductRepository), CachesReadPayload::readFiltersWithPaging

**Bằng chứng:** FE CatalogMasterScreen.tsx:196 `useState("")` keyword -> :211 `keyword: keyword.trim()` -> :217-218 `useQuery({ queryKey: ['catalog-master', activeKind, listParams] })` with no debounce (file absent from `grep -i debounce`); other keyword+useQuery files without any debounce token: TrayOverviewPage.tsx, OutboundIntakeModal.tsx, ComponentIssueModal.tsx, inventory-flow-api.ts, report-data.ts (not individually verified). BE: CurrentBaselineMasterController.php:698-706 key includes `hashFilters($request->only(['status','keyword']))`, TTL `master` 1800 s (:34); ProductController.php:129 `paginate($request->all(), ...)` -> BaseRepository.php:87-91 hashes every query param, TTL `sku` 900 s; all 'search' endpoints key on `$request->query()` verbatim (CachesReadPayload.php:665-668). Observed sizes: catalog product_categories key 14,496 B, brands 6,304 B, sku list page 24,704 B. Valkey maxmemory 256 MB.

**Nguyên nhân gốc:** Server keys are built from the raw keyword and every query param, while the client sends a request per keystroke on at least one screen; long TTLs meant for reference data are applied to search results.

**Ảnh hưởng ở 10.000 người dùng:** Each user typing a 6-char search creates ~6 keys x 6-25 KB living 15-30 min; 1,000 such searches/hour ~ 36-150 MB/hour of dead keys competing with real hot keys for 256 MB under LRU (and each miss = lock + ILIKE query + SETEX). Sparse keys also drag the measured hit ratio down and inflate the `isCorrupted` walk cost per hit.

**Hướng sửa đề xuất:** FE: route CatalogMasterScreen (and the unverified list) through useTablePattern's `searchDebounceMs` / use-autocomplete's 300 ms debounce with minSearchLength >= 2 (pattern already exists in src/shared/hooks). BE: when `keyword` is non-empty use the `search` (120 s) policy instead of `master`/`sku`; whitelist the filter params that enter the hash (drop unknown params) in BaseRepository::paginate and CachesReadPayload; optionally skip caching for keyword length < 2. Keep page/per_page in keys for SQL-paged endpoints (rule 2 of the trait).

**Cách kiểm chứng:** Type a 10-char search on Catalog master and SKU list; sample `valkey-cli -n 1 --scan --pattern '*catalog-master*'` count and `used_memory` before/after; count `cache.miss` for those namespaces; confirm request count per typed word in browser devtools drops to 1-2.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### WRITE-01 — AFTER STATEMENT 'ERD mirror' triggers re-project entire tables on every write to stock_documents / stock_document_lines / stock_movements / idempotency_keys, inside the lock window

**Thành phần:** PostgreSQL triggers hn_erd_mirror_* (Modules/Warehouse/database/migrations/2026_08_10_000765_keep_legacy_runtime_and_erd_physical_schema_in_sync.php:425, 2026_08_16_133000_sync_idempotency_mirror_deletes.php) — fired by scan, Post Receipt, Post Issue, reversal, component issue, every idempotent mutation

**Bằng chứng:** Live catalog: `CREATE TRIGGER trg_erd_mirror_inbound_lines_from_stock_documents AFTER INSERT OR DELETE OR UPDATE ON stock_documents FOR EACH STATEMENT EXECUTE FUNCTION hn_erd_mirror_inbound_lines()`; same function also on stock_document_lines; `trg_erd_mirror_inbound_documents_from_stock_documents`, `trg_erd_mirror_outbound_lines_from_stock_documents` (+ on lines), `trg_erd_mirror_inventory_movements_from_stock_movements`, `trg_erd_mirror_idempotency_records_from_idempotency_keys` — all FOR EACH STATEMENT. Function bodies have NO row predicate: hn_erd_mirror_inbound_lines = `DELETE FROM inbound_lines c WHERE NOT EXISTS (SELECT 1 FROM inbound_lines__legacy_projection p WHERE p.id=c.id); INSERT INTO inbound_lines (...) SELECT ... FROM inbound_lines__legacy_projection ON CONFLICT (id) DO UPDATE SET ...` (all 13 columns rewritten for every row); hn_erd_mirror_inventory_movements = `INSERT INTO inventory_movements SELECT ... FROM inventory_movements__legacy_projection ON CONFLICT DO NOTHING`. EXPLAIN on the DELETE: `Hash Anti Join … Index Scan on inbound_lines c rows=2923 … Seq Scan on stock_document_lines l rows=4328 … Seq Scan on stock_documents d rows=246`; EXPLAIN inventory_movements__legacy_projection: `Seq Scan on stock_movements rows=7350`. Only hn_erd_mirror_outbound_documents is row-scoped (`WHERE id = v_document_id`). Measured amplification on the idempotency mirror (pg_stat_user_tables, current stats window): idempotency_keys n_tup_ins=5, n_tup_upd=5 → idempotency_records n_tup_upd=85, n_dead_tup=31 at 11 live rows (17 row-updates per idempotency write, each computing md5() twice per row). Statements per scan that fire them: UPDATE stock_documents version (CurrentBaselineStockFlowController.php:573), UPDATE stock_document_lines scanned_qty (CurrentBaselineStockService.php:117), DRAFT→SCANNING UPDATE (:93), plus INSERT+UPDATE on idempotency_keys (IdempotencyStore.php:98,:214) — i.e. ≥5 full-table re-projections per scan; Post Receipt adds one full scan of stock_movements per movement inserted (:1608 inside the per-line loop). The repo already recorded a functional side-effect of this design: migration 2026_08_12_170500 header: 'AFTER STATEMENT trigger on stock_documents that blanket-resyncs the ENTIRE legacy projection … every time ANY row is written'. No application code reads inbound_lines/inbound_documents/outbound_lines/inventory_movements (grep over Modules/*/app, app/ finds only DropsBaselineViewsAroundAlter.php).

**Nguyên nhân gốc:** The ERD-compatibility mirrors were implemented as statement-level 'full resync' triggers instead of incremental row-level (or transition-table) upserts. Because ON CONFLICT DO UPDATE writes every row even when unchanged, each statement produces a dead tuple per mirrored row and O(table) index maintenance, and it all executes inside the business transaction while stock_documents / stock_document_lines / inventory_balances FOR UPDATE locks are held.

**Ảnh hưởng ở 10.000 người dùng:** Cost of every scan and every post grows linearly with total history rather than with the document size: with ~1M stock_document_lines (a few months at 10k users) one scan rewrites ~1M inbound_lines rows 2-3 times and Post Receipt rescans all stock_movements once per line, while holding the document and balance locks — this alone puts the write path far outside any latency budget and drives autovacuum/WAL/bloat continuously. Exact per-write time at scale: NOT MEASURED (no write load test exists, see not_measured).

**Hướng sửa đề xuất:** Keep every mirror table and every immutability/evidence guard (ERD compliance), but make the mirroring incremental: (1) convert hn_erd_mirror_inbound_lines / _inbound_documents / _outbound_lines / _inventory_movements / _idempotency_records to FOR EACH ROW triggers that upsert/delete only `NEW.id`/`OLD.id` (the pattern hn_erd_mirror_outbound_documents already uses), or keep FOR EACH STATEMENT with `REFERENCING NEW TABLE AS new_rows OLD TABLE AS old_rows` and join the projection to the transition table; (2) add `WHERE inbound_lines.* IS DISTINCT FROM EXCLUDED.*` (or per-column IS DISTINCT FROM) to the DO UPDATE so unchanged rows are not rewritten; (3) for hn_erd_mirror_inbound_lines on stock_documents, mirror only lines of the affected document (join on stock_document_id IN transition rows). Add a static test asserting no `FOR EACH STATEMENT` mirror trigger without transition tables remains (like the SchemaCache guard). Do NOT drop the mirror tables, the append-only/POSTED-immutable triggers, or the DELETE-sync semantics fixed in 2026_08_16_133000 — the row-level version must still delete the mirror row when IdempotencyStore deletes a failed lease.

**Cách kiểm chứng:** Before/after on a copy of the dev DB: `EXPLAIN (ANALYZE, BUFFERS)` of one `UPDATE stock_document_lines SET scanned_qty=scanned_qty WHERE id=<x>` and one `INSERT INTO stock_movements …` inside a transaction that is rolled back — record total time and the trigger time lines ('Trigger trg_erd_mirror_… : time=… calls=1'); read pg_stat_user_tables.n_tup_upd/n_dead_tup for inbound_lines, outbound_lines, idempotency_records before/after 100 scans via the API (target: ≤ rows touched by the scan, not table size); then run a k6 write scenario (scan + post-receipt) at 20 VU on a DB seeded with ≥500k stock_document_lines and compare p95 of POST …/scan and …/post-receipt.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### WRITE-02 — WWHN-172 aggregate mutex serialises every SKU and every container write system-wide behind one row lock taken before auth/validation, including the 1,000-row Excel import

**Thành phần:** app/Http/Middleware/Wwhn172AggregateLock.php (global prepend in bootstrap/app.php:46); routes POST/PATCH/DELETE under /api/v1/skus* (6) and /api/v1/containers* (4)

**Bằng chứng:** Wwhn172AggregateLock.php:27-36: `DB::transaction(function () { insertOrIgnore(mutex_key); DB::table('aggregate_mutexes')->where('mutex_key',$mutex)->lockForUpdate()->firstOrFail(); return $next($request); }, 3)` — the transaction and FOR UPDATE span the whole downstream stack; :44-56 keys are only two constants (`WWHN172:SKU_GRAPH_AND_IDENTITY`, `WWHN172:CONTAINER_GRAPH_AND_IDENTITY`) matched by path prefix for any non-GET method. bootstrap/app.php:46 `$middleware->prepend(Wwhn172AggregateLock::class)` → runs before route middleware `auth:api`/`permission:*` (route list shows those as route middleware). Routes under the SKU key include `POST api/v1/skus/import-excel → ImportController@importExcel` (route:list), which parses the workbook in-request (ImportController.php:147 store → :178 importService->import) and ImportService::import runs `DB::transaction` (:461) with `lockForUpdate` (:467) and `AuditLogger::record` per record (:518, :566) for up to `config('warehouse.sku_import.max_rows', 1000)` rows (SkuImportContract.php:27). Container key covers `POST api/v1/containers/{id}/items` and `DELETE …/items/{containerItemId}` (packing-floor operations). PG live settings: lock_timeout=0, idle_in_transaction_session_timeout=0 (waiters wait forever); php-fpm pm.max_children=48 (docker-compose.perf.yml:98) / 64 (docker/php/www.conf:72). Post-fix idle read latency for auth+DB endpoints is 32-90 ms (LOAD_TEST_2026-09-07.md §1); write latency NOT MEASURED.

**Nguyên nhân gốc:** A coarse-grained pessimistic mutex chosen for WWHN-172 invariants (SKU component graph, code identity) is applied at the outermost HTTP layer with two global keys, so unrelated aggregates and even unauthenticated or invalid requests contend for the same row, and long-running work (Excel import, per-record audits) extends the critical section.

**Ảnh hưởng ở 10.000 người dùng:** Hard ceiling of 1/(mean hold time) writes per key for the whole deployment regardless of replicas (≈10-30 req/s at 30-100 ms holds; ~0 while an import holds it). Every queued request pins one php-fpm worker and one PG connection; one 10-30 s import can absorb all 48 workers of a replica with waiters, stalling GET traffic as well (head-of-line blocking of the worker pool). Also a trivial DoS surface: unauthenticated POST /api/v1/skus floods still take the lock.

**Hướng sửa đề xuất:** Keep the mutex semantics and fail_closed audit policy, but (1) move the lock from the global prepend to route-group middleware registered AFTER `auth:api` and `permission:*` and after FormRequest/validation where possible, so rejected requests never enter the transaction; (2) take the import out of the request-wide lock: let ImportService acquire the mutex per chunk inside its own transaction, or run the import as a queued job that acquires the mutex per chunk (the row-level SkuAggregateService locks at :215-236, :297, :320, :439, :451 plus UNIQUE indexes already protect identity/graph within a chunk); (3) narrow keys for {id} routes to the aggregate (`WWHN172:SKU:{id}` / `WWHN172:CONTAINER:{id}`) and keep the global key only for operations that mutate cross-aggregate invariants (component-graph edits, code assignment) — this must be validated against the WWHN-172 invariant list before rollout; (4) execute `SET LOCAL lock_timeout = '5s'` right after BEGIN in the middleware and map SQLSTATE 55P03 to 409 CONCURRENCY_CONFLICT so a stuck holder cannot exhaust the pool. Do NOT remove the transaction, the audit policy override, or RBAC.

**Cách kiểm chứng:** k6 closed-model probe (load/k6/probe-concurrency.js pattern) with 40 VU hitting PATCH /api/v1/skus/{id} for 40 distinct ids: record req/s and p95 before/after; concurrently start one POST /api/v1/skus/import-excel with 1,000 rows and observe (a) PATCH p95 and (b) `SELECT count(*) FROM pg_stat_activity WHERE wait_event_type='Lock'` and `pg_locks` on aggregate_mutexes during the import; check that unauthenticated POST /api/v1/skus returns 401 without appearing in pg_locks after the change.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### WRITE-03 — idempotency_keys grows without bound (no purge) while every scan and mutation writes a key with full response body, and each write triggers a full-table mirror

**Thành phần:** app/Support/IdempotencyStore.php + trigger hn_erd_mirror_idempotency_records (2026_08_16_133000) + FE per-scan keys

**Bằng chứng:** routes/console.php schedules only audit:reconcile (:32), import:purge-error-files (:50), report-export:purge (:63), warranty:purge-pii (:82) — no idempotency purge; grep 'idempotency' in app/Console/Commands finds only export/go-live checks. IdempotencyStore.php:214-224 stores `response_body` JSON for every 2xx and never deletes 'done' rows; FE sends a fresh UUID key per scan action (src/modules/inbound/services/inboundService.ts:482-495, :293) so row count = total scans + posts. Trigger `trg_erd_mirror_idempotency_records_from_idempotency_keys AFTER INSERT OR DELETE OR UPDATE … FOR EACH STATEMENT` runs a full DELETE anti-join + full INSERT…ON CONFLICT DO UPDATE over idempotency_records__legacy_projection (`EXPLAIN: Seq Scan on idempotency_keys rows=12`), computing md5() per row twice; measured: 5 inserts + 5 updates on idempotency_keys → 85 updates + 31 dead tuples on idempotency_records at 11 rows. IdempotencyStore::handle does 1 INSERT + 1 UPDATE (or DELETE) per mutation → 2 full mirrors per mutation. Index `idx_idem_locked_until` exists but no job uses it.

**Nguyên nhân gốc:** Retention was never defined for the idempotency table; combined with the statement-level mirror trigger, cost per mutation is O(number of historical keys).

**Ảnh hưởng ở 10.000 người dùng:** At 10k users a warehouse day is easily 10^5-10^6 scans → idempotency_keys reaches millions of rows within weeks; with the current trigger every scan then rewrites millions of idempotency_records rows (O(N) per write, O(N²) cumulative). Even after WRITE-01 is fixed, unbounded growth degrades the (idem_key, operation, user_id) lookups and bloats backups. NOT MEASURED beyond the 17× ratio above.

**Hướng sửa đề xuất:** Add a scheduled command (e.g. `idempotency:purge`, every 15 min, onOneServer, withoutOverlapping) that deletes rows with status='done' AND completed_at < now() - <replay window> (pick the window from the SRS replay contract, e.g. 24-72 h) plus status='failed'/'processing' with locked_until < now() - 1 day, in batches of ≤5,000 using idx_idem_locked_until / a new (status, completed_at) index; fix the mirror trigger to row-level (WRITE-01) so DELETEs stay O(batch). Keep the unique (idem_key, operation, user_id) and business_key constraints and the replay/conflict semantics unchanged.

**Cách kiểm chứng:** Track `pg_total_relation_size('idempotency_keys')` and row count daily; after the purge job runs, assert count ≤ mutations in window; re-run the 5-mutation sample and confirm idempotency_records.n_tup_upd increases by ≤ rows written (not by table size).

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### WRITE-04 — Post Receipt holds document, line and inventory_balance locks across O(items) inserts and one nested-savepoint audit insert per item

**Thành phần:** Modules/Warehouse/app/Services/CurrentBaselineStockService.php::postStockDocument (:379-506), registerCandidateItems (:1240-1346), applyReceiptSubledger (:1357-1413); Modules/AuditLog/app/Services/AuditLogger.php:78

**Bằng chứng:** Lock acquisition order inside one transaction: stock_documents FOR UPDATE (:1684) → all stock_document_lines FOR UPDATE (:396) → inbound_scan_entries candidates FOR UPDATE (:1252) → per candidate: 2 EXISTS queries (:1261-1262), INSERT item_instances (:1293; table has 14 indexes incl. 3 duplicate unique pairs), INSERT physical_codes (:1296; 20 indexes incl. two triplicated unique sets), UPDATE item_instances (:1320), UPDATE inbound_scan_entries (:1325), `AuditLogger::record` (:1331) → AuditLogger.php:78 `DB::transaction(fn () => AuditLog::create($payload))` = SAVEPOINT + INSERT through the `audit_log` VIEW's INSTEAD OF trigger hn_legacy_audit_log_write (regex on object_id + jsonb_build_object + jsonb_strip_nulls) + RELEASE SAVEPOINT; then per line: inventory_balances FOR UPDATE (:1556) + CAS UPDATE (:1593) + INSERT stock_movements (:1608, fires full mirror of inventory_movements — WRITE-01); then applyReceiptSubledger: bulk UPDATE item_instances (:1368) + per-entry UPDATE (:1377) + container_items INSERTs (:1394); then document_status_history, posting_event (INSTEAD OF view trigger), final audit (:486). inventory_balances rows are the shared contention point across all documents of the same SKU/warehouse and are held from the first mutateBalance until COMMIT. inventory_balances has `idx_erd_inventory_balances_updated_at`, so every balance UPDATE (which sets updated_at) is non-HOT and maintains all 10 indexes (5 of them redundant duplicates).

**Nguyên nhân gốc:** Per-item work (including per-item audit rows with their own savepoint round trips) is executed row-by-row inside the posting transaction, and the balance locks are taken before the subledger/audit phase rather than as late as possible.

**Ảnh hưởng ở 10.000 người dùng:** A 200-item receipt executes ≈ 200 × 9 statements + 200 × 3 audit round trips ≈ 2,400 statements while holding the balance rows of every SKU on the document; concurrent posts/reversals/component issues on the same SKUs wait for the whole duration. Exact hold time NOT MEASURED (no write load test, pg_stat_statements absent).

**Hướng sửa đề xuất:** Keep every audit row and the same-transaction guarantee, but batch: add `AuditLogger::recordMany(array $events)` that performs ONE nested transaction and ONE multi-row insert (same columns, same sanitisation, same policy handling, targeting audit_logs directly or via the view) and use it for the per-item audits in registerCandidateItems and ImportService (:518/:566). Bulk-insert item_instances / physical_codes / container_items with a single `insert([...])` per table and a single `UPDATE … WHERE id IN (…)` for the back-references. Reorder the transaction so mutateBalance (the shared lock) runs after registerCandidateItems and applyReceiptSubledger where dependencies allow, keeping the product_id-sorted balance locking (WWHN-234 F-04) intact. Drop the redundant duplicate indexes (see WRITE-07) and consider replacing `idx_erd_inventory_balances_updated_at` (only useful for change-feed queries) with a BRIN index so balance updates can be HOT. Do not remove validation, audit, or idempotency.

**Cách kiểm chứng:** Seed one receipt with 200 candidate items; run POST …/post-receipt with `DB::listen` counting statements and total time (temporary probe as in PERF_AUDIT §2) before/after; measure balance-lock hold time via `pg_stat_activity` (xact_start → commit) or `log_lock_waits=on` with a concurrent post-issue on the same SKU; confirm audit_logs row count is identical before/after the change.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### WRITE-05 — Print/reprint label routes perform the 5 s printer HTTP call inside the IdempotencyStore transaction (connection + lease lock held across external I/O); Post Issue label build likewise runs inside it

**Thành phần:** Modules/Warehouse/app/Http/Controllers/CurrentBaselineOperationsController.php::dispatchLabel (:1066-1083) / dispatchLabelOnce (:1085-1196); AlignedOutboundFlowController.php::postIssue (:595-694); app/Support/IdempotencyStore.php::run (:186-240)

**Bằng chứng:** IdempotencyStore::run :195 `DB::transaction(function () { $lease = …->lockForUpdate()->first(); … [$code,$body] = $fn(); …update snapshot… }, 3)` — the business closure runs inside an open transaction holding the lease row FOR UPDATE. dispatchLabel :1070-1079 passes `fn() => $this->dispatchLabelOnce(...)` as that closure; dispatchLabelOnce :1111 `Http::acceptJson()->timeout(5)->post((string) $printer->agent_endpoint, …)` and only afterwards opens its own DB::transaction (:1146, a nested savepoint). The comment at :1135-1137 assumes no lock is held during the HTTP call, which is true for label_snapshots but not for the outer transaction/connection. Same pattern in postIssue: :666-668 comment says label preparation is 'NGOÀI transaction' but `ensurePackingLabel` (:670) runs inside the IdempotencyStore closure and takes `label_snapshots … lockForUpdate` (:304). PG: idle_in_transaction_session_timeout=0, statement_timeout=0.

**Nguyên nhân gốc:** IdempotencyStore couples 'exactly-once' with 'run the whole handler inside my transaction'; handlers that must talk to external systems have no way to release the connection between reserve and complete.

**Ảnh hưởng ở 10.000 người dùng:** Each print request pins one php-fpm worker AND one PG connection in 'idle in transaction' for up to 5 s when a printer agent is down/slow; ~10 concurrent print timeouts remove ~20 % of a 48-worker replica and ~8 % of the 120-connection budget for the whole window; no server-side timeout ends them. Frequency of printer timeouts in production: NOT MEASURED.

**Hướng sửa đề xuất:** Extend IdempotencyStore with a two-phase mode (same table, same key semantics): `reserve()` commits the lease in a short transaction, the handler performs the external call with no transaction open, then `complete()`/`fail()` runs a short transaction that re-locks the lease (WHERE status='processing' AND locked_until=$lease) and writes the snapshot or deletes the lease — replay/conflict behaviour and the 5-minute takeover stay identical. Use it for print/reprint and move `ensurePackingLabel` in postIssue to that post-commit phase (it is already designed to be non-rolling-back). As a safety net set `idle_in_transaction_session_timeout` (e.g. 30 s) and `statement_timeout` (e.g. 60 s) in the PG profile. Do not remove the Idempotency-Key requirement on print routes.

**Cách kiểm chứng:** Point a printer_config at a black-hole endpoint; fire 20 concurrent POST …/print and watch `SELECT count(*) FROM pg_stat_activity WHERE state='idle in transaction'` (expect ≈20 before, 0 after) and php-fpm active processes; verify replay with the same key still returns the stored result and a different payload still returns 409 IDEMPOTENCY_CONFLICT.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-NETWORK-01 — Login path issues a redundant serial GET /auth/me although POST /auth/login already returns the identical profile payload

**Thành phần:** FE auth boot (src/modules/auth/hooks/use-login.ts, src/app/router/ProtectedRoute.tsx) / BE Modules/Auth AuthController

**Bằng chứng:** use-login.ts:27-32 onSuccess only calls setAuth (auth.store.ts:60-75 sets isProfileLoaded:false) then navigate; ProtectedRoute.tsx:50 useMe() fires GET /auth/me because token is set and profile is not (use-me.ts:12-17), and :109/:114 block the Outlet (and the IndexedDB restore, use-persisted-cache.ts:28-60) until it returns. BE login response is `'user' => $this->userPayload($user)` (AuthController.php ~190) and me() returns `...userProfilePayload + ...userAuthorizationPayload` (AuthController.php:300-304, 433-439, 456-470: roles, permissions, warehouse_scope_ids) — the same shape. FE already has `meResponseFromRefreshUser` (auth.service.ts:266-280) and uses it only in bootstrap-auth.ts:46-49 for the F5 path. BE PERF_AUDIT §4 B lists /auth/me as deliberately uncached, and CACHE_POLICY.md §5 records 170–380 ms per extra RTT on the F5 path before the same fix was applied there.

**Nguyên nhân gốc:** The refresh-path optimisation (seed ['auth','me',token] from the token response) was never applied to the login path; useLogin stores the token and user but leaves the profile empty, so the fail-closed guard has to fetch it again.

**Ảnh hưởng ở 10.000 người dùng:** Every login costs one extra uncached, DB-backed request (roles + permissions + scope queries) and one extra network round trip on the critical path before the first data screen; at shift start (login storm) auth-path request volume is doubled. Exact server cost per /auth/me: NOT MEASURED.

**Hướng sửa đề xuất:** In useLogin.onSuccess, after setAuth, seed `queryClient.setQueryData(['auth','me', data.access_token], meResponseFromRefreshUser(rawUser))` and call `setProfile(...)` exactly as bootstrap-auth.ts:46-49 does (keep the raw `user` object from the login response available to the hook — normalizeLoginResponse currently discards the raw shape, so pass it through or normalise once). Keep the null fallback (missing `permissions` → still call /auth/me). Do NOT weaken fail-closed: profile still comes from the server response that issued the JWT; do not persist it to disk (rbac class stays persist:false).

**Cách kiểm chứng:** Before/after in DevTools or Playwright: count requests between POST /auth/login and first page query; expect GET /auth/me to disappear and time-to-first-data-request to drop by one RTT. Re-run e2e/auth-role-navigation-qa2.spec.ts and the RBAC guards (403 page, PASSWORD_CHANGE_REQUIRED) to confirm fail-closed behaviour is unchanged.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-NETWORK-02 — Unconditional POST /auth/refresh on every page load: guaranteed 422 on anonymous loads (root cause of FE docs §7.4), and one DB-write token rotation + synchronous audit insert per F5 with no cross-tab coordination

**Thành phần:** FE src/main.tsx + src/app/bootstrap-auth.ts + src/modules/auth/services/auth.service.ts; BE Modules/Auth PersistRefreshTokenCookie + AuthController::refresh + RefreshTokenService

**Bằng chứng:** main.tsx:35 `void bootstrapAuth()` runs on every load; bootstrap-auth.ts:30-35 'Always attempt one refresh'; auth.service.ts:323-334 sends `{}` when memoryStorage has no token (always true after reload). BE PersistRefreshTokenCookie.php:27-33 merges the HttpOnly cookie into the request only if it exists; :45-48 expires the cookie on logout/changePassword; AuthController.php:222 `validate(['refresh_token' => ['required',...]])` → 422 when neither body nor cookie carries a token. So any first visit to /login, any load after logout, and any load in a browser without the cookie yields exactly one 422 — which is what scripts/perf/verify-keep-alive.mjs:61 (`page.goto('/login')`) observes; FE_PERF_AUDIT §7.4 attributes it to 'lần F5 đầu'. bootstrap-auth.ts:16-19 treats 422 as an invalid session and clears the cookie hint (functionally harmless). For logged-in reloads: RefreshTokenService.php:45-70 runs DB::transaction + SELECT ... FOR UPDATE + UPDATE revoked_at + INSERT new token, and AuthController.php:238 logs TOKEN_REFRESH through AuditLogger.php:78 (`DB::transaction(fn () => AuditLog::create(...))`, synchronous). RefreshTokenService.php:52-56: a second use of the same cookie value (two tabs reloading concurrently, browser session restore) is treated as replay and revokes all tokens for the user; bootstrapAuth is single-flight per tab only (bootstrap-auth.ts:64-72).

**Nguyên nhân gốc:** The FE cannot see the HttpOnly cookie, so it always tries; nothing on the client side (public route detection, persisted auth hint) short-circuits the attempt, and no cross-tab lock serialises rotation.

**Ảnh hưởng ở 10.000 người dùng:** (a) Every anonymous or post-logout load = 1 wasted POST through the auth route group ending in a validation exception (cheap per request, but on the same php-fpm pool as everything else). (b) Every reload by an authenticated user = 2 row-locked writes on refresh_tokens + 1 audit_logs insert; at 10k users the write rate is proportional to reload frequency (NOT MEASURED). (c) Concurrent multi-tab reload → whole session revoked → forced re-login → +login +me +dashboard fan-out per affected user; on warehouse PCs with several tabs this is a plausible daily event, frequency NOT MEASURED.

**Hướng sửa đề xuất:** FE: (1) skip the refresh attempt on public routes (/login, /forgot-password, /pv/*) when the rehydrated auth hint (auth.store.ts:139-146 persists `user` in the erp-auth cookie) is empty — i.e. only attempt when either the hint exists or the route is protected; keep fail-closed semantics for protected routes (still always try there). (2) Serialise rotation across tabs with `navigator.locks.request('wms-refresh', ...)` (fallback BroadcastChannel) and share the new access token with other tabs via BroadcastChannel so only one tab rotates per reload burst. BE (out of FE scope, note only): a short grace window that returns the already-rotated pair for the same token hash within N seconds would remove the replay false-positive; do not weaken replay detection beyond that. Do NOT reintroduce trusting the cookie hint as proof of authentication.

**Cách kiểm chứng:** Playwright: cold `goto('/login')` must produce 0 POST /auth/refresh; reload while logged in must produce exactly 1; open two tabs and reload both within 100 ms → both stay logged in and only one TOKEN_REFRESH audit row appears (query audit_logs by request_id). Load test: compare refresh_tokens/audit_logs insert rate per simulated reload before/after.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-NETWORK-03 — Whole-period report ledgers (document-history 461 KB, inventory-ledger, reversal-documents, movement-trace 2.1–4.3 MB) are fetched unpaginated and additionally under three different query roots, so moving between report pages re-downloads the same payloads

**Thành phần:** src/modules/reports/operations/report-data.ts, movement-trace/movement-trace-api.ts, io-history/io-history-api.ts, inventory-flow/inventory-flow-api.ts

**Bằng chứng:** Same request, different keys: `/reports/document-history` under ['rpt-ops','document-history',from,to,docType] (report-data.ts:439-455, used by inventory-flow-api.ts:377 and recipient-agency-api.ts:462-467,632-637,714-719), under ['rpt-mov-01','document-history',from,to] with doc_type=all (movement-trace-api.ts:203-216), and under ['rpt-io-01','document-history',...] with an extra status param (io-history-api.ts:167-181). `/reports/inventory-ledger` identical params under ['rpt-xnt-01','inventory-ledger',from,to] (inventory-flow-api.ts:304-311) and ['rpt-io-01','inventory-ledger',from,to] (io-history-api.ts:194-201). `/reversal-documents?per_page=200` under rpt-ops (report-data.ts:465-476) and rpt-mov-01 (movement-trace-api.ts:218-231). React Query dedups only on identical keys, so a user opening Traceability then Inventory-flow then Pending-evidence downloads document-history up to three times for the same period. Recorded sizes: docs/cache/CACHE_POLICY.md:87-89 (movement-trace 2.1 MB, document-history 461 KB, XNT 159 KB); FE_PERF_AUDIT §6 (movement-trace 4.3 MB at 5,956 rows, 'tăng tuyến tính'); report-data.ts:15-20 documents the deliberate no-per_page contract. gzip is on at nginx (docker/nginx/default.conf.template:44-49) — compressed size NOT MEASURED.

**Nguyên nhân gốc:** Each report module declared its own root (rpt-xnt-01, rpt-mov-01, rpt-io-01) for the same endpoints; the cache registry forbids nested prefixes but does not detect same-endpoint duplicates. The unpaginated contract is intentional (chart == table invariant) and needs server-side aggregation to change.

**Ảnh hưởng ở 10.000 người dùng:** The heaviest per-user transfers in the app, multiplied by the number of report pages visited per 5-minute window. BE returns cached bytes on hit (report TTL 300 s) but still serialises and streams multi-MB JSON per request; per-request PHP/nginx cost and egress at 10k users NOT MEASURED. Traceability alone is 4.3 MB per open on today's dataset and grows with movements.

**Hướng sửa đề xuất:** Step 1 (FE-only, safe): collapse to one hook per endpoint under a single root: `useDocumentHistory({from,to,docType,status?})`, `useInventoryLedger({from,to})`, `useReversalDocuments({from,to})` in report-data.ts (`rpt-ops`), and make movement-trace-api.ts / io-history-api.ts / inventory-flow-api.ts consume them; keep io-history's status filter as an explicit key segment so its request stays distinct only when the param differs. Step 2 (contract change, already listed as residual in FE docs): server-side KPI + timeline aggregation for movement-trace and document-history with paginated rows; FE keeps client filtering only on the aggregated set. Do NOT paginate the rows alone — the chart/table invariant the pages rely on would break.

**Cách kiểm chứng:** Playwright network log: visit /reports/traceability → /reports/inventory-flow → /reports/pending-evidence with the same period; count GET /reports/document-history and /reports/inventory-ledger (expect 1 each after step 1, 3 today). Add a guard test that scans hooks for duplicate endpoint+params under different roots. After step 2, measure bytes per open of /reports/traceability (today 4.3 MB).

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-NETWORK-04 — Four report inputs crawl entire tables without a period filter (warranty-cases, component-issue-documents, label-print-events, users) — request count and bytes grow with history and then silently truncate at the page cap

**Thành phần:** src/modules/reports/operations/report-data.ts, src/modules/reports/packing-label/packing-label-api.ts; BE list endpoints lacking from/to

**Bằng chứng:** report-data.ts:660-681 `useWarrantyCases` → fetchAllPages(/warranty-cases, per_page 100, ≤10 pages), no from_date/to_date; :684-710 `useComponentIssueDocuments` same; packing-label-api.ts:215-240 `useLabelPrintEvents` → /label-print-events ≤20×100 with the comment 'BE không có tham số ngày cho endpoint này' and a `capped` flag when truncated; report-data.ts:206-216 `useUserDirectory` → /users?per_page=200 (not persisted, PII) on 4 report pages, plus AuditLogsPage.tsx:117 /users?per_page=200 under a different (`iam`) key. paginate-all.ts:19 caps concurrency at 6, so a full crawl is 1 + ceil((N-1)/6) round trips. Recorded today: /reports/warranty api=5, /reports/packing-label api=3 (transitions-final-cpu1.json lines 57-77) because tables are small.

**Nguyên nhân gốc:** The report needs fields only the raw list endpoints expose (created_by, item_id, print events), and those endpoints have no date filter, so the FE fetches everything and filters client-side.

**Ảnh hưởng ở 10.000 người dùng:** Per open of /reports/warranty up to 1+10+10+10 requests and of /reports/packing-label up to 1+20 requests every 5 minutes per user, growing linearly with case/print history until the cap is hit — after which the report is quietly wrong (capped). Bytes per crawl NOT MEASURED.

**Hướng sửa đề xuất:** BE: add `from_date/to_date` (and `warranty_case_ids[]` for component issues) to /warranty-cases, /component-issue-documents, /label-print-events; add a slim `/users/lookup` (id, display_name, department) analogous to /skus/lookup with master-class caching. FE: pass the report period to those hooks and key the queries on it; keep the 100/page cap and the `capped` flag. Do NOT drop the truncation flag or raise MAX_PAGES as a substitute.

**Cách kiểm chứng:** Seed-scale check: with HoaNamBulkDemoSeeder data, record request count and total bytes for /reports/warranty and /reports/packing-label before/after; assert `capped` never becomes true for a one-month period. Grep guard: no fetchAllPages call without a date-bounded param set.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-NETWORK-05 — Dashboard polls four endpoints every 60 s while visible, including the full XNT ledger (159 KB) that the BE caches for 300 s, and derives KPIs client-side from it

**Thành phần:** src/modules/dashboard/overview/overview-data.ts, src/modules/dashboard/pages/DashboardPage.tsx; BE /reports/dashboard-overview

**Bằng chứng:** overview-data.ts:178 AUTO_REFRESH_MS = 60_000; :187-199 reportQueryOptions sets refetchInterval when autoRefresh; DashboardPage.tsx:147-152 passes `!offline` → polling is on for every online viewer, no user toggle in code. Four queries: :222-229 dashboard-overview (widened range), :231-238 inventory-xnt-enhanced for the KPI period (default 'last7', DashboardPage.tsx:110), :241-253 inventory-xnt-daily (7 d), :255-262 dashboard-quick. XNT is consumed only to compute equation totals, top-5 SKUs and in-stock counts client-side (:391-460). BE TTLs: dashboard 60 s, report 300 s (config/wms_cache.php:17-18) → the two XNT polls return identical cached bytes 4 of every 5 ticks. Recorded XNT payload 159 KB (CACHE_POLICY.md:89) for the measured range; dashboard payloads 5–20 KB. React Query default refetchIntervalInBackground=false so hidden tabs do not poll.

**Nguyên nhân gốc:** The demo's '60-second auto refresh' was implemented uniformly on all four sources; XNT was bolted on because dashboard-overview does not publish the equation/top-5 fields.

**Ảnh hưởng ở 10.000 người dùng:** Steady-state load proportional to concurrent dashboard viewers: 4 requests/min/viewer and ~159 KB/min/viewer uncompressed for XNT alone (e.g. 1,000 viewers ≈ 67 req/s and ≈2.6 MB/s uncompressed before gzip). Whether load/k6 flows model this cadence: NOT MEASURED.

**Hướng sửa đề xuất:** (1) BE: publish `equation` (opening/receipt/issue/component/reversal/closing), `top_issue_skus` (5) and `skus_in_stock` in /reports/dashboard-overview (it already aggregates per period) and drop the XNT + XNT-daily calls from the dashboard. (2) Until then, set refetchInterval per query: 60 s only for dashboard-overview/dashboard-quick, ≥300 s for XNT/XNT-daily (their BE TTL), and consider 120 s overall with jitter to spread ticks across users. Keep the dashboard cache class at 60 s (NFR staleness) and keep refetchIntervalInBackground=false.

**Cách kiểm chứng:** Open /dashboard for 5 minutes in a browser and count requests per endpoint (expect 20 today; ≤10 after). In k6, add a dashboard-viewer flow with the real interval and measure req/s and bytes/s at 1k and 10k VUs before/after.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-RENDER-01 — Five-hop serial waterfall on every authenticated F5 (and three on /login): shell and page chunks start only after the auth/IDB gate

**Thành phần:** src/app/router/index.tsx, src/app/router/ProtectedRoute.tsx, src/main.tsx, index.html

**Bằng chứng:** router/index.tsx:18-20 (`MainLayout = lazy(...)`), :44-48 (`DashboardPage = lazy(...)`), :238-243 (`MainLayout` is a child of `<ProtectedRoute/>`); ProtectedRoute.tsx:62 `if (!isBootstrapped) return <Loading/>`, :109, :114 `if (!isCacheReady) return <Loading/>`, :116 `<Outlet/>` — the lazy import() of MainLayout is first invoked by that Outlet render, and DashboardPage's import() only when MainLayout renders its outlet. dist/index.html carries 32 `modulepreload` links, none for `MainLayout-*.js` (62,129 B) or `DashboardPage-*.js` (56,611 B + 46,910 B CSS); `index-BUYY3orM.js` lists them only in `__vite__mapDeps` (dynamic). bootstrap-auth.ts:33 `authService.refresh()` runs at module eval (main.tsx:35) — nothing else network-bound is started alongside it. IDB restore gate: query-persister.ts:59 `RESTORE_TIMEOUT_MS = 1_500`, idb.ts:56 3,000 ms open timeout. Login: LoginPage chunk graph = 17 chunks / 194 KB raw not in the modulepreload list; illustration URL is inside that graph (AuthIllustration.tsx:2,33-47), no fetchpriority/preload; index.html has no preconnect/preload hints (grep returned nothing). Lab numbers already recorded: first entry to a screen 349–711 ms at CPU x1 on LAN (FE audit §7.3) — cold F5 including refresh not measured.

**Nguyên nhân gốc:** Route-level `lazy()` is correct for bytes, but the fail-closed auth gate serialises chunk discovery behind two network/IO waits (refresh, IDB) that do not depend on the chunks, and Vite only emits modulepreload for the static graph. The idle prefetcher (`route-preload.ts`) starts after MainLayout mounts, i.e. after the waterfall it could have shortened.

**Ảnh hưởng ở 10.000 người dùng:** Per-user latency rather than server load: on a WAN with 40–80 ms RTT + TLS the two extra chunk hops add ~150–300 ms and the IDB gate up to 1.5 s worst case on every F5, in addition to the API time. At 10k users mostly on warehouse PCs (FE audit measured CPU x4 as the realistic profile) this is the dominant part of 'time to first data' that the BE fixes (32–90 ms endpoints) cannot recover. Login page LCP (illustration) sits at the third serial round.

**Hướng sửa đề xuất:** 1) In main.tsx, next to `bootstrapAuth()`, fire `void import('@/app/layouts/MainLayout')` and a `preloadRoute(window.location.pathname)` (reuse route-preload.ts LOADERS) so the shell + target page chunks download in parallel with POST /auth/refresh; keep ProtectedRoute's gate order unchanged (do NOT render Outlet before profile + cache — that is a data-scope guarantee). 2) Warm `openDb()` (idb.ts) at boot so the restore after /auth/me is a single get, not open+get. 3) Add `<link rel="preconnect" href="<API origin>" crossorigin>` in index.html (API is cross-origin per CORS preflight note in FE audit §5). 4) For /login desktop, either `<link rel="preload" as="image">` the illustration from index.html (hash the path at build via a small plugin) or add `fetchpriority="high"` + explicit width/height; keep it hidden < lg. Do not move LoginPage back to a static import (that regression was measured and reverted, router/index.tsx:10-12).

**Cách kiểm chứng:** Before/after with Chromium CDP or Playwright `page.on('request')`: on a cold-cache F5 of /dashboard, record the ordered timeline of index.html → last modulepreload → /auth/refresh → MainLayout-*.js → DashboardPage-*.js → first dashboard GET → first KPI paint; count serial rounds (expect 5 → 3). Extend scripts/perf/measure-transitions.mjs with a `coldF5` round at CPU x1 and x4 with 50 ms injected RTT; report 'time to first KPI paint' p50/p95 over ≥ 5 runs. For /login: Lighthouse LCP and 'LCP resource load delay' before/after.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-RENDER-03 — Full 3,586-glyph icon font + its 155 KB CSS are render-blocking on every page for 56 used icons; woff2 (386 KB) is fetched on the login page

**Thành phần:** src/main.tsx:9, src/shared/ui/icons/icon-family.ts, src/shared/ui/forms/UiFormText.tsx

**Bằng chứng:** main.tsx:9 `import "@flaticon/flaticon-uicons/css/regular/rounded.css"` (global). node_modules/@flaticon/flaticon-uicons/css/regular/rounded.css = 154,778 B raw / 28,052 B gz with 3,586 `.fi-rr-*:before` rules; all 3,586 are present in dist/assets/index-MlGUkuqS.css (184,908 B / 34,306 B gz) → 82% of the only render-blocking stylesheet. @font-face in that CSS: `font-display:swap`, woff2 dist/assets/uicons-regular-rounded-J3WOUERV-*.woff2 = 385,636 B (also emitted: woff 521,880 B, eot 1,092,772 B). Distinct icon slugs used across src (createFlatIcon/flatIconClass/startIcon/icon: props/fi-rr- literals): 56; icon-family.ts comment counts 113. On /login the password field renders `flatIconClass("eye")` (UiFormText.tsx:110) so the woff2 is downloaded before login. index.html has no `<link rel=preload as=font>`. Nav icons already use inline SVG paths (vuexy-nav-icon-paths.ts).

**Nguyên nhân gốc:** The icon package is consumed as a monolithic CSS+font instead of a subset; the ui:icons guard enforces family consistency but not glyph subsetting.

**Ảnh hưởng ở 10.000 người dùng:** Every first visit / every deploy (SW cache name changes per build, service-worker.js:18-22) pays ~28 KB gz CSS parse + 386 KB font per user: 10k users ≈ 4.1 GB of font egress per deploy wave and ~0.3 GB CSS; CSS blocks first paint on every page; `swap` on an icon font renders empty boxes/tofu until the font lands, a visible layout/flash on slow links.

**Hướng sửa đề xuất:** Generate a subset at build or check-in time: `pyftsubset uicons-regular-rounded.woff2 --unicodes=<codepoints of the 56–113 used slugs> --flavor=woff2` plus a generated CSS containing only those classes (a script under scripts/ can derive the slug list the same way `check-ui-icons.mjs` does); keep `flatIconClass()` API unchanged so no screen changes. Alternatively migrate to inline SVG via the existing VuexyNavIcon pattern. If the font stays: add `<link rel="preload" as="font" type="font/woff2" crossorigin>` for the hashed file and use `font-display: block` for the icon face (icons have no meaningful fallback glyph). Drop eot/woff from the build (they are dead for supported browsers).

**Cách kiểm chứng:** Compare dist sizes: index CSS gz (34 KB → ≈8 KB expected), woff2 (386 KB → < 30 KB for ~100 glyphs). `yarn ui:icons` self-test must still pass. Network waterfall on /login: font request size and start time; Lighthouse 'Ensure text remains visible' + CLS before/after; visual check that no icon renders as a box (e2e/ui-foundation.spec.ts snapshot).

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-RENDER-02 — antd runtime (ConfigProvider + App + cssinjs, ~217 KB raw / 77 KB gz) is mounted at the root and shipped in the entry graph although only 8 files use antd

**Thành phần:** src/shared/ui/theme/AppThemeProvider.tsx, src/app/providers/AppProviders.tsx, vite.config.ts

**Bằng chứng:** AppThemeProvider.tsx:14 `import { App as AntdApp, ConfigProvider } from "antd"`, :16 `import viVN from "antd/locale/vi_VN"`, :47-50 wraps every route in `<ConfigProvider><AntdApp>`. Entry graph chunks identified as antd by markers (`ant-`, `rc-`, `anticon`, `cssinjs`/`useStyleRegister`): ContextIsolator-pgcfM9K5.js 90,919 B / 31,451 gz; compact-item-DKLv6e8i.js 75,326 / 28,810 (7 cssinjs markers); useModal-BmzB_vM1.js 40,414 / 13,183; config-provider-hJncvHxa.js 7,706 / 3,201; zoom-peffDNgI.js 2,815 / 738 → 217,180 B raw / 77,383 B gz = 19% of the 399,514 B gz entry graph; all five are in dist/index.html's modulepreload list, so they are fetched and evaluated on /login. antd consumers: 8 files (UiDatePicker.tsx, UiFormDate.tsx, ScanInput.tsx, CameraScanModal.tsx, FilterBar.tsx, PageHeader.tsx, antd-adapter.ts, AppThemeProvider.tsx) vs 60 files importing @mui/material. vite.config.ts:92-95 comment explicitly relies on Rollup to keep antd out of the login graph — the root provider defeats that.

**Nguyên nhân gốc:** Two UI frameworks coexist after the Vuexy/MUI migration; the antd ConfigProvider was kept as a global composition root (architecture guard requires a single ThemeProvider), so antd's runtime and cssinjs style injection execute on every page including ones with zero antd components.

**Ảnh hưởng ở 10.000 người dùng:** ~77 KB gz extra download and antd/cssinjs JS evaluation on every session start and every deploy wave for all 10k users (≈ 0.8 GB per deploy just for antd), plus main-thread parse/compile of ~217 KB of unused code on low-end warehouse PCs before the login form can render.

**Hướng sửa đề xuất:** Preferred: finish the migration of the 5 remaining antd components (date pickers → MUI X DatePicker or the existing native-input path, ScanInput/CameraScanModal/FilterBar/PageHeader → @/shared/ui primitives), then remove antd, antd-adapter.ts and the viVN locale. Interim, zero-behaviour-change: move `<ConfigProvider theme={ANTD_THEMES[mode]} locale={locale}><AntdApp>` into a `lazy()`-loaded `AntdScope` component and mount it only around the five antd-using components (or the routes that render them), keeping AppThemeProvider MUI-only; update the provider-uniqueness test/allowlist (docs/ui/vuexy-sync/allowlists/themeprovider-exceptions.json) rather than disabling it. Keep token parity (buildAntdTheme) for the interim scope.

**Cách kiểm chứng:** Rebuild and re-run the static-import closure: entry graph must not contain ContextIsolator/compact-item/useModal/config-provider chunks; expected entry gz ≈ 322 KB (from 399.5 KB). Lighthouse on /login: TBT and 'Reduce unused JavaScript' before/after; Playwright coverage (`page.coverage.startJSCoverage`) on /login to confirm unused-byte ratio drops. Regression: yarn ui:guards, e2e/ui-foundation.spec.ts, date-picker screens (Inbound/Outbound filters) visual check.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-RENDER-05 — Dashboard polls four heavy report endpoints every 60 s per open tab — a fixed background load that scales with concurrent users, not with activity

**Thành phần:** src/modules/dashboard/overview/overview-data.ts

**Bằng chứng:** overview-data.ts:178 `const AUTO_REFRESH_MS = 60_000;`, :191 `refetchInterval: autoRefresh ? AUTO_REFRESH_MS : (false as const)` applied to all four queries (:222-261): `API_ENDPOINTS.REPORTS_DASHBOARD_OVERVIEW`, `REPORTS_XNT_ENHANCED`, `OPERATIONS_REPORT_ENDPOINTS.XNT_DAILY`, `API_ENDPOINTS.DASHBOARD_KPI`. `autoRefresh` is `!offline` (DashboardPage.tsx:147-152) — always on while online. FE staleTime = `CACHE_POLICIES.dashboard.staleTime` (:176) with the source comment tying it to a 60 s BE cache TTL (config/wms_cache.php ttl.dashboard — not re-verified here). React Query default `refetchIntervalInBackground: false` (query-client.ts has no override) so hidden tabs pause. A Reverb push channel already exists per user (useNotificationSocket.ts:27) but is not used for data invalidation. Payload sizes recorded in query-persister.ts:45-46 comments: dashboard 5–20 KB, xnt 159 KB.

**Nguyên nhân gốc:** Port of the demo's 'auto refresh after 60 s' banner as literal client polling on all four sources, independent of whether anything changed and independent of the BE cache window.

**Ảnh hưởng ở 10.000 người dùng:** Background request rate = (tabs on /dashboard) × 4 / 60 s. If 20% of 10k users keep the dashboard visible: ≈ 133 req/s (10,000 → ≈ 667 req/s) on the four most expensive read endpoints, continuously, on top of the k6-modelled flows; the ~159 KB xnt payload alone is ≈ 21 MB/s egress at 133 req/s. With a 60 s BE TTL aligned to a 60 s client interval, each user's tick lands at a random phase so the BE cache serves most, but every TTL expiry becomes a stampede window guarded only by rememberStampede's lock.

**Hướng sửa đề xuất:** Keep the manual 'Làm mới' button and the offline pause. Replace the 60 s interval with either (a) push invalidation: broadcast a lightweight `dashboard.updated` event on the existing `user.{id}`/a shared `warehouse.{id}` channel when a Post/movement commits, and call `queryClient.invalidateQueries({queryKey:['dashboard']})` in useNotificationSocket; or (b) at minimum raise to 180–300 s with ±20% jitter and `refetchInterval` returning false while `document.visibilityState !== 'visible'` (already default) and while `busy`. Do not change the FE staleTime/BE TTL relationship documented in cache-policy.ts (NFR-SRS-016).

**Cách kiểm chứng:** Before/after on the perf compose profile: k6 scenario with N virtual users parked on /dashboard for 10 min (flows.js can replay the four GETs on the FE's cadence); measure req/s and p95 on those four endpoints from nginx access logs plus Valkey hit ratio; then the same with the new cadence/push. In the browser: count dashboard GETs over 10 min on one tab (expect 40 → ≤ 14 or event-driven).

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CAPACITY-01 — Per-IP limiters (auth-refresh 30/min, pv-public 120/min, public-api 180/min) will throttle a whole NAT'd warehouse; refresh is hit on every page load and kiosk mode retries 429s every 5 s

**Thành phần:** BE rate limiting (AppServiceProvider) + FE bootstrap/kiosk retry

**Bằng chứng:** C:/LAM_PHONG_TECH/HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE/app/Providers/AppServiceProvider.php:164-177 `Limit::perMinute($perMinute)->by('auth-refresh:'.$request->ip())`, :109-122 pv-public by ip, :127-140 public-api by ip; config/rate_limit.php:18,23,34 (120/180/30); route:list: POST api/v1/auth/refresh [throttle:auth-refresh], 7 pv routes, 8 public routes; config/trustedproxy.php:29 trusts RFC1918 hops so X-Forwarded-For (the client's NAT egress IP) becomes the key; FE calls POST /auth/refresh on every page load: C:/LAM_PHONG_TECH/HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE/src/app/bootstrap-auth.ts:30-35; 429 is not an invalid session (:16-19) → bootstrapError → ProtectedRoute.tsx:19,26-34,55-57 retries bootstrapAuth every 5 s when VITE_SCANNER_KIOSK_MODE=true (FE/.env currently true); axios.ts:196-203 does not retry 429. Measured: a single k6 IP at 78 req/s already produced 52 and 24 × HTTP 429 on pv-public (load/results/baseline-perf-local-1replica.json / -2replica.json wms_rate_limited) and 613 × 429 at 116 req/s (target-think20-local-2replica.json); .env.perf.example:155-164 documents disabling these limiters for load tests.

**Nguyên nhân gốc:** Limiters intended as anti-abuse were keyed on client IP only; the deployment topology (warehouse LAN → NAT → TLS terminator) makes thousands of legitimate users share one IP, and the refresh endpoint is on the critical path of every app open/F5, not just abuse.

**Ảnh hưởng ở 10.000 người dùng:** Derived: with JWT_TTL restored to 60 min, steady-state refresh demand is 10,000/60 ≈ 167/min per egress IP vs 30/min allowed → ~82% of session restores 429 continuously; at shift start (10k opens in 10 min = 1,000/min) → ~97% 429. Each kiosk client then retries every 5 s (12 req/min) → up to 120k req/min ≈ 2,000 req/s of throttled refreshes, each still booting Laravel (~20–26 ms CPU) ≈ 40–50 cores of self-inflicted load. PV/public users behind carrier-grade NAT hit 120/180 per minute per IP similarly.

**Hướng sửa đề xuất:** Key auth-refresh by the refresh credential, not the IP: e.g. `->by('auth-refresh:'.sha1((string)($request->cookie(config('refresh_token.cookie.name')) ?: $request->input('refresh_token') ?: $request->ip())))` with a generous per-credential limit (e.g. 10/min) plus a much higher per-IP backstop (e.g. 3,000/min) — keep 429 envelope (RateLimitResponse) and Retry-After. For pv-public/public-api raise per-IP limits by an order of magnitude and/or add a second dimension (IP + User-Agent/device hint) or allow-list known warehouse egress CIDRs via env. Keep the login failure limiter (IP+identifier) unchanged; never set RATE_LIMIT_*=0 in production; revert VITE_SCANNER_KIOSK_MODE to false after the scan campaign, or make the kiosk retry honour Retry-After with jitter. Do not remove throttling (SRS §8.1 requires anti-abuse controls) and do not change the 429 contract.

**Cách kiểm chứng:** Before: replay 40 POST /auth/refresh from one IP within 60 s → expect ≥10 × 429 today. After: 0 × 429 for distinct credentials from one IP; run k6 target profile WITHOUT RATE_LIMIT_*=0 and require wms_rate_limited=0; add a test asserting the auth-refresh limiter key is not IP-only.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CAPACITY-02 — No coherent timeout/cancellation hierarchy: browser gives up at 10 s and retries, while php-fpm (request_terminate_timeout 0) and PostgreSQL (statement_timeout/lock_timeout/idle_in_transaction 0) keep the orphaned work running for up to 60 s+

**Thành phần:** nginx / php-fpm / PostgreSQL / FE React Query

**Bằng chứng:** FE/src/shared/api/axios.ts:39 timeout=runtimeConfig.apiTimeoutMs (FE/.env VITE_API_TIMEOUT_MS=10000); FE/src/app/providers/query-client.ts:15-19 retries once when status is undefined (timeout/network) or ≥500; BE/docker/nginx/perf/default.conf.template:123-125 fastcgi_send/read_timeout 60 s; php-fpm -tt in backend-1: request_terminate_timeout = 0s; php-fpm -i: max_execution_time 30 (CPU-time only on Linux — does not bound DB/Redis waits), Loaded Configuration File => (none); live pg_settings: statement_timeout 0, lock_timeout 0, idle_in_transaction_session_timeout 0; app/Http/Middleware/Wwhn172AggregateLock.php:27-36 wraps whole write requests in a transaction holding `lockForUpdate()` with lock_timeout 0; measured bimodal latency in load/results/target-think20-local-2replica.json: median 70 ms, p99(all) 59,994 ms, 710 status-0 timeouts vs 1 × 5xx.

**Nguyên nhân gốc:** Each layer's timeout was set independently; nothing propagates cancellation downward (php-fpm only notices client abort on output, PG never aborts), and the FE treats a timeout as a transient error to be retried.

**Ảnh hưởng ở 10.000 người dùng:** Derived: at 500 req/s, if 1% of requests are slow reports that exceed 10 s under contention, 5/s time out in the browser and are retried once → 10 slow requests/s each pinning a php-fpm child up to 60 s (nginx) and a PG backend indefinitely → ~600 pinned children demanded vs 96 available: full pool exhaustion within ~10 s of any brownout; a single runaway report query can hold a PG backend for minutes with no bound.

**Hướng sửa đề xuất:** Bound each layer inside-out: (1) PostgreSQL role-level `ALTER ROLE <app_role> SET statement_timeout='30s', lock_timeout='10s', idle_in_transaction_session_timeout='60s'` (migrations/seeders/scheduler use a separate role or `SET LOCAL` overrides — Wwhn172AggregateLock semantics must stay: a lock_timeout error should surface as 409/503 with Retry-After, not as a silent skip); (2) php-fpm `request_terminate_timeout = 65s` (≥ nginx 60 s) so orphaned workers are reclaimed; (3) keep nginx 60 s but make it the documented max for sync endpoints (imports); (4) FE: for the `report`/`dashboard` cache classes set `retry: false` on timeouts (retry only on 5xx with jittered delay) and raise the per-request timeout for report queries to match server budgets instead of retrying; (5) consider `ignore_user_abort`-aware checks in long report resolvers. No business rule, RBAC or idempotency behaviour changes.

**Cách kiểm chứng:** Before/after: rerun k6 target THINK=20 and compare p99(all) (expect no 60 s cluster), count `pg_stat_activity` rows with `now()-query_start > 30s` during the run (expect 0 after), php-fpm status `max children reached` counter; unit test that a lock_timeout QueryException maps to the 409/503 envelope.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CAPACITY-03 — ~1.3% of requests stalled for 60 s at only 116 req/s on 2 replicas — root cause unverified; nginx upstream keepalive (16 workers × 128 cached conns) pinning the 96 php-fpm children is the mechanism most consistent with the evidence

**Thành phần:** nginx upstream keepalive ↔ php-fpm pool

**Bằng chứng:** load/results/target-think20-local-2replica.json: http_reqs 116.5/s, median 70 ms, p95 675 ms, p99(all) 59,994 ms, 1,324 failures = 613 × 429 + 1 × 5xx + ~710 status-0 (k6 60 s timeout), dropped 435, VUs up to 483 — while backend CPU was far from its 10-core limit (docs LOAD_TEST_2026-09-07.md §1 reports ~3.4 cores at the earlier stall; orchestrator brief ~1.8 cores/replica). nginx: worker_processes auto → 16 workers (docker stats PIDs 17), `keepalive 128; keepalive_timeout 60s` (BE/docker/nginx/perf/default.conf.template:53-55), `fastcgi_keep_conn on` (:111); php-fpm pm.max_children 48 per replica (zz-wms-pool.conf), no idle-keepalive timeout exists in php-fpm (a child on a kept-alive FastCGI connection blocks reading the next record until nginx closes it); 16 × 128 = 2,048 possible idle upstream connections vs 96 children; probe with keepalive off showed no throughput loss (54 vs 57–67 req/s, LOAD_TEST_2026-09-07.md §1); pm.status_listen undefined in the running container so 'listen queue' could not be read.

**Nguyên nhân gốc:** Hypothesis: nginx workers park idle keep-alive connections on php-fpm children; children blocked on those idle connections cannot accept requests queued in the listen backlog by other nginx workers, so a subset of requests waits until a 60 s keepalive/read timeout expires. Alternative causes (PG contention, host CPU contention from co-located k6) are not excluded.

**Ảnh hưởng ở 10.000 người dùng:** At 500 req/s and ~1,000+ concurrent browser connections spread over 16 nginx workers, the share of children pinned idle rises with burstiness; every stall is a 10 s axios timeout plus a React Query retry (CAPACITY-02), so 1.3% stalls at 116 req/s would translate to thousands of failed user actions per hour at 10k users. If the cause is instead PG/host contention the impact is the same order: the pool exhausts long before CPU.

**Hướng sửa đề xuất:** Cheap, reversible: set `worker_processes 4` (a reverse proxy in front of 96 PHP children does not need 16) and `keepalive 8` (≤ children ÷ (workers × replicas)), or `fastcgi_keep_conn off` (measured ~zero throughput cost); keep `zone`/`resolve`/failover semantics untouched. Ship the php-fpm status page already added on disk (perf-entrypoint.sh:50-55 `pm.status_path=/fpm-status`) so 'listen queue', 'active processes', 'max children reached' become evidence. Do not raise max_children as a workaround (RAM: 48×55 MB ≈ 2.6 GB = BACKEND_MEM_LIMIT).

**Cách kiểm chứng:** Rerun the exact target THINK=20 run with `fastcgi_keep_conn off` (or keepalive 8) and everything else equal; compare p99(all), status-0 count and `/fpm-status?full` states ('Reading headers' vs 'Running', listen queue) sampled every 5 s. If stalls persist, capture `pg_stat_activity` wait_event and `docker stats` per second to shift the diagnosis to PG/host.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CAPACITY-04 — Replica scaling is decoupled from PostgreSQL max_connections and there is no connection pooler: the 3rd backend replica reintroduces 'sorry, too many clients already'

**Thành phần:** PostgreSQL connections / docker-compose.perf.yml

**Bằng chứng:** docker-compose.perf.yml:98 PHP_FPM_MAX_CHILDREN=48 and :218 PG_MAX_CONNECTIONS=120 are independent env vars; live pg_settings max_connections 120, superuser_reserved_connections 3 → 117 usable; budget formula in .env.perf.example:36-43 and LOAD_TEST_2026-09-07.md §3 ('2×48 + 2 queue + scheduler + reverb + 10 ≤ 120'); 3 replicas = 144+14 = 158 > 117; config/database.php:87-100 pgsql has no PDO options (non-persistent, one connect/auth per request); no PgBouncer service anywhere in compose; the identical failure was measured on 06/09 (1,361 × SQLSTATE 08006, LOAD_TEST_2026-09-06.md §3); pgbench -C 1,438 tps @ 40 clients (§1) shows per-request connection setup is a real but not yet limiting cost; DEPLOYMENT_PROFILE.md:98 defers PgBouncer to 'multiple machines'.

**Nguyên nhân gốc:** Connection budget is sized by hand for a fixed replica count; `--scale backend=N` changes one side of the invariant silently, and the golive check (`wms:runtime-profile`: max_connections ≥ max_children + 12) validates a single replica only.

**Ảnh hưởng ở 10.000 người dùng:** 5 app nodes (planning value) need ≥ 5×48 + 2 queue + scheduler + reverb + 10 ≈ 255 connections; 10 nodes (8 s think) ≈ 495; every replica above the budget converts overload into 5xx for the excess workers. Without pooling, 500 req/s also means ~500 PostgreSQL fork+auth cycles per second (CPU cost NOT MEASURED).

**Hướng sửa đề xuất:** Add PgBouncer (transaction pooling) as the DB endpoint for backend/queue/scheduler/reverb: `max_client_conn ≥ replicas×48 + 20`, `default_pool_size ≈ 2–4 × PG cores`; PDO pgsql uses server-side prepared statements, so require PgBouncer ≥ 1.21 with `max_prepared_statements` > 0 or set `PDO::ATTR_EMULATE_PREPARES => true` in the pgsql `options` and run the full suite first (documented regression risk in PERF_TUNING.md §6). Until then: make PG_MAX_CONNECTIONS a function of the replica count in .env presets (e.g. 48×N+30) and extend `wms:runtime-profile` to take a REPLICAS env so the check multiplies. Keep the invariant comment in docker/php/www.conf.

**Cách kiểm chứng:** `select count(*) from pg_stat_activity` and `pg_stat_database.numbackends` sampled during a 3-replica target run (expect 0 × 08006 in stderr); with PgBouncer: `SHOW POOLS` cl_active/sv_active during the same run; regression suite green with emulated prepares.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CAPACITY-05 — Node sizing: ~26 ms PHP CPU + ~18 ms PG CPU per request means 10k users at 20 s think (500 req/s) need ≈24 cores; the current 16-vCPU shared host saturates first, and a full 48-child pool (~2.6 GB) equals the container memory limit

**Thành phần:** Backend replica CPU/memory budget (docker-compose.perf.yml)

**Bằng chứng:** CPU per request: getrusage 24–26 ms for /health (LOAD_TEST_2026-09-07.md §1 table), ~1.8 cores/replica at the 150 req/s target (orchestrator brief; not in docs), dev-stack pre-fix 760–860% CPU at 120–130 req/s (LOAD_TEST_2026-09-06.md §2); PG 0.8 core at 45 req/s (§1) ≈ 18 ms/req, 4.5 cores at 125 req/s on the pre-fix dev stack; nginx 21–34% at 120 req/s; limits: BACKEND_CPU_LIMIT 10 / BACKEND_MEM_LIMIT 2600m (docker-compose.perf.yml:122-126), 40–55 MB per worker (perf-entrypoint.sh:21-23) → 48×55 = 2,640 MB > 2,600 MB before counting the master and 256+64 MB opcache/JIT SHM; host = Docker Desktop 16 vCPU / 7.6 GB shared with PG (1.6 GB), Valkey, nginx, k6; measured: 2 replicas could not hold 150 req/s (116.5 achieved).

**Nguyên nhân gốc:** The perf presets were sized for 3,000 users on one 8–16 GB box; per-request CPU (framework boot + 5–8 SQL + Redis) was measured only after the bind-mount fix and never multiplied out to 10k users, and the memory limit was set equal to the worst-case pool footprint.

**Ảnh hưởng ở 10.000 người dùng:** 500 req/s ≈ 13 PHP cores + 9 PG cores + 1.5 nginx cores ≈ 24 cores vs 16 vCPU total today → theoretical host ceiling ≈ 330 req/s ≈ 6,600 users at 20 s think even with perfect scaling (which the 150 req/s run contradicts); at full pool a replica risks OOM-kill (in-flight requests lost) instead of queueing. ESTIMATE from local measurements.

**Hướng sửa đề xuất:** Plan capacity from the table in inventory §F: 4+1 app nodes (each the current container shape) for 10k users at 20 s think, a dedicated 12–16 vCPU PostgreSQL host (preset C memory values), and treat 26 ms/req as the budget to defend — the cheapest lever is cutting per-request CPU (framework boot, 2 user lookups per request noted in PERF_TUNING.md §4) rather than adding nodes. Set BACKEND_MEM_LIMIT ≥ children×55 MB + 400 MB (≈3.1 GB for 48) or lower max_children to 40 at 2.6 GB; keep `pm.max_children ≤ max_connections − reserve` invariant. Re-derive PHP CPU/req on the target hardware before ordering it.

**Cách kiểm chứng:** Run k6 target from a SEPARATE machine against a Linux host with the perf profile; record docker stats (CPU per container) at 100/200/300 req/s and fit cores-per-req; confirm RSS per worker via `/proc/<pid>/status` at full pool; capacity PASS criterion: p95 < 800 ms, 0 dropped, 0 5xx at USERS=10000 THINK=20 for 5 min.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CAPACITY-06 — Reverb is a single unscalable instance for 10,000 persistent WebSockets, and every (re)connection costs a php-fpm request to /api/v1/broadcasting/auth

**Thành phần:** Reverb + broadcasting auth

**Bằng chứng:** BE/config/reverb.php:41 `'scaling' => ['enabled' => env('REVERB_SCALING_ENABLED', false)]` (no Redis pub/sub → a second instance would not see events), :86-88 ping 60 s / activity_timeout 30 s / max_connections null; docker-compose.perf.yml:188-208 one `reverb` service, PHP_MEMORY_LIMIT 384M, container 512m, nofile 65535 (comment: '3000 người = 3000 socket'); FE/src/shared/lib/echo.ts:32-39 authorizer POSTs /broadcasting/auth with the JWT for each private channel; Modules/Notification/routes/api.php:13 route behind auth:api (JWT decode + user lookup per call); no measurement of Reverb memory/CPU per connection anywhere in docs/perf.

**Nguyên nhân gốc:** Real-time bell was designed for a few thousand sockets on one process; scaling mode and per-app connection caps were left at defaults, and channel auth reuses the full Laravel HTTP path.

**Ảnh hưởng ở 10.000 người dùng:** 10k sockets ≈ 167 ping frames/s (cheap) but unknown RSS in one 384 MB PHP process (NOT MEASURED — 40 KB/socket would already be 400 MB); a Reverb restart or LB blip triggers ~10k reconnects within pusher-js backoff → ~10k auth POSTs ≈ 10k × 26 ms = 260 core-seconds ≈ 20 s of full php-fpm saturation on 13 cores, during which all other API calls queue and time out (CAPACITY-02).

**Hướng sửa đề xuất:** Set REVERB_SCALING_ENABLED=true (Redis pub/sub on the existing Valkey), run ≥2 reverb replicas behind an LB with WebSocket support, set REVERB_APP_MAX_CONNECTIONS per instance (e.g. 5,000) and raise REVERB_MEMORY_LIMIT accordingly; add a per-user (not per-IP) limiter on /broadcasting/auth and consider caching the channel-auth signature per (user, socket) for a short TTL; optionally stagger reconnects with jitter in the Echo config. Keep channel authorization rules (routes/channels.php) unchanged.

**Cách kiểm chứng:** k6 `ws` soak: 10k connections to one Reverb instance for 30 min, record RSS/CPU and connection count from `reverb:start --debug` or docker stats; then `docker restart reverb` and measure php-fpm request rate and p95 of unrelated GETs during the reconnect storm; PASS: p95 < 800 ms sustained.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### SEARCH-REPORTS-01 — One global `stock:version` namespace invalidates every dashboard/report cache on every inventory mutation; misses then serialize on a 5 s Cache::lock and fall through to duplicate rebuilds

**Thành phần:** BE cache layer for reports/dashboards — Modules/Warehouse/app/Http/Controllers/Concerns/CachesReportPayload.php, app/Support/Cache/CacheService.php, OperationalReportController::dashboardQuick/dashboardOverview

**Bằng chứng:** CachesReportPayload::reportCacheKey (lines ~66-80) appends `$this->cacheService()->currentVersion('stock:version')` to EVERY report key; dashboardQuick (OperationalReportController.php 651-656) and dashboardOverview (683-688) do the same. Docblocks state the namespace is bumped after each stock mutation: StockMovementController.php 8-13 ('CurrentBaselineStockService::mutateBalance() … DB::afterCommit(bumpVersion("stock:version"))'), InventoryBalanceController.php index comment. CacheService::rememberStampede (lines ~75-118): on miss, `Cache::lock('lock:'.$key, 10)->block(5, …)`; on LockTimeoutException it logs and `return $resolver()` (every waiter rebuilds). config/wms_cache.php: lock wait 5 s, ttl dashboard 60 s, report 300 s. LOAD_TEST_2026-09-06 §5: measured hit ratio 35 % on a read-only mix (no bumps at all) — with writes it can only be lower. dashboard-overview rebuild = 17 queries + nested inventory-balance (6 queries) (PERF_AUDIT §4: 26 queries / 23 shapes).

**Nguyên nhân gốc:** Invalidation granularity is global (one counter for all warehouses, all reports, all dashboards) and event-driven, while the consumers (dashboard 60 s auto-refresh, report screens) already tolerate 60 s / 5 min staleness (FE cache-policy.ts dashboard.staleTime 60 s, report 5 min). Under continuous scanning the counter changes faster than any TTL, so the TTL layer is dead and every request pays the full rebuild plus lock wait.

**Ảnh hưởng ở 10.000 người dùng:** Arithmetic, not measurement: with 10,000 users a stock mutation every second is conservative, so every dashboard poll (4 requests per user per 60 s ⇒ 1,000 dashboard users ≈ 67 req/s) and every report open misses. Each miss on dashboard-overview runs 23 queries incl. whole-ledger aggregates (SEARCH-REPORTS-02); concurrent misses on the same key hold a php-fpm worker (pm.max_children 48-64 per LOAD_TEST docs) for up to 5 s waiting on the lock, then rebuild in parallel anyway. Measured saturation ceiling on the dev box was 120-130 req/s (LOAD_TEST_2026-09-06 §2); lock convoys would consume that budget with idle workers.

**Hướng sửa đề xuất:** 1) Time-based caching for dashboards: remove the `stock:version` component from the dashboard-quick / dashboard-overview / xnt-daily keys (keep from,to,scope) and rely on the 60 s 'dashboard' TTL — this matches the staleness the FE already declares (cache-policy.ts dashboard 60 s) and NFR ≤60 s. 2) For reports, replace the global counter with per-warehouse counters `stock:version:{warehouse_id}` and include only the scope's warehouses in the key (scope is already resolved before cache), or use a coarse time bucket (floor(now/60 s)) for reports whose FE staleTime is 5 min. 3) Stale-while-revalidate in CacheService: keep a shadow key `…:stale` with a long TTL; on miss with lock contention or version mismatch serve the stale value and enqueue a rebuild job instead of blocking; lower lock wait to ≤1 s and never fall through to N parallel rebuilds (return stale or 503-retry-after for the rare cold case). 4) Keep everything else: scope in key, validation and 403 before cache, `plainReportPayload` JSON round-trip, no `meta` in cache. Do NOT touch `/inventory` or `/stock-movements` keys (stock_short 20 s + version is what guarantees NFR-SRS-016 ≤60 s staleness) and do NOT cache `/scan/classify`.

**Cách kiểm chứng:** Before/after: (a) run scripts/perf/cache-coverage.mjs plus a write loop (one inbound scan post per second) and read `redis-cli INFO stats` keyspace_hits/misses filtered to `wms:*:report:*` keys; (b) k6 probe-concurrency.js with TARGET=dashboard-overview at 40 VU while the same write loop runs — record req/s, p50, `cache.lock.timeout` count in laravel.log; (c) confirm no staleness regression by asserting a posted receipt appears in /inventory within 20 s and in dashboard within 60 s.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### SEARCH-REPORTS-02 — XNT, inventory-balance and dashboard opening balances aggregate the WHOLE stock_movements ledger with no leading occurred_at index (Seq Scan + HashAggregate); cost grows with ledger history, not with the report period

**Thành phần:** OperationalReportController::buildXntUncached / buildXntDailyUncached / buildDashboardOverview / buildDashboardQuick; LedgerReconciliationService::ledgerAggregateQuery; PostgreSQL indexes on stock_movements

**Bằng chứng:** buildXntUncached (OperationalReportController.php 1641-1651): `DB::table('stock_movements as m')->where('m.occurred_at','<=',$toTs)->groupBy(warehouse_id, product_id, stock_status)` with 5 CASE SUMs — only upper bound. ledgerAggregateQuery (LedgerReconciliationService.php 46-85): GROUP BY 3 dims with no date predicate unless as_of. buildDashboardOverview 1001-1003 and buildXntDailyUncached 1868-1870: `where('m.occurred_at','<',$fromTs)->sum('qty_delta')` over all prior history. pg_indexes: stock_movements has (warehouse_id,product_id,stock_status) INCLUDE(qty_delta,occurred_at), (warehouse_id,product_id,occurred_at), document/source/original/chain/stock_status — no index led by occurred_at. EXPLAIN (dev DB): XNT aggregate ⇒ `Seq Scan on stock_movements … Filter occurred_at <=` + HashAggregate; dashboard daily ⇒ Seq Scan; `occurred_at < X` ⇒ Index Scan on idx_sm_wh_product_time with Index Cond on the 3rd column (full index walk, chosen only because the table is 7,350 rows). inventory_report_snapshots table exists (migration 2026_07_30_000003) but is referenced only by its model and migrations — no producer, no reader. These builders run on every dashboard load (dashboard calls xnt-enhanced + xnt-daily + overview + quick; overview/quick nest buildInventoryBalance).

**Nguyên nhân gốc:** Opening balances are recomputed from day one on every request instead of from a periodic closing snapshot, and there is no index that lets PostgreSQL seek by time for the in-period part. At production scale the same query reads the entire append-only ledger per rebuild, and SEARCH-REPORTS-01 makes rebuilds frequent.

**Ảnh hưởng ở 10.000 người dùng:** NOT MEASURED at scale (dev DB 7,350 rows). Structurally: cost per rebuild is O(total ledger rows) for XNT/inventory-balance/opening sums, executed on every dashboard cache miss (see 01) and every report open; with 2 replicas × 48 workers each holding a connection (max_connections 120), a few concurrent whole-ledger aggregates with work_mem 8 MB spill to disk and starve the scan/posting paths that share the same pool.

**Hướng sửa đề xuất:** 1) Index: `CREATE INDEX CONCURRENTLY idx_sm_occurred_at_brin ON stock_movements USING brin (occurred_at)` (append-only ledger ⇒ BRIN is tiny and correct) or a btree `(occurred_at) INCLUDE (warehouse_id, product_id, stock_status, movement_type, qty_delta)` if planner tests prefer it; this bounds every in-period aggregate (dashboard, xnt-daily, movement-trace, document-history lines). 2) Opening snapshot: populate the existing `inventory_report_snapshots` (daily closing per warehouse×sku×status) with a scheduled job (nightly, plus on-demand catch-up by day) and rewrite opening = snapshot(closing at from-1) + movements in [from-1 snapshot, from) — XNT, xnt-daily, dashboard stock_trend and inventory-balance `ledger_qty` (= latest snapshot + movements since) become bounded by the period. Keep the response shapes (opening/receipt/issue/…/closing, gross/net aliases, formula strings) and the reversal breakdown logic. Guard correctness with the existing LedgerReconciliationService divergence check comparing snapshot+delta vs full-scan on a sample. 3) Keep scope predicates and product_id_in subqueries as they are (they already avoid join-before-group).

**Cách kiểm chứng:** Seed a perf DB copy (not dev) to ≥5 M stock_movements with HoaNamBulkDemoSeeder / DailySkuCoverageSeeder; run EXPLAIN (ANALYZE, BUFFERS) for the XNT aggregate, dashboard daily and opening-sum queries before/after the index and snapshot rewrite; bench /reports/inventory-xnt-enhanced?from_date=&to_date= and /reports/dashboard-overview with scripts/perf/bench-be.mjs (currently NOT MEASURED because bench sends no from/to and gets 422 — add the params). Assert XNT closing per row equals full-scan closing on the seeded DB.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### SEARCH-REPORTS-03 — /reports/movement-trace and /reports/inventory-ledger return the whole period unpaginated (4.51 MB today), and the FE coverage probe re-hits them with a 365-day window

**Thành phần:** OperationalReportController::buildMovementTrace / inventoryLedger; FE src/modules/reports/movement-trace/movement-trace-api.ts, operations/report-coverage.ts, operations/report-probes.ts, io-history-api.ts, inventory-flow-api.ts

**Bằng chứng:** buildMovementTraceUncached (OperationalReportController.php 2381-2503): selects `m.*` ⋈ warehouses ⋈ products ⋈ app_user for occurred_at BETWEEN, `ORDER BY occurred_at DESC, id DESC`, SQL paging only `if ($request->filled('per_page'))` (2444). EXPLAIN: Seq Scan + Sort (no occurred_at index). bench-be-after-all.json: /reports/movement-trace and /reports/inventory-ledger = 4,511,814 bytes, cold 213 / warm 209 ms — warm ≈ cold, i.e. the time is payload serialization/gzip, not SQL. FE deliberately omits per_page: movement-trace-api.ts 24 ('KHÔNG gửi per_page'), io-history-api.ts 152, inventory-flow-api.ts 291. Coverage probes: report-coverage.ts `COVERAGE_WINDOW_DAYS = 365` (line 36); LEDGER_COVERAGE_PROBE and movementTraceCoverageProbe (report-probes.ts 6-25) call these endpoints with from=today-364 and no per_page whenever `shouldProbeCoverage` is true (empty or sparse selected range, report-range-advice.ts 286-300). The cached entry (report 300 s) is the full PHP array of the 12-month dump in a Valkey with maxmemory 256 MB volatile-lru (PERF_AUDIT §3.4). PERF_AUDIT §6 and FE_PERF_AUDIT §6 already flag the 4.3 MB payload as residual risk.

**Nguyên nhân gốc:** Contract requires table, timeline and 4 KPI cards to describe one identical set, so the FE takes the whole set and aggregates client-side; the BE has no summary mode, so the only way to get KPIs is the full row dump — and the range-advice feature reuses that dump to learn which days have data.

**Ảnh hưởng ở 10.000 người dùng:** Today 4.5 MB per open (5,956 rows ≈ 1 month of dev data). Ledger volume scales with scans: at production a month is orders of magnitude larger and the 365-day probe is a full-year seq scan + sort + multi-100 MB JSON per click on a quiet period; one such cache entry can exceed Valkey maxmemory and evict every dashboard/report key (volatile-lru). Each request also holds a php-fpm worker for the whole serialization.

**Hướng sửa đề xuất:** Dedicated summary endpoint (clearly better than bending the row endpoint): BE `GET /reports/movement-trace/summary` (same filters/scope/validation/period as movement-trace) returning summary{total_movements, net_effect, reversal_count}, by_movement_type, daily{date, count, net} and available_days — all SQL aggregates (already exist for the per_page path: `$aggregate` 2447-2450 and `$byMovementType` 2428-2438) plus one `GROUP BY DATE(occurred_at)`. Keep `/reports/movement-trace` rows contract intact but (a) apply a hard server cap when per_page is absent (e.g. 5,000 rows, `truncated: true` in payload — additive field) and (b) always fill `summary`/`by_movement_type` from the aggregate queries rather than from the loaded rows so KPIs stay whole-period. FE: MovementTraceReport/IoHistory/InventoryFlow read KPIs+timeline from the summary endpoint and page the table (per_page ≤200 with useRenderWindow already in place); coverage probes switch to the summary's `available_days` (or `xnt-daily`, which is capped at 366 days and returns ≤366 points) instead of the row endpoints. Add the occurred_at index from SEARCH-REPORTS-02 so ORDER BY occurred_at DESC LIMIT is an index walk. Do NOT change data-scope handling, the `code_value` → physical_codes resolution, or the 'movement_no/original_movement_no' fields.

**Cách kiểm chứng:** Network tab on /reports/traceability, /reports/io-history, /reports/inventory-flow: bytes and request count per open before/after (today: one 4.5 MB request each); bench-be.mjs on the new summary endpoint vs /reports/movement-trace; simulate the probe (select a period with no data) and confirm no request exceeds the cap; `redis-cli --bigkeys` after opening the traceability screen to confirm no multi-MB entries; EXPLAIN of the paged query shows Index Scan Backward on occurred_at + LIMIT.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### SEARCH-REPORTS-04 — Dashboard downloads the entire XNT report to compute six sums, top-5 and a count client-side, and polls four endpoints every 60 s

**Thành phần:** FE src/modules/dashboard/overview/overview-data.ts, pages/DashboardPage.tsx; BE OperationalReportController::buildDashboardOverview / buildXntUncached / buildDashboardQuick

**Bằng chứng:** overview-data.ts useOverviewSource (lines ~200-265): 4 useQuery calls per dashboard mount — dashboard-overview (widened range), inventory-xnt-enhanced (full `lines`), inventory-xnt-daily, dashboard-quick — each with `refetchInterval: 60_000` (AUTO_REFRESH_MS, line ~176) and staleTime 60 s. buildEquation/buildTopSkus/countSkusInStock (overview-data.ts ~380-450) reduce over every XNT line (one line per warehouse×SKU×status) for 6 totals, a top-5 and a count. dashboard-quick is consumed only for `below_min_stock_count`/`above_max_stock_count` (DashboardQuick interface, ~line 150) yet returns 22,906 B (bench) including 50-row samples and a daily series. XNT builder = whole-ledger aggregate (SEARCH-REPORTS-02) and its `by_product` array already exists server-side (1770-1782).

**Nguyên nhân gốc:** The overview endpoint was ported 1:1 from the demo (memory: vuexy-demo-topbar-dashboard) and the missing numbers were sourced from existing report endpoints rather than added to the dashboard payload, so the heaviest report in the system is fetched as a dashboard side-dish and re-fetched every minute.

**Ảnh hưởng ở 10.000 người dùng:** Arithmetic: 4 requests/user/60 s; if 1,000 of 10,000 users keep the dashboard open ≈ 67 req/s, of which 17 req/s are full XNT payloads whose size scales with warehouses×SKUs×statuses (tens of MB at 50k SKUs) — and, per SEARCH-REPORTS-01, mostly cache misses. The dev-box saturation ceiling measured was 120-130 req/s total.

**Hướng sửa đề xuất:** BE (additive, contract-compatible): extend dashboard-overview with `equation{opening, receipt_net, issue_net, component_issue_net, reversal_unlinked_net, closing}`, `top_issue_skus[5]` (reuse `$issueRanked`), `sku_in_stock{opening, closing}` and `stock_threshold{below_min_count, above_max_count}` computed in SQL (COUNT over inventory_balances ⋈ sku thresholds — the idx_skus_stock_thresholds partial index exists). Compute the equation from the snapshot+delta path of SEARCH-REPORTS-02 or from the same aggregate as XNT restricted to `SUM()` without the per-dimension GROUP BY. FE: useOverviewSource drops the xnt-enhanced and dashboard-quick queries (keep xnt-daily for the reversal series or add `reversal_net` to `flow_daily` and drop it too) ⇒ 1-2 requests per load; keep 60 s auto-refresh but rely on React Query's default `refetchIntervalInBackground=false` (already the case) and consider 120 s. Do NOT change KPI definitions (product/component split, GLOBAL warranty counts, threshold semantics) or the 366-day guard.

**Cách kiểm chứng:** Network tab on /dashboard: requests and bytes per load and per 60 s tick before/after (today: 4 requests, XNT payload proportional to SKU count); bench-be.mjs for dashboard-overview after the additive fields; assert equation totals equal the FE-computed values on the same data (unit test against buildEquation output).

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### SEARCH-REPORTS-05 — Report screens crawl paginated endpoints to rebuild whole sets client-side (warranty 3×10×100, recipient 10-30×100, label-print-events 20×100 unfiltered), and outbound-by-recipient recomputes 5 aggregates for every page

**Thành phần:** FE src/modules/reports/operations/report-data.ts (useWarrantyReport/useWarrantyCases/useComponentIssueDocuments/useOutboundByRecipient), recipient-agency/recipient-agency-api.ts, packing-label/packing-label-api.ts; BE BaM1ReportController::outboundByRecipientData, CurrentBaselineOperationsController::listLabelPrintEvents

**Bằng chứng:** report-data.ts 603-604: WARRANTY_PAGE_SIZE 100, WARRANTY_MAX_PAGES 10; useWarrantyReport (606-640) fetchAllPages of /reports/warranty-defects; useWarrantyCases (642-660) and useComponentIssueDocuments (662-690) crawl `/warranty-cases` and `/component-issue-documents` with NO filters — both are in the 'A. intentionally uncached' list (PERF_AUDIT §4b), so each page is a fresh DB query; WarrantyComponentsReport.tsx uses all three. useOutboundByRecipient (762-800) default maxPages 10; recipient-agency-api.ts 594 `COMPARISON_MAX_PAGES = 30` (3,000 rows) plus reversal-documents(200)/document-history/sku-catalog/user-directory. packing-label-api.ts 57-59 LABEL_EVENT_MAX_PAGES 20 × EVENT_PAGE_SIZE against /label-print-events, whose BE validator accepts only label_snapshot_id/status/per_page (CurrentBaselineOperationsController::listLabelPrintEvents) — no date bound, silently `capped`. BaM1ReportController::outboundByRecipient (31-52) caches with `reportFiltersWithPaging` (page in key) while outboundByRecipientData (110-150) runs count, SUM, DISTINCT count, bySku GROUP BY and byRecipient GROUP BY on every call ⇒ 30 pages = 150 aggregate executions per comparison-tab open on cold cache. Coverage probes for warranty and recipient also crawl 10×100 (report-probes.ts 41-53). paginate-all.ts limits concurrency to 6 but not total volume.

**Nguyên nhân gốc:** BE report endpoints paginate rows but the FE needs cross-page aggregations (comparison tab, warranty defect mix, print attempts), and no server-side grouped/summary mode exists; the label-print-events list has no period filter at all.

**Ảnh hưởng ở 10.000 người dùng:** Per report open: up to 30 warranty requests (20 uncached), 10-30 recipient requests each re-running 5 aggregates, 20 label-event requests over an ever-growing table. At 10,000 users even a 1 % share opening these screens (100 users) means thousands of requests per open wave; rows above the caps are silently dropped (`truncated`/`capped`), so at production volume the reports become both slow and incomplete.

**Hướng sửa đề xuất:** BE: (1) outbound-by-recipient — split the cache into an aggregates entry keyed WITHOUT page (summary, by_sku, by_recipient) and a rows entry keyed WITH page; return the same JSON shape. Add optional `group_by=recipient,sku,period` (additive) returning grouped totals for the comparison tab so the FE stops crawling 3,000 rows. (2) warranty-defects — rows already embed defects and issued components; add `include=summary_only` or expose `by_defect`/`by_status` in page 1 only, and add `from_date/to_date` + `warranty_case_ids` filters to `/warranty-cases` and `/component-issue-documents` so the FE fetches only the period (or drop those two crawls and read case status/component data from the report rows). (3) label-print-events — add `from_date/to_date` (indexed via idx_label_print_snapshot_created / status_requested_at) and let label-log carry `attempts` per snapshot (it already computes them). FE: use the new aggregates; keep caps as a safety net but surface `truncated` prominently. Keep PII masking (`warranty.pii.view`), data-scope, and the per-page SQL pagination contract.

**Cách kiểm chứng:** Count requests and total bytes per open of /reports/warranty, /reports/recipient (both tabs), /reports/packing-label before/after via the Network tab or scripts/perf/measure-transitions.mjs; on BE, DB::listen query count for one outbound-by-recipient page before/after the cache split; confirm `truncated`/`capped` no longer trigger on a 90-day window of seeded data.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### SEARCH-REPORTS-06 — Keyword search is `ILIKE/LOWER(...) LIKE '%kw%'` with no trigram index, and the SKU/catalog list screens fire one request and one long-TTL cache key per keystroke

**Thành phần:** BE app/Repositories/BaseRepository.php::applyFilters, ProductRepository, InventoryBalanceController, MiniAppProductController, PvCatalogReadModel, report keyword filters; FE src/modules/master-data/pages/ApiMasterScreens.tsx, CatalogMasterScreen.tsx

**Bằng chứng:** BaseRepository.php 419-431: `orWhere($column, ilike, "%{$search}%")` over ProductRepository::$searchable ['sku_code','sku_name']; InventoryBalanceController 127-132 `LOWER(CAST(sku.sku_code AS TEXT)) LIKE`; report builders use `LOWER(p.code) LIKE ?` (OperationalReportController 1254-1257, 1671-1673, 2133-2138, 2285-2290; BaM1 101-109). pg_extension lists only plpgsql (no pg_trgm); skus has only btree unique(sku_code); EXPLAIN `LOWER(sku_code) LIKE '%khoan%' OR LOWER(sku_name) LIKE …` ⇒ Seq Scan on skus. Cache keys: BaseRepository::paginate 87-91 key = hash(filters incl. keyword) + per_page + page, TTL 'sku' 900 s (ProductRepository cacheTtlPolicy) / 'master' 1800 s. FE: ApiMasterScreens.tsx 398-410 `useMvpSkus({ keyword: keyword.trim(), … })` fed directly from `onChange={(event) => { setKeyword(event.target.value); setPage(1); }}` (720-726) — no debounce (grep for debounce/setTimeout/useDeferredValue in the file: none); CatalogMasterScreen.tsx 210-218 and 576-590 identical pattern. Contrast: InventoryPage.tsx has SEARCH_DEBOUNCE_MS = 250 (line 65, effect 216-221) and shared/hooks/use-autocomplete.ts exports useDebounce (300 ms default).

**Nguyên nhân gốc:** Substring search was implemented with LIKE on lowercased columns (portable across the sqlite test driver) without a matching index, and two list screens bind the search box straight into the query key.

**Ảnh hưởng ở 10.000 người dùng:** Typing 'khoan' from the SKU screen = 5 requests, 5 DB seq scans over skus, 5 Valkey entries alive 15 min each; keyword space is unbounded so cache memory (256 MB, volatile-lru) fills with one-off prefixes and evicts hot dashboard/report keys. Seq scan cost is small at 818 SKUs (NOT MEASURED at 50k+) — the request amplification and key explosion are the immediate cost at 10k users; the index matters once master data is large.

**Hướng sửa đề xuất:** FE: debounce keyword with the existing useDebounce (300 ms) and require ≥2 chars in ApiMasterScreens (SKU) and CatalogMasterScreen; keep `setPage(1)` on the debounced value. BE: (1) `CREATE EXTENSION pg_trgm; CREATE INDEX CONCURRENTLY … ON skus USING gin (lower(sku_code) gin_trgm_ops, lower(sku_name) gin_trgm_ops)` — gin_trgm_ops serves both ILIKE and lower() LIKE; same for physical_codes.code_value / item_instances.serial_number if those screens ever send keyword. (2) In BaseRepository::paginate use the 'search' policy (120 s) whenever `keyword` is non-empty instead of the repository's list policy, so keyword keys are short-lived; keep the version namespace and per-page keys. Do NOT relax validation (max:200) or the UUID guard on *_id filters; keep sqlite compatibility by only adding the index in a pgsql-guarded migration.

**Cách kiểm chứng:** Network tab while typing a 5-char term on /master-data/skus before/after (5 → 1 request); `redis-cli --scan --pattern 'wms:*:warehouse.sku:list:*' | wc -l` after a typing session; EXPLAIN on the LIKE query after the GIN index shows Bitmap Index Scan; bench /products?keyword=… with scripts/perf/bench-be.mjs.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### SEARCH-REPORTS-07 — trace-by-code resolves a scanned code with LOWER(CAST(code_value AS TEXT)) = ? — an expression match with no functional index, i.e. a full scan of physical_codes that grows with every item

**Thành phần:** Modules/Warehouse/app/Http/Controllers/StockMovementController.php::resolveTraceTarget (636-661), traceByPhysicalCode (426-582)

**Bằng chứng:** StockMovementController.php 656-661: `$needle = mb_strtolower($codeValue); DB::table('physical_codes')->where(fn($q) => $q->whereRaw('LOWER(CAST(code_value AS TEXT)) = ?', [$needle])->orWhereRaw('LOWER(CAST(hardware_uid AS TEXT)) = ?', [$needle]))`. pg_indexes on physical_codes: unique btree on code_value and hardware_uid only (no expression index). By contrast ScanClassificationService::classify (36) and ProductIdentityResolver::findItemByUnique (55-58) use exact `code_value = ?` and EXPLAIN shows `Index Scan using ux_erd_doc_physical_codes_40f937d566`. Codes are normalized upper-case at write time (ProductIdentityResolver 48, CurrentBaselineItemIdentityController generateUniqueCodeValue). Cached via cachedReadPayload keyed on stock:version (SEARCH-REPORTS-01 applies: near-zero hit rate under load). physical_codes: 3,162 rows today (pg_stat_user_tables); every issued item adds at least one row.

**Nguyên nhân gốc:** Case-insensitive match implemented as an expression on the column instead of normalizing the input to the stored form (or indexing the expression).

**Ảnh hưởng ở 10.000 người dùng:** Each trace lookup = sequential scan of physical_codes; at production (hundreds of thousands to millions of codes) that is tens-hundreds of ms of DB CPU per lookup, uncached in practice, on the same connection pool as scanning. NOT MEASURED (bench returned 422 without a code).

**Hướng sửa đề xuất:** Query the stored form first: `where('code_value', strtoupper(trim($codeValue)))` (unique index) then `hardware_uid` exact; if a case-insensitive fallback must remain, add `CREATE INDEX CONCURRENTLY … ON physical_codes (lower(code_value))` and `(lower(hardware_uid))` in a pgsql-guarded migration and keep the LOWER() predicate text identical so the planner matches it. Keep the resolution order (physical code → item → container → sku), the `stock:version` cache, and the warranty PII masking in traceWarrantyHistory.

**Cách kiểm chứng:** EXPLAIN the resolveTraceTarget query before/after (Seq Scan → Index Scan); bench /stock-movements/trace-by-code?code_value=<real code> with bench-be.mjs on a perf DB seeded to ≥500k physical_codes; unit test that mixed-case input still resolves.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

## Nhóm D — CHƯA phản biện, mức P2 (32)

### API-06 — OFFSET pagination sorted by occurred_at/created_at on ledger-sized tables with no matching index

**Thành phần:** StockMovementController::index, WmsContractController::scanEvents, CanonicalMvpController::tablePage, BaseRepository::paginate

**Bằng chứng:** EXPLAIN `SELECT * FROM stock_movements ORDER BY occurred_at DESC LIMIT 15 OFFSET 0` -> Seq Scan + Sort (cost 555); OFFSET 1900 LIMIT 100 -> same plan cost 820; pg_indexes on stock_movements has no occurred_at-leading index (10 indexes incl. idx_sm_wh_product_time, idx_sm_ledger_dimension). Laravel paginate() issues COUNT(*) (index-only via stock_movements_stock_status_index) + page query. scan_events (5,010 rows, seq_scan 42) union in WmsContractController.php:343-380 sorted by created_at with no index; label_snapshots/warranty_cases tablePage order by created_at with only (status, created_at) / (status, received_at) indexes. Dev volume (7,350 rows) hides this; the audit's section 6 explicitly says production-scale plans are NOT VERIFIED.

**Nguyên nhân gốc:** Sort column not covered by an index; OFFSET pagination re-sorts the whole filtered set per page.

**Ảnh hưởng ở 10.000 người dùng:** stock-movements list (k6 weight 6) is 20 s-cached so hit rate is high, but every miss and every distinct filter/page combination performs a full sort of the ledger; at 10^6 rows this is 100s of ms per miss and CPU on postgres (already 4.5 cores at saturation in LOAD_TEST_2026-09-06.md).

**Hướng sửa đề xuất:** Add btree indexes (occurred_at DESC, id DESC) on stock_movements and created_at on scan_events/scan_record_events/label_snapshots/warranty_cases; expose a keyset cursor (`after=<occurred_at,id>`) on /stock-movements while keeping the offset contract for existing clients. Do not change ScopesWarehouseData or the 20 s TTL semantics.

**Cách kiểm chứng:** EXPLAIN before/after showing Index Scan Backward; bench-be.mjs cold ms for /stock-movements?page=1..20; after the load DB is seeded to production-like row counts (HoaNamBulkDemoSeeder per memory notes), repeat.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### API-07 — Dashboard overview rebuild cost and optional 60 s polling of 4 endpoints

**Thành phần:** OperationalReportController::dashboardOverview/buildDashboardOverview + FE overview-data.ts

**Bằng chứng:** OperationalReportController.php:668-717 (rememberStampede dashboard 60 s +/-10%, key from+to+scope+stock:version), :904-1160 builder: ~23 distinct aggregate statements incl. a nested buildInventoryBalance(new Request(...)) (:1120-1125) that materialises the whole balances x ledger set; PERF_AUDIT section 4 measured 26 queries cold, bench warm 170 ms / cold 200 ms. FE overview-data.ts:178-191 refetchInterval 60_000 when autoRefresh is on, applied to 4 queries (dashboard-overview, xnt-enhanced, xnt-daily, dashboard-quick :225-261); default state NOT MEASURED (DashboardPage.tsx:198 only shows manualRefresh). Any write path bumps stock:version (mutateBalance) which orphans the entry, so under active scanning the effective TTL is the interval between posts, not 60 s.

**Nguyên nhân gốc:** One key per (period, scope); every stock post invalidates it; each rebuild is the most expensive read in the system.

**Ảnh hưởng ở 10.000 người dùng:** With N warehouse-scope signatures x period presets, each post triggers up to N x presets rebuilds of ~26 aggregates within the next 60 s; with polling on, 10k users x 4 req/60 s = 667 req/s of cache hits at ~30-90 ms PHP each (local-code profile) - that alone exceeds the measured 250-285 req/s single-replica ceiling.

**Hướng sửa đề xuất:** Keep the stampede lock; decouple the dashboard from stock:version (use its own `dashboard:version` bumped by a debounced job, or accept TTL-only staleness of 60 s as the audit already accepts for warranty), split the nested inventory-balance summary into its own cached key (it is also served by /reports/inventory-balance), and make FE polling opt-in / back off when the tab is hidden. Keep the 366-day guard and scope-before-cache ordering.

**Cách kiểm chứng:** Count cache.rebuild log lines per minute for the dashboard key during a scan-heavy k6 flow before/after; bench cold ms of /reports/dashboard-overview; FE: confirm autoRefresh default and measure idle req/s per open dashboard tab.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### API-08 — No rate limiting or concurrency guard on authenticated read endpoints

**Thành phần:** app/Providers/AppServiceProvider.php registerRateLimiters + routes

**Bằng chứng:** AppServiceProvider.php:107-180 defines only pv-public (120/min/IP), public-api (180/min/IP), public-quote-request (5/min), auth-refresh (30/min/IP); route:list shows `throttle:` only on /auth/refresh, /pv/*, /public/* - none on /reports/*, /inventory*, /warranty-cases etc. LOAD_TEST_2026-09-07.md shows the per-IP limiter already fires under k6 (429s) because it keys on IP, i.e. it is a bot guard, not a capacity guard.

**Nguyên nhân gốc:** Rate limiting was added for SRS 8.1 anti-abuse on anonymous routes only.

**Ảnh hưởng ở 10.000 người dùng:** A single misbehaving client (or the FE fan-out in API-03, or a report tab left with auto-refresh) can monopolise the 48-64 php-fpm workers per replica; the load test already demonstrated that saturation manifests as queueing/timeouts for everyone rather than 429 for the offender.

**Hướng sửa đề xuất:** Add a per-user limiter (Limit::perMinute(N)->by($user->user_id)) on the report and list groups sized above legitimate FE usage (after API-03 reduces fan-out), plus a lower per-user limit on the export-heavy /reports/*/export-excel; keep existing pv/public limiters and RateLimitResponse envelope. Do not throttle scan/post paths - those are the business-critical writes.

**Cách kiểm chứng:** k6 probe-concurrency.js with one VU hammering /reports/movement-trace should receive 429 after N/min while other VUs keep baseline p95; confirm 429 envelope matches Annex C.

**Trạng thái 07/09/2026:** **CHƯA SỬA — cân nhắc có chủ đích.** Thêm rate limit cho endpoint đọc đã xác thực sẽ chặn chính người dùng thật ở 10k (mandate §49); đúng hướng là bỏ mẫu fan-out ở FE (API-03) chứ không phải chặn ở BE.

---

### DB-07 — audit_log view derives id from an md5 expression and the repository default orders by it — every audit page is a full seq scan + sort, on an endpoint that is uncached by design

**Thành phần:** Modules/AuditLog (AuditLogRepository via BaseRepository default sort) + audit_log view

**Bằng chứng:** pg_get_viewdef('audit_log'): `id = COALESCE(NULLIF(metadata->>'legacy_id','')::uuid, (substr(md5('audit_log:'||id),1,8)||'-'…)::uuid)`, `object_id = COALESCE(metadata->>'legacy_object_id', entity_id::text)`. BaseRepository.php:466-467: `elseif (in_array('id', $columns)) $query->orderBy(table.'.id','desc')`; AuditLogRepository.php has no ordering override; AuditLogController.php:58-70 and :91-105 (`where('object_type','user')->where('object_id',$userId)`). EXPLAIN `SELECT * FROM audit_log ORDER BY id DESC LIMIT 20`: `Limit -> Sort (Sort Key: COALESCE(… md5(…) …) DESC) -> Seq Scan on audit_logs`; unchanged with enable_seqscan=off. The chronological index `idx_erd_audit_logs_created_at` exists and works: `ORDER BY created_at DESC LIMIT 20` → `Index Scan Backward using idx_erd_audit_logs_created_at`. myHistory: `Index Scan using idx_erd_audit_logs_entity_type_entity_id Index Cond: entity_type='user'` then `Filter: COALESCE(metadata->>'legacy_object_id', entity_id::text) = …` (entity_id not usable as Index Cond). PERF_AUDIT §4b C: /audit-logs deliberately uncached.

**Nguyên nhân gốc:** The legacy-compat view synthesises UUIDs for a bigint-keyed append-only table; the generic repository picks `id` as the sort key, which is an expression on the view.

**Ảnh hưởng ở 10.000 người dùng:** audit_logs is the fastest-growing table (every write and every RBAC deny is recorded); at tens of millions of rows each admin page computes md5 for every row and sorts them, uncached. Ordering by md5 is also non-chronological, so the page is not even the newest entries.

**Hướng sửa đề xuất:** In AuditLogRepository override sort to `orderBy('created_at','desc')->orderBy(...)` (index-backed, expected plan `Index Scan Backward using idx_erd_audit_logs_created_at … Limit`), and in myHistory filter on the base column `entity_id` (uuid) instead of the view's `object_id` expression so `idx_erd_audit_logs_entity_type_entity_id` covers both columns. Append-only guarantee and RBAC module filter (`whereIn('module', …)`) unchanged. Longer term stop exposing md5-synthesised ids via the view.

**Cách kiểm chứng:** EXPLAIN before/after on the paginate SQL; response time of /audit-logs?page=1 on a seeded 1M-row audit_logs; pg_stat_user_tables.seq_scan on audit_logs stays flat under k6 iam group.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### DB-08 — Document and warranty listings sort on created_at with no matching index and search with ilike '%kw%' — sort of the whole filtered set per page

**Thành phần:** OutboundDocumentController/InboundDocumentController index, CanonicalMvpController::tablePage

**Bằng chứng:** OutboundDocumentController.php:252-261 `StockDocument::query()->with('lines')->where('doc_type', …)[->where('status')]->orderByDesc('created_at')->paginate()`; stock_documents indexes: idx_sd_doc_type_status(doc_type,status), idx_sd_doc_date(doc_date), agency, outbound_request, doc_no, pkey, (doc_type,create_idempotency_key) — none contains created_at. EXPLAIN: `Limit -> Sort (created_at DESC) -> Seq Scan Filter: deleted_at IS NULL AND doc_type='receipt'` (seqscan off: Bitmap on idx_sd_doc_type_status + Sort). CanonicalMvpController.php:536-560 `tablePage()` default `orderByDesc('created_at')` for `warranty_case`; :373-382 keyword adds `ilike '%kw%'` on 5 columns (:669) plus two `orWhereIn('item_id', subselect … ilike)`; warranty_cases has (status, received_at), (sku_id, status) but no created_at index → EXPLAIN `Sort (created_at DESC) -> Seq Scan on warranty_cases` even with seqscan off. Controller.php:28-42 `applyKeyword` = `ilike '%kw%'` everywhere (also stock-movements movement_no/business_key/source_type). pg_stat: stock_documents seq_scan=27,120 (10.1M tuples), warranty_cases seq_scan=13,828 (2.57M), warranty_case_defects 6,586.

**Nguyên nhân gốc:** List ordering columns were never indexed; keyword search uses leading-wildcard LIKE which no btree can serve.

**Ảnh hưởng ở 10.000 người dùng:** At ~1M documents / ~500k cases each page sorts the entire doc_type partition (uncached endpoints, k6 weight 6+6+3); keyword searches become full scans with per-row pattern matching across 5–7 columns.

**Hướng sửa đề xuất:** `CREATE INDEX CONCURRENTLY ON stock_documents (doc_type, created_at DESC) WHERE deleted_at IS NULL` (expected: `Index Scan Backward … Index Cond: doc_type=…` with no Sort; add `(doc_type, status, created_at DESC)` only if the status-filtered list is common). For warranty_case either pass `received_at` as tablePage's `$orderBy` (already indexed with status) or add `warranty_cases (created_at DESC)`. For keyword search on high-cardinality text (customer_phone, customer_name, warranty_case_code, movement_no) add `pg_trgm` GIN indexes if the search is frequent; otherwise cap keyword search to prefix match. PII masking rule (§13.8, :536-541) and status filters unchanged. Write amplification: one btree each on moderate-write tables; GIN trigram indexes are heavier on insert — only where justified.

**Cách kiểm chứng:** EXPLAIN before/after for `/inbound-documents?page=1` and `/warranty-cases?page=1` SQL; pg_stat_user_tables.seq_scan delta on stock_documents/warranty_cases per 1,000 list requests → ~0.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### DB-09 — inbound_lines/outbound_lines are ~99.7% dead space (146 MB + 57 MB for 4,328 rows) and have never been vacuumed; autovacuum defaults are too lax for the hot UPDATE tables

**Thành phần:** PostgreSQL maintenance / autovacuum

**Bằng chứng:** pg_class: inbound_lines relpages=18,709, reltuples=2,923 → 52,434 bytes/row for a 13-column scalar row (toast 8 kB); outbound_lines relpages=7,264 / 1,405 rows → 42,354 bytes/row; pg_stat_user_tables: vacuum_count=0, autovacuum_count=0, last_autovacuum NULL, n_dead_tup=0 (stats were reset, so autovacuum's trigger is blind to the existing garbage). Index side: inbound_lines 40 MB of indexes incl. id ×3 (8.3 MB). Usage is low today (seq_scan=1, idx_scan=3; legacy `*__legacy_projection` views). Settings: autovacuum_vacuum_scale_factor=0.2, autovacuum_vacuum_threshold=50, track_io_timing=off.

**Nguyên nhân gốc:** Repeated seed/delete cycles (bulk demo seeders) without VACUUM; stats reset hid the dead tuples; default scale factor means large tables accumulate 20% dead rows before cleanup.

**Ảnh hưởng ở 10.000 người dùng:** Direct impact is on backups/replica copies and any accidental seq scan (200 MB of garbage); the systemic risk is that the same pattern on inventory_balances (updated on every post, non-HOT per DB-03), stock_documents and item_instances will bloat under load while autovacuum waits for 20% dead tuples (2M dead rows on a 10M-row ledger), inflating the seq scans of DB-01/DB-08.

**Hướng sửa đề xuất:** Ops: `VACUUM (VERBOSE, ANALYZE) inbound_lines, outbound_lines` then `VACUUM FULL`/`pg_repack` in a window (or drop the legacy tables if the projection views are unused — confirm with grep of `inbound_lines`/`outbound_lines` writers first). Config: per-table `ALTER TABLE inventory_balances, stock_documents, stock_document_lines, item_instances, physical_codes SET (autovacuum_vacuum_scale_factor=0.02, autovacuum_analyze_scale_factor=0.02, autovacuum_vacuum_cost_delay=2)`; enable `track_io_timing=on`. No code change.

**Cách kiểm chứng:** pg_class.relpages of the two tables after VACUUM FULL (expect ≤ ~60 pages); `n_dead_tup` trend on inventory_balances during a k6 write run; `pg_stat_progress_vacuum` shows autovacuum firing within minutes rather than hours.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### DB-10 — Post/Issue/Reversal lock scope: ordering is deadlock-safe, but per-SKU balance locks are held through subledger, posting-event and audit inserts with no lock/idle timeouts, and the OR-predicate doubles index work

**Thành phần:** CurrentBaselineStockService / AlignedOutboundStockService / CurrentBaselineReversalService (transactions)

**Bằng chứng:** CurrentBaselineStockService.php:387-470 `DB::transaction`: lockDocument (:1679-1684 `stock_documents … lockForUpdate`) → all lines `lockForUpdate()` (:393-397) → registerCandidateItems (:1240-1252, item/physical_code inserts) → per line `mutateBalance()` in global sku order (:431-436, :1221-1227 `sortLinesForBalanceLock`) → `inventory_balances … FOR UPDATE` (:1550-1556) + optimistic `version` check (:1593-1603) → movement insert → applyReceiptSubledger → status update → recordDocumentStatus/recordPostingEvent/AuditLogger::record all before COMMIT. AlignedOutboundStockService.php:37-122 same shape with `DB::transaction(…, 3)` (deadlock retry). Reversal: assertQuantityDependency :671-677 locks balances in phase 2 sorted by sku (:260-265). EXPLAIN of the lock statement with real ids: `LockRows -> Bitmap Heap Scan -> BitmapOr (idx_erd_inventory_balances_sku_id_stock_status, uk_inventory_balances_wh_product_status)` — the `(sku_id = ? OR product_id = ?)` predicate needs two index probes although 0 of 293 rows differ. Server settings: lock_timeout=0, idle_in_transaction_session_timeout=0, statement_timeout=0, deadlock_timeout=1 s. `business_key` pre-check :1546 uses the unique index (good). Lock waits under concurrent posting NOT MEASURED (k6 writes disabled).

**Nguyên nhân gốc:** Correct but coarse transactional design: a document's whole post is one transaction that pins every touched SKU's balance row for its full duration; no server-side guard against a stalled worker holding those rows.

**Ảnh hưởng ở 10.000 người dùng:** Throughput on a hot SKU is bounded by 1/(transaction duration); a multi-line document holding N balance locks serialises every other post sharing any of those SKUs (and mini-app scanners posting the same SKU). Without idle/lock timeouts a hung php-fpm worker (e.g. stuck on Valkey or S3 inside the request) blocks those SKUs indefinitely and other workers pile up to `pm.max_children`.

**Hướng sửa đề xuất:** Keep lock order, FOR UPDATE scope, version check and posting_events idempotency exactly as is. Add server-side guards: `ALTER ROLE <app> SET lock_timeout='5s', idle_in_transaction_session_timeout='30s', statement_timeout='30s'` (surface as 409/503 with retry). Shorten the critical section without changing semantics: simplify the balance predicate to `sku_id = ?` once a migration asserts `sku_id = product_id` (single index probe), and consider moving `AuditLogger::record` for POST_* to `DB::afterCommit` only if the audit contract allows non-atomic audit (flag as a product decision, not a perf change). Rely on the existing `DB::transaction(…, 3)` retry.

**Cách kiểm chứng:** k6 write profile (ENABLE_WRITES) posting documents that share SKUs while sampling `pg_stat_activity` (`wait_event_type='Lock'`) and `pg_locks` every second; `pg_stat_database.deadlocks` and posts/s per hot SKU before/after; distribution of transaction duration from log_min_duration_statement.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### DB-11 — pg_stat_statements is preloaded but never created and track_io_timing is off — statement-level hotspots cannot be measured on the perf stack

**Thành phần:** docker-compose.perf.yml postgres service

**Bằng chứng:** `SHOW shared_preload_libraries` = pg_stat_statements; `SELECT extname FROM pg_extension` in wms_hoanam, wms_hoanam_perf and postgres = plpgsql only; docker-compose.perf.yml:248-252 sets `shared_preload_libraries`, `pg_stat_statements.max=5000`, `.track=top` but no init SQL runs `CREATE EXTENSION`; `track_io_timing=off`; `log_min_duration_statement=500` (only >500 ms statements are logged). Consequence: the requested top-15 by total_exec_time/calls is NOT MEASURED in this audit.

**Nguyên nhân gốc:** Extension creation is a per-database DDL step that the compose overlay does not perform.

**Ảnh hưởng ở 10.000 người dùng:** No way to attribute PG CPU (4.5 cores at saturation per LOAD_TEST_2026-09-06 §2) to statements during load tests, so DB-01/02/05 cannot be ranked by measured cost and regressions will go unnoticed.

**Hướng sửa đề xuất:** Add an initdb script (docker/postgres/initdb/*.sql: `CREATE EXTENSION IF NOT EXISTS pg_stat_statements;`) or a one-off `docker compose exec postgres psql -c 'CREATE EXTENSION pg_stat_statements'` on the perf DB, set `track_io_timing=on`, and include `SELECT … FROM pg_stat_statements ORDER BY total_exec_time DESC LIMIT 15` in load/summarize.mjs output. Read-only observability; no application change.

**Cách kiểm chứng:** After a k6 baseline run, `pg_stat_statements` returns rows; compare top-15 by total_exec_time and by calls before/after DB-01/DB-02 fixes.

**Trạng thái 07/09/2026:** **ĐÃ SỬA** — `CREATE EXTENSION pg_stat_statements` (trước đó chỉ preload trong `shared_preload_libraries` mà chưa tạo, nên không truy vấn được).

---

### CACHE-05 — Stampede lock and TTL applied to per-user keys that the FE never re-reads (write-only cache, +4 Redis RTT per miss)

**Thành phần:** app/Support/Cache/Concerns/CachesReadPayload.php::cachedReadPayload, Modules/Notification/app/Http/Controllers/NotificationController.php

**Bằng chứng:** CachesReadPayload.php:599 always calls `rememberStampede` even when `$actorId !== null`; NotificationController.php:53-72 and :107-119 cache per actor with TTL `stock_short` (20 s +/-10%); FE useNotifications.ts:19-30 fetches unread-count once per session (`enabled` gate, comment: Echo increments afterwards) and the list with default staleTime 60 s (query-client.ts:49, refetchOnWindowFocus false) - both longer than the 20 s server TTL. Miss path costs SET NX (cmdstat_set) + GET recheck + SETEX + EVAL release (vendor RedisLock.php:37,65) in addition to the version GET. Same pattern: ImportReportController.php:215 (detail per actor), StockMovementController.php:464 (trace-by-code per actor).

**Nguyên nhân gốc:** The generic trait picks the hot-key strategy for every caller; per-user keys have concurrency ~1 so the lock never collapses anything, and the TTL is shorter than the client's own cache window.

**Ảnh hưởng ở 10.000 người dùng:** Every notification/unread-count/per-actor detail request is a guaranteed miss with 5 extra Redis round trips and one extra SETEX write (10k users x session bootstrap = pure write traffic and key churn); no DB offload is obtained.

**Hướng sửa đề xuất:** In cachedReadPayload use `remember` (no lock) when `$actorId !== null` (CacheService.php:46-63 already exists for this purpose per its docblock); drop caching for `notification:unread-count` entirely (single indexed COUNT) or raise per-user TTL to >= FE staleTime. Keep actor in the key for anything that stays cached.

**Cách kiểm chứng:** Log-sample `cache.hit` vs `cache.miss` for `notification:*` keys over an hour of real usage (expect ~0 hits today); cmdstat_set/eval deltas per 1,000 requests before/after.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CACHE-06 — Lock wait 5 s / TTL 10 s / 250 ms poll with no stale-while-revalidate: protection flips to dogpile when rebuilds are slow

**Thành phần:** app/Support/Cache/CacheService.php::rememberStampede, config/wms_cache.php lock.*

**Bằng chứng:** CacheService.php:89-90 defaults wait 5 s, ttl 10 s; :109-114 on LockTimeoutException every waiter runs `$resolver()` with no cap; vendor Lock.php:43 `sleepMilliseconds = 250`, :121-127 waiters sleep 250 ms before re-checking, so any concurrent miss pays >= 250 ms even when the rebuild takes 30 ms; no SWR anywhere (Cache::flexible exists at vendor Repository.php:642 but unused). Rebuild durations: cold p95 321 ms across read endpoints (PERF_AUDIT §1), dashboard-overview 26 queries (PERF_AUDIT §4), movement-trace ~280 ms at 5,956 rows (PERF_AUDIT §1); under saturation 305 requests > 3 s in php-fpm slowlog (LOAD_TEST_2026-09-07 §1). After each `stock:version` bump (CACHE-01) all readers of a hot key arrive on a cold key simultaneously.

**Nguyên nhân gốc:** Lock parameters were set for an unloaded system; once a rebuild exceeds 5 s (saturation, or movement-trace at production volume), waiters time out and rebuild in parallel; a rebuild > 10 s drops the lock while still running. Readers have no stale value to serve during rebuild.

**Ảnh hưởng ở 10.000 người dùng:** After every invalidation of dashboard-overview/sku-lookup/report keys, N concurrent users queue 250 ms-quantised behind one worker; if the rebuild passes 5 s all N run the 26-query aggregate at once - the DB sees the worst case precisely at peak, and php-fpm children are held for the full wait (48-64 slots).

**Hướng sửa đề xuất:** For shared hot keys (dashboard, sku lookup, reports, catalog lists) use `Cache::flexible($key, [fresh, stale], resolver, lock)` (framework 13.23 supports it: stale served immediately, refresh deferred after the response) or implement soft-TTL in CacheService (store `soft_expires`, serve stale + single refresher under a non-blocking `lock->get()`). On LockTimeoutException return the last stale value if one exists instead of rebuilding. Set lock TTL >= max observed rebuild (30 s) and lock wait shorter (1-2 s) with stale fallback; reduce `sleepMilliseconds` via `$lock->betweenBlockedAttemptsSleepFor(50)`. Keep the lock in the cache DB (WWHN-295).

**Cách kiểm chứng:** Count `cache.lock.timeout` warnings under the mixed k6 run; measure p95 of /reports/dashboard-overview in the 2 s after a forced `wms:cache:clear --scope=stock` with 40 VUs on that endpoint (probe-concurrency.js TARGET) before/after.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CACHE-07 — Fail-open is partial and expensive: JWT blacklist + throttle still hard-fail, and each request pays 3 retried connection attempts before the DB fallback

**Thành phần:** app/Support/Cache/CacheService.php, config/database.php redis.*, Tymon blacklist storage, throttle middleware

**Bằng chứng:** CacheService.php:148-229 wraps get/lock/put; CacheVersion.php:395-404 wraps the version GET; but tymon Blacklist::has (vendor Blacklist.php:128-138) -> Cache::tags()->get() is unwrapped and runs on every authenticated request (config/jwt.php:224), and `throttle:*` middleware (routes Modules/Warehouse/routes/api.php:62, Auth/routes/api.php:20, public_api.php:29,53) uses the cache store directly. phpredis options: max_retries 3, backoff decorrelated_jitter base 100 ms cap 1,000 ms (config/database.php:305-308,318-321), connect timeout 0.0 = unbounded (PhpRedisConnector.php:172). A cached read touches Valkey 3 separate times (version GET, payload GET, lock) each of which would fail independently. Behaviour with Valkey down NOT MEASURED (cannot stop it on this shared machine).

**Nguyên nhân gốc:** Fail-open was implemented at the CacheService boundary only; framework and package code paths that also depend on Valkey were not covered, and the retry policy is tuned for transient blips, not outages.

**Ảnh hưởng ở 10.000 người dùng:** If Valkey is unreachable: every authenticated request 500s at the auth middleware regardless of CacheService fail-open; if it is reachable but slow/partitioned, each request burns up to 3 x (retries x backoff) seconds holding a php-fpm child before reaching the DB, then all 10k users hit PostgreSQL with zero request collapsing (max_connections 120).

**Hướng sửa đề xuất:** Add a process-local circuit breaker in CacheService (on failure, mark Valkey down for N s in a static/APCu flag and skip cache calls - static is safe here because it is a health flag, not data); set REDIS_MAX_RETRIES=0-1 and explicit `timeout`/`read_timeout` (~0.2-0.5 s) on the `cache` connection; decide auth policy explicitly: either wrap Blacklist storage in a fail-open adapter with a short JWT_TTL, or accept hard-fail and document it; wrap RateLimiter in a try/catch middleware that allows the request when the store is unavailable. Do not put CacheService in fail-closed mode.

**Cách kiểm chứng:** In a staging stack: `docker compose pause valkey`, run bench-be.mjs for 60 s, record error rate and p95 for /api/v1/health, /api/v1/products, /api/v1/pv/products; then unpause and verify recovery. NOT MEASURED now.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CACHE-08 — Version-key double GET plus RBAC + JWT lookups = 6 GETs per request; version key is a guaranteed miss until the first write

**Thành phần:** app/Support/Cache/CacheVersion.php, CachesReadPayload::readCacheKey, Modules/Auth/app/Support/AuthCache.php

**Bằng chứng:** CachesReadPayload.php:575-579 `currentVersion()` GET before every payload GET; CacheVersion.php:398 `get(...) ?? 1` - key only created by bump() (:412-413), so on a fresh cache every version GET is a miss (LOAD_TEST_2026-09-06 §5: hit ratio 35% 'floor'); User.php:98-171 + CheckPermission.php:35-44: is-super-admin, permissions, warehouse-scopes = 3 GETs per non-superadmin request (memoised per model instance only); tymon blacklist = 1 GET. Live: cmdstat_get 642,962 over 164,387 connections = 3.91 GET/connection (includes 0-GET health/queue connections). Redis RTT 0.1-0.2 ms in-container (LOAD_TEST_2026-09-07 §1).

**Nguyên nhân gốc:** No request-scoped memoisation; the doc rejected a `static` memo because php-fpm reuses workers (correct), but a per-request memo (instance property on the container-bound CacheService, rebuilt every request under php-fpm) was not considered.

**Ảnh hưởng ở 10.000 người dùng:** ~1-2 ms of serial Redis RTT per request plus ~6 GET x 500 req/s = 3,000 GET/s; not a Valkey capacity issue but it inflates the miss counter (making the hit ratio unusable as a health signal) and adds latency on the CPU-bound PHP tier.

**Hướng sửa đề xuất:** 1) Seed version keys in `wms:cache:clear` / on boot with `Cache::add(key, 1)` so the GET is a hit. 2) Memoise `CacheVersion::current()` per request in a non-static property of the CacheService singleton (safe: container is rebuilt per php-fpm request; NOT safe under Octane - document). 3) Batch the RBAC keys: one MGET for is-super-admin/permissions/warehouse-scopes in AuthCache, or store them as one hash-like array under a single key. 4) Consider pipelining version GET + payload GET when the version is memoised from a previous call in the same request. Keep the version-in-key design (no wildcard deletes).

**Cách kiểm chứng:** Replay 1,000 requests of /api/v1/products with bench-be.mjs; measure cmdstat_get delta per request (expect 6 -> 3) and keyspace hit ratio (expect > 60% on a read-only mix once version keys exist).

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CACHE-09 — Uncompressed PHP-serialized MB payloads: double encode on miss, unserialize + full recursive corruption walk + json_encode on every hit

**Thành phần:** app/Support/Cache/CacheService.php::isCorrupted, CachesReadPayload::plainReadPayload, config/database.php redis options, docker/php/Dockerfile

**Bằng chứng:** CacheService.php:163-198 walks the entire payload array recursively on every hit; CachesReadPayload.php:630-647 json_encode + json_decode on every miss before serialize(); vendor RedisStore.php:485-535 uses plain `serialize()` unless phpredis `serializer`/`compression` options are set - none configured (config/database.php:288-322); container `php -r` shows Redis::SERIALIZER_IGBINARY / COMPRESSION_LZ4 / COMPRESSION_ZSTD undefined (phpredis 6.3.0 built without them). In-memory-paged reports drop page/per_page so one entry holds the whole result (CachesReportPayload.php:149-152); movement-trace = 4.3 MB JSON (PERF_AUDIT §6); Valkey net_output 24.9 GB / 245,468 hits = ~101 KB per hit upper bound; PHP CPU is the documented bottleneck (LOAD_TEST_2026-09-06 §2, backend 760-860% CPU).

**Nguyên nhân gốc:** Defensive design (plain arrays + corruption check) is applied uniformly regardless of payload size, and the transport is neither compressed nor binary-serialized.

**Ảnh hưởng ở 10.000 người dùng:** For report/dashboard hits the PHP worker still spends CPU proportional to payload size (unserialize + O(n) walk + json_encode of 100 KB-4 MB) and Valkey ships ~100 KB per hit; at 500 req/s that is ~50 MB/s of Valkey egress and a large share of the PHP CPU budget going to re-encoding data that was already encoded once.

**Hướng sửa đề xuất:** Rebuild the php image with `pecl install redis` configured for lz4/zstd (+ igbinary) and set `'compression' => 'lz4'` (and `'serializer' => 'igbinary'`) on the `cache` connection; for the largest report endpoints cache the final JSON string (skip isCorrupted for string payloads - it cannot contain __PHP_Incomplete_Class) and return it via a raw JSON response; cap `per_page`/date range for in-memory-paged reports; keep `serializable_classes=false`.

**Cách kiểm chứng:** MEMORY USAGE of a movement-trace key before/after compression; getrusage CPU per request for /reports/dashboard-overview on hit (probe used in LOAD_TEST_2026-09-07 §1); Valkey total_net_output_bytes per hit.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CACHE-10 — No negative caching: missing-id lookups and public PV search/404 always hit PostgreSQL

**Thành phần:** app/Support/Cache/CacheService.php (MISS sentinel), app/Repositories/BaseRepository.php::find, Modules/Warehouse/app/Services/PvCatalogReadModel.php

**Bằng chứng:** CacheService.php:25-33 documents that a null resolver result can never hit; BaseRepository.php:158-168 caches `null` for not-found ids -> rebuild every call; PvCatalogReadModel.php has 0 references to Cache/CacheService (paginateProducts :44 with ILIKE :525), PvPublicCatalogController.php:58-73 listProducts and :96 showProduct (404 for unpublished) are uncached, protected only by `throttle:pv-public` 120 req/min per IP (AppServiceProvider.php:109-115) - PERF_AUDIT §4b did not list /pv/* among the 29 uncached endpoints.

**Nguyên nhân gốc:** Sentinel design cannot represent null, and the anonymous PV read model was never put behind the cache layer.

**Ảnh hưởng ở 10.000 người dùng:** Anonymous catalogue traffic (App PV) and any client/bot enumerating ids or typing searches translate 1:1 into ILIKE/aggregate queries; a single IP gets 120/min, but 10k real devices or a distributed scraper are unthrottled at the DB.

**Hướng sửa đề xuất:** Cache an explicit marker (e.g. `['__none' => true]`) with a 30-60 s TTL for not-found lookups in BaseRepository::find and PV showProduct; wrap PvCatalogReadModel::paginateProducts/listIndustries in cachedReadPayload with `search` policy, global scope, keyword >= 2 chars, keyed on `master:version`/`stock:version` as appropriate (in_stock filter depends on stock).

**Cách kiểm chứng:** pg_stat_statements calls/min for the PV ILIKE query and the `sku ... where sku_id = ?` lookup under a k6 PV flow before/after; Valkey key count for `pv:*` namespace.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CACHE-11 — One log line per cache read (`cache.miss` at info, `cache.hit` at debug) becomes I/O at production log levels

**Thành phần:** app/Support/Cache/CacheService.php

**Bằng chứng:** CacheService.php:53,58,82,87 log on every hit/miss; .env and .env.example LOG_LEVEL=debug, LOG_CHANNEL=stack (file); LOAD_TEST_2026-09-07 §1 recorded storage/logs/laravel.log at 357 MB / 2.1 M lines consisting of cache.hit/cache.rebuild; perf overlay mitigates with LOG_LEVEL=warning (docker-compose.perf.yml:67-68) but `cache.miss` is info-level, so any production setting of info re-enables ~1 line per cached read.

**Nguyên nhân gốc:** Observability implemented as per-call logging rather than counters.

**Ảnh hưởng ở 10.000 người dùng:** At 500 req/s with info logging this is ~500-3,000 log lines/s of synchronous file writes per backend replica.

**Hướng sửa đề xuất:** Downgrade cache.miss to debug or sample (1 in 100), and expose hit/miss/rebuild/lock-timeout as counters (e.g. Valkey INCR per namespace flushed by scheduler, or Prometheus via a /metrics endpoint); keep warning-level logs for corrupted/unavailable/lock-timeout.

**Cách kiểm chứng:** Line count of laravel.log per 1,000 requests at LOG_LEVEL=info before/after.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### WRITE-06 — Redundant duplicate unique indexes on the hottest write tables multiply per-row index maintenance and WAL

**Thành phần:** PostgreSQL schema: inventory_balances, item_instances, physical_codes, label_snapshots, stock_documents-derived mirror tables

**Bằng chứng:** pg_index duplicate-column-set query on wms_hoanam: inventory_balances has 4 identical UNIQUE indexes on (warehouse_id, sku_id, stock_status) {uk_inventory_balances_wh_sku_status, ux_erd_inventory_balances_a7d7fe67, ux_erd_inventory_balance_bucket, ux_erd_doc_inventory_balances_a7d7fe67bf} plus a duplicate of the PK (ux_erd_canonical_key_inventory_balances) → 10 indexes on a 293-row table (392 kB index vs 80 kB heap); item_instances: 3 duplicate pairs (id ×2, item_code ×2, serial_number ×2) → 14 indexes; physical_codes: 3× on code_value, 3× on hardware_uid, duplicate PK, two duplicate non-unique pairs → 20 indexes (2,376 kB idx vs 744 kB heap); label_snapshots: 4 identical UNIQUE on outbound_document_id; 25+ tables carry a triple {ux_erd_mirror_*, pk_erd_*, ux_erd_canonical_key_*} on id. Post Receipt inserts item_instances + physical_codes per item and updates inventory_balances per line (CurrentBaselineStockService.php:1293, :1296, :1593); inventory_balances UPDATE touches indexed updated_at so it is never HOT.

**Nguyên nhân gốc:** Successive ERD-alignment migrations added constraint-backed unique indexes without dropping the earlier equivalent ones.

**Ảnh hưởng ở 10.000 người dùng:** Every item registration maintains ~34 index entries instead of ~24, every balance update writes 10 index entries instead of 5; adds WAL and vacuum pressure proportional to write volume. Absolute cost NOT MEASURED.

**Hướng sửa đề xuất:** For each duplicate set keep exactly one index (prefer the one backing a named constraint, `pg_constraint.conindid`) and `DROP INDEX CONCURRENTLY` the rest; keep all uniqueness guarantees (each column set still has one unique index). Add a governance test that fails when pg_index reports two indexes with identical (indrelid, indkey, indisunique, indpred).

**Cách kiểm chứng:** Re-run the duplicate-set catalog query (expect 0 rows); compare `pg_stat_user_indexes` sizes and, on a copy, `EXPLAIN (ANALYZE, WAL)` of the item_instances/physical_codes INSERT and inventory_balances UPDATE before/after (WAL bytes and time).

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### WRITE-07 — Write path has never been measured: k6 harness is read-only, pg_stat_statements is absent, and PG has no lock/idle-in-transaction timeouts

**Thành phần:** load/k6/lib/flows.js, load/k6/wms-load.js, docker-compose.perf.yml (postgres), PG settings

**Bằng chứng:** load/k6/lib/flows.js:9-10 'TẤT CẢ endpoint ở đây là GET/đọc. Kịch bản GHI nằm riêng trong writes.js và mặc định tắt' — `ls load/k6 load/k6/lib` shows no writes.js; all 31 flows are GET. `SELECT count(*) FROM pg_extension WHERE extname='pg_stat_statements'` = 0. Live settings: lock_timeout=0, statement_timeout=0, idle_in_transaction_session_timeout=0. pg_stat_user_tables shows n_tup_ins=0/n_tup_upd=0 on stock_documents, stock_document_lines, stock_movements in the current stats window (only reads exercised). LOAD_TEST_2026-09-06.md and 2026-09-07.md report read-only numbers.

**Nguyên nhân gốc:** Perf work to date targeted read latency and bootstrap cost; the write scenario was deferred and the DB has no observability extension enabled.

**Ảnh hưởng ở 10.000 người dùng:** All write findings above cannot be sized; regressions in the posting path (e.g. WRITE-01) would only surface in production. Missing timeouts mean a single stuck writer can hold locks and connections indefinitely.

**Hướng sửa đề xuất:** Add load/k6/writes.js with an isolated tenant/warehouse (create receipt → N scans → post-receipt; create outbound from request → scans → post-issue; putaway confirm), gated by ENABLE_WRITES and run only against a throwaway DB copy; install pg_stat_statements in docker-compose.perf.yml (shared_preload_libraries) and expose `top by total_exec_time`; set `lock_timeout`, `statement_timeout`, `idle_in_transaction_session_timeout` in the perf profile and map the resulting SQLSTATEs to 409/503 envelopes. This does not change business behaviour.

**Cách kiểm chứng:** First run of writes.js at baseline (e.g. 20 scans/s + 1 post/s) with pg_stat_statements enabled: record p50/p95 for scan and post, top-10 statements by total time (expect the mirror triggers' statements to dominate until WRITE-01 is fixed), and `pg_stat_database.deadlocks` / lock waits.

**Trạng thái 07/09/2026:** **ĐÃ SỬA một phần** — bộ đo vẫn chỉ đọc, nhưng `pg_stat_statements` đã bật và `SlowRequestLog` ghi được cả đường ghi. Kịch bản k6 có mutation vẫn CHƯA viết (xem phần 'Chưa đạt').

---

### FE-NETWORK-06 — Component-issue list performs an N+1 GET /warranty-cases/{id} per visible row to render case labels

**Thành phần:** src/modules/warranty/pages/WarrantyComponentIssueListPage.tsx

**Bằng chứng:** WarrantyComponentIssueListPage.tsx:135-155: `caseIds` derived from the current page rows, then `useQueries` with one `fetchWarrantyCase(caseId)` per id (up to perPage = 10–50, MVP_PAGE_SIZE_OPTIONS) on every page load/search change; results are used only for display via `caseById`.

**Nguyên nhân gốc:** The list endpoint returns warranty_case_id but not the case code/customer the table needs.

**Ảnh hưởng ở 10.000 người dùng:** perPage+1 requests per page view of this screen instead of 1; per-request cost of a case detail (joins, attachments count) NOT MEASURED.

**Hướng sửa đề xuất:** BE: embed `warranty_case.{code, customer_name, status}` in /component-issue-documents rows (or support `include=warranty_case`). FE: read the embedded fields and drop useQueries; keep `warrantyKeys.detail(id)` for the modal. Do not batch by issuing /warranty-cases?ids= unless BE supports it.

**Cách kiểm chứng:** Network log on /warranty/component-issues with perPage=50: expect 2 requests (list + warehouses) instead of up to 52.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-NETWORK-07 — Mount-time over-fetch: hidden tabs fetched eagerly (Inventory, Putaway), whole tables loaded for dropdowns (/users per_page=200, roles/permissions), a 90-day unfiltered movement ledger for a one-SKU drill-down, and a 200-row public product list used only to build filter options

**Thành phần:** InventoryPage.tsx, MvpPutawayPage.tsx, AuditLogsPage.tsx, IamUserScreen.tsx, stock-reconcile-api.ts, PvCatalogPage.tsx

**Bằng chứng:** InventoryPage.tsx:183-197 both `useInventoryBalances` and `useStockMovements` run on mount with no `enabled`, while only one tab renders (:601-754) — recorded api=2. MvpPutawayPage.tsx:208-219 tasks + history + trays on mount; history is only rendered for activeTab==='history' (:752,801). AuditLogsPage.tsx:117 `/users?per_page=200` for a filter dropdown (BE PERF_AUDIT §4 D: /users uncached). IamUserScreen.tsx:348-359 users + roles(100) + departments + warehouses + permissions(200) on mount; /system/roles loads /permissions?per_page=100 and /users?per_page=100 under different keys. stock-reconcile-api.ts:293 MOVEMENT_TRACE_DAYS=90 and :304-311 `useSkuMovements` fetches /reports/movement-trace for 90 days with no SKU filter then filters `product_code` client-side, although the endpoint accepts `product_id` (OperationalReportController.php:546 OA params). PvCatalogPage.tsx:62-78: a second `usePvProducts({industry_id, per_page:200})` purely to derive category/tag options on the anonymous PV app.

**Nguyên nhân gốc:** Convenience loading patterns: queries declared at page level without `enabled`, and option lists derived from full list endpoints because no lookup endpoints exist.

**Ảnh hưởng ở 10.000 người dùng:** Each is a 1–2× multiplier on a page's mount requests or a large payload for a small need; the movement-trace drill-down is the worst single item (a 90-day ledger — multi-MB at current data — per SKU click). Per-item cost NOT MEASURED.

**Hướng sửa đề xuất:** InventoryPage/MvpPutawayPage: `enabled: activeTab === 'balance'` / `'movements'` / `'history'` (keep keys so switching back is cached). Stock-reconcile: pass `product_id` (catalog.byCode) to useMovementTrace for the drill-down. Audit-logs/reports: use a slim `/users/lookup` (see FE-NETWORK-04) under the `ref` root and denylist persistence (already in PERSIST_DENYLIST for rpt-ops). PV: add `/pv/taxonomy?industry_id=` returning categories/tags, or cache the snapshot under the `reference` class since it is anonymous and rarely changes. Do not change business filtering semantics (server still filters).

**Cách kiểm chứng:** Per page, count mount requests before/after in Playwright (Inventory 2→1, Putaway 3→2, Audit 2→1+lookup). For the SKU drill-down, record response bytes of /reports/movement-trace with and without product_id.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-NETWORK-08 — Empty-period coverage probe re-queries the report's own endpoint over a 365-day window — for movement-trace, inventory-ledger and document-history that is an unpaginated whole-year ledger

**Thành phần:** src/modules/reports/operations/report-coverage.ts, report-probes.ts, report-range-advice.ts

**Bằng chứng:** report-coverage.ts:36 COVERAGE_WINDOW_DAYS=365; :96-130 useReportCoverage fires when `shouldProbeCoverage` (report-range-advice.ts:286-304: true whenever the selected period has zero data days, or is long and sparse); probes: report-probes.ts:24-25 inventory-ledger, :37-38 document-history, :44-47 movement-trace (no `paginate` → full-year payload), :80-82 warranty-defects and :94-96 outbound-by-recipient paginated ≤10×100. Only ImportMatchReport passes `knownCoverageDays` to skip the probe (ImportMatchReport.tsx:770). Cached 5 min per key, retry:false.

**Nguyên nhân gốc:** No BE endpoint answers 'which dates have data', so the FE reuses the heaviest read endpoints with a maximal window.

**Ảnh hưởng ở 10.000 người dùng:** Every user who selects a period with no activity (a future week, a holiday, a freshly-created scope) triggers a full-year read of the heaviest report endpoints once per 5 minutes; BE report cache absorbs repeats within TTL per scope, but the first miss is a full-year scan. Frequency in real usage and payload size NOT MEASURED (today's 3-month movement-trace is already 4.3 MB).

**Hướng sửa đề xuất:** BE: add a cheap `GET /reports/coverage?report=<key>&from&to` returning distinct activity dates (GROUP BY date, cached master/report class). FE: point CoverageProbe at it, or as an interim limit probes to endpoints that support `per_page` with a minimal page plus a `dates` aggregate. Keep the 'per-report own source' rule the file documents.

**Cách kiểm chứng:** Select an empty future week on /reports/traceability and capture the probe request's response size and duration before/after; assert the new probe returns <10 KB.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-NETWORK-09 — Search/typeahead: local 250 ms debounce but no AbortSignal, no keepPreviousData on paged lists, and a dormant useAutocomplete that retries aborted requests

**Thành phần:** list pages (InboundPage, AlignedMvpOutboundPage, InventoryPage, WarrantyPage, ReversalPage, AuditLogsPage, WarrantyComponentIssueListPage, MvpPutawayPage), module services, src/shared/hooks/use-autocomplete.ts

**Bằng chứng:** SEARCH_DEBOUNCE_MS=250 in 8 pages (e.g. InboundPage.tsx:51 with the setTimeout effect at :119-126); service functions never forward the queryFn `signal` (use-inbound.ts:38-41, use-inventory.ts:32-42, use-warranty.ts:58-61, use-reversal.ts:34-37 — `signal` appears only in pv image upload code), so superseded keyword requests complete server-side and are cached under dead keys. No list hook sets `placeholderData` (only report hooks do: report-data.ts:420-733), so every page/search change renders an empty table until the new page arrives. use-autocomplete.ts:137,155-167 creates its own AbortController inside queryFn and aborts the previous one, while `retry: 1` (:187) turns each abort into a failed query that is retried after the backoff — an extra request per keystroke window; its only consumer is UiAddressField (static JSON), so this is dormant today.

**Nguyên nhân gốc:** The shared http wrapper accepts AxiosRequestConfig but hooks never pass the React Query signal through; list pages were written before the report layer adopted placeholderData.

**Ảnh hưởng ở 10.000 người dùng:** Each abandoned keystroke window still costs a full list query on the BE (search class cached 120 s only for endpoints that use it); user-visible flicker on every page change encourages repeated clicks. Wasted-request ratio NOT MEASURED.

**Hướng sửa đề xuất:** Pass `{ signal }` from the queryFn context into `http.getPage(url, { params, signal })` in list/search services (axios supports AbortSignal); add `placeholderData: keepPreviousData` to paged list hooks (inbound, outbound, inventory, warranty, reversal, audit-logs, iam, mvp-master); in useAutocomplete drop the manual controller and use the context signal (React Query then treats aborts as cancellations, not failures). Keep the 250 ms debounce and the RBAC/business filters untouched.

**Cách kiểm chứng:** Type a 5-character keyword at ~150 ms per key on /inbound and count GET /inbound-documents in the network log before/after (expect fewer completed requests; aborted ones show as cancelled). Confirm no retries of cancelled requests in the log.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-NETWORK-10 — Realtime channel: each notification broadcast triggers two REST refetches per recipient, and every WebSocket (re)connect costs a POST /broadcasting/auth — Reverb capacity at 10k concurrent sockets is unverified

**Thành phần:** src/modules/notifications/hooks/useNotificationSocket.ts, src/shared/lib/echo.ts, src/app/layouts/topbar/AppTopbar.tsx; BE Modules/Notification + Reverb

**Bằng chứng:** useNotificationSocket.ts:27-35 subscribes `user.{id}` and on `.notification.created` calls `increment()` AND `queryClient.invalidateQueries({queryKey:['notifications']})`, which refetches both ['notifications','unread-count'] and ['notifications',{per_page:5}] mounted in AppTopbar.tsx:160-161 — i.e. the payload already carried by the socket is discarded and re-read over REST. echo.ts:26-40 authoriser posts /broadcasting/auth per channel subscription; pusher-js reconnects automatically after drops, re-authorising each time. Config: FE .env sets VITE_REVERB_APP_KEY (empty in .env.example); BE .env.example REVERB_APP_KEY blank — production realtime wiring unknown. BE unread-count is cached per actor (NotificationController.php:103-115) so the refetch is cheap on hit, but the cache is bumped on write.

**Nguyên nhân gốc:** Invalidate-on-event pattern instead of applying the pushed payload to the cache; no backoff/jitter on reconnect-driven auth.

**Ảnh hưởng ở 10.000 người dùng:** A fan-out broadcast to N users (e.g. SecurityAlertRaised) produces 2N REST requests within a second; a Reverb restart or Wi-Fi blip produces N POST /broadcasting/auth in a burst plus N×(unread-count+list) refetches on reconnect. Reverb memory/CPU per 10k sockets and the burst size: NOT MEASURED.

**Hướng sửa đề xuất:** On `.notification.created`, `setQueryData(['notifications',{per_page:5}], prepend payload)` and `setQueryData(unread-count, +1)` instead of invalidating; invalidate only on mark-read. Add jittered delay (e.g. 0–5 s) before any reconnect-triggered refetch. Confirm production REVERB_* values and size Reverb (or disable the app key so the FE degrades to badge bootstrap only, which the code already supports: echo.ts:44-47).

**Cách kiểm chứng:** Trigger one broadcast to 50 test users and count REST requests at the BE (expect 0 list/unread refetches after the change). Load test Reverb separately: 10k idle sockets + reconnect storm, measuring /broadcasting/auth req/s and Reverb memory.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-RENDER-04 — On-demand libraries are statically imported into hot paths: pusher-js + laravel-echo on /login (via auth.store), jsbarcode + qrcode on every Outbound/Warranty visit, xlsx on AuditLogs entry

**Thành phần:** src/shared/store/auth.store.ts, src/shared/lib/echo.ts, src/modules/outbound/components/PackingLabelPreviewDialog.tsx, src/modules/warranty/components/WarrantyReturnLabelDialog.tsx, src/modules/audit-logs/pages/AuditLogsPage.tsx

**Bằng chứng:** auth.store.ts:8 `import { disconnectEcho } from "@/shared/lib/echo"` (used once at :134 on logout) → echo.ts:1-2 static `import Echo from "laravel-echo"; import Pusher from "pusher-js"`; auth.store is in the entry graph (main.tsx → bootstrap-auth.ts:6). Result: dist/assets/axios-By3HSJZM.js (142,279 B / 41,689 gz, in the modulepreload list) contains pusher (markers: 100×`pusher`, 16×`Pusher`, 10×`WebSocket`); node_modules sizes pusher.min.js 60,621 B, laravel-echo/dist/echo.js 22,381 B vs axios.min.js 45,585 B → ≈ 80–95 KB raw of that chunk is Echo/Pusher that /login never uses (the only consumer is useNotificationSocket.ts:4 inside MainLayout). JsBarcode-BzYRHmQC.js (87,604 B / 19,506 gz, jsbarcode+qrcode) is a STATIC import of OutboundPage-*.js and WarrantyPage-*.js because PackingLabelPreviewDialog.tsx:2-3 and WarrantyReturnLabelDialog.tsx:2-3 import them at module top and the dialogs are statically imported by the pages. vendor-xlsx-CI0oAJXo.js (282,251 B / 92,539 gz) is a STATIC import of AuditLogsPage-CG1dHFHh.js (export-xlsx.ts:1 `import * as XLSX from "xlsx"`), whereas the report screens reach it through the separate report-export chunk.

**Nguyên nhân gốc:** Top-level imports of libraries whose only use is behind a user action (logout, open label preview, export).

**Ảnh hưởng ở 10.000 người dùng:** /login: ≈ 25 KB gz extra for every user and every deploy wave; Outbound/Warranty (the two busiest operational screens by role) each carry 19.5 KB gz + parse of barcode/QR code that only the label dialog needs; AuditLogs pays 92.5 KB gz of xlsx on entry. Small individually but they sit on the paths with the highest visit counts.

**Hướng sửa đề xuất:** echo.ts: keep `getEcho()` static for the socket hook but make auth.store's logout call `void import('@/shared/lib/echo').then(m => m.disconnectEcho())` (or have echo.ts subscribe to the auth store itself), so laravel-echo/pusher-js only load with MainLayout. Label dialogs: `const JsBarcode = await import('jsbarcode')` / `import('qrcode')` inside the effect that draws the preview (both already run in effects), or lazy() the two dialogs themselves. AuditLogs export: route through the same dynamic `import('xlsx')` path the reports use (report-export chunk). No business-rule or RBAC changes.

**Cách kiểm chứng:** Re-run the static closure script on a new build: `axios-*` chunk must lose the `Pusher` markers and shrink to ≈ 45–50 KB raw; OutboundPage/WarrantyPage graphs must not list JsBarcode-*.js; AuditLogsPage graph must not list vendor-xlsx. Network tab on /login: total JS gz before/after (expect ≈ −25 KB).

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-RENDER-07 — IoHistoryReport (/reports/pending-evidence) renders the full unpaginated document-history set into a hand-built table with no render window — the one report screen the 06/09 fix did not cover

**Thành phần:** src/modules/reports/io-history/IoHistoryReport.tsx

**Bằng chứng:** IoHistoryReport.tsx:1219 `documentFilter.rows.map((row, index) => (<tr …>` inside a raw `<table>`; :464 `const documentFilter = useSectionFilter(rows, documentFilterConfig)` — the full filtered set; io-history-api.ts:152 and IoHistoryReport.tsx:22-25 state the screen intentionally sends no `per_page`. grep for useRenderWindow/WindowedRowsFooter/WindowedMore/useAutoLoadMore/ResponsiveDataTable in this file: 0 matches, versus InventoryFlowXntReport (7), StockReconcileReport (9), WarrantyComponentsReport (5), MovementTraceReport (4). The DOM count of 1,884 nodes in FE audit §4 was measured on 06/09 before this route became the RPT-08 port (memory note rpt08-io-history-83-port); document-history payload was measured at 461 KB (query-persister.ts:44 comment).

**Nguyên nhân gốc:** The RPT-08 port arrived after the windowing sweep and rebuilt its table by hand instead of via ResponsiveDataTable or the WindowedRowsFooter helper.

**Ảnh hưởng ở 10.000 người dùng:** Not a server-side multiplier (one request per open) but a client-side re-creation of the exact INP failure class already fixed elsewhere: rows grow with warehouse history, each filter keystroke re-renders every `<tr>`; on CPU x4 machines this is the same 'long task per keystroke' pattern the audit measured at 14.7 s for 5,956 rows. Also 461 KB per open is above the 256 KB persist cap so it is refetched on every F5.

**Hướng sửa đề xuất:** Wrap `documentFilter.rows` with `useRenderWindow(rows)` and render `<WindowedRowsFooter>` after the tbody exactly as StockReconcileReport does; keep KPI/chart/filters computed on the full set (the business invariant at line 22-25 stays intact — only the painted rows are windowed). Keep `index + 1` for STT (it is already O(1)). Optionally add the STT/row-index Map if any `indexOf` appears on drill-down.

**Cách kiểm chứng:** Same method as FE audit §1/§3: open /reports/pending-evidence with a 90-day range on the seeded DB, record `document.querySelectorAll('*').length` and a `PerformanceObserver('longtask')` while typing one character in the section filter; expect DOM nodes bounded (~60 rows) and long tasks < 50 ms; confirm 'Đang hiển thị 60/N dòng' footer appears and totals/KPI unchanged.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-RENDER-08 — The entire Vietnamese dictionary (~226 KB, est.) ships in the entry graph of every page including /login

**Thành phần:** src/shared/i18n/useT.ts, src/shared/i18n/messages.ts, dist/assets/routes-*.js

**Bằng chứng:** useT.ts:12 `import { MESSAGES_VI, type MessageKey } from "./messages"` (static); i18n/index.ts:2 re-exports it; messages.ts = 275,589 B source. In dist the VI strings live in routes-JjAtcDvK.js (237,357 B / 65,491 gz — the largest entry chunk; `grep -l "Đăng nhập"` and the report string "Sổ tồn kho" both hit that file), which every chunk statically imports and which is in dist/index.html's modulepreload list. Size attribution is an estimate by proportion: messages.en.ts 241,503 B → messages.en-*.js 198,267 B (0.82), so MESSAGES_VI ≈ 226 KB of the 237 KB chunk ≈ 62 KB gz ≈ 15.5% of entry gz. The EN dictionary is already split and loaded on demand (english-messages.ts, language.store.ts:48), proving the pattern is available.

**Nguyên nhân gốc:** A single monolithic message tree for 38 screens is read through one static import; only the secondary language was split.

**Ảnh hưởng ở 10.000 người dùng:** ≈ 62 KB gz per user per session start / deploy wave (≈ 0.6 GB per deploy for 10k users) and parse of a ~226 KB object literal before the login form can render, for a page that needs ~30 auth/shared keys.

**Hướng sửa đề xuất:** Split messages.ts into a small core (auth, shared, topbar, nav) kept static and per-module namespaces (reports.*, dashboard.*, warranty.*, …) loaded with the route chunk — either by having each route module `import('./messages.<module>')` and merging into the live tree the same way ensureEnglishMessages() does, or by generating per-namespace files with a script and a typed `MessageKey` union preserved via `keyof` on the merged type. Keep the fail-safe (never render a raw key) and the `useT` API unchanged.

**Cách kiểm chứng:** Rebuild; routes-*.js (or its successor) in the entry graph should drop to < 40 KB raw; /login total JS gz −55..−62 KB; i18n.test.ts and the nav-label tests must still pass; spot-check EN/VI toggling on three screens.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-RENDER-06 — One persistent WebSocket + one channel-auth POST per authenticated tab against a single Reverb process; reconnect storms are unmitigated on the client

**Thành phần:** src/shared/lib/echo.ts, src/modules/notifications/hooks/useNotificationSocket.ts, src/app/layouts/MainLayout.tsx

**Bằng chứng:** MainLayout.tsx:94 `useNotificationSocket()` runs for every authenticated route; useNotificationSocket.ts:24-29 `getEcho()` then `echo.private(`user.${userId}`)`; echo.ts:26-41 custom authorizer issues `POST /broadcasting/auth` through the axios instance for each subscribe, :50-59 `new Echo({ broadcaster: "reverb", enabledTransports: ["ws","wss"] })` with default pusher-js reconnect settings (no jitter/activityTimeout tuning). Cleanup is correct: :37-40 `echo.leave()` on unmount, :43-47 `disconnectEcho()` on logout; auth.store.ts:134. BE side: docker-compose.yml:113-119 a single `php artisan reverb:start --port=6001`; LOAD_TEST_2026-09-07.md:123 budgets one Postgres connection for reverb; no k6 flow exercises the WS or /broadcasting/auth (load/k6/lib/flows.js is HTTP-only per the known-facts docs). Capacity per connection: NOT MEASURED.

**Nguyên nhân gốc:** Realtime bell is implemented as a per-tab private channel; fine functionally, but the client has no reconnect jitter and every reconnect re-authorises through php-fpm.

**Ảnh hưởng ở 10.000 người dùng:** 10k concurrent users ≈ 10k open WS on one Reverb process plus 10k `POST /api/v1/broadcasting/auth` (each a JWT-authenticated PHP request) at login wave; a Reverb restart/deploy makes all clients reconnect within pusher-js's default backoff and re-fire the auth POST — a synchronized burst onto php-fpm that no cache absorbs. Multi-tab users multiply this.

**Hướng sửa đề xuất:** Client: pass pusher-js options through Echo (`activityTimeout`, `pongTimeout`) and add randomized reconnect delay (pusher-js `connection.bind('state_change')` with a jittered `disconnect()/connect()` on `unavailable`), and consider connecting only after the shell is idle (rIC) rather than on MainLayout mount; keep the leave/disconnect cleanup as is. Infra (other lens): enable Reverb scaling (`REVERB_SCALING_ENABLED` with the existing Valkey) and run ≥ 2 reverb replicas behind the LB; add a k6 WS scenario. No change to channel authorization rules.

**Cách kiểm chứng:** k6 `ws` scenario: ramp 1k→10k connections to Reverb, subscribe `user.{id}` with real JWTs, measure connection success rate, p95 subscribe time, and php-fpm req/s on /broadcasting/auth; then restart reverb and measure the reconnect burst width (auth req/s over 30 s) before/after jitter. Browser: confirm exactly one WS per tab and zero WS on /login.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### FE-RENDER-09 — No field Web Vitals / RUM: LCP, INP and CLS at scale cannot be observed; the only performance monitor in the codebase is dead code

**Thành phần:** src/main.tsx, src/shared/utils/performance-monitor.ts, src/shared/config/env.ts

**Bằng chứng:** grep for `web-vitals`, `onLCP`, `onINP`, `onCLS`, `PerformanceObserver` in non-test src: no hits. performance-monitor.ts exists (custom chunk/API timers gated by `VITE_ENABLE_PERF_MONITOR`, env.ts:184-186) but `grep -rn "performance-monitor" src` finds no importer; preload-manager.ts likewise unreferenced and its route lists are empty. All numbers in FE_PERF_AUDIT_2026-09-06.md are lab (Playwright/CDP on one machine); §7.3 explicitly discards the CPU x4 runs as noisy and §7.6 notes FE was not re-measured on the fixed BE stack.

**Nguyên nhân gốc:** Performance work has been driven by ad-hoc lab scripts (scripts/perf/*.mjs); nothing reports from real users' browsers.

**Ảnh hưởng ở 10.000 người dùng:** The 10k target cannot be verified from the client side: regressions in INP (the class of bug found at 14.7 s) or LCP on warehouse hardware will only be found by user complaints, and there is no way to attribute slowness to network (VPS/CDN), BE latency, or main-thread work per route.

**Hướng sửa đề xuất:** Add the `web-vitals` package (≈ 2 KB gz) in main.tsx after first paint: `onLCP/onINP/onCLS/onTTFB` with attribution build, sample 2–5% of sessions, batch via `navigator.sendBeacon` to a tiny BE endpoint (or the existing audit/log sink) tagged with route, build id (`__APP_BUILD_ID__`), and device memory/hardwareConcurrency; delete performance-monitor.ts and preload-manager.ts (dead). Respect the SW denylist (beacon goes to /api, which the SW already ignores).

**Cách kiểm chứng:** Dashboard of p75 LCP/INP/CLS per route and per build id; assert p75 INP < 200 ms and LCP < 2.5 s on /dashboard and the 8 report routes over a week of real traffic; use it as the acceptance gate for FE-RENDER-01/02/03 instead of lab-only numbers.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CAPACITY-07 — Global DB row mutex serialises all writes under /api/v1/skus* and /api/v1/containers* (10 routes incl. the 60 s SKU import) cluster-wide with unbounded lock waits that pin php-fpm children

**Thành phần:** Wwhn172AggregateLock middleware

**Bằng chứng:** BE/app/Http/Middleware/Wwhn172AggregateLock.php:27-36 wraps the entire request in `DB::transaction` after `lockForUpdate()` on a single `aggregate_mutexes` row; :44-57 applies to every non-GET request whose path starts with /api/v1/skus or /api/v1/containers; route:list: 10 such routes (POST/PATCH skus, POST skus/import-excel, POST skus/{id}/items, physical-codes, POST/PATCH containers, container items); SkuImportContract.php:86-87 commit budget 60,000 ms at max_rows 1000; live pg_settings lock_timeout 0; middleware is prepended globally (bootstrap/app.php:46).

**Nguyên nhân gốc:** WWHN-172 integrity invariant implemented as one process-wide mutex row rather than per-aggregate keys or bounded waits; correct for consistency, but it turns every concurrent write into a queue whose waiters each hold a worker and a PG connection.

**Ảnh hưởng ở 10.000 người dùng:** Derived: if 1% of 10k users perform a container/SKU write per minute (≈1.7/s at 50–100 ms each) utilisation is only 8–17% in steady state, but one SKU import (up to 60 s) blocks all of them → ~100 waiters within a minute > 96 children (2 replicas) → reads also fail; the same happens whenever any single write on these routes is slow, since lock_timeout is 0.

**Hướng sửa đề xuất:** Keep the lock and its fail-closed audit policy; bound the wait: inside the transaction run `DB::statement("SET LOCAL lock_timeout = '5s'")` before `lockForUpdate()` and map the 55P03 QueryException to the existing 409 STATE_CONFLICT/503 envelope with Retry-After (FE already treats 409 specially, axios.ts:189-192); take the import commit out of the request path (queue job with the same mutex) or give the import its own key; if WWHN-172 allows, key the mutex per SKU/container id instead of one global row. No change to RBAC, idempotency keys or business rules.

**Cách kiểm chứng:** Start an import-excel with max rows while POSTing /containers from 20 VUs: before, waiters accumulate in `pg_locks` (granted=false) and php-fpm active processes climb; after, waiters return 409 within 5 s and active processes stay flat; regression tests for WWHN-172 remain green.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### CAPACITY-08 — Valkey client has no connect/read timeout (stall = hang, fail-open never triggers), opens a new TCP connection per request, and volatile-lru at 256 MB can evict JWT blacklist and rate-limit keys

**Thành phần:** Valkey / config/database.php redis client

**Bằng chứng:** BE/config/database.php:150-166: `'persistent' => env('REDIS_PERSISTENT', false)`, no `timeout`/`read_timeout` keys, `max_retries` 3 with decorrelated jitter 100–1,000 ms; vendor Illuminate/Redis/Connectors/PhpRedisConnector.php:167-185 passes timeout 0.0 and uses `connect` (not `pconnect`); live `INFO stats`: total_connections_received 164,404 vs keyspace_hits 245,468 + misses 440,776 (≈1 connect per 4 commands); CONFIG GET: maxmemory 268,435,456, maxmemory-policy volatile-lru, maxclients 10000, timeout 0; config/jwt.php:224 blacklist_enabled true (blacklist entries carry TTL → evictable), limiters and cache locks also TTL'd; CacheService fail-open only catches exceptions (app/Support/Cache/CacheService.php:148-171,216-229) — a hung socket throws nothing.

**Nguyên nhân gốc:** Laravel defaults kept (no timeouts, non-persistent), and a single Valkey instance with one eviction policy serves both evictable cache payloads and non-evictable security state.

**Ảnh hưởng ở 10.000 người dùng:** At 500 req/s ≈ 500–1,000 TCP connects/s to Valkey (functionally fine, adds NOT MEASURED sub-ms per request); a Valkey pause/network stall blocks every php-fpm child indefinitely (max_execution_time does not count socket waits) → total outage instead of the designed fail-open; 3 retries × up to 1 s backoff add up to ~3 s per Redis connection attempt on outage; when report payloads (MB × filter combos × 10k users' RBAC entries) exceed 256 MB, LRU can evict revoked-token blacklist entries (revocation silently undone) and limiter counters.

**Hướng sửa đề xuất:** Set `'timeout' => 1.0, 'read_timeout' => 1.0` on both `default` and `cache` connections (env REDIS_TIMEOUT), `REDIS_PERSISTENT=true`, `max_retries` 1 with cap 200 ms; raise VALKEY_MAXMEMORY to preset B/C (1–2 GB); split security/queue state from cache: second Valkey instance (or ACL/DB with its own policy) using `noeviction` for DB0 (queue, blacklist, limiters, sessions) while cache keeps volatile-lru; keep CacheService fail-open semantics and lock connection on the cache DB (config/cache.php:90).

**Cách kiểm chứng:** `INFO stats` connections/sec before/after persistent=true under the baseline profile; `docker pause valkey` for 5 s during smoke → requests must return (fail-open) within ~1 s instead of hanging until nginx 60 s; memory soak: fill cache past maxmemory and assert `evicted_keys` never includes `jwt`/`throttle` prefixes (`SCAN` sample).

**Trạng thái 07/09/2026:** **ĐÃ SỬA một phần** — timeout kết nối/đọc Valkey đã có (2 s ở hồ sơ chịu tải) nên Valkey treo không còn ghim worker; kết nối nay là pconnect. Phần trần bộ nhớ 256 MB / volatile-lru evict được khoá JWT blacklist thì CHƯA xử lý (cần tách DB hoặc chính sách noeviction cho khoá bảo mật).

---

### CAPACITY-09 — Capacity cannot be proven in production: no php-fpm status page in the running container, pg_stat_statements not created, PHP display_errors=On with no php.ini, and no Docker log rotation for stderr logging

**Thành phần:** Observability / runtime defaults

**Bằng chứng:** php-fpm -tt in backend-1: `pm.status_listen = undefined` (the on-disk perf-entrypoint.sh:50-55 now adds `pm.status_path=/fpm-status` but the running container predates it); psql: `select extname from pg_extension where extname='pg_stat_statements'` → 0 rows although shared_preload_libraries=pg_stat_statements (docker-compose.perf.yml:248-252); php-fpm -i: `Loaded Configuration File => (none)`, display_errors On, log_errors Off (only php.ini-development/production present in /usr/local/etc/php); nginx perf `access_log off` (nginx.conf:31); no `logging:` block in docker-compose.yml or docker-compose.perf.yml while LOG_STACK=stderr (docker-compose.perf.yml:68) — the same unbounded-growth pattern that produced a 357 MB laravel.log (LOAD_TEST_2026-09-07.md §1).

**Nguyên nhân gốc:** Perf overlay focused on throughput knobs; instrumentation and hardening defaults were left at image defaults.

**Ảnh hưởng ở 10.000 người dùng:** Every finding above needs pool/queue/DB-statement evidence to be verified; without it, saturation shows up as user-visible 60 s stalls first. display_errors=On leaks paths/stack fragments in fatal responses (e.g. memory_limit hits under heavy reports). Unrotated json-file logs at warning level are small, but any regression to info/debug at 500 req/s fills the disk.

**Hướng sửa đề xuất:** Recreate backend so `/fpm-status` is live and scrape it; run once `CREATE EXTENSION pg_stat_statements;` (DBA action, then `pg_stat_statements_reset()` before load runs); `COPY docker/php/php.ini` derived from php.ini-production (display_errors Off, log_errors On, expose_php Off) in docker/php/Dockerfile; add `logging: {driver: json-file, options: {max-size: '50m', max-file: '5'}}` to backend/queue/reverb/nginx; re-enable buffered access log (`access_log ... main buffer=64k flush=5s`) with rt/urt fields already defined (nginx.conf:23-26). No application behaviour change.

**Cách kiểm chứng:** `curl http://127.0.0.1/fpm-status?full` from the nginx container returns pool metrics; `select count(*) from pg_stat_statements` > 0; `php-fpm -i | grep display_errors` → Off; `docker inspect backend-1 | jq .[0].HostConfig.LogConfig` shows max-size.

**Trạng thái 07/09/2026:** **ĐÃ SỬA phần đo được** — trang `pm.status_path=/fpm-status` (chỉ mở cho mạng nội bộ) + `SlowRequestLog` (route, SQL ms, số câu, hit/miss cache, chờ lock, bộ nhớ đỉnh) + `pg_stat_statements` đã `CREATE EXTENSION`. Xoay vòng log Docker và `php.ini` production vẫn CHƯA làm.

---

### SEARCH-REPORTS-08 — Unbounded sub-scans inside three reports: label-log aggregates all label_print_events, import-match's sku_code filter json_decodes every import row in PHP, warranty reports filter on COALESCE(received_at, created_at) which defeats the (status, received_at) index

**Thành phần:** AlignedLabelLogReportController::buildUncached (129-140), AlignedImportMatchReportController::buildUncached (152-168), BaM1ReportController::warrantyDefectsData/defectSkuBreakdownData/componentUsageData (277, 382, 393, 507, 579), AlignedWarrantyReportController (215)

**Bằng chứng:** AlignedLabelLogReportController.php 129-139: `$attempts = DB::table('label_print_events')->select('label_snapshot_id')->selectRaw(COUNT/SUM CASE…)->groupBy('label_snapshot_id')` with no predicate, then `leftJoinSub($attempts, …)` on the period-filtered snapshots — the sub-aggregate always covers the whole table. AlignedImportMatchReportController.php 154-167: when `sku_code` is set, `DB::table('outbound_request_import_rows')->orderBy('row_no')->get(['import_id','normalized_payload'])` (no WHERE) and `json_decode` per row in PHP although idx_erd_outbound_request_import_rows_resolved_sku_id exists. BaM1ReportController.php 277/382/393/507/579 and AlignedWarrantyReportController 215: `whereBetween(DB::raw('COALESCE(wc.received_at, wc.created_at)'), …)` — pg_indexes shows idx_erd_warranty_cases_status_received_at (status, received_at), unusable for the COALESCE expression. Tables are small today (label_print_events 89, outbound_request_import_rows 216, warranty_cases 186 rows) so nothing is measurable yet.

**Nguyên nhân gốc:** Aggregations and filters written for correctness on tiny tables; the period predicate was applied to the driving table only.

**Ảnh hưởng ở 10.000 người dùng:** Grows with history rather than with the selected period; label-log and warranty reports are opened from the report hub and by the 365-day coverage probes, so each cache miss (frequent, see 01) re-aggregates whole tables. NOT MEASURED at scale.

**Hướng sửa đề xuất:** label-log: restrict the attempts subquery to snapshots in the period (`whereIn('label_snapshot_id', snapshot-id subquery)` or `created_at` bound via idx_label_print_snapshot_created). import-match: replace the PHP scan with `whereExists` on outbound_request_import_rows using `resolved_sku_id IN (…)` or `normalized_payload::jsonb ->> 'sku_code' = ?`; keep the same matching semantics (sku_id OR sku_code). warranty: backfill `received_at` where NULL (it is the ERD field) and filter on `wc.received_at` directly, or add an expression index `(COALESCE(received_at, created_at))` on warranty_cases in a pgsql-guarded migration; keep the identification_status/defect filters and the PII rules untouched.

**Cách kiểm chứng:** EXPLAIN the three queries on a seeded perf DB (≥100k label_print_events, ≥50k warranty_cases): expect Index Scan/Bitmap on the period bound instead of Seq Scan; bench /reports/label-log, /reports/import-match?sku_code=, /reports/warranty-defects with bench-be.mjs before/after.

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---

### SEARCH-REPORTS-09 — inventory-balance builds every (warehouse×SKU×status) row with four `whereIn(all product ids)` subledger queries and PHP sorting, even when the caller only needs one count (dashboards) or one page (reconcile screen)

**Thành phần:** OperationalReportController::buildInventoryBalanceUncached (1216-1398), serialSubledgerCounts/boxSubledgerCounts/locationHints/serialTrackedFlags (1400-1607), inventoryReconciliationData (596-624), paginateReport (2542-2565)

**Bằng chứng:** buildInventoryBalanceUncached: `$balanceQuery->get()` (all rows) + `ledgerAggregateWithMasterQuery(...)->get()` (whole ledger, see 02) → `$productIds` of every SKU in the result → 4 queries each `whereIn('sku_id'/'id'/'primary_sku_id', $productIds)` (a bind list as long as the catalog) → PHP map/groupBy/sortBy over every row. buildDashboardOverview 1128-1131 calls it for `summary.mismatch_count` only; buildDashboardQuick 840 calls it with only_mismatch (not a validated filter — the whole payload is built then `->where('reconciliation_status','MISMATCH')` in PHP). inventoryReconciliation re-runs withSubledgerChecks over all rows then pages in memory (paginateReport). Measured: 244,489 B / 130 ms (balance), 365,771 B / 129 ms (reconciliation) at 293 balance rows. inventory_balances seq_scan counter = 8,924,965 (pg_stat_user_tables) — harmless at 293 rows, indicative of full-table access patterns.

**Nguyên nhân gốc:** One builder serves the reconcile screen, the export, and two dashboard counters; there is no summary-only path, and the subledger counts are fetched per SKU list instead of joined/grouped in SQL.

**Ảnh hưởng ở 10.000 người dùng:** Rows scale with warehouses×SKUs×statuses (e.g. 5×50k×4 = 1M rows per rebuild), 4 bind lists of 50k UUIDs, all sorted in PHP — executed on every dashboard cache miss (01). NOT MEASURED at scale.

**Hướng sửa đề xuất:** Add a `summary_only` internal path (or reuse LedgerReconciliationService::divergences) that computes total_rows/total_on_hand/total_available/mismatch_count in SQL (`balances FULL OUTER JOIN ledger aggregate ON dims` with `ABS(on_hand - ledger) > 0.001`) and let dashboards call that; move serial/box/location counts to grouped joins on the aggregated dimension set instead of whereIn lists; for the reconcile screen paginate in SQL when per_page is present (keep `by_category` and `summary` whole-set via the aggregate). Keep the row shape, `as_of_snapshot` semantics, the NOT_APPLICABLE rules and mismatch reason codes exactly as they are.

**Cách kiểm chứng:** DB::listen query count and bytes for /reports/dashboard-overview and /reports/inventory-reconciliation before/after on a perf DB seeded to ≥200k inventory_balances; assert mismatch_count equality between the SQL summary and the current PHP path on seeded divergences (the seeder intentionally leaves 6 mismatches per memory note).

**Trạng thái 07/09/2026:** chưa xử lý trong đợt này.

---
