# Audit hiệu năng toàn hệ thống — 06/09/2026

Phạm vi: **cả FE và BE** của Hoà Nam WMS (`C:\LAM_PHONG_TECH`).
Điểm xuất phát: người dùng báo INP **14.696 ms** trên màn Truy vết hàng hoá, kèm
cảm giác "một số màn đứng, mở modal / đổi tab / lọc / xem biểu đồ bị lag".

Mọi con số dưới đây là **đo thật** trên stack đang chạy (docker compose local,
Postgres + Valkey + nginx + php-fpm, FE build production chạy qua `vite preview`).
Chỗ nào chưa chứng minh được thì ghi thẳng `NOT VERIFIED`, không ghi PASS.

---

## 1. Kết luận ngắn

Nguyên nhân của "14.696 ms" **không nằm ở backend**. Backend lúc đó đã trả
`/reports/movement-trace` trong ~280 ms. 14,7 giây là **một long task duy nhất
của trình duyệt**: màn Truy vết dựng 5.956 dòng thẳng vào DOM (**293.514 node**)
và cột STT của bảng đánh số bằng `rows.indexOf(row)` — O(n²), ~35 triệu phép so
sánh cho một lần render.

Đã tái hiện đúng hiện tượng, rồi sửa và đo lại:

| Việc người dùng làm | Trước | Sau |
|---|---|---|
| Gõ một ký tự vào ô lọc màn Truy vết | **14.666 ms** khoá luồng chính | **8–61 ms** (phần gấp) + 137 ms nền, ngắt được |
| Số node DOM màn Truy vết | 293.514 | 3.292 |
| Gõ một ký tự màn Đối soát tồn kho | 220 ms | 9–20 ms + 62 ms nền |
| Số node DOM màn Đối soát | 16.638 | 5.932 |

Song song, backend cũng có một khoản phí cố định lớn đã được cắt:

| Đo trên toàn bộ endpoint đọc (n=82) | Trước | Sau |
|---|---|---|
| warm p50 | 207,6 ms | **132,3 ms** |
| warm p95 | 270,3 ms | **159,1 ms** |
| cold p50 | 258,6 ms | **173 ms** |
| cold p95 | 422,4 ms | **321,1 ms** |

---

## 2. Cách đo (tái lập được)

> **ĐÍNH CHÍNH 07/09/2026** (đo lại có bằng chứng strace, xem `LOAD_TEST_2026-09-07.md` §1):
> khoản ~180 ms mỗi request mà mục này quy cho "khởi động Laravel" thật ra là Laravel **đọc file**
> (module.json ×4, modules_statuses.json, dò thư mục config/view, và — khi cache config bị xoá —
> toàn bộ ~45 file config/*.php) **nhân với** bind mount gRPC-FUSE của Docker Desktop. Cùng mã, cùng DB,
> phục vụ từ filesystem của container: /health 190–210 ms → 27–40 ms; 40 người đồng thời 65 → 248–284 req/s.
> Hồ sơ chịu tải nay phục vụ mã từ `/srv/app` (`PERF_LOCAL_CODE`). Các con số tối ưu bootstrap ở mục này
> (80 → 4,8 ms cho BootProviders…) vẫn đúng và vẫn có giá trị — chúng chính là bớt đi những lần đọc đĩa đó.


| Thứ cần đo | Công cụ | Vị trí |
|---|---|---|
| Độ trễ từng endpoint, cold vs warm | script Node gọi thẳng `http://localhost:8080` | `scripts/perf/bench-be.mjs` |
| Số truy vấn + hình dạng truy vấn mỗi endpoint | probe tạm bật `DB::enableQueryLog()` rồi replay request qua HTTP kernel | đã xoá sau khi đo (xem §7) |
| Chi phí bootstrap Laravel theo từng bootstrapper / provider | probe tạm dựng app rồi bấm giờ từng `bootstrap()` | đã xoá sau khi đo |
| Long task / INP / số node DOM của FE | `PerformanceObserver({entryTypes:['longtask']})` + `document.querySelectorAll('*').length` chạy trong trang | trực tiếp trong trình duyệt |
| Tải đồng thời | k6, open model | `load/` (đã có sẵn) |

Tách nginx / php-fpm / Laravel bằng ba đích trên cùng một máy:

```
file tĩnh qua nginx        8 ms
file PHP trống qua fpm     8 ms
route Laravel /api/v1/health   175 ms   ← toàn bộ chênh lệch nằm trong Laravel
```

Đây là bằng chứng bác bỏ giả thiết cũ "180 ms là thuế của Docker Desktop trên
Windows" ghi trong `LOAD_TEST_REPORT.md` §1: lớp nginx+fpm trên chính máy đó chỉ
tốn 8 ms.

---

## 3. Bottleneck đã tìm ra và đã sửa

### 3.1 BE — `boot()` của 4 module quét filesystem trên MỌI request

`Nwidart\Modules\Support\ModuleServiceProvider::boot()` chạy nguyên vẹn mỗi
request: `registerCommands`, `registerCommandSchedules`, `registerConfig`
(`RecursiveDirectoryIterator` + `publishes()` cho từng file config),
`registerViews` (`is_dir()` cho từng `view.paths`), `loadMigrationsFrom`. Repo
này **không có file `.blade.php` nào trong `Modules/*/resources`**, nên phần
view/translation là công thuần tuý bỏ đi.

Đo được: `BootProviders` = **80 ms/request**, riêng `AuditLogServiceProvider::boot()`
= 38 ms.

Sửa: `app/Support/Modules/HttpLeanModuleServiceProvider.php` — đường console giữ
**nguyên 100%** hành vi cũ (`parent::boot()`), đường HTTP chỉ giữ thứ một response
JSON có thể cần. Thêm một điểm nữa: không gọi `module_path()` trên đường HTTP vì
helper đó kéo theo lần quét `Modules/*/module.json` đầu tiên (~27 ms).

Kết quả: `BootProviders` **80 ms → 4,8 ms**.

### 3.2 BE — `predis` thay vì `phpredis`

`.env` đặt `REDIS_CLIENT=predis` (client thuần PHP) trong khi **extension
`phpredis` đã có sẵn trong image** và `docker-compose.perf.yml` vốn dùng
`phpredis`. Mỗi request chạm Valkey nhiều lần (cache đọc, phiên bản namespace).

Sửa: `.env` + `.env.example` → `phpredis`.
Kết quả: `/api/v1/health` **175 ms → ~120 ms**.

### 3.3 BE — 136 lần hỏi catalog PostgreSQL trong đường chạy request

`Schema::hasColumn()` / `hasTable()` / `hasView()` của Laravel **không nhớ gì**:
mỗi lần gọi là một truy vấn vào `pg_attribute`/`pg_class`/`pg_type`. Repo gọi
chúng **136 chỗ** trong đường chạy request, dày nhất ở hai service nóng nhất —
`ScanClassificationService` (16) và `CurrentBaselineStockService` (15), tức luồng
quét nhập/xuất.

Đo được trên `GET /api/v1/warranty-cases` (cold): 5 truy vấn catalog =
**8,7 ms / 18,8 ms tổng thời gian SQL — 46% thời gian SQL không mang một byte dữ
liệu nghiệp vụ nào.**

Sửa: `app/Support/Database/SchemaCache.php` — nhớ danh sách cột của bảng ở lần
hỏi đầu, trong phạm vi **một tiến trình PHP**, xoá sạch khi có migration
(`MigrationsStarted/MigrationEnded/MigrationsEnded/DatabaseRefreshed`).

Kết quả trên chính endpoint đó: **13 → 8 truy vấn**, SQL **18,8 → 7,0 ms**,
request **46,4 → 20,6 ms**.

Hai chỗ CỐ Ý không dùng bộ nhớ đệm này, vì schema đổi ngay trong tiến trình:
`app/Support/HoaNamErdSchema.php` (chạy DDL) và `app/Support/Migration/**` +
`Wwhn172MigrationInspector` (được gọi từ bên trong migration). Có hàng rào tĩnh
chặn tái phạm: `tests/Unit/Support/Database/SchemaCacheTest.php`.

### 3.4 BE — Valkey không có trần bộ nhớ

`docker-compose.yml` (dev) dùng mặc định của image: `maxmemory=0` +
`noeviction`. Nghĩa là Valkey lớn lên tới khi hết RAM máy, rồi **mọi lệnh ghi bắt
đầu lỗi** — hỏng phiên đăng nhập, hỏng việc đẩy job vào hàng đợi. Với payload báo
cáo cỡ MB nhân số tổ hợp bộ lọc, đây không phải tình huống lý thuyết.

`docker-compose.perf.yml` đã làm đúng từ trước. Sửa: đồng bộ chính sách đó sang
compose dev (`maxmemory 256mb`, `volatile-lru` — chỉ đuổi khoá CÓ TTL, để hàng
đợi job không bao giờ bị mất).

### 3.5 FE — nguyên nhân thật của 14.696 ms

Ba lớp cộng dồn trên màn Truy vết hàng hoá:

1. **Cột STT quét lại cả mảng cho từng dòng.** `ledgerFilter.rows.indexOf(row)`
   chạy một lần cho MỖI dòng → O(n²) = ~35 triệu phép so sánh với 5.956 dòng.
   Cùng khuôn mẫu này có ở **25 chỗ** khắp dự án.
   Sửa: `src/shared/components/data-display/row-index.ts` — tra bằng Map
   (WeakMap khoá theo chính mảng), thay đủ 25 chỗ.

2. **Vẽ toàn bộ tập đã lọc vào DOM.** Dòng thời gian dựng 5.956 mục × ~8 `Box`
   MUI, bảng ledger dựng 5.956 `<tr>` → 293.514 node.
   Sửa: `useRenderWindow` — vẽ 60 dòng đầu, tự nới thêm khi cuộn tới nơi
   (IntersectionObserver) và có nút "Hiện thêm". Gắn vào `ResponsiveDataTable`
   nên **mọi bảng của dự án** được bảo vệ, cộng 8 danh sách/lưới dựng tay.
   **Không cắt dữ liệu**: mọi KPI, biểu đồ và bộ đếm vẫn tính trên trọn tập, và
   màn hình luôn nói rõ "đang hiển thị 60/5956".

3. **Mỗi phím gõ dựng lại cả màn ở mức ưu tiên GẤP.** Một lượt render lại của màn
   Truy vết tốn ~200 ms (hàng trăm `sx` của MUI), nên ký tự vừa gõ chỉ hiện lên
   sau chừng đó.
   Sửa: `useSectionFilter.setKeyword` ghi trong `startTransition`, còn
   `SectionFilterBar` giữ bản nháp cục bộ để ô nhập hiện chữ tức thì. Thêm bộ nhớ
   đệm chuỗi tìm kiếm theo dòng (trước đây mỗi phím dựng lại ~42.000 chuỗi).

Vì sao **không** chữa bằng cách cắt trang phía server: bảng, dòng thời gian và 4
thẻ KPI của màn này buộc phải mô tả **cùng một tập** (ghi rõ trong
`movement-trace-api.ts`). Cắt trang ở server sẽ phá đúng bất biến đó. Cắt phần
được VẼ thì không.

---

## 4. Những gì đã kiểm và thấy KHÔNG phải vấn đề

Ghi lại để lần sau không phải đi lại đường cũ:

- **N+1 nghiêm trọng: không có.** Quét 30 endpoint ở đường cold-cache: cao nhất
  là `/reports/dashboard-overview` với 26 truy vấn / 23 hình dạng khác nhau —
  tức là 23 phép tổng hợp khác nhau, không phải một vòng lặp.
- **Nén response: đã có.** `Content-Encoding: gzip` trên API.
- **Preflight CORS: đã có `Access-Control-Max-Age: 3600`.**
- **Chart/xlsx: đã lazy.** Chunk `react-apexcharts` (888 KB) và `vendor-xlsx`
  (282 KB) không nằm trong đồ thị tải của trang đăng nhập.
- **Các màn vận hành (kho, SKU, nhập, xuất, nhật ký, phân quyền, PV): nhẹ**,
  537–1.094 node DOM, đều phân trang phía server.
- **Route cache / config cache / event cache / opcache: đã bật.** Thử tắt
  `opcache.validate_timestamps` không cải thiện — không phải chi phí stat file.
- **Chỉ mục DB:** các bảng nóng đã có nhiều chỉ mục (`stock_movements` 10,
  `item_instances` 14, `physical_codes` 20). **Không thêm chỉ mục nào** vì chưa
  có bằng chứng seq-scan trên khối lượng thật (xem §6).

---

## 4b. Độ phủ cache — ĐO, không suy từ code

Commit gần nhất của BE ghi "phủ cache cho toàn bộ endpoint đọc". **Đo lại thì
không đúng.**

Cách đo (`scripts/perf/cache-coverage.mjs`): với mỗi endpoint đọc — xoá sạch cache,
gọi một lần, đếm số khoá MỚI xuất hiện trong Valkey. Có khoá mới ⇒ endpoint đó
thật sự ghi cache. Đo hành vi runtime nên bắt được cả những chỗ cache ở tầng
repository mà phân tích tĩnh ở tầng controller đếm thiếu.

| | |
|---|---|
| Endpoint đọc đo được (trả 200, không tham số đường dẫn) | **84** |
| Thật sự ghi cache | **55 (65,5 %)** |
| Không ghi cache | **29** |

29 endpoint không ghi cache, phân loại:

**A. ĐÚNG khi không cache — trạng thái vận hành đang sống.** Cache ở đây là hiển
thị cho người vận hành một hàng đợi công việc đã cũ:
`/scan/events`, `/scan/batches`, `/putaway/tasks`, `/putaway-tasks`,
`/putaway/history`, `/inbound-documents`, `/warranty-cases`,
`/warranty-component-issues`, `/component-issue-documents`, `/item-instances`,
`/containers`, `/physical-codes`, `/label-print-events`, `/import-batches`,
`/outbound-request-imports`, `/document-attachments/pending-evidence`,
`/mini-app/*` (6 endpoint).

**B. ĐÚNG khi không cache — phải luôn tươi:** `/health`, `/auth/me`.

**C. Cân nhắc riêng:** `/audit-logs`, `/audit-logs/me`. Nhật ký kiểm toán phục vụ
tra soát; trả bản cũ có thể làm người tra soát kết luận sai. Để nguyên là lựa chọn
có lý, không phải sót.

**D. ỨNG VIÊN cache nhưng CHƯA làm — và vì sao:** `/warehouses`,
`/printer-configs`, `/mini-app/printer-configs`, `/users`, `/reports/hub`. Đây là
dữ liệu tham chiếu, đổi rất ít, đọc nhiều. **Không thêm cache trong đợt này** vì
đo cho thấy chúng đã nhanh (warm ~100 ms) và phần lớn thời gian đó là chi phí
framework, không phải SQL — thêm cache chỉ tiết kiệm vài ms SQL trong khi nút thắt
thật ở tải cao là **CPU của PHP** (§ LOAD_TEST_2026-09-06.md). Cache thêm ở đây
là thêm bề mặt vô hiệu hoá phải bảo trì mà gần như không đổi được số đo.

## 5. Không đổi hành vi nghiệp vụ

- Không endpoint nào đổi shape response.
- Không đổi TTL, khoá cache, hay quy tắc vô hiệu hoá cache nào đang có.
- `SchemaCache` trả **đúng cùng câu trả lời** với `Schema::` — có test so khớp.
- Cửa sổ vẽ chỉ giới hạn số phần tử ĐƯỢC VẼ; mọi con số công bố trên màn hình
  vẫn tính trên trọn tập đã lọc, và số tổng luôn hiển thị.
- Đường console/artisan/migration giữ nguyên 100% hành vi cũ.

---

## 6. Chưa đạt / chưa chứng minh được

| Mục | Trạng thái | Vì sao |
|---|---|---|
| Chịu tải 3.000 người đồng thời | xem §7 của `LOAD_TEST_REPORT.md` | Máy dev sinh tải và chạy hệ thống trên cùng một CPU |
| Hành vi ở khối lượng dữ liệu thật | **NOT VERIFIED** | DB dev có 7.350 `stock_movements`; `wms_hoanam_perf` còn nhỏ hơn (3.239). Không đủ để kết luận về kế hoạch truy vấn ở quy mô production |
| `/reports/movement-trace` ở quy mô production | **RỦI RO CÒN LẠI** | Payload tăng tuyến tính theo số biến động trong kỳ. 5.956 dòng = 4,3 MB hôm nay. Cần chuyển KPI + dòng thời gian sang tổng hợp phía server rồi mới cắt trang được bảng — thay đổi hợp đồng API, ngoài phạm vi đợt này |
| Nén + cache asset tĩnh của FE | **NGOÀI REPO** | Không có nginx/Dockerfile của FE trong repo; do hạ tầng cấu hình |

## 7. Sẵn sàng chạy nhiều instance (load balancing) — ĐẠT với hồ sơ chịu tải, còn 1 chặn nhỏ (cập nhật 07/09/2026)

Đã kiểm từng mảnh trạng thái:

| Mảnh | Trạng thái | Ghi chú |
|---|---|---|
| Phiên đăng nhập | ✅ dùng chung | JWT stateless + `SESSION_DRIVER=redis` |
| Cache ứng dụng | ✅ dùng chung | `CACHE_STORE=redis`, khoá mang scope + actor |
| Hàng đợi job | ✅ dùng chung | `QUEUE_CONNECTION=redis`, worker là container riêng |
| Scheduler | ✅ một tiến trình riêng | không nhân bản theo instance web |
| Health check | ✅ | `/up` và `/api/v1/health` |
| Trần worker ↔ trần kết nối DB | ✅ **đã sửa hôm nay** | xem LOAD_TEST_2026-09-06.md §3 |
| **File export báo cáo** | ✅ **đã mở (07/09)** | hồ sơ chịu tải đặt `REPORT_EXPORT_DISK=s3` cho cả backend lẫn queue; bucket MinIO `wms-hoanam` nay do service `minio-init` tạo, đã kiểm `put()`=true. **11/09**: không đặt biến thì mặc định nay là `database` (bảng `report_export_files`, dùng chung mọi tiến trình); tải về không đọc được file thì tự dựng lại vào database |
| File import SKU (tệp tải lên) | ✅ **không phải chặn** | đọc lại kỹ: `store` → parse → `finally delete` trong CÙNG một request, tệp tạm chỉ cần sống trên instance đang xử lý. Kết luận "chặn" hôm 06/09 là sai |
| File CSV chi tiết lỗi import | ✅ **đã mở (07/09)** | `config/wms_import.php` → `IMPORT_ERROR_FILE_DISK` (mặc định `local`); ghi (`ImportErrorFileService::diskName()`), tải về (`ImportReportController`) và dọn dẹp (`PurgeExpiredImportErrorFiles`) cùng đọc một chỗ. Hồ sơ chịu tải đặt `s3` cho backend + queue + **scheduler** (lệnh dọn dẹp chạy ở scheduler — trước đó `REPORT_EXPORT_DISK` cũng thiếu ở scheduler, đã thêm) |
| Ảnh bảo hành | ✅ **đã mở (07/09)** | trước ghi cứng `'local'`; nay `config('warranty.photo_disk')` ← `WARRANTY_PHOTO_DISK` (video đã có `WARRANTY_VIDEO_DISK`). Mỗi bản ghi lưu `storage_disk` riêng nên file cũ vẫn đọc đúng đĩa cũ |
| Chứng từ đính kèm | ✅ đã cấu hình được từ trước | `config('documents.disk')` ← `DOCUMENT_ATTACHMENT_DISK`; hồ sơ chịu tải đặt `s3` |
| Hâm nóng `bootstrap/cache` khi có N replica | ✅ **đã sửa (07/09)** | `perf-entrypoint.sh` khoá bằng `mkdir` (nguyên tử trên bind mount chung); replica khác chờ ≤ 90 s, khoá bỏ hoang thì tự tiếp quản. Đã chạy thử cả hai nhánh |
| Maintenance mode | ⚠️ | `APP_MAINTENANCE_DRIVER=file` — `artisan down` chỉ tác dụng trên MỘT instance |

### Chặn 1 — file export nằm trên đĩa của từng instance

`GenerateReportExportJob` GHI file (tiến trình queue) còn
`OperationalReportController::exportDownload` ĐỌC file (tiến trình web). Cả hai
trước đây dùng cứng `Storage::disk('local')`. Nó chỉ chạy được vì hai container
đang bind-mount cùng một thư mục trên máy chủ. Sau load balancer, request tải về
rơi vào instance khác sẽ nhận 404 cho một file có thật.

Đã làm: tách ra thành `config/wms_export.php` → `REPORT_EXPORT_DISK`, dùng chung
cho cả ba nơi (job ghi, controller đọc, lệnh dọn dẹp). **Mặc định vẫn `local`** —
không đổi hành vi. Triển khai nhiều instance chỉ cần trỏ biến này sang đĩa dùng
chung.

**06/09 chưa bật mặc định sang `s3`** vì bucket `wms-hoanam` chưa được tạo (mọi thao
tác lỗi `NoSuchBucket`). **07/09**: service `minio-init` (do một phiên khác thêm) tạo
bucket lúc `docker compose up`; kiểm bằng lệnh ghi thật trả `true`. Hồ sơ chịu tải
(`docker-compose.perf.yml`) nay đặt `REPORT_EXPORT_DISK=s3` cho cả `backend` lẫn
`queue`; stack dev giữ `local`. Môi trường mới (R2 thật) vẫn phải kiểm lệnh ghi
trước khi bật.

### Chặn 2 (đính chính 07/09) — tệp import KHÔNG phải chặn; tệp CSV lỗi mới là chặn

Hôm 06/09 ghi "import SKU đọc bằng đường dẫn hệ thống tệp nên chặn multi-instance".
Đọc lại toàn bộ luồng: `ImportController::importExcel` gọi `$file->store('temp/imports',
'local')`, parse bằng PhpSpreadsheet, rồi `finally { $localDisk->delete($path); }` —
**tất cả trong một request**, trên một instance. Tệp tạm không bao giờ cần tới instance
khác. Kết luận cũ sai; không cần đổi luồng nhập liệu.

Cái thật sự còn chặn là **tệp CSV chi tiết lỗi**: `ImportErrorFileService` ghi vào
`DISK = 'local'` trong request import, còn `ImportReportController::downloadErrorFile`
đọc ở một request SAU — sau load balancer có thể rơi vào instance khác ⇒ 404 cho file
có thật. **Đã sửa cùng ngày** theo đúng mẫu export: `config/wms_import.php` →
`IMPORT_ERROR_FILE_DISK`, ba nơi ghi/đọc/dọn gọi `ImportErrorFileService::diskName()`;
hồ sơ chịu tải trỏ sang `s3` cho backend, queue và scheduler. Test dọn dẹp
(`Wwhn330ImportErrorFilePurgeTest`) đổi theo.

### Lỗi kèm theo đã sửa

Trong lúc thử đổi đĩa, lộ ra `GenerateReportExportJob` **không kiểm giá trị trả về
của `put()`**. `put()` trả `false` khi ghi hỏng chứ không ném lỗi, nên job vẫn tự
chuyển sang READY cho một file chưa bao giờ được ghi; người dùng chỉ phát hiện lúc
bấm Tải về. Đã sửa: ghi hỏng thì job FAILED kèm lý do.
