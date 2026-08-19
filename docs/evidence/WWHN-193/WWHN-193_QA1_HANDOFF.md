# WWHN-193 — Bàn giao QA1 (DEV193-P1-02R / P1-04R / EXT-DEP-01R)

DEV **không** ký thay QA1. Tài liệu này chỉ cung cấp đúng thứ QA1 cần để chạy lại và phán quyết
trên **exact candidate**.

## 0. Xác định đúng revision đang xét

```bash
git -C WMS_Warehouse_Hoa_Nam_BE fetch origin
git -C WMS_Warehouse_Hoa_Nam_BE rev-parse HEAD          # phải bằng SHA trong comment Jira
node scripts/verify-wwhn193-closure.mjs --expect-sha <SHA_TRONG_COMMENT>
```

Verifier fail-closed: sai SHA, sai digest artifact, hoặc `base` không phải ancestor → thoát khác 0.

> Nhánh `m1-be-2` **không còn** là nguồn candidate của WWHN-193 (xem `WWHN-193_PINS.json`
> → `source_conflict_disposition`). Nếu QA1 đang chạy trên `m1-be-2`, kết quả không dùng được.

## 1. Chạy trên sqlite (nhanh, dùng để khoanh vùng)

```bash
php vendor/bin/phpunit \
  tests/Feature/Wwhn193PutawayLifecycleTest.php \
  tests/Feature/Wwhn193PutawayAuditFailClosedTest.php \
  tests/Feature/Wwhn193PutawayRetryRecoveryContractTest.php \
  tests/Feature/Wwhn193ContainerPutawayBypassRegressionTest.php \
  tests/Feature/Wwhn193PutawayDependencyChainTest.php \
  tests/Feature/Flow02InboundPutawayAlignmentTest.php \
  tests/Feature/FoundationInboundPutawayReadinessTest.php \
  tests/Feature/ContainerTest.php \
  --no-coverage
```

## 2. Chạy trên PostgreSQL — **bắt buộc cho DEV193-P1-04R**

`phpunit.xml` không đặt `force="true"` cho `<env>`, nên biến shell thắng:

```powershell
$env:DB_CONNECTION="pgsql"; $env:DB_HOST="127.0.0.1"; $env:DB_PORT="5432"
$env:DB_DATABASE="hoa_nam_audit"; $env:DB_USERNAME="postgres"; $env:DB_PASSWORD="postgres"
php vendor/bin/phpunit tests/Feature/Wwhn193*.php --no-coverage
```

Kết quả DEV đo được tại revision này: **42 test / 42 pass / 0 fail / 0 error / 290 assertion**,
PostgreSQL **16.14**.

Sau khi chạy, mở `build/evidence/wwhn-193/audit-fail-closed-proof-pgsql.json` và kiểm:

| Trường | Giá trị phải thấy |
|---|---|
| `engine.driver` | `pgsql` (nếu là `sqlite` thì bằng chứng **không** dùng được cho P1-04R) |
| `audit_write_policy` | `fail_closed` |
| `http_status` / `error_code` | `500` / `INTERNAL_SERVER_ERROR` |
| `db_before` vs `db_after` | giống hệt nhau |
| `audit_log_rows_delta` | `0` |
| `audit_outbox_rows` | `0` |
| `db_after.stock_movements` | `0` |
| `raw_error_response` | không chứa `trace` / `file` / `line` / đường dẫn `/vendor/` |

## 3. Ma trận kịch bản QA1 phải phán quyết (DEV193-P1-02R)

### 3.1 Vòng đời + RBAC + lỗi + audit

| # | Kịch bản | Kỳ vọng |
|---|---|---|
| 1 | 5 route putaway khi ẩn danh | `401 UNAUTHENTICATED` |
| 2 | Có `putaway.view`, không `putaway.manage` → create/confirm/cancel | `403` |
| 3 | `WAREHOUSE_STAFF` (có `putaway.manage`, **không** `container.manage`) trên cả `/putaway/*` và `/putaway-tasks` | `2xx` |
| 4 | Confirm thiếu `If-Match` | `428 PRECONDITION_REQUIRED` |
| 5 | Confirm sai version | `412 STALE_VERSION` |
| 6 | Confirm id không tồn tại | `404 PUTAWAY_NOT_FOUND` |
| 7 | Tạo DRAFT thứ hai cho cùng BOX | `409 PUTAWAY_DRAFT_EXISTS` |
| 8 | Confirm → BOX `STORED`, `movement_created=false` | `stock_movements` không đổi |
| 9 | audit_log có `PUTAWAY_DRAFT_CREATE` / `PUTAWAY_CONFIRM` / `PUTAWAY_CANCEL` | có đủ 3 |

### 3.2 Retry / recovery (DEV193-P1-03R)

| # | Kịch bản | Kỳ vọng |
|---|---|---|
| 10 | Confirm có `Idempotency-Key`, gửi lại y hệt | `200`, `Idempotent-Replay: true`, `data.replayed=true`, version **không** tăng lần hai |
| 11 | Cùng key, body khác | `409 IDEMPOTENCY_CONFLICT`, state không đổi |
| 12 | Cùng key khi lease trước còn hạn | `409 CONCURRENCY_CONFLICT`, state không đổi |
| 13 | Không key, retry sau khi đã confirm | `409 STATE_CONFLICT` + `error.details.recovery.outcome = ALREADY_APPLIED_BY_CALLER`, `safe_to_treat_as_success = true` |
| 14 | Người khác retry với version cũ | `outcome = ALREADY_APPLIED_BY_OTHER`, response **không** chứa user id của người kia |
| 15 | Cancel một task đã CONFIRMED | `outcome = FINALIZED_DIFFERENT_OUTCOME`, `safe_to_treat_as_success = false` |
| 16 | Confirm **không** gửi `Idempotency-Key` | vẫn `200` (Annex C: key là tuỳ chọn), **không** phải 428 |

### 3.3 Chống bypass (DEV193-P1-05R)

| # | Kịch bản | Kỳ vọng |
|---|---|---|
| 17 | `POST /containers` kèm `parent_container_id` | `422 PUTAWAY_MUST_USE_TASK_FLOW`; **không** có container, **không** có `putaway_events` |
| 18 | `POST /containers` kèm `status=STORED` (và `RECEIVED_PENDING_PUTAWAY`, `ISSUED`) | `422 CONTAINER_STATUS_NOT_CREATABLE` |
| 19 | `PATCH /containers/{id}` kèm `status=STORED` | `422 CONTAINER_STATUS_LIFECYCLE_MANAGED`, state giữ nguyên |
| 20 | `POST /putaway-tasks` cho BOX chưa có phiếu POSTED | `409 BOX_NOT_PUTAWAY_ELIGIBLE`, `error.details.reason = NO_POSTED_RECEIPT` |
| 21 | Confirm một DRAFT đã mất linkage chứng từ | `409` cùng mã, DRAFT giữ nguyên |

### 3.4 Chuỗi phụ thuộc (EXT-DEP-01R)

| # | Kịch bản | Kỳ vọng |
|---|---|---|
| 22 | Post Receipt → movement/balance → BOX eligible → Putaway, **cùng build** | `movements` 0→1, `balance` = số lượng Post, BOX `RECEIVED_PENDING_PUTAWAY`→`STORED`, putaway giữ `movements` = 1 |
| 23 | Đối soát ledger vs projection | `mismatch_count = 0` |
| 24 | BOX đã scan nhưng phiếu **chưa** POSTED | `409 BOX_NOT_PUTAWAY_ELIGIBLE` |

## 4. Artifact QA1 cần đính kèm khi phán quyết

1. Output thô của lệnh chạy (kèm dòng tổng test/pass/fail).
2. `build/evidence/wwhn-193/*.json` **do chính lần chạy của QA1 sinh ra** (xoá trước khi chạy
   để chắc chắn không đọc lại file đã commit).
3. `git rev-parse HEAD` + tên/`version` của PostgreSQL.
4. Verdict PASS/FAIL cho từng dòng trong ma trận §3.

## 5. Bẫy đã gặp — để QA1 không mất thời gian lặp lại

- **Fixture BOX dựng tay không còn đủ điều kiện.** Sau P1-05R, `status = RECEIVED_PENDING_PUTAWAY`
  một mình **không** mở được putaway; phải có phiếu nhập POSTED thật. Dùng
  `Tests\Feature\Concerns\PreparesPutawayFixtures::seedPostedReceiptForBox()` hoặc chạy hết luồng HTTP.
- **PostgreSQL khác sqlite ở ba chỗ** đã gặp trong vòng này: trigger mirror `idempotency_records`
  đòi `request_hash` NOT NULL; `hn_reject_posted_document_change()` giữ phiếu POSTED bất biến
  (không UPDATE status được, phải dùng reversal); `app_users` trên PG có hình dạng cột khác
  `app_user` nên đừng truy vấn thẳng tên bảng.
- **Khoá idempotency của FE là suy diễn** (`ptw-{op}-{id}-v{version}`), không random. Nếu QA1
  tự sinh key ngẫu nhiên mỗi lần bấm thì kịch bản #10 sẽ không bao giờ replay.
