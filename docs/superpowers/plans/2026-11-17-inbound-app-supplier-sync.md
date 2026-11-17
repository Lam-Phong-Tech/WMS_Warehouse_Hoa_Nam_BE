# Inbound App Supplier Synchronization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Display and persist suppliers consistently for old and new app receipts without changing app contracts.

**Architecture:** Keep app metadata intact, fill canonical header within recording transaction, batch-resolve legacy read fallback, and reconcile legacy headers explicitly.

**Tech Stack:** Laravel 13 / PHP 8.3+, SQLite regression tests, PostgreSQL DEV.

**Spec:** docs/superpowers/specs/2026-11-17-inbound-app-supplier-sync-design.md

## Global Constraints

- Preserve app API contracts, stock behavior and metadata snapshots.
- No frontend changes; no production deployment. Only the narrowly reviewed POSTED supplier-repair guard migration is added, with all other immutability rules preserved.
- Only backfill NULL receipt header suppliers from exact existing metadata references; never guess or overwrite.

## Review Focus

- Historical INACTIVE suppliers must still display and reconcile.
- Header/metadata conflicts preserve header and are reported.
- App replay and failed recording must remain atomic.
- Reconciliation preserves business fields and is idempotent.
- Failed report creation must prevent data changes.

### Task 1: Receipt write and web compatibility

**Files:** Modify P04InboundController.php, Concerns/InteractsWithStockDocuments.php; test tests/Feature/P04InboundContractTest.php.

**Interfaces:** Existing record and GET endpoints remain unchanged; web receives resolved supplier_id and supplier.

- [ ] Add new-receipt assertion and legacy list/detail/precedence regressions; run and observe missing header/fallback failures.
- [ ] Persist verified supplier in the existing record transaction; batch-read metadata for headers without a supplier.
- [ ] Run P04InboundContractTest and InboundSupplierFieldTest; commit scoped changes.

### Task 2: Historical reconciliation

**Files:** Create app/Console/Commands/SyncInboundSuppliers.php and Modules/Warehouse/database/migrations/2026_11_17_000100_allow_verified_posted_inbound_supplier_repair.php; extend tests/Feature/P04InboundContractTest.php and PostgreSQL CI coverage.

**Interfaces:** inbound:sync-suppliers defaults to dry-run; --apply requires --report=<new file>. JSON manifest records statuses and before/after header values.

- [ ] Add dry-run/apply/idempotency/conflict/report-failure tests and verify failing behavior.
- [ ] Implement guarded transactional reconciliation, durable report and cache version invalidation; preserve all business fields.
- [ ] Verify the POSTED repair guard on PostgreSQL: without transaction-local scope, with wrong supplier, with other field changes, after initial repair and on DELETE it must continue rejecting writes.
- [ ] Verify supplier-only repair leaves ERD mirrors unchanged across UTC-to-Asia/Ho_Chi_Minh session changes; add the scoped mirror early-exit migration, preserving every ordinary app path.
- [ ] Run focused tests, formatting, full suite and stock-write guard; obtain independent code review and commit.

### Task 3: DEV delivery and evidence

**Files:** Deployment and reconciliation evidence under docs/evidence/inbound-app-supplier-sync/ and external server operation directory.

**Interfaces:** Existing DEV CI/deployment; only hoa_nam_dev containers and their database.

- [ ] Back up DEV, apply the exact reviewed additive guard migration and create the trusted migration receipt; publish tested commit through DEV pipeline and wait for exact build SHA health.
- [ ] Dry-run, take protected backup, apply with report, rerun expecting zero eligible records.
- [ ] Verify original app receipts, matching web list/detail supplier, unchanged operational fields/stock, and record final evidence and limitations.
