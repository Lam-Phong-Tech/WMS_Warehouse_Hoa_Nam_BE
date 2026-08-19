# DEC-SCAN-AUDIT-001 — Phạm vi audit của bước quét (scan_event/scan_batch vs audit_log)

- Ticket: **WWHN-187** (đóng action `DEV187-P1-05R` và open point `BE2-INSCAN-OP-01`)
- Canonical repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
- Canonical branch: `m1`
- Trạng thái: `ACCEPTED — chờ BA/Tech Lead sign-off trên Jira`
- Liên quan: [[ADR-AUDIT-001]] (chính sách *ghi* audit), `DEC-SCAN-IDEMP-001` (ngữ nghĩa retry của scan)

## Câu hỏi cần chốt

`WWHN-187` mục 4 và open point `BE2-INSCAN-OP-01` để mở: bước quét
(`POST /inbound-documents/{id}/scan` và `/scans`) **không** gọi
`AuditLogger::record()`. Reviewer yêu cầu BA/Tech quyết định: `scan_event`/`scan_batch`
đã đủ làm bằng chứng, hay phải sinh thêm một dòng `audit_log` cho mỗi lần quét.

## Sự thật đã kiểm chứng trong mã nguồn

`AuditLogger::record()` được gọi cho đúng nhóm hành động **kết thúc / đổi trạng thái**:
`POST_RECEIPT`, `POST_ISSUE`, `CANCEL_STOCK_DOCUMENT`, `CREATE_OUTBOUND`,
`UPDATE_STOCK_DOCUMENT`, `CREATE_RECEIPT_BOX`, `LABEL_DIRECT_PRINT`, `OUTBOUND_UNMATCH`.

Bước quét — **cả inbound lẫn outbound** — không nằm trong nhóm đó. Đây là quy ước nhất
quán toàn hệ thống chứ không phải sót riêng của luồng nhập.

Bằng chứng của bước quét được ghi ở ba nơi, đều có actor và timestamp:

| Nơi ghi | Nội dung | Nguồn |
|---|---|---|
| `inbound_scan_entries` | line, item/BOX, quantity, `scan_source`, `created_by`/`updated_by`, `status` ACTIVE/REMOVED | ERD bảng 28 |
| `scan_batch` / `scan_event` | mọi lần quét, `resolved_object_type`/`resolved_object_id`, batch nguồn | NFR-SRS-004 |
| `stock_documents` + `document_status_history` | chuyển `DRAFT → SCANNING`, version bump từng lần quét | §6 vòng đời chứng từ |

## Quyết định

**Giữ nguyên: `scan_event` + `scan_batch` + `inbound_scan_entries` là bằng chứng chính
thức của bước quét. KHÔNG thêm `audit_log` cho mỗi lần quét.**

Lý do:

1. **Scan là stock-neutral và lặp lại được.** Một phiếu PRODUCT serial-tracked có thể
   quét hàng chục lần trước Post; `audit_log` được thiết kế cho hành động một-lần,
   cuối luồng, có hệ quả tồn kho. Đổ mỗi lần quét vào `audit_log` làm loãng đúng cái
   bảng mà kiểm toán cần đọc, mà không thêm một trường thông tin nào so với
   `scan_event`.
2. **Không mất traceability.** Mọi câu hỏi kiểm toán về bước quét — ai quét, quét mã
   gì, vào dòng nào, lúc nào, đã gỡ chưa — đều trả lời được từ ba bảng trên; hai
   endpoint tra cứu `GET /scan/batches` và `GET /scan/events` đã public sẵn cho
   FE/QA (NFR-SRS-004).
3. **Hành động thật sự đổi tồn vẫn được audit đủ.** `POST_RECEIPT` ghi `audit_log` và
   nối được ngược về từng `inbound_scan_entries`/`scan_event` của phiếu, nên chuỗi
   truy vết không đứt.
4. **Không mở rộng phạm vi ngoài task.** Thêm audit cho scan là thay đổi xuyên suốt
   inbound + outbound + component issue, phải có quyết định riêng ở tầng hệ thống chứ
   không phải hệ quả phụ của một task rà soát luồng nhập.

## Ràng buộc kèm theo quyết định

- Endpoint gỡ mã đã quét (`POST /unscan`) **giữ nguyên** cơ chế hiện tại: chuyển
  `ACTIVE → REMOVED` kèm `removed_by`/`removed_at`, không xoá vật lý. Đây là điều kiện
  để quyết định này còn đúng — nếu sau này entry bị xoá cứng thì phải mở lại
  `DEC-SCAN-AUDIT-001`.
- Mọi thay đổi làm `scan_event` không còn được ghi cho một nhánh quét nào đó (ví dụ
  thêm chế độ quét mới) phải cập nhật quyết định này trước khi merge.
- Quyết định này **không** áp cho các hành động đổi tồn: `Post Receipt`, `Post Issue`,
  `Cancel`, `Reversal`, `Component Issue Post` vẫn bắt buộc `audit_log`.

## Bằng chứng đóng

- Mã: `Modules/Warehouse/app/Services/CurrentBaselineStockService.php`
  (`recordInboundScan()`, `recordScanEvidence()`),
  `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php`
  (`inboundUnscan()`).
- Test: `tests/Feature/Wwhn187InboundScanValidationTest.php::test_scan_writes_scan_event_evidence_row`
  — assert đúng một `scan_event` với `resolved_object_type=ITEM` sau mỗi lần quét hợp lệ.
- Test: `tests/Feature/PostReceiptAuditLogTest.php` — assert `audit_log` vẫn được ghi
  cho hành động đổi tồn.
