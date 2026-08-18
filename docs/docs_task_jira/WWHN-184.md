# WWHN-184 — [BE1] Triển khai API chứng từ/trạng thái nhập kho

> Vai trò: **BE1** (Quang Nguyễn Thanh — placeholder `HN-BE-01`)
> Task cha: **WWHN-41 / HN-IN-001** — [INBOUND] Tạo/chỉnh/duyệt chứng từ nhập
> Dependency: **HN-PIN-05; HN-FND-001; HN-MC-001**
> Nhánh triển khai: `m1`
> Exact commit SHA (fix lần rà soát này): `157968b7e6a863b626d5090eef051d80b363012d` — BE
> `[fix(WWHN-184): reconcile inbound.cancel|inbound.create contract, close Swagger scan gap]`
> FE đồng hành: `09e0dd376f04610505669c79009478030c3a9223` (repo
> `WMS_Warehouse_Hoa_Nam_FE`, branch `fix/wwhn-184-p1-cancel-permission`) —
> `[fix(WWHN-184): let inbound.create satisfy Cancel like the BE route does]`

## 0. Sửa lỗi mapping ticket (nguồn gốc DEV184-P1-01R)

File này **trước đây bị ghi sai nội dung**: bản cũ (commit `97bafcd`) chép lại
100% nội dung của WWHN-56/224 (BE2 — SKU import dry-run), tự nhận "nội dung
JIRA của WWHN-184 khớp 100% với WWHN-224". Đó là kết luận sai — WWHN-184 JIRA
thực tế có tiêu đề "[BE] Triển khai API chứng từ/trạng thái nhập kho", task
cha **WWHN-41** (Inbound), vai trò **BE1**, hoàn toàn khác WWHN-56/HN-DATA-001
(Import SKU, vai trò BE2). Đây chính là nguồn gốc của lỗi P1 "Attachment
WWHN-184 ATT-11831 trỏ nhầm sang WWHN-56/WWHN-224" mà reviewer nêu ngày
2026-08-17 — không chỉ là gắn nhầm attachment trên Jira, mà là tài liệu gốc
trong repo mô tả sai luôn task. File cũ được giữ nguyên nội dung tại
`docs/docs_task_jira/WWHN-224.md` (đúng chỗ của nó); file này viết lại từ đầu
đúng phạm vi WWHN-184/WWHN-41.

## 1. Phạm vi thực tế đã rà soát

Toàn bộ Inbound Document API (`/api/v1/inbound-documents*`) — vòng đời
DRAFT → SCANNING → POSTED/CANCELLED, không có approval workflow, không có
Submit/Approve/Reject (baseline hiện hành xác nhận qua docblock
`InboundDocumentController.php`, khớp DEV184-P1-04R sau khi BA/PM/PO xác
nhận baseline hiện hành ở mục 5):

| Method | Path | Controller@action (runtime thật) | Permission | Service ghi dữ liệu |
|---|---|---|---|---|
| GET | `/inbound-documents` | `InboundDocumentController::index` | `inbound.view` | `StockDocumentService` |
| POST | `/inbound-documents` | `CurrentBaselineDocumentController::storeInbound` | `inbound.create` + `EnsureCanonicalInboundWarehouse` | — (chỉ tạo DRAFT, không đổi tồn) |
| GET | `/inbound-documents/{id}` | `InboundDocumentController::show` | `inbound.view` | `StockDocumentService` |
| PATCH | `/inbound-documents/{id}` | `CurrentBaselineDocumentController::updateInbound` | `inbound.create` + If-Match | — |
| POST | `/inbound-documents/{id}/cancel` | `CurrentBaselineDocumentController::cancelInbound` | `inbound.cancel\|inbound.create` + If-Match | — (stock-neutral) |
| POST | `/inbound-documents/{id}/boxes` | `CurrentBaselineInboundBoxController::store` | `inbound.scan` | — |
| POST | `/inbound-documents/{id}/scan` | `CurrentBaselineStockFlowController::inboundScan` | `inbound.scan` + `EnsureInboundComponentBox` + If-Match | `CurrentBaselineStockService::scanStockDocument` (stock-neutral) |
| POST | `/inbound-documents/{id}/unscan` | `CurrentBaselineStockFlowController::inboundUnscan` | `inbound.scan` + If-Match | — (ERD-28 status REMOVED, stock-neutral) |
| POST | `/inbound-documents/{id}/post-receipt` | `CurrentBaselineStockFlowController::postReceipt` | `inbound.post` + If-Match + Idempotency-Key | `CurrentBaselineStockService::postStockDocument` (duy nhất ghi `stock_movements`/`inventory_balances`) |
| POST | `/inbound-documents/{id}/reverse` | `CurrentBaselineStockFlowController::reverseInbound` | `inventory.reversal.post` + If-Match + Idempotency-Key | reversal aggregate liên kết movement gốc |

## 2. DEV184-P1-01R — Permission Cancel & OpenAPI evidence: đã đóng

**Vấn đề 1 — 3 nguồn tài liệu khai permission Cancel khác nhau:**

| Nguồn | Trước fix | Sau fix |
|---|---|---|
| `docs/HN_SRS_FRD_v1.12_FULL.md` Annex C dòng 1443 | `inbound.create` (thiếu OR) | `inbound.cancel\|inbound.create` |
| `docs/HN_SRS_FRD_v1.12_FULL.md` role matrix dòng 284 | không liệt kê `inbound.cancel` | liệt kê đủ cả hai |
| OpenAPI 403 (`InboundDocumentController.php:156`) | chỉ nhắc `inbound.cancel` | nhắc cả hai + trỏ tới DR_HN_20260817_FLOW02_OPEN_DECISIONS.md |
| `docs/docs_task_jira/WWHN-207.md:100` | chỉ `inbound.cancel` | `inbound.cancel\|inbound.create` |
| Route thật (`routes/api.php:75-76`) | `permission:inbound.cancel\|inbound.create` (không đổi — đây là nguồn sự thật) | (không đổi) |

Root cause: route OR-permission được thêm có chủ đích từ Flow02 open-decisions
(WAREHOUSE_APPROVER/KEEPER được seed `inbound.cancel` riêng, WAREHOUSE_STAFF chỉ
có `inbound.create`) nhưng chỉ được ghi lại ở 1 file quyết định
(`DR_HN_20260817_FLOW02_OPEN_DECISIONS.md`), không lan ra 3 tài liệu tham chiếu
còn lại — reviewer đọc 3 tài liệu đó thấy mâu thuẫn nên coi là chưa reconcile.
Đã đồng bộ cả 3.

**Vấn đề 2 (phát hiện thêm khi điều tra P1-01R, nghiêm trọng hơn) — FE cũng
gate sai:** `WMS_ACTION_PERMISSIONS.CANCEL_INBOUND` phía FE chỉ liệt kê
`INBOUND_CANCEL`, dùng để (a) ẩn/hiện nút Cancel trên `MvpInboundScreens.tsx`
và (b) chặn phía client trong `inboundService.cancelInboundDocument()` qua
`assertWmsActionPermission`. Kết quả: WAREHOUSE_STAFF (chỉ có `inbound.create`)
không bao giờ thấy nút Cancel và bị chặn ngay trên trình duyệt trước khi request
rời đi, dù BE cho phép. Đã sửa theo đúng pattern OR-permission đã có sẵn trong
codebase cho `scan.execute|scan.classify` (`SCAN_CLASSIFY_GENERIC`) và
`putaway.manage|container.manage` (`PUTAWAY_CONTAINER`) — xem
`WMS_ACTION_PERMISSIONS.CANCEL_INBOUND` mới.

**Vấn đề 3 (root cause thật của "không có OpenAPI evidence") —
`InboundDocumentController.php` (file mang toàn bộ annotation contract thật:
If-Match/Idempotency-Key bắt buộc, schema `reason`, permission chính xác) đã
bị thiếu khỏi `config/l5-swagger.php` → `annotations[]`.**
`RouteSyncProcessor` tự sinh skeleton chung chung cho mọi route
`inbound-documents/*` ("Không có quyền truy cập", requestBody rỗng
`{"type":"object","additionalProperties":true}`) — evidence bị "biến mất"
không phải vì chưa viết OpenAPI, mà vì OpenAPI viết rồi nhưng chưa bao giờ
được compile vào `storage/api-docs/api-docs.json` mà FE/QA/reviewer thực sự
đọc. Đây là đúng loại lỗi đã từng xảy ra và được vá cho các report controller
(xem comment tại `config/l5-swagger.php` dòng 60-62) nhưng không được áp dụng
cho Inbound. Đã thêm dòng scan còn thiếu + `php artisan l5-swagger:generate`
lại + khoá bằng test mới `SwaggerRouteSyncTest::test_inbound_cancel_operation_carries_real_contract_not_generic_skeleton`.

## 3. Evidence chạy lại độc lập tại SHA `157968b`

| Lệnh | Kết quả |
|---|---|
| `php vendor/bin/phpunit --filter="Flow02InboundPutawayAlignmentTest\|InboundCanonicalListContractTest\|InboundExpectedCorrectionContractTest\|InboundScanConcurrencyBehaviorTest\|PostReceiptConcurrencyBehaviorTest\|Wwhn198InboundScanValidationTest\|Wwhn207InboundReversalFlowTest\|PostReceiptAuditLogTest\|PermissionAndValidationTest\|SecurityRbacAuditNegativeTest\|FoundationInboundPutawayReadinessTest"` (¹) | **88/88 pass, 585 assertions** |

(¹) Ghi chú (2026-08-18): lệnh trên là evidence lịch sử, giữ nguyên không sửa để bảo toàn tính xác thực tại thời điểm đã chạy. Lớp `Wwhn198InboundScanValidationTest` sau đó đã được đổi tên thành `Wwhn187InboundScanValidationTest` (cùng nội dung test, không đổi logic) sau khi xác nhận qua Jira live rằng key chính xác của task là WWHN-187 — xem `docs/docs_task_jira/WWHN-187.md`.
| `php vendor/bin/phpunit --filter=SwaggerRouteSyncTest` | **3/3 pass, 10 assertions** (gồm test mới khoá regression) |
| `php vendor/bin/phpunit` (full suite, sqlite) | **553 tests, 534 passed, 19 skipped, 0 failed, 6537 assertions** — không có regression |
| `php artisan l5-swagger:generate` rồi grep `stock_in_cancel` trong `storage/api-docs/api-docs.json` | operationId thật xuất hiện, mô tả 403 chứa cả `inbound.cancel` và `inbound.create` (trước fix: generic "Không có quyền truy cập") |
| FE `npx vitest run` (repo `WMS_Warehouse_Hoa_Nam_FE`, SHA `09e0dd3`) | **482/482 tests passed (63 files)** — gồm test mới khoá `inbound.create` đủ để cancel |

Không migration nào được thêm/sửa trong fix này (chỉ sửa mô tả tài liệu +
config scan path + code doc-comment) nên `migrate:status` không bị ảnh hưởng;
không cần DBA/DevOps sign-off riêng cho phần này.

## 4. Đối chiếu Acceptance Criteria (theo đúng mô tả JIRA của WWHN-184)

| # | Acceptance Criteria (JIRA WWHN-184) | Trạng thái | Evidence |
|---|---|---|---|
| 1 | Output "Inbound API" rà soát/xác minh, gắn evidence, không còn blocker vai trò BE1 | **Done (kỹ thuật, phía BE1)** cho DEV184-P1-01R | Mục 2-3 ở trên |
| 2 | Logic API/data đáp ứng quy tắc nghiệp vụ; permission/validation/giao dịch/audit/idempotency/concurrency khi thuộc phạm vi | **Done** — xem bảng route mục 1; RBAC đủ 6 action; 422 validation; If-Match 412/428; Idempotency-Key optional (create) / required (post-receipt, reverse); audit qua `AuditLogger::record` (create/update/cancel/unscan) + `document_status_history`/`posting_event` (scan/post) | Test 88/88 mục 3; chi tiết file:line trong báo cáo điều tra (xem lịch sử PR) |
| 3 | Lỗi trả error code rõ ràng, không tạo dữ liệu sai, không lộ secret/stack trace | **Done** | `ApiResponse::respondError()`; 409/412/422/428 đúng mã ở từng action |
| 4 | Có unit/integration test và API/DB evidence; OpenAPI/Postman/payload mẫu cập nhật khi contract đổi | **Done cho phần đã đổi (permission Cancel)** — OpenAPI đã cập nhật + compile lại; test mới khoá regression. Postman scoped riêng cho Inbound **chưa tồn tại** (chỉ nằm rải trong 2 collection chung `DEV`/`TEST`) — ghi nhận là điểm còn mở, không tự nhận Done | `storage/api-docs/api-docs.json` diff; `tests/Feature/SwaggerRouteSyncTest.php` |
| 5 | FE1 và QA nhận đủ contract/evidence; không còn P0/P1 blocker chưa có disposition | **Cần QA1/FE1 xác nhận độc lập** (xem mục 6) | — |

## 5. Điểm còn mở — không thuộc phạm vi tự nhận của BE1

- **Postman scoped riêng cho WWHN-184/WWHN-41** chưa tồn tại như một file
  version hoá độc lập (kiểu `WWHN-180 Canonical Contract.postman_collection.json`).
  Việc trích xuất 1 collection Postman scoped từ OpenAPI vừa compile lại là
  việc cơ học (export theo tag "09. Stock In (Inbound)"), không phải quyết
  định nghiệp vụ — khuyến nghị DevOps/QA thực hiện bằng import OpenAPI mới
  vào Postman và lưu lại dưới `docs/postman/WWHN-184-inbound-contract.postman_collection.json`,
  không cần BA/PM chốt gì thêm.
- **Audit trail của hành động scan** dùng `inbound_scan_entries`/`scan_batch`/
  `scan_event` thay vì `AuditLogger::record` trực tiếp (khác với create/
  update/cancel/unscan). Đây là thiết kế có chủ đích (scan evidence tự nó là
  audit trail chi tiết hơn generic audit log), không phải thiếu sót, nhưng
  QA1 nên xác nhận cơ chế này đáp ứng đúng yêu cầu "audit/history" của
  DEV184-P1-03R trước khi coi checklist đó là Done.
- **DEV184-P1-02R (deploy evidence)** và **DEV184-P1-04R (baseline
  governance WWHN-41)** không thuộc phạm vi kỹ thuật BE1 có thể tự đóng —
  xem comment Jira riêng cho DevOps và cho BA/BAS/PM/PO.

## 6. Định nghĩa Done — thực tế

### BE1 technical completion (mục này)

Đạt tại SHA `157968b7e6a863b626d5090eef051d80b363012d`: permission Cancel
reconciled trên cả 4 nguồn tài liệu + FE; OpenAPI compile đúng contract thật;
test full suite xanh (553/553 chạy được, 0 fail); test mới khoá regression.

### Còn lại — không thuộc phạm vi tự nhận của BE1

- QA1 retest exact build theo checklist DEV184-P1-03R (mục 5 ở trên nêu rõ
  điểm cần QA1 chú ý thêm: audit trail của scan).
- DevOps deploy candidate DEV + gắn evidence pack (DEV184-P1-02R).
- BA + BAS + PM/PO xác nhận baseline WWHN-41 hiện hành (DRAFT/SCANNING/
  POSTED/CANCELLED, full-post exactly once, không Submit/Approve/Reject) là
  disposition chính thức, cập nhật lại parent AC/source (DEV184-P1-04R).
- Không tự đánh dấu Done/Approved trên Jira chỉ dựa trên nội dung do BE1 tự
  ghi trong file này.
