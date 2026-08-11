# Chốt Chặn API Contract & Cấu Trúc (BE2-P1-05)

## Cập nhật Contract và Hash SHA-256

API Contract OpenAPI và Postman Collection đã được cập nhật đồng bộ để phù hợp với kiến trúc `Sku` và kết quả trả về của chuỗi truy vết (`/trace`).

*Kích thước và mã băm SHA-256 được chốt tại commit hiện tại:*

| Tệp tin | Đường dẫn | Kích thước (Bytes) | Mã băm SHA-256 |
|---|---|---|---|
| **OpenAPI Specification** | `storage/api-docs/api-docs.json` | 340,669 | `ccb06a36523ec9776f456b3f7b783cfcc934a32a85fccc8e27c7b53dc0e7e5a0` |
| **Postman Collection** | `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | 2,245,274 | `c571f0d1a1979625b0a8b8a992bd0d4967e024040d38e51280bb46ae0f267662` |

## Tự động hóa kiểm thử
Mọi thay đổi contract đều được xác minh (verify) tự động bằng Newman:
```bash
npm run api:smoke
```
*Kết quả:* Các API đảm bảo tính nhất quán (Consistent), payload request và response khớp chính xác với đặc tả đã chốt.
