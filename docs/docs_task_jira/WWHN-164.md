# [BE1] Triển khai auth/RBAC/audit và contract xử lý lỗi — WWHN-164

- Repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
- Branch bắt buộc: `m1`
- Kiến trúc tham chiếu: `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`
- Runtime: Laravel 13, PHP 8.3, JWT, modular architecture, OpenAPI/L5-Swagger.
- Trạng thái BE: **implementation hoàn tất; closure artifact tự động hóa; chờ independent sign-off**.
- Cập nhật: 2026-08-05.

> Không lưu credential dùng được trong source, docs, Postman hoặc evidence. Không dùng từ `APPROVED` nếu chưa có reviewer có thẩm quyền xác nhận trên Jira/GitHub.

## 1. Kết quả xử lý đánh giá 80/100

| ID | Sev | Phần BE đã hoàn thiện trên `m1` | Closure gate bắt buộc |
|---|---|---|---|
| FIX-01 | P1 | Khóa test 401/403, invalid/expired token, refresh replay, RBAC, audit leakage/failure, negative API và data-scope trong targeted + full PHPUnit; xuất JUnit/log theo exact SHA. | QA1 chạy độc lập trên current SHA/DB staging và cập nhật WWHN-166. |
| FIX-02 | P1 | Backend contract login/expiry/logout/403/role/permission/scope đã ổn định; runbook browser/viewport được chuẩn hóa. | QA2 chạy browser thật, video/screenshots và cập nhật WWHN-167. |
| FIX-03 | P1 | CI generate OpenAPI từ runtime annotations, lint, rồi generate Postman từ **cùng OpenAPI artifact**; kiểm tra endpoint parity và checksum. | Tech Lead/FE/QA duyệt cùng artifact/SHA/version. |
| FIX-04 | P1 | Workflow mới ghi exact SHA, branch, run ID, PHP, DB engine, audit policy; upload artifact 90 ngày. Do yêu cầu sửa thẳng `m1`, dùng direct-commit review record thay PR mới. | Tech Lead/QA1 review commit, changed files, run/jobs và artifact digest. |
| FIX-05 | P1 | Targeted security test + full regression và runbook DB evidence Kho A/Kho B list/detail/mutation. | QA1/Security retest staging và ký DB evidence. |
| FIX-06 | P1 | Policy outbox-required/fail-closed, failure tests và artifact được khóa; bổ sung checklist reconcile/lock/retry/alert/dashboard/runbook. | Tech/Security/DevOps duyệt policy và cung cấp monitoring/schedule evidence. |
| FIX-07 | P1 | CI secret-pattern scan không in giá trị match; tài liệu cấm đưa secret/rotation value vào repo. | DevOps/Security xác nhận Rotate/Revoke hoặc Not Applicable có căn cứ. |
| FIX-08 | P2 | CI trích permission keys trực tiếp từ runtime DB, JSON parse gate và checksum. | BA/QA1 đối chiếu seed/middleware/contract/role matrix và ký version. |

Không có P0 được liệt kê trong lần đánh giá ngày 2026-08-03. Các P1/P2 thuộc BE đã có disposition và evidence mechanism; các mục còn lại là separation-of-duties gate, BE không thể tự ký thay.

## 2. Thay đổi kỹ thuật bổ sung

### 2.1 Exact-SHA closure workflow

File: `.github/workflows/wwhn-164-closure-gate.yml`

Workflow chạy khi push vào `m1` hoặc manual dispatch:

1. Checkout full history đúng SHA.
2. Cài PHP 8.3 và Node 22 từ lock files.
3. Tạo SQLite test database tách biệt, generate JWT secret ngẫu nhiên cho CI.
4. Chạy syntax, `git diff --check`, Pint và shell syntax.
5. Chạy targeted security tests và full PHPUnit suite.
6. Generate/lint OpenAPI; generate Postman từ exact OpenAPI artifact.
7. Kiểm tra canonical auth paths và collection structure.
8. Scan tracked files cho private key/token patterns mà không log secret.
9. Trích runtime permission catalog.
10. Tạo metadata, signoff register Pending, SHA256 checksums.
11. Upload `wwhn-164-closure-evidence-<SHA>` retention 90 ngày.

### 2.2 Evidence collector

File: `scripts/collect-wwhn164-closure-evidence.sh`

Script fail-fast và không tạo false-positive approval. `review/signoff-register.json` được khởi tạo Pending; CI sẽ fail nếu automation tự ghi một gate là Approved.

### 2.3 Reviewer runbook

File: `docs/evidence/WWHN-164-CLOSURE-RUNBOOK.md`

Runbook quy định command, expected result, browser viewport, DB evidence, audit operations, credential rotation/N-A record, permission traceability và closure decision cho từng FIX-01…08.

## 3. Artifact structure

```text
build/evidence/wwhn-164/
├── build-metadata.json
├── SHA256SUMS
├── contracts/
│   ├── openapi.json
│   ├── openapi-lint.log
│   ├── postman.collection.json
│   ├── postman-conversion.log
│   └── contract-parity.json
├── tests/
│   ├── phpunit-security.xml
│   ├── phpunit-security.log
│   ├── phpunit-full.xml
│   ├── phpunit-full.log
│   ├── credential-scan-files.txt
│   └── runtime-permission-keys.json
└── review/
    └── signoff-register.json
```

`build-metadata.json` và artifact name phải khớp `GITHUB_SHA`; toàn bộ file được kiểm tra bằng `SHA256SUMS`.

## 4. Contract và security baseline

- Auth canonical: `POST /api/v1/auth/login`, `POST /api/v1/auth/refresh`, `POST /api/v1/auth/logout`, `GET /api/v1/auth/me`.
- Error baseline: 401 unauthenticated; 403 access denied; 422 validation; 409 conflict/idempotency; 500 không lộ stack trace.
- Refresh token rotate một lần; replay bị từ chối và revoke token family theo implementation hiện hành.
- Data scope được backend enforce; FE visibility không thay thế authorization.
- Audit payload sanitize đệ quy; primary failure đi outbox; audit + outbox failure fail request theo policy.
- Repository evidence chỉ chứa sanitized data và metadata; secret values không được commit.

## 5. Hướng dẫn reviewer

Chi tiết tại `docs/evidence/WWHN-164-CLOSURE-RUNBOOK.md`.

Reviewer phải khóa cùng full SHA và artifact checksum. Evidence từ SHA khác không được dùng để đóng task. Với sửa trực tiếp `m1`, review được ghi trên commit/Jira sau CI thay vì tự tạo nhánh/PR trái yêu cầu.

## 6. Closure matrix

| Gate | Owner | Jira/record | Trạng thái sau commit |
|---|---|---|---|
| Security/API/DB independent retest | QA1 | WWHN-166 | Pending reviewer |
| Browser/viewport | QA2 | WWHN-167 | Pending reviewer |
| OpenAPI/Postman approval | Tech Lead/FE/QA | WWHN-164 comment | Pending reviewer |
| Direct commit + CI review | Tech Lead/QA1 | GitHub/Jira | Pending reviewer |
| Data-scope DB evidence | QA1/Security | WWHN-166/WWHN-164 | Pending reviewer |
| Audit monitoring/reconcile | Tech/Security/DevOps | ops evidence link | Pending reviewer |
| Credential rotation/N-A | DevOps/Security | protected change record | Pending reviewer |
| Permission traceability | BA/QA1 | WWHN-164 comment | Pending reviewer |

## 7. Definition of Done chính xác

Phần BE được xem là Ready for Review khi workflow final SHA xanh và artifact tải được. WWHN-164 chỉ đạt 100% khi tám gate độc lập phía trên có reviewer, timestamp, exact SHA và evidence link. Việc tự ghi approval để nâng điểm là không hợp lệ và bị CI guard ngăn chặn.
