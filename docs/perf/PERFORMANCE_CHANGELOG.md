# PERFORMANCE_CHANGELOG — Hoà Nam WMS

Mỗi mục: file đã sửa · vấn đề · nguyên nhân gốc · cách sửa · số đo trước/sau · rủi ro còn lại.
Chỉ ghi những thay đổi ĐÃ THỰC HIỆN. Những gì mới chỉ là đề xuất nằm ở
`PERFORMANCE_BOTTLENECKS.md`.

Máy đo cho mọi số dưới đây: Windows 11 + Docker Desktop, VM 16 vCPU / 8 GB RAM,
k6 chạy **cùng máy** với hệ thống (xem phần "Bối cảnh phần cứng" của
`PERFORMANCE_FINAL_REPORT.md`).

---

## 2026-09-07 · BE-01 — Phục vụ mã nguồn từ filesystem của container, không qua bind mount

**File:** `docker/php/perf-entrypoint.sh`, `docker/nginx/perf/default.conf.template`,
`docker-compose.perf.yml` (`PERF_LOCAL_CODE`, `PERF_APP_DIR`, `WMS_PHP_DOCROOT`).

**Vấn đề:** hồ sơ chịu tải CHẬM HƠN stack dev — baseline 45,1 req/s với 19,9 % lỗi,
trong khi stack dev đạt 77,9 req/s với 0 % lỗi.

**Nguyên nhân gốc (bằng chứng `strace` trên php-fpm):** mỗi request Laravel đọc
10–60 file KHÔNG phải PHP qua bind mount gRPC-FUSE — `Modules/*/module.json` ×4,
`modules_statuses.json`, dò thư mục config/view của module, kiểm tra maintenance —
và `bootstrap/cache/config.php` trả **ENOENT** vì `bootstrap/cache` nằm trên bind
mount DÙNG CHUNG, bị container chạy test `config:clear` xoá mất. opcache không đỡ
được các file đó, và gRPC-FUSE xếp hàng khi 48 tiến trình cùng đọc.

**Cách sửa:** lúc khởi động chép nguyên cây (kể cả vendor) sang `/srv/app` trong
container, hâm nóng config/route/event cache **ở đó**, nginx trỏ `SCRIPT_FILENAME`
sang đó. Mỗi lần khởi động là một bản chép mới của mã hiện tại.

**Số đo (cùng mã, cùng DB, cùng nginx/php-fpm):**

| | bind mount (thiếu cache) | bind mount (có cache) | mã trong container |
|---|---|---|---|
| `/health`, 1 người dùng | 190–210 ms | 92–138 ms | **27–40 ms** |
| `/health`, 40 người đồng thời | 65 req/s | 173 req/s | **248–284 req/s** |
| endpoint có auth + DB, rảnh | 160–260 ms | — | **32–90 ms** |

**Rủi ro còn lại:** sửa code phải khởi động lại container backend mới có hiệu lực
(đúng bản chất hồ sơ chịu tải). `PERF_LOCAL_CODE=false` để quay lại bind mount.

---

## 2026-09-07 · BE-02 — PgBouncer gom kết nối PostgreSQL (chế độ session)

**File:** `docker-compose.perf.yml` (service `pgbouncer`, `DB_HOST` của
backend/queue/scheduler trỏ sang nó).

**Vấn đề:** ở 150 req/s, `SlowRequestLog` cho thấy "thời gian SQL" chiếm 80–90 %
mọi route chậm, dù `pg_stat_statements` nói các câu đó chỉ 0–16 ms.

**Nguyên nhân gốc:** Laravel tính thời gian MỞ KẾT NỐI vào câu truy vấn đầu tiên.
Mỗi request PHP mở một kết nối PostgreSQL mới (fork backend + bắt tay SCRAM);
`pgbench -C` xác nhận: 1.438 tps khi mở kết nối mỗi giao dịch so với 47.990 tps
khi tái dùng — gấp 33 lần.

**Cách sửa:** PgBouncer chế độ **session** (không phải transaction) nên mỗi request
PHP vẫn giữ nguyên một kết nối server suốt request ⇒ `lockForUpdate`, transaction,
prepared statement, `search_path` giữ đúng ngữ nghĩa; `SERVER_RESET_QUERY=DISCARD ALL`
dọn transaction rò rỉ nếu PHP chết giữa chừng.

**Số đo A/B (máy rảnh, 2 replica, cùng lượt):**

| Phép đo | Trực tiếp tới PostgreSQL | Qua PgBouncer | Thay đổi |
|---|---|---|---|
| `/health` 40 VU | 148 req/s · p50 234 ms | **237 req/s · p50 159 ms** | **+60 % thông lượng, −32 % p50** |
| `/products` (auth+DB) 40 VU | 97 req/s · max 54 s | **189 req/s · max 4,4 s** | **+94 % thông lượng** |
| 3.000 người dùng, think 20 s | 116,6 req/s · p95 675 ms · p99 1,19 s · lỗi 2,36 % | **124,5 req/s · p95 562 ms · p99 991 ms · lỗi 0,59 %** | p99 −17 %, lỗi −75 % |
| CPU PostgreSQL ở cùng mức tải | ~500 % (5 lõi) | **46–96 %** | ~5 lần ít hơn |

**Rủi ro còn lại:** thêm một tiến trình trong đường đi (PgBouncer dùng 30–45 % một
lõi ở 150–300 req/s). `DEFAULT_POOL_SIZE=100` phải nằm trong ngân sách
`PG_MAX_CONNECTIONS=120` — thêm replica KHÔNG làm tăng số kết nối server.

---

## 2026-09-07 · BE-03 — Chỉ mục theo thời gian cho sổ cái `stock_movements`

**File:** `Modules/Warehouse/database/migrations/2026_09_07_000000_add_stock_movements_time_indexes.php`

**Vấn đề:** bảng có 10 chỉ mục nhưng không cái nào dẫn đầu bằng `occurred_at`, trong
khi màn "Biến động tồn" sắp xếp theo cột đó và 8 truy vấn báo cáo lọc
`occurred_at BETWEEN`.

**Bằng chứng:** `pg_stat_user_tables`: `seq_scan` 1.497 lần đọc 11.002.950 dòng
(mỗi lần quét trọn sổ cái). `SET enable_seqscan=off` vẫn ra Seq Scan ⇒ không có
chỉ mục dùng được.

**Cách sửa:** thêm `idx_sm_occurred_at_id (occurred_at DESC, id DESC)` và
`idx_sm_wh_occurred_at (warehouse_id, occurred_at DESC)`, dùng `CONCURRENTLY` trên
PostgreSQL. Không đổi cột/ràng buộc/nghiệp vụ, không đụng `business_key`/`movement_no`.

**Số đo (EXPLAIN):**

| Truy vấn | Trước | Sau |
|---|---|---|
| danh sách theo `occurred_at DESC` | `Limit → Sort → Seq Scan` | `Limit → Index Scan using idx_sm_occurred_at_id` |
| tổng hợp theo kỳ | `HashAggregate → Seq Scan (Filter)` | `HashAggregate → Index Scan (Index Cond)` |
| danh sách theo kho | Seq Scan + Sort | `Index Scan using idx_sm_wh_occurred_at` |

**Rủi ro còn lại:** +2 B-tree trên bảng chỉ ghi thêm với khoá tăng dần — chi phí
chèn vào lá phải cùng, thấp nhất trong các kiểu chèn. Dữ liệu dev chỉ 7.350 dòng
nên **lợi ích thật ở khối lượng production chưa được đo**.

---

## 2026-09-07 · BE-04 — Khoá cache `/inventory` thiếu chữ ký phạm vi kho (RÒ DỮ LIỆU)

**File:** `Modules/Warehouse/app/Http/Controllers/InventoryBalanceController.php`

**Vấn đề:** khoá cache gồm bộ lọc + phân trang nhưng KHÔNG có phạm vi kho, trong
khi model `InventoryBalance` dùng `ScopesWarehouseData` (thêm
`whereIn(warehouse_id, $user->warehouseScopeIds())`). Hai người có phạm vi kho khác
nhau, cùng bộ lọc ⇒ **dùng chung một mục cache**: người mở sau đọc trúng dữ liệu
kho không thuộc quyền.

**Cách sửa:** thêm `scope-<chữ ký>` vào khoá bằng `CacheKey::scopeSignature()`, đúng
quy ước `CachesReadPayload` mà `/stock-movements` đã dùng. Không đổi hình dạng
response, không gỡ global scope, không đụng `EnforceInventoryWarehouseScope`.

**Số đo:** đây là sửa ĐÚNG SAI, không phải tối ưu — không có "trước/sau" về tốc độ.
Hệ quả phụ: tỉ lệ hit của endpoint này sẽ giảm (mỗi phạm vi một mục cache) — đó là
cái giá đúng.

**Rủi ro còn lại:** chưa có test hồi quy khẳng định hai phạm vi không dùng chung
khoá; nên bổ sung.

---

## 2026-09-07 · BE-05 — Kết nối Valkey bền + trần thời gian chờ

**File:** `config/database.php` (hai khoá `timeout`/`read_timeout` mới, mặc định 0
= giữ nguyên hành vi cũ), `docker-compose.perf.yml` (`REDIS_PERSISTENT=true`,
`REDIS_TIMEOUT=2`, `REDIS_READ_TIMEOUT=2` cho backend/queue/scheduler).

**Vấn đề:** mỗi request mở một kết nối Valkey mới (TCP + AUTH + SELECT), và không
có trần thời gian chờ — Valkey TREO (không phải chết) sẽ ghim worker php-fpm và
lớp fail-open xuống database **không bao giờ chạy**.

**Cách sửa:** `pconnect` + timeout 2 s ở hồ sơ chịu tải. An toàn với hàng đợi vì
`config/queue.php` đặt `block_for = null` (không dùng BLPOP chặn).

**Số đo:** đo cùng lượt với BE-02 nên **chưa tách riêng được đóng góp của mục này**
— ghi `KHÔNG TÁCH ĐƯỢC`. Valkey trong lượt 150 req/s: 643.022 lệnh GET ở
20,2 µs/lệnh, `blocked_clients` 0, `evicted_keys` 0.

**Rủi ro còn lại:** `read_timeout` 2 s sẽ cắt bất kỳ lệnh Valkey nào chạy lâu hơn —
đúng ý đồ, nhưng nếu sau này có lệnh chậm hợp lệ thì phải nâng riêng.

---

## 2026-09-07 · BE-06 — Nâng `pm.max_requests` 1000 → 5000 (hồ sơ chịu tải)

**File:** `docker-compose.perf.yml`

**Vấn đề:** `pg_stat_statements` cho thấy **24.099 câu introspection catalog cột**
(`select a.attname … from pg_attribute`) tiêu 74 giây tổng — nhiều hơn bất kỳ truy
vấn nghiệp vụ nào.

**Nguyên nhân gốc:** `BaseRepository::getTableColumns()` và `nonNullableColumns()`
ghi nhớ theo TIẾN TRÌNH (`static $cache`). Mỗi child php-fpm bị tái sinh sau 1000
request phải hỏi lại toàn bộ catalog.

**Cách sửa:** nâng mặc định lên 5000 (vẫn là chốt chống rò rỉ bộ nhớ thông dụng).

**Số đo:** áp dụng cùng lượt với BE-02/BE-05 ⇒ **KHÔNG TÁCH ĐƯỢC** đóng góp riêng.

**Rủi ro còn lại:** worker sống lâu hơn ⇒ rò rỉ bộ nhớ (nếu có) tích tụ lâu hơn
trước khi được dọn. Cần theo dõi RSS của worker trong bài soak.

---

## 2026-09-07 · BE-07 — Quan sát được: log request chậm + trang trạng thái php-fpm

**File:** `app/Http/Middleware/SlowRequestLog.php` (mới), `config/wms_perf.php` (mới),
`bootstrap/app.php`, `app/Support/Cache/CacheService.php` (đo thời gian chờ lock),
`docker/php/perf-entrypoint.sh` (`pm.status_path`), `docker/nginx/perf/default.conf.template`
(mở `/fpm-status` cho mạng nội bộ), `load/analyze-slow-requests.mjs` (mới).

**Vấn đề:** khi người dùng báo "màn này lag", không có cách nào biết request nào
chậm và chậm ở đâu — slowlog của php-fpm chỉ ghi `GET /index.php?page=1`.

**Cách sửa:** middleware ngoài cùng ghi (ở mức WARNING, chỉ khi vượt ngưỡng
`SLOW_REQUEST_MS`): route, mã trạng thái, tổng thời gian, **số câu SQL + thời gian
SQL**, hit/miss/put cache, **thời gian chờ lock stampede**, bộ nhớ đỉnh, request_id.
Không ghi tham số/body/PII.

**Số đo (chính nó là công cụ đo):** trong một lượt 150 req/s, gom được 4.790 request
chậm trên 28 route và quy được thời gian về từng route — đó là cách tìm ra BE-02.

**Rủi ro còn lại:** hai listener sự kiện chạy trên mọi request (vài µs). Ngưỡng mặc
định 500 ms ở hồ sơ chịu tải; đặt `SLOW_REQUEST_MS=0` để tắt hẳn.

---

## 2026-09-07 · BE-08 — `pg_stat_statements` được TẠO, không chỉ preload

**File:** (thao tác vận hành) `CREATE EXTENSION pg_stat_statements`.

**Vấn đề:** `shared_preload_libraries` đã có thư viện nhưng extension chưa được tạo
trong database ⇒ mọi truy vấn vào `pg_stat_statements` báo lỗi, không xếp hạng được
truy vấn nào.

**Số đo:** sau khi bật, xếp hạng được top theo tổng thời gian và phát hiện BE-06.

**Rủi ro còn lại:** nên đưa vào migration/`docker-entrypoint-initdb.d` để môi trường
mới không lặp lại thiếu sót này. **CHƯA làm.**

---

## 2026-09-07 · BE-09 — Bộ đo tải: chỉ số theo nhóm + tách luồng công khai

**File:** `load/k6/wms-load.js`, `load/k6/lib/flows.js`, `load/k6/probe-concurrency.js`
(mới), `load/summarize.mjs` (mới).

**Vấn đề:** (1) không xuất được p50/p95/p99 theo NHÓM nghiệp vụ; (2) các luồng công
khai đi qua `throttle:pv-public` 120 req/phút **theo IP**, mà k6 phát mọi request từ
một IP ⇒ 429 giả tạo làm bẩn tỉ lệ lỗi.

**Cách sửa:** thêm ngưỡng theo nhóm (chỉ để k6 xuất sub-metric, không phải gate) và
cờ `INCLUDE_PUBLIC=0`. Thêm `probe-concurrency.js` để cô lập từng tầng và
`summarize.mjs` để so sánh các lượt.

**Số đo:** với `INCLUDE_PUBLIC=0`, lượt 3.000 người dùng có **0 lỗi 429** (trước đó
613) — con số lỗi còn lại là lỗi thật.

**Rủi ro còn lại:** bộ đo vẫn **chỉ đọc**; đường ghi (Post Receipt/Issue) chưa có
kịch bản — xem WRITE-07 trong sổ điểm nghẽn.

---

## 2026-09-07 · FE-01…FE-08 — Đợt tối ưu frontend (chi tiết ở `FE_PERF_AUDIT_2026-09-06.md` §7)

Tóm tắt để đối chiếu (đo bằng Playwright trên bản build production):

| # | Thay đổi | File chính | Trước → Sau |
|---|---|---|---|
| FE-01 | Nạp trước chunk route khi rê chuột + lúc rảnh | `app/router/route-preload.ts`, `SideNav.tsx` | 1–38 chunk khi bấm menu → **0** |
| FE-02 | Giữ sống 9 màn báo cáo (`<Activity mode="hidden">`) | `app/layouts/KeepAliveOutlet.tsx` | quay lại 0,7–0,9 s → **36–136 ms**, 0 request |
| FE-03 | Overlay portal không render khi trang bị ẩn | `shared/ui/page-visibility.ts`, `components/PageOverlays.tsx`, `UiDialog.tsx` | drawer treo đè lên trang khác → biến mất đúng lúc |
| FE-04 | Tab = cập nhật gấp, panel = `useDeferredValue` | 7 màn báo cáo | bấm tab khoá luồng chính ~930 ms → tab đổi màu ngay |
| FE-05 | Chart lười (tách chunk + IntersectionObserver + tắt animation) | `shared/components/charts/LazyApexChart.tsx` | chunk 868 KB rời đường tải |
| FE-06 | `content-visibility: auto` cho thẻ ngoài màn hình | 3 CSS + 2 `CARD_SX` | bớt layout/paint ngoài khung nhìn |
| FE-07 | Danh mục SKU: 9 request phân trang → 1 `GET /skus/lookup` | BE `ProductController::lookup`, FE `report-data.ts` | 9 → **1** request/màn báo cáo đầu |
| FE-08 | Trang không keep-alive về đầu khung cuộn | `KeepAliveOutlet.tsx` (`FreshPage`) | `main.scrollTop` "dính" → 0 |

Kiểm chứng đầu-cuối: `scripts/perf/verify-keep-alive.mjs` — 19/20 điều kiện đạt.

---

## 2026-09-07 · BE-10 — nginx tự cắt dịch vụ khi quá tải (kiểm tra sức khoẻ thụ động)

**File:** `docker-compose.perf.yml` (`WMS_PHP_UPSTREAM_SERVERS`),
`docker/nginx/perf/nginx.conf` (`worker_processes`),
`docker/nginx/perf/default.conf.template` (`keepalive`).

**Vấn đề:** ở 200 req/s, 10,77 % request hỏng với **2.930 lỗi 5xx**, trong khi cả
hai container backend vẫn sống và vẫn trả 200 cho request khác.

**Bằng chứng:** log nginx có **2.917 dòng `no live upstreams`** — khớp gần đúng số
lỗi 5xx. Không có exception nào của Laravel, không có `too many clients`, không có
deadlock.

**Nguyên nhân gốc:** `max_fails=3 fail_timeout=10s` là kiểm tra sức khoẻ THỤ ĐỘNG.
Quá tải ⇒ 3 request quá hạn ⇒ nginx đánh dấu một backend "chết" 10 giây ⇒ toàn bộ
tải dồn sang backend còn lại ⇒ nó cũng bị đánh dấu chết ⇒ **không còn upstream nào
sống** ⇒ 502 cho MỌI request trong 10 giây. Chậm tạm thời bị KHUẾCH ĐẠI thành mất
dịch vụ hoàn toàn. Với FastCGI, "upstream" không phải máy chủ độc lập có thể hỏng
riêng — chúng là toàn bộ năng lực hệ thống, nên loại bớt là tự cắt chân mình.

**Cách sửa:** `max_fails=0` (quá tải thì XẾP HÀNG, `fastcgi_next_upstream` vẫn xử
lý lỗi từng request). Kèm theo, giới hạn số kết nối keepalive nginx giữ tới
php-fpm: `worker_processes 4` + `keepalive 8` ⇒ 4 × 8 = 32 ≤ 96 child (php-fpm
KHÔNG có idle timeout cho kết nối FastCGI đang giữ, nên mỗi kết nối dư là một
child bị ghim; bản cũ 16 × 128 = 2.048 kết nối trên 96 child).

**Số đo A/B tại đúng 200 req/s (chỉ khác cấu hình nginx):**

| Chỉ số | Trước | Sau |
|---|---|---|
| Tỉ lệ lỗi | **10,77 %** | **0,00 %** (0/44.699) |
| Lỗi 5xx | 2.930 | **0** |
| `no live upstreams` | 2.917 dòng | **0** |
| Thông lượng | 146,7 req/s | **166,1 req/s** |
| p50 | 298 ms | **68 ms** |
| p95 | 713 ms | **172 ms** |
| p99 | 966 ms | **262 ms** |
| max | — | **663 ms** |
| dropped | 897 | **0** |

**Mức 200 req/s chuyển từ KHÔNG ĐẠT sang ĐẠT toàn bộ ngưỡng SLO** chỉ bằng cấu
hình nginx, không sửa một dòng mã ứng dụng. Điều này cũng chỉnh lại kết luận về
"điểm gãy": trước đây tưởng hệ thống hết sức ở 150–200 req/s, thực ra lớp cân tải
đang tự cắt dịch vụ. Trần thật đo được là **~200–205 req/s**.

**Đính chính về phương pháp:** lượt A/B đầu tiên chạy nhầm với MỘT replica (lệnh
recreate nginx đưa service `backend` về scale mặc định) và cho p99 18,76 s. Bảng
trên là lượt chạy lại với đủ hai replica. Bài học: sau mỗi `docker compose up` cho
một service riêng lẻ, phải kiểm lại số replica trước khi đo.

**Rủi ro còn lại:** với nhiều máy thật có dự phòng, `max_fails` là cơ chế hữu ích —
lúc đó bật lại qua biến `WMS_PHP_UPSTREAM_SERVERS` chứ không sửa file. Đổi
`worker_processes` phải đổi `keepalive` theo để giữ bất biến ở trên.

---

## 2026-09-07 · FE-09 — Bỏ `POST /auth/refresh` chắc chắn hỏng trên trang công khai

**File:** `src/app/bootstrap-auth.ts`

**Vấn đề:** mọi lần tải trang đều gọi `POST /auth/refresh`. Trên máy sạch mở
`/login`, request đó **luôn** trả 422 (BE yêu cầu `refresh_token` ở body hoặc
cookie, không có cái nào) — một vòng mạng hỏng nằm trên đường tới nội dung đầu
tiên của trang đăng nhập, cộng một dòng lỗi trong log BE cho mỗi lượt khách.

**Cách sửa:** chỉ thử refresh khi CÓ LÝ DO tin phiên tồn tại — còn dấu vết phiên
(cookie `erp-auth` giữ `user`), HOẶC đang vào đường dẫn cần đăng nhập (cookie
refresh là HttpOnly nên JS không đọc được, phải thử mới biết). Chỉ BỎ QUA đúng một
trường hợp: trang công khai + không dấu vết. **Giữ nguyên fail-closed** của
`ProtectedRoute`.

**Số đo:** LCP `/login` (CPU x1) 432 ms, (CPU x4) 1.320 ms — đạt ngưỡng. **Chưa có
đối chứng trước** vì bộ đo Web Vitals mới viết cùng đợt.

**Rủi ro còn lại:** nếu sau này thêm đường dẫn công khai mới, phải thêm vào
`PUBLIC_PATH_PREFIXES`; quên thì chỉ mất tối ưu, không sai bảo mật.

---

## 2026-09-07 · FE-10 — Tải song song shell + trang đích thay vì nối tiếp sau xác thực

**File:** `src/main.tsx`

**Vấn đề:** mỗi lần F5 ở trang cần đăng nhập là chuỗi NỐI TIẾP 5 chặng — bundle
chính → `POST /auth/refresh` → `ProtectedRoute` mở cổng → mới bắt đầu `import()`
`MainLayout` (62 KB) → `MainLayout` render outlet mới `import()` trang đích (~57 KB).
Bốn chặng sau chỉ là tải file tĩnh, không phụ thuộc kết quả xác thực.

**Cách sửa:** ngay cạnh `bootstrapAuth()`, kích hoạt `import()` của `MainLayout` và
`preloadRoute(location.pathname)` (dùng lại `route-preload.ts`). **Không đụng thứ
tự cổng** của `ProtectedRoute` — đây chỉ là tải trước MÃ NGUỒN; trang vẫn chỉ
render sau khi có hồ sơ người dùng và cache sẵn sàng, nên bảo đảm phạm vi dữ liệu
giữ nguyên.

**Số đo:** **KHÔNG TÁCH ĐƯỢC** khỏi FE-09 (áp dụng cùng lượt build). Web Vitals sau
khi áp dụng cả hai: xem `FRONTEND_PERFORMANCE_REPORT.md` §4b.

**Rủi ro còn lại:** người chưa đăng nhập tải thừa hai chunk (~119 KB). Đổi lại
người đã đăng nhập — tuyệt đại đa số lượt tải của hệ thống nội bộ — bớt hai vòng.
