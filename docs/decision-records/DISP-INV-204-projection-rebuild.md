# DISP-INV-204 — Ranh giới "dựng lại projection từ ledger" so với lệnh cấm sửa tồn trực tiếp

| Trường | Giá trị |
|---|---|
| ID | `DISP-INV-204` |
| Version | 1.0.0 |
| Ngày hiệu lực | 2026-08-19 |
| Ticket | `WWHN-204` — [DEV] Triển khai ledger/số dư/truy vấn/đối soát (parent `WWHN-48`, epic `HN-EPIC-07`) |
| Đóng mục register | `DEV204-P1-03` (rebuild balance từ immutable ledger), `DEV204-P1-05` (technical baseline của DEC-INV-001) |
| Nhánh | `m1` (xem `docs/evidence/WWHN-204/BRANCH_CANDIDATE_PIN.md`) |
| Trạng thái | ĐỀ XUẤT KỸ THUẬT — chờ BA/BAS + Tech Lead xác nhận (không phải quyết định nghiệp vụ mới) |

---

## 1. Vấn đề phải xử lý

Register khắc phục WWHN-204 yêu cầu ở `DEV204-P1-03`:

> *"Implement hoặc formalize cơ chế rebuild balance từ immutable ledger; sau intentional drift
> phải đưa projection về ledger authoritative"* — neo vào `inventory:reconcile` và `WWHN-48 AC#2`.

Đọc thẳng câu này rồi đi hiện thực sẽ đâm vào hai điều khoản đang có hiệu lực của baseline:

| Nguồn | Câu chữ | Nếu hiểu sai thì mâu thuẫn ở đâu |
|---|---|---|
| SRS/FRD v1.12 §7.5 "Reconciliation Controls", dòng **Divergence** | *"Create Reconciliation_Incident and block readiness/sign-off; resolve with audited reversal under WWHN-26, **never by direct ledger or Balance edit**."* | Một lệnh ghi vào `inventory_balances` trông đúng như "direct Balance edit" bị cấm |
| SRS/FRD v1.12 `REQ-SRS-037` / `AC-SRS-037` / `BR-NOSTK-01..03` | *"Không expose Stocktake/Variance, menu/API/permission/entity/report hoặc action sửa tồn trực tiếp"*; *"reconciliation chỉ tạo incident, sửa sai dùng reversal theo WWHN-26"* | Một cơ chế "đưa số dư về giá trị đúng" dễ bị coi là apply-adjustment đã bị gỡ ở `DISP-STK-230/231/236/237` |

Đây chính là chỗ mà các ticket trước đã phải park (`DISP-STK-236` apply-adjustment DEFERRED,
`DISP-STK-237` variance report DEFERRED). Nếu WWHN-204 hiện thực bừa thì sẽ **mở lại đúng
capability vừa bị đóng**, và nếu không hiện thực gì thì `WWHN-48 AC#2` vẫn hở.

## 2. Phân biệt hai khái niệm bị gộp làm một

Điểm gốc của mâu thuẫn: chữ "sửa số dư" trong baseline đang chỉ **một** hành vi, còn register
đang nói tới **một hành vi khác**.

| | (A) Business variance absorption — **BỊ CẤM** | (B) Projection rebuild — phạm vi DISP-INV-204 |
|---|---|---|
| Câu hỏi nghiệp vụ | "Đếm thực tế lệch sổ sách, ghi nhận lệch đó vào tồn" | "Bộ nhớ dẫn xuất lệch khỏi nguồn sự thật, đồng bộ lại" |
| Nguồn của con số đích | Người vận hành nhập vào (kết quả kiểm kê) | `SUM(stock_movements.qty_delta)` — không có tham số nhập tay |
| Ledger sau thao tác | Phải sinh movement mới (đó là lý do §7.5 bắt đi qua Reversal) | **Không đổi một byte nào** |
| Tổng tồn nghiệp vụ | Thay đổi | Không đổi so với nguồn sự thật; chỉ bản sao dẫn xuất được sửa |
| Trạng thái baseline | `REQ-SRS-037` gỡ khỏi sản phẩm; `DISP-STK-236/237` DEFERRED | §7.1 định nghĩa `Inventory_Balance = Projection` — bản chất là artifact dẫn xuất |

Đẳng thức mà (B) tái lập chính là dòng đầu tiên của bảng §7.5:

> `Ledger to Balance | Balance = Opening + Posted Receipt - Posted Issue + Posted Reversal`

Tức là §7.5 vừa **định nghĩa** giá trị đúng của `Balance` là một hàm của ledger, vừa **cấm**
đặt `Balance` sang một giá trị *khác* hàm đó. Rebuild làm đúng vế thứ nhất và không vi phạm
vế thứ hai. Nói cách khác: §7.5 cấm *nội dung* của phép ghi (một con số không có trong
ledger), không cấm *việc chạm vào bảng projection* — bằng chứng là chính đường ghi nghiệp vụ
(`post-receipt`, `post-issue`, `reversal`) vẫn UPDATE bảng này mỗi ngày.

## 3. Vì sao projection luôn dựng lại được (không mất dữ liệu)

Quét toàn bộ điểm ghi `inventory_balances` trên `m1`:

| Điểm ghi | File | Có insert `stock_movements` cùng transaction? |
|---|---|---|
| `mutateBalance()` (post-receipt, component/warranty issue, opening) | `Modules/Warehouse/app/Services/CurrentBaselineStockService.php` | Có |
| `mutateIssueBalance()` (Post Issue) | `Modules/Warehouse/app/Services/AlignedOutboundStockService.php` | Có |
| Reversal | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php` | Có |

Không có đường ghi tồn nào nằm ngoài ledger. Đúng như §7.1 quy định *"Inventory_Balance |
Projection | Updated in same transaction with ledger"*. Vì vậy ledger là **tập cha đầy đủ**
của mọi biến động tồn: dựng lại từ ledger không thể làm mất một biến động hợp lệ nào.

## 4. Quyết định

**Chấp nhận phương án (B), hiện thực dưới dạng công cụ vận hành CLI, với 6 ràng buộc bắt buộc.**

| # | Ràng buộc | Cưỡng chế ở đâu |
|---|---|---|
| R1 | Giá trị đích **chỉ** đến từ ledger; không có tham số nào cho phép nhập số tự chọn | `InventoryProjectionRebuildService::plan()` — chỉ đọc `LedgerReconciliationService::ledgerTotals()` |
| R2 | Không một câu lệnh nào ghi `stock_movements` | Trigger append-only ở DB (§5) + assertion `ledgerFingerprint()` trước/sau, lệch thì ném `LEDGER_MUTATED_DURING_REBUILD` |
| R3 | Không expose lên HTTP/menu/permission → `AC-SRS-037` vẫn đúng | Bề mặt duy nhất là `php artisan inventory:reconcile --rebuild`; test `test_rebuild_is_not_exposed_as_any_http_route` |
| R4 | Phải ghi vết: divergence được ghi incident **trước**, rebuild đóng incident với `resolution_ref` | `ReconcileInventoryBalances::handle()` chạy bước phát hiện trước `runRebuild()` |
| R5 | Có audit trail đầy đủ before/after | `AuditLogger::record('INVENTORY_PROJECTION_REBUILT', ...)` mỗi dimension |
| R6 | Idempotent — chạy lại trên hệ đã khớp là no-op | Test `test_rebuild_is_idempotent_on_a_consistent_system` |

**Không thay đổi** so với baseline: không thêm endpoint, không thêm permission, không thêm
entity, không đổi response shape của bất kỳ API nào, không đụng OpenAPI. FE và QA giữ nguyên
contract hiện hành (xem `docs/evidence/WWHN-204/SOURCE_BASELINE_PIN.md` §4).

## 5. Hạng mục kèm theo: append-only phải cưỡng chế ở tầng DB trên CẢ HAI driver

R2 chỉ đáng tin nếu ledger thật sự bất biến. Khi rà soát đã phát hiện một lỗ hổng thật:

- `2026_08_15_000100_enforce_stock_movements_append_only_trigger.php` (WWHN-205) mở đầu bằng
  `if (DB::getDriverName() !== 'pgsql') return;` ⇒ **chỉ PostgreSQL** có trigger.
- Trên sqlite — driver mà toàn bộ test suite và CI `current-baseline-doc-contract.yml` đang
  chạy — ledger chỉ còn hàng rào ở tầng Eloquent (`StockMovement::booted()`), trong khi mọi
  đường ghi tồn thật lại dùng `DB::table(...)`, tức **đi vòng qua Eloquent**.
- Hệ quả: bất biến "Movement append-only" của DEC-INV-001 §7.1 **không thể chứng minh bằng
  test trên driver mặc định**; một hồi quy phá vỡ tính bất biến sẽ lọt qua CI.

Đã bổ sung `2026_08_19_050100_enforce_stock_movements_append_only_on_sqlite.php` — cùng ngữ
nghĩa, cùng thông điệp lỗi `... is append-only under HN-WMS-ERD-DD-001`. Bằng chứng chạy thật
trên PostgreSQL mục tiêu ở `docs/evidence/WWHN-204/PG_APPEND_ONLY_EVIDENCE.md`.

## 6. Cái KHÔNG thuộc quyết định này

- Không hồi sinh Stocktake/Variance/apply-adjustment. `DISP-STK-230`, `DISP-STK-231`,
  `DISP-STK-233`, `DISP-STK-236`, `DISP-STK-237` giữ nguyên hiệu lực.
- Không thay `WWHN-26`/`BR-REV-01` làm đường sửa sai nghiệp vụ. Lệch **thật** (hàng hoá thực
  tế khác sổ) vẫn phải đi Reversal; rebuild không giải quyết loại lệch đó và không được dùng
  cho mục đích đó.
- Không tự nhận là "quyết định nghiệp vụ mới". Đây là **technical baseline** của
  `DEC-INV-001` đã duyệt; cần BA/BAS + Tech Lead xác nhận cách diễn giải §7.5 ở mục 2 là
  đúng ý baseline.

## 7. Truy vết

| Neo | Tham chiếu |
|---|---|
| Quyết định nghiệp vụ gốc | `DEC-INV-001` (SRS v1.12 §7.1, §1.6 Supersession Register dòng *"Balance/Movement/subledger without authority definition → DEC-INV-001"*) |
| Điều khoản ràng buộc | SRS v1.12 §7.5; `REQ-SRS-037` / `AC-SRS-037` / `BR-NOSTK-01..03`; `REQ-SRS-013` / `BR-REV-01` |
| Yêu cầu gốc của rebuild | `WWHN-48` AC#2 (parent `HN-INV-001`) |
| Quyết định liên quan | `DISP-STK-231` (đối soát stock-neutral, dùng chung `LedgerReconciliationService`) |
| Hiện thực | `Modules/Warehouse/app/Services/InventoryProjectionRebuildService.php`, `app/Console/Commands/ReconcileInventoryBalances.php` |
| Test | `tests/Feature/Wwhn204LedgerProjectionRebuildTest.php` (6 case) |
| Gate | `node scripts/verify-wwhn-204-closure.mjs` |
