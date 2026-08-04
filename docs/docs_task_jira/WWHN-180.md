# [BE1] Chủ trì rà soát API/dữ liệu — WWHN-180

## 1. Kiểm soát tài liệu

| Thuộc tính | Giá trị |
|---|---|
| Parent gate | `WWHN-40` |
| Related Jira | `WWHN-181` FE1; `WWHN-182` QA2; `WWHN-183` BA |
| Repository / branch | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` / **`m1`** |
| Architecture baseline | `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` |
| Document version | **1.7.0** |
| Status | **PROPOSED / PENDING_INDEPENDENT_SIGN_OFF** |
| Exact SHA authority | `build/evidence/api-data/build-metadata.json.commit_sha` |
| CI status context | `wwhn-180/evidence-gate` |
| Passing artifact | `api-data-evidence-<exact_commit_sha>` |

Không tự ghi `APPROVED`, `Done` hoặc `100%`. Final approval chỉ hợp lệ khi workflow PASS và BA, FE1, QA2 cùng dẫn chiếu một exact BE SHA, exact FE SHA và verified manifest digest.

## 2. Kết quả rà soát sâu vòng 1.7.0

### 2.1 Canonical handoff đã tách khỏi CI harness

Postman handoff cũ chứa các request `submit` và `approve`, trong khi baseline Hoa Nam quy định inbound/outbound không dùng approval workflow làm canonical flow.

Đã tách rõ:

- **Canonical handoff:** chỉ chứa Scan, Post Receipt, Post Issue, Stocktake Adjustment, Warranty TEMP_ONLY và Reconciliation.
- **CI harness:** được giữ riêng để provision dữ liệu kiểm thử cho backend; không phải API contract giao FE/QA.
- CI fail nếu canonical Postman hoặc focused OpenAPI xuất hiện `/submit`, `/approve` hoặc `/reject`.

Việc tách này ngăn reviewer nhầm platform capability với Hoa Nam canonical flow và đóng trực tiếp finding contract mismatch.

### 2.2 CI observability không cần PR

Workflow chạy trên push `m1` và publish commit status:

```text
context = wwhn-180/evidence-gate
state   = pending | success | failure
target  = exact GitHub Actions run URL
```

Nhờ đó exact-SHA CI có thể được kiểm tra bằng commit status dù không tạo branch/PR riêng.

### 2.3 Sửa lỗi CI readiness tại run 30912919649

Run trên exact SHA `ebd69d06238ce12d67d1253cb213e8804ee90a43` chứng minh static gate, full PHPUnit `152 tests / 1416 assertions` và các focused suite đều PASS, nhưng collector dừng trước Newman vì dùng `curl --fail /api/health` làm readiness probe. Laravel server thực tế đã listen cổng 8000; endpoint health trả `503` theo đúng semantic `degraded`, nên bị nhận nhầm thành process chưa sẵn sàng.

Đã sửa collector theo nguyên tắc fail-closed:

- kiểm tra PID server còn sống;
- coi process ready khi nhận được bất kỳ HTTP response hợp lệ `1xx..5xx`;
- lưu `health-response.json`, `health-http-status.txt`, `health-probe-evidence.json`;
- không biến health degraded thành PASS; Newman, DB delta, reconciliation, credential hygiene và checksum vẫn là hard gate;
- dump `server.raw.log` khi process chết hoặc timeout để tránh lỗi mù.

Commit sửa collector: `64362d86d9acf8212b23604ca40a89598ed0b19c`. CI chính thức phải dẫn chiếu exact head SHA sau commit cập nhật tài liệu này.

## 3. Canonical contract Hoa Nam

| Action | Endpoint | Permission BE | FE guard | Stock effect | Idempotency |
|---|---|---|---|---|---|
| Classify scan | `POST /api/v1/scan/classify` | `scan.classify` | `scan.classify` | `NONE` | N/A |
| Scan inbound | `POST /api/v1/inbound-documents/{id}/scan` | `stock_document.scan` | `scan.inbound` | `NONE` | N/A |
| Scan outbound | `POST /api/v1/outbound-documents/{id}/scan` | `stock_document.scan` | `scan.outbound` | `NONE` | N/A |
| Post receipt | `POST /api/v1/inbound-documents/{id}/post-receipt` | `stock_document.post` | `inbound.post` | `RECEIPT_IN`, exactly once | Required |
| Post issue | `POST /api/v1/outbound-documents/{id}/post-issue` | `stock_document.post` | `outbound.post` | `ISSUE_OUT`, exactly once | Required |
| Apply adjustment | `POST /api/v1/stocktake/sessions/{id}/apply-adjustment` | `stocktake.adjust` | `stocktake.adjust` | Adjustment, exactly once | Required |
| Warranty TEMP_ONLY | `POST /api/v1/warranty-cases/{id}/temp-only` | `warranty.temp_only` | `warranty.temp_only` | `NONE` | Required |
| Reconcile | `GET /api/v1/reports/inventory-reconciliation` | `report.view` | `report.view` | `NONE` | N/A |

Canonical scope:

- một kho tổng Hoa Nam;
- Agency là nơi nhận, không phải warehouse;
- transfer/multi-warehouse ngoài scope Hoa Nam;
- platform approval workflow ngoài canonical handoff;
- scan không tạo movement và không đổi balance.

## 4. Contract artifacts bất biến

| Artifact | Repository path |
|---|---|
| Focused OpenAPI YAML | `docs/api-contract/WWHN-180-posting-openapi.yaml` |
| Focused OpenAPI JSON | `docs/api-contract/WWHN-180-posting-openapi.json` |
| Canonical Postman | `docs/postman/WWHN-180 Canonical Contract.postman_collection.json` |
| Canonical Postman environment | `docs/postman/WWHN-180 Canonical Contract.postman_environment.json` |
| Internal CI harness | `docs/postman/WWHN-180 Posting Contract.postman_collection.json` |
| CI environment | `docs/postman/WWHN-180 Posting CI.postman_environment.json` |
| Test matrix | `docs/testing/WWHN-180-posting-test-cases.json` |
| Permission catalog | `docs/evidence/WWHN-180/permission-catalog.json` |
| BE/FE mapping | `docs/evidence/WWHN-180/BE_FE_PERMISSION_MAPPING.json` |
| Static hashes/blob identities | `docs/evidence/WWHN-180/IMMUTABLE_HANDOFF_MANIFEST.json` |

Canonical environment không chứa password, token hoặc secret value.

## 5. Transaction, race và idempotency invariants

```text
Idempotency identity = (trimmed key, operation, user_id)
```

- Missing/blank key: `428 PRECONDITION_REQUIRED`; không business write.
- Key ngoài 8–100 ký tự: `422 VALIDATION_ERROR`; không business write.
- Same key + same canonical payload: replay exact persisted response.
- Same key + different payload: `409 IDEMPOTENCY_ERROR`.
- Different key + document đã post: `409 STATE_CONFLICT`.
- Document, line và inventory balance được lock tại stock-effect boundary.
- Movement là append-only và có unique business key.
- Business write, posting event và response snapshot commit/rollback cùng transaction.
- Race/double-post chỉ được có một stock effect.
- Reconciliation phải trả `PASS`, `mismatch_count=0`.

## 6. CI evidence package

Passing artifact bắt buộc có:

```text
build-metadata.json
source-tree.sha256
SHA256SUMS
SHA256SUMS.sha256
canonical-handoff-verification.json
wwhn180-openapi.json
wwhn180-openapi.yaml
wwhn180.postman.collection.json
wwhn180.postman.environment.json
wwhn180.ci-harness.collection.json
wwhn180.ci-harness.environment.json
route-list.raw.json
permission-catalog.json
permission-catalog.static.json
be-fe-permission-mapping.json
credential-hygiene.json
health-response.json
health-http-status.txt
health-probe-evidence.json
phpunit.raw.log
wwhn180-atomicity-phpunit.raw.log
stock-document-canonical-phpunit.raw.log
stock-document-concurrency-phpunit.raw.log
wwhn180-permission-alias-phpunit.raw.log
reconciliation-integrity-phpunit.raw.log
newman.raw.log
newman.result.json
db-before.json
db-after.json
wwhn180-db-delta.json
reconciliation-report.json
```

Hard gates:

1. PHP syntax, Pint, npm lint/test/build.
2. Full PHPUnit và focused atomicity/concurrency/RBAC/reconciliation.
3. Canonical handoff drift verification.
4. Generated và focused OpenAPI lint.
5. Newman failure count bằng `0`.
6. DB delta và reconciliation `PASS`.
7. Credential hygiene residual count bằng `0`.
8. Artifact commit SHA bằng workflow SHA.
9. `SHA256SUMS` và `SHA256SUMS.sha256` verify PASS.
10. Commit status `wwhn-180/evidence-gate=success`.

## 7. P0/P1/P2 closure matrix

| ID | Kết quả BE/repository v1.7.0 | Formal gate còn lại |
|---|---|---|
| `BE1-P0-01` | Exact-SHA rules, canonical artifacts, reviewer forms và Jira trace đã khóa | BA + FE1 + QA2 sign cùng SHA/digest |
| `BE1-P0-02` | Atomicity/idempotency/race/DB/reconcile tests và evidence collector có hard gate | QA2 independent execution |
| `BE1-P1-01` | OpenAPI JSON/YAML, canonical Postman/env và static manifest được commit | Reviewer verify immutable commit/blob/hash |
| `BE1-P1-02` | Push-CI publish commit status; readiness false-negative đã sửa; raw logs/artifact/checksums được hard-gate | Quan sát status success và artifact của exact head SHA |
| `BE1-P1-03` | Permission catalog + exact FE source mapping + safe aliases | FE1/BA reviewer/result/date |
| `BE1-P1-04` | QA protocol executable, CI harness tách khỏi canonical contract | QA2 comment/attachment |
| `BE1-P1-05` | G1 minutes inputs đầy đủ | BA decision `GO|CONDITIONAL_GO|NO_GO` |
| `BE1-P2-01` | Readable closure matrix đã post Jira và phải cập nhật theo final artifact | Update final digest |

## 8. Reviewer acceptance

### FE1 — WWHN-181

Phải ghi reviewer/date/result và xác nhận:

- canonical paths/payload/error;
- mapping FE aliases → canonical BE permission;
- không dùng platform approval routes làm Hoa Nam flow;
- stocktake route alias;
- exact BE SHA và FE SHA `5cd10ec46021cecd24949075cb4eeefaa1fcc9b2`.

### QA2 — WWHN-182

Phải attach/comment:

- happy/negative/RBAC;
- missing/invalid key;
- replay/conflict/double-post;
- race/stale model;
- scan stock effect `NONE`;
- DB before/after;
- reconciliation;
- workflow URL, artifact, manifest digest;
- result `PASS|FAIL|CONDITIONAL`.

### BA — WWHN-183 / WWHN-40

Minutes phải có:

```text
blocker → endpoint/schema/rule → test/evidence
→ reviewer/result/date → owner/disposition
→ GO | CONDITIONAL_GO | NO_GO
```

## 9. Definition of Done trung thực

### BE/repository

- [x] Chỉ sửa nhánh `m1`.
- [x] Canonical OpenAPI JSON/YAML.
- [x] Canonical Postman/env không chứa approval workflow.
- [x] CI harness được phân loại ngoài handoff.
- [x] Drift verifier ngăn contract quay lại sai baseline.
- [x] Push-CI commit status observability.
- [x] Readiness probe tách khỏi business-health semantics; lưu đầy đủ HTTP status/body và server diagnostics.
- [x] Raw test/API/DB/reconcile/checksum artifact contract.
- [x] Permission mapping và closure traceability.

### External/formal

- [ ] Commit status `wwhn-180/evidence-gate=success`.
- [ ] Passing artifact và checksum digest.
- [ ] FE1 sign-off.
- [ ] QA2 independent evidence.
- [ ] BA G1 minutes/decision.
- [ ] Chỉ sau các mục trên mới được chấm `100/100`.
