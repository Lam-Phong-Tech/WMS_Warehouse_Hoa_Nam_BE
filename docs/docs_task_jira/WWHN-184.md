# WWHN-184 — [DEV] Triển khai API chứng từ/trạng thái nhập kho

> Vai trò narrative: **DEV** (vai trò hợp nhất). Nhãn và history trên Jira giữ nguyên
> **BE1** (Quang Nguyễn Thanh — placeholder `HN-BE-01`) để không đứt mạch trace — đây
> đúng là disposition của `DEV184-P2-01R`: đổi cách gọi vai trò trong phần mô tả, không
> đổi label/lịch sử.
> Task cha: **WWHN-41 / HN-IN-001** — [INBOUND] Tạo/chỉnh/duyệt chứng từ nhập
> (tiêu đề cha còn chữ "duyệt" — luồng nhập không có bước duyệt; xem `DISP-IN-184` §3.3)
> Dependency: **HN-PIN-05; HN-FND-001; HN-MC-001**
> Nhánh triển khai: `m1` (không tạo nhánh riêng)
> Candidate pin: `docs/evidence/WWHN-184/RC_PIN.json` (`HN-PIN-184`) —
> `node scripts/verify-wwhn184-candidate-pin.mjs`
> Exact commit SHA vòng rà soát trước: `157968b7e6a863b626d5090eef051d80b363012d`
> `[fix(WWHN-184): reconcile inbound.cancel|inbound.create contract, close Swagger scan gap]`
> FE đồng hành: `09e0dd376f04610505669c79009478030c3a9223` (repo
> `WMS_Warehouse_Hoa_Nam_FE`) —
> `[fix(WWHN-184): let inbound.create satisfy Cancel like the BE route does]`

---

## 0-bis. Vòng đóng register 2026-08-19 — P1-01R/P1-02R/P1-03R/P1-04R/P2-01R

Mục này ghi những gì đã sửa **ở vòng này**; các mục 0-6 phía dưới là hồ sơ của vòng
trước, giữ nguyên để bảo toàn tính xác thực.

### DEV184-P1-01R (PARTIAL → đã xử lý tận gốc)

Reviewer: *"Postman mutation version chaining còn sai/không chứng minh pass"*.

**Nguyên nhân gốc — không phải lỗi soạn ẩu, mà là hiểu sai thứ tự guard.**
`cancelDocument()` và `postDocument()` gọi `assertVersion()` **trước** khi kiểm tra
status. Cancel và Post đều bump `version`, nhưng bản collection cũ không bắt lại version
sau hai mutation đó. Hệ quả: mọi state probe phía sau gửi `If-Match` cũ nên dừng ở
`412 STALE_VERSION` và **không bao giờ chạm state guard** — assertion `409 STATE_CONFLICT`
của chúng không thể pass, bất kể server chạy đúng hay sai.

Đã sửa:

| Defect trong collection cũ | Sửa |
|---|---|
| Cancel (#11) không lưu version mới → double-cancel (#12) nhận 412 chứ không 409 | Mọi mutation 2xx bắt lại `data.version` **và** đối chiếu header `ETag` |
| Post (#15) không lưu version mới → #18, #19 nhận 412 chứ không 409 | Như trên, cộng refetch `GET` sau mỗi mutation trước khi probe |
| #17 assert `IDEMPOTENCY_ERROR` | Mã đúng là **`IDEMPOTENCY_CONFLICT`** (SRS §8.2 / `ConflictCode::IDEMPOTENCY`); `IDEMPOTENCY_ERROR` là mã của luồng SKU |
| `03/04` lấy `data[0]` tuỳ ý | Resolve tất định: kho `KHO-TONG-HN` ACTIVE (`EnsureCanonicalInboundWarehouse`), SKU `COMPONENT` không serial-tracked |
| Scan dòng PRODUCT theo số lượng | PRODUCT/serial-tracked bắt buộc scan từng item (`422 ITEM_REQUIRED`); đã chuyển sang đúng luồng COMPONENT + đăng ký BOX |
| Không có file environment | Thêm `docs/postman/WWHN-184 Inbound CI.postman_environment.json` (không commit secret) |

Chống tái diễn:

- `scripts/verify-wwhn184-postman-contract.mjs` — verifier **tĩnh**, 7 rule. Chạy trong CI
  trước cả khi dựng server. Nó đã bắt được 4 lỗi trong chính bản viết lại đầu tiên.
- `tests/Feature/Wwhn184InboundContractParityTest.php` — chứng minh **assertion của
  Postman đúng bằng hành vi runtime**, chạy được trên cả sqlite và PostgreSQL.
- Probe `D03`/`D04`: cùng một request Cancel, khác đúng giá trị `If-Match` → `409` vs `412`.
  Thứ tự guard giờ là hợp đồng được kiểm chứng, không còn là kiến thức ngầm.

### DEV184-P1-02R (OPEN → có hồ sơ pin bất biến, chờ DevOps deploy)

Vấn đề gốc: `m1` dùng chung nhiều phiên nên pin theo `HEAD` rot gần như tức thì — đó là
lý do "branch đã trôi 2 commits" trở thành finding. Đã chuyển sang **content-hash range
invariant** (`RC_PIN.json`, 19 artifact) + cổng `--expect-sha` lúc deploy + guard chống
rewrite nhánh. Runbook đầy đủ (env/build/migration/health/smoke/config/rollback):
`docs/evidence/WWHN-184/DEPLOY_RUNBOOK.md`. **DEV không deploy được — ô `deployment` do
DevOps điền.**

### DEV184-P1-03R (OPEN → nửa DEV đã đóng, chờ QA1)

Reviewer: *"DEV race chỉ SQLite stale replay"* — đúng. WWHN-188 đã dựng race thật cho
`inboundScan()` (stock-neutral) nhưng `postReceipt()` — luồng **duy nhất** ghi
`stock_movements`/`inventory_balances` — thì chưa có. Đã bổ sung
`scripts/wwhn184-post-receipt-runtime-concurrency.php` + worker: tiến trình OS thật, mỗi
tiến trình một PostgreSQL backend, lấy mẫu `pg_locks`, timestamp thô chứng minh chồng lấn,
assert DB đã commit trước/sau. Ba kịch bản: double-post 2 key, chia sẻ Idempotency-Key,
Post đua Cancel. Checklist bàn giao QA1: `docs/evidence/WWHN-184/QA1_RETEST_CHECKLIST.md`.

### DEV184-P1-04R (OPEN → artifact chờ BA duyệt)

`docs/decision-records/DISP-IN-184-INBOUND-DOCUMENT-STATE-BASELINE.md` — chốt baseline
`DRAFT/SCANNING/POSTED/CANCELLED`, full-post exactly once, POSTED/CANCELLED terminal,
**không có Submit/Approve/Reject**, neo vào đúng điều khoản SRS v1.12 (§2 phạm vi, §5.1,
`REQ-SRS-006/007/008`, Annex C, Annex D `SCR-IN-01`). Phát hiện thêm: **§6 STATE MODEL của
SRS thiếu hẳn dòng Inbound/Outbound Document** trong khi mọi object khác đều có — đó là
nguyên nhân cấu trúc khiến AC của task cha trôi về wording BRD v3.9 mà không ai bắt được.
Dòng đề nghị bổ sung đã soạn sẵn trong disposition §3.4 để BA duyệt.

### DEV184-P2-01R

Narrative role đổi về **DEV** ở tiêu đề và dòng vai trò; label/history Jira giữ **BE1**.

### Bằng chứng chạy lại vòng này

| Lệnh | Kết quả |
|---|---|
| `node scripts/verify-wwhn184-postman-contract.mjs …` | **PASS** — 33 request, 14 mutation, 9 refetch, 3 probe STATE_CONFLICT, 2 probe STALE_VERSION |
| `php vendor/bin/phpunit --filter=Wwhn184InboundContractParityTest` | **3/3 pass, 256 assertions** |
| `php vendor/bin/phpunit --filter="Wwhn184…\|Flow02InboundPutawayAlignmentTest\|PostReceiptConcurrencyBehaviorTest\|InboundScanConcurrencyBehaviorTest\|SwaggerRouteSyncTest\|Wwhn207InboundReversalFlowTest\|PostReceiptAuditLogTest"` | **36 test, 35 pass, 1 skip** (skip = gate PostgreSQL, đúng thiết kế trên sqlite) |
| `./vendor/bin/pint --test` (file mới) | **pass** |
| `node scripts/verify-wwhn184-candidate-pin.mjs` | **PASS** — 19 artifact |

---

## 0-ter. Review R3 (75/100) — DEV184-P1-03R3 đóng bằng bằng chứng chạy thật, 2026-08-19

Register R3 chấm `P1-01R3` **CLOSED**, `P1-02R3` **DEFERRED** (CI/CD — chuyển sang project-final
release review, không đụng tới trong vòng này), `P1-03R3`/`P1-04R3` **P1 OPEN**, `P2-01R3` **P2
non-blocking**. Chi tiết đầy đủ + 2 defect mới phát hiện (không chỉ sign-off): xem
**`docs/evidence/WWHN-184/QA1_RETEST_REPORT_20260819.md`**.

Tóm tắt cực ngắn:

- **DEV184-P1-03R3**: chạy độc lập toàn bộ `QA1_RETEST_CHECKLIST.md` trên PostgreSQL thật (không
  phải CI). Phát hiện + sửa 2 defect: (1) `RC_PIN.json` hash cả file `routes/api.php` dùng chung
  nên bị đỏ oan bởi commit của WWHN-195/WWHN-234 — đổi sang pin mức khối route (pattern HN-PIN-328);
  (2) `audit_log` trên PostgreSQL là VIEW, migration WWHN-222 (`2026_08_19_000001`) dùng
  `Schema::hasTable('audit_log')` nên no-op trên pgsql → **audit_log rỗng toàn hệ thống trên
  PostgreSQL** kể từ hôm nay — vá bằng `2026_08_19_000002_fix_audit_log_pgsql_view_missing_event_catalog_fields.php`.
  Sau vá: contract/RBAC/idempotency/audit/race 2-backend/reconciliation đều PASS.
- **DEV184-P1-04R3**: nội dung `DISP-IN-184-INBOUND-DOCUMENT-STATE-BASELINE.md` đã đối chiếu lại
  với `HN_SRS_FRD_v1.12_FULL.md` hiện hành (2026-08-19) — không có gì trôi kể từ khi soạn, vẫn
  **PROPOSED**, chờ BA + BAS + PM/PO duyệt chính thức (comment xác nhận soạn sẵn ngoài Jira).
  Cập nhật cùng ngày (vòng đóng R3): erratum §3.4 của disposition đã được **áp vào chính SRS** —
  §6 STATE MODEL thêm dòng `Inbound / Outbound Document` (4 trạng thái, full-post exactly once,
  terminal, không Submit/Approve/Reject, thứ tự guard 428/412/409) + mục Controlled Amendment §15
  ngày 19/08/2026 chú nguồn `DISP-IN-184`; áp **đồng thời cả hai bản** (repo BE và repo FE, SHA-256
  hai file khớp nhau sau sửa) để đóng đúng root cause "AC cha trôi vì không có ô state model để đối
  chiếu". Verify sau sửa: `verify-wwhn184-candidate-pin.mjs` PASS (19 artifact, HEAD `22564b4`);
  38/38 test gate tham chiếu SRS (`Wwhn230|Wwhn233|Wwhn236|Wwhn237|Wwhn184…ParityTest`) pass.
  Ba ô header của disposition (Trạng thái/Effective date/Approval evidence) do BA điền sau khi bộ
  comment sign-off được đăng trên Jira — DEV không tự điền, đúng nguyên tắc mục 6.
- **DEV184-P2-01R3**: phần repo (narrative role → DEV, giữ label Jira BE1) đã xong từ vòng trước
  (mục 0-bis); phần còn lại thuần Jira metadata, PM thực hiện trực tiếp trên Jira.

---

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
