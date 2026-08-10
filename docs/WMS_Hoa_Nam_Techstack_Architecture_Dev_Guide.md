# WMS HOA NAM — TECHSTACK, ARCHITECTURE & DEV GUIDE

**Document ID:** HN-WMS-TECH-GUIDE-001  
**Version:** 2.0  
**Technical alignment:** 10/08/2026  
**Semantic baseline:** 04/08/2026  
**Status:** Implementation baseline for DEV/QA/DevOps

## 1. Source of truth and conflict rule

This guide implements, and must not override, the approved business baseline. Priority is: approved contract/CR/decision → BRD v3.13 → SRS/FRD v1.6 → BPD v1.5 → ERD/OpenAPI/UI/test artifacts. The current physical/data baseline is `HoaNam_ERD_Moi_Nhat.md` (`HN-WMS-ERD-DD-001 v1.0`) and the consolidated business/API baseline is `HoaNam_Tai_lieu_tong_hop_Moi_Nhat.md` (`HN-BA-MASTER-DEVTEST-001 v1.0`).

The former Stocktake/Apply-Adjustment design and partial-posting design are **superseded**. MVP must not expose Stocktake sessions/snapshots/counts/variances/adjustments, `PARTIALLY_POSTED`, `remaining_qty`, `cancelled_remainder_qty`, `close-remainder`, stock reservations, safe-stock, transfer-orders, stock-requests, or agency stock ledgers.

## 2. Runtime stack

- PHP 8.3 / Laravel modular monolith.
- PostgreSQL 14+ is the production reference DB. CI also runs SQLite/MySQL compatibility gates where applicable.
- Redis/Valkey may be used for cache/queue/session; correctness must not depend on cache.
- JWT authentication; action-level RBAC middleware.
- L5-Swagger / swagger-php for OpenAPI generation.
- Docker immutable build artifact; exact commit SHA is the deployment identity.

## 3. Architecture boundaries

Modules remain `Auth`, `Warehouse`, and `AuditLog`. Controllers validate transport input and delegate transaction/business work; stock-changing use cases must execute inside a DB transaction, lock the affected aggregate/balance rows, enforce idempotency, write append-only movement/audit evidence, and update the balance projection atomically.

`inventory_movements` is authoritative history; `inventory_balances` is a projection/cache. Posted documents, movement rows, posting evidence and audit evidence are append-only. Correction is performed by controlled reversal, never by editing posted history.

MVP has one active Hoa Nam warehouse. Agency is a recipient master only. Zone/rack/bin/tray are locators/container metadata, not independent stock buckets. PV is read-only/public catalog scope with no cart/order/reservation/payment semantics.

## 4. Data conventions

- UUID primary keys; `timestamptz`/UTC for time.
- Inventory quantities are integer `BIGINT`. Fractional stock requires an approved CR before schema change.
- Statuses are varchar + CHECK/validated allow-list, not PostgreSQL ENUM.
- Mutable business aggregates use `version` and `If-Match`; missing precondition is HTTP 428, stale precondition is HTTP 412.
- Product/stock/audit history is retained; no hard delete of posted transactional data.
- Warranty PII is role-scoped and must not be written raw to application logs.

Canonical status values are those in `HN-WMS-ERD-DD-001`: document `DRAFT|SCANNING|POSTED|CANCELLED`; SKU `PRODUCT|COMPONENT`; tracking `SERIAL|QUANTITY`; movement `OPENING|RECEIPT|ISSUE|COMPONENT_ISSUE|REVERSAL`; stock `AVAILABLE|NON_SELLABLE|DEFECTIVE|QUARANTINE`; warranty `RECEIVED|CHECKING|REPAIRING|COMPLETED|RETURNED|CANCELLED`.

## 5. API contract

All application API routes use `/api/v1/*`. Current baseline contains **129 unique method+path operations**. `app/Swagger/Processors/RouteSyncProcessor.php` is the route-parity processor: generation must remove stale paths and add real Laravel paths so Swagger method/path surface is exactly the runtime route surface. `SwaggerRouteSyncTest` fails on drift.

Route parity does not replace business schemas. Request/response payload schemas, validation, examples and business error codes remain explicit API contract responsibilities. The generic error envelope must cover HTTP `400, 401, 403, 404, 409, 412, 422, 428, 500`, with `request_id` returned for traceability.

## 6. Canonical permissions

New routes must use the current namespace, not legacy aliases:

- IAM: `iam.user.view/manage`, `iam.role.view/manage`, `iam.permission.view`.
- Catalog/SKU: `catalog.view/manage`, `sku.view/manage`, `agency.view/manage`, `defect.view/manage`.
- Inbound: `inbound.view/create/scan/post/cancel`.
- Outbound: `outbound.view/import/create/scan/post/cancel`.
- Inventory: `inventory.view`, `inventory.trace`, `inventory.reversal.post`.
- Physical/label: `physical_code.assign_rfid`, `label.print`, `label.reprint`.
- Warranty: `warranty.manage`, `warranty.component_issue`.
- PV/report: `pv.publish`, `report.view`, `report.export`.

Legacy permission keys may be seeded only as compatibility aliases for old clients/tests; they must not be used on new canonical routes.

Minimum business roles are `ADMIN`, `WAREHOUSE_MANAGER`, `WAREHOUSE_STAFF`, `WARRANTY_STAFF`, `LEADERSHIP`. `SUPER_ADMIN` remains a technical/bootstrap compatibility role.

## 7. Transaction invariants

Inbound/outbound are full-post only. Before Post, every line must satisfy scanned/matched quantity = expected/required quantity. Product outbound requires the matched PRODUCT item to have an active RFID/hardware identity according to the approved rule. Components are issued only for active warranty cases. No balance may become negative. `inventory_balances` is unique by `(warehouse_id, sku_id, stock_status)`.

A repeated command with the same idempotency key must return the prior result or a deterministic conflict; it must not double-write movement/balance/audit. Concurrent stock mutation must serialize by DB transaction/row locking and preserve ledger-balance equality.

## 8. Development flow

1. Change business behavior only against the current source documents/approved CR.
2. Add/update migration before model/service code when the physical contract changes.
3. Add route with canonical permission.
4. Implement validation + transaction + idempotency + audit/movement rules.
5. Generate Swagger and run route-parity/contract tests.
6. Run `migrate:fresh --seed`, full PHPUnit, static/style gates and DB-engine matrix.
7. Build immutable Docker artifact and retain exact SHA + OpenAPI + hashes as evidence.

No change is complete if only Swagger or only tests were updated while runtime routes/schema still disagree with the source documents.

## 9. CI quality gate

A releasable SHA must pass: syntax/style, Composer/npm/build/audit, migration + seed, current documentation contract test, Swagger route parity, ERD baseline tests, full PHPUnit, and exact-SHA evidence workflow. UAT deployment remains an explicit opt-in action after the test gate.

The machine-readable baseline is `docs/contracts/HoaNam_Current_Baseline_Contract.json`. `tests/Feature/DocumentationBaselineContractTest.php` pins source document IDs, 129-operation API surface, forbidden scope, canonical route permissions, required DB objects/critical columns, required roles/permissions, Swagger error status coverage and PostgreSQL BIGINT quantity semantics.

## 10. Change control

When BA/PO approves a newer baseline, update the source document first, then the machine contract, migration/API implementation, tests and generated OpenAPI in the same change set. A downstream artifact must never silently redefine business semantics.
