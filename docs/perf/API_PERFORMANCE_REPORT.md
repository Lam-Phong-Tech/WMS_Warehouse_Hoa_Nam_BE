# API_PERFORMANCE_REPORT — Hoà Nam WMS (07/09/2026)

> **Mức tin cậy.** Các phát hiện dưới đây do một luồng audit chỉ-đọc lập, có bằng
> chứng file:dòng / câu SQL / EXPLAIN. Vòng phản biện độc lập chỉ chạy được 1/42
> mục trước khi phiên chạm hạn mức, nên **trừ những mục ghi rõ "ĐÃ SỬA" (đã có số
> đo trước/sau), phần còn lại là CHƯA ĐƯỢC XÁC NHẬN ĐỘC LẬP** — hãy đọc như đầu
> mối điều tra, không phải kết luận.

## 1. Tổng quan

Backend exposes 352 routes (174 GET, 135 POST, 31 PATCH, 8 DELETE, 3 PUT, 1 GET|POST, 7 non-API: swagger/docs/up/storage/root). Of the ~170 API GETs, ~70 are hit by the web FE (mini-app/* is a separate client, public/* and pv/* are anonymous). Per-request fixed cost on every authenticated route: auth:api guard SELECT app_user + a SECOND identical SELECT inside CheckPermission (JWTAuth::setToken()->authenticate() -> onceUsingId, vendor JWTAuth.php:61-69, Illuminate.php:54-57), plus 4-6 Valkey GETs (is-super-admin, permissions, warehouse-scopes on two different User instances, CacheVersion, payload). Middleware chain otherwise cheap; Wwhn172AggregateLock only serializes POST/PATCH under /skus and /containers. Pagination: every list clamps per_page (BaseRepository 100, Controller::perPage 200, OperationalReport MAX_PER_PAGE 200); all are OFFSET-based (Laravel paginate = COUNT(*) + LIMIT/OFFSET) but no FE screen paginates deeper than 30 pages. The real risks at 10k users are (1) five report endpoints that return the whole filtered set with no page cap and are called by the FE without per_page (movement-trace/inventory-ledger 4.5 MB, document-history 1.0 MB, outbound-by-agency 544 KB, inventory-reconciliation 366 KB, inventory-balance 244 KB, measured in build/perf/bench-be-after-all.json), all backed by seq scans on stock_movements because there is no index on occurred_at alone; (2) FE fan-out patterns that turn one screen into 10-30 sequential/parallel API calls (fetchAllPages on warranty-cases/component-issues/warranty-defects/outbound-by-recipient/label-print-events, per-SKU /inventory/balances calls in outbound modals, per-page aggregate re-computation in outbound-by-recipient); (3) a cache-key bug on /inventory (scope not in key while the model carries ScopesWarehouseData) which is a correctness issue that also blocks safely raising its TTL; (4) the published "55/84 cached" figure is inflated: 13 public/pv endpoints were counted because the throttle counter wrote a key (cache-coverage.json sample = wms-hoanam-database-wms<sha1>), so real payload-cache coverage is 42/84, and the anonymous PV/public catalog runs two correlated subqueries per row on inventory_balances uncached. Dashboard overview is well cached (60s TTL, stampede lock) but rebuilds ~26 aggregates on miss and the FE can poll 4 endpoints every 60s. Rate limiting exists only on public/pv (per IP) and auth/refresh; no limiter on authenticated read endpoints, so the FE fan-out patterns are unthrottled.

## 2. Kiểm kê endpoint

## Route census (docker compose exec backend php artisan route:list --json, 352 routes)

| Domain | GET | Write (POST/PATCH/PUT/DELETE) | Notes |
|---|---|---|---|
| auth | 1 (/auth/me) | 8 (login, logout, refresh, switch-context, change/forgot/reset password, verify-otp) | refresh throttled 30/min/IP |
| dashboard / reports | 30 | 3 (export create, byCode POST alias) | /reports/{report_code} dispatcher + 8 export-excel + export job status/download |
| inventory / stock | 4 inventory + 5 stock-movements + 2 safe-stock | reversal-documents 4 GET + 4 write | EnforceInventoryWarehouseScope on 6 paths |
| SKU / master / search | products 2, skus 7 (lookup, import-reports, template), product-masters 2, brands, categories, models, power-sources, package-variants, catalog-masters, defects 4, agencies/recipients 4, warehouses 2, containers 2, physical-codes 2, item-instances 3, import-batches 2 | ~40 | BaseRepository cache enabled for product, product-master, agency, defect, warehouse, role, permission |
| inbound / receipt | 4 (list, show, attachments, scan-entries) | ~14 (boxes, component-lines, scans, unscan, post-receipt, reversals, cancel, carton complete-scan) | EnforceInboundWarehouseScope |
| putaway | 3 (putaway-tasks, putaway/tasks alias, putaway/history) | 4 | none cached (correct: live queue) |
| outbound / issue | 3 outbound-documents + 2 outbound-requests + 5 outbound-request-imports | ~20 (scans, unmatch, post-issue, reversals, rfid, import confirm) | EnforceOutboundWarehouseScope |
| packing label | label-snapshots 2, label-print-events 1, printer-configs 1 | 6 (print/reprint/apply/ready/preview) | |
| lifecycle / trace | stock-movements/{id}/trace-source, trace-by-code, inventory/trace/{code}, item-instances/{id}/trace, scan/events, scan/batches, document-status-history | scan/classify | |
| warranty | warranty-cases 4, warranty-component-issues 2, component-issue-documents 2, warranty-attachments 1 | ~12 | listWarrantyCases NOT cached |
| RBAC / admin / audit | users 3, roles 2, permissions 2, departments 1, audit-logs 2, notifications 2, pv-admin/resources 1 | ~20 | audit-logs uncached by design |
| PV public (anonymous) | pv/products, pv/products/{id}, pv/catalog, pv/industries, pv/tags, pv/recommendations, pv/product-images/{key} | 0 | throttle:pv-public 120/min/IP |
| public storefront (anonymous) | 8 (/public/home, products, products/{slug}, related, categories, facets, config, health/version) | 1 (quote-requests, 5/min/IP+phone) | throttle:public-api 180/min/IP |
| mini-app | 23 GET | ~30 | separate client; shares controllers with web |
| misc | /, /up, /api/v1/health, /docs, /api/documentation, storage/{path}, broadcasting/auth | webhooks/m2 | |

## Interactive endpoints used by the web FE

TTLs from config/wms_cache.php: rbac 300, master 1800, reference 3600, sku 900, search 120, dashboard 60, report 300, stock_short 20 (+/-10% jitter). "queries(est)" = SQL statements on happy path with warm RBAC cache and cache miss, INCLUDING the 2 app_user SELECTs from auth:api + CheckPermission; on cache hit only those 2 (+ scope-middleware lookups) run.

| route | domain | pagination | cache/TTL | queries(est) | unbounded? | notes |
|---|---|---|---|---|---|---|
| GET /auth/me | auth | none | none (RBAC sub-lookups cached 300s) | 2 + roles GET(1) + 3 Redis | no | AuthController.php:296-305, 456-468 |
| POST /auth/refresh | auth | none | none | ~6 in txn (lockForUpdate refresh_tokens, revoke, insert, user, audit) | no | RefreshTokenService.php:45-86; FE calls on every F5; throttle 30/min/IP |
| GET /reports/dashboard-overview | dashboard | none | rememberStampede dashboard 60s, key=from+to+scope+stock:version | 2 + ~23 aggregates on miss (audit measured 26) | no (366-day guard) | OperationalReportController.php:668-717, 904-1160; nested buildInventoryBalance() (whole balances x ledger agg) inside; FE can refetchInterval 60s x4 endpoints (overview-data.ts:178-191) |
| GET /reports/dashboard-quick | dashboard | none | same policy | 2 + ~9 | no | :627-666, 750-903 |
| GET /reports/inventory-xnt-enhanced, /inventory-xnt-daily | dashboard/reports | none | cachedReportPayload report 300s | 2 + 4-6 | rows = dims (wh x sku x status), bounded by SKU count | :1609-1920; 422 without period |
| GET /reports/hub | reports | none | none (static array) | 2 | no | :176-190; FE reads it on report screens; harmless |
| GET /reports/movement-trace, /reports/inventory-ledger, /reports/{code} inventory-ledger | reports | in-memory ONLY if client sends per_page (paginateReport :2542-2565); SQL forPage only when per_page sent (:2434-2447) | cachedReportPayload report 300s, key WITHOUT page/per_page (:2367-2378) | 2 + 5 (main SELECT, by_movement_type, original_no, physical_codes map, [aggregate]) | YES: FE omits per_page by design (movement-trace-api.ts:24, io-history-api.ts:197, inventory-flow-api.ts:307) -> whole period; measured 4,511,814 bytes / 5,956 rows for 30 days; seq scan on stock_movements (EXPLAIN: Seq Scan Filter occurred_at BETWEEN, no occurred_at index) | default period 30 days; 6,190 movements/30d in dev DB |
| GET /reports/document-history | reports | in-memory if per_page sent | report 300s, key w/o paging | 2 + 3 (docs, posted_by_line agg, lines) | YES: all docs in period with nested lines; measured 1,022,303 bytes; FE io-history/recipient/movement-trace call it without per_page | :2080-2237 |
| GET /reports/outbound-by-agency | reports | none | report 300s | 2 + 1 join query on whole period | YES: 543,706 bytes measured; FE useOutboundByAgency no per_page | :2239-2300 |
| GET /reports/inventory-balance | reports | none | rememberStampede report 300s | 2 + 6 (balances, ledger agg+master, serial counts, box counts, location hints, serial flags) | whole balances table (bounded by dims); 244,489 bytes | :1160-1400 |
| GET /reports/inventory-reconciliation | reports | none | report 300s (wraps inventory-balance) | 2 + 6 + 1 | whole balance set; 365,771 bytes | :574-620 |
| GET /reports/outbound-by-recipient | reports | SQL forPage, max 100 | cachedReportPayload report 300s, key WITH page (BaM1ReportController.php:39) | 2 + 6 per page (count, sum, distinct count, by_sku agg, by_recipient agg, page) | bounded per page but FE fetches up to 10 pages (report-data.ts:770-810) and 30 pages for comparison tab (recipient-agency-api.ts:594) -> aggregates recomputed for EVERY page on miss | :55-140 |
| GET /reports/warranty-defects | reports | SQL forPage, max 100 | report 300s with page | 2 + 4 per page | FE fetchAllPages up to 10 pages (report-data.ts:603-645) | :250-330 |
| GET /reports/import-match, /reports/label-log | reports | in-memory after full build (Aligned*Controller paginate()) | report 300s w/o paging | 2 + 3-5 | whole period; import-match 209,001 bytes; import-match sku_code branch scans ALL outbound_request_import_rows (AlignedImportMatchReportController.php:157) | |
| GET /inventory, /inventory/balances | inventory | SQL paginate, per_page<=200 (Controller::perPage) | rememberStampede stock_short 20s, key=filters+page+per_page+stock:version, NO scope (InventoryBalanceController.php:78-96) | 2 + 6 (count, page, product+warehouse eager x2, ledger totals, subledger, container hints, incidents) | no | model InventoryBalance uses ScopesWarehouseData global scope -> key shared across scopes; FE outbound modals call once PER SKU (mvpOutboundService.ts:815-830) |
| GET /inventory/balances/{id}/subledger | inventory | none | none seen | 2 + scope lookup + n | no | |
| GET /stock-movements | inventory | SQL paginate <=200 | cachedReadPayload stock_short 20s, key WITH paging + scope | 2 + 6 (count, page, product, warehouse, document, creator) | no | ORDER BY occurred_at DESC -> Seq Scan + Sort (EXPLAIN), COUNT(*) index-only; deep OFFSET cost grows with ledger |
| GET /stock-movements/{id}, /trace-source, /trace-by-code | trace | none | stock_short 20s (trace-by-code uncached) | 2 + 1 scope + 3-10 (chain walk bounded by MAX_CORRECTION_CHAIN_DEPTH) | no | StockMovementController.php:159-260 |
| GET /products, /skus | SKU | SQL paginate <=100 (BaseRepository) | rememberStampede sku 900s, key filters+page+per_page | 2 + 2 (count, page) | no | BaseRepository.php:70-115 |
| GET /skus/lookup | SKU | none | rememberStampede master 1800s | 2 + 1 | whole SKU table by design (818 rows, 9 cols) | ProductController.php:~90-108 |
| GET /products/{id} | SKU | none | remember sku 900s | 2 + 1 | no | |
| GET /brands, /product-categories, /product-models, /power-sources, /package-variants, /agencies, /defects, /warehouses, /product-masters | master | paginate <=100 or full list | master/reference via repo or CurrentBaselineMasterController | 2 + 1-2 | small tables | /warehouses, /printer-configs, /users NOT cached (audit 4b-D) |
| GET /inbound-documents, /outbound-documents | documents | SQL paginate <=200 | inbound: NONE (cache-coverage false); outbound aligned: cachedReadPayload search 120s WITH paging | inbound 2 + 3 (count, page, lines eager); outbound 2 + 5 (count, page, lines, progress stats, request_no) | no | InteractsWithStockDocuments.php:24-43; AlignedOutboundDocumentController.php:31-96; inbound list measured cold 384 ms / 40 KB (largest cold in bench) |
| GET /inbound-documents/{id}, /outbound-documents/{id} | documents | none | none | 2 + 1 scope + 4 (doc, lines.product, movements, statusHistory) | no | InteractsWithStockDocuments.php:58 |
| GET /warranty-cases | warranty | SQL paginate <=200 (tablePage) | NONE | 2 + 4 (count, page, sku enrich, defects enrich) | no; but FE fetchAllPages up to 10x100 on warranty report (report-data.ts:660-675) | CanonicalMvpController.php:362-447, 515-575; keyword adds 2 subqueries |
| GET /warranty-cases/{id}, /events, /attachments | warranty | events paginate | none | 2 + 2-6 | no | |
| GET /warranty-component-issues, /component-issue-documents | warranty | tablePage <=200 | NONE | 2 + 2 | FE fetchAllPages up to 10 pages | |
| GET /label-snapshots | packing label | tablePage <=200 | cached (coverage true) | 2 + 2 | no; FE mvpOutboundService.fetchLabelSnapshots() still loops all pages (mvpOutboundService.ts:468-486, 792) but only via alignedOutboundService re-export | |
| GET /label-print-events | packing label | paginate <=100 | NONE | 2 + 2 | FE fetchAllPages LABEL_EVENT_MAX_PAGES for packing-label report (packing-label-api.ts:215-235) | CurrentBaselineOperationsController.php:646-673 |
| GET /printer-configs | packing label | tablePage | NONE | 2 + 2 | no | |
| GET /putaway-tasks, /putaway/history | putaway | SQL paginate <=200 | NONE (correct) | tasks 2 + 5 (page, count, qty batch, drafts batch, receipts batch, trays); history 2 + 2 | no | PutawayController.php:67-137, 388-420; putawayService.ts fetches trays per_page 200/500 |
| GET /physical-codes, /item-instances | lifecycle | BaseRepository paginate <=100 | NONE | 2 + 2 | no; FE fetchActiveRfidCodes loops up to 10 pages (mvpOutboundService.ts:897) | |
| GET /scan/events, /scan/batches | lifecycle | paginate <=100 | NONE | 2 + 2 (UNION ALL subquery + count) | no | WmsContractController.php:308-380; scan_events 5,010 rows, seq_scan 42 |
| GET /document-status-history | lifecycle | paginate | search 120s WITH paging | 2 + 2 | no | |
| GET /reversal-documents (+/{id}/lines) | inventory | paginate | cached | 2 + 2 | FE per_page 200 on movement-trace (movement-trace-api.ts:225) | |
| GET /users | RBAC | paginate <=100 | NONE | 2 + 4 (count, page, roles, department) | FE requests per_page 200 (clamped to 100) on audit-logs page & report user directory | UserRepository.php |
| GET /roles, /permissions | RBAC | paginate | rememberStampede master via repo | 2 + 2 | /roles 74 KB with permissions eager | |
| GET /audit-logs | audit | paginate <=100 | NONE (by design) | 2 + 3 (count, page, actor) | no | indexes on created_at, actor_id |
| GET /notifications, /notifications/unread-count | notifications | paginate | stock_short 20s keyed by actor | 2 + 2 / 2 + 1 | no | FE bootstraps once then uses Reverb Echo; no polling |
| GET /pv/products, /pv/catalog | PV public | SQL forPage, max 50 | NONE (coverage "true" is the throttle key) | 1 (count) + 1 page query with 2 correlated subqueries per row on inventory_balances (PvCatalogReadModel.php:172-195) | no | throttle 120/min/IP; NoStoreApiResponses forces Cache-Control no-store on anonymous catalog too |
| GET /public/products, /public/home | public | forPage | NONE (same throttle false positive) | same correlated-subquery pattern (PublicCatalogReadModel.php:33) | no | throttle 180/min/IP |
| GET /api/v1/health | misc | none | none | 5 (per audit) | no | |

## 3. Phân loại theo thời gian đo được

Đo trên hồ sơ chịu tải (2 replica, mã trong container, PgBouncer), máy rảnh,
cache ấm, một người dùng:

| Nhóm | Endpoint | p50 đo được | Đánh giá |
|---|---|---|---|
| FAST | `/api/v1/health` | 30–45 ms | đạt |
| FAST | `/skus?page=1&per_page=20` | 75–124 ms | đạt |
| FAST | `/auth/me` | 60–63 ms | đạt |
| FAST | `/reports/dashboard-overview` (ấm) | 76–197 ms | đạt |
| ACCEPTABLE | `/inventory?page=1&per_page=20` | 170–207 ms | đạt, nhưng xem API-04 |
| WARNING | `/reports/movement-trace`, `/reports/inventory-ledger`, `/reports/document-history` | 86–221 ms ở dữ liệu dev | **payload 1,0–4,5 MB, không chặn trang** — API-01; sẽ vỡ ở khối lượng thật |
| FAIL (dưới tải) | mọi endpoint khi vượt ~130 req/s trên máy đo | p99 60 s (timeout nginx) | máy đo bão hoà CPU, xem LOAD_TEST_2026-09-07.md |

Bảng p50/p95/p99 theo NHÓM dưới tải thật nằm trong `LOAD_TEST_2026-09-07.md`.

## 4. Phát hiện

| ID | Mức | Thành phần | Vấn đề | Trạng thái 07/09 |
|---|---|---|---|---|
| API-01 | P0 | BE OperationalReportController + FE report scr | Five report endpoints return the whole filtered set with no page cap and the FE deliberately calls them without per_page | chưa xử lý |
| API-02 | P1 | Modules/Auth/app/Http/Middleware/CheckPermissi | Every authenticated request loads the user twice (auth:api guard + CheckPermission re-authenticate) and resolves RBAC on two different User instances | chưa xử lý |
| API-03 | P1 | FE report-data.ts / recipient-agency-api.ts /  | FE fan-out: one screen open issues 10-30+ list/aggregate requests (fetchAllPages, per-SKU balance lookups, per-page aggregate recomputation) | chưa xử lý |
| API-04 | P1 | Modules/Warehouse/app/Http/Controllers/Invento | /inventory and /inventory/balances cache key omits warehouse scope while the model applies ScopesWarehouseData - cross-scope cache sharing | **ĐÃ SỬA** (scope vào khoá cache) |
| API-05 | P1 | PvCatalogReadModel / PublicCatalogReadModel +  | Cache-coverage figure is inflated by throttle keys: anonymous PV/public catalog endpoints are NOT payload-cached and run 2 correlated subqueries per row | chưa xử lý |
| API-06 | P2 | StockMovementController::index, WmsContractCon | OFFSET pagination sorted by occurred_at/created_at on ledger-sized tables with no matching index | chưa xử lý |
| API-07 | P2 | OperationalReportController::dashboardOverview | Dashboard overview rebuild cost and optional 60 s polling of 4 endpoints | chưa xử lý |
| API-08 | P2 | app/Providers/AppServiceProvider.php registerR | No rate limiting or concurrency guard on authenticated read endpoints | cân nhắc — không rate-limit người dùng thật |


### API-01 — Five report endpoints return the whole filtered set with no page cap and the FE deliberately calls them without per_page

**Bằng chứng:** OperationalReportController.php:2367-2378 (key excludes page/per_page), :2434-2450 (SQL forPage ONLY if $request->filled('per_page')), :2542-2565 paginateReport returns $report unchanged when per_page absent; same for document-history :2080-2160 and outbound-by-agency :2239-2295 (->get() on whole period). FE: movement-trace-api.ts:24 ('KHONG gui per_page'), io-history-api.ts:197, inventory-flow-api.ts:307, report-data.ts useOutboundByAgency (no per_page). Measured (build/perf/bench-be-after-all.json): /reports/inventory-ledger and /reports/movement-trace 4,511,814 bytes, warm 188-209 ms; /reports/document-history 1,022,303 bytes; /reports/outbound-by-agency 543,706 bytes; /reports/inventory-reconciliation 365,771 bytes. DB: 6,190 stock_movements in last 30 days (dev); EXPLAIN of the movement-trace SELECT = Seq Scan on stock_movements with Filter occurred_at BETWEEN (no occurred_at index; only composite idx_sm_wh_product_time (warehouse_id, product_id, occurred_at)).

**Nguyên nhân:** API contract couples KPI/timeline/table to one payload so pagination is optional; payload size and SQL cost grow linearly with ledger size; cache entry per (filters, scope) holds the entire 4.5 MB array in Valkey and json_encode/decode of it happens on every hit (plainReportPayload).

**Ở 10k người dùng:** If 5% of 10k users open a report screen per minute: ~8 req/s x 4.5 MB = 36 MB/s egress plus ~200 ms PHP CPU per hit even when cached (json decode of 4.5 MB); a 12-month or high-volume warehouse (production ledgers of 10^6+ rows) turns this into multi-second seq scans and Valkey values of tens of MB (maxmemory 256 MB, volatile-lru will evict them constantly). This is the single largest per-request cost in the API surface.

**Sửa thế nào:** Split the contract: (a) server-side summary + by_movement_type + a bounded timeline (e.g. daily buckets) in one cached payload; (b) rows paged in SQL with a hard cap (MAX_PER_PAGE=200 already exists) and keyset pagination on (occurred_at, id); (c) add index stock_movements (occurred_at DESC, id DESC) or (warehouse_id, occurred_at) to make the period filter index-driven. Keep business rules intact: KPIs must still be computed over the FULL filtered set (do it in SQL aggregates, as the per_page branch at :2434 already does), scope must stay resolved before cache (keep reportWarehouseScope() ordering), export-excel keeps the 10,000-row cap. Do NOT simply cap rows silently on the un-paged branch - FE KPIs would silently diverge from the table.

**Kiểm chứng:** Before/after with scripts/perf/bench-be.mjs on /reports/movement-trace and /reports/document-history: bytes, warm/cold ms; EXPLAIN (not ANALYZE) showing Index Scan on occurred_at; k6 flows.js 'reports' group p95 at baseline profile; Valkey `INFO memory` used_memory during the report mix.


### API-02 — Every authenticated request loads the user twice (auth:api guard + CheckPermission re-authenticate) and resolves RBAC on two different User instances

**Bằng chứng:** CheckPermission.php:27-33: `JWTAuth::setToken($request->bearerToken())->authenticate()` executed on every permission-protected route even though auth:api already resolved the user. vendor JWTAuth.php:61-69 authenticate() -> $this->auth->byId($id); Providers/Auth/Illuminate.php:54-57 byId() -> onceUsingId($id) -> provider->retrieveById => a second `SELECT * FROM app_user WHERE id=?`. The controller later uses auth('api')->user() (a different instance) so isSuperAdminMemo/activeRolesMemo are not shared: hasPermission() in middleware does 2 Valkey GETs (is-super-admin, permissions), then readCacheScopeIds()/ScopesWarehouseData in the controller repeats is-super-admin + warehouse-scopes GETs. LOAD_TEST_2026-09-06.md section 5 already documents the CacheVersion GET miss floor.

**Nguyên nhân:** Defensive re-authentication written before the guard was trusted; no per-request user singleton.

**Ở 10k người dùng:** At 375 req/s (3k users, 8 s think) this is ~375 extra PK SELECTs/s and ~750-1,100 extra Redis round trips/s; at 10k users (~1,250 req/s) ~1,250 SQL + ~3,700 Redis ops/s of pure overhead, each adding ~0.2-0.5 ms latency and consuming a php-fpm worker slot (pm.max_children=48-64 is the proven ceiling).

**Sửa thế nào:** In CheckPermission use `$user = auth('api')->user()` first and only fall back to JWTAuth::authenticate() when the guard has no user (keep the try/catch so malformed tokens still yield 401); or bind the resolved User into the container for the request so ApiAuthContext/global scope/controllers share one instance and its memos. RBAC semantics (SUPER_ADMIN bypass, OR-list permissions, DenyAuditRecorder on 403, AuthCache version bump) must not change; do not memoize CacheVersion in a static (see LOAD_TEST_2026-09-06.md section 5 for why).

**Kiểm chứng:** Temporary DB::enableQueryLog probe (as done in PERF_AUDIT section 2) on GET /api/v1/products: expect app_user SELECT count 2 -> 1 and Valkey MONITOR GET count per request to drop by >=2; re-run k6 baseline profile and compare p50/p95 and postgres CPU.


### API-03 — FE fan-out: one screen open issues 10-30+ list/aggregate requests (fetchAllPages, per-SKU balance lookups, per-page aggregate recomputation)

**Bằng chứng:** report-data.ts:603-604 WARRANTY_PAGE_SIZE=100, WARRANTY_MAX_PAGES=10 used by useWarrantyDefects (:639), useWarrantyCases (:665), useComponentIssueDocuments (:693), useOutboundByRecipient (:810) ; recipient-agency-api.ts:594 COMPARISON_MAX_PAGES=30 for the comparison tab; packing-label-api.ts:221 fetchAllPages(LABEL_PRINT_EVENTS); mvpOutboundService.ts:815-830 fetchAvailableQtyBySku fires one GET /inventory/balances?product_id= per unique SKU (Promise.all, no limit) from AlignedOutboundDetailModal.tsx:231, OutboundIntakeModal.tsx:393, ComponentIssueModal.tsx:232 - avg 11.6 lines/doc, max 101 (psql). BE: BaM1ReportController.php:39 caches outbound-by-recipient WITH page in key and recomputes count + sum + distinct count + by_sku + by_recipient aggregates (5 aggregate queries) for EVERY page (:118-140); /warranty-cases and /warranty-component-issues are uncached (cache-coverage.json false; CanonicalMvpController.php:362, 331). paginate-all.ts caps concurrency at 6 so 10 pages = 1 + 2 waves.

**Nguyên nhân:** Report/list contracts return only what a table needs, so the FE reconstructs the full set client-side; per-page cache keys duplicate whole-set aggregates.

**Ở 10k người dùng:** A single warranty report open = up to 30 requests (3 endpoints x 10 pages) each costing 2 SQL + RBAC; an outbound detail modal on a 100-line document = 101 requests. With 10k users, 1% opening such a screen per minute adds 50-170 req/s of list traffic that no cache dedups (warranty lists uncached, 20 s TTL on balances). None of these authenticated endpoints has a rate limiter.

**Sửa thế nào:** BE: add batch/summary endpoints - `GET /inventory/balances?product_ids[]=` (single query, keep scope + 20 s cache) and a `summary=1` mode or a separate `/reports/outbound-by-recipient/summary` so by_sku/by_recipient/totals are computed once per (filters, scope) with page rows cached separately; give /warranty-cases and /warranty-component-issues the same cachedReadPayload(search/stock_short) treatment (scope + actor rules per CachesReadPayload). FE: replace fetchAllPages callers with the summary endpoints and keep the 6-concurrency cap. Keep PII masking (warranty.pii.view) and data-scope checks identical; do not raise per_page caps above 200.

**Kiểm chứng:** Browser network log on /reports/warranty and outbound detail modal before/after (request count, total bytes); k6 flow for the same sequence measuring server req/s per screen-open; cache-coverage.mjs must show new keys for warranty lists.


### API-04 — /inventory and /inventory/balances cache key omits warehouse scope while the model applies ScopesWarehouseData - cross-scope cache sharing

**Bằng chứng:** InventoryBalanceController.php:78-96 key = CacheKey::of('warehouse:inventory-balance:list', ['f'=>hash(filters),'per_page','page'], stock:version) with no scope/actor part; cache-coverage.json sample key `warehouse:inventory-balance:list:f-f512...:per_page-10:page-1:v1` (no scope segment, compare stock-movements `...:scope-global:v1`). InventoryBalance.php:8,12 `use ScopesWarehouseData` whose global scope adds whereIn(warehouse_id, $user->warehouseScopeIds()) (ScopesWarehouseData.php:19-36). EnforceInventoryWarehouseScope only validates an explicit warehouse_id param (middleware :46-52).

**Nguyên nhân:** Controller predates CachesReadPayload trait (which mandates scope in key, rule 1) and was never migrated.

**Ở 10k người dùng:** Functionally: a scoped user can be served a GLOBAL user's page (rows from warehouses outside their scope) or vice versa within the 20 s TTL - a data-scope violation, not just staleness. Performance-wise it also blocks the obvious fix for this hot endpoint (k6 weight 8+7, highest in the inventory group) which is raising the TTL/using stampede lock safely.

**Sửa thế nào:** Migrate index() to CachesReadPayload::cachedReadPayload('warehouse:inventory-balance:list', readFiltersWithPaging($request), builder, 'stock_short', 'stock:version') so scope signature is in the key (the trait already does this); keep validation and EnforceInventoryWarehouseScope 403 before the cache read. Do not remove the global scope or change the response shape.

**Kiểm chứng:** Integration test: user A (global) warms page 1, user B (scoped to one warehouse) requests same filters -> must not receive A's rows; cache-coverage.mjs sample key must contain scope-<sig>.


### API-05 — Cache-coverage figure is inflated by throttle keys: anonymous PV/public catalog endpoints are NOT payload-cached and run 2 correlated subqueries per row

**Bằng chứng:** cache-coverage.json for /pv/products, /pv/catalog, /public/products, /public/home: cached=true, keysAdded=2, sample `wms-hoanam-database-wms62d896aee3697c0289d2214eb361eb8c` = Laravel cache prefix + sha1 throttle key, not a payload key; grep for rememberStampede/remember in PvPublicCatalogController.php, PvCatalogReadModel.php, PublicApi/* returns nothing. So 13 of the '55 cached' endpoints (all under throttle:pv-public / throttle:public-api) are uncached: real coverage 42/84. PvCatalogReadModel.php:172-195 selects `(SELECT COALESCE(SUM(b.available_qty),0) FROM inventory_balances b WHERE b.product_id = s.sku_id AND stock_status='AVAILABLE')` and a MAX(updated_at) twin per row; EXPLAIN shows SubPlan 1/2 executed per output row via uk_inventory_balances_wh_product_status plus a third SubPlan on pv_product_profiles for the published filter; PublicCatalogReadModel.php:33 uses the same pattern. pg_stat_user_tables: pv_product_profiles idx_scan 20,690,439 and inventory_balances seq_scan 8,924,965 (attribution NOT MEASURED - pg_stat_statements not installed). NoStoreApiResponses.php forces `Cache-Control: no-store, private` on these anonymous responses too.

**Nguyên nhân:** Public read models were built for freshness (available_qty inline) without an app-cache layer; the coverage script counts any new Valkey key as 'cached'.

**Ở 10k người dùng:** These are the only endpoints reachable without login, so they see bot/crawler and storefront traffic on top of WMS users; per-IP throttling does not cap aggregate load. Each page costs 1 count + 1 query with 3 subplans x 20 rows; there is no CDN/browser caching either.

**Sửa thế nào:** Wrap paginateProducts/findPublishedProduct/home/facets in CacheService::rememberStampede with a 'search' or 'reference' policy keyed by normalized filters+page (no scope needed - anonymous), bump a `pv:version` namespace from publish/unpublish and from mutateBalance; consider LEFT JOIN LATERAL or a pre-aggregated available_qty column refreshed by mutateBalance. Relax NoStoreApiResponses for /api/v1/public/* and /api/v1/pv/* to `public, max-age=30-60` so a CDN/browser can absorb repeats (keep no-store for authenticated routes - WWHN-325). Fix cache-coverage.mjs to ignore keys matching the throttle pattern. Keep the 404-masking rule for unpublished SKUs and the per-IP throttles.

**Kiểm chứng:** cache-coverage.mjs after filtering throttle keys should list these endpoints with a real namespaced key; bench-be.mjs warm ms for /pv/products (currently 110 ms) should drop to the framework floor; k6 'public' group p95.


### API-06 — OFFSET pagination sorted by occurred_at/created_at on ledger-sized tables with no matching index

**Bằng chứng:** EXPLAIN `SELECT * FROM stock_movements ORDER BY occurred_at DESC LIMIT 15 OFFSET 0` -> Seq Scan + Sort (cost 555); OFFSET 1900 LIMIT 100 -> same plan cost 820; pg_indexes on stock_movements has no occurred_at-leading index (10 indexes incl. idx_sm_wh_product_time, idx_sm_ledger_dimension). Laravel paginate() issues COUNT(*) (index-only via stock_movements_stock_status_index) + page query. scan_events (5,010 rows, seq_scan 42) union in WmsContractController.php:343-380 sorted by created_at with no index; label_snapshots/warranty_cases tablePage order by created_at with only (status, created_at) / (status, received_at) indexes. Dev volume (7,350 rows) hides this; the audit's section 6 explicitly says production-scale plans are NOT VERIFIED.

**Nguyên nhân:** Sort column not covered by an index; OFFSET pagination re-sorts the whole filtered set per page.

**Ở 10k người dùng:** stock-movements list (k6 weight 6) is 20 s-cached so hit rate is high, but every miss and every distinct filter/page combination performs a full sort of the ledger; at 10^6 rows this is 100s of ms per miss and CPU on postgres (already 4.5 cores at saturation in LOAD_TEST_2026-09-06.md).

**Sửa thế nào:** Add btree indexes (occurred_at DESC, id DESC) on stock_movements and created_at on scan_events/scan_record_events/label_snapshots/warranty_cases; expose a keyset cursor (`after=<occurred_at,id>`) on /stock-movements while keeping the offset contract for existing clients. Do not change ScopesWarehouseData or the 20 s TTL semantics.

**Kiểm chứng:** EXPLAIN before/after showing Index Scan Backward; bench-be.mjs cold ms for /stock-movements?page=1..20; after the load DB is seeded to production-like row counts (HoaNamBulkDemoSeeder per memory notes), repeat.


### API-07 — Dashboard overview rebuild cost and optional 60 s polling of 4 endpoints

**Bằng chứng:** OperationalReportController.php:668-717 (rememberStampede dashboard 60 s +/-10%, key from+to+scope+stock:version), :904-1160 builder: ~23 distinct aggregate statements incl. a nested buildInventoryBalance(new Request(...)) (:1120-1125) that materialises the whole balances x ledger set; PERF_AUDIT section 4 measured 26 queries cold, bench warm 170 ms / cold 200 ms. FE overview-data.ts:178-191 refetchInterval 60_000 when autoRefresh is on, applied to 4 queries (dashboard-overview, xnt-enhanced, xnt-daily, dashboard-quick :225-261); default state NOT MEASURED (DashboardPage.tsx:198 only shows manualRefresh). Any write path bumps stock:version (mutateBalance) which orphans the entry, so under active scanning the effective TTL is the interval between posts, not 60 s.

**Nguyên nhân:** One key per (period, scope); every stock post invalidates it; each rebuild is the most expensive read in the system.

**Ở 10k người dùng:** With N warehouse-scope signatures x period presets, each post triggers up to N x presets rebuilds of ~26 aggregates within the next 60 s; with polling on, 10k users x 4 req/60 s = 667 req/s of cache hits at ~30-90 ms PHP each (local-code profile) - that alone exceeds the measured 250-285 req/s single-replica ceiling.

**Sửa thế nào:** Keep the stampede lock; decouple the dashboard from stock:version (use its own `dashboard:version` bumped by a debounced job, or accept TTL-only staleness of 60 s as the audit already accepts for warranty), split the nested inventory-balance summary into its own cached key (it is also served by /reports/inventory-balance), and make FE polling opt-in / back off when the tab is hidden. Keep the 366-day guard and scope-before-cache ordering.

**Kiểm chứng:** Count cache.rebuild log lines per minute for the dashboard key during a scan-heavy k6 flow before/after; bench cold ms of /reports/dashboard-overview; FE: confirm autoRefresh default and measure idle req/s per open dashboard tab.


### API-08 — No rate limiting or concurrency guard on authenticated read endpoints

**Bằng chứng:** AppServiceProvider.php:107-180 defines only pv-public (120/min/IP), public-api (180/min/IP), public-quote-request (5/min), auth-refresh (30/min/IP); route:list shows `throttle:` only on /auth/refresh, /pv/*, /public/* - none on /reports/*, /inventory*, /warranty-cases etc. LOAD_TEST_2026-09-07.md shows the per-IP limiter already fires under k6 (429s) because it keys on IP, i.e. it is a bot guard, not a capacity guard.

**Nguyên nhân:** Rate limiting was added for SRS 8.1 anti-abuse on anonymous routes only.

**Ở 10k người dùng:** A single misbehaving client (or the FE fan-out in API-03, or a report tab left with auto-refresh) can monopolise the 48-64 php-fpm workers per replica; the load test already demonstrated that saturation manifests as queueing/timeouts for everyone rather than 429 for the offender.

**Sửa thế nào:** Add a per-user limiter (Limit::perMinute(N)->by($user->user_id)) on the report and list groups sized above legitimate FE usage (after API-03 reduces fan-out), plus a lower per-user limit on the export-heavy /reports/*/export-excel; keep existing pv/public limiters and RateLimitResponse envelope. Do not throttle scan/post paths - those are the business-critical writes.

**Kiểm chứng:** k6 probe-concurrency.js with one VU hammering /reports/movement-trace should receive 429 after N/min while other VUs keep baseline p95; confirm 429 envelope matches Annex C.


## 5. Chưa đo được

- Per-endpoint SQL statement counts were derived by reading code, not from a live query log (the audit's DB::enableQueryLog probe was deleted); counts marked (est).
- Query plans at production data volume: dev DB has 7,350 stock_movements / 818 SKUs / 293 inventory_balances; EXPLAIN (no ANALYZE) run only on dev volume, so absolute costs of the seq scans in API-01/API-06 at 10^6 rows are extrapolated.
- Attribution of pg_stat_user_tables counters (inventory_balances seq_scan 8,924,965; pv_product_profiles idx_scan 20,690,439; app_users seq_scan 263,923) to specific endpoints: pg_stat_statements is not installed (pg_extension lists only plpgsql), and counters are cumulative since last stats reset including seeders/tests.
- Actual Valkey round trips per request (API-02) were inferred from code paths (AuthCache, CacheVersion, CachesReadPayload); not captured with MONITOR because a live k6 run shares the machine.
- Dashboard autoRefresh default on/off in DashboardPage.tsx and the real idle request rate per open dashboard tab.
- Payload fields the FE never uses: not audited field-by-field; only whole-payload sizes from build/perf/bench-be-after-all.json are cited.
- Effective cache hit ratio per endpoint under realistic scope/period diversity (the load test used a single admin user, i.e. one scope signature).
- mini-app/* endpoints (23 GET) were inventoried but not analysed in depth since they serve a different client; several share controllers with web routes so findings API-02/API-04 apply to them as well.
- Row counts/page sizes actually requested by users in production (per_page distribution, how many pages deep list screens go).
