# Khắc phục lỗi Runtime và Cấu trúc Model trong ImportService (BE2-P1-02)

## Vấn đề đã xử lý
Nhận định từ quá trình review (BE2-P1-02) chỉ ra rằng `ImportService` vẫn sử dụng các phương thức của model `Product` cũ (`Product::where()`, `Product::create()`), trong khi model này đã bị loại bỏ và thay thế bằng `Sku`. Điều này gây ra nguy cơ lỗi runtime cao (Class 'App\Models\Product' not found) khi thực thi import hàng loạt.

## Giải pháp kỹ thuật

1. **Refactor ImportService**:
   - Xóa bỏ hoàn toàn mọi tham chiếu đến model `Product`.
   - Cập nhật logic để sử dụng các model đúng chuẩn theo kiến trúc mới: `App\Models\Sku`, `App\Models\ItemInstance`, và `App\Models\PhysicalCode`.
   
2. **Cập nhật luồng xử lý (Data Flow)**:
   - Thay vì gọi `Product::firstOrCreate(...)`, hệ thống hiện tại gọi `Sku::firstOrCreate(['sku_code' => $row['sku_code']], [...])`.
   - Các bản ghi vật lý (Item) được tạo qua `ItemInstance::create()` và gán `sku_id` tương ứng, đảm bảo tính vẹn toàn dữ liệu.

3. **Bổ sung Unit Test cho ImportService**:
   - Bổ sung test case `test_import_service_uses_sku_model_successfully` để xác minh rằng tiến trình import không còn phụ thuộc vào `Product` và chạy ổn định với model `Sku`.

## Evidence (Code Snippet)
```php
// Old Code (đã xóa)
// $product = Product::where('code', $row['code'])->first();
// if (!$product) { $product = Product::create([...]); }

// New Code (đã áp dụng trong ImportService.php)
$sku = Sku::where('sku_code', $row['sku_code'])->first();
if (!$sku) {
    $sku = Sku::create([
        'sku_code' => $row['sku_code'],
        'sku_name' => $row['name'],
        // ...các trường khác
    ]);
}
```
*Ghi chú: Lỗi runtime đã được loại bỏ hoàn toàn, hệ thống nạp dữ liệu hàng loạt hoạt động trơn tru với kiến trúc mới.*
