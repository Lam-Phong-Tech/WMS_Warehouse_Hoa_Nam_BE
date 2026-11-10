# Thiết kế: Trường mô tả cho nhà cung cấp (NCC)

Ngày: 2026-11-10
Trạng thái: Đã được người dùng duyệt
Phạm vi: `inbound_suppliers` (DB), P04 supplier API, màn Danh sách NCC (FE)

## Mục đích

Bổ sung trường mô tả tự do cho nhà cung cấp để người dùng ghi lại thông tin
nhận diện/ghi chú ngắn, xem và sửa cùng lúc với tên/trạng thái NCC. Đây là
thay đổi nhỏ, không đổi vòng đời hay mã NCC.

## Mục tiêu

- Lưu được mô tả tối đa 500 ký tự, không bắt buộc, cho cả NCC cũ và mới.
- API tạo/sửa nhận `description`; API danh sách và phản hồi tạo/sửa trả lại.
- Tìm kiếm NCC theo mã, tên **hoặc** mô tả.
- Form Thêm/Sửa NCC có ô “Mô tả”; bảng danh sách có cột “Mô tả”.
- Giữ nguyên UUID, mã NCC, `version`/ETag, quyền và optimistic lock hiện hành.

## Không nằm trong phạm vi

- Không đổi định dạng `display_name`, không đưa mô tả vào tên hiển thị.
- Không backfill dữ liệu, không đổi mã NCC, không thêm quyền mới.
- Không đổi các màn hình nhập kho đang dùng danh mục NCC (ngoài dữ liệu trả về
  thêm trường mới, không phá vỡ hợp đồng cũ).

## Ràng buộc

- Migration phải chạy được trên PostgreSQL (production/test) và SQLite (test
  cục bộ): cột nullable, không dùng `after()`.
- `PATCH` vẫn yêu cầu `If-Match`; field lạ vẫn bị từ chối; body vẫn giới hạn
  16 KiB.
- Mô tả rỗng sau khi trim được lưu là `null`.
- FE phải chịu được response cũ chưa có `description` trong thời gian chuyển
  tiếp (schema cho phép thiếu → `null`).

## Phương án đã cân nhắc

1. **Cột riêng `description` trên `inbound_suppliers` (đã chọn)** — đơn giản,
   đúng 1-1 với NCC, tìm kiếm và validation dễ, không ảnh hưởng bảng khác.
2. Lưu trong JSON metadata — hiện bảng không có cột JSON phù hợp; phải thêm
   cột rồi vẫn phải tách để tìm kiếm/validate, phức tạp hơn.
3. Bảng `supplier_profiles` riêng — thừa cho một trường, thêm join và rủi ro
   đồng bộ trạng thái.

Chọn phương án 1.

## Thiết kế

### DB

Migration mới thêm:

```php
$table->string('description', 500)->nullable();
```

`down()` drop cột. Không đổi index hiện có.

### API

- `POST /api/v1/mini-app/inbound/suppliers`
  - Thêm field tùy chọn `description`: `nullable|string|max:500`.
  - Trim; `''` → `null`.
- `PATCH /api/v1/mini-app/inbound/suppliers/{id}`
  - Thêm `description` vào danh sách field được phép; vẫn bắt buộc ít nhất một
    field (`name`, `status`, `description`).
  - Trim; `''` → `null`.
- `GET /api/v1/mini-app/inbound/suppliers`
  - Trả `description` trong từng dòng.
  - `q` tìm không phân biệt hoa/thường trên `code`, `name`, `description`.
- Response của POST/PATCH trả `description`.
- OpenAPI: thêm property nullable vào schema dòng NCC, request body POST/PATCH;
  cập nhật mô tả tham số `q`.
- Không đổi ETag, version, quyền, mã lỗi hiện hành.

### Frontend

- `Supplier` type/schema có `description: string | null` (chấp nhận thiếu khi
  đọc).
- `SupplierPayload` có `description?: string | null`.
- Form Thêm/Sửa: `UiTextField` multiline 3 dòng, nhãn “Mô tả”, tối đa 500 ký
  tự, không bắt buộc; gửi chuỗi đã trim hoặc `null`.
- Bảng: thêm cột “Mô tả” giữa Tên và Trạng thái; hiển thị tối đa 2 dòng, có
  `title` đầy đủ; rỗng hiển thị “—”. Cập nhật `colgroup`, `colSpan`, CSS cột.
- Copy vi/en/zh: nhãn, placeholder/helper, trạng thái rỗng; cập nhật placeholder
  tìm kiếm thành mã, tên hoặc mô tả.
- Giữ nguyên hành vi phân quyền, offline, lỗi 412 và xác nhận bỏ thay đổi; mô
  tả nằm trong phép so sánh “dirty”.

### Kiểm thử

- BE feature test: tạo có/không có mô tả; trim và rỗng → null; quá 500 ký tự bị
  422; sửa mô tả tăng version; danh sách trả mô tả; tìm theo mô tả.
- FE service test: schema chấp nhận `description` null/thiếu; payload gửi mô tả
  đã trim.
- FE page test: form hiển thị/khôi phục mô tả; lưu gửi description; bảng hiển
  thị mô tả và “—” khi rỗng.
- Chạy test liên quan, typecheck, lint; kiểm tra giao diện thực tế nếu môi
  trường dev đang chạy.

## Quyết định để builder tự xử lý

- Câu chữ helper/placeholder tiếng Việt/Anh/Trung cụ thể, miễn đúng nghĩa.
- Cách rút gọn mô tả dài (CSS line-clamp + title) miễn không làm vỡ bảng.
- Thứ tự chạy migration/kiểm thử trong từng repo.
