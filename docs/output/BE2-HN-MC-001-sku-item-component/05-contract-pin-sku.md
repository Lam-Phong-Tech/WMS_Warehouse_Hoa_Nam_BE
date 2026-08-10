# Chốt Chặn Mã Băm API Contract (BE2-P1-05)

## Hiện trạng và Đồng bộ Contract

Tài liệu API đã được cập nhật đồng bộ để phản ánh các thay đổi lớn trong cấu trúc dữ liệu của SKU (sử dụng trường `sku_code` và `sku_name` thay cho `code` và `name` cũ), đồng thời bổ sung cấu trúc đầu ra của endpoint truy vết `/trace` bao gồm mảng `stock_movements` liên kết.

Hệ thống đã thực hiện regenerate lại toàn bộ tài liệu đặc tả OpenAPI và Postman collection bằng các lệnh:
```bash
php artisan l5-swagger:generate
npm run api:postman
```

## Thông tin Mã băm SHA-256 Chính Thức

Để đảm bảo tính nhất quán và làm mốc đối chiếu phiên bản chính xác cho mã nguồn tại commit hiện tại, thông tin kích thước và mã băm SHA-256 của các tệp tin đặc tả giao tiếp được xác định như sau:

| Tệp tin | Đường dẫn | Kích thước (Bytes) | Mã băm SHA-256 |
|---|---|---|---|
| **OpenAPI Specification** | `storage/api-docs/api-docs.json` | 340,669 | `ccb06a36523ec9776f456b3f7b783cfcc934a32a85fccc8e27c7b53dc0e7e5a0` |
| **Postman Collection** | `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | 2,245,274 | `c571f0d1a1979625b0a8b8a992bd0d4967e024040d38e51280bb46ae0f267662` |

Các mã băm này dùng để kiểm tra tính toàn vẹn và khớp phiên bản giữa các môi trường phát triển độc lập.
