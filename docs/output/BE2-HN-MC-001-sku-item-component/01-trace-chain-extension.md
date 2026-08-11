# Mở rộng chuỗi truy vết (trace chain) cấp độ Item/Physical Code (BE2-P1-01)

## Giải quyết truy vết cấp Item (Item-level Traceability) và Negative Testing

Sau phản hồi P1-01, endpoint `GET /api/v1/item-instances/{id}/trace` đã được refactor hoàn chỉnh để không chỉ lọc theo `sku_id` mà còn truy vết chính xác các `stock_movements` dựa trên **chứng từ khởi tạo (document)** của riêng cá thể đó thông qua `import_batch_id`.

**Bằng chứng kiểm thử Negative Test (ItemInstanceTraceTest.php)**:
Chúng tôi đã bổ sung kịch bản kiểm thử độc lập `test_trace_returns_only_movements_for_specific_item_instance_not_just_sku` để chứng minh tính phân lập:
1. Tạo 2 Item (`Item A`, `Item B`) thuộc **cùng một mã SKU**, nhưng nằm ở 2 lô nhập khác nhau (khác `import_batch_id`).
2. Tạo các chứng từ kho và biến động kho (StockMovements) riêng biệt cho từng lô.
3. **Assert**: Khi gọi API trace cho `Item A`, hệ thống *chỉ* trả về movement của `Item A`, và **hoàn toàn loại trừ** movement của `Item B` (Negative Test).

## Evidence (Trích xuất Test Case Mới)
```php
// Trích xuất ItemInstanceTraceTest.php
$response = $this->getJson("/api/v1/item-instances/{$itemA->item_id}/trace");
$response->assertStatus(200);

$movements = $response->json('data.movements');
$this->assertCount(1, $movements); // Chỉ có 1 movement của A
$this->assertEquals($movementA->id, $movements[0]['movement_id']); // Đúng ID của A
$this->assertNotEquals($movementB->id, $movements[0]['movement_id']); // KHÔNG bị lẫn movement của B
```
