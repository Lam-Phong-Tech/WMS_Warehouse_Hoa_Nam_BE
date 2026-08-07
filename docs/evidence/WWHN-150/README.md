# WWHN-150 Evidence Pack

Thư mục này là index source-controlled cho evidence WWHN-150. Raw logs và DB snapshots không được commit dưới dạng file tĩnh vì chúng phải được sinh lại từ exact commit SHA.

Nguồn phát hành duy nhất:

- workflow: `.github/workflows/auth-rbac-audit-contract.yml`;
- artifact: `wwhn-150-evidence-<exact_commit_sha>`;
- exact SHA/run URL/hash: `build-metadata.json`;
- integrity: GitHub artifact digest, `SHA256SUMS`, `SHA256SUMS.sha256`;
- governance: `PENDING_INDEPENDENT_SIGN_OFF` cho đến khi QA1/BE2/FE1/BA ghi Jira comment.

## Why this closes the previous evidence gap

Pipeline cũ gộp WWHN-150 với một verifier Postman tổng quát; một assertion reconciliation không thuộc scan làm dừng toàn bộ collector dù 154 tests đã PASS. Pipeline mới chỉ chạy gate thuộc WWHN-150, nhưng vẫn đưa race/rollback/reconciliation và boundary tests vào cùng exact-SHA artifact.

## Reviewer entry points

- Contract: `docs/api-contract/WWHN-150-scan-openapi.yaml`
- Postman: `docs/postman/WWHN-150 Scan ReadOnly Contract.postman_collection.json`
- Test: `tests/Feature/ScanClassifyReadOnlyContractTest.php`
- Service: `Modules/Warehouse/app/Services/ScanClassificationService.php`
- Closure matrix: `CLOSURE_MATRIX.md`
- Full handoff: `docs/docs_task_jira/WWHN-150.md`
