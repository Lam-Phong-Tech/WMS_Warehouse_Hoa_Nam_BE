# WWHN-164 — Closure Review Runbook

Tài liệu này khóa quy trình nghiệm thu cho auth/RBAC/audit/error contract trên **đúng commit SHA của nhánh `m1`**. Automated evidence chứng minh code/contract/test; trạng thái `APPROVED` chỉ được ghi bởi đúng reviewer trên GitHub/Jira.

## 1. Artifact chuẩn

Workflow: `.github/workflows/wwhn-164-closure-gate.yml`

Artifact: `wwhn-164-closure-evidence-<FULL_SHA>`

Nội dung bắt buộc:

- `build-metadata.json`: SHA, branch, run ID, PHP, DB engine, audit policy.
- `tests/phpunit-security.xml|log`: regression auth/RBAC/audit/data-scope.
- `tests/phpunit-full.xml|log`: full PHPUnit regression.
- `contracts/openapi.json`: contract generate từ runtime annotations.
- `contracts/postman.collection.json`: Postman generate từ chính OpenAPI artifact.
- `contracts/contract-parity.json`: kiểm tra endpoint auth canonical.
- `tests/runtime-permission-keys.json`: permission key lấy trực tiếp từ database runtime.
- `tests/credential-scan-files.txt`: phải rỗng.
- `review/signoff-register.json`: luôn khởi tạo Pending; automation không được tự duyệt.
- `SHA256SUMS`: checksum toàn bộ artifact.

## 2. FIX-01 — QA1 security/API/DB

QA1 checkout đúng SHA ghi trong `build-metadata.json`, dùng DB staging tách biệt, rồi chạy:

```bash
DB_CONNECTION=<staging-driver> php artisan test \
  tests/Feature/AuthRbacAuditHardeningTest.php \
  tests/Feature/SecurityRbacAuditNegativeTest.php
```

Bắt buộc xác nhận: missing/invalid/expired token = 401; thiếu permission = 403; refresh replay bị chặn và family bị revoke; audit redaction không lộ secret; audit primary/outbox failure đúng policy; negative API không lộ stack trace; Kho A không list/detail/mutate Kho B.

Closure evidence: command log nguyên bản, SHA, DB engine/schema version, request/response đã sanitize, DB rows before/after, reviewer và thời điểm. Cập nhật WWHN-166; BE1 không ký thay QA1.

## 3. FIX-02 — QA2 browser/viewport

QA2 chạy trên frontend build trỏ tới backend SHA tương ứng:

- login hợp lệ/sai;
- access/session expiry;
- logout và back navigation;
- direct URL khi chưa auth;
- trang 403;
- menu/action visibility theo role;
- tối thiểu desktop 1366×768 và mobile 390×844.

Closure evidence: browser/version, frontend build SHA, backend SHA, video hoặc screenshots có timestamp, test report. Cập nhật WWHN-167; backend chỉ ở trạng thái `Parent Ready` đến khi QA2 ký.

## 4. FIX-03 — OpenAPI/Postman approval

Reviewer tải cùng một artifact và kiểm tra checksum trước khi review:

```bash
sha256sum --check SHA256SUMS
npx redocly lint contracts/openapi.json
npx newman run contracts/postman.collection.json --folder Auth \
  -e <sanitized-staging-environment.json>
```

Tech Lead kiểm tra security/error contract; FE kiểm tra request/response integration; QA kiểm tra import và scenario. Approval phải ghi full SHA, artifact name/digest và version OpenAPI. Không duyệt file từ hai run/SHA khác nhau.

## 5. FIX-04 — PR/CI evidence khi sửa trực tiếp `m1`

Theo yêu cầu không tạo nhánh/PR mới, closure dùng **direct-commit review record** thay PR:

- commit URL và full SHA;
- parent SHA;
- danh sách changed files từ GitHub compare;
- workflow run, jobs, environment và artifact digest;
- review comment độc lập của Tech Lead/QA1 trên Jira hoặc GitHub commit.

Direct commit không tự tạo ra review approval. Reviewer phải xác nhận sau khi CI hoàn tất.

## 6. FIX-05 — Data-scope

QA1/Security kiểm tra ba lớp trên cùng dữ liệu Kho A/Kho B:

| Case | Expected |
|---|---|
| List bằng user scope Kho A | Không xuất hiện Kho B |
| Detail UUID Kho B | `403 ACCESS_DENIED` |
| Mutation UUID Kho B | `403 ACCESS_DENIED`, DB không đổi |

Evidence phải gồm seed/setup, actor-role-scope mapping, SQL/DB snapshot đã sanitize và transaction result.

## 7. FIX-06 — Audit operations

Policy baseline:

- `outbox_required`: audit primary lỗi thì ghi outbox; cả hai lỗi thì fail request.
- `fail_closed`: mutation nhạy cảm không hoàn tất nếu audit không bền vững.
- Best-effort chỉ được sử dụng với action đã được Tech Lead/Security phê duyệt rõ.

DevOps phải cung cấp: lịch `audit:reconcile`, lock chống chạy chồng, retry/backoff, alert threshold, dashboard/log query, runbook khi outbox backlog hoặc dead-letter tăng. Security/QA1 chạy failure injection và xác nhận request/audit outcome. Không coi log policy trong source là monitoring evidence.

## 8. FIX-07 — Credential rotation

Repository scan chỉ chứng minh **không còn credential dạng nhận diện trong tracked files**. DevOps/Security phải xác minh lịch sử chia sẻ ngoài repo:

- Nếu từng chia sẻ: revoke/rotate, kiểm tra token/session cũ không dùng được, ghi secret identifier dạng masked, owner, thời điểm, ticket/change record.
- Nếu chưa từng chia sẻ: ghi `NOT_APPLICABLE` kèm phạm vi nguồn đã kiểm tra và người xác nhận.

Không commit secret, token, password, private key hoặc rotation value vào repository/evidence.

## 9. FIX-08 — Permission traceability

BA/QA1 đối chiếu `tests/runtime-permission-keys.json` với:

- migration/seed hiện hành;
- middleware/controller permission key;
- OpenAPI/Postman scenario;
- role-permission matrix baseline.

Version xác nhận phải chứa full SHA và artifact checksum. Mismatch bất kỳ key nào là blocker, không sửa bằng cách chỉ đổi bảng tài liệu.

## 10. Closure decision

WWHN-164 chỉ đạt 100% khi tám signoff trong `signoff-register.json` có evidence link độc lập trên Jira/GitHub. BE1 có thể hoàn tất implementation và tạo artifact, nhưng không được tự đổi các gate QA/Lead/Security/DevOps/BA thành Approved.
