# HN-BE-API-004 - Số liệu chính thức của hợp đồng API

> **Sinh tự động** bởi `node scripts/generate-api-contract-metrics.mjs` từ `storage/api-docs/api-docs.json`, `HN-BE-API-002` và `HN-BE-API-003`.
> Không sửa tay. CI chạy `--check` và cổng tổng WWHN-309 đối chiếu mọi con số trong tài liệu bàn giao với bảng này.

Lý do tài liệu này tồn tại: các con số contract trước đây được chép tay vào nhiều tài liệu khác nhau,
nên cùng một gói bàn giao có thể ghi hai giá trị khác nhau cho cùng một phép đếm (2267 vs 2268) trong khi
spec thật đã sang giá trị thứ ba. Từ nay chỉ có **một** nguồn số liệu và nó được sinh từ artifact thật.

| Chỉ số | Giá trị |
| --- | --- |
| Phiên bản OpenAPI | 2.0.0 |
| Path trong OpenAPI | 183 |
| Operation trong OpenAPI | 231 |
| Response trong OpenAPI | 2334 |
| Response có schema envelope | 2334/2334 |
| Schema trong components.schemas | 22 |
| Operation khai `Idempotency-Key` | 33 |
| Trong đó bắt buộc / tuỳ chọn | 23 / 10 |
| Operation khai `If-Match` | 58 |
| Error code trong catalog | 248 |
| Operation trong ma trận endpoint | 231 |
| Permission trong ma trận endpoint | 57 |
| Nhóm nghiệp vụ (tag) | 22 |
| Operation có data scope theo kho | 53 |
| Operation public (không JWT) | 9 |

## Cách dùng

1. FE/QA cần số liệu contract thì đọc bảng này, không đọc văn xuôi ở tài liệu khác.
2. Sau khi đổi route/annotation: chạy `php artisan l5-swagger:generate`, hai generator `HN-BE-API-002/003`, rồi `node scripts/generate-api-contract-metrics.mjs`.
3. Cổng tổng `node scripts/verify-wwhn-309-handover-contract.mjs` sẽ FAIL nếu bất kỳ tài liệu bàn giao nào còn phát biểu con số cũ.
