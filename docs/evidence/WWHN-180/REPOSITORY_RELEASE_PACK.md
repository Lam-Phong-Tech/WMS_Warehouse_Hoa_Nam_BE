# WWHN-180 — Repository Release Pack

## Authority

- Repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
- Branch: `m1`
- Contract version: `1.7.0`
- Jira: `WWHN-180`; reviewers: `WWHN-181` FE1, `WWHN-182` QA2, `WWHN-183` BA; parent gate: `WWHN-40`
- Release parent: `04026757d679446b54e1e0c54ea18c3c0a0423ed`
- FE baseline: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE@m1`, exact SHA `f6e137d01ed7afec5d30e8fecde6a2cc31bb7c8b`

The release commit must be the sole direct child of the release parent and its changed path set must equal `IMMUTABLE_HANDOFF_MANIFEST.json.changed_paths`.

## Direct artifacts

- Focused OpenAPI: `docs/api-contract/WWHN-180-posting-openapi.json` and `.yaml`
- Canonical Postman: `docs/postman/WWHN-180 Canonical Contract.postman_collection.json`
- Canonical environment: `docs/postman/WWHN-180 Canonical Contract.postman_environment.json`
- Test matrix: `docs/testing/WWHN-180-posting-test-cases.json`
- Permission mapping: `docs/evidence/WWHN-180/BE_FE_PERMISSION_MAPPING.json`
- Permission catalog: `docs/evidence/WWHN-180/permission-catalog.json`
- Source register: `docs/docs_task_jira/WWHN-180-source-register.json`
- Immutable manifest: `docs/evidence/WWHN-180/IMMUTABLE_HANDOFF_MANIFEST.json`
- Repository checksums: `docs/evidence/WWHN-180/REPOSITORY_SHA256SUMS`

## Stock-effect race control

`StockDocumentService` reloads and locks the current `stock_documents` row before status validation or any ledger call for receipt, issue and reverse. The route-bound model is not trusted as current state.

`StockDocumentConcurrencyGuardTest` includes a stale-model probe with a mocked `StockLedger`. The test fails if `inbound()` is called after another request has already moved the document to `received`. It also verifies no movement, no balance and no posting event are created.

Stocktake adjustment already locks scoped session, posting event, approved variances and inventory balances, checks session/balance versions, signed-ledger reconciliation and unique movement business keys in one transaction.

## Repository verification

Run from repository root:

```bash
sha256sum -c docs/evidence/WWHN-180/REPOSITORY_SHA256SUMS
mkdir -p build/evidence/api-data
node scripts/verify-wwhn180-canonical-handoff.mjs \
  "docs/postman/WWHN-180 Canonical Contract.postman_collection.json" \
  "docs/postman/WWHN-180 Canonical Contract.postman_environment.json" \
  docs/api-contract/WWHN-180-posting-openapi.json \
  docs/docs_task_jira/WWHN-180-source-register.json \
  build/evidence/api-data/canonical-handoff-verification.json
node scripts/verify-wwhn180-immutable-handoff.mjs \
  docs/evidence/WWHN-180/IMMUTABLE_HANDOFF_MANIFEST.json \
  docs/docs_task_jira/WWHN-180-source-register.json \
  build/evidence/api-data/immutable-handoff-verification.json
php artisan test --filter=StockDocumentConcurrencyGuardTest
php artisan test --filter=Wwhn180IdempotencyAtomicityTest
php artisan test --filter=Wwhn180PermissionAliasContractTest
```

The GitHub Actions workflow must additionally produce raw PHPUnit/Newman/DB before-after/reconciliation logs, `SHA256SUMS`, `SHA256SUMS.sha256`, artifact ID/name and `sha256:<artifact-digest>` for the exact release commit.

## Audit disposition

| ID | Repository disposition | Independent/formal gate |
|---|---|---|
| BE1-P0-01 | Contract, source/version/hash, FE baseline binding and one-commit release are fail-closed. | FE1, QA2 and BA sign the same BE SHA, FE SHA and artifact digest. |
| BE1-P0-02 | Receipt/issue/reverse lock current document before ledger; idempotency/race/reconcile tests are pinned. | QA2 executes the passing artifact on the target environment. |
| BE1-P1-01 | OpenAPI/Postman/test/mapping/checksum pack is directly retrievable from the exact commit. | Post final artifact identity and digest to Jira. |
| BE1-P1-02 | Workflow output contract requires raw PHPUnit/Newman/DB/reconcile/checksum evidence. | Organization billing/runner must allow the exact-head workflow to run. |
| BE1-P1-03 | Endpoint → BE permission → FE guard mapping is pinned to the FE baseline SHA/blob. | FE1 and BA record reviewer/result/date. |
| BE1-P1-04 | QA2 protocol and source-side regression are complete. | QA2 records `PASS/FAIL/CONDITIONAL` with raw evidence. |
| BE1-P1-05 | G1 inputs and minutes template are ready. | BA records `GO/CONDITIONAL_GO/NO_GO` on WWHN-183/WWHN-40. |
| BE1-P2-01 | Markdown/source register/canonical artifacts remain synchronized at contract version `1.7.0`. | Record final artifact digest after a successful run. |

## Fail-closed statement

Repository integrity PASS is not an approval. Do not mark `API contract approved`, `Done` or `100/100` until the exact-head workflow succeeds and FE1, QA2 and BA independently close their gates.
