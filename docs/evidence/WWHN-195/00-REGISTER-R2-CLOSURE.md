# WWHN-195 — Hồ sơ đóng register R2 (69/100 → closure)

| Trường | Giá trị |
|---|---|
| Task | WWHN-195 — [BE1] Triển khai chứng từ/chế độ/trạng thái xuất kho |
| Parent | WWHN-45 (HN-OUT-001) · Epic HN-EPIC-06 |
| Register | DEV195-P1-01R2 … DEV195-P2-02R2 (bản đánh giá 69/100) |
| Nhánh | `m1` (không tạo nhánh riêng) |
| Ngày | 19/08/2026 |

---

## 1. Root cause chung của cả ba lệch API contract

Ba mục P1-05R2 (*ghost POST/PATCH*, *max 200 vs runtime 150*, *label state cũ*) **không phải ba lỗi độc lập**. Chúng cùng một gốc:

> `Modules/Warehouse/routes/api.php` khai route outbound trỏ `CurrentBaselineStockFlowController`,
> nhưng `Flow06AlignmentServiceProvider::boot()` đăng ký lại đúng method+URI đó ở `booted()`
> và trỏ sang `AlignedOutboundFlowController`. Laravel lấy route đăng ký **sau**, nên
> handler chạy thật là `Aligned*`, còn bảng route tĩnh mô tả `CurrentBaseline*`.

Hệ quả dây chuyền:

| Ai đọc gì | Thấy | Sự thật runtime |
|---|---|---|
| Người dựng contract đọc `routes/api.php` | `CurrentBaselineStockFlowController::outboundScan` | `AlignedOutboundFlowController::outboundScan` |
| → `code_value` maxLength | `max:150` (handler bị che) | `max:200` (handler thật) — contract ghi 200, reviewer đọc source ra 150 |
| → model nhãn | tạo `Label_Snapshot` **PENDING_PRINT** cho từng item + bắn lệnh in ngay lúc scan | tạo đúng **01 Packing Label DRAFT** cho cả phiếu khi full match, không in |
| → annotation Swagger inline | vẫn khai `stock_out_create` / `stock_out_update` | không có route nào cho hai method này |

Vì vậy fix không dừng ở việc sửa file YAML: **phải bỏ cơ chế shadow-route** thì contract mới không lệch lại lần sau.

---

## 2. Đã sửa gì

### DEV195-P1-05R2 — API contract

| # | Thay đổi | File |
|---|---|---|
| 1 | Trỏ 4 route outbound (GET danh sách, `/scan`, `/unmatch`, `/post-issue`) thẳng vào handler canonical `Aligned*`; bảng route tĩnh = runtime | `Modules/Warehouse/routes/api.php` |
| 2 | Gỡ 4 override trùng khỏi provider, giữ lại đúng phần `label-snapshots` còn cần override | `Modules/Warehouse/app/Providers/Flow06AlignmentServiceProvider.php` |
| 3 | Xoá `outboundScan()`, `postIssue()`, `createAndDispatchLabel()`, `recordPrintResult()` — toàn bộ model nhãn per-item `PENDING_PRINT` + auto-print lúc scan | `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php` |
| 4 | Gỡ annotation ghost `stock_out_create` / `stock_out_update`; cập nhật mô tả `/scan` và `/post-issue` theo §5.2/§6; `code_value` maxLength 200 → 150 | `Modules/Warehouse/app/Http/Controllers/OutboundDocumentController.php` |
| 5 | Thống nhất `code_value` = **150** ở handler đang chạy (trước là 200) | `Modules/Warehouse/app/Http/Controllers/AlignedOutboundFlowController.php` |
| 6 | Sinh lại contract từ route canonical: **14 operation**, khớp 1-1 route runtime; enum nhãn `DRAFT/PREVIEWED/READY_TO_PRINT/PRINTED/PRINT_FAILED` | `docs/api-contract/WWHN-195-outbound-openapi.yaml` + `.json` |
| 7 | Postman viết lại 19 request theo luồng thật, kèm 2 guard cho ghost operation | `docs/postman/WWHN-195 Outbound Contract.postman_collection.json` |
| 8 | Test case: re-scope nhóm `create` sang đường Confirm toa, thêm nhóm `source-of-truth`, `packing-label`, `driver-parity` → **47 case** | `docs/testing/WWHN-195-outbound-test-cases.json` |
| 9 | FE: gỡ `updateOutboundDocument()` gọi `PATCH /outbound-documents/{id}` (endpoint không tồn tại → luôn 404) | `src/modules/outbound/services/mvpOutboundService.ts`, `alignedOutboundService.ts` |

### DEV195-P1-01R2 — chế độ quét

Quyết định đầy đủ ở `docs/decision-records/DR-HN-20260819-WWHN195-SCAN-MODE.md`. Tóm tắt:

- `scan_source` **optional, explicit**, enum đúng CHECK của ERD bảng 25: `CAMERA | MANUAL | HARDWARE`.
- Không khai → server suy luận và trả `scan_source_origin=INFERRED` (không phá client cũ).
- **Không khoá mode theo phiếu**, không có endpoint switch — vì AC-SRS-005 + NFR-SRS-021 đòi camera-denied phải rơi về nhập tay ngay giữa luồng.
- Giá trị được ghi thẳng xuống `scan_events.scan_source`, thay vì để trigger DB đoán `physical_code_id IS NULL ? MANUAL : HARDWARE` — cách đoán đó **không bao giờ sinh ra `CAMERA`**, tức mọi lần quét camera bị ghi sai thành `HARDWARE`.
- FE khai đúng nguồn: `CameraScanModal.onDetected` → `CAMERA`, ô nhập tay/Enter → `MANUAL`.

### DEV195-P2-02R2 — driver parity

- Probe cho thấy nhánh sqlite (bộ CI) **không có** cột `scan_source` ở cả `scan_event` lẫn view `scan_events` → không test nào chứng minh được audit source trên CI.
- Migration `2026_08_19_000300_expose_scan_source_on_sqlite_scan_evidence.php` (driver-gated, chỉ chạy sqlite) bổ sung `scan_source`/`context_type`/`context_id` và dựng lại view.
- Rationale driver-specific ghi ngay trong docblock migration; PostgreSQL không cần vì `2026_08_10_000770` đã tạo sẵn cột kèm CHECK.

---

## 3. Bằng chứng chạy được lại

| Loại | Lệnh | File evidence |
|---|---|---|
| Bề mặt route runtime | `php artisan route:list --json` | `01-runtime-route-surface.json` |
| Verifier contract (6 bất biến) | `node scripts/verify-wwhn-195-outbound-contract.mjs --json` | `02-contract-verifier.json` |
| Test hồi quy | `php vendor/bin/phpunit --filter=Wwhn195OutboundContractAlignmentTest` | `03-phpunit-wwhn195.json` |
| Full suite sqlite | `php vendor/bin/phpunit` | `04-phpunit-full-sqlite.json` |
| PostgreSQL migrate/schema/FK | xem `05-postgres-*.json` | `05-postgres-schema.json` |

Verifier kiểm đúng 6 điểm mà review R2 đã bắt:

1. **V1** không còn ghost `POST /outbound-documents` và `PATCH /outbound-documents/{id}` (route + annotation).
2. **V2** không route outbound nào bị đăng ký ở hai nơi — chặn tái lập cơ chế shadow đã gây ra cả ba lệch.
3. **V3** contract khai đúng bằng tập route runtime, cả hai chiều.
4. **V4** `code_value` maxLength contract == runtime == 150.
5. **V5** vòng đời nhãn §6, source không còn đường in per-item.
6. **V6** `scan_source` tường minh ở BE + contract + FE.

---

## 4. Còn mở (đã có owner, không chặn code)

| Mục | Nội dung | Owner |
|---|---|---|
| DATA-01 | `physical_codes.code_value`: ERD ghi `varchar(200)`, migration tạo `varchar(100)`, API chốt 150. Không tạo dữ liệu sai (mã > 100 ký tự chỉ trả `PHYSICAL_CODE_NOT_FOUND`) nhưng ba con số cần về một bằng ERD amendment. | BE2 / ERD owner |
| DATA-02 | Inbound ghi `inbound_scan_entries.scan_source = 'CAMERA_OR_MANUAL_CODE'` — ngoài tập ERD. Ngoài phạm vi WWHN-195 (thuộc WWHN-187/190). | BE1 inbound |
| BA-01 | Actor gắn/ghi/kiểm tra RFID theo OP-05 — CÒN MỞ theo chính SRS v1.12 (DR-CARRIER-001). Không chặn vì RFID không phải gate Post Issue. | BA |
