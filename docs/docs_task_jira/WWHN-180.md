# [BE1] Chủ trì rà soát API/dữ liệu — WWHN-180

## 1. Kiểm soát tài liệu

| Thuộc tính | Giá trị |
|---|---|
| Parent gate | `WWHN-40` — `[G1] Rà soát contract FE/BE/QA và cổng kiểm soát payload mẫu` |
| Jira liên quan | `WWHN-181` FE1; `WWHN-182` QA2; `WWHN-183` BA/G1 |
| Repository / branch | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` / **`m1`** |
| Architecture baseline | `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` |
| Document version | `1.5.0` |
| Document status | **PROPOSED** |
| BE implementation | **COMPLETE — READY FOR EXACT-SHA CI** |
| Formal approval | **PENDING INDEPENDENT BA + FE1 + QA2 SIGN-OFF** |
| Exact SHA authority | `build/evidence/api-data/build-metadata.json.commit_sha` |
| Passing artifact | `api-data-evidence-<exact_commit_sha>` |
| Hash authority | `SHA256SUMS` và `SHA256SUMS.sha256` trong passing artifact |

> Không tự ghi `APPROVED`, `Done` hoặc `100%`. Approval chỉ hợp lệ khi BA, FE1 và QA2 cùng dẫn chiếu một exact BE SHA, exact FE SHA, workflow URL và verified artifact digest.

## 2. Kết luận kỹ thuật theo Architecture Guide

Implementation giữ đúng các nguyên tắc bắt buộc:

1. Scan/classify chỉ validate và cập nhật scan state; `stock_effect=NONE`.
2. Chỉ Post Receipt, Post Issue và Apply Adjustment được đổi tồn.
3. Stock effect chạy trong transaction; document, line và balance được khóa tại authoritative boundary.
4. Inventory movement là append-only audit source; không sửa/xóa movement đã post.
5. Idempotency snapshot và business write commit/rollback cùng transaction.
6. Backend permission/data scope là authority; FE alias chỉ phục vụ route/action visibility.
7. Reconciliation bắt buộc chứng minh balance bằng signed movement ledger.
8. Error response không lộ secret, bearer token, JWT hoặc stack trace.

## 3. Canonical contract G1

| Action | Endpoint | Backend permission | FE guard/alias | Stock effect | Idempotency |
|---|---|---|---|---|---|
| Classify scan | `POST /api/v1/scan/classify` | `scan.classify` | `scan.classify` | `NONE` | N/A |
| Scan inbound | `POST /api/v1/inbound-documents/{id}/scan` | `stock_document.scan` | `scan.inbound` | `NONE` | N/A |
| Scan outbound | `POST /api/v1/outbound-documents/{id}/scan` | `stock_document.scan` | `scan.outbound` | `NONE` | N/A |
| Post receipt | `POST /api/v1/inbound-documents/{id}/post-receipt` | `stock_document.post` | `inbound.post` | `RECEIPT_IN`, exactly once | Required |
| Post issue | `POST /api/v1/outbound-documents/{id}/post-issue` | `stock_document.post` | `outbound.post` | `ISSUE_OUT`, exactly once | Required |
| Apply adjustment | `POST /api/v1/stocktake/sessions/{id}/apply-adjustment` | `stocktake.adjust` | `stocktake.adjust` | Adjustment, exactly once | Required |
| Warranty TEMP_ONLY | `POST /api/v1/warranty-cases/{id}/temp-only` | `warranty.temp_only` | `warranty.temp_only` | `NONE` | Required |
| Reconcile | `GET /api/v1/reports/inventory-reconciliation` | `report.view` | `report.view` | `NONE` | N/A |

Canonical Hoa Nam giữ baseline một kho tổng; Agency là nơi nhận, không phải warehouse. Transfer/multi-warehouse không được dùng làm canonical flow của G1.

## 4. Transaction, idempotency và race invariants

### 4.1 Idempotency

```text
identity = (trimmed idem_key, operation, user_id)
```

- Missing/blank key: `428 PRECONDITION_REQUIRED`, không có DB write.
- Key ngoài 8–100 ký tự: `422 VALIDATION_ERROR`, không có DB write.
- Same key + same canonical payload: replay exact persisted response.
- Same key + different payload: `409 IDEMPOTENCY_ERROR`.
- Different key + document đã post: `409 STATE_CONFLICT`.
- Canonical request hash dùng SHA-256 v2.
- Failure trước commit rollback stock write, movement, event và owned lease.

### 4.2 Authoritative stock boundary

```text
lock current stock_documents
→ verify current type/status
→ lock current stock_document_lines
→ recompute warehouse/product/qty/business key
→ lock/create inventory balance
→ CAS/version update
→ append unique signed movement
→ write status/history/posting event
→ persist idempotency response snapshot
→ commit
```

Stale Eloquent model không được dùng làm source of truth tại stock-effect boundary.

## 5. Permission mapping

| FE permission | Canonical capability | Role được cấp alias |
|---|---|---|
| `inbound.post` | `stock_document.post` | `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER` |
| `outbound.post` | `stock_document.post` | `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER` |
| `scan.inbound` | `stock_document.scan` | `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER` |
| `scan.outbound` | `stock_document.scan` | `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER` |
| `scan.warranty` | warranty scan action | `WAREHOUSE_MANAGER` |
| `inventory.trace` | inventory/trace route entry | `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER` |
| `stocktake.count` | stocktake route entry | `WAREHOUSE_MANAGER` |
| `warranty.manage` | warranty route entry | `WAREHOUSE_MANAGER` |
| `report.export` | report export action | `WAREHOUSE_MANAGER` |

Guard rails:

- `NO_PERMISSION` phải có alias count bằng `0`.
- API middleware tiếp tục dùng canonical permission.
- Alias không được thay thế backend authorization.
- FE source được đối chiếu read-only tại exact SHA `5cd10ec46021cecd24949075cb4eeefaa1fcc9b2`; đây chưa phải FE1 sign-off.

## 6. Immutable repository handoff

Các artifact nguồn chuẩn đã commit trong `m1` và phải được review tại exact release SHA:

| Artifact | Repository path |
|---|---|
| Focused OpenAPI YAML | `docs/api-contract/WWHN-180-posting-openapi.yaml` |
| Postman collection | `docs/postman/WWHN-180 Posting Contract.postman_collection.json` |
| Postman environment | `docs/postman/WWHN-180 Posting CI.postman_environment.json` |
| Test matrix | `docs/testing/WWHN-180-posting-test-cases.json` |
| Permission catalog | `docs/evidence/WWHN-180/permission-catalog.json` |
| BE–FE mapping | `docs/evidence/WWHN-180/BE_FE_PERMISSION_MAPPING.json` |
| FE1 sign-off form | `docs/evidence/WWHN-180/FE1_MAPPING_SIGNOFF.md` |
| QA2 protocol | `docs/evidence/WWHN-180/QA2_RETEST_PROTOCOL.md` |
| G1 minutes template | `docs/evidence/WWHN-180/G1_MINUTES_TEMPLATE.md` |
| Source register | `docs/docs_task_jira/WWHN-180-source-register.json` |
| CI workflow | `.github/workflows/wwhn-180-api-posting-contract.yml` |

Immutable URL pattern:

```text
https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/blob/<EXACT_SHA>/<PATH>
```

Không dùng branch URL động hoặc local path `C:/...` làm evidence nghiệm thu.

## 7. Required passing evidence package

Workflow `WWHN-180 API Data Contract Evidence Gate` chạy khi push vào `m1` và phải sinh:

```text
build-metadata.json
source-tree.sha256
SHA256SUMS
SHA256SUMS.sha256
route-list.raw.json
permission-catalog.json
permission-catalog.static.json
be-fe-permission-mapping.json
credential-hygiene.json
openapi.json
openapi.yaml
wwhn180-openapi.yaml
wwhn180.postman.collection.json
wwhn180.postman.environment.json
wwhn180-source-register.json
wwhn180-test-cases.json
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
wwhn180-contract-verification.json
g1-closure-matrix.json
jira-closure-comment.txt
ci-summary.md
```

Hard gates:

- Full PHPUnit và focused atomicity/canonical/concurrency/RBAC/reconcile tests PASS.
- Newman failure count bằng `0`.
- DB delta và reconciliation status bằng `PASS`, failed/mismatch bằng `0`.
- Runtime/static permission catalog integrity bằng `PASS`.
- Credential hygiene residual password/token/JWT bằng `0`.
- `build-metadata.commit_sha == GITHUB_SHA`.
- Approval metadata vẫn `PROPOSED/PENDING_INDEPENDENT_SIGN_OFF`.
- `sha256sum --check SHA256SUMS` và `SHA256SUMS.sha256` đều PASS.

## 8. P0/P1/P2 closure matrix v1.5.0

| ID | Kết quả BE/repository | Evidence | Cổng formal còn lại |
|---|---|---|---|
| `BE1-P0-01` | Contract, exact-SHA rule và sign-off forms đã khóa | Mục 3, 6, 9–11 | BA decision + FE1 mapping + QA2 evidence cùng SHA/digest |
| `BE1-P0-02` | Stock boundary, idempotency, race, DB delta và reconcile đã được tự động hóa | Tests + workflow + artifact package | QA2 independent retest trên passing artifact |
| `BE1-P1-01` | OpenAPI/Postman/env/test cases đã commit tại immutable paths | Mục 6 | Jira comment dẫn exact SHA + digest; artifact PASS |
| `BE1-P1-02` | CI tự sinh ephemeral credential, raw logs, route list, DB delta và manifests | Mục 7 | Quan sát workflow PASS và ghi workflow URL |
| `BE1-P1-03` | Permission catalog, exact FE source mapping, safe aliases và NO_PERMISSION regression đã có | Mục 5–6 | FE1 + BA reviewer/result/date |
| `BE1-P1-04` | Happy/negative/RBAC/idempotency/double-post/race/reconcile test protocol đã executable | Mục 7 và 9 | QA2 comment/attachment độc lập |
| `BE1-P1-05` | G1 minutes template và decision inputs đã đầy đủ | Mục 10 | BA ghi GO/CONDITIONAL_GO/NO_GO tại WWHN-40/WWHN-183 |
| `BE1-P2-01` | Closure matrix đọc nhanh đã post tại Jira comment `14516`; bản này cập nhật theo final SHA | Mục 8 và 11 | Cập nhật Jira sau workflow PASS |

## 9. QA2 independent retest — WWHN-182

QA2 phải checkout exact SHA hoặc tải exact artifact, không test branch name động.

| Gate | Expected result |
|---|---|
| Scan | `stock_effect=NONE`; movement/balance/event/posting status không đổi |
| Receipt/Issue happy | Signed movement và balance đúng một lần |
| Same-key replay | Exact response replay; không tạo effect lần hai |
| Same key/different payload | `409 IDEMPOTENCY_ERROR`; DB không đổi |
| Different key/double post | `409 STATE_CONFLICT`; chỉ một stock effect |
| Missing/invalid key | 428/422; DB không đổi |
| Race/stale model | Exactly one winner; loser không tạo movement/balance effect |
| RBAC | 403; `NO_PERMISSION` không có alias, không có DB write |
| DB delta | `summary.status=PASS`, `failed=0` |
| Reconciliation | `summary.status=PASS`, `mismatch=0`, `failed=0` |
| Hygiene | residual credential/JWT bằng `0` |
| Manifest | Mọi checksum verify PASS |

QA2 comment bắt buộc có reviewer, date, exact SHA, workflow URL, artifact name, manifest digest, test count, race result, DB delta và reconciliation result.

## 10. BA/FE1 sign-off

### FE1

FE1 xác nhận endpoint/method/path, payload/response/error, canonical permission/alias, route alias stocktake và retry/idempotency behavior trên exact BE + FE SHA. Comment phải có reviewer/result/date/evidence URL.

### BA G1

BA ghi một quyết định:

```text
GO
CONDITIONAL_GO
NO_GO
```

Minutes phải chứa blocker → endpoint/schema/rule → test/evidence → reviewer → result → owner/date/disposition.

## 11. Jira closure payload sau workflow PASS

```text
WWHN-180 API/DATA CLOSURE MATRIX — v1.5.0
Branch: m1; direct commit; không tạo branch/PR riêng
Exact BE SHA: <FINAL_SHA>
Commit URL: https://github.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/commit/<FINAL_SHA>
Exact FE SHA observed: 5cd10ec46021cecd24949075cb4eeefaa1fcc9b2
Workflow URL: <PASSING_WORKFLOW_URL>
Artifact: api-data-evidence-<FINAL_SHA>
SHA256SUMS digest: <VERIFIED_DIGEST>
OpenAPI/Postman: immutable repo paths + passing artifact
Permission mapping: BE complete; FE1/BA independent review result required
QA2: independent API/DB/race/reconcile result required
Formal status: PROPOSED / PENDING_INDEPENDENT_SIGN_OFF
```

## 12. Definition of Done

### BE/repository scope

- [x] Chỉ sửa nhánh `m1`.
- [x] Canonical stock-effect boundary, transaction, lock, idempotency và append-only movement được bảo toàn.
- [x] Scan không đổi tồn.
- [x] OpenAPI/Postman/test cases/permission catalog/source register đã commit.
- [x] CI evidence gate tự chứa ephemeral credential; không phụ thuộc repository secret.
- [x] Raw PHPUnit/Newman/route/DB/reconcile/hash outputs được bắt buộc trong artifact.
- [x] Credential/JWT redaction là hard gate.
- [x] Permission alias không thay canonical middleware; NO_PERMISSION regression có test.
- [x] Closure matrix đã cập nhật.

### External/formal gates

- [ ] Workflow PASS trên exact final SHA.
- [ ] `SHA256SUMS` và `SHA256SUMS.sha256` verify PASS.
- [ ] FE1 reviewer/result/date trên exact BE + FE SHA.
- [ ] QA2 independent API/DB/race/reconcile evidence.
- [ ] BA G1 minutes và final decision.
- [ ] WWHN-181/182/183 cùng dẫn chiếu exact SHA/artifact digest.
- [ ] Chỉ sau toàn bộ các gate trên mới chuyển `APPROVED/Done/100%`.

## 13. Residual ownership

| Gate | Owner | Cách đóng |
|---|---|---|
| Passing CI artifact | BE1 / Repo CI | Workflow PASS trên commit này; verify manifest |
| FE permission mapping | FE1 + BA | Review exact BE/FE SHA và ghi result/date |
| QA independent retest | QA2 | Chạy protocol WWHN-182 và attach/comment raw evidence |
| G1 minutes/decision | BA/PM | Ghi decision trên WWHN-40 và WWHN-183 |
| Final approval | BA/PM | Chỉ sau khi mọi P0/P1 external gate PASS |

**Kết luận:** phần BE/repository đã hoàn thiện sâu và sẵn sàng cho exact-SHA CI. Mọi claim `100%` trước independent sign-off sẽ là sai quy trình nghiệm thu.