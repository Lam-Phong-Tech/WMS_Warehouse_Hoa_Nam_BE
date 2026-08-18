# WWHN-234 · DEV234-P1-01 — Scope authority của Stocktake

| Field | Value |
|---|---|
| Register item | `DEV234-P1-01` (P1) |
| Xung đột phải đóng | `WWHN-59` Source/AC ↔ `WWHN-25` ↔ OpenAPI `2.0.0` |
| Owner | BA Main + PM/PO |
| Reviewer | Tech Lead + QA |
| Gate | G1 / Dev Ready |
| **Cách đóng** | **KẾ THỪA `DISP-STK-233`** — không phát hành disposition mới |
| Trạng thái | `DISP-STK-233` = **PROPOSED**, effective date `NULL` ⇒ `DEV234-P1-01` **OPEN** cho tới khi approver ký |

---

## 1. Kết luận trước, lý do sau

Nhánh ticket `WWHN-59` **đã có** disposition phạm vi:

> `docs/decision-records/DISP-STK-233-COUNT-RECOUNT-DEFERRED.md`
> — *"Disposition Count / Recount / Partial / Stale (WWHN-59 ↔ WWHN-233 ↔ WWHN-235)"*

`WWHN-234` là **anh em ruột** của `WWHN-233` dưới cùng parent `WWHN-59`. Vì vậy cách đúng để
đóng `DEV234-P1-01` là **ghi nhận kế thừa**, không phải viết `DISP-STK-234`.

Nếu `WWHN-234` phát hành disposition riêng, dự án sẽ có **hai** văn bản cùng trả lời một câu
hỏi cho cùng một nhánh ticket. Đó chính là cơ chế hỏng mà `DEC-STK-002` §Context đã mô tả
(cùng một câu hỏi bị nêu lại năm lần ở `WWHN-190/195/205/207/218`), và là lý do các vòng rà
soát trước không đóng được. Vòng này không lặp lại nó.

## 2. Authority đã được chốt ở đâu

`DISP-STK-233` §2 chốt **controlling source duy nhất** và xếp hạng thẩm quyền:

```text
REQ-SRS-037 (SRS v1.12, ADOPTED tại WWHN-334, Must)
  >  ERD §0.1 / Techstack Dev Guide / Wireframe MVP     (xác nhận chéo, cùng baseline)
  >  DEC-STK-001 (WWHN-25)                              (PROPOSED, effective date NULL)
  >  WWHN-180 posting OpenAPI v1.7.0                    (PROPOSED)
  >  BRD/Handoff v3.9 + Changelog BE1 v1.0.1            (SUPERSEDED)
```

Disposition: `WWHN-59` và `WWHN-233` **DEFERRED — NO CODE**. Điều kiện re-enable là bốn điều
kiện ở `DISP-STK-233` §2 (CR + `WWHN-25` GO/CONDITIONAL GO + rebaseline SRS + mở DEV sau
cùng). `WWHN-234` áp dụng **nguyên văn** bốn điều kiện đó, không thêm không bớt.

Họ disposition anh em, để reviewer không phải đi tìm:

| Disposition | Nhánh ticket | Phạm vi |
|---|---|---|
| `DISP-STK-230` | `WWHN-230 / 58 / 232` | core kiểm kê: session/snapshot/cutoff/variance |
| **`DISP-STK-233`** | **`WWHN-59 / 233 / 235`** | **Count / Recount / Partial / Stale — nhánh của WWHN-234** |
| `DISP-STK-236` | `WWHN-236` | Apply Adjustment |
| `DISP-STK-237` | `WWHN-237 / 238 / 60` | Variance report |
| `DISP-ADJ-001` | `WWHN-219` | `apply-adjustment` là business action, không phải endpoint |

## 3. Điều `WWHN-234` bổ sung vào nhánh này

`DISP-STK-233` phủ phần **kiểm kê**. Nó không nói gì về phần còn lại của `WWHN-234` — tức là
Concurrency & State review của **các action đổi tồn thật sự tồn tại**. Đóng góp của
`WWHN-234` vào nhánh `WWHN-59` gồm đúng ba thứ:

1. **Ghi `WWHN-234` vào impacted list của `DISP-STK-233`.** Hiện `DISP-STK-233` liệt kê
   `WWHN-59 / 233 / 235`; thiếu `234`. Đây là hành động của BA khi ký — xem §5.
2. **Bản review thật cho phạm vi in-scope** (`CONCURRENCY_STATE_REVIEW.md`), thứ mà
   disposition kiểm kê theo định nghĩa không thể cung cấp.
3. **Phần `235-A` của QA gate** (`QA1_HANDOFF_WWHN-235.md`): `DISP-STK-233` §8 đã dispose
   phần Stocktake của `WWHN-235` thành `DEFERRED / N/A`, nhưng `WWHN-235` còn tám chiều test
   (duplicate / simultaneous / stale / partial / invalid transition / RBAC / rollback / DB
   verification) áp dụng cho `POST_RECEIPT`, `POST_ISSUE`, `POST_REVERSAL`,
   `MIGRATION_COMMIT` — bốn action **có thật**. Phần đó chưa ai giao cho QA1.

## 4. Dữ kiện runtime đo lại tại SHA của `WWHN-234`

Không phải để thay `DISP-STK-233`, mà để xác nhận disposition vẫn còn đúng tại thời điểm này
(disposition PROPOSED mà baseline đã trôi thì chữ ký sẽ ký lên một sự thật cũ).

| # | Mệnh đề | Cách đo | Kết quả |
|---|---|---|---|
| R1 | Không có route Stocktake | `php artisan route:list --json` | **0/239** |
| R2 | Không có permission Stocktake | grep `database/seeders`, `Modules/*/database/seeders` | 0 hit |
| R3 | Không có controller/service Stocktake | liệt kê `Modules/Warehouse/app/Http/Controllers`, `.../Services` | 0 file |
| R4 | Bảng `stocktake_*` + `safe_stock_configs` đã drop | `Modules/Warehouse/database/migrations/2026_08_10_000600_enforce_current_erd_business_contract.php:39-49` | 6 bảng |
| R5 | OpenAPI `2.0.0` không có operation Stocktake | quét 231 operationId | 0 |
| R6 | FE không có route/permission/màn hình | `src/app/router/access.test.ts:71-77`, `src/shared/constants/routes.ts:31`, `wms-permissions.ts:71` | guard xanh |

Sáu phép đo này chạy lại được bằng `node scripts/verify-wwhn234-closure.mjs`.

## 5. Ô ký — approver tự điền

`DEV234-P1-01` **không** cần một chữ ký riêng. Nó đóng khi `DISP-STK-233` được ký, với đúng
một bổ sung: impacted list phải có `WWHN-234`.

```text
Tôi xác nhận WWHN-234 kế thừa DISP-STK-233 v1.0.0 (không phát hành disposition mới),
và impacted list của DISP-STK-233 được bổ sung: WWHN-59, WWHN-233, WWHN-234, WWHN-235.

DISP-STK-233 effective date : ______________________ (ISO-8601, hoặc NULL nếu chưa hiệu lực)
BA Main   : tên ____________  kết quả ____________  ngày __________
PM/PO     : tên ____________  kết quả ____________  ngày __________
Tech Lead : tên ____________  kết quả ____________  ngày __________  (reviewer)
QA        : tên ____________  kết quả ____________  ngày __________  (reviewer)
```

Chừng nào `DISP-STK-233-APPROVAL`-tương đương còn `PROPOSED / effective date NULL`,
`DEV234-P1-01` vẫn **OPEN** và gate G1 chưa đóng. `DEV234-P1-02/03/04` được soạn theo đúng
hướng của `DISP-STK-233` (Stocktake DEFERRED — NO CODE) vì đó là trạng thái runtime đang tồn
tại; nếu approver đảo hướng thì ba hạng mục đó phải **mở rộng phạm vi**, không phải viết lại.

## 6. Điều tài liệu này không làm

- Không tuyên bố `DISP-STK-233` đã APPROVED — nó vẫn `PROPOSED`.
- Không tuyên bố `DEC-NO-STOCKTAKE-001` là approved standalone Decision ID; formal decision
  là `WWHN-25`, còn `In Progress`.
- Không sửa file của ticket khác. `DISP-STK-233`, `DISP-STK-230`, `DISP-STK-236`,
  `DISP-STK-237` chỉ được **đọc** ở đây.
