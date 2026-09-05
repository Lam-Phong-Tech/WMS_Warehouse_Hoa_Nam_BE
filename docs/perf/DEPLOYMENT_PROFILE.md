# Hồ sơ triển khai — chạy bản nào, và làm sao biết mình đang chạy đúng bản

## 1. Vấn đề

`docker-compose.perf.yml` là overlay **opt-in**. Gõ thiếu một cờ `-f` là cả stack
chạy bằng cấu hình dev: `APP_DEBUG` bật, opcache stat từng file mỗi request,
predis thay phpredis, pool php-fpm mặc định, Postgres `max_connections=100`.

Điều nguy hiểm là **không có dấu hiệu nào nhìn thấy được**. Hệ thống vẫn trả 200,
vẫn đúng nghiệp vụ, chỉ chậm hơn nhiều lần — và không ai biết cho tới khi tải
thật ập vào.

---

## 2. Lệnh chạy production

```bash
docker compose -f docker-compose.yml \
               -f docker-compose.override.yml \
               -f docker-compose.perf.yml up -d --build
```

Nhắc: khi đã truyền `-f` thì `docker-compose.override.yml` **không** còn được nạp
ngầm nữa, phải liệt kê tay như trên.

Chọn preset tham số theo RAM máy thật trong `.env.perf.example` (A: 8GB dev,
B: 16GB, C: 32GB) rồi chép vào cuối `.env`.

---

## 3. Xác minh sau khi deploy — bắt buộc

```bash
docker compose exec backend php artisan wms:runtime-profile
```

Lệnh in bảng PASS/FAIL và **trả mã thoát khác 0** khi runtime không đạt hồ sơ
chịu tải. Nên gắn vào bước smoke test của pipeline: khi đó "quên overlay" trở
thành một lần deploy đỏ, chứ không phải một sự cố hiệu năng phát hiện sau vài
tuần.

Nó kiểm:

| Hạng mục | Vì sao quan trọng |
|---|---|
| `APP_DEBUG=false` | Bật là Laravel thu thập mọi query + stack trace cho **mọi** request |
| config/route đã cache | Thiếu là mỗi request nạp lại toàn bộ file config |
| `opcache.enable` + `validate_timestamps=0` | Bật validate là PHP stat từng file ở mỗi request |
| `redis client = phpredis` | predis là client thuần PHP, chậm hơn đáng kể ở vài trăm req/s |
| cache/session/queue = redis | Điều kiện để scale ngang: session dùng chung ⇒ không cần sticky |
| cache tách logical DB với queue/session | Dọn cache không được đụng hàng đợi job |
| cache store trả lời | fail-open giữ app sống khi Valkey chết, nhưng người vận hành phải **biết** |
| `pg max_connections` ≥ `pm.max_children` + 12 | Thiếu là "sorry, too many clients already" trả thẳng 500 |
| `JWT_TTL` | Cảnh báo khi > 240 phút — thường là cấu hình tạm cho scanner rồi quên hoàn tác |

---

## 4. Mở rộng ngang

Khối `upstream` của hồ sơ perf dùng `zone` + tham số `resolve`, nên nginx tự theo
kịp IP mới của backend mà **không cần reload**.

```bash
# nhiều replica trên cùng một máy
docker compose -f docker-compose.yml -f docker-compose.override.yml \
               -f docker-compose.perf.yml up -d --scale backend=2
```

Docker DNS trả về nhiều bản ghi A cho tên `backend`, nginx cân tải vòng tròn giữa
chúng. Đã kiểm chứng: 20 request `/auth/me` với cùng một JWT chia 11/10 giữa hai
container, tất cả 200 — **không cần sticky session** vì session nằm ở Redis dùng chung.

Nhiều máy: nối thêm vào `WMS_PHP_UPSTREAM_SERVERS`

```
server host2:9000 resolve; server host3:9000 resolve;
```

### Failover
`max_fails=3 fail_timeout=10s` + `fastcgi_next_upstream error timeout` +
`tries 2` + `timeout 10s`. Đã kiểm chứng: giết một node trong hai, 23/24 request
kế tiếp vẫn 200; một request đầu tiên trả 504 đúng bằng trần
`fastcgi_next_upstream_timeout`.

**Không** khai `non_idempotent`: nginx do đó không bao giờ phát lại POST/PATCH —
toàn bộ đường ghi tồn kho của WMS (post-receipt, post-issue, reversal) nằm ở hai
phương thức này, nên không thể sinh bút toán lặp do retry.

---

## 5. Việc còn lại của chủ hệ thống

- `JWT_TTL=43200` (30 ngày) là cấu hình **tạm** cho việc quét bằng scanner. Đưa
  về `60` trước khi chạy thật.
- `BCRYPT_ROUNDS=12`: mỗi lần đăng nhập tốn ~200–300ms CPU. Với 3000 người đăng
  nhập đầu ca đây là khoản CPU lớn nhất. Hạ về `10` vẫn an toàn và rẻ hơn ~4 lần,
  nhưng đây là quyết định bảo mật — cần đo và chấp thuận, không tự đổi.
- PgBouncer: chỉ cần khi tổng kết nối của **nhiều máy** app vượt `max_connections`.
  Một máy với preset B/C thì chưa cần.
