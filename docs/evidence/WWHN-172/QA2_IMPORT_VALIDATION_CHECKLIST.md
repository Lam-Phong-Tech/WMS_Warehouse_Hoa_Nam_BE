# WWHN-174 — QA2 import/data validation checklist

**Trạng thái:** `PENDING QA2 EXECUTION`.

1. Template version `WWHN-172-SKU-IMPORT-v1.1.0`.
2. Đảo thứ tự cột vẫn map theo header name.
3. Thiếu/duplicate/unknown header: 422, committed=false.
4. Invalid code/name/enum/boolean: row+field+code rõ ràng, không silent default.
5. Duplicate file/DB và inactive existing: toàn file reject.
6. Một valid + một invalid row: zero insert/update/audit.
7. Valid multi-row: tất cả commit, version/audit đúng.
8. Force audit failure: toàn bộ product/audit/outbox rollback.
9. File >10MB, >1.000 rows, sai extension: reject.
10. Verify SHA-256, header_map, transaction_mode, partial_write=false.

QA2 comment reference exact SHA, artifact/hash, fixture SHA-256, DB before/after và reviewer account.
