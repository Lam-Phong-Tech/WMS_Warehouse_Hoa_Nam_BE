# WWHN-150 — BE1 Output Architecture, scan read-only contract và evidence handoff

| Thuộc tính | Giá trị |
|---|---|
| Branch triển khai | `m1` |
| Document version | `1.0.0` |
| Document status | **PROPOSED** |
| Implementation owner | BE1 |
| Independent reviewers | QA1, BE2, FE1, BA, Tech Lead/PM |
| Exact review commit | Lấy từ `wwhn-150-evidence-<SHA>/build-metadata.json` |
| Artifact hash source | `wwhn-150-evidence-<SHA>/SHA256SUMS` |
| Formal approval | **PENDING FORMAL JIRA APPROVAL** |

Tài liệu này không tự xác nhận `APPROVED`, `Done` hoặc `100%`. Repository đã triển khai các fix có thể thực hiện bởi BE1; các gate QA1/BE2/FE/BA/Tech Lead phải review độc lập trên **exact commit SHA** và ghi bằng chứng trên Jira.

## 1. Kết quả xử lý P0/P1/P2

| ID | Sev | Required fix | Thay đổi đã triển khai trên `m1` | Evidence repository/CI | Trạng thái trung thực |
|---|---|---|---|---|---|
| `BE1-P0-01` | P0 | Attach OpenAPI JSON/YAML, Postman collection/environment, changelog, raw logs; hash verify được | Thêm OpenAPI contract YAML riêng, Postman collection/environment thực thi, changelog, source register; CI generate full OpenAPI JSON/YAML, raw generation log, route list, PHPUnit/Newman logs, build metadata, source-tree hash và `SHA256SUMS` | `docs/api-contract/WWHN-150-scan-openapi.yaml`; `docs/postman/WWHN-150*`; `scripts/collect-api-data-evidence.sh`; workflow artifact `wwhn-150-evidence-<SHA>` | **REPOSITORY IMPLEMENTED**; Jira attachment/formal approval còn phải thực hiện bởi owner/reviewer |
| `BE1-P1-01` | P1 | QA1 verify scan không tạo movement/balance/posting_event/idempotency cache | Thay mock resolver bằng lookup dữ liệu thật; scan chỉ đọc; remove required idempotency contract; data-scope guard; response có write flags; feature tests fingerprint DB trước/sau và replay cùng key | `ScanClassificationService.php`; `ScanController.php`; `ScanClassifyReadOnlyContractTest.php`; `scan-readonly-comparison.json` | **REPOSITORY IMPLEMENTED**; QA1 independent sign-off pending |
| `BE1-P1-02` | P1 | Raw `php artisan test`, `route:list`, build SHA, DB before-after, reconcile report | CI xuất targeted scan test log + full PHPUnit log, route list JSON, OpenAPI generation log, Newman raw/JSON, exact commit/source-tree SHA, DB snapshots trước/sau scan và reconciliation report | `phpunit.raw.log`; `scan-readonly-phpunit.raw.log`; `route-list.raw.json`; `build-metadata.json`; `scan-db-before.json`; `scan-db-after.json`; `reconciliation-report.json`; `SHA256SUMS` | **REPOSITORY IMPLEMENTED**; kết quả chỉ PASS khi workflow exact SHA thành công |
| `BE1-P1-03` | P1 | QA1 review 403/409/422, idempotency, race, rollback, reconciliation theo OpenAPI hash | Test matrix có 200/403/404/409/422, same-key, DB no-write; source register khóa review vào generated OpenAPI hash; stocktake/race/rollback/reconcile evidence tiếp tục nằm trong cùng artifact | `docs/testing/WWHN-150-scan-readonly-test-cases.json`; `WWHN-150-source-register.json`; existing stocktake tests/evidence | **READY FOR QA1 REVIEW**; không giả mạo QA1 sign-off |
| `BE1-P1-04` | P1 | BE2 xác nhận boundary schema/report/warranty/PV, không tạo nguồn tồn lệch BE1 | Chốt ownership: scan classify chỉ resolve; balance/ledger/posting_event chỉ được ghi bởi posting services; resolver có static write guard trong verifier; không tạo bảng tồn/song song mới | Mục 2 Architecture Boundary; `verify-wwhn150-contract.mjs` | **READY FOR BE2 REVIEW**; BE2 sign-off pending |
| `BE1-P1-05` | P1 | FE mapping endpoint/payload/error/permission sau contract chính thức | Chốt endpoint, payload, success flags, error codes, permission và data scope; Postman là executable mapping baseline | Mục 5 FE handoff; generated OpenAPI + Postman exact SHA | **READY FOR FE1 + BA REVIEW**; sign-off pending |
| `BE1-P1-06` | P1 | Permission catalog, seed diff/hash, endpoint → permission → role matrix | Permission exporter bổ sung source SHA-256, route integrity gate và endpoint-permission-role matrix; static catalog cập nhật WWHN-150 | `ExportPermissionCatalog.php`; `permission-catalog.json`; `docs/permissions/WMS_PERMISSION_CATALOG_BE1.*` | **REPOSITORY IMPLEMENTED**; FE/BA review pending |
| `BE1-P2-01` | P2 | Jira closure matrix AC → artifact/version/hash → reviewer → status → blocker | Tạo exact closure matrix và ready-to-paste Jira comment; hash/version lấy từ exact artifact, không ghi hash giả | Mục 7 và Mục 8 | **READY FOR JIRA HANDOFF** |

## 2. Output Architecture và boundary ownership

### 2.1 Read path của scan/classify

```text
FE / Scanner
  -> POST /api/v1/scan/classify
  -> auth:api
  -> permission:scan.classify
  -> warehouse data-scope check
  -> ScanClassificationService (SELECT-only)
       -> physical_code
       -> inventory_serials / item_instance
       -> container
       -> products + inventory_balances (read-only SKU information)
  -> response stock_effect=NONE
```

### 2.2 Write boundary bắt buộc

```text
scan/classify                     : READ ONLY
inbound post-receipt              : WRITE balance + movement + posting_event
outbound post-issue               : WRITE balance + movement + posting_event
stocktake apply-adjustment        : WRITE balance + movement + posting_event
transfer posting                  : WRITE theo transfer service contract
warranty TEMP_ONLY                : không được tự tạo nguồn tồn cạnh tranh
report/PV                         : read model, không phải stock source of truth
```

Nguồn tồn canonical của BE1:

- `inventory_balances`: current balance theo warehouse/product/status;
- `stock_movements`: signed append-only ledger;
- `posting_event`: operation-level posting/idempotency evidence cho **write operation**;
- scan/classify không được ghi vào ba nguồn trên và không được tạo idempotency cache.

### 2.3 Static and runtime guard

`verify-wwhn150-contract.mjs` fail khi controller/service scan chứa write patterns như `insert`, `update`, `delete`, model `create`, `IdempotencyStore`, `StockMovement` hoặc `InventoryBalance` mutation. Runtime proof dùng DB fingerprint trước/sau Newman scan.

## 3. Artifact pack bắt buộc

### 3.1 Source artifacts trong repository

| Artifact | Version/status |
|---|---|
| `docs/api-contract/WWHN-150-scan-openapi.yaml` | `1.0.0 / PROPOSED` |
| `docs/api-contract/WWHN-150_OpenAPI_Changelog_BE1.md` | `1.0.0 / PROPOSED` |
| `docs/postman/WWHN-150 Scan ReadOnly Contract.postman_collection.json` | `1.0.0` |
| `docs/postman/WWHN-150 Scan ReadOnly CI.postman_environment.json` | `1.0.0` |
| `docs/testing/WWHN-150-scan-readonly-test-cases.json` | `1.0.0 / PROPOSED` |
| `docs/docs_task_jira/WWHN-150-source-register.json` | `1.0.0 / PROPOSED` |
| `docs/permissions/WMS_PERMISSION_CATALOG_BE1.md` | `1.1.0-be1-wwhn150 / PROPOSED` |

### 3.2 Generated immutable evidence

GitHub Actions artifact name:

```text
wwhn-150-evidence-<exact_commit_sha>
```

Required files:

- `build-metadata.json` — exact commit SHA, source-tree SHA-256, runtime/tool versions và source hashes;
- `source-tree.sha256` — hash của `git archive HEAD`;
- `SHA256SUMS` — hash manifest của toàn bộ evidence/source copy;
- `openapi.json`, `openapi.yaml`, `openapi-generate.raw.log`;
- `postman.collection.json`, `postman.environment.json`, `newman.raw.log`, `newman.result.json`;
- stocktake collection/result/log giữ nguyên để QA1 review race/rollback/reconcile;
- `wwhn150.postman.collection.json`, `wwhn150.postman.environment.json`, `wwhn150-newman.raw.log`, `wwhn150-newman.result.json`;
- `phpunit.raw.log`, `scan-readonly-phpunit.raw.log`;
- `route-list.raw.json`, `permission-catalog.json`;
- `scan-db-before.json`, `scan-db-after.json`, `scan-readonly-comparison.json`;
- `db-before.json`, `db-after.json`, `reconciliation-report.json`;
- `wwhn150-contract-verification.json`;
- `migrate-fresh.raw.log`, `database-seed.raw.log`, `server.raw.log`.

Verification command after download:

```bash
cd wwhn-150-evidence-<SHA>
sha256sum --check SHA256SUMS
node -e "const r=require('./wwhn150-contract-verification.json'); if(r.summary.status!=='PASS') process.exit(1)"
node -e "const r=require('./scan-readonly-comparison.json'); if(r.summary.status!=='PASS') process.exit(1)"
node -e "const r=require('./reconciliation-report.json'); if(r.summary.status!=='PASS') process.exit(1)"
```

## 4. QA1 review packet — WWHN-152

QA1 phải chạy trên exact SHA, không review branch động.

| Gate | Expected evidence | Expected result |
|---|---|---|
| Auth/RBAC | no-permission Newman + feature test | `403` |
| Data scope | scoped warehouse negative test | `403 WAREHOUSE_SCOPE_DENIED` |
| Invalid object/context | feature test | `409` và không ghi DB |
| Validation | Postman/feature test | `422` và không ghi DB |
| Unknown code | Postman/feature test | `404 SCAN_CODE_NOT_FOUND` |
| Same key retry | two identical scan requests | cả hai `200`; idempotency table unchanged |
| Stock effect | DB fingerprint | balance/movement/posting_event unchanged |
| Scan event | DB fingerprint | `scan_event` unchanged |
| Reconciliation | generated report | `failed=0` |
| OpenAPI lock | `openapi.json` hash in `SHA256SUMS` | hash PASS |
| Stocktake race/rollback | existing stocktake tests/artifact | PASS on same exact SHA |

QA1 comment phải ghi: exact SHA, artifact URL/name, `SHA256SUMS` result, reviewed OpenAPI hash, test result, reviewer, date và blocker.

## 5. FE1 + BA contract handoff — WWHN-153

### 5.1 Endpoint

```http
POST /api/v1/scan/classify
Authorization: Bearer <JWT>
Content-Type: application/json
```

`Idempotency-Key` không bắt buộc. Nếu FE vẫn gửi header để thống nhất client interceptor, backend bỏ qua và không ghi cache.

### 5.2 Request

```json
{
  "raw_code": "HN-PV-2026-00001",
  "context": "STOCKTAKE",
  "warehouse_id": "<uuid>"
}
```

### 5.3 Success invariants

```json
{
  "success": true,
  "data": {
    "classification": "ITEM",
    "stock_effect": "NONE",
    "movement_created": false,
    "posting_event_created": false,
    "idempotency_cache_written": false,
    "current_location": {
      "warehouse_id": "<uuid>"
    }
  }
}
```

FE không được tăng/giảm tồn, giả lập posting, hoặc coi classify response là hạch toán thành công. Posting chỉ xảy ra qua endpoint posting riêng.

### 5.4 Error mapping

| HTTP | Error code | FE behavior |
|---|---|---|
| 401 | `UNAUTHENTICATED` | logout/refresh token theo auth policy |
| 403 | `ACCESS_DENIED` hoặc `WAREHOUSE_SCOPE_DENIED` | ẩn action hoặc báo không có quyền/phạm vi kho |
| 404 | `SCAN_CODE_NOT_FOUND` | cho scan lại/nhập mã khác |
| 409 | `WAREHOUSE_INACTIVE`, `SCAN_CODE_INACTIVE`, `SCAN_WAREHOUSE_MISMATCH`, `SCAN_CONTEXT_CONFLICT`, ... | hiển thị business conflict, không retry tự động |
| 422 | validation envelope | map lỗi field |

### 5.5 Permission

```text
POST /api/v1/scan/classify
  -> permission: scan.classify
  -> seeded role: WAREHOUSE_MANAGER
  -> SUPER_ADMIN: backend bypass
```

FE permission visibility chỉ là UX; backend middleware vẫn là nguồn enforcement.

## 6. BE2 boundary review — WWHN-151

BE2 cần xác nhận bằng Jira comment:

- không tạo balance/ledger/posting_event thứ hai cho schema/report/warranty/PV;
- report và PV chỉ đọc nguồn canonical hoặc read model có reconciliation rõ ràng;
- warranty TEMP_ONLY không tự post tồn;
- field `warehouse_id`, product/SKU reference và status mapping không tạo mapping song song không kiểm soát;
- endpoint/DB ownership không xung đột với BE1 posting services;
- exact OpenAPI/source hash đã review.

Nếu BE2 phát hiện schema mirror (`warehouse`, `sku`, `inventory_movement`) còn được dùng, phải ghi rõ mapping/owner/reconcile rule; không được coi mirror là nguồn tồn canonical thứ hai.

## 7. Closure matrix P2

| AC/Fix ID | Artifact/version | Hash source | Reviewer | Repository status | External blocker |
|---|---|---|---|---|---|
| `BE1-P0-01` | OpenAPI/Postman/source register `1.0.0` | `SHA256SUMS` | Tech Lead/PM | Implemented | Jira attachment + formal approval |
| `BE1-P1-01` | Scan service/controller/test `WWHN-150.1.0.0` | source copy hashes | QA1 | Implemented | QA1 independent test |
| `BE1-P1-02` | Raw logs/route/build/DB/reconcile | `SHA256SUMS` | QA1 + DevOps | CI wired | exact SHA workflow success |
| `BE1-P1-03` | Test matrix `1.0.0` | source + result hashes | QA1 | Ready | QA1 comment |
| `BE1-P1-04` | Architecture boundary v1.0 | doc/source hashes | BE2 | Ready | BE2 comment |
| `BE1-P1-05` | FE contract v1.0 | OpenAPI/Postman hashes | FE1 + BA | Ready | FE/BA comment |
| `BE1-P1-06` | Permission catalog `1.1.0` | seed/route/middleware SHA-256 | FE1 + BA | Implemented | FE/BA comment |
| `BE1-P2-01` | This closure matrix | document SHA-256 | BE1/PM | Ready | Jira closure comment |

## 8. Ready-to-paste Jira closure comment

```text
WWHN-150 BE1 HANDOFF — PROPOSED FOR INDEPENDENT REVIEW

Branch: m1
Exact review commit: <build-metadata.json.commit_sha>
Workflow run: <url>
Artifact: wwhn-150-evidence-<exact_commit_sha>
Source-tree SHA-256: <build-metadata.json.source_tree_sha256>
SHA256SUMS validation: PASS|FAIL — <evidence/link>
Generated OpenAPI SHA-256: <SHA256SUMS openapi.json>
Postman collection SHA-256: <SHA256SUMS wwhn150.postman.collection.json>
Permission seed SHA-256: <permission-catalog.json.source_hashes.permission_seed.sha256>
Warehouse routes SHA-256: <permission-catalog.json.source_hashes.warehouse_routes.sha256>

Repository implementation:
- scan/classify real resolver, no mock IDs
- permission + warehouse data-scope enforcement
- stock_effect=NONE; no balance/movement/posting_event/scan_event/idempotency writes
- targeted + full PHPUnit raw logs
- route:list, generated OpenAPI JSON/YAML, Postman/Newman raw/JSON
- DB before/after fingerprint and reconciliation report
- endpoint -> permission -> role matrix and source hashes

Automated gate:
- wwhn150-contract-verification: PASS|FAIL
- scan-readonly-comparison: PASS|FAIL
- reconciliation-report: PASS|FAIL
- full PHPUnit: PASS|FAIL
- WWHN-150 Newman: PASS|FAIL

Independent reviews:
- QA1 / WWHN-152: PENDING|PASS — <reviewer/date/comment>
- BE2 / WWHN-151: PENDING|PASS — <reviewer/date/comment>
- FE1 + BA / WWHN-153: PENDING|PASS — <reviewer/date/comment>
- Tech Lead/PM approval: PENDING|APPROVED — <reviewer/date/comment>

Remaining blockers: <none or exact blockers>
Decision: PROPOSED until every independent review and formal Jira approval above is recorded.
```

## 9. Definition of Done

- [ ] Exact `m1` commit workflow success.
- [ ] Artifact downloaded; `sha256sum --check SHA256SUMS` PASS.
- [ ] `wwhn150-contract-verification.summary.status=PASS`.
- [ ] `scan-readonly-comparison.summary.status=PASS`.
- [ ] `reconciliation-report.summary.status=PASS`.
- [ ] Full PHPUnit and all Newman suites PASS.
- [ ] QA1 signs WWHN-152 on exact SHA/hash.
- [ ] BE2 signs WWHN-151 boundary on exact SHA/hash.
- [ ] FE1 + BA sign WWHN-153 mapping on exact OpenAPI/Postman hash.
- [ ] Tech Lead/PM records formal Jira approval with version/hash/date.
- [ ] Only after all gates: change Jira/document status from `PROPOSED` to approved closure state.
