# QA1 independent test pack — WWHN-190 / WWHN-192 (register item DEV190-P1-04R2)

| Trường | Giá trị |
|---|---|
| Register item | `DEV190-P1-04R2` (P1, Hard Gate) — QA1 chạy độc lập trên **đúng RC SHA** |
| Yêu cầu bao phủ | auth/RBAC, 428, 412, 409, 422, replay, payload conflict, true race, rollback, audit, movement/balance/reconcile |
| RC SHA | xem `docs/evidence/WWHN-190/RC_PIN.json` (do DEV pin sau khi commit) |
| Nguồn hợp đồng để assert | `docs/api-contract/releases/WWHN-190/1.8.0/openapi.json` (**không** dùng WWHN-180 v1.7.0 — xem §0) |

## §0. Đọc trước khi chạy: 3 chỗ QA phải đổi so với bộ test cũ

| Bộ cũ (WWHN-180 v1.7.0) | Đúng theo runtime + SRS §8.2 |
|---|---|
| `409 IDEMPOTENCY_ERROR` | **`409 IDEMPOTENCY_CONFLICT`** |
| không có `If-Match` trong request | **`If-Match` bắt buộc**; thiếu → `428 PRECONDITION_REQUIRED`, sai định dạng → `422 INVALID_VERSION`, lệch → `412 STALE_VERSION` |
| `W180-ROUTE-001` yêu cầu tồn tại `/api/v1/stocktake/sessions/{id}/apply-adjustment` | **OBSOLETE** — route đã bị gỡ theo REQ-SRS-037 + DEC-NO-STOCKTAKE-001, đóng bằng DISP-STK-236. Test case này phải bị đánh dấu N/A, không phải fail. |

Ngoài ra `x-permission` đúng là `inbound.post` / `outbound.post` / `inbound.scan` / `outbound.scan`,
không phải `stock_document.*`.

## §1. Chuẩn bị môi trường

```bash
git fetch origin m1
git checkout <RC_SHA>              # đúng SHA trong RC_PIN.json, không dùng HEAD
composer install --no-interaction --prefer-dist
npm ci --ignore-scripts
cp .env.example .env && php artisan key:generate
```

PostgreSQL cho phần race/reconcile (dùng DB riêng, không dùng DB dev):

```bash
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=qa1_wwhn190 DB_USERNAME=postgres DB_PASSWORD=postgres
php artisan migrate --force
```

## §2. Ma trận thực thi

| # | Bao phủ | Lệnh | Kỳ vọng |
|---|---|---|---|
| 1 | Hợp đồng ↔ runtime (permission, bề mặt đã gỡ) | `php artisan route:list --json > build/wwhn190/route-list.json && node scripts/build-wwhn190-posting-contract.mjs --check --routes build/wwhn190/route-list.json` | `status: PASS`, `errors: []`, note `REMOVED-SURFACE-ABSENT` |
| 2 | auth/RBAC, 428, 412, 409, 422, 404, replay, payload conflict, full-post | `php vendor/bin/phpunit tests/Feature/Wwhn190PostingContractParityTest.php` | 15 test PASS |
| 3 | true race 5 kịch bản (2 process, 2 backend PG) | `php scripts/wwhn190-postgres-concurrency.php docs/evidence/WWHN-190/concurrency` | exit 0, `CONCURRENCY_EVIDENCE.json.status = PASS`, `failed_checks: []` |
| 4 | rollback / không half-post / lease released | cùng lệnh #3, đọc scenario `05-forced-rollback` | `no_movement_written_during_fault`, `document_still_scanning_version_1`, `lease_released_no_processing_row`, `retry_after_fault_cleared_succeeds` đều `true` |
| 5 | movement/balance/reconcile | `php scripts/wwhn190-receipt-reconciliation.php docs/evidence/WWHN-190/reconciliation` | exit 0, `status: PASS`, mọi counter = 0, `accepted_receipt_qty_total == signed_receipt_movement_total == balance_on_hand_total == ledger_sum_total` |
| 6 | audit | `php vendor/bin/phpunit tests/Feature/PostReceiptAuditLogTest.php` | PASS — 1 row `audit_log` `action=POST_RECEIPT`, replay **không** tạo row thứ hai |
| 7 | guard cũ không regression | `php vendor/bin/phpunit tests/Feature/PostReceiptConcurrencyBehaviorTest.php tests/Feature/StockDocumentIdempotencyTest.php tests/Feature/StockDocumentConcurrencyGuardTest.php` | PASS |

## §3. Bảng mã lỗi để assert (nguồn: runtime + SRS §8.2)

| Tình huống | HTTP | error_code |
|---|---|---|
| Chưa xác thực | 401 | `UNAUTHENTICATED` |
| Thiếu permission `inbound.post` | 403 | `ACCESS_DENIED` |
| Ngoài warehouse data scope | 403 | `WAREHOUSE_SCOPE_DENIED` |
| Không tìm thấy chứng từ | 404 | `RESOURCE_NOT_FOUND` |
| Chứng từ không ở SCANNING | 409 | `STATE_CONFLICT` |
| Cùng key, khác payload | 409 | `IDEMPOTENCY_CONFLICT` |
| Hai request cùng key chồng nhau (lease bận) | 409 | `CONCURRENCY_CONFLICT` |
| If-Match lệch version | 412 | `STALE_VERSION` |
| If-Match sai định dạng | 422 | `INVALID_VERSION` |
| Idempotency-Key < 8 hoặc > 100 ký tự | 422 | `VALIDATION_ERROR` |
| Có dòng chưa scan đủ | 422 | `FULL_POST_REQUIRED` |
| Thiếu `If-Match` **hoặc** thiếu `Idempotency-Key` | 428 | `PRECONDITION_REQUIRED` |

Envelope lỗi có **cả** `error_code` (phẳng) và `error.code` (lồng); `request_id`/`timestamp` nằm trong `meta`.
Response 200 của post-receipt có header `ETag` (version mới) và `Idempotent-Replay: true|false`,
đồng thời `data.replayed` phản chiếu đúng header đó.

## §4. Nộp kết quả

1. Ghi rõ **RC SHA đã checkout** và `git rev-parse HEAD` trong report.
2. Đính kèm `docs/evidence/WWHN-190/concurrency/CONCURRENCY_EVIDENCE.json`,
   `docs/evidence/WWHN-190/reconciliation/RECONCILIATION_REPORT.json`,
   `reconciliation-raw-sql.txt` sinh ra từ máy QA (không dùng lại file của DEV).
3. Mọi defect mở phải gắn: kịch bản, mã lỗi quan sát được, mã lỗi hợp đồng khai, và ảnh chụp DB trước/sau.
4. Nếu bước nào không chạy được, ghi **SKIPPED + lý do** — không được coi là PASS.
