[BE1] Triển khai giao dịch/idempotency/khóa đồng thời
Mục tiêu
Triển khai giao dịch/idempotency/khóa đồng thời.

Input đầu vào
Task cha: WWHN-43 — [INBOUND] Ghi nhận nhập kho (Post Receipt) atomic, idempotent và có audit

Dependency: HN-DEC-002; HN-DEC-005; HN-IN-002

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE1 — Quang Nguyễn Thanh.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE2 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker  4phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-IN-003

Epic Temp Key: HN-EPIC-05

Dependency: HN-DEC-002; HN-DEC-005; HN-IN-002

Role: BE1

Assignee Placeholder: HN-BE-01

Acceptance Criteria
Output "ghi nhận nhập kho (Post Receipt) backend" được rà soát/xác minh, gắn evidence vào HN-IN-003 và không còn blocker thuộc vai trò BE1.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
ghi nhận nhập kho (Post Receipt) backend

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

## Kết quả rà soát (Evidence — cập nhật 2026-08-14, BE1)

**Stack thực tế**: PHP/Laravel 11, module hóa qua `nwidart/laravel-modules`. Luồng runtime chính: `POST /api/v1/inbound-documents/{id}/post-receipt` → `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController::postReceipt()` → `postDocument()` → `Modules\Warehouse\Services\CurrentBaselineStockService::postStockDocument()`.

Ghi chú kỹ thuật: `Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php` và `Modules/Warehouse/app/Services/StockDocumentService.php` **không** nằm trong route thực thi (`routes/api.php` không trỏ tới) — chỉ giữ lại để lưu OpenAPI annotation. Toàn bộ logic atomic/idempotent/concurrency thật nằm ở `CurrentBaselineStockFlowController` + `CurrentBaselineStockService`. Rủi ro bảo trì cần lưu ý: không sửa nhầm 2 file song song không active.

### 1. Transaction (atomic) — ĐẦY ĐỦ
- `CurrentBaselineStockService::postStockDocument()` (`Modules/Warehouse/app/Services/CurrentBaselineStockService.php:110-208`): toàn bộ post-receipt (lock document, lock line, validate full-post, mutate balance, ghi stock_movements, cập nhật status, ghi document_status_history, posting_event, audit) chạy trong `DB::transaction(..., 3)`.
- `CurrentBaselineStockFlowController::postDocument()` (`Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:200-252`) bọc thêm 1 transaction ngoài (bump version trước khi gọi service).
- `IdempotencyStore::run()` (`app/Support/IdempotencyStore.php:173-227`) bọc toàn bộ callback nghiệp vụ trong transaction ngoài cùng — đảm bảo stock effect và idempotency response snapshot commit/rollback cùng nhau.
- Verify bằng test: `Wwhn180IdempotencyAtomicityTest::test_business_write_rolls_back_and_lease_is_removed_when_callback_fails`.

### 2. Idempotency — ĐẦY ĐỦ
- Header `Idempotency-Key` bắt buộc (`app/Support/IdempotencyKeyGuard.php:19-46`), 428 nếu thiếu, 422 nếu sai định dạng.
- `IdempotencyStore` (bảng `idempotency_keys`, unique `(idem_key, operation, user_id)`): cùng key + cùng payload → replay response cũ; cùng key + khác payload → 409 `IDEMPOTENCY_ERROR`; lease hết hạn mới cho takeover.
- Lớp phòng thủ phụ: bảng `posting_event` ghi trong `CurrentBaselineStockService::recordPostingEvent()` (dòng 923-947).
- Test HTTP end-to-end: `FoundationInboundPutawayReadinessTest.php:174-197` (post 2 lần cùng key → lần 2 `Idempotent-Replay: true`, không tạo thêm movement). Test engine-level: `Wwhn180IdempotencyAtomicityTest.php` (same-key/same-payload replay, same-key/khác-payload → 409, canonical JSON hash, rollback khi lỗi).

### 3. Concurrency guard — ĐẦY ĐỦ (implementation), đã bổ sung test hành vi thật
Nhiều lớp khóa kết hợp trong `CurrentBaselineStockService.php` và `CurrentBaselineStockFlowController.php`:
- Optimistic lock qua header `If-Match` (`expectedVersion()`/`assertVersion()`, controller dòng 510-533) → 412 `PRECONDITION_FAILED` khi version lệch.
- Row lock `SELECT ... FOR UPDATE` trên `stock_documents`, `stock_document_lines`, `inventory_balances` (`lockDocument()` dòng 893-905, `mutateBalance()` dòng 785-891).
- Version check kép ngay trên `inventory_balances` (dòng 850-861) → 409 `VERSION_CONFLICT` nếu update 0 row.
- Duplicate business-key guard trên `stock_movements` (dòng 803-805) → 409 `DUPLICATE_STOCK_EFFECT`.

**Gap tìm thấy**: `tests/Feature/StockDocumentConcurrencyGuardTest.php` trước đây chỉ là "static source assertion" (`assertStringContainsString('lockForUpdate()', ...)` đọc trực tiếp nội dung file PHP), không verify hành vi runtime khi có race thật.

**Đã bổ sung**: `tests/Feature/PostReceiptConcurrencyBehaviorTest.php` — 3 test case lái qua HTTP thật:
- `test_stale_version_racer_is_rejected_after_winner_commits_and_stock_effect_applies_once`: writer B giữ `If-Match` cũ (đọc trước khi writer A commit) bị từ chối 412 `PRECONDITION_FAILED` sau khi A thắng; xác nhận `stock_movements` và `inventory_balances.on_hand_qty` chỉ được cập nhật đúng 1 lần.
- `test_repost_with_current_version_after_already_posted_is_rejected_by_state_guard`: writer thứ 2 dùng version mới (khác idempotency key, không phải replay) vẫn bị chặn bởi state guard 409 `STATE_CONFLICT`, không tạo thêm movement — chứng minh guard là defense-in-depth, không chỉ dựa vào version.
- `test_stock_movement_business_key_is_unique_per_document_line_after_post`: xác nhận bất biến `business_key` duy nhất trên `stock_movements` — nền tảng của guard `DUPLICATE_STOCK_EFFECT`.

Lưu ý: test suite dùng SQLite `:memory:` (`phpunit.xml`), là kết nối đơn tiến trình nên không thể mô phỏng 2 DB connection song song thật (multi-thread/multi-process). Test mới verify **hành vi/outcome** của guard qua HTTP calls tuần tự mô phỏng đúng trạng thái mà 1 racer thua cuộc sẽ quan sát được (stale version, state đã đổi), thay vì chỉ assert chuỗi trong source code như trước.

### 4. Permission/RBAC — ĐẦY ĐỦ
- Route `Modules/Warehouse/routes/api.php:66` gắn middleware `permission:inbound.post`.
- `CheckPermission` (`Modules/Auth/app/Http/Middleware/CheckPermission.php:17-41`): 401 `UNAUTHENTICATED` nếu chưa auth, 403 `ACCESS_DENIED` nếu thiếu quyền. `SUPER_ADMIN` bypass.
- Middleware riêng `EnsureCanonicalInboundWarehouse` chặn kho ngoài baseline (`KHO-TONG-HN`).

### 5. Validation — ĐẦY ĐỦ
- Input: `StoreInboundDocumentRequest.php:14-25` (`qty_planned` min:1, `product_id` phải tồn tại).
- Business rule: chỉ document `SCANNING` mới được Post (409 `STATE_CONFLICT`); full-post exact-match từng dòng (422 `FULL_POST_REQUIRED`); chặn tồn âm (`INSUFFICIENT_STOCK`/`INSUFFICIENT_AVAILABLE_STOCK`/`INVALID_QUANTITY`); item bắt buộc cho PRODUCT/serial-tracked SKU; chặn item nhận trùng 2 document (`ITEM_ALREADY_RECEIVING`).

### 6. Audit log — ĐẦY ĐỦ, đã bổ sung test trực tiếp
- `CurrentBaselineStockService.php:191-199` ghi `AuditLogger::record(action: 'POST_RECEIPT', ...)` trong cùng transaction với stock effect.
- `AuditLogger::record()` (`Modules/AuditLog/app/Services/AuditLogger.php`) có policy `outbox_required`/`fail_closed`: lỗi ghi `audit_log` → fallback `audit_outbox`; lỗi cả hai → throw để không tạo trạng thái nghiệp vụ thiếu audit trail. Có `sanitize()` redact sensitive keys.

**Gap tìm thấy**: chưa có test HTTP nào assert trực tiếp `audit_log` row được tạo sau khi gọi `/post-receipt` (chỉ có test static suy luận từ code, và test audit cho luồng login).

**Đã bổ sung**: `tests/Feature/PostReceiptAuditLogTest.php` — verify sau khi post-receipt thành công có đúng 1 row `audit_log` với `action=POST_RECEIPT`, `object_type=stock_document`, `actor_id` đúng user, `request_id` không null, `before_data.status=SCANNING`, `after_data.status=POSTED` kèm `movement_ids`; và xác nhận idempotent replay (cùng key) **không** tạo thêm audit row thứ hai.

### 7. Error handling — ĐẦY ĐỦ
- `ApiError::abort()` (`app/Support/ApiError.php:16-40`) trả envelope chuẩn hóa, không lộ stack trace.
- `bootstrap/app.php:43-129`: exception handler chuẩn hóa 401/404/405/409 (unique constraint), fallback mọi `Throwable` khác → 500 `INTERNAL_SERVER_ERROR` chung chung, không lộ `$e->getMessage()`.
- Test xác nhận: `SecurityRbacAuditNegativeTest.php` (`assertJsonMissingPath('trace')`/`'stack'`).
- Mọi lỗi trong transaction tự động rollback; lease idempotency `processing` bị xóa khi callback lỗi để cho phép retry.

### 8. Test coverage — ĐÃ ĐÓNG GAP
| Loại test | Trạng thái trước | Trạng thái sau |
|---|---|---|
| Idempotency (engine + HTTP end-to-end) | Có | Có (không đổi) |
| Transaction rollback | Có | Có (không đổi) |
| Concurrency — static source assertion | Có (yếu) | Giữ nguyên (không xóa, vẫn hữu ích chống refactor xóa nhầm `lockForUpdate()`) |
| Concurrency — hành vi HTTP thật (stale version, state guard, business-key uniqueness) | **Không có** | **Đã bổ sung**: `tests/Feature/PostReceiptConcurrencyBehaviorTest.php` (3 test case) |
| Audit log row cho riêng `POST_RECEIPT` | **Không có** | **Đã bổ sung**: `tests/Feature/PostReceiptAuditLogTest.php` (2 test case, gồm cả kiểm tra replay không duplicate) |

Kết quả chạy: `php artisan test tests/Feature/PostReceiptAuditLogTest.php tests/Feature/PostReceiptConcurrencyBehaviorTest.php tests/Feature/FoundationInboundPutawayReadinessTest.php tests/Feature/StockDocumentConcurrencyGuardTest.php tests/Feature/Wwhn180IdempotencyAtomicityTest.php` → **18/18 passed, 157 assertions**. Full suite (`php artisan test`) chạy lại để xác nhận không có regression toàn cục.

### 9. API docs (OpenAPI/Postman) — ĐẦY ĐỦ, không cần sửa
- OpenAPI annotation `InboundDocumentController.php:198-220` (mô tả đầy đủ headers `If-Match`/`Idempotency-Key`, response 200/401/403/404/409/412/422/428).
- `docs/api-contract/WWHN-180-posting-openapi.json`/`.yaml`, Postman collections `docs/postman/WWHN-180 *.postman_collection.json`, test-case JSON `docs/testing/WWHN-180-posting-test-cases.json` — không có thay đổi contract nên không cần cập nhật lại (chỉ bổ sung test, không đổi request/response shape).

---

## Tổng kết trạng thái HN-IN-003 (BE1)

| # | Hạng mục | Trạng thái |
|---|---|---|
| 1 | Transaction | Đầy đủ — không cần sửa |
| 2 | Idempotency | Đầy đủ — không cần sửa |
| 3 | Concurrency lock | Đầy đủ, **đã bổ sung test hành vi thật** |
| 4 | RBAC | Đầy đủ — không cần sửa |
| 5 | Validation | Đầy đủ — không cần sửa |
| 6 | Audit log | Đầy đủ, **đã bổ sung test trực tiếp** |
| 7 | Error handling | Đầy đủ — không cần sửa |
| 8 | Test coverage | **Đã đóng 2 gap** (concurrency behavior + audit log) |
| 9 | API docs | Đầy đủ, chất lượng cao — không cần sửa |

**Không còn blocker P0/P1 thuộc vai trò BE1** cho HN-IN-003 tại thời điểm rà soát 2026-08-14. Rủi ro kỹ thuật tồn đọng (không chặn release): tồn tại 2 bộ code trùng tên nghiệp vụ (`StockDocumentController`/`StockDocumentService` không active vs `CurrentBaselineStockFlowController`/`CurrentBaselineStockService` active) — khuyến nghị dọn dẹp code chết trong task riêng để tránh dev sau sửa nhầm file không có tác dụng runtime; không thuộc phạm vi WWHN-190 nên không tự ý xử lý ở đây.

**Bàn giao FE1/QA**: contract không đổi (không cần cập nhật OpenAPI/Postman). Evidence bổ sung cho QA: 2 file test mới nêu trên + kết quả chạy pass ở trên.
