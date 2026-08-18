# WWHN-239 — DEV239 P0/P1/P2 EXACT FIX ACTION REGISTER (closure)

| Field | Value |
|---|---|
| Jira task | `WWHN-239` (DEV Reversal) · QA1 song hành `WWHN-241` |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` · branch `m1` (không tạo nhánh riêng) |
| Controlling decision | `DEC-REV-001` / `HN-DEC-004` / `WWHN-26` — xem `docs/decision-records/DISPOSITION-WWHN-239-REVERSAL-CONTROLLING-DECISION.md` |
| Baseline nghiệp vụ | SRS/FRD v1.12 `REQ-SRS-013`, `AC-SRS-013`, `BR-REV-01..12`, `§6`, `§8.1`, `§8.2`; ERD bảng 33/34 |
| Điểm đánh giá trước | 68/100 |
| Ngày lập | 2026-08-18 |

Register này bám đúng 5 dòng của bảng chấm điểm. Mỗi dòng ghi: hiện trạng đo được →
việc đã làm → bằng chứng → phần còn lại thuộc về ai.

---

## 1. Bảng đóng theo từng ID

| ID | Sev | Trạng thái sau phiên này | Việc đã làm (DEV) | Việc còn lại (không phải DEV) |
|---|---|---|---|---|
| DEV239-P1-01 | P1 | **DEV-DONE · chờ BA/Approver** | Lập formal disposition `DISP-REV-239`: đo trạng thái `DEC-REV-001` (không có version EFFECTIVE, ledger §12 toàn `PENDING`), chốt SRS v1.12 là nguồn điều khiển, tách `OP-REV-05` (API surface) khỏi bất biến nghiệp vụ, và quy `HN-STK-003` thành danh sách S-1…S-10 có enforcement + evidence từng dòng | BA Main + Approver ghi kết quả D-1…D-6 trên Jira |
| DEV239-P1-02 | P1 | **DEV-DONE · chờ Tech Lead** | Pin file set / migration / OpenAPI / test / lệnh chạy trong `EVIDENCE_MATRIX.md`; nêu rõ PR=N/A và lý do (m1 là nhánh làm việc chung, đã có 2 phiên song song trên cùng working tree) | Tech Lead review + ghi exact SHA sau khi DEV freeze candidate |
| DEV239-P1-03 | P1 | **DONE** | `migrate:fresh --seed` + suite Reversal + regression tồn kho chạy thật trên **PostgreSQL 16**, log thô lưu trong thư mục này | QA1/TL đối chiếu log |
| DEV239-P1-04 | P1 | **DEV-DONE · chờ QA1 (WWHN-241)** | Dựng **test pack tự động** phủ đúng 10 kịch bản QA1 + bộ SQL đối soát 7 truy vấn; **đóng 1 defect P0 mà chính ma trận QA1 sẽ trượt** (xem §2) | QA1 chạy trên exact build và nộp report WWHN-241 |
| DEV239-P2-01 | P2 | **DEV-DONE · chờ PM/BAS** | Nêu rõ field nào authoritative (§5) | PM/BAS đồng bộ field trên Jira |

---

## 2. Defect thật đã phát hiện và đóng trong phiên này

### D-239-01 (P0) — Double-reverse cùng một serial: `duplicate movement` ≠ 0

**Vị trí:** `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php`
(`createDraft`, `postDraft`, `assertItemDependency`).

**Cơ chế lỗi.** `CurrentBaselineStockService::postStockDocument()` ghi **một** movement
cho mỗi `stock_document_line`, với `quantity = qty_planned`. Một line PRODUCT
serial-tracked gồm N item vì thế chỉ sinh **một** movement `RECEIPT quantity=N`
(`item_id` để null khi line có nhiều hơn một item — xem `resolveLineItemId()`).

Guard `reversible_qty` của Reversal lại tính theo **tổng quantity** của movement đó:

```php
$alreadyPosted = … sum(quantity_to_reverse) …;
if ($alreadyPosted + $staged + $qty > $originalQty) { abort(422, 'REVERSAL_QTY_EXCEEDED'); }
```

Hệ quả: với N = 3, client gọi ba lần reversal cho **cùng một `item_id`** vẫn nằm trong
hạn mức 3 → hệ thống sinh **3 movement `REVERSAL` cho một serial vật lý duy nhất** và
trừ tồn 3 lần.

`assertItemDependency()` không chặn được vì nó **cho phép** `item_status = 'ADJUSTED_OUT'`
ở nhánh receipt — mà `ADJUSTED_OUT` chính là trạng thái do lần reversal trước đặt ra.
Nói cách khác, guard đang whitelist đúng cái state chứng minh "item này đã bị đảo rồi".

**Vì sao không ai thấy trước đó.** Preflight `GET /stock-movements/reversible`
(`StockMovementController::buildItemCandidateMap`) chỉ chào item `IN_STOCK`/`NEW`, nên
UI không bao giờ đề xuất item đã đảo. Lỗ hổng chỉ lộ khi gọi API trực tiếp — và SRS
§8.1 quy định backend là nguồn chuẩn, enforcement **không được lỏng hơn** UI.

**Vì sao đây đúng là việc của WWHN-239.** Ba dòng trong ma trận QA1 của DEV239-P1-04 —
`double-reverse`, `duplicate movement = 0`, `ledger = balance` — sẽ trượt trên chính
kịch bản này. Đóng nó **trước** khi QA1 chạy là cách duy nhất để WWHN-241 không phải
mở lại vòng nữa.

**Cách đóng — ba lớp, fail-closed:**

1. **Tầng nghiệp vụ, lúc tạo DRAFT** — `assertItemNotAlreadyReversed()`:
   - cùng một `item_id` khai hai dòng trong một request → `422 REVERSAL_ITEM_DUPLICATED`;
   - đã tồn tại dòng reversal POSTED cho cặp (`original_movement_id`, `item_id`) →
     `409 REVERSAL_ITEM_ALREADY_REVERSED` (biến thể chi tiết của lớp `STATE_CONFLICT`
     theo `App\Support\ConflictCode`).
2. **Tầng nghiệp vụ, lúc Post, dưới `lockForUpdate`** — re-check cùng điều kiện; nếu một
   reversal song song đã Post trước thì trả `409 CONCURRENCY_CONFLICT`
   (`ConflictCode::CONCURRENCY`) để client **reload rồi retry với cùng Idempotency-Key**,
   đúng phân lớp WWHN-234.
3. **Tầng DB** — migration `2026_08_18_214000_enforce_one_posted_reversal_per_item.php`
   tạo partial unique index:

   ```sql
   CREATE UNIQUE INDEX ux_reversal_line_posted_item_per_movement
       ON reversal_lines (original_movement_id, item_id)
       WHERE item_id IS NOT NULL AND posted_movement_id IS NOT NULL;
   ```

   Điều kiện `posted_movement_id IS NOT NULL` (thay vì `status='POSTED'` — cột đó nằm ở
   bảng header nên không dùng được trong partial index một bảng) khiến DRAFT/CANCELLED
   không bị chặn, đúng ý BR-REV-08. Đây là lớp duy nhất đúng cho **true competing
   reversal**: hai transaction thật sự song song trên PostgreSQL thì đúng một transaction
   commit được, transaction còn lại rollback toàn bộ, không phụ thuộc thứ tự lock của
   tầng ứng dụng.

**Siết kèm theo:** bỏ `ADJUSTED_OUT` khỏi whitelist receipt trong
`assertItemDependency()`. Item `ADJUSTED_OUT` được **nhận lại** bình thường
(`assertItemAvailableForReceipt` vẫn cho `NEW`/`ADJUSTED_OUT`) và sau khi Post Receipt
nó trở lại `IN_STOCK` → vẫn đảo được. Không mất năng lực nghiệp vụ nào.

### D-239-02 (P1, liên đới) — Data scope của nhóm `/reversal-documents`

Bảy route `reversal-documents` (`index/store/show/lines/confirm/post/cancel`) chỉ có
`permission:` mà không có ràng buộc phạm vi kho, trong khi nhóm inventory (WWHN-211) và
inbound (WWHN-289) đã có — trái §8.1 "Exact x-permission + data scope; default deny" và
`DEC-REV-001` §3 bất biến 9.

Việc này **thuộc WWHN-242** (một phiên khác đang làm song song trên cùng `m1`) và đã
được nối vào route ở đó. Phần WWHN-239 đóng góp là class middleware
`Modules/Warehouse/app/Http/Middleware/EnforceReversalWarehouseScope.php`, fail-closed
trên cả ba mặt: tham số `warehouse_id`, truy cập theo `{id}` (giải qua
`reversal_documents`/`reversal_document` tuỳ driver), và `reference_document_id` khi tạo
mới (giải qua `stock_documents` rồi `stock_movements`).
`Wwhn242ReversalScopeAndProjectionTest` (7 test) xanh trên cả hai driver.

---

## 3. Thay đổi mã nguồn (file set chính xác)

| File | Loại | Nội dung |
|---|---|---|
| `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php` | sửa | `assertItemNotAlreadyReversed()`, `postedReversalLineForItem()`, re-check dưới lock ở `postDraft()`, siết whitelist `assertItemDependency()` |
| `Modules/Warehouse/app/Http/Middleware/EnforceReversalWarehouseScope.php` | thêm | data scope cho nhóm reversal (dùng chung với WWHN-242) |
| `Modules/Warehouse/database/migrations/2026_08_18_214000_enforce_one_posted_reversal_per_item.php` | thêm | partial unique index theo item; tự dò relation vật lý theo driver; fail-safe khi dữ liệu cũ đã trùng |
| `tests/Feature/Wwhn239ReversalItemLevelClosureTest.php` | thêm | 8 test = 10 dòng ma trận QA1 |
| `docs/decision-records/DISPOSITION-WWHN-239-REVERSAL-CONTROLLING-DECISION.md` | thêm | DEV239-P1-01 |
| `docs/evidence/WWHN-239/*` | thêm | register, evidence matrix, QA1 pack, SQL đối soát, log thô |

**Không đụng tới:** `Modules/Warehouse/routes/api.php` và
`ReversalDocumentController.php` — hai file đó đang do phiên WWHN-242 sửa trên cùng
working tree; WWHN-239 cố tình không ghi đè.

---

## 4. Điều KHÔNG phải defect (đã kiểm, ghi lại để lần rà sau khỏi mở lại)

| Nghi vấn | Kết luận | Căn cứ |
|---|---|---|
| Mã 409 của luồng reversal (`REVERSAL_NOT_DRAFT`, `REVERSAL_POSTED_IMMUTABLE`, …) không nằm trong 4 mã §8.2 | **Không phải defect** | Cột §8.2 có tiêu đề "Error code **examples**"; `App\Support\ConflictCode` quy định rõ chỉ **lớp concurrency và lớp downstream-dependency** phải dùng đúng hằng, mã miền chi tiết vẫn hợp lệ như biến thể của `STATE_CONFLICT` |
| Runtime dùng `POST /inbound-documents/{id}/reverse` thay vì `POST /api/v1/reversals` của `DEC-REV-001` §5 | **Không phải defect** | `DEC-REV-001` §6 tự ghi `OP-REV-05` là ticket adoption riêng; SRS §8.1 mới là nguồn khai path đang chạy — xem `DISP-REV-239` §2 |
| `DEC-STK-001` (kiểm kê) còn `PROPOSED` | **Không chặn** | REQ-SRS-037 đã gỡ Stocktake khỏi v1.12; Reversal không đọc artefact kiểm kê — `DISP-REV-239` §3 dòng S-10 |
| Hai DRAFT reversal cùng khai hết `reversible_qty` | **Không phải defect** | `postDraft()` re-check dưới lock và trả `409 CONCURRENCY_CONFLICT`; DRAFT là stock-neutral |

---

## 5. DEV239-P2-01 — Sprint metadata

Hai field đang lệch: `Sprint = W3` (field chuẩn của Jira board) và
`Sprint Scope (custom) = W4`.

**Field authoritative = `Sprint` (field hệ thống của board).** Lý do: field hệ thống là
thứ chi phối burndown, velocity và mọi báo cáo Reporting; custom field chỉ là nhãn kế
hoạch do người nhập tay, không vào phép tính nào. Vì vậy đồng bộ theo hướng
**sửa custom field `Sprint Scope` về W3**, không phải kéo `Sprint` sang W4 — kéo sprint
hệ thống sẽ làm sai luôn báo cáo của cả sprint chứ không chỉ của task này.

---

## 6. Trở ngại môi trường gặp khi thu bằng chứng P1-03 (ghi lại để khỏi quy sai trách nhiệm)

Tại thời điểm thu bằng chứng, `php artisan migrate:fresh --seed` **hỏng trên PostgreSQL
16** ở chính `m1` — không phải do WWHN-239:

```
SQLSTATE[42804]: Datatype mismatch: 7 ERROR:  column "created_by" is of type uuid but expression is of type text
QUERY: INSERT INTO "pv_industries" (...) SELECT ... FROM "pv_industries__legacy_projection" ON CONFLICT ("id") DO UPDATE SET ...
```

- Nguồn: `Modules/Warehouse/database/migrations/2026_08_19_000100_add_optimistic_lock_to_pv_master_tables.php`
  (commit `b90d5ef`, WWHN-252) tạo lại view `pv_industries__legacy_projection` /
  `pv_tags__legacy_projection` với `NULL::text AS created_by, NULL::text AS updated_by`,
  trong khi bảng vật lý `pv_industries`/`pv_tags` khai hai cột đó là `uuid`. Trigger
  mirror `trg_erd_mirror_*` INSERT ... SELECT từ view sang bảng nên PostgreSQL từ chối.
- Chỉ lộ trên PostgreSQL; sqlite không cưỡng chế kiểu nên suite sqlite vẫn xanh.
- Hệ quả đo được: chạy suite Reversal trên pgsql ở working tree chung cho **41/41 test
  error ngay tại `setUp`** với đúng một message trên.
- **Bằng chứng migration của WWHN-239 nằm TRƯỚC điểm hỏng**: log
  `RAW_migrate_fresh_seed_pgsql.log` cho thấy `2026_08_18_214000_enforce_one_posted_reversal_per_item`
  là migration **thứ 155 và DONE (15.55ms)**, rồi mới chết ở migration PV phía sau.

Cách xử lý của WWHN-239: **không sửa file của WWHN-252** (đang là vùng đang làm dở, và
đổi kiểu cột của view đang có trigger phụ thuộc là thao tác dễ brick DB — bài học
WWHN-300). Thay vào đó thu bằng chứng trên **worktree cô lập**:

1. `git worktree add --detach <tmp> <HEAD>` — HEAD sạch, không mang WIP của phiên khác.
2. Junction `vendor` về repo chính.
3. Chép **đúng file set candidate** của WWHN-239 (§3) vào worktree.
4. Gỡ **chỉ trong worktree tạm** migration PV đang hỏng để cô lập luồng Reversal.
5. `migrate:fresh --seed` + suite Reversal + `RECONCILIATION.sql` trên database riêng
   `hn_wwhn239_iso`.

Đây là đúng phương pháp đã dùng cho các lần "lỗi này do mình hay có sẵn" trước đây, và
nó giữ nguyên tính quy trách nhiệm của bằng chứng. Defect PV đã được tách thành hạng mục
riêng cho chủ sở hữu WWHN-252 — **không** gộp vào closure của WWHN-239.
