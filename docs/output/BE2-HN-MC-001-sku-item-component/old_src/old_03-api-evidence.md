# API evidence (chạy thật qua Postgres/docker, không phải SQLite test)

Toàn bộ request dưới đây chạy qua nginx thật (`http://localhost:8080`) với user `admin` (SUPER_ADMIN) trên database Postgres của docker-compose, sau `migrate:fresh --seed`.

## 401 — chưa xác thực

```
GET /api/v1/containers (không có Authorization header)
→ 401
{"success":false,"message":"Chưa xác thực","error":{"code":"UNAUTHENTICATED","details":[]}}
```

## Product Master — tạo brand

```
POST /api/v1/product-masters {"master_type":"BRAND","code":"BR-SMOKE","name":"Smoke Brand"}
→ 201
{"success":true,"message":"Tạo product master thành công",
 "data":{"master_type":"BRAND","code":"BR-SMOKE","name":"Smoke Brand","status":"ACTIVE","master_id":"019fc118-..."}}
```

## Physical Code — ITEM + QR (hợp lệ) vs ITEM + MANUAL_CODE (business rule 422)

```
POST /api/v1/physical-codes {"code_value":"SMOKE-IT-1","code_type":"ITEM","carrier_type":"QR"}
→ 201  {"success":true,"data":{"code_value":"SMOKE-IT-1","code_type":"ITEM","carrier_type":"QR",...}}

POST /api/v1/physical-codes {"code_value":"SMOKE-IT-BAD","code_type":"ITEM","carrier_type":"MANUAL_CODE"}
→ 422
{"success":false,"message":"Mã vật lý loại ITEM bắt buộc dùng carrier chip (QR/BARCODE/RFID/NFC).",
 "error":{"code":"BUSINESS_RULE_ERROR","details":{"carrier_type":["Mã vật lý loại ITEM bắt buộc dùng carrier chip (QR/BARCODE/RFID/NFC)."]}}}
```

## SKU — tạo có brand_id, is_serial_tracked=true

```
POST /api/v1/products {"sku_code":"SMOKE-SKU-1","sku_name":"May khoan smoke","sku_type":"PRODUCT","is_serial_tracked":true,"brand_id":"<BRAND_ID>"}
→ 201
{"success":true,"message":"Tạo SKU thành công",
 "data":{"sku_code":"SMOKE-SKU-1","sku_name":"May khoan smoke","sku_type":"PRODUCT","is_serial_tracked":true,"brand_id":"019fc118-...","status":"ACTIVE","sku_id":"019fc119-..."}}
```

## Item Instance — thiếu physical_code_id bắt buộc (422) vs đủ điều kiện (201)

```
POST /api/v1/item-instances {"sku_id":"<SKU_ID>"}   # SKU là PRODUCT + is_serial_tracked=true
→ 422
{"success":false,"message":"SKU loại PRODUCT có is_serial_tracked=true bắt buộc phải có physical_code_id.",
 "error":{"code":"BUSINESS_RULE_ERROR","details":{"physical_code_id":["..."]}}}

POST /api/v1/item-instances {"sku_id":"<SKU_ID>","physical_code_id":"<CODE_ID>"}
→ 201
{"success":true,"message":"Tạo item instance thành công",
 "data":{"sku_id":"...","physical_code_id":"...","item_status":"NEW","item_condition":"NORMAL","version":1,"item_id":"019fc119-..."}}
```

## Item Instance — trace (traceability chain)

```
GET /api/v1/item-instances/{id}/trace
→ 200
{"success":true,"data":{
  "item": {...}, "sku": {"sku_code":"SMOKE-SKU-1", ...},
  "physical_code": {"code_value":"SMOKE-IT-1", ...},
  "container": null, "parent_container": null, "import_batch": null
}}
```

## Container — TRAY không cần primary_sku_id, optimistic locking 409, 404

```
POST /api/v1/containers {"container_code":"SMOKE-TRAY","container_type":"TRAY","physical_code_id":"<CODE_ID>","status":"ACTIVE"}
→ 201  {"success":true,"data":{"container_code":"SMOKE-TRAY","container_type":"TRAY","status":"ACTIVE","version":1,"container_id":"019fc11c-..."}}

PATCH /api/v1/containers/{id} {"zone_code":"A1","version":99}   # version thật là 1
→ 409
{"success":false,"message":"Container đã bị thay đổi bởi thao tác khác, vui lòng tải lại dữ liệu.",
 "error":{"code":"STATE_CONFLICT","details":[]}}

GET /api/v1/containers/00000000-0000-0000-0000-000000000000
→ 404  {"success":false,"message":"Không tìm thấy container.","error":{"code":"RESOURCE_NOT_FOUND","details":[]}}
```

## FK integrity thật trên Postgres sau khi rename `products` → `sku`

```
POST /api/v1/inbound-documents {"dst_warehouse_id":"<WH_ID>","lines":[{"product_id":"<SKU_ID>","qty_planned":3}]}
→ 201  (tạo stock_document_line với product_id trỏ đúng sang bảng sku mới, không lỗi FK)
{"success":true,"message":"Tạo phiếu nhập kho thành công",
 "data":{"doc_type":"receipt","doc_no":"PN-20260802-0001",...,
         "lines":[{"product_id":"019fc119-...","qty_planned":"3.000",...}]}}
```

Thử xoá SKU đang bị `stock_document_lines` tham chiếu (qua tinker, tương đương gọi repository `delete()`):

```php
Modules\Warehouse\Models\Sku::find($skuId)->delete();
// → Illuminate\Database\QueryException — bị chặn đúng như restrictOnDelete() khai báo trong migration,
//   xác nhận constraint FK vẫn còn nguyên vẹn sau khi rename bảng products→sku.
```

Kết luận: rename bảng không làm mất/sai FK constraint — insert, join-đọc quan hệ (`sku_code` đọc được qua relation `belongsTo`), và `restrictOnDelete` đều hoạt động đúng trên Postgres thật.
