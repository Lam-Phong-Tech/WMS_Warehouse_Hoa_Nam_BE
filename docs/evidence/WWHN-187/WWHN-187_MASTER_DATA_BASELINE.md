# WWHN-187 — Baseline master data cho luồng quét nhập (`DEV187-P1-04R`, thay `HN-MC-001`)

## 1. Vấn đề

`WWHN-187` khai dependency `HN-MC-001` (Master data / catalog). Tài liệu bàn giao gốc của
`HN-MC-001` **không tồn tại trong repo** — điều này đã được ghi nhận lặp lại ở
`WWHN-184`, `WWHN-195`, `WWHN-224` và open point `BE2-INSCAN-OP-03`. Ghi chú governance
kèm theo còn nói "v3.11 controls", tức là bám vào một revision đã bị thay thế (xem
`WWHN-187_SOURCE_TRACEABILITY_SUPERSESSION.md`). Task cha `WWHN-14` chưa DONE và QA2
đang ở trạng thái *changes required*, nên không có baseline đã duyệt để trích.

Hệ quả nếu để nguyên: mọi kết luận về SKU / ITEM / COMPONENT / Physical_Code / tracking
mode trong luồng quét đều không có neo, và bất kỳ ai cũng có thể diễn giải khác.

## 2. Disposition

**Không chờ `HN-MC-001`.** Baseline master data cho *riêng phạm vi quét nhập* được pin
trực tiếp vào các nguồn điều khiển đã tồn tại và đã được version hoá — SRS/FRD v1.12 và
ERD hiện hành. Đây là baseline **thay thế có phạm vi giới hạn**: chỉ ràng buộc những gì
bước quét nhập thật sự đọc/ghi, không thay thế toàn bộ `HN-MC-001` cho các luồng khác.

Nguồn và hash (đóng băng tại thời điểm lập hồ sơ):

| Nguồn | Đường dẫn | SHA-256 |
|---|---|---|
| SRS/FRD v1.12 | `docs/HN_SRS_FRD_v1.12_FULL.md` (repo FE) | `02cd010cdc40036b62ec23a0d3aa645e4cce813497edf8b0e851ba6fcb512ff2` |
| Wireframe MVP v3.20 ↔ v1.12 | `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` (repo FE) | `107a2b4de0f5763e39278ec130758d57e772e004e525416c088018cf9ddba658` |
| ERD hiện hành | `docs/HoaNam_ERD_Moi_Nhat.md` (repo FE) | `5209358cb0e9d91ced71b3b2ebaeb6e50d7f4304c03618a81a6a6a0adabf12e2` |
| Techstack / Architecture Dev Guide | `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` (repo FE) | `049818cae2d18af0d06d40f5d40098a8cf11279fde7d38cafb256a27cc0812ea` |

Ngày hiệu lực của baseline thay thế: **2026-08-19**, hiệu lực **tới khi `HN-MC-001` có
tài liệu bàn giao chính thức**. Khi tài liệu đó xuất hiện, việc phải làm là *đối chiếu*
ma trận ở mục 3 chứ không viết lại từ đầu — mỗi dòng đã trỏ sẵn tới nơi thi hành và nơi
kiểm chứng.

## 3. Ma trận rule → runtime → test

Mỗi dòng: quy tắc nghiệp vụ (nguồn) → chỗ mã thi hành → mã lỗi trả về → test chứng minh.
Toàn bộ test đều lái qua HTTP thật.

| # | Quy tắc (nguồn) | Nơi thi hành | Mã lỗi | Test |
|---|---|---|---|---|
| 1 | SKU phải `ACTIVE` mới được nhận hàng (`REQ-SRS-002`) | `CurrentBaselineStockService::scanStockDocument()` | 422 `SKU_NOT_ACTIVE` | `Wwhn187…::test_scan_rejects_inactive_sku` |
| 2 | SKU `PRODUCT` hoặc `is_serial_tracked` bắt buộc quét từng ITEM (`REQ-SRS-002`; SRS §"Serial-tracked components are scanned individually") | `scanStockDocument()` | 422 `ITEM_REQUIRED` | `…::test_scan_product_line_without_item_is_rejected` |
| 3 | Quét một ITEM cụ thể thì `quantity` phải = 1 (`REQ-SRS-002`) | `scanStockDocument()` | 422 `SERIAL_QUANTITY_MUST_BE_ONE` | `…::test_scan_serial_tracked_line_rejects_quantity_greater_than_one` |
| 4 | ITEM phải thuộc đúng SKU của dòng (`REQ-SRS-002`) | `CurrentBaselineStockService::resolveItem()` | 422 `ITEM_SKU_MISMATCH` | `…::test_scan_rejects_item_belonging_to_different_sku` |
| 5 | ITEM chỉ nhận được khi ở `NEW`/`ADJUSTED_OUT` (vòng đời ITEM, ERD bảng ITEM) | `assertItemAvailableForReceipt()` | 422 `ITEM_NOT_RECEIVABLE` | `…::test_scan_rejects_item_not_receivable_when_already_in_stock` |
| 6 | Một ITEM không thuộc hai phiếu nhập đang mở (`REQ-SRS-003`) | `assertItemAvailableForReceipt()` | 409 `ITEM_ALREADY_RECEIVING` | `…::test_scanning_same_item_into_two_different_inbound_documents_is_rejected` |
| 7 | Một ITEM chỉ quét một lần trong cùng phiếu (`REQ-SRS-003`) | `CurrentBaselineStockFlowController::assertNoDuplicateItemInDocument()` | 409 `DUPLICATE_ITEM_IN_DOCUMENT` | `…::test_scanning_same_item_twice_into_same_inbound_document_is_rejected` |
| 8 | ITEM có Physical_Code duy nhất; Barcode/QR là định danh hợp lệ khi nhập (`REQ-SRS-003`, `DR-CARRIER-001`) | `resolveItemId()` + `resolveItem()` | 422 `PHYSICAL_CODE_NOT_FOUND` | `…::test_scan_with_unknown_code_value_is_rejected` |
| 9 | Physical_Code phải `ACTIVE` (`REQ-SRS-003`) | `resolveItemId()` | 422 `PHYSICAL_CODE_NOT_FOUND` | `…::test_scan_with_inactive_code_value_is_rejected` |
| 10 | `item_id` và `code_value` gửi cùng lúc phải trỏ cùng một ITEM (`REQ-SRS-003`) | `resolveItem()` | 422 `PHYSICAL_CODE_ITEM_MISMATCH` | `…::test_scan_rejects_mismatched_item_id_and_code_value` |
| 11 | Physical_Code trôi nổi (chưa gắn ITEM) không quét được (`REQ-SRS-003`) | `resolveItem()` | 422 `PHYSICAL_CODE_UNASSIGNED` | *(dùng chung 100% code path với outbound; xem mục 4)* |
| 12 | COMPONENT quantity-mode dùng **BOX + quantity** (SRS §"quantity-only components use box + quantity") | middleware `EnsureInboundComponentBox` | 422 `COMPONENT_BOX_REQUIRED` | `…::test_scan_rejects_component_line_without_box` |
| 13 | `container_id` phải tồn tại và đúng loại `BOX` (`REQ-SRS-004`, `BR-CON-*`) | `EnsureInboundComponentBox` | 422 `COMPONENT_BOX_NOT_FOUND` / `COMPONENT_BOX_REQUIRED` | `…::test_scan_rejects_unknown_box_container_id` |
| 14 | Một BOX chỉ chứa đúng một SKU COMPONENT đã đăng ký (`REQ-SRS-004`) | `EnsureInboundComponentBox`, `CurrentBaselineInboundBoxController::assertBoxReusable()` | 422 `BOX_SKU_MISMATCH` | `…::test_scan_rejects_box_with_mismatched_sku` |
| 15 | BOX phải `EMPTY` và chưa có `parent_container_id` mới nhận hàng được (`REQ-SRS-004`) | `EnsureInboundComponentBox` | 409 `BOX_NOT_RECEIVABLE` | `…::test_scan_rejects_box_not_in_receivable_state` |
| 16 | Một BOX chỉ thuộc một phiếu nhập chưa kết thúc (`REQ-SRS-004`) | `EnsureInboundComponentBox` | 409 `BOX_IN_OTHER_ACTIVE_RECEIPT` | `…::test_scan_rejects_box_already_used_by_other_active_receipt` |
| 17 | Không quét vượt Expected của dòng (`REQ-SRS-006/007`) | `scanStockDocument()` | 422 `SCAN_EXCEEDS_REQUIRED` | `…::test_scan_rejects_quantity_exceeding_line_planned_qty` |
| 18 | Quét bằng camera/manual đều hợp lệ, phân biệt được nguồn (`REQ-SRS-005`, `DEC-CAMERA-001`) | `recordInboundScan()` → `scan_source` `CAMERA_OR_MANUAL_CODE` \| `MANUAL` | — | `…::test_scan_code_value_resolves_item_and_records_scan_entry` |
| 19 | Mọi lần quét để lại bằng chứng tra cứu được (`NFR-SRS-004`) | `recordScanEvidence()` → `scan_batch`/`scan_event` | — | `…::test_scan_writes_scan_event_evidence_row` |
| 20 | Quét là stock-neutral; chỉ Post mới đổi tồn (`REQ-SRS-007/008`, `NFR-SRS-001`) | `scanStockDocument()` trả `stock_effect=NONE`, `movement_created=false` | — | `…::test_scan_document_transitions_draft_to_scanning_and_bumps_version`, `FoundationInboundPutawayReadinessTest` |
| 21 | Hệ thống **không** lưu trạng thái đầy/vơi của CARTON/BOX (SRS §Banned obsolete keyword: *"no persisted Carton/Box fullness state"*) | Không có cột fullness ở `containers`; BOX chỉ có `status` vòng đời | — | `Wwhn188ScanIdempotencyContractTest::test_different_key_same_payload_is_a_genuine_second_scan` (hai đợt quét vào cùng BOX vẫn hợp lệ) |
| 22 | Sửa mã quét sai trước Post bằng gỡ entry, không xoá cứng (ERD bảng 28 `status` ACTIVE/REMOVED) | `CurrentBaselineStockFlowController::inboundUnscan()` | 409 `SCAN_ENTRY_NOT_ACTIVE` | `Wwhn187…` + `InboundScanModal.test.tsx` (FE) |

## 4. Khoảng trống còn lại, có chủ đích

- **Dòng 11 (`PHYSICAL_CODE_UNASSIGNED`)** chưa có test HTTP riêng cho nhánh *inbound*.
  Nhánh này đòi một Physical_Code `ACTIVE`, `assigned_object_type != 'ITEM'`, **và**
  không có `item_instances.physical_code_id` nào trỏ tới — tức một mã hoàn toàn trôi
  nổi. `resolveItem()` không rẽ nhánh theo `documentType`, nên inbound và outbound dùng
  chung 100% code path; rủi ro thấp. Ghi nhận là `BE2-INSCAN-OP-02`, không chặn release.
- Baseline này **không** quyết định gì cho các luồng ngoài quét nhập (import SKU, App PV,
  catalog công khai…). Những luồng đó vẫn chờ `HN-MC-001` hoặc đã có disposition riêng.

## 5. Cái BA/BAS cần duyệt

Đúng một câu hỏi: **chấp nhận baseline thay thế ở mục 2 + ma trận ở mục 3 làm neo cho
`HN-MC-001` trong phạm vi quét nhập, cho tới khi `WWHN-14` bàn giao tài liệu gốc?**
Nếu BA có baseline khác cho bất kỳ dòng nào ở mục 3, chỉ cần chỉ đúng số dòng — mỗi dòng
đã trỏ sẵn nơi thi hành và nơi kiểm chứng nên sửa được cục bộ.
