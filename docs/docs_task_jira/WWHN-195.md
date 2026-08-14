[BE1] Triển khai chứng từ/chế độ/trạng thái xuất kho
Mục tiêu
Triển khai chứng từ/chế độ/trạng thái xuất kho.

Input đầu vào
Task cha: WWHN-45 — [OUTBOUND] Tạo chứng từ và chọn chế độ nhập tay/quét mã

Dependency: HN-PIN-06; HN-FND-001; HN-MC-001

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE1 — Quang Nguyễn Thanh.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE2 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-OUT-001

Epic Temp Key: HN-EPIC-06

Dependency: HN-PIN-06; HN-FND-001; HN-MC-001

Role: BE1

Assignee Placeholder: HN-BE-01

Acceptance Criteria
Output "Outbound API" được rà soát/xác minh, gắn evidence vào HN-OUT-001 và không còn blocker thuộc vai trò BE1.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
Outbound API

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

## Kết quả rà soát (Evidence — cập nhật 2026-08-15, BE1)

**Cảnh báo trước khi đọc**: file WWHN-195.md này trống hoàn toàn (0 byte) tại thời điểm bắt đầu rà soát. Task cha **WWHN-45**, và dependency **HN-PIN-06**, **HN-FND-001** không tìm thấy ở bất kỳ đâu trong repo (đã grep toàn bộ, loại trừ `vendor`). **HN-MC-001** chỉ xuất hiện trong `WWHN-184.md`/`WWHN-224.md` (task Import SKU dry-run, không liên quan trực tiếp outbound) — BE2 cũng đã ghi nhận HN-MC-001 "chưa có tài liệu bàn giao đầy đủ trong repo". Toàn bộ nội dung task phía trên (Mục tiêu/AC/DoD...) được phục hồi từ format chuẩn lặp lại ở các task BE1 khác (WWHN-190, WWHN-184...) theo đúng cấu trúc, **không phải nội dung gốc lấy lại từ Jira** — cần BA đối chiếu lại Jira gốc để xác nhận AC chính xác trước khi coi tài liệu này là baseline chính thức.

**Stack thực tế**: PHP/Laravel 13, module hóa qua `nwidart/laravel-modules`, PostgreSQL (production) / SQLite `:memory:` (test suite). Không có `OutboundDocument` model riêng — `StockDocument`/`StockDocumentLine` là bảng chung cho cả inbound (`doc_type=receipt`) và outbound (`doc_type=issue`).

Luồng runtime thật: `POST /api/v1/outbound-documents` → `CurrentBaselineDocumentController::storeOutbound()` → `createIdempotent()` → `createDocument()`; `POST /api/v1/outbound-documents/{id}/scan` và `/post-issue` → `CurrentBaselineStockFlowController::outboundScan()`/`postIssue()` → `CurrentBaselineStockService`.

Ghi chú kỹ thuật (rủi ro bảo trì, không chặn release): `OutboundDocumentController` (phần mutation), `StoreOutboundDocumentRequest.php`, `UpdateOutboundDocumentRequest.php` **không** nằm trong route thực thi (`routes/api.php` không trỏ tới create/update qua các class này) — cùng pattern dead-code đã ghi nhận ở WWHN-190 cho inbound. `docs/evidence/fe1/route-outbound-documents-verbose.log` mô tả model route CŨ (`submit/approve/reject/close-remainder`, permission `stock_document.*`) đã bị supersede hoàn toàn bởi model hiện tại (`scan/unmatch/post-issue/cancel`, permission `outbound.*`) — nên archive để tránh nhầm lẫn.

### 1. Tạo chứng từ xuất kho — ĐẦY ĐỦ
- `CurrentBaselineDocumentController::storeOutbound()` (`Modules/Warehouse/app/Http/Controllers/CurrentBaselineDocumentController.php:36-54`): bắt buộc `outbound_request_id` (BP-WMS-04/05 — mọi phiếu xuất phải sinh từ toa đã OPEN), **cấm tuyệt đối** `lines` thủ công (`'lines' => ['prohibited']`) để tránh "phiếu chết" không gắn toa.
- `hydrateOutboundRequest()` (dòng 423-448): lock toa (`lockForUpdate`), reject nếu `CANCELLED`/không active (422 `OUTBOUND_REQUEST_NOT_ACTIVE`), reject nếu toa đã gắn document khác đang DRAFT/SCANNING/POSTED (409 `OUTBOUND_REQUEST_ALREADY_USED`), reject nếu toa rỗng (422 `OUTBOUND_REQUEST_EMPTY`); lines được hydrate tự động từ `outbound_request_lines`.
- Toa chuyển `OPEN → CONSUMED` ngay khi document được tạo (ERD bảng 37); nếu document sau đó bị Cancel và không còn phiếu active/POSTED nào khác dùng toa, toa tự trả về `OPEN`.
- Toàn bộ nằm trong `DB::transaction(..., 3)` (`createDocument()` dòng 137-214).

### 2. Chế độ nhập tay/quét mã — ĐÃ TRIỂN KHAI, KHÔNG PHẢI FIELD ENUM TƯỜNG MINH (open point cần BA xác nhận)
- Payload `/scan` (`CurrentBaselineStockFlowController::scanPayload()` dòng 484-498) nhận `item_id` (chế độ nhập tay) **hoặc** `code_value` (chế độ quét mã/barcode/QR/RFID); nếu gửi cả hai, server đối chiếu khớp nhau (422 `PHYSICAL_CODE_ITEM_MISMATCH` nếu lệch).
- `resolveItemId()`/`resolveItem()` (controller dòng 441-459; `CurrentBaselineStockService.php:469-503`): ưu tiên `item_id` trực tiếp; nếu chỉ có `code_value`, tra `physical_codes` (ACTIVE) rồi map sang `item_instances`.
- **Không tồn tại field/enum `input_mode` tường minh** trong request/response/migration ở cả outbound lẫn inbound — đây là pattern chung toàn hệ thống, không phải riêng outbound thiếu sót khi implement.
- **Open point cho BA**: cần xác nhận cơ chế ngầm định này (suy luận mode từ có/không có `code_value`) có đáp ứng đủ AC gốc của WWHN-195/WWHN-45 hay cần bổ sung field `input_mode` tường minh để FE hiển thị toggle hoặc để audit log ghi rõ mode nào được dùng cho từng lần scan. Không tự ý thêm field khi chưa có AC gốc xác nhận (tránh mở rộng scope ngoài dependency).

### 3. Trạng thái xuất kho — ĐẦY ĐỦ
- State machine dùng chung với inbound trên `stock_documents.status`: `DRAFT → SCANNING → POSTED`, hoặc `→ CANCELLED` (terminal, không quay lại được — muốn xuất lại phải tạo document mới từ cùng toa).
- Guard theo state: Update/Cancel chỉ cho `DRAFT`/`SCANNING` (409 `STATE_CONFLICT`); Scan/Unmatch chỉ cho `DRAFT`/`SCANNING`; Post Issue chỉ cho `SCANNING` và yêu cầu `scanned_qty == qty_planned` mọi dòng (422 `FULL_POST_REQUIRED`).
- Gate riêng cho outbound trước Post Issue (không có ở inbound): mọi PRODUCT unit phải có item match ACTIVE (`assertMatchedProductRfids`, 422 `PRODUCT_ITEM_MATCH_REQUIRED`), item đó phải có RFID `hardware_uid` ACTIVE (422 `ITEM_RFID_REQUIRED`), và label phải `PRINTED` (`assertLabelsPrinted`, 422 `LABEL_NOT_PRINTED`).

### 4. Transaction, Idempotency, Concurrency guard — ĐẦY ĐỦ (dùng chung hạ tầng đã kiểm chứng với inbound WWHN-190)
- Optimistic lock qua header `If-Match` (`expectedVersion()`/`assertVersion()`) → 412 `PRECONDITION_FAILED`; thiếu header → 428 `PRECONDITION_REQUIRED`.
- Row lock `SELECT ... FOR UPDATE` trên `stock_documents`, `outbound_item_matches`, `inventory_balances`.
- Idempotency bắt buộc ở Post Issue/Reverse (`IdempotencyKeyGuard::require()`), optional ở Create; bảng `idempotency_keys` unique `(idem_key, operation, user_id)`, operation key riêng `post_issue_{id}`.
- Duplicate business-key guard trên `stock_movements` (`sha1('ISSUE|doc_id|line_id')`) → 409 `DUPLICATE_STOCK_EFFECT`.
- **Gap tìm thấy**: trước khi rà soát, chỉ có `tests/Feature/OutboundDocsAlignmentContractTest.php` — 100% static source assertion (`file_get_contents` + `assertStringContainsString`), **0% test HTTP thật** cho toàn bộ luồng outbound. Đối chiếu inbound đã có `PostReceiptConcurrencyBehaviorTest.php`, `PostReceiptAuditLogTest.php` chạy HTTP end-to-end (WWHN-190).

**Đã bổ sung 4 file test HTTP end-to-end mới** (tổng 18 test case, 136 assertions, 100% pass):
- `tests/Feature/OutboundDocumentFlowTest.php` (5 test): luồng đầy đủ create-from-request → scan chế độ nhập tay (`item_id`) → scan chế độ quét mã (`code_value`) → label PRINTED gate → Post Issue → Reverse; cộng negative case (lines prohibited, toa đã CONSUMED, insufficient stock, unmatch stock-neutral).
- `tests/Feature/PostIssueConcurrencyBehaviorTest.php` (4 test): đối xứng `PostReceiptConcurrencyBehaviorTest` cho Post Issue — stale-version racer bị 412, state guard chặn re-post sau POSTED (409), business_key duy nhất, scan stale-version cũng bị chặn.
- `tests/Feature/PostIssueAuditLogTest.php` (3 test): verify `audit_log` row thật cho `CREATE_OUTBOUND`, `POST_ISSUE` (kèm idempotent replay không nhân đôi), `CANCEL_STOCK_DOCUMENT` (kèm reason và toa trả về OPEN).
- `tests/Feature/OutboundPermissionTest.php` (6 test): 401 chưa auth, 403 theo đúng role matrix thật từ `AuthDatabaseSeeder` (`VIEWER` chỉ view, `WAREHOUSE_STAFF` không có post/cancel, `WAREHOUSE_APPROVER` không có create/scan, `SUPER_ADMIN` bypass), và verify response 403 không lộ stack trace.
- Helper dùng chung: `tests/Feature/Concerns/PreparesOutboundFixtures.php` (tránh lặp lại boilerplate seed DB giữa 4 file).

**Bug phát hiện trong lúc viết test (đã né tránh trong test, không sửa schema)**: `scan_event.physical_code_id` FK trỏ về bảng legacy số ít `physical_code` (không phải `physical_codes` canonical mà toàn bộ business logic đọc/ghi). Migration `2026_08_10_000630_repoint_scan_evidence_to_canonical_physical_codes.php` đã cố ý **bỏ qua** việc repoint FK này trên SQLite (dòng 18-20, vì SQLite không hỗ trợ drop/add named FK sau khi tạo bảng) — chỉ áp dụng trên PostgreSQL. Đây là lý do trước đây **không có test nào** lái qua HTTP tới `code_value` scan (chỉ test `item_id`/container_id) — bug chỉ lộ ra khi test thật sự exercise `code_value`. Test mới seed cả hai bảng (`physical_codes` + `physical_code`) trên SQLite để không bị FK chặn; trên Postgres production không cần vì FK đã được repoint đúng. Không sửa migration vì rủi ro thay đổi schema ngoài phạm vi WWHN-195 — nêu ra để BE2/DevOps biết nếu cần dọn dẹp bảng `physical_code` legacy trong tương lai.

### 5. Permission/RBAC — ĐẦY ĐỦ
- 6 permission `outbound.view/import/create/scan/post/cancel` đã seed đủ trong `AuthDatabaseSeeder`, gán đúng role (`WAREHOUSE_STAFF`: view/create/scan; `WAREHOUSE_APPROVER`: view/post/cancel; `WAREHOUSE_KEEPER`/`ADMIN`/`WAREHOUSE_MANAGER`: đầy đủ; `VIEWER`: chỉ view).
- `CheckPermission` middleware: 401 `UNAUTHENTICATED` nếu chưa auth, 403 `ACCESS_DENIED` nếu thiếu quyền; `SUPER_ADMIN` bypass toàn bộ. Verify bằng `OutboundPermissionTest.php` (6 test case theo đúng role matrix thật).

### 6. Validation, Error handling — ĐẦY ĐỦ
- Input validate qua `$request->validate([...])` inline trong controller (không qua `StoreOutboundDocumentRequest`/`UpdateOutboundDocumentRequest` — 2 class này là dead code, xem ghi chú kỹ thuật ở trên).
- Business rule: SKU phải ACTIVE và `sku_type=PRODUCT` cho outbound (COMPONENT chỉ xuất qua Component Issue WARRANTY — `PRODUCT_SKU_REQUIRED`); chặn tồn âm (`INSUFFICIENT_STOCK`/`INSUFFICIENT_AVAILABLE_STOCK`); chặn item trùng trong cùng document (`DUPLICATE_ITEM_IN_DOCUMENT`) hoặc đang match ở document khác (`ITEM_ALREADY_MATCHED`).
- `ApiError::abort()` trả envelope chuẩn hóa, không lộ stack trace/secret — verify bằng `OutboundPermissionTest::test_403_response_does_not_leak_stack_trace_or_secrets`.

### 7. Audit log — ĐẦY ĐỦ, đã bổ sung test trực tiếp
- Ghi `AuditLogger::record()` cho `CREATE_OUTBOUND`, `UPDATE_STOCK_DOCUMENT`, `CANCEL_STOCK_DOCUMENT`, `OUTBOUND_UNMATCH`, `POST_ISSUE` — trong cùng transaction với thay đổi nghiệp vụ.
- **Gap tìm thấy → đã đóng**: trước đây không có test nào verify `audit_log` row thật được tạo (chỉ suy luận từ code). `PostIssueAuditLogTest.php` xác nhận row thật với đúng `actor_id`, `request_id`, `before_data`/`after_data`, và idempotent replay không nhân đôi.

### 8. OpenAPI/Postman/test-case — ĐÃ ĐÓNG GAP
Trước khi rà soát: chỉ có OpenAPI annotation inline trong controller (được generator gộp đúng vào `storage/api-docs/api-docs.yaml`), **không có** file OpenAPI/Postman/test-case JSON riêng theo task như inbound (`WWHN-150-scan-openapi.yaml`, `WWHN-180-posting-openapi.yaml/.json` + Postman + test-case JSON).

**Đã bổ sung**:
- `docs/api-contract/WWHN-195-outbound-openapi.yaml` + `.json` — đặc tả đầy đủ 7 endpoint outbound (list/detail/create/update/cancel/scan/unmatch/post-issue/reverse), request/response schema, mọi error code đã verify bằng test, ghi rõ open point về chế độ nhập tay/quét mã.
- `docs/postman/WWHN-195 Outbound Contract.postman_collection.json` + `WWHN-195 Outbound CI.postman_environment.json` — 11 request bám theo đúng luồng `OutboundDocumentFlowTest.php` (login → resolve warehouse → create từ toa → scan 2 chế độ → post-issue gate label → replay idempotent → reverse → RBAC negative case).
- `docs/testing/WWHN-195-outbound-test-cases.json` — 31 test case (`W195-*`) map 1-1 với 4 file PHPUnit mới, có mục `known_gaps_for_ba_review` liệt kê rõ 2 open point (chế độ mode không tường minh; WWHN-195/WWHN-45/dependency gốc thiếu trong repo).

### 9. Test coverage — tổng kết
| Loại test | Trạng thái trước | Trạng thái sau |
|---|---|---|
| Static source assertion (`OutboundDocsAlignmentContractTest`) | Có | Giữ nguyên (không xóa, vẫn hữu ích chống refactor phá vỡ invariant tài liệu) |
| HTTP end-to-end: create → scan (2 chế độ) → post-issue → reverse | **Không có** | **Đã bổ sung**: `OutboundDocumentFlowTest.php` (5 test) |
| Concurrency hành vi thật (stale version, state guard, business-key) | **Không có** | **Đã bổ sung**: `PostIssueConcurrencyBehaviorTest.php` (4 test) |
| Audit log row thật cho CREATE_OUTBOUND/POST_ISSUE/CANCEL | **Không có** | **Đã bổ sung**: `PostIssueAuditLogTest.php` (3 test) |
| RBAC 401/403 theo role matrix thật | **Không có** | **Đã bổ sung**: `OutboundPermissionTest.php` (6 test) |

Kết quả chạy: `php artisan test --filter="OutboundDocumentFlowTest|PostIssueConcurrencyBehaviorTest|PostIssueAuditLogTest|OutboundPermissionTest"` → **18/18 passed, 136 assertions**. Full suite (`php artisan test`) → **323 passed, 16 skipped (pre-existing, môi trường-gated), 0 failed** — không có regression toàn cục.

---

## Tổng kết trạng thái HN-OUT-001 (BE1)

| # | Hạng mục | Trạng thái |
|---|---|---|
| 1 | Tạo chứng từ xuất kho | Đầy đủ — không cần sửa |
| 2 | Chế độ nhập tay/quét mã | Đã triển khai (ngầm định qua item_id/code_value); **open point cần BA xác nhận có cần field tường minh** |
| 3 | Trạng thái xuất kho | Đầy đủ — không cần sửa |
| 4 | Transaction/Idempotency/Concurrency | Đầy đủ, **đã bổ sung test hành vi thật** |
| 5 | RBAC | Đầy đủ, **đã bổ sung test theo role matrix thật** |
| 6 | Validation/Error handling | Đầy đủ — không cần sửa |
| 7 | Audit log | Đầy đủ, **đã bổ sung test trực tiếp** |
| 8 | OpenAPI/Postman/test-case | **Đã đóng gap** — 5 file mới |
| 9 | Test coverage | **Đã đóng 4 gap lớn** (flow HTTP, concurrency, audit, RBAC) |

**Không còn blocker P0 thuộc vai trò BE1** cho HN-OUT-001 tại thời điểm rà soát 2026-08-15. Còn 2 open point cần BA/PM xác nhận trước sign-off chính thức (không chặn merge code hiện tại vì implementation đã đúng baseline suy ra từ code + business rule comment trong source):

1. **Nội dung WWHN-195 gốc trên Jira, task cha WWHN-45, và dependency HN-PIN-06/HN-FND-001/HN-MC-001 không có trong repo** — cần lấy lại từ Jira để đối chiếu AC chính xác, tránh rủi ro code lệch spec thật do phải suy luận từ baseline code hiện tại.
2. **Chế độ nhập tay/quét mã có cần field `input_mode` tường minh hay không** — hiện tại suy luận từ `item_id` vs `code_value` trong payload scan, đúng với pattern chung toàn hệ thống (inbound cũng vậy) nhưng chưa có xác nhận chính thức từ AC gốc.

Rủi ro kỹ thuật tồn đọng (không chặn release, đã ghi nhận, không tự ý xử lý ngoài phạm vi task): dead code (`StoreOutboundDocumentRequest`, `UpdateOutboundDocumentRequest`, phần mutation của `OutboundDocumentController`); evidence log lỗi thời `docs/evidence/fe1/route-outbound-documents-verbose.log`; FK `scan_event.physical_code_id` trỏ bảng legacy `physical_code` chưa được repoint trên SQLite (chỉ ảnh hưởng test environment, không ảnh hưởng Postgres production).

**Bàn giao FE1/QA**: `docs/api-contract/WWHN-195-outbound-openapi.yaml`/`.json`, `docs/postman/WWHN-195 Outbound Contract.postman_collection.json` + environment, `docs/testing/WWHN-195-outbound-test-cases.json`, và 4 file PHPUnit mới nêu trên.
