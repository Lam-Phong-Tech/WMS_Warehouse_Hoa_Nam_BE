# WWHN-239 — Evidence matrix vòng R2 (đáp ứng DEV239-P1-02R2 / P1-03R2 / P1-04R2)

| Field | Value |
|---|---|
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` — không tạo nhánh riêng |
| Điểm đánh giá trước vòng này | 68/100 (register R2) |
| Ngày thu bằng chứng | 2026-08-19 |
| Candidate SHA (R1, 18/08) | `c85eca4d9f9ea932bdb57cba0b4711aa1a3aa2b6` (xem `EVIDENCE_MATRIX.md`) |
| Candidate SHA (R2, vòng này) | **commit chứa chính file này** — chạy `git log -1 --format=%H -- docs/evidence/WWHN-239/EVIDENCE_MATRIX_R2.md` để lấy SHA chính xác thay vì chép tay (chống pin tự tham chiếu/pin rot, xem `[[project-wwhn191]]`) |
| Base HEAD lúc bắt đầu thu bằng chứng | `59e82b7dc939c4867312cd4062341a78c5cdb6d0` (`docs(WWHN-260): dong DEV260-P1-07R2`) |

Register R2 chấm lại đúng 68/100 vì bốn lý do đo được, không phải vì code sai:

1. **DEV239-P1-01R2** — governance: `DEC-REV-001` chưa có version `EFFECTIVE`; chưa ai ghi kết quả BA/Approver lên Jira. Đây **không phải việc code** — xem đề xuất comment xác nhận gửi kèm ngoài repo.
2. **DEV239-P1-02R2** — candidate `c85eca4` bị coi là "mất dấu vết" vì kể từ 18/08 đã có hàng chục commit khác (WWHN-240/242/266/267/268/289…) chồng lên trên `m1` mà không có PR/re-pin nào tham chiếu lại WWHN-239. Mục này đóng bằng tài liệu R2 hiện tại.
3. **DEV239-P1-03R2** — vòng 18/08 bị chặn giữa chừng bởi migration PV lỗi của WWHN-252 (`SQLSTATE[42804]`), phải chạy trong worktree cô lập có gỡ bớt migration. Vòng này **không cần gỡ gì nữa**: WWHN-289 (commit `8bdf9ad`, sáng 19/08) đã sửa gốc lỗi migration đó bằng `2026_08_19_000050_repair_erd_mirror_projection_casts.php`.
4. **DEV239-P1-04R2** — ca "true competing reversal" (case #8) trước đó chỉ có kịch bản `curl` viết sẵn cho QA1, chưa có lần chạy nào bằng 2 process PostgreSQL thật. Vòng này đã chạy thật và PASS (xem §3).

---

## 1. Chạy lại full, không blocker, tại HEAD hiện tại (R2)

Thực hiện trong worktree cô lập (`git worktree add --detach <tmp> <base HEAD>` + junction `vendor`), database riêng `hn_wwhn239_r2`, **không sửa/gỡ bất kỳ migration nào** — khác với vòng 18/08.

| Bước | Lệnh | Kết quả | Raw log |
|---|---|---|---|
| Schema + seed | `DB_CONNECTION=pgsql DB_DATABASE=hn_wwhn239_r2 … php artisan migrate:fresh --seed --force` | **EXIT 0**, toàn bộ migration DONE kể cả `2026_08_19_000050_repair_erd_mirror_projection_casts` (257ms) và `2026_08_19_000100_add_optimistic_lock_to_pv_master_tables` (91ms) — migration PV **không còn chặn** | `R2/RAW_migrate_fresh_seed_pgsql_r2.log` |
| Suite Reversal + regression tồn kho | `php vendor/bin/phpunit --filter "Wwhn239…\|Wwhn242…\|Wwhn207…\|Wwhn205…\|Wwhn204…\|Wwhn201…"` | **41/41 PASS**, 377 assertion, 4:03 | `R2/RAW_phpunit_reversal_pgsql_r2.log` |
| Đối soát Q1–Q7 | `RECONCILIATION.sql` trên `hn_wwhn239_r2` | Q1–Q6 = 0 dòng (sạch tuyệt đối), Q7 = đúng 1 dòng (`ux_reversal_line_posted_item_per_movement` tồn tại) | `R2/RAW_reconciliation_pgsql_r2.log` |

Khác biệt so với vòng 18/08: **không còn "còn treo"**. Vòng này chạy `migrate:fresh --seed` trên chính schema đầy đủ (không gỡ migration PV), nên bằng chứng này *mạnh hơn* vòng trước — không còn phụ thuộc vào lý do "trở ngại môi trường không do WWHN-239" nữa.

## 2. Xác nhận không có drift trên file set candidate

```
git diff 7761e5e HEAD -- Modules/Warehouse/app/Services/CurrentBaselineReversalService.php \
  Modules/Warehouse/app/Http/Middleware/EnforceReversalWarehouseScope.php \
  Modules/Warehouse/database/migrations/2026_08_18_214000_enforce_one_posted_reversal_per_item.php \
  tests/Feature/Wwhn239ReversalItemLevelClosureTest.php
```

Kết quả: duy nhất một khác biệt cosmetic (đổi dấu nháy kép sang nháy đơn trong một chuỗi SQL, không đổi hành vi) ở migration `2026_08_18_214000`, do một lượt format tự động của nhánh khác. Không có thay đổi logic nào trên bốn file này kể từ candidate R1.

**Lưu ý tại thời điểm commit tài liệu này**: `CurrentBaselineReversalService.php` đang có một patch **chưa commit** của phiên khác (WWHN-234 F-04 — sắp lại thứ tự khoá `inventory_balances` theo `sku_id` khi Post nhiều dòng, chống deadlock). Patch đó **không đụng** `assertItemNotAlreadyReversed`/`postedReversalLineForItem`/whitelist `assertItemDependency` — ba cơ chế WWHN-239 thêm vẫn nguyên vẹn. WWHN-239 R2 **không commit hộ** file đó (không phải của mình, đang dở).

## 3. Case #8 "true competing reversal" — chạy thật bằng 2 process PostgreSQL

Vòng 18/08 để ngỏ ca này cho QA1 (chỉ có kịch bản `curl` viết sẵn ở `QA1_TEST_PACK_WWHN-241.md` §3, chưa ai chạy). Vòng R2 bổ sung **bằng chứng runtime tham chiếu** bằng hạ tầng race-test đã được chấp nhận ở WWHN-205/219/198/199 (`scripts/wwhn219-concurrency-worker.php`), thêm đúng một nhánh `match` bổ sung `'reversal_execute'` gọi thẳng `CurrentBaselineReversalService::execute()` — **đúng service mà `CurrentBaselineStockFlowController::reverse()` gọi qua `$this->reversal`**, không phải `CurrentBaselineStockService::reverseStockDocument()` (case `'reverse'` cũ, hiện không còn route nào trỏ tới, xem ghi chú trong code).

Script mới: `scripts/wwhn239-reversal-item-race.php`. Kịch bản: dựng 1 phiếu nhập PRODUCT serial-tracked 2 item, Post thật qua `CurrentBaselineStockService::postStockDocument()`, rồi bắn 2 process PHP độc lập (2 `pg_backend_pid()` khác nhau) cùng gọi `execute()` đảo **cùng một `item_id`** trên **cùng một `movement_id`**, khác `Idempotency-Key`, đồng bộ qua barrier file để đảm bảo cả hai cùng lao vào tranh chấp thật.

**Kết quả (PostgreSQL 16.14, `hn_wwhn239_r2`, 2026-08-19):**

```json
{
  "status": "PASS",
  "checks": {
    "two_distinct_backend_connections": true,
    "real_contention_proven": true,
    "exactly_one_success": true,
    "loser_rejected_with_expected_error": true,
    "exactly_one_reversal_movement_for_item": true,
    "exactly_one_posted_reversal_line_for_item": true,
    "db_level_unique_index_present": true,
    "balance_decreased_by_exactly_one": true,
    "balance_never_negative": true
  },
  "workers": [
    {"backend_pid": 20183, "outcome": "REJECTED", "http_status": 409, "error_code": "REVERSAL_ITEM_ALREADY_REVERSED"},
    {"backend_pid": 20182, "outcome": "SUCCESS", "http_status": 200, "error_code": null}
  ]
}
```

Raw: `R2/RAW_reversal_item_race_r2.log`, chi tiết đầy đủ (lock sample, timeline, fixture): `R2/reversal-item-race-summary.json`.

**Đọc kết quả:** hai backend PID khác nhau (đúng 2 connection PostgreSQL độc lập) cùng tranh chấp thật (`real_contention_proven=true`, đo bằng `pg_locks`/`pg_stat_activity`), đúng một request thành công, phía thua nhận đúng `409 REVERSAL_ITEM_ALREADY_REVERSED`, ledger chỉ có đúng một movement `REVERSAL` cho item đó, và tồn giảm đúng 1 (không phải 2) — chứng minh guard giữ đúng dù có race thật, không chỉ đúng khi test tuần tự.

**Ranh giới của bằng chứng này (đọc trước khi dùng để đóng WWHN-241):** đây là bằng chứng **tham chiếu ở tầng service**, cùng phạm vi đã được chấp nhận cho WWHN-205/219 (không đi qua tầng HTTP/If-Match/Idempotency middleware). Nó **không thay thế** yêu cầu QA1 chạy kịch bản `curl` thật ở `QA1_TEST_PACK_WWHN-241.md` §3 (tầng HTTP đầy đủ) — nhưng cung cấp một tham chiếu độc lập, đã chạy thật, để QA1 đối chiếu kết quả.

## 4. Trở ngại môi trường gặp phải khi thu bằng chứng vòng này (ghi lại để không quy sai)

Trong lúc thu bằng chứng, `vendor/` của repo (dùng chung `vendor/autoload.php`) bị **nhiều phiên khác nhau cùng chạy `composer install`/xoá giữa chừng** nhiều lần liên tiếp (khác sự cố đã ghi ở `DEV239_FIX_REGISTER.md` §6, lần đó chỉ xảy ra một lần). Đã xử lý bằng cách chạy lại `composer install --no-interaction` từ `composer.lock` (an toàn, không đổi dependency) cho tới khi ổn định, không sửa file nào của phiên khác.

## 5. Re-pin — điều BA Main / Tech Lead cần đọc

- **Candidate R2 = commit chứa các file trong `docs/evidence/WWHN-239/R2/`, `EVIDENCE_MATRIX_R2.md`, `scripts/wwhn239-reversal-item-race.php`, và patch bổ sung trong `scripts/wwhn219-concurrency-worker.php`.**
- File set candidate R1 (`CurrentBaselineReversalService.php`, `EnforceReversalWarehouseScope.php`, migration `2026_08_18_214000`, `Wwhn239ReversalItemLevelClosureTest.php`) **không đổi logic** — xem §2.
- **PR = N/A vẫn giữ nguyên lý do đã ghi ở `EVIDENCE_MATRIX.md` §1**: `m1` là nhánh làm việc chung, review thực hiện trực tiếp trên exact SHA.
- Không có test nào bị bỏ qua để đạt kết quả này — `migrate:fresh --seed` chạy full, không gỡ migration nào.
