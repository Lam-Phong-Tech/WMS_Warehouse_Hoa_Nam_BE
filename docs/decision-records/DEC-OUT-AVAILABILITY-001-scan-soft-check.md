# DEC-OUT-AVAILABILITY-001 — Nguồn "khả dụng" cho FE khi lấy hàng/quét mã

- Ticket: WWHN-198 (register DEV198-P1-03R); đóng open point BE2-PICK-OP-01
- Ngày: 2026-08-19
- Owner đề xuất: DEV (BE2) → **BA Main chốt**
- Status: ADOPTED (chờ BA Main ký — mục 5)
- Sources: HN_SRS_FRD_v1.12_FULL.md Annex D `SCR-OUT-01`, REQ-SRS-011, §8.1;
  wireframe `HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12` OUT-REQ-01/OUT-01;
  DEC-OUT-STOCK-HTTP-001; DR-HN-20260817.

## 1. Câu hỏi cần chốt

Register hỏi: FE có cần một **standalone availability API** (kiểu
`GET /outbound-documents/{id}/availability`) hay **reactive scan soft-check** mới là
controlling rule?

## 2. Quyết định

**Không xây endpoint availability mới. Controlling rule là hai tầng đã có:**

| Tầng | Nguồn | Vai trò |
|---|---|---|
| Hiển thị (FE đọc trước/trong khi quét) | `GET /api/v1/inventory/balances` → `available_qty` | Cột "Khả dụng" của OUT-REQ-01/SCR-OUT-01. Có sẵn filter `warehouse_id`, `sku_id`/`product_id`, `stock_status` |
| Soft-check tại scan | `assertIssueAvailability()` đọc `available_qty` (không lock) | Phản hồi sớm 422 `INSUFFICIENT_STOCK` khi rõ ràng thiếu hàng |
| Hard-check tại Post Issue | `mutateBalance()` có row lock | **Nguồn sự thật cuối cùng**; không bao giờ cho tồn âm |

Căn cứ chốt như vậy, không phải chọn cho tiện:

1. **SRS không yêu cầu endpoint riêng.** Annex D `SCR-OUT-01` khai trường `available_qty`
   là *Number/read-only*, data source ghi thẳng là `Inventory_Balance.available_qty`, quy
   tắc "Refresh at commit; stale warning". Đó là mô tả một **cột đọc từ read model tồn
   kho**, không phải một operation mới. Annex C (67 operation) cũng không khai operation
   availability nào.
2. **FE đã tiêu thụ đúng nguồn đó rồi.** `mvpOutboundService.fetchAvailableQtyBySku()`
   gọi `INVENTORY_BALANCES` với `product_id` và cộng `available_qty` — chính là cột "Khả
   dụng" của OUT-REQ-01. Thêm endpoint mới sẽ tạo **nguồn thứ hai cho cùng một con số**,
   đúng loại mâu thuẫn mà register đang phạt ở các hạng mục khác.
3. **Ghi chú "không có filter warehouse_id" ở rà soát vòng 1 là SAI và được sửa tại đây.**
   `InventoryBalanceController` có validate + áp dụng `warehouse_id`, `sku_id`/`product_id`,
   `sku_type`, `stock_status` (khai đủ trong OpenAPI annotation). Không có gap.
4. **"Stale warning" là đúng thiết kế, không phải lỗ hổng.** Con số hiển thị luôn có thể
   cũ trong môi trường nhiều người quét; vì thế SRS mới bắt "refresh at commit". Một
   endpoint pre-check riêng cũng **không** làm con số bớt cũ — chỉ Post Issue có lock mới
   quyết định được. Đây là lý do soft-check không được nâng thành gate.

## 3. Hệ quả bắt buộc

1. FE **không** chặn thao tác quét dựa trên con số khả dụng hiển thị; con số đó chỉ để
   cảnh báo. Việc chặn thuộc về BE (422 `INSUFFICIENT_STOCK`).
2. FE hiển thị `available_qty`, **không** tự tính từ `on_hand_qty - reserved_qty`
   (xem DEC-INV-RESERVED-001).
3. Nếu sau này BA thật sự muốn một pre-check API riêng, đó là **feature mới cần task
   riêng**, không phải gap của WWHN-198/WWHN-46 — và phải trả lời được nó thêm gì so với
   `GET /inventory/balances` đã có.

## 4. Ảnh hưởng contract

Không đổi shape endpoint nào. Bản ghi này chỉ chốt **nguồn có thẩm quyền**; OpenAPI
`WWHN-195` v1.2.0 và annotation của `GET /inventory/balances` đã mô tả đúng.

## 5. Cần ký xác nhận

- **BA Main**: xác nhận mục 2 (không cần standalone availability API; reactive
  soft-check + hard-check tại Post là controlling rule) và mục 3 là ràng buộc cho FE/QA.
- **FE1/QA**: xác nhận không có contract impact và checklist hồi quy dùng đúng nguồn.
