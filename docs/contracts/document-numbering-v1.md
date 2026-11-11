# Đặc tả Hợp đồng Cấp mã Chứng từ & Hồ sơ Bảo hành Thống nhất (`document-numbering-v1`)

- **Contract ID**: `document-numbering-v1`
- **Tích hợp mở rộng**:
  - Nhập kho (P04): `mini-app-p04-v1` / `p04-ui-options-v1`
  - Xuất kho (P05): `mini-app-p05-v1` / `p05-save-numbering-v1` (`revision`: `p05-save-numbering-r1`)
  - Bảo hành (P09): `mini-app-p09-v1`
- **Múi giờ cấp mã chuẩn**: `Asia/Ho_Chi_Minh` (`UTC+07:00`)
- **Nguồn cấp mã duy nhất**: Backend (BE). App/FE tuyệt đối không tự sinh, không đoán và không nối hậu tố vào mã.

---

## 1. Quy ước Định dạng & Bất biến (Invariants)

| Nghiệp vụ | Tiền tố & Định dạng | Regex chuẩn | Ví dụ | Bảng lưu mã chính thức | Bảng giữ mã nháp / Bộ đếm |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Nhập kho** | `PN-YYYYMMDDHHmmss` | `^PN-[0-9]{14}$` | `PN-20261111140530` | `stock_documents.doc_no` | `inbound_draft_numbers`, `inbound_daily_numbers` |
| **Xuất kho** | `PX-YYYYMMDDHHmmss` | `^PX-[0-9]{14}$` | `PX-20261111140530` | `stock_documents.doc_no` | `outbound_draft_numbers`, `outbound_daily_numbers` |
| **Bảo hành** | `BH-YYYYMMDDHHmmss` | `^BH-[0-9]{14}$` | `BH-20261111140530` | `warranty_case.warranty_case_code` | `warranty_daily_numbers` |

### Bất biến bắt buộc
1. **Không thêm hậu tố ngẫu nhiên hoặc STT phụ**: Mã luôn đúng 17 ký tự (`{PN|PX|BH}-` + 14 chữ số `YYYYMMDDHHmmss`).
2. **Không đổi UUID**: Định danh kỹ thuật (`id`, `document_id`, `draft_id`, `warranty_case_id`, `request_id`) giữ nguyên là UUID v4 độc lập với mã nghiệp vụ.
3. **Bảo toàn lịch sử & Tương thích ngược**: Không backfill, không seed đè hoặc sửa mã chứng từ/hồ sơ lịch sử (`PN-YYYYMMDD-NNNN`, `PX-YYYYMMDD-NNNN`, `WC-*`, `PN-SEED-*`, `PX-SEED-*`, `WC-SEED-*`). Mọi API danh sách, chi tiết và tìm kiếm tiếp tục đọc/tìm kiếm bình thường cả mã cũ lẫn mã mới.
4. **Không tái sử dụng mã đã hủy**: Khi nháp (`CANCEL`) hoặc phiếu bị hủy, mã đã cấp được giữ nguyên vĩnh viễn trong bảng reservation/chứng từ, không bao giờ cấp lại cho phiếu khác (`RETAIN_NEVER_REUSE`).

---

## 2. Thời điểm Cấp mã Chính xác theo Từng Luồng

### 2.1. Nhập kho (`PN-YYYYMMDDHHmmss`)
- **Luồng Mini App có nháp (P04 Staging Draft)**:
  - **Thời điểm cấp mã**: Ngay tại lần **`SAVE` nháp đầu tiên thành công** (`PUT /api/v1/mini-app/inbound/drafts/{draftId}`).
  - **Ổn định qua vòng đời**:
    - Các lần `SAVE` cập nhật nháp tiếp theo (`If-Match` tăng version), mở lại nháp (`GET /api/v1/mini-app/inbound/drafts/{draftId}`), đồng bộ nhiều thiết bị hoặc replay cùng `Idempotency-Key` đều trả về **cùng một mã `PN-YYYYMMDDHHmmss`** đã giữ trong `inbound_draft_numbers`.
    - Khi `RECORD` (`POST /api/v1/mini-app/inbound/drafts/{draftId}/record`), BE chuyển nguyên vẹn mã `PN-YYYYMMDDHHmmss` từ `inbound_draft_numbers` sang `stock_documents.doc_no`.
- **Luồng tạo trực tiếp không qua nháp SAVE (Web `POST /api/v1/inbound-documents`, `POST /api/v1/receipts`, hoặc Mini App direct record không đính kèm nháp đã giữ số)**:
  - **Thời điểm cấp mã**: Ngay trong transaction **tạo chứng từ (`RECORD` / `CREATE`)** qua `InboundDocumentNumber::next()`.

### 2.2. Xuất kho (`PX-YYYYMMDDHHmmss`)
- **Luồng Mini App tạo phiếu xuất thủ công có nháp (P05 Opt-in Numbered Draft)**:
  - **Thời điểm cấp mã**: Ngay tại lần **`SAVE` nháp đầu tiên thành công** (`PUT /api/v1/mini-app/drafts/{draftId}` với `operation=OUTBOUND` và header `X-Outbound-Numbering-Contract: p05-save-numbering-v1`).
  - **Ổn định qua vòng đời**:
    - Giữ cố định trong `outbound_draft_numbers` qua mọi lần `SAVE` kế tiếp, `GET` chi tiết/danh sách nháp, đổi ngày qua nửa đêm Việt Nam, đồng bộ thiết bị và replay.
    - Khi `RECORD` (`POST /api/v1/mini-app/outbound/record` kèm `X-Draft-ID`), BE kiểm tra quyền sở hữu nháp (`assertForRecord`) và ghi đúng mã `PX-YYYYMMDDHHmmss` đó vào `stock_documents.doc_no`.
- **Luồng Xuất kho từ Toa/Nguồn Web đã có sẵn (`source_linked`)**:
  - Giữ nguyên `doc_no` của chứng từ nguồn (`KEEP_SOURCE_DOCUMENT_NO`), không sinh mã mới.
- **Luồng tạo trực tiếp hồ sơ xuất (Web Confirm Import `POST /api/v1/outbound-request-imports/{id}/confirm`, Consume Import `POST /api/v1/outbound-request-imports/{id}/consume`, Mini App direct `POST /api/v1/mini-app/outbound-documents` hoặc `POST /api/v1/mini-app/outbound/record` không qua nháp P05)**:
  - **Thời điểm cấp mã**: Ngay trong transaction **tạo chứng từ xuất** qua `OutboundDocumentNumber::next()`.

### 2.3. Bảo hành (`BH-YYYYMMDDHHmmss`)
- **Không có bước nháp SAVE trên BE**: Bảo hành cấp mã trực tiếp tại thời điểm **tạo hồ sơ bảo hành (`CREATE`)**:
  - Mini App: `POST /api/v1/mini-app/warranty-cases`
  - Web/Baseline: `POST /api/v1/warranty-cases` và `POST /api/v1/operations/warranty-cases`
- **Ổn định qua vòng đời**:
  - Mã `BH-YYYYMMDDHHmmss` được lưu ngay vào `warranty_case.warranty_case_code` trong cùng DB transaction tạo hồ sơ và giữ nguyên qua toàn bộ vòng đời (`RECEIVED` -> `CHECKING` -> `REPAIRING` -> `COMPLETED` -> `RETURNED` / `CANCELLED`), mở lại trên nhiều thiết bị, idempotent replay và đối chiếu receipt.

---

## 3. Cơ chế Chống trùng Cùng Giây, Đa Worker/Container & Idempotent Replay

### 3.1. Khóa dòng mức Database (`SELECT ... FOR UPDATE`) & Tiến lùi Đơn điệu theo Giây
Khi hai hoặc nhiều yêu cầu cùng loại (`PN`, `PX`, hoặc `BH`) đến trong **cùng một giây** trên nhiều PHP-FPM worker / container:
1. Mỗi dịch vụ (`InboundDocumentNumber`, `OutboundDocumentNumber`, `WarrantyCaseNumber`) thực thi trong `DB::transaction(..., 3)` và khóa dòng ngày hiện tại (`number_date = YYYY-MM-DD` theo `Asia/Ho_Chi_Minh`) bằng `SELECT ... FOR UPDATE` trên bảng bộ đếm tương ứng (`inbound_daily_numbers`, `outbound_daily_numbers`, `warranty_daily_numbers`).
2. `last_value` (kiểu `BIGINT`) lưu mốc giây 14 chữ số `YYYYMMDDHHmmss` lớn nhất đã cấp.
3. Thời điểm ứng viên khởi tạo là `$candidate = now('Asia/Ho_Chi_Minh')->startOfSecond()`:
   - Nếu `$lastCarbon >= $candidate`, BE dịch tiến `$candidate = $lastCarbon->copy()->addSecond()` (+1 giây theo lịch thực `Carbon` tại `Asia/Ho_Chi_Minh`).
   - BE kiểm tra thêm tập mã `PREFIX-YYYYMMDD*` hiện có trong bảng chính (`stock_documents` / `warranty_case`) và bảng giữ số nháp (`inbound_draft_numbers` / `outbound_draft_numbers`); nếu đã tồn tại mã có mốc giây `>= $candidate` thì dịch tiến tới `max + 1 second` cho đến khi không trùng.
   - Nếu việc cộng giây vượt qua `23:59:59` sang ngày kế tiếp (`00:00:00`), `Carbon::addSecond()` tự động chuyển sang ngày lịch hợp lệ kế tiếp (`YYYYMMDD000000`) và BE khóa/cập nhật đồng thời dòng ngày mới trong bảng `*_daily_numbers`.
4. **Không khóa nghẽn bằng `sleep()`**, không sinh hậu tố, không bao giờ trả trùng mã và nếu transaction nghiệp vụ lỗi thì cả bộ đếm lẫn reservation đều rollback nguyên tử.

### 3.2. Chống tạo trùng khi Retry cùng `Idempotency-Key` hoặc cùng `draft_id`
- **Theo `draft_id` (P04 / P05 SAVE & RECORD)**:
  - `InboundDocumentNumber::reserve($draftId)` và `OutboundDocumentNumber::reserve($draftId)` khóa dòng `mini_app_drafts` (`lockForUpdate()`) và kiểm tra `forDraft($draftId)` trước khi gọi `next()`. Nếu nháp đã có mã, BE trả lại ngay mã cũ mà không tăng bộ đếm.
- **Theo `Idempotency-Key` / `request_id`**:
  - P04 / P05 lưu durable receipt trong `mini_app_draft_receipts`; P09 lưu durable receipt trong `p09_warranty_requests` (khóa duy nhất theo `(scope_hash, idempotency_key)` và `(scope_hash, request_id)`).
  - Mọi retry hợp lệ cùng `Idempotency-Key` trả về nguyên vẹn response gốc kèm header `Idempotent-Replay: true` và giữ nguyên mã đã cấp.

---

## 4. Trường Response, Trạng thái Cấp mã, Mã lỗi Xung đột & API Đối chiếu khi Timeout/`UNKNOWN`

### 4.1. Trường Response & Trạng thái Cấp mã

| Luồng API | Endpoint | Trường chứa mã cấp | Trạng thái cấp mã / Receipt |
| :--- | :--- | :--- | :--- |
| **P04 Context** | `GET /api/v1/mini-app/inbound/context` | `data.document_number_format = "PN-YYYYMMDDHHmmss"`<br>`data.document_number_timezone = "Asia/Ho_Chi_Minh"` | `data.document_number_assignment = "WITH_DRAFT_SAVE"` |
| **P04 Nháp (SAVE / GET)** | `PUT\|GET /api/v1/mini-app/inbound/drafts/{draftId}` | `data.document.document_no` | `data.receipt.outcome = "SUCCEEDED"` |
| **P04 Ghi nhận (RECORD)** | `POST /api/v1/mini-app/inbound/drafts/{draftId}/record` | `data.doc_no` | `data.status = "SCANNING"`, `data.stock_effect = "NONE"` |
| **P05 Context** | `GET /api/v1/mini-app/outbound/context` | `data.numbering.format = "PX-YYYYMMDDHHmmss"`<br>`data.numbering.timezone = "Asia/Ho_Chi_Minh"` | `data.document_number_assignment = "WITH_DRAFT_SAVE"` |
| **P05 Nháp (SAVE / GET / List)** | `PUT /api/v1/mini-app/drafts/{draftId}`<br>`GET /api/v1/mini-app/drafts/{draftId}`<br>`GET /api/v1/mini-app/drafts` | SAVE: `data.document.document_no`<br>Detail: `data.document_no`<br>List: `data[].document_no` | `numbering_status = "ASSIGNED"` (hoặc `"AWAITING_EXPLICIT_SAVE"` với nháp cũ chưa opt-in) |
| **P05 Ghi nhận (RECORD)** | `POST /api/v1/mini-app/outbound/record` | `data.doc_no` | `data.status = "DRAFT"`, `data.stock_effect = "NONE"` |
| **P09 Tạo / Cập nhật Bảo hành** | `POST /api/v1/mini-app/warranty-cases`<br>`PATCH /api/v1/mini-app/warranty-cases/{id}`<br>`POST /api/v1/mini-app/warranty-cases/{id}/status` | `data.warranty_case_code`, `data.case_no`,<br>`data.receipt.warranty_case_code`, `data.receipt.case_no` | `data.receipt.state = "SUCCEEDED"` |
| **P09 Chi tiết / Danh sách** | `GET /api/v1/mini-app/warranty-cases`<br>`GET /api/v1/mini-app/warranty-cases/{id}` | `data.warranty_case_code`, `data.case_no` | `data.status` (`RECEIVED`, `CHECKING`, ...) |

### 4.2. Bảng Mã lỗi Xung đột & Precondition

| HTTP | Error Code | Tình huống xảy ra | Hành xử bắt buộc phía App/FE |
| :---: | :--- | :--- | :--- |
| `409` | `DOCUMENT_NUMBER_RESERVED` | Tạo/sửa chứng từ truyền `doc_no` trùng với mã đã giữ cho nháp khác hoặc không khớp nháp sở hữu. | Không dùng mã đó; giữ nguyên nháp gốc hoặc để BE tự cấp. |
| `409` | `P04_NUMBER_IDENTITY_MISMATCH`<br>`P05_NUMBER_IDENTITY_MISMATCH` | `draft_id`, `scan_session_id`, owner hoặc kho không khớp với bản ghi giữ số. | Không tạo nháp mới đè lên; kiểm tra lại đúng phiên/nháp. |
| `409` | `DRAFT_STATE_CONFLICT` | Cố cấp số hoặc ghi đè cho nháp đã kết thúc (`RECORDED` / `CANCELLED`). | Đọc lại trạng thái nháp/receipt. |
| `409` | `IDEMPOTENCY_KEY_REUSED`<br>`IDEMPOTENCY_CONFLICT` | Dùng lại cùng `Idempotency-Key` nhưng thay đổi payload/route/phiên bản. | Giữ nguyên payload gốc khi retry hoặc dùng key mới cho thao tác mới sau khi đã đối chiếu. |
| `409` | `OWNER_RECONCILE_REQUIRED` | Re-submit lệnh trong ca làm việc (P14 shift fence) hoặc khi lệnh đang xử lý. | Gọi API đối chiếu (`reconcile`) theo key gốc. |
| `412` | `STALE_VERSION` / `DRAFT_VERSION_CONFLICT` | `If-Match` / `X-Draft-Version` không khớp version hiện tại trên BE. | `GET` lại bản ghi/nháp để lấy version và mã hiện tại. |
| `422` | `WARRANTY_CASE_CODE_EXISTS` | Truyền mã bảo hành tường minh đã tồn tại trong `warranty_case`. | Để trống trường mã để BE tự cấp `BH-YYYYMMDDHHmmss`. |
| `503` | `DOCUMENT_NUMBERING_SCHEMA_UNAVAILABLE`<br>`P05_NUMBERING_SCHEMA_UNAVAILABLE`<br>`P05_SAVE_NUMBERING_UNAVAILABLE` | Thiếu bảng counter/reservation (fail-closed, không tự bịa mã tạm). | Báo lỗi hệ thống bảo trì, không tự sinh mã ở client. |

### 4.3. API Đối chiếu (Reconcile) khi Timeout hoặc `UNKNOWN`

Khi client gặp timeout mạng, mất kết nối hoặc nhận trạng thái `202 Accepted` / `UNKNOWN` / `OWNER_RECONCILE_REQUIRED`, **tuyệt đối không tự tạo UUID nháp mới hoặc gửi lệnh tạo mới ngay**, mà phải gọi API đối chiếu theo định danh gốc:

1. **Nhập kho (P04 Draft SAVE / RECORD / CANCEL)**:
   - **Endpoint**: `GET /api/v1/mini-app/inbound/drafts/{draftId}/requests/{requestId}?warehouse_id={warehouseId}&scan_session_id={scanSessionId}&command={SAVE|RECORD|CANCEL}`
   - **Kết quả**:
     - `data.outcome = "SUCCEEDED"`: Lấy lại nguyên vẹn `data.document.document_no` (hoặc `data.response.data.doc_no` khi `command=RECORD`).
     - `data.outcome = "UNKNOWN"`: Lệnh chưa bao giờ commit trên BE; chỉ khi đã xác nhận `UNKNOWN` mới được phép retry cùng key hoặc khởi tạo lại theo `retry` policy.

2. **Xuất kho (P05 Draft SAVE / CANCEL / RECORD)**:
   - **Endpoint**: `GET /api/v1/mini-app/drafts/{draftId}/requests/{operationKey}?warehouse_id={warehouseId}&operation=OUTBOUND&scan_session_id={scanSessionId}&command={SAVE|CANCEL|RECORD}`
   - **Header**: `X-Outbound-Numbering-Contract: p05-save-numbering-v1`
   - **Kết quả**:
     - `data.outcome = "SUCCEEDED"`: Lấy lại mã `PX-YYYYMMDDHHmmss` tại `data.document.document_no` và `data.response.data.document.document_no`.
     - `data.outcome = "UNKNOWN"`: Kèm `data.retry = "CHECK_ONLY"`, `data.new_document_allowed = false`.

3. **Bảo hành (P09 CREATE / UPDATE / STATUS)**:
   - **Endpoint**: `GET /api/v1/mini-app/warranty-requests/{requestId}?warehouse_id={warehouseId}`
   - **Kết quả**:
     - `data.receipt.state = "SUCCEEDED"`: Trả về `data.warranty_case_id`, `data.warranty_case_code` (`BH-YYYYMMDDHHmmss`), `data.case_no`, `data.receipt.warranty_case_code` và `meta.p12.read_target.path = "/api/v1/mini-app/warranty-cases/{warranty_case_id}"`.
     - `data.receipt.state = "PROCESSING"` hoặc `"UNKNOWN"`: `data.receipt.may_create_new_request = false` (không được tự tạo hồ sơ mới trùng lặp).
     - `data.receipt.state = "FAILED"`: `data.receipt.may_create_new_request = true` (lệnh cũ đã thất bại hoàn toàn, có thể tạo yêu cầu mới sau khi sửa dữ liệu).
