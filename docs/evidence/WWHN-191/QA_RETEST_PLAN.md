# WWHN-191 — QA independent fixed-build retest plan (DEV191-P1-03R)

Mục đích: gỡ tình trạng WWHN-192 bị "Blocked/Pending" mà không ai biết phải chạy cái gì.
Tài liệu này KHÔNG thay QA sign-off. Nó chỉ biến 8 kịch bản register yêu cầu thành
lệnh chạy được trên đúng candidate SHA, để QA1 chạy độc lập và ghi kết quả.

## 0. Điều kiện tiên quyết

- Checkout đúng `candidate_sha` khai trong `docs/evidence/WWHN-191/CANDIDATE_MANIFEST.json`
  (KHÔNG dùng `git pull` lấy HEAD mới — pin sẽ sai).
- Cổng pin phải xanh trước khi test:

```bash
node scripts/verify-wwhn-191-closure.mjs
```

- Chạy trên **cả hai driver**. SQLite không enforce CHECK constraint nên một lớp lỗi
  chỉ lộ trên PostgreSQL (đã có tiền lệ thật ở `Flow05InventoryControlAlignmentTest`).

```bash
php artisan test --testsuite=Feature                                   # sqlite (mặc định phpunit.xml)
docker exec wms_warehouse_hoa_nam_be-backend-1 php artisan test --configuration=phpunit.pgsql.xml
```

## 1. Ma trận kịch bản → test thực thi được

| # | Kịch bản register | Test/lệnh chạy | Ghi chú |
|---|---|---|---|
| 1 | Post Receipt → movement → balance/reconcile | `tests/Feature/Wwhn191BalanceMovementProjectionTest.php::test_inventory_balances_list_reflects_ledger_after_post_receipt_and_matches` | Assert `ledger_total`, `reconciliation_status=MATCH`, `variance=0` sau HTTP flow thật |
| 2 | Same-key replay | `Wwhn180IdempotencyAtomicityTest::test_same_key_same_payload_replays_exact_snapshot_and_runs_callback_once` | Snapshot phải giống hệt, callback chạy đúng 1 lần |
| 3 | Different-payload conflict | `Wwhn180IdempotencyAtomicityTest::test_same_key_different_payload_returns_409_without_second_callback` | 409, không chạy callback lần hai |
| 4 | Concurrent retry | `PostReceiptConcurrencyBehaviorTest::test_stale_version_racer_is_rejected_after_winner_commits_and_stock_effect_applies_once` + `Wwhn219RuntimeConcurrencyTest` | Race 2 tiến trình OS thật: `php scripts/wwhn219-runtime-concurrency.php` |
| 5 | Timeout/retry | `Wwhn180IdempotencyAtomicityTest::test_business_write_rolls_back_and_lease_is_removed_when_callback_fails` | Lease phải được giải phóng, retry sau timeout không kẹt 409 vĩnh viễn |
| 6 | Rollback / no half-post | cùng test #5 + `PostReceiptConcurrencyBehaviorTest::test_stock_movement_business_key_is_unique_per_document_line_after_post` | Không có movement mồ côi, business key unique |
| 7 | Permission / data scope | `Wwhn191BalanceMovementProjectionTest::test_stock_movements_endpoints_require_inventory_permissions`, `..._requires_authentication`, và toàn bộ `Wwhn211InventoryScopeTest` (8 test, gồm fail-closed khi scope rỗng và trace lọc movement ngoài scope) | 401 `UNAUTHENTICATED`, 403 `ACCESS_DENIED` |
| 8 | DB reconciliation | `php artisan inventory:reconcile --dry-run` trên DB đã seed + `Wwhn219InventoryReconcileCommandTest` (3 test) | Kỳ vọng 0 lệch trên seed sạch; test bơm lệch nhân tạo để chứng minh command **có** phát hiện được |

## 2. Kịch bản trace/reversal thuộc output WWHN-191 (bổ sung theo DEV191-P2-01R)

| Kịch bản | Test |
|---|---|
| trace-by-code positive — mã SKU, có case-insensitive | `Wwhn191BalanceMovementProjectionTest::test_stock_movement_trace_by_code_resolves_sku_code_to_posted_receipt_movement` |
| trace-by-code positive — mã container | `..._resolves_container_code_of_received_box` |
| trace-by-code positive — mã physical/ITEM (nhánh ISSUE) | `Wwhn207OutboundBalanceReconciliationTest::test_stock_movement_trace_by_code_resolves_issued_item_and_its_movement` |
| trace-by-code negative | `Wwhn191...::test_stock_movement_trace_by_code_returns_404_for_unknown_physical_code` |
| Annex C `/inventory/trace/{code}` parity | `Wwhn191...::test_annex_c_inventory_trace_path_returns_same_payload_as_trace_by_code` |
| `/reversible` positive — RECEIPT, chưa có downstream | `Wwhn191...::test_stock_movements_reversible_lists_posted_receipt_with_full_reversible_qty` |
| `/reversible` positive — ISSUE serial-tracked + dependency guard | `Wwhn207OutboundBalanceReconciliationTest::test_stock_movements_reversible_lists_posted_issue_with_dependency_guard` |
| Append-only invariant của ledger | `Wwhn191...::test_stock_movement_is_append_only_and_rejects_update_and_delete`, `..._delete_is_rejected` |

## 3. QA report phải ghi (theo yêu cầu register)

- `build/SHA`: candidate SHA đã checkout (`git rev-parse HEAD`)
- `env`: local / docker / CI, kèm output `php artisan db:show`
- `DB engine`: sqlite (bản `:memory:`) và/hoặc PostgreSQL (`SELECT version()`)
- Kết quả từng dòng bảng mục 1 và 2: PASS/FAIL + số assertion
- Fail nằm ngoài phạm vi WWHN-191 phải liệt kê tên test và lý do tại sao ngoài phạm vi
