# 1. Mapping dữ liệu đã duyệt — DEV260-P1-02

Bản đầy đủ: `docs/migration/DATA_MAPPING_v1.0.md` (`HN-MIG-MAP-001`, mapping_version **1.0**).
Bản máy đọc được: `config/migration_mapping.php`.

## 1.1 Vì sao mapping phải là artifact riêng

Register ghi "approved mapping/data owner chưa đóng". Trước WWHN-260, ánh xạ cột nguồn → cột
đích chỉ tồn tại **ngầm trong code** của `FinalDataLoadService`. Hệ quả thực tế:

- không ai duyệt được thứ không viết ra;
- dòng bị loại không có **chủ**, nên `WWHN-263` (cleansing log) không có đầu vào;
- đổi ngữ nghĩa một cột nguồn không buộc phải chạy lại dry-run vì không có version để so.

Nay ba thứ đó có thật: tài liệu mapping có version, config khai chủ dữ liệu theo dataset, và
mọi báo cáo run đều in `mapping_version` bên cạnh `package_version`.

## 1.2 Sáu dataset và chủ dữ liệu

| Dataset | Bảng đích | Chủ dữ liệu (chịu trách nhiệm nguồn + remediation) |
|---|---|---|
| AGENCY | `agencies` | BAS / Kinh doanh |
| SKU | `sku` (+ FK `brands`/`product_categories`/`product_models`) | BAS / Danh mục sản phẩm |
| PHYSICAL_CODE | `physical_codes` | BE2 / Quản trị mã vật lý |
| CONTAINER | `containers` | QL kho |
| ITEM | `item_instances` (+ `import_batches`) | QL kho |
| OPENING_BALANCE | `stock_movements` (`OPENING`) + `inventory_balances` | QL kho |

## 1.3 Điểm mapping phải được duyệt rõ

1. **`version_code`** của dataset SKU chính là cột "version" của §13.12 — không phải
   `sku.version` (optimistic locking).
2. **brand/category/model** trong §13.12 là **mã master**, giải sang FK; khai mà không giải
   được thì **loại dòng**, không ghi `null` im lặng.
3. **`target_legacy_key`** (PHYSICAL_CODE) và **`legacy_item_key`** (ITEM) chỉ dùng để đối
   chiếu nguồn, không có cột đích — đây là quyết định, không phải bỏ sót.
4. **`carrier`** (CONTAINER) không có cột đích: vật mang của mã nằm ở
   `physical_codes.carrier_type`.
5. **`source` / `owner_confirmation`** (OPENING_BALANCE) đi vào audit của run, không tạo cột
   mới trong ledger.
6. Bộ ba `(warehouse_code, sku_code, stock_status)` là chiều duy nhất của tồn đầu.

## 1.4 Cần ký

| Vai trò | Ký cái gì |
|---|---|
| BAS / Data Owner | Toàn bộ §1.2 và §1.3 |
| QL kho | Riêng dataset OPENING_BALANCE và cách đối soát |
