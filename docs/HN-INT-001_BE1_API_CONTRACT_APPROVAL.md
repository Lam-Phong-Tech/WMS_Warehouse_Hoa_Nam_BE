# HN-INT-001 - BE1 API Contract Approval

## Traceability

| Field | Value |
|---|---|
| Parent Task | WWHN-40 - [G1] Rà soát contract FE/BE/QA và cổng kiểm soát payload mẫu |
| Parent Temp Key | HN-INT-001 |
| Epic Temp Key | HN-EPIC-03 |
| Role | BE1 |
| Assignee Placeholder | HN-BE-01 |
| Responsible | BE1 - Quang Nguyễn Thanh |
| Dependencies | HN-TEC-001; HN-UX-002; HN-QA-002 |
| Output | API contract approved |
| Evidence Date | 2026-07-29 |

## Scope Reviewed

Baseline reviewed from `WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`, current Laravel modular code, migrations, API routes, request validators, services, repository layer, audit/idempotency helpers, generated API docs, and automated tests.

The BE1 review covers API/data contract for Auth, RBAC-protected Warehouse master data, stock documents, stock requests, safe stock, reporting, audit visibility, idempotency, validation, transaction guard, movement ledger, and error envelope behavior. No baseline/reference file was replaced or removed.

## Contract Decision

Status: Approved with one environment note.

Disposition: no P0/P1 BE1 blocker remains in code or API/data contract. The only residual note is local PHPUnit result-cache write permission on `.phpunit.result.cache`; tests still execute and pass, and this does not affect runtime API behavior.

## Standard API Envelope

Success response:

```json
{
  "success": true,
  "message": "Created successfully",
  "data": {},
  "meta": {
    "request_id": "uuid-or-client-request-id",
    "timestamp": "2026-07-29T00:00:00+07:00"
  }
}
```

Paginated response:

```json
{
  "success": true,
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "last_page": 1,
    "total": 0,
    "from": null,
    "to": null,
    "request_id": "uuid-or-client-request-id",
    "timestamp": "2026-07-29T00:00:00+07:00"
  },
  "links": {
    "first": null,
    "last": null,
    "prev": null,
    "next": null
  }
}
```

Error response:

```json
{
  "success": false,
  "message": "Business rule failed",
  "error_code": "STATE_CONFLICT",
  "errors": {},
  "error": {
    "code": "STATE_CONFLICT",
    "details": {}
  },
  "meta": {
    "request_id": "uuid-or-client-request-id",
    "timestamp": "2026-07-29T00:00:00+07:00"
  }
}
```

## Security And Permission Contract

All `/api/v1/*` business endpoints except login/refresh require JWT Bearer auth. Backend permission middleware enforces RBAC; FE must not treat hidden UI controls as security.

| Endpoint Group | Permission |
|---|---|
| `GET /api/v1/warehouses`, `GET /api/v1/warehouses/{id}` | `master-data.warehouse.view` |
| `POST /api/v1/warehouses` | `master-data.warehouse.create` |
| `GET /api/v1/products`, `GET /api/v1/products/{id}` | `master-data.product.view` |
| `POST /api/v1/products` | `master-data.product.create` |
| `GET /api/v1/stock-documents`, `GET /api/v1/stock-documents/{id}` | `warehouse.stock-document.view` |
| `POST /api/v1/stock-documents` | `warehouse.stock-document.create` |
| Stock document submit | `warehouse.stock-document.submit` |
| Stock document approve/reject | `warehouse.stock-document.approve` |
| Stock document post/dispatch/receive/record-loss/return/confirm-return | `warehouse.stock-document.post` |
| Stock document cancel | `warehouse.stock-document.cancel` |
| Stock request view/create/submit/approve/cancel/convert | Matching `warehouse.stock-request.*` |
| Safe stock config/alert | `warehouse.safe-stock.view` or `warehouse.safe-stock.manage` |
| Reports | `report.inventory.view` |
| Audit logs | `audit-log.view` |

## Idempotency Contract

Header: `Idempotency-Key`.

Required for stock-changing or duplicate-sensitive document operations:

| Operation | Required |
|---|---|
| `POST /api/v1/stock-documents` | Yes |
| `POST /api/v1/stock-documents/{id}/post` | Yes |
| `POST /api/v1/stock-documents/{id}/dispatch` | Yes |
| `POST /api/v1/stock-documents/{id}/receive` | Yes |
| `POST /api/v1/stock-documents/{id}/confirm-return` | Yes |

Rules:

- Same key, same operation, same user, same payload returns the cached 2xx response.
- Same key, same operation, same user, different payload returns `409 IDEMPOTENCY_ERROR`.
- Missing required key returns `428 PRECONDITION_REQUIRED`.
- 4xx responses are not cached, so corrected requests can be retried with the same key.

## Stock Document Contract

### Create Stock Document

`POST /api/v1/stock-documents`

Required body:

```json
{
  "doc_type": "receipt",
  "doc_no": "PN-20260729-0001",
  "doc_date": "2026-07-29",
  "src_warehouse_id": null,
  "dst_warehouse_id": "uuid",
  "note": "optional",
  "lines": [
    {
      "product_id": "uuid",
      "qty_planned": 10
    }
  ]
}
```

Validation:

| Field | Rule |
|---|---|
| `doc_type` | required enum: `receipt`, `issue`, `transfer` |
| `doc_no` | required, unique, max 80 |
| `doc_date` | required date |
| `src_warehouse_id` | required for `issue` and `transfer`; must exist |
| `dst_warehouse_id` | required for `receipt` and `transfer`; must exist |
| `lines` | required array min 1 |
| `lines.*.product_id` | required UUID, must exist |
| `lines.*.qty_planned` | required numeric greater than 0 |

Business rules:

- `transfer` cannot use the same source and destination warehouse.
- Warehouses must be active.
- Create writes `document_status_history` and `audit_log`.

### Post Receipt Or Issue

`POST /api/v1/stock-documents/{id}/post`

Full post body:

```json
{}
```

Partial post body:

```json
{
  "lines": [
    {
      "line_id": "stock-document-line-uuid",
      "qty_actual": 4
    }
  ]
}
```

Validation:

- If `lines` is omitted, BE posts remaining quantity for all lines.
- If `lines` is present, it must be a non-empty array.
- `lines.*.line_id` is required, must be UUID, and must belong to the current stock document.
- `lines.*.qty_actual` is required, numeric, and greater than or equal to 0.
- Invalid payload returns `422 VALIDATION_ERROR` before any stock mutation.

Business rules:

- Only `receipt` and `issue` documents can use this endpoint.
- Receipt increases `inventory_balances` and writes `stock_movements` type `RECEIPT_IN`.
- Issue decreases `inventory_balances`, prevents negative stock, and writes `stock_movements` type `ISSUE_OUT`.
- Reposting the same line/action is guarded by idempotency and movement `business_key`.
- All stock mutation runs in DB transaction with `lockForUpdate()` on `inventory_balances` and optimistic `version` update.

### Transfer Flow

Transfer documents use:

| Step | Endpoint | State |
|---|---|---|
| Submit | `POST /api/v1/stock-documents/{id}/submit` | `draft` to `pending` |
| Approve | `POST /api/v1/stock-documents/{id}/approve` | `pending` to `approved` |
| Dispatch | `POST /api/v1/stock-documents/{id}/dispatch` | `approved` to `in_transit` |
| Receive | `POST /api/v1/stock-documents/{id}/receive` | `in_transit` to `completed` or remains `in_transit` |
| Record loss | `POST /api/v1/stock-documents/{id}/record-loss` | records in-transit loss |
| Return | `POST /api/v1/stock-documents/{id}/return` | `in_transit` to `return_in_transit` |
| Confirm return | `POST /api/v1/stock-documents/{id}/confirm-return` | `return_in_transit` to `returned` |

Receive partial body:

```json
{
  "lines": [
    {
      "line_id": "stock-document-line-uuid",
      "qty_actual": 8
    }
  ]
}
```

Record loss body:

```json
{
  "reason": "Damaged during transit",
  "lines": [
    {
      "line_id": "stock-document-line-uuid",
      "qty_lost": 1
    }
  ]
}
```

Transfer dispatch decreases source warehouse stock and creates `in_transit_ledger`. Receive increases destination stock and updates `in_transit_ledger`. Return confirmation increases source warehouse stock for returned quantity.

## Error Codes Verified

| HTTP | Code | Meaning |
|---|---|---|
| 400 | `MALFORMED_JSON` | Invalid JSON request body |
| 401 | `UNAUTHENTICATED` | Missing/invalid credentials |
| 403 | `ACCESS_DENIED` | Authenticated user lacks permission or account is not allowed |
| 404 | `RESOURCE_NOT_FOUND` | Entity not found |
| 409 | `STATE_CONFLICT` | Invalid lifecycle state or business conflict |
| 409 | `IDEMPOTENCY_ERROR` | Reused idempotency key with different payload or in-flight duplicate |
| 422 | `VALIDATION_ERROR` | Payload structure/field validation failed |
| 422 | `INSUFFICIENT_STOCK` | Issue/transfer quantity exceeds available stock |
| 428 | `PRECONDITION_REQUIRED` | Required `Idempotency-Key` missing |

Stack trace and secrets are not exposed by tested API error contracts.

## Data And Audit Evidence

Verified tables/migrations:

| Area | Tables |
|---|---|
| Auth/RBAC | `app_user`, `role`, `permission`, `user_role`, `role_permission`, `refresh_tokens` |
| Idempotency | `idempotency_keys` |
| Warehouse master | `warehouses`, `products` |
| Documents | `stock_documents`, `stock_document_lines`, `document_status_history`, `in_transit_ledger` |
| Inventory | `inventory_balances`, `stock_movements`, `inventory_serials` |
| Safe stock | `safe_stock_configs`, `safe_stock_alerts_history` |
| Audit | `audit_log` |

Evidence:

- Create stock document writes status history and audit log.
- Post receipt writes exactly one movement per idempotent action and updates balance once.
- Partial receipt can be posted in multiple idempotent chunks without double counting.
- Issue with insufficient stock is rejected and does not create invalid negative balance.
- Transfer lifecycle moves stock from source warehouse to destination warehouse through dispatch/receive.
- Auth login, refresh, logout, RBAC denial, malformed JSON, and audit visibility contracts are tested.

## Automated Test Evidence

Command:

```bash
php artisan test
```

Result:

```text
passed: 19 tests, 144 assertions
duration: 1187 ms
```

Environment note:

```text
PHPUnit warning: file_put_contents(C:\Hoa_Nam_Backend\.phpunit.result.cache) failed with Permission denied.
```

Disposition: test execution passed; warning is local result-cache write permission only.

## Acceptance Criteria Mapping

| Acceptance Criteria | Evidence / Disposition |
|---|---|
| Output `API contract approved` reviewed/verified and attached to HN-INT-001 | This file is the BE1 approval artifact for HN-INT-001 |
| Logic API/data satisfies business rules/dependencies | Route permission, request validation, service transaction, ledger mutation, audit, idempotency reviewed |
| Permission, validation, transaction, audit, idempotency/concurrency guard applied | Verified in middleware, FormRequest, controller guard, DB transaction, `lockForUpdate`, version update, audit logger, idempotency store |
| Error cases return clear error code and do not create wrong data | Tests cover auth errors, malformed JSON, missing idempotency key, idempotency conflict, invalid line payload, insufficient stock |
| No secret/stack trace leak | Tested with auth failure and permission failure contracts |
| Unit/integration test and API/DB evidence exists | `php artisan test`: 19 tests, 144 assertions; DB table/evidence listed above |
| OpenAPI/Postman/payload samples updated when contract changed | Payload samples are documented here and Postman collection added at `docs/HN-INT-001_BE1_payload_samples.postman_collection.json` |
| FE1 and QA receive contract/evidence | This artifact and Postman sample collection are ready for FE1/QA handoff |
| No P0/P1 blocker without disposition | None. Environment warning has disposition above |

## Handoff

FE1 handoff:

- Use `Authorization: Bearer <token>` for protected endpoints.
- Use `X-Request-ID` for trace correlation when available.
- Send `Idempotency-Key` for all required stock document operations.
- Treat `error.code`/`error_code` as stable error handling keys.
- Do not compute stock changes on FE; use API result from BE.

QA/BA handoff:

- Use the Postman collection and test evidence above for happy-path and negative-path verification.
- Confirm formal sign-off against HN-INT-001 if dependency baselines HN-TEC-001, HN-UX-002, or HN-QA-002 introduce additional endpoint fields.
- Current BE1 disposition has no unowned blocker.
