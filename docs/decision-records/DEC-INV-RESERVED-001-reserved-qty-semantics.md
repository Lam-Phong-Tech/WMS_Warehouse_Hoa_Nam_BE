# DEC-INV-RESERVED-001 — Ngữ nghĩa `inventory_balances.reserved_qty`

- Decision date: 2026-08-19
- Ticket nguồn: WWHN-198 (register DEV198-P1-04R); kế thừa open point BE1-RESV-OP-01 (WWHN-199)
  và BE2-PICK-OP-03 (WWHN-198 mục 11).
- Status: ADOPTED (chờ BA Main ký xác nhận — xem mục 5).
- Sources: HN_SRS_FRD_v1.12_FULL.md §5.2, §8.1, §15 (Data Dictionary precedence);
  HoaNam_ERD_Moi_Nhat.md bảng `inventory_balances`; DR-HN-20260817 (Flow 03);
  DEC-OUT-STOCK-HTTP-001.

## 1. Vấn đề

`inventory_balances.reserved_qty` tồn tại trong schema nhưng runtime **luôn ghi 0**.
Không có đường code nào tăng/giảm nó. Trong khi đó FE có map field này
(`inventoryService.ts` → `reservedQty`) và ERD gợi ý đây là "số lượng đang giữ chỗ".
Nếu ai đó hiểu `reserved_qty` là "hàng đang bị phiếu DRAFT/SCANNING giữ", họ sẽ kết
luận sai rằng hệ thống không giữ chỗ gì cả — hoặc tệ hơn, tự tính
`khả dụng = on_hand_qty - reserved_qty` và ra số sai.

## 2. Quyết định

**`reserved_qty` là COMPATIBILITY FIELD, luôn bằng 0, và KHÔNG phải nguồn sự thật của
bất kỳ phép tính khả dụng nào.**

Mô hình giữ chỗ thật của hệ thống là **giữ chỗ theo ITEM (identity-level), không theo
số lượng (quantity-level)**:

| Câu hỏi | Nguồn sự thật |
|---|---|
| Item vật lý này đã bị phiếu khác chiếm chưa? | `outbound_item_matches.status = 'ACTIVE'` (+ unique partial index `ux_erd_outbound_active_item` / `uk_outbound_match_active_item`) |
| Còn bao nhiêu hàng để bán/xuất? | `inventory_balances.available_qty` |
| Tồn vật lý đang có? | `inventory_balances.on_hand_qty` / `quantity` |
| Đang giữ chỗ bao nhiêu đơn vị? | **Không tồn tại như một con số** — giữ chỗ là tập item ACTIVE, đếm được bằng `outbound_item_matches` chứ không đọc từ `reserved_qty` |

Lý do model này đúng với nghiệp vụ Hòa Nam: mặt hàng PRODUCT là serial-tracked, một
phiếu xuất giữ **đúng những item cụ thể** đã quét, không phải "n đơn vị bất kỳ".
Race PostgreSQL đã thực thi (WWHN-198/DEV198-P1-02R) chứng minh chính index trên
`outbound_item_matches` — không phải `reserved_qty` — là thứ chặn double-booking.

## 3. Hệ quả bắt buộc

1. **Không được ghi giá trị khác 0 vào `reserved_qty`** khi chưa có Decision Record thay
   thế bản này. Guard: `tests/Feature/Wwhn198ReservedQtySemanticsTest.php`.
2. **API không được quảng cáo `reserved_qty` như số giữ chỗ.** Nó chỉ đi kèm như raw
   passthrough của hàng balance, kèm ghi chú compatibility.
3. **FE không được hiển thị `reserved_qty` như "Đang giữ chỗ"**, và không được dùng nó
   để tính khả dụng. Cột khả dụng trên UI đọc `available_qty` (Annex D SCR-OUT-01:
   "Refresh at commit; stale warning").
4. **Khả dụng tại thời điểm scan** vẫn là soft-check trên `available_qty` (phản hồi
   sớm); nguồn sự thật cuối cùng là `mutateBalance()` tại Post Issue, có row lock và
   không bao giờ cho tồn âm (xem DEC-OUT-STOCK-HTTP-001: thiếu tồn = 422
   `INSUFFICIENT_STOCK`).

## 3b. Công thức hiện hành (ghi lại đúng như code, không tô vẽ)

Đường ghi nghiệp vụ (`CurrentBaselineStockService::mutateBalance()`) và đường dựng lại
projection (`InventoryProjectionRebuildService`) đều dùng
`available_qty = max(on_hand_qty - reserved_qty, 0)` cho bucket `AVAILABLE` (bucket khác
giữ 0). Vì `reserved_qty` bị ghim ở 0 theo mục 2, công thức này **thoái hoá thành
`available_qty = on_hand_qty`**. Đây là trạng thái có chủ đích, không phải bug: công
thức được giữ nguyên để nếu sau này BA mở scope giữ chỗ theo số lượng thì chỉ cần bật
nguồn ghi `reserved_qty`, không phải viết lại phép tính khả dụng.

Riêng `OperationalReportController` khi xuất báo cáo `as_of` (snapshot quá khứ) trả
`reserved_qty = 0` tường minh, vì giữ chỗ là trạng thái *hiện tại* chứ không tái dựng
được cho một mốc thời gian trong quá khứ.

## 4. Cái KHÔNG quyết định ở đây

Bản này **không** đóng lại khả năng triển khai giữ chỗ theo số lượng trong tương lai
(ví dụ khi có SKU không serial-tracked bán theo lô). Nếu BA mở scope đó, phải:
ra Decision Record mới thay thế bản này, định nghĩa ai tăng/giảm `reserved_qty` và tại
transaction nào, cập nhật ERD + OpenAPI + UI mapping, rồi mới gỡ guard ở mục 3.1.

## 5. Cần ký xác nhận

- **BA Main**: xác nhận ngữ nghĩa mục 2 là quy tắc nghiệp vụ chính thức (giữ chỗ theo
  item, không theo số lượng) và mục 3.3 là ràng buộc hiển thị bắt buộc cho FE.
- **FE1**: xác nhận UI không hiển thị `reserved_qty` và không dùng nó tính khả dụng.
- **QA1**: đưa mục 3 vào checklist hồi quy tồn kho/xuất kho.

## 6. Artifact neo quyết định

| Artifact | Vai trò |
|---|---|
| `docs/api-contract/WWHN-198-reserved-qty-contract.json` | Bản máy đọc được của mục 2-3, dùng cho verifier |
| `tests/Feature/Wwhn198ReservedQtySemanticsTest.php` | Guard runtime: `reserved_qty` = 0 xuyên suốt scan và Post Issue |
| `scripts/verify-wwhn-198-closure.mjs` | Cổng kiểm tra quyết định + contract + evidence còn khớp nhau |
