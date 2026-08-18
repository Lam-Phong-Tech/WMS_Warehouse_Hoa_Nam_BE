# WWHN-234 → WWHN-235 · Handoff cho QA1 (DEV234-P1-03)

| Field | Value |
|---|---|
| Register item | `DEV234-P1-03` (P1) |
| Owner → Reviewer | QA1 → BA/Tech |
| Gate | Core Quality |
| Candidate | pin chính xác ở `REVIEW_IDENTITY_PIN.json` |
| Scope authority | `DISP-STK-233` (kế thừa — xem `SCOPE_AUTHORITY_DISPOSITION.md`) |

---

## 1. Hai phần rạch ròi — không được để trạng thái mơ hồ

Register nói rõ: nếu Stocktake formal deferred thì `WWHN-235` **phải** có disposition
`N/A` hoặc `DEFERRED`, không được để `Blocked`/`Pending`. Nhưng `WWHN-235` không chỉ nói về
Stocktake — nó nói "duplicate, simultaneous requests, stale version, partial state, invalid
transition, RBAC, rollback/no-partial-write và DB verification". Tám chiều đó **có tồn tại**
trên baseline cho bốn action đổi tồn khác. Vì vậy ticket phải được tách:

| Phần | Phạm vi | Disposition |
|---|---|---|
| **235-A** | Tám chiều test trên `POST_RECEIPT`, `POST_ISSUE`, `POST_REVERSAL`, `MIGRATION_COMMIT` | **PHẢI CHẠY** — §2 dưới đây |
| **235-B** | Tám chiều test trên Stocktake session/count/variance/apply-adjustment | **N/A hoặc DEFERRED** — §4 dưới đây |

Nếu chỉ đóng một phần và để phần kia treo, `DEV234-P1-03` vẫn OPEN.

## 2. 235-A — bộ test độc lập phải chạy trên exact candidate

### 2.1. Chuẩn bị

```bash
git -C <BE_REPO> fetch --all && git -C <BE_REPO> checkout <be_review_sha>
composer install --no-interaction
php artisan test --filter "Wwhn234ConflictClassContractTest|Wwhn234PostIssueIdempotencyPayloadTest"
```

`<be_review_sha>` lấy từ `REVIEW_IDENTITY_PIN.json` → `backend.review_sha`. **Không** chạy
trên `m1` HEAD trôi: `m1` là nhánh dùng chung, và một ticket khác regenerate artifact trong
cùng ngày sẽ làm kết quả không tái lập được (bài học `WWHN-290`).

### 2.2. Ánh xạ TC → test

`TC_P1_TRACEABILITY.json` là nguồn chính thức. Tóm tắt: **30 COVERED / 1 PARTIAL / 1 GAP**
trên 32 TC của SRS §13.3.

Lệnh chạy toàn bộ tập test được ánh xạ:

```bash
php artisan test --filter "Wwhn289StockInvariantProofTest|Wwhn289SecurityRegressionProofTest|StockDocumentCanonicalFlowTest|PostReceiptAuditLogTest|PostReceiptConcurrencyBehaviorTest|Wwhn219RuntimeConcurrencyTest|InboundScanRuntimeConcurrencyTest|Wwhn201PostIssueAtomicNonNegativeStockTest|PostIssueAuditLogTest|OutboundPermissionTest|PostIssueConcurrencyBehaviorTest|Wwhn234PostIssueIdempotencyPayloadTest|Wwhn207InboundReversalFlowTest|Flow05InventoryControlAlignmentTest|Wwhn239ReversalItemLevelClosureTest|Wwhn242ReversalScopeAndProjectionTest|MigrationFinalDataLoadTest|Wwhn260MigrationPackClosureTest|Wwhn172MigrationSafetyTest|Wwhn234ConflictClassContractTest"
```

### 2.3. Tám chiều theo yêu cầu của WWHN-235

| Chiều WWHN-235 | TC tương ứng | Chạy bằng |
|---|---|---|
| duplicate | TC-P1-005/006, 013/014, 029/030, 037/038 | `Wwhn234PostIssueIdempotencyPayloadTest`, `Wwhn289StockInvariantProofTest`, `Wwhn239ReversalItemLevelClosureTest`, `MigrationFinalDataLoadTest` |
| simultaneous requests | TC-P1-007/015/031/039 | `Wwhn219RuntimeConcurrencyTest`, `InboundScanRuntimeConcurrencyTest`, `Wwhn201PostIssueAtomicNonNegativeStockTest`, `Wwhn289StockInvariantProofTest` |
| stale version | TC-P1-007/015 | `PostReceiptConcurrencyBehaviorTest`, `PostIssueConcurrencyBehaviorTest` |
| partial state | TC-P1-002/010 | `StockDocumentCanonicalFlowTest` (422 `FULL_POST_REQUIRED`), `Wwhn207InboundReversalFlowTest` (reversal một phần) |
| invalid transition | TC-P1-004/012/028/036 | `PostReceiptConcurrencyBehaviorTest`, `PostIssueConcurrencyBehaviorTest`, `Wwhn207InboundReversalFlowTest`, `MigrationFinalDataLoadTest` |
| RBAC | TC-P1-003/011/027/035 | `Wwhn289SecurityRegressionProofTest`, `OutboundPermissionTest`, `Wwhn242ReversalScopeAndProjectionTest`, `MigrationFinalDataLoadTest` |
| rollback / no-partial-write | TC-P1-008/016/032/040 | `Wwhn289StockInvariantProofTest`, `Wwhn239ReversalItemLevelClosureTest`, `Wwhn172MigrationSafetyTest` |
| DB verification | mọi TC | §2.4 |

### 2.4. DB verification — truy vấn độc lập, không tin response

Chạy sau mỗi kịch bản; kết quả rỗng = PASS.

```sql
-- 1. Ledger phải khớp balance tuyệt đối (DEC-INV-001: movement là nguồn sự thật).
SELECT b.warehouse_id, b.sku_id, b.on_hand_qty, COALESCE(SUM(m.quantity), 0) AS ledger_qty
FROM inventory_balances b
LEFT JOIN stock_movements m
  ON m.warehouse_id = b.warehouse_id AND m.sku_id = b.sku_id AND m.stock_status = b.stock_status
GROUP BY b.warehouse_id, b.sku_id, b.on_hand_qty, b.stock_status
HAVING b.on_hand_qty <> COALESCE(SUM(m.quantity), 0);

-- 2. Không được có movement trùng cho cùng (document, line).
SELECT movement_key, COUNT(*) FROM stock_movements GROUP BY movement_key HAVING COUNT(*) > 1;

-- 3. Không âm kho ở bất kỳ bucket nào.
SELECT * FROM inventory_balances WHERE on_hand_qty < 0 OR available_qty < 0;

-- 4. Mỗi operation scope chỉ có đúng một posting_event thành công.
SELECT operation, idempotency_key, COUNT(*) FROM posting_event
GROUP BY operation, idempotency_key HAVING COUNT(*) > 1;

-- 5. Request bị từ chối không được để lại lease treo.
SELECT * FROM idempotency_keys
WHERE status = 'processing' AND locked_until < NOW() - INTERVAL '10 minutes';

-- 6. REQ-SRS-037: không được có bảng kiểm kê nào.
SELECT table_name FROM information_schema.tables
WHERE table_name LIKE 'stocktake%' OR table_name = 'safe_stock_configs';
```

### 2.5. Kiểm chứng mã lỗi §8.2 (mới sau WWHN-234)

QA1 phải xác nhận bằng response thật, không chỉ bằng test:

| Kịch bản | Kỳ vọng |
|---|---|
| Hai Post cùng chứng từ, kẻ thua | `412 STALE_VERSION` kèm `expected_version`/`current_version` |
| Mất CAS trên `inventory_balances` | `409 CONCURRENCY_CONFLICT` (**không còn** `VERSION_CONFLICT`) |
| Reversal Receipt khi item đã Issue | `409 DOWNSTREAM_DEPENDENCY_EXISTS` (**không còn** `REVERSAL_DEPENDENCY_EXISTS`) |
| Cùng key, khác payload | `409 IDEMPOTENCY_CONFLICT` |
| Retry cùng key, cùng payload — cả Post Receipt **và Post Issue** | `200` + `data.replayed=true` + header `Idempotent-Replay: true` |
| Thiếu `If-Match` hoặc `Idempotency-Key` | `428 PRECONDITION_REQUIRED` |

Hàng thứ năm là hồi quy của finding **F-09**: trước WWHN-234 Post Issue **không** trả
`data.replayed`.

### 2.6. FE — hành vi UI của request thua race (AC-GWT-CONC-001)

```bash
cd <FE_REPO> && npx vitest run src/shared/api/concurrency-contract.test.ts
```

Kiểm tra thủ công trên UI: mở cùng một phiếu ở hai tab, Post ở tab 1, rồi Post ở tab 2.
Tab 2 phải hiện thông báo *"Phiếu đã được người khác cập nhật… Hãy tải lại phiếu rồi thực
hiện lại"* và **không** được tự gửi lại. Nếu tab 2 tự retry hoặc sinh Idempotency-Key mới,
đó là FAIL của AC-GWT-CONC-001.

## 3. Khoảng trống QA1 phải ghi nhận (không được lấp bằng lời)

| ID | TC | Vấn đề | Đề xuất |
|---|---|---|---|
| G-01 | `TC-P1-016` | POST_ISSUE ROLLBACK mới chứng minh gián tiếp qua nhánh `INSUFFICIENT_STOCK`; chưa có test inject lỗi sau bước movement/balance | Dựng theo mẫu `Wwhn289StockInvariantProofTest::test_rollback_dimension_*` |
| G-02 | `TC-P1-039` | MIGRATION_COMMIT CONCURRENCY: không có test hai commit song song cùng `migration_run_id` | Rủi ro thấp (job một lần + approver gate) nhưng là ô trống thật của §13.3 |
| G-03 | `TC-P1-017..024` | Không tồn tại trong SRS; `COMPONENT_ISSUE_POST` là action đổi tồn nhưng không có hàng nào trong ma trận | Chuyển BA (finding F-07) |

## 4. 235-B — phần Stocktake ĐÃ có disposition, không viết lại

Phần này **không** cần disposition mới. `DISP-STK-233` §8 và
`docs/evidence/WWHN-233/QA_DISPOSITION_WWHN-235.md` đã dispose `WWHN-235` phần kiểm kê thành
**DEFERRED / N/A cho MVP v1.12**, kèm lý do vì sao `Blocked`/`Pending` là sai trạng thái và
kèm điều kiện re-open dùng chung bốn điều kiện của `DISP-STK-233` §2.

QA1 chỉ cần **trỏ tới** văn bản đó khi ghi trạng thái ticket, rồi chạy negative gate mà nó
mô tả:

```bash
node scripts/verify-wwhn233-count-disposition.mjs
php vendor/bin/phpunit --filter Wwhn233CountDeferredGate
```

```bash
node scripts/verify-wwhn-233-closure.mjs
```

Viết một disposition thứ hai cho cùng một ticket chính là cơ chế hỏng mà `DEC-STK-002`
§Context mô tả — cùng một câu hỏi bị nêu lại năm lần ở `WWHN-190/195/205/207/218`. Vòng này
không lặp lại nó.

Điều `WWHN-234` bổ sung là **235-A** ở §2: tám chiều test đó áp dụng cho bốn action đổi tồn
**có thật**, và phần đó trước nay chưa ai giao cho QA1.

## 5. Điều kiện đóng `DEV234-P1-03`

1. 235-A có **fixed-build report** trên đúng `backend.review_sha`, kèm log test và output của
   sáu truy vấn DB ở §2.4.
2. G-01/G-02/G-03 được ghi nhận rõ ràng (đã bổ sung test, hoặc chấp nhận rủi ro có người ký).
3. 235-B: QA1 ghi trạng thái `DEFERRED / N/A` **trỏ tới** `DISP-STK-233` §8 và
   `docs/evidence/WWHN-233/QA_DISPOSITION_WWHN-235.md`, và chạy negative gate ở §4.
   Không tạo văn bản disposition mới.

Thiếu bất kỳ mục nào ⇒ `DEV234-P1-03` vẫn OPEN.
