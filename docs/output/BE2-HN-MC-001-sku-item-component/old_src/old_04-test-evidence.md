# Test evidence

## 1. Test mới cho 5 entity của task này

Lệnh: `php artisan test tests/Feature/ProductMasterTest.php tests/Feature/SkuManagementTest.php tests/Feature/PhysicalCodeTest.php tests/Feature/ImportBatchTest.php tests/Feature/ContainerTest.php tests/Feature/ItemInstanceTest.php`

```
   PASS  Tests\Feature\ProductMasterTest
  ✓ product master can be created listed and updated
  ✓ code must be unique within same master type but can repeat across types
  ✓ product master create writes audit log

   PASS  Tests\Feature\SkuManagementTest
  ✓ sku update sets brand category model and package variant
  ✓ sku update writes audit log

   PASS  Tests\Feature\PhysicalCodeTest
  ✓ physical code can be created with valid chip carrier
  ✓ item code type with non chip carrier is rejected on create
  ✓ item code type with non chip carrier is rejected on update too
  ✓ code value and hardware uid must be unique

   PASS  Tests\Feature\ImportBatchTest
  ✓ import batch can be created listed and updated
  ✓ import batch code must be unique
  ✓ import batch create writes audit log

   PASS  Tests\Feature\ContainerTest
  ✓ carton and box require primary sku id but tray does not
  ✓ physical code must be active to assign to container
  ✓ container update with matching version succeeds and stale version returns 409
  ✓ container item xor rule rejects both and neither
  ✓ remove item marks removed instead of deleting row
  ✓ container create writes audit log

   PASS  Tests\Feature\ItemInstanceTest
  ✓ serial tracked product requires physical code id on create
  ✓ non serial component does not require physical code id
  ✓ physical code cannot be assigned to two items via create or update
  ✓ update with matching version succeeds and bumps version
  ✓ update with stale version returns 409 and does not partially write
  ✓ trace endpoint returns full chain
  ✓ item instance create writes audit log

  Tests:    25 passed (74 assertions)
  Duration: 24.19s
```

### Bảng mục đích test "chống bug nguy hiểm" (theo yêu cầu test kỹ trước khi coi task xong)

| Test | Mục đích |
|---|---|
| `ItemInstanceTest::physical_code_cannot_be_assigned_to_two_items_via_create_or_update` | Chặn unique-active-mapping bị bypass qua **cả 2 đường** create lẫn update — không chỉ validate ở create |
| `ItemInstanceTest::update_with_stale_version_returns_409_and_does_not_partially_write` | Optimistic locking: version lệch → 409, **verify không có field nào bị ghi đè một phần** (`item_status`, `item_condition`, `version` đều giữ nguyên giá trị cũ) |
| `ContainerTest::container_item_xor_rule_rejects_both_and_neither` | XOR `item_id` vs `sku_id`+`quantity` bị chặn ở **4 trường hợp**: thiếu cả 2, có cả 2, có sku thiếu quantity, và 2 case hợp lệ |
| `ContainerTest::container_update_with_matching_version_succeeds_and_stale_version_returns_409` | Optimistic locking cho container, verify `zone_code` không bị đổi khi version lệch |
| `PhysicalCodeTest::item_code_type_with_non_chip_carrier_is_rejected_on_update_too` | Business rule ITEM+chip verify ở **update**, không chỉ create — chặn bypass đổi `code_type` sau khi tạo |
| `ContainerTest::carton_and_box_require_primary_sku_id_but_tray_does_not` + `assertDatabaseMissing` | Xác nhận không có row rác khi validate fail giữa chừng |

## 2. Regression toàn bộ suite (trước/sau so với baseline HN-FND-002)

Baseline trước task này (ghi trong `docs/output/BE2-HN-FND-002-master-danh-muc/04-test-evidence.md`): **54 passed / 1 failed** (pre-existing `dashboard-quick`).

Sau khi rename `products`→`sku` (đụng 10 bảng FK) + thêm 6 entity mới, chạy `php artisan test` (toàn bộ, không filter):

```
Tests:    81 passed, 1 failed (269 assertions)
Duration: ~50s
```

1 lỗi duy nhất — `StockDocumentIdempotencyTest::dashboard_quick_reports_period_totals_and_partial_documents` — **giống hệt baseline cũ**, xác nhận không phải do thay đổi của task này. Tất cả test liên quan trực tiếp đến bảng có FK `product_id` (`PermissionAndValidationTest`, `StockDocumentIdempotencyTest` — trừ dashboard-quick, `StockRequestAndTransferTest`) đều **pass** sau khi rename, bao gồm cả luồng post-receipt/scan/reverse/transfer đụng `stock_movements`/`inventory_balances`/`safe_stock_configs`.

## 3. FK integrity trên Postgres thật (không chỉ SQLite test)

Test suite dùng SQLite in-memory (theo `phpunit.xml`) nên không đủ để tin tưởng hành vi FK sau rename bảng trên Postgres thật (2 driver xử lý rename khác nhau). Đã verify riêng trên docker Postgres — xem `03-api-evidence.md` mục cuối:
- `migrate:fresh` chạy sạch, **2 lần liên tiếp** (idempotent) trên Postgres thật.
- Tạo `inbound-document` với line trỏ `product_id` vào SKU mới → insert thành công, không lỗi FK.
- Thử xoá SKU đang bị `stock_document_lines` tham chiếu → bị chặn đúng bởi `restrictOnDelete()` (`QueryException`), xác nhận constraint FK còn nguyên vẹn sau rename.

## 4. Test có sẵn từ trước (không đổi, verify vẫn pass sau khi sửa Product→Sku)

`UserManagementTest`, `RoleManagementTest`, `AgencyTest`, `DefectCatalogTest`, `PermissionAndValidationTest`, `PermissionCatalogTest`, `WarehouseManagementTest`, `StockDocumentIdempotencyTest`, `StockRequestAndTransferTest` — tất cả đã tồn tại từ task trước, phải sửa các chỗ tham chiếu `Product`/`code`/`name` → `Sku`/`sku_code`/`sku_name` (do rename) và `$product->id` → `$product->sku_id` (do đổi primary key), verify lại toàn bộ pass.
