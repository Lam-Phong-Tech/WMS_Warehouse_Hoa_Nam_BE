# [BE1] Chủ trì rà soát API/dữ liệu — WWHN-180

## 1. Document control

| Thuộc tính | Giá trị |
|---|---|
| Parent gate | `WWHN-40` — G1 API/Data Contract Review |
| Related Jira | `WWHN-181` BA decision; `WWHN-182` QA2 verification; `WWHN-183` FE1/BA closure |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch triển khai | **Chỉ `m1`**; không tạo branch/PR riêng |
| Document version | `1.2.0` |
| Document status | **PROPOSED** |
| BE implementation | **IMPLEMENTED ON M1 — SUBJECT TO EXACT-SHA CI** |
| Formal approval | **PENDING INDEPENDENT SIGN-OFF** |
| Exact review SHA | Lấy từ `build/evidence/api-data/build-metadata.json.commit_sha` |
| Passing artifact | `api-data-evidence-<exact_commit_sha>` |
| Failed-run artifact | `api-data-diagnostics-<exact_commit_sha>` — chỉ dùng chẩn đoán, không dùng closure |
| Hash authority | `SHA256SUMS` và `SHA256SUMS.sha256` trong passing artifact |

> Không được đổi tài liệu/Jira sang `APPROVED`, `Done` hoặc claim `100%` trước khi BA, FE1 và QA2 cùng xác nhận độc lập trên **cùng exact commit SHA**, cùng workflow run và cùng artifact digest. BE1 self-review hoặc CI tự động không thay thế independent sign-off.

## 2. P0/P1/P2 closure matrix

| ID | Sev | Finding / required fix | Thay đổi đã triển khai trên `m1` | Evidence | Owner/reviewer | Trạng thái |
|---|---|---|---|---|---|---|
| `BE1-P0-01` | P0 | File còn `PENDING INDEPENDENT SIGN-OFF`; WWHN-182/183 chưa Done. BA phải ghi G1 decision, FE1 ký mapping, QA2 attach exact SHA/hash; chỉ sau đó Approved/Done | Khóa trạng thái `PROPOSED/PENDING_INDEPENDENT_SIGN_OFF` trong task doc, source register, build metadata và closure matrix; cung cấp template BA/FE1/QA2 ở mục 10–12 | `WWHN-180-source-register.json`; `build-metadata.json`; `g1-closure-matrix.json`; `SHA256SUMS*` | BA + FE1 + QA2 + BE1 | **READY FOR INDEPENDENT SIGN-OFF**; chưa được tự chuyển Approved |
| `BE1-P0-02` | P0 | Scan/Post Receipt/Post Issue mới mô tả rule, thiếu xác minh độc lập trên artifact thật | Hardening idempotency atomic transaction, lease CAS, exact replay, canonical v2 SHA-256 comparator, legacy-row compatibility, header boundary 8–100, rollback; executable OpenAPI/Postman; full PHPUnit; Newman; DB before/after; DB delta verifier; reconciliation; immutable manifest | Passing artifact `api-data-evidence-<SHA>` | BE1 build; QA2 retest; BA/FE1 review | **REPOSITORY IMPLEMENTED**; closure phụ thuộc passing workflow + independent review |
| `BE1-P1-01` | P1 | Jira chỉ có Markdown, thiếu JSON/YAML/collection/environment | Commit trực tiếp focused OpenAPI YAML, Postman collection/environment; CI generate full OpenAPI JSON/YAML; hash từng artifact | `docs/api-contract/WWHN-180-posting-openapi.yaml`; `docs/postman/WWHN-180*`; generated `openapi.json/openapi.yaml`; `SHA256SUMS` | BE1 | **IMPLEMENTED** |
| `BE1-P1-02` | P1 | Thiếu CI URL, build metadata, raw route/PHPUnit/Newman và hash pass | Workflow exact-SHA sinh URL/run metadata, route list, permission catalog, full/focused PHPUnit, Newman raw/JSON, DB snapshots, source-tree digest, manifest + manifest digest | `.github/workflows/wwhn-180-api-posting-contract.yml`; `scripts/collect-wwhn180-evidence.sh` | BE1 | **IMPLEMENTED**; chỉ passing artifact mới là closure evidence |
| `BE1-P1-03` | P1 | Permission matrix pending FE1/BA | Runtime permission catalog + route mapping + focused OpenAPI `x-permission`; FE mapping table và sign-off template ở mục 8–10 | `permission-catalog.json`; `route-list.raw.json`; focused/generated OpenAPI | BE1 + FE1 + BA | **READY FOR FE1/BA REVIEW** |
| `BE1-P1-04` | P1 | WWHN-182 chưa có API/DB evidence | Automation gồm happy, negative, RBAC, missing/invalid key, exact replay, semantic JSON key-order replay, payload conflict, different-key double-post, atomic rollback, stale status, DB delta, reconcile | `phpunit*.raw.log`; `newman.*`; `db-before.json`; `db-after.json`; `wwhn180-db-delta.json`; `reconciliation-report.json` | QA2 + BE1 | **READY FOR QA2 INDEPENDENT RETEST** |
| `BE1-P1-05` | P1 | Parent WWHN-40 chưa có G1 minutes | Chuẩn hóa decision register: blocker → endpoint/schema → test/evidence → reviewer → result → final Go/Conditional Go/No-Go | Mục 11 và `g1-closure-matrix.json` | BA + BE1 + FE1 | **PENDING BA G1 MINUTES** |
| `BE1-P2-01` | P2 | Jira handoff đang là blob, thiếu closure matrix đọc nhanh | Collector tự sinh `jira-closure-comment.txt`; mục 13 có payload ngắn exact SHA/digest/status/reviewer | `jira-closure-comment.txt`; mục 13 | BE1 | **READY TO POST**; Atlassian connector hiện 403/app not installed |

## 3. G1 contract đã khóa

| Action | Endpoint | Permission | Stock effect | Idempotency |
|---|---|---|---|---|
| Scan inbound | `POST /api/v1/inbound-documents/{id}/scan` | `stock_document.scan` | `NONE`; không movement/balance/document posting status | Không bắt buộc |
| Scan outbound | `POST /api/v1/outbound-documents/{id}/scan` | `stock_document.scan` | `NONE`; không movement/balance/document posting status | Không bắt buộc |
| Classify scan | `POST /api/v1/scan/classify` | `scan.classify` | Read-only classify/resolve | Không bắt buộc |
| Post receipt | `POST /api/v1/inbound-documents/{id}/post-receipt` | `stock_document.post` | Signed movement dương + balance; đúng một lần | Bắt buộc `Idempotency-Key`, trim, 8–100 ký tự |
| Post issue | `POST /api/v1/outbound-documents/{id}/post-issue` | `stock_document.post` | Signed movement âm + balance; đúng một lần | Bắt buộc `Idempotency-Key`, trim, 8–100 ký tự |
| Reconcile | `GET /api/v1/reports/inventory-reconciliation` | `report.view` | Read-only, so khớp balance với signed ledger | Không áp dụng |

State transition chính:

```text
receipt: draft -> pending -> approved -> received
issue:   draft -> pending -> approved -> issued
scan:    approved -> approved; stock_effect=NONE
```

## 4. Core correctness: idempotency và atomic posting

### 4.1 Invariant bắt buộc

`app/Support/IdempotencyStore.php` và controller concern đã được harden theo các invariant:

1. Logical key: `(idem_key, operation, user_id)`.
2. `Idempotency-Key` được trim; blank/missing trả `428 PRECONDITION_REQUIRED`; ngắn hơn 8 hoặc dài hơn 100 ký tự trả `422 VALIDATION_ERROR` trước khi ghi DB.
3. Same key + same payload: trả lại đúng persisted response snapshot; callback không chạy lần hai.
4. Same key + different payload: `409 IDEMPOTENCY_ERROR`; không stock effect mới.
5. Request hash v2: canonical JSON + SHA-256; thứ tự object key không làm đổi canonical digest.
6. Comparator v2 chỉ so canonical SHA-256 digest. Legacy MD5 suffix có thể khác theo thứ tự key cũ nhưng không được gây false `409` giữa hai hash v2.
7. Tương thích row cũ: v2 hash vẫn mang legacy MD5 để đối chiếu row chỉ lưu MD5 từ deployment cũ.
8. Lease takeover dùng compare-and-set trên `status` và `locked_until`; không blind reclaim.
9. Lease timestamp chuẩn hóa đến giây để nhất quán SQLite/PostgreSQL.
10. Callback nghiệp vụ và việc lưu `status=done`, HTTP code, response snapshot cùng nằm trong **một outer DB transaction**.
11. Lỗi sau business write nhưng trước response completion: rollback business write và dọn đúng lease của request hiện tại.
12. Client timeout sau DB commit: retry đọc row `done` và replay, không post lần hai.
13. Lỗi schema/kết nối không bị che thành `409 race` giả.

Atomic boundary:

```text
acquire processing lease
  -> BEGIN OUTER TRANSACTION
     -> lock exact idempotency row
     -> service transaction
        -> validate approved state
        -> lock/create inventory balance
        -> version CAS update balance
        -> append unique stock movement
        -> update document + status history
        -> write posting_event
     -> persist exact HTTP response snapshot
  -> COMMIT ONCE
```

### 4.2 Double-post và concurrency guards

- `stock_movements.business_key` unique theo action/document/line.
- Ledger kiểm tra movement lặp trước và sau thời điểm chờ balance row lock.
- Balance dùng `lockForUpdate` + optimistic `version` CAS.
- `posting_event` unique `(operation, idempotency_key)`.
- Document status có stale-model/optimistic conflict guard.
- Different key sau khi document đã post trả `409 STATE_CONFLICT` hoặc movement conflict; toàn transaction rollback.
- Same key overlap: một request chạy callback; request còn lại nhận processing conflict hoặc exact replay sau commit.

Expected race outcomes:

| Tình huống | Kết quả hợp lệ | DB invariant |
|---|---|---|
| Same key, same payload, overlap | `200 + 409 processing` hoặc `200 + replay 200` | Một stock effect |
| Same semantic payload, khác object-key order | `200 + replay 200` | Callback chạy một lần; comparator dùng v2 digest |
| Same key, different payload | `200 + 409 IDEMPOTENCY_ERROR` | Một stock effect |
| Different keys, cùng document | `200 + 409 STATE_CONFLICT` | Một stock effect |
| Failure giữa business write và response completion | Exception/5xx | Không business write dở dang, không lease treo do request hiện tại |
| Timeout phía client sau commit | Retry `200 replay` | Không movement/balance lần hai |

## 5. Reconciliation integrity

Reconciliation dimension lấy từ hợp của:

- `inventory_balances(warehouse_id, product_id, stock_status)`;
- `stock_movements(warehouse_id, product_id, stock_status)`.

Signed ledger formula:

```text
expected_qty = SUM(stock_movements.qty_delta)
PASS when inventory_balances.on_hand_qty == expected_qty
```

Mismatch reasons:

- `BALANCE_MISSING_FOR_LEDGER`;
- `LEDGER_MISSING_FOR_BALANCE`;
- `BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM`.

`InventoryReconciliationIntegrityTest` kiểm tra cả ledger-only và balance-only dimension. `verify-inventory-reconciliation.mjs` buộc report cuối cùng `summary.status=PASS` và `failed=0`.

## 6. Executable OpenAPI/Postman package

### 6.1 Source artifacts

| File | Version/status |
|---|---|
| `docs/api-contract/WWHN-180-posting-openapi.yaml` | `1.0.0 / PROPOSED`; đủ classify, inbound/outbound scan, receipt, issue, reconcile |
| `docs/postman/WWHN-180 Posting Contract.postman_collection.json` | `1.1.0`, 23 requests |
| `docs/postman/WWHN-180 Posting CI.postman_environment.json` | Không lưu credential/token |
| `docs/testing/WWHN-180-posting-test-cases.json` | `1.2.0`; P0/P1 test matrix |
| `docs/docs_task_jira/WWHN-180-source-register.json` | `1.1.0 / PROPOSED` |

### 6.2 Newman coverage — 23 requests

- Manager login và no-permission login.
- Dynamic warehouse/product discovery.
- Inbound create → submit → approve → scan read-only.
- Post receipt thiếu key `428`.
- Post receipt happy path.
- Same-key exact replay.
- Same-key/different-payload `409 IDEMPOTENCY_ERROR`.
- Different-key double-post `409 STATE_CONFLICT`.
- Outbound create → submit → approve.
- Post issue thiếu key `428`.
- Post issue happy path/replay/payload conflict/different-key double-post.
- Reconciliation manager `PASS`, `mismatch_count=0`.
- Reconciliation no-permission `403 ACCESS_DENIED`.

Focused PHPUnit bổ sung semantic object-key-order replay, key trim, boundary 8/100, blank `428` và out-of-range `422`. Credential được inject bằng Newman runtime `--env-var`; collection/environment source không lưu password hoặc token.

## 7. Immutable exact-SHA evidence

### 7.1 Workflow và collector

```text
.github/workflows/wwhn-180-api-posting-contract.yml
scripts/collect-wwhn180-evidence.sh
```

Workflow chạy trên push/PR vào `m1` và manual dispatch. Passing run upload:

```text
api-data-evidence-<exact_commit_sha>
```

Failed run chỉ upload diagnostics:

```text
api-data-diagnostics-<exact_commit_sha>
```

Failed diagnostics không được dùng để đóng P0/P1.

### 7.2 Required artifact files

```text
build-metadata.json
g1-closure-matrix.json
jira-closure-comment.txt
ci-summary.md
source-tree.sha256
SHA256SUMS
SHA256SUMS.sha256
route-list.raw.json
permission-catalog.json
openapi.json
openapi.yaml
wwhn180-openapi.yaml
wwhn180.postman.collection.json
wwhn180.postman.environment.json
wwhn180-source-register.json
wwhn180-test-cases.json
wwhn180-contract-verification.json
wwhn180-db-delta.json
phpunit.raw.log
wwhn180-atomicity-phpunit.raw.log
stock-document-canonical-phpunit.raw.log
stock-document-concurrency-phpunit.raw.log
reconciliation-integrity-phpunit.raw.log
newman.raw.log
newman.result.json
db-before.json
db-after.json
reconciliation-report.json
source/*
```

### 7.3 CI gates

1. PHP syntax toàn `app`, `Modules`, `tests`, `config`, `database`, `routes`.
2. `git diff --check`.
3. Pint toàn backend scope.
4. npm lint/test/build baseline.
5. Full PHPUnit + focused atomicity/canonical/concurrency/reconcile tests.
6. Full OpenAPI generation; Redocly lint JSON/YAML/focused YAML.
7. Permission catalog integrity `PASS`.
8. Static executable-contract verifier `PASS`, gồm 6 focused path, header 8–100, canonical v2 digest comparator, secret hygiene và atomicity primitives.
9. Newman 23 requests, failures = 0.
10. DB before/after snapshot.
11. DB delta verifier `PASS`.
12. Reconciliation `PASS`, failed = 0.
13. `build-metadata.commit_sha == GITHUB_SHA`.
14. Closure status phải giữ `BLOCKED_PENDING_INDEPENDENT_SIGN_OFF` trước reviewer sign-off.
15. `SHA256SUMS` và `SHA256SUMS.sha256` đều verify `OK`.

Local evidence command:

```bash
export WWHN180_EMAIL='manager01@gmail.com'
export WWHN180_PASSWORD='<runtime-secret>'
export WWHN180_NO_PERMISSION_EMAIL='test-no-permission@hoanam.com'
export WWHN180_NO_PERMISSION_PASSWORD='<runtime-secret>'
EVIDENCE_DIR=build/evidence/api-data bash scripts/collect-wwhn180-evidence.sh
(cd build/evidence/api-data && sha256sum --check SHA256SUMS && sha256sum --check SHA256SUMS.sha256)
```

## 8. Permission/RBAC mapping

| Endpoint | Backend permission | FE action/route key | Denied result |
|---|---|---|---|
| `POST /api/v1/scan/classify` | `scan.classify` | `scan.classify` | `403 ACCESS_DENIED` hoặc scope-specific 403 |
| `POST /api/v1/inbound-documents/{id}/scan` | `stock_document.scan` | `stock_document.scan` | `403 ACCESS_DENIED` |
| `POST /api/v1/outbound-documents/{id}/scan` | `stock_document.scan` | `stock_document.scan` | `403 ACCESS_DENIED` |
| `POST /api/v1/inbound-documents/{id}/post-receipt` | `stock_document.post` | `stock_document.post` | `403 ACCESS_DENIED` |
| `POST /api/v1/outbound-documents/{id}/post-issue` | `stock_document.post` | `stock_document.post` | `403 ACCESS_DENIED` |
| `GET /api/v1/reports/inventory-reconciliation` | `report.view` | `report.view` | `403 ACCESS_DENIED` |

Backend middleware là enforcement source of truth. FE guard chỉ kiểm soát visibility/UX và không thay backend authorization.

## 9. FE1 integration rules

Headers cho posting:

```http
Authorization: Bearer <JWT>
Content-Type: application/json
Idempotency-Key: <client-generated logical-action key, 8-100 characters>
```

FE retry policy:

- Trim key trước khi gửi; key sau trim phải dài 8–100 ký tự.
- Timeout/network failure của cùng logical action: giữ nguyên Idempotency-Key.
- Payload hoặc logical action thay đổi: sinh key mới.
- Thứ tự object key trong JSON không thay đổi semantic payload; backend so canonical v2 digest.
- `409 IDEMPOTENCY_ERROR`: không auto-retry bằng payload đã đổi.
- `409 STATE_CONFLICT`: reload document/status; không tự giả định post thành công.
- `422`: map field/business error; không retry tự động.
- `428 PRECONDITION_REQUIRED`: client defect, phải bổ sung key.

Error mapping:

| HTTP | Code chính | FE behavior |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Auth refresh/logout policy |
| 403 | `ACCESS_DENIED` / scope code | Disable/hide action và hiển thị lý do quyền/phạm vi |
| 409 | `IDEMPOTENCY_ERROR` | Chặn auto-retry; user xác nhận trước logical action mới |
| 409 | `STATE_CONFLICT` | Reload state và movement/history |
| 422 | `VALIDATION_ERROR` / `INSUFFICIENT_STOCK` | Sửa key/payload hoặc hiển thị business/field errors |
| 428 | `PRECONDITION_REQUIRED` | Bổ sung Idempotency-Key |

## 10. FE1 sign-off template — WWHN-183

```text
FE1 CONTRACT REVIEW — WWHN-183
Branch: m1
Exact commit: <build-metadata.commit_sha>
Workflow run: <build-metadata.workflow_run_url>
Artifact: api-data-evidence-<SHA>
SHA256SUMS digest: <SHA256SUMS.sha256>
OpenAPI SHA-256: <build-metadata.openapi_sha256>
Postman SHA-256: <build-metadata.postman_collection_sha256>
Permission catalog SHA-256: <build-metadata.permission_catalog_sha256>

Endpoint/payload mapping: PASS|FAIL
Error mapping 401/403/409/422/428: PASS|FAIL
Permission/action mapping: PASS|FAIL
Retry/idempotency behavior: PASS|FAIL
Reviewer: <FE1 name>
Reviewed at: <ISO-8601 +07:00>
Blocker: <none or issue key>
```

## 11. QA2 independent verification — WWHN-182

QA2 phải test exact commit artifact, không test branch name động.

| Gate | Expected |
|---|---|
| Scan happy | `stock_effect=NONE`; movement/balance/posting_event không đổi |
| Scan RBAC/scope/invalid | 403/409/422 đúng contract; DB không đổi |
| Receipt happy | `received`; +1 movement/line; balance tăng đúng; one posting event |
| Receipt replay | Same movement/response; không stock effect lần hai |
| Semantic order replay | Hai JSON cùng nghĩa nhưng khác thứ tự object key phải replay; không false 409 |
| Receipt payload conflict | `409 IDEMPOTENCY_ERROR`; DB không đổi |
| Receipt different-key double-post | `409 STATE_CONFLICT`; DB không đổi |
| Issue happy/replay/conflicts | Đối xứng receipt; signed delta âm đúng một lần |
| Missing/blank key | `428 PRECONDITION_REQUIRED`; DB không đổi |
| Key ngoài 8–100 | `422 VALIDATION_ERROR`; DB không đổi |
| Race | Exactly one stock effect |
| Atomic rollback | Business probe và owned processing lease rollback |
| DB delta | `wwhn180-db-delta.summary.status=PASS` |
| Reconcile | `summary.status=PASS`, `failed=0` |
| Manifest | Tất cả `SHA256SUMS: OK`; manifest digest OK |

QA2 comment:

```text
QA2 RETEST — WWHN-182
Branch: m1
Exact commit: <build-metadata.commit_sha>
Workflow run: <URL>
Artifact: api-data-evidence-<SHA>
SHA256SUMS: PASS|FAIL
Manifest digest: PASS|FAIL
PHPUnit: PASS|FAIL — <tests/assertions/failures>
Newman: PASS|FAIL — <requests/assertions/failures>
DB before/after reviewed: YES|NO
DB delta verifier: PASS|FAIL
Reconciliation: PASS|FAIL — failed=<n>
Race/timeout-after-commit: PASS|FAIL
Reviewer: <QA2 name>
Reviewed at: <ISO-8601 +07:00>
Result: PASS|FAIL
Blocker: <none or issue key>
```

## 12. BA G1 minutes — WWHN-40/WWHN-181

```text
G1 MINUTES — WWHN-180 API/DATA
Meeting date: <ISO-8601 +07:00>
Branch: m1
Exact commit: <build-metadata.commit_sha>
Workflow run: <URL>
Artifact: api-data-evidence-<SHA>
SHA256SUMS: PASS|FAIL

Decision register
1. Scan boundary
   Decision: classify/validate only; stock_effect=NONE
   Endpoint/schema: <OpenAPI hash>
   Test evidence: <file/result>
   Reviewers: BA / FE1 / QA2
   Status: PASS|FAIL

2. Receipt posting
   Decision: approved -> received; atomic signed stock increase; exact replay
   Evidence: <files/hashes/results>
   Status: PASS|FAIL

3. Issue posting
   Decision: approved -> issued; atomic signed stock decrease; exact replay
   Evidence: <files/hashes/results>
   Status: PASS|FAIL

4. RBAC/data scope
   Decision: backend enforcement is authoritative
   Evidence: permission-catalog.json + route-list.raw.json
   Status: PASS|FAIL

5. DB integrity/reconciliation
   Decision: balance equals signed append-only ledger
   Evidence: db-before/after + db-delta + reconciliation
   Status: PASS|FAIL

Open blockers: <none or issue list>
Final decision: GO | CONDITIONAL GO | NO-GO
Decision owner: <BA/PM name>
Decision date: <ISO-8601 +07:00>
```

## 13. Jira closure comment — P2 ready to paste

```text
[WWHN-180 API/DATA CLOSURE MATRIX]
Branch: m1 — direct commits, no separate PR
Exact review SHA: <build-metadata.commit_sha>
Commit URL: <build-metadata.commit_url>
CI run: <build-metadata.workflow_run_url>
Artifact: api-data-evidence-<SHA>
Source-tree SHA-256: <build-metadata.source_tree_sha256>
SHA256SUMS: PASS|FAIL
SHA256SUMS digest: <SHA256SUMS.sha256>
OpenAPI SHA-256: <build-metadata.openapi_sha256>
Postman SHA-256: <build-metadata.postman_collection_sha256>
Permission catalog SHA-256: <build-metadata.permission_catalog_sha256>

BE1-P0-01: READY FOR SIGN-OFF — BA=<pending/pass/fail>, FE1=<pending/pass/fail>, QA2=<pending/pass/fail>
BE1-P0-02: <pass/fail> — real artifact + stock-effect verification
BE1-P1-01: <pass/fail> — OpenAPI JSON/YAML + Postman collection/environment
BE1-P1-02: <pass/fail> — CI/build metadata/route/PHPUnit/Newman/hash
BE1-P1-03: <pass/fail> — RBAC catalog + FE/BA mapping
BE1-P1-04: <pass/fail> — QA/API/DB/race/reconcile
BE1-P1-05: <pending/pass/fail> — BA G1 minutes
BE1-P2-01: PASS — readable closure matrix posted

WWHN-181: <status/link>
WWHN-182: <status/link>
WWHN-183: <status/link>
Final decision: GO | CONDITIONAL GO | NO-GO
Formal status: PROPOSED | APPROVED
Remaining blocker: <none or exact issue>
```

Current Jira posting constraint: **BLOCKED — Atlassian connector returned 403/app not installed**. Không claim comment đã được đăng.

## 14. Definition of Done

Chỉ đóng WWHN-180 khi toàn bộ điều kiện sau cùng đúng:

- [ ] Chốt exact commit SHA trên `m1`.
- [ ] Workflow `WWHN-180 API Data Contract Evidence Gate` success trên exact SHA.
- [ ] Download đúng `api-data-evidence-<SHA>`; không dùng diagnostics artifact.
- [ ] `SHA256SUMS` và `SHA256SUMS.sha256` verify PASS.
- [ ] Full PHPUnit và các focused test logs PASS.
- [ ] Redocly lint full/focused OpenAPI PASS.
- [ ] Contract verifier PASS, failed = 0.
- [ ] Newman failures = 0.
- [ ] DB delta verifier PASS, failed = 0.
- [ ] Reconciliation PASS, failed = 0.
- [ ] FE1 ký endpoint/payload/error/permission/retry mapping trên cùng SHA/hash.
- [ ] QA2 attach independent API/DB/race/reconcile evidence trên cùng SHA/hash.
- [ ] BA ghi G1 minutes và final decision.
- [ ] Jira WWHN-181/182/183 có reviewer, result, date, exact SHA và evidence link.
- [ ] Chỉ sau các gate trên mới đổi `PROPOSED/PENDING` sang `APPROVED/DONE`.

## 15. Changelog chính trên `m1`

| Hạng mục | Nội dung |
|---|---|
| Idempotency | Atomic outer transaction, lease CAS, exact response replay, canonical v2 SHA-256 comparator, legacy MD5 row compatibility, owned-lease cleanup |
| Header validation | `Idempotency-Key` trim; blank/missing 428; ngoài 8–100 ký tự 422 trước DB |
| Tests | `Wwhn180IdempotencyAtomicityTest` gồm semantic-order replay và header boundary; canonical flow; concurrency; reconciliation integrity |
| API contract | Focused OpenAPI đủ classify, inbound/outbound scan, receipt, issue, reconcile + generated full OpenAPI JSON/YAML |
| Postman | Executable collection v1.1.0, 23 requests; environment không lưu credential |
| Evidence | Full/focused PHPUnit, Newman, route/permission catalog, DB before-after, DB delta, reconciliation |
| Static verifier | Khóa focused path/permission, key bounds, canonical v2 comparator, secret hygiene và atomicity primitives |
| Integrity | Source-tree SHA-256, per-file `SHA256SUMS`, manifest digest `SHA256SUMS.sha256` |
| Governance | Source register, G1 closure matrix, Jira comment, CI summary; approval giữ Pending trước independent sign-off |

Exact review SHA cuối cùng luôn phải lấy từ passing artifact `build-metadata.json`; không dùng hash viết tay hoặc tên branch động để đóng gate.
