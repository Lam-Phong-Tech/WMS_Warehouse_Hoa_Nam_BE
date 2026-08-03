# [BE1] Chủ trì rà soát API/dữ liệu — WWHN-180

## 1. Document control

| Thuộc tính | Giá trị |
|---|---|
| Parent gate | `WWHN-40` — G1 API/Data Contract Review |
| Related Jira | `WWHN-181` BA decision; `WWHN-182` QA2 verification; `WWHN-183` FE1/BA closure |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch triển khai | **Chỉ `m1`**; không tạo branch/PR riêng |
| Document version | `1.3.0` |
| Document status | **PROPOSED** |
| BE implementation | **IMPLEMENTED ON M1 — CI EVIDENCE PENDING** |
| Formal approval | **PENDING INDEPENDENT SIGN-OFF** |
| Exact review SHA | Lấy từ `build/evidence/api-data/build-metadata.json.commit_sha` của workflow PASS |
| Passing artifact | `api-data-evidence-<exact_commit_sha>` |
| Failed-run artifact | `api-data-diagnostics-<exact_commit_sha>` — chỉ dùng chẩn đoán |
| Hash authority | `SHA256SUMS` và `SHA256SUMS.sha256` trong passing artifact |

> Không được đổi tài liệu/Jira sang `APPROVED`, `Done` hoặc claim `100%` trước khi BA, FE1 và QA2 cùng xác nhận độc lập trên **cùng exact commit SHA**, cùng workflow run và cùng artifact digest. BE1 self-review hoặc commit code không thay thế independent sign-off.

## 2. Kết quả xử lý vòng này

### 2.1 Các commit trực tiếp trên `m1`

| Commit | Nội dung |
|---|---|
| `9c1b9377ccaf5581152b8c40785a675fc6509f24` | Loại bỏ mật khẩu hard-code khỏi workflow; bắt buộc GitHub Actions secrets runtime và preflight rõ lỗi cấu hình |
| `68470c16344f260645c2af6777155752a6d0dbdb` | Khóa authoritative `stock_document` và `stock_document_line` bằng `lockForUpdate()` ngay tại stock-effect boundary |
| `8550f6f073ca43e4e65f9b04f682c4defac9e55b` | Bổ sung regression test: model `approved` stale không thể tạo balance/movement/posting event |
| `9d00d9386c95bd060cec7a80550e4bba3160acf2` | Thêm static `permission-catalog.json` để FE1/BA review trực tiếp |
| `d01436a0b9b62b5f2ecfed0f62c439c9ee673ef5` | Thêm hướng dẫn cấu hình runtime secrets cho evidence workflow |
| `e89bd20016288a06d227a92793cfc168b8b28210` | Đồng bộ Source Register v1.3.0 với race guard, evidence và external blockers |

Exact SHA cuối để review phải là commit chứa tài liệu này hoặc SHA mới hơn trên `m1`, và phải được workflow PASS đóng dấu trong `build-metadata.json`.

### 2.2 Hai lỗi sâu đã sửa

1. **Credential leakage trong CI:** workflow trước đó commit trực tiếp mật khẩu test, trái với claim “runtime-only credential”. Workflow mới chỉ nhận `WWHN180_PASSWORD` và `WWHN180_NO_PERMISSION_PASSWORD` từ GitHub Actions secrets; thiếu secret thì fail-fast.
2. **Stale model tại stock-effect boundary:** `postReceipt/postIssue` có thể nhận model load trước outer transaction. Dù đã có unique movement và optimistic status guard, stock ledger nay bắt buộc re-read + `lockForUpdate()` current document/current line trước mọi `RECEIPT_IN` hoặc `ISSUE_OUT`. Nếu DB không còn `approved`, trả `409 STATE_CONFLICT` trước khi tạo balance/movement.

## 3. P0/P1/P2 closure matrix

| ID | Sev | Required fix | Repo-side result trên `m1` | Evidence/source | Trạng thái formal |
|---|---|---|---|---|---|
| `BE1-P0-01` | P0 | BA ghi G1 decision; FE1 ký mapping; QA2 attach exact SHA/hash | Giữ status `PROPOSED/PENDING_INDEPENDENT_SIGN_OFF`; có đầy đủ template và closure rules | Mục 10–12; source register; CI closure matrix | **PENDING BA + FE1 + QA2** |
| `BE1-P0-02` | P0 | QA2 verify Scan/Post Receipt/Post Issue trên artifact thật | Atomic idempotency, authoritative posting lock, replay/conflict guards, DB delta/reconcile workflow và regression tests đã có | Code + tests + passing artifact sau CI | **BE IMPLEMENTED; PENDING QA2 RETEST** |
| `BE1-P1-01` | P1 | OpenAPI JSON/YAML + Postman collection/environment hoặc immutable repo URL/hash | Focused OpenAPI YAML và Postman collection/environment đã commit; full JSON/YAML sinh trong artifact | `docs/api-contract/*`; `docs/postman/*`; `SHA256SUMS` | **IMPLEMENTED; PENDING PASS ARTIFACT/JIRA LINK** |
| `BE1-P1-02` | P1 | CI URL, build metadata, route list, raw PHPUnit/Newman, hash | Workflow/collector sinh đủ raw logs, metadata, source-tree digest và manifest | `.github/workflows/wwhn-180-api-posting-contract.yml`; `scripts/collect-wwhn180-evidence.sh` | **IMPLEMENTED; CI RUN NOT OBSERVED** |
| `BE1-P1-03` | P1 | Permission catalog + FE `ROUTE_PERMISSIONS` + reviewer/result/date | Static backend catalog đã commit; runtime catalog sinh từ routes; FE template có exact SHA/FE SHA fields | `docs/evidence/WWHN-180/permission-catalog.json`; `FE1_MAPPING_SIGNOFF.md` | **BACKEND PASS; FE1/BA PENDING** |
| `BE1-P1-04` | P1 | QA2 happy/negative/RBAC/idempotency/double-post/race/reconcile | Full/focused PHPUnit, Newman 23 requests, DB before/after, delta, reconcile và stale-model regression đã wire vào collector | Artifact outputs ở mục 8 | **AUTOMATED; QA2 INDEPENDENT RETEST PENDING** |
| `BE1-P1-05` | P1 | BA G1 minutes: blocker → endpoint/schema/test → reviewer → decision | G1 minutes template và decision register đã chuẩn hóa | `docs/evidence/WWHN-180/G1_MINUTES_TEMPLATE.md`; mục 12 | **PENDING BA** |
| `BE1-P2-01` | P2 | Post closure matrix trực tiếp trên Jira | Payload đọc nhanh có sẵn ở mục 13 và collector tự sinh `jira-closure-comment.txt` | Mục 13; artifact | **BLOCKED: ATLASSIAN 403 / APP NOT INSTALLED** |

## 4. Contract G1 đã khóa

| Action | Endpoint | Permission | Stock effect | Idempotency |
|---|---|---|---|---|
| Classify scan | `POST /api/v1/scan/classify` | `scan.classify` | Read-only classify/resolve | Không áp dụng |
| Scan inbound | `POST /api/v1/inbound-documents/{id}/scan` | `stock_document.scan` | `NONE`; không movement/balance/posting event/document posting status | Không bắt buộc |
| Scan outbound | `POST /api/v1/outbound-documents/{id}/scan` | `stock_document.scan` | `NONE`; chỉ validate available stock | Không bắt buộc |
| Post receipt | `POST /api/v1/inbound-documents/{id}/post-receipt` | `stock_document.post` | Signed movement dương + balance + status/history + posting event, đúng một lần | Bắt buộc `Idempotency-Key`, trim, 8–100 ký tự |
| Post issue | `POST /api/v1/outbound-documents/{id}/post-issue` | `stock_document.post` | Signed movement âm + balance + status/history + posting event, đúng một lần | Bắt buộc `Idempotency-Key`, trim, 8–100 ký tự |
| Reconcile | `GET /api/v1/reports/inventory-reconciliation` | `report.view` | Read-only, balance so với signed ledger | Không áp dụng |

State transitions:

```text
receipt: draft -> pending -> approved -> received
issue:   draft -> pending -> approved -> issued
scan:    approved -> approved; stock_effect=NONE
```

## 5. Idempotency và transaction boundary

### 5.1 Logical identity

```text
(idem_key, operation, user_id)
```

Rules:

- Missing/blank key: `428 PRECONDITION_REQUIRED` trước DB write.
- Key sau trim ngoài 8–100 ký tự: `422 VALIDATION_ERROR` trước DB write.
- Same key + same canonical payload: replay exact persisted response snapshot; callback không chạy lần hai.
- Same key + different payload: `409 IDEMPOTENCY_ERROR`; không stock effect mới.
- Request hash v2: canonical method/path/query/body + SHA-256; object key order không đổi semantic digest.
- Legacy MD5 suffix chỉ dùng compatibility với row cũ; hai v2 hash luôn so canonical SHA-256.
- Lease takeover dùng compare-and-set theo `status/locked_until`, không blind reclaim.
- Business write và response snapshot nằm trong cùng outer transaction.
- Exception sau business write nhưng trước response completion rollback business write và owned lease.
- Timeout phía client sau commit: retry cùng key replay, không hạch toán lần hai.

### 5.2 Authoritative stock-effect lock

Tại `StockLedger::mutate()` cho `RECEIPT_IN`/`ISSUE_OUT`:

```text
lock current stock_documents row
  -> verify current doc_type
  -> verify current status == approved
  -> lock current stock_document_lines row under same document
  -> recompute warehouse/product/qty/business key from locked rows
  -> lock/create balance
  -> version-CAS balance update
  -> append unique movement
```

Không sử dụng model controller load trước đó làm source of truth cho stock effect.

Expected race outcomes:

| Tình huống | API result hợp lệ | DB invariant |
|---|---|---|
| Same key/same payload overlap | `200 + 409 processing` hoặc `200 + replay 200` | Một stock effect |
| Same semantic payload, khác JSON key order | `200 + replay 200` | Callback chạy một lần |
| Same key/different payload | `200 + 409 IDEMPOTENCY_ERROR` | Một stock effect |
| Different keys/cùng document | `200 + 409 STATE_CONFLICT` | Một stock effect |
| Stale `approved` model sau request thắng | `409 STATE_CONFLICT` | Không balance/movement/posting event mới |
| Failure trước snapshot commit | Exception/5xx | Toàn bộ business write rollback |
| Timeout sau DB commit | Retry `200 replay` | Không movement/balance lần hai |

## 6. Scan boundary

`validateScanLine()` chỉ:

- xác nhận document đang `approved`;
- lock line;
- validate `qty > 0` và không vượt remaining;
- với outbound, validate available stock nhưng không trừ tồn;
- update `scanned_qty` trên line;
- trả `stock_effect=NONE`, `movement_created=false`.

Scan không ghi:

- `stock_movements`;
- `inventory_balances`;
- `posting_event`;
- document posting status.

## 7. Reconciliation integrity

Dimension là hợp của:

- `inventory_balances(warehouse_id, product_id, stock_status)`;
- `stock_movements(warehouse_id, product_id, stock_status)`.

Formula:

```text
expected_qty = SUM(stock_movements.qty_delta)
PASS when inventory_balances.on_hand_qty == expected_qty
```

Mismatch reasons:

- `BALANCE_MISSING_FOR_LEDGER`;
- `LEDGER_MISSING_FOR_BALANCE`;
- `BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM`.

Closure yêu cầu `reconciliation-report.json.summary.status=PASS` và `failed=0`.

## 8. Executable evidence package

### 8.1 Source files

| File | Vai trò |
|---|---|
| `docs/api-contract/WWHN-180-posting-openapi.yaml` | Focused executable OpenAPI, 6 critical paths, `x-permission` |
| `docs/postman/WWHN-180 Posting Contract.postman_collection.json` | Newman contract, 23 requests |
| `docs/postman/WWHN-180 Posting CI.postman_environment.json` | Environment không lưu token/password |
| `docs/testing/WWHN-180-posting-test-cases.json` | P0/P1 test matrix |
| `docs/evidence/WWHN-180/permission-catalog.json` | Static route/permission/FE expectation catalog |
| `docs/evidence/WWHN-180/CI_RUNTIME_SECRET_SETUP.md` | Secret names, preflight và manual dispatch procedure |
| `docs/docs_task_jira/WWHN-180-source-register.json` | Source Register v1.3.0 |

### 8.2 Required passing artifact files

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

### 8.3 CI gates

1. PHP syntax toàn backend scope.
2. `git diff --check`.
3. Pint `--test`.
4. npm lint/test/build.
5. Full PHPUnit.
6. Focused atomicity/canonical/concurrency/reconciliation PHPUnit.
7. Full OpenAPI generation và Redocly lint JSON/YAML/focused YAML.
8. Runtime route list và permission catalog integrity PASS.
9. Static contract verifier PASS.
10. Newman failures = 0.
11. DB before/after và delta verifier PASS.
12. Reconciliation PASS, failed = 0.
13. `build-metadata.commit_sha == GITHUB_SHA`.
14. Approval status vẫn `PENDING_INDEPENDENT_SIGN_OFF` trước reviewer sign-off.
15. `SHA256SUMS` và `SHA256SUMS.sha256` verify OK.

## 9. CI credential security

Workflow không còn commit password. Hai secrets bắt buộc:

```text
WWHN180_PASSWORD
WWHN180_NO_PERMISSION_PASSWORD
```

Emails evidence:

```text
manager01@gmail.com
test-no-permission@hoanam.com
```

Nếu thiếu secret, workflow fail-fast tại `Validate runtime-only Newman credentials`. Diagnostics artifact từ run lỗi không được dùng closure.

## 10. Permission/RBAC mapping

| Endpoint | Backend permission | FE key expected | Denied |
|---|---|---|---|
| `POST /api/v1/scan/classify` | `scan.classify` | `scan.classify` | `403 ACCESS_DENIED`/scope 403 |
| `POST /api/v1/inbound-documents/{id}/scan` | `stock_document.scan` | `stock_document.scan` | `403 ACCESS_DENIED` |
| `POST /api/v1/outbound-documents/{id}/scan` | `stock_document.scan` | `stock_document.scan` | `403 ACCESS_DENIED` |
| `POST /api/v1/inbound-documents/{id}/post-receipt` | `stock_document.post` | `stock_document.post` | `403 ACCESS_DENIED` |
| `POST /api/v1/outbound-documents/{id}/post-issue` | `stock_document.post` | `stock_document.post` | `403 ACCESS_DENIED` |
| `GET /api/v1/reports/inventory-reconciliation` | `report.view` | `report.view` | `403 ACCESS_DENIED` |

Backend middleware là enforcement source of truth. FE guard chỉ điều khiển visibility/UX.

FE1 phải điền vào `docs/evidence/WWHN-180/FE1_MAPPING_SIGNOFF.md`:

- FE repository + exact SHA;
- real `ROUTE_PERMISSIONS`/component action source;
- endpoint/payload/error/permission/retry result;
- reviewer/result/date;
- exact BE SHA và artifact digest.

## 11. QA2 independent verification — WWHN-182

QA2 phải test exact commit artifact, không test branch name động.

| Gate | Expected |
|---|---|
| Scan happy | `stock_effect=NONE`; movement/balance/posting event không đổi |
| Scan RBAC/scope/invalid | 403/409/422 đúng contract; stock DB không đổi |
| Receipt happy | `received`; signed movement dương; balance tăng đúng; one posting event |
| Receipt replay | Exact response replay; không stock effect lần hai |
| Semantic order replay | Same semantic JSON khác key order vẫn replay |
| Receipt payload conflict | `409 IDEMPOTENCY_ERROR`; DB không đổi |
| Receipt different-key double-post | `409 STATE_CONFLICT`; DB không đổi |
| Issue happy/replay/conflict | Đối xứng receipt; signed delta âm đúng một lần |
| Missing/blank key | `428 PRECONDITION_REQUIRED`; DB không đổi |
| Key ngoài 8–100 | `422 VALIDATION_ERROR`; DB không đổi |
| Stale model/race | Exactly one stock effect; loser không tạo balance/movement/posting event |
| Atomic rollback | Business write và owned lease rollback |
| DB delta | `wwhn180-db-delta.summary.status=PASS` |
| Reconcile | `summary.status=PASS`, `failed=0` |
| Manifest | Mọi file `SHA256SUMS: OK`; manifest digest OK |

QA2 comment phải gồm exact SHA, workflow URL, artifact name, manifest digest, test counts, DB delta, reconciliation, race result, reviewer/date/result.

## 12. BA G1 minutes — WWHN-40/WWHN-181

BA phải ghi decision register:

1. Scan boundary — classify/validate only; `stock_effect=NONE`.
2. Receipt posting — approved → received; atomic signed increase; exact replay.
3. Issue posting — approved → issued; atomic signed decrease; exact replay.
4. RBAC/data scope — backend enforcement authoritative.
5. DB integrity — balance bằng signed append-only ledger.
6. Evidence binding — same exact SHA, workflow run, artifact digest cho BA/FE1/QA2.

Final decision chỉ một trong:

```text
GO
CONDITIONAL GO
NO-GO
```

Decision phải có owner, date, open blockers và evidence links.

## 13. Jira closure comment — ready to paste

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

Current posting result from this execution:

```text
BLOCKED — Atlassian Rovo HTTP 403: app is not installed on the Jira instance.
```

Không claim Jira comment đã được đăng.

## 14. Definition of Done

- [x] Chỉ sửa trực tiếp trên branch `m1`.
- [x] Commit message bằng tiếng Việt.
- [x] Focused OpenAPI YAML và Postman collection/environment trong repo.
- [x] Runtime permission catalog và static review catalog.
- [x] Idempotency canonical replay/conflict/rollback guards.
- [x] Authoritative `lockForUpdate()` tại receipt/issue stock-effect boundary.
- [x] Regression test stale model không tạo stock effect.
- [x] Workflow loại bỏ committed password và yêu cầu secrets runtime.
- [x] Evidence collector sinh route/raw test/Newman/DB/hash/metadata.
- [ ] GitHub Actions workflow PASS trên exact final `m1` SHA.
- [ ] Download đúng passing artifact; không dùng diagnostics.
- [ ] Verify `SHA256SUMS` và manifest digest.
- [ ] FE1 ký real mapping trên cùng SHA/hash.
- [ ] QA2 attach independent API/DB/race/reconcile evidence.
- [ ] BA ghi G1 minutes và final decision.
- [ ] Jira WWHN-181/182/183 có reviewer/result/date/evidence link.
- [ ] Post closure matrix trực tiếp lên Jira.
- [ ] Chỉ sau các gate trên mới chuyển `APPROVED/DONE`.

## 15. Residual blockers và đánh giá trung thực

| Blocker | Owner | Cách đóng |
|---|---|---|
| Chưa quan sát được workflow run/status cho commit direct qua GitHub App | BE1/Repo admin | Cấu hình secrets và chạy `workflow_dispatch` trên final `m1` SHA |
| Atlassian connector 403/app not installed | Jira admin | Cài/authorize Atlassian app hoặc BA/BE1 paste mục 13 trực tiếp |
| WWHN-182 independent QA evidence | QA2 | Retest passing artifact và comment exact SHA/hash |
| WWHN-183 FE mapping sign-off | FE1 | Review backend catalog với real FE source/commit |
| WWHN-40/WWHN-181 G1 minutes | BA/PM | Ghi decision register và final decision |

Repo-side implementation đã được nâng sâu hơn mức đánh giá 66%, nhưng **formal score 100% chưa thể xác nhận** khi chưa có workflow PASS và independent sign-off. Mục tiêu thực tế sau khi run CI thành công là đưa phần BE repository/evidence readiness lên trên 80%; phần approval vẫn phụ thuộc BA, FE1, QA2 và Jira access.
