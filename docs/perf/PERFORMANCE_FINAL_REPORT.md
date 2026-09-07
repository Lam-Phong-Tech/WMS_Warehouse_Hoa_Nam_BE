# PERFORMANCE_FINAL_REPORT — Hoà Nam WMS

**Ngày:** 07/09/2026 · **Phạm vi:** `C:\LAM_PHONG_TECH` (BE Laravel 13 + FE React 19)
**Kết luận:** **PARTIALLY PASSED** — giải thích bằng số ở mục 6.

---

## 1. Bối cảnh phần cứng (đọc trước mọi con số)

| Hạng mục | Giá trị |
|---|---|
| Máy | Windows 11 + Docker Desktop, VM **16 vCPU / 7,98 GB RAM** |
| Trình sinh tải | k6 trong container, **CÙNG MÁY** với hệ thống bị đo |
| Backend | 2 replica php-fpm (48 worker/replica), nginx, mã phục vụ từ `/srv/app` trong container |
| CSDL | PostgreSQL 16 sau **PgBouncer** (session pooling, pool 100), `max_connections=120` |
| Cache | Valkey 8, 256 MB, kết nối bền, timeout 2 s |
| Dữ liệu | DB dev: 7.350 `stock_movements`, 4.549 `item_instances` — **nhỏ hơn production nhiều** |
| Frontend | build production, phục vụ tĩnh; đo bằng Chromium/Playwright 1440×900 |

Ba giới hạn khiến đây là **LOCAL PERFORMANCE RESULT**, không phải năng lực
production: k6 chung máy, dữ liệu dev, và bộ đo chỉ có request đọc.

---

## 2. Bảng tổng kết theo yêu cầu

| Metric | Before | After | Target | Status |
|---|---|---|---|---|
| API p95 (**200 req/s** giữ 3 phút) | 1,52 s | **172 ms** | ≤ 1 s | **PASS** |
| API p99 (cùng mức tải) | 2,19 s | **262 ms** | ≤ 1,5 s | **PASS** |
| API p50 (cùng mức tải) | 995 ms | **68 ms** | thấp nhất có thể | **PASS** |
| Tỉ lệ lỗi (cùng mức tải) | 19,87 % | **0,00 %** (0/45.149) | < 0,1 % | **PASS** |
| `dropped_iterations` (cùng mức tải) | 7.531 | **0** | 0 | **PASS** |
| Thông lượng bền vững | 45,1 req/s | **200 req/s giữ được** | — | đo được |
| Trần thông lượng tuyệt đối | ~120–130 req/s | **~200–205 req/s** | — | đo được |
| Người dùng đồng thời phục vụ được (think 20 s) | ~900 | **~4.000** | ≥ 10.000 | **NOT PASSED** |
| Người dùng đồng thời (think 40 s, nhân viên kho thật) | ~1.800 | **~8.000** | ≥ 10.000 | **NOT PASSED** (còn thiếu ~25 %) |
| LCP (CPU x1) | CHƯA ĐO | **432–640 ms** | ≤ 1,5 s | **PASS** |
| LCP (CPU x4, máy phổ thông) | CHƯA ĐO | 1.008–1.972 ms | ≤ 1,5 s | **FAIL ở 2/7 trang** (dashboard, báo cáo XNT) |
| CLS | CHƯA ĐO | **0,000–0,003** | ≤ 0,1 | **PASS** |
| INP (CPU x1) | 14.666 ms (màn Truy vết) | **24–72 ms** | ≤ 100 ms | **PASS** |
| INP (CPU x4, máy phổ thông) | — | 88–360 ms | ≤ 100 ms | **FAIL ở 3/4 thao tác** |
| Request chậm > 500 ms ở 150 req/s (6 phút) | 4.790 trên 28 route | **15 trên 7 route** | — | **PASS** |

---

## 3. Backend — trước/sau

### 3.1 Độ trễ khi rảnh (một người dùng)

| Endpoint | Trước | Sau | Cải thiện |
|---|---|---|---|
| `/api/v1/health` | 190–210 ms | **30–45 ms** | ~78 % |
| `/skus?page=1&per_page=20` | 162–196 ms | **75–124 ms** | ~45 % |
| `/inventory?page=1&per_page=20` | 217–225 ms | **170–207 ms** | ~13 % |
| `/auth/me` | — | **60–63 ms** | — |
| `/reports/dashboard-overview` (ấm) | — | **76–197 ms** | — |

### 3.2 Chịu tải (mô hình mở, k6 cùng máy, 2 replica)

| Mức tải | Trước (hồ sơ perf chưa sửa) | Sau (đủ các bản sửa) |
|---|---|---|
| 94 req/s | 45,1 req/s · p95 1,52 s · lỗi 19,87 % · 7.531 dropped | **78,5 req/s · p95 91 ms · lỗi 0,11 % · 0 dropped** |
| 150 req/s | 109,2 req/s · p95 1,01 s · lỗi 12,82 % · 701 dropped | **giữ được · p95 314 ms · p99 563 ms · lỗi 0,003 % · 0 dropped** |
| 200 req/s | 146,7 req/s · lỗi 10,77 % · 2.930 lỗi 5xx | **giữ được · p95 172 ms · p99 262 ms · lỗi 0,00 % · 0 dropped** |
| Điểm gãy | ~120–130 req/s | **giữa 200 và 250 req/s** |
| Trần thông lượng tuyệt đối | ~120–130 req/s | **~200–205 req/s** |

Thang bậc đầy đủ (mỗi mức giữ 3 phút) ở `LOAD_TEST_10000_USERS.md` §3.2:
100 **ĐẠT** · 150 **ĐẠT** · 200 **ĐẠT** · 250 **KHÔNG ĐẠT** · 300 **KHÔNG ĐẠT**.

### 3.3 Cô lập từng tầng (đầu dò 40 người dùng đồng thời)

| Đích | Trực tiếp tới PostgreSQL | Qua PgBouncer |
|---|---|---|
| `/health` | 148 req/s · p50 234 ms | **237 req/s · p50 159 ms** |
| `/products` (auth + DB) | 97 req/s · max 54 s | **189 req/s · max 4,4 s** |

### 3.4 Tài nguyên ở mức 150 req/s

| Thành phần | Trước | Sau |
|---|---|---|
| CPU của VM | 96 % (idle 4 %) | 61–80 % |
| CPU PostgreSQL | ~500 % (5 lõi) | **46–98 %** |
| `listen queue` của php-fpm | 574–771 | **0** |
| Kết nối PostgreSQL | 40 (mới mỗi request) | pool 100, tái dùng |
| Request > 500 ms trong 6 phút | 4.790 | **15** |

Phân rã 15 request chậm còn lại: **SQL 30 % · chờ lock cache 6 % · còn lại (CPU) 64 %**
⇒ nút thắt còn lại là **CPU của PHP**, không phải cơ sở dữ liệu.

---

## 4. Frontend — trước/sau

### 4.1 Tương tác (đo bằng Playwright trên build production)

| Chỉ số | Trước đợt 1 | Sau đợt 1 | Sau đợt 2 |
|---|---|---|---|
| INP khi gõ một phím (màn Truy vết) | **14.666 ms** | 9–34 ms | 9–34 ms |
| Số node DOM của màn đó | 293.514 | 3.292 | 3.292 |
| Quay lại màn báo cáo đã mở (CPU x1) | — | 0,7–0,9 s | **36–136 ms**, 0 request |
| Bấm tab trong màn báo cáo (CPU x1) | — | ~930 ms khoá luồng chính | **trung vị 106 ms, p90 167 ms** |
| Chunk tải khi bấm menu | 1–38 | 1–38 | **0** |

### 4.2 Core Web Vitals (số đo thật, bảng đầy đủ ở `FRONTEND_PERFORMANCE_REPORT.md` §4b)

| | CPU x1 (máy dev) | CPU x4 (máy văn phòng phổ thông) |
|---|---|---|
| **LCP** | 432–640 ms — **ĐẠT toàn bộ 7 trang** | 1.008–1.972 ms — **HỎNG ở 2 trang**: /dashboard 1.972 ms, /reports/inventory-flow 1.844 ms |
| **CLS** | 0,000–0,003 — **ĐẠT** | 0,000 — **ĐẠT** |
| **INP** | 24–72 ms — **ĐẠT toàn bộ** | 88–360 ms — **HỎNG ở 3/4 thao tác** (bấm tab 208 ms, mở modal 320 ms, gõ ô lọc 360 ms) |
| TBT | 0–13 ms | 126–694 ms |
| TTFB | 3–10 ms (phục vụ tĩnh cục bộ — **không phải TTFB production**) | 3–10 ms |

Nguyên nhân phần chưa đạt là **thời gian CPU dựng hình**, không phải mạng (TBT
187–694 ms trong khi TTFB 3–10 ms). Ba việc còn lại, chưa làm: cắt bộ icon font
3.586 glyph xuống ~56 icon dùng thật (155 KB CSS chặn render + 386 KB woff2),
gỡ runtime antd khỏi đồ thị tải đầu, tách từ điển tiếng Việt khỏi chunk vào.

**Không có đối chứng "trước"** cho LCP/CLS vì bộ đo chưa tồn tại ở mốc nền — thiếu
sót của đợt này, đã ghi vào `PERFORMANCE_BASELINE.md` §5.4. Chỉ INP có số trước
(14.666 ms).

---

## 5. Những gì đã sửa

Chi tiết đầy đủ (file, nguyên nhân, số đo, rủi ro) ở `PERFORMANCE_CHANGELOG.md`.

| # | Thay đổi | Đóng góp đo được |
|---|---|---|
| BE-01 | Phục vụ mã từ filesystem container thay vì bind mount | 45,1 → 78,5 req/s; lỗi 19,87 % → 0,24 % |
| BE-02 | **PgBouncer (session pooling)** | `/health` 40 VU 148 → 237 req/s; CPU PostgreSQL ~500 % → 46–98 % |
| BE-03 | Chỉ mục thời gian cho `stock_movements` | EXPLAIN: `Sort + Seq Scan` → `Index Scan` |
| BE-04 | Khoá cache `/inventory` mang chữ ký phạm vi kho | **sửa RÒ DỮ LIỆU** giữa các phạm vi |
| BE-05 | Kết nối Valkey bền + timeout 2 s | không tách được riêng |
| BE-06 | `pm.max_requests` 1000 → 5000 | không tách được riêng |
| BE-07 | Log request chậm + trang trạng thái php-fpm | công cụ tìm ra BE-02 |
| BE-08 | `CREATE EXTENSION pg_stat_statements` | xếp hạng được truy vấn |
| BE-09 | Bộ đo: chỉ số theo nhóm, tách luồng công khai, 3 công cụ phân tích | 429 giả tạo 613 → 0 |
| **BE-10** | **nginx: bỏ loại upstream thụ động (`max_fails=0`) + `worker_processes 4` + `keepalive 8`** | **ở 200 req/s: lỗi 10,77 % → 0,00 %; 2.930 lỗi 5xx → 0; p95 713 → 172 ms; p99 966 → 262 ms; dropped 897 → 0. Mức 200 req/s chuyển từ KHÔNG ĐẠT sang ĐẠT** |
| FE-09 | Không gọi `POST /auth/refresh` trên trang công khai khi không có dấu vết phiên | bỏ một request CHẮC CHẮN HỎNG (422) khỏi đường tải trang đăng nhập |
| FE-10 | Tải song song shell + chunk trang đích ngay lúc khởi động | bỏ 2 chặng nối tiếp khỏi mỗi lần F5 ở trang cần đăng nhập |
| FE-01…08 | Nạp trước chunk, giữ sống màn báo cáo, overlay theo trạng thái trang, `useDeferredValue`, chart lười, `content-visibility`, `/skus/lookup`, cuộn về đầu | xem mục 4.1 |

**Không có thay đổi nào** đụng vào nghiệp vụ, RBAC, validation, transaction,
idempotency hay audit log. Một thay đổi (BE-04) **sửa** một lỗi đúng-sai về phạm vi
dữ liệu.

---

## 6. Kết luận: PARTIALLY PASSED

**Đạt:**

- Mọi ngưỡng SLO về độ trễ và lỗi ở mức tải **200 req/s giữ liên tục 3 phút**
  (≈ 4.000 người dùng ở think-time 20 s, ≈ **8.000 người ở think-time 40 s** —
  think-time thật của nhân viên kho): **p50 68 ms, p95 172 ms, p99 262 ms,
  max 663 ms, 0,00 % lỗi, 0 iteration bị bỏ**.
- Điểm nghẽn P0 của backend đã xử lý và có bằng chứng trước/sau cho từng cái.
- Một lỗi rò dữ liệu giữa phạm vi kho được tìm ra và sửa.
- Hệ thống stateless đủ để scale ngang: đã chạy thật 2 replica sau nginx, nginx chia
  đều (10/10 trên 20 request), mọi file dùng chung đã lên MinIO/S3.
- **Kiểu hỏng khi quá tải đã đổi từ MẤT DỊCH VỤ sang CHẬM DẦN có kiểm soát**:
  trước đây vượt ngưỡng là nginx tự loại cả hai backend và trả 502 hàng loạt
  (2.930 lỗi); nay quá tải chỉ làm hàng đợi dài ra, 0 lỗi.
- **Core Web Vitals trên máy dev đạt toàn bộ**: LCP 432–640 ms, CLS ≤ 0,003,
  INP 24–72 ms.

**Chưa đạt:**

- **10.000 người dùng đồng thời: NOT PASSED.** Máy đo bão hoà ở ~200–205 req/s;
  10.000 người cần **250 req/s** (think 40 s) đến 1.250 req/s (think 8 s). Ở kịch
  bản thực tế nhất (think 40 s) hệ thống hiện đạt **8.000/10.000 người — thiếu
  ~25 % năng lực**, tương đương thêm một node ứng dụng. Ước lượng hạ tầng ở
  `LOAD_TEST_10000_USERS.md` §5 — **là ngoại suy, chưa kiểm chứng**.
- **Đường ghi chưa được đo dưới tải** (Post Receipt/Issue, Putaway): bộ đo chỉ đọc.
  Vì vậy **chưa kiểm chứng** được tính đúng dữ liệu khi đồng thời (lost update,
  trừ tồn hai lần, oversell).
- **Soak và spike chưa chạy** ⇒ chưa loại trừ rò rỉ bộ nhớ/kết nối và hiện tượng
  chậm dần.
- **Khối lượng dữ liệu production chưa mô phỏng** ⇒ kế hoạch truy vấn ở quy mô thật
  chưa biết.
- **Web Vitals trên máy phổ thông (CPU x4) CHƯA ĐẠT**: LCP hỏng ở 2/7 trang,
  INP hỏng ở 3/4 thao tác. Đây là bài kiểm đúng với thiết bị thật của nhân viên kho.
- **68/74 phát hiện của audit chưa được phản biện độc lập** (phiên chạm hạn mức).

**Không được phép kết luận** từ tài liệu này rằng hệ thống "sẵn sàng cho 10.000
người dùng". Điều đã chứng minh: **mã nguồn hiện tại phục vụ ~4.000–8.000 người dùng đồng
thời trên một máy 16 vCPU** và không có điểm nghẽn kiến trúc nào cản việc scale
ngang — phần còn lại là bài toán hạ tầng cộng với các hạng mục "chưa đạt" ở trên.

---

## 7. Danh sách tài liệu/bằng chứng đã tạo

| File | Nội dung |
|---|---|
| `docs/perf/PERFORMANCE_BASELINE.md` | Mốc nền: môi trường, dữ liệu, số đo trước |
| `docs/perf/PERFORMANCE_FINAL_REPORT.md` | Tài liệu này |
| `docs/perf/PERFORMANCE_BOTTLENECKS.md` | 74 phát hiện có bằng chứng file:dòng, kèm mức tin cậy |
| `docs/perf/PERFORMANCE_CHANGELOG.md` | Từng thay đổi: file, nguyên nhân, số đo, rủi ro |
| `docs/perf/API_PERFORMANCE_REPORT.md` | Kiểm kê 352 route, phân loại FAST/ACCEPTABLE/WARNING/FAIL |
| `docs/perf/DATABASE_PERFORMANCE_REPORT.md` | Bảng/chỉ mục/truy vấn, EXPLAIN trước-sau, `pg_stat_statements` |
| `docs/perf/CACHE_PERFORMANCE_REPORT.md` | Kiểm kê cache, số đo Valkey, stampede, invalidation |
| `docs/perf/LOAD_TEST_10000_USERS.md` | Kịch bản, kết quả, phân loại lỗi, ước lượng năng lực |
| `docs/perf/LOAD_TEST_2026-09-07.md` | Vì sao hồ sơ chịu tải từng chậm hơn stack dev |
| `FE/docs/perf/FRONTEND_PERFORMANCE_REPORT.md` | Theo màn hình: API, gói JS, render, Web Vitals |
| `FE/docs/perf/FE_PERF_AUDIT_2026-09-06.md` §7 | Đợt tối ưu FE lần 2 |
| `load/README.md` | Cách chạy lại mọi phép đo |
| `load/results/*.json` | Dữ liệu thô của từng lượt k6 |
| `build/perf/*.json` | Dữ liệu thô của các phép đo FE |

---

## 8. Trạng thái môi trường khi kết thúc đợt (07/09/2026)

Trong lúc làm việc có **nhiều phiên Claude sửa song song trên cùng working tree**.
Một phiên khác đã chạy `docker compose up` (KHÔNG kèm overlay) ở cuối đợt, nên stack
đang chạy hiện là **stack dev**: 1 replica, nginx `worker_processes auto`,
`DB_HOST=postgres` (không qua PgBouncer), mã phục vụ từ bind mount.

**Đó là hành vi ĐÚNG như thiết kế** — mọi tinh chỉnh chịu tải nằm trong file overlay
riêng và không tự nạp. Mọi số đo trong tài liệu này được lấy khi overlay ĐANG bật;
các file cấu hình vẫn nguyên vẹn (`max_fails=0`, `worker_processes 4`, service
`pgbouncer`, `PERF_LOCAL_CODE`).

Bật lại hồ sơ chịu tải:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.perf.yml up -d --scale backend=2
```

**Hai cái bẫy đã gặp, ghi lại để không lặp:**

1. `docker compose ... up -d <service>` cho MỘT service sẽ đưa các service khác về
   scale mặc định — `backend` tụt từ 2 xuống 1 replica giữa lúc đo. Luôn kèm
   `--scale backend=N` và kiểm `docker ps | grep backend` trước khi tin số đo.
2. Container chạy test dùng chung `bootstrap/cache` trên bind mount với container
   đang chịu tải; `config:clear` của bên này xoá cache của bên kia. Hồ sơ chịu tải
   nay phục vụ mã từ `/srv/app` nên không còn dính, nhưng stack dev thì vẫn.

## 9. Việc nên làm tiếp, theo thứ tự lợi ích/rủi ro

| # | Việc | Vì sao | Rủi ro |
|---|---|---|---|
| 1 | Kịch bản k6 có ĐƯỜNG GHI (Post Receipt/Issue/Putaway) trên bản sao dữ liệu | Chưa hề đo đường ghi ⇒ chưa biết tính đúng dữ liệu khi đồng thời | Phải chạy trên `PERF_DB_DATABASE`, không đụng dữ liệu dev |
| 2 | Chạy soak ≥ 1 giờ ở 150 req/s | Chưa loại trừ rò rỉ bộ nhớ/kết nối và chậm dần | Thấp |
| 3 | Cắt bộ icon font (3.586 → ~56 ký tự) | 155 KB CSS chặn render + 386 KB font trên MỌI trang; là nguyên nhân LCP hỏng ở máy chậm | Cần script sinh subset ở bước build |
| 4 | Năm endpoint báo cáo trả trọn tập không giới hạn trang (API-01) | 1,0–4,5 MB mỗi lần gọi; sẽ vỡ ở khối lượng production | Phải sửa cả FE lẫn BE, đổi hợp đồng |
| 5 | Đo lại trên khối lượng dữ liệu giống production | Kế hoạch truy vấn ở quy mô thật chưa biết | Cần môi trường riêng |
| 6 | Phản biện độc lập 68 phát hiện còn lại của audit | Chỉ 6/74 đã qua vòng bác bỏ | Thấp |
| 7 | Cân nhắc Laravel Octane | ~53 ms CPU/request phần lớn là khởi động framework | **Cao** — đổi mô hình chạy; phải rà mọi biến `static` (đã biết: `SchemaCache`, `CacheService::$lockWaitMs`) |
