# WWHN-128 — DEC-INV-001: Nguồn dữ liệu chuẩn của tồn kho

## 0. Kiểm soát tài liệu

| Trường | Giá trị |
|---|---|
| Ticket | `WWHN-128` |
| Task cha | `WWHN-24` |
| Decision ID | `DEC-INV-001` |
| Phiên bản | `1.6-R6` |
| Ngày cập nhật | `2026-08-06` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Nhánh duy nhất | `m1` |
| Baseline trước R6 | `a9951965ee2d968435ada425a389f03b5aa4bede` |
| Implementation baseline đã chạy R5 | `f3b225ec0f5374c9c3d2b395fb59efbcfc601390` |
| R6 release commit | Ghi exact SHA tại Jira sau single commit; không tự nhúng SHA vòng lặp vào chính file |
| Trạng thái | **BE1 TECHNICAL PACKAGE COMPLETE — INDEPENDENT REVIEW / FORMAL APPROVAL PENDING** |

> Tài liệu không tự nhận `APPROVED`, `BASELINED`, `EFFECTIVE`, `QA PASS` hoặc `DONE`. CI chỉ cung cấp technical evidence. BA, BAS, Tech Lead, QA1 và PM/Approver phải disposition độc lập trên Jira.

## 1. Baseline kỹ thuật áp dụng

R6 tuân thủ `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`:

1. Laravel API theo Modular Monolith; controller không giữ toàn bộ business logic.
2. Backend enforce RBAC/data scope; frontend không phải security boundary.
3. Operation đổi tồn phải có DB transaction, row lock/concurrency guard và idempotency.
4. `stock_movements` là authoritative append-only ledger; `inventory_balances` là projection/read model.
5. PostgreSQL là target database chính; evidence CI hiện ghi rõ database engine thực thi, không được đánh tráo.
6. Error contract phải ổn định, không lộ stack trace/secret.
7. Evidence phải khóa exact commit, run, artifact digest và checksum.

## 2. Quyết định cốt lõi

### 2.1 Authority model

- `stock_movements` là nguồn sự thật về biến động số lượng tồn.
- `inventory_balances` là projection phục vụ đọc nhanh, có thể rebuild/reconcile từ ledger.
- Serial/item/container là physical subledger, không thay thế ledger số lượng.
- Movement đã post không sửa/xóa; sửa sai bằng reversal/adjustment có reference.
- Dimension chuẩn:

```text
(warehouse_id, product_id, stock_status)
```

- Reconciliation dùng duy nhất:

```sql
SUM(stock_movements.qty_delta)
```

Không đảo dấu lần hai theo `movement_type`.

### 2.2 Warranty status-only

`TEMP_ONLY` và lifecycle Warranty:

- không tạo `stock_movements`;
- không cập nhật `inventory_balances`;
- không tạo movement zero để giả audit;
- audit tại `warranty_events`;
- response công khai `stock_effect=NONE`, `movement_created=false`;
- chỉ serial `ISSUED` hoặc `RETURNED_TO_CUSTOMER` đủ điều kiện;
- serial `IN_STOCK` trả `422 WARRANTY_ITEM_NOT_ELIGIBLE`, `required_action=POST_ISSUE`;
- serial chỉ có tối đa một warranty case chưa `CLOSED`/`CANCELLED`.

### 2.3 Idempotency contract

- Header `Idempotency-Key` được validate tập trung qua `App\Support\IdempotencyKeyGuard`.
- Missing/blank: `428 PRECONDITION_REQUIRED`.
- Sau trim ngoài biên `8–100`: `422 VALIDATION_ERROR`.
- Scope logic: `(idem_key, operation, user_id)`; operation chứa aggregate ID.
- Cùng key/scope/payload: replay exact committed 2xx snapshot.
- Cùng key/scope, payload khác: `409 IDEMPOTENCY_ERROR`.
- Callback nghiệp vụ và response snapshot nằm trong cùng outer transaction.
- Lease stale takeover dùng conditional update; request thua race không chạy callback.
- Dọn lease chỉ khi còn đúng ownership; không xóa lease đã bị request khác takeover.

## 3. Audit implementation hiện tại

| Artifact | Git blob SHA | Kết quả audit |
|---|---|---|
| `app/Support/IdempotencyKeyGuard.php` | `f0ea48754fbab4c9b09cc522b7f59a864e924161` | Shared 428/422, boundary 8–100 |
| `app/Support/IdempotencyStore.php` | `78e45f98f799da90d6170dfb6259ce23263383b3` | Canonical request hash, lease ownership, exact replay, atomic snapshot |
| `Modules/Warehouse/app/Http/Controllers/WarrantyController.php` | `dbafecd8b606dafa7c5ca3c83b9b4ee840160fa2` | Case/serial row lock, eligibility, active-case guard, no stock effect |
| `Modules/Warehouse/app/Http/Controllers/ReportController.php` | `91612835ee00192fdd36590c403c1d1363fd8edc` | Union dimension, signed sum, missing-ledger/missing-balance detection |
| `database/migrations/2026_01_01_000010_create_idempotency_keys_table.php` | `915dd4bbee58e3871c6272b6ebc3680a0605fd2a` | Unique `(idem_key, operation, user_id)` |
| `Modules/Warehouse/database/migrations/2026_01_01_000034_create_inventory_balances_table.php` | `77b283a1684d12c3b00dd87e58c55e056cedab71` | Unique inventory dimension |
| `Modules/Warehouse/database/migrations/2026_01_01_000035_create_stock_movements_table.php` | `c9d47f3b7c239d220a326b8f53be3179629ee8b7` | Unique `business_key`, append-only ledger schema |

Không phát hiện P0 mới thuộc implementation WWHN-128 tại thời điểm R6. Review độc lập vẫn bắt buộc.

## 4. Evidence đã xác minh từ R5

### 4.1 Exact-SHA CI

| Trường | Giá trị |
|---|---|
| Commit | `f3b225ec0f5374c9c3d2b395fb59efbcfc601390` |
| Workflow run ID | `30876196296` |
| Run attempt | `1` |
| Conclusion | `success` |
| Job ID | `91888041476` |
| Artifact ID | `8879619822` |
| Artifact name | `wwhn-128-evidence-f3b225ec0f5374c9c3d2b395fb59efbcfc601390` |
| Artifact digest | `sha256:a5d37aad06ffb47edb381c26b9a12cb56cce36108336d100a262614149c3094a` |
| Test result | `17 tests / 99 assertions / 0 failures / 0 errors / 0 skipped` |
| Runtime | `PHP 8.3.33`, PHPUnit `12.5.33` |
| Database engine | `sqlite` isolated CI database |

R5 đã chứng minh focused regression pass. R6 bổ sung DB before/after, schema/index verification, reconciliation JSON, dual checksum manifest và immutable handoff fields. R5 không được mô tả là PostgreSQL/UAT evidence.

### 4.2 Jira artifact identity hiện có

| Jira attachment | ID | Size | Candidate SHA-256 từ exact R5 CI source bytes | Trạng thái |
|---|---:|---:|---|---|
| `WWHN-128.md` | `11570` | `25184` | `82bf4521c0e96ca9421ef29e57d1c5c01f99001037955b0ce7a5c66340eed0b6` | Metadata size match; exact Jira bytes chưa download qua connector |
| `WWHN-128-source-register.json` | `11569` | `12329` | `f76c4b01be2d78029aea200dca3b9c680157d414b4987252678363dadd3a2db9` | Metadata size match; exact Jira bytes chưa download qua connector |

Không được nâng trạng thái thành `CURRENT_SHA_VERIFIED` chỉ dựa vào size match. Formal approver phải:

- download exact Jira bytes và xác minh hash; hoặc
- ghi `APPROVED WITH CONDITIONS`/`ACCEPTED WITH CONDITIONS` với owner, deadline và lý do connector không cung cấp attachment-byte download.

## 5. Current Source Register

| Source | Version | Jira attachment ID | SHA state |
|---|---:|---:|---|
| BRD | `3.12` | `11386` | `ID_VERIFIED_SHA_PENDING_EXTERNAL` |
| SRS/FRD | `1.5` | `11415` | `ID_VERIFIED_SHA_PENDING_EXTERNAL` |
| OpenAPI | `1.1` | `11414` | `ID_VERIFIED_SHA_PENDING_EXTERNAL` |
| BPD | `1.4` | `11419` | `ID_VERIFIED_SHA_PENDING_EXTERNAL` |
| Baseline Register | `2.2.1` | `11545` | `ID_VERIFIED_SHA_PENDING_EXTERNAL` |

Machine-readable register: `docs/docs_task_jira/WWHN-128-source-register.json`.

## 6. R6 evidence pipeline

Workflow: `.github/workflows/wwhn-128-inventory-decision-contract.yml`.

Required outputs:

- exact commit/run/attempt/build metadata;
- JUnit + raw PHPUnit log + parsed test summary;
- `db-before.json` và `db-after.json`;
- `schema-verification.json` cho unique indexes cốt lõi;
- `reconciliation-report.json` với signed sum/status dimension;
- route list, commit stat, changed files, source-tree digest;
- Decision, Source Register và independent review protocol;
- review ledger không self-sign;
- `SHA256SUMS` và `SHA256SUMS.sha256`;
- immutable artifact `wwhn-128-evidence-<exact SHA>`.

Automated DB evidence test: `tests/Feature/Wwhn128EvidenceContractTest.php`.

Boundary: pipeline hiện dùng SQLite isolated database để giữ regression ổn định. QA1/Tech Lead có quyền yêu cầu thêm PostgreSQL/UAT matrix; khi đó disposition phải là `PASS WITH CONDITIONS`, không được che giấu engine.

## 7. Traceability

| Source/Decision | Rule | API/Code | Test/Evidence | Tickets |
|---|---|---|---|---|
| BRD 3.12 / DEC §2.2 | Warranty status-only | `POST /api/v1/warranty-cases/{id}/temp-only`; `WarrantyController::tempOnly` | Warranty status-only suite; DB no-effect assertions | WWHN-24/31/33/128/129/130 |
| BRD 3.12 | `IN_STOCK` reject, Post Issue required | `assertLinkedSerialEligible` | `WAR-AC-05` | WWHN-31/33/128/130 |
| OpenAPI 1.1 | Idempotency-Key 8–100 | `IdempotencyKeyGuard::require` | missing/short/long/boundary suite | WWHN-31/33/128/130/180 |
| DEC §2.3 | Operation-level idempotency | `IdempotencyStore::handle` | atomicity/replay/payload conflict/lease tests | WWHN-31/33/128/130/180 |
| DEC §2.1 | Signed reconciliation by stock status | reconciliation endpoint | integrity/signed-status/evidence tests | WWHN-31/33/128/130 |
| Baseline Register 2.2.1 | No false PASS | exact-SHA workflow + artifact manifest | checksum/digest/DB evidence gate | WWHN-24/128/129/130 |

Jira trace đã được BE1 post tại `WWHN-130`, comment `14511`; BAS disposition vẫn là role-owned gate.

## 8. Latest finding closure matrix

| ID | Kết quả R6 thuộc BE1 | Gate còn lại | Trạng thái trung thực |
|---|---|---|---|
| `BE1-R5-P1-01` | Formal approval target/template và identity fields được khóa | PM/Approver quyết định trên WWHN-24 | `EXTERNAL FORMAL DECISION PENDING` |
| `BE1-R5-P1-02` | Ghi attachment `11570/11569`, size, candidate SHA và verification policy | Authorized uploader/reviewer download exact Jira bytes hoặc approver accept-with-condition | `BE1 EVIDENCE COMPLETE / BYTE VERIFICATION EXTERNAL` |
| `BE1-R5-P1-03` | BA package + exact disposition template đầy đủ | BA comment trên WWHN-129 | `BA DISPOSITION PENDING` |
| `BE1-R5-P1-04` | Trace chain đầy đủ trong repo/Jira | BAS comment trên WWHN-130 | `BAS DISPOSITION PENDING` |
| `BE1-R5-P1-05` | Code/index/transaction/reconciliation audit map đầy đủ | Tech Lead independent review | `TECH LEAD DISPOSITION PENDING` |
| `BE1-R5-P1-06` | R5 CI success được xác minh; R6 sinh DB before/after/reconcile/schema/manifests | R6 run success + QA1 artifact review | `AUTOMATION COMPLETE / QA1 SIGNATURE PENDING` |
| `BE1-R5-P1-07` | Source IDs/version/state và accepted-condition policy rõ | BA/BAS/PM source hash disposition | `SOURCE BYTE HASH EXTERNAL` |
| `BE1-R5-P2-01` | Structured post-upload handoff template + CI-generated handoff file | BE1 post exact R6 fields sau commit/run | `READY TO CLOSE POST-COMMIT` |

## 9. Independent review ledger

| Vai trò | Scope | Jira | Trạng thái |
|---|---|---|---|
| BA | Business rule, source baseline, consequence, finding-by-finding disposition | WWHN-129 | `PENDING` |
| BAS | Source → Decision → Rule → API/TC → ticket | WWHN-130 | `PENDING` |
| Tech Lead | Transaction, lock, lease, indexes, ledger, reconciliation | WWHN-24/WWHN-128 | `PENDING` |
| QA1 | Run, artifact digest, JUnit/log, DB evidence, checksums | WWHN-24/WWHN-128 | `PENDING` |
| PM/Approver | Formal decision, effective date, scope, conditions | WWHN-24 | `PENDING` |

Exact templates: `docs/evidence/WWHN-128/REVIEW_AND_APPROVAL_PROTOCOL.md`.

## 10. Final handoff format

```text
[BE1 R6 FINAL HANDOFF — WWHN-128]
Decision: DEC-INV-001 v1.6-R6
Repository/branch: Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE / m1
Exact commit: <R6 40-char SHA>
Decision path/blob: docs/docs_task_jira/WWHN-128.md / <blob>
Source register path/blob: docs/docs_task_jira/WWHN-128-source-register.json / <blob>
Workflow run/attempt/conclusion: <run>/<attempt>/<result>
Artifact ID/name/digest: <id>/<name>/<sha256:digest>
Test result/database: <tests/assertions/failures>/<engine>
Jira R5 attachments: 11570 (25184 bytes), 11569 (12329 bytes)
Hash state: exact Jira bytes verified | accepted-with-condition | pending owner/deadline
Reviewer status: BA=<...>; BAS=<...>; TL=<...>; QA1=<...>; PM=<...>
BE1 blockers: none | <exact blocker>
Overall: READY FOR INDEPENDENT DISPOSITION; NOT SELF-APPROVED.
```

## 11. Definition of Done

### 11.1 BE1 technical scope complete khi

- R6 single commit tồn tại trên `m1`;
- exact-SHA workflow success;
- artifact có DB before/after, schema, reconciliation, JUnit/log và dual checksum;
- WWHN-128/WWHN-129/WWHN-130/WWHN-24 nhận structured handoff/request;
- không còn false claim hoặc blocker thuộc BE1 không có disposition.

### 11.2 Formal Decision complete khi

- BA và BAS disposition exact R6;
- Tech Lead review exact commit/artifact;
- QA1 verify exact run/artifact/digest/DB evidence;
- Jira artifact/source hash được verified hoặc formally accepted with conditions;
- PM/Approver ghi `APPROVED`, `APPROVED WITH CONDITIONS` hoặc `REJECTED`, scope, date và account.

## 12. Version history

| Version | Date | Change |
|---|---|---|
| `1.5-R5` | 2026-08-04 | Shared idempotency guard, Warranty/reconciliation tests, exact-SHA workflow |
| `1.6-R6` | 2026-08-06 | Xác minh R5 CI thật; khóa Jira attachment identity; bổ sung DB before/after, schema/reconciliation evidence, dual checksum, independent review protocol và latest P1/P2 closure matrix |
