# WWHN-180 — CI runtime credential policy

## Mục đích

Workflow `.github/workflows/wwhn-180-api-posting-contract.yml` phải tự chạy được trên exact commit mà không phụ thuộc repository secret cấu hình thủ công và không đưa credential vào Git, log hoặc artifact.

## Cơ chế đã khóa

1. Workflow sinh một password ngẫu nhiên và một JWT signing secret ngẫu nhiên bằng `openssl rand` cho **mỗi workflow run**.
2. GitHub Actions mask hai giá trị ngay khi sinh.
3. Password được truyền qua process environment `CI_DEMO_PASSWORD`; `AuthDatabaseSeeder` dùng chính giá trị này để hash password cho các tài khoản evidence sau `migrate:fresh`.
4. Newman chỉ nhận credential qua environment của process. Postman collection/environment trong repo để trống password/token.
5. Sau khi Newman và API test hoàn tất, `scripts/redact-wwhn180-evidence.mjs` thay mọi runtime password/JWT trong raw logs và JSON bằng marker redacted.
6. `credential-hygiene.json` phải có `status=PASS`, `residual_runtime_secret_count=0` và `residual_jwt_count=0` trước khi tạo `SHA256SUMS`.
7. Artifact chỉ được upload sau khi toàn bộ structured gate, OpenAPI lint, test, DB delta, reconciliation và hash verification PASS.

## Không còn repository secrets bắt buộc

WWHN-180 không yêu cầu:

- `WWHN180_PASSWORD`;
- `WWHN180_NO_PERMISSION_PASSWORD`;
- password seed hard-code trong workflow.

Hai biến Newman vẫn tồn tại ở runtime nhưng được gán từ cùng password ngẫu nhiên của run. Chúng không được commit hoặc ghi vào artifact.

## Tài khoản evidence

| Tài khoản | Mục đích |
|---|---|
| `manager01@gmail.com` | Happy path và API contract |
| `test-no-permission@hoanam.com` | RBAC negative |

Password của hai tài khoản chỉ tồn tại trong process environment của workflow run và DB SQLite tạm thời.

## Cách xác minh passing artifact

```bash
sha256sum --check SHA256SUMS
sha256sum --check SHA256SUMS.sha256
```

Sau đó kiểm tra:

- `build-metadata.json.credential_mode = EPHEMERAL_PER_WORKFLOW_RUN`;
- `credential-hygiene.json.status = PASS`;
- `credential-hygiene.json.residual_runtime_secret_count = 0`;
- `credential-hygiene.json.residual_jwt_count = 0`;
- `build-metadata.json.commit_sha` bằng exact SHA được BA, FE1 và QA2 review.

## Giới hạn thẩm quyền

Workflow PASS chứng minh BE/CI evidence trên exact SHA. Nó không thay thế FE1 mapping sign-off, QA2 independent retest hoặc BA G1 decision.
