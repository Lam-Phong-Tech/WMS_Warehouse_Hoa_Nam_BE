# WWHN-241 (QA1) — Test pack cho luồng Reversal trên exact build

Mục đích: WWHN-241 đang `Blocked/Pending` với **zero evidence**. Tài liệu này biến 10
dòng yêu cầu của DEV239-P1-04 thành thứ QA1 chạy được ngay, và chỉ rõ dòng nào đã có
test tự động chạy thật (kèm tên method) — QA1 chỉ cần chạy lại trên đúng build và chụp
output, không phải tự dựng kịch bản từ đầu.

Nguyên tắc chấm: mỗi ca **đều phải đo lại state sau khi request bị từ chối**
(`stock_movements` count, `inventory_balances.quantity`, document status). Một ca chỉ
PASS khi vừa đúng HTTP code + `error.code`, vừa chứng minh **không có write nào xảy ra**
(SRS §8.2 cột "mandatory no-write behavior").

---

## 1. Ma trận 10 dòng của DEV239-P1-04

| # | Kịch bản QA1 | Kết quả kỳ vọng | Test tự động phủ sẵn | Truy vấn đối soát |
|---|---|---|---|---|
| 1 | **Double-reverse** cùng một serial trên cùng movement gốc | Lần 2 bị chặn `409`; **đúng 1** movement `REVERSAL` cho item đó; tồn không đổi | `Wwhn239ReversalItemLevelClosureTest::test_double_reverse_of_the_same_serial_item_is_blocked_and_creates_no_duplicate_movement` | Q1, Q2 |
| 2 | **Same-key replay** (cùng Idempotency-Key, cùng payload) | `200` + header `Idempotent-Replay: true`, cùng `reversal_document_id`, **không** movement mới, tồn không đổi | `…::test_same_key_replay_returns_the_first_result_without_second_write` | Q6 |
| 3 | **Same-key / different payload** | `409 IDEMPOTENCY_CONFLICT`; state/stock/movement như trước request xung đột | `…::test_same_key_with_different_payload_conflicts_without_write` | Q6 |
| 4 | **RBAC 401** (không token) | `401`; không write | `…::test_reversal_is_denied_for_anonymous_and_for_actor_without_permission` | Q6 |
| 5 | **RBAC 403** (có `inbound.*` nhưng thiếu `inventory.reversal.post`) | `403 ACCESS_DENIED`; không có `reversal_document` nào được tạo | cùng test #4 | Q6 |
| 5b | **403 data scope** (actor `scope_ref` trỏ kho khác) | `403 DATA_SCOPE_DENIED` trên cả read và write; không stock effect | `Wwhn242ReversalScopeAndProjectionTest` (7 test) | Q6 |
| 6 | **Rollback** — reversal nhiều dòng, một dòng hỏng | Toàn bộ transaction rollback: **không** header reversal, **không** line, **không** movement, tồn nguyên | `…::test_failed_line_rolls_back_the_whole_reversal_document` | Q3, Q6 |
| 7 | **Dependency guard** — item đã đi downstream (ISSUED/Warranty) | `409 DOWNSTREAM_DEPENDENCY_EXISTS`; tồn không đổi | `…::test_dependency_guard_blocks_receipt_reversal_when_item_moved_downstream` | Q4b |
| 8 | **True competing reversal** — hai request Post song song cùng item | Đúng **một** thành công; request thua nhận `409 CONCURRENCY_CONFLICT`; ledger chỉ có 1 movement | guard 3 lớp (§2 của register); ca đồng thời thật xem §3 dưới đây | Q1, Q2 |
| 9 | **No-negative** — đảo vượt phần còn lại | `422` trước khi chạm tồn; không bucket nào âm | `…::test_ledger_reconciles_with_balance_and_leaves_no_orphan_after_reversals` | Q5 |
| 10 | **duplicate movement = 0 / orphan = 0 / ledger = balance** | cả ba đều 0 lệch | cùng test #9 + `Wwhn204LedgerReconciliationCoverageTest` | Q1, Q2, Q3, Q6 |

Ca bổ sung đã có sẵn, nên chạy kèm để khỏi phải mở vòng sau:

| Ca | Test |
|---|---|
| Cùng một item khai hai dòng trong **một** request | `…::test_same_item_declared_twice_in_one_reversal_request_is_rejected` → `422 REVERSAL_ITEM_DUPLICATED` |
| Thiếu `If-Match` / thiếu `Idempotency-Key` | `Wwhn207InboundReversalFlowTest::test_reverse_inbound_requires_idempotency_key_and_if_match` → `428` |
| Đảo vượt `reversible_qty` ở nhánh quantity-mode | `Wwhn207InboundReversalFlowTest::test_reverse_inbound_rejects_quantity_above_reversible_and_invalid_movement` → `422 REVERSAL_QTY_EXCEEDED` |

---

## 2. Lệnh chạy (PostgreSQL 16 — DB tham chiếu)

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hn_wwhn239 DB_USERNAME=postgres DB_PASSWORD=postgres php artisan migrate:fresh --seed --force
```

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=hn_wwhn239 DB_USERNAME=postgres DB_PASSWORD=postgres php vendor/bin/phpunit --filter "Wwhn239ReversalItemLevelClosureTest|Wwhn242ReversalScopeAndProjectionTest|Wwhn207InboundReversalFlowTest|Wwhn207OutboundBalanceReconciliationTest|Wwhn205StockMovementItemIdInvariantTest|Wwhn204LedgerReconciliationCoverageTest|Wwhn201PostIssueAtomicNonNegativeStockTest"
```

Trên sqlite (nhánh schema thứ hai của repo — kết quả **không** thay cho PostgreSQL):

```bash
php vendor/bin/phpunit --filter "Wwhn239ReversalItemLevelClosureTest|Wwhn242ReversalScopeAndProjectionTest|Wwhn207InboundReversalFlowTest"
```

Đối soát dữ liệu sau khi chạy:

```bash
docker exec -i wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -d hn_wwhn239 -f - < docs/evidence/WWHN-239/RECONCILIATION.sql
```

---

## 3. Ca #8 "true competing reversal" — cách QA1 chứng minh bằng đồng thời thật

Test tự động chạy tuần tự nên chỉ chứng minh được **guard**, không chứng minh được
**race**. Để có bằng chứng đồng thời thật, chạy hai request Post cùng lúc trên cùng
`item_id`, khác `Idempotency-Key`:

1. Dựng một phiếu nhập PRODUCT serial-tracked đã POSTED (2 item).
2. Lấy `movement_id` của movement `RECEIPT`.
3. Bắn song song hai request:

```bash
for i in 1 2; do
  curl -s -o "rev-$i.json" -w "%{http_code}\n" \
    -X POST "$BASE/api/v1/inbound-documents/$DOC_ID/reverse" \
    -H "Authorization: Bearer $TOKEN" \
    -H "If-Match: $DOC_VERSION" \
    -H "Idempotency-Key: race-$i-$(date +%s%N)" \
    -H 'Content-Type: application/json' \
    -d "{\"reason\":\"race\",\"lines\":[{\"movement_id\":\"$MOVEMENT_ID\",\"quantity\":1,\"item_id\":\"$ITEM_ID\"}]}" &
done; wait
```

**PASS khi:** đúng một request trả `200`, request còn lại trả `409` với
`error.code = CONCURRENCY_CONFLICT`, và Q1/Q2 vẫn rỗng. Nếu cả hai trả `200` → FAIL, dù
tồn có vẻ đúng.

Lớp bảo vệ cuối cùng cho ca này nằm ở DB (không ở PHP): partial unique index
`ux_reversal_line_posted_item_per_movement`. Q7 phải trả về đúng 1 dòng — nếu Q7 rỗng
thì build đang thiếu migration `2026_08_18_214000`, và ca #8 **không được chấm PASS** kể
cả khi hai request cho kết quả đúng.

---

## 4. Report WWHN-241 phải nộp kèm

1. `PostgreSQL version` (`SELECT version();`) và exact commit SHA của build đã chạy.
2. Log thô PHPUnit (không phải ảnh chụp tóm tắt).
3. Output 7 truy vấn của `RECONCILIATION.sql`.
4. Với mỗi ca 1–10: request thật (đã che token), HTTP code, `error.code`, và **số liệu
   trước/sau** của `stock_movements` count + `inventory_balances.quantity`.
5. Kết luận reconciliation: `PASS` chỉ khi Q1–Q5 rỗng, Q6 rỗng và Q7 có đúng 1 dòng.
