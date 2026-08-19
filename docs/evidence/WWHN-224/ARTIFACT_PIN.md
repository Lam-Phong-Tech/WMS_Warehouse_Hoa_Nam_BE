# WWHN-224 — Pin artifact tại exact SHA (DEV224-P1-04R)

> Yêu cầu register: *"Regenerate OpenAPI/Postman từ cùng source và pin exact hash/version."*

## 1. Phiên bản hợp đồng

| Khoản | Giá trị |
|---|---|
| Template import SKU | `WWHN-172-SKU-IMPORT-v1.1.0` |
| Quyền canonical của cả 5 endpoint import | `sku.manage` |
| Mã lỗi mới công bố | `SKU_IMPORT_REPORT_PERSISTENCE_FAILED` (HTTP 503, kèm `Retry-After`) |
| Quyết định nghiệp vụ hạn mức | `DEC-IMP-001` |
| Disposition phạm vi/mapping | `DISP-IMP-224` (trạng thái: chờ BA/Data Owner) |

## 2. SHA-256 của artifact (tính tại `301899c`, nội dung WWHN-224 không đổi kể từ `937a5c0`)

```
6f769a9ebdbdbfe94051db65d9dc0fbce9bb729091201e904cd0e4f277f0b4de  storage/api-docs/api-docs.json
573213572f8192ed0b1ecc9a350b98fed6ea0739121da84915539d146341068f  storage/api-docs/api-docs.yaml
45c6c1de14edb6e35c8baa159a05a6b1b76def63198ffdd1ed096db6ce55f939  docs/postman/WWHN-172 SKU Container Contract.postman_collection.json
ada995684f97b617fbe3d57240c894b7a7a827e969379cbcc47bb2cc63d985d5  docs/decision-records/DISP-IMP-224-sku-import-scope-mapping.json
3bde90c2b432c76eaee9078f72f2ac5b3328e560b63ab10c69208ff9c98d8057  Modules/Warehouse/app/Http/Controllers/ImportController.php
f72ba9fed3e924f3c3fff3a9f798f3d07122b866511e0763f5c3e35830dbd13c  Modules/Warehouse/app/Services/ImportReportWriter.php
bc6523eb97daf0e8629dcc0c3da1eab018bd97c7d911033b99a55d0897a7189f  Modules/Warehouse/app/Services/ImportService.php
15ae169af294254d2febb75469aed691e1817fe048058e2f13cc5045332b2cbb  tests/Feature/Wwhn224P1ClosureTest.php
```

Tái lập:

```bash
sha256sum storage/api-docs/api-docs.json storage/api-docs/api-docs.yaml \
  "docs/postman/WWHN-172 SKU Container Contract.postman_collection.json" \
  docs/decision-records/DISP-IMP-224-sku-import-scope-mapping.json
```

## 3. Vì sao pin hash của **hai tệp toàn cục** chứ không tách artifact riêng cho WWHN-224

`storage/api-docs/api-docs.json|yaml` là artifact toàn hệ thống, được nhiều task chạm.
Hash toàn tệp vì vậy sẽ đổi khi bất kỳ task nào khác regenerate — pin cứng theo hash tổng
sẽ mục (pin rot) ngay ngày hôm sau và biến cổng thành tiếng ồn.

Nên cổng thật của WWHN-224 **không** so hash tổng mà so **nội dung nhóm path import**:

```bash
# tái sinh spec vào thư mục tạm rồi so đúng 5 path import
php artisan l5-swagger:generate
php scripts/sync-wwhn224-import-openapi.php --fresh-dir=<thư-mục-vừa-sinh> --check
```

Exit code khác 0 = annotation và artifact đã lệch. Hash ở §2 là mốc tham chiếu để QA2 xác
nhận mình đang xem đúng bản tại thời điểm đóng, không phải điều kiện pass vĩnh viễn.

Lý do không tạo `docs/api-contract/WWHN-224-sku-import-openapi.yaml` (như PR #5 từng làm):
một artifact OpenAPI thứ hai cho cùng nhóm endpoint là thêm một nguồn sự thật nữa để lệch,
đúng loại lỗi mà DEV224-P1-04R đang phạt.

## 4. Bất biến nội dung được cổng CI kiểm

| Bất biến | Kiểm bởi |
|---|---|
| 5 path `/api/v1/skus/import*` tồn tại trong spec | `verify-wwhn-224-closure.mjs` |
| Không path nào còn chuỗi `product.manage` | `verify-wwhn-224-closure.mjs` + test phpunit |
| Mọi 403 nêu đúng `sku.manage` — quyền route thực thi | test `test_generated_openapi_documents_the_permission_the_route_actually_enforces` (đọc bảng route thật) |
| `POST /skus/import-excel` công bố 503 + đúng error code | test + verifier |
| Route import gate đúng 5 lần bằng `sku.manage` | verifier (đọc thẳng `routes/import.php`) |
| Postman có case đọc `/docs` để khẳng định quyền + 503 | folder *"SKU import — hợp đồng P1 đóng 18/08 (WWHN-224)"* |
