# Ánh Xạ Git Commit và Traceability (BE2-P2-01)

## Danh sách Ánh xạ Commit sang Acceptance Criteria (WWHN-37)

Dưới đây là bảng đối chiếu chi tiết giữa các mã Git commit trong lịch sử nhánh `m1-be-2` và các phần việc cụ thể thuộc phạm vi task WWHN-37 (HN-MC-001):

| Mã Commit (SHA) | Nội dung thay đổi kỹ thuật | Acceptance Criteria / Ý nghĩa nghiệp vụ |
|---|---|---|
| **`13eb33c`** | - Bổ sung mảng `stock_movements` vào `/trace` API của ItemInstance.<br>- Tạo mới migration guard đổi tên bảng `products -> sku` an toàn.<br>- Bổ sung các test suite kiểm thử tương ứng. | - **BE2-P1-01**: Hoàn tất chuỗi truy vết đầy đủ từ item tới stock movements/documents.<br>- **BE2-P1-04**: Giải quyết rủi ro migration dở dang ở các môi trường phát triển. |
| **`6c13c2a`** | - Chỉnh sửa file migration `2026_01_01_000031_create_products_table.php` tại chỗ: Đổi tên bảng tạo thành `sku`, đổi tên cột `id -> sku_id`, `code -> sku_code`, `name -> sku_name`. | - **WWHN-37**: Thiết lập cấu trúc dữ liệu cho SKU (đổi tên từ product cũ sang sku). |
| **`59b1721`** | - Tạo mới các bảng danh mục cấu trúc: `product_masters`, `physical_codes`, `item_instances`, `containers`, `container_items`, `import_batches`. | - **WWHN-37**: Triển khai toàn bộ mô hình thực thể SKU, item, component, mã vật lý và container. |

## Khuyến nghị Quy tắc Commit cho các lần sau

Để cải thiện chất lượng lịch sử Git và tính dễ dàng truy vết (traceability) tự động, các commit tiếp theo được khuyến nghị tuân thủ định dạng Conventional Commits sau:

```text
<kiểu>(<mã-task>): <mô tả ngắn bằng tiếng Việt>

Ví dụ:
- fix(WWHN-171): bổ sung stock_movements vào endpoint trace của item instance
- docs(WWHN-171): cập nhật tài liệu và mã băm SHA-256 cho API contract
```
Điều này giúp hệ thống tự động liên kết các thay đổi mã nguồn trực tiếp vào thẻ Jira/task liên quan mà không cần phải thực hiện ánh xạ thủ công.
