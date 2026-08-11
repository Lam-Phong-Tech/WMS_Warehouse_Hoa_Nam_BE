# Kiến trúc Transaction All-or-Nothing và Chuẩn hóa Mapping (BE2-P1-02)

## Giải quyết triệt để sự cố Partial-Write và Mapping dữ liệu

Phản hồi P1-02 chỉ ra rằng tiến trình import vẫn bị lưu thành công một phần (partial-write) nếu tệp trộn lẫn dữ liệu đúng và sai, đồng thời việc mapping giữa code của file và `sku_code` của Database bị lệch. Toàn bộ đã được khắc phục.

1. **Chuẩn hóa Mapping Dữ liệu Đầu vào**:
   - Headers file nạp dữ liệu (CSV/Excel) được bắt buộc sử dụng định dạng tên cột chuẩn: `sku_code`, `sku_name` (thay vì `code`, `name` cũ của bảng Product).
   - Tiến trình `parseRow()` đọc trực tiếp và ánh xạ chuẩn xác vào các field `sku_code`, `sku_name` khi insert, đảm bảo dữ liệu toàn vẹn ngay từ tầng Service.

2. **Cơ chế All-or-Nothing qua DB Transaction**:
   - Toàn bộ tiến trình nạp (vòng lặp parse rows) được bao bọc trong `DB::beginTransaction()`.
   - Nếu phát hiện **BẤT KỲ LỖI NÀO** (`$errorRows > 0`) ở bất kỳ dòng nào, hệ thống sẽ gọi `DB::rollBack()`.
   - Các bản ghi (dù hợp lệ trước đó) cũng không được ghi vào database, đảm bảo dữ liệu ở trạng thái No-Side-Effect đúng với chuẩn QA2.

## Evidence (Code Snippet ImportService.php)
```php
DB::beginTransaction();
try {
    foreach ($rows as $index => $row) {
        // ... (xử lý validation và Sku::create)
    }

    if ($errorRows > 0) {
        DB::rollBack(); // Hủy toàn bộ thay đổi nếu có lỗi (All-or-Nothing)
        $insertedRows = 0; $updatedRows = 0; $partialWrite = false;
    } else {
        DB::commit(); // Chỉ commit khi 100% hợp lệ
    }
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```
