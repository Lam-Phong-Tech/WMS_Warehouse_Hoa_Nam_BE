# WWHN-165 contract samples — nguồn gốc

Toàn bộ file JSON trong thư mục này là **response body thật** do backend phát ra,
không viết tay. Chúng được dump bởi test
`tests/Feature/Wwhn165AuthzDenyAuditProofTest.php::test_error_contract_samples_cover_release_pack_matrix`
(helper `flushSamples()`, ghi ra `build/evidence/wwhn-165/samples/` rồi copy nguyên
trạng vào đây). Mỗi nhánh đều bị assert đúng HTTP status + `error.code` **trước khi**
capture — sample sai hợp đồng thì test đỏ và không lọt vào pack.

| File | HTTP | error_code | Cách kích hoạt (endpoint thật) |
|---|---|---|---|
| `401_UNAUTHENTICATED.json` | 401 | UNAUTHENTICATED | POST `/api/v1/inbound-documents/{id}/post-receipt` không có Bearer token |
| `403_ACCESS_DENIED.json` | 403 | ACCESS_DENIED | Token chỉ có `inbound.view` gọi action đòi `inbound.post` |
| `403_DATA_SCOPE_DENIED.json` | 403 | DATA_SCOPE_DENIED | Token đủ permission nhưng `scope_ref` trỏ kho khác (EnforceInboundWarehouseScope) |
| `409_IDEMPOTENCY_CONFLICT.json` | 409 | IDEMPOTENCY_CONFLICT | Cùng `Idempotency-Key`, payload khác (scan quantity 1 → 2) |
| `409_STATE_CONFLICT.json` | 409 | STATE_CONFLICT | PATCH phiếu nhập đã POSTED (chỉ DRAFT/SCANNING được cập nhật) |
| `412_STALE_VERSION.json` | 412 | STALE_VERSION | `If-Match: 999` trong khi version hiện hành là 2 |
| `422_VALIDATION.json` | 422 | (validation envelope) | POST `/api/v1/inbound-documents` thiếu `lines` |
| `428_PRECONDITION_REQUIRED.json` | 428 | PRECONDITION_REQUIRED | Mutation scan không gửi header `If-Match` |

- Môi trường capture: PHPUnit `APP_ENV=testing`, PostgreSQL 16 trong container
  `wms_warehouse_hoa_nam_be-backend-1` (`php artisan test --configuration=phpunit.pgsql.xml`);
  cùng nội dung envelope khi chạy sqlite in-memory (driver không đổi hợp đồng HTTP).
- Source commit (code phát ra các envelope này): `a452e4459fe20aaaf1c23981577b3e0570c13c00` (nhánh `m1`).
- Capture lúc: 2026-08-20 (ICT), run PG 09:45:33+07:00.
- Giá trị động (`meta.request_id` UUID, `meta.timestamp`) là giá trị thật của lần chạy,
  không chứa dữ liệu nhạy cảm — không có token/PII nào trong error envelope theo
  thiết kế (xem `Wwhn165AuthzDenyAuditProofTest` nhánh no-stack-trace).
