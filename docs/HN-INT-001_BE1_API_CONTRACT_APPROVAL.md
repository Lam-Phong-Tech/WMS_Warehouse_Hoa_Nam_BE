# HN-INT-001 - BE1 API Contract Review

## Traceability

| Field | Value |
|---|---|
| Parent Task | WWHN-40 - [G1] Review FE/BE/QA contract and sample payload control gate |
| Parent Temp Key | HN-INT-001 |
| Epic Temp Key | HN-EPIC-03 |
| Role | BE1 |
| Responsible | BE1 - Quang Nguyen Thanh |
| Evidence Date | 2026-07-30 |
| Evidence | `docs/HN-INT-001_BE1_payload_samples.postman_collection.json` |

## Contract Decision

Status: **Not approved - BA/FE re-review required**.

BE1 cannot claim "API contract approved" yet because the previous handoff still mixed platform/multi-warehouse behavior with the Hoa Nam baseline and still documented approval-oriented flows as canonical. This document supersedes the previous BE1 approval text and locks the canonical Hoa Nam contract target below.

Current blocker disposition:

| Blocker | Disposition Required Before Approval |
|---|---|
| FE-SO-001 | Confirm canonical scan classify contract, payload, validation, errors, permissions, and sample. |
| FE-SO-002 | Confirm Inbound contract uses partial post flow only; no submit/approve/reject in Hoa Nam canonical flow. |
| FE-SO-003 | Confirm Outbound contract uses partial post flow only; no submit/approve/reject in Hoa Nam canonical flow. |
| FE-SO-004 | Confirm Inventory, Stocktake Apply Adjustment, Warranty TEMP_ONLY, and Reversal contracts are available to FE/QA. |
| FE-SO-005 | Align permission names with BA baseline or provide explicit BE-to-FE mapping. |

## Baseline Lock

Hoa Nam canonical warehouse baseline is **01 kho tong Hoa Nam**.

- Agency is a receiver/customer destination, not a warehouse.
- Inbound increases stock in the single Hoa Nam warehouse.
- Outbound decreases stock from the single Hoa Nam warehouse and may carry `agency_id` or receiver fields for delivery context.
- Transfer and multi-warehouse APIs are **outside Hoa Nam scope**. If retained as platform capability, they must not be used as the canonical flow for WWHN-40.
- FE must use the endpoints and states in this document for Hoa Nam screens.

Canonical status names for Hoa Nam FE/QA:

| State | Meaning |
|---|---|
| `DRAFT` | Document created, no stock impact. |
| `SCANNING` | Scanning/posting is in progress; stock may be partially impacted by committed scan/post events. |
| `PARTIALLY_POSTED` | One or more lines posted but document is not fully posted. |
| `POSTED` | Planned quantity posted completely. |
| `CLOSED` | Remaining quantity closed as shortage/no further posting. |
| `CANCELLED` | Document cancelled before posting. |

Implementation note: current code still exposes legacy lowercase/internal states including `pending`, `approved`, `received`, `issued`, and `reversed`. That mismatch is a blocker until BE normalizes API responses to the canonical Hoa Nam states or provides an accepted mapping layer.

## Standard API Envelope

Success:

```json
{
  "success": true,
  "message": "OK",
  "data": {},
  "meta": {
    "request_id": "HN-INT-001-001",
    "timestamp": "2026-07-30T00:00:00+07:00"
  }
}
```

Error:

```json
{
  "success": false,
  "message": "Validation failed",
  "error_code": "VALIDATION_ERROR",
  "errors": {},
  "error": {
    "code": "VALIDATION_ERROR",
    "details": {}
  },
  "meta": {
    "request_id": "HN-INT-001-001",
    "timestamp": "2026-07-30T00:00:00+07:00"
  }
}
```

Stable error codes:

| HTTP | Code | Use |
|---|---|---|
| 400 | `MALFORMED_JSON` | Invalid JSON body. |
| 401 | `UNAUTHENTICATED` | Missing/invalid token. |
| 403 | `ACCESS_DENIED` | User lacks permission. |
| 404 | `RESOURCE_NOT_FOUND` | Entity not found. |
| 409 | `STATE_CONFLICT` | Invalid lifecycle transition or optimistic lock conflict. |
| 409 | `IDEMPOTENCY_ERROR` | Same idempotency key reused with different payload. |
| 422 | `VALIDATION_ERROR` | Payload validation failed. |
| 422 | `INSUFFICIENT_STOCK` | Outbound or reversal would make stock negative. |
| 428 | `PRECONDITION_REQUIRED` | Required `Idempotency-Key` is missing. |

## Permission Mapping

All business endpoints require `Authorization: Bearer <token>`.

| FE Capability | Canonical Permission | Current BE Permission |
|---|---|---|
| View warehouse | `warehouse.view` | `warehouse.view` |
| Manage warehouse metadata | `warehouse.manage` | `warehouse.manage` |
| View product/SKU | `product.view` | `product.view` |
| Manage product/SKU | `product.manage` | `product.manage` |
| View inbound/outbound | `stock_document.view` | `stock_document.view` |
| Create/update draft inbound/outbound | `stock_document.create` | `stock_document.create` |
| Scan classify / post receipt / post issue / close remainder | `stock_document.post` | `stock_document.post` |
| Cancel draft document | `stock_document.cancel` | `stock_document.cancel` |
| Reverse posted document | `stock_document.reverse` | `stock_document.reverse` |
| View inventory and movements | `inventory.view` | `inventory.view` |
| Apply stocktake adjustment | `stocktake.adjust` | **Missing in current route/API** |
| Warranty TEMP_ONLY | `warranty.temp_only` | **Missing in current route/API** |
| View safe stock | `safe_stock.view` | `safe_stock.view` |
| Manage safe stock | `safe_stock.manage` | `safe_stock.manage` |
| View reports | `report.view` | `report.view` |
| View audit log | `audit_log.view` | `audit_log.view` |

Permissions `stock_document.submit` and `stock_document.approve` exist in the current backend seed but are not part of the Hoa Nam canonical flow. FE must not infer approval screens from those backend permission names.

## Idempotency

Header: `Idempotency-Key`.

Required for all stock-changing actions:

| Operation | Required |
|---|---|
| Scan classify/post partial line | Yes |
| Post Receipt | Yes |
| Post Issue | Yes |
| Stocktake Apply Adjustment | Yes |
| Warranty TEMP_ONLY stock move | Yes |
| Reversal | Yes |

Rules:

- Same user, same operation, same key, same payload returns cached 2xx response.
- Same user, same operation, same key, different payload returns `409 IDEMPOTENCY_ERROR`.
- Missing required key returns `428 PRECONDITION_REQUIRED`.
- Failed 4xx responses are not cached.

## Canonical Endpoints

### Scan Classify

Purpose: classify a scanned QR/barcode/container/item before FE applies it to inbound, outbound, stocktake, or warranty.

`POST /api/v1/scan/classify`

Permission: `stock_document.post`

Status: **Required canonical endpoint; missing from current backend route list.**

Request:

```json
{
  "raw_code": "QR-HN-2026-00001",
  "context": "INBOUND",
  "warehouse_id": "{{warehouse_id}}"
}
```

Validation:

| Field | Rule |
|---|---|
| `raw_code` | required string max 120 |
| `context` | required enum `INBOUND`, `OUTBOUND`, `STOCKTAKE`, `WARRANTY_TEMP_ONLY` |
| `warehouse_id` | required UUID of the single active Hoa Nam warehouse |

Success sample:

```json
{
  "success": true,
  "message": "Scan classified",
  "data": {
    "raw_code": "QR-HN-2026-00001",
    "classification": "ITEM",
    "item_id": "{{item_id}}",
    "sku_id": "{{sku_id}}",
    "stock_status": "AVAILABLE",
    "current_location": {
      "warehouse_id": "{{warehouse_id}}"
    }
  }
}
```

Errors: `VALIDATION_ERROR`, `RESOURCE_NOT_FOUND`, `STATE_CONFLICT`, `ACCESS_DENIED`.

### Inbound

Canonical route group: `/api/v1/inbound-documents`

Permissions:

| Endpoint | Permission |
|---|---|
| `GET /api/v1/inbound-documents` | `stock_document.view` |
| `POST /api/v1/inbound-documents` | `stock_document.create` |
| `GET /api/v1/inbound-documents/{id}` | `stock_document.view` |
| `PATCH /api/v1/inbound-documents/{id}` | `stock_document.create` |
| `POST /api/v1/inbound-documents/{id}/scan` | `stock_document.post` |
| `POST /api/v1/inbound-documents/{id}/post-receipt` | `stock_document.post` |
| `POST /api/v1/inbound-documents/{id}/close-remainder` | `stock_document.post` |
| `POST /api/v1/inbound-documents/{id}/cancel` | `stock_document.cancel` |
| `POST /api/v1/inbound-documents/{id}/reverse` | `stock_document.reverse` |

Create request:

```json
{
  "doc_no": "PN-20260730-0001",
  "doc_date": "2026-07-30",
  "dst_warehouse_id": "{{warehouse_id}}",
  "note": "Nhap hang nha cung cap",
  "lines": [
    {
      "product_id": "{{product_id}}",
      "qty_planned": 10
    }
  ]
}
```

Validation:

- `dst_warehouse_id` is required and must be the single active Hoa Nam warehouse.
- `lines` is required, min 1.
- `lines.*.product_id` is required UUID and must exist.
- `lines.*.qty_planned` is required numeric `> 0`.

Partial post by scan:

`POST /api/v1/inbound-documents/{id}/scan`

```json
{
  "line_id": "{{inbound_line_id}}",
  "qty": 4
}
```

Full post:

`POST /api/v1/inbound-documents/{id}/post-receipt`

```json
{}
```

Flow: `DRAFT -> SCANNING/PARTIALLY_POSTED -> POSTED`, or `PARTIALLY_POSTED -> CLOSED`, or `DRAFT -> CANCELLED`.

No Hoa Nam canonical submit/approve/reject step exists for inbound. Current `/submit`, `/approve`, and `/reject` routes are legacy/platform surface and must not be consumed by FE for WWHN-40.

### Outbound

Canonical route group: `/api/v1/outbound-documents`

Permissions mirror inbound, using `post-issue` instead of `post-receipt`.

Create request:

```json
{
  "doc_no": "PX-20260730-0001",
  "doc_date": "2026-07-30",
  "src_warehouse_id": "{{warehouse_id}}",
  "agency_id": "{{agency_id}}",
  "receiver_name": "Dai ly mien Bac 01",
  "note": "Xuat cho dai ly",
  "lines": [
    {
      "product_id": "{{product_id}}",
      "qty_planned": 3
    }
  ]
}
```

Validation:

- `src_warehouse_id` is required and must be the single active Hoa Nam warehouse.
- `agency_id` is optional delivery context; it must not be treated as warehouse.
- `lines` is required, min 1.
- `lines.*.qty_planned` is required numeric `> 0`.
- Posting must reject insufficient available stock with `422 INSUFFICIENT_STOCK`.

Partial post by scan:

`POST /api/v1/outbound-documents/{id}/scan`

```json
{
  "line_id": "{{outbound_line_id}}",
  "qty": 1
}
```

Full post:

`POST /api/v1/outbound-documents/{id}/post-issue`

```json
{}
```

Flow: `DRAFT -> SCANNING/PARTIALLY_POSTED -> POSTED`, or `PARTIALLY_POSTED -> CLOSED`, or `DRAFT -> CANCELLED`.

No Hoa Nam canonical submit/approve/reject step exists for outbound.

### Inventory

`GET /api/v1/inventory/balances`

Permission: `inventory.view`

Query:

| Field | Rule |
|---|---|
| `warehouse_id` | optional; if supplied must be Hoa Nam warehouse |
| `product_id` | optional UUID |
| `status` | optional enum `normal`, `low_stock`, `overflow` |
| `page`, `per_page` | optional pagination |

Sample:

```json
{
  "success": true,
  "data": [
    {
      "warehouse_id": "{{warehouse_id}}",
      "product_id": "{{product_id}}",
      "product_code": "HN-PV-BASE-001",
      "on_hand_qty": "42.000",
      "available_qty": "42.000",
      "reserved_qty": "0.000",
      "status": "normal"
    }
  ]
}
```

`GET /api/v1/stock-movements` and `GET /api/v1/stock-movements/{id}/trace-source` are canonical read-only evidence endpoints for FE/QA traceability.

### Stocktake Apply Adjustment

`POST /api/v1/stocktake-sessions/{id}/apply-adjustment`

Permission: `stocktake.adjust`

Status: **Required canonical endpoint; missing from current backend route list.**

Request:

```json
{
  "reason": "Cycle count variance approved",
  "lines": [
    {
      "sku_id": "{{sku_id}}",
      "stock_status": "AVAILABLE",
      "expected_qty": 100,
      "actual_qty": 98,
      "adjustment_qty": -2
    }
  ]
}
```

Validation:

- Session must be counted/reviewed and not already applied.
- `adjustment_qty` must equal `actual_qty - expected_qty`.
- Negative adjustment must not make available stock below zero.
- Must write inventory movement and audit evidence.

Errors: `VALIDATION_ERROR`, `STATE_CONFLICT`, `INSUFFICIENT_STOCK`, `PRECONDITION_REQUIRED`.

### Warranty TEMP_ONLY

`POST /api/v1/warranty-cases/{id}/temp-only`

Permission: `warranty.temp_only`

Status: **Required canonical endpoint; missing from current backend route list.**

Request:

```json
{
  "item_id": "{{item_id}}",
  "defect_id": "{{defect_id}}",
  "condition_status": "WAITING_CHECK",
  "note": "Nhan tam thoi de kiem tra bao hanh"
}
```

Validation:

- Only warranty temporary receiving is in scope.
- TEMP_ONLY item must not become sellable stock.
- TEMP_ONLY movement must be traceable and reversible.
- If item is already in TEMP_ONLY or closed warranty state, return `STATE_CONFLICT`.

### Reversal

Canonical stock document reversal:

`POST /api/v1/inbound-documents/{id}/reverse`
`POST /api/v1/outbound-documents/{id}/reverse`

Permission: `stock_document.reverse`

Request:

```json
{
  "reason": "Sai so luong, lap phieu dao nguoc"
}
```

Validation:

- Source document must be `POSTED` or `CLOSED` with posted movements.
- Each original movement may be reversed once.
- Reversal must create linked opposite movement with `original_movement_id`.
- Reversal must reject if it would make stock negative.

Sample response:

```json
{
  "success": true,
  "message": "Document reversed",
  "data": {
    "id": "{{document_id}}",
    "status": "REVERSED",
    "reverse_reason": "Sai so luong, lap phieu dao nguoc"
  }
}
```

Implementation note: current backend returns internal lowercase `reversed`; FE requires accepted canonical mapping before approval.

## Evidence Handoff

Postman collection for shared handoff is committed in repo:

`docs/HN-INT-001_BE1_payload_samples.postman_collection.json`

Local machine paths such as `C:/...` are not evidence for Jira. Attach this JSON file directly to Jira HN-INT-001 or link the shared repository file/commit.

## Approval Gate

Contract can move back to "API contract approved" only after:

- BE removes or clearly gates Hoa Nam-noncanonical transfer/multi-warehouse flow from WWHN-40 handoff.
- BE removes submit/approve/reject from canonical Hoa Nam Inbound/Outbound docs or maps them out of FE consumption.
- Missing canonical endpoints for Scan Classify, Stocktake Apply Adjustment, and Warranty TEMP_ONLY are implemented or formally deferred with BA approval.
- Permission mapping above is accepted by BA/FE or replaced with BA baseline names.
- FE-SO-001 through FE-SO-005 are closed with evidence.
