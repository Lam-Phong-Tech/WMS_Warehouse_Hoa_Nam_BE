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

## Ghi chú traceability — ĐÃ CHỐT (2026-08-18)

**Cập nhật 2026-08-18**: đã fetch trực tiếp Jira live (`https://tan0110.atlassian.net/browse/WWHN-42`, danh sách subtask) và xác nhận dứt điểm: **WWHN-188 là key chính thức, duy nhất của task "Rà soát giao dịch/khóa đồng thời"** (BE1, nội dung dưới đây). **WWHN-187 là một task khác hoàn toàn** — "[BE] Triển khai quét/ghi nhận/validation" (BE2, output "Inbound scan backend") — nội dung đã được chuyển đúng chỗ sang `docs/docs_task_jira/WWHN-187.md` (trước đó bị lưu nhầm dưới tên `WWHN-198-inbound-scan.md`). Đóng open point `BE1-DUPKEY-188-01` (xem mục 11) — không còn ambiguity giữa 2 số hiệu.

File này (`WWHN-188.md`) trước đây (2026-08-15) từng giả định `WWHN-187.md` và `WWHN-188.md` **trỏ tới cùng một task** — giả định đó đã được xác nhận SAI qua Jira live và được sửa lại ở đây. Toàn bộ nội dung kỹ thuật (transaction/concurrency/idempotency review) bên dưới không đổi, vẫn đúng và đầy đủ cho task WWHN-188 — chỉ có phần diễn giải "trùng số hiệu" ở trên là cần đính chính.

**Xác minh lại trước khi tham chiếu (2026-08-15)** — không chỉ copy nguyên văn mà đã tự chạy lại để đảm bảo evidence còn đúng với code hiện tại tại thời điểm này:

- `php artisan test tests/Feature/InboundScanConcurrencyBehaviorTest.php tests/Feature/Wwhn198InboundScanValidationTest.php tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php` → **34/34 passed, 158 assertions**. (Ghi chú 2026-08-18: lệnh trên là evidence lịch sử tại thời điểm chạy 2026-08-15, giữ nguyên không sửa để bảo toàn tính xác thực; lớp `Wwhn198InboundScanValidationTest` sau đó đã đổi tên thành `Wwhn187InboundScanValidationTest`, cùng nội dung test, xem `WWHN-187.md`.)
- `php artisan test` (full suite) → **427 tests, 411 passed, 16 skipped, 0 failed, 4816 assertions** — khớp chính xác với con số đã ghi trong `WWHN-187.md` mục 7, xác nhận không có thay đổi/regression nào giữa 2 thời điểm.

Không phát hiện sai lệch nào giữa evidence đã ghi và trạng thái code thật hiện tại — nội dung dưới đây được giữ nguyên (ghi chú 2026-08-18: đoạn dưới đây ban đầu diễn giải là "tham chiếu lại `WWHN-187.md`" theo giả định trùng key cũ; nay đã xác nhận đây luôn là evidence gốc của chính task WWHN-188, không phải tham chiếu chéo — xem đính chính traceability ở trên).

## Rà soát bổ sung — phát hiện mới ngoài `WWHN-187.md` (2026-08-15, BE1)

Sau khi xác nhận `WWHN-187.md`/`WWHN-188.md` là cùng một task, đã tự chạy thêm một vòng khảo sát độc lập (đọc trực tiếp `CurrentBaselineStockService::scanStockDocument()`/`recordInboundScan()`/`recordOutboundMatch()`, chạy `php artisan route:list`) để đảm bảo không bỏ sót gap nào trước khi coi task đã hoàn chỉnh. Phát hiện 2 điểm chưa từng được ghi nhận ở bất kỳ WWHN-* nào trước đây:

**1. Gap thật — không idempotency guard cho scan COMPONENT quantity-mode (dòng không có `item_id`)**

`recordInboundScan()` (`CurrentBaselineStockService.php:567-582`) insert thẳng `inbound_scan_entries` không kiểm tra trùng khi `$item === null` (SKU COMPONENT, quantity-mode) — khác với nhánh có `item_id`, nơi `item_instances` bị `lockForUpdate()` + guard `ITEM_ALREADY_RECEIVING`/`DUPLICATE_ITEM_IN_DOCUMENT` chặn trùng tuyệt đối. Với quantity-mode, guard duy nhất là `SCAN_EXCEEDS_REQUIRED` (`scanned_qty + quantity > qty_planned`) — **chỉ chặn khi tổng vượt số lượng yêu cầu**, không chặn được trường hợp client gửi lại đúng request do network retry (timeout phía client nhưng server đã xử lý xong) trong khi tổng vẫn còn trong ngưỡng `qty_planned`. Ví dụ cụ thể: dòng COMPONENT cần 10, đã scan 3, client scan thêm 3 nhưng mạng timeout → retry cùng payload `quantity=3` → server xử lý cả 2 lần (lần đầu do timeout chỉ mất response, không mất transaction) → `scanned_qty` thành 9 thay vì 6, sai lệch thật so với số lượng vật lý đã quét.

Endpoint scan không dùng `Idempotency-Key` theo thiết kế đã xác nhận (mục 3 ở evidence gốc, `BE1-SCANCONC-OP-02`) — lý do được chấp nhận cho trường hợp có `item_id` (dedupe tự nhiên qua item), nhưng lý do đó **không áp dụng được cho quantity-mode** vì không có thực thể nào để dedupe. Đây là khoảng trống thật trong lý luận "scan không cần Idempotency-Key", chưa được bất kỳ task rà soát nào trước đó (WWHN-187/198/198-inbound-scan/199) chỉ ra tường minh.

**Không tự sửa** — theo quyết định đã thống nhất, vì mọi phương án khắc phục đều đổi hành vi/contract:
- Thêm `Idempotency-Key` optional cho scan: đổi API contract (dù optional), cần FE phối hợp gửi kèm và BA xác nhận đây có phải rủi ro thật trong vận hành (tuỳ thuộc network reliability, có UI debounce/loading-lock phía FE chặn double-submit hay không).
- Thêm dedupe theo cửa sổ thời gian ngắn (chặn 2 scan giống hệt nhau trong X giây cho cùng line/actor): rủi ro chặn nhầm 2 lần scan hợp lệ liên tiếp thật (ví dụ quét nhanh 2 kiện hàng cùng lúc) nếu ngưỡng thời gian chọn sai — cần BA định nghĩa rõ ngưỡng nghiệp vụ, không phải quyết định kỹ thuật đơn phương.

**2. Đã xác minh — dead code `CurrentBaselineStockController` không phải rủi ro route thật (loại bỏ nghi vấn)**

Một giả thuyết cần kiểm chứng: liệu `CurrentBaselineStockController` (dead code đã biết từ nhiều task trước, gọi thẳng `scanStockDocument()`/`postStockDocument()`/`reverseStockDocument()` nhưng **thiếu** `If-Match` và `Idempotency-Key` trên scan) có đang bị route nào bind nhầm tới hay không — nếu có, đây sẽ là lỗ hổng bảo mật/tính toàn vẹn dữ liệu nghiêm trọng (endpoint sống với guard yếu hơn hẳn).

Đã xác minh bằng `php artisan route:list` thật: route `POST inbound-documents/{id}/scan` chỉ trỏ vào `CurrentBaselineStockFlowController@inboundScan` (controller live, đầy đủ `If-Match`); route `POST component-issue-documents/{id}/scan` trỏ vào `CurrentBaselineComponentFlowController`, không phải `CurrentBaselineStockController`. Xác nhận `CurrentBaselineStockController` **không được bind bởi bất kỳ route nào** trong `routes/api.php`/`routes/governance.php` hiện tại — đúng là dead code hoàn toàn, không phải rủi ro route thật. Không cần xử lý gì thêm; giữ nguyên khuyến nghị dọn dẹp dead code đã có từ WWHN-190/195/205/207 (BA/Tech Lead disposition).

**Test suite sau rà soát bổ sung**: không thay đổi code sản xuất, không có test mới cần thêm cho phần này (gap #1 là open point cần quyết định thiết kế trước khi viết test cho hành vi mới; gap #2 chỉ là xác minh, không có hành vi mới để test) — `php artisan test` vẫn giữ nguyên **427 tests, 411 passed, 16 skipped, 4816 assertions** như đã xác nhận ở mục trên.

---

## Kết quả rà soát (Evidence — cập nhật 2026-08-15, BE1)

**Ghi chú traceability quan trọng — đọc trước khi dùng file này**: task này có Parent Temp Key `HN-IN-002` (task cha WWHN-42, "Quét/ghi nhận item & component serial/quantity") và output riêng "Kết quả rà soát đồng thời" — **khác** với `WWHN-190.md` (Parent Temp Key `HN-IN-003`, task cha WWHN-43 "Ghi nhận nhập kho Post Receipt", output "ghi nhận nhập kho backend"). Cả hai đều đụng tới giao dịch/khóa đồng thời của cùng file `CurrentBaselineStockService.php`/`CurrentBaselineStockFlowController.php` vì 2 bước (scan và post-receipt) chia sẻ hạ tầng chung (transaction wrapper, optimistic lock `If-Match`, `lockForUpdate()`), nhưng WWHN-190 tập trung vào **post-receipt** (`postDocument()`/`postStockDocument()`), còn task này tập trung đúng phạm vi **HN-IN-002 = bước scan** (`inboundScan()`/`versionedScan()`/`scanStockDocument()`). Không lặp lại rà soát post-receipt đã có ở WWHN-190; tham chiếu kết quả đó khi hạ tầng dùng chung.

Cũng cần phân biệt với `WWHN-187.md` (BE2, cùng Parent Temp Key `HN-IN-002` nhưng task/output khác — "Inbound scan backend", tập trung vào **validation/error code** của scan, 27 test case; tên file cũ `WWHN-198-inbound-scan.md`, đã đổi tên 2026-08-18 sau khi xác nhận Jira live). Task này (BE1, key chính thức **WWHN-188**) tập trung đúng vào khía cạnh **giao dịch/khóa đồng thời** của cùng bước scan đó — bổ sung, không trùng lặp, với WWHN-187.

**Exact commit SHA khi bắt đầu rà soát**: `fad4242` (HEAD của `m1`), cùng working tree với thay đổi chưa commit của các task song song khác (WWHN-190/191/193/195/199/201/202/204/205/207 — xem `git status`). Task này chỉ thêm 1 file test mới (`tests/Feature/InboundScanConcurrencyBehaviorTest.php`) và không sửa file production nào.

### Phương pháp

Đọc trực tiếp `CurrentBaselineStockFlowController::inboundScan()`/`versionedScan()`/`scanPayload()`/`expectedVersion()`/`assertVersion()`/`lockDocument()` và `CurrentBaselineStockService::scanStockDocument()`/`resolveItem()`/`assertItemAvailableForReceipt()`. Đối chiếu với 2 bộ evidence liên quan đã có: `WWHN-190.md` (transaction/idempotency/concurrency của post-receipt, cùng hạ tầng) và `WWHN-187.md` (BE2, validation của scan, cùng endpoint; tên file cũ `WWHN-198-inbound-scan.md`). Rà soát riêng: file test hiện có cho scan (`Wwhn187InboundScanValidationTest.php`, tên cũ `Wwhn198InboundScanValidationTest.php`; `Wwhn199ReservationConcurrencyGuardTest.php`) đã cover khía cạnh nào của concurrency, khía cạnh nào chưa — rồi lấp đúng phần thiếu bằng test mới, không lặp lại.

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

**Gap tìm thấy trước khi rà soát này**: `Wwhn187InboundScanValidationTest::test_scan_rejects_stale_version_with_412` (tên cũ `Wwhn198InboundScanValidationTest`) chỉ gửi `If-Match` sai tĩnh (giá trị `99`) để test guard, không mô phỏng race thật giữa 2 writer — writer A đọc version, writer B đọc cùng version, A scan trước và thắng (bump version), B replay version cũ vào **request thật của B** rồi mới bị từ chối. `Wwhn199ReservationConcurrencyGuardTest` có cover race thật cho guard trùng item (`ITEM_ALREADY_RECEIVING`), nhưng không cover riêng optimistic version guard của chính request scan (2 line khác nhau, cùng document, cùng version ban đầu). Đây đúng là gap thuộc phạm vi "Kết quả rà soát đồng thời" của task này — khác với validation error code (đã đóng ở WWHN-187) và khác với post-receipt (đã đóng ở WWHN-190).

**Đã bổ sung**: `tests/Feature/InboundScanConcurrencyBehaviorTest.php` — 2 test case, lái qua HTTP thật:
- `test_stale_version_racer_is_rejected_after_winner_scan_commits`: document 2 line (khác SKU), 2 writer cùng đọc version=1. Writer A scan line 1 trước, thắng, version bump lên 2. Writer B replay version=1 cũ vào line 2 (line hoàn toàn khác, chưa từng bị đụng) — vẫn bị 412 `PRECONDITION_FAILED`, không tạo `inbound_scan_entries` cho line 2, `scanned_qty` của line 2 giữ nguyên 0. Sau đó writer B retry với version hiện tại (2) thì thành công (version bump lên 3) — chứng minh guard chỉ chặn đúng request mang version cũ, không chặn nhầm line B.
- `test_scan_with_current_version_on_posted_document_is_rejected_by_state_guard`: scan xong 1 line rồi post-receipt (document chuyển `POSTED`, version=3). Gửi tiếp request scan với `If-Match: 3` — đúng version hiện tại thật — nhưng vẫn bị 409 `STATE_CONFLICT` vì document đã `POSTED`, không tạo thêm `inbound_scan_entries`. Xác nhận state guard là lớp phòng thủ độc lập, không chỉ dựa vào version, đúng nguyên tắc defense-in-depth đã áp dụng nhất quán ở post-receipt (WWHN-190).

Không phát hiện bug chức năng nào — cả 2 test case pass ngay lần chạy đầu tiên, xác nhận guard hoạt động đúng theo thiết kế.

### 3. Idempotency cho scan — ĐÃ XÁC NHẬN theo phạm vi (scan không dùng Idempotency-Key)

Endpoint `POST /inbound-documents/{id}/scan` **không** yêu cầu header `Idempotency-Key` (chỉ `post-receipt`/`reverse` yêu cầu, đã xác nhận ở WWHN-190) — đây là quyết định thiết kế nhất quán: scan là thao tác lặp lại nhiều lần trong vòng đời 1 document (mỗi item/component 1 lần scan), không phải hành động một-lần cuối luồng như Post/Cancel, nên không cần idempotency-key theo semantics "replay an toàn". An toàn dữ liệu khi client retry mạng (network retry) vẫn được đảm bảo gián tiếp qua guard `DUPLICATE_ITEM_IN_DOCUMENT`/`ITEM_ALREADY_RECEIVING` (item đã scan vào document → lần scan lặp lại cùng item bị chặn 409, không tạo `inbound_scan_entries` trùng) — không phải cơ chế idempotency-key cổ điển nhưng đạt cùng mục tiêu chống ghi trùng dữ liệu nghiệp vụ cho action tự nhiên "add item vào document". Không phải gap — không tự ý thêm `Idempotency-Key` cho scan vì đây là thay đổi contract vượt phạm vi rà soát, cần BA/Tech Lead xác nhận nếu muốn đổi.

### 4. Permission/RBAC cho scan — ĐẦY ĐỦ, không cần sửa

- Route `POST inbound-documents/{id}/scan` gắn permission `inbound.scan` + middleware `EnsureInboundComponentBox` (chỉ áp dụng dòng COMPONENT).
- Đã có test hành vi thật: 401 `UNAUTHENTICATED` (thiếu auth), 403 `ACCESS_DENIED` (thiếu quyền) — `Wwhn187InboundScanValidationTest` (tên cũ `Wwhn198InboundScanValidationTest`) test case 1-2.

### 5. Audit log cho scan — Xác nhận pattern nhất quán, không phải gap riêng của task này

Đã xác nhận lại (kế thừa từ rà soát của BE2 ở `WWHN-187.md` mục 4, tên file cũ `WWHN-198-inbound-scan.md`): scan không ghi `AuditLogger::record()` riêng (chỉ final actions như `POST_RECEIPT`/`CANCEL` mới ghi `audit_log`), nhưng có evidence trail riêng qua `scan_event`/`scan_batch`/`inbound_scan_entries` (đầy đủ `created_by`/timestamp). Đây là quyết định thiết kế xuyên suốt hệ thống, đã ghi nhận là open point cho BA/Tech Lead ở `BE2-INSCAN-OP-01` trong `WWHN-187.md` — không lặp lại xử lý ở đây, tham chiếu cùng open point.

### 6. Error handling — ĐẦY ĐỦ, không cần sửa

- Toàn bộ lỗi guard (412/409/428/422) trả qua `ApiError::abort()` — envelope chuẩn hóa, không lộ stack trace (kế thừa xác nhận từ WWHN-190 cho cùng hạ tầng `ApiError`).
- Test mới bổ sung xác nhận: request bị chặn không tạo `inbound_scan_entries`, không tăng `scanned_qty` — không có side-effect dữ liệu sai khi guard kích hoạt.
- Mọi lỗi trong transaction tự động rollback (kế thừa cơ chế `DB::transaction` đã xác nhận ở mục 1).

### 7. Test coverage — trước và sau

| Khía cạnh | Trạng thái trước (riêng scan) | Trạng thái sau |
|---|---|---|
| Transaction/atomicity (source-level) | Có (đọc code, khớp pattern post-receipt) | Không đổi — không cần test riêng vì cùng cơ chế `DB::transaction` đã verify ở WWHN-190 |
| Version guard — request sai tĩnh (`If-Match` giá trị bất kỳ khác) | Có (`Wwhn187InboundScanValidationTest`, tên cũ `Wwhn198InboundScanValidationTest`) | Giữ nguyên, không lặp lại |
| Version guard — race thật (2 writer cùng đọc version, 1 bên thắng trước) | **Không có** | **Đã bổ sung**: `InboundScanConcurrencyBehaviorTest::test_stale_version_racer_is_rejected_after_winner_scan_commits` |
| State guard sau khi version đã hợp lệ nhưng document đã POSTED | **Không có** (chỉ test state guard qua nhánh dùng SKU khác ở `Wwhn187InboundScanValidationTest`, không phải race version→state) | **Đã bổ sung**: `InboundScanConcurrencyBehaviorTest::test_scan_with_current_version_on_posted_document_is_rejected_by_state_guard` |
| Item-level row lock / dedup trùng item khi race (`ITEM_ALREADY_RECEIVING`) | Có (`Wwhn199ReservationConcurrencyGuardTest`) | Giữ nguyên, không lặp lại |
| Permission 401/403 trên route scan | Có (`Wwhn187InboundScanValidationTest`, tên cũ `Wwhn198InboundScanValidationTest`) | Giữ nguyên, không lặp lại |

Kết quả chạy: `php artisan test tests/Feature/InboundScanConcurrencyBehaviorTest.php` → **2/2 passed, 20 assertions**.

Kết quả chạy bộ evidence liên quan (test mới + toàn bộ test concurrency/scan có sẵn của WWHN-190/198/199): `php artisan test tests/Feature/InboundScanConcurrencyBehaviorTest.php tests/Feature/Wwhn198InboundScanValidationTest.php tests/Feature/Wwhn199ReservationConcurrencyGuardTest.php tests/Feature/PostReceiptConcurrencyBehaviorTest.php tests/Feature/StockDocumentConcurrencyGuardTest.php tests/Feature/Wwhn180IdempotencyAtomicityTest.php tests/Feature/PostReceiptAuditLogTest.php` → **48/48 passed, 240 assertions**.

`vendor/bin/pint --test tests/Feature/InboundScanConcurrencyBehaviorTest.php` → passed (không cần auto-fix).

Kết quả chạy full suite (xác nhận không regression toàn cục, bao gồm cả thay đổi song song chưa commit của các task khác đang nằm trong cùng working tree): `php artisan test` → **427 tests, 411 passed, 16 skipped (baseline có sẵn, Postgres-gated/môi trường, không liên quan tới thay đổi này), 0 failed, 4816 assertions** — tăng đúng 2 test/20 assertions so với baseline trước khi bắt đầu (425 tests/409 passed/4796 assertions, ghi nhận ở `WWHN-198-inbound-scan.md`).

**Xác minh lại 2026-08-15 (phiên rà soát WWHN-188, tham chiếu file này)**: re-run cùng bộ test → 34/34 passed (subset), full suite → 427 tests/411 passed/16 skipped/4816 assertions — khớp chính xác số liệu trên, không có sai lệch/regression.

### 7b. Đóng DEV188-P1-02 và DEV188-P1-03 bằng evidence thật (2026-08-18, BE1)

Reviewer (đánh giá 62/100 và 68/100) từ chối evidence race trước đó vì `InboundScanConcurrencyBehaviorTest` chỉ replay tuần tự 2 HTTP request trên **cùng một kết nối SQLite** — chưa từng có 2 kết nối/tiến trình PostgreSQL thật cạnh tranh nhau. Đã đóng dứt điểm bằng cách chạy race thật trên PostgreSQL cục bộ (container `wms_warehouse_hoa_nam_be-postgres-1`, đang chạy, driver `pgsql`), theo đúng pattern đã được chấp nhận ở WWHN-219 (`scripts/wwhn219-runtime-concurrency.php`/`wwhn219-concurrency-worker.php`).

**DEV188-P1-02 — race thật ≥2 connection/process**: `scripts/wwhn188-inbound-scan-runtime-concurrency.php` (orchestrator) + `scripts/wwhn188-inbound-scan-worker.php` (worker, 1 tiến trình OS = 1 backend PostgreSQL thật) spawn 2 tiến trình độc lập gọi thẳng `CurrentBaselineStockFlowController::inboundScan()` (code path production thật, không mock) cho 3 kịch bản đúng yêu cầu reviewer:

| Kịch bản | Cạnh tranh trên | Kết quả thật (chạy 2026-08-18) |
|---|---|---|
| `same_document_same_line` — cùng document, cùng line, cùng version=1 | row lock `stock_documents` | 2 backend PID phân biệt (36898/36899), có `pg_locks` wait quan sát được (`observed_pg_lock_wait=true`, max wait 1447ms). Đúng 1 writer thắng (200, version 1→2, `scanned_qty` +1 đúng 1 lần); writer thua bị 412 `STALE_VERSION`, không tạo thêm `inbound_scan_entries`. |
| `same_document_different_line` — cùng document, cùng version=1, khác line | row lock `stock_documents` | 2 PID phân biệt, wait quan sát được (max 1483ms). Writer thắng commit trước (version 1→2); writer thua bị 412 dù line của chính nó **chưa từng bị đụng** — chứng minh khóa optimistic là cấp document, không phải cấp line. Line của writer thua vẫn `scanned_qty=0`. |
| `same_item_different_document` — cùng item (serial), 2 document khác nhau, mỗi document tự version=1 | row lock `item_instances` (trong `resolveItem()`) | 2 PID phân biệt, wait quan sát được (max 1398ms). Đúng 1 writer thắng; writer thua bị 409 `ITEM_ALREADY_RECEIVING` (không phải version guard — 2 document độc lập version nên không đụng version, đúng guard phải kích hoạt là item-level). Đúng 1 `inbound_scan_entries` cho item dùng chung. |

Cả 3 kịch bản: `distinct_backend_pid_count=2` (2 kết nối PostgreSQL thật, không phải mock/giả lập) và `real_contention_proven=true` (quan sát được `pg_locks`/`pg_stat_activity` wait thật, không chỉ suy luận từ thời gian). Evidence JSON đầy đủ tại `storage/app/wwhn-188-evidence/runtime-concurrency-summary.json` (không commit — gitignore theo `storage/app/.gitignore`, cùng quy ước với WWHN-219).

Gated test: `tests/Feature/InboundScanRuntimeConcurrencyTest.php` (skip nếu không phải `pgsql` hoặc thiếu `WWHN188_RUNTIME_CONCURRENCY=1`, cùng cơ chế gate với `Wwhn219RuntimeConcurrencyTest`). Chạy thật:

```
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=wms_hoanam DB_USERNAME=postgres DB_PASSWORD=postgres \
WWHN188_RUNTIME_CONCURRENCY=1 php vendor/bin/phpunit --filter=InboundScanRuntimeConcurrencyTest
```
→ **1/1 passed, 14 assertions** (chạy lại 2 lần liên tiếp đều pass, evidence tái lập được — fixture warehouse tái sử dụng warehouse ACTIVE sẵn có để không vi phạm `uk_warehouses_single_active` khi rerun).

**DEV188-P1-03 — chứng minh (không chỉ mô tả) hành vi retry quantity-mode**: thêm `InboundScanConcurrencyBehaviorTest::test_component_quantity_mode_rescan_after_client_refetch_can_double_count_pending_ba_decision` — chạy thật kịch bản "lost-response, client re-fetch, retry với version mới": scan quantity=2 thành công (version 1→2, `scanned_qty`=2), rồi retry **đúng request đó** với version hiện tại (2) như một client tưởng request đầu bị mất response. Kết quả thật: request thứ 2 được chấp nhận (200, version 2→3), `scanned_qty` thành **4** (double-count) — xác nhận bằng test thật, không phải suy luận, đúng gap `BE1-SCANCONC-OP-03` đã nêu ở mục "Rà soát bổ sung". Test này **không sửa hành vi** (giữ nguyên `assertJsonPath('data.scanned_quantity', 4)`) vì thay đổi outcome (thêm Idempotency-Key hoặc dedupe window cho scan) là thay đổi contract/business rule cần BA/Tech Lead quyết định theo đúng phạm vi task — xem cập nhật open point ở mục 11.

Kết quả chạy: `php vendor/bin/phpunit --filter=InboundScanConcurrencyBehaviorTest` → **3/3 passed, 30 assertions** (2 test cũ + 1 test mới). `php vendor/bin/phpunit --filter="InboundScanConcurrencyBehaviorTest|Wwhn187InboundScanValidationTest|Wwhn199ReservationConcurrencyGuardTest|PostReceiptConcurrencyBehaviorTest|StockDocumentConcurrencyGuardTest|Wwhn46OutboundPickScanDedupTest"` → **51/51 passed, 235 assertions**. `php vendor/bin/pint --test` trên 4 file mới/sửa → pass (1 file cần auto-fix format, đã áp dụng). Full suite sqlite: **578 tests, 555 passed, 1 failed, 22 skipped** — 1 fail duy nhất là `Wwhn330ImportHardeningTest` (file untracked của task WWHN-330 khác, không liên quan scan/concurrency, đã xác nhận không phải do thay đổi của task này gây ra).

### 8. File thay đổi

| File | Loại | Nội dung |
|---|---|---|
| `tests/Feature/InboundScanConcurrencyBehaviorTest.php` | Cập nhật (2026-08-18: +1 test) | 2 test case HTTP end-to-end cho race thật của optimistic version guard trên chính request scan (khác `Wwhn187InboundScanValidationTest` — request sai tĩnh; khác `Wwhn199ReservationConcurrencyGuardTest` — item-level dedup); **+1 test mới** chứng minh hành vi retry double-count của COMPONENT quantity-mode (DEV188-P1-03, `BE1-SCANCONC-OP-03`) |
| `tests/Feature/InboundScanRuntimeConcurrencyTest.php` | Mới (2026-08-18) | Gated PostgreSQL runtime-concurrency test (đóng DEV188-P1-02) — chạy `scripts/wwhn188-inbound-scan-runtime-concurrency.php`, cùng pattern gate `Wwhn219RuntimeConcurrencyTest` |
| `scripts/wwhn188-inbound-scan-runtime-concurrency.php` | Mới (2026-08-18) | Orchestrator: dựng fixture cô lập, spawn 2 tiến trình worker thật, sample `pg_locks`, assert 3 kịch bản race (same doc/version+same line; same doc/version+different line; same item/different doc) |
| `scripts/wwhn188-inbound-scan-worker.php` | Mới (2026-08-18) | Worker: 1 tiến trình OS = 1 backend PostgreSQL thật, gọi thẳng `CurrentBaselineStockFlowController::inboundScan()` (code path production) sau khi pre-lock đúng row đang tranh chấp |
| `docs/docs_task_jira/WWHN-188.md` | Cập nhật (file này) | Evidence rà soát giao dịch/khóa đồng thời cho HN-IN-002 (bước scan), phân biệt rõ với WWHN-190 (HN-IN-003, post-receipt) và WWHN-187 (HN-IN-002, validation). **Cập nhật 2026-08-18**: đính chính traceability sau khi xác nhận Jira live — key chính thức của task này là WWHN-188 (không còn trùng với WWHN-187, xem đầu mục Evidence); bổ sung mục 7b đóng DEV188-P1-02/P1-03 bằng evidence thật |

**Không sửa code sản xuất nào** — toàn bộ transaction wrapper, optimistic lock, row lock, state guard của scan đã đúng theo baseline từ trước; rà soát chỉ phát hiện gap coverage test (mục 2), không phải bug.

### 9. API docs (OpenAPI/Postman) — không cần sửa

Không đổi contract (chỉ bổ sung test hành vi cho error code `PRECONDITION_FAILED`/`STATE_CONFLICT` đã có sẵn trong OpenAPI annotation từ trước, xác nhận khớp ở WWHN-190/WWHN-187) — không cần cập nhật `docs/api-contract/`, Postman collection, hay payload mẫu.

### 10. Đối chiếu Acceptance Criteria

| # | Acceptance Criteria (WWHN-188, HN-IN-002) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "Kết quả rà soát đồng thời" được rà soát/xác minh, gắn evidence, không còn blocker thuộc vai trò BE1 | **Done** — rà soát transaction/idempotency/concurrency guard riêng cho bước scan, phân biệt rõ với post-receipt (WWHN-190) và validation (WWHN-187); lấp gap race-condition test còn thiếu; evidence xác minh lại đúng tại thời điểm rà soát file này; traceability số hiệu đã chốt 2026-08-18 | Mục 1-6 |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ và dependency; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — transaction atomic, optimistic + row lock xác nhận qua test race thật, state guard defense-in-depth, permission 401/403 có test, audit qua `scan_event` (pattern nhất quán, không phải gap riêng) | Mục 1-5 |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** — test mới xác nhận racer thua cuộc không tạo `inbound_scan_entries`/không tăng `scanned_qty`; `ApiError::abort()` không lộ stack trace (kế thừa WWHN-190) | Mục 2, 6 |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done** — 2 test case HTTP mới; không cần cập nhật OpenAPI/Postman (không đổi contract) | Mục 7-9 |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Không phát hiện blocker P0/P1 kỹ thuật mới** thuộc vai trò BE1 cho khía cạnh giao dịch/khóa đồng thời của scan | — |

### 11. Blocker / Open point

| ID | Nội dung | Owner đề xuất | Disposition |
|---|---|---|---|
| BE1-SCANCONC-OP-01 (kế thừa từ `WWHN-187.md`, `BE2-INSCAN-OP-01`) | Không có `AuditLogger::record()` riêng cho hành động scan — chỉ có `scan_event`/`scan_batch` evidence trail. Cần BA/Tech Lead xác nhận có đủ hay cần audit_log riêng | BA/Tech Lead | Mở, kế thừa (không lặp lại xử lý ở task này) |
| BE1-SCANCONC-OP-02 | Scan không dùng `Idempotency-Key` (khác post-receipt/reverse) — an toàn dữ liệu dựa vào guard trùng item, không phải cơ chế idempotency-key cổ điển. Xác nhận đây là thiết kế chủ đích, không phải gap; ghi nhận để BA xác nhận nếu baseline gốc yêu cầu khác | BA/Tech Lead | Mở, rủi ro thấp (không chặn release) |
| BE1-DUPKEY-188-01 | ~~Hai số hiệu Jira `WWHN-187` và `WWHN-188` trỏ tới cùng một task Jira thật... cần BA/PM xác nhận số hiệu chính xác duy nhất~~ | — | **Đã đóng (2026-08-18)** — đã fetch Jira live (danh sách subtask của WWHN-42) và xác nhận: WWHN-187 = "[BE] Triển khai quét/ghi nhận/validation" (BE2, task khác, nội dung tại `WWHN-187.md`), WWHN-188 = "[BE] Rà soát giao dịch/khóa đồng thời" (BE1, task này). Không phải trùng key — giả định ban đầu (dựa trên nội dung task giống hệt nhau ở thời điểm 2026-08-15) sai vì khi đó chưa đối chiếu được với Jira live. Đã cập nhật traceability note ở đầu file. |
| BE1-SCANCONC-OP-03 (phát hiện trong rà soát bổ sung; **đã có evidence thật 2026-08-18**) | Scan COMPONENT quantity-mode (không có `item_id`) không có guard chống double-count khi client retry đúng request trong ngưỡng `qty_planned` — khác nhánh có `item_id` (dedupe tuyệt đối qua row lock + unique index). **2026-08-18**: gap đã được chứng minh bằng test thật (`InboundScanConcurrencyBehaviorTest::test_component_quantity_mode_rescan_after_client_refetch_can_double_count_pending_ba_decision`) — scan quantity=2 rồi "re-fetch + retry" với version mới làm `scanned_qty` thành 4 thay vì 2, không phải suy đoán. Cần BA xác nhận đây có phải rủi ro vận hành thật (tuỳ network reliability thực tế và UI có debounce/loading-lock chặn double-submit hay không) trước khi quyết định phương án khắc phục (Idempotency-Key optional cho scan, hoặc dedupe theo cửa sổ thời gian, hoặc chấp nhận rủi ro vì tần suất thấp) | BA/Tech Lead | Mở — evidence đã đủ, chờ quyết định thiết kế (không tự sửa vì là thay đổi contract) |
| BE1-SCANCONC-OP-04 (mới, đã xác minh và đóng ngay trong task này) | Đã kiểm chứng giả thuyết `CurrentBaselineStockController` (dead code, thiếu `If-Match`/`Idempotency-Key` trên scan) có thể đang bị route nào bind nhầm — xác nhận bằng `php artisan route:list` thật: **không route nào** trỏ tới controller này. Không phải rủi ro thật, không cần hành động thêm | — | Đã đóng (không phải bug) |

### 12. Định nghĩa Done — thực tế

#### BE1 technical completion (mục này)

Đạt: rà soát transaction/concurrency/idempotency đầy đủ cho bước inbound scan (chi tiết mục 1-6) — transaction atomic, optimistic lock (`If-Match`) và row lock (`lockForUpdate` trên `stock_documents`/`item_instances`) hoạt động đúng qua test race thật (`InboundScanConcurrencyBehaviorTest`), state guard defense-in-depth độc lập với version. Re-run toàn bộ test liên quan (34/34) và full suite (411/411, 16 skip baseline không liên quan) tại thời điểm rà soát — không có sai lệch hay regression. **Cập nhật 2026-08-18**: đính chính traceability — file này là evidence gốc, duy nhất của task WWHN-188 (không phải bản tham chiếu của WWHN-187 như giả định sai ban đầu ngày 2026-08-15); xem đầu mục Evidence.

**Đi sâu hơn ngoài việc tái xác nhận**: tự chạy thêm một vòng rà soát độc lập thay vì chỉ chấp nhận evidence cũ, phát hiện 1 gap thật chưa từng được ghi nhận trước đây (scan COMPONENT quantity-mode thiếu guard chống double-count khi client retry — mục "Rà soát bổ sung"), và chủ động kiểm chứng bằng `route:list` thật một giả thuyết rủi ro (dead-code controller có thể bị bind nhầm route) — xác nhận không phải rủi ro, đóng ngay trong task này. Không sửa code sản xuất nào (gap #1 cần quyết định thiết kế từ BA trước khi có phương án sửa cụ thể; gap #2 không cần sửa vì không phải bug). Bổ sung 3 open point mới: trùng số hiệu Jira (BE1-DUPKEY-188-01), gap idempotency scan quantity-mode (BE1-SCANCONC-OP-03), và 1 mục đã xác minh-đóng ngay (BE1-SCANCONC-OP-04).

**Cập nhật 2026-08-18 (đóng DEV188-P1-02/P1-03 bằng evidence thật, xem mục 7b)**: reviewer từ chối evidence race trước đó vì chỉ replay HTTP tuần tự trên 1 kết nối SQLite. Đã build và chạy thật race PostgreSQL 2-process/2-connection (`scripts/wwhn188-inbound-scan-runtime-concurrency.php` + worker, gated `InboundScanRuntimeConcurrencyTest`) cho đúng 3 kịch bản reviewer yêu cầu — same doc/version+same line, same doc/version+different line, same item/different doc — cả 3 đều pass với 2 backend PostgreSQL PID phân biệt thật và `pg_locks` wait quan sát được thật (không suy luận). Đồng thời chứng minh bằng test thật (không chỉ mô tả) hành vi double-count của COMPONENT quantity-mode khi client re-fetch+retry, đóng góp evidence cụ thể cho quyết định BA ở `BE1-SCANCONC-OP-03`. Không có bug mới phát sinh — cả 2 phần việc đều là bổ sung evidence/test, không sửa code sản xuất, không đổi contract.

#### Còn lại — không thuộc phạm vi tự nhận của BE1

- ~~BA/PM xác nhận số hiệu Jira chính xác duy nhất cho task này~~ — Đã đóng 2026-08-18 (BE1-DUPKEY-188-01), xem đầu mục Evidence.
- BA/Tech Lead xác nhận disposition cho audit log riêng của bước scan (BE1-SCANCONC-OP-01, kế thừa).
- BA/Tech Lead xác nhận thiết kế "scan không cần Idempotency-Key" là đúng baseline cho nhánh có `item_id`, và quyết định phương án cho nhánh quantity-mode (BE1-SCANCONC-OP-02, BE1-SCANCONC-OP-03 mới).
- QA xác nhận evidence (mục 2, 7, 10) đáp ứng đúng Acceptance Criteria.
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE1 tự ghi trong file này.

---

## 13. Vòng R2 — đóng P0/P1/P2 của bản đánh giá 62/100 (2026-08-19, BE1)

Vòng trước bị từ chối vì ba lý do có thể kiểm bằng máy, không phải vì thiếu chữ:
evidence race nằm trong `storage/` (bị gitignore) nên reviewer **không đọc lại được**;
defect double-count quantity-mode chỉ được **mô tả** chứ chưa **sửa**; và candidate
không được pin nên không ai biết "đã review bản nào". Vòng này đóng cả ba.

Sổ đóng đầy đủ: `docs/evidence/WWHN-188/WWHN-188_CLOSURE_REGISTER.md`.
Verifier: `node scripts/verify-wwhn-188-closure.mjs [--expect-sha=<candidate>]`.

### 13.1 `DEV188-P1-03R2` — sửa defect, không chỉ mô tả

Reviewer xác nhận **VERIFIED DEFECT**: COMPONENT quantity-mode cộng `scanned_qty`
hai lần khi client mất response → tải lại phiếu → gửi lại với version mới hợp lệ.
`If-Match` không thể bắt được vì version hoàn toàn đúng.

Đã đóng bằng `DEC-SCAN-IDEMP-001` (`docs/decision-records/`): `Idempotency-Key`
**tùy chọn** cho scan (đúng Annex C — scan là stock-neutral, thiếu header **không**
trả 428), kết hợp fingerprint **tất định** `App\Support\ScanRequestFingerprint`.

Fingerprint cố ý **không chứa version và không chứa mốc thời gian** — đây chính là
điểm reviewer yêu cầu ("deterministic fingerprint thay vì time-window heuristic"):
nếu chứa version thì retry sau re-fetch lại ra fingerprint khác và defect vẫn còn.

| Tình huống | Kết quả sau fix |
|---|---|
| lost-response retry (cùng key, `If-Match` cũ) | 200, `data.replayed=true`, `scanned_qty` không đổi |
| stale retry | 200 replay, không 412, `scanned_qty` không đổi |
| re-fetch retry (`If-Match` mới) — **chính defect** | 200 replay, `scanned_qty` không đổi (trước là ×2) |
| chuỗi 6 lần retry xen kẽ version cũ/mới | áp dụng đúng **một** lần |
| cùng key, khác nội dung | 409 `IDEMPOTENCY_CONFLICT` |
| khác key, cùng nội dung | lần quét thật thứ hai, vẫn cộng dồn (không nuốt oan) |
| không gửi key | y như trước, **không** 428 |

Bằng chứng: `tests/Feature/Wwhn188ScanIdempotencyContractTest.php` — **10 passed,
100 assertions**, mọi case kiểm ở tầng DB (`scanned_qty`, `version`,
`inbound_scan_entries`, `idempotency_keys`) chứ không chỉ HTTP status. Test cũ ghi
nhận defect đã được chuyển thành test ghi nhận **đã sửa**
(`InboundScanConcurrencyBehaviorTest::test_component_quantity_mode_refetch_retry_is_deduped_by_idempotency_key`),
đồng thời vẫn khoá lại nhánh "không có key giữ nguyên hành vi cũ" để hành vi đó là
lựa chọn có chủ đích chứ không phải sót.

OpenAPI đã cập nhật (`operationId: stock_in_scan`): thêm tham số `Idempotency-Key`
(optional, 8..100), 200 mô tả `data.replayed` + header `Idempotent-Replay`, 409 liệt
kê `IDEMPOTENCY_CONFLICT`/`CONCURRENCY_CONFLICT`. Bản trích + SHA-256 canonical:
`docs/evidence/WWHN-188/openapi/`.

### 13.2 `DEV188-P1-02R2` — race thật, đọc lại được

Evidence chuyển từ `storage/app/wwhn-188-evidence` (gitignore) sang
`docs/evidence/WWHN-188/runtime/` (commit vào repo). Bổ sung đúng những gì reviewer
đòi mà bản cũ thiếu: **PostgreSQL version**, **candidate SHA**, **mốc thời gian thô
từng worker** (chứng minh hai tiến trình chồng lấn thật, không phải chạy nối tiếp),
**snapshot DB before/after** từng kịch bản, và blob SHA của chính các artifact được
chứng minh.

Bốn kịch bản, mỗi kịch bản hai tiến trình OS = hai backend PostgreSQL phân biệt:

| Kịch bản | Tranh chấp | Kết quả |
|---|---|---|
| `same_document_same_line` | row lock `stock_documents` | 1 winner; loser 412 `STALE_VERSION`, không side effect |
| `same_document_different_line` | row lock `stock_documents` | loser bị 412 dù dòng của nó chưa bị đụng ⇒ khoá là **cấp document** |
| `same_item_different_document` | row lock `item_instances` | 1 winner; loser 409 `ITEM_ALREADY_RECEIVING`; đúng 1 scan entry |
| `same_idempotency_key_quantity_mode` *(mới)* | lease `idempotency_keys` | 1 worker áp dụng; worker kia 409 `CONCURRENCY_CONFLICT`; `scanned_qty` +2 **đúng một lần** |

Kịch bản thứ tư là bằng chứng `DEC-SCAN-IDEMP-001` đứng vững dưới race thật chứ
không chỉ trong test tuần tự — đo được **overlap 2.69s** giữa hai tiến trình.

Gate: `tests/Feature/InboundScanRuntimeConcurrencyTest.php` (skip nếu không phải
pgsql hoặc thiếu `WWHN188_RUNTIME_CONCURRENCY=1`) + workflow CI
`.github/workflows/wwhn-188-scan-concurrency-gate.yml` (job PostgreSQL 16 riêng).

### 13.3 `DEV188-P1-01R2` / `P1-04R2` / `P1-05R2` / `P1-06R2` / `P2-01`

| ID | Đã làm | Nơi đọc |
|---|---|---|
| `P1-01R2` | Hồ sơ supersede `ATT-11852`; nói rõ **WWHN-187 = implementation, WWHN-188 = concurrency**; ánh xạ blob/commit SHA thật | `docs/evidence/WWHN-188/WWHN-188_TRACE_SUPERSESSION.md` |
| `P1-04R2` | Candidate QA phủ đủ 401/403/409/412/422/428 + duplicate/serial/quantity/race/lost-response retry/rollback, mỗi ô trỏ **một test đang chạy được** | `docs/evidence/WWHN-188/WWHN-188_QA_TEST_MATRIX.md` |
| `P1-05R2` | Pin **hai tầng**: commit cuối cùng *chạm artifact* (không dùng floating `m1` HEAD) + blob SHA từng file; verifier có `--expect-sha` | `docs/evidence/WWHN-188/WWHN-188_CANDIDATE_PIN.json`, `scripts/pin-wwhn-188-candidate.mjs` |
| `P1-06R2` | Source Register cho `WWHN-42`: nguồn hiện hành + supersession `BRD v3.9 → v3.20` theo SRS v1.12 §1.6, kèm neo yêu cầu riêng của luồng scan | `docs/evidence/WWHN-188/WWHN-188_SOURCE_REGISTER.md` |
| `P2-01` | Dọn fixture `WWHN187-*` trong test concurrency; verifier chặn tái phát | `tests/Feature/InboundScanConcurrencyBehaviorTest.php` |

### 13.4 Kết quả chạy tại candidate

- `Wwhn188ScanIdempotencyContractTest` → **10 passed, 100 assertions**.
- 6 file scan/concurrency (sqlite) → **51 passed, 317 assertions, 0 failed**.
- Runtime concurrency PostgreSQL 16.14 → **4/4 kịch bản PASS**, mỗi kịch bản 2
  backend PID phân biệt, `pg_locks` wait quan sát được ở 3 kịch bản row-lock.
- `vendor/bin/pint --test` trên toàn bộ file mới/sửa → pass.

### 13.5 Cập nhật open point

| ID | Trạng thái mới |
|---|---|
| `BE1-SCANCONC-OP-02` (scan không dùng Idempotency-Key) | **Đã đóng** bằng `DEC-SCAN-IDEMP-001` — optional, đúng Annex C |
| `BE1-SCANCONC-OP-03` (double-count quantity-mode) | **Đã sửa**, có test tái lập trước/sau và race thật |
| `BE1-SCANCONC-OP-01` (audit log riêng cho scan) | Vẫn mở, kế thừa từ `WWHN-187`, chờ BA/Tech Lead |
| `BE1-SCANCONC-OP-05` *(mới)* | Outbound scan có cùng hình dạng gap (label dispatch không idempotent) — ngoài phạm vi WWHN-188, cần Tech Lead quyết trong Flow 03 |
| `BE1-SCANCONC-OP-06` *(mới)* | Có siết `Idempotency-Key` thành bắt buộc sau MVP không — BA + Tech Lead, không chặn release |

### 13.6 Điều BE1 KHÔNG tự nhận

`DEC-SCAN-IDEMP-001` đang ở trạng thái **PROPOSED**: code đã hiện thực đúng nội dung
đó và revert được nguyên khối (không migration, không đổi schema). Việc ký duyệt
semantics thuộc BA + Tech Lead; việc đính kèm/superseded/đổi trường nguồn trên Jira
thuộc BA/PM; việc nghiệm thu ma trận test thuộc QA1. BE1 không tự đánh dấu Done.
