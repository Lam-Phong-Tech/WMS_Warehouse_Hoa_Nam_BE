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
| Reversal list/detail lộ ngoài warehouse scope | WWHN-242 | (cùng đợt sửa `c85eca4` + theo dõi `809d483`) | `Wwhn242ReversalScopeAndProjectionTest` |
| Component Issue (warranty) chưa có core-transaction gate + If-Match sai gốc hợp đồng | WWHN-266 | `8c77507` | `Wwhn266ComponentIssueCoreTransactionTest` |
| `@group` annotation không map bằng chứng chạy thật | WWHN-202 | `8e82dd6` | `Wwhn202OutboundBalanceEvidenceTest` |

Mỗi dòng trên đã có reviewer confirmation tại chính ticket gốc (xem Jira/commit message
tương ứng); ở đây chỉ liên kết lại vào WWHN-214, không sửa/diễn giải lại nội dung fix gốc.

## 4. DoD

- [x] Không tạo dữ liệu sai, không code suy đoán cho defect không tồn tại.
- [x] Evidence chạy thật local (sqlite), không đụng GitHub Actions/CI.
- [x] Traceability 1-1 defect → commit → test cho toàn bộ hạng mục reuse.
- [ ] P1-05 (BA pin controlling source tại WWHN-52) và P2-01 (PM sprint date) — ngoài vai trò DEV, xem comment riêng.
