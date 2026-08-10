# Mapping commit ↔ AC cho WWHN-37 (BE2-P2-01)

## Hiện trạng

Commit gần nhất trên `m1-be-2` không gắn `WWHN-171`/`WWHN-37`, message chung chung:

```
6c13c2a fix api
59b1721 add api sprint-1
80c74aa fix base
4da92dd fix base
ea55fbf fix base
c42fd1e fix base
```

**Không rewrite lại message các commit này** — nhánh đã push lên remote, amend/rebase -i cần force-push, rủi ro cao hơn lợi ích, đặc biệt khi nhánh còn đang có các thay đổi khác chưa merge (xem `03-branch-sync-status.md`). Thay vào đó, mapping thủ công dưới đây bổ sung phần trace bị thiếu, riêng cho phạm vi WWHN-37.

## Mapping (phạm vi WWHN-37 / HN-MC-001)

| SHA | Nội dung thực tế (theo diff) | Entity/AC liên quan |
|---|---|---|
| `59b1721` (add api sprint-1) | Tạo mới `product_masters`, đổi `products`→`sku` (schema stub ban đầu), `physical_codes`, `item_instances`, `containers`/`container_items`, `import_batches`, cùng 10 migration repoint FK `product_id`→`sku` | WWHN-37 — toàn bộ entity SKU/item/component/physical code/container |
| `6c13c2a` (fix api) | Sửa migration `2026_01_01_000031_create_products_table.php` tại chỗ: `Schema::create('products')`→`Schema::create('sku')`, đổi tên cột `id/code/name`→`sku_id/sku_code/sku_name`, thêm cột brand/category/model/spec... | WWHN-37 — chính là nguồn gốc của flag BE2-P1-04 (không có migration ALTER/RENAME riêng) |
| `80c74aa`/`4da92dd`/`ea55fbf`/`c42fd1e` (fix base) | Baseline chung trước sprint-1 (điểm merge-base với `origin/m1`) | Nền tảng dùng chung, không gắn riêng WWHN-36/37 |

File/entity thuộc WWHN-37 cụ thể (để đối chiếu khi review từng AC): `Modules/Warehouse/database/migrations/2026_01_01_000030_create_product_masters_table.php`, `2026_01_01_000031_create_products_table.php` (nay tạo bảng `sku`), `2026_01_01_000044_create_physical_codes_table.php`, `2026_01_01_000045_create_import_batches_table.php`, `2026_01_01_000046_create_containers_table.php`, `2026_01_01_000047_create_item_instances_table.php`, `2026_01_01_000048_create_container_items_table.php`, cùng các Controller/Repository tương ứng (`ProductController`, `ProductMasterController`, `PhysicalCodeController`, `ItemInstanceController`, `ContainerController`, `ImportBatchController`).

## Khuyến nghị convention cho commit sau này

```
<type>(<task-key>): <mô tả ngắn>

vd: fix(WWHN-171): bổ sung stock_movements vào trace chain item instance
    docs(WWHN-171): pin OpenAPI/Postman sau breaking change sku_code/sku_name
```

Giúp `git log --grep="WWHN-171"` tìm lại đúng commit mà không cần mapping thủ công như trên.
