# WWHN-172 / WWHN-171 — BE1/BE2 boundary matrix

| Area | Authoritative boundary | BE1 implementation | BE2 / reviewer gate |
|---|---|---|---|
| SKU root | `products` là root hiện hành; UUID `id`; `code`/`sku_code` unique; `version` optimistic lock | Aggregate API, transaction, mutex, error/audit/idempotency contract | Xác nhận mapping từ master/import của WWHN-171 không tạo writer thứ hai bỏ qua aggregate guard |
| Component graph | `sku_component(parent_product_id, component_product_id)`; edge unique; quantity > 0; DAG | Self/transitive-cycle guard; global SKU mutation mutex ngăn opposite-edge race | Xác nhận import/trace của BE2 không ghi trực tiếp edge ngoài service/API chuẩn |
| Physical code | `physical_code.code_value` unique; một owner SKU hoặc CONTAINER; revoke giải phóng owner | Row lock + aggregate mutex + canonical 409; audit before/after | Xác nhận barcode/QR/RFID mapping, lifecycle và trace granularity |
| Container | `container` là aggregate root; `container_item` là owned child; SEALED immutable | Transaction replace-set, version guard, hierarchy guard, serial/physical-code unique | Xác nhận loại container, branch/warehouse mapping và không tạo shadow table/API |
| Serial ownership | Một `inventory_serial_id` chỉ có một container-item owner hiện hành | Unique index + service conflict + global container mutex | Xác nhận lịch sử containerization được đưa sang audit/history, không tái dùng active row |
| Import | Header canonical theo tên; tối đa 1.000 rows; `ALL_OR_NOTHING`; inactive conflict không tự kích hoạt | Strict parser, two-phase validation, single DB transaction, per-row + summary audit | Xác nhận template/column mapping và source import từ WWHN-171 dùng endpoint này |
| Transaction | Root + child + audit + idempotency snapshot cùng transaction | Middleware outer mutex transaction + service nested transaction; audit fail-closed | Tech Lead review nested transaction/savepoint behavior trên PostgreSQL/MySQL |
| Trace/audit | `request_id`, actor, action, object, before/after, reason; không secret | DB before/after snapshot; fail-closed rollback test; redaction gate | BE2 xác nhận trace source references không bị mất khi import/container mapping |
| Branch | Chỉ `m1`; exact commit SHA trong CI/Jira | Workflow checkout exact SHA; source-tree/hash manifest | BE2 rebase/merge không được ghi đè contract hoặc bỏ middleware/migration |
| Contract | OpenAPI/Postman/Test register là bundle chuẩn của WWHN-172 | CI copies canonical sources, hashes manifest and runs Redocly/PHPUnit | BE2/FE/QA review impacted payload/error codes trước sign-off |

## Disposition

- Mỗi writer được chỉ rõ và có gate.
- WWHN-171 phải xác nhận không có writer bypass và trace/import mapping không phá boundary.
- Jira chỉ đóng dependency sau comment exact SHA + artifact/hash và Tech Lead xác nhận matrix.
