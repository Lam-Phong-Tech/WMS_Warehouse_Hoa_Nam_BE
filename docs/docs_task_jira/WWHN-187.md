[DEV] Rà soát giao dịch/khóa đồng thời

Description

Mục tiêu
Rà soát giao dịch/khóa đồng thời.

Input đầu vào
Task cha: WWHN-42 — [INBOUND] Quét/ghi nhận item & component serial/quantity

Dependency: HN-IN-002-ST01

Baseline nghiệp vụ, API/UI specification, dữ liệu mẫu và evidence được tham chiếu trong task cha hoặc Source & Traceability.

Vai trò và phối hợp
Người chịu trách nhiệm chính: BE1 — Quang Nguyễn Thanh.

Nhận từ BA/BAS: quy tắc nghiệp vụ, Acceptance Criteria, Decision Record/baseline, quy tắc dữ liệu và điểm chưa chốt đã được xử lý.

Phối hợp với BE2 về ranh giới giao dịch, schema, truy vấn/báo cáo, audit và dependency; phối hợp DevOps về database/environment khi cần.

Bàn giao cho FE1: API contract, endpoint, payload mẫu, validation/error code, permission và dữ liệu tích hợp.

Bàn giao cho QA/BA: test evidence, migration/seed hoặc API/DB evidence; blocker phải có owner, ETA và ảnh hưởng.

Source & Traceability
Parent Temp Key: HN-IN-002

Epic Temp Key: HN-EPIC-05

Dependency: HN-IN-002-ST01

Role: BE1

Assignee Placeholder: HN-BE-01

Acceptance Criteria
Output "Kết quả rà soát đồng thời" được rà soát/xác minh, gắn evidence vào HN-IN-002 và không còn blocker thuộc vai trò BE1.

Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; áp dụng permission, validation, giao dịch, audit, idempotency hoặc concurrency guard khi thuộc phạm vi.

Trường hợp lỗi trả error code rõ ràng, không tạo dữ liệu sai và không lộ secret/stack trace.

Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu được cập nhật khi contract thay đổi.

FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition.

Output đầu ra
Kết quả rà soát đồng thời

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

**Ghi chú traceability quan trọng — đọc trước khi dùng file này**: task này có Parent Temp Key `HN-IN-002` (task cha WWHN-42, "Quét/ghi nhận item & component serial/quantity") và output riêng "Kết quả rà soát đồng thời" — **khác** với `WWHN-190.md` (Parent Temp Key `HN-IN-003`, task cha WWHN-43 "Ghi nhận nhập kho Post Receipt", output "ghi nhận nhập kho backend"). Cả hai đều đụng tới giao dịch/khóa đồng thời của cùng file `CurrentBaselineStockService.php`/`CurrentBaselineStockFlowController.php` vì 2 bước (scan và post-receipt) chia sẻ hạ tầng chung (transaction wrapper, optimistic lock `If-Match`, `lockForUpdate()`), nhưng WWHN-190 tập trung vào **post-receipt** (`postDocument()`/`postStockDocument()`), còn task này tập trung đúng phạm vi **HN-IN-002 = bước scan** (`inboundScan()`/`versionedScan()`/`scanStockDocument()`). Không lặp lại rà soát post-receipt đã có ở WWHN-190; tham chiếu kết quả đó khi hạ tầng dùng chung.

Cũng cần phân biệt với `WWHN-198-inbound-scan.md` (BE2, cùng Parent Temp Key `HN-IN-002` nhưng output khác — "Inbound scan backend", tập trung vào **validation/error code** của scan, 27 test case). Task này (BE1) tập trung đúng vào khía cạnh **giao dịch/khóa đồng thời** của cùng bước scan đó — bổ sung, không trùng lặp, với WWHN-198-inbound-scan.

**Exact commit SHA khi bắt đầu rà soát**: `fad4242` (HEAD của `m1`), cùng working tree với thay đổi chưa commit của các task song song khác (WWHN-190/191/193/195/199/201/202/204/205/207 — xem `git status`). Task này chỉ thêm 1 file test mới (`tests/Feature/InboundScanConcurrencyBehaviorTest.php`) và không sửa file production nào.

### Phương pháp

Đọc trực tiếp `CurrentBaselineStockFlowController::inboundScan()`/`versionedScan()`/`scanPayload()`/`expectedVersion()`/`assertVersion()`/`lockDocument()` và `CurrentBaselineStockService::scanStockDocument()`/`resolveItem()`/`assertItemAvailableForReceipt()`. Đối chiếu với 2 bộ evidence liên quan đã có: `WWHN-190.md` (transaction/idempotency/concurrency của post-receipt, cùng hạ tầng) và `WWHN-198-inbound-scan.md` (BE2, validation của scan, cùng endpoint). Rà soát riêng: file test hiện có cho scan (`Wwhn198InboundScanValidationTest.php`, `Wwhn199ReservationConcurrencyGuardTest.php`) đã cover khía cạnh nào của concurrency, khía cạnh nào chưa — rồi lấp đúng phần thiếu bằng test mới, không lặp lại.

### 1. Transaction (atomic) cho scan — ĐẦY ĐỦ, không cần sửa

- `CurrentBaselineStockFlowController::versionedScan()` (`CurrentBaselineStockFlowController.php:160-198`) bọc toàn bộ scan trong `DB::transaction(..., 3)` — lock document (`lockDocument()`), kiểm tra version, gọi service, bump version, ghi `document_status_history` nếu đổi trạng thái, tất cả cùng 1 transaction.
- `CurrentBaselineStockService::scanStockDocument()` (`CurrentBaselineStockService.php:17-115`) tự bọc thêm 1 `DB::transaction` bên trong: `lockForUpdate()` trên `stock_documents` (dòng 32), resolve/lock item qua `resolveItem()` → `lockForUpdate()` trên `item_instances` (dòng 506), validate, ghi `inbound_scan_entries`, ghi `scan_batch`/`scan_event`, cập nhật `scanned_qty`/status — cùng transaction, rollback toàn bộ nếu bất kỳ bước nào lỗi.
- Cùng pattern lồng transaction 2 lớp (controller ngoài bump version, service trong ghi scan effect) đã được xác nhận atomic ở WWHN-190 cho post-receipt — không phải code path riêng, dùng chung cơ chế `DB::transaction` của Laravel nên kết luận atomicity áp dụng tương đương.

### 2. Concurrency guard cho scan — ĐẦY ĐỦ (implementation), đã bổ sung test hành vi race thật

Các lớp khóa áp dụng cho scan:
- Optimistic lock qua header `If-Match` (`expectedVersion()`/`assertVersion()`, `CurrentBaselineStockFlowController.php:510-533`) → thiếu header 428 `PRECONDITION_REQUIRED`, sai định dạng 422 `INVALID_VERSION`, sai version 412 `PRECONDITION_FAILED`.
- Row lock `SELECT ... FOR UPDATE` trên `stock_documents` (`lockDocument()`, dòng 500-508) — chặn 2 request scan cùng document ghi đè version của nhau.
- Row lock `SELECT ... FOR UPDATE` trên `item_instances` trong `resolveItem()` (`CurrentBaselineStockService.php:506`) — chặn 2 request scan cùng item (dù khác document) đọc/ghi trạng thái item đồng thời; đây là cơ chế nền tảng cho guard `ITEM_ALREADY_RECEIVING`/`ITEM_ALREADY_MATCHED`.
- Document-state guard (`STATE_CONFLICT` 409) — chỉ document `DRAFT`/`SCANNING` được scan; kể cả khi request mang đúng version hiện tại, state guard vẫn chặn nếu document đã `POSTED`/`CANCELLED` — defense-in-depth độc lập với version check.

**Gap tìm thấy trước khi rà soát này**: `Wwhn198InboundScanValidationTest::test_scan_rejects_stale_version_with_412` chỉ gửi `If-Match` sai tĩnh (giá trị `99`) để test guard, không mô phỏng race thật giữa 2 writer — writer A đọc version, writer B đọc cùng version, A scan trước và thắng (bump version), B replay version cũ vào **request thật của B** rồi mới bị từ chối. `Wwhn199ReservationConcurrencyGuardTest` có cover race thật cho guard trùng item (`ITEM_ALREADY_RECEIVING`), nhưng không cover riêng optimistic version guard của chính request scan (2 line khác nhau, cùng document, cùng version ban đầu). Đây đúng là gap thuộc phạm vi "Kết quả rà soát đồng thời" của task này — khác với validation error code (đã đóng ở WWHN-198-inbound-scan) và khác với post-receipt (đã đóng ở WWHN-190).

**Đã bổ sung**: `tests/Feature/InboundScanConcurrencyBehaviorTest.php` — 2 test case, lái qua HTTP thật:
- `test_stale_version_racer_is_rejected_after_winner_scan_commits`: document 2 line (khác SKU), 2 writer cùng đọc version=1. Writer A scan line 1 trước, thắng, version bump lên 2. Writer B replay version=1 cũ vào line 2 (line hoàn toàn khác, chưa từng bị đụng) — vẫn bị 412 `PRECONDITION_FAILED`, không tạo `inbound_scan_entries` cho line 2, `scanned_qty` của line 2 giữ nguyên 0. Sau đó writer B retry với version hiện tại (2) thì thành công (version bump lên 3) — chứng minh guard chỉ chặn đúng request mang version cũ, không chặn nhầm line B.
- `test_scan_with_current_version_on_posted_document_is_rejected_by_state_guard`: scan xong 1 line rồi post-receipt (document chuyển `POSTED`, version=3). Gửi tiếp request scan với `If-Match: 3` — đúng version hiện tại thật — nhưng vẫn bị 409 `STATE_CONFLICT` vì document đã `POSTED`, không tạo thêm `inbound_scan_entries`. Xác nhận state guard là lớp phòng thủ độc lập, không chỉ dựa vào version, đúng nguyên tắc defense-in-depth đã áp dụng nhất quán ở post-receipt (WWHN-190).

Không phát hiện bug chức năng nào — cả 2 test case pass ngay lần chạy đầu tiên, xác nhận guard hoạt động đúng theo thiết kế.

### 3. Idempotency cho scan — ĐÃ XÁC NHẬN theo phạm vi (scan không dùng Idempotency-Key)

Endpoint `POST /inbound-documents/{id}/scan` **không** yêu cầu header `Idempotency-Key` (chỉ `post-receipt`/`reverse` yêu cầu, đã xác nhận ở WWHN-190) — đây là quyết định thiết kế nhất quán: scan là thao tác lặp lại nhiều lần trong vòng đời 1 document (mỗi item/component 1 lần scan), không phải hành động một-lần cuối luồng như Post/Cancel, nên không cần idempotency-key theo semantics "replay an toàn". An toàn dữ liệu khi client retry mạng (network retry) vẫn được đảm bảo gián tiếp qua guard `DUPLICATE_ITEM_IN_DOCUMENT`/`ITEM_ALREADY_RECEIVING` (item đã scan vào document → lần scan lặp lại cùng item bị chặn 409, không tạo `inbound_scan_entries` trùng) — không phải cơ chế idempotency-key cổ điển nhưng đạt cùng mục tiêu chống ghi trùng dữ liệu nghiệp vụ cho action tự nhiên "add item vào document". Không phải gap — không tự ý thêm `Idempotency-Key` cho scan vì đây là thay đổi contract vượt phạm vi rà soát, cần BA/Tech Lead xác nhận nếu muốn đổi.

### 4. Permission/RBAC cho scan — ĐẦY ĐỦ, không cần sửa

- Route `POST inbound-documents/{id}/scan` gắn permission `inbound.scan` + middleware `EnsureInboundComponentBox` (chỉ áp dụng dòng COMPONENT).
- Đã có test hành vi thật: 401 `UNAUTHENTICATED` (thiếu auth), 403 `ACCESS_DENIED` (thiếu quyền) — `Wwhn198InboundScanValidationTest` test case 1-2.

### 5. Audit log cho scan — Xác nhận pattern nhất quán, không phải gap riêng của task này

Đã xác nhận lại (kế thừa từ rà soát của BE2 ở `WWHN-198-inbound-scan.md` mục 4): scan không ghi `AuditLogger::record()` riêng (chỉ final actions như `POST_RECEIPT`/`CANCEL` mới ghi `audit_log`), nhưng có evidence trail riêng qua `scan_event`/`scan_batch`/`inbound_scan_entries` (đầy đủ `created_by`/timestamp). Đây là quyết định thiết kế xuyên suốt hệ thống, đã ghi nhận là open point cho BA/Tech Lead ở `BE2-INSCAN-OP-01` trong `WWHN-198-inbound-scan.md` — không lặp lại xử lý ở đây, tham chiếu cùng open point.

### 6. Error handling — ĐẦY ĐỦ, không cần sửa

- Toàn bộ lỗi guard (412/409/428/422) trả qua `ApiError::abort()` — envelope chuẩn hóa, không lộ stack trace (kế thừa xác nhận từ WWHN-190 cho cùng hạ tầng `ApiError`).
- Test mới bổ sung xác nhận: request bị chặn không tạo `inbound_scan_entries`, không tăng `scanned_qty` — không có side-effect dữ liệu sai khi guard kích hoạt.
- Mọi lỗi trong transaction tự động rollback (kế thừa cơ chế `DB::transaction` đã xác nhận ở mục 1).

### 7. Test coverage — trước và sau

| Khía cạnh | Trạng thái trước (riêng scan) | Trạng thái sau |
|---|---|---|
| Transaction/atomicity (source-level) | Có (đọc code, khớp pattern post-receipt) | Không đổi — không cần test riêng vì cùng cơ chế `DB::transaction` đã verify ở WWHN-190 |
| Version guard — request sai tĩnh (`If-Match` giá trị bất kỳ khác) | Có (`Wwhn198InboundScanValidationTest`) | Giữ nguyên, không lặp lại |
| Version guard — race thật (2 writer cùng đọc version, 1 bên thắng trước) | **Không có** | **Đã bổ sung**: `InboundScanConcurrencyBehaviorTest::test_stale_version_racer_is_rejected_after_winner_scan_commits` |
| State guard sau khi version đã hợp lệ nhưng document đã POSTED | **Không có** (chỉ test state guard qua nhánh dùng SKU khác ở `Wwhn198InboundScanValidationTest`, không phải race version→state) | **Đã bổ sung**: `InboundScanConcurrencyBehaviorTest::test_scan_with_current_version_on_posted_document_is_rejected_by_state_guard` |
| Item-level row lock / dedup trùng item khi race (`ITEM_ALREADY_RECEIVING`) | Có (`Wwhn199ReservationConcurrencyGuardTest`) | Giữ nguyên, không lặp lại |
| Permission 401/403 trên route scan | Có (`Wwhn198InboundScanValidationTest`) | Giữ nguyên, không lặp lại |

Kết quả chạy: `php artisan test tests/Feature/InboundScanConcurrencyBehaviorTest.php` → **2/2 passed, 20 assertions**.

Kết quả chạy bộ evidence liên quan (test mới + toàn bộ test concurrency/scan có sẵn của WWHN-190/198/199): `php artisan test tests/Feature/InboundScanConcurrencyBehaviorTest.php tests/Feature/Wwhn198InboundScanValidationTest.php tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php tests/Feature/PostReceiptConcurrencyBehaviorTest.php tests/Feature/StockDocumentConcurrencyGuardTest.php tests/Feature/Wwhn180IdempotencyAtomicityTest.php tests/Feature/PostReceiptAuditLogTest.php` → **48/48 passed, 240 assertions**.

`vendor/bin/pint --test tests/Feature/InboundScanConcurrencyBehaviorTest.php` → passed (không cần auto-fix).

Kết quả chạy full suite (xác nhận không regression toàn cục, bao gồm cả thay đổi song song chưa commit của các task khác đang nằm trong cùng working tree): `php artisan test` → **427 tests, 411 passed, 16 skipped (baseline có sẵn, Postgres-gated/môi trường, không liên quan tới thay đổi này), 0 failed, 4816 assertions** — tăng đúng 2 test/20 assertions so với baseline trước khi bắt đầu (425 tests/409 passed/4796 assertions, ghi nhận ở `WWHN-198-inbound-scan.md`).

### 8. File thay đổi

| File | Loại | Nội dung |
|---|---|---|
| `tests/Feature/InboundScanConcurrencyBehaviorTest.php` | Mới | 2 test case HTTP end-to-end cho race thật của optimistic version guard trên chính request scan (khác `Wwhn198InboundScanValidationTest` — request sai tĩnh; khác `Wwhn199ReservationConcurrencyGuardTest` — item-level dedup) |
| `docs/docs_task_jira/WWHN-187.md` | Cập nhật (file này) | Evidence rà soát giao dịch/khóa đồng thời cho HN-IN-002 (bước scan), phân biệt rõ với WWHN-190 (HN-IN-003, post-receipt) và WWHN-198-inbound-scan (HN-IN-002, validation) |

**Không sửa code sản xuất nào** — toàn bộ transaction wrapper, optimistic lock, row lock, state guard của scan đã đúng theo baseline từ trước; rà soát chỉ phát hiện gap coverage test (mục 2), không phải bug.

### 9. API docs (OpenAPI/Postman) — không cần sửa

Không đổi contract (chỉ bổ sung test hành vi cho error code `PRECONDITION_FAILED`/`STATE_CONFLICT` đã có sẵn trong OpenAPI annotation từ trước, xác nhận khớp ở WWHN-190/WWHN-198-inbound-scan) — không cần cập nhật `docs/api-contract/`, Postman collection, hay payload mẫu.

### 10. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (WWHN-187, HN-IN-002) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "Kết quả rà soát đồng thời" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE1 | **Done** — rà soát transaction/idempotency/concurrency guard riêng cho bước scan, phân biệt rõ với post-receipt (WWHN-190) và validation (WWHN-198-inbound-scan); lấp gap race-condition test còn thiếu | Mục 1-6 |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — transaction atomic, optimistic + row lock xác nhận qua test race thật, state guard defense-in-depth, permission 401/403 có test, audit qua `scan_event` (pattern nhất quán, không phải gap riêng) | Mục 1-5 |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — test mới xác nhận racer thua cuộc không tạo `inbound_scan_entries`/không tăng `scanned_qty`; `ApiError::abort()` không lộ stack trace (kế thừa WWHN-190) | Mục 2, 6 |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 2 test case HTTP mới; không cần cập nhật OpenAPI/Postman (không đổi contract) | Mục 7-9 |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Không phát hiện blocker P0/P1 kỹ thuật mới** thuộc vai trò BE1 cho khía cạnh giao dịch/khóa đồng thời của scan | — |

### 11. Blocker / Open point

| ID | Nội dung | Owner đề xuất | Disposition |
|---|---|---|---|
| BE1-SCANCONC-OP-01 (kế thừa từ `WWHN-198-inbound-scan.md`, `BE2-INSCAN-OP-01`) | Không có `AuditLogger::record()` riêng cho hành động scan — chỉ có `scan_event`/`scan_batch` evidence trail. Cần BA/Tech Lead xác nhận có đủ hay cần audit_log riêng | BA/Tech Lead | Mở, kế thừa (không lặp lại xử lý ở task này) |
| BE1-SCANCONC-OP-02 | Scan không dùng `Idempotency-Key` (khác post-receipt/reverse) — an toàn dữ liệu dựa vào guard trùng item, không phải cơ chế idempotency-key cổ điển. Xác nhận đây là thiết kế chủ đích, không phải gap; ghi nhận để BA xác nhận nếu baseline gốc yêu cầu khác | BA/Tech Lead | Mở, rủi ro thấp (không chặn release) |

### 12. Định nghĩa Done — thực tế

#### BE1 technical completion (mục này)

Đạt: rà soát đúng phạm vi "giao dịch/khóa đồng thời" cho bước scan (HN-IN-002) — xác nhận transaction atomic (cùng hạ tầng đã verify ở WWHN-190), optimistic lock (`If-Match`) và row lock (`lockForUpdate` trên `stock_documents`/`item_instances`) hoạt động đúng qua test race thật mới viết (trước đây chỉ có test request sai tĩnh, chưa có test race thật riêng cho scan), state guard xác nhận defense-in-depth độc lập với version. Không phát hiện bug chức năng — 2/2 test pass ngay lần đầu. Full suite 411/411 pass (16 skip baseline không liên quan) — không regression, không sửa code sản xuất nào. Đã làm rõ ranh giới traceability giữa 3 file dễ nhầm lẫn (`WWHN-187` vs `WWHN-190` vs `WWHN-198-inbound-scan`) để tránh rà soát trùng lặp trong tương lai.

#### Còn lại — không thuộc phạm vi tự nhận của BE1

- BA/Tech Lead xác nhận disposition cho audit log riêng của bước scan (BE1-SCANCONC-OP-01, kế thừa).
- BA/Tech Lead xác nhận thiết kế "scan không cần Idempotency-Key" là đúng baseline (BE1-SCANCONC-OP-02).
- QA xác nhận evidence (mục 2, 7) đáp ứng đúng Acceptance Criteria.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE1 tự ghi trong file này.
