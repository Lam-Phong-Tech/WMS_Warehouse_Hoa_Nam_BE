# WWHN-219 — Full suite triage on PostgreSQL

First executed full-suite run on the canonical `m1` build. This is the fact the
70/100 assessment predicted would surface: the suite had never actually been run
green on PostgreSQL, only asserted about.

| Metric | Value |
|---|---|
| Branch | `m1` |
| Head at time of run | `59a9eb75c29f01df861cc2fbde0673ee6e31ce0a` |
| Database | PostgreSQL 16, `migrate:fresh --seed` |
| Tests | 455 |
| Passed | 274 |
| **Failed** | **21** |
| **Errored** | **156** |
| Skipped | 4 |
| **Total red** | **177 of 455 (39%)** |
| Duration | ~227s |

> An earlier revision of this document reported only the 21 failures. That was
> incomplete: PHPUnit reports errors separately from failures, and the 156 errors
> were omitted. The corrected total is 177 red.

None of the red tests is caused by the WWHN-219 evidence work. Spot checks confirm
they reproduce in isolation, not only under full-suite ordering.

### After the A1 fix

Same command, same database, after the RBAC migration and doc alignment landed:

| Run | Passed | Failed | Errored | Red |
|---|---|---|---|---|
| Before | 274 | 21 | 156 | 177 |
| After | **275** | **20** | 156 | **176** |
| Delta | +1 | −1 | 0 | −1 |

Exactly one test moved, in the intended direction:
`SecurityRbacAuditNegativeTest::test_backend_denies_permissions_from_inactive_expired_and_future_roles`
is now green. No regressions were introduced.

The WWHN-219 final gate will **fail at Phase 1** until these are resolved. That is
intended. Do not weaken the gate to make it pass.

---

## The dominant root cause: compatibility views are not write-complete

`2026_08_10_000790_retire_legacy_runtime_tables.php` replaced 32 legacy tables with
views over canonical ERD tables, each with an `INSTEAD OF INSERT/UPDATE/DELETE`
trigger. Many of those triggers do not carry every column the canonical table
requires, or that the view advertises. Writes through the view are then either
rejected by a NOT NULL / CHECK constraint, or accepted with the missing values
**silently discarded**.

Roughly 133 of the 177 red tests trace to this one defect class.

| Count | Symptom | Meaning |
|---|---|---|
| 50 | `null value in column "sku_type" of relation "skus"` | `sku` / `products` view trigger drops the column |
| 31 | `null value in column "import_id" of relation "outbound_requests"` | outbound request projection incomplete |
| 23 | `null value in column "id" of relation "containers"` | `container` view trigger does not generate/pass the PK |
| 18 | `chk_erd_physical_codes_2` violated | `physical_code` view drops carrier fields |
| 7 | `null value in column "customer_name" of relation "warranty_cases"` | `warranty_case` view trigger incomplete |
| 2 | `pv_product_profile compatibility view is read-only` | no write path at all |
| 1 | `cannot insert into column "is_serial_tracked" of view "products"` | view has no INSTEAD OF trigger |
| 1 | silent-write class, see A1 below | **P0 security** |

### A1 — P0 SECURITY: time-bounded role assignments were silently permanent — **FIXED**

The most dangerous instance of the defect class above, because it failed *silently*
rather than erroring.

- `user_role` is a view exposing `scope_ref`, `effective_from`, `effective_to`.
- Canonical `user_roles` had only `(user_id, role_id, assigned_at, assigned_by)`.
- Trigger `hn_legacy_user_role_write` inserted only those four columns.

`Modules/Auth/app/Models/User.php:57-65` filters on the window with
`IS NULL OR ...`, so a permanently NULL window matched every branch.

Measured before the fix, against a freshly seeded PostgreSQL 16:

| Case | Expected | Before fix |
|---|---|---|
| Role `status = INACTIVE` | 403 | 403 — correct (the `role` view trigger does persist `status`) |
| Assignment expired yesterday | 403 | **200 — GRANTED** |
| Assignment starting tomorrow | 403 | **200 — GRANTED** |

Revoking access by setting `effective_to` had no effect whatsoever.

**Resolution — Option A, accepted 2026-08-16.** Keep the feature, restore the columns,
amend the ERD to match. Implemented by:

- `Modules/Warehouse/database/migrations/2026_08_16_000100_restore_user_role_effective_window.php`
  — adds `scope_ref` / `effective_from` / `effective_to` to canonical `user_roles`,
  recreates the `user_role` view to project the real columns instead of NULL literals,
  and rewrites the INSTEAD OF trigger to persist them on **both** the INSERT and the
  `ON CONFLICT` branch. Omitting the conflict branch would have re-dropped the window
  on every re-attach.
- `docs/HoaNam_ERD_Moi_Nhat.md` §4 `user_roles` — the three columns are now documented,
  which is also what makes `2026_08_10_000750` materialise them on a fresh migrate.
- `Modules/Warehouse/database/migrations/2026_08_09_000400_align_latest_erd_baseline.php`
  — the SQLite canonical projection now exposes the same three columns.

Primary key stays `(user_id, role_id)`: one assignment row per user+role.

Verified after the fix, on a full-suite run against a freshly migrated and seeded
database: the P0 test is green and the suite delta is exactly +1 passed / −1 red, so
nothing else moved. The one remaining error in that test file is unrelated (see B2).

### Recommended systemic fix for the rest of the class

Add a schema test that, for every compatibility view, asserts its `INSTEAD OF` trigger
persists every non-generated column the view exposes and every NOT NULL column the
canonical table requires. That converts this whole class from silent data loss and
scattered 500s into one deterministic build failure. Without it, each of the ~130
remaining red tests gets fixed one at a time and the class recurs.

---

## B — product guards that contradict the tests (~20)

### B1 — `chk_erd_physical_codes_2`

18 errors. Tests create physical codes whose carrier fields do not satisfy the ERD
check constraint. Either the constraint or the fixtures are wrong; needs a ruling.

### B2 — `uk_warehouses_single_active`

16 errors. A partial unique index enforces exactly one ACTIVE warehouse, matching the
single-warehouse MVP baseline. Multiple tests still create two, e.g.
`SecurityRbacAuditNegativeTest::test_role_based_navigation_capabilities:421` creates
`KHO-NAV1` and `KHO-NAV2` both ACTIVE.

The guard reflects the current baseline, so the tests are the stale side here. Confirm
with BA, then update the fixtures rather than dropping the guard.

### B3 — quantity check constraints

4 errors on `chk_stock_movement_positive_quantity` and
`chk_stock_document_line_integer_positive`.

---

## C — documented physical tables are still views (7 failures)

The ERD Data Dictionary requires certain relations to be physical base tables with
their own constraints, triggers and indexes. They are currently views.

- `DocumentationBaselineContractTest::test_mutable_operational_storage_is_physical` — `reversal_document` is a view
- `DocumentationBaselineContractTest::test_postgres_types_single_warehouse_and_history_guards` — missing `trg_reversal_document_posted_immutable`
- `DocumentationBaselineContractTest::test_reference_database_foreign_keys_match_documented_relationships` — missing FK `sku_compatible_accessories.sku_id -> sku.sku_id`
- `ErdPhysicalSchemaParityTest` ×4 — `defect_catalog` is a view not a base table; nullability, the `user_roles` PK parse, and index `defect_catalog(status)` do not match the dictionary
- `ErdBusinessInvariantParityTest` — missing mirror trigger `app_user.trg_erd_mirror_app_users_from_app_user`

`reversal_document` being a view is directly relevant to WWHN-219: BR-REV-08 requires a
POSTED reversal to be immutable, and the immutability trigger the contract test looks
for does not exist. The runtime concurrency proof shows the *service* layer enforces
single-posting correctly, but there is no database-level guard behind it.

Note on the `user_roles` PK item: `ErdPhysicalSchemaParityTest` derives a single-column
PK expectation from the per-field `PK part` markers and then demands
`user_roles(user_id)` exactly. The physical composite PK `(user_id, role_id)` is correct
and matches the `PK(user_id, role_id)` constraint line. This is a parser/document
mismatch in the test, pre-existing and unrelated to the A1 fix.

---

## D — environment

`DocumentationBaselineContractTest::test_source_documents_and_manifest_are_pinned`
errors because `docs/HoaNam_Tai_lieu_tong_hop_Moi_Nhat.md` is absent from the working
tree. `/docs/` is gitignored wholesale in this repo and the tracked docs were all
force-added, so this file was never committed. Either commit it or remove it from
`sources[]` in `docs/contracts/HoaNam_Current_Baseline_Contract.json`.

---

## Recommended order

1. **A1 — done.** Verify it stays green in CI.
2. Build the systemic view-completeness test, then fix the ~130 remaining view write
   gaps as one change. This is the single highest-leverage item.
3. B2 and B1/B3 — BA ruling on guard-versus-fixture, then update the stale side.
4. C — ERD-versus-implementation reconciliation. Scope as its own ticket, not WWHN-219.
5. D — one-line docs/config decision.
6. Re-run the gate, take a new FINAL_SHA, then collect evidence.

Until Phase 1 is green, WWHN-219 cannot honestly be scored above its current level.
The evidence chain is now sound; the build behind it is not.
