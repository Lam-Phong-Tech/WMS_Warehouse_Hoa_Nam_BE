# Bộ đo tải WMS Hoà Nam (k6)

Thư mục này **không** thuộc mã chạy thật. Không có gì trong `app/`, `Modules/`
hay `config/` phụ thuộc vào nó; xoá cả thư mục đi thì hệ thống vẫn chạy nguyên
vẹn.

## Chạy nhanh

```bash
docker compose up -d
```

```bash
docker compose -f load/docker-compose.k6.yml run --rm k6 run -e PROFILE=smoke //scripts/wms-load.js
```

> Dấu `//scripts` (hai gạch chéo) là cách chặn Git Bash trên Windows tự đổi
> `/scripts/...` thành đường dẫn Windows. Trên PowerShell/Linux dùng
> `/scripts/wms-load.js` như bình thường.

## Bốn hồ sơ đo

| `PROFILE`  | Dùng để làm gì | Thời lượng |
|-----------|----------------|-----------|
| `smoke`    | Kiểm tra script và môi trường trước khi đo thật | 30 giây |
| `baseline` | Mức nền 1/4 tải mục tiêu — dùng để so sánh trước/sau khi tối ưu | ~4,5 phút |
| `target`   | Đúng tải của `USERS` người dùng, giữ 5 phút | ~7,5 phút |
| `stress`   | Đẩy tới 2 lần tải mục tiêu để tìm điểm gãy | ~7,5 phút |

## Tham số

| Biến | Mặc định | Ý nghĩa |
|------|----------|---------|
| `USERS` | `3000` | Số người dùng đồng thời cần mô phỏng |
| `THINK` | `8` | Số giây trung bình giữa hai thao tác của một người |
| `BASE_URL` | `http://nginx` | Đích đo. Mặc định gọi thẳng container nginx trong mạng docker |
| `P95_MS` | `800` | Ngưỡng đạt: 95% request phải nhanh hơn mức này |
| `ERROR_RATE` | `0.01` | Ngưỡng đạt: tỉ lệ lỗi tối đa |
| `LOAD_USERS` | `admin@gmail.com,manager01@gmail.com,...` | Danh sách EMAIL lấy JWT trong `setup()` (login chỉ nhận email) |
| `LOAD_PASSWORD` | *(bắt buộc, không có mặc định)* | Mật khẩu của các tài khoản trên. Không ghi vào repo; truyền lúc chạy (`-e LOAD_PASSWORD=...`) |

Tải mục tiêu suy ra từ hai tham số đầu:

```
số request mỗi giây = USERS / THINK
```

## Bắt buộc: tắt trần gọi API trước khi đo

Từ WWHN-SEC-01, nhóm endpoint đã đăng nhập có trần gọi khoá theo **user_id**
(`config/rate_limit.php`). `setup()` chỉ đăng nhập bằng 6 tài khoản trong
`LOAD_USERS` rồi chia cho toàn bộ `USERS` người dùng ảo, nên toàn bộ tải dồn vào
6 bộ đếm và chạm trần 600 đọc/phút gần như ngay lập tức — kết quả đo sẽ là tỉ lệ
429 chứ không phải hiệu năng thật.

Đặt bốn biến sau về `0` trên môi trường đo (mẫu đầy đủ trong `.env.perf.example`),
và **không** mang giá trị này sang môi trường thật:

```
RATE_LIMIT_API_READ_PER_MINUTE=0
RATE_LIMIT_API_WRITE_PER_MINUTE=0
RATE_LIMIT_API_EXPORT_PER_MINUTE=0
RATE_LIMIT_API_UNAUTHENTICATED_PER_MINUTE=0
```

3000 người với think-time 8 giây tạo ra **375 request/giây**.

## Vì sao dùng mô hình mở (open model)

`k6` được cấu hình theo `ramping-arrival-rate`: bộ phát giữ đúng số request mỗi
giây bất kể hệ thống trả lời nhanh hay chậm.

Mô hình đóng (N luồng, mỗi luồng gửi xong mới gửi tiếp) tự động giảm tải khi hệ
thống chậm lại — nghĩa là nó **che mất** điểm quá tải: hệ thống càng chậm, phép
đo càng nhẹ tay. Mô hình mở giữ nguyên áp lực, nên khi vượt ngưỡng ta nhìn thấy
đúng ba dấu hiệu: `p95` tăng vọt, `dropped_iterations` khác 0, và tỉ lệ lỗi tăng.

## Phạm vi phủ

`k6/lib/flows.js` liệt kê 31 endpoint đọc, chia theo nhóm nghiệp vụ và có trọng
số phản ánh tần suất dùng thật: bảng điều khiển, tồn kho, dữ liệu nền, chứng từ
nhập/xuất/bảo hành, báo cáo nặng, quản trị người dùng, chuông thông báo, phiên
đăng nhập và cổng công khai.

**Toàn bộ là request đọc.** Đây là lựa chọn có chủ ý: chạy kịch bản ghi ở mức
vài trăm request/giây sẽ sinh hàng chục nghìn phiếu rác vào cơ sở dữ liệu. Muốn
đo cả đường ghi thì trỏ stack sang bản sao dữ liệu (`PERF_DB_DATABASE`) rồi mới
bật — xem `.env.perf.example`.

## Lưu ý khi đọc kết quả trên máy dev

Trình sinh tải chạy **cùng một máy** với hệ thống bị đo, nên ở mức tải cao chính
k6 cũng giành CPU với backend. Con số thu được là **cận dưới** của năng lực
thật. Muốn số chính xác thì chạy k6 từ một máy khác:

```bash
docker run --rm -i -v "$PWD/load/k6:/scripts:ro" grafana/k6 run -e BASE_URL=http://<ip-server>:8080 -e PROFILE=target //scripts/wms-load.js
```

## Bổ sung 07/09/2026

### Bắt buộc trên Git Bash / Windows

```bash
MSYS_NO_PATHCONV=1 docker compose -f load/docker-compose.k6.yml run --rm k6 run -e PROFILE=baseline /scripts/wms-load.js
```

Thiếu `MSYS_NO_PATHCONV=1` thì Git Bash đổi `/scripts/...` thành `C:/Git/scripts/...`
và k6 báo không tìm thấy file. (Cách cũ `//scripts` vẫn dùng được.)

### Tham số mới

| Biến | Mặc định | Ý nghĩa |
|------|----------|---------|
| `INCLUDE_PUBLIC` | `1` | `0` = bỏ các luồng công khai (PV/catalog) khỏi mix. Chúng đi qua `throttle:pv-public` **120 req/phút theo IP**, mà k6 phát mọi request từ MỘT IP ⇒ 429 giả tạo của bộ đo, không phải năng lực hệ thống. Đặt `0` khi muốn tỉ lệ lỗi phản ánh đúng hệ thống. |
| `RUN_TAG` | `run` | Nhãn của lượt đo, đi vào tag của mọi metric |
| `SLOW_REQUEST_MS` | `500` (hồ sơ chịu tải) | Ngưỡng ghi log request chậm phía BE (xem bên dưới) |

Luôn kèm `--summary-export=/results/<tag>.json` để so sánh được về sau.

### Ba công cụ đi kèm

```bash
# 1. So sánh mọi lượt đã lưu trong load/results
node load/summarize.mjs
node load/summarize.mjs baseline           # lọc theo tên

# 2. Cô lập từng tầng: bắn MỘT endpoint bằng mô hình đóng (constant-vus)
MSYS_NO_PATHCONV=1 docker compose -f load/docker-compose.k6.yml run --rm k6 run \
  -e VUS=40 -e DURATION=40s -e TARGET=health /scripts/probe-concurrency.js
#   TARGET = health | products | inventory | hub
#   Dùng để trả lời "nghẽn ở tầng nào": nếu /health (không auth, 5 câu SQL) đã
#   bung ở 40 VU thì vấn đề nằm dưới tầng ứng dụng, không phải ở logic màn hình.

# 3. Quy thời gian về từng ROUTE sau một lượt đo (cần SLOW_REQUEST_MS > 0)
(docker logs --since 15m wms_warehouse_hoa_nam_be-backend-1 2>&1; \
 docker logs --since 15m wms_warehouse_hoa_nam_be-backend-2 2>&1) \
 | node load/analyze-slow-requests.mjs
```

Cột của công cụ 3: `p50/p95/max` tổng thời gian, `db_ms` + `sql#`, `lock` (thời
gian chờ lock chống stampede), `miss%` cache. Đọc như sau:

| Dấu hiệu | Kết luận |
|---|---|
| `db_ms` ≈ `duration` | nghẽn ở SQL (hoặc ở việc MỞ KẾT NỐI — xem PgBouncer trong `LOAD_TEST_2026-09-07.md`) |
| `lock` lớn | nhiều request cùng dựng lại một khoá cache (stampede) |
| `db_ms` và `lock` đều nhỏ mà `duration` lớn | tiến trình đang chờ CPU ⇒ máy đã bão hoà |

### Trạng thái php-fpm trong lúc đo

```bash
docker compose exec nginx wget -qO- http://127.0.0.1/fpm-status
```

Chỉ mở cho loopback + mạng docker. Ba cột quyết định: `listen queue` (request
đang xếp hàng chờ worker — khác 0 là đã quá tải), `active processes`,
`max children reached`.

### Hồ sơ chịu tải hiện tại (07/09/2026)

Phải chạy kèm overlay, và nay gồm cả PgBouncer:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml -f docker-compose.perf.yml up -d --scale backend=2
```

Xem `docs/perf/LOAD_TEST_2026-09-07.md` để biết vì sao mã nguồn được phục vụ từ
`/srv/app` trong container và vì sao PgBouncer là bắt buộc trong hồ sơ này.
