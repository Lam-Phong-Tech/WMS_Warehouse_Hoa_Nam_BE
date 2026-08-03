# WWHN-180 — QA2 Independent Retest Protocol

> Trạng thái: **PROPOSED**. Đây là protocol thực thi, không phải bằng chứng QA PASS. QA2 phải chạy độc lập trên artifact/workflow PASS của exact SHA.

## 1. Input bắt buộc

- Branch: `m1`
- Exact SHA từ `build-metadata.json.commit_sha`
- CI run URL và artifact `api-data-evidence-<exact_sha>`
- `SHA256SUMS` và `SHA256SUMS.sha256` đều verify PASS
- Runtime/deployment SHA: `<sha hoặc N/A nếu chỉ test CI build>`
- Database/schema version: `<migration head>`
- Reviewer QA2 không phải BE1

## 2. Trình tự retest

1. Verify artifact digest; đối chiếu exact SHA với CI run và source tree.
2. Review OpenAPI JSON/YAML, route list, permission catalog và Postman v1.1.0.
3. Chạy full PHPUnit và focused tests từ artifact/source exact SHA.
4. Chạy Postman/Newman 23 request trên môi trường QA có DB cô lập.
5. Thu DB snapshot trước/sau; không chỉnh sửa thủ công giữa hai snapshot.
6. Chạy race test bằng ít nhất hai worker/connection độc lập trên cùng document.
7. Chạy retry sau mô phỏng mất response: request đầu commit nhưng client không nhận response; retry cùng key/payload phải replay.
8. Chạy reconcile và đối chiếu movement, balance, posting event, idempotency row.
9. Attach raw logs/result JSON/DB queries; ghi reviewer, timestamp, SHA và digest trên WWHN-182.

## 3. Acceptance matrix

| Nhóm | Điều kiện PASS |
|---|---|
| Scan | `stock_effect=NONE`; không đổi balance/movement/status; RBAC/scope negative đúng mã lỗi |
| Receipt | Missing key 428; first post đúng một movement/effect; replay giữ nguyên response/movement; payload conflict 409; different-key double post 409 |
| Issue | Tương tự receipt; insufficient stock rollback toàn transaction |
| Atomicity | Business write và response snapshot cùng commit/rollback; không tồn tại cửa sổ committed stock + processing lease |
| Race | Chỉ một stock effect; unique movement business key; loser 409/replay hợp lệ |
| DB delta | Canonical flow tạo đúng 2 movement, 2 posting event COMMITTED, 4 idempotency row `done` |
| RBAC | User thiếu quyền nhận 403; không có DB write |
| Reconcile | `summary.status=PASS`, `mismatch_count=0`, balance bằng signed ledger sum |
| Artifact | Exact SHA khớp; cả hai checksum verify PASS; không có credential trong committed Postman files |

## 4. Evidence tối thiểu phải attach

- `build-metadata.json`, `SHA256SUMS`, `SHA256SUMS.sha256`
- `phpunit.raw.log` và focused logs
- `newman.raw.log`, `newman.result.json`
- `route-list.raw.json`, `permission-catalog.json`
- `db-before.json`, `db-after.json`, `wwhn180-db-delta.json`
- `reconciliation-report.json`
- Raw race/timeout-after-commit logs từ QA environment
- DB query/result xác nhận movement/business key/posting event/idempotency snapshot

## 5. QA2 sign-off

- Reviewer QA2: `<họ tên/Jira user>`
- Executed at: `<ISO-8601 +07:00>`
- Exact SHA: `<sha>`
- Artifact digest: `<SHA256SUMS.sha256>`
- Environment: `<QA/staging identifier>`
- Result: `PASS | FAIL | CONDITIONAL`
- Failed case IDs/defects: `<IDs hoặc NONE>`
- WWHN-182 comment/attachment URLs: `<urls>`

Không chuyển WWHN-182 sang Done khi thiếu raw evidence hoặc khi evidence không cùng exact SHA/digest.
