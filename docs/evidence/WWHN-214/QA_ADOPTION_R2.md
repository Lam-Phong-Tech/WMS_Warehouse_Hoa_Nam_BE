# WWHN-213 — QA Execution Record · Round R2 (DEV214-P1-01R2 / P1-04R2)

**Mục đích:** bản ghi thi hành để QA1 **chính danh adopt** (ký bằng Jira comment trên
WWHN-213) hoặc tự tái lập độc lập. Đây là record kỹ thuật; hiệu lực QA chỉ phát sinh khi
QA1 ký — DEV không tự ký thay (giữ đúng nghĩa "độc lập").

## 1. Định danh lượt chạy

| | |
|---|---|
| Exact candidate | `9c18f69f27352be78d9fadd23913ea7016876428` (điều kiện hiệu lực: `DEV214_DISPOSITION_R2.md` §1.2 — diff bề mặt rỗng tới SHA lúc chạy `9d888a1`) |
| Ngày chạy | 2026-08-19 (timestamp trong từng evidence JSON) |
| Env | PHP 8.3.30, Laravel (repo BE `m1`), Windows local; PostgreSQL 16.14 container `wms_warehouse_hoa_nam_be-postgres-1` |
| DB | sqlite `:memory:` (driver test mặc định) + **DB PostgreSQL cô lập `wwhn214_retest_r2`** tạo mới cho lượt này, `migrate:fresh --force` + `db:seed --force`; KHÔNG dùng DB chung `hoa_nam_audit` |
| CI | không kích hoạt (ràng buộc 3000/3000 phút Actions) — evidence 100% local |

## 2. PASS/FAIL — 7 hạng mục core suite

| # | Hạng mục | sqlite | pgsql | Raw evidence (`docs/evidence/WWHN-214/retest-r2/`) |
|---|---|---|---|---|
| 1 | No-negative stock | **PASS** | **PASS** | `qa-retest-raw-api-db.<driver>.json → areas.no-negative` |
| 2 | Duplicate/retry (idempotency) | **PASS** | **PASS** | `→ areas.duplicate-retry` |
| 3 | Concurrency (If-Match/version + race thật) | **PASS** | **PASS** | `→ areas.concurrency` + 3 race summary |
| 4 | Rollback / no partial write | **PASS** | **PASS** | `→ areas.rollback` |
| 5 | RBAC | **PASS** | **PASS** | `→ areas.rbac` |
| 6 | Append-only ledger | **PASS** | **PASS** | `→ areas.append-only-ledger` |
| 7 | Reconciliation = 0 | **PASS** | **PASS** | `→ areas.reconciliation` |

Tổng raw API/DB: **7/7 PASS × 2 driver, 83 assertion/driver, 39 bước HTTP thô + dump DB
thô mỗi driver.** Race đa tiến trình PostgreSQL tại candidate: **WWHN-198 3/3 PASS**
(phpunit xanh), **WWHN-219 3/3 PASS** (phpunit xanh), **WWHN-184 3/3 PASS theo
orchestrator summary** (9/9 scenario `passed=true`, mỗi scenario đúng 2 backend PID,
`pg_locks` contention thật; 1 self-check wall-clock của wrapper fail trên Windows local
— hạn chế môi trường đã hồ sơ hoá từ vòng R, không phải lỗi nghiệp vụ). Suite nền do
lượt adoption song song cùng ngày tái lập: 232 test / 2 562 assertion / 0 fail (sqlite)
+ 10 test / 87 assertion / 0 fail (PG) — xem `logs/*-R2.log`.

## 3. Kết luận đề nghị QA1 ký

- **P0 = 0** tại exact candidate: không stock corruption, không double effect, không
  auth bypass, không data loss trên mọi đường ghi tồn, cả hai driver, kể cả dưới race
  đa tiến trình thật.
- **NO QUALIFYING DEFECT → DEV FIX N/A** — khớp độc lập với disposition NO CODE CHANGE
  của DEV (`DEV214_DISPOSITION.md` §1). Không có defect package để bàn giao.
- Oracle đối chiếu: `EXPECTED_RESULT_MAPPING.md` (SRS v1.12 §4/§6/§7.1/§7.5/§8.2/§13,
  ERD HN-WMS-ERD-DD-001).

## 4. Tái lập độc lập (nếu QA1 muốn tự chạy thay vì adopt)

```bash
php vendor/bin/phpunit tests/Feature/Wwhn214QaRetestRawEvidenceTest.php
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=wwhn214_retest_r2 \
DB_USERNAME=postgres DB_PASSWORD=postgres \
php vendor/bin/phpunit tests/Feature/Wwhn214QaRetestRawEvidenceTest.php
# race (sau migrate:fresh --force + db:seed --force trên DB cô lập):
WWHN198_RUNTIME_RACE=1 WWHN184_RUNTIME_CONCURRENCY=1 WWHN219_RUNTIME_CONCURRENCY=1 \
php vendor/bin/phpunit tests/Feature/Wwhn198OutboundReservationRuntimeRaceTest.php \
  tests/Feature/Wwhn184PostReceiptRuntimeConcurrencyTest.php \
  tests/Feature/Wwhn219RuntimeConcurrencyTest.php
```

Trước khi chạy, đối chiếu `git diff 9c18f69 HEAD -- <11 path bề mặt>` phải RỖNG
(danh sách path: `DEV214_DISPOSITION_R2.md` §1.2).
