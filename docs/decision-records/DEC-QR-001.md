# DEC-QR-001 — Composite PRODUCT Barcode/QR: parser, resolver, new item candidate và transition legacy

| Trường | Giá trị |
|---|---|
| Mã quyết định | `DEC-QR-001` |
| Ngày lập | 20/08/2026 |
| Nguồn yêu cầu | Change Pack "HN_Tong the thay doi" + "HN_Huong_dan_dev_trien_khai" (BA, 20/08/2026) — PRODUCT QR chứa đồng thời `sku_code` + `item_unique`; backend parse authoritative |
| Phạm vi | `POST /inbound-documents/{id}/scans`, `POST /inbound-documents/{id}/post-receipt`, `POST /outbound-documents/{id}/scans`, `POST /scan/classify` (alias `/scan-batches/classify`), `GET /inventory/trace/{code}` + `/stock-movements/trace-by-code`, `POST /warranty-cases` |
| Nguồn sự thật kỹ thuật | `config/warehouse.php` → khoá `warehouse.product_qr`; `Modules\Warehouse\Services\ProductQrPayloadParser`; `Modules\Warehouse\Services\ProductIdentityResolver` |
| Test | `tests/Unit/ProductQrPayloadParserTest.php`, `tests/Feature/ProductQrCompositeScanTest.php`, `tests/Feature/ProductQrClassifyResolveTest.php` (QR-01..QR-18 của change pack) |

## 1. Reference format (đóng DEC-QR-01 của change pack)

`HN1|SKU=<sku_code>|ITEM=<item_unique>[|SER=<serial_nsx>]`

- Prefix/version đọc từ config (`composite_prefix` = `HN`, `supported_versions` = `[1]`);
  đổi format nhà cung cấp = thay adapter/parser + version mới, KHÔNG sửa luồng nghiệp vụ.
- Parser là pure function (không DB), chuẩn hoá trim + uppercase đúng quy ước sẵn có của
  `physical_codes.code_value` (mọi điểm ghi/tra đều strtoupper) — nhờ vậy FE `ScanInput`
  có uppercase trước hay camera gửi nguyên văn đều cho cùng kết quả.
- Payload "có ý định composite" (khớp `HN<digits>|`) mà sai cấu trúc → `INVALID_PAYLOAD`,
  KHÔNG rơi thầm về nhánh legacy (Change Pack §9: không fallback đoán cấu trúc).
- Segment lạ/trùng/rỗng → `INVALID_PAYLOAD`; version lạ → `UNSUPPORTED_PAYLOAD_VERSION`.
- Trần raw payload request = 512 (nâng từ 150 theo guide §5.1); từng thành phần SKU/ITEM/SER
  ≤ 100 ký tự (trần cột chặt nhất giữa hai driver: `physical_codes.code_value` sqlite
  varchar(100), `item_instances.serial_number` varchar(100)).

## 2. item_unique unique toàn hệ thống (đóng DEC-QR-02)

Theo mặc định của change pack (§7): `item_unique` là định danh item duy nhất toàn hệ thống —
`Physical_Code.code_value` (UNIQUE) giữ normalized item_unique, `serial_number` nhận `SER=`
nếu có, ngược lại nhận chính item_unique. KHÔNG đổi uniqueness constraint hiện có; nếu sau
này mẫu dữ liệu thật cho thấy item_unique chỉ unique trong SKU, phải mở amendment mới
(composite key) — không tự đổi constraint (đúng ràng buộc §7 change pack).

## 3. Register-at-Post cho new item candidate (đóng DEC-QR-03)

- Inbound scan composite với item chưa tồn tại → entry `inbound_scan_entries` ghi
  `candidate_item_unique`/`candidate_serial_number`, `item_id = NULL`, KHÔNG tạo
  item/physical code, KHÔNG stock effect (AC-QR-03/08).
- Post Receipt tạo `item_instances` (NEW) + `physical_codes` (ACTIVE, carrier QR,
  `encoded_payload_version = '1'`) atomically TRONG cùng transaction với movement/balance,
  TRƯỚC vòng mutateBalance để movement.item_id được gán đúng; subledger chuyển NEW → IN_STOCK
  qua đường state chung với item pre-seeded (AC-QR-04). Idempotency replay không chạy lại
  closure nên không thể tạo item lần hai (QR-07).
- Cancel trước Post: candidate chỉ là dòng entry của phiếu — không có gì để cleanup (QR-08).
- Duplicate guard: cùng phiếu → 409 `DUPLICATE_ITEM_IN_DOCUMENT`; phiếu inbound mở khác →
  409 `ITEM_ALREADY_RECEIVING`; identity đã bị chiếm (kể cả mã INACTIVE) → 409
  `ITEM_ALREADY_REGISTERED`; re-check fail-closed ngay trong transaction Post + unique
  constraint DB làm backstop.
- Hai cột candidate là cột runtime bổ sung ngoài Data Dictionary ERD bảng 28 (cùng tiền lệ
  `created_at/updated_at/version` của chính bảng này); parity test chỉ yêu cầu cột đã tài
  liệu hoá tồn tại đúng kiểu nên KHÔNG cần amendment ERD/pin hai repo cho thay đổi này.

## 4. Transition legacy (đóng DEC-QR-04)

- `warehouse.product_qr.legacy_enabled` (env `WMS_PRODUCT_QR_LEGACY_ENABLED`, mặc định
  `true`): flag OFF chỉ chặn plain code trên dòng PRODUCT của scan nhập/xuất
  (422 `LEGACY_CODE_DISABLED`); composite luôn chạy.
- Ngữ cảnh read-only (classify/trace/warranty) TIẾP TỤC resolve plain code kể cả khi flag
  OFF — khoá tra cứu lịch sử/bảo hành của item phát hành trước change là tổn thất vận hành
  không cần thiết và không nằm trong rủi ro mà flag này phải chặn (flag chặn NHẬN HÀNG bằng
  mã cũ, không chặn ĐỌC).
- QR-17/18 cover cả hai trạng thái flag.

## 5. Audit scan payload (Change Pack §7 — mức "Khuyến nghị")

- `scan_event.raw_code` ghi raw payload (cắt 200 ký tự theo trần cột canonical ERD bảng 25).
- `raw_payload` đầy đủ + `parsed_sku_code`/`parsed_item_unique`/`payload_version`/`parse_status`
  ghi vào `scan_events.metadata` (jsonb có sẵn theo ERD bảng 25) bằng pattern defensive
  hasColumn/try-update như `stampScanSource` — KHÔNG thêm cột mới vào scan_events để không
  phải amendment ERD + pin hai repo cho một hạng mục khuyến nghị.

## 6. Error taxonomy mới (Change Pack §9 → mã thực tế)

| Change pack | Mã hệ thống | HTTP | Ngữ cảnh |
|---|---|---:|---|
| INVALID_PAYLOAD | `INVALID_PAYLOAD` | 422 | scan/classify/trace |
| UNSUPPORTED_PAYLOAD_VERSION | `UNSUPPORTED_PAYLOAD_VERSION` | 422 | scan/classify/trace |
| SKU_NOT_FOUND / SKU_INACTIVE | `SKU_NOT_FOUND` / `SKU_INACTIVE` | 422 | mọi đường composite |
| WRONG_SKU | `WRONG_SKU` | 422 | embedded SKU lệch line/item (inbound, outbound, classify, warranty) |
| DUPLICATE | `DUPLICATE_ITEM_IN_DOCUMENT` (giữ mã hiện hành) | 409 | inbound/outbound |
| EXCESS | `SCAN_EXCEEDS_REQUIRED` (giữ mã hiện hành) | 422 | inbound/outbound |
| UNKNOWN_CODE | `UNKNOWN_CODE` | 422 scan / 404 classify | composite valid, item chưa đăng ký |
| INVALID_STATE / ALREADY_ISSUED | `ITEM_NOT_AVAILABLE` / `ITEM_NOT_RECEIVABLE` (giữ mã hiện hành) | 422 | state item |
| (transition) | `LEGACY_CODE_DISABLED` | 422 | flag OFF + plain code PRODUCT |
| (đăng ký đè) | `ITEM_ALREADY_REGISTERED` | 409 | candidate trùng identity đã tồn tại |

Đường legacy (plain code) giữ nguyên 100% mã lỗi cũ (`PHYSICAL_CODE_NOT_FOUND`,
`ITEM_SKU_MISMATCH`, ...) — không client cũ nào đổi hành vi khi flag ON.

## 7. Auto-match line (Change Pack §6.1 bước 4, guide §11)

`line_id` trở thành TÙY CHỌN khi request có `code_value`: composite → match theo SKU
embedded; legacy → match theo SKU của item đã đăng ký. Nhiều dòng cùng SKU: ưu tiên dòng
còn thiếu theo `line_no`. `line_id` gửi tường minh vẫn được nhận và bị cross-check
`WRONG_SKU` khi lệch SKU embedded — user không thể override một scan sai SKU bằng chọn tay
(guide §12 mục 18). Scan quantity-mode (COMPONENT, không có code) vẫn bắt buộc `line_id`
(`required_without:code_value`).
