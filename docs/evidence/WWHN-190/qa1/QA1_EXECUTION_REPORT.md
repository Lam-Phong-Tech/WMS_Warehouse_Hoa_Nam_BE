# QA1 independent execution report — WWHN-190 / WWHN-192 (DEV190-P1-04R2)

Thực thi độc lập theo `docs/evidence/WWHN-190/QA1_INDEPENDENT_TEST_PACK.md`, dùng
database và thư mục evidence riêng (`hn_wwhn190_qa1`, `docs/evidence/WWHN-190/qa1/`) —
**không** dùng lại file/DB của DEV (`hn_wwhn190_r3`).

| Trường | Giá trị |
|---|---|
| RC SHA đã pin (`RC_PIN.json`) | `9b184439a7f25dbaed4b6c19af638d143b7b2061` |
| `git rev-parse HEAD` tại lúc chạy | `71c069f64dd8e47a3ebaf4b6d4483cafa01ac8f7` (chỉ 1 commit metadata `docs(WWHN-190): re-pin...` chồng lên `9b18443`, **không đổi bất kỳ file runtime nào** — `git diff 9b18443 71c069f -- Modules/ app/ scripts/ tests/` rỗng, xem xác nhận bên dưới) |
| Database | PostgreSQL 16.14, `hn_wwhn190_qa1` (migrate --force sạch từ đầu, không tái sử dụng dữ liệu cũ) |
| Người thực thi | Phiên rà soát độc lập WWHN-190 (không phải người viết code BE1/Quang) |

## Xác nhận working tree khớp RC SHA cho phần runtime

```
git diff 9b184439a7f25dbaed4b6c19af638d143b7b2061 HEAD -- \
  Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php \
  Modules/Warehouse/app/Services/CurrentBaselineStockService.php \
  app/Support/IdempotencyStore.php \
  scripts/wwhn190-postgres-concurrency.php scripts/wwhn190-concurrency-worker.php \
  scripts/wwhn190-receipt-reconciliation.php scripts/build-wwhn190-posting-contract.mjs \
  tests/Feature/Wwhn190PostingContractParityTest.php
# → (rỗng — commit sau rc_sha chỉ đổi docs/evidence, đúng note_pin_metadata_only)
```

## Ma trận thực thi (§2 test pack)

| # | Bao phủ | Lệnh | Kết quả |
|---|---|---|---|
| 1 | Hợp đồng ↔ runtime | `build-wwhn190-posting-contract.mjs --check` | `status: PASS`, `errors: []`, note `REMOVED-SURFACE-ABSENT` |
| 2 | auth/RBAC, 428/412/409/422/404, replay, payload conflict, full-post | `Wwhn190PostingContractParityTest` | PASS |
| 3 | true race 5 kịch bản (2 process, 2 backend PG) | `wwhn190-postgres-concurrency.php` trên `hn_wwhn190_qa1` | exit 0, `docs/evidence/WWHN-190/qa1/concurrency/CONCURRENCY_EVIDENCE.json` → `status: PASS`, `failed_checks: []` |
| 4 | rollback / không half-post / lease released | scenario `05-forced-rollback` trong file trên | `no_movement_written_during_fault=true`, `document_still_scanning_version_1=true`, `lease_released_no_processing_row=true`, `retry_after_fault_cleared_succeeds=true` |
| 5 | movement/balance/reconcile | `wwhn190-receipt-reconciliation.php` | `docs/evidence/WWHN-190/qa1/reconciliation/RECONCILIATION_REPORT.json` → `status: PASS`, mọi counter = 0, `accepted_receipt_qty_total(20) == signed_receipt_movement_total(20) == balance_on_hand_total(20) == ledger_sum_total(20)` |
| 6 | audit | `PostReceiptAuditLogTest` | PASS — 1 row `audit_log`, replay không tạo row thứ 2 |
| 7 | guard cũ không regression | `PostReceiptConcurrencyBehaviorTest` + `StockDocumentIdempotencyTest` + `StockDocumentConcurrencyGuardTest` | PASS (26 test, 245 assertion) |

## Bảng mã lỗi §3 — xác nhận qua kết quả 5 kịch bản concurrency + parity test

| Tình huống | Kỳ vọng | Quan sát |
|---|---|---|
| Cùng key, khác payload | `409 IDEMPOTENCY_CONFLICT` | Khớp (kịch bản 04) |
| If-Match lệch version | `412 STALE_VERSION` | Khớp (kịch bản 02, 03) |
| Hai request cùng key chồng nhau | `409 CONCURRENCY_CONFLICT` | Khớp (kịch bản 01) |
| Thiếu If-Match/Idempotency-Key | `428 PRECONDITION_REQUIRED` | Khớp (`Wwhn190PostingContractParityTest`) |
| If-Match sai định dạng | `422 INVALID_VERSION` | Khớp (kịch bản 03 follow-up) |

## Kết luận

Không phát hiện defect mới. Kết quả **khớp hoàn toàn** với vòng DEV re-verification
(`RC_PIN.json` §`local_verification_r3`), chạy trên DB và output hoàn toàn độc lập —
đủ điều kiện đóng `DEV190-P1-04R2` ở phần "chạy được, PASS". Phần còn lại của register
item (ai đứng tên QA1 chính thức trên Jira) là quyết định vai trò, không phải kỹ thuật —
xem comment sign-off soạn riêng cho Tech Lead/BA.

Không chạy/động CI (GitHub Actions) — toàn bộ chạy local qua Docker theo yêu cầu người dùng.
