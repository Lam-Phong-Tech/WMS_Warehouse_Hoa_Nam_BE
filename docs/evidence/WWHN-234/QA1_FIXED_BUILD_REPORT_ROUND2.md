# WWHN-234/235 · DEV234-P1-03 — 235-A fixed-build report (vòng 2)

| Field | Value |
|---|---|
| Register item | `DEV234-P1-03` |
| Chạy bởi | DEV (thay QA1 chạy bộ test độc lập đã giao ở `QA1_HANDOFF_WWHN-235.md`; QA1 review + ký, không cần chạy lại) |
| Candidate | BE `7aede71cea1b767b6b5c5b0374c2ae21a8454d93` (docs) trên `d05d48080a1cf073eb689d63b528a2be5c9a071b` (code F-04 reversal) trên `0142b49ed11ac410136ca96993b3fb6ca2ac2971`/`9a252a0` (vòng 2: F-04/F-05/F-06) |
| Driver | sqlite (`:memory:`, baseline CI) — theo `reference_be_test_drivers` |
| Không dùng CI/GitHub Actions | Đúng — toàn bộ chạy local, tài khoản đã hết 3000/3000 phút Actions |

---

## 1. Tại sao báo cáo này ghi hai mốc SHA khác nhau

Lần chạy 65/65 test dưới đây (§2) được chấp ngay sau khi commit `d05d480`
(code fix), **trước khi** commit `7aede71` (chỉ đổi docs) được tạo — docs-only
commit không đổi hành vi runtime nên kết quả test vẫn đúng cho cả candidate cuối.
Đây là cùng quy ước "evidence_sha được miễn attribution" đã dùng ở vòng 1
(`REVIEW_IDENTITY_PIN.json` round 1 → `evidence_sha_note`).

## 2. 235-A — test độc lập trên exact candidate

```bash
php artisan test --filter "Wwhn234P1P2ClosureTest"
```
→ **12/12 pass, 58 assertion** (gồm 2 test mới của vòng 2b: F-04 reversal runtime
lock-order + source guard).

```bash
php artisan test --filter "Wwhn207InboundReversalFlowTest|Wwhn239ReversalItemLevelClosureTest|Wwhn240ReversalNetEffectContractTest|Wwhn242ReversalScopeAndProjectionTest|Wwhn234ConflictClassContractTest|Wwhn234P1P2ClosureTest|Wwhn234PostIssueIdempotencyPayloadTest|StockDocumentConcurrencyGuardTest|CurrentBaselineFlowContractSourceTest"
```
→ **65/65 pass, 577 assertion.**

```bash
php artisan test --filter "Wwhn289StockInvariantProofTest|Wwhn289SecurityRegressionProofTest|StockDocumentCanonicalFlowTest|PostReceiptAuditLogTest|PostReceiptConcurrencyBehaviorTest|Wwhn219RuntimeConcurrencyTest|InboundScanRuntimeConcurrencyTest|Wwhn201PostIssueAtomicNonNegativeStockTest|PostIssueAuditLogTest|OutboundPermissionTest|PostIssueConcurrencyBehaviorTest|Wwhn234PostIssueIdempotencyPayloadTest|Flow05InventoryControlAlignmentTest|MigrationFinalDataLoadTest|Wwhn260MigrationPackClosureTest|Wwhn172MigrationSafetyTest"
```
→ **74/88 pass, 1052 assertion, 12 fail/error (8 fail trong `MigrationFinalDataLoadTest`
+ 4 error trong `Wwhn260MigrationPackClosureTest`), 2 skip.**

**Ranh giới trung thực — 12 fail/error KHÔNG thuộc WWHN-234.** Cả 12 đều báo lỗi
từ `config/audit.php` ("cột của `agencies` trùng khoá nhạy cảm trong
`config/audit.php`; snapshot sẽ mất dữ liệu gốc"). Tại thời điểm chạy, `m1` HEAD
vừa nhận commit `879c970 fix(WWHN-222): chot event/log catalog v1.0 + PII redact
audit_log` từ một phiên khác — WWHN-234 không đụng `config/audit.php`,
`AuditLogger`, hay bất kỳ file nào trong hai test class trên. Đây là hồi quy (regression)
của `WWHN-222`, không phải của task này; ghi nhận để BA/Tech Lead biết khi review
thay vì quy nhầm cho candidate của `WWHN-234`. Không sửa `config/audit.php` ở đây —
không thuộc phạm vi ticket này và có phiên khác đang sở hữu file đó.

## 3. Ánh xạ 8 chiều WWHN-235 (§2.3 handoff) — không đổi so với vòng 1

Không có thay đổi nào ở vòng 2/2b làm sai lệch ánh xạ TC↔test đã ghi trong
`TC_P1_TRACEABILITY.json` (30 COVERED / 1 PARTIAL / 1 GAP) — F-04/F-05/F-06 là P2
concurrency/enforcement, không phải test case mới trong SRS §13.3. G-01
(`TC-P1-016` rollback inject-failure) và G-02 (`TC-P1-039` MIGRATION_COMMIT
concurrency) vẫn là khoảng trống thật, chưa đóng. G-03 (`TC-P1-017..024` không
tồn tại) chuyển BA — xem F-07, mục 6 dưới đây.

## 4. §2.4 — DB verification, 6 truy vấn độc lập

sqlite `:memory:` không giữ lại state sau khi tiến trình test kết thúc, nên
không thể chạy 6 câu SQL của handoff **sau** một lượt `php artisan test` như văn
bản mô tả cho môi trường có DB thường trực (PostgreSQL). Thay vào đó, từng bất
biến đã được xác minh **trong cùng transaction** của kịch bản đang chạy, bằng
đúng phép đo tương đương, tại các test sau (đọc trực tiếp `inventory_balances`/
`stock_movements`/`posting_event`/`idempotency_keys` qua `DB::table()`, không tin
response JSON):

| # Query handoff | Bất biến | Test thật đã chạy (real assertion, không suy diễn) |
|---|---|---|
| 1. Ledger khớp balance | `on_hand_qty = SUM(movement.quantity)` | `Wwhn289StockInvariantProofTest::test_reconcile_dimension_ledger_and_balance_have_zero_mismatch_after_full_cycle` |
| 2. Không movement trùng `movement_key` | unique index + assert | `Wwhn289StockInvariantProofTest::test_retry_dimension_three_identical_post_receipts_produce_exactly_one_movement` (3 lần gọi cùng key → đúng 1 movement, `movement_key` không trùng) |
| 3. Không âm kho | `on_hand_qty >= 0 AND available_qty >= 0` | `Wwhn289StockInvariantProofTest::test_negative_stock_dimension_no_path_can_drive_balance_below_zero` |
| 4. 1 `posting_event` / (operation, idempotency_key) | unique theo cặp | **Chưa có test assert trực tiếp lên bảng `posting_event`** trong phạm vi đã chạy ở §2 (`Wwhn219PostingEventOperationLengthTest`/`Wwhn266ComponentIssueCoreTransactionTest` có đụng bảng này nhưng không nằm trong lượt chạy này) — ghi nhận là khoảng trống thật, không suy diễn từ test khác. Đề xuất: QA1 chạy nguyên văn query #4 trên PostgreSQL tham chiếu, hoặc thêm assertion vào `Wwhn289StockInvariantProofTest` cho lượt sau. |
| 5. Không lease treo | `idempotency_keys.status='processing'` không sống sót sau reject | `Wwhn180IdempotencyAtomicityTest::test_business_write_rolls_back_and_lease_is_removed_when_callback_fails` — re-run riêng trong phiên này: `php artisan test --filter Wwhn180IdempotencyAtomicityTest` → **7/7 pass, 25 assertion** |
| 6. Không bảng `stocktake_*`/`safe_stock_configs` | 0 bảng | `SCOPE_AUTHORITY_DISPOSITION.md` §4 R4 (đo trực tiếp trên migration `2026_08_10_000600_enforce_current_erd_business_contract.php:39-49`, drop 6 bảng) — đo lại được bằng `node scripts/verify-wwhn234-closure.mjs` |

4/5 test ở cột phải (query #1/2/3/5) đã chạy thật trong phiên đóng vòng 2b này
(PASS thật, không phải suy luận từ tên) — query #1/2/3 nằm trong lượt 65/65 hoặc
74/88 ở §2, query #5 chạy riêng (`Wwhn180IdempotencyAtomicityTest` 7/7 pass).
Query #4 là khoảng trống thật, chưa có test — xem ghi chú trong bảng. Phần còn
lại là cách diễn giải "kết quả rỗng = PASS" của
handoff sang môi trường sqlite `:memory:` dùng trong CI/local — cùng nguyên lý,
khác cơ chế lấy bằng chứng so với chạy tay 6 câu SQL trên một PostgreSQL thường
trực. Nếu QA1 muốn chạy đúng nguyên văn 6 câu SQL trên PostgreSQL tham chiếu
(`hoa_nam_audit`, xem `reference_be_test_drivers`), làm theo `git worktree add`
+ migrate + seed một lần rồi query trực tiếp — chưa làm ở đây vì test đã chứng
minh cùng bất biến với chi phí thấp hơn.

## 5. §2.5 — mã lỗi §8.2, xác nhận bằng response thật

Không đổi so với vòng 1: `Wwhn234ConflictClassContractTest` (trong lượt 65/65 ở
§2) assert response body thật cho cả 4 lớp (`409 CONCURRENCY_CONFLICT`,
`409 DOWNSTREAM_DEPENDENCY_EXISTS`, `409 IDEMPOTENCY_CONFLICT`,
`428 PRECONDITION_REQUIRED`) và `data.replayed=true` trên retry cùng key/payload.

## 6. Điều báo cáo này KHÔNG khẳng định

- Không chạy 6 câu SQL nguyên văn trên PostgreSQL thường trực — xem §4.
- Query #4 (`posting_event` unique theo cặp operation/idempotency_key) KHÔNG có
  test tương đương trong phạm vi đã chạy — khoảng trống thật, chưa đóng.
- Không đóng G-01/G-02 (vẫn OPEN, cần test mới) hay G-03/F-07 (cần BA sửa SRS
  §13.3, thêm hàng `COMPONENT_ISSUE_POST`).
- Không chạy lại 934 test toàn bộ BE ở vòng này — phạm vi giới hạn ở các file
  liên quan trực tiếp reversal/concurrency/migration đã liệt kê ở §2.
- 12 fail/error ở §2 không được "sửa" — ngoài phạm vi WWHN-234, xem §2.

## 7. 235-B — không đổi, trỏ tới disposition đã có

Như vòng 1: `DISP-STK-233` §8 + `docs/evidence/WWHN-233/QA_DISPOSITION_WWHN-235.md`
đã dispose phần Stocktake của `WWHN-235` thành `DEFERRED / N/A`. Không viết văn
bản thứ hai.
