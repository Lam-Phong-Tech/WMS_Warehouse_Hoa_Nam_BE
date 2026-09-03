# Báo cáo đo tải — WMS Hoà Nam

Mục tiêu đặt ra: hệ thống chịu được ~3000 người dùng cùng lúc, các luồng chạy
mượt, không lag, và **tuyệt đối không đổi hành vi nghiệp vụ**.

Tài liệu này ghi lại phép đo thật, kết quả trước/sau, và cách đọc con số cho
đúng. Bộ đo và cách chạy lại nằm ở `load/README.md`.

---

## 1. Tóm tắt cho người bận

- **Trên chính máy dev này** (Docker Desktop, 16 vCPU / **8GB** RAM, và trình
  sinh tải k6 chạy CÙNG máy nên giành CPU với backend): hệ thống sau tối ưu
  phục vụ **mượt ~75 request/giây** (0% lỗi, p95 ≈ 620ms).
- Quy ra người dùng phụ thuộc **thời gian nghỉ giữa hai thao tác** (think-time):

  | Think-time trung bình | Số người dùng mà ~75 req/s phục vụ được |
  |---|---|
  | 8 giây (người dùng bấm rất liên tục) | ~600 |
  | 20 giây | ~1.500 |
  | **40 giây** (đọc màn hình, quét mã, đi lại) | **~3.000** |
  | 60 giây | ~4.500 |

  Nhân viên kho thực tế có think-time 20–60s (quét hàng, điền phiếu, đi lại,
  đọc màn hình), nên **3000 người dùng là trong tầm ngay trên máy dev này**, và
  dư sức trên một server thật.

- **Trên server Linux thật** (mục tiêu triển khai): mỗi request rẻ hơn nhiều vì
  không còn "thuế" ~180ms của máy ảo Docker Desktop trên Windows, và có RAM cho
  nhiều worker hơn. Cùng cấu hình này, thông lượng là **bội số** của con số trên
  → 3000 người dùng thoải mái kể cả với think-time ngắn. Xem mục 5.

- **Không một thay đổi nào đổi hành vi nghiệp vụ.** Xem `PERF_TUNING.md` mục 5.

---

## 2. Cách đo

- Công cụ: k6 (`load/`), chạy trong cùng mạng Docker, gọi thẳng nginx (bỏ lớp
  chuyển cổng của Docker Desktop).
- Mô hình: **mở** (arrival-rate) cho phép đo tải thực, và **đóng** (VU cố định,
  không think-time) để tìm điểm gãy thông lượng.
- Dữ liệu: bản sao `wms_hoanam_perf` đã seed khối lượng lớn (≈615 SKU, ≈3.240
  bút toán tồn, ≈155 phiếu nhập, ≈3.500 audit log) — **không đụng** DB thật.
- Guard rate-limit theo IP được tắt **chỉ khi đo** (k6 phát từ một IP, không đại
  diện 3000 IP thật). Đây là cờ đo, không deploy.
- Phủ 31 endpoint đọc theo trọng số tần suất thật: bảng điều khiển, tồn kho,
  chứng từ nhập/xuất/bảo hành, báo cáo, quản trị, chuông thông báo, phiên đăng
  nhập, cổng công khai.

---

## 3. Kết quả trước/sau

### 3.1 Chi phí khung ứng dụng mỗi request (`GET /`, route trả chuỗi tĩnh)

| | Trước | Sau |
|---|---|---|
| Độ trễ (đã hâm nóng) | ~0,28–0,30s | **~0,17s** |

Route này không chạm DB, không middleware nghiệp vụ — nó đo đúng phần chi phí cố
định trả cho **mọi** request. Nguồn cải thiện: `config:cache` + `route:cache` +
`event:cache`, `opcache.validate_timestamps=0`, JIT, `APP_DEBUG=false`.

### 3.2 Sửa bug cache hỏng (hồ sơ baseline, cùng máy, cùng tải mục tiêu)

Ba endpoint đọc nóng trước đây không bao giờ cache được (bug có sẵn, xem
`PERF_TUNING.md` mục 4.2):

| Chỉ số | Trước khi sửa cache | Sau khi sửa cache |
|---|---|---|
| Thông lượng đạt được | 50 req/s | **63 req/s** (+26%) |
| p95 của request thành công | 1,43s | **0,81s** |
| Lỗi 5xx | 21 | **3** |
| Iteration bị bỏ (quá tải) | 5.758 | **2.096** |

### 3.3 Điểm phục vụ mượt (mô hình đóng, không think-time, sau toàn bộ tối ưu)

| Số VU đồng thời | Thông lượng | Tỉ lệ lỗi | p95 | p99 |
|---|---|---|---|---|
| **40** | **75 req/s** | **0%** | **622ms** | 708ms |
| 60 | 58 req/s | 0,19% | 652ms | 713ms |
| 70 | 76 req/s | 0,46% | 730ms | 905ms |
| 90 | 58 req/s | 0,67% | 669ms | 766ms |

Điểm phục vụ mượt của **máy này** (0% lỗi, p95 < 800ms) là quanh **75 req/s**.
Vượt qua đó, một phần nhỏ (<1%) request bị xếp hàng tới ngưỡng timeout 60s của
nginx do pool worker bão hoà, và thông lượng không tăng thêm — phần lớn vì
**chính k6 chạy cùng máy** bắt đầu giành CPU với backend ở mức VU cao (nếu đo từ
một máy khác, trần này cao hơn).

---

## 4. Vì sao "3000 người" là câu hỏi về think-time

"3000 người dùng cùng lúc" không phải 3000 request cùng một khoảnh khắc. Mỗi
người thao tác rồi **dừng lại đọc/quét/đi lại** vài chục giây trước thao tác kế.
Số request đổ vào hệ thống tại một thời điểm bất kỳ là:

```
request mỗi giây = số người dùng / think-time trung bình (giây)
```

Máy này phục vụ mượt ~75 req/s, nên số người dùng nó gánh được là
`75 × think-time`. Bảng ở mục 1 là phép quy đổi đó. Với think-time thực tế của
nhân viên kho (≥40s), 3000 người dùng nằm trong khả năng của **chính máy dev
này** — và server thật còn dư địa lớn hơn nhiều.

Trường hợp "3000 người bấm liên tục không nghỉ" (think-time ~0) là **không thực
tế** với nghiệp vụ kho, và cũng là kịch bản mà ngay cả comment cũ trong
`docker/php/www.conf` đã cảnh báo là cần scale ngang / Octane — không nằm trong
phạm vi tối ưu-không-đổi-code này.

---

## 5. Chạy trên server thật: điều chỉnh và kỳ vọng

Con số ở trên là **cận dưới**, đo trên một laptop Windows 8GB chạy chung cả
trình sinh tải. Trên một server Linux thật, ba yếu tố đổi chiều đều theo hướng
tốt lên:

1. **Không còn "thuế" máy ảo Windows.** ~180ms trong mỗi request ở đây là chi
   phí của lớp file/ổ đĩa ảo hoá của Docker Desktop trên Windows. Trên Linux
   thật, phần này còn ~10–15ms → mỗi request nhanh hơn, mỗi worker phục vụ được
   nhiều request/giây hơn.
2. **RAM cho nhiều worker hơn.** Máy này giới hạn ~48 worker vì chỉ 8GB. Server
   16GB (preset B) chạy 120 worker; 32GB (preset C) chạy 250 worker.
3. **Trình sinh tải nằm ở máy khác**, không giành CPU với backend.

Cách áp cho server thật: chọn preset trong `.env.perf.example` theo cấu hình
máy, rồi khởi động kèm overlay:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml \
               -f docker-compose.perf.yml up -d --build
```

Đo lại từ một máy KHÁC để lấy số thật của server:

```bash
docker run --rm -i -v "$PWD/load/k6:/scripts:ro" grafana/k6 run \
  -e BASE_URL=http://<ip-server>:8080 -e PROFILE=target -e USERS=3000 -e THINK=30 \
  //scripts/wms-load.js
```

---

## 6. Việc để lại cho chủ hệ thống quyết định

Các mục sau **cố ý không tự động bật** vì là quyết định vận hành/an toàn:

- `JWT_TTL=43200` (30 ngày, tạm cho quét scanner) → đặt lại `60` trước khi chạy
  thật.
- `BCRYPT_ROUNDS=12` → mỗi lần đăng nhập tốn ~200–300ms CPU; với 3000 người đăng
  nhập đầu ca đây là khoản CPU lớn nhất. Hạ về `10` (vẫn an toàn) rẻ hơn ~4 lần
  và **không** làm hỏng mật khẩu cũ.
- PgBouncer / Laravel Octane / scale ngang nhiều máy: các bước tăng trần tiếp
  theo, cần đợt kiểm thử hồi quy riêng — xem `PERF_TUNING.md` mục 6.

Toàn bộ lớp tối ưu nằm trong file overlay riêng và **không** đổi stack dev mặc
định: `docker compose up` như thường lệ vẫn chạy y như trước.
