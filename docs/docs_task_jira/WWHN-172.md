# WWHN-172 — Handoff kỹ thuật SKU/component/mã vật lý/container

> Phiên bản hồ sơ: **2.1.0**
> Nhánh triển khai duy nhất: **`m1`**
> Architecture baseline: `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`
> Assessment reference: **80/100 — PASS có điều kiện**, SHA prefix tham chiếu `376c14f...`

## 1. Nguyên tắc xác minh

`376c14f...` chỉ là provenance của lần đánh giá. Bộ remediation làm thay đổi source nên không được dùng hash cũ để ký PASS. Kết quả hợp lệ phải gắn với:

1. final exact commit SHA trên `m1`;
2. GitHub Actions run URL của exact SHA đó;
3. artifact theo từng DB engine;
4. `build-metadata.json`, `evidence-index.json`, `SHA256SUMS`, `SHA256SUMS.sha256`;
5. comment PASS/FAIL độc lập của đúng reviewer.

BE1 không tự đánh dấu PASS thay QA1, QA2, BE2, Tech Lead hoặc DBA/DevOps.

## 2. Baseline kiến trúc đã áp dụng

Triển khai giữ đúng modular monolith Laravel và ranh giới ghi dữ liệu trong architecture guide:

- controller mỏng; validation/request → service/use case → persistence;
- mutation SKU/container/import chạy trong transaction;
- lock order xác định và optimistic version guard;
- idempotency key lưu canonical request hash và response snapshot;
- audit/audit-outbox nằm trong cùng commit boundary, policy `fail_closed`;
- error envelope không lộ secret, SQL, file path hoặc stack trace;
- OpenAPI/Postman/test-case là contract nguồn và được hash trong CI;
- full PHPUnit chạy trên toàn repository để phát hiện regression ngoài WWHN-172.

## 3. Phạm vi nghiệp vụ đã harden

### 3.1 SKU aggregate

Atomic boundary:

```text
products
+ sku_component
+ physical_code
+ audit_log / audit_outbox
+ idempotency response snapshot
```

Guard bắt buộc:

- code normalize uppercase và unique;
- component không self-reference, quantity > 0, không duplicate, không cycle;
- component phải tồn tại và ACTIVE;
- physical code không bị chuyển ownership trái phép;
- update dùng `If-Match` hoặc body `version`;
- exact replay không tạo thêm business/audit row;
- same key + different payload trả `409` và không side effect.

### 3.2 Container aggregate

Atomic boundary:

```text
container
+ container_item
+ product/serial/physical-code locks
+ audit_log / audit_outbox
+ idempotency response snapshot
```

Guard bắt buộc:

- parent không self/cycle và không lệch warehouse;
- item grain dùng `item_key` xác định, không phụ thuộc NULL uniqueness;
- serial chỉ thuộc một active container;
- bulk quantity > 0 và product ACTIVE;
- `SEALED`/`CLOSED` immutable;
- seal chỉ từ `ACTIVE` và container phải có item;
- stale version trả `409`;
- transaction retry có giới hạn cho deadlock.

### 3.3 Import/data validation

- map cột theo header, không theo vị trí cố định;
- validate toàn file trước khi commit;
- duplicate trong file/DB, enum/boolean sai, inactive SKU đều fail rõ ràng;
- `ALL_OR_NOTHING`, không partial write;
- audit failure rollback toàn bộ row và outbox;
- fixture hợp lệ/không hợp lệ được tạo trong CI và khóa bằng SHA-256.

## 4. Root cause của các lần CI chưa đạt

### 4.1 Run `30999373942`

Exact SHA `23f57c27fed8dd2cad4846bdea0ed1f51d32f807`.

Cả SQLite/PostgreSQL/MySQL dừng ở static gate trước khi collector chạy. Pint đã quét:

- `bootstrap/cache/*.php` được framework sinh tự động;
- các maintenance script ngoài phạm vi WWHN-172;
- hai evidence script WWHN-172 còn style issue thật.

Disposition:

- loại generated cache và unrelated maintenance scripts khỏi Pint scope của workflow WWHN-172;
- vẫn syntax-check source PHP và Pint toàn bộ `app`, `Modules`, `tests`, config/routes;
- sửa style thật trong `prepare-wwhn172-evidence.php` và `collect-wwhn172-service-proof.php`;
- tạo evidence directory trước static gate để failure cũng có artifact chẩn đoán;
- thêm Newman và race runner thực trên PostgreSQL/MySQL.

### 4.2 Run `31001257267`

Exact SHA `3eeb5f0e2a5373ad7af0680e375348488d8b790d`.

Syntax check và Pint đã PASS toàn bộ phạm vi 211 file. Ba matrix job tiếp tục dừng trước runtime collector vì OpenAPI khai báo `openapi: 3.0.3` nhưng dùng cú pháp numeric `exclusiveMinimum: 0` của OpenAPI 3.1 tại hai schema quantity. Redocly yêu cầu `exclusiveMinimum` là boolean trong OpenAPI 3.0.

Remediation trong phiên bản 2.1.0:

- giữ nguyên OpenAPI 3.0.3 để không tạo breaking change tooling;
- biểu diễn điều kiện `quantity > 0` bằng `minimum: 0` và `exclusiveMinimum: true`;
- bổ sung `operationId` duy nhất cho từng operation;
- bổ sung `info.license` cho contract nội bộ;
- bổ sung response `401` chuẩn hóa cho các endpoint có bearer security, bao gồm list container;
- không thay đổi endpoint, payload nghiệp vụ, error code, transaction boundary hoặc compatibility behavior.

## 5. Remediation matrix P0/P1/P2

| ID | Sev | Required fix | Thực hiện trong `m1` | Evidence đóng lỗi |
|---|---:|---|---|---|
| P0 regression gate | P0 | Không để sửa evidence làm hỏng transaction/permission/audit | Full PHPUnit toàn repo trên SQLite/PostgreSQL/MySQL; focused P0 tests; OpenAPI lint | `phpunit-full.*`, focused JUnit/raw, static log |
| BE1-P1-01 | P1 | Raw CI/DB matrix có thể verify | Workflow exact SHA; raw/JUnit PHPUnit; raw/JUnit Newman; DB engine/version; route list; build metadata; manifest hai lớp | `build-metadata.json`, `database-version.raw.log`, `phpunit-full.*`, `newman.*`, `SHA256SUMS*` |
| BE1-P1-02 | P1 | OpenAPI/Postman/environment/test-case file rời | Copy canonical files vào từng engine artifact; source register ghi version/path/role; CI hash toàn bộ | `wwhn172.openapi.yaml`, `openapi.generated.json`, `wwhn172.postman.collection.json`, `wwhn172.postman.environment.json`, `wwhn172.test-cases.json` |
| BE1-P1-03 | P1 | QA1 verify race/idempotency/cycle/audit/no-stack | Focused PHPUnit + Newman + multi-worker Node race trên PostgreSQL/MySQL + DB snapshots | `phpunit-aggregate.*`, `phpunit-audit.*`, `concurrency-report.json`, `newman.*`, `db-before.json`, `db-after.json` |
| BE1-P1-04 | P1 | QA2 verify duplicate/invalid/inactive/no-side-effect | Import test suite, valid/invalid XLSX fixtures, fixture hash, audit rollback test | `phpunit-import.*`, `fixture-metadata.json`, `fixtures/*.xlsx` |
| BE1-P1-05 | P1 | BE2/Tech Lead sign writer boundary/trace grain/alignment | Giữ boundary matrix và source register trong artifact exact SHA | `reviewer-protocols/BE1_BE2_BOUNDARY_MATRIX.md`, source register |
| BE1-P1-06 | P1 | Business row + audit/outbox + rollback proof | Service proof ghi business/audit/idempotency rows; DB before/after; forced audit failure test dưới `APP_DEBUG=true` | `service-proof.json`, `db-before.json`, `db-after.json`, `phpunit-audit.*` |
| BE1-P1-07 | P1 | Migration preflight/dry-run/rollback/reapply | Migrate pretend, preflight JSON, rollback one step, reapply, postcheck và focused migration test trên từng engine | `migration-*.raw.log`, `migration-preflight.json`, `migration-postcheck.json`, `phpunit-migration.*` |
| BE1-P2-01 | P2 | Aggregate response/compatibility | Newman đọc lại aggregate; legacy POST SKU không idempotency key vẫn 201; versioned writes vẫn strict | `newman.raw.log`, `newman.junit.xml` |
| BE1-P2-02 | P2 | Evidence dễ audit, không claim bề mặt | Một `evidence-index.json` map finding → artifact; source tree SHA; manifest hash hai lớp | `evidence-index.json`, `build-metadata.json`, `SHA256SUMS*` |

Assessment hiện tại không nêu P0 còn mở. P0 vẫn được giữ như regression gate, không tự suy diễn “không có P0” thành bỏ test.

## 6. CI/evidence contract

Workflow: `.github/workflows/wwhn-172-sku-container-contract.yml`

Matrix:

- SQLite: compatibility/reference engine;
- PostgreSQL 16: DB chuẩn cho lock/race;
- MySQL 8.4: portability matrix;
- PHP 8.3, Node 22, Composer v2, Newman 6.x.

Race test dùng PHP CLI server nhiều worker. SQLite ghi `SKIPPED` có lý do; PostgreSQL và MySQL bắt buộc PASS các nhóm:

1. hai update cùng version → một `200`, một `409 SKU_VERSION_CONFLICT`;
2. hai SKU claim cùng physical code → một `201`, một `409` ownership/unique conflict;
3. hai container claim cùng serial → một `201`, một `409` serial ownership/unique conflict.

Collector từ chối chạy nếu DB không phải database CI chuyên dụng.

## 7. Artifact bắt buộc

Tên artifact:

```text
wwhn-172-evidence-{sqlite|pgsql|mysql}-{exact_commit_sha}
```

Artifact tối thiểu gồm:

- static/contract gate raw log;
- tool/runtime/OS/DB version logs;
- full và focused PHPUnit raw + JUnit;
- Newman raw + JUnit;
- PostgreSQL/MySQL race report;
- migration pretend/preflight/rollback/reapply/postcheck;
- fixture metadata và XLSX hash;
- DB before/after và service proof;
- canonical OpenAPI/Postman/environment/test-case/source register;
- reviewer protocols;
- `build-metadata.json`, `evidence-index.json`, `SHA256SUMS`, `SHA256SUMS.sha256`.

Credential leak gate kiểm cả pattern lẫn exact temporary password/JWT. Artifact không được chứa token hoặc secret.

## 8. Lock order và writer boundary

Lock order chuẩn:

```text
idempotency lease
→ aggregate root
→ parent/root references
→ component/product references theo ID tăng dần
→ physical codes theo normalized code tăng dần
→ serials theo ID tăng dần
→ business writes
→ audit/audit_outbox
→ response snapshot
→ COMMIT
```

Writer ownership:

- BE1: SKU aggregate, physical-code ownership, container aggregate, import contract;
- BE2: shared schema/report/query boundary và các writer ngoài aggregate trên;
- Tech Lead: phê duyệt boundary cuối cùng;
- mọi writer mới chạm `products`, `physical_code`, `container`, `container_item`, `inventory_serials` phải review lock order và impacted contract.

## 9. Reviewer checklist bắt buộc

### WWHN-173 — QA1

Comment theo từng nhóm, không ghi PASS chung chung:

- race;
- idempotency exact replay và changed-payload conflict;
- component self/cycle;
- audit success + forced fail-closed rollback;
- canonical error/no stack trace;
- exact SHA, engine artifact, `SHA256SUMS` hash, reviewer identity.

### WWHN-174 — QA2

- valid import commit;
- duplicate in file/DB;
- invalid enum/boolean/header;
- inactive existing SKU;
- no partial business/audit row;
- fixture SHA-256;
- exact SHA, engine artifact, reviewer identity.

### WWHN-171 — BE2 + Tech Lead

- writer ownership;
- transaction boundary;
- lock order;
- trace grain;
- branch alignment;
- impacted OpenAPI/Postman/schema;
- exact SHA và disposition.

### DevOps/DBA

- DB engine/version mục tiêu;
- preflight PASS;
- dry-run reviewed;
- backup/restore point;
- rollback/reapply log;
- deployment window và owner.

## 10. Definition of Done thực tế

### BE1 technical completion

Đạt khi final exact SHA có ba matrix jobs hoàn tất, artifact/hash đầy đủ và không có regression.

### Formal Jira closure

Chỉ đạt 100% khi đồng thời có:

- QA1 PASS độc lập WWHN-173;
- QA2 PASS độc lập WWHN-174;
- BE2 + Tech Lead sign-off WWHN-171;
- DevOps/DBA migration confirmation;
- Jira WWHN-172 tham chiếu final exact SHA, run URL, artifact names và manifest hashes.

Không được đổi trạng thái `Done/Approved` chỉ dựa trên nội dung do BE1 tự ghi.

## 11. Quy tắc ghi nhận exact SHA sau commit

Do tài liệu này nằm trong chính commit remediation, exact SHA không được tự ghi trước khi Git tạo commit. Giá trị authoritative là SHA của commit trên nhánh `m1` chứa phiên bản **2.1.0** này. GitHub Actions phải checkout trực tiếp SHA đó; Jira handoff phải ghi run URL, artifact ID, artifact name và manifest hash sau khi workflow hoàn tất.
