# WWHN-241 — Runbook QA1 tại exact SHA của WWHN-240

Đóng `DEV240-P1-04`. Fixed build:

| Repo | Branch | Exact SHA |
|---|---|---|
| BE | `m1` | `9640091cf780fce1ef54102f6955971a57c5678b` (ancestor bắt buộc: `c855851b37920237d110f7dffe5dbbad509aebce`) |
| FE | `m1` | `79474b8c6cd873f26bc6b42ca1bd9af2171ce7cf` |

Mọi bằng chứng phải chụp **tại đúng hai SHA trên**. Kết quả chạy ở SHA khác không dùng được
cho gate này.

---

## 0. Chuẩn bị

```bash
git -C <BE> checkout 9640091cf780fce1ef54102f6955971a57c5678b
php vendor/bin/phpunit --filter Wwhn240ReversalNetEffectContractTest   # kỳ vọng 14/14
```

Driver: `phpunit.xml` ép `sqlite :memory:`. Muốn chạy trên PostgreSQL tham chiếu thì set env
trước (`DB_CONNECTION=pgsql`…) vì `<env>` trong `phpunit.xml` không có `force="true"`.

---

## 1. XNT — original / reversal / net (BR-RPT-01..07, BR-REV-11)

| TC | Dựng dữ liệu | Kỳ vọng |
|---|---|---|
| QA240-01 | RECEIPT +30, ISSUE −20, COMPONENT_ISSUE −5, reversal −4 (gốc RECEIPT), +6 (gốc ISSUE), +2 (gốc COMPONENT_ISSUE), opening 100 | `receipt_gross=30`, `receipt_reversal=4`, `receipt_net=26`; `issue_gross=20`, `issue_reversal=6`, `issue_net=14`; `component_issue_net=3`; `reversal_net=4`; `closing=109` |
| QA240-02 | ISSUE −9 + reversal +9; COMPONENT_ISSUE −7 + reversal +7 | `issue_reversal=9` và `component_issue_reversal=7` **tách riêng** (không gộp), `issue_net=0`, `component_issue_net=0` |
| QA240-03 | RECEIPT +10 → reversal −4 → reversal +4 (trỏ vào reversal trước, BR-REV-08) | `receipt_reversal=0`, `receipt_net=10`, `closing=10` |
| QA240-04 | RECEIPT +12, REVERSAL −5 **không có** `original_movement_id` | `reversal_unlinked_net=-5`, `closing=7` (không được đánh mất bút toán) |
| QA240-05 | Bất kỳ | `data.formula` giữ nguyên chuỗi cũ; có thêm `data.formula_net` và `data.column_semantics` |

**Bất biến bắt buộc kiểm ở mọi TC** (đây là điều kiện chấp nhận thật, không phải nice-to-have):

```
closing == opening + receipt_net - issue_net - component_issue_net + reversal_unlinked_net
closing == opening + receipt   - issue     - component_issue     + reversal_net
closing == SELECT SUM(qty_delta) FROM stock_movements WHERE product_id=? AND occurred_at <= to_date
reversal_net == -receipt_reversal + issue_reversal + component_issue_reversal + reversal_unlinked_net
```

Export: `GET /api/v1/reports/inventory-xnt/export-excel?...` phải trả XLSX với 16 cột
(`Kho, Mã SKU, Tên SKU, Trạng thái tồn, Đầu kỳ, Nhập (gross), Đảo nhập, Nhập thuần,
Xuất (gross), Đảo xuất, Xuất thuần, Xuất linh kiện (gross), Đảo xuất linh kiện,
Xuất linh kiện thuần, Reversal net, Cuối kỳ`) và cùng bộ lọc với màn hình (BR-EXP-01..05).

## 2. Trace chain (BR-REV-01/05/08/11 · RPT-MOV-01)

`GET /api/v1/stock-movements/{id}/trace-source`

| TC | Dựng dữ liệu | Kỳ vọng |
|---|---|---|
| QA240-06 | RECEIPT +10, reversal −3 | `correction_chain.nodes` 2 phần tử; node[0] `role=ORIGINAL`,`depth=0`,`is_subject=true`; node[1] `role=REVERSAL`,`depth=1`; `net_effect`: `original_qty=10`, `reversed_qty=3`, `remaining_reversible_qty=7`, `net_qty_delta=7`, `fully_reversed=false` |
| QA240-07 | RECEIPT +10, reversal −3, reversal −2 (**đảo hai lần**) | `reversal_movements` có 2 phần tử; `node_count=3`; `reversed_qty=5`; `remaining_reversible_qty=5` |
| QA240-08 | RECEIPT +8 → reversal −8 → reversal +8; tra từ mắt xích cuối | `root_movement_id` = movement RECEIPT; `node_count=3`; `max_depth=2`; `reversed_qty=0`; `original_movement` = reversal trước đó |
| QA240-09 | ISSUE −6, reversal +6 | `fully_reversed=true`, `net_qty_delta=0`, `remaining_reversible_qty=0` |
| QA240-10 | id không tồn tại | 404, envelope lỗi chuẩn |

Kiểm thêm: `original_movement` và `reversal_movement` (số ít) **vẫn còn** trong response —
đây là cam kết tương thích ngược, mất chúng là regression.

## 3. RBAC / data scope

| TC | Actor | Kỳ vọng |
|---|---|---|
| QA240-11 | `inventory.view` + `inventory.trace`, `scope_ref` trỏ kho **khác** | 403, `error.code = ACCESS_DENIED`, không lộ bất kỳ node nào của chain |
| QA240-12 | chỉ `inventory.trace` (thiếu `inventory.view`) | 403 |
| QA240-13 | đủ quyền, đúng kho | 200, `correction_chain.node_count` đúng — ca đối chứng chống chặn nhầm |
| QA240-14 | thiếu `report.view` gọi `/reports/inventory-xnt` | 403 |
| QA240-15 | `warehouse_id` ngoài data scope trên `/reports/inventory-xnt` | 403 `ACCESS_DENIED` (không phải 200 với danh sách rỗng) |

## 4. Replay / double reverse / rollback (BR-REV-05/09/12)

Chạy qua HTTP thật, không INSERT thẳng:

| TC | Thao tác | Kỳ vọng |
|---|---|---|
| QA240-16 | Post Reversal 2 lần **cùng** `Idempotency-Key` + cùng payload | Lần 2 replay; **chain vẫn 2 node**; tồn không đổi lần hai |
| QA240-17 | Cùng key, payload khác | 409 `IDEMPOTENCY_CONFLICT`; chain không đổi |
| QA240-18 | Đảo 3/10 rồi xin đảo thêm 8 | 422 `REVERSAL_QTY_EXCEEDS_REVERSIBLE`; `remaining_reversible_qty` trong trace vẫn = 7 |
| QA240-19 | Hai request reversal đồng thời cùng movement | Một thành công, một 409/412; **không** sinh node trùng trong chain |
| QA240-20 | Inject lỗi sau bước movement/balance | 500 + rollback; chain và XNT trở về nguyên trạng trước request |

Sau mỗi TC nhóm này, chạy lại QA240-01 style để chứng minh **báo cáo XNT và trace luôn kể
cùng một câu chuyện**: `net_effect.net_qty_delta` của chain phải khớp phần đóng góp tương
ứng trong `closing`.

## 5. Bằng chứng phải nộp

1. Raw response JSON của các call ở §1–§3 (kèm `meta.request_id`).
2. Dump DB `stock_movements` (`id, movement_type, qty_delta, original_movement_id,
   correction_chain_id`) cho từng TC.
3. Output `php vendor/bin/phpunit --filter Wwhn240ReversalNetEffectContractTest`.
4. Ảnh chụp FE: tab XNT (thấy đủ Gross/Đã đảo/Thuần) và TRACE-01 (bảng Correction chain +
   khối Net effect).
5. Ghi rõ hai SHA ở đầu tài liệu này trong report.
