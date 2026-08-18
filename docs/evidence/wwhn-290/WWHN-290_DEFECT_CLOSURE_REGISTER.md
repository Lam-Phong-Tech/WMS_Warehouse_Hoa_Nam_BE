# WWHN-290 — Defect closure register and QA handoff

- Date: 2026-08-18
- Jira: `WWHN-290` — `[BE] Khắc phục lỗi dữ liệu/báo cáo/tính năng`
- Parent: `WWHN-77`
- Branch policy: `m1` only; `m1-be-2` is not an accepted closure source for this package.
- BE runtime revision reviewed before this evidence-only package: `57370ed29f0749235c06ab8aef2990ff6bac6a0f`
- FE consumer/evidence revision: `9076cbdc3cd7a3599a17171b9ce11b26dfcbfc2c`
- OpenAPI target version: `2.0.0`
- OpenAPI blob SHA: `c262a0d3c1525807e50ec88e436d8b5459e8ac0d`
- API namespace: `/api/v1`

## 1. Baseline reviewed

The candidate is checked against the current project baseline used by `m1`:

- `docs/HN_SRS_FRD_v1.12_FULL.md`
- `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html`
- `docs/HoaNam_ERD_Moi_Nhat.md`
- `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`

Relevant invariants are fail-closed authorization/branch scope, validation, transaction integrity, append-only/auditable history where applicable, authoritative inventory movement/reconciliation semantics, and stable `/api/v1` contracts.

## 2. Verified defect-input disposition

`WWHN-77` requires `Triaged backend lỗi`, `Root cause/log/request-id`, and `Regression scope`. The live Jira parent currently has no defect attachment, no linked defect issue and no comment containing a concrete UAT defect identity/repro. The latest `WWHN-290` review (`DEV290-P1-01`) identifies the same missing input.

**Fail-closed disposition:** no runtime business-code change is attributed to WWHN-290 without a verified defect identity. Existing fixes already present on `m1` must not be retroactively relabeled as WWHN-290 fixes without source evidence.

If a real defect is supplied, add one row per defect below before claiming closure:

| Defect ID | Severity | Module / endpoint / report | Expected | Actual | Repro + request-id/log | Root cause | Fix SHA/files | Data/role impact | Regression tests | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| `NO-VERIFIED-UAT-DEFECT-INPUT` | N/A | WWHN-290 input gate | A triaged defect with reproducible evidence | Jira provides no concrete defect record | WWHN-77/WWHN-290 live issue inspection | Upstream defect register was never pinned | No speculative runtime fix | None introduced by this closure package | Existing suites listed in §5 + WWHN-290 CI gate | `FAIL-CLOSED / NEEDS OWNER DISPOSITION` |

This row is not a fake defect and must not be converted to PASS by DEV. It can be closed only by either (a) supplying the real defect rows, or (b) an independent owner confirming that no qualifying WWHN-290 defect exists and that the item is therefore `NO CODE CHANGE / N/A`.

## 3. Contract disposition — DEV290-P1-03

Canonical contract for this candidate is `storage/api-docs/api-docs.json` `info.version = 2.0.0`, blob `c262a0d3c1525807e50ec88e436d8b5459e8ac0d` on `m1`.

- Contract change introduced by this closure package: **NO**.
- Backward-compatibility impact introduced by this closure package: **NONE**.
- FE consumer baseline: exact FE revision above; FE handoff records that no payload/enum/endpoint adaptation is justified without a concrete mismatch.
- An older `m1-be-2` OpenAPI version must not be used as WWHN-290 closure evidence.

## 4. Data / migration / report impact — DEV290-P1-05

Changes introduced by this closure package are evidence + CI guard only.

- Database schema migration: **NO**.
- Seed change: **NO**.
- Direct UAT/production data correction: **NO**.
- ERD change: **NO**.
- Report formula/query change: **NO**.
- Stock-changing operation: **NO**.
- Rollback script for data: **N/A**, because this package performs no data write/migration.

Therefore counts/checksum/reconciliation/rollback execution is not applicable to this evidence-only closure change. If a later real defect requires a data write, this N/A is automatically invalid and an audited idempotent script/runbook with before/after counts, checksum/reconciliation and rollback evidence becomes mandatory.

## 5. Existing regression surface to reuse, not duplicate

The repository already contains targeted suites suitable for WWHN-290 candidate verification, including:

- `tests/Feature/ReportFlow06ContractTest.php`
- `tests/Feature/ReportFlow06IntegrationTest.php`
- `tests/Feature/Flow06WarrantyReportAlignmentTest.php`
- `tests/Feature/WarrantyFlow04ApiSurfaceTest.php`
- `tests/Feature/WarrantyIdempotencyKeyContractTest.php`
- `tests/Feature/AuthorizationTest.php`
- `tests/Feature/SecurityRbacAuditNegativeTest.php`
- `tests/Feature/InventoryReconciliationIntegrityTest.php`
- `tests/Feature/BackendMigrationSeedHealthTest.php`
- `tests/Feature/SwaggerRouteSyncTest.php`

The WWHN-290 CI workflow executes a focused subset and emits an exact-SHA artifact. Source-level test existence is DEV evidence; it does not replace QA1 runtime/UAT sign-off.

## 6. P1 closure map

| Review item | Repository disposition | Remaining independent gate |
|---|---|---|
| `DEV290-P1-01` | Missing source defect is explicitly recorded fail-closed; matrix schema is pinned and speculation is prohibited. | Defect owner/QA must supply real rows or approve `NO CODE CHANGE / N/A`. |
| `DEV290-P1-02` | Canonical branch is `m1`; exact BE/FE revisions and a dedicated exact-SHA CI artifact are pinned. | Reviewer verifies final workflow run/commit after this package lands. |
| `DEV290-P1-03` | OpenAPI `2.0.0` + blob SHA pinned; no contract delta in this package; FE handoff created. | FE1 confirms consumer compatibility once. |
| `DEV290-P1-04` | QA handoff and deterministic candidate suite are prepared. | **QA1 must execute/verify WWHN-291; DEV cannot self-sign.** |
| `DEV290-P1-05` | Data/migration/report impact is explicitly `NO CHANGE`; rollback/reconcile N/A is justified for this package. | Independent Data/Tech reviewer confirms this N/A once. |

## 7. QA1 exact-candidate handoff — WWHN-291

QA1 should verify the exact final WWHN-290 BE SHA and FE consumer SHA and record PASS/FAIL for:

1. positive and negative API behavior for each real defect row, if any;
2. 401/403/RBAC and branch/data-scope behavior where impacted;
3. report totals/filter/date-boundary/reconciliation behavior where impacted;
4. warranty status/idempotency/duplicate behavior where impacted;
5. DB before/after and reconciliation only when a real defect writes data;
6. regression scope from the defect row;
7. exact environment, build/SHA, account/role, request/response or request-id/log.

Current Jira status observed for WWHN-291 at preparation time: `Blocked/Pending`, with no comment and no attachment. This document intentionally does **not** claim QA1 PASS.

## 8. Final closure rule

WWHN-290 may be moved to final review only when the exact-SHA CI run is green and the independent gates above are recorded. A DEV-authored statement cannot substitute for QA1/FE1/Data/Tech sign-off.
