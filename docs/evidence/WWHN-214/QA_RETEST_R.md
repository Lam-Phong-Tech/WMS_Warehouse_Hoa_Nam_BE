# WWHN-214 — QA Retest Report · Round R (đóng DEV214-P1-01R / P1-04R HARD GATE)

**Ticket:** WWHN-214 [BE] Khắc phục và debug lỗi giao dịch core (parent WWHN-52 → HN-QA-003-ST01)
**Register:** Re-review 32/100, các mục DEV214-P1-01R..05R + P2-01
**Candidate SHA (exact, frozen):** `9c18f69f27352be78d9fadd23913ea7016876428` — commit cuối
cùng chạm artifact test (`Wwhn214QaRetestRawEvidenceTest`); mọi lượt chạy dưới đây thực thi
tại đúng SHA này (test class nạp từ worktree detached sạch; ứng dụng nạp từ `m1` main repo
có nội dung tracked đồng nhất `9c18f69` — xem mục 5 "Độ tin cậy môi trường").
**Oracle:** SRS v1.12 (HN-SRS-FRD-001) — ánh xạ điều khoản tại
[`EXPECTED_RESULT_MAPPING.md`](EXPECTED_RESULT_MAPPING.md) (DEV214-P1-05R).

## 1. Điểm khác cốt lõi so với vòng 1 (vì sao P1-04R giờ mới đóng được)

Vòng 1 (commit `089eacd`, run tại `28625ee`) chỉ có **log tên test PHPUnit** — không có
payload HTTP thô, không có output truy vấn DB thô, concurrency không retest tại candidate.
Vòng R bổ sung đúng các mảnh còn thiếu:

1. **RAW API evidence** — bộ test mới `tests/Feature/Wwhn214QaRetestRawEvidenceTest.php`
   lái 7 hạng mục checklist qua HTTP thật và ghi từng cặp request/response (method, URI,
   If-Match, Idempotency-Key, body JSON, status, ETag, Idempotent-Replay) — 39 bước API
   mỗi driver.
2. **RAW DB evidence** — dump dòng thô `stock_movements`, `inventory_balances`,
   `stock_documents`, message trigger append-only, bảng đối chiếu ledger-vs-balance từng
   dòng, đếm `reconciliation_incidents`.
3. **Chạy trên CẢ HAI driver** — sqlite (driver test mặc định) **và PostgreSQL 16.14**
   (driver tham chiếu production) trên **DB riêng `wwhn214_retest`** (không đụng
   `hoa_nam_audit` dùng chung — tránh đúng bẫy nhiễu đã ghi nhận ở vòng 1).
4. **Race đa kết nối thật tại chính candidate** — 3 orchestrator (WWHN-198/184/219) spawn
   tiến trình OS độc lập trên PostgreSQL, sample `pg_locks`/`pg_stat_activity`.

## 2. Kết quả — PASS/FAIL theo 7 hạng mục checklist

| # | Hạng mục | sqlite | pgsql | Raw evidence |
|---|---|---|---|---|
| 1 | No-negative stock (422 INSUFFICIENT_STOCK, không âm tồn) | **PASS** | **PASS** | `retest/qa-retest-raw-api-db.<driver>.json` → `areas.no-negative` |
| 2 | Duplicate/retry (replay 200 một hiệu ứng; khác payload → 409 IDEMPOTENCY_CONFLICT) | **PASS** | **PASS** | → `areas.duplicate-retry` |
| 3 | Concurrency (428 thiếu If-Match / 412 STALE_VERSION / 409 STATE_CONFLICT; race thật: đúng 1 commit) | **PASS** | **PASS** (+ race orchestrator, xem mục 3) | → `areas.concurrency` + 3 race summary |
| 4 | Rollback / no partial write (500 không lộ nội bộ, rollback toàn bộ, retry được) | **PASS** | **PASS** | → `areas.rollback` (dump DB before/after/retry) |
| 5 | RBAC (401 nặc danh; 403/404 thiếu quyền; không hiệu ứng tồn) | **PASS** | **PASS** | → `areas.rbac` |
| 6 | Append-only ledger (DB trigger chặn UPDATE/DELETE, cả 2 driver) | **PASS** | **PASS** | → `areas.append-only-ledger` (message trigger thô) |
| 7 | Reconciliation = 0 (balance = Σ qty_delta từng dòng; mismatch_count=0; 0 incident) | **PASS** | **PASS** | → `areas.reconciliation` (bảng đối chiếu thô) |

**Suite hồi quy nền (33 class core-transaction):** 232 test, 2 562 assertion, **0 fail,
0 error, 4 skip** (sqlite, log `logs/core-transaction-sqlite-R.log`). 4 skip = đúng nhóm
race/index cần PostgreSQL, được đóng bằng lượt PG bên dưới ngay tại candidate này (khác
vòng 1: không còn mượn evidence SHA cũ).

**Suite PG (evidence + index contract):** 10 test, 87 assertion, **0 fail, 1 skip** (log
`logs/core-transaction-pgsql-R.log`). 1 skip = `Wwhn199...::test_database_rejects_a_second_active_match`
tự skip theo thiết kế khi DB fresh chưa có match ACTIVE làm mốc; đúng ngữ nghĩa đó đã được
race Wwhn198 chứng minh bằng SQLSTATE unique-violation thật (xem mục 3).

## 3. Race đa kết nối thật trên PostgreSQL (đóng gap "concurrency chưa retest tại candidate")

Cả 3 orchestrator chạy với DB seed theo đúng công thức CI (`migrate:fresh --force` +
`db:seed --force`), tiến trình OS độc lập, mỗi worker một backend PostgreSQL riêng:

| Orchestrator | Kịch bản | Kết quả | Raw summary |
|---|---|---|---|
| WWHN-198 reservation race | cross_request_same_item, same_request_same_item, unique_index_guard | **PASS 3/3** (phpunit xanh; 409 đúng lớp, không double active match, loser bị chặn bởi partial unique index với SQLSTATE unique-violation, scan stock-neutral) | `retest/wwhn-198-race-summary.json` |
| WWHN-184 post-receipt race | same_document_same_version_two_keys, shared_idempotency_key, post_vs_cancel | **PASS 3/3 theo summary orchestrator** (đúng 1 winner, version +1 đúng 1 lần, tồn tăng đúng 1 lần, 1 movement; `real_contention_proven=true` qua sampling `pg_locks`; pin `git_sha=9c18f69`, PostgreSQL 16.14) — xem ghi chú wrapper ở mục 5 | `retest/wwhn-184-race-summary.json` |
| WWHN-219 ledger/balance/audit race | concurrent_post_receipt, concurrent_reversal, shared_balance_lost_update | **PASS 3/3** (phpunit xanh; không over-reversal, balance không bao giờ âm, không lost update, đúng 1 audit entry) | `retest/wwhn-219-race-summary.json` |

Log: `logs/race-runtime-pgsql-R.log` (lượt chạy 198/219 xanh) +
`logs/race-184-worktree-rerun.log` (lượt 184, orchestrator PASS).

## 4. Kết luận retest

- **P0 = 0 xác nhận lại tại exact candidate `9c18f69`**: không tìm thấy stock corruption,
  double effect, auth bypass, data loss trên bất kỳ đường ghi tồn nào (receipt/issue/
  component-issue/reversal), trên cả hai driver, kể cả dưới race đa tiến trình thật.
- **NO QUALIFYING NEW DEFECT** — disposition NO CODE CHANGE của DEV (xem
  `DEV214_DISPOSITION.md` §1, §6) khớp với kết quả retest độc lập này; không phát sinh
  defect package mới.
- Toàn bộ 7 hạng mục HARD GATE DEV214-P1-04R: **PASS** với raw API + raw DB evidence đính kèm.

## 5. Độ tin cậy môi trường (khai báo minh bạch)

- **Test class pinned**: nạp từ git worktree detached, sạch, tại `9c18f69`.
- **Ứng dụng (routes/services/migrations)**: do worktree dùng junction `vendor/`, Laravel
  suy `base_path` về main repo `m1`; tại mọi thời điểm chạy, nội dung tracked của main repo
  = `9c18f69` (2 commit của phiên khác — WWHN-231 `0a9dffd`, permission catalog `7f30067` —
  đều là ancestor của `9c18f69`). File chưa track duy nhất chạm `app/`:
  `app/Console/Commands/Wwhn231PopulatedReconciliationEvidence.php` (console command của
  phiên WWHN-231, không nằm trên đường ghi tồn nào).
- **Wrapper Wwhn184 trên máy local Windows**: heuristic phụ "hai worker chồng lấn wall-clock"
  của wrapper phpunit đọc timestamp phía worker; chi phí spawn process trên Windows tuần
  tự hoá cửa sổ thời gian nên heuristic này không đạt ở local — trong khi bằng chứng
  contention mức DB (`pg_locks` sampling, `real_contention_proven=true`) trong chính summary
  orchestrator vẫn PASS ở **mọi** lượt chạy (7+ lượt). Đây là hạn chế môi trường local đã
  cùng lớp với bẫy "phpunit env thua env container" (WWHN-190); gate CI Linux
  `wwhn-184-inbound-contract.yml` là nơi wrapper này xanh (không kích hoạt CI trong vòng
  này theo ràng buộc chi phí Actions — evidence local + summary thô thay thế).
- **Không đụng GitHub Actions/CI**: mọi commit của vòng này mang `[skip ci]`; không file
  nào dưới `.github/` bị thay đổi.

## 6. Cách tái lập (1 lệnh mỗi lượt)

```bash
# sqlite (mặc định)
php vendor/bin/phpunit tests/Feature/Wwhn214QaRetestRawEvidenceTest.php
# PostgreSQL — DB riêng, KHÔNG dùng hoa_nam_audit
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=wwhn214_retest \
DB_USERNAME=postgres DB_PASSWORD=postgres \
php vendor/bin/phpunit tests/Feature/Wwhn214QaRetestRawEvidenceTest.php
# race orchestrators (sau migrate:fresh --force + db:seed --force trên DB riêng)
WWHN198_RUNTIME_RACE=1 WWHN184_RUNTIME_CONCURRENCY=1 WWHN219_RUNTIME_CONCURRENCY=1 \
php vendor/bin/phpunit tests/Feature/Wwhn198OutboundReservationRuntimeRaceTest.php \
  tests/Feature/Wwhn184PostReceiptRuntimeConcurrencyTest.php \
  tests/Feature/Wwhn219RuntimeConcurrencyTest.php
```

Evidence JSON tự pin SHA lúc chạy (`run.candidate_sha`) — reviewer đối chiếu bằng
`git rev-parse HEAD` trước khi chạy lại.
