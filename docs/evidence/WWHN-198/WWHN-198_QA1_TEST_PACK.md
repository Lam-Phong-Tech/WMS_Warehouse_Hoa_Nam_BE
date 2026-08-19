# WWHN-198 — QA1 test pack (DEV198-P1-05R)

- Nhánh canonical: **`m1`** (không có nhánh riêng — xem `DISP-OUT-198-CANONICAL-BRANCH.md`)
- Candidate BE SHA: **`e0ef044b8e0a9eda1a051d2414e0b7b4c331cb92`**
- Candidate FE SHA: xem `WWHN-198_CLOSURE_REGISTER.json` → `candidate.frontend_sha`
- Task cha: WWHN-46 (HN-OUT-002). **WWHN-198 là OUTBOUND**; task inbound bị nhầm số
  trước đây là **WWHN-187**.

Toàn bộ lệnh dưới đây chạy lại được tại đúng SHA trên. Nếu `git rev-parse HEAD` khác
SHA candidate, kết quả **không** dùng để ký nghiệm thu.

## 0. Chốt đúng build

```bash
git -C HoaNam_BE/WMS_Warehouse_Hoa_Nam_BE checkout m1 && git rev-parse HEAD
```

```bash
node scripts/verify-wwhn-198-closure.mjs --expect-sha=e0ef044b8e0a9eda1a051d2414e0b7b4c331cb92
```

## 1. Bộ test trên sqlite (mặc định — nhanh, chạy được mọi máy)

```bash
php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn46OutboundPickScanDedupTest.php tests/Feature/Wwhn198PackingLabelContractAlignmentTest.php tests/Feature/Wwhn198ReservedQtySemanticsTest.php tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php tests/Feature/OutboundDocumentFlowTest.php tests/Feature/PostIssueConcurrencyBehaviorTest.php tests/Feature/PostIssueAuditLogTest.php tests/Feature/OutboundPermissionTest.php
```

Kỳ vọng: **42 passed, 271 assertions**.

Phủ theo yêu cầu register:

| Yêu cầu QA | Test |
|---|---|
| Valid scan (nhập tay + quét mã) | `OutboundDocumentFlowTest`, `Wwhn46OutboundPickScanDedupTest` (nhánh fallback `item_instances.physical_code_id`) |
| Toàn bộ negative code | `Wwhn46OutboundPickScanDedupTest`: `PHYSICAL_CODE_NOT_FOUND`, `PHYSICAL_CODE_ITEM_MISMATCH`, `PHYSICAL_CODE_UNASSIGNED`, `ITEM_SKU_MISMATCH`, `ITEM_NOT_AVAILABLE`, `SERIAL_QUANTITY_MUST_BE_ONE`, `ITEM_REQUIRED`, `SCAN_EXCEEDS_REQUIRED`, `PRODUCT_SKU_REQUIRED` |
| RBAC | `OutboundPermissionTest` (permission `outbound.scan` / `outbound.post`) |
| Dedup same request | `Wwhn199ReservationConcurrencyGuardTest` (`DUPLICATE_ITEM_IN_DOCUMENT`) |
| Dedup cross request | `Wwhn199ReservationConcurrencyGuardTest` (`ITEM_ALREADY_MATCHED`) |
| Retry / idempotency | `PostIssueConcurrencyBehaviorTest` (Idempotency-Key replay, If-Match 412/428) |
| DB state sau khi bị chặn | Mọi test negative đều assert `outbound_item_matches` count và `scanned_qty` không đổi |
| Stock-neutrality | `Wwhn198ReservedQtySemanticsTest`, `Wwhn198PackingLabelContractAlignmentTest` (0 `stock_movements` khi Post bị chặn) |
| Hợp đồng Packing Label | `Wwhn198PackingLabelContractAlignmentTest` (5 case, gồm guard chống mã lỗi ma) |

## 2. Race đa connection thật trên PostgreSQL (DEV198-P1-02R)

Yêu cầu một database PostgreSQL đã migrate + seed riêng cho lần chạy này:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=wwhn198_race DB_USERNAME=postgres DB_PASSWORD=postgres php artisan migrate:fresh --seed --force
```

```bash
WWHN198_RUNTIME_RACE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=wwhn198_race DB_USERNAME=postgres DB_PASSWORD=postgres php vendor/bin/phpunit --no-coverage tests/Feature/Wwhn198OutboundReservationRuntimeRaceTest.php
```

Kỳ vọng: **1 passed, 22 assertions**, và `storage/app/wwhn-198-evidence/reservation-race-summary.json` có `status = PASS`.

Kết quả đã chạy (PostgreSQL 16.14) lưu tại `docs/evidence/WWHN-198/reservation-race-summary.json`:

| Scenario | Winner | Loser | Invariant |
|---|---|---|---|
| `cross_request_same_item` | 1 SUCCESS | 409 `ITEM_ALREADY_MATCHED` | 1 ACTIVE match; loser `scanned_qty`=0; balance before==after; 0 movement |
| `same_request_same_item` | 1 SUCCESS | 409 `DUPLICATE_ITEM_MATCH` | 1 ACTIVE match; `scanned_qty` chỉ tăng 1 |
| `unique_index_guard` (bypass application) | 1 SUCCESS | SQLSTATE **23505** | Chặn bởi `ux_erd_outbound_active_item` / `uk_outbound_match_active_item` |

Mỗi scenario đều assert 2 `pg_backend_pid()` khác nhau và có contention thật (mẫu
`pg_locks`/`pg_stat_activity` lưu ở `pg-lock-samples-cross-request.json`).

## 3. Hợp đồng API cùng revision (DEV198-P1-06)

```bash
node scripts/verify-wwhn-201-contract.mjs
```

Kỳ vọng: `status: PASS`, `contract_version: 2.0.0-R2`, và JSON/YAML/Postman cùng bộ
sha256 trong `WWHN-201-outbound-contract-lock.json`.

## 4. Điểm cần BA/Tech Lead ký trước khi QA đóng gate

| Hạng mục | Người ký | Tài liệu |
|---|---|---|
| DEV198-P1-01R nhánh canonical + trace | PM/Tech Lead | `DISP-OUT-198-CANONICAL-BRANCH.md` |
| DEV198-P1-03R availability | BA Main | `DEC-OUT-AVAILABILITY-001-scan-soft-check.md` |
| DEV198-P1-04R reserved_qty | BA Main + FE1 | `DEC-INV-RESERVED-001-reserved-qty-semantics.md` |
| DEV198-P2-01 DUPLICATE_ITEM_MATCH | Tech Lead | `DISP-OUT-198-DUPLICATE-ITEM-MATCH.md` |
