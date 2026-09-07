# CACHE_PERFORMANCE_REPORT — Hoà Nam WMS (07/09/2026)

> **Mức tin cậy.** Các phát hiện dưới đây do một luồng audit chỉ-đọc lập, có bằng
> chứng file:dòng / câu SQL / EXPLAIN. Vòng phản biện độc lập chỉ chạy được 1/42
> mục trước khi phiên chạm hạn mức, nên **trừ những mục ghi rõ "ĐÃ SỬA" (đã có số
> đo trước/sau), phần còn lại là CHƯA ĐƯỢC XÁC NHẬN ĐỘC LẬP** — hãy đọc như đầu
> mối điều tra, không phải kết luận.

## 1. Tổng quan

Cache layer is well-structured (single gateway CacheService, scope+actor in keys, plain-array payloads, afterCommit bumps, 10% TTL jitter everywhere, lock in the same Valkey DB) but its invalidation topology and connection handling will not survive 10k concurrent users. Measured on the live Valkey (uptime 5,902 s): keyspace hit ratio 35.8% (245,468 hits / 440,119 misses); cmdstat_select 164,391 == total_connections_received 164,387, i.e. one fresh TCP connection + AUTH + SELECT per PHP request (REDIS_PERSISTENT unset -> false); ~3.9 GET per connection; 24.9 GB output bytes / 245k hits ~= 101 KB per hit (upper bound). Root problems, in order: (1) ~42 read endpoints (12 report + 2 dashboard + inventory-balance list/report + 26 cachedReadPayload sites) share ONE version key `stock:version` that is bumped by 36 write paths including CurrentBaselineStockService::mutateBalance() per balance mutation - under continuous warehouse scanning the whole stock/report/dashboard/search cache is orphaned sub-second, so hit rate tends to zero and every read pays lock + full rebuild (dashboard-overview = 26 queries; movement-trace = 4.3 MB); (2) non-persistent phpredis connections; (3) JWT blacklist (tymon, JWT_TTL 43200 min -> observed TTL 2,581,751 s) lives in the same volatile-lru DB as 100 KB-4 MB report payloads, so memory pressure can evict revoked tokens, and its tag ZSET is never pruned; (4) cache-per-keystroke: CatalogMasterScreen sends `keyword` with no debounce into a 30-minute 'master' cache, SKU list keys on `$request->all()` with 15-minute TTL, all 'search' endpoints key on the full query string; (5) rememberStampede is used for per-user keys (notifications) whose FE staleTime (60 s) exceeds the BE TTL (20 s), making them write-only plus 4 extra Redis RTT per miss; (6) lock wait 5 s / TTL 10 s with 250 ms poll and no stale-while-revalidate turns into a dogpile amplifier exactly when rebuilds exceed 5 s (305 requests >3 s were seen in slowlog under saturation); (7) fail-open covers only CacheService - JWT blacklist check and throttle middleware still throw when Valkey is down, and phpredis retries (max_retries 3, backoff 100-1000 ms) are paid 3x per request before the DB fallback with no request collapsing. Valkey capacity itself is not the constraint: maxclients 10,000 vs at most 2x48 (perf) / 2x64 (dev) php-fpm children; used_memory 7.6 MB, evicted_keys 0, io-threads 1.

## 2. Kiểm kê cache

## Cache layer facts (evidence: file:line)

| Item | Value | Evidence |
|---|---|---|
| Store / DB | redis store, connection `cache` = Valkey DB 1, lock_connection `cache` (same DB) | config/cache.php:81-91, config/database.php:311-322 |
| Client | phpredis 6.3.0, `persistent` = env REDIS_PERSISTENT (unset -> false), max_retries 3, decorrelated_jitter backoff 100/1000 ms, connect timeout 0.0 (unbounded) | config/database.php:290-322, bootstrap/cache/config.php:493, vendor PhpRedisConnector.php:167-185 |
| Serialization | PHP `serialize()`, uncompressed; phpredis built WITHOUT igbinary/lz4/zstd (no Redis::SERIALIZER_IGBINARY / COMPRESSION_* constants); `serializable_classes=false` | vendor RedisStore.php:485-535, config/cache.php:140, `php -m` in container |
| TTL policies (s) | rbac 300, master 1800, reference 3600, sku 900, search 120, dashboard 60, report 300, stock_short 20; jitter +/-10% applied by CacheTtl::seconds at every call site | config/wms_cache.php:11-23, CacheTtl.php:350-376 |
| Lock | wait 5 s, ttl 10 s, poll 250 ms; on LockTimeoutException -> rebuild without cap | config/wms_cache.php:26-29, CacheService.php:89-117, vendor Lock.php:43,114-127 |
| Fail-open | CacheService only (read/lock/put wrapped); CacheVersion::current returns 1 on error | CacheService.php:148-229, CacheVersion.php:395-404 |
| Stale-while-revalidate | none (Cache::flexible exists in framework 13.23 but unused) | vendor Repository.php:642 |
| Version keys | `wms:{env}:cache-version:{ns}` written with add()+increment() (no TTL, survive volatile-lru); GET on every cached read; absent until first write | CacheVersion.php:395-427, LOAD_TEST_2026-09-06 §5 |
| Valkey runtime | valkey 8.1.9, maxmemory 256 MB, volatile-lru, maxclients 10000, io-threads 1, timeout 0, tcp-keepalive 300; used 7.63 MB (peak 23.58 MB), frag 1.92, evicted_keys 0, expired_keys 1,379 | `valkey-cli INFO`, `CONFIG GET` (read-only, 2026-09-07) |
| Measured ratios | hits 245,468 / misses 440,119 = 35.8%; cmds 978,915 / connections 164,387 = 5.96 cmd/conn; GET 642,962 (3.91/conn); SELECT 164,391 (= connections); SETEX 14,708; INCRBY 3,987; SET(NX lock) 1,569; EVAL 44,711 (lock release + queue Lua); net_output 24,912,853,517 B / hits = ~101 KB per hit (upper bound) | `INFO stats`, `INFO commandstats` |
| php-fpm children vs maxclients | dev www.conf 64/replica (2 replicas running = 128); perf overlay 48 (presets 120/250); all << 10,000 | docker/php/www.conf:72, docker-compose.perf.yml:98, .env.perf.example:84,110 |
| Redis RTT per authenticated cached read (non-superadmin, HIT) | connect+AUTH+SELECT (3) + GET jwt-blacklist + GET is-super-admin + GET permissions + GET warehouse-scopes + GET version + GET payload = 9 RTT; MISS adds SET NX + GET recheck + SETEX + EVAL = 13 RTT; 0.1-0.2 ms/cmd measured in-container | User.php:98-171, AuthCache.php:29-55, CheckPermission.php:35-44, tymon Blacklist.php:128-138, CachesReadPayload.php:560-610, LOAD_TEST_2026-09-07 §1 |

## Cache inventory (group | key pattern | TTL | jitter | stampede | invalidation | payload | per-user/shared | evidence)

| Group | Key pattern (after `wms-hoanam-database-wms` + `wms:{env}:`) | TTL policy | Jitter | Stampede lock | Invalidation | Payload size class | Scope | Evidence |
|---|---|---|---|---|---|---|---|---|
| RBAC | `auth:user:{uid}:v:{user.version}:is-super-admin` / `:permissions` / `:super-admin-permissions` / `:warehouse-scopes` | rbac 300 s | yes | no (remember) | key rotation via `app_user.version` increment (afterCommit) | tiny (168 B observed) | per-user | AuthCache.php:29-94, User.php:98-171 |
| RBAC | `auth.role:list:f-{h}:per_page-N:page-N:v{V}` / `auth.permission:list:...` | rbac 300 / reference 3600 | yes | yes | namespace bump `{domain}:list` on create/update/delete (afterCommit) | small-medium | shared (no scope; repo refuses cache when `$scope` closure given) | BaseRepository.php:82-116,380-388, RoleRepository.php:22-26, PermissionRepository.php:17-21 |
| RBAC | `auth.role:detail:id-{id}` / `auth.permission:detail:id-{id}` | rbac / reference | yes | no | targeted forget (afterCommit); null (404) never cached | small | shared | BaseRepository.php:149-169,391-404, CacheService.php:25-33 |
| Auth (JWT) | `{sha1('tag:tymon.jwt:entries')}:{jti}` = `ca7581d6...:{jti}` + ZSET `tag:tymon.jwt:entries` (no TTL) | JWT_TTL 43200 min (observed TTL 2,581,751 s) | no | n/a | TTL only; tag ZSET never pruned (`cache:prune-stale-tags` not scheduled) | tiny (160 B) per logout | per-token; read on EVERY authenticated request (1 GET) | config/jwt.php:224,301; .env JWT_TTL=43200; vendor RedisTagSet.php:133-148; SCAN sample |
| master | `warehouse.warehouse:list|detail`, `warehouse.agency:list|detail`, `warehouse.product_master:list|detail` | master 1800 | yes | list yes / detail no | `{domain}:list` bump + targeted detail forget | small-medium | shared | WarehouseRepository.php:20-24, AgencyRepository.php:15-19, ProductMasterRepository.php:15-19 |
| master | `warehouse:catalog-master:{table}:list:f-{hash(status,keyword)}:per_page-N:page-N:v{V}` (brands, product_categories, power_sources, product_models, package_variants) | master 1800 | yes | yes | `warehouse.catalog_master.{table}:list` bump (afterCommit) | 6-15 KB observed (brands 6,304 B, product_categories 14,496 B) | shared, **keyword in key -> one key per typed prefix for 30 min** | CurrentBaselineMasterController.php:698-745,754-768 |
| master | `auth:department:list`, `warehouse:recipient:list|detail`, PvAdminResource list | master 1800 | yes | yes | `master:version` bump | small-medium (recipient list 8,320 B) | shared + scope sig | DepartmentController.php:27,64; BaM1RecipientController.php:30,72,197; PvAdminResourceController.php:45,93 |
| master | `warehouse:sku-import-report:list` / `:detail` (actor) | search 120 | yes | yes | `master:version` | medium | list shared / detail per-user | ImportReportController.php:34,137,215 |
| reference | `warehouse.defect_catalog:list|detail` | reference 3600 | yes | list yes | `{domain}:list` bump | small (10,400 B for per_page 100) | shared | DefectCatalogRepository.php:15-19 |
| SKU | `warehouse.sku:list:f-{hash($request->all())}:per_page-N:page-N:v{V}` | sku 900 | yes | yes | `warehouse.sku:list` bump (repo) + `stock:version`/`master:version` (ProductController:46-47) | 24,704 B observed for 20 rows | shared, **keyword + every query param in key** | ProductController.php:129, BaseRepository.php:86-91, ProductRepository.php:15-19 |
| SKU | `warehouse.sku:lookup:f-{status}:scope-global:v{V}` (whole catalogue, 9 cols) | master 1800 | yes | yes | `master:version` | medium (818 SKU x 9 cols) | shared global; hot key candidate | ProductController.php:85-107 |
| search | outbound-document list/detail/aligned-list, outbound-request list/detail, reversal-document list/detail/lines, stock-request list/detail, component-issue detail/read, document-status-history list, document-status-transition list, warranty-attachment list | search 120 | yes | yes | `stock:version` bump (warranty-attachment: `warranty:version`) | small-medium per page | shared + scope sig; full query string in key (readFiltersWithPaging) | CachesReadPayload.php:665-668 + call sites listed in callsites grep |
| stock | `warehouse:inventory-balance:list:f-{filters incl keyword}:per_page:page:v{V}` | stock_short 20 | yes | yes | `stock:version` | medium | shared (scope enforced by middleware, not in key) | InventoryBalanceController.php:79-99 |
| stock | `warehouse:stock-movement:list|detail|trace-source|reversible`, `:trace-by-code` (actor) | stock_short 20 | yes | yes | `stock:version` | small-medium | shared / per-user | StockMovementController.php:70,149,187,464,758 |
| stock (mini-app) | `miniapp:inbound:list|detail|scan-entries`, `miniapp:outbound:list|detail|scan-entries|packing-label`, `miniapp:product:list|detail`, `miniapp:warranty:resolve-code` | stock_short 20 | yes | yes | `stock:version` | small | shared + scope sig | MiniApp*Controller.php (CACHE_VERSION_NS='stock:version') |
| notification | `notification:list:f-{page,per_page,unread,severity}:scope-...:actor-{uid}:v{V}`, `notification:unread-count:...:actor-{uid}` | stock_short 20 | yes | **yes (pointless for per-user)** | `notification:version` bump on mark-read | tiny | per-user; FE fetches unread-count once per session then relies on Echo (staleTime 60 s > TTL 20 s) | NotificationController.php:25,53-72,107-119; FE useNotifications.ts:12-30; query-client.ts:49 |
| dashboard | `warehouse:report:dashboard-quick:from-..:to-..:scope-{global|hash}:v{V}`, `...:dashboard-overview:...` | dashboard 60 | yes | yes | `stock:version` | medium (26 aggregate queries to rebuild overview) | shared per (period, scope); **hottest shared keys** | OperationalReportController.php ~646-700 |
| report | `warehouse:report:{inventory-balance, inventory-reconciliation, xnt, xnt-daily, document-history, movement-trace, outbound-by-agency, outbound-by-recipient, import-match, label-log, warranty-defects, warranty-component-usage, warranty-defect-sku-breakdown}:f-{h}:scope-{sig}:v{V}` | report 300 | yes | yes | `stock:version` | **large**: in-memory-paged reports drop page/per_page so one entry holds the whole result (movement-trace 5,956 rows = 4.3 MB JSON per PERF_AUDIT §6) | shared per (filters, scope) | CachesReportPayload.php:71-101,149-152; OperationalReportController.php:589,1615,1807,2086,2245,2373 |
| rate-limit | Laravel `throttle:*` counters (pv-public 120/min/IP, public-api 180, quote 5, auth-refresh 30) | 60 s | no | n/a | TTL | tiny | per-IP | AppServiceProvider.php:109-170, routes |
| scheduler | `framework/schedule-{sha}{minute}` mutexes | ~1 h | no | n/a | TTL | tiny | n/a | SCAN sample |
| NOT cached (by design or gap) | `/pv/products?keyword=`, `/pv/products/{id}` (404 too), `/pv/industries` (PvCatalogReadModel: 0 cache refs); `/warehouses`, `/users`, `/printer-configs`, `/reports/hub`; live queues (`/scan/*`, `/putaway/*`, `/warranty-cases`, ...); `/audit-logs` | - | - | - | - | - | public PV search protected only by throttle | PvCatalogReadModel.php; PERF_AUDIT §4b |

### Version namespaces and their blast radius
| Namespace | Bumped by | Read endpoints keyed on it |
|---|---|---|
| `stock:version` | **36 sites** (mutateBalance per balance write, inbound/outbound/reversal/putaway/container flows, imports, SKU/warehouse/defect master writes, wms:cache:clear) | **~42**: 12 report + 2 dashboard + inventory-balance list + inventory-balance report + 26 cachedReadPayload sites |
| `master:version` | department, recipient, pv-admin, sku-import-report writes, ProductController:47 | department list, recipient list/detail, pv-admin list, sku lookup, sku-import-report |
| `notification:version` | mark-read (2 sites) | notification list, unread-count |
| `warranty:version` | warranty-attachment writes (3) | warranty-attachment list |
| `{repo}:list` (auth.role, auth.permission, warehouse.{warehouse,agency,product_master,sku,defect_catalog}, warehouse.catalog_master.{table}) | BaseRepository create/update/delete, CurrentBaselineMasterController | repo paginate() lists, catalog-master lists |

### Hot-key candidates (10k users)
1. `cache-version:stock:version` - one GET from every cached read (~42 endpoints) + INCR from 36 write paths.
2. `warehouse:report:dashboard-overview|dashboard-quick:{today window}:scope-global` - every dashboard open, 26-query rebuild, orphaned on every stock bump.
3. `warehouse.sku:lookup:...:scope-global` - every report screen open (whole catalogue).
4. `auth:user:{uid}:v:*:permissions|is-super-admin|warehouse-scopes` - 3 GETs per request per user (per-user, so spread).
5. `tag:tymon.jwt:entries` ZSET (unbounded growth) and `ca7581d6...:{jti}` reads on every request.

## 3. Số đo Valkey trong một lượt chịu tải (150 req/s đích, 2 replica)

```
keyspace_hits 245.470 / keyspace_misses 441.142      (tỉ lệ hit thô 35,7 %)
cmdstat_get     643.022 lệnh, 20,2 µs/lệnh
cmdstat_eval     44.867 lệnh, 26,7 µs/lệnh           (lock stampede + rate limit)
cmdstat_setex    14.708 lệnh
evicted_keys 0, expired_keys 1.386, connected_clients 6–82, blocked_clients 0
```

**Tỉ lệ hit thô 35,7 % KHÔNG phải tỉ lệ hit thật.** Mỗi lần đọc có cache tốn hai
lệnh: một GET khoá phiên bản (`CacheVersion`) và một GET payload; khoá phiên bản
chỉ tồn tại sau lần ghi đầu tiên, mà bộ đo tải toàn request đọc — nên có một sàn
~50 % miss do chính cơ chế. Xem CACHE-08.

## 4. Thay đổi đã thực hiện

- **Kết nối Valkey giữ lại giữa các request** (`REDIS_PERSISTENT=true`) thay vì
  TCP connect + AUTH + SELECT mỗi request (CACHE-02).
- **Trần thời gian chờ Valkey 2 s** (`REDIS_TIMEOUT`, `REDIS_READ_TIMEOUT`): Valkey
  treo không còn ghim worker php-fpm và lớp fail-open xuống DB mới thật sự chạy
  được (CAPACITY-08). Mặc định trong `config/database.php` vẫn là 0 nên stack dev
  không đổi hành vi; hàng đợi dùng `block_for = null` nên read timeout không cắt
  nhầm BLPOP.
- **Khoá cache của `/inventory` nay mang chữ ký phạm vi kho** (API-04) — đây là lỗi
  rò dữ liệu giữa các phạm vi, không chỉ là hiệu năng.
- **Đo được thời gian chờ lock stampede**: `SlowRequestLog` ghi `cache_lock_wait_ms`
  cho mỗi request chậm, nên lần sau phân biệt được "chờ lock" với "chờ SQL".

## 5. Phát hiện

| ID | Mức | Thành phần | Vấn đề | Trạng thái 07/09 |
|---|---|---|---|---|
| CACHE-01 | P0 | app/Support/Cache/Concerns/CachesReadPayload.p | One coarse `stock:version` namespace orphans ~42 read endpoints on every stock write (self-inflicted stampede) | chưa xử lý |
| CACHE-02 | P1 | config/database.php (redis.options.persistent) | Non-persistent Redis connections: one TCP connect + AUTH + SELECT per PHP request | **ĐÃ SỬA** (pconnect + timeout) |
| CACHE-03 | P1 | config/jwt.php, config/cache.php, docker-compo | JWT blacklist and rate-limit counters share the volatile-lru cache DB with multi-MB report payloads; tag ZSET never pruned | chưa xử lý |
| CACHE-04 | P1 | FE src/modules/master-data/pages/CatalogMaster | Cache-per-keystroke: undebounced `keyword` fills 30-min/15-min caches with one key per typed prefix | chưa xử lý |
| CACHE-05 | P2 | app/Support/Cache/Concerns/CachesReadPayload.p | Stampede lock and TTL applied to per-user keys that the FE never re-reads (write-only cache, +4 Redis RTT per miss) | chưa xử lý |
| CACHE-06 | P2 | app/Support/Cache/CacheService.php::rememberSt | Lock wait 5 s / TTL 10 s / 250 ms poll with no stale-while-revalidate: protection flips to dogpile when rebuilds are slow | chưa xử lý |
| CACHE-07 | P2 | app/Support/Cache/CacheService.php, config/dat | Fail-open is partial and expensive: JWT blacklist + throttle still hard-fail, and each request pays 3 retried connection attempts before the DB fallback | chưa xử lý |
| CACHE-08 | P2 | app/Support/Cache/CacheVersion.php, CachesRead | Version-key double GET plus RBAC + JWT lookups = 6 GETs per request; version key is a guaranteed miss until the first write | chưa xử lý |
| CACHE-09 | P2 | app/Support/Cache/CacheService.php::isCorrupte | Uncompressed PHP-serialized MB payloads: double encode on miss, unserialize + full recursive corruption walk + json_encode on every hit | chưa xử lý |
| CACHE-10 | P2 | app/Support/Cache/CacheService.php (MISS senti | No negative caching: missing-id lookups and public PV search/404 always hit PostgreSQL | chưa xử lý |
| CACHE-11 | P2 | app/Support/Cache/CacheService.php | One log line per cache read (`cache.miss` at info, `cache.hit` at debug) becomes I/O at production log levels | chưa xử lý |


### CACHE-01 — One coarse `stock:version` namespace orphans ~42 read endpoints on every stock write (self-inflicted stampede)

**Bằng chứng:** CachesReadPayload.php:596 default `$versionNamespace = 'stock:version'`; CachesReportPayload.php:76-79 all 12 report namespaces keyed on `currentVersion('stock:version')`; OperationalReportController dashboard-quick/overview keys use `currentVersion('stock:version')`; InventoryBalanceController.php:82; grep count of `bumpReadCache('stock:version')|bumpVersion('stock:version')` outside tests = 36 sites, incl. CurrentBaselineStockService.php:1640 (`DB::afterCommit(bumpVersion('stock:version'))` inside mutateBalance(), i.e. per balance mutation), PutawayController.php:293, CurrentBaselineContainerFlowController.php:179/289/410/489, MiniAppInboundStagingController.php:262, ImportController.php:195, WarehouseController.php:41, DefectCatalogController.php:38; ClearWmsCache.php:47-51 documents that reports share it. Live Valkey: keyspace hit ratio 35.8% on a READ-ONLY k6 mix (LOAD_TEST_2026-09-06 §5), cmdstat_incrby=3,987 vs cmdstat_setex=14,708 in a 98-min window (INCR includes rate-limiter counters, so this is indicative, not a bump-rate measurement).

**Nguyên nhân:** Invalidation granularity is one global counter for stock, documents, SKU master, mini-app, dashboards and all reports. Correct-by-construction (never stale) but every INCR makes every existing key in the namespace unreachable, so cache lifetime = time between any two stock writes anywhere in the system, not the configured TTL.

**Ở 10k người dùng:** With 10k users a fraction of whom are scanning/posting continuously, `stock:version` increments many times per second; the effective TTL of dashboard (26 queries), all 12 reports (movement-trace 4.3 MB rebuild) and all list endpoints drops to sub-second. Hit rate for ~42 endpoints tends to 0; every read becomes lock SET + full SQL rebuild + SETEX, and concurrent readers of the same key block 250 ms per poll (CACHE-07). The cache stops offloading the DB exactly when load is highest; PHP CPU (documented bottleneck) rebuilds and re-serializes MB payloads per request.

**Sửa thế nào:** 1) Split the namespace by aggregate and bump only what the write touches: `stock:balance`, `stock:movement`, `document:inbound`, `document:outbound`, `document:reversal`, `master:sku`, `master:warehouse`, `report:*`; a write path lists the namespaces it dirties. 2) For dashboards/reports accept TTL-bounded staleness instead of version-keying: key on (period, scope, floor(now/60s)) with 'dashboard'/'report' TTL and NO version, or keep version but bump it from a scheduler every N seconds (coalesced) rather than per write. 3) Coalesce bumps: collect dirty namespaces in a request-scoped set and INCR each once in a single afterCommit (mutateBalance currently bumps once per line). 4) Keep: afterCommit ordering (never bump before commit), scope/actor in keys, plain-array payloads, RBAC per-user keys. Do NOT reintroduce KEYS/FLUSH. Do NOT relax the 403-before-cache scope resolution.

**Kiểm chứng:** Add a mixed read/write k6 flow (load/k6/lib/flows.js is read-only today: include post-receipt/scan/putaway at 5-10% of iterations). Before/after: Valkey keyspace_hits/(hits+misses) per run, count of `cache.version.bump` and `cache.miss` log lines per minute for `warehouse:report:*` and `dashboard-*`, p95 of /reports/dashboard-overview and /reports/movement-trace during the mixed run, and pg_stat_statements calls/min for the dashboard aggregate queries. Target: report/dashboard hit ratio > 80% under mixed load.


### CACHE-02 — Non-persistent Redis connections: one TCP connect + AUTH + SELECT per PHP request

**Bằng chứng:** config/database.php:295 `'persistent' => env('REDIS_PERSISTENT', false)`; no REDIS_PERSISTENT in .env, .env.example, .env.perf.example, docker-compose*.yml or perf-entrypoint.sh (grep); bootstrap/cache/config.php:493 `'persistent' => false`; vendor PhpRedisConnector.php:167-185 uses `connect()` unless persistent. Live Valkey INFO: total_connections_received 164,387 vs cmdstat_select 164,391 (one SELECT per connection), total_commands_processed 978,915 -> 5.96 commands per connection, cmdstat_get 642,962 -> 3.91 GET per connection; connected_clients only 6 at rest (connections are short-lived). In-container Redis command latency measured 0.1-0.2 ms (LOAD_TEST_2026-09-07 §1).

**Nguyên nhân:** Laravel defaults to non-persistent phpredis sockets; the perf profile switched to phpredis (PERF_AUDIT §3.2) but never enabled pconnect, so every php-fpm request pays TCP handshake + AUTH + SELECT before its first GET.

**Ở 10k người dùng:** At ~500 req/s (10k users, 20 s think time) this is ~500 connect/AUTH/SELECT sequences per second on Valkey and 3 extra round-trips (~0.3-0.6 ms) on every request, plus TIME_WAIT churn on the backend hosts. Not a Valkey capacity problem (maxclients 10,000 vs <=128 workers) but pure waste on the PHP-CPU-bound tier; also adds to the per-request Redis RTT count of 9 (hit) / 13 (miss).

**Sửa thế nào:** Set `REDIS_PERSISTENT=true` in docker-compose.perf.yml for backend/queue/reverb (php-fpm keeps one socket per child; with pm.max_requests 1000 the socket is reused across requests). Keep DB 0/1 split - phpredis keys persistent sockets by host:port:db so session/queue and cache do not share a socket. Keep max_retries/backoff. Do not enable persistent for CLI one-offs (irrelevant).

**Kiểm chứng:** Before/after on the perf stack under the same k6 baseline: INFO total_connections_received delta per 1,000 requests (expect ~0 instead of ~1,000), connected_clients ~= active php-fpm children, cmdstat_select delta, and /api/v1/products warm p50 (bench-be.mjs).


### CACHE-03 — JWT blacklist and rate-limit counters share the volatile-lru cache DB with multi-MB report payloads; tag ZSET never pruned

**Bằng chứng:** config/jwt.php:224 `blacklist_enabled` default true, :301 storage = Tymon Illuminate (Cache::tags('tymon.jwt')); .env JWT_TTL=43200 (30 days, memory note says temporary); SCAN sample in DB 1: key `wms-hoanam-database-wmsca7581d6d4749d8fd143ea956aa654aa02abe0ec:y1ooxIeOVSaXBCbK` ttl=2,581,751 s (sha1('tag:tymon.jwt:entries') = ca7581d6... verified) and `tag:tymon.jwt:entries` ttl=-1; valkey `maxmemory 268435456`, `maxmemory-policy volatile-lru` (docker-compose.yml:213-218, perf.yml:265-270); `cache:prune-stale-tags` not scheduled (grep routes/app/bootstrap = 0); report payloads up to 4.3 MB (PERF_AUDIT §6) and ~101 KB average per hit (INFO net_output/hits); evicted_keys currently 0.

**Nguyên nhân:** Security state (token revocation) is stored as an evictable cache entry in the same keyspace and memory budget as bulk read-through cache. volatile-lru evicts the least-recently-READ volatile keys first; blacklist entries are only read when the revoked token is presented, so under memory pressure they are among the first to go.

**Ở 10k người dùng:** 10k users x report caches (100 KB-4 MB x filter combinations x scopes) will reach 256 MB (dev/perf default) quickly; once eviction starts, logged-out or admin-revoked tokens silently become valid again until their 30-day exp. Separately, `tag:tymon.jwt:entries` grows by one member per logout forever (no TTL, not evictable, never pruned).

**Sửa thế nào:** Move the JWT blacklist off the LRU cache: dedicated redis connection/store (separate Valkey instance or the `database` cache store) with `noeviction`, or disable the blacklist and cut JWT_TTL to the intended 60 min + refresh (memory note already flags 43200 as temporary). Schedule `cache:prune-stale-tags` hourly. Size `VALKEY_MAXMEMORY` from measured payload volume (CACHE-06) rather than 256 MB. Keep volatile-lru for the bulk cache DB so queue keys are never evicted.

**Kiểm chứng:** Fill DB 1 past maxmemory in staging (or set VALKEY_MAXMEMORY low), then: `INFO stats` evicted_keys > 0, present a logged-out token and observe 200 instead of 401; `ZCARD tag:tymon.jwt:entries` growth over a day of logins/logouts before/after scheduling prune.


### CACHE-04 — Cache-per-keystroke: undebounced `keyword` fills 30-min/15-min caches with one key per typed prefix

**Bằng chứng:** FE CatalogMasterScreen.tsx:196 `useState("")` keyword -> :211 `keyword: keyword.trim()` -> :217-218 `useQuery({ queryKey: ['catalog-master', activeKind, listParams] })` with no debounce (file absent from `grep -i debounce`); other keyword+useQuery files without any debounce token: TrayOverviewPage.tsx, OutboundIntakeModal.tsx, ComponentIssueModal.tsx, inventory-flow-api.ts, report-data.ts (not individually verified). BE: CurrentBaselineMasterController.php:698-706 key includes `hashFilters($request->only(['status','keyword']))`, TTL `master` 1800 s (:34); ProductController.php:129 `paginate($request->all(), ...)` -> BaseRepository.php:87-91 hashes every query param, TTL `sku` 900 s; all 'search' endpoints key on `$request->query()` verbatim (CachesReadPayload.php:665-668). Observed sizes: catalog product_categories key 14,496 B, brands 6,304 B, sku list page 24,704 B. Valkey maxmemory 256 MB.

**Nguyên nhân:** Server keys are built from the raw keyword and every query param, while the client sends a request per keystroke on at least one screen; long TTLs meant for reference data are applied to search results.

**Ở 10k người dùng:** Each user typing a 6-char search creates ~6 keys x 6-25 KB living 15-30 min; 1,000 such searches/hour ~ 36-150 MB/hour of dead keys competing with real hot keys for 256 MB under LRU (and each miss = lock + ILIKE query + SETEX). Sparse keys also drag the measured hit ratio down and inflate the `isCorrupted` walk cost per hit.

**Sửa thế nào:** FE: route CatalogMasterScreen (and the unverified list) through useTablePattern's `searchDebounceMs` / use-autocomplete's 300 ms debounce with minSearchLength >= 2 (pattern already exists in src/shared/hooks). BE: when `keyword` is non-empty use the `search` (120 s) policy instead of `master`/`sku`; whitelist the filter params that enter the hash (drop unknown params) in BaseRepository::paginate and CachesReadPayload; optionally skip caching for keyword length < 2. Keep page/per_page in keys for SQL-paged endpoints (rule 2 of the trait).

**Kiểm chứng:** Type a 10-char search on Catalog master and SKU list; sample `valkey-cli -n 1 --scan --pattern '*catalog-master*'` count and `used_memory` before/after; count `cache.miss` for those namespaces; confirm request count per typed word in browser devtools drops to 1-2.


### CACHE-05 — Stampede lock and TTL applied to per-user keys that the FE never re-reads (write-only cache, +4 Redis RTT per miss)

**Bằng chứng:** CachesReadPayload.php:599 always calls `rememberStampede` even when `$actorId !== null`; NotificationController.php:53-72 and :107-119 cache per actor with TTL `stock_short` (20 s +/-10%); FE useNotifications.ts:19-30 fetches unread-count once per session (`enabled` gate, comment: Echo increments afterwards) and the list with default staleTime 60 s (query-client.ts:49, refetchOnWindowFocus false) - both longer than the 20 s server TTL. Miss path costs SET NX (cmdstat_set) + GET recheck + SETEX + EVAL release (vendor RedisLock.php:37,65) in addition to the version GET. Same pattern: ImportReportController.php:215 (detail per actor), StockMovementController.php:464 (trace-by-code per actor).

**Nguyên nhân:** The generic trait picks the hot-key strategy for every caller; per-user keys have concurrency ~1 so the lock never collapses anything, and the TTL is shorter than the client's own cache window.

**Ở 10k người dùng:** Every notification/unread-count/per-actor detail request is a guaranteed miss with 5 extra Redis round trips and one extra SETEX write (10k users x session bootstrap = pure write traffic and key churn); no DB offload is obtained.

**Sửa thế nào:** In cachedReadPayload use `remember` (no lock) when `$actorId !== null` (CacheService.php:46-63 already exists for this purpose per its docblock); drop caching for `notification:unread-count` entirely (single indexed COUNT) or raise per-user TTL to >= FE staleTime. Keep actor in the key for anything that stays cached.

**Kiểm chứng:** Log-sample `cache.hit` vs `cache.miss` for `notification:*` keys over an hour of real usage (expect ~0 hits today); cmdstat_set/eval deltas per 1,000 requests before/after.


### CACHE-06 — Lock wait 5 s / TTL 10 s / 250 ms poll with no stale-while-revalidate: protection flips to dogpile when rebuilds are slow

**Bằng chứng:** CacheService.php:89-90 defaults wait 5 s, ttl 10 s; :109-114 on LockTimeoutException every waiter runs `$resolver()` with no cap; vendor Lock.php:43 `sleepMilliseconds = 250`, :121-127 waiters sleep 250 ms before re-checking, so any concurrent miss pays >= 250 ms even when the rebuild takes 30 ms; no SWR anywhere (Cache::flexible exists at vendor Repository.php:642 but unused). Rebuild durations: cold p95 321 ms across read endpoints (PERF_AUDIT §1), dashboard-overview 26 queries (PERF_AUDIT §4), movement-trace ~280 ms at 5,956 rows (PERF_AUDIT §1); under saturation 305 requests > 3 s in php-fpm slowlog (LOAD_TEST_2026-09-07 §1). After each `stock:version` bump (CACHE-01) all readers of a hot key arrive on a cold key simultaneously.

**Nguyên nhân:** Lock parameters were set for an unloaded system; once a rebuild exceeds 5 s (saturation, or movement-trace at production volume), waiters time out and rebuild in parallel; a rebuild > 10 s drops the lock while still running. Readers have no stale value to serve during rebuild.

**Ở 10k người dùng:** After every invalidation of dashboard-overview/sku-lookup/report keys, N concurrent users queue 250 ms-quantised behind one worker; if the rebuild passes 5 s all N run the 26-query aggregate at once - the DB sees the worst case precisely at peak, and php-fpm children are held for the full wait (48-64 slots).

**Sửa thế nào:** For shared hot keys (dashboard, sku lookup, reports, catalog lists) use `Cache::flexible($key, [fresh, stale], resolver, lock)` (framework 13.23 supports it: stale served immediately, refresh deferred after the response) or implement soft-TTL in CacheService (store `soft_expires`, serve stale + single refresher under a non-blocking `lock->get()`). On LockTimeoutException return the last stale value if one exists instead of rebuilding. Set lock TTL >= max observed rebuild (30 s) and lock wait shorter (1-2 s) with stale fallback; reduce `sleepMilliseconds` via `$lock->betweenBlockedAttemptsSleepFor(50)`. Keep the lock in the cache DB (WWHN-295).

**Kiểm chứng:** Count `cache.lock.timeout` warnings under the mixed k6 run; measure p95 of /reports/dashboard-overview in the 2 s after a forced `wms:cache:clear --scope=stock` with 40 VUs on that endpoint (probe-concurrency.js TARGET) before/after.


### CACHE-07 — Fail-open is partial and expensive: JWT blacklist + throttle still hard-fail, and each request pays 3 retried connection attempts before the DB fallback

**Bằng chứng:** CacheService.php:148-229 wraps get/lock/put; CacheVersion.php:395-404 wraps the version GET; but tymon Blacklist::has (vendor Blacklist.php:128-138) -> Cache::tags()->get() is unwrapped and runs on every authenticated request (config/jwt.php:224), and `throttle:*` middleware (routes Modules/Warehouse/routes/api.php:62, Auth/routes/api.php:20, public_api.php:29,53) uses the cache store directly. phpredis options: max_retries 3, backoff decorrelated_jitter base 100 ms cap 1,000 ms (config/database.php:305-308,318-321), connect timeout 0.0 = unbounded (PhpRedisConnector.php:172). A cached read touches Valkey 3 separate times (version GET, payload GET, lock) each of which would fail independently. Behaviour with Valkey down NOT MEASURED (cannot stop it on this shared machine).

**Nguyên nhân:** Fail-open was implemented at the CacheService boundary only; framework and package code paths that also depend on Valkey were not covered, and the retry policy is tuned for transient blips, not outages.

**Ở 10k người dùng:** If Valkey is unreachable: every authenticated request 500s at the auth middleware regardless of CacheService fail-open; if it is reachable but slow/partitioned, each request burns up to 3 x (retries x backoff) seconds holding a php-fpm child before reaching the DB, then all 10k users hit PostgreSQL with zero request collapsing (max_connections 120).

**Sửa thế nào:** Add a process-local circuit breaker in CacheService (on failure, mark Valkey down for N s in a static/APCu flag and skip cache calls - static is safe here because it is a health flag, not data); set REDIS_MAX_RETRIES=0-1 and explicit `timeout`/`read_timeout` (~0.2-0.5 s) on the `cache` connection; decide auth policy explicitly: either wrap Blacklist storage in a fail-open adapter with a short JWT_TTL, or accept hard-fail and document it; wrap RateLimiter in a try/catch middleware that allows the request when the store is unavailable. Do not put CacheService in fail-closed mode.

**Kiểm chứng:** In a staging stack: `docker compose pause valkey`, run bench-be.mjs for 60 s, record error rate and p95 for /api/v1/health, /api/v1/products, /api/v1/pv/products; then unpause and verify recovery. NOT MEASURED now.


### CACHE-08 — Version-key double GET plus RBAC + JWT lookups = 6 GETs per request; version key is a guaranteed miss until the first write

**Bằng chứng:** CachesReadPayload.php:575-579 `currentVersion()` GET before every payload GET; CacheVersion.php:398 `get(...) ?? 1` - key only created by bump() (:412-413), so on a fresh cache every version GET is a miss (LOAD_TEST_2026-09-06 §5: hit ratio 35% 'floor'); User.php:98-171 + CheckPermission.php:35-44: is-super-admin, permissions, warehouse-scopes = 3 GETs per non-superadmin request (memoised per model instance only); tymon blacklist = 1 GET. Live: cmdstat_get 642,962 over 164,387 connections = 3.91 GET/connection (includes 0-GET health/queue connections). Redis RTT 0.1-0.2 ms in-container (LOAD_TEST_2026-09-07 §1).

**Nguyên nhân:** No request-scoped memoisation; the doc rejected a `static` memo because php-fpm reuses workers (correct), but a per-request memo (instance property on the container-bound CacheService, rebuilt every request under php-fpm) was not considered.

**Ở 10k người dùng:** ~1-2 ms of serial Redis RTT per request plus ~6 GET x 500 req/s = 3,000 GET/s; not a Valkey capacity issue but it inflates the miss counter (making the hit ratio unusable as a health signal) and adds latency on the CPU-bound PHP tier.

**Sửa thế nào:** 1) Seed version keys in `wms:cache:clear` / on boot with `Cache::add(key, 1)` so the GET is a hit. 2) Memoise `CacheVersion::current()` per request in a non-static property of the CacheService singleton (safe: container is rebuilt per php-fpm request; NOT safe under Octane - document). 3) Batch the RBAC keys: one MGET for is-super-admin/permissions/warehouse-scopes in AuthCache, or store them as one hash-like array under a single key. 4) Consider pipelining version GET + payload GET when the version is memoised from a previous call in the same request. Keep the version-in-key design (no wildcard deletes).

**Kiểm chứng:** Replay 1,000 requests of /api/v1/products with bench-be.mjs; measure cmdstat_get delta per request (expect 6 -> 3) and keyspace hit ratio (expect > 60% on a read-only mix once version keys exist).


### CACHE-09 — Uncompressed PHP-serialized MB payloads: double encode on miss, unserialize + full recursive corruption walk + json_encode on every hit

**Bằng chứng:** CacheService.php:163-198 walks the entire payload array recursively on every hit; CachesReadPayload.php:630-647 json_encode + json_decode on every miss before serialize(); vendor RedisStore.php:485-535 uses plain `serialize()` unless phpredis `serializer`/`compression` options are set - none configured (config/database.php:288-322); container `php -r` shows Redis::SERIALIZER_IGBINARY / COMPRESSION_LZ4 / COMPRESSION_ZSTD undefined (phpredis 6.3.0 built without them). In-memory-paged reports drop page/per_page so one entry holds the whole result (CachesReportPayload.php:149-152); movement-trace = 4.3 MB JSON (PERF_AUDIT §6); Valkey net_output 24.9 GB / 245,468 hits = ~101 KB per hit upper bound; PHP CPU is the documented bottleneck (LOAD_TEST_2026-09-06 §2, backend 760-860% CPU).

**Nguyên nhân:** Defensive design (plain arrays + corruption check) is applied uniformly regardless of payload size, and the transport is neither compressed nor binary-serialized.

**Ở 10k người dùng:** For report/dashboard hits the PHP worker still spends CPU proportional to payload size (unserialize + O(n) walk + json_encode of 100 KB-4 MB) and Valkey ships ~100 KB per hit; at 500 req/s that is ~50 MB/s of Valkey egress and a large share of the PHP CPU budget going to re-encoding data that was already encoded once.

**Sửa thế nào:** Rebuild the php image with `pecl install redis` configured for lz4/zstd (+ igbinary) and set `'compression' => 'lz4'` (and `'serializer' => 'igbinary'`) on the `cache` connection; for the largest report endpoints cache the final JSON string (skip isCorrupted for string payloads - it cannot contain __PHP_Incomplete_Class) and return it via a raw JSON response; cap `per_page`/date range for in-memory-paged reports; keep `serializable_classes=false`.

**Kiểm chứng:** MEMORY USAGE of a movement-trace key before/after compression; getrusage CPU per request for /reports/dashboard-overview on hit (probe used in LOAD_TEST_2026-09-07 §1); Valkey total_net_output_bytes per hit.


### CACHE-10 — No negative caching: missing-id lookups and public PV search/404 always hit PostgreSQL

**Bằng chứng:** CacheService.php:25-33 documents that a null resolver result can never hit; BaseRepository.php:158-168 caches `null` for not-found ids -> rebuild every call; PvCatalogReadModel.php has 0 references to Cache/CacheService (paginateProducts :44 with ILIKE :525), PvPublicCatalogController.php:58-73 listProducts and :96 showProduct (404 for unpublished) are uncached, protected only by `throttle:pv-public` 120 req/min per IP (AppServiceProvider.php:109-115) - PERF_AUDIT §4b did not list /pv/* among the 29 uncached endpoints.

**Nguyên nhân:** Sentinel design cannot represent null, and the anonymous PV read model was never put behind the cache layer.

**Ở 10k người dùng:** Anonymous catalogue traffic (App PV) and any client/bot enumerating ids or typing searches translate 1:1 into ILIKE/aggregate queries; a single IP gets 120/min, but 10k real devices or a distributed scraper are unthrottled at the DB.

**Sửa thế nào:** Cache an explicit marker (e.g. `['__none' => true]`) with a 30-60 s TTL for not-found lookups in BaseRepository::find and PV showProduct; wrap PvCatalogReadModel::paginateProducts/listIndustries in cachedReadPayload with `search` policy, global scope, keyword >= 2 chars, keyed on `master:version`/`stock:version` as appropriate (in_stock filter depends on stock).

**Kiểm chứng:** pg_stat_statements calls/min for the PV ILIKE query and the `sku ... where sku_id = ?` lookup under a k6 PV flow before/after; Valkey key count for `pv:*` namespace.


### CACHE-11 — One log line per cache read (`cache.miss` at info, `cache.hit` at debug) becomes I/O at production log levels

**Bằng chứng:** CacheService.php:53,58,82,87 log on every hit/miss; .env and .env.example LOG_LEVEL=debug, LOG_CHANNEL=stack (file); LOAD_TEST_2026-09-07 §1 recorded storage/logs/laravel.log at 357 MB / 2.1 M lines consisting of cache.hit/cache.rebuild; perf overlay mitigates with LOG_LEVEL=warning (docker-compose.perf.yml:67-68) but `cache.miss` is info-level, so any production setting of info re-enables ~1 line per cached read.

**Nguyên nhân:** Observability implemented as per-call logging rather than counters.

**Ở 10k người dùng:** At 500 req/s with info logging this is ~500-3,000 log lines/s of synchronous file writes per backend replica.

**Sửa thế nào:** Downgrade cache.miss to debug or sample (1 in 100), and expose hit/miss/rebuild/lock-timeout as counters (e.g. Valkey INCR per namespace flushed by scheduler, or Prometheus via a /metrics endpoint); keep warning-level logs for corrupted/unavailable/lock-timeout.

**Kiểm chứng:** Line count of laravel.log per 1,000 requests at LOG_LEVEL=info before/after.


## 6. Chưa đo được

- Actual `stock:version` bump frequency under production-like mixed traffic - the k6 flows (load/k6/lib/flows.js) are read-only, so no measured invalidation rate exists; cmdstat_incrby (3,987) mixes version bumps with rate-limiter counters.
- Per-hot-key rebuild duration under load (only aggregate cold p95 321 ms, dashboard-overview query count 26, movement-trace ~280 ms at 5,956 rows, and 305 slowlog entries > 3 s under saturation are recorded).
- Exact per-request Redis command count - derived from cumulative INFO ratios (5.96 cmd/conn, 3.91 GET/conn) and code paths, not from a per-request trace.
- Valkey CPU/ops at 10k users - current INFO snapshot is from a mostly idle window (instantaneous_ops_per_sec 9); the concurrent k6 run on this machine could not be isolated.
- Behaviour when Valkey is down or slow (fail-open latency, auth/throttle 500s) - Valkey could not be stopped on the shared machine.
- Serialized size of a movement-trace or dashboard-overview key in Valkey - no such key was present during the SCAN samples (only 33 keys, mostly expired 20 s entries).
- Memory growth rate from keystroke keys - not observed live; estimate derived from observed key sizes (6-25 KB) and TTLs.
- Whether TrayOverviewPage, OutboundIntakeModal, ComponentIssueModal, inventory-flow-api.ts and report-data.ts send undebounced `keyword` requests (only CatalogMasterScreen.tsx was verified line-by-line).
- Whether phpredis can be rebuilt with lz4/zstd/igbinary in this image (requires a Dockerfile change and build; not attempted).
- DB-side cost of cache misses (pg_stat_statements per query) - out of this lens, not queried.
