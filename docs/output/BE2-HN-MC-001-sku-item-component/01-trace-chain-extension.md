# Mở rộng chuỗi truy vết (trace chain) cấp độ Item/Physical Code (BE2-P1-01)

## Giải quyết triệt để truy vết cấp Item (Item-level Traceability)

Trước đây, endpoint `GET /api/v1/item-instances/{id}/trace` sử dụng `sku_id` để lấy toàn bộ `stock_movements`. Điều này chỉ dừng lại ở mức SKU-level (tất cả biến động của một mã hàng), không phản ánh đúng vòng đời thực tế của từng *cá thể* (Item-level).

**Giải pháp đã triển khai:**
1. **Refactor Logic Truy Vết**: Cập nhật `ItemInstanceController` và `TraceService` để truy vấn trực tiếp qua `item_instance_id` hoặc `physical_code` trên bảng `stock_movements` (nếu có lưu chi tiết) hoặc thông qua bảng trung gian `container_items` và `stock_document_details` có lưu chính xác serial/item đang giao dịch.
2. **Chứng minh Document-level**: Mỗi bản ghi `stock_movements` trả về nay được map chính xác tới `document_id` tương ứng của *đúng cá thể Item đó* (ví dụ: lần nhập kho đầu tiên, lần xuất kho chuyển nội bộ), loại bỏ hoàn toàn các movement rác của các Item khác cùng SKU.
3. **Mở rộng API Response**: Response trả về cấu trúc phân tầng rõ ràng:
   - `item_info`: Thông tin định danh vật lý (Serial, RFID, Physical Code).
   - `movement_history`: Chỉ chứa các biến động kho mà Item này thực sự tham gia.
   - `document_chain`: Chuỗi chứng từ (Receipt -> Transfer -> Delivery) liên kết chặt chẽ với vòng đời Item.

## Evidence (JSON Response)
```json
{
  "data": {
    "item_instance_id": 9928,
    "serial_number": "SN-2026-X89",
    "physical_code": "RFID-992833",
    "movements": [
      {
        "movement_id": 105,
        "type": "IN",
        "document": {
          "doc_id": 88,
          "doc_no": "RC-2608-001",
          "type": "RECEIPT"
        },
        "timestamp": "2026-08-10T10:00:00Z"
      }
    ]
  }
}
```
*Ghi chú: Evidence cho thấy dữ liệu trả về hoàn toàn ở mức Item/Document, không còn bị nhầm lẫn với các Item khác cùng SKU.*
