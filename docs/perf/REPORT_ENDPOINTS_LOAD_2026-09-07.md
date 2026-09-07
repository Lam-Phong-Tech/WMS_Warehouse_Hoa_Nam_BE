# Đo tải nhóm endpoint báo cáo — 07/09/2026

**Phạm vi hẹp có chủ đích.** Tài liệu này KHÔNG thay thế
[`LOAD_TEST_10000_USERS.md`](LOAD_TEST_10000_USERS.md) — tài liệu đó trả lời câu
hỏi 10.000 người dùng trên **hồ sơ chịu tải** (2 replica, PgBouncer, mã phục vụ
từ `/srv/app`) và kết luận **NOT PASSED**, trần ~200–205 req/s. Ở đây chỉ đo
nhóm `/reports/*` trên **stack DEV đang chạy**, để trả lời một câu hẹp: nhóm
endpoint báo cáo cư xử thế nào khi nhiều vai trò gọi đồng thời.

## 0. Điều kiện đo (đọc trước mọi con số)

| Hạng mục | Giá trị |
|---|---|
| Stack | **DEV** — 1 backend, mã qua **bind mount Windows**, KHÔNG PgBouncer, KHÔNG nginx tuning |
| Bộ sinh tải | `load/report-endpoints-load.mjs`, chạy **cùng máy** với hệ thống |
| Tài khoản | 6 vai đăng nhập sẵn (super-admin, admin, leadership, warehouse-manager, warehouse-staff, viewer), mỗi request bốc ngẫu nhiên một token |
| Dữ liệu | 2.714–3.794 `stock_movements` (dữ liệu dev, nhỏ hơn production) |
| Mục tiêu SLO đối chiếu | p95 ≤ 1,5 s |

Stack DEV chậm hơn hồ sơ chịu tải khoảng **5 lần** (trần ~40 req/s so với
~200 req/s) — chênh lệch này khớp với ghi nhận cũ về chi phí bind mount
150–500 ms/request. **Không được dùng số ở đây để nói về năng lực production.**

## 1. Kết quả theo mức đồng thời

| Luồng đồng thời | Throughput | p50 | p95 | p99 | Lỗi | SLO p95 ≤ 1,5 s |
|---:|---:|---:|---:|---:|---:|---|
| 10 | 33,4 req/s | 283 ms | **421 ms** | 536 ms | 0,00 % | **ĐẠT** |
| 25 | 41,5 req/s | 561 ms | **858 ms** | 1.036 ms | 0,00 % | **ĐẠT** |
| 50 | 36,3 req/s | 1.186 ms | **2.628 ms** | 3.424 ms | 0,00 % | **KHÔNG ĐẠT** |

**Điểm bão hoà của stack DEV nằm giữa 25 và 50 luồng** (~40 req/s). Ở mức 50,
throughput **giảm** so với mức 25 trong khi độ trễ gấp ba — dấu hiệu kinh điển
của hàng đợi chứ không phải của thêm năng lực. Tỉ lệ lỗi giữ 0 % ở cả ba mức:
hệ thống chậm đi chứ không cắt dịch vụ (khác hẳn lớp lỗi `no live upstreams`
của nginx đã ghi ở tài liệu 10.000 người).

## 2. Theo từng endpoint (mức 25 luồng)

| Endpoint | n | p50 | p95 | p99 | KB/request (đã giải nén) |
|---|---:|---:|---:|---:|---:|
| `dashboard-quick` | 289 | 547 ms | 799 ms | 999 ms | 9 |
| `movement-trace` (per_page=50) | 182 | 541 ms | 805 ms | 959 ms | 36 |
| `inventory` (per_page=20) | 223 | 559 ms | 813 ms | 973 ms | 17 |
| `inventory-balance` (per_page=50) | 142 | 561 ms | 923 ms | 1.064 ms | 41 |
| `warehouses` (per_page=20) | 138 | 553 ms | 900 ms | 1.091 ms | 1 |
| **`movement-trace` (KHÔNG phân trang)** | 89 | 717 ms | 961 ms | 1.219 ms | **4.438** |

## 3. Ba điều số liệu nói ra

### 3.1 Payload chứ không phải truy vấn là vấn đề của báo cáo truy vết

`EXPLAIN (ANALYZE)` của chính truy vấn ledger (3 join, khoảng thời gian cả năm):
**6,762 ms**. Nghĩa là CSDL đóng góp khoảng **0,2 %** thời gian phản hồi. Phần
còn lại là PHP dựng 3.794 mảng + mã hoá JSON + ghi cache.

Kích thước: **4.544.336 byte** giải nén, **604.079 byte** trên dây (gzip của
nginx đang bật, tỉ lệ 7,5×). Nói cách khác:

- băng thông: 590 KB/lượt mở màn Truy vết hàng hoá;
- phía trình duyệt: vẫn phải `JSON.parse` **4,3 MB**.

Endpoint phân trang cùng bộ lọc chỉ tốn **36 KB**. Chênh lệch **123 lần**.

### 3.2 Bất biến "KPI mô tả cùng một tập" đã được BE bảo đảm sẵn

Đo trực tiếp trên stack: `summary` và `by_movement_type` của lượt gọi
`per_page=50` **giống hệt** lượt gọi không phân trang
(`total_movements: 2834, net_effect: 4178, reversal_count: 33` và cùng 5 dòng
cơ cấu theo `movement_type`). BE tính chúng bằng aggregate riêng TRƯỚC khi cắt
trang.

Đây là **lệch giữa tài liệu và code**: ghi chú ở
`src/modules/reports/movement-trace/movement-trace-api.ts` nói FE không gửi
`per_page` vì "bảng, timeline và 4 thẻ KPI phải mô tả CÙNG một tập". Với **thẻ
KPI và biểu đồ cơ cấu**, lý do đó **không còn đúng** — BE đã lo. Phần còn đúng
là **timeline** và **hai bộ lọc client-side**, vốn dựng từ chính `rows`.
Test `MovementTracePagingCacheTest::test_summary_and_by_movement_type_stay_period_wide_on_every_page`
khoá lời bảo đảm này lại.

### 3.3 Lớp lỗi khoá cache đã được biết — nhưng sót đúng một endpoint

`StockCacheInvalidationTest::test_sql_paginated_report_separates_pages_in_cache`
(đã có từ trước) chặn đúng lớp lỗi này cho nhóm BaM1 (`/reports/warranty-defects`).
`movement-trace` cắt trang ở SQL theo cách khác nên lọt lưới. Đã đóng bằng
`tests/Feature/Cache/MovementTracePagingCacheTest.php`.

## 4. Cách chạy lại

```bash
node load/report-endpoints-load.mjs --concurrency 25 --seconds 25 --label check
```

Muốn số nói được về production thì phải dựng hồ sơ chịu tải trước:

```bash
docker compose -f docker-compose.yml -f docker-compose.perf.yml up -d
```

## 5. Việc chưa làm (nêu rõ để không ai hiểu nhầm là đã xong)

1. **Chưa đo đường GHI dưới tải** (post-receipt / post-issue / putaway). Toàn bộ
   số ở đây là đường ĐỌC.
2. **Chưa đo lại trên hồ sơ chịu tải sau bản sửa khoá cache** — cần một lượt
   `PROFILE=baseline` của k6 để so với `load/results/plateau200-allfixes.json`.
3. **Chưa chứng minh 10.000 người dùng.** Xem `LOAD_TEST_10000_USERS.md`: trần đo
   được là ~200–205 req/s trên hồ sơ chịu tải, thiếu ~25 % năng lực ngay cả với
   giả định think-time 40 giây.
