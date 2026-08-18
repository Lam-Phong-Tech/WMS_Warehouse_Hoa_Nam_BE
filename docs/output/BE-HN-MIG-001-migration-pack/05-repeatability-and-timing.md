# 5. Tính lặp lại và thời lượng — DEV260-P1-06

## 5.1 Ba lớp bảo vệ chống "double effect"

| Lớp | Tình huống | Kết quả |
|---|---|---|
| Replay theo run code | Chạy lại **cùng** run code + cùng checksum | Trả lại báo cáo đã lưu, `replayed = true`, không tạo run thứ hai |
| `business_key` theo run | Cùng run ghi lại dòng opening đã ghi | Bỏ qua, không cộng tồn |
| Khoá theo checksum | Chạy **run code khác** trên cùng package đã COMMIT | `BLOCKED / PACKAGE_ALREADY_COMMITTED` |

Lớp thứ ba là phần mới của WWHN-260. Trước đó, idempotency chỉ neo theo `run_code`: đổi
`--run-code` là sinh `business_key` khác, và toàn bộ tồn đầu bị cộng **lần thứ hai** mà không
có cảnh báo nào. Đây là rủi ro thật của một cutover chạy lại trong đêm.

Khoá tự mở sau khi rollback: `06-rollback.json` → `07-recommit-after-rollback.json` cho thấy
lần nạp mới `COMMITTED` bình thường.

## 5.2 Diễn tập hai lần dry-run + clean reset

| Bước | Artifact | Kết quả |
|---|---|---|
| Dry-run lần 1 | `01-dry-run.json` | `PASSED`, `replayed = false` |
| Dry-run lần 2 (cùng run code) | `02-dry-run-replay.json` | `PASSED`, `replayed = true` |
| Commit | `03-commit.json` | `COMMITTED` |
| Nạp lại package dưới run code khác | `05-double-load-blocked.json` | `BLOCKED`, tồn không đổi |
| Rollback | `06-rollback.json` | `ROLLED_BACK`, `residual_opening_qty = 0` |
| Nạp lại sau rollback | `07-recommit-after-rollback.json` | `COMMITTED` |

## 5.3 Thời lượng đo thật

Báo cáo có khối `timing` với `duration_ms` và `duration_source`. Thời lượng đo bằng đồng hồ
đơn điệu trong tiến trình, không suy ra từ `started_at`/`finished_at` (cột timestamptz chỉ có
độ phân giải giây, nên mọi run dưới 1 giây sẽ báo 0 ms — vô dụng cho việc ước lượng cửa sổ
cutover).

| Run | Quy mô | `duration_ms` |
|---|---|---|
| Dry-run diễn tập | 11 dòng / 6 dataset | 17 |
| Commit diễn tập | 11 dòng | 54 |
| Commit lại sau rollback | 11 dòng | 52 |
| Dry-run package lỗi | 15 dòng | 19 |
| **Dry-run capacity** | **30.000 dòng (3 file × 10.000)** | **353** |

`NFR-SRS-017` yêu cầu tối thiểu 10.000 dòng/file. Đo thật ở 10.000 dòng/file trên 3 file:
353 ms cho toàn bộ vòng validate + đối soát nguồn.

Điều kiện tuyến tính này có được nhờ cache tra cứu theo run: trước đó mỗi dòng opening/item
gọi lại `SELECT` cho cùng một mã kho/SKU, tức chi phí O(số dòng) truy vấn. Cache chỉ nhớ kết
quả **tìm thấy**, nên một mã chưa tồn tại lúc validate vẫn được tra lại sau khi master được ghi.

## 5.4 Ước lượng cửa sổ cutover

Từ số đo trên, phần validate/đối soát tăng gần tuyến tính theo số dòng (~12 µs/dòng trên máy
diễn tập). Chi phí thật của cutover production nằm ở bước ghi (COMMIT) và phụ thuộc hạ tầng
PostgreSQL, nên **`WWHN-261` phải đo lại trên UAT** với package thật rồi ghi vào evidence;
con số ở đây là baseline để phát hiện lệch bất thường, không phải cam kết SLA.
