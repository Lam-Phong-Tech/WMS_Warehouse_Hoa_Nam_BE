# WWHN-131 — BE1 draft DEC-STK-001 and stocktake evidence handoff

## 1. Document control

| Field | Value |
|---|---|
| Authoritative BE task | `WWHN-131` |
| Parent | `WWHN-25 — DEC-STK-001` |
| Parent Temp Key | `HN-DEC-003` |
| Role / assignee | `BE1 / Quang Nguyễn Thanh` |
| Repository / branch | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE / m1` |
| Architecture baseline | `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` |
| Document version | `1.3.0` |
| Revision | `2026-08-06 remediation-2` |
| Status | **PROPOSED** |
| Decision output | `docs/decision-records/DEC-STK-001.md v1.0.0 / PROPOSED` |
| Source Register | `docs/docs_task_jira/WWHN-131-source-register.json v1.3.0 / PROPOSED` |
| Exact-SHA workflow | `.github/workflows/wwhn-131-stocktake-contract.yml` |
| Success artifact | `stocktake-evidence-<exact_commit_sha>` |
| Failure diagnostics | `stocktake-diagnostics-<exact_commit_sha>` |

The repository path is case-sensitive and authoritative at
`docs/docs_task_jira/WWHN-131.md`. The Windows-style request
`docs\Task_docs_Jira\WWHN-131.md` resolves to this existing repository document;
a duplicate directory is deliberately not created.

`WWHN-130` belongs to `WWHN-24 / DEC-INV-001`; it is not this BE1 task. Any
WWHN-180 attachment previously used for this gate is non-authoritative and must
be superseded by the WWHN-131 exact-SHA artifact.

## 2. Architecture and proposed decision

The implementation follows the architecture guide: thin HTTP controller,
transactional application service, explicit authorization/data scope,
database-enforced invariants, stable API errors, immutable evidence and tests
bound to the exact source SHA.

Proposed Model A:

```text
expected_at_count = snapshot_qty
                  + SUM(stock_movements.qty_delta
                        from stocktake_started_at through count_cutoff_at)

variance_qty      = actual_qty - expected_at_count
inventory_effect  = variance_qty
```

`qty_delta` is signed. Receipt/adjust-in is positive; issue/adjust-out is
negative. The model preserves normal warehouse posting while making a count
reproducible and auditable. Full warehouse freeze and live-balance-at-apply
remain rejected alternatives because they respectively disrupt operations and
make the variance non-deterministic.

Formal status remains `PROPOSED` until the decision owners record an exact
version/hash/date/result on `WWHN-25`.

## 3. Repository implementation controls

| Risk | Enforced control |
|---|---|
| Concurrent state transition | session row lock plus `session_version` compare-and-swap |
| Concurrent balance update | balance row lock plus balance `version` compare-and-swap |
| Duplicate/replayed apply | unique `(operation,idempotency_key)`, canonical request SHA-256 and committed response snapshot |
| Same key, changed payload | `409 IDEMPOTENCY_ERROR`, no business write |
| Lost response after commit | same-key/same-payload returns the committed snapshot and creates no second effect |
| Second key after apply | session/version gate blocks a second movement/effect |
| Cross-warehouse replay leak | warehouse scope is checked before idempotency snapshot lookup |
| Foreign/partial/missing/duplicate variance | exact approved set is mandatory; invalid selection is `422` with no write |
| Unacknowledged movement | exact detected/acknowledged movement set plus actor/time is mandatory |
| Stale baseline | reviewed balance quantity/version must still match |
| Ledger drift | `SUM(stock_movements.qty_delta)` must equal current balance before apply |
| Negative inventory | computed post-adjustment quantity cannot be negative |
| Empty adjustment | zero effect creates no movement and does not increment balance version |
| Partial physical update | session, variance, movement, balance, posting event and ITEM/CONTAINER update share one transaction |
| Race on movement effect | deterministic movement business key and unique constraint defense |
| Error leakage | stable business codes; no token, secret or stack trace in API response |

Relevant sources:

- `Modules/Warehouse/app/Http/Controllers/StocktakeController.php`
- `Modules/Warehouse/app/Services/StocktakeService.php`
- `Modules/Warehouse/app/Services/StocktakeAdjustmentService.php`
- `Modules/Warehouse/app/Services/StocktakeSessionGuard.php`
- `Modules/Warehouse/app/Services/StocktakeGrainService.php`
- `Modules/Warehouse/database/migrations/2026_08_03_000100_harden_stocktake_three_layer_contract.php`
- `database/migrations/2026_08_03_000200_allow_completed_idempotency_lease_release.php`

## 4. API and RBAC contract

| Method | Endpoint | Permission |
|---|---|---|
| GET | `/api/v1/stocktake-sessions` | `inventory.view` |
| POST | `/api/v1/stocktake-sessions` | `stocktake.adjust` |
| GET | `/api/v1/stocktake-sessions/{id}` | `inventory.view` |
| POST | `/api/v1/stocktake-sessions/{id}/start` | `stocktake.adjust` |
| POST | `/api/v1/stocktake-sessions/{id}/counts` | `stocktake.adjust` |
| POST | `/api/v1/stocktake-sessions/{id}/review` | `stocktake.adjust` |
| POST | `/api/v1/stocktake-sessions/{id}/acknowledge-movements` | `stocktake.adjust` |
| GET | `/api/v1/stocktake-sessions/{id}/variances` | `inventory.view` |
| POST | `/api/v1/stocktake-sessions/{id}/apply-adjustment` | `stocktake.adjust` |

Apply input is backend-governed: `session_version`, exact `variance_ids`,
`reason`, and `Idempotency-Key`. FE must not send authoritative
`expected_qty`, `variance_qty` or `inventory_effect_qty`.

Mandatory negative responses include `WAREHOUSE_SCOPE_DENIED`,
`PRECONDITION_REQUIRED`, `SESSION_VERSION_CONFLICT`, `IDEMPOTENCY_ERROR`,
`IDEMPOTENCY_IN_PROGRESS`, `INVALID_VARIANCE_SELECTION`,
`MOVEMENT_ACKNOWLEDGEMENT_REQUIRED`, `LEDGER_BALANCE_MISMATCH`,
`STALE_STOCKTAKE_BASELINE`, `BALANCE_VERSION_CONFLICT` and full rollback on an
invalid ITEM/CONTAINER grain.

## 5. Exact-SHA evidence gate

The workflow was rebuilt as valid GitHub Actions YAML. It:

1. checks out the exact commit;
2. uses locked Composer/npm dependencies;
3. generates and masks per-run password/JWT credentials;
4. refuses a non-testing or non-dedicated SQLite database;
5. runs PHP syntax, Pint, script syntax and static evidence-pipeline checks;
6. rehearses migration and seed;
7. runs focused stocktake suites and full PHPUnit;
8. generates/lints OpenAPI;
9. runs Newman against a fresh runtime database;
10. captures DB before/after and reconciliation;
11. redacts credentials before checksums;
12. verifies `SHA256SUMS`, manifest digest and structured PASS reports;
13. uploads only `stocktake-evidence-<GITHUB_SHA>` on success;
14. uploads redacted diagnostics on failure.

A successful artifact must contain `build-metadata.json`,
`source-tree.sha256`, `SHA256SUMS`, `SHA256SUMS.sha256`, OpenAPI JSON/YAML,
runtime routes, Postman collection/environment, test/source registers, focused
and full raw PHPUnit logs, migration/seed raw logs, Newman raw/JSON, schema
verification, DB before/after, reconciliation, credential hygiene, decision
record, task document and Jira handoff.

`CI_VERIFIED` is invalid unless `build-metadata.commit_sha`, artifact name,
source-tree SHA-256 and both checksum files all bind the same exact commit.

## 6. P0/P1/P2 disposition after re-review

No direct repository P0 was reported. All P1/P2 items are represented below
without fabricating another role's result.

| ID | Repository remediation | Evidence path | Remaining owner gate |
|---|---|---|---|
| `STK-P1-01` | Decision options, consequence, exact-SHA payload and formal-decision template are present | `DEC-STK-001.md`, this document, review protocol | BA + Tech Lead + PM comment exact version/hash/date/result on `WWHN-25` |
| `STK-P1-02` | Automated idempotency, rollback, reconcile, duplicate no-write and scope tests; explicit two-worker/timeout matrix | focused tests, test-case register, raw logs in artifact | QA1 + Tech Lead independently execute race/retry/timeout on the same SHA |
| `STK-P1-03` | Migration/schema verifier and security negative matrix are packaged | migration log, schema report, review protocol | DBA target PostgreSQL rehearsal; Security staging retest |
| `STK-P1-04` | Correct WWHN-131 document/source register, exact-SHA artifact name, checksums and raw-log contract | workflow and success artifact | GitHub Actions must actually start and PASS after organization billing is restored |
| `STK-P1-05` | GO/CONDITIONAL GO/NO-GO payload is defined | review protocol section 8 | BA records result on `WWHN-132`; PM confirms |
| `STK-P1-06` | Current external-source slots and impacted tickets are explicit | Source Register | BAS supplies BRD/SRS/BPD/DEC-INV attachment IDs, versions, SHA-256 and disposition on `WWHN-133` |
| `STK-P2-01` | Final closure matrix uses exact artifact/result/reviewer/blocker columns | section 8 below and generated Jira handoff | Each reviewer posts its own evidence |

## 7. Current verified blockers

Observed on `2026-08-06`:

- `WWHN-25` remains `In Progress`;
- `WWHN-132` remains `In Progress`;
- `WWHN-133` remains `To Do`;
- WWHN-131 attachment IDs `11565` and `11564` have no recorded SHA-256;
- the current WWHN-131 workflow run `31077768118` has no jobs because the
  previous workflow revision was not executable;
- other exact-SHA checks were not started by GitHub because the organization
  account has failed payments or insufficient Actions spending limit.

The GitHub billing/spending condition is an infrastructure blocker owned by the
organization administrator/finance owner. Code cannot create a real Actions
artifact while GitHub refuses to start jobs. A rerun or a new push after billing
restoration is mandatory; an old attachment or local self-written log cannot
substitute for this evidence.

## 8. Final closure matrix

| Gate | Required immutable evidence | Current repository result | Independent reviewer/result | Blocker/disposition |
|---|---|---|---|---|
| G1 Contract/source | Decision, task doc, source register, OpenAPI/Postman hashes | `READY_FOR_CI` | BA/Tech/PM `PENDING` | formal result absent |
| G2 Runtime correctness | focused/full PHPUnit, Newman, DB before/after, reconciliation | workflow packaged | QA1/Tech Lead `PENDING` | exact-SHA execution absent |
| G3 Race/retry/timeout | two-worker timestamps, one effect, replay/no-write DB proof | protocol and automation prerequisites ready | QA1/Tech Lead `PENDING` | independent concurrent run required |
| G4 Migration | target PostgreSQL version, backup ID, row/checksum, locks, rollback | schema verifier packaged | DBA `PENDING` | target DB rehearsal required |
| G5 Security | cross-session/cross-warehouse/replay leak/no-write raw HTTP+DB | automated baseline packaged | Security/QA1 `PENDING` | staging retest required |
| G6 Traceability | BRD/SRS/BPD/DEC-INV attachment ID/version/SHA-256 | nullable slots explicit | BAS `PENDING` | WWHN-133 incomplete |
| G7 CI artifact | run URL, `stocktake-evidence-<sha>`, artifact ID, manifests/digests | workflow corrected | repository CI `BLOCKED` | GitHub billing/spending limit |
| G8 Decision | exact version/hash/date, GO/CONDITIONAL GO/NO-GO, conditions/owner/deadline | decision payload ready | BA/PM/Tech `PENDING` | WWHN-25/WWHN-132 not finalized |

Final Jira closure comment must include: commit SHA, run ID/URL, artifact ID/name,
artifact digest, source-tree hash, `SHA256SUMS.sha256`, each reviewer/date/result,
open P0/P1 count, blocker owner and disposition. Blob-only attachments without
these fields are superseded and must not be used for closure.

## 9. Completion rule

- `REPOSITORY_IMPLEMENTED`: one final commit on `m1` contains all repository-side remediation.
- `CI_VERIFIED`: exact-SHA workflow starts and every manifest/test/schema/Newman/reconciliation/hygiene gate passes.
- `INDEPENDENTLY_VERIFIED`: QA1, Tech Lead, DBA and Security bind their results to the same SHA/digest.
- `TRACEABILITY_VERIFIED`: BAS completes current source IDs/hashes.
- `APPROVED`: BA/PM/Tech Lead issue formal GO/effective date and open P0/P1 is zero.

Automation cannot elevate above `CI_VERIFIED`; BE1 cannot sign for another role.

**Current truthful state before the final commit: repository remediation prepared;
formal decision and independent gates remain pending.**
