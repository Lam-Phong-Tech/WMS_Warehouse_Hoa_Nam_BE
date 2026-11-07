# Supplier Write API Implementation Plan

> **For agentic workers:** Use executing-plans to implement this plan task-by-task.

**Goal:** Create and edit suppliers with stable automatic NCC codes.
**Architecture:** A focused write controller extends the existing authenticated P04 directory. PostgreSQL advisory locking serializes allocation; existing unique code index remains authoritative. No new schema is needed.
**Tech Stack:** Laravel, PHP, PostgreSQL, PHPUnit/SQLite.
**Spec:** ../specs/2026-11-07-supplier-write-api-design.md (approved by user).

## Global Constraints

- Reuse inbound_suppliers and catalog.manage; keep read ACL unchanged.
- Preserve UUID/code and receipt history on update; require If-Match.
- No live migrations, seed, deployment or unrelated edits.

## Review Focus

- Simultaneous allocation: verify serialization on disposable PostgreSQL if available.
- Numeric order past 999, inactive codes and overflow: feature tests.
- Untrusted fields and whitespace-only names: feature tests.
- Stale edits and expired/absent authentication: feature tests.
- History and identity preservation: update/database assertions plus existing P04 tests.

### Task 1: Supplier writes

Files: new SupplierWriteController.php; modify p04_inbound.php; new SupplierWriteApiTest.php.
Interfaces: POST suppliers(name, optional status) -> 201 row; PATCH suppliers/{id}(name and/or status, If-Match) -> 200 row/ETag.
- [x] Write feature tests; run and confirm missing routes fail.
- [x] Implement controller, validation, auth/session checks, code allocation and optimistic update locking.
- [x] Run supplier write/read, P04 and authorization tests; fix failures.

### Task 2: Documentation and verification

Files: supplier OpenAPI source, supplier-business-code-v1.json, frontend handoff and verification record.
- [x] Document create/update fields, version and errors; update obsolete future-creation note.
- [x] Run formatting, PHP lint, OpenAPI generation and repository test command as feasible; record exact results and limitations.
- [x] Independent review complete. User subsequently requested commit/push to dev.
