# LOAD_TEST_10000_USERS — Hoà Nam WMS

**Ngày:** 07/09/2026 · **Kết luận ngắn:** **NOT PASSED trên phần cứng đo được** —
và đây là con số thật, không phải phỏng đoán.

---

## 0. Trả lời thẳng câu hỏi

> "Hệ thống có phục vụ được 10.000 người dùng đồng thời không?"

**Chưa chứng minh được, và trên MÁY ĐANG ĐO thì chưa tới.** Máy đo (VM Docker
16 vCPU / 8 GB, k6 chạy **cùng máy**) giữ được **200 request/giây** với mọi ngưỡng
SLO đạt (p95 172 ms, p99 262 ms, 0 % lỗi, 0 lượt bị bỏ), và bão hoà ở
**~200–205 req/s**. 10.000 người dùng cần:

| Think-time trung bình | Request/giây cần | Máy đo hiện tại |
|---|---:|---|
| 8 giây (bấm liên tục) | 1.250 | **không đạt** — gấp ~6 lần năng lực đo được |
| 20 giây | 500 | **không đạt** — gấp ~2,5 lần; hiện phục vụ được ~4.000 người |
| **40 giây** (quét mã, đi lại, đọc màn hình — nhân viên kho thực tế) | 250 | **gần đạt** — hiện phục vụ được **~8.000/10.000 người**, thiếu ~25 % năng lực (≈ thêm một node ứng dụng) |
| 60 giây (luồng quét–đi–quét) | 167 | **đã vượt** — năng lực hiện tại tương đương ~12.000 người |

Ước lượng hạ tầng cần thiết nằm ở mục 5. Nó là **PHÉP NGOẠI SUY TỪ SỐ ĐO**, không
phải kết quả đã kiểm chứng — mục 6 nói rõ phải làm gì để biến nó thành bằng chứng.

---

## 1. Bối cảnh phần cứng (bắt buộc đọc trước mọi con số)

| Hạng mục | Giá trị |
|---|---|
| Máy chủ | Windows 11 + Docker Desktop, VM **16 vCPU / 7,98 GB RAM** |
| Trình sinh tải | k6 trong container, **CÙNG MÁY** với hệ thống |
| Backend | 2 replica php-fpm (`pm.max_children=48` mỗi replica), nginx, mã phục vụ từ `/srv/app` |
| CSDL | PostgreSQL 16, `max_connections=120`, **qua PgBouncer** (session pooling, pool 100) |
| Cache | Valkey 8, 256 MB, `volatile-lru`, kết nối bền (pconnect), timeout 2 s |
| Khối lượng dữ liệu | 7.350 `stock_movements`, 4.549 `item_instances`, 293 `inventory_balances` — **nhỏ hơn production rất nhiều** |

**Ba giới hạn khiến kết quả này KHÔNG được dùng để tuyên bố năng lực production:**

1. k6 giành CPU với chính hệ thống bị đo ⇒ mọi con số là **cận dưới**.
2. Khối lượng dữ liệu là dữ liệu dev, không phải production.
3. Bộ đo **chỉ có request đọc** — đường ghi (Post Receipt / Post Issue / Putaway)
   chưa được đo dưới tải. Xem mục 7.

---

## 2. Mô hình tải

- **Mô hình mở** (`ramping-arrival-rate`): giữ đúng số request/giây bất kể hệ
  thống nhanh hay chậm, nên điểm quá tải hiện ra thay vì bị che.
- **Mix nghiệp vụ** (`load/k6/lib/flows.js`, 31 endpoint có trọng số): bảng điều
  khiển 20 %, tồn kho/sổ cái 25 %, dữ liệu nền 18 %, chứng từ nhập/xuất/bảo hành
  19 %, báo cáo nặng 12 %, quản trị 4 %, thông báo + phiên 13 %.
- **Nhiều vai trò cùng lúc**: `setup()` đăng nhập một pool tài khoản
  (`admin, manager01, keeper01, staff01, approver01, viewer01`) và mỗi iteration
  bốc ngẫu nhiên một token ⇒ RBAC, phạm vi kho và cache theo scope đều bị đụng
  đồng thời, không phải chạy tuần tự từng vai.
- **Luồng công khai bị loại** (`INCLUDE_PUBLIC=0`) trong các lượt chuẩn: chúng đi
  qua `throttle:pv-public` 120 req/phút **theo IP**, mà k6 phát từ một IP ⇒ 429 giả
  tạo của bộ đo. Loại đi thì tỉ lệ lỗi mới phản ánh hệ thống.

---

## 3. Kết quả đo

### 3.1 Toàn bộ các lượt (mọi số từ `load/results/*.json`)

| Lượt | Ngày | Tải đích | Đạt được | p50 | p95 | p99 | Lỗi | dropped |
|---|---|---|---|---|---|---|---|---|
| smoke 5 VU | 06/09 | — | 2,2 req/s | 219 ms | 452 ms | 623 ms | 0,00 % | 0 |
| baseline, hồ sơ perf **trước khi sửa** | 07/09 | 94 req/s | 45,1 req/s | 995 ms | 1,52 s | 2,19 s | **19,87 %** | 7.531 |
| baseline, 1 replica, mã trong container | 07/09 | 94 req/s | 78,5 req/s | 37 ms | 98 ms | 150 ms | 0,24 % | **0** |
| baseline, 2 replica, mã trong container | 07/09 | 94 req/s | 78,5 req/s | **32 ms** | **91 ms** | 149 ms | 0,11 % | **0** |
| target 3.000 người / think 20 s, 2 replica | 07/09 | 150 req/s | 116,6 req/s | 70 ms | 675 ms | 1,19 s | 2,36 % | 435 |
| target 3.000 người / think 20 s, **+ PgBouncer** | 07/09 | 150 req/s | 124,5 req/s | 50 ms | 562 ms | 991 ms | **0,59 %** | 340 |
| stress bậc thang 75→300 req/s | 07/09 | tới 300 req/s | 144,9 req/s (trung bình cả ramp) | 154 ms | 569 ms | 821 ms | 8,93 % | 4.629 |

### 3.2 Thang bậc tải giữ ổn định (mỗi mức giữ 3 phút, đủ VU cấp phát sẵn)

Đây là phép đo quyết định điểm gãy. Mỗi lượt giữ nguyên một mức tải trong 3 phút
(hồ sơ `baseline`, `INCLUDE_PUBLIC=0`, 2 replica, đủ các bản sửa):

| Mức giữ | Cấu hình | p50 | p95 | p99 | Lỗi | dropped | Đánh giá SLO |
|---:|---|---|---|---|---|---:|---|
| **100 req/s** | 2 replica, chưa sửa nginx | 33 ms | **80 ms** | **111 ms** | **0,00 %** | **0** | **ĐẠT** |
| **150 req/s** | 2 replica, chưa sửa nginx | 54 ms | **314 ms** | **563 ms** | **0,003 %** | **0** | **ĐẠT** |
| 200 req/s | 2 replica, **chưa** sửa nginx | 298 ms | 713 ms* | 966 ms* | **10,77 %** | 897 | **KHÔNG ĐẠT** (2.930 lỗi 5xx) |
| **200 req/s** | 2 replica, **đã** sửa nginx | 68 ms | **172 ms** | **262 ms** | **0,00 %** (0/45.149) | **0** | **ĐẠT** |
| **250 req/s** | 2 replica, đã sửa nginx | 304 ms | 26,17 s | 37,38 s | 0,00 % | 2.171 | **KHÔNG ĐẠT** (p95/p99) |
| **300 req/s** | 2 replica, đã sửa nginx | 305 ms | 36,62 s | 45,73 s | 0,00 % | 11.952 | **KHÔNG ĐẠT** |

\* p95/p99 của dòng "chưa sửa nginx" chỉ tính request THÀNH CÔNG — nhìn đẹp một
cách gây hiểu lầm vì 10,77 % request đã hỏng và không có mặt trong phân vị.

**Điểm gãy nằm giữa 200 và 250 req/s.** Trần thông lượng tuyệt đối đo được là
**~200–205 req/s** (mức 250 và 300 đều chỉ đạt 199,8 và 205,2 req/s) — đó là trần
CPU của máy đo; đẩy tải cao hơn chỉ làm hàng đợi dài ra.

**Lưu ý đọc bảng:** dòng 200 req/s "đã sửa nginx" có p95/p99 TỐT HƠN dòng 150 req/s
ở trên. Không phải nghịch lý — hai dòng đó khác cấu hình: dòng 150 đo trước khi sửa
nginx. Bản sửa nginx cải thiện toàn dải, không chỉ ở mức quá tải.

#### Vì sao gãy: nginx tự cắt dịch vụ, không phải backend chết

Ở mức 200 req/s, nginx ghi **2.917 dòng `no live upstreams`** — khớp gần đúng với
2.930 lỗi 5xx. Cả hai container backend vẫn sống và vẫn trả 200 cho request khác
trong cùng lúc đó. Cơ chế:

`max_fails=3 fail_timeout=10s` là kiểm tra sức khoẻ THỤ ĐỘNG. Khi quá tải, chỉ cần
3 request quá hạn là nginx đánh dấu một backend "chết" trong 10 giây. Với đúng hai
backend và không có dự phòng, loại một cái dồn toàn bộ tải sang cái còn lại ⇒ cái
đó cũng bị loại ⇒ **không còn upstream nào sống** ⇒ 502 cho MỌI request trong 10
giây, kể cả những request lẽ ra phục vụ được. Chậm tạm thời bị khuếch đại thành
mất dịch vụ hoàn toàn.

Đã sửa: `max_fails=0` cho hồ sơ chịu tải (quá tải thì XẾP HÀNG, không cắt dịch vụ),
kèm `worker_processes 4` + `keepalive 8` để tổng số kết nối keepalive nginx giữ
(4 × 8 = 32) nhỏ hơn tổng số child php-fpm (48 × 2 = 96) — php-fpm không có idle
timeout cho kết nối FastCGI đang giữ, nên mỗi kết nối dư là một child bị ghim.

#### A/B tại đúng điểm gãy (200 req/s, cùng máy, chỉ khác cấu hình nginx)

| Chỉ số | Trước | Sau | |
|---|---|---|---|
| Thông lượng đạt được | 146,7 req/s | **166,1 req/s** | +13 % |
| p50 | 298 ms | **68 ms** | −77 % |
| p95 | 713 ms | **172 ms** | −76 % |
| p99 | 966 ms | **262 ms** | −73 % |
| max | — | **663 ms** | |
| **Tỉ lệ lỗi** | **10,77 %** | **0,00 % (0/45.149)** | **hết hẳn** |
| Lỗi 5xx | 2.930 | **0** | |
| `no live upstreams` | 2.917 dòng | **0 dòng** | |
| dropped | 897 | **0** | **hết hẳn** |

Mức 200 req/s **chuyển từ KHÔNG ĐẠT sang ĐẠT toàn bộ ngưỡng SLO** chỉ nhờ đổi cấu
hình nginx — không sửa một dòng mã ứng dụng nào. Điều này cũng cho thấy con số
"điểm gãy" trước đó (150–200 req/s) là do lớp cân tải tự cắt dịch vụ chứ không
phải năng lực xử lý của hệ thống.

**Một đính chính về phương pháp:** lượt A/B đầu tiên chạy nhầm với MỘT replica
(lệnh recreate nginx đã đưa service backend về scale mặc định). Bảng trên là lượt
chạy lại với đủ hai replica. Bài học: sau mỗi lần `docker compose up` cho một
service riêng lẻ, phải kiểm lại số replica trước khi đo.

### 3.3 Đường đi tới điểm gãy (mẫu 10 giây trong lượt stress)

| Mức tải (trong lượt stress ramp) | CPU của VM | php-fpm `listen queue` | `active processes` | CPU Postgres | Nhận xét |
|---|---|---|---|---|---|
| 75 req/s | 37–50 % | **0** | 19–63 | 42–54 % | thoải mái |
| 150 req/s | 61–80 % | 0–6 | 49–140 | 67–98 % | bắt đầu xếp hàng |
| 225 req/s | 80 %+ | tăng dần | chạm 140+ | ~100 % | quá tải |
| 300 req/s | — | hàng trăm | chạm trần | — | timeout hàng loạt |

(Lượt stress ramp qua nhanh mỗi mức nên chỉ dùng để nhìn xu hướng tài nguyên; điểm gãy chính thức lấy từ thang bậc giữ ổn định ở §3.2.)

### 3.4 Phân loại lỗi (không gộp thành một con số "error rate")

| Loại | Số lượng | Nguồn |
|---|---|---|
| HTTP 429 (rate limit) | 613 → **0** sau khi loại luồng công khai | `throttle:pv-public` theo IP — giả tạo của bộ đo |
| HTTP 5xx | **0–1** trong các lượt sau khi sửa | — |
| Timeout của k6 (60 s) | phần lớn lỗi còn lại | request xếp hàng chờ worker php-fpm khi CPU bão hoà |
| `upstream timed out` ở nginx | 19 dòng trong lượt 150 req/s trước khi sửa | cùng nguyên nhân |
| Deadlock / "too many clients" / lock DB | **0** | không xảy ra lần nào sau khi có PgBouncer |
| Lỗi Valkey | **0** (`blocked_clients` 0, `evicted_keys` 0) | — |

---

## 4. Điều gì đã thay đổi giữa các lượt (và đóng góp đo được)

| Thay đổi | Bằng chứng | Đóng góp |
|---|---|---|
| Phục vụ mã từ filesystem container thay vì bind mount | `strace`: 10–60 file không phải PHP mỗi request; `bootstrap/cache/config.php` ENOENT | 45,1 → 78,5 req/s; lỗi 19,87 % → 0,24 %; `/health` 40 VU 65 → 248 req/s |
| **PgBouncer (session pooling)** | `pgbench -C` 1.438 tps vs 47.990 tps khi tái dùng kết nối | `/health` 40 VU 148 → 237 req/s; `/products` 97 → 189 req/s; ở 3.000 người: p99 1,19 s → 991 ms, lỗi 2,36 % → 0,59 %; CPU Postgres ~500 % → 46–96 % |
| Chỉ mục thời gian cho `stock_movements` | EXPLAIN: nút `Sort` biến mất, `Seq Scan` → `Index Scan` | chưa tách riêng được trong số tổng (dữ liệu dev quá nhỏ để lộ) |
| Kết nối Valkey bền + timeout 2 s | 643.022 lệnh GET ở 20,2 µs | **KHÔNG TÁCH ĐƯỢC** (đo cùng lượt với PgBouncer) |
| `pm.max_requests` 1000 → 5000 | 24.099 câu introspection catalog tiêu 74 s | **KHÔNG TÁCH ĐƯỢC** |

---

## 5. Ước lượng năng lực cho 10.000 người dùng

**Đây là phép ngoại suy từ số đo, KHÔNG phải kết quả đã kiểm chứng.**

### 5.1 CPU cho mỗi request (đo ở mức 150 req/s, lượt stress)

| Thành phần | CPU quan sát được | Quy ra mỗi request |
|---|---|---|
| php-fpm (2 replica) | ~8 lõi | **~53 ms CPU** |
| PostgreSQL | ~1 lõi | ~6,7 ms CPU |
| PgBouncer | ~0,45 lõi | ~3 ms CPU |
| **Tổng (không kể nginx, Valkey)** | ~9,5 lõi | **~63 ms CPU/request** |

### 5.2 Suy ra số lõi cần

| Kịch bản | req/s | Lõi cần ở 100 % | Lõi cần ở **70 %** (có dự phòng) |
|---|---:|---:|---:|
| 10.000 người, think 40 s | 250 | ~16 | **~23** |
| 10.000 người, think 20 s | 500 | ~32 | **~45** |
| 10.000 người, think 8 s | 1.250 | ~79 | **~113** |

### 5.3 Hình dung theo node

Lấy node ứng dụng = 8 vCPU / 16 GB (cỡ phổ biến), dành ~7 lõi cho php-fpm:

| Kịch bản | Node ứng dụng | CSDL | Cache |
|---|---|---|---|
| think 40 s | **3–4 node** | 1 PostgreSQL 4–8 vCPU + PgBouncer | 1 Valkey 2 vCPU |
| think 20 s | **6–7 node** | 1 PostgreSQL 8–16 vCPU + PgBouncer (cân nhắc read replica cho báo cáo) | 1–2 Valkey |
| think 8 s | **15–17 node** | phải tách read replica + tầng tổng hợp sẵn | Valkey cụm |

**Ràng buộc kèm theo, không được bỏ qua:**

- `PG_MAX_CONNECTIONS=120` là trần cho **toàn bộ** cụm khi có PgBouncer:
  `DEFAULT_POOL_SIZE` (100) + queue + scheduler + reverb + dự phòng. Thêm node ứng
  dụng **không** làm tăng kết nối server — đó chính là lý do PgBouncer là điều kiện
  cần để scale ngang.
- Reverb (WebSocket) hiện là **một tiến trình duy nhất** — 10.000 socket bền chưa
  được kiểm chứng (CAPACITY-06).
- Mọi file ghi-ở-đây-đọc-ở-kia đã lên MinIO/S3 nên node ứng dụng đã stateless đủ để
  scale ngang (xem `PERF_AUDIT_2026-09-06.md` §7).

---

## 6. Muốn biến ước lượng thành BẰNG CHỨNG thì phải làm gì

Thiếu bất kỳ điều kiện nào dưới đây thì kết quả **không dùng để kết luận** được:

1. **k6 chạy trên máy KHÁC** với hệ thống (hiện đang chung máy).
2. **Môi trường giống production**: số node thật, PostgreSQL/Valkey trên máy riêng,
   không phải Docker Desktop trên Windows.
3. **Khối lượng dữ liệu giống production** (hiện là DB dev 7.350 dòng sổ cái).
4. **`THINK` đúng think-time thật** của nhân viên kho, đo từ hệ thống đang chạy
   chứ không đoán.
5. **Có kịch bản GHI** trong mix (Post Receipt, Post Issue, Putaway) trên bản sao
   dữ liệu (`PERF_DB_DATABASE`) — hiện chưa có.
6. **Chạy soak** (≥ 1 giờ) để bắt rò rỉ bộ nhớ/kết nối và hiện tượng chậm dần.

Lệnh khi đủ điều kiện:

```bash
LOAD_BASE_URL=https://<uat-host> MSYS_NO_PATHCONV=1 \
docker compose -f load/docker-compose.k6.yml run --rm k6 run \
  -e PROFILE=target -e USERS=10000 -e THINK=40 -e INCLUDE_PUBLIC=0 \
  --summary-export=/results/uat-10k-think40.json /scripts/wms-load.js
```

---

## 7. Những gì bài đo này KHÔNG bao phủ

| Hạng mục | Trạng thái |
|---|---|
| Đường ghi dưới tải (Post Receipt/Issue, Putaway, bảo hành) | **CHƯA ĐO** — bộ đo chỉ đọc |
| Tính đúng dữ liệu dưới đồng thời (lost update, oversell, trừ tồn hai lần) | **CHƯA ĐO** — hệ quả của mục trên |
| Soak dài giờ (rò rỉ bộ nhớ, kết nối, chậm dần) | **CHƯA ĐO** |
| Spike 1.000 → 10.000 trong thời gian ngắn | **CHƯA ĐO** |
| Redis/Valkey chết hoặc chậm giữa chừng | **CHƯA ĐO** (timeout đã có, kịch bản chưa chạy) |
| 10.000 WebSocket đồng thời (Reverb) | **CHƯA ĐO** |
| Hành vi ở khối lượng dữ liệu production | **CHƯA ĐO** |

---

## 8. Kết luận

**NOT PASSED** cho mục tiêu 10.000 người dùng đồng thời.

Điều đã chứng minh được bằng số đo:

- Ở **94 req/s** (tương đương ~1.900 người dùng ở think-time 20 s, hoặc ~3.700 ở
  40 s): **p50 32 ms, p95 91 ms, p99 149 ms, 0 % dropped, 0,11 % lỗi** — đạt toàn
  bộ ngưỡng SLO đặt ra (p95 ≤ 1 s, p99 ≤ 1,5 s, lỗi < 0,1 % — sát ngưỡng lỗi).
- Ở **150 req/s** (~3.000 người ở think-time 20 s): p50 50 ms, p95 562 ms,
  p99 991 ms, lỗi 0,59 % — **p95/p99 vẫn trong ngưỡng, nhưng tỉ lệ lỗi vượt 0,1 %**
  và có 340 iteration bị bỏ ⇒ **chưa đạt**.
- Điểm gãy: **giữa 150 và 225 req/s** trên 16 vCPU với k6 chạy chung máy.

Nghĩa là: **mã nguồn hiện tại phục vụ được khoảng 1.900–3.000 người dùng đồng thời
trên MỘT máy 16 vCPU** (think-time 20 s), và muốn tới 10.000 thì cần thêm hạ tầng
theo mục 5 — chứ không phải cần viết lại ứng dụng.
