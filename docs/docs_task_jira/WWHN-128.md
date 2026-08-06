# WWHN-128 — DEC-INV-001: Nguồn dữ liệu chuẩn của tồn kho

## 0. Kiểm soát tài liệu

| Trường | Giá trị |
|---|---|
| Ticket / Task cha | `WWHN-128` / `WWHN-24` |
| Decision ID | `DEC-INV-001` |
| Phiên bản | `1.7-R7` |
| Ngày cập nhật | `2026-08-06` |
| Repository / branch | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` / `m1` |
| Head trước R7 | `14a271eea1c2714a5e8fc54b4857af37f6b128ad` |
| R6 commit | `7d7091c48798114b5bbf7ff4c0b58617d177c737` |
| R7 release commit | Ghi exact SHA trên Jira sau một commit duy nhất; không tự nhúng SHA vòng lặp |
| Trạng thái | **REPO CONTROLS COMPLETE — EXTERNAL REVIEW / FORMAL APPROVAL PENDING** |

> **NOT APPROVED / NOT BASELINED / NOT EFFECTIVE.** Tài liệu không tự nhận
> `QA PASS` hoặc `DONE`. CI chỉ cung cấp technical evidence; BA, BAS, Tech Lead,
> QA/FE và PM/Approver phải disposition bằng chính tài khoản của vai trò đó.

## 1. Kết luận audit kỹ thuật

Đã đọc và đối chiếu `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` với
implementation R6. Không phát hiện P0 kỹ thuật mới thuộc riêng DEC-INV-001.

Các invariant được giữ:

1. Backend enforce RBAC/data scope; FE không phải security boundary.
2. Operation đổi tồn có transaction, lock/concurrency guard và idempotency.
3. `stock_movements` là authoritative append-only ledger.
4. `inventory_balances` là projection/read model có thể reconcile/rebuild.
5. Reconciliation theo `(warehouse_id, product_id, stock_status)` và dùng signed
   `SUM(qty_delta)`, không đảo dấu lần hai theo `movement_type`.
6. PostgreSQL là target database; evidence phải khai đúng engine thực chạy.
7. Error contract không lộ secret/stack trace; evidence khóa exact SHA/run/artifact/checksum.

R7 không sửa runtime transaction/ledger vì không có finding kỹ thuật mới yêu cầu
thay đổi. Cách này giảm nguy cơ phát sinh regression nghiệp vụ.

## 2. Quyết định cốt lõi

### 2.1 Inventory authority

- `stock_movements` ghi biến động số lượng đã post và không sửa/xóa.
- `inventory_balances` tối ưu truy vấn; mismatch phải được phát hiện qua reconciliation.
- Serial/item/container là physical subledger, không thay thế ledger số lượng.
- Sửa sai bằng reversal/adjustment có reference/audit, không mutate movement cũ.

### 2.2 Warranty status-only

- không tạo `stock_movements` hoặc cập nhật `inventory_balances`;
- audit tại `warranty_events`, không tạo zero movement;
- response `stock_effect=NONE`, `movement_created=false`;
- chỉ serial `ISSUED` hoặc `RETURNED_TO_CUSTOMER` đủ điều kiện;
- `IN_STOCK` trả `422 WARRANTY_ITEM_NOT_ELIGIBLE`, `required_action=POST_ISSUE`;
- tối đa một warranty case chưa `CLOSED`/`CANCELLED` cho cùng serial.

### 2.3 Idempotency

- `Idempotency-Key` trim length `8–100`; missing `428`, invalid boundary `422`.
- Scope `(idem_key, operation, user_id)`, operation chứa aggregate ID.
- Cùng key/scope/payload replay exact committed 2xx snapshot.
- Cùng key/scope, payload khác trả `409 IDEMPOTENCY_ERROR`.
- Callback và response snapshot trong cùng outer transaction.
- Lease takeover dùng conditional ownership; request thua race không chạy callback.

## 3. Audit map implementation

| Artifact | Git blob tại R6 | Control |
|---|---|---|
| `app/Support/IdempotencyKeyGuard.php` | `f0ea48754fbab4c9b09cc522b7f59a864e924161` | Shared 428/422, boundary 8–100 |
| `app/Support/IdempotencyStore.php` | `78e45f98f799da90d6170dfb6259ce23263383b3` | Atomic callback/snapshot, canonical hash, lease ownership |
| `Modules/Warehouse/app/Http/Controllers/WarrantyController.php` | `dbafecd8b606dafa7c5ca3c83b9b4ee840160fa2` | Status-only, eligibility, active-case row lock |
| `Modules/Warehouse/app/Http/Controllers/ReportController.php` | `91612835ee00192fdd36590c403c1d1363fd8edc` | Signed sum, union dimensions, mismatch detection |
| `database/migrations/2026_01_01_000010_create_idempotency_keys_table.php` | `915dd4bbee58e3871c6272b6ebc3680a0605fd2a` | Unique `(idem_key, operation, user_id)` |
| `...000034_create_inventory_balances_table.php` | `77b283a1684d12c3b00dd87e58c55e056cedab71` | Unique warehouse/product/status |
| `...000035_create_stock_movements_table.php` | `c9d47f3b7c239d220a326b8f53be3179629ee8b7` | Unique business key, append-only ledger schema |

## 4. CI/evidence truth

### 4.1 Verified R5 baseline

| Trường | Giá trị |
|---|---|
| Commit / run / job | `f3b225ec0f5374c9c3d2b395fb59efbcfc601390` / `30876196296` / `91888041476` |
| Artifact | `8879619822` / `wwhn-128-evidence-f3b225ec0f5374c9c3d2b395fb59efbcfc601390` |
| Digest | `sha256:a5d37aad06ffb47edb381c26b9a12cb56cce36108336d100a262614149c3094a` |
| Result | `17 tests / 99 assertions / 0 failures / 0 errors / 0 skipped` |
| Engine | `sqlite` isolated CI; không phải PostgreSQL/UAT |

### 4.2 R6 billing blocker — exact observation

| Trường | Giá trị |
|---|---|
| Commit | `7d7091c48798114b5bbf7ff4c0b58617d177c737` |
| Run / attempt / job | `31082380025` / `2` / `92570314627` |
| Runner/steps | runner không được cấp; `steps=[]` |
| Artifact count | `0` |
| Classification | `GITHUB_BILLING_OR_SPENDING_LIMIT_PRE_RUNNER_BLOCKER` |
| Code/test failure | **Không được kết luận**; test chưa chạy |

Owner: DevOps/GitHub organization billing admin. Required action: xử lý Billing &
plans/spending limit rồi rerun exact failed run. Không tạo commit giả chỉ để đổi run ID.

### 4.3 R7 artifact contract

Workflow `.github/workflows/wwhn-128-inventory-decision-contract.yml` bắt buộc sinh:

- exact commit/run/attempt/build metadata;
- JUnit, raw PHPUnit log và parsed summary;
- DB before/after, schema/index verification, signed reconciliation report;
- route list, commit stat, changed files, source-tree digest;
- Decision, Source Register, Closure Register, Downstream Gate, review protocol;
- `closure-verification.json`, review ledger không self-sign;
- `SHA256SUMS`, `SHA256SUMS.sha256` và immutable artifact theo exact SHA.

Verifier: `scripts/verify_wwhn128_closure.py`. Pipeline khai `sqlite`; QA1/Tech Lead
có thể yêu cầu PostgreSQL/UAT và dùng `PASS WITH CONDITIONS` cho đến khi đủ.

## 5. Current Jira/source identity

### 5.1 Attachment hiện hành

| Jira attachment | ID | Size | Exact Jira-byte SHA-256 |
|---|---:|---:|---|
| `WWHN-128.md` | `11647` | `13447` | `PENDING_EXTERNAL_DOWNLOAD` |
| `WWHN-128-source-register.json` | `11646` | `9446` | `PENDING_EXTERNAL_DOWNLOAD` |

Connector không có attachment-byte download. Không claim hash verified từ metadata
hoặc repo bytes. Authorized uploader/reviewer phải tải exact Jira bytes và tính
SHA-256, hoặc PM/BA/BAS ghi `ACCEPTED WITH CONDITIONS` có owner/deadline. Attachment
cũ `11570/11569` không phải approval target hiện hành.

### 5.2 Business sources

| Source | Version | Jira ID | Hash state |
|---|---:|---:|---|
| BRD | `3.12` | `11386` | `ID_VERIFIED_SHA_PENDING_EXTERNAL` |
| SRS/FRD | `1.5` | `11415` | `ID_VERIFIED_SHA_PENDING_EXTERNAL` |
| OpenAPI | `1.1` | `11414` | `ID_VERIFIED_SHA_PENDING_EXTERNAL` |
| BPD | `1.4` | `11419` | `ID_VERIFIED_SHA_PENDING_EXTERNAL` |
| Baseline Register | `2.2.1` | `11545` | `ID_VERIFIED_SHA_PENDING_EXTERNAL` |

## 6. Machine-readable governance package

| File | Mục đích |
|---|---|
| `docs/docs_task_jira/WWHN-128-source-register.json` | Source, implementation, CI và review identity |
| `docs/evidence/WWHN-128/INV_P1_P2_CLOSURE_REGISTER.json` | Finding `INV-*` → control → external gate → evidence |
| `docs/evidence/WWHN-128/DOWNSTREAM_CONTRACT_GATE.json` | WWHN-31/40/180 contract/consumer/G1 gate |
| `docs/evidence/WWHN-128/REVIEW_AND_APPROVAL_PROTOCOL.md` | Template role-owned disposition |
| `scripts/verify_wwhn128_closure.py` | Fail-closed automated verifier |

## 7. Traceability

| Source/Decision | Rule | API/Code | TC/Evidence | Tickets |
|---|---|---|---|---|
| BRD 3.12 / DEC | Warranty status-only, `IN_STOCK` reject | `WarrantyController::tempOnly`, eligibility/row lock | Warranty status/idempotency suites | WWHN-24/31/40/128/129/130/180 |
| OpenAPI 1.1 / DEC | Key 8–100, 428/422, replay/conflict | `IdempotencyKeyGuard`, `IdempotencyStore` | boundary/replay/conflict/race/atomicity | WWHN-31/40/128/180 |
| DEC authority | Signed ledger reconciliation by status | `ReportController::inventoryReconciliation` | signed/status/integrity/evidence suites | WWHN-31/40/128/180 |
| Baseline Register 2.2.1 | No false PASS | exact-SHA workflow + verifier | JUnit/log/DB/schema/reconcile/checksum | WWHN-24/128/129/130 |

## 8. Downstream contract gate — WWHN-31/40/180

Repository paths được ghim trong `DOWNSTREAM_CONTRACT_GATE.json`:

- `docs/api-contract/WWHN-180-posting-openapi.yaml`;
- `docs/postman/WWHN-180 Posting Contract.postman_collection.json`;
- `docs/postman/WWHN-180 Posting CI.postman_environment.json`;
- `docs/testing/WWHN-180-posting-test-cases.json`;
- permission catalog và BE/FE permission mapping.

| Ticket | Trạng thái | Cổng còn lại |
|---|---|---|
| WWHN-31 | `In Progress` | immutable contract identity/hash/changelog + independent publisher/QA |
| WWHN-40 | `In Progress` | BA G1 `GO / CONDITIONAL_GO / NO_GO` |
| WWHN-180 | `In Progress` | FE mapping + QA API/DB/race/reconciliation + BA decision |

Repo artifact không thay independent acceptance. Thiếu hash/reviewer hoặc
self-confirmation giữ gate fail-closed.

## 9. INV P0/P1/P2 Risk & Action Register

P0 hiện tại: **0 finding kỹ thuật trực tiếp** trong register này.

| ID | Repo-side action R7 | Gate còn lại | Trạng thái |
|---|---|---|---|
| `INV-P1-01` | Formal decision template và exact identity fields | PM/Approver WWHN-24 | `PENDING_FORMAL_DECISION` |
| `INV-P1-02` | Exact run/attempt/job và pre-runner classification | DevOps/org admin fix billing + rerun | `BLOCKED_BY_GITHUB_BILLING` |
| `INV-P1-03` | Artifact/JUnit/log/DB/schema/reconcile/dual checksum gate | Successful run + QA1 | `PENDING_SUCCESSFUL_RUN_AND_QA1` |
| `INV-P1-04` | BA finding-by-finding template | BA WWHN-129 | `PENDING_BA_DISPOSITION` |
| `INV-P1-05` | Machine-readable trace chain | BAS WWHN-130 | `PENDING_BAS_SIGN_OFF` |
| `INV-P1-06` | Tech Lead scope template | Tech Lead exact commit/artifact | `PENDING_TECH_LEAD_REVIEW` |
| `INV-P1-07` | Current IDs/size/accepted-condition policy | exact byte hash hoặc owner/deadline condition | `PENDING_BYTE_HASH_DISPOSITION` |
| `INV-P1-08` | Downstream contract gate + path verification | WWHN-31/40/180 review | `PENDING_DOWNSTREAM_DISPOSITIONS` |
| `INV-P2-01` | Canonical comments/exclusion được máy kiểm | Không | `CLOSED_REPO_SIDE` |

## 10. Jira comment evidence policy

- Closure text chính R6: comments `14742` và `14761`.
- Comment `14762` là blob-only; **không dùng làm evidence**.
- R7 phải post structured comment gồm exact commit, blobs, verifier result, workflow
  observation và owner/gate còn mở; không xóa lịch sử R6.

## 11. Independent review ledger

| Vai trò | Jira | Bắt buộc |
|---|---|---|
| BA | WWHN-129 | từng finding `ACCEPTED / ACCEPTED WITH CONDITIONS / REJECTED` |
| BAS | WWHN-130 | full trace chain và source hash disposition |
| Tech Lead | WWHN-24/WWHN-128 | transaction, lock, idempotency, indexes, ledger, reconciliation |
| QA1 | WWHN-24/WWHN-128 | run/artifact/digest/JUnit/log/DB/schema/reconcile/checksum |
| FE/QA/BA | WWHN-31/40/180 | consumer mapping, API/DB evidence, G1 decision |
| PM/Approver | WWHN-24 | formal decision, scope/effective date/conditions/account |

Exact templates: `docs/evidence/WWHN-128/REVIEW_AND_APPROVAL_PROTOCOL.md`.

## 12. Definition of Done

### 12.1 BE1/repository-controlled complete

- một commit duy nhất trên `m1`;
- closure/downstream/source registers hợp lệ;
- verifier fail-closed PASS;
- workflow artifact contract chứa đủ raw/DB/checksum outputs;
- structured Jira handoff dùng exact R7 commit/blob;
- không có false approval, false QA PASS hoặc P1 thuộc BE1 vô chủ.

### 12.2 Formal complete

- GitHub billing được xử lý và exact run thành công;
- QA1 verify artifact ID/name/digest/checksum/DB evidence;
- BA/BAS/Tech Lead/FE/QA disposition exact revision;
- Jira/source bytes được hash hoặc formally accepted with conditions;
- PM/Approver ghi `APPROVED`, `APPROVED WITH CONDITIONS` hoặc `REJECTED`, exact
  commit/attachment/hash disposition/scope/date/account.

## 13. Version history

| Version | Date | Change |
|---|---|---|
| `1.5-R5` | 2026-08-04 | Shared idempotency, Warranty/reconciliation tests, exact-SHA workflow |
| `1.6-R6` | 2026-08-06 | R5 CI verification, DB/schema/reconcile evidence contract, review protocol |
| `1.7-R7` | 2026-08-06 | Đồng bộ `11647/11646`; ghi billing attempt/job; thêm INV register, downstream gate, verifier, canonical `14742/14761` và loại `14762` |
