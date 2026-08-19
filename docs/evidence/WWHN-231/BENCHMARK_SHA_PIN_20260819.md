# WWHN-231 — Pin SHA cho benchmark (DEV231-P1-03R2)

Review vòng 3 nêu đúng: `BENCHMARK_RESULT.json` được tạo **sau** candidate và không tự
embed SHA thực thi bên trong artifact. Tài liệu này chốt lại pin, độc lập khỏi
`EXACT_SHA.json` (vốn thuộc register `P1-02`), và xác nhận query benchmark đúng là query
candidate — không phải suy đoán.

## Pin hai tầng

| SHA | Vai trò | Nội dung |
|---|---|---|
| `a16d1d6` | Implementation SHA | `Modules/Warehouse/app/Services/LedgerReconciliationService.php` — service tạo ra query được đo |
| `015f7be` | Benchmark-generation SHA | Commit cuối cùng sửa `scripts/wwhn231-ledger-benchmark.mjs` trước khi `BENCHMARK_RESULT.json` hiện tại được sinh ra — thêm hai phép đo `I-xnt-legacy`/`J-xnt-optimized`, khớp đúng 10 `measurements` đang có trong `BENCHMARK_RESULT.json` (A..J) |

`git log --oneline a16d1d6..015f7be` cho 6 commit ở giữa: 3 của chính WWHN-231
(`d52727c`, `f58596c`, `015f7be`) xen với 3 commit của **WWHN-237** (`62dcbc1`, `897710e`,
`d6f7258` — ticket khác, đã đóng bằng DEFERRED/NO-CODE, không đụng
`Modules/Warehouse/app/Services/LedgerReconciliationService.php` hay
`scripts/wwhn231-ledger-benchmark.mjs`). Có commit ngoài phạm vi xen giữa hai SHA, nhưng
không commit nào trong số đó chạm vào service hay script benchmark — xác nhận bằng
`git diff --stat a16d1d6..HEAD -- Modules/Warehouse/app/Services/LedgerReconciliationService.php`
(rỗng) nên "benchmark sinh sau candidate" không đồng nghĩa với "benchmark đo nhầm code".

## Toàn vẹn tệp — đối chiếu lại `SHA256SUMS`

`sha256sum -c SHA256SUMS` chạy lại tại thời điểm ghi tài liệu này (2026-08-19): **21/21 OK**,
không tệp `plans/*.txt` hay `BENCHMARK_RESULT.json` nào bị đổi kể từ lúc hash — loại khả năng
ai đó sửa evidence sau khi hash.

## Chứng minh benchmark đo ĐÚNG query candidate (không phải bản chép tay lệch)

Query "optimized" ghi trong `BENCHMARK_RESULT.json.queries.optimized` được đối chiếu với SQL
thật sinh ra từ chính service tại HEAD (`php artisan tinker --execute="echo
app(\Modules\Warehouse\Services\LedgerReconciliationService::class)
->ledgerAggregateWithMasterQuery()->toSql();"`):

```sql
select "agg"."warehouse_id", "agg"."product_id", "agg"."stock_status", "agg"."ledger_qty",
       "agg"."movement_updated_at", "w"."code" as "warehouse_code", "p"."code" as "product_code",
       "p"."name" as "product_name", "p"."category_id", "pc"."category_name"
from (
    select "warehouse_id", "product_id", "stock_status",
           SUM(qty_delta) AS ledger_qty, MAX(occurred_at) AS movement_updated_at
    from "stock_movements"
    group by "warehouse_id", "product_id", "stock_status"
) as "agg"
left join "warehouses" as "w" on "w"."id" = "agg"."warehouse_id"
left join "products" as "p" on "p"."id" = "agg"."product_id"
left join "product_categories" as "pc" on "pc"."id" = "p"."category_id"
```

Cùng cột chọn (cùng thứ tự), cùng subquery gom trước-join-sau, cùng ba `LEFT JOIN` — khác biệt
duy nhất là dấu ngoặc kép định danh (Laravel query builder tự thêm khi build cho PostgreSQL) và
cách format khoảng trắng, không phải khác biệt ngữ nghĩa. Vì `LedgerReconciliationService.php`
không đổi gì kể từ `a16d1d6` (xem `NO_DRIFT_RECHECK_20260819.json` +
`SOURCE_SUPERSEDE_M1_20260819.md`), SQL đo tại `015f7be` và SQL chạy thật ở HEAD hôm nay là
CÙNG một câu.

## Đóng `DEV231-P1-03R2`

DEV/DBA xác nhận: SHA đã pin, hash tệp còn nguyên, query benchmark đúng bằng query candidate
thật (đối chiếu tự động qua `->toSql()`, không phải đọc mắt). Không có thay đổi kỹ thuật mới
cần làm thêm ở benchmark — số đo trong `BENCHMARK_RESULT.json` (×21,4 gom toàn bảng, ×7,4 nhánh
filter sau khi có index, ×10,7 XNT) vẫn hợp lệ.
