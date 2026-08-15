[DEV] Triển khai lấy/quét hàng/dedup/availability
Mục tiêu

Triển khai lấy/quét hàng/dedup/availability.

Input đầu vào

Task cha: WWHN-46 — [OUTBOUND] Lấy hàng/quét mã và chống ghi nhận trùng

Dependency: HN-OUT-001; HN-INV-001

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp

Người chịu trách nhiệm chính: BE2 — Duy Khánh Nguyễn.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE1 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability

Parent Temp Key: HN-OUT-002

Epic Temp Key: HN-EPIC-06

Dependency: HN-OUT-001; HN-INV-001

Role: BE2

Assignee Placeholder: HN-BE-02

Acceptance Criteria

Output "Outbound pick backend" được rà soát/xác minh, gắn evidence vào HN-OUT-002 và không còn blocker thuộc vai trò BE2.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra

Outbound pick backend

Definition of Done

Code, review, unit/integration test, API/DB evidence và traceability hoàn tất.

Contract, migration/seed, tài liệu API hoặc release note được cập nhật khi bị ảnh hưởng.

FE/QA nhận đủ đầu ra; không còn blocker chưa có owner, ETA và disposition.

Phạm vi thực hiện

Thực hiện đúng Mục tiêu, Input đầu vào, Source & Traceability, Acceptance Criteria và Output đầu ra.

Bảo toàn baseline, contract, dependency và evidence; không tự ý mở rộng scope hoặc thay đổi reference.

Thay đổi ảnh hưởng API, UI, data hoặc CI/CD phải được trace và review trước khi áp dụng.

Thuật ngữ / technical terms giữ nguyên

API, endpoint, payload, field, enum, table, schema, branch, environment, status, label, UI, RBAC, OpenAPI, CI/CD, UAT và tên kỹ thuật trong phạm vi.

Parent key, Epic key, output name, role và assignee placeholder được giữ nguyên để bảo toàn traceability.

Lưu ý cho DEV

Làm đúng vai trò và phạm vi bàn giao; nếu thiếu input/dependency, phải comment blocker kèm owner và ETA.

Không thay thế hoặc xóa baseline/reference; khác biệt giữa code, API, UI và data phải được nêu trong PR/evidence.

Lưu ý cho BA/QA

Xác nhận Acceptance Criteria, evidence, dependency và open point theo baseline.

Đánh dấu rõ nội dung cần formal sign-off, rủi ro hiểu sai hoặc contract mismatch trước khi nghiệm thu.

---

## Kết quả rà soát (Evidence — cập nhật 2026-08-15, BE2)

**Exact commit SHA khi bắt đầu rà soát**: `fad424283638254f0b8ccb441fc49e88e9e1a692` (HEAD của `m1`), cùng working tree với các thay đổi chưa commit của các task song song khác (WWHN-201/202/204/205/207 — sửa `CurrentBaselineStockService.php` phần `mutateBalance()`/ledger, thêm migration `2026_08_15_000100_enforce_stock_movements_append_only_trigger.php`). Task này chỉ thêm 1 file test mới (`tests/Feature/Wwhn198OutboundPickScanDedupTest.php`), không sửa file production nào đang bị task khác động tới — không xung đột.

**Quan hệ với task rà soát trước đó (WWHN-199)**: File `docs/docs_task_jira/WWHN-199.md` đã tồn tại với nội dung khác — task "[DEV] Rà soát khóa đồng thời/quy tắc reservation" (BE1, cùng Parent HN-OUT-002/Epic HN-EPIC-06/task cha WWHN-46, dependency `HN-OUT-002-ST01`) — đã rà soát sâu **tầng reservation/concurrency** (item không được match trùng 2 document, toa chỉ sinh 1 document) và bổ sung `tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php` (5 test case). Task này (WWHN-198, BE2, dependency `HN-OUT-001`/`HN-INV-001`) rà soát một tầng **khác và rộng hơn**: toàn bộ luồng pick/scan/resolve mã vật lý và availability-check tại thời điểm scan — phần lõi "quét mã" của task cha WWHN-46 chưa từng được lái qua HTTP ở bất kỳ đường lỗi nào (khác với WWHN-199 vốn chỉ test đường thành công + 2 lỗi dedup cấp cao).

### Phương pháp

Đọc trực tiếp toàn bộ `CurrentBaselineStockFlowController.php` (555 dòng) và `CurrentBaselineStockService.php` (983 dòng, đặc biệt `scanStockDocument()`, `resolveItem()`, `assertItemAvailableForIssue()`, `assertIssueAvailability()`, `recordOutboundMatch()`), route file (`Modules/Warehouse/routes/api.php`), migration `item_instances`/`physical_codes`/`inventory_balances`/`outbound_item_matches`, và toàn bộ test hiện có (`OutboundDocumentFlowTest.php`, `Wwhn199ReservationConcurrencyGuardTest.php`, `Wwhn207OutboundBalanceReconciliationTest.php`, `PreparesOutboundFixtures.php`). Đối chiếu độc lập bằng một Explore agent chuyên trách đọc toàn bộ 8 hạng mục (endpoint/dedup/availability/container-RFID/test-coverage/OpenAPI/WWHN-199 tra cứu/migration schema) — kết quả trùng khớp 100% với rà soát trực tiếp, bổ sung thêm 2 quan sát (mục 5, 8 dưới đây).

### 1. Endpoint lấy hàng/quét mã (`POST .../scan`, `POST .../unmatch`) — ĐẦY ĐỦ

- `outboundScan()` (`CurrentBaselineStockFlowController.php:37-49`) → `versionedScan()` (dòng 160-198) → `CurrentBaselineStockService::scanStockDocument()`. Route `POST outbound-documents/{id}/scan`, permission `outbound.scan` (`routes/api.php:80`).
- `scanPayload()` (dòng 484-498): `line_id` bắt buộc; `quantity`/`qty` một trong hai bắt buộc (int ≥1); `code_value` (chế độ quét mã) và `item_id` (chế độ nhập tay) đều optional, có thể gửi cả hai để server tự đối chiếu khớp.
- `outboundUnmatch()` (dòng 56-138): gỡ item đã match trước Post, chuyển match `ACTIVE → REMOVED`, giảm `scanned_qty`, hoàn toàn stock-neutral; route `POST outbound-documents/{id}/unmatch`, cùng permission `outbound.scan`.
- Cả hai đều bắt buộc header `If-Match` (optimistic concurrency, thiếu → 428 `PRECONDITION_REQUIRED`, sai version → 412 `PRECONDITION_FAILED`), chạy trong `DB::transaction(..., 3)`.
- Khớp đúng OpenAPI đã publish ở WWHN-195 (`docs/api-contract/WWHN-195-outbound-openapi.yaml:297-351`) — không cần cập nhật contract vì không đổi shape.

### 2. Dedup (chống ghi nhận trùng) — ĐẦY ĐỦ, đã có test từ WWHN-199; bổ sung 1 quan sát kỹ thuật

Toàn bộ guard dedup (`DUPLICATE_ITEM_IN_DOCUMENT`, `ITEM_ALREADY_MATCHED`, `ITEM_ALREADY_RECEIVING`, unique index Postgres-level `ux_erd_outbound_active_item`/`uk_inbound_scan_active_item`) đã được BE1 rà soát và test đầy đủ ở WWHN-199 (xem `docs/docs_task_jira/WWHN-199.md` mục 2, 5). Không lặp lại phạm vi đó.

**Quan sát mới (không phải bug, không sửa)**: `recordOutboundMatch()` (`CurrentBaselineStockService.php:586-594`) có guard `DUPLICATE_ITEM_MATCH` riêng (409) cho cùng invariant "item trùng trong cùng document" mà `assertNoDuplicateItemInDocument()` (controller, dòng 422-439, trả `DUPLICATE_ITEM_IN_DOCUMENT`) đã chặn **trước** trong luồng gọi (`versionedScan()` dòng 181-184 gọi `resolveItemId()` + `assertNoDuplicateItemInDocument()` trước khi gọi `scanStockDocument()`). Vì `resolveItemId()` (controller) và `resolveItem()` (service) dùng cùng logic tra `physical_codes`/`item_id`, `DUPLICATE_ITEM_MATCH` gần như không thể đạt tới qua luồng HTTP bình thường — đây là lớp defense-in-depth hợp lệ (service không tin tưởng tuyệt đối input đã qua controller), không phải dead code cần xóa. Ghi nhận cho BE1/tech-lead cân nhắc khi review, không tự ý sửa vì ngoài phạm vi task và rủi ro thay đổi một invariant đang hoạt động đúng.

### 3. Quét mã — resolve mã vật lý (`resolveItem()`/`resolveItemId()`) — ĐẦY ĐỦ, phát hiện gap coverage lớn (đã lấp)

`resolveItem()` (`CurrentBaselineStockService.php:478-512`, dùng bên trong transaction, có `lockForUpdate()` trên `item_instances`) là resolver có thẩm quyền:
- `code_value` → tra `physical_codes` (`status='ACTIVE'`) → nếu không thấy: 422 `PHYSICAL_CODE_NOT_FOUND`.
- Nếu `assigned_object_type != 'ITEM'` hoặc thiếu `assigned_object_id`: fallback tra `item_instances.physical_code_id`; nếu vẫn null: 422 `PHYSICAL_CODE_UNASSIGNED`.
- Nếu gửi cả `item_id` lẫn `code_value` và chúng không trỏ cùng item: 422 `PHYSICAL_CODE_ITEM_MISMATCH`.
- Item resolve được phải thuộc đúng `sku_id` của dòng, nếu không: 422 `ITEM_SKU_MISMATCH`.
- SKU serial-tracked/PRODUCT bắt buộc phải có item cụ thể (422 `ITEM_REQUIRED` nếu thiếu); nếu có item, `quantity` bắt buộc = 1 (422 `SERIAL_QUANTITY_MUST_BE_ONE`).
- Item resolve được phải `IN_STOCK` trước khi match issue (422 `ITEM_NOT_AVAILABLE`, phân biệt với 409 `ITEM_ALREADY_MATCHED` — đây là 2 lỗi khác nhau: item không sẵn sàng vs item đã bị chiếm bởi document khác).

**Gap tìm thấy trước khi rà soát**: `OutboundDocumentFlowTest.php`/`Wwhn199ReservationConcurrencyGuardTest.php` chỉ lái qua đường thành công (`item_id` hợp lệ, `code_value` hợp lệ) và 2-3 lỗi cấp cao (`INSUFFICIENT_STOCK`, `ITEM_ALREADY_MATCHED`, `DUPLICATE_ITEM_IN_DOCUMENT`). **Không có test HTTP nào** cho `PHYSICAL_CODE_NOT_FOUND`, `PHYSICAL_CODE_ITEM_MISMATCH`, `PHYSICAL_CODE_UNASSIGNED` (nhánh fallback qua `item_instances.physical_code_id`), `ITEM_SKU_MISMATCH`, `SERIAL_QUANTITY_MUST_BE_ONE`, `ITEM_REQUIRED`, `ITEM_NOT_AVAILABLE`, `SCAN_EXCEEDS_REQUIRED` — đây chính là phần lõi "quét mã" của task cha WWHN-46, chưa từng lái qua HTTP thật.

**Đã lấp**: `tests/Feature/Wwhn198OutboundPickScanDedupTest.php` — 10 test case mới, toàn bộ lái qua HTTP thật (chi tiết ở mục 6).

### 4. Availability check tại scan-time — ĐẦY ĐỦ theo thiết kế đã có (soft-check + hard-check); không có endpoint availability riêng (không phải bug)

- `assertIssueAvailability()` (`CurrentBaselineStockService.php:553-565`): đọc `inventory_balances.available_qty` theo `warehouse_id`+`sku_id`+`stock_status=AVAILABLE`, **không** `lockForUpdate()` — soft-check tại scan time cho phản hồi sớm, đã được WWHN-199 xác nhận đây là quyết định thiết kế đúng (không phải lỗ hổng — xem WWHN-199.md mục 3), vì `mutateBalance()` tại Post Issue mới là nguồn sự thật cuối cùng (có lock, không bao giờ cho tồn âm). Không sửa.
- **Xác nhận không có endpoint `GET .../availability` riêng biệt** ở bất kỳ đâu trong `Modules/Warehouse/routes/api.php` — đã grep toàn bộ route file và toàn bộ `Modules/Warehouse` cho từ khóa "availability"/"available" (21 file khớp, toàn bộ là inline check/report/safe-stock, không có endpoint pre-check availability độc lập). `GET /api/v1/inventory/balances` (`InventoryBalanceController`) là endpoint gần nhất nhưng là view báo cáo/đối soát chung (`inventory.view`), không thiết kế cho mục đích pre-check trước khi scan, không có filter `warehouse_id` dù balance vốn theo warehouse.
- **Đây là điểm cần BA/PM xác nhận phạm vi**: nếu AC gốc của WWHN-46/HN-OUT-002/HN-INV-001 yêu cầu một API "kiểm tra khả dụng" độc lập (FE gọi trước/trong khi quét để hiển thị số lượng còn lại mà không cần thử scan), đây là **tính năng mới chưa xây dựng**, không phải gap của rà soát hiện tại — không tự ý thêm endpoint mới ngoài AC đã xác nhận (tránh mở rộng scope).
- `inventory_balances.reserved_qty` luôn hardcode 0 (đã ghi nhận ở WWHN-199 mục 4, BE1-RESV-OP-01) — kế thừa nguyên trạng, không lặp lại rà soát.

### 5. Container/RFID trong luồng scan — ghi nhận, không phải gap cần xử lý

`scanPayload()` nhận `container_id` optional, được lưu vào `outbound_item_matches.container_id`/`inbound_scan_entries.container_id` như metadata (item được lấy từ container nào), nhưng **không có logic "quét mã container để tự resolve toàn bộ item bên trong"** — muốn thêm/gỡ item khỏi container phải dùng API `ContainerController`/`CurrentBaselineContainerFlowController` riêng, không tích hợp vào `outboundScan`. Không có AC nào trong task cha yêu cầu container-level bulk scan cho outbound pick — không tự ý mở rộng.

RFID (`hardware_uid` ACTIVE) chỉ bắt buộc tại Post Issue (`assertMatchedProductRfids()`), không bắt buộc tại scan — barcode/QR/manual code đã đủ để match tại thời điểm quét. Đúng theo baseline đã xác nhận ở WWHN-195.

### 6. Test mới bổ sung

File `tests/Feature/Wwhn198OutboundPickScanDedupTest.php` — 10 test case, toàn bộ lái qua HTTP thật:

| Test case | Xác nhận |
|---|---|
| `test_scan_with_unknown_code_value_is_rejected` | `code_value` không tồn tại → 422 `PHYSICAL_CODE_NOT_FOUND`; không tạo match, `scanned_qty` không tăng |
| `test_scan_with_inactive_code_value_is_rejected` | `physical_codes.status != ACTIVE` (LOST) → 422 `PHYSICAL_CODE_NOT_FOUND` (cùng nhánh không-tìm-thấy vì query lọc `status='ACTIVE'`) |
| `test_scan_rejects_mismatched_item_id_and_code_value` | Gửi `item_id` và `code_value` trỏ 2 item khác nhau → 422 `PHYSICAL_CODE_ITEM_MISMATCH`; không tạo match |
| `test_scan_rejects_item_belonging_to_different_sku` | Item thuộc SKU khác dòng đang scan → 422 `ITEM_SKU_MISMATCH` |
| `test_scan_rejects_item_not_in_stock` | Item `item_status=ISSUED` (không phải `IN_STOCK`) → 422 `ITEM_NOT_AVAILABLE` (phân biệt với `ITEM_ALREADY_MATCHED` đã test ở WWHN-199) |
| `test_scan_serial_tracked_line_rejects_quantity_greater_than_one` | Scan item cụ thể với `quantity=2` → 422 `SERIAL_QUANTITY_MUST_BE_ONE` |
| `test_scan_product_line_without_item_is_rejected` | Dòng PRODUCT/serial-tracked scan không kèm `item_id`/`code_value` → 422 `ITEM_REQUIRED` |
| `test_scan_rejects_quantity_exceeding_line_required_qty` | Scan đủ `qty_planned` rồi scan thêm → 422 `SCAN_EXCEEDS_REQUIRED` |
| `test_scan_code_value_resolves_via_item_instances_fallback_when_physical_code_unassigned_type` | `physical_codes.assigned_object_type` không phải `ITEM` → resolver fallback đúng qua `item_instances.physical_code_id`, scan thành công |
| `test_create_outbound_document_rejects_component_sku_request` | Xác nhận `PRODUCT_SKU_REQUIRED` thực chất được chặn tại **create document** (`CurrentBaselineDocumentController::hydrateOutboundRequest()`), không phải tại scan — làm rõ boundary giữa 2 lớp validate (ghi chú kỹ thuật, không phải lỗi) |

**Kết quả**: `php artisan test tests/Feature/Wwhn198OutboundPickScanDedupTest.php` → **10/10 passed, 34 assertions** (sau 2 lần sửa test ban đầu — không phải bug sản phẩm, xem mục 7).

### 7. Vấn đề gặp phải khi viết test (đã tự sửa trong test, không phải bug sản phẩm)

- `test_scan_serial_tracked_line_rejects_quantity_greater_than_one`: lần đầu seed `qty_planned=1`, khiến `SCAN_EXCEEDS_REQUIRED` (check thứ tự sớm hơn trong `scanStockDocument()`) chặn trước khi tới check serial-quantity. Sửa bằng seed `qty_planned=2`.
- Test fallback resolve qua `item_instances.physical_code_id`: gặp `FOREIGN KEY constraint failed` khi insert `scan_event` trên SQLite, vì `scan_event.physical_code_id` trỏ bảng legacy số ít `physical_code` (không phải `physical_codes` canonical) — đúng bug môi trường-test đã ghi nhận từ WWHN-195 (migration `2026_08_10_000630` cố ý bỏ qua repoint FK trên SQLite). Sửa bằng cách mirror thêm `physical_code` legacy qua helper `mirrorLegacyPhysicalCode()` có sẵn trong `PreparesOutboundFixtures`, giống pattern các test WWHN-195/199 đã dùng. Không sửa migration/schema.
- `test_create_outbound_document_rejects_component_sku_request` (ban đầu đặt tên/kỳ vọng là scan-level): phát hiện `PRODUCT_SKU_REQUIRED` với COMPONENT SKU thực tế bị chặn tại **create document** (toa nguồn gắn SKU cố định trước khi có line để scan), không phải tại scan như giả định ban đầu. Đổi tên test và assertion cho đúng điểm chặn thực tế.

### 8. Test coverage — trước và sau

| Hạng mục | Trạng thái trước | Trạng thái sau |
|---|---|---|
| `PHYSICAL_CODE_NOT_FOUND` (code_value không tồn tại/không ACTIVE) qua HTTP thật | Không có | **Đã bổ sung** |
| `PHYSICAL_CODE_ITEM_MISMATCH` (item_id và code_value lệch nhau) qua HTTP thật | Không có | **Đã bổ sung** |
| `PHYSICAL_CODE_UNASSIGNED`/fallback `item_instances.physical_code_id` qua HTTP thật | Không có | **Đã bổ sung** (nhánh thành công của fallback) |
| `ITEM_SKU_MISMATCH` qua HTTP thật | Không có | **Đã bổ sung** |
| `ITEM_NOT_AVAILABLE` (item không IN_STOCK) qua HTTP thật | Không có | **Đã bổ sung** |
| `SERIAL_QUANTITY_MUST_BE_ONE` qua HTTP thật | Không có | **Đã bổ sung** |
| `ITEM_REQUIRED` (PRODUCT line thiếu item) qua HTTP thật | Không có | **Đã bổ sung** |
| `SCAN_EXCEEDS_REQUIRED` qua HTTP thật | Không có | **Đã bổ sung** |
| Boundary `PRODUCT_SKU_REQUIRED` (create vs scan) | Chưa xác nhận rõ | **Đã làm rõ + test** |

Kết quả chạy bộ evidence liên quan (test mới + toàn bộ test outbound flow/reservation/audit/permission/balance có sẵn):
`php artisan test tests/Feature/Wwhn198OutboundPickScanDedupTest.php tests/Feature/OutboundDocumentFlowTest.php tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php tests/Feature/PostIssueConcurrencyBehaviorTest.php tests/Feature/PostIssueAuditLogTest.php tests/Feature/OutboundPermissionTest.php tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php`
→ **41/41 passed, 276 assertions**.

Kết quả chạy full suite (xác nhận không regression toàn cục, bao gồm cả các thay đổi song song của WWHN-201/202/204/205/207 đang nằm trong cùng working tree): `php artisan test` → **398 tests, 382 passed, 16 skipped (baseline có sẵn, Postgres-gated/môi trường, không liên quan tới thay đổi này), 0 failed, 4693 assertions**.

`vendor/bin/pint --test tests/Feature/Wwhn198OutboundPickScanDedupTest.php` → fail lần đầu (thứ tự `use trait`/import), đã `vendor/bin/pint` auto-fix, re-run xanh (`pint --test` → passed) và re-run PHPUnit xác nhận vẫn 10/10 sau khi Pint sửa.

### 9. File thay đổi

| File | Loại | Nội dung |
|---|---|---|
| `tests/Feature/Wwhn198OutboundPickScanDedupTest.php` | Mới | 10 test case HTTP end-to-end cho các nhánh lỗi resolve mã vật lý/validate scan chưa từng có coverage |

**Không sửa code sản xuất nào** — toàn bộ endpoint pick/scan, resolver mã vật lý, và availability soft-check tại scan đã đúng theo baseline từ trước; rà soát chỉ phát hiện gap coverage (mục 3, 6) và các điểm cần BA/PM xác nhận phạm vi (mục 2 quan sát `DUPLICATE_ITEM_MATCH`; mục 4 endpoint availability độc lập), không phải bug.

### 10. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (WWHN-198) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "Outbound pick backend" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE2 | **Done** — rà soát toàn bộ endpoint scan/unmatch, resolver mã vật lý, availability soft-check; lấp gap coverage bằng 10 test case mới | Mục 1, 3, 6 |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — permission `outbound.scan`, `If-Match` version guard, transaction bao trọn scan/unmatch, dedup guard (kế thừa WWHN-199) đã xác nhận đầy đủ, không cần sửa | Mục 1-3 |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — 8 error code mới được test xác nhận trả đúng, không tạo match/tăng scanned_qty khi bị chặn (test assert đếm row) | Mục 6 |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 10 test case HTTP mới; **không cần cập nhật OpenAPI/Postman** vì không đổi endpoint/request/response shape nào (chỉ bổ sung test cho error code đã có sẵn trong contract từ WWHN-195) | Mục 6, 9 |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần BA/PM xác nhận độc lập cho 2 open point** — xem mục 11 | — |

### 11. Blocker / Open point

| ID | Nội dung | Owner đề xuất | Disposition |
|---|---|---|---|
| BE2-PICK-OP-01 | Không có endpoint `GET .../availability` độc lập để FE query khả dụng tồn kho trước/trong khi quét — hiện tại chỉ biết `INSUFFICIENT_STOCK` phản ứng (reactive) tại từng lần scan. Cần BA/PM xác nhận AC gốc của WWHN-46/HN-OUT-002/HN-INV-001 có yêu cầu API pre-check độc lập hay không; nếu có, đây là tính năng mới cần task riêng, không thuộc phạm vi rà soát này | BA/PM | Mở |
| BE2-PICK-OP-02 | `DUPLICATE_ITEM_MATCH` (`CurrentBaselineStockService.php:593`) gần như không thể đạt tới qua luồng HTTP bình thường vì `assertNoDuplicateItemInDocument()` ở controller luôn chặn trước với `DUPLICATE_ITEM_IN_DOCUMENT`. Không phải bug (defense-in-depth hợp lệ) nhưng cần BE1/tech-lead xác nhận có giữ nguyên hay dọn dẹp | BE1/Tech Lead | Mở |
| BE2-PICK-OP-03 (kế thừa BE1-RESV-OP-01, WWHN-199) | `inventory_balances.reserved_qty` luôn 0, không phản ánh số lượng đang giữ chỗ bởi document DRAFT/SCANNING — có thể gây hiểu nhầm nếu FE hiển thị trực tiếp | BA/FE1 | Mở (kế thừa) |
| BE2-PICK-OP-04 (kế thừa BE1-RESV-OP-02/03, WWHN-199/205/218) | Unique index Postgres-level và race-condition đa connection thật cho dedup chưa verify trên môi trường Postgres thật | DevOps/QA | Mở (kế thừa) |

### 12. Định nghĩa Done — thực tế

#### BE2 technical completion (mục này)

Đạt: rà soát toàn bộ luồng "lấy hàng/quét mã" ở tầng endpoint + resolver mã vật lý + availability soft-check (task cha WWHN-46, phần chưa được WWHN-199 — vốn tập trung tầng reservation/concurrency — bao phủ). Xác nhận endpoint scan/unmatch, validate, permission, transaction, error handling đã đúng theo baseline, không phát hiện bug chức năng nào. Gap thực sự chỉ nằm ở test coverage cho 8 nhánh lỗi resolve/validate chưa từng lái qua HTTP (đã lấp bằng 10 test case mới) và 2 điểm cần BA/PM/tech-lead làm rõ phạm vi (endpoint availability độc lập; `DUPLICATE_ITEM_MATCH` defense-in-depth). Full suite 382/382 pass (16 skip baseline không liên quan) — không regression, không sửa code sản xuất nào.

#### Còn lại — không thuộc phạm vi tự nhận của BE2

- BA/PM xác nhận disposition cho endpoint availability độc lập (BE2-PICK-OP-01) — có cần triển khai hay giữ nguyên mô hình reactive hiện tại.
- BE1/Tech Lead xác nhận disposition cho `DUPLICATE_ITEM_MATCH` (BE2-PICK-OP-02).
- BA xác nhận disposition cho `reserved_qty` (BE2-PICK-OP-03, kế thừa từ WWHN-199/BE1-RESV-OP-01).
- DevOps/QA xác nhận unique index Postgres-level và race-condition test đa connection thật (BE2-PICK-OP-04, kế thừa từ WWHN-199/205/218).
- QA xác nhận evidence (mục 6-10) đáp ứng đúng Acceptance Criteria.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE2 tự ghi trong file này.
