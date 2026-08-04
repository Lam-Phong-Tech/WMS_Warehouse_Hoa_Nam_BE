# WWHN-131 — BE1 draft DEC-STK-001 and stocktake evidence handoff

## 1. Document control

| Field | Value |
|---|---|
| Authoritative BE task | `WWHN-131` |
| Parent | `WWHN-25 — DEC-STK-001` |
| Parent Temp Key | `HN-DEC-003` |
| Role / assignee | `BE1 / Quang Nguyễn Thanh` |
| Assignee Placeholder | `HN-BE-01` |
| Repository / branch | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE / m1` |
| Document version | `1.3.0` |
| Revision | `2026-08-04` |
| Status | **PROPOSED** |
| Decision output | `docs/decision-records/DEC-STK-001.md v1.0.0 / PROPOSED` |
| Source Register | `docs/docs_task_jira/WWHN-131-source-register.json v1.3.0 / PROPOSED` |
| Workflow | `.github/workflows/wwhn-131-stocktake-contract.yml` |
| Artifact | `stocktake-evidence-<exact_commit_sha>` |

`WWHN-130` is a BAS subtask of `WWHN-24 / DEC-INV-001`; it is not this BE1 task. The requested `WWHN-130.md` is retained only as a non-authoritative traceability/execution log. No parent, role or output is silently repurposed.

## 2. Output and proposed choice

The required draft Decision Record is delivered at `docs/decision-records/DEC-STK-001.md`. It compares:

1. **Model A — snapshot + signed net movement through count cutoff**: proposed.
2. Warehouse freeze for the full stocktake window: rejected for MVP operational impact.
3. Live balance at review/apply: rejected because it is non-deterministic and weak for audit.

Model A keeps normal warehouse posting available while making variance reproducible:

```text
expected_at_count = snapshot_qty + SUM(stock_movements.qty_delta from stocktake_started_at through count_cutoff_at)
variance_qty      = actual_qty - expected_at_count
```

`qty_delta` is signed: receipt/adjust-in positive; issue/adjust-out negative.

## 3. P0 correctness closure in repository

| Control | Enforcement |
|---|---|
| State and concurrency | `DRAFT -> IN_PROGRESS -> REVIEWED -> APPLIED`; session row lock + `session_version` CAS; balance row lock + version CAS |
| Scope | Warehouse data scope is checked on list/create/detail/count/review/ack/variance/apply and before idempotency replay lookup |
| Exact variance set | Apply accepts the full approved set for the same session; foreign/missing/partial/duplicate IDs return `422 INVALID_VARIANCE_SELECTION` with no write |
| Idempotency | Unique `(operation,idempotency_key)`, canonical SHA-256 request hash, committed response snapshot; same key/different payload returns `409 IDEMPOTENCY_ERROR` |
| Movement acknowledgement | Detected IDs and acknowledged IDs remain distinct; exact match plus actor/time is required before apply |
| Ledger | Canonical quantity is `SUM(stock_movements.qty_delta)`; pre-apply ledger must equal balance or return `409 LEDGER_BALANCE_MISMATCH` |
| Zero effect | No zero-delta movement and no balance-version increment |
| Three-layer atomicity | Session, variance, balance, movement, posting event and ITEM/CONTAINER physical layer commit or roll back together |
| Error hygiene | Stable codes; no secret or stack trace in business response |

Core implementation and regression suites already cover the controls above. This change set closes the evidence/governance gaps rather than introducing a second stocktake implementation.

## 4. API/RBAC contract for FE1 and QA

| Method | Endpoint | Permission |
|---|---|---|
| GET/POST | `/api/v1/stocktake-sessions` | `inventory.view` / `stocktake.adjust` |
| GET | `/api/v1/stocktake-sessions/{id}` | `inventory.view` |
| POST | `/api/v1/stocktake-sessions/{id}/start` | `stocktake.adjust` |
| POST | `/api/v1/stocktake-sessions/{id}/counts` | `stocktake.adjust` |
| POST | `/api/v1/stocktake-sessions/{id}/review` | `stocktake.adjust` |
| POST | `/api/v1/stocktake-sessions/{id}/acknowledge-movements` | `stocktake.adjust` |
| GET | `/api/v1/stocktake-sessions/{id}/variances` | `inventory.view` |
| POST | `/api/v1/stocktake-sessions/{id}/apply-adjustment` | `stocktake.adjust` |

Apply input is backend-governed: `session_version`, exact `variance_ids`, `reason`, and `Idempotency-Key`. FE must not send authoritative `expected_qty` or `inventory_effect_qty`.

Negative contract includes `403 WAREHOUSE_SCOPE_DENIED`, `428 PRECONDITION_REQUIRED`, `409 SESSION_VERSION_CONFLICT`, `409 IDEMPOTENCY_ERROR`, `409 IDEMPOTENCY_IN_PROGRESS`, `422 INVALID_VARIANCE_SELECTION`, `428 MOVEMENT_ACKNOWLEDGEMENT_REQUIRED`, `409 LEDGER_BALANCE_MISMATCH`, `409 STALE_STOCKTAKE_BASELINE`, and full rollback on invalid ITEM/CONTAINER grain.

## 5. P1/P2 remediation from the 76/100 assessment

| Finding | Repository disposition | Remaining independent gate |
|---|---|---|
| `STK-P1-01` formal decision | Dedicated `DEC-STK-001 v1.0.0` with options, consequences, impacted tickets, exact-SHA decision payload and role separation | BA + Tech Lead + PM record version/hash/date/result on `WWHN-25` |
| `STK-P1-02` QA/Tech review | Focused/full PHPUnit, Newman, DB before/after, reconciliation, race/retry/rollback matrix and raw-log artifact contract | QA1 + Tech Lead execute independently on same SHA/digest |
| `STK-P1-03` DBA/Security | Schema verifier, migration rehearsal, cross-session/cross-warehouse/replay-leak no-write protocol | DBA target PostgreSQL/data-copy rehearsal; Security staging retest |
| `STK-P1-04` artifact/hash | Exact-SHA workflow, source-tree hash, `SHA256SUMS`, digest, pipeline verifier, success/failure artifacts | Final commit workflow must PASS and artifact URL/digest must be posted |
| `STK-P1-05` BA final | GO / CONDITIONAL GO / NO-GO payload; conditional requires condition, owner and deadline | BA on `WWHN-132`; PM confirmation |
| `STK-P1-06` traceability | Source Register records repo sources, Jira facts, impacted tickets and nullable external-source slots | BAS adds current BRD/SRS/BPD/DEC-INV attachment IDs and SHA-256 on `WWHN-133` |
| `STK-P2-01` handoff | Collector generates `jira-handoff-comment.txt`; live structured Jira comments are posted after commit | Reviewers reply with their own evidence; BE1 does not impersonate them |

## 6. Evidence and credential safety

The final workflow generates a random password and JWT secret per run, masks both, exports them only as runtime environment values, and uses `CI_DEMO_PASSWORD` for seeded users. The collector passes the password to Newman only through a runtime variable.

Before checksums or upload, `scripts/redact-wwhn131-evidence.mjs` removes the runtime password, JWTs and Bearer tokens and writes `credential-hygiene.json`. CI fails if any residual secret/JWT remains. If collection fails, the EXIT trap redacts diagnostics; if redaction itself cannot be verified, diagnostics are discarded and replaced by a safe failure notice.

Static gate: `scripts/verify-wwhn131-evidence-pipeline.mjs` validates the workflow, collector, redactor, Source Register, decision sections, task-key correction and no false approval claim.

## 7. Evidence package

Required successful artifact outputs include:

- `build-metadata.json`, `source-tree.sha256`, `SHA256SUMS`, `SHA256SUMS.sha256`;
- OpenAPI JSON/YAML and runtime routes;
- WWHN-131 Postman collection/environment and Newman raw/JSON;
- focused/full PHPUnit raw logs;
- schema verification and migration/seed rehearsal logs;
- DB before/after and reconciliation report;
- contract verifier and evidence-pipeline verifier results;
- `credential-hygiene.json`;
- Decision Record, this task document, Source Register, independent review protocol and source copies;
- `jira-handoff-comment.txt` and `ci-summary.md`.

## 8. Owner, target and impact register

BE1 must not invent another role’s calendar ETA. The enforceable target is the next formal gate; each owner must post its actual date/result.

| Blocker | Owner | Required target | Impact |
|---|---|---|---|
| Final exact-SHA workflow/artifact | BE1 / repository CI | Immediately after the single final commit; only that run is valid | `STK-P1-04` remains open without immutable evidence |
| FE mapping acknowledgement | FE1 | Before formal decision; exact OpenAPI/Postman SHA | FE may integrate stale payload/error/RBAC mapping |
| Race/retry/rollback/reconcile review | QA1 + Tech Lead | Before formal decision; exact execution date required | Core correctness not independently verified |
| Target PostgreSQL migration rehearsal | DBA | Before deployment/decision; exact rehearsal date required | Schema/backfill/locking risk remains unknown |
| Cross-scope/replay security retest | Security + QA1 | Before formal decision; exact execution date required | Data-leak/no-write gate remains unverified |
| BRD/SRS/BPD/DEC-INV IDs/hashes | BAS | Before `WWHN-133` closure; exact update date required | Source currency and impacted links incomplete |
| Formal decision | BA + Tech Lead + PM | After all mandatory evidence; date/result required | `DEC-STK-001` remains PROPOSED and dependent tickets remain gated |

## 9. Jira facts and handoff

Observed on `2026-08-04`: `WWHN-25`, `WWHN-131`, `WWHN-132` are In Progress; `WWHN-133` is To Do. Known WWHN-131 attachment IDs `11565` and `11564` have no recorded SHA-256 and cannot substitute the exact-SHA artifact.

The Atlassian connector is available. BE1 may post implementation/evidence links but must not post a reviewer result on behalf of FE1, QA1, Tech Lead, DBA, Security, BA, PM or BAS.

## 10. Completion rule

Repository-side remediation is complete for handoff only after the single commit is on `m1`. `CI_VERIFIED` requires a successful workflow on that exact commit. `APPROVED`, `GO`, Jira Done or formal `100%` additionally require all independent results, current source hashes and the formal Jira decision on the same SHA/digest.

**Current truthful status: REPOSITORY REMEDIATION READY FOR FINAL COMMIT; DECISION REMAINS PROPOSED.**
