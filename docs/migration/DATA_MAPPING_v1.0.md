# Migration Data Mapping v1.0 — Import Package v1.2 → HoaNam WMS

- Mã tài liệu: `HN-MIG-MAP-001`
- Phiên bản mapping: **1.0** (khớp `config/migration_mapping.php` → `mapping_version`)
- Package áp dụng: **Import Package v1.2** (SRS §13.12, `WWHN-333`)
- Ticket: `WWHN-260` (DEV260-P1-02), thực thi tại `WWHN-299`, dữ liệu nguồn tại `WWHN-225`
- Baseline: SRS/FRD v1.12 §13.12 + §14 Data Dictionary; ERD `HN-WMS-ERD-DD-001`
- Trạng thái: **CHỜ DUYỆT** — BAS/Data Owner ký tại comment Jira `WWHN-260`

> Mapping này là hợp đồng dữ liệu **duy nhất** của lần nạp cuối. Đổi bất kỳ dòng
> nào ⇒ tăng `mapping_version` ⇒ phải chạy lại dry-run, vì ngữ nghĩa cột nguồn đã
> khác (SRS §13.12: "File changed after dry-run requires a new checksum and rerun").

---

## 0. Nguyên tắc chung

| # | Nguyên tắc | Nguồn |
|---|---|---|
| 1 | Nạp bằng **controlled job** `migration:final-load`, không có endpoint public/admin. | `DEC-MIG-API-001` |
| 2 | Thứ tự nạp cố định `AGENCY → SKU → PHYSICAL_CODE → CONTAINER → ITEM → OPENING_BALANCE`; FK phải tồn tại trước khi dataset phụ thuộc được ghi. | ERD §4 |
| 3 | Master đã có được **cập nhật theo mã** (`updateOrCreate` theo business code), không tạo bản trùng. | REQ-SRS-034 |
| 4 | Tồn đầu kỳ chỉ được ghi qua movement `OPENING` + cập nhật `inventory_balances` trong **một** transaction. | ERD 43/44, `AC-GWT-MIG-001` |
| 5 | Container/Item là subledger định danh — **không** được sinh thêm tồn. | SRS §13.12 |
| 6 | Dòng không giải được tham chiếu bị **loại**, không được nạp một phần. | SRS §13.12 |

---

## 1. AGENCY → `agencies` (ERD bảng 12/13 nhóm B)

| Cột nguồn | Bắt buộc | Đích | Quy tắc | Lỗi khi sai |
|---|---|---|---|---|
| `agency_code` | Có | `agencies.agency_code` | Khoá nghiệp vụ; UNIQUE; trùng trong package ⇒ loại. | `REQUIRED` / `DUPLICATE_CODE` |
| `agency_name` | Có | `agencies.agency_name` | Giữ nguyên. | `REQUIRED` |
| `phone` | Không | `agencies.phone` | **PII** — mask khi ghi error file. | — |
| `address` | Không | `agencies.address` | **PII** — mask khi ghi error file. | — |
| `status` | Có | `agencies.status` | `ACTIVE` \| `INACTIVE`. | `INVALID_ENUM` |

**Data owner:** BAS / Kinh doanh.

---

## 2. SKU → `sku` (ERD bảng 16)

| Cột nguồn | Bắt buộc | Đích | Quy tắc | Lỗi khi sai |
|---|---|---|---|---|
| `sku_code` | Có | `sku.sku_code` | Khoá nghiệp vụ, UNIQUE. | `REQUIRED` / `DUPLICATE_CODE` |
| `sku_name` | Có | `sku.sku_name` | Giữ nguyên. | `REQUIRED` |
| `sku_type` | Có | `sku.sku_type` | `PRODUCT` \| `COMPONENT`. | `INVALID_ENUM` |
| `unit` | Có | `sku.unit` | Đơn vị tính. | `REQUIRED` |
| `is_serial_tracked` | Có | `sku.is_serial_tracked` | `TRUE/FALSE/1/0`; quyết định ràng buộc serial ở dataset ITEM. | `INVALID_TYPE` |
| `version_code` | Có | `sku.version_code` | Phiên bản catalog của SKU (§13.12 gọi là "version"). | `REQUIRED` |
| `status` | Có | `sku.status` | `ACTIVE` \| `INACTIVE`. | `INVALID_ENUM` |
| `brand_code` | Không | `sku.brand_id` | Giải sang `brands.brand_code → brands.id`. Khai mà không giải được ⇒ **loại dòng**, không ghi null im lặng. | `UNKNOWN_FK` |
| `category_code` | Không | `sku.category_id` | Giải sang `product_categories.category_code`. | `UNKNOWN_FK` |
| `model_code` | Không | `sku.model_id` | Giải sang `product_models.model_code`. | `UNKNOWN_FK` |

**Ghi chú §13.12:** cột "brand/category/model" trong bảng đặc tả là **mã master**, không phải
chuỗi tự do. Không khai ⇒ giữ nguyên giá trị hiện có của SKU (không xoá trắng).

**Data owner:** BAS / Danh mục sản phẩm.

---

## 3. PHYSICAL_CODE → `physical_codes` (ERD bảng 21)

| Cột nguồn | Bắt buộc | Đích | Quy tắc | Lỗi khi sai |
|---|---|---|---|---|
| `code_value` | Có | `physical_codes.code_value` | UNIQUE toàn hệ thống. | `REQUIRED` / `DUPLICATE_CODE` |
| `code_type` | Có | `physical_codes.code_type` | `ITEM` \| `CONTAINER` \| `SKU`. | `INVALID_ENUM` |
| `carrier_type` | Có | `physical_codes.carrier_type` | `BARCODE` \| `QR` \| `RFID` \| `NFC` \| `MANUAL`. | `INVALID_ENUM` |
| `hardware_uid` | Có **khi** `carrier_type=RFID` | `physical_codes.hardware_uid` | UNIQUE khi có giá trị; **PII kỹ thuật** ⇒ mask ở error file. | `REQUIRED` / `DUPLICATE_CODE` |
| `target_legacy_key` | Không | — (chỉ để đối chiếu với `ITEM.legacy_item_key`) | Không ghi vào schema; dùng để dò khớp thủ công khi cleansing. | — |
| `status` | Có | `physical_codes.status` | `ACTIVE` \| `INACTIVE` \| `LOST` \| `DAMAGED`. | `INVALID_ENUM` |

**Data owner:** BE2 / Quản trị mã vật lý.

---

## 4. CONTAINER → `containers` (ERD bảng 22)

| Cột nguồn | Bắt buộc | Đích | Quy tắc | Lỗi khi sai |
|---|---|---|---|---|
| `container_code` | Có | `containers.container_code` | UNIQUE. | `REQUIRED` / `DUPLICATE_CODE` |
| `type` | Có | `containers.container_type` | `CARTON` \| `BOX` \| `TRAY`. | `INVALID_ENUM` |
| `carrier` | Không | — | Thông tin vật mang của mã; hình dạng thật nằm ở `physical_codes.carrier_type`. | — |
| `physical_code` | Không | `containers.physical_code_id` | Giải theo `physical_codes.code_value`. | `UNKNOWN_FK` |
| `primary_sku` | Không | `containers.primary_sku_id` | Giải theo `sku.sku_code`. | `UNKNOWN_SKU` |
| `parent_tray` | Không | `containers.parent_container_id` | Giải theo `containers.container_code` (khay cha). | `UNKNOWN_FK` |
| `status` | Có | `containers.status` | `EMPTY` \| `IN_STOCK` \| `PARTIAL` \| `ISSUED` \| `RECEIVED_PENDING_PUTAWAY` \| `STORED` \| `CLOSED`. | `INVALID_ENUM` |

Mọi container do run tạo mang `migration_run_id` để rollback xác định đúng tập bản ghi.
**Container không sinh movement** — đối soát `CONTAINER_MAPPING_NO_EXTRA_STOCK` chặn điều ngược lại.

**Data owner:** QL kho.

---

## 5. ITEM → `item_instances` (ERD bảng 20)

| Cột nguồn | Bắt buộc | Đích | Quy tắc | Lỗi khi sai |
|---|---|---|---|---|
| `legacy_item_key` | Có | — (khoá đối chiếu nguồn) | UNIQUE trong package; dùng làm `serial_number` khi SKU không serial-tracked. | `REQUIRED` / `DUPLICATE_CODE` |
| `sku_code` | Có | `item_instances.sku_id` | Giải theo `sku.sku_code`; bắt buộc giải được. | `UNKNOWN_SKU` |
| `serial_number` | Có **khi** SKU `is_serial_tracked=TRUE` | `item_instances.serial_number` | UNIQUE; **PII kỹ thuật** ⇒ mask ở error file. | `REQUIRED` / `DUPLICATE_CODE` |
| `code_value` | Không | `item_instances.physical_code_id` | Giải theo `physical_codes.code_value`. | `UNKNOWN_FK` |
| `condition` | Có | `item_instances.item_condition` | `NORMAL` \| `DEMO` \| `DEFECTIVE` \| `WARRANTY_RETURN` \| `NON_SELLABLE`. | `INVALID_ENUM` |
| `import_batch` | Không | `item_instances.import_batch_id` | Tạo/khớp `import_batches.import_batch_code`, gắn `migration_run_id`. | — |

`item_status` được đặt `IN_STOCK` cho mọi item nạp từ cutover; item do run tạo mang `migration_run_id`.

**Data owner:** QL kho.

---

## 6. OPENING_BALANCE → `stock_movements` + `inventory_balances` (ERD bảng 43/44)

| Cột nguồn | Bắt buộc | Đích | Quy tắc | Lỗi khi sai |
|---|---|---|---|---|
| `warehouse_code` | Có | `stock_movements.warehouse_id` | Giải theo `warehouses.code`; kho phải tồn tại từ trước. | `UNKNOWN_FK` |
| `sku_code` | Có | `stock_movements.product_id` / `sku_id` | Giải theo `sku.sku_code`. | `UNKNOWN_SKU` |
| `stock_status` | Có | `stock_movements.stock_status` | `AVAILABLE` \| `NON_SELLABLE` \| `DEFECTIVE` \| `QUARANTINE`. | `INVALID_ENUM` |
| `quantity` | Có | `qty_delta` / `inventory_balances.on_hand_qty` | Số nguyên dương (tồn kho là integer theo ERD). | `INVALID_TYPE` |
| `source` | Có | ghi vào audit của run | Ví dụ `CUTOVER_COUNT` — nguồn kiểm kê đã duyệt. | `REQUIRED` |
| `owner_confirmation` | Có | ghi vào audit của run | Xác nhận của QL kho; **PII/định danh** ⇒ mask ở error file. | `REQUIRED` |

Bộ ba `(warehouse_code, sku_code, stock_status)` là **chiều duy nhất** của tồn đầu; lặp bộ ba ⇒
`DUPLICATE_CODE`. Movement mang `business_key = OPENING|<run_id>|<wh>|<sku>|<status>` nên chạy lại
cùng run là idempotent.

**Data owner:** QL kho (ký duyệt bằng `--approved-by`).

---

## 7. Quy tắc loại dòng và quyền xử lý

| error_code | Nghĩa | Ai xử lý | Cách xử lý |
|---|---|---|---|
| `REQUIRED` | Thiếu giá trị bắt buộc | Data owner của dataset | Bổ sung giá trị vào file nguồn, chạy lại dry-run |
| `INVALID_ENUM` / `INVALID_TYPE` | Giá trị ngoài enum/kiểu | Data owner của dataset | Sửa về enum baseline |
| `DUPLICATE_CODE` | Trùng khoá trong package | Data owner của dataset | Hợp nhất hoặc loại dòng trùng |
| `UNKNOWN_SKU` / `UNKNOWN_FK` | Tham chiếu không tồn tại | Data owner của dataset **đích** | Bổ sung master hoặc sửa mã tham chiếu |
| `RECON_MISMATCH` | Đối soát lệch | QL kho + BE | Đối chiếu lại nguồn tồn đã duyệt |
| `CHECKSUM_CHANGED` | File đổi sau dry-run | Cutover operator | Dùng run code mới, chạy lại từ dry-run |

Chủ dữ liệu theo dataset được khai máy-đọc-được ở `config/migration_mapping.php` và in ra cột
`data_owner` của error file (`migration:verify-run --error-file=...`).

---

## 8. Tiêu chí PASS (không được nới)

**Dry-run PASS** — SRS §13.12:
`critical_error = 0`; `duplicate active item/code/hardware_uid = 0`; `unknown required FK = 0`;
`required/enum/type error = 0`; `valid + invalid + duplicate = input row count`;
checksum + `package_version` + `migration_run_id` đã ghi.

**Reconciliation PASS** — SRS §13.12:
100% lượng SKU khớp giữa nguồn đã duyệt và ledger/balance; lượng của SKU serial-tracked bằng số
Item hợp lệ duy nhất; container mapping không sinh thêm tồn; QL kho ký kết quả.

**Commit bị chặn khi FAIL.** Rollback phải chứng minh không còn subset master/opening/movement.

---

## 9. Ký duyệt

| Vai trò | Nội dung duyệt | Ghi ở đâu |
|---|---|---|
| BAS / Data Owner | Mapping 6 dataset + chủ dữ liệu từng nguồn (mục 1–7) | Comment `WWHN-260` |
| QL kho | Tồn đầu kỳ và cách đối soát (mục 6, 8) | Comment `WWHN-260` + `--approved-by` khi COMMIT |
| Tech Lead | Diễn giải rollback append-only và tiêu chí PASS (mục 8) | Comment `WWHN-260` |
