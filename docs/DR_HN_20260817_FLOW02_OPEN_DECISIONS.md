# DR-HN-20260817 · Luồng 02 Nhập kho — hạng mục còn mở cần BA quyết

Nguồn rà soát: SRS/FRD v1.12, HoaNam_ERD_Moi_Nhat.md (HN-WMS-ERD-DD-001 v1.0),
HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html.

Rà soát docs-vs-code luồng 02 ngày 17/08/2026 đã sửa xong toàn bộ hạng mục thuộc
thẩm quyền kỹ thuật. Hai hạng mục dưới đây **không thể "sửa cho khớp docs"** vì
chính docs chưa định nghĩa hoặc đang tự xung đột — cần Change Decision của BA.

---

## OPEN-FLOW02-001 · Không có operation nào sinh ITEM + Barcode/QR trước khi nhập kho

**Mức độ: chặn nghiệp vụ (P0).** Luồng 02 không chạy được end-to-end với hàng
PRODUCT mới nếu chưa giải quyết.

### Hiện trạng bắt buộc theo docs

- REQ-SRS-003: "ITEM có Physical_Code duy nhất; dùng Barcode/QR khi nhập kho."
- §13.9 bảng lifecycle: state `ACTIVE` — "Barcode scan hợp lệ tại Receipt"; state
  `UNASSIGNED` — action "Generate/Import", nhưng **không gán screen và không gán
  actor** cho action này.
- Annex D SCR-IN-01 chỉ có 3 field: `document_no`, `source_reference`, `scan_input`.
- Wireframe flow 02 chỉ có IN-01, IN-02, IN-03, PTW-00, PTW-01.

### Hệ quả kỹ thuật

Backend bắt buộc phải resolve được một `item_instances` ở trạng thái
`NEW`/`ADJUSTED_OUT` qua một `physical_codes` ACTIVE mới cho ghi nhận scan dòng
PRODUCT / serial-tracked (`CurrentBaselineStockService::resolveItem` +
`assertItemAvailableForReceipt`). Nghĩa là ITEM và mã vật lý **phải tồn tại trước
khi** phiếu nhập được quét.

Nhưng:

1. **Annex C (67 operation) không có** operation nào tạo item-instance. Backend có
   `POST /api/v1/item-instances` và `POST /api/v1/physical-codes` nhưng chúng nằm
   ngoài contract Annex C.
2. **FE không có màn nào** gọi hai endpoint đó cho mục đích nhập kho
   (`API_ENDPOINTS.ITEMS` được khai báo nhưng không nơi nào dùng;
   `PHYSICAL_CODES` chỉ dùng ở outbound để map RFID).
3. Role `WAREHOUSE_STAFF` **không** được cấp `item_instance.manage` lẫn
   `physical_code.assign_rfid`, nên kể cả gọi API trực tiếp cũng 403. Chỉ
   ADMIN / WAREHOUSE_KEEPER / WAREHOUSE_MANAGER làm được.

### Cần BA quyết

| Câu hỏi | Vì sao cần |
|---|---|
| Ai sinh Barcode/QR cho ITEM: NCC in sẵn, hay kho tự in tại cửa nhập? | Quyết định có cần màn "in tem" trong MVP hay không |
| Nếu kho tự sinh: đặt ở màn nào — bước mới trong IN-02, hay màn master riêng? | Cần bổ sung Annex D + wireframe |
| Actor nào được phép: QL kho, hay cả NV kho (A*)? | Cần bổ sung §3 permission matrix + seed role |
| Operation nào vào Annex C và với permission gì? | Cần bổ sung Annex C để hết trạng thái "endpoint ngoài contract" |

**Không tự ý hiện thực** trước khi có quyết định: thêm màn/endpoint ngoài Annex C
sẽ làm API surface phình ra ngoài baseline đã ký.

---

## OPEN-FLOW02-002 · SRS Annex A và ERD xung đột về `Inventory_Movement.movement_type`

**Mức độ: P2 (không chặn chạy, nhưng nhãn hiển thị trên report khác tên trong SRS).**

| Nguồn | Giá trị |
|---|---|
| SRS Annex A (dòng `Inventory_Movement.movement_type`) | `OPENING` / `RECEIPT_POSTED` / `ISSUE_POSTED` / `RECEIPT_REVERSED` / `ISSUE_REVERSED` |
| SRS §13.2 AC-GWT-IN-001, §13.3 TC-P1-001 | dùng `RECEIPT_POSTED` |
| SRS §5.5 BR-REV-06/07, TC-REV-04 | dùng `ISSUE_REVERSED` |
| ERD bảng 30 `inventory_movements.movement_type` | `CHECK OPENING/RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL` |

Code đang theo **ERD** (`RECEIPT` / `ISSUE` / `REVERSAL` / `COMPONENT_ISSUE`) vì đó
là baseline vật lý và được enforce bằng DB CHECK constraint. Lưu ý ERD còn có
`COMPONENT_ISSUE` mà Annex A không liệt kê, và Annex A tách REVERSAL thành hai
nhãn theo chiều đảo mà ERD gộp làm một.

### Cần BA quyết

Chốt **một** bộ nhãn duy nhất. Hai lựa chọn:

- **(A) Giữ ERD làm chuẩn lưu trữ** — cập nhật Annex A + các AC/TC dùng
  `RECEIPT`/`ISSUE`/`REVERSAL`/`COMPONENT_ISSUE`. Rẻ nhất, không đụng schema.
- **(B) Giữ nhãn Annex A cho tầng hiển thị** — bổ sung field dẫn xuất
  `movement_type_label` ở read API/report, giữ nguyên giá trị lưu trữ theo ERD.
  Lưu ý: RPT-MOV-01 đã có contract nhãn được chốt ở Flow 06, nên đổi output
  report phải đi kèm review lại Flow 06.

Chưa sửa code cho hạng mục này để không phá contract report Flow 06 đã ký.

---

## Đã xử lý xong trong cùng lần rà soát (tham chiếu)

| Hạng mục | Nơi sửa |
|---|---|
| Permission Putaway theo SRS §3 `putaway.manage` | `Modules/Warehouse/routes/api.php`; FE `wms-permissions.ts`, `MvpPutawayPage.tsx` |
| ERD bảng 28 gỡ mã quét sai trước Post | `POST /inbound-documents/{id}/unscan` + mọi query lọc `status != REMOVED` |
| §8.2 mã lỗi 412 = `STALE_VERSION` | toàn bộ transactional layer BE |
| Annex D SCR-IN-01 bảng mã đã quét đọc lại được | FE `useInboundScanEntries` + bảng "Mã đã quét của phiếu" |
| Annex D map `scan_input` -> `/scan-batches/classify` | FE `scanInboundDocument` luôn classify khi gửi mã ITEM |
| Annex A `source_reference` max 200 | BE nhận/trả alias, FE dùng đúng tên field |
| Annex D `scan_input` 1..150 + trim | `CurrentBaselineStockFlowController::scanPayload` |
| Annex C `cancelInboundDocument` = `inbound.create` | route nhận `inbound.cancel|inbound.create` |
| §13.6 pagination `page_size`, max 200 | `app/Http/Controllers/Controller::perPage` |
| BR-REV-01 dead code đặt document về `REVERSED` | `StockDocumentService::reverseDocument` fail-closed |
| Test gate cho luồng 02 | `tests/Feature/Flow02InboundPutawayAlignmentTest.php`; FE `InboundScanModal.test.tsx`, `MvpPutawayPage.test.tsx` |
