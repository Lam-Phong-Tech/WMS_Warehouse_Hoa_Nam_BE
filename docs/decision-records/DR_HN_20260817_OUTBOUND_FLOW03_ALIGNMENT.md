# DR-HN-20260817 — Flow 03 (Xuất kho) alignment tận gốc theo SRS/FRD v1.12

- Decision date: 2026-08-17
- Sources: HN_SRS_FRD_v1.12_FULL.md (§5.2, §6, §8.1, §8.2, §13.2 AC-GWT-OUTBOUND-REQUEST-001,
  §15, Annex C, Annex D SCR-OUT-01/SCR-REQ-OUT-01/SCR-LBL-01),
  HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html (OUT-REQ-01, OUT-01, OUT-02, OUT-03,
  LBL-01, RFID-01), HoaNam_ERD_Moi_Nhat.md (bảng 35–43), Techstack Guide.
- Status: ADOPTED. Kế thừa và không thay đổi DR-HN-20260815 (01 Packing Label/lô, RFID optional).

## 1. Quyết định thực hiện

| # | Nội dung | Nguồn |
|---|---|---|
| 1 | Tiếp nhận toa lưu **SĐT người nhận** và **ngày toa**; cả hai nullable, thiếu thì nhãn render blank và ngày toa fallback ngày Confirm. | §5.2, Annex D SCR-OUT-01, wireframe OUT-REQ-01 |
| 2 | `ConfirmRequest` nhận `lines[]` (map row → SKU + required_qty) và override người nhận (`receiver_name/address/contact_phone`, `request_date`); Confirm re-validate rồi mới tạo toa + phiếu DRAFT. | §8.1 Outbound request row, SCR-REQ-OUT-01 "line mapping" |
| 3 | Confirm yêu cầu **Idempotency-Key + If-Match** (version của import). | Annex C `confirmOutboundRequestImport` |
| 4 | Một `request_no` chỉ một người nhận/một ngày toa; trùng `request_no` bị chặn 409 `DUPLICATE_OUTBOUND_REQUEST_NO` ngay ở preview lẫn Confirm. | §5.2, AC-GWT-OUTBOUND-REQUEST-001 |
| 5 | **Full match tự động tạo/đảm bảo 01 Packing Label `DRAFT`** thay vì chờ thao tác tay. | §5.2, §15, AC-GWT-OUTBOUND-REQUEST-001 |
| 6 | Lifecycle nhãn enforced: `DRAFT → PREVIEWED → READY_TO_PRINT → PRINTED` (`PRINT_FAILED` khi in lỗi, retry dùng lại snapshot). Print chỉ từ `READY_TO_PRINT`/`PRINT_FAILED`; Reprint chỉ từ `PRINTED`/`PRINT_FAILED` và được phép sau completion. | §6 State Model, SCR-LBL-01 |
| 7 | Preview yêu cầu If-Match; Ready/Print/Reprint yêu cầu **Idempotency-Key + If-Match**, trả `ETag` version nhãn. | Annex C `previewPackingLabel`/`readyPackingLabel`/`printPackingLabel`/`reprintPackingLabel` |
| 8 | Sửa match sau khi nhãn đã sinh: nhãn chưa đóng băng (`DRAFT`/`PREVIEWED`) bị huỷ có audit rồi tạo lại; từ `READY_TO_PRINT` trở đi chặn 409. | §5.2 "Ready freezes immutable snapshot" |
| 9 | Gỡ `POST /api/v1/outbound-documents` và `PATCH /api/v1/outbound-documents/{id}`. | §8.1 "no direct POST /outbound-documents create operation"; Annex C không khai 2 operation này |
| 10 | Gắn data-scope middleware `EnforceOutboundWarehouseScope` (403 `DATA_SCOPE_DENIED`) cho toàn bộ route phiếu xuất, kể cả route bị `Flow06AlignmentServiceProvider` override. | §8.1 Enforcement standard, §8.2 |
| 11 | FE dùng đúng path Annex C: `/outbound-documents/{id}/scans`, `/outbound-request-imports/{id}/confirm`, `/outbound-documents/{id}/packing-labels/{label_id}/{preview,ready,print,reprint}`, `PUT /outbound-documents/{id}/items/{item_id}/rfid`. | Annex C |

## 2. Amendment tài liệu cần BA cập nhật ở revision kế tiếp

Code theo SRS §15 "Data Dictionary precedence" (SRS thắng ERD khi mâu thuẫn). Các điểm ERD
cần sửa để khớp trạng thái đã triển khai:

1. **Bảng 37 `outbound_requests`**: thêm `customer_phone varchar(50) NULL` (SĐT người nhận theo toa).
2. **Bảng 39 `outbound_documents`**: thêm `recipient_contact_phone varchar(50) NULL`;
   `recipient_address` phải là NULL-able (đã amend từ 2026-08-16 theo §15).
3. **Bảng 42 `label_snapshots`**: CHECK `status` phải là
   `DRAFT/PREVIEWED/READY_TO_PRINT/PRINTED/PRINT_FAILED` theo §6 (hiện ERD chỉ ghi
   `PENDING_PRINT/PRINTED/PRINT_FAILED`). `PENDING_PRINT` giữ lại chỉ để đọc dữ liệu cũ.
4. **Annex C**: bổ sung 2 operation đang cần cho gate §5.2 nhưng chưa được khai —
   tạo Packing Label (`POST /label-snapshots`) và xác nhận đã dán nhãn
   (`POST /label-snapshots/{id}/apply`) — hoặc chuyển `label_applied_confirmed` vào
   `OutboundPostRequest`.
5. **AC-GWT-OUT-001**: câu "POST post-issue với 5 item_codes" mâu thuẫn §8.1 "persisted Item
   match"; hiện thực theo §8.1 (item đã match được lưu trước ở bước scan, body Post rỗng).

## 3. Không đổi

- Post Issue vẫn là hành động duy nhất trừ tồn; gate = full match + đủ tồn + 01 nhãn PRINTED
  + snapshot current (content_hash) + xác nhận đã dán.
- RFID optional, stock-neutral, không nằm trong gate; Barcode/QR là fallback hợp lệ.
- Scan/match, tổng hợp lô, preview/ready/print/reprint, map RFID: stock effect = 0.
