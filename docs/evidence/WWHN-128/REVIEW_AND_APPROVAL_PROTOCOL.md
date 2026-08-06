# WWHN-128 — Independent Review & Formal Approval Protocol

## 1. Phạm vi

Protocol này áp dụng cho `DEC-INV-001 v1.6-R6` trên repository `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`, nhánh duy nhất `m1`. Mục tiêu là đóng các finding `BE1-R5-P1-01..07` và `BE1-R5-P2-01` mà không cho phép BE1 hoặc CI tự ký thay BA, BAS, Tech Lead, QA1 hay PM/Approver.

## 2. Identity chain bắt buộc

Mỗi review phải khóa đủ các trường sau:

- repository và branch;
- exact 40-character commit SHA;
- workflow run ID/attempt/conclusion;
- artifact ID/name/digest;
- Decision path + Git blob SHA;
- Source Register path + Git blob SHA;
- Jira attachment ID, filename, size và SHA-256 nếu reviewer tải được exact Jira bytes;
- scope review, result, reviewer Jira account và ISO-8601 timestamp.

Không chấp nhận review chỉ ghi “OK”, chỉ đính file/blob, hoặc dùng revision cũ.

## 3. Jira attachment hash policy

Jira hiện có:

| Attachment | ID | Size |
|---|---:|---:|
| `WWHN-128.md` | `11570` | `25184` bytes |
| `WWHN-128-source-register.json` | `11569` | `12329` bytes |

Exact R5 CI source bytes có cùng kích thước và checksum:

| File trong artifact R5 | SHA-256 |
|---|---|
| `WWHN-128.md` | `82bf4521c0e96ca9421ef29e57d1c5c01f99001037955b0ce7a5c66340eed0b6` |
| `source-register.json` | `f76c4b01be2d78029aea200dca3b9c680157d414b4987252678363dadd3a2db9` |

Đây là **candidate hash có size match**, không được mô tả là “Jira exact bytes verified” khi chưa download lại attachment bằng kênh có quyền. Hai disposition hợp lệ:

1. `CURRENT_SHA_VERIFIED`: reviewer/uploader download exact Jira bytes, tính SHA-256 và xác nhận trùng; hoặc
2. `ACCEPTED_WITH_CONDITION_CONNECTOR_BYTE_DOWNLOAD_UNAVAILABLE`: PM/BA/BAS chấp nhận có điều kiện, ghi rõ attachment ID/size/candidate hash, owner và deadline xác minh ngoài connector.

## 4. Review templates

### 4.1 BA — WWHN-129

```text
[BA DISPOSITION — DEC-INV-001 v1.6-R6]
Exact commit: <40-char SHA>
Decision/source-register Git blobs: <sha>/<sha>
Workflow run/artifact/digest: <run>/<artifact>/<sha256:digest>
Scope: business rule, source baseline, consequence, AC and finding-by-finding review
BE1-R5-P1-01: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
BE1-R5-P1-02: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
BE1-R5-P1-03: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
BE1-R5-P1-07: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
Conditions/open points: <none or explicit>
Reviewer Jira account: <account>
Reviewed at: <ISO-8601>
```

### 4.2 BAS — WWHN-130

```text
[BAS TRACEABILITY DISPOSITION — DEC-INV-001 v1.6-R6]
Exact commit/artifact: <SHA>/<artifact ID + digest>
Reviewed chain: Source -> Decision -> Rule -> API/Code -> TC -> affected ticket
Result: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
Missing/broken links: <none or exact rows>
Reviewer Jira account: <account>
Reviewed at: <ISO-8601>
```

### 4.3 Tech Lead — WWHN-24 hoặc WWHN-128

```text
[TECH LEAD REVIEW — DEC-INV-001 v1.6-R6]
Exact commit/artifact: <SHA>/<artifact ID + digest>
Scope reviewed: transaction boundary; row lock; lease takeover; request hash; unique indexes;
operation-level idempotency; append-only movement; signed reconciliation; rollback/no partial write
Result: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
Findings: <none or exact file/line/impact>
Reviewer Jira account: <account>
Reviewed at: <ISO-8601>
```

### 4.4 QA1 — WWHN-24 hoặc WWHN-128

```text
[QA1 EXACT-SHA EVIDENCE REVIEW — DEC-INV-001 v1.6-R6]
Commit/run/attempt/conclusion: <SHA>/<run>/<attempt>/<success|failure>
Artifact ID/name/digest: <id>/<name>/<sha256:digest>
Verified: SHA256SUMS, SHA256SUMS.sha256, JUnit, raw log, route list,
db-before.json, db-after.json, schema-verification.json, reconciliation-report.json
Test result: <tests>/<assertions>/<failures>/<errors>
Database engine: <engine>
Result: PASS | PASS WITH CONDITIONS | FAIL
Conditions: <none or exact gap, including PostgreSQL/UAT matrix if required>
Reviewer Jira account: <account>
Reviewed at: <ISO-8601>
```

### 4.5 PM/Approver — WWHN-24

```text
[FORMAL DECISION — DEC-INV-001 v1.6-R6]
Decision: APPROVED | APPROVED WITH CONDITIONS | REJECTED
Exact commit: <40-char SHA>
Decision Jira attachment: <ID>/<filename>/<SHA-256 or accepted-condition reference>
Source Register Jira attachment: <ID>/<filename>/<SHA-256 or accepted-condition reference>
Workflow artifact: <ID>/<name>/<sha256:digest>
Approved scope: inventory authority; Warranty status-only; idempotency; transaction/lock;
append-only movement; signed reconciliation; evidence policy
Effective date: <ISO-8601 date/time or NOT EFFECTIVE>
Conditions/expiry: <none or explicit>
Approver Jira account: <account>
```

## 5. Fail-closed rules

- Không chuyển Decision thành `APPROVED`, `BASELINED`, `EFFECTIVE` chỉ từ BE1 comment hoặc CI success.
- Không dùng attachment `11560/11559` hoặc revision cũ thay cho target hiện hành.
- Không dùng UI/FE behavior thay cho backend/API/DB evidence.
- Không ghi QA PASS khi artifact digest, checksum hoặc DB evidence không kiểm tra được.
- Nếu source SHA chưa tải được, phải dùng `ACCEPTED WITH CONDITIONS`, nêu owner/deadline; không để “pending” vô chủ.
