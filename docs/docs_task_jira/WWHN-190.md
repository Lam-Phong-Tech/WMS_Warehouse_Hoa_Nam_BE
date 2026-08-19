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

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker  phải có owner, ETA và ảnh hưởng.

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

**Stack thực tế**: PHP ^8.3 / Laravel v13.23.0 (pin tại composer.lock, không phải Laravel 11 như bản ghi 2026-08-14), module hóa qua `nwidart/laravel-modules`. Luồng runtime chính: `POST /api/v1/inbound-documents/{id}/post-receipt` → `Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController::postReceipt()` → `postDocument()` → `Modules\Warehouse\Services\CurrentBaselineStockService::postStockDocument()`.

Ghi chú kỹ thuật: `Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php` và `Modules/Warehouse/app/Services/StockDocumentService.php` **không** nằm trong route thực thi (`routes/api.php` không trỏ tới) — chỉ giữ lại để lưu OpenAPI annotation. Toàn bộ logic atomic/idempotent/concurrency thật nằm ở `CurrentBaselineStockFlowController` + `CurrentBaselineStockService`. Rủi ro bảo trì cần lưu ý: không sửa nhầm 2 file song song không active.

### 1. Transaction (atomic) — ĐẦY ĐỦ
- `CurrentBaselineStockService::postStockDocument()` (`Modules/Warehouse/app/Services/CurrentBaselineStockService.php:110-208`): toàn bộ post-receipt (lock document, lock line, validate full-post, mutate balance, ghi stock_movements, cập nhật status, ghi document_status_history, posting_event, audit) chạy trong `DB::transaction(..., 3)`.
- `CurrentBaselineStockFlowController::postDocument()` (`Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php:200-252`) bọc thêm 1 transaction ngoài (bump version trước khi gọi service).
- `IdempotencyStore::run()` (`app/Support/IdempotencyStore.php:173-227`) bọc toàn bộ callback nghiệp vụ trong transaction ngoài cùng — đảm bảo stock effect và idempotency response snapshot commit/rollback cùng nhau.
- Verify bằng test: `Wwhn180IdempotencyAtomicityTest::test_business_write_rolls_back_and_lease_is_removed_when_callback_fails`.

### 2. Idempotency — ĐẦY ĐỦ
- Header `Idempotency-Key` bắt buộc (`app/Support/IdempotencyKeyGuard.php:19-46`), 428 nếu thiếu, 422 nếu sai định dạng.
- `IdempotencyStore` (bảng `idempotency_keys`, unique `(idem_key, operation, user_id)`): cùng key + cùng payload → replay response cũ; cùng key + khác payload → 409 `IDEMPOTENCY_CONFLICT`; lease hết hạn mới cho takeover.
- Lớp phòng thủ phụ: bảng `posting_event` ghi trong `CurrentBaselineStockService::recordPostingEvent()` (dòng 923-947).
- Test HTTP end-to-end: `FoundationInboundPutawayReadinessTest.php:174-197` (post 2 lần cùng key → lần 2 `Idempotent-Replay: true`, không tạo thêm movement). Test engine-level: `Wwhn180IdempotencyAtomicityTest.php` (same-key/same-payload replay, same-key/khác-payload → 409, canonical JSON hash, rollback khi lỗi).

### 3. Concurrency guard — ĐẦY ĐỦ (implementation), đã bổ sung test hành vi thật
Nhiều lớp khóa kết hợp trong `CurrentBaselineStockService.php` và `CurrentBaselineStockFlowController.php`:
- Optimistic lock qua header `If-Match` (`expectedVersion()`/`assertVersion()` trong CurrentBaselineStockFlowController; thiếu header → 428 `PRECONDITION_REQUIRED`, sai định dạng → 422 `INVALID_VERSION`) → 412 `STALE_VERSION` khi version lệch.
- Row lock `SELECT ... FOR UPDATE` trên `stock_documents`, `stock_document_lines`, `inventory_balances` (`lockDocument()`, `mutateBalance()`).
- Version check kép ngay trên `inventory_balances` (`mutateBalance()`, update có điều kiện `where version = ?`) → 409 `CONCURRENCY_CONFLICT` nếu update 0 row.
- Duplicate business-key guard trên `stock_movements` (`business_key` = sha1(RECEIPT|doc|line)) → 409 `DUPLICATE_STOCK_EFFECT`.

**Gap tìm thấy**: `tests/Feature/StockDocumentConcurrencyGuardTest.php` trước đây chỉ là "static source assertion" (`assertStringContainsString('lockForUpdate()', ...)` đọc trực tiếp nội dung file PHP), không verify hành vi runtime khi có race thật.

**Đã bổ sung**: `tests/Feature/PostReceiptConcurrencyBehaviorTest.php` — 3 test case lái qua HTTP thật:
- `test_stale_version_racer_is_rejected_after_winner_commits_and_stock_effect_applies_once`: writer B giữ `If-Match` cũ (đọc trước khi writer A commit) bị từ chối 412 `STALE_VERSION` sau khi A thắng; xác nhận `stock_movements` và `inventory_balances.on_hand_qty` chỉ được cập nhật đúng 1 lần.
- `test_repost_with_current_version_after_already_posted_is_rejected_by_state_guard`: writer thứ 2 dùng version mới (khác idempotency key, không phải replay) vẫn bị chặn bởi state guard 409 `STATE_CONFLICT`, không tạo thêm movement — chứng minh guard là defense-in-depth, không chỉ dựa vào version.
- `test_stock_movement_business_key_is_unique_per_document_line_after_post`: xác nhận bất biến `business_key` duy nhất trên `stock_movements` — nền tảng của guard `DUPLICATE_STOCK_EFFECT`.

Lưu ý (đã được thay thế ở vòng R2 — xem §R2 cuối tài liệu): trước đây bằng chứng concurrency chỉ chạy trên SQLite `:memory:` (một kết nối/tiến trình) nên không thể chứng minh race thật. Từ R2, bằng chứng race nằm ở `scripts/wwhn190-postgres-concurrency.php`: 5 kịch bản, mỗi kịch bản 2 OS process với 2 backend PostgreSQL riêng, có mẫu `pg_locks`/`pg_stat_activity` và ảnh chụp DB trước/sau.

### 4. Permission/RBAC — ĐẦY ĐỦ
- Route `Modules/Warehouse/routes/api.php` gắn middleware `permission:inbound.post` + `EnforceInboundWarehouseScope` cho `post-receipt`.
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

### 10. Xác minh trên PostgreSQL thật + trace SHA (cập nhật 2026-08-18, remediation cho review 62/100 CHƯA ĐẠT)

Root cause của DEV190-P1-02R ("Verify locks trên target PostgreSQL, không dùng SQLite sequential simulation làm closure"): `phpunit.xml` mặc định `DB_CONNECTION=sqlite`/`DB_DATABASE=:memory:`, nên **mọi** lần `php artisan test` trước đây trong lịch sử task này (kể cả các lần review trước) đều chạy trên SQLite in-memory, chưa từng chạy thật trên Postgres — kể cả khi mục 3 ở trên đã tự nhận diện gap này ("test suite dùng SQLite `:memory:`... không thể mô phỏng 2 DB connection song song thật").

**a) Chạy test thật trên PostgreSQL 16** (container `wms_warehouse_hoa_nam_be-postgres-1`, image `postgres:16-alpine`, đã chạy sẵn qua `docker-compose.yml`, cổng 5432):
- Tạo database test riêng `wms_hoanam_testing` (không đụng `wms_hoanam` — DB dev có dữ liệu thật): `CREATE DATABASE wms_hoanam_testing OWNER postgres;`.
- Thêm `phpunit.pgsql.xml` (working-tree only, copy từ `phpunit.xml`, override `DB_CONNECTION=pgsql force=true`, `DB_HOST=postgres`, `DB_PORT=5432`, `DB_DATABASE=wms_hoanam_testing`, `DB_USERNAME=postgres`, `DB_PASSWORD=postgres`) — không sửa `phpunit.xml` gốc để giữ nguyên baseline SQLite cho CI hiện có.
- Lệnh chạy: `docker exec wms_warehouse_hoa_nam_be-backend-1 php artisan test --configuration=phpunit.pgsql.xml tests/Feature/PostReceiptConcurrencyBehaviorTest.php tests/Feature/PostReceiptAuditLogTest.php tests/Feature/StockDocumentConcurrencyGuardTest.php tests/Feature/Wwhn191BalanceMovementProjectionTest.php tests/Feature/Wwhn180IdempotencyAtomicityTest.php`
- Kết quả: **24 tests passed, 147 assertions**, ~39s. Xác nhận driver thật bằng `php artisan db:show` (env tương ứng): `Connection: pgsql`, `Database: wms_hoanam_testing`, `Host: postgres`, `PostgreSQL 16.14` — không phải SQLite.

**b) Nhận định sequential-simulation vs real-concurrent cho từng file (đọc code, không suy đoán)**:
- `PostReceiptConcurrencyBehaviorTest.php` (docblock dòng 15-25 của chính file): tự nhận là **mô phỏng tuần tự trong 1 process** ("races are simulated by holding a stale version/state and replaying the second writer's request against it"). Chuyển sang chạy trên Postgres **không** thay đổi bản chất này — đây vẫn là 1 HTTP test client, 1 process, gọi tuần tự 2 request, không phải 2 connection/process thật. Driver Postgres chỉ xác nhận là code/schema tương thích Postgres, không tự chứng minh lock hoạt động dưới tải song song thật.
- `StockDocumentConcurrencyGuardTest.php` (docblock dòng 7-19 của chính file): tự nhận rõ ràng nhất — **"ARCHITECTURE GUARD — NOT concurrency evidence"**, chỉ đọc source code assert chuỗi (`lockForUpdate()`, `STALE_VERSION`...) tồn tại, không thực thi transaction nào, không mở connection cạnh tranh nào. Docblock này tự trỏ tới `Wwhn219RuntimeConcurrencyTest`/`scripts/wwhn219-runtime-concurrency.php` là nơi "Executed proof" thật sự nằm.
- `Wwhn180IdempotencyAtomicityTest.php`: gọi trực tiếp `IdempotencyStore::handle()` trong 1 process/transaction — đúng phạm vi của nó (đúng-sai của logic idempotency-store, không phải race 2 connection), không claim là bằng chứng concurrency đa connection.

**c) Bằng chứng 2-connection/2-process thật đã tồn tại sẵn trong repo (phạm vi WWHN-219) — đã chạy lại trên `wms_hoanam_testing`**: `scripts/wwhn219-runtime-concurrency.php` + `tests/Feature/Wwhn219RuntimeConcurrencyTest.php` spawn **2 tiến trình OS thật** (`proc_open`), mỗi tiến trình có `pg_backend_pid()` Postgres riêng, đồng bộ qua filesystem barrier, sample `pg_locks`/`pg_stat_activity` trong lúc tranh chấp, rồi assert ledger/balance/audit đã commit. Đã `migrate:fresh --seed --force` trên `wms_hoanam_testing` rồi chạy trực tiếp: `docker exec ... php scripts/wwhn219-runtime-concurrency.php storage/app/wwhn-190-191-evidence` (env `WWHN219_LOCK_HOLD_MS=1500`).
  - **Scenario `concurrent_post_receipt`** (2 request `post-receipt` đua trên cùng 1 document — chính là race của WWHN-190): **PASS** toàn bộ check — `two_distinct_backend_connections=true` (PID 2 backend khác nhau), `real_contention_proven=true` (quan sát được lock wait thật qua `pg_locks`), `exactly_one_success=true`, `loser_rejected_not_errored=true`, `balance_increased_exactly_once=true`, `single_movement_written=true`, `single_audit_entry=true`, `document_posted=true`. Đây là bằng chứng 2-connection thật trên Postgres thật cho đúng route `post-receipt`, đóng yêu cầu của DEV190-P1-02R ở mức thực thi (không chỉ code review).
  - **Scenario `shared_balance_lost_update`** (2 document khác nhau, cùng warehouse+SKU, đua trên cùng row `inventory_balances`): **PASS** — `no_lost_update=true`, `balance_version_advanced_twice=true` — chứng minh row lock trên `inventory_balances` cộng dồn đúng dưới tải song song thật, liên quan trực tiếp WWHN-191 (balance = ledger sau race thật).
  - **Scenario `concurrent_reversal`**: phát hiện lần đầu **FAIL** — cả 2 worker đều lỗi `SQLSTATE[22001]: String data, right truncated ... value too long for type character varying(50)` từ trigger Postgres `hn_erd_normalize_canonical_posting_events()` khi ghi cột `operation` của bảng `posting_events`/`posting_event` (giá trị thật `reverse_stock_document_<uuid>` dài hơn 50 ký tự). Đây là **lỗi thật trên Postgres**, không phải do race — lỗi này xảy ra ngay cả khi post reversal tuần tự (không cần 2 connection để trigger), SQLite không phát hiện được vì SQLite không enforce độ dài `varchar`. Ban đầu ghi nhận "ngoài phạm vi WWHN-190/191", nhưng vì lỗi này chặn thẳng AC "Failure giữa chừng rollback toàn bộ; không có half-post" của WWHN-43 (reversal là 1 phần luồng Post Receipt) và chặn chính scenario `concurrent_post_receipt`-kề-cận mà DEV190-P1-02R yêu cầu verify, đã root-cause và sửa trong remediation này — xem mục 11.
  - Bằng chứng này hiện **chỉ chạy trong CI ở workflow "WWHN-219 Final Gate"** (Phase 3, `.github/workflows/wwhn-219-final-gate.yml`), gate này thuộc phạm vi WWHN-219 và hiện đang **FAIL trên `m1` HEAD `94abebe1`** vì lý do khác (ngoài phạm vi remediation này) — không có CI gate riêng chạy full-suite + runtime-concurrency scoped cho WWHN-190/191. Đây là gap thật, ghi ở "Gap còn lại" cuối tài liệu.

**d) Trace SHA `0accacb` → `4c78d5a` → HEAD `94abebe1`** (bằng chứng cho Tech Lead formal PR=N/A disposition):
- `0accacb82d3141e98c34411ca8d3cac4695093ad` (2026-08-14 23:51:04 +0700) là ancestor của HEAD hiện tại (`git merge-base --is-ancestor 0accacb HEAD` → true).
- `4c78d5a587d9d7832c15c631461d9d133d82f145` ("gỡ cờ 6", 2026-08-17 16:29:07 +0700) là ancestor của HEAD hiện tại (`git merge-base --is-ancestor 4c78d5a HEAD` → true).
- Chuỗi commit trung gian liên quan trực tiếp file post-receipt (`git log --oneline 0accacb..HEAD -- Modules/Warehouse/app/Http/Controllers/CurrentBaselineStockFlowController.php Modules/Warehouse/app/Services/CurrentBaselineStockService.php app/Support/IdempotencyStore.php app/Http/Middleware/CurrentBaselineIfMatch.php tests/Feature/PostReceiptConcurrencyBehaviorTest.php tests/Feature/PostReceiptAuditLogTest.php tests/Feature/StockDocumentConcurrencyGuardTest.php tests/Feature/Wwhn180IdempotencyAtomicityTest.php`): `4c78d5a` ("gỡ cờ 6") → `f0974b5` ("hoàn thiện xong docx, 6 luồng") → `c7856d9` ("gỡ cờ 6") → `eb8a298` ("fix: khắc phục lỗi tương thích SQLite và test lỗi thời lộ ra sau merge m1-be-2") → `7a78477` ("hoan thien m1"). Toàn bộ 84 commit giữa `0accacb..HEAD` đều là ancestor hợp lệ của `m1`, không có commit nào bị revert/orphan — chuỗi liên tục, không đứt gãy.
- Kết luận cho Tech Lead: `94abebe16cb4517859fe47b06b44620f782d0689` (HEAD `m1` hiện tại) chứa đầy đủ và mới hơn cả 2 SHA `0accacb`/`4c78d5a` từng được review trước trích dẫn — hợp lệ làm baseline PR=N/A (không có PR riêng, code đã vào thẳng `m1`).

**e) Dead code (mục D remediation, P2) — đính chính chi tiết hơn ghi chú gốc**: đã grep lại `Modules/Warehouse/routes/api.php` và đọc source thật, phát hiện claim gốc ở "Ghi chú kỹ thuật" đầu tài liệu ("`InboundDocumentController.php` không nằm trong route thực thi") **không hoàn toàn chính xác** — cần đính chính:
  - `InboundDocumentController::index()`/`show()` (thực chất định nghĩa trong trait dùng chung `Concerns/InteractsWithStockDocuments.php`) **CÓ** route thật đang sống: `GET /api/v1/inbound-documents` và `GET /api/v1/inbound-documents/{id}` (`Modules/Warehouse/routes/api.php:66,69`). Đây là 2 route đọc (list/detail), không phải write.
  - Mọi hành động **ghi** (`POST /inbound-documents` tạo phiếu, `/boxes`, `/scan`, `/post-receipt`, `/reverse`...) đều route tới `CurrentBaselineDocumentController`/`CurrentBaselineStockFlowController`, **không** đi qua `InboundDocumentController` — đúng như docblock ngay trong chính file `InboundDocumentController.php` tự ghi: "Runtime write actions được route tới CurrentBaselineDocumentController / CurrentBaselineStockFlowController; các annotation dưới đây chỉ mô tả contract thật để RouteSyncProcessor giữ schema chi tiết". Các OpenAPI annotation cho create/scan/post/reverse trong file này thuần tuý là tài liệu (giữ schema cho generator), không có handler method tương ứng nào được route tới.
  - `Modules/Warehouse/app/Services/StockDocumentService.php`: **inject được (constructor `InboundDocumentController::__construct(StockDocumentService $documentService)`) nhưng KHÔNG được gọi** ở bất kỳ đâu trong runtime path thật — `index()`/`show()` (trait `InteractsWithStockDocuments`) query thẳng model `StockDocument::query()`, không đụng tới `$this->service()`. Xác nhận qua `grep -rln StockDocumentService` toàn bộ `app/`+`Modules/`: chỉ còn được tham chiếu bởi chính nó và các controller (`InboundDocumentController`, `OutboundDocumentController`, `TransferController`) theo cùng pattern inject-nhưng-không-gọi cho action ghi. Kết luận: `StockDocumentService` là dead code cho phần logic ghi (create/scan/post/reverse) — vẫn bị Laravel container khởi tạo mỗi request (chi phí nhỏ, không phải bug chức năng) nhưng không có luồng runtime nào thực thi các method ghi của nó.
  - Không xoá code này (đúng ràng buộc remediation), chỉ ghi nhận đính chính vào evidence.

**f) So sánh `m1-be-2` (read-only, không checkout/merge)**: `git fetch origin m1-be-2` (tip `6081649`) rồi `git log HEAD..origin/m1-be-2 --oneline` → **rỗng** (không có commit nào trên `m1-be-2` mà `m1` HEAD chưa có), và `git merge-base HEAD origin/m1-be-2` → `60816499066f9f493596e326dc41116ef32f5c4b` — **trùng chính xác với tip của `m1-be-2`**, tức `m1-be-2` là **ancestor thật sự** của `m1` HEAD hiện tại (`m1-be-2 ⊂ m1`, không phải 2 nhánh phân kỳ). `git diff --stat HEAD origin/m1-be-2 -- Modules/Warehouse/app/Http/Controllers/InventoryBalanceController.php tests/Feature/Wwhn191BalanceMovementProjectionTest.php` → `InventoryBalanceController.php` trên `m1-be-2` thiếu 396 dòng so với `m1` (bản cũ hơn, chưa có fix Bug 1/2 của WWHN-191), `Wwhn191BalanceMovementProjectionTest.php` **không tồn tại** trên `m1-be-2` (thiếu toàn bộ 306 dòng — nhánh này dừng ở commit `83ed34e`, trước cả commit `46249187` tạo ra WWHN-190/191). Kết luận cho Tech Lead: `m1` là nhánh canonical duy nhất chứa đầy đủ (và mới hơn) mọi thứ `m1-be-2` có cho phạm vi WWHN-190/191 — khớp với việc `.github/workflows/wwhn-219-final-gate.yml` đã liệt kê `m1-be-2` vào `LEGACY_SOURCES_EXCLUDED.txt` (nguồn evidence bị loại, không phải canonical).

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

### 11. Root-cause fix: `posting_events.posting_action` overflow trên PostgreSQL (2026-08-18)

**Root cause** (không phải cột quá hẹp): trigger `hn_erd_normalize_canonical_posting_events()` (định nghĩa ở migration `2026_08_10_000765`) derive `posting_action` bằng `COALESCE(NEW.posting_action, NEW.operation, 'POST')`. `posting_action` là `varchar(50)` theo Data Dictionary bảng 31 (`docs/HoaNam_ERD_Moi_Nhat.md`, giá trị mẫu "POST_RECEIPT/POST_ISSUE/...") — **chủ ý** chỉ chứa action-code ngắn, vì UUID của resource đã có cột `resource_id` riêng. `operation` (business/audit key, `varchar(160)`) luôn có dạng `<verb>_<uuid>`; khi verb dài (`reverse_stock_document_`, `post_component_issue_`) tổng chuỗi vượt 50 ký tự, và vì insert không set `posting_action` tường minh nên trigger coalesce nhầm cả `operation` (có UUID) vào cột chỉ dành cho action-code ngắn → Postgres ném `SQLSTATE 22001`. `post_receipt_<uuid>`/`post_issue_<uuid>` (49/47 ký tự) may mắn lọt qua nên bug ẩn suốt — SQLite không enforce độ dài `varchar` nên toàn bộ lịch sử test trên SQLite (kể cả review trước) không phát hiện được.

**Sửa 2 lớp (defense-in-depth), không đổi API contract/business rule, không đổi độ rộng cột/`docs/HoaNam_ERD_Moi_Nhat.md`**:
1. **Lớp DB (an toàn ngay cả khi caller không set `posting_action`)** — migration `2026_08_18_150000_fix_posting_events_posting_action_operation_overflow.php`: `CREATE OR REPLACE FUNCTION hn_erd_normalize_canonical_posting_events()` — derive `posting_action` bằng cách cắt hậu tố `_<uuid>` khỏi `operation` trước khi coalesce (`regexp_replace` + `LEFT(...,50)` làm lưới đỡ cuối), thay vì coalesce thẳng `operation` gốc; kèm backfill 1 lần cho row cũ đã lỡ ghi `posting_action` có UUID (chỉ áp dụng nếu Postgres, `down()` fail-forward — không có bản "cũ đúng hơn" để revert).
2. **Lớp ứng dụng (chất lượng cao hơn, action-code tường minh đúng enum ERD)** — 3 service `CurrentBaselineStockService`, `AlignedOutboundStockService`, `CurrentBaselineReversalService`: `recordPostingEvent()` nay nhận thêm tham số `$postingAction` ngắn cố định (`POST_RECEIPT`/`POST_ISSUE`/`POST_COMPONENT_ISSUE`/`REVERSE_RECEIPT`/`REVERSE_ISSUE`) và ghi trực tiếp, không phụ thuộc trigger derive nữa. Vì view compatibility số ít `posting_event` (migration `2026_08_10_000791`) trước đó **không** có cột `posting_action` trong SELECT list nên insert tường minh sẽ lỗi "column does not exist" — migration `2026_08_18_150100_expose_posting_action_on_posting_event_legacy_view.php` bổ sung cột này vào view + `INSTEAD OF` trigger `hn_legacy_posting_event_write()` (CREATE OR REPLACE, không DROP, an toàn với migration cũ đã chạy). Migration `2026_08_18_150200_add_posting_action_to_posting_event_sqlite.php` bổ sung cột tương ứng trên SQLite (bảng vật lý số ít khác Postgres — xem `reference_be_test_drivers`) để cùng 1 code path hoạt động trên cả 2 driver.

**Evidence verify thật** (database test riêng `wms_hoanam_testing`, migrate sạch từ đầu, seed lại, không dùng lại dữ liệu cũ):
- `php artisan migrate --force` — cả 3 migration mới chạy `DONE` không lỗi.
- `php scripts/wwhn219-runtime-concurrency.php` (2 tiến trình OS thật) — **trước fix**: `concurrent_reversal` FAIL (lỗi 22001 ở cả 2 worker); **sau fix**: `status: PASS`, cả 3 scenario (`concurrent_post_receipt`, `concurrent_reversal`, `shared_balance_lost_update`) pass toàn bộ check.
- Query trực tiếp `posting_events` sau fix: `posting_action` = `POST_RECEIPT`/`POST_ISSUE`/`REVERSE_RECEIPT` (ngắn, đúng enum), `operation` vẫn giữ nguyên chuỗi dài đầy đủ có UUID — đúng thiết kế 2 cột tách biệt của ERD.
- Test suite mở rộng trên Postgres (`--configuration=phpunit.pgsql.xml`, DB `wms_hoanam_testing`): `tests/Feature/PostReceiptConcurrencyBehaviorTest.php`, `PostReceiptAuditLogTest.php`, `Wwhn191BalanceMovementProjectionTest.php`, `Wwhn180IdempotencyAtomicityTest.php`, `Wwhn207InboundReversalFlowTest.php`, `Wwhn207OutboundBalanceReconciliationTest.php`, `StockDocumentIdempotencyTest.php`, `AnnexCOutboundLifecycleTest.php`, `Wwhn193PutawayLifecycleTest.php` → **65/65 passed, 438 assertions**, không regression.
- Full suite trên Postgres: xem "Gap còn lại" — chạy lần đầu bị nhiễm do 1 lệnh reset DB chạy chồng lên tiến trình test nền khác trong cùng phiên (tự phát hiện qua log, không tính là evidence), đã chạy lại sạch — kết quả cuối cùng ghi ở mục Verification tổng của báo cáo remediation (ngoài file này).

**Blast radius đã kiểm tra**: chỉ 3 nơi gọi `recordPostingEvent()`-tương tự trong toàn repo (đã grep `'operation' =>` + `posting_event`), cả 3 đều đã sửa. Không còn code path nào khác ghi `posting_event`/`posting_events`.

## Gap còn lại sau remediation 2026-08-18 (không thể tự đóng bằng code, cần role khác quyết định)

- **Không có CI gate riêng chạy full-suite + runtime-concurrency trên PostgreSQL, scoped cho WWHN-190/191.** Bằng chứng Postgres thật ở mục 10-11 hiện chỉ chạy thủ công (docker exec cục bộ) hoặc gián tiếp qua `.github/workflows/wwhn-219-final-gate.yml` (Phase 1-3, phạm vi WWHN-219) — gate đó đang **FAIL trên `m1` HEAD `94abebe1`** vì lý do ngoài phạm vi remediation này (WWHN-219/128/164/172/180/Auth RBAC — xem review trace). Cần Tech Lead/DevOps quyết định có tạo gate CI riêng cho WWHN-190/191 hay tiếp tục dựa vào gate WWHN-219 dùng chung, và có nên thêm bước "migrate + test trên Postgres thật" (không chỉ SQLite) vào CI chính để tránh tái diễn class-of-bug này.
- **QA1 (WWHN-192)**: theo review trước, vẫn ở trạng thái Blocked/Pending, cần assignee DT Soi retest sau khi có evidence Postgres mới này (bao gồm cả bug reversal vừa đóng).
- **DEC-INV-001**: đã xác minh **KHÔNG phải quyết định còn thiếu** — đã được duyệt chính thức trong baseline hiện hành `docs/HN_SRS_FRD_v1.12_FULL.md` §7.1 (FE repo), cùng `DEC-FULL-POST-001` (full-post-only), ngày 04/08/2026. Vấn đề thật: ticket cha WWHN-43 vẫn trích nguồn cũ đã superseded (`BRD_HoaNam_v3_9`, `HoaNam_Handoff_v3_9`) thay vì baseline hiện tại (`BRD v3.20` + `SRS/FRD v1.12`, theo bảng supersession trong chính SRS v1.12). Cần BA/PM cập nhật lại Source & Traceability trên Jira WWHN-43, không cần ra quyết định nghiệp vụ mới.
- File `phpunit.pgsql.xml` được thêm vào working tree để chạy evidence trên (không commit trong remediation này) — nếu Tech Lead muốn giữ lại làm cấu hình CI/dev chính thức cho việc chạy test trên Postgres, cần review riêng (đặt tên, vị trí, có nên gộp vào `composer.json` script) trước khi commit.

---

## §R2. Đóng register 62/100 — vòng 2026-08-19 (DEV190-P1-01R2 … P2-01R2)

Vòng này chuyển mọi hạng mục "cần chứng minh" thành **mệnh đề chạy lại được tại đúng commit**,
và tách rõ hạng mục nào chỉ cần **sign-off** (không phát sinh code mới).

### R2.1 — DEV190-P1-01C: hợp đồng khớp runtime (BE làm xong, chờ duyệt)

Đã tìm ra **6 điểm lệch thật** giữa hợp đồng posting WWHN-180 v1.7.0 và runtime; runtime và
SRS §8.2 **đồng ý với nhau**, chỉ artifact hợp đồng lệch:

| # | Điểm lệch | v1.7.0 khai | Runtime + SRS §8.2 |
|---|---|---|---|
| 1 | Header `If-Match` | không khai | **bắt buộc** cho scan/post/reversal |
| 2 | `412` | không khai | `412 STALE_VERSION` |
| 3 | `x-permission` | `stock_document.post` / `stock_document.scan` (không tồn tại) | `inbound.post`, `outbound.post`, `inbound.scan`, `outbound.scan`, `inventory.reversal.post` |
| 4 | 409 idempotency | `IDEMPOTENCY_ERROR` | `IDEMPOTENCY_CONFLICT` |
| 5 | `404` | không khai | `RESOURCE_NOT_FOUND` |
| 6 | `/stocktake/sessions/{id}/apply-adjustment` | có khai | **không tồn tại** (REQ-SRS-037 + DISP-STK-236) |

Cách đóng — **không sửa pack immutable**: `DISP-STK-236 §3` cấm sửa nội dung
`WWHN-180-posting-openapi.{json,yaml}` và `WWHN-180 Canonical Contract.postman_*`; chúng chỉ
được supersede bằng decision record. Vì vậy:

- Phát hành release mới **`docs/api-contract/releases/WWHN-190/1.8.0/`** (openapi.json + openapi.yaml
  + postman_collection.json + RELEASE_MANIFEST.json + SHA256SUMS + CHANGELOG.md).
- Decision record **`docs/decision-records/DEC-API-POSTING-002-WWHN190-RUNTIME-PARITY.md`** trỏ ngược v1.7.0.
- Artifact **sinh bằng script** `scripts/build-wwhn190-posting-contract.mjs` (hash do script sinh, không chép tay).
- Gate hai chiều: `node scripts/build-wwhn190-posting-contract.mjs --check` (đối chiếu từng
  `x-permission` với `php artisan route:list --json`, chặn bề mặt đã gỡ quay lại) +
  `tests/Feature/Wwhn190PostingContractParityTest.php` (bắn HTTP thật, assert đúng từng mã lỗi
  428/422/412/409/404/403/401 mà hợp đồng khai, và assert pack 1.7.0 vẫn nguyên vẹn).

### R2.2 — DEV190-P1-02R2: true concurrency PostgreSQL, đủ 5 kịch bản (BE làm xong)

`scripts/wwhn190-postgres-concurrency.php` + `scripts/wwhn190-concurrency-worker.php`.
Mỗi worker là **một OS process riêng, một backend PostgreSQL riêng**, bắn **HTTP thật qua HTTP kernel**
(khác worker WWHN-219 gọi thẳng service — vì If-Match/Idempotency-Key nằm ở tầng controller/middleware).
Thả đồng thời qua barrier filesystem; một connection monitor độc lập lấy mẫu `pg_locks`/`pg_stat_activity`;
kịch bản cần tranh chấp có thêm tiến trình **blocker** trung lập giữ row lock để hai worker chắc chắn xếp hàng trong PG.

| Kịch bản | Kết quả quan sát |
|---|---|
| 01 same key / same payload | `200` + `409 CONCURRENCY_CONFLICT`; movement = 1; balance += qty đúng 1 lần; posting_event = 1; retry tuần tự → `200` + `Idempotent-Replay: true`, `data.replayed=true` |
| 02 different key / same doc | `200` + `412 STALE_VERSION`; follow-up key mới với version ĐÚNG → `409 STATE_CONFLICT`; không có movement thứ hai |
| 03 stale version | worker stale → `412 STALE_VERSION`; thiếu If-Match → `428 PRECONDITION_REQUIRED`; If-Match sai định dạng → `422 INVALID_VERSION` |
| 04 same key / different payload | `200` + `409`; retry tuần tự khác payload → đúng `409 IDEMPOTENCY_CONFLICT` |
| 05 forced rollback | fault injection bằng trigger PG khi chứng từ chuyển POSTED (tức **sau** khi movement + balance đã ghi trong cùng transaction): cả 2 worker `500`, **0 movement**, balance không đổi, chứng từ vẫn `SCANNING` version 1, **lease idempotency được giải phóng**; gỡ trigger rồi retry cùng key → `200`, đúng 1 movement |

Mỗi kịch bản chỉ PASS khi đủ 4 điều kiện: 2 backend pid khác nhau, có tranh chấp thật
(cửa sổ request chồng nhau hoặc quan sát được lock wait), đúng số lần thành công + đúng mã lỗi bên thua,
và bất biến dữ liệu sau cùng. Bằng chứng: `docs/evidence/WWHN-190/concurrency/` gồm
`CONCURRENCY_EVIDENCE.json`, `concurrency-run.txt`, `pg-lock-samples.json` và raw stdout/stderr từng worker
(đuôi `.txt` vì `.gitignore` dòng 1 ignore `*.log`).

### R2.3 — DEV190-P1-03R2: đối chiếu cho WWHN-191 (BE làm xong)

`scripts/wwhn190-receipt-reconciliation.php` — 4 lớp kiểm tra, xuất báo cáo có version + file
`reconciliation-raw-sql.txt` chứa **nguyên văn SQL** và raw rows để QA chạy lại:

- **L1** `accepted_receipt_qty` (= `stock_document_lines.qty_planned`, và kiểm luôn bất biến
  `scanned_qty = qty_planned` của DEC-FULL-POST-001) **=** `SUM(stock_movements.qty_delta)` của movement `RECEIPT` theo từng dòng.
- **L2** `inventory_balances.on_hand_qty` **=** `SUM(qty_delta)` toàn ledger theo bucket (warehouse, sku, stock_status) — DEC-INV-001.
- **L3** orphan: movement mồ côi, movement RECEIPT/ISSUE trên chứng từ chưa POSTED, dòng POSTED không sinh movement, balance khác 0 không có ledger.
- **L4** duplicate business effect: `business_key` trùng, movement trùng trên cùng dòng, posting_event trùng.

Lưu ý thuật ngữ: schema **không có** cột tên `accepted_receipt_qty`; ánh xạ đúng là `qty_planned`
(bằng `scanned_qty` tại thời điểm Post do full-post gate), đã ghi rõ trong báo cáo.

### R2.4 — CI gate mới (đóng "Gap còn lại" của vòng trước)

`.github/workflows/wwhn-190-posting-evidence-gate.yml` — 2 job, fail-closed:

1. `contract-parity` (sqlite): pint, generator `--check`, kiểm pack 1.7.0 còn nguyên, phpunit parity test, các test guard posting cũ.
2. `true-concurrency` (PostgreSQL 16 service): `migrate --force`, chạy 5 kịch bản concurrency, chạy reconciliation,
   niêm phong `SHA256SUMS` + `SHA256SUMS.sha256` + `EVIDENCE_BUILD.txt` (commit + run id), upload artifact 90 ngày.

Đây là gate CI **riêng cho WWHN-190/191** mà vòng trước ghi là chưa có.

### R2.5 — DEV190-P1-01R2: điều kiện để WWHN-180 evidence gate xanh

Gate `WWHN-180 Scan Receipt Issue Evidence Gate` đang đỏ ở **bước đầu tiên** (`Static gates`) vì
`vendor/bin/pint --test` báo **23 file lệch format** — toàn bộ là nợ format tích luỹ từ nhiều nhánh khác
(migration, seeder, controller báo cáo, test của WWHN-191/219/237/247/249…), **không** phải lỗi logic.
Vòng này đã chạy `pint` cho đúng 23 file đó (chỉ format: `ordered_imports`, `braces_position`,
`unary_operator_spaces`, `no_unused_imports`…), không đổi một dòng logic nào, để gate đi qua được
`Static gates` và chạy tới các bước evidence thật.

### R2.6 — DEV190-P2-01R2: đính chính narrative + task dọn dẹp

- Đã sửa trong chính tài liệu này: “PHP/Laravel 11” → **PHP ^8.3 / Laravel v13.23.0** (pin tại `composer.lock`);
  `412 PRECONDITION_FAILED` → **`412 STALE_VERSION`**; `409 IDEMPOTENCY_ERROR` → **`409 IDEMPOTENCY_CONFLICT`**;
  `409 VERSION_CONFLICT` → **`409 CONCURRENCY_CONFLICT`**; gỡ các tham chiếu “dòng 510-533 / 893-905 / 850-861 / 803-805”
  (số dòng đã trôi) thành tham chiếu theo tên method; cập nhật ghi chú SQLite đã bị thay thế bởi harness PostgreSQL.
- Task dọn dẹp/deprecation cho lớp legacy không active: **`docs/evidence/WWHN-190/LEGACY_CLEANUP_TASK.md`**
  (đặc tả sẵn scope + AC + rủi ro; cần BA/PM tạo Jira key con của WWHN-43 rồi điền lại vào file).

### R2.7 — Hạng mục chỉ cần sign-off (không phát sinh code)

| Register item | Ai duyệt | Duyệt cái gì |
|---|---|---|
| DEV190-P1-01R2 | Tech Lead + QA1 | Pin exact RC SHA, chấp nhận PR=N/A (code vào thẳng `m1`), và điều kiện xanh của WWHN-180 gate ở R2.5 |
| DEV190-P1-04R2 | QA1 → BA + Tech Lead | Chạy độc lập theo `docs/evidence/WWHN-190/QA1_INDEPENDENT_TEST_PACK.md` tại đúng RC SHA |
| DEV190-P1-05R2 | BA/PM → Tech Lead + QA | Formalize DEC-INV-001 + DEC-FULL-POST-001 theo `docs/decision-records/DEC-INV-FULLPOST-PIN-190.md`; cập nhật WWHN-43 bỏ nguồn v3.9 |

## §R3 — Re-pin sau khi HEAD trôi khỏi RC SHA của R2 (2026-08-19)

Re-review 62/100 chỉ ra RC SHA `10a2c6e` của R2 không còn là HEAD `m1` (57 commit khác đã
vào sau, do nhiều phiên Claude khác làm song song ticket khác). Đã grep chính xác 2/57 commit
chạm runtime path của WWHN-190 (`0142b49` WWHN-234 F-04 đổi thứ tự khoá `inventory_balances`
ngay trong `postStockDocument`; `ea93dd6` WWHN-195 sửa cùng file khác method) và chạy lại toàn
bộ evidence (contract-parity, 52 test PHPUnit sqlite, 5 kịch bản true-concurrency PostgreSQL DB
cô lập mới, reconciliation) tại RC SHA mới `9b18443` — tất cả PASS, không cần sửa code. Chi
tiết đầy đủ: `EVIDENCE_PACK.md §9`, pin mới: `RC_PIN.json` (giữ lịch sử round R2 làm tham
chiếu). Không chạy/động CI theo yêu cầu người dùng (Actions hết quota).

## §R4 — Đóng 4 P1 của re-review 62/100 lần 2 (2026-08-19)

Acceptance SHA = **`22564b46898f616c0df2880f797fcc0f1fcf9507`** = đúng SHA chạy lại toàn bộ
PostgreSQL evidence (hết khoảng hở "SHA nghiệm thu ≠ SHA evidence" của DEV190-P1-01R2). Lý do
re-run dù path runtime không đổi: commit `41dfa6f` (WWHN-184) sửa VIEW `audit_log` pgsql — thuộc
schema-surface mà bất biến audit của WWHN-190 assert; đã chạy thêm 20/20 test trực tiếp trên
PostgreSQL để chứng minh không phá bất biến. QA1 chạy độc lập cùng ma trận tại đúng SHA này
(DB/output riêng `qa1-r4`). FE độc lập: repo FE commit `cf0fe8d`, vitest 22/22 PASS. Business
authority: `DEC-INV-FULLPOST-PIN-190.md` (đính chính: artifact "Handoff v3.9" không tồn tại —
chỉ có `Handoff v3.10 reference → REMOVED / NO ARTIFACT` ở §1.6 SRS; formal gate của
DEC-FULL-POST-001 là WWHN-27). Chi tiết: `docs/evidence/WWHN-190/EVIDENCE_PACK.md §10`,
`RC_PIN.json` (round R4). Không động CI/GitHub Actions.
