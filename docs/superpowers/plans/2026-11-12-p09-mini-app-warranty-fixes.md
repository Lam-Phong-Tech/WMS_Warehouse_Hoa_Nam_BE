# P09 Mini App Warranty — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unblock the Mini App warranty write flow on never-recorded request IDs, and add the missing read-model fields (`status_counts`, `actor.display_name`, component issue summary) plus retire `DEF-902`.

**Architecture:** Keep P09 as the single receipt/state-machine owner. Move pre-execution validation inside the existing receipt boundary, add a global `request_id` existence gate to reconcile so only truly unknown IDs become `NOT_FOUND` + retryable, and extend read projections additively. The DEF-902 correction is an idempotent data migration with audit + cache-version bumps.

**Tech Stack:** Laravel 11 / PHP 8.4, PostgreSQL (dev-test), SQLite in-memory (tests), PHPUnit, Valkey cache.

**Spec:** `P09_MINI_APP_WARRANTY_BE_ANALYSIS_20261112.txt` (approved 2026-11-12)

## Global Constraints

- Keep `p09_warranty_requests` as the durable receipt owner; no second receipt table.
- `GET /warranty-requests/{requestId}` stays HTTP 200; state + `may_create_new_request` is the signal.
- A request ID known only in another scope must stay `UNKNOWN` + `may_create_new_request: false`.
- Only `Modules/Warehouse/app/Http/Controllers/MiniAppWarrantyController.php`, `Modules/Warehouse/app/Support/P09WarrantyRequests.php`, new migrations, tests, and docs are in scope.
- Do not touch or commit the pre-existing modified files `CurrentBaselineWarrantyController.php` and `tests/Feature/WarrantyFlow04ApiSurfaceTest.php`.
- Push only files created/modified in this session to `dev`.

## Review Focus

- Replaying a failed request with the same `Idempotency-Key` but a changed body must remain `409 IDEMPOTENCY_CONFLICT`, not overwrite the FAILED receipt.
- A request ID that succeeded under another actor/session/warehouse must remain `UNKNOWN`/blocked on the current scope.
- `status_counts` must exclude only the `status` filter; search/date/warehouse filters still apply, and `ALL` is the sum of grouped statuses.
- Component summary quantity must come from POSTED line `scanned_qty`, never invent a value for a null/incomplete line.
- DEF-902 status correction must be idempotent and must not change historical `warranty_case.defect_id` references.

---

### Task 1: P09 receipt boundary + NOT_FOUND reconcile

**Files:**
- Modify: `Modules/Warehouse/app/Support/P09WarrantyRequests.php`
- Modify: `Modules/Warehouse/app/Http/Controllers/MiniAppWarrantyController.php`
- Create: `Modules/Warehouse/database/migrations/2026_11_12_000200_add_p09_warranty_requests_request_id_index.php`
- Test: `tests/Feature/P09WarrantyReceiptReconcileTest.php`

**Interfaces:**
- Produces: `P09WarrantyRequests::reconcile()` returns `NOT_FOUND` receipt for globally unknown request IDs; `UNKNOWN` for scope-foreign rows.
- Produces: `P09WarrantyRequests::execute()` returns `data.receipt` on the first 4xx with field-level `errors`/`message`.
- Consumes: `P09WarrantyScope::resolve()`, `P09WarrantyRequests::execute()`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/P09WarrantyReceiptReconcileTest.php` with:
- `test_unknown_request_id_returns_not_found_and_allows_new_request`
- `test_receipt_from_another_session_stays_unknown_and_blocked`
- `test_validation_4xx_persists_failed_receipt_with_field_errors`
- `test_missing_if_match_persists_failed_receipt`

Use a unique SUPER_ADMIN user/session per call (role `firstOrCreate`, random user), browser login + warehouse context like `MiniAppWarrantyAdapterTest::authHeaders()`. Create a TEMP_ONLY case with `missing_code_reason` + `manual_product_description`.

Assertions:
```php
// unknown id
->assertOk()
->assertJsonPath('data.receipt.state', 'NOT_FOUND')
->assertJsonPath('data.receipt.error_code', 'REQUEST_NOT_RECORDED')
->assertJsonPath('data.receipt.retry_policy', 'NEW_REQUEST_ALLOWED')
->assertJsonPath('data.receipt.may_create_new_request', true);

// foreign session
->assertJsonPath('data.receipt.state', 'UNKNOWN')
->assertJsonPath('data.receipt.may_create_new_request', false);

// validation 4xx
->assertStatus(422)
->assertJsonPath('data.receipt.state', 'FAILED')
->assertJsonPath('data.receipt.may_create_new_request', true)
->assertJsonPath('error_code', 'VALIDATION_ERROR')
->assertJsonStructure(['errors' => ['item_id']]);

// missing If-Match
->assertStatus(428)
->assertJsonPath('data.receipt.state', 'FAILED')
->assertJsonPath('data.receipt.may_create_new_request', true)
->assertJsonPath('error_code', 'PRECONDITION_REQUIRED');
```
Then GET the same request ID and assert `FAILED` + `may_create_new_request: true` for both.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=P09WarrantyReceiptReconcileTest`
Expected: FAIL (UNKNOWN/false and missing receipt paths).

- [ ] **Step 3: Implement the receipt boundary changes**

In `MiniAppWarrantyController::store()`:
- Remove `queryFields()` and `body()` before `Scope::resolve()`.
- Inside the `execute()` closure, call `$this->queryFields($request, ['warehouse_id']);` and `$body = $this->body($request, Policy::CREATE_FIELDS);` before the existing validators.

In `update()`:
- Remove `queryFields()`, `body()`, `hasHeader('If-Match')`, and version parsing before `execute()`.
- Keep `$s = Scope::resolve($request, true);` and the canonical `$id = (string) Scope::requireCase($s, $id)->warranty_case_id;` outside.
- Inside the closure, call `$this->queryFields()`, `$body = $this->body()`, the 428 check, and `Policy::version()`.

In `P09WarrantyRequests`:
- Add `private static function unknownScopeResponse(array $scope, string $requestId): JsonResponse` preserving the current UNKNOWN/false body.
- Add `private static function notFoundResponse(array $scope, string $requestId): JsonResponse` with the approved shape.
- In `reconcile()`: if the scoped row is null, run `DB::table(self::TABLE)->where('request_id', $id)->exists()`; return UNKNOWN if true, NOT_FOUND if false.
- Extend `reply()` with `?array $failure = null`; when non-read and `$failure` is present, merge `message`, `errors`, and `error.details` from the original 4xx body.
- In the catch block, capture `ValidationException->errors()` or the `HttpResponseException` JSON body and pass it to `reply()`.

- [ ] **Step 4: Add the request_id index migration**

Create the migration with:
```php
DB::statement('CREATE INDEX IF NOT EXISTS idx_p09_warranty_requests_request_id ON p09_warranty_requests (request_id)');
```
`down()` drops it with `IF EXISTS`. Skip if the table is absent.

- [ ] **Step 5: Run the focused tests**

Run: `php artisan test --filter="P09WarrantyReceiptReconcileTest|P09WarrantyHandoverContractTest|P12ReadRevision2Test"`
Expected: PASS. The foreign-session P12 test must remain UNKNOWN/false.

- [ ] **Step 6: Commit**

```bash
git add Modules/Warehouse/app/Support/P09WarrantyRequests.php \
  Modules/Warehouse/app/Http/Controllers/MiniAppWarrantyController.php \
  Modules/Warehouse/database/migrations/2026_11_12_000200_add_p09_warranty_requests_request_id_index.php \
  tests/Feature/P09WarrantyReceiptReconcileTest.php
git commit -m "fix(p09): persist rejected warranty receipts and return NOT_FOUND for unknown request ids"
```

---

### Task 2: `status_counts` facet

**Files:**
- Modify: `Modules/Warehouse/app/Http/Controllers/MiniAppWarrantyController.php`
- Test: `tests/Feature/MiniAppWarrantyAdapterTest.php`
- Docs: `docs/contracts/p23-read-v2.json`

**Interfaces:**
- Produces: `meta.status_counts` = `{ALL, RECEIVED, CHECKING, REPAIRING, COMPLETED, RETURNED, CANCELLED}` and `meta.status_counts_basis`.
- Consumes: `Policy::STATUSES`, `Scope::cases()`, `P23WarrantyDateFilter`.

- [ ] **Step 1: Write the failing test**

Add `test_status_counts_exclude_only_the_status_filter` to `MiniAppWarrantyAdapterTest`:
- Create 3 cases in the test warehouse and update statuses to `RECEIVED`, `CHECKING`, `CHECKING`.
- Move one extra case to another warehouse via `warranty_case_warehouse_owners.warehouse_id` update and set status `COMPLETED`.
- `GET /warranty-cases?per_page=100` → `meta.status_counts.ALL=3`, `CHECKING=2`, `RECEIVED=1`, `COMPLETED=0`.
- `GET ...?status=CHECKING` → `meta.total=2`, `meta.status_counts.ALL=3`, `CHECKING=2`.
- Search for one case code → counts reflect only that matched case.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_status_counts_exclude_only_the_status_filter`
Expected: FAIL (`meta.status_counts` missing).

- [ ] **Step 3: Implement**

- Extract the existing search closure into `private function applyWarrantySearch(Builder $query, string $search, array $scope): void`.
- In `index()`, after dates + search and before the status filter:
```php
$counts = (clone $q)->selectRaw('c.status, COUNT(*) AS count')
    ->groupBy('c.status')->pluck('count', 'status');
$statusCounts = ['ALL' => (int) $counts->sum()];
foreach (Policy::STATUSES as $status) {
    $statusCounts[$status] = (int) ($counts[$status] ?? 0);
}
```
- Apply the status filter after the clone.
- Add to the `reply()` meta:
```php
'status_counts' => $statusCounts,
'status_counts_basis' => 'SAME_WAREHOUSE_SEARCH_DATE_FILTERS_EXCLUDING_STATUS',
```

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="MiniAppWarrantyAdapterTest|Wwhn340WarrantyListFilterTest"`
Expected: PASS.

- [ ] **Step 5: Update contract doc + commit**

Add `status_counts`/`status_counts_basis` to `docs/contracts/p23-read-v2.json` `case_list`.

```bash
git add Modules/Warehouse/app/Http/Controllers/MiniAppWarrantyController.php \
  tests/Feature/MiniAppWarrantyAdapterTest.php docs/contracts/p23-read-v2.json
git commit -m "feat(p09): expose warranty status counts in list meta"
```

---

### Task 3: Event actor display name

**Files:**
- Modify: `Modules/Warehouse/app/Http/Controllers/MiniAppWarrantyController.php`
- Test: `tests/Feature/MiniAppWarrantyAdapterTest.php`

**Interfaces:**
- Produces: `actor.display_name`, `actor.display_name_status`, `actor.display_name_source` on every event.
- Status values: `CURRENT_DIRECTORY_LABEL_NOT_HISTORICAL_SNAPSHOT`, `NOT_RECORDED_IN_EVENT`, `ACTOR_SOURCE_UNAVAILABLE`.

- [ ] **Step 1: Write the failing test**

Add `test_events_resolve_actor_display_name_from_app_user`:
- Create a case; the RECEIVE event is created by the test user.
- Insert a `warranty_event` with `created_by = Str::uuid()` (unknown) and one with `created_by = null`.
- GET `/events`; assert the known event has `actor.display_name = 'Mini Warranty Tester'` and status `CURRENT_DIRECTORY_LABEL_NOT_HISTORICAL_SNAPSHOT`; the unknown event has `ACTOR_SOURCE_UNAVAILABLE`; the null event has `NOT_RECORDED_IN_EVENT`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_events_resolve_actor_display_name_from_app_user`
Expected: FAIL (display_name null).

- [ ] **Step 3: Implement**

- After loading `$events`, batch query:
```php
$actorIds = $events->pluck('created_by')->filter()->unique()->values()->all();
$actors = $actorIds === [] ? collect() : DB::table('app_user')
    ->whereIn('user_id', $actorIds)->get(['user_id', 'display_name'])->keyBy('user_id');
```
- In the row map, set `display_name`, `display_name_status`, `display_name_source` by the three cases.
- Do not filter `deleted_at`; a historical actor name remains readable. Catch `QueryException` and use `ACTOR_SOURCE_UNAVAILABLE` if the source is unavailable.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="MiniAppWarrantyAdapterTest|P12ReadRevision2Test"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/Warehouse/app/Http/Controllers/MiniAppWarrantyController.php \
  tests/Feature/MiniAppWarrantyAdapterTest.php
git commit -m "feat(p09): resolve warranty event actor display names"
```

---

### Task 4: Component issue summary on legacy list

**Files:**
- Modify: `Modules/Warehouse/app/Http/Controllers/MiniAppWarrantyController.php`
- Test: `tests/Feature/P20ComponentHistoryTest.php`

**Interfaces:**
- Produces on the legacy path only: `line_count`, `total_quantity`, `quantity_status`, `lines[]`, `document_no`, `posted_at`.
- P20 v1 opt-in response remains unchanged (`list_has_lines: false`).

- [ ] **Step 1: Write the failing test**

In `P20ComponentHistoryTest`, on the existing legacy list call (`headers($token, false)`), assert:
- `data.0.line_count = 1`
- `data.0.total_quantity = 3`
- `data.0.quantity_status = CONFIRMED`
- `data.0.lines.0.sku_code` equals the fixture SKU code
- `data.0.lines.0.quantity = 3`
- `data.0.document_no` and `data.0.posted_at` are not null
- P20 opt-in list still has no `lines` key.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=P20ComponentHistoryTest`
Expected: FAIL (missing summary fields).

- [ ] **Step 3: Implement**

In `MiniAppWarrantyController::componentIssues()`:
- Batch load documents by `$ids` (`id`, `component_issue_no`, `posted_at`) using the existing POSTED/warehouse/`Scope::isolate` filters.
- Batch load lines by `$ids` (`component_issue_document_id`, `sku_id`, `scanned_qty`) with `Scope::isolate`.
- Batch load SKUs by the collected `sku_id`s (`sku_id`, `sku_code`, `sku_name`, `unit`) with `Scope::isolate`.
- Group lines by document + SKU; `quantity = SUM(scanned_qty)`, `line_count` = number of line rows, `total_quantity` = sum of grouped quantities, `quantity_status = INCOMPLETE` if any line `scanned_qty` is null or the doc has no lines, otherwise `CONFIRMED`.
- Add `document_no` + ISO-8601 `posted_at` to each row.
- Add meta `component_lines_basis = SUM_POSTED_LINE_SCANNED_QTY_BY_CURRENT_SKU_MASTER`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="P20ComponentHistoryTest|MiniAppWarrantyAdapterTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/Warehouse/app/Http/Controllers/MiniAppWarrantyController.php \
  tests/Feature/P20ComponentHistoryTest.php
git commit -m "feat(p09): include component issue line summary in warranty case list"
```

---

### Task 5: Retire DEF-902 with audit + cache bump

**Files:**
- Create: `Modules/Warehouse/database/migrations/2026_11_12_000300_deactivate_def_902.php`
- Test: `tests/Feature/DefectCatalogStatusMigrationTest.php`
- Docs: `docs/contracts/document-numbering-v1.md`, `docs/contracts/HoaNam_Current_Baseline_Contract.json`, `docs/contracts/p12-read-v2.json`, `docs/fe-handoff/P09_MINI_APP_WARRANTY_FIXES_20261112.md`

**Interfaces:**
- Produces: `DEF-902` status is `INACTIVE`, version incremented, audit log row written, cache versions bumped.
- Consumes: `AuditLogger::record()`, `CacheVersion::bump()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DefectCatalogStatusMigrationTest.php`:
- Insert `defect_catalogs` row `DEF-902` with `status='ACTIVE'`, `version=1`.
- `require` the migration file and call `up()`.
- Assert `status='INACTIVE'`, `version=2`, and `audit_log` has `object_type='defect_catalog'`, `action='UPDATE'`, `object_id` = the defect ID.
- Call `up()` again; assert version stays 2 and only one audit row.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=DefectCatalogStatusMigrationTest`
Expected: FAIL (migration file missing).

- [ ] **Step 3: Implement the migration**

```php
$defect = DB::table('defect_catalogs')->where('defect_code', 'DEF-902')
    ->first(['defect_id', 'id', 'status', 'version']);
if ($defect === null || $defect->status === 'INACTIVE') {
    return;
}
DB::table('defect_catalogs')->where('defect_code', 'DEF-902')->update([
    'status' => 'INACTIVE',
    'version' => DB::raw('COALESCE(version, 0) + 1'),
    'updated_at' => now(),
]);
AuditLogger::record(
    action: 'UPDATE',
    objectType: 'defect_catalog',
    objectId: (string) ($defect->defect_id ?? $defect->id),
    actorId: null,
    requestId: 'MIGRATION_2026_11_12_000300',
    beforeData: ['defect_code' => 'DEF-902', 'status' => $defect->status, 'version' => (int) $defect->version],
    afterData: ['defect_code' => 'DEF-902', 'status' => 'INACTIVE', 'version' => (int) $defect->version + 1],
    reason: 'Data migration: retire DEF-902 from the active defect dropdown.',
    result: 'SUCCESS',
    module: 'SYSTEM',
);
CacheVersion::bump('warehouse.defect_catalog:list');
CacheVersion::bump('stock:version');
```
Guard `Schema::hasTable('defect_catalogs')`.

- [ ] **Step 4: Run tests**

Run: `php artisan test --filter="DefectCatalogStatusMigrationTest|DefectCatalogTest"`
Expected: PASS.

- [ ] **Step 5: Update contract/handoff docs + commit**

Document `NOT_FOUND`, `status_counts`, `actor.display_name`, component summary, and DEF-902.

```bash
git add Modules/Warehouse/database/migrations/2026_11_12_000300_deactivate_def_902.php \
  tests/Feature/DefectCatalogStatusMigrationTest.php \
  docs/contracts/document-numbering-v1.md \
  docs/contracts/HoaNam_Current_Baseline_Contract.json \
  docs/contracts/p12-read-v2.json \
  docs/fe-handoff/P09_MINI_APP_WARRANTY_FIXES_20261112.md
git commit -m "fix(catalog): retire DEF-902 via audited data migration"
```

---

### Task 6: Final verification and push

- [ ] **Step 1: Run the focused warranty/defect suite**

```bash
php artisan test --filter="P09WarrantyReceiptReconcileTest|MiniAppWarrantyAdapterTest|P09WarrantyHandoverContractTest|P12ReadRevision2Test|P20ComponentHistoryTest|Wwhn340WarrantyListFilterTest|WarrantyWarehouseScopeTest|DefectCatalogTest|DefectCatalogStatusMigrationTest"
```

Expected: PASS.

- [ ] **Step 2: Format only changed PHP files**

```bash
vendor/bin/pint Modules/Warehouse/app/Support/P09WarrantyRequests.php \
  Modules/Warehouse/app/Http/Controllers/MiniAppWarrantyController.php \
  Modules/Warehouse/database/migrations/2026_11_12_000200_add_p09_warranty_requests_request_id_index.php \
  Modules/Warehouse/database/migrations/2026_11_12_000300_deactivate_def_902.php \
  tests/Feature/P09WarrantyReceiptReconcileTest.php \
  tests/Feature/DefectCatalogStatusMigrationTest.php \
  tests/Feature/MiniAppWarrantyAdapterTest.php \
  tests/Feature/P20ComponentHistoryTest.php
```

- [ ] **Step 3: Verify the pre-existing modified files are untouched**

```bash
git status --short
```
Expected: only this session's files are staged/committed. `CurrentBaselineWarrantyController.php` and `WarrantyFlow04ApiSurfaceTest.php` remain unstaged modifications from another session.

- [ ] **Step 4: Push only this session's commits**

```bash
git push origin dev
```
