# WWHN-128 — Independent Review & Formal Approval Protocol

## 1. Phạm vi và nguyên tắc

Protocol này áp dụng cho `DEC-INV-001 v1.7-R7` trên repository
`Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`, duy nhất nhánh `m1`. Mục tiêu là
disposition các finding `INV-P1-01..08` và `INV-P2-01` mà không cho BE1 hoặc CI
tự ký thay BA, BAS, Tech Lead, QA1, FE/QA downstream hoặc PM/Approver.

Repository-side completeness không đồng nghĩa `APPROVED`, `BASELINED`,
`EFFECTIVE`, `QA PASS` hay `DONE`.

## 2. Identity chain bắt buộc

Mỗi review phải khóa đủ:

- repository, branch và exact commit SHA 40 ký tự;
- workflow run ID, attempt, conclusion và job ID;
- artifact ID, name, digest;
- Decision/Source Register/Closure Register/Downstream Gate path và Git blob;
- Jira attachment ID, filename, size và SHA-256 nếu reviewer có exact bytes;
- scope review, result, reviewer Jira account và ISO-8601 timestamp.

Không chấp nhận comment chỉ ghi “OK”, blob-only, ảnh chụp không kiểm hash, branch
URL động hoặc revision cũ.

## 3. Current Jira artifact identity

| Attachment | ID | Size | Exact Jira-byte SHA-256 |
|---|---:|---:|---|
| `WWHN-128.md` | `11647` | `13447` bytes | `PENDING_EXTERNAL_DOWNLOAD` |
| `WWHN-128-source-register.json` | `11646` | `9446` bytes | `PENDING_EXTERNAL_DOWNLOAD` |

Connector hiện không cung cấp action download attachment bytes. Hai disposition hợp lệ:

1. `CURRENT_SHA_VERIFIED`: uploader/reviewer tải exact Jira bytes, tính SHA-256,
   ghi tool/command, hash, account và timestamp; hoặc
2. `ACCEPTED WITH CONDITIONS`: ghi attachment ID/size, owner, deadline và yêu
   cầu xác minh ngoài connector.

Không dùng attachment cũ `11570/11569` hoặc metadata size để tự nhận exact-byte verified.

## 4. CI truth và artifact gate

R6 exact target:

- commit `7d7091c48798114b5bbf7ff4c0b58617d177c737`;
- run `31082380025`, attempt `2`, job `92570314627`;
- runner chưa được cấp, `steps=[]`, artifact count `0`;
- classification: GitHub billing/spending-limit trước runner;
- không được gọi đây là code/test failure.

DevOps/org billing admin phải xử lý billing rồi rerun exact failed run. QA1 chỉ
PASS sau khi có artifact ID/name/digest và kiểm `SHA256SUMS`,
`SHA256SUMS.sha256`, JUnit/raw log, DB before/after, schema và reconciliation.

## 5. Review templates

### 5.1 BA — WWHN-129

```text
[BA DISPOSITION — DEC-INV-001 v1.7-R7]
Exact commit and artifact: <SHA>/<ID>/<name>/<digest>
Scope: business rule, source, consequence, AC and finding-by-finding review
INV-P1-01: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
INV-P1-04: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
INV-P1-07: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
INV-P1-08: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
Conditions/open points: <none or explicit owner/deadline>
Reviewer Jira account: <account>
Reviewed at: <ISO-8601>
```

### 5.2 BAS — WWHN-130

```text
[BAS TRACEABILITY DISPOSITION — DEC-INV-001 v1.7-R7]
Exact commit/artifact: <SHA>/<ID + digest>
Reviewed chain: Source -> Decision -> Rule -> API/Code -> TC/Evidence -> tickets
INV-P1-05: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
Broken/missing rows: <NONE or exact rows>
Source hash disposition: VERIFIED | ACCEPTED WITH CONDITIONS
Reviewer Jira account: <account>
Reviewed at: <ISO-8601>
```

### 5.3 Tech Lead

```text
[TECH LEAD REVIEW — DEC-INV-001 v1.7-R7]
Exact commit/artifact: <SHA>/<ID + digest>
Scope: outer/nested transaction; row lock; lease ownership/takeover; request hash;
unique indexes; operation-level idempotency; append-only ledger; signed
reconciliation; rollback/no partial write/no response leak
INV-P1-06: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
PostgreSQL/UAT condition: <none or explicit>
Findings: <none or file/line/impact>
Reviewer Jira account: <account>
Reviewed at: <ISO-8601>
```

### 5.4 QA1

```text
[QA1 EXACT-SHA EVIDENCE REVIEW — DEC-INV-001 v1.7-R7]
Commit/run/attempt/conclusion/job: <SHA>/<run>/<attempt>/<result>/<job>
Artifact ID/name/digest: <id>/<name>/<sha256:digest>
Verified: SHA256SUMS, SHA256SUMS.sha256, JUnit, raw log, route list,
db-before.json, db-after.json, schema-verification.json,
reconciliation-report.json, closure-verification.json
Test result/database: <tests/assertions/failures/errors>/<engine>
INV-P1-02: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
INV-P1-03: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
Result: PASS | PASS WITH CONDITIONS | FAIL
Reviewer Jira account: <account>
Reviewed at: <ISO-8601>
```

### 5.5 FE/QA/BA downstream — WWHN-31/40/180

```text
[DOWNSTREAM CONTRACT DISPOSITION — DEC-INV-001 v1.7-R7]
Exact backend SHA: <SHA>
Contract/changelog path + SHA-256: <...>
Endpoint -> payload -> error -> permission mapping: <evidence>
FE result/date/account: <...>
QA API/DB/race/reconciliation result/date/account: <...>
BA G1 decision: GO | CONDITIONAL_GO | NO_GO
INV-P1-08: ACCEPTED | ACCEPTED WITH CONDITIONS | REJECTED
```

### 5.6 PM/Approver — WWHN-24

```text
[FORMAL DECISION — DEC-INV-001 v1.7-R7]
Decision: APPROVED | APPROVED WITH CONDITIONS | REJECTED
Exact commit: <40-char SHA>
Decision attachment: 11647/WWHN-128.md/<SHA-256 or accepted-condition reference>
Source Register attachment: 11646/WWHN-128-source-register.json/<SHA-256 or accepted-condition reference>
Workflow artifact: <ID>/<name>/<sha256:digest>
Approved scope: inventory authority; Warranty status-only; idempotency;
transaction/lock; append-only movement; signed reconciliation; evidence policy
Effective date: <ISO-8601 or NOT EFFECTIVE>
Conditions/expiry: <none or explicit>
Approver Jira account: <account>
```

## 6. P2 evidence rule

- Closure text chính: Jira comments `14742` và `14761`.
- Comment `14762` là blob-only và bị loại khỏi closure evidence.
- Revision R7 phải post một structured closure comment mới, dẫn exact commit và
  machine-readable register; không xóa lịch sử R6.

## 7. Fail-closed rules

- Không đổi Decision thành approved/baselined/effective từ BE1 comment hay CI.
- Không ghi QA PASS khi artifact/digest/checksum/DB evidence chưa kiểm.
- Không dùng FE UI hide/redirect thay backend authorization evidence.
- Không dùng branch URL động hoặc self-confirmation thay exact artifact review.
- Source/Jira byte hash chưa có phải accepted-with-condition có owner/deadline.
- WWHN-31/40/180 còn `In Progress` thì downstream gate giữ fail-closed.
