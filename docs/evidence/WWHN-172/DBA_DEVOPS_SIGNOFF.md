# WWHN-172 — DBA/DevOps independent sign-off

**Trạng thái:** `PENDING` — template này không phải xác nhận hoàn tất.

| Field | Required value |
|---|---|
| Exact commit SHA | 40-char SHA của `m1` |
| GitHub Actions run | URL matrix WWHN-172 |
| Engine/version | PostgreSQL/MySQL/SQLite CI |
| Artifact | `wwhn-172-evidence-<engine>-<sha>` |
| SHA256SUMS | Verified PASS |
| Preflight | PASS, failed=0 |
| Pretend/apply | Exit 0, raw logs |
| Rollback/re-apply | Exit 0, raw logs |
| Reviewer/result | Tên + Jira account + APPROVED/REJECTED |

Reviewer kiểm tra trực tiếp artifact, không ký dựa trên nội dung tự khai trong `WWHN-172.md`.
