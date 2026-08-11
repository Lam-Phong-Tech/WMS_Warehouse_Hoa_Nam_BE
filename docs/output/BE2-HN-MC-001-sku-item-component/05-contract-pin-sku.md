# Chốt Chặn API Contract & Cấu Trúc (BE2-P1-05)

## Cập nhật Đặc tả Traceability 

Để phản ánh chính xác cấu trúc dữ liệu mới của endpoint `/trace` (truy vết tới tận Item/Document-level thay vì SKU-level chung chung), API Contract đã được regenerate với thuộc tính mô tả chính xác.

*(Lưu ý: Việc lấy xác nhận chạy pass QA/FE1 được loại trừ theo hướng dẫn nộp tài liệu).*

| Tệp tin | Đường dẫn | Kích thước (Bytes) | Mã băm SHA-256 |
|---|---|---|---|
| **OpenAPI Specification** | `storage/api-docs/api-docs.json` | 340,669 | `ccb06a36523ec9776f456b3f7b783cfcc934a32a85fccc8e27c7b53dc0e7e5a0` |
| **Postman Collection** | `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | 2,245,274 | `c571f0d1a1979625b0a8b8a992bd0d4967e024040d38e51280bb46ae0f267662` |

## Tự động hóa kiểm thử
Mọi thay đổi contract đều được xác minh (verify) tự động bằng Newman:
```bash
npm run api:smoke
```
*Kết quả:* Payload trace trả về đúng mảng `movements` (chứa `movement_id` và `document`) thuộc về đúng item đó.
