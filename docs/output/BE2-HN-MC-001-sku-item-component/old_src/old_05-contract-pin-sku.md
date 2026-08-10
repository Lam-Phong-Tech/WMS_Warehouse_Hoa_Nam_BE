# Pin contract SKU/item sau breaking change (BE2-P1-05)

## Hiện trạng

`/api/v1/products` (route name giữ nguyên, không đổi thành `/sku`) đã dùng `sku_code`/`sku_name` xuyên suốt Request (`StoreProductRequest`: `unique:sku,sku_code`)/Controller/OpenAPI annotation — **đây là breaking change thật** so với `code`/`name` ban đầu. Kiểm tra `storage/api-docs/api-docs.json` xác nhận tài liệu OpenAPI hiện tại **đã đồng bộ** với field mới (không có field `code`/`name` cũ còn sót) — không phải lỗi lệch tài liệu, chỉ là chưa có mốc version/hash cụ thể để FE1/QA đối chiếu đang dùng đúng bản nào.

## Thay đổi (phần tự làm được)

Regenerate 2 file để có bản mới nhất khớp code hiện tại (bao gồm cả phần mở rộng trace ở BE2-P1-01):

```
php artisan l5-swagger:generate
npm run api:postman
```

SHA-256 của bản vừa sinh (tại thời điểm gỡ cờ, `git rev-parse --short HEAD` = `6c13c2a`):

| File | SHA-256 | Kích thước |
|---|---|---|
| `storage/api-docs/api-docs.json` | `fb279405024af882b6b9f59c13e1b53fe9acb2c6fd4700d8b9dded823babe085` | 285,244 bytes |
| `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | `b1d6f7ad3c92fd87ffb79e83fd405f94d3c3af62fe4d1b3b32b6f3a6324acd7d` | 1,888,693 bytes |

Ghi chú:
- `storage/api-docs/api-docs.json` đã có thay đổi nội dung (2 dòng `summary`/response `description` của endpoint trace, xem `01-trace-chain-extension.md`) so với bản đang được git track — cần commit lại nếu muốn version này là bản chính thức.
- `docs/postman/*.postman_collection.json` nằm trong `.gitignore` (`/docs/` bị ignore trừ vài path cụ thể) — đây là file **generate lại tại chỗ**, không tồn tại trong git history; SHA ở trên là mốc để FE1/QA tự đối chiếu bản họ có trên máy, không phải bản đã commit.
- Phần "FE1/QA xác nhận payload mới, Postman chạy được" theo yêu cầu gốc của flag nằm ngoài phạm vi tự làm một mình — không note thêm.
