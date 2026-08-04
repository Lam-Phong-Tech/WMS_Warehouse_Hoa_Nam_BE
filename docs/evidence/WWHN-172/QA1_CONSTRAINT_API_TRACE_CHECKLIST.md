# WWHN-173 — QA1 constraint/API trace checklist

**Trạng thái:** `PENDING QA1 EXECUTION`.

1. Same-version race: một success, một 409; version tăng một lần.
2. Opposite component edge race: không tạo cycle.
3. Physical-code/serial race: một owner; loser 409; không SQL leak.
4. Idempotency exact replay và same-key/different-payload.
5. Audit row cùng transaction; before/after/request_id/actor/action.
6. Force audit failure: business/idempotency/outbox rollback.
7. `APP_DEBUG=true`: không exception/file/line/trace/SQL/vendor path.
8. RBAC 401/403 không side effect.
9. Verify exact SHA, artifact, `SHA256SUMS` và source-tree hash.

QA1 comment ghi exact SHA, CI run, engine artifact, PASS/FAIL, defect link và reviewer account.
