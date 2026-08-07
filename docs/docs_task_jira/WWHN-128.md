# WWHN-128 — [BE1] DEC-INV-001 Technical/Business Draft and Proposal

## Release identity

| Field | Value |
|---|---|
| Decision | `DEC-INV-001` — nguồn dữ liệu chuẩn của tồn kho |
| Document revision | `1.8-R8` |
| Canonical contract version | `1.7.0` |
| Repository / branch | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` / `m1` |
| Architecture baseline | `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` |
| Runtime exact SHA | `build-metadata.json.commit_sha` from the successful workflow |
| Runtime artifact | `wwhn-128-evidence-<commit_sha>` |
| Formal state | `NOT APPROVED / NOT BASELINED / NOT EFFECTIVE` until G1 signatures complete |

`1.8-R8` is the WWHN-128 evidence/document revision. `1.7.0` is the canonical API contract version. Markdown, source register, closure register, downstream gate, signoff ledger, QA2 matrix, verifier and workflow use both values consistently.

## Decision invariants

1. `stock_movements` is the append-only signed ledger and audit/source record.
2. `inventory_balances` is the transactional current-state projection.
3. Stock changes only through `POST_RECEIPT`, `POST_ISSUE`, or approved `APPLY_ADJUSTMENT`; scan/classify and draft/approve have `stock_effect=NONE`.
4. Every stock-changing command uses a DB transaction, row lock, idempotency guard and atomic movement/balance/event/idempotency completion.
5. Same key + same semantic payload replays the persisted result; same key + different payload fails; another key cannot post an already-posted document.
6. RBAC and warehouse/data scope are enforced by backend. FE hidden/disabled actions are not authorization evidence.
7. Reconciliation is by warehouse, product and `stock_status`: projection balance equals the signed movement ledger.
8. API responses and artifacts must not expose secrets or stack traces.

## Canonical contract source

| Artifact | Path | Version |
|---|---|---|
| OpenAPI JSON | `docs/api-contract/WWHN-180-posting-openapi.json` | `1.7.0` |
| OpenAPI YAML | `docs/api-contract/WWHN-180-posting-openapi.yaml` | `1.7.0` |
| Canonical Postman | `docs/postman/WWHN-180 Canonical Contract.postman_collection.json` | `1.7.0` |
| Canonical environment | `docs/postman/WWHN-180 Canonical Contract.postman_environment.json` | `1.7.0` |
| Executable CI Postman | `docs/postman/WWHN-180 Posting Contract.postman_collection.json` | CI harness |
| Test matrix | `docs/testing/WWHN-180-posting-test-cases.json` | `1.7.0` |
| Permission catalog | `docs/evidence/WWHN-180/permission-catalog.json` | `1.7.0` |
| BE↔FE mapping | `docs/evidence/WWHN-180/BE_FE_PERMISSION_MAPPING.json` | `1.7.0` |

The exact-SHA workflow copies these files into one downloadable artifact and generates `artifact-manifest.json`, `SHA256SUMS`, and `SHA256SUMS.sha256`. A branch URL or a Jira Markdown attachment alone is not an immutable review target.

## Current FE binding

- FE repository/branch: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE` / `m1`
- Observed FE commit: `2b88d55922c7fc4269918ef5e35beea86297b49a`
- Baseline: `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json`
- Baseline Git blob: `e2e760c2e6fb22646a4bb314b564ce8c9fc956dc`
- Review issue: `WWHN-181`

The previous BE record used FE `f6e137...` and baseline blob `656b...`; that binding is superseded because FE `m1` advanced. FE1 must review the current binding or explicitly record a newer exact binding.

## P0/P1/P2 closure map

| ID | Sev | R8 repository fix | Formal evidence still required |
|---|---:|---|---|
| BE1-P0-01 | P0 | `G1_SIGNOFF_LEDGER.json` binds WWHN-181/182/183/40 to one BE SHA, FE SHA, artifact ID/name/digest and checksums. | FE1, QA2 and BA role-owned results; WWHN-40 derived decision. |
| BE1-P0-02 | P0 | `QA2_EXECUTION_MATRIX.json`; workflow produces SQLite/Newman and PostgreSQL idempotency/race/DB/reconciliation evidence. | QA2 raw evidence and `PASS/FAIL/CONDITIONAL` in WWHN-182. |
| BE1-P1-01 | P1 | One artifact contains OpenAPI, Postman, permissions, tests, logs, DB snapshots, manifest and dual checksums. | Successful run with artifact ID/name/digest. |
| BE1-P1-02 | P1 | Exact-head workflow emits raw PHPUnit/Newman/PostgreSQL logs, JUnit, DB evidence and source-tree digest. | Successful exact-head run; billing/pre-runner blocker resolved if it recurs. |
| BE1-P1-03 | P1 | Permission sources and current FE binding are packaged. | FE1 and BA sign the same binding. |
| BE1-P1-04 | P1 | QA matrix covers happy/negative/RBAC/idempotency/double-post/race/rollback/reconcile. | WWHN-182 independent result. |
| BE1-P1-05 | P1 | Strict G1 minutes template and GO formulas are versioned. | WWHN-183 minutes and WWHN-40 result. |
| BE1-P2-01 | P2 | Revision `1.8-R8` and contract `1.7.0` are synchronized and CI-enforced. | Static/version verifier PASS. |

## Exact-SHA evidence workflow

Workflow: `.github/workflows/wwhn-128-inventory-decision-contract.yml`

A successful artifact contains:

- `decision/`: focused WWHN-128 PHPUnit raw log, JUnit, DB before/after, schema and reconciliation.
- `runtime/`: Newman/API evidence, OpenAPI lint/parity, route/permission mapping, DB delta and credential hygiene.
- `postgresql/`: PostgreSQL migration log and idempotency/race/reconciliation PHPUnit/JUnit.
- `contract/`: direct canonical OpenAPI/Postman/test/permission/mapping files.
- `governance/`: R8 source register, closure register, downstream gate, signoff ledger, QA matrix, protocol and minutes template.
- root: build metadata, source-tree digest, artifact manifest and dual checksums.

Technical PASS proves automated execution and artifact integrity only. It does not assign QA2 PASS or BA GO.

## QA2 mandatory execution

QA2 uses `docs/evidence/WWHN-128/QA2_EXECUTION_MATRIX.json` and records in WWHN-182:

- exact BE/FE SHA, run/attempt, artifact ID/name/digest;
- environment, DB engine, timestamp and reviewer account;
- raw happy/negative/RBAC/data-scope request/response and DB evidence;
- retry, same-key-different-payload, different-key double-post and concurrent races;
- rollback/no-write, DB before/after, reconciliation and checksum verification;
- final `PASS`, `FAIL`, or `CONDITIONAL`.

Automation may prepare evidence, but QA2 must independently inspect and sign it.

## G1 closure protocol

- FE1/WWHN-181: `PASS / FAIL / CONDITIONAL`.
- QA2/WWHN-182: `PASS / FAIL / CONDITIONAL`.
- BA/WWHN-183: `GO / CONDITIONAL_GO / NO_GO`.
- WWHN-40: derived final gate.

`GO` requires FE1 PASS, QA2 PASS, BA GO, integrity PASS and open P0/P1 = 0. `CONDITIONAL_GO` is invalid while a P0 remains or a P1 lacks owner, due date and objective evidence. Missing signatures keep the gate closed.

## CI history and truthful status

- Last retained passing R5 package: commit `f3b225ec...`, run `30876196296`, artifact ID `8879619822`, digest `sha256:a5d37ec0...`; scope was focused SQLite evidence.
- R6 run `31082380025`, attempt 2/job `92570314627`, was blocked before runner allocation by GitHub billing/spending limit and produced no artifact. It is not a code/test failure and is not PASS evidence.
- R8 requires a new successful run on the single final commit. If GitHub blocks before runner again, repository controls are present but BE1-P1-01/P1-02 and downstream signatures remain open.

## Definition of Done

### Repository/BE1 portion

- [x] Architecture-aligned decision invariants.
- [x] Direct canonical OpenAPI/Postman/test/permission sources.
- [x] Current FE binding.
- [x] QA2 matrix and G1 ledger/minutes.
- [x] Exact-SHA artifact workflow with SQLite/Newman/PostgreSQL evidence and dual checksums.
- [x] Fail-closed verifier and version invariants.
- [x] One direct commit on `m1`.

### External/formal portion

- [ ] Successful exact-SHA R8 workflow and artifact metadata.
- [ ] WWHN-181 FE1 signature.
- [ ] WWHN-182 QA2 raw evidence and result.
- [ ] WWHN-183 BA minutes.
- [ ] WWHN-40 final derived result.
- [ ] WWHN-24 formal decision when required by parent governance.

Until the external items complete, the truthful state is `TECHNICAL PACKAGE READY / FORMAL G1 PENDING`, not 100% approved.
