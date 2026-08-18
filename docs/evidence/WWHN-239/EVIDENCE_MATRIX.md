# WWHN-239 — Evidence matrix (DEV239-P1-02 / P1-03)

| Field | Value |
|---|---|
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` |
| Branch | `m1` — **không tạo nhánh riêng** cho task này |
| Candidate SHA | `__BE_SHA__` |
| DB tham chiếu | PostgreSQL 16 (container `wms_warehouse_hoa_nam_be-postgres-1`), database kiểm thử `hn_wwhn239` |
| Ngày thu bằng chứng | 2026-08-18 |

---

## 1. PR / review — vì sao PR = N/A và điều đó có được chấp nhận không

`m1` là nhánh làm việc chung của dự án; tại thời điểm thu bằng chứng có **nhiều phiên
sửa song song trên cùng một working tree** (WWHN-234 phân lớp mã 409, WWHN-242 data
scope reversal, và các nhánh việc khác). Tạo PR từ `m1` về `m1` là vô nghĩa, còn tạo
nhánh riêng thì trái ràng buộc của task.

**Disposition: `PR = N/A`.** Review được thực hiện trực tiếp trên candidate SHA
`__BE_SHA__` với file set và digest pin ở §2. Tech Lead ghi kết quả review kèm đúng SHA
này lên Jira WWHN-239. Không dùng URL nhánh, không dùng SHA rút gọn, không dùng kết quả
CI của task khác thay cho review.

**Ranh giới sở hữu file** (quan trọng vì working tree dùng chung):

| File | Ai sở hữu | WWHN-239 có ghi vào không |
|---|---|---|
| `Modules/Warehouse/routes/api.php` | WWHN-242 | **Không** |
| `Modules/Warehouse/app/Http/Controllers/ReversalDocumentController.php` | WWHN-242 | **Không** |
| `app/Support/ConflictCode.php` | WWHN-234 | **Không sửa** — nhưng **có commit kèm**, xem §2 ghi chú |
| `app/Http/Middleware/ApiConventionMiddleware.php` | WWHN-234 | **Không** |

## 2. File set của candidate

| # | File | Loại | SHA-256 |
|---|---|---|---|
| 1 | `Modules/Warehouse/app/Services/CurrentBaselineReversalService.php` | sửa | `__D1__` |
| 2 | `Modules/Warehouse/app/Http/Middleware/EnforceReversalWarehouseScope.php` | thêm | `__D2__` |
| 3 | `Modules/Warehouse/database/migrations/2026_08_18_214000_enforce_one_posted_reversal_per_item.php` | thêm | `__D3__` |
| 4 | `tests/Feature/Wwhn239ReversalItemLevelClosureTest.php` | thêm | `__D4__` |
| 5 | `app/Support/ConflictCode.php` | thêm (WWHN-234) | `__D5__` |
| 6 | `docs/decision-records/DISPOSITION-WWHN-239-REVERSAL-CONTROLLING-DECISION.md` | thêm | `__D6__` |

**Ghi chú bắt buộc đọc về file #5.** `CurrentBaselineReversalService` sau thay đổi này
`use App\Support\ConflictCode` — hằng phân lớp 409 do WWHN-234 đưa vào và tại thời điểm
freeze vẫn đang là file **untracked** trong working tree chung. Nếu commit file #1 mà
không commit #5 thì tại chính candidate SHA sẽ **không tồn tại class** đó và build hỏng.
Vì vậy #5 được commit kèm để candidate tự nhất quán. Đây là **carry-over có chủ đích**,
không phải WWHN-239 chiếm phần việc của WWHN-234; các file còn lại của WWHN-234
(`ApiConventionMiddleware.php`, …) **không** nằm trong commit này.

### Migration

| Migration | Driver | Tác dụng |
|---|---|---|
| `2026_08_18_214000_enforce_one_posted_reversal_per_item` | pgsql + sqlite | partial unique index `ux_reversal_line_posted_item_per_movement` trên `(original_movement_id, item_id) WHERE item_id IS NOT NULL AND posted_movement_id IS NOT NULL`; tự dò relation vật lý theo driver; fail-safe hạ xuống index thường + `Log::warning` nếu dữ liệu hiện có đã trùng |

`down()` chỉ `DROP INDEX IF EXISTS` — không đụng bảng/view, nên không lặp lại sự cố
`migrate:rollback` brick DB của WWHN-300.

### OpenAPI / Postman

**Không thay đổi.** Task không thêm/sửa/xoá route, không đổi permission key, không đổi
path, không đổi field của request/response. Phần mới duy nhất là **mã lỗi nghiệp vụ**
bên trong `ErrorEnvelope` đã khai sẵn (`409 CONCURRENCY_CONFLICT`,
`409 REVERSAL_ITEM_ALREADY_REVERSED`, `422 REVERSAL_ITEM_DUPLICATED`). SRS §8.2 đặt tên
cột là "Error code **examples**", và `App\Support\ConflictCode` quy định chỉ **lớp
concurrency** và **lớp downstream-dependency** buộc dùng đúng hằng — cả hai đều đã tuân
thủ. Do đó `storage/api-docs/*` không cần regenerate cho task này (và cố ý không đụng
vào, vì file đó đang bị phiên khác sửa).

## 3. Test set

| Test | Số ca | Phủ dòng nào của DEV239-P1-04 |
|---|---|---|
| `tests/Feature/Wwhn239ReversalItemLevelClosureTest.php` | 8 | double-reverse, duplicate-in-request, replay, same-key/diff-payload, RBAC 401/403, dependency guard, rollback, no-negative + ledger=balance + orphan=0 + duplicate=0 |
| `tests/Feature/Wwhn242ReversalScopeAndProjectionTest.php` | 7 | 403 data scope (read + write), projection §6 |
| `tests/Feature/Wwhn207InboundReversalFlowTest.php` | 6 | partial/full reverse, over-reverse, idempotency, permission, 428 headers |
| `tests/Feature/Wwhn207OutboundBalanceReconciliationTest.php`, `Wwhn205StockMovementItemIdInvariantTest.php`, `Wwhn204LedgerReconciliationCoverageTest.php`, `Wwhn201PostIssueAtomicNonNegativeStockTest.php` | regression | ledger/balance/no-negative/item invariant |

## 4. Lệnh đã chạy và log thô

| Bước | Lệnh | Log |
|---|---|---|
| Schema | `DB_CONNECTION=pgsql … php artisan migrate:fresh --seed --force` | `RAW_migrate_fresh_seed_pgsql.log` |
| Reversal + regression tồn kho (PostgreSQL 16) | `DB_CONNECTION=pgsql … php vendor/bin/phpunit --filter "Wwhn239…\|Wwhn242…\|Wwhn207…\|Wwhn205…\|Wwhn204…\|Wwhn201…"` | `RAW_phpunit_reversal_pgsql.log` |
| Contract/schema regression (sqlite) | `php vendor/bin/phpunit --filter "DocumentationBaselineContractTest\|Flow05InventoryControlAlignmentTest\|InventoryReconciliationIntegrityTest\|Wwhn219RuntimeConcurrencyTest\|Wwhn219ApplyAdjustmentDispositionTest\|Wwhn211InventoryScopeTest"` | `RAW_phpunit_contract_sqlite.log` |
| Đối soát dữ liệu | `psql -d hn_wwhn239 -f docs/evidence/WWHN-239/RECONCILIATION.sql` | `RAW_reconciliation_pgsql.log` |

## 5. Kết quả

__RESULTS__

## 6. Điều bằng chứng này KHÔNG chứng minh

- **Không** thay cho WWHN-241: test tự động chạy tuần tự nên chứng minh được *guard*,
  không chứng minh được *race thật*. Ca "true competing reversal" bằng hai request song
  song vẫn thuộc QA1 — xem `QA1_TEST_PACK_WWHN-241.md` §3.
- **Không** phải UAT và không phải bằng chứng vận hành trên môi trường thật.
- **Không** đóng `OP-REV-05` (adoption `POST /api/v1/reversals`).
- **Không** thay cho sign-off của BA/Approver về `DISP-REV-239`.
