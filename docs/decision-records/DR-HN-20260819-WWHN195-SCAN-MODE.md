# DR-HN-20260819-WWHN195-SCAN-MODE — Chế độ quét ở luồng xuất: EXPLICIT, per-event, không khoá

| Trường | Giá trị |
|---|---|
| Decision ID | DR-HN-20260819-WWHN195-SCAN-MODE |
| Register item | DEV195-P1-01R2 |
| Ngày | 19/08/2026 |
| Trạng thái | ĐÃ TRIỂN KHAI — chờ BAS/BA formal sign-off |
| Áp dụng cho | WWHN-45 (task cha) / WWHN-195 / WWHN-197 / endpoint `/outbound-documents/{id}/scan` và `/scans` |
| Authority tham chiếu | SRS/FRD v1.12 REQ-SRS-005, AC-SRS-005, NFR-SRS-021, §5.2, §5.3; DEC-CAMERA-001; DEC-RFID-FALLBACK-001; ERD `HoaNam_ERD_Moi_Nhat.md` bảng 25 `scan_events` |

## 1. Câu hỏi phải chốt

Register R2 ghi: *"mode inferred nhưng parent yêu cầu lock/switch/audit; BAS chưa disposition"*, và yêu cầu chốt bốn điểm: **explicit vs inferred**, **switch/lock**, **audit source**, **permission/state**, rồi map AC → OpenAPI → TC.

## 2. Hiện trạng trước khi sửa (bằng chứng)

1. **API không có field mode.** `scanPayload()` chỉ nhận `line_id`, `quantity`, `item_id`, `code_value`, `container_id`. Chế độ được suy ra gián tiếp: có `code_value` → coi là quét mã, chỉ có `item_id` → coi là nhập tay.

2. **ERD đã có sẵn field, nhưng tầng ứng dụng không ghi được.** ERD bảng 25 khai
   `scan_events.scan_source varchar(20) NOT NULL CHECK CAMERA/MANUAL/HARDWARE`.
   Trên PostgreSQL, giá trị này do trigger `hn_fill_scan_evidence_fields` điền:

   ```sql
   NEW.scan_source := COALESCE(NEW.scan_source,
       CASE WHEN NEW.physical_code_id IS NULL THEN 'MANUAL' ELSE 'HARDWARE' END);
   ```

   Cách đoán này **không bao giờ sinh ra `'CAMERA'`**. Mọi lần quét bằng camera —
   trường hợp chính mà DEC-CAMERA-001 và REQ-SRS-005 nói tới — bị ghi vào audit là
   `HARDWARE`. Đây là dữ liệu audit sai, không chỉ là "thiếu tường minh".

3. **Bảng ghi evidence không lộ cột đó ra tầng ứng dụng.** View compatibility
   `scan_event` (số ít) chỉ select `scan_event_id, scan_batch_id, raw_code,
   physical_code_id, resolved_object_type, resolved_object_id, result_status,
   message, created_at` — không có `scan_source`. Code PHP ghi qua view này nên
   dù muốn cũng không có đường ghi giá trị thật.

4. **Nhánh sqlite (CI) thiếu hẳn cột.** Probe trên sqlite: `scan_event` là bảng vật
   lý, `scan_events` là view, **cả hai đều không có `scan_source`** → không test nào
   trên CI chứng minh được mode đã ghi đúng.

## 3. Quyết định

### 3.1. Explicit vs inferred → **EXPLICIT, có fallback được đánh dấu**

- Payload scan nhận thêm field **optional** `scan_source` với đúng tập giá trị của ERD: `CAMERA | MANUAL | HARDWARE`. Giá trị ngoài tập này → `422`.
- Không gửi thì server vẫn suy luận như cũ (có `code_value` → `HARDWARE`, không có → `MANUAL`) nên **không phá client cũ**.
- Response luôn trả `scan_source` **và** `scan_source_origin ∈ {EXPLICIT, INFERRED}`, để audit và QA phân biệt được "người dùng khai" với "server đoán".

**Lý do không chọn 'giữ nguyên inferred':** suy luận không phân biệt được camera với gõ tay, mà đó chính là phân biệt REQ-SRS-005/DEC-CAMERA-001 và NFR-SRS-021 cần (camera-denied → manual fallback phải đo được).

**Lý do không chọn 'bắt buộc field':** sẽ phá mọi client hiện có và biến một cải thiện audit thành breaking change; SRS không đòi field bắt buộc.

### 3.2. Switch / lock → **KHÔNG khoá. Mode là thuộc tính của từng scan event.**

- Không có state mode trên `stock_documents`, không có endpoint "switch mode".
- Trong cùng một phiếu, lần quét 1 có thể là `CAMERA`, lần 2 là `MANUAL`.

**Căn cứ:** AC-SRS-005 — *"decode điền code_value vào đúng context; denied/unavailable có lỗi rõ + nhập tay"*; §5.3 — *"Permission denied, no camera, unsupported browser hoặc timeout phải có lỗi rõ và manual input dùng cùng server validation"*; NFR-SRS-021 — *"camera-denied/unsupported falls back to manual input"*. Fallback chỉ có nghĩa khi nó xảy ra **giữa luồng**; khoá mode theo phiếu sẽ làm chính yêu cầu này không thực hiện được.

### 3.3. Audit source → **ghi thẳng vào `scan_events.scan_source`**

- Tầng service ghi tường minh giá trị client khai (kèm `context_type` = INBOUND/OUTBOUND và `context_id` = id chứng từ) đè lên giá trị trigger đoán.
- Cách ghi trung lập với driver: thử bảng canonical `scan_events` trước (PostgreSQL), nếu không chạm dòng nào thì ghi `scan_event` (sqlite) — đúng pattern hai driver đảo chiều tên bảng của repo này.
- Migration `2026_08_19_000300_expose_scan_source_on_sqlite_scan_evidence.php` bổ sung `scan_source`/`context_type`/`context_id` cho nhánh sqlite và dựng lại view để CI kiểm chứng được (đóng luôn DEV195-P2-02R2 phần scan evidence).

### 3.4. Permission / state → **giữ nguyên, không tách theo mode**

- Mọi mode dùng chung permission `outbound.scan` và middleware `EnforceOutboundWarehouseScope`.
- Mọi mode dùng chung state guard: chỉ `DRAFT`/`SCANNING`.
- Mọi mode dùng chung validation nghiệp vụ (SKU PRODUCT, item ACTIVE, chống trùng, đủ tồn) — đúng câu *"manual input dùng cùng server validation"* của §5.3.
- Không tạo permission mới: §3 permission matrix của SRS không có permission theo mode, thêm mới sẽ là mở rộng scope ngoài baseline.

## 4. Map AC → OpenAPI → TC

| AC / Requirement | OpenAPI (WWHN-195-outbound-openapi.yaml v2.0.0-R2) | Test case | Automation |
|---|---|---|---|
| AC-SRS-005 (decode điền code_value đúng context) | `OutboundScanRequest.code_value` + `scan_source` | W195-MODE-001 | `Wwhn195OutboundContractAlignmentTest::test_scan_source_is_explicit_and_reaches_audit_evidence` |
| AC-SRS-005 + NFR-SRS-021 (manual fallback giữa luồng) | mô tả `x-state-guard` + `scan_source` không khoá | W195-MODE-004 | `..._test_mode_is_per_scan_event_and_not_locked_to_the_document` |
| ERD bảng 25 CHECK | `enum: [CAMERA, MANUAL, HARDWARE]` | W195-MODE-003 | `..._test_invalid_scan_source_is_rejected_against_the_erd_check_values` |
| Backward compatibility | `scan_source` nullable + `scan_source_origin` | W195-MODE-002 | `..._test_scan_source_falls_back_to_inference_and_is_labelled_as_such` |
| DEC-CAMERA-001 (FE camera vs nhập tay) | — (tầng FE) | W195-MODE-005 | `AlignedOutboundDetailModal`: `CameraScanModal.onDetected → CAMERA`, ô nhập tay → `MANUAL` |
| §8.1 Enforcement standard | `x-permission: outbound.scan`, `x-data-scope` | W195-SRC-003 | `..._test_route_table_matches_the_handler_that_actually_runs` |

## 5. Điểm còn mở (không chặn)

- **Actor gắn/ghi/kiểm tra RFID theo OP-05** vẫn CÒN MỞ theo chính SRS v1.12 (DR-CARRIER-001). Không chặn luồng xuất vì RFID không phải gate Post Issue (DEC-RFID-FALLBACK-001).
- **Inbound** hiện ghi `inbound_scan_entries.scan_source = 'CAMERA_OR_MANUAL_CODE'` — giá trị nằm ngoài tập ERD. Nằm ngoài phạm vi WWHN-195 (thuộc WWHN-187/190); nêu ở đây để BE1 inbound có căn cứ xử lý cùng chuẩn.

## 6. Cần sign-off

BAS + BA xác nhận bốn quyết định ở §3. Sau khi có sign-off, mục "chế độ nhập tay/quét mã cần field tường minh?" trong `docs/testing/WWHN-195-outbound-test-cases.json` chuyển hẳn sang ĐÃ ĐÓNG.
