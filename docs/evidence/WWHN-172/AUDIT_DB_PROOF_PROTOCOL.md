# WWHN-172 — Audit DB before/after và fail-closed proof

Evidence collector tạo:

- `db-before.json`: fixture/business/audit state trước service proof;
- `service-proof.json`: business row, audit row, idempotency row, exact replay và outbox assertion;
- `db-after.json`: state sau proof;
- `phpunit-audit.raw.log`: forced audit failure;
- `phpunit-aggregate.raw.log`: idempotency/cycle/version/container guards.

Điều kiện PASS:

- successful mutation có đúng business row và audit row;
- exact replay không tạo business/audit row thứ hai;
- `audit_outbox=0` sau successful proof;
- forced audit failure rollback product/idempotency/outbox;
- response không có exception, SQL, file path hoặc trace;
- file nằm trong `SHA256SUMS` và artifact gắn exact SHA + DB engine.
