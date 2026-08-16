# OpenAPI / Postman diff

## Contract mới

6 tag mới trong OpenAPI (`storage/api-docs/api-docs.json`, regenerate bằng `php artisan l5-swagger:generate`):

- **20. Product Master** — `GET/POST /product-masters`, `GET/PATCH /product-masters/{id}`
- **21. Physical Code** — `GET/POST /physical-codes`, `GET/PATCH /physical-codes/{id}`
- **22. Import Batch** — `GET/POST /import-batches`, `GET/PATCH /import-batches/{id}`
- **23. Container** — `GET/POST /containers`, `GET/PATCH /containers/{id}`, `POST /containers/{id}/items`, `DELETE /containers/{id}/items/{containerItemId}`
- **24. Item Instance** — `GET/POST /item-instances`, `GET/PATCH /item-instances/{id}`, `GET /item-instances/{id}/trace`

## Contract thay đổi (breaking cho field name, route giữ nguyên)

`05. Product` (`/api/v1/products`):
- **Field đổi tên**: request/response `code` → `sku_code`, `name` → `sku_name` (đồng bộ theo ERD). FE1/QA cần cập nhật payload khi tích hợp — đây là breaking change duy nhất của task này, đã có trong `03-api-evidence.md`.
- **Field mới** (optional, POST + PATCH): `brand_id`, `category_id`, `model_id`, `power_or_voltage`, `package_variant`, `version_code`, `warranty_applicable`, `specification`, `published_to_app`.
- **Endpoint mới**: `PATCH /api/v1/products/{id}` (trước đây chỉ có GET list/detail + POST, thiếu update).
- Route path, permission code (`product.view`/`product.manage`), status code convention: **không đổi**.

## Đã regenerate

```bash
php artisan l5-swagger:generate   # storage/api-docs/api-docs.json — thành công, không lỗi
npx @redocly/cli lint storage/api-docs/api-docs.json
# → chỉ 2 warning cũ (info-license, no-server-example.com) đã có từ trước task này, không phát sinh lỗi mới
npx openapi-to-postmanv2 -s storage/api-docs/api-docs.json \
  -o "docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json" -p -O folderStrategy=Tags
# → conversion successful, ghi đè đúng file Postman collection hiện có (giữ tên cũ, không tạo file trùng lặp)
```

`docs/postman/WMS_HoaNam - Local.postman_environment.json` và `WMS_HoaNam API Scenario Testing - TEST.postman_collection.json` không cần đổi (không có scenario test riêng cho entity mới trong phạm vi task này).

Lưu ý: `/docs/` nằm trong `.gitignore` (dòng 28) — các file OpenAPI/Postman regenerate này không được track bởi git, đúng hành vi sẵn có của repo (không phải thay đổi mới của task).
