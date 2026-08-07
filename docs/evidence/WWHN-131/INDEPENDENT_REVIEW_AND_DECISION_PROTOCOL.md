# WWHN-131 — Independent review and DEC-STK-001 decision protocol

> Version `1.1.0` — **PROPOSED**. This is an execution protocol, not a sign-off.

## 1. Immutable review input

Every reviewer must use the same successful artifact:

- repository `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`, branch `m1`;
- exact SHA from `build-metadata.json.commit_sha`;
- artifact `stocktake-evidence-<exact_sha>`;
- source-tree hash `source-tree.sha256`;
- `SHA256SUMS` and `SHA256SUMS.sha256` both verified;
- `credential-hygiene.json.status=PASS` with zero residual runtime secret/JWT;
- environment/deployment SHA and reviewer execution date.

Reviewing a moving branch, a different SHA, an unverified artifact or a redaction-failed diagnostics package is invalid.

## 2. Role separation

| Role | Owns | Must not self-confirm |
|---|---|---|
| BE1 | implementation, contract sources, workflow, artifact generation and handoff | FE1/QA/Tech/DBA/Security/BA/PM/BAS result |
| FE1 | endpoint/payload/error/RBAC mapping and integration acknowledgement | backend correctness or business decision |
| QA1 | API/DB negative, retry, rollback, race and reconciliation execution | architecture or DBA approval |
| Tech Lead | concurrency/idempotency/transaction/ledger review | PM/BA business decision |
| DBA | target PostgreSQL migration/backfill/constraint/lock rehearsal | Security/RBAC |
| Security | cross-session/cross-warehouse/replay-leak/no-write review | DBA schema result |
| BA | business rule, impacted sources and final decision draft | technical test execution |
| PM | GO / CONDITIONAL GO / NO-GO | test evidence generation |
| BAS | current BRD/SRS/BPD/DEC-INV IDs/hashes and impacted links | technical approval |

## 3. FE1 handoff gate

FE1 must bind its result to exact BE SHA, exact generated OpenAPI/Postman hashes and the FE integration SHA. Verify:

- canonical session lifecycle and status rendering;
- apply input excludes authoritative expected/effect fields;
- idempotency header behavior and replay response;
- movement warning/acknowledgement state;
- stale version, invalid variance, ledger mismatch and scope-denied errors;
- read/write action visibility for `inventory.view` and `stocktake.adjust`.

Result format: reviewer, date, BE SHA, FE SHA, OpenAPI/Postman hashes, `PASS|FAIL|CONDITIONAL`, mismatches/defect IDs.

## 4. QA1 and Tech Lead matrix

Run focused suites, full PHPUnit, Newman, contract verifier and reconciliation. Independently execute:

1. same-key/same-payload retry after simulated lost response — replay only;
2. same-key/different-payload — `409 IDEMPOTENCY_ERROR`, no write;
3. different key after successful apply — no second effect;
4. two-worker race on same session/version — one business effect only;
5. pending/incomplete event — `409 IDEMPOTENCY_IN_PROGRESS`;
6. foreign/missing/partial/duplicate variance IDs — `422`, no write;
7. missing or mismatched movement acknowledgement — blocked, no write;
8. invalid ITEM/CONTAINER after review — full transaction rollback;
9. signed-ledger mismatch — `409`, no write;
10. zero effect — no movement and no balance version increment;
11. canonical flow reconciliation — PASS with zero mismatch;
12. cross-scope replay — `403` before response-snapshot disclosure.

Attach raw requests/responses, worker timestamps, DB before/after rows and exact SHA/digest. Do not replace race evidence with sequential requests.

## 5. DBA target database rehearsal

SQLite CI proves repository consistency only. DBA must use the target PostgreSQL version and a controlled data copy:

- backup/snapshot ID, row counts/checksums and rollback reference;
- detect NULL/duplicate warehouse, grain and posting-event data before migration;
- use only BA-approved backfill mapping;
- run migrations and `verify-wwhn131-schema.php`;
- verify NOT NULL, unique, indexes and FKs;
- run canonical stocktake lifecycle;
- record duration, locks/blocking queries, row counts/checksums after migration;
- return `PASS|FAIL|CONDITIONAL` with exact SHA and migration hash.

## 6. Security negative matrix

Use scoped and non-scoped users to verify:

- cross-warehouse create/detail/variance/review/ack/apply returns `403` with no write/data leak;
- committed-response replay is not disclosed outside warehouse scope;
- cross-session variance injection returns `422`;
- missing `stocktake.adjust` returns `403` on every write lifecycle endpoint;
- legacy alias lists only in-scope sessions;
- business errors contain no stack trace, token or secret.

Attach redacted raw HTTP and DB no-write evidence on the exact environment SHA.

## 7. BAS traceability gate

On `WWHN-133`, BAS must record current authoritative BRD, SRS, BPD and DEC-INV attachment IDs, versions/dates, SHA-256, impacted rules/tickets and reviewer/date. Existing WWHN-131 attachment IDs `11565` and `11564` are not immutable evidence until hashes are recorded or they are superseded by the exact-SHA artifact.

## 8. Formal DEC-STK-001 payload

```text
[DEC-STK-001 — STOCKTAKE MODEL A DECISION]
Jira: WWHN-25
BE evidence: WWHN-131
BA final: WWHN-132
Traceability: WWHN-133
Decision document: docs/decision-records/DEC-STK-001.md v1.0.0 / PROPOSED
Task document: docs/docs_task_jira/WWHN-131.md v1.3.0 / PROPOSED
Source Register: docs/docs_task_jira/WWHN-131-source-register.json v1.3.0 / PROPOSED
Exact BE SHA: <sha>
CI run/artifact: <url/name>
Source-tree SHA-256: <hash>
SHA256SUMS digest: <hash>
Credential hygiene: PASS / <report hash>
Deployment environment/SHA: <value>

FE1: <name/date/result/BE+FE SHA/evidence>
QA1: <name/date/result/evidence>
Tech Lead: <name/date/result/evidence>
DBA: <name/date/result/evidence>
Security: <name/date/result/evidence>
BA: <name/date/contract result>
PM: <name/date/GO|CONDITIONAL GO|NO-GO>
BAS: <name/date/source-hash result>

Open defects/conditions: <IDs or NONE>
Decision: GO | CONDITIONAL GO | NO-GO
Effective date: <ISO-8601 +07:00 or NULL>
Conditions/owner/deadline: <exact list or NONE>
```

A `CONDITIONAL GO` without named conditions, owner and deadline is invalid. A `GO` is invalid while any P0/P1 lacks disposition or evidence references a different SHA/digest.

## 9. Closure states

| State | Evidence |
|---|---|
| `REPOSITORY_IMPLEMENTED` | one final commit on `m1` contains decision, docs, workflow, redactor and verifier |
| `CI_VERIFIED` | exact-SHA workflow and every manifest/contract/schema/Newman/PHPUnit/reconciliation/hygiene gate PASS |
| `INDEPENDENTLY_VERIFIED` | FE1, QA1, Tech Lead, DBA and Security results bind the same SHA/digest |
| `TRACEABILITY_VERIFIED` | BAS current source IDs/hashes and impacted links complete |
| `CONDITIONAL GO` | BA/PM decision includes exact conditions, owner and deadline |
| `APPROVED` | formal GO, effective date, no open P0/P1 and all evidence complete |
| `NO-GO` | a mandatory gate fails or evidence SHA/digest mismatches |

Automation cannot elevate status above `CI_VERIFIED`; BE1 cannot elevate another reviewer’s result.
