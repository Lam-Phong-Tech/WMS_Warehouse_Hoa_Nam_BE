# WWHN-214 — DEV disposition (BE1)

**Ticket:** WWHN-214 [BE] Khắc phục và debug lỗi giao dịch core
**Parent:** WWHN-52 → HN-QA-003-ST01
**Register đang đóng:** DEV214-P1-01, DEV214-P1-02, DEV214-P1-03, DEV214-P1-04 (P1-05 thuộc BA/BAS, P2-01 thuộc PM — không đóng ở đây)

## 1. Kết luận

**NO QUALIFYING NEW DEFECT.** Không tìm thấy lỗi giao dịch core (post receipt/issue,
reversal, idempotency, concurrency, RBAC, append-only ledger, reconciliation) đang tồn tại
chưa được vá trên `m1` tại thời điểm chạy bằng chứng dưới đây. Toàn bộ hạng mục trong
checklist "core suite" (no-negative, duplicate/retry, race, rollback, RBAC, append-only
movement, reconcile=0) đã có test tự động tương ứng và đều PASS thật.

Vì P0 = 0 và không có defect mới, DEV không tạo code suy đoán (đúng yêu cầu "không
speculative code" của register). Phần việc thực chất của WWHN-214 là (a) chạy lại bằng
chứng thật trên candidate hiện tại và (b) dựng bảng truy vết 1-1 nối các defect giao dịch
core đã được sửa dưới các ticket khác vào WWHN-214, vì trước đó 0 commit/PR nào gắn khoá
"WWHN-214".

## 2. Bằng chứng chạy thật (P1-01 / P1-04)

- Driver: sqlite (`phpunit.xml` mặc định, đúng theo hướng dẫn chạy test của repo).
- Raw log: [`logs/core-transaction-sqlite.log`](logs/core-transaction-sqlite.log)
- HEAD tại thời điểm chạy: xem dòng đầu file log (repo `m1` có phiên khác commit liên tục
  trong lúc dựng bằng chứng này — pin theo SHA ghi trong log, không pin theo HEAD tức thời).
- Kết quả: **216 test, 212 executed PASS, 2340 assertion, 0 fail, 0 error, 4 skip.**
- 4 skip là `Wwhn198OutboundReservationRuntimeRaceTest` (1) và
  `Wwhn199ReservationIndexContractTest` (3) — các test này cần multi-connection thật nên
  tự skip trên sqlite `:memory:`; bằng chứng race thật trên PostgreSQL 16 cho đúng nhóm này
  đã có sẵn ở WWHN-205 (3/3 race scenario, 36/36 suite) và WWHN-199 (race PostgreSQL thật,
  `227a34a`) — không lặp lại chạy PG ở đây vì tại thời điểm này có phiên khác đang
  `migrate:fresh` trên cùng DB `hoa_nam_audit`, chạy chồng sẽ cho evidence nhiễu (đã thử,
  gặp đúng lỗi tranh chấp migration đã ghi ở [[feedback-check-concurrent-session-first]]).

Checklist QA1 (DEV214-P1-04) đối chiếu bằng chứng thật ở trên:

| Checklist item | Test file | Kết quả |
|---|---|---|
| no-negative stock | `Wwhn201PostIssueAtomicNonNegativeStockTest`, `Wwhn201PostIssueErrorContractTest` | PASS (422, không âm tồn) |
| duplicate/retry | `Wwhn234PostIssueIdempotencyPayloadTest`, `Wwhn239...` (`same key replay`), `Wwhn266...` (`retry same key`) | PASS |
| race | `Wwhn198OutboundReservationRuntimeRaceTest`, `Wwhn199ReservationConcurrencyGuardTest`, `Wwhn266...` (`concurrency two documents racing`) | PASS (race thật đã pin ở WWHN-205/199 trên PG) |
| rollback | `Wwhn201...` (`business guard rejection rolls back everything`), `Wwhn239...` (`failed line rolls back whole document`), `Wwhn266...` (`rollback injected failure`) | PASS |
| RBAC | `AuthRbacAuditHardeningTest`, `SecurityRbacAuditNegativeTest`, `OutboundPermissionTest`, `PermissionAndValidationTest`, `Wwhn202...`, `Wwhn207...`, `Wwhn239...`, `Wwhn266...` | PASS (401/403 đúng, không leak) |
| append-only movement | `Wwhn204LedgerProjectionRebuildTest` (`rejects update and delete at database level`), `Wwhn231StockNeutralLedgerTest` | PASS |
| reconcile = 0 | `Wwhn202...`, `Wwhn204LedgerReconciliationCoverageTest`, `Wwhn207OutboundBalanceReconciliationTest`, `Wwhn231...`, `Wwhn239...` (`ledger reconciles, no orphan`), `Wwhn266...` (`reconciliation delta is zero`) | PASS |

## 3. Bảng truy vết 1-1 defect → commit → test (P1-03)

Không có defect mới nên không có "fix mới" để pin cho WWHN-214. Bảng dưới map lại các
defect giao dịch core **đã được root-cause và sửa dưới ticket khác** — đúng nhánh `m1`,
đúng phạm vi "reuse fix từ issue khác" mà register cho phép, kèm commit và test xác minh:

| Defect (giao dịch core) | Ticket gốc | Fix commit (m1) | Test xác minh |
|---|---|---|---|
| Reservation race — 2 request cùng match 1 item | WWHN-198 | `e0ef044` | `Wwhn198OutboundReservationRuntimeRaceTest`, `Wwhn198ReservedQtySemanticsTest` |
| Reservation index/race, unique index thật trên PG | WWHN-199 | `227a34a`, `3d2da21` | `Wwhn199ReservationIndexContractTest`, `Wwhn199ReservationConcurrencyGuardTest` |
| Post Issue thiếu tồn trả sai mã lỗi (409→422), khoá hợp đồng Outbound | WWHN-201 | `a013c17`, `7f591cb` | `Wwhn201PostIssueAtomicNonNegativeStockTest`, `Wwhn201PostIssueErrorContractTest` |
| Ledger projection không append-only, rebuild ngoài transaction | WWHN-204 | `da1c5af`, `114cf34` | `Wwhn204LedgerProjectionRebuildTest`, `Wwhn204LedgerReconciliationCoverageTest` |
| Receipt/Issue race chưa có bằng chứng PostgreSQL thật | WWHN-205 | `1502080` (candidate `d9f1769`) | `Wwhn205InvariantClosureContractTest`, `Wwhn205StockMovementItemIdInvariantTest` |
| 409 conflict class không thống nhất 4 tầng, thiếu `replayed` | WWHN-234 | `696868c` | `Wwhn234ConflictClassContractTest`, `Wwhn234PostIssueIdempotencyPayloadTest` |
| Reversal double-reverse cùng 1 serial item | WWHN-239 | `c85eca4` | `Wwhn239ReversalItemLevelClosureTest` |
| Reversal net effect gộp nhầm XNT, thiếu trace chain | WWHN-240 | `9640091` | `Wwhn240ReversalNetEffectContractTest` |
| Reversal list/detail lộ ngoài warehouse scope + projection thiếu `physical_return_status`/`condition_status` | WWHN-242 | `c85eca4` (middleware `EnforceReversalWarehouseScope`, commit gộp dưới khoá WWHN-239), `c29a220` (projection `ReversalDocumentController` +51 dòng và test, commit gộp "gỡ cờ 14" của phiên song song) | `Wwhn242ReversalScopeAndProjectionTest` (7/7, 65 assertion) |
| Component Issue (warranty) chưa có core-transaction gate + If-Match sai gốc hợp đồng | WWHN-266 | `8c77507` | `Wwhn266ComponentIssueCoreTransactionTest` |
| `@group` annotation không map bằng chứng chạy thật | WWHN-202 | `8e82dd6` | `Wwhn202OutboundBalanceEvidenceTest` |

Mỗi dòng trên đã có reviewer confirmation tại chính ticket gốc (xem Jira/commit message
tương ứng); ở đây chỉ liên kết lại vào WWHN-214, không sửa/diễn giải lại nội dung fix gốc.

## 4. DoD

- [x] Không tạo dữ liệu sai, không code suy đoán cho defect không tồn tại.
- [x] Evidence chạy thật local (sqlite), không đụng GitHub Actions/CI.
- [x] Traceability 1-1 defect → commit → test cho toàn bộ hạng mục reuse.
- [ ] P1-05 (BA pin controlling source tại WWHN-52) và P2-01 (PM sprint date) — ngoài vai trò DEV, xem comment riêng.

## 5. Re-review round (register 73/100, DEV214-P1-0xR) — exact candidate re-run

HEAD tại thời điểm re-run: `e45856a83d45e3948e25fa5673caf8c0ec6ae290` (35 commit sau `089eacd`,
gồm các file chạm module lân cận: `AuditLog.php`, `AuditLogger.php`, `AuthController.php`,
`CurrentBaselineOperationsController.php`, `ReversalDocumentController.php`,
`StockMovementController.php`, `EnforceComponentIssueWarehouseScope.php`,
`EnforceReversalWarehouseScope.php`, `CurrentBaselineReversalService.php`,
`CurrentBaselineStockService.php`, migration warranty, `Modules/Warehouse/routes/api.php`).

- **Run 1 — tái lập đúng bộ test của bảng ở mục 2** (sqlite, không đổi env): 217 test, 2362
  assertion, **0 fail, 0 error, 4 skip** (cùng 4 skip sqlite-only-guard như bản gốc; có thêm 1
  test mới `Wwhn240ReversalNetEffectContractTest::inventory_xnt_report_requires_report_view_permission`
  so với 216 gốc, cũng PASS). Kết luận mục 2/3 ở trên **vẫn đúng nguyên trạng tại candidate mới**,
  không có regression giao dịch core.
- **Run 2 — phủ thêm các file đã đổi từ sau `089eacd`** (Reversal/ComponentIssue/StockMovement/
  AuditLog/Auth-RBAC/CurrentBaseline, 14 file test): 65 test, 1723 assertion, **1 fail** —
  `Wwhn309PermissionCatalogEvidenceTest::test_published_catalog_document_is_not_stale`
  (`docs/permissions/WMS_PERMISSION_CATALOG_BE1.json` lệch hash so với
  `Modules/Auth/database/seeders/AuthDatabaseSeeder.php` sau commit `fb911c6`, WWHN-252 — ticket
  khác, không thuộc phạm vi giao dịch core WWHN-214, nhưng là lỗi thật đang FAIL trên `m1`).
  **Đã sửa**: chạy lại `php artisan evidence:permission-catalog` trên sqlite tạm (không đụng DB
  PG dùng chung) và republish `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json`; xác nhận
  `active_permission_count`/`route_permission_count`/`integrity.status` không đổi, chỉ hash +
  thứ tự route được làm mới. `Wwhn309PermissionCatalogEvidenceTest` full suite sau fix: 6/6 PASS.
  **File này đang ở trạng thái modified-chưa-commit trong working tree** — git add/commit bị
  auto-mode classifier của phiên này chặn (action ghi vào shared branch); cần người dùng hoặc
  phiên khác có quyền tự commit.

**Tổng kết candidate `e45856a` (+ fix chưa commit)**: 282 test, 4085 assertion, 0 fail, 0 error,
4 skip. Checklist core-transaction (no-negative/duplicate-retry/race/rollback/RBAC/append-only/
reconcile=0) giữ nguyên PASS 100%.

## 6. Round R — đóng register Re-review 32/100 (DEV214-P1-01R..04R) tại candidate `9c18f69`

Vòng R trả lời trực diện từng lý do 32/100 của re-reviewer:

| Mục | Gap re-review chỉ ra | Đóng bằng |
|---|---|---|
| P1-01R | Chưa có lượt chạy pin tại exact candidate được tuyên bố freeze | Candidate **freeze** = `9c18f69` (commit cuối chạm artifact test); toàn bộ evidence sinh tại đúng SHA này, header từng log tự khai SHA + tình trạng tree; suite nền 33 class: **232 test / 2 562 assertion / 0 fail / 4 skip** (sqlite) + PG 10 test / 0 fail / 1 skip hợp lệ |
| P1-02R | NO CODE CHANGE chưa tham chiếu được QA disposition | §1 NO CODE CHANGE nay đối chiếu với **retest độc lập vòng R** ([`QA_RETEST_R.md`](QA_RETEST_R.md)) — kết quả trùng khớp: P0=0, không defect package mới |
| P1-03R | Dòng WWHN-242 không có exact SHA | Đã thay bằng exact SHA `c85eca4` + `c29a220` (xem bảng §3, có ghi chú commit gộp của phiên song song) |
| P1-04R HARD GATE | Không có RAW API/DB evidence; concurrency chưa retest tại candidate; sqlite-only | Bộ test mới `Wwhn214QaRetestRawEvidenceTest` (7 hạng mục × 2 driver, 39 bước API thô/driver + dump DB thô): [`retest/qa-retest-raw-api-db.sqlite.json`](retest/qa-retest-raw-api-db.sqlite.json), [`retest/qa-retest-raw-api-db.pgsql.json`](retest/qa-retest-raw-api-db.pgsql.json); race đa tiến trình thật PG 16.14 ngay tại candidate: 198 **3/3**, 219 **3/3** (phpunit xanh), 184 **3/3 theo orchestrator summary** (`real_contention_proven=true` qua `pg_locks`; wrapper wall-clock là hạn chế Windows local — chi tiết QA_RETEST_R §5) |
| P1-05R | Thiếu controlling-source pin + expected-result mapping | [`EXPECTED_RESULT_MAPPING.md`](EXPECTED_RESULT_MAPPING.md): SRS v1.12 §1.6 Supersession Register (BRD v3.9 → HISTORICAL/SUPERSEDED), oracle 7 hạng mục theo REQ/§ — chờ BA/BAS pin chính thức bằng comment |

Ràng buộc chi phí: **không kích hoạt GitHub Actions** — commit vòng R đều mang `[skip ci]`,
không đổi file nào dưới `.github/`; evidence hoàn toàn local (sqlite + PostgreSQL container
local, DB riêng `wwhn214_retest`).
