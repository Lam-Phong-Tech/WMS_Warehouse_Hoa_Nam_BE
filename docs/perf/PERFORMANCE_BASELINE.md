# PERFORMANCE_BASELINE — Hoà Nam WMS

Mốc nền để mọi con số "sau khi sửa" có cái để so. Tất cả đều là **số đo thật**;
chỗ nào chưa đo được thì ghi `CHƯA ĐO`.

> **Lưu ý về tính "baseline".** Đợt tối ưu này diễn ra qua ba chặng (06/09 sáng,
> 06/09 tối, 07/09) nên không tồn tại MỘT thời điểm "trước tất cả". Bảng dưới ghi
> đúng trạng thái đo được **ngay trước từng nhóm thay đổi**, kèm ngày. Đây là lý
> do một vài dòng có hai cột "trước" khác nhau.

## 1. Môi trường đo

| Hạng mục | Giá trị |
|---|---|
| Máy | Windows 11 Home 10.0.22631, Docker Desktop |
| VM của Docker | **16 vCPU, 7,98 GB RAM**, swap 2 GB |
| Trình sinh tải | k6 (grafana/k6), **chạy CÙNG máy** với hệ thống bị đo |
| Backend | PHP 8.3-fpm-alpine, Laravel 13, nginx:alpine, 1–2 replica |
| Cơ sở dữ liệu | PostgreSQL 16.14 (container), `max_connections=120`, `shared_buffers=512MB` |
| Cache / session / queue | Valkey 8-alpine, `maxmemory=256mb`, `volatile-lru` |
| Object storage | MinIO (bucket `wms-hoanam`) |
| Frontend | build production (Vite 8 / React 19.2), phục vụ tĩnh tại `localhost:5199` |
| Trình duyệt đo FE | Chromium qua Playwright, 1440×900, có/không giả lập CPU chậm 4× |

**Giới hạn phải nhớ khi đọc mọi kết quả:** k6 chạy chung máy nên ở tải cao chính
nó giành CPU với backend. Mọi con số thông lượng là **cận dưới**. Không con số nào
trong tài liệu này được phép dùng để tuyên bố năng lực production.

## 2. Phiên bản mã

| Repo | Nhánh | Trạng thái |
|---|---|---|
| BE `HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE` | `dev` | working tree có thay đổi chưa commit của nhiều phiên song song |
| FE `HoaNam_FE/WMS_Warehouse_Hoa_Nam_FE` | `dev` | như trên |

Vì có **nhiều phiên Claude sửa song song trên cùng working tree**, mốc nền được
ghi theo thời điểm đo chứ không theo commit SHA.

## 3. Khối lượng dữ liệu lúc đo

| Bảng | Số dòng | Kích thước |
|---|---:|---|
| `stock_movements` | 7.350 | 5,8 MB |
| `item_instances` | 4.549 | 4,7 MB |
| `physical_codes` | 3.162 | 3,2 MB |
| `inbound_lines` | 2.923 | **186 MB** (phình — xem DB-09) |
| `outbound_lines` | 1.405 | **70 MB** (phình) |
| `inventory_balances` | 293 | 504 kB |
| Toàn database | — | ~330 MB |

**Đây KHÔNG phải khối lượng production.** 7.350 dòng sổ cái là nhỏ; kế hoạch truy
vấn ở khối lượng thật có thể khác hẳn. Xem "Chưa đạt" ở báo cáo cuối.

## 4. Mốc nền — Backend

### 4.1 Độ trễ khi rảnh (một người dùng, cache ấm)

| Endpoint | 06/09 (stack dev, bind mount) | 07/09 trước PgBouncer | Ghi chú |
|---|---|---|---|
| `/api/v1/health` | 190–210 ms | 92–138 ms | sau khi phục vụ mã từ container: 27–40 ms |
| `/skus?page=1&per_page=20` | 162–196 ms | — | |
| `/item-instances?page=1&per_page=20` | 186–219 ms | — | |
| `/reports/hub` | 200–259 ms | — | |
| `/inventory?page=1&per_page=20` | 217–225 ms | — | |
| `/audit-logs?page=1&per_page=20` | 197–223 ms | — | |

### 4.2 Chịu tải (mô hình mở, k6 cùng máy)

| Lượt | Ngày | Tải đích | Đạt được | p50 | p95 | p99 | Lỗi | dropped |
|---|---|---|---|---|---|---|---|---|
| smoke 5 VU | 06/09 | — | 2,3 req/s | 156 ms | 311 ms | 407 ms | 0 % | 0 |
| baseline, stack dev | 06/09 | 94 req/s | 77,9 req/s | 445 ms | 1,26 s | 1,98 s | 0 % | 17 |
| target 3.000 người/8 s | 06/09 | 375 req/s | 120,5–130,7 req/s | 10,6–11,2 s | 11,9–13,4 s | 12,5–14,4 s | 0–2,28 % | 81–85 nghìn |
| baseline, hồ sơ chịu tải (bind mount) | 07/09 | 94 req/s | **45,1 req/s** | 995 ms | 1,52 s | 2,19 s | **19,87 %** | 7.531 |
| target 3.000 người/20 s, 2 replica | 07/09 | 150 req/s | 116,6 req/s | 70 ms | 675 ms | 1,19 s | 2,36 % | 435 |

### 4.3 Dấu hiệu bão hoà ở mốc nền

| Chỉ số | Giá trị đo được |
|---|---|
| CPU của VM ở 150 req/s | **96 % (idle 4 %)** trên 16 vCPU |
| `listen queue` của php-fpm | 574–771 request đang xếp hàng |
| `max children reached` | 1 (chạm trần 48 worker/replica) |
| Kết nối PostgreSQL | 40 (mỗi request một kết nối mới) |
| CPU PostgreSQL | ~500 % (5 lõi) — phần lớn là fork + bắt tay SCRAM |
| `pgbench -C` (mở kết nối mỗi giao dịch) | 1.438 tps so với 47.990 tps khi tái dùng |
| Valkey | `blocked_clients` 0, `evicted_keys` 0, 20,2 µs/lệnh GET |
| Truy vấn SQL chậm nhất | 16 ms trung bình (sổ cái biến động) |

### 4.4 Độ phủ cache (đo hành vi runtime, 06/09)

| Chỉ số | Giá trị |
|---|---|
| Endpoint đọc đo được | 84 |
| Thật sự ghi cache | 55 (**42 nếu loại các khoá do throttle sinh ra** — xem API-05) |
| Tỉ lệ hit thô của Valkey dưới tải | 35,7 % (bị bóp méo bởi GET khoá phiên bản — xem CACHE-08) |

## 5. Mốc nền — Frontend

### 5.1 Trước đợt 1 (06/09)

| Chỉ số | Giá trị |
|---|---|
| INP khi gõ một phím vào ô lọc (màn Truy vết) | **14.666 ms** (một long task duy nhất) |
| Số node DOM của màn đó | **293.514** |
| Thời gian một lượt render lại của màn | ~200 ms |

### 5.2 Trước đợt 2 (07/09, sau đợt 1)

| Thao tác | CPU x1 | CPU x4 (máy văn phòng) |
|---|---|---|
| Lần đầu vào màn báo cáo (shell) | 300–370 ms chỉ để tải chunk | > 1 s |
| Quay lại màn báo cáo đã mở | 0,7–0,9 s, khoá luồng chính 200–400 ms | 700–900 ms |
| Bấm tab trong màn báo cáo | — | ~930 ms trước khi tab đổi màu |
| Chunk phải tải khi bấm menu | 1–38 | 1–38 |

### 5.3 Ngân sách gói (bản build production)

| Hạng mục | Giá trị |
|---|---|
| Đồ thị tải của trang đầu | **1.453 KB thô** (33 file) |
| Chunk lớn nhất | `react-apexcharts` 868 KB (đã tách khỏi đường tải) |
| CSS trong đường tải | 184 KB |

### 5.4 Core Web Vitals

`CHƯA ĐO ở mốc nền.` Bộ đo (`scripts/perf/measure-web-vitals.mjs`) chỉ được viết
trong đợt 07/09; số đo hiện tại nằm ở `PERFORMANCE_FINAL_REPORT.md` và **không có
đối chứng "trước"** cho LCP/CLS/TBT. Đây là thiếu sót của đợt này, ghi lại để lần
sau đo trước khi sửa.

## 6. Điểm nghẽn đã xác định ở mốc nền

Xem `PERFORMANCE_BOTTLENECKS.md` — 74 phát hiện có bằng chứng file:dòng, trong đó
5 đã qua phản biện độc lập, 1 bị bác bỏ, 68 chưa được xác nhận độc lập (phiên
chạm hạn mức trước khi kiểm xong).
