# P09 Mini App Warranty — BE fixes (2026-11-12)

Phạm vi: Mini App Bảo hành. Không đổi P20 v1 contract.

## 1. Reconcile `GET /api/v1/mini-app/warranty-requests/{requestId}`

`data.receipt` có thể trả các state:

| State | `may_create_new_request` | Ý nghĩa / FE làm gì |
|---|---:|---|
| `SUCCEEDED` | `false` | Đọc `data.warranty_case_id`, `data.warranty_case_code`, `data.case_no`; mở chi tiết. |
| `PROCESSING` | `false` | Đang chạy; tiếp tục chờ/đối chiếu, không tạo mới. |
| `FAILED` | `true` | Lệnh đã bị từ chối, không có hiệu ứng nghiệp vụ; sửa dữ liệu rồi tạo lần mới. |
| `UNKNOWN` | `false` | Không xác định được, hoặc `requestId` tồn tại ở scope actor/session/kho khác. Chỉ đối chiếu, không tạo mới. |
| `NOT_FOUND` | `true` | `requestId` không tồn tại ở bất kỳ scope nào; chưa từng có hiệu ứng. Được phép tạo lần mới. |

Response `NOT_FOUND`:

```json
{
  "success": true,
  "data": {
    "receipt": {
      "request_id": "826c3896-2a98-48d7-8b8a-42943059408a",
      "state": "NOT_FOUND",
      "error_code": "REQUEST_NOT_RECORDED",
      "retry_policy": "NEW_REQUEST_ALLOWED",
      "may_create_new_request": true,
      "operation": null,
      "warranty_case_id": null,
      "version": null,
      "status": null,
      "http_status": null,
      "completed_at": null,
      "stock_effect": "NONE",
      "movement_created": false
    }
  }
}
```

Lưu ý:

- `GET` vẫn trả HTTP **200**; quyết định nằm ở `state` + `may_create_new_request`.
- Lần thử mới sau `FAILED`/`NOT_FOUND` phải dùng **cả `Idempotency-Key` mới và `X-Request-ID` mới**. Giữ `requestId` cũ với key mới sẽ trả `409 IDEMPOTENCY_CONFLICT` (unique `(scope_hash, request_id)`).
- Response 4xx đầu tiên của `POST/PATCH/status` giờ mang thêm `data.receipt` (state `FAILED`), đồng thời giữ `message` và `errors` field-level để hiển thị đúng lỗi từng trường. Ví dụ:

```json
{
  "success": false,
  "message": "Sản phẩm cụ thể không hợp lệ.",
  "error_code": "VALIDATION_ERROR",
  "errors": { "item_id": ["Sản phẩm cụ thể không hợp lệ."] },
  "error": { "code": "VALIDATION_ERROR", "details": { "item_id": ["..."] } },
  "data": { "receipt": { "state": "FAILED", "may_create_new_request": true } }
}
```

- Kiểm tra `dev-test` ngày 2026-11-12: `request_id = 826c3896-2a98-48d7-8b8a-42943059408a` **không có row** trong `p09_warranty_requests`; không phải row kẹt `PROCESSING`. Sau deploy, GET sẽ trả `NOT_FOUND` + `true`.

## 2. `GET /api/v1/mini-app/warranty-cases` — `meta.status_counts`

```json
{
  "meta": {
    "total": 2,
    "status_counts": {
      "ALL": 3,
      "RECEIVED": 1,
      "CHECKING": 2,
      "REPAIRING": 0,
      "COMPLETED": 0,
      "RETURNED": 0,
      "CANCELLED": 0
    },
    "status_counts_basis": "SAME_WAREHOUSE_SEARCH_DATE_FILTERS_EXCLUDING_STATUS"
  }
}
```

- Counts áp dụng cùng warehouse + search + date filter, **bỏ qua filter `status`**.
- `ALL` là tổng các status đã group.
- Khi đang chọn `status=CHECKING`, `meta.total` = 2 nhưng `status_counts.ALL` vẫn = 3.
- 4 chip nhanh dùng trực tiếp `RECEIVED`, `CHECKING`, `COMPLETED`, `RETURNED`; modal dùng đủ 6 + `ALL`.

## 3. `GET /api/v1/mini-app/warranty-cases/{id}/events`

`actor` giờ có:

```json
{
  "user_id": "…",
  "user_id_source": "warranty_event.created_by",
  "display_name": "Nguyễn Văn A",
  "display_name_status": "CURRENT_DIRECTORY_LABEL_NOT_HISTORICAL_SNAPSHOT",
  "display_name_source": "app_user.display_name"
}
```

Status values:

- `CURRENT_DIRECTORY_LABEL_NOT_HISTORICAL_SNAPSHOT`: tên hiện tại trong `app_user`, không phải snapshot lịch sử.
- `NOT_RECORDED_IN_EVENT`: event không có `created_by`.
- `ACTOR_SOURCE_UNAVAILABLE`: có user_id nhưng không đọc được tên từ nguồn.

Không gate bằng `warranty.pii.view`; mọi user có `warranty.view | warranty.manage` đều đọc được (audit nội bộ).

## 4. `GET /api/v1/mini-app/warranty-cases/{id}/component-issues` (legacy path)

Chỉ thêm vào legacy path (không gửi `X-Component-History-Contract`). P20 v1 giữ nguyên `list_has_lines: false`.

Mỗi row có thêm:

```json
{
  "component_issue_document_id": "…",
  "status": "POSTED",
  "document_no": "XK-…",
  "posted_at": "2026-11-12T05:00:00+07:00",
  "line_count": 2,
  "total_quantity": 5,
  "quantity_status": "CONFIRMED",
  "lines": [
    { "sku_id": "…", "sku_code": "LK-001", "sku_name": "Bộ chế hòa khí", "unit": "cái", "quantity": 3 }
  ]
}
```

- `quantity` = `SUM(component_issue_lines.scanned_qty)` group theo SKU, chỉ cho document `POSTED`.
- `quantity_status = INCOMPLETE` nếu có line `scanned_qty` null hoặc document không có line.
- `sku_code`/`sku_name`/`unit` là master hiện tại, không phải snapshot lịch sử.
- `detail_url` vẫn giữ để mở chi tiết đầy đủ (items/scan evidence).

## 5. `DEF-902`

Data migration set `DEF-902 = INACTIVE`, tăng `version`, ghi `audit_log` (`object_type=defect_catalog`, `action=UPDATE`, `module=SYSTEM`) và bump cache `warehouse.defect_catalog:list` + `stock:version`.

Sau deploy, `GET /api/v1/defects?status=ACTIVE&page=1&per_page=100` không còn DEF-902. Các hồ sơ bảo hành cũ vẫn giữ `defect_id`; không có delete/backfill lịch sử.
