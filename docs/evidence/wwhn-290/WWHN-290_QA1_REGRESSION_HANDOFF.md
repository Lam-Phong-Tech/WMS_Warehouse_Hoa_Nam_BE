# WWHN-290 → WWHN-291 — QA1 regression handoff

- Date: 2026-08-18
- Jira: `WWHN-290` (parent `WWHN-77`), QA ticket `WWHN-291`
- Closes review item: `DEV290-P1-04`
- Machine-readable pins: `docs/evidence/wwhn-290/WWHN-290_PINS.json`
- Reviewer gate: **QA1** — this document prepares the run, it does not record a QA1 result.

## 1. What the review asked for

`DEV290-P1-04` is `OPEN`: WWHN-291 is still `Blocked/Pending` with zero comments and zero attachments. The
reviewer explicitly allowed the honest outcome — *"QA1 không nhất thiết phải test fake defects; nhưng phải độc
lập confirm NO QUALIFYING DEFECT / QA REGRESSION N/A hoặc chạy candidate regression nếu gate yêu cầu."*

So QA1 has two admissible closures. Both are prepared below, so QA1 spends minutes, not a day.

## 2. Prerequisite already settled — the defect-input gate

BA/QA confirmed on WWHN-290 that no concrete UAT defect (defect ID + repro + request-id/log) was ever assigned
to this ticket, and accepted the disposition `NO CODE CHANGE / N/A`. QA1 is therefore **not** expected to
retest invented defects. `DEV290-P1-01` is `CLOSED` on that basis.

## 3. Option A — `QA REGRESSION N/A` confirmation (sufficient when no defect exists)

QA1 confirms three facts, each independently checkable:

| # | Statement | How QA1 checks it |
|---|---|---|
| 1 | No qualifying UAT defect is attached to `WWHN-77` / `WWHN-290` | read both tickets: no defect attachment, no linked defect issue, no repro comment |
| 2 | The candidate range introduces no runtime, migration, seeder or contract change | `bash scripts/verify-wwhn290-closure.sh`, section 4 |
| 3 | The candidate regression surface is green on the candidate | §4 result below, or QA1 re-runs §5 |

That is a complete, defensible QA closure for a ticket whose input gate is `NO CODE CHANGE / N/A`.

## 4. Option B — candidate regression, already executed by DEV as a dry run

DEV executed the focused suite so QA1 knows the expected outcome before spending environment time. **This is a
DEV dry run and is explicitly not a QA1 sign-off.**

| Field | Value |
|---|---|
| Runner | `php artisan test` (PHPUnit) |
| Driver | `sqlite :memory:` (repository default per `phpunit.xml`) |
| Date | 2026-08-18 |
| Result | **91 tests passed, 706 assertions, 0 failures, 0 errors** (~30.7s) |

Suites in scope, chosen to cover exactly the surfaces WWHN-290 could plausibly touch:

| Suite | Surface it protects | SRS reference |
|---|---|---|
| `Wwhn290ClosureEvidenceTest` | closure-evidence integrity and anti-drift pin equality | evidence governance |
| `ReportFlow06ContractTest`, `ReportFlow06IntegrationTest` | report contract, columns, filters, period boundaries | §13.10 |
| `Flow06WarrantyReportAlignmentTest` | warranty report alignment, PII masking | §13.10 RPT-WAR-01, §13.8 |
| `WarrantyFlow04ApiSurfaceTest`, `WarrantyIdempotencyKeyContractTest` | warranty API surface, idempotency/duplicate behaviour | §5.4, §8.2 |
| `AuthorizationTest` | RBAC / action-level permission, 401/403 | §3, §8.2 |
| `InventoryReconciliationIntegrityTest` | ledger = balance = subledger reconciliation | §7.1 `DEC-INV-001`, §7.5 |
| `BackendMigrationSeedHealthTest` | migration/seed health | §13.12 |
| `SwaggerRouteSyncTest` | published contract matches the real route list | §8.4 |

## 5. Exact commands QA1 runs to reproduce

Repository default driver (fast, matches the committed `phpunit.xml`):

```bash
php artisan test --filter '/(Wwhn290ClosureEvidenceTest|ReportFlow06ContractTest|ReportFlow06IntegrationTest|Flow06WarrantyReportAlignmentTest|WarrantyFlow04ApiSurfaceTest|WarrantyIdempotencyKeyContractTest|AuthorizationTest|InventoryReconciliationIntegrityTest|BackendMigrationSeedHealthTest|SwaggerRouteSyncTest)/'
```

PostgreSQL — the reference driver in the docs baseline — requires the environment to be set before the run,
because `phpunit.xml` does not force the connection:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hoa_nam_audit DB_USERNAME=postgres DB_PASSWORD=postgres php artisan test --filter '/(ReportFlow06ContractTest|WarrantyFlow04ApiSurfaceTest|AuthorizationTest|InventoryReconciliationIntegrityTest|SwaggerRouteSyncTest)/'
```

Closure-evidence verification, independent of any database:

```bash
bash scripts/verify-wwhn290-closure.sh
```

Cổng CI `.github/workflows/wwhn-290-closure.yml` chạy đúng bộ test trên **sqlite** (driver mặc định của
`phpunit.xml`) trong job **chặn** `closure`, và chạy `migrate:fresh --seed` PostgreSQL 16 trong job **không
chặn** `postgres-diagnostic`. Lý do tách: bước PostgreSQL dùng chung của dự án đang hỏng vì một defect
thuộc ticket khác — chi tiết và quy trách nhiệm ở `WWHN-290_EXACT_SHA_GATE_RUN.md` mục `DISPOSITION`.
QA1 có thể đóng dựa trên một CI run xanh của job `closure` tại đúng frozen candidate, thay cho chạy tay.

## 6. If QA1 chooses Option B, record this

| Field | Required in the QA1 comment |
|---|---|
| Environment | local / CI, database driver and version |
| Build identity | exact BE SHA (and FE SHA if a consumer check was done) |
| Account / role | the role used for RBAC checks |
| Result | pass/fail counts, plus any failing test name verbatim |
| Evidence | CI run URL or the console summary line |

## 7. Scope QA1 is *not* asked to cover

Per the accepted disposition, QA1 does not need to produce positive/negative cases per defect, DB before/after
snapshots, or reconciliation runs — there is no defect and no data write in this range. If a real defect is
attached later, that scope returns in full and this handoff is superseded.

DEV does not sign this gate.

## 8. Chạy tại đúng frozen candidate (yêu cầu mới của `DEV290-P1-04R`)

Review vòng 3 yêu cầu QA1 ghi nhận `NO QUALIFYING DEFECT / REGRESSION N/A` **tại exact SHA**. SHA đó là
`frozen_candidate.sha` trong `WWHN-290_PINS.json` — một chỗ duy nhất, có test chặn lệch.

```bash
git fetch origin m1
SHA=$(php -r 'echo json_decode(file_get_contents("docs/evidence/wwhn-290/WWHN-290_PINS.json"), true)["frozen_candidate"]["sha"];')
git checkout "$SHA"
composer install --no-interaction
bash scripts/verify-wwhn290-closure.sh
php artisan test --filter '/(Wwhn290ClosureEvidenceTest|ReportFlow06ContractTest|ReportFlow06IntegrationTest|Flow06WarrantyReportAlignmentTest|WarrantyFlow04ApiSurfaceTest|WarrantyIdempotencyKeyContractTest|AuthorizationTest|InventoryReconciliationIntegrityTest|BackendMigrationSeedHealthTest|SwaggerRouteSyncTest)/'
```

Kết quả DEV đo được tại frozen candidate nằm ở `WWHN-290_EXACT_SHA_GATE_RUN.md` kèm log thô trong
`docs/evidence/wwhn-290/runs/`. Đó là **dry run của DEV**, không phải chữ ký QA1 — QA1 vẫn phải tự chạy
hoặc tự đối chiếu CI run rồi comment trên `WWHN-291`.
