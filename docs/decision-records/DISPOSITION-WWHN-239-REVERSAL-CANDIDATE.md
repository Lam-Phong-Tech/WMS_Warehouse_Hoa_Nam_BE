# DISPOSITION — Reversal candidate mà WWHN-240 consume

- ID: `DISP-REV-240-001`
- Ngày: 2026-08-18
- Parent decision: `DEC-REV-001` / WWHN-26 (`PROPOSED — PENDING INDEPENDENT SIGN-OFF`)
- Upstream task: WWHN-239 (pin accepted reversal candidate)
- Consumer task: WWHN-240 (XNT net effect + trace chain + OpenAPI)
- Downstream QA task: WWHN-241
- Đóng gate: `DEV240-P1-01`

---

## 1. Vì sao cần văn bản này

`DEV240-P1-01` chặn WWHN-240 vì WWHN-240 **consume một baseline reversal chưa được chốt**:
`DEC-REV-001` vẫn ở trạng thái `PROPOSED — PENDING INDEPENDENT SIGN-OFF` và WWHN-239 chưa
pin exact SHA của candidate được chấp nhận. Nếu cứ triển khai tiếp, mọi công thức net
effect trong báo cáo và trace sẽ được gắn vào một hợp đồng có thể còn đổi.

Văn bản này **không** thay Decision Owner phê duyệt `DEC-REV-001`. Nó làm đúng phần có thể
làm mà không vượt quyền: khoá lại **runtime reversal candidate đang tồn tại thật trong
repo**, chỉ ra chỗ `DEC-REV-001` đã lệch khỏi SRS v1.12, và tuyên bố rõ WWHN-240 phụ thuộc
vào **đúng cái gì**, để phê duyệt formal chỉ còn là một thao tác transition trên Jira.

## 2. Runtime reversal candidate (nguồn sự thật hiện hành)

Tính đến `2026-08-18`, luồng Reversal đang chạy thật trên nhánh `m1` gồm:

| Thành phần | Đường dẫn |
|---|---|
| Service | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php` |
| Controller | `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php` |
| Flow controller | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php` |
| Data-scope guard | `Modules/Warehouse/app/Http/Middleware/EnforceReversalWarehouseScope.php` |
| Ledger | `stock_movements` (`movement_type='REVERSAL'`, `original_movement_id`, `correction_chain_id`) |

Bất biến đã có runtime và đã có test khoá:

1. Movement gốc bất biến; Reversal chỉ **append** movement đối ứng (`StockMovement::booted()`
   ném `LogicException` cho mọi `update`/`delete`).
2. `movement_type` canonical là **`REVERSAL`** cho cả đảo-nhập lẫn đảo-xuất.
   `RECEIPT_REVERSED`/`ISSUE_REVERSED` trong SRS/TC-P1-025 là **tên nghiệp vụ**, đã được
   migration `2026_08_09_000420` và `2026_08_10_000500` quy về `REVERSAL`; chiều đảo đọc
   bằng `original_movement_id`, không đọc bằng tên type.
3. `reversible_qty = |movement gốc.quantity| - Σ quantity của reversal_line đã POSTED`
   (`CurrentBaselineReversalService::…`), guard trong cùng transaction + row lock.
4. Permission runtime là `inventory.reversal.post` (đúng SRS v1.12 §6/§8.1).

## 3. Lệch đã phát hiện giữa DEC-REV-001 và SRS v1.12

| # | `DEC-REV-001` (viết theo SRS v1.4) | SRS/FRD v1.12 + runtime m1 | Disposition |
|---|---|---|---|
| 1 | Route canonical `POST /api/v1/reversals` | `POST /api/v1/reversal-documents`, `POST /api/v1/{inbound\|outbound}-documents/{id}/reversals` | **SRS v1.12 thắng.** DEC-REV-001 §5 là đề xuất chưa adopt, chính §6 của nó đã ghi `FAIL-CLOSED / NOT IMPLEMENTATION EVIDENCE`. |
| 2 | Permission `stock.reversal.create` | `inventory.reversal.post` | **SRS v1.12 thắng** (§6 RBAC + §8.1). |
| 3 | Trường `reversal_of_movement_id` | Cột thật `original_movement_id` + `correction_chain_id` | **ERD/runtime thắng**; SRS §7 Data Dictionary ghi `Inventory_Movement.original_movement_id`. |
| 4 | Mã lỗi `IDEMPOTENCY_ERROR`, `NO_PERMISSION`, `ORIGINAL_NOT_FOUND` | Catalog §8.2 hiện hành (`IDEMPOTENCY_CONFLICT`, `ACCESS_DENIED`/`PERMISSION_DENIED`, `RESOURCE_NOT_FOUND`) | **Catalog §8.2 thắng**; đã đóng ở WWHN-289. |

→ Kết luận: **nội dung nghiệp vụ** của `DEC-REV-001` (Option B — append-only, movement gốc
bất biến, không có generic stock-edit) đã được adopt đầy đủ trong SRS v1.12 §5.5 BR-REV-01..12
và đang chạy đúng. Chỉ có **lớp vỏ contract** trong `DEC-REV-001` §5 là lỗi thời.

## 4. WWHN-240 phụ thuộc vào đúng cái gì

WWHN-240 **chỉ** phụ thuộc bốn bất biến ở §2, tất cả đều thuộc phần đã adopt:

- BR-REV-01 (append-only) → cho phép tính net effect bằng cách cộng dồn ledger.
- BR-REV-05 (đảo từng phần, nhiều lần) → trace phải trả **danh sách** reversal.
- BR-REV-08 (reversal-của-reversal) → phải đi ngược `original_movement_id` tới gốc.
- BR-REV-11 (report hiển thị gốc + chain + net effect) → chính là scope của WWHN-240.

WWHN-240 **không** phụ thuộc route name, permission key hay error code của `DEC-REV-001` §5.
Vì vậy nếu Decision Owner phê duyệt `DEC-REV-001` với các đính chính ở §3, **không có dòng
code nào của WWHN-240 phải sửa lại**.

## 5. Điều còn lại phải làm (không thuộc quyền DEV)

| Việc | Owner | Ghi chú |
|---|---|---|
| Chuyển `DEC-REV-001` sang `APPROVED` kèm 4 đính chính §3, hoặc phát hành disposition chính thức | Decision Owner (PM/PO) + Tech Lead | Nội dung nghiệp vụ không đổi ⇒ **không cần Change Request nghiệp vụ** |
| Pin exact SHA của accepted reversal candidate lên WWHN-239 | WWHN-239 owner | SHA lấy ở `docs/evidence/WWHN-240/BE_CHANGE_MANIFEST.md` |
| Ghi vào WWHN-240 rằng candidate consume = SHA đã pin ở WWHN-239 | DEV | Sau khi WWHN-239 pin xong |

Đến khi Decision Owner ra kết quả formal, trạng thái đúng của WWHN-240 là
**"code hoàn tất, chờ formal decision outcome"**, không phải "blocked do thiếu thiết kế".
