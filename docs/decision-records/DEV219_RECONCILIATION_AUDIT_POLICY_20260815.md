# DEV219 — Reconciliation Scheduler & Audit Write Policy Decision Record

- Date: 2026-08-15
- Jira: WWHN-219 / WWHN-220
- Canonical BE repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
- Target branch: `m1`
- Baseline SHA before DEV219 hardening: `ea30992dbedd0c980aa08dafe173eba93eed24a3`
- Status: **TECHNICAL IMPLEMENTATION READY; HUMAN GOVERNANCE GATES NOT FABRICATED**

## 1. Baseline sources reviewed

DEV219 is implemented against the current repository baselines:

1. `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html`
2. `docs/HoaNam_ERD_Moi_Nhat.md`
3. `docs/HoaNam_Tai_lieu_tong_hop_Moi_Nhat.md`
4. `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`

Security invariant retained: FE authorization is only a UX gate; BE is the security boundary. Inventory ledger/projection reconciliation does not auto-correct stock.

## 2. DEV219-P1-04 — Scheduler decision implemented

Application scheduler registrations:

- `audit:reconcile`: default `*/5 * * * *` (every 5 minutes)
- `inventory:reconcile`: default `*/15 * * * *` (every 15 minutes)
- both commands use `withoutOverlapping(...)`
- both schedules are environment-configurable
- both schedules have environment kill switches

Required infrastructure trigger:

```cron
* * * * * cd /path/to/application && php artisan schedule:run >> /dev/null 2>&1
```

Environment controls:

- `AUDIT_RECONCILE_ENABLED=true|false`
- `AUDIT_RECONCILE_CRON="*/5 * * * *"`
- `AUDIT_RECONCILE_LOCK_MINUTES=10`
- `INVENTORY_RECONCILE_ENABLED=true|false`
- `INVENTORY_RECONCILE_CRON="*/15 * * * *"`
- `INVENTORY_RECONCILE_LOCK_MINUTES=30`

Rollback: set the corresponding `*_ENABLED=false`, clear/config-cache as required by deployment procedure, then verify `schedule:list` and scheduler execution log. This disables new scheduled executions without reverting application code.

Alert/evidence signals:

- `audit:reconcile` returns non-zero when a batch contains failures.
- failed audit retries emit `AUDIT_RECONCILE_FAILED`.
- primary/outbox write failures emit `AUDIT_WRITE_FAILED` / `AUDIT_OUTBOX_WRITE_FAILED`.
- exact-SHA PostgreSQL CI uploads scheduler list, migration status, constraints, triggers, test report and SHA256 manifest.

## 3. DEV219-P1-06 — Explicit audit policy semantics

`AuditLogger` now accepts only:

| Policy | Primary audit fails | Outbox succeeds | Outbox also fails |
|---|---|---|---|
| `outbox_required` | fallback to outbox | business may continue | **fail business operation** |
| `fail_closed` | fallback to outbox | **fail business operation** | **fail business operation** |
| `best_effort` | fallback to outbox | warn + continue | critical/warn + continue |

Unknown policy values fail safe with `AUDIT_WRITE_POLICY_INVALID`; they do not silently degrade to `best_effort`.

`best_effort` is intentionally weaker and must only be enabled when the Tech Lead/authorized architecture owner explicitly approves it for the target use case/environment.

## 4. DEV219-P1-05 — Inventory reconcile classification / retention

Current technical behavior:

- `inventory:reconcile` compares `inventory_balances` projection against `stock_movements` ledger.
- it creates `reconciliation_incidents` for newly detected divergence.
- it writes audit evidence for detected incidents.
- it **does not auto-correct inventory**.

Until BA + QA -> PM formally decide whether this command is an official HN-SEC-001 output or an internal operational utility:

- no destructive incident purge is enabled;
- `RECONCILIATION_INCIDENT_RETENTION_DAYS` remains unset;
- incidents are retained rather than silently deleted;
- the command may be operationally scheduled, but its deliverable classification remains **PENDING HUMAN DISPOSITION**.

This record deliberately does not impersonate BA/QA/PM approval.

## 5. DEV219-P1-03 — PostgreSQL production-parity gate

Workflow: `.github/workflows/dev219-postgres-security-reconciliation-gate.yml`

The gate uses PostgreSQL 16 and records:

- exact repository/ref/SHA/run metadata;
- real `migrate:fresh --seed` result;
- migration status;
- PostgreSQL constraint catalog;
- PostgreSQL trigger catalog;
- scheduler registration;
- targeted abuse/RBAC/replay/race/audit/reconciliation regression tests;
- DB before/after counters;
- SHA-256 manifest;
- immutable GitHub Actions artifact name containing the exact commit SHA.

SQLite remains useful for fast tests but is not accepted as the sole DEV219 production-parity evidence.

## 6. Human closure gates still required

The following evidence must be authored/confirmed by the named human role before Jira can honestly be called 100% closed:

- QA1: execute/review WWHN-220 on the exact build and attach redacted request/response + DB/log evidence.
- DevOps: confirm the production/UAT scheduler host actually invokes `php artisan schedule:run` every minute and attach an execution log.
- BA + QA -> PM: formal disposition for HN-SEC-001 classification and incident retention/disposition.
- Tech Lead: approve/adjust the explicit `best_effort` semantics if that mode is intended to be enabled.

No human sign-off is inferred from code or CI PASS.
