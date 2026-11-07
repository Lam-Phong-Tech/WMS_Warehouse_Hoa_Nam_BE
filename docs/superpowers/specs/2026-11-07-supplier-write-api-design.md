# Supplier create and update API

Status: approved by the user on 2026-11-07; implemented and verified (see frontend verification handoff).

## Intent

Add supplier creation with automatically increasing NCC business codes and supplier editing to the existing global directory. Preserve supplier UUIDs and historical receipt snapshots.

## Approach

Extend `/api/v1/mini-app/inbound/suppliers` with POST and PATCH routes, using a focused supplier write controller. Reuse the existing table, JSON envelope, authentication and stable-session checks. A separate directory or reusing import-batch supplier names would duplicate the authoritative source and is unnecessary.

## HTTP contract

- `POST /api/v1/mini-app/inbound/suppliers`: JSON `{ "name": "Honda", "status": "ACTIVE" }`. Name is trimmed, required and at most 200 characters; status is optional and defaults to ACTIVE. Return 201 with the same supplier fields used by the directory and an ETag for version 1.
- `PATCH /api/v1/mini-app/inbound/suppliers/{id}`: JSON containing name and/or status. Require `If-Match: "<version>"` to protect concurrent edits. Return 200 with the updated row and new ETag. Reject empty updates, invalid values, unknown fields and attempts to change id, code, warehouse_id or version.
- Status values are ACTIVE and INACTIVE. Renaming and deactivation preserve UUID, business code and all historical receipt metadata.
- Require an active authenticated stable session and the existing `catalog.manage` permission for both mutations. Keep GET authorization unchanged.
- Responses use the existing success/data/meta envelope. Missing supplier: 404; forbidden: 403; stale version: 412; missing precondition: 428; invalid input: 422.

## Automatic numbering

In one database transaction, serialize supplier creation before reading existing NCC codes and inserting the new row. PostgreSQL uses a transaction-scoped advisory lock shared by all new supplier creations; SQLite uses its serialized write transaction in tests. Select the greatest existing numeric NCC suffix, including inactive suppliers, and add one. Format with a minimum of three digits: NCC-001, NCC-002, NCC-999, NCC-1000. Do not accept a caller-supplied code or reuse gaps. Retain the existing unique index as the final database guard. Reject namespace overflow.

The required legacy warehouse_id is resolved server-side from the canonical active KHO-TONG-HN warehouse. The directory remains global. Fail without writes if the canonical warehouse is unavailable.

## Database assessment

The original directory migration already provides id, warehouse_id, name, status, version, created_at and updated_at. `database/migrations/2026_11_07_235900_add_inbound_supplier_codes.php` adds nullable code VARCHAR(32), a unique index and initial codes for existing rows. No additional column or new migration is required for this scope. Deployments must have applied that existing migration before using these APIs. This assessment is based on repository migrations, not inspection of a running server database.

## Verification

Feature tests cover creation on an empty directory and after existing/inactive codes, progression beyond NCC-999, invalid input and immutable fields, permissions and authentication, update success and stale versions, missing suppliers and preservation of historical snapshot data. Check the existing supplier-read suite and authorization route coverage. Verify concurrent creations against disposable PostgreSQL when available; do not claim SQLite proves PostgreSQL concurrency. Update OpenAPI and the frontend handoff with request/response examples.

## Scope

This change implements backend APIs only. No supplier deletion, additional contact fields, UI, deployment or live database migration is included.
