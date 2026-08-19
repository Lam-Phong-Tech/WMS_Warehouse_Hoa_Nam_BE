# WWHN-203 — QA1 retest pack cho WWHN-202 "Balance hỗ trợ"

| Field | Value |
|---|---|
| Register item | `DEV202-P1-02R2` (QA Hard Gate) |
| DEV task | `WWHN-202` · QA task `WWHN-203` |
| Branch | `m1` (canonical — xem `EVIDENCE_MATRIX.md` §1.1) |
| Candidate SHA | lấy bằng lệnh ở `EVIDENCE_MATRIX.md` §1.3, **không** dùng `HEAD` |
| Contract QA phải dùng | OpenAPI slice `docs/evidence/WWHN-202/openapi-wwhn202-slice.json` + Postman DEV collection (đủ 8/8 bề mặt) |
| Taxonomy mã lỗi | `DEC-STK-202` §D-1 — **đọc `error_code`, không suy từ `message`** |

Register đòi QA1 retest 8 nhóm: *balance, subledger, trace-source, trace-by-code,
reversible, reconciliation, reverse, 401/403/errors* — kèm **DB before/after**.

Pack này cho QA1 hai đường đi, dùng chung một tập kỳ vọng:

- **Đường A (tự động)** — chạy bộ test đã dựng sẵn, mỗi ca đã assert cả DB trước/sau.
- **Đường B (thủ công qua Postman)** — cho ca cần chụp màn hình/nộp raw response.

Không đường nào được coi là thay thế đường kia ở phần **DB before/after**: raw response
không chứng minh được "stock/movement không đổi", chỉ truy vấn DB mới chứng minh được.

---

## 1. Chuẩn bị môi trường

```bash
# DB tham chiếu theo Techstack/Dev Guide là PostgreSQL, KHÔNG phải sqlite.
docker exec wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -c "CREATE DATABASE hn_wwhn203;"

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 \
       DB_DATABASE=hn_wwhn203 DB_USERNAME=postgres DB_PASSWORD=postgres
php artisan migrate --force
php artisan db:seed --force
```

> `phpunit.xml` ép `DB_CONNECTION=sqlite` nhưng phần tử `<env>` **không có** `force="true"`,
> nên biến shell thắng. Nếu quên export, bạn đang test sqlite chứ không phải PostgreSQL và
> kết quả không so sánh chéo được (tên bảng số ít/số nhiều đảo chiều giữa hai driver).

---

## 2. Ma trận retest — mỗi dòng là một ca phải có kết luận

Cột "Tự động" là test đã dựng sẵn; QA1 chạy và đính log. Cột "Thủ công" là request Postman
tương ứng nếu cần bằng chứng dạng raw.

| # | Nhóm | Kỳ vọng (expected) | Tự động | Thủ công |
|---|---|---|---|---|
| 1 | **balance** | Sau Post Issue: `on_hand_qty` và `available_qty` giảm đúng số lượng; `reconciliation_status = MATCH`; `variance = 0`; `incident_id = null` | `Wwhn202OutboundBalanceEvidenceTest::test_post_issue_moves_balance_ledger_and_subledger_together_with_db_before_after` | `GET /api/v1/inventory/balances?product_id=…` |
| 2 | **subledger** | Item vừa ISSUED **không** còn trong danh sách khả dụng; item còn IN_STOCK vẫn có | cùng test #1 (phần cuối) + `Wwhn207…::test_inventory_balance_subledger_excludes_issued_item_after_post` | `GET /api/v1/inventory/balances/{id}/subledger` |
| 3 | **movement list + detail** | List lọc `movement_type=ISSUE` chứa movement vừa sinh; detail có `qty_delta = -1` | `Wwhn202…::test_all_movement_read_surfaces_agree_with_the_ledger_after_post_issue` | `GET /api/v1/stock-movements`, `GET /api/v1/stock-movements/{id}` |
| 4 | **trace-source** | `data.document.id` = outbound document; `original_movement = null`, `reversal_movement = null` khi chưa reverse | cùng test #3 | `GET /api/v1/stock-movements/{id}/trace-source` |
| 5 | **trace-by-code** | `resolved_type = ITEM`, `resolved_id` = item đúng, `movements` **chứa** movement ISSUE. Với line gộp nhiều item (`item_id` null) vẫn phải trả về, không được rỗng | cùng test #3 + `Wwhn202…::test_aggregate_line_leaves_item_id_null_and_trace_by_code_falls_back_to_the_line` | `GET /api/v1/stock-movements/trace-by-code?code=…` |
| 6 | **reversible** | Movement ISSUE có `can_reverse = true`, `lines[0].reversible_qty = 1`; **không** 500 với document serial-tracked | cùng test #3 + `Wwhn207…::test_stock_movements_reversible_lists_posted_issue_with_dependency_guard` | `GET /api/v1/stock-movements/reversible?reversible_only=1` |
| 7 | **reconciliation** | `checks` đúng 4 phần tử theo §7.5; `formula` đúng nguyên văn; dòng vừa post có `reconciliation_overall = MATCH`; `inventory:reconcile` **không** tạo incident giả | `Wwhn202…::test_reconciliation_report_passes_all_four_checks_and_command_creates_no_incident` | `GET /api/v1/reports/inventory-reconciliation` |
| 8 | **reverse** | Tồn quay về đúng; movement ISSUE gốc **không** bị sửa/xoá; movement đối ứng tham chiếu `original_movement_id`; `trace-source` của movement gốc trỏ tới movement đảo | `Wwhn202…::test_reverse_restores_stock_and_every_read_surface_reflects_the_net_effect` | `POST /api/v1/outbound-documents/{id}/reverse` |
| 9 | **401** | **Cả 8** bề mặt trả `401` + `error_code = UNAUTHENTICATED`; `error_code == error.code` | `Wwhn202…::test_every_read_surface_rejects_anonymous_access_with_401` | bỏ header `Authorization` |
| 10 | **403** | **Cả 8** bề mặt trả `403` + `ACCESS_DENIED` + có `meta.request_id`; **DB không đổi** | `Wwhn202…::test_every_read_surface_rejects_missing_permission_with_403_and_changes_nothing` | dùng token của role không có quyền |
| 11 | **404 / 422** | id/mã không tồn tại → `404 RESOURCE_NOT_FOUND`; thiếu tham số bắt buộc → `422`; `message` **không** chứa SQL/stack trace | `Wwhn202…::test_read_surfaces_return_404_and_422_without_leaking_internals` | `GET /api/v1/stock-movements/{uuid-bịa}` |
| 12 | **409 vs 422** | 412 stale · 200 replay · 409 cùng key khác payload · 422 hết tồn — theo `DEC-STK-202` §D-1 | `Wwhn202…::test_post_issue_error_taxonomy_matches_srs_8_2` | 4 request Post Issue theo bảng §D-1 |
| 13 | **permission matrix** | `reversible` dùng `inventory.reversal.post` (KHÔNG phải `inventory.view`); `reconcile` dùng `report.view` | `Wwhn202…::test_read_surface_permission_matrix_matches_the_registered_routes` | — |

Lệnh chạy toàn bộ cột "Tự động":

```bash
php vendor/bin/phpunit --testdox \
  tests/Feature/Wwhn202OutboundBalanceEvidenceTest.php \
  tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php \
  tests/Feature/Wwhn205StockMovementItemIdInvariantTest.php \
  tests/Feature/Wwhn201PostIssueAtomicNonNegativeStockTest.php \
  tests/Feature/Wwhn234PostIssueIdempotencyPayloadTest.php
```

> Truyền **đường dẫn file**, đừng dùng `--filter`: PHPUnit nạp cả thư mục `tests/` trước khi
> lọc, nên một file test hỏng của luồng khác trên `m1` sẽ làm chết cả lượt chạy.

---

## 3. DB before/after — phần QA1 phải nộp

Bộ test tự ghi ảnh chụp DB trước/sau cho mỗi bước đổi tồn ra:

```
storage/app/wwhn-202/db-before-after.json
```

Mỗi mục có `balance_on_hand_qty`, `balance_available_qty`, `ledger_sum_qty_delta`,
`ledger_row_count`, `variance_balance_minus_ledger`, `item_statuses`. Bản mẫu đã chạy nằm ở
`docs/evidence/WWHN-202/DB_BEFORE_AFTER.json`.

Kỳ vọng đọc file này:

| Bước | Bất biến phải thấy |
|---|---|
| post-issue | `balance_on_hand_qty` 1 → 0 · `ledger_row_count` +1 · `variance` = 0 ở **cả hai** đầu · item `IN_STOCK` → `ISSUED` |
| reverse | `balance_on_hand_qty` 0 → 1 · `ledger_row_count` +1 (append, không sửa) · `variance` = 0 ở cả hai đầu |

Ngoài ra chạy bộ SQL đối soát và đính output thô:

```bash
docker exec -i wms_warehouse_hoa_nam_be-postgres-1 psql -U postgres -d hn_wwhn203 \
  < docs/evidence/WWHN-202/RECONCILIATION.sql
```

**Quy ước đọc: cả 6 truy vấn phải trả `(0 rows)`.** Một dòng bất kỳ = FAIL, không phải
cảnh báo. Bản chạy tham chiếu: `docs/evidence/WWHN-202/RAW_reconciliation_sql_pgsql.log`.

---

## 4. Kỳ vọng mã lỗi — dán thẳng vào test case negative

| Tình huống | HTTP | `error_code` |
|---|---|---|
| Thiếu `If-Match` | 428 | `PRECONDITION_REQUIRED` |
| `If-Match` lệch | 412 | `STALE_VERSION` |
| Cùng `Idempotency-Key` + cùng payload | 200 | — (trả lại kết quả cũ, **không** ghi movement thứ hai) |
| Cùng `Idempotency-Key` + khác payload | 409 | `IDEMPOTENCY_CONFLICT` |
| Hai request song song cùng key | 409 | `CONCURRENCY_CONFLICT` |
| `business_key` đã tồn tại | 409 | `DUPLICATE_STOCK_EFFECT` |
| **Không đủ tồn** | **422** | `INSUFFICIENT_STOCK` |
| `quantity <= 0` | 422 | `INVALID_QUANTITY` |
| Thiếu quyền / ngoài data scope | 403 | `ACCESS_DENIED` |
| Chưa xác thực | 401 | `UNAUTHENTICATED` |
| Không tìm thấy | 404 | `RESOURCE_NOT_FOUND` |

Hai chỗ dễ ghi sai kỳ vọng nhất:

1. **Hết tồn là 422, không phải 409.** §8.2 xếp `INSUFFICIENT_STOCK` ở hàng 422; đây là vi
   phạm quy tắc nghiệp vụ "không âm tồn", không phải va chạm phiên bản.
2. **`reversible` cần `inventory.reversal.post`.** Token chỉ có `inventory.view` sẽ nhận 403
   ở bề mặt này — đó là hành vi **đúng**, đừng ghi thành defect.

---

## 5. Điều kiện PASS của QA Hard Gate

Gate `DEV202-P1-02R2` chỉ đóng khi QA1 nộp đủ:

1. Kết quả 13 dòng ở §2, chạy trên **PostgreSQL**, tại **candidate SHA** (ghi rõ SHA đã dùng).
2. File `db-before-after.json` của chính lượt chạy đó.
3. Output thô 6 truy vấn `RECONCILIATION.sql` — cả 6 trả 0 dòng.
4. Xác nhận đã dùng đúng contract đã pin: `node scripts/verify-wwhn-202-closure.mjs` PASS
   tại cùng SHA (check C2 chứng minh OpenAPI slice và Postman chưa trôi).
5. Danh sách defect (nếu có) kèm mức độ và bề mặt; nếu không có, ghi rõ "không phát hiện
   defect mới" thay vì để trống.
