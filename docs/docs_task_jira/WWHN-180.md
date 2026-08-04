# [BE1] Chủ trì rà soát API/dữ liệu — WWHN-180

## 1. Document control

| Thuộc tính | Giá trị |
|---|---|
| Parent gate | `WWHN-40` — `[G1] Rà soát contract FE/BE/QA và cổng kiểm soát payload mẫu` |
| Related Jira | `WWHN-181` FE1; `WWHN-182` QA2; `WWHN-183` BA/G1 |
| Parent Temp Key | `HN-INT-001` |
| Epic Temp Key | `HN-EPIC-03` |
| Role / Assignee Placeholder | `BE1` / `HN-BE-01` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | **Chỉ `m1`**, direct update, không tạo branch/PR riêng |
| Parent commit trước release này | `f3b225ec0f5374c9c3d2b395fb59efbcfc601390` |
| Document version | `1.4.0` |
| Document status | **PROPOSED** |
| BE implementation | **READY FOR EXACT-SHA CI + INDEPENDENT REVIEW** |
| Formal approval | **PENDING BA + FE1 + QA2 INDEPENDENT SIGN-OFF** |
| Exact release SHA | `build/evidence/api-data/build-metadata.json.commit_sha` của workflow PASS |
| Passing artifact | `api-data-evidence-<exact_commit_sha>` |
| Hash authority | `SHA256SUMS` + `SHA256SUMS.sha256` trong passing artifact |

> BE1 không tự ghi `APPROVED`, `Done` hoặc `100%`. Final approval chỉ hợp lệ khi BA, FE1 và QA2 cùng dẫn chiếu **một exact BE SHA**, exact FE SHA, cùng workflow run và verified artifact digest.

## 2. Kết quả xử lý vòng 1.4.0

### 2.1 Lỗi thực tế đã phát hiện và xử lý

1. **CI phụ thuộc repository secrets:** workflow cũ dừng nếu thiếu `WWHN180_PASSWORD` và `WWHN180_NO_PERMISSION_PASSWORD`, nên evidence gate không tự chạy được.
   - Workflow mới sinh password và JWT secret ngẫu nhiên cho từng run.
   - `AuthDatabaseSeeder` nhận password qua `CI_DEMO_PASSWORD`.
   - Không cần repository secret.
   - Password/token được redact trước khi hash/upload artifact.
   - `credential-hygiene.json` là hard gate.

2. **Permission mismatch giữa FE và BE:**
   - FE exact SHA `5cd10ec46021cecd24949075cb4eeefaa1fcc9b2` dùng `inbound.post`, `outbound.post`, `scan.inbound`, `scan.outbound`.
   - BE route middleware dùng canonical `stock_document.post`, `stock_document.scan`.
   - Đã thêm compatibility permission seed có kiểm soát; chỉ role đã có canonical capability mới nhận alias.
   - Middleware API vẫn giữ canonical permission, không thay bằng alias và không nới quyền backend.
   - Có regression test bảo đảm `NO_PERMISSION` không nhận alias.

3. **Route mismatch stocktake adjustment:**
   - FE gọi `POST /api/v1/stocktake/sessions/{id}/apply-adjustment`.
   - BE trước đó chỉ có `POST /api/v1/stocktake-sessions/{id}/apply-adjustment`.
   - Đã bổ sung route alias FE, dùng cùng `StocktakeController@applyAdjustment` và `permission:stocktake.adjust`.
   - Route cũ được giữ để bảo toàn backward compatibility.

4. **Evidence artifact có nguy cơ chứa runtime credential/token:**
   - Đã thêm redactor quét toàn bộ text artifact.
   - Runtime password, Bearer token và JWT bị thay bằng marker redacted trước `SHA256SUMS`.
   - Workflow fail nếu còn residual secret/JWT.

5. **P1-03 chỉ có catalog BE, chưa có source mapping FE thật:**
   - Đã tạo `BE_FE_PERMISSION_MAPPING.json`.
   - Mapping khóa FE repo, branch, exact SHA và baseline blob SHA.
   - Phân biệt rõ source observation của BE1 với independent FE1 sign-off.

### 2.2 Các file chính của release

| File | Vai trò |
|---|---|
| `.github/workflows/wwhn-180-api-posting-contract.yml` | Exact-SHA evidence gate tự chứa credential runtime |
| `scripts/collect-wwhn180-evidence.sh` | Thu full/focused test, OpenAPI, Newman, DB delta, reconcile, manifest |
| `scripts/redact-wwhn180-evidence.mjs` | Redact runtime credential/JWT và sinh hygiene report |
| `Modules/Auth/database/seeders/WmsPermissionCompatibilitySeeder.php` | Compatibility permission không thay canonical middleware |
| `database/seeders/DatabaseSeeder.php` | Đăng ký compatibility seeder sau Auth baseline |
| `Modules/Warehouse/routes/api.php` | Route alias stocktake apply-adjustment cho FE |
| `tests/Feature/Wwhn180PermissionAliasContractTest.php` | Regression permission alias, NO_PERMISSION và canonical middleware |
| `docs/evidence/WWHN-180/BE_FE_PERMISSION_MAPPING.json` | Mapping BE ↔ FE exact SHA |
| `docs/evidence/WWHN-180/permission-catalog.json` | Static permission catalog có FE observed source |
| `docs/testing/WWHN-180-posting-test-cases.json` | Test matrix P0/P1 v1.3.0 |
| `docs/docs_task_jira/WWHN-180-source-register.json` | Source/evidence register v1.4.0 |

## 3. P0/P1/P2 closure matrix

| ID | Sev | Required fix | Kết quả BE/repo v1.4.0 | Formal status |
|---|---|---|---|---|
| `BE1-P0-01` | P0 | BA decision, FE1 mapping, QA2 evidence cùng exact SHA/hash | Exact-SHA/artifact rules, FE exact source, sign-off templates và Jira matrix đã khóa | **READY; PENDING BA + FE1 + QA2** |
| `BE1-P0-02` | P0 | QA2 verify stock effect, idempotency, race, DB before/after, reconcile | Full/focused PHPUnit, Newman, DB delta, reconcile, race/atomicity source và immutable artifact pipeline đã wire | **BE CLOSED; PENDING QA2 INDEPENDENT RETEST** |
| `BE1-P1-01` | P1 | OpenAPI JSON/YAML + Postman collection/env hoặc immutable URL/SHA/digest | Focused OpenAPI và Postman commit trực tiếp; generated OpenAPI JSON/YAML + checksums trong artifact | **REPO CLOSED; CI ARTIFACT VERIFICATION REQUIRED** |
| `BE1-P1-02` | P1 | CI URL, metadata, raw logs, route list, DB delta, SHA256SUMS | Workflow tự chứa credential, không cần repo secret; artifact gồm đầy đủ raw/structured/hash evidence | **IMPLEMENTED; PASS RUN MUST BE OBSERVED** |
| `BE1-P1-03` | P1 | Permission catalog + FE/BA mapping | Đã đối chiếu FE exact SHA, tạo mapping, compatibility aliases và regression test; middleware BE vẫn canonical | **BE CLOSED; FE1/BA REVIEW PENDING** |
| `BE1-P1-04` | P1 | QA2 happy/negative/RBAC/idempotency/double-post/race/reconcile | Test matrix 32 cases, full/focused logs, Newman 23 request, DB delta/reconcile và hygiene hard gate | **AUTOMATED; QA2 EXECUTION PENDING** |
| `BE1-P1-05` | P1 | BA G1 minutes và decision register | G1 template + decision inputs + exact SHA/hash rules đã chuẩn hóa | **READY; BA ACTION PENDING** |
| `BE1-P2-01` | P2 | Closure matrix đọc nhanh trực tiếp trên Jira | Mục 11 là payload Jira; phải post sau final commit để gắn exact SHA | **READY TO POST** |

## 4. Contract G1 được bảo toàn

| Action | Endpoint | Backend permission | FE permission/guard | Stock effect | Idempotency |
|---|---|---|---|---|---|
| Classify scan | `POST /api/v1/scan/classify` | `scan.classify` | `scan.classify` | `NONE` | N/A |
| Scan inbound | `POST /api/v1/inbound-documents/{id}/scan` | `stock_document.scan` | `scan.inbound` alias | `NONE` | N/A |
| Scan outbound | `POST /api/v1/outbound-documents/{id}/scan` | `stock_document.scan` | `scan.outbound` alias | `NONE` | N/A |
| Post receipt | `POST /api/v1/inbound-documents/{id}/post-receipt` | `stock_document.post` | `inbound.post` alias | signed `RECEIPT_IN`, exactly once | Required |
| Post issue | `POST /api/v1/outbound-documents/{id}/post-issue` | `stock_document.post` | `outbound.post` alias | signed `ISSUE_OUT`, exactly once | Required |
| Apply adjustment | `POST /api/v1/stocktake/sessions/{id}/apply-adjustment` | `stocktake.adjust` | `stocktake.adjust` | adjustment, exactly once | Required by controller contract |
| Warranty TEMP_ONLY | `POST /api/v1/warranty-cases/{id}/temp-only` | `warranty.temp_only` | `warranty.temp_only` | `NONE` | Required |
| Reconcile | `GET /api/v1/reports/inventory-reconciliation` | `report.view` | `report.view` | `NONE` | N/A |

Compatibility aliases chỉ phục vụ FE route/action visibility. Backend vẫn trả `403` theo canonical middleware và data scope.

## 5. Transaction, idempotency và race invariants

### 5.1 Idempotency identity

```text
(idem_key, operation, user_id)
```

- Missing/blank key: `428 PRECONDITION_REQUIRED`, trước business write.
- Trimmed key ngoài 8–100 ký tự: `422 VALIDATION_ERROR`.
- Same key + same canonical payload: replay persisted response snapshot.
- Same key + different payload: `409 IDEMPOTENCY_ERROR`.
- Different key + document đã post: `409 STATE_CONFLICT`.
- Canonical request hash dùng SHA-256 v2; JSON object-key order không đổi semantic digest.
- Business write và response snapshot cùng outer transaction.
- Failure trước commit rollback cả stock write và owned lease.
- Retry sau client timeout replay snapshot, không hạch toán lần hai.

### 5.2 Authoritative stock-effect boundary

```text
lock current stock_documents row
→ verify current doc_type/status=approved
→ lock current stock_document_lines
→ recompute warehouse/product/qty/business key
→ lock/create inventory balance
→ CAS/version update
→ append unique stock movement
→ status/history/posting event
→ persist response snapshot
→ commit
```

Model `approved` đã stale không được dùng làm source of truth tại stock-effect boundary.

### 5.3 Scan boundary

Scan chỉ update scan state sau validation. Scan không được tạo hoặc sửa:

- `stock_movements`;
- `inventory_balances`;
- posting event;
- posting status của document.

## 6. Permission compatibility design

### 6.1 Alias assignments

| FE permission | Canonical capability | Role nhận alias |
|---|---|---|
| `inbound.post` | `stock_document.post` | `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER` |
| `outbound.post` | `stock_document.post` | `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER` |
| `scan.inbound` | `stock_document.scan` | `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER` |
| `scan.outbound` | `stock_document.scan` | `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER` |
| `scan.warranty` | warranty/scan action | `WAREHOUSE_MANAGER` |
| `inventory.trace` | inventory/scan route entry | `WAREHOUSE_KEEPER`, `WAREHOUSE_MANAGER` |
| `stocktake.count` | `stocktake.adjust` route entry | `WAREHOUSE_MANAGER` |
| `warranty.manage` | `warranty.temp_only` route entry | `WAREHOUSE_MANAGER` |
| `report.export` | narrower FE export guard | `WAREHOUSE_MANAGER` |

### 6.2 Guard rails

- `NO_PERMISSION` alias count phải bằng `0`.
- Alias không xuất hiện trong API route middleware.
- Role không có canonical capability không được nhận alias.
- Runtime catalog và static catalog đều phải `integrity.status=PASS`.
- FE source được khóa tại exact SHA `5cd10ec46021cecd24949075cb4eeefaa1fcc9b2`.

## 7. Executable evidence package

Passing artifact phải có tối thiểu:

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
wwhn180-contract-verification.json
wwhn180-db-delta.json
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
reconciliation-report.json
source/*
```

## 8. CI gates

1. PHP syntax toàn scope.
2. `git diff --check`.
3. Pint `--test`.
4. npm lint/test/build.
5. Full PHPUnit.
6. Focused atomicity/canonical/concurrency/permission-alias/reconciliation PHPUnit.
7. OpenAPI generation + Redocly lint.
8. Runtime/static permission catalog integrity.
9. BE-FE exact-SHA mapping integrity.
10. Newman failure count bằng `0`.
11. DB delta PASS.
12. Reconciliation PASS, failed/mismatch bằng `0`.
13. Credential hygiene PASS, không còn runtime secret/JWT.
14. `build-metadata.commit_sha == GITHUB_SHA`.
15. Approval metadata vẫn `PROPOSED/PENDING_INDEPENDENT_SIGN_OFF`.
16. `SHA256SUMS` và `SHA256SUMS.sha256` verify OK.

## 9. QA2 independent retest — WWHN-182

QA2 phải dùng exact SHA/artifact, không test branch name động.

| Gate | Expected |
|---|---|
| Scan | `stock_effect=NONE`; movement/balance/posting event/status không đổi |
| Receipt/Issue happy | Signed movement và balance đúng một lần |
| Replay | Exact response/movement replay, không callback lần hai |
| Same key/different payload | `409 IDEMPOTENCY_ERROR`, DB không đổi |
| Different key/double post | `409 STATE_CONFLICT`, một stock effect |
| Missing/invalid key | 428/422, DB không đổi |
| Race/stale model | Exactly one winner; loser không tạo stock effect |
| RBAC | 403; `NO_PERMISSION` không có alias và không có DB write |
| Route alias | FE stocktake path match cùng controller/middleware canonical |
| DB delta | `wwhn180-db-delta.summary.status=PASS` |
| Reconcile | `summary.status=PASS`, mismatch/failed=0 |
| Hygiene | residual secret/JWT=0 |
| Manifest | Mọi checksum verify PASS |

QA2 comment phải có reviewer, date, exact SHA, workflow URL, artifact name, manifest digest, test count, DB delta, race result và reconciliation result.

## 10. BA/FE1 sign-off input

### 10.1 FE1

FE1 xác nhận trên exact BE SHA và FE SHA:

- endpoint/method/path;
- payload/response/error;
- canonical BE permission và FE alias;
- route alias stocktake;
- retry/idempotency behavior;
- result/reviewer/date/evidence URL.

### 10.2 BA G1

BA ghi một final decision:

```text
GO
CONDITIONAL_GO
NO_GO
```

Decision phải có blocker → endpoint/schema/rule → test/evidence → reviewer → result → owner/date/disposition.

## 11. Jira closure comment — post sau final commit

```text
[WWHN-180 API/DATA CLOSURE MATRIX — v1.4.0]
Branch: m1 — direct update, no separate PR
Exact BE SHA: <final commit SHA>
Commit URL: <commit URL>
Exact FE SHA observed: 5cd10ec46021cecd24949075cb4eeefaa1fcc9b2
FE baseline blob: 86cc8f024b1f520ebb355eb086f453d0438d3de1
CI run: <workflow URL or PENDING_NOT_OBSERVED>
Artifact: api-data-evidence-<exact SHA>
SHA256SUMS digest: <digest or PENDING_CI>
Credential hygiene: <PASS/PENDING>
OpenAPI/Postman: committed + artifact-bound
Permission mapping: BE compatibility implemented; FE1/BA review pending

BE1-P0-01: READY — pending BA+FE1+QA2 same SHA/digest
BE1-P0-02: BE CLOSED — pending QA2 independent retest
BE1-P1-01: REPO CLOSED — pending passing artifact verification
BE1-P1-02: IMPLEMENTED — self-contained CI, no repository secret
BE1-P1-03: BE CLOSED — exact FE source mapping + safe aliases + route regression
BE1-P1-04: AUTOMATED — pending QA2 execution/comment
BE1-P1-05: READY — pending BA G1 minutes
BE1-P2-01: PASS — this readable matrix is posted directly

WWHN-181: FE exact source observed; independent final sign-off pending
WWHN-182: QA2 independent evidence pending
WWHN-183/WWHN-40: BA G1 decision pending
Formal status: PROPOSED / PENDING_INDEPENDENT_SIGN_OFF
```

## 12. Definition of Done và trạng thái trung thực

### 12.1 BE/repository scope

- [x] Chỉ sửa branch `m1`.
- [x] Một release commit bằng tiếng Việt.
- [x] Bảo toàn canonical stock-effect/idempotency/reconciliation.
- [x] OpenAPI/Postman/test cases/source register trong repo.
- [x] CI không phụ thuộc repository credential secret.
- [x] Artifact credential/JWT redaction hard gate.
- [x] Exact FE SHA mapping.
- [x] Compatibility aliases không thay canonical middleware.
- [x] Stocktake FE path alias.
- [x] Regression test alias/NO_PERMISSION/middleware.
- [x] Jira closure matrix sẵn sàng post.

### 12.2 External/formal gates

- [ ] Workflow PASS trên exact final SHA.
- [ ] Verify và ghi `SHA256SUMS.sha256`.
- [ ] FE1 reviewer/result/date trên exact BE+FE SHA.
- [ ] QA2 independent API/DB/race/reconcile evidence.
- [ ] BA G1 minutes và final decision.
- [ ] WWHN-181/182/183 cùng dẫn chiếu exact SHA/artifact digest.
- [ ] Chỉ sau đó mới chuyển `APPROVED/Done/100%`.

## 13. Residual ownership

| Gate | Owner | Cách đóng |
|---|---|---|
| Passing CI artifact | BE1 / Repo CI | Workflow PASS trên final commit; verify manifests |
| FE mapping sign-off | FE1 + BA | Review mapping/runtime trên exact BE+FE SHA |
| QA independent retest | QA2 | Execute protocol và attach/comment raw evidence |
| G1 minutes/decision | BA/PM | Ghi decision register trên WWHN-40/WWHN-183 |
| Final approval | BA/PM | Chỉ khi P0/P1 external gates đều PASS |

**Kết luận:** phạm vi BE/repository được chuẩn bị để đóng sâu các finding P0/P1/P2. Formal score `100%` không được claim trước independent sign-off; đây là kiểm soát chất lượng, không phải thiếu code.
