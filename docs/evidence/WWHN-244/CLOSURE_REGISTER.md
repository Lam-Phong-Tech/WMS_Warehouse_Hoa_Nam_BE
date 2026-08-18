# WWHN-244 — Evidence pack đóng P0/P1/P2 (Flow 04 Bảo hành)

| Trường | Giá trị |
|---|---|
| Ngày | 18/08/2026 |
| Nhánh làm việc | `m1` (BE và FE) — **không** tạo nhánh riêng |
| Candidate BE | `77d243e56913ea658a7b93963b3fa71fb7cc1d2c` |
| Candidate FE | `be18bec7d161b0656c460a44e37a7c286099c24c` |
| Quyết định kèm theo | `docs/decision-records/DEC-WAR-STATE-001.md` |
| Ticket liên quan | WWHN-245 (QA), WWHN-334 (component issue) |

---

## 1. DEV244-P1-01 — Pin canonical branch sau owner transfer

**Kết luận: `m1` là canonical, `m1-be-2` đã bị supersede — chứng minh được bằng lịch sử
git, không phải bằng phát biểu.**

| Đối tượng | SHA |
|---|---|
| `origin/m1-be-2` (nhánh cũ của owner trước) | `60816499066f9f493596e326dc41116ef32f5c4b` |
| `origin/m1` tại thời điểm bắt đầu | `127c573a7236ad1c25829eb92011a4914360b840` |
| `m1` local trước thay đổi này | `9894914f1da2cfa89a49d451d680d5b1472573cb` |
| **Candidate BE (m1)** | **`77d243e56913ea658a7b93963b3fa71fb7cc1d2c`** |
| **Candidate FE (m1)** | **`be18bec7d161b0656c460a44e37a7c286099c24c`** |

Lệnh kiểm chứng và kết quả đã chạy:

```bash
git merge-base --is-ancestor origin/m1-be-2 m1   # exit 0  → m1-be-2 là ancestor thuần
git rev-list --count origin/m1-be-2..m1          # 686
git rev-list --count m1..origin/m1-be-2          # 0
```

`m1-be-2` là **ancestor thuần** của `m1`: mọi commit của nó đã nằm trong `m1`, và `m1` đi
trước 686 commit. Vì vậy **supersession là toàn phần, không mất mã, không cần merge**.
Không có commit nào chỉ tồn tại trên `m1-be-2`.

**Formal supersession:** `m1-be-2` đóng băng ở `6081649`, không nhận commit mới; mọi thay
đổi Flow 04 đi qua `m1`. Đây là điều cần Tech/PM xác nhận trong comment sign-off.

## 2. DEV244-P1-02 — Khoá state/guard matrix

Đóng bằng `DEC-WAR-STATE-001` (xem tài liệu quyết định). Tóm tắt điều đã chốt:

- `CHECKING → COMPLETED`: **CHO PHÉP** (case kiểm tra xong không cần sửa không phải đi
  vòng qua `REPAIRING` giả — nếu ép, metric turnaround "đã sửa chữa" của RPT-WAR-01 bị
  thổi phồng và `warranty_event` ghi một sự kiện `REPAIR` không có thật).
- Bốn guard bắt buộc: `WARRANTY_DIAGNOSIS_REQUIRED`, `WARRANTY_TREATMENT_RESULT_REQUIRED`,
  `WARRANTY_HANDOVER_NOTE_REQUIRED`, `CANCEL_REASON_REQUIRED`.
- Guard "người nhận" ghi vào `warranty_event` (`RETURN` + `note` bắt buộc + `created_by`)
  **chứ không thêm cột `receiver`**, vì ERD §45 là baseline đã ký và không có cột đó.
  Nếu BA cần trường có cấu trúc → amendment ERD riêng.

Đồng bộ ba tầng: `CurrentBaselineWarrantyController::TRANSITIONS` ↔
`src/modules/warranty/constants.ts::WARRANTY_NEXT_STATUS` ↔ OpenAPI
`transitionWarrantyStatus.requestBody.status.enum`. Cả ba đều có test khoá lại.

## 3. DEV244-P1-03 — Bộ test QA1 chạy trên exact candidate

Suite thực thi được, **không phải checklist giấy**:

| Hạng mục QA yêu cầu | Test tự động | File |
|---|---|---|
| Positive/negative state | `test_full_transition_matrix_rejects_every_undeclared_edge` (quét toàn bộ 6×6) | `tests/Feature/Wwhn244WarrantyStateMatrixTest.php` |
| Terminal mutation | cùng test trên, nhánh `STATE_CONFLICT` | như trên |
| Guard chẩn đoán / xử lý / bàn giao | `test_leaving_checking_without_diagnosis_is_rejected`, `test_completed_and_returned_require_treatment_and_handover_evidence` | như trên |
| One-active-case (tầng DB, bypass API) | `test_second_active_case_for_same_item_is_blocked_at_database_level` | như trên |
| Giải phóng item sau khi case đóng | `test_closed_case_releases_item_for_a_new_warranty_case` | như trên |
| Stock-neutral | assert `stock_effect=NONE`, `movement_created=false`, `stock_movements` không đổi | như trên + `WarrantyReadinessTest` |
| Stale/concurrent update (412) | `test_identified_warranty_case_resolves_physical_code_and_enforces_versioned_lifecycle` | `tests/Feature/WarrantyReadinessTest.php` |
| Replay/conflict Idempotency-Key | `WarrantyIdempotencyKeyContractTest` | `tests/Feature/WarrantyIdempotencyKeyContractTest.php` |
| TEMP_ONLY | `test_temp_only_warranty_requires_evidence_and_is_stock_neutral` | `tests/Feature/WarrantyReadinessTest.php` |
| RBAC / PII / 401 / 403 | `Wwhn219WarrantyTempOnlyAuditTest`, `WarrantyStatusOnlyContractTest` + guard 403 `PERMISSION_DENIED` khi sửa PII thiếu `warranty.pii.view` | các file tương ứng |
| Audit / history | assert `warranty_event` theo từng transition + `AuditLogger` ghi `changed_fields`/`pii_fields_changed` (không ghi giá trị PII) | `Wwhn244WarrantyStateMatrixTest` + controller |
| PII mask ở FE (fail-closed) | `constants.test.ts` — 6 case mask + idempotency + fail-closed | `src/modules/warranty/constants.test.ts` |

**Còn thiếu và không thể tự đóng ở đây:** QA1 vẫn phải chạy bộ này trên **exact candidate
SHA ở mục 1** rồi đính báo cáo vào WWHN-245. Test tự động là điều kiện cần, không thay thế
chữ ký QA.

### 3.1 Kết quả chạy thật (local, 18/08/2026)

```bash
php artisan test tests/Feature/Wwhn244WarrantyStateMatrixTest.php tests/Feature/WarrantyReadinessTest.php
# → tests=10 passed=9 skipped=1 assertions=181
#   (1 skip = test_suite_runs_on_the_driver_the_pipeline_claims, chỉ chạy khi CI đặt
#    WARRANTY_REQUIRE_DB_DRIVER — đúng thiết kế)

php artisan test --filter='Warranty|Wwhn244|Flow04|Flow06Warranty'
# → tests=58 passed=54 assertions=573 failed=1
```

FE:

```bash
npx vitest run src/modules/warranty     # 6 file · 49 test · pass
npx tsc --noEmit -p tsconfig.app.json   # sạch trong phạm vi module warranty
```

**Về 1 test đỏ:** `CurrentBaselineFlowContractSourceTest::test_warranty_label_and_pv_rules_are_pinned`.
**Không thuộc WWHN-244.** Nguyên nhân: một phiên làm việc song song đang sửa
`CurrentBaselineOperationsController.php` (chưa commit) và đã xoá hai chuỗi mà test này
pin: `where('s.status', 'ACTIVE')` và `where('p.published', true)` (còn 2 lần ở `HEAD`, còn
0 lần trên working tree). Hai assertion về `CurrentBaselineWarrantyController` trong chính
test đó (`If-Match`, `PRECONDITION_REQUIRED`) vẫn xanh. Chủ nhân thay đổi kia phải đóng.

## 4. DEV244-P1-04 — Warranty Readiness workflow

`.github/workflows/warranty-readiness.yml` được bổ sung để lần chạy lại thực sự chứng minh
được điều nó tuyên bố:

| Bước | Trước | Sau |
|---|---|---|
| Lint | 4 file | + suite mới + migration mới |
| Integration test | chỉ `WarrantyReadinessTest` | + `Wwhn244WarrantyStateMatrixTest` |
| Runtime route | có | giữ nguyên |
| OpenAPI route-sync | 7 route | + `POST /api/v1/warranty-cases/{id}/status` |
| Contract publication | **không có** | bước mới: fail nếu OpenAPI mất ma trận / mã lỗi guard / enum status |
| PostgreSQL | **không có** | job `warranty-one-active-case-postgres` (PostgreSQL 16 service) |

Run 32041944324 hỏng vì 429/cache dependencies — đó là hạ tầng, không phải mã. Sau khi
re-run, workflow phải **xanh ở cả hai job** và gắn vào candidate SHA mục 1.

## 5. DEV244-P1-05 — Pin OpenAPI / Postman

| Artifact | Giá trị tại candidate `77d243e` |
|---|---|
| `storage/api-docs/api-docs.json` — SHA-256 | `896f4b1f96e5eded3badac079560055e31c79ff9ba38d5a823ef6f282dd7168c` |
| `storage/api-docs/api-docs.yaml` — SHA-256 | `4ae015a336608dc64d8880a6826d022cfe433d2049ccde1458be614df761bf78` |
| `info.version` | `2.0.0` (runtime contract sinh từ annotation; companion doc: HN_OpenAPI_v1_7.yaml) |
| Số path / operation | 183 path · 231 operation |

Lệnh tái lập:

```bash
php artisan l5-swagger:generate
sha256sum storage/api-docs/api-docs.json storage/api-docs/api-docs.yaml
```

**Lưu ý quan trọng:** hash chỉ tái lập được khi `config/l5-swagger.php` còn dòng
`CurrentBaselineWarrantyController.php` trong `annotations`. Bỏ dòng đó, generator quay lại
sinh `requestBody: {}` rỗng và toàn bộ ma trận biến mất khỏi contract — đây chính là lý do
FE/QA trước đây không đọc được state enum từ OpenAPI. Bước CI "Verify DEC-WAR-STATE-001 is
published in the contract" chặn hồi quy này.

**Postman:** collection phải được export lại từ đúng hai file trên; FE/QA xác nhận cùng
hash trong comment sign-off.

## 6. DEV244-P1-06 — One-active-case ở tầng PostgreSQL

**Gốc lỗi:** partial unique index `uk_warranty_case_one_active_item` chỉ được tạo bên trong
nhánh `if (DB::getDriverName() !== 'pgsql') return;` của
`2026_08_10_000620` / `2026_08_10_000710`. Toàn bộ test suite chạy SQLite ⇒ **không có ràng
buộc nào tồn tại lúc test** ⇒ suite xanh không nói gì về production. Guard trong controller
(`lockForUpdate` + `whereNotIn`) không che job/seeder/import ghi thẳng vào bảng.

**Đã sửa:**

1. Migration `2026_08_18_000100_enforce_warranty_one_active_case_all_drivers.php` tạo index
   trên cả `pgsql` lẫn `sqlite`, điều kiện active theo đúng ERD §45
   (`item_id IS NOT NULL AND status NOT IN ('RETURNED','CANCELLED')`), có `assertNoDuplicateActiveWarranty()`
   fail-fast và `down()` drop index.
2. Test `test_second_active_case_for_same_item_is_blocked_at_database_level` ghi **thẳng vào
   bảng** (bỏ qua controller) và kỳ vọng `QueryException` — chỉ pass khi index thật sự tồn tại.
3. Job CI PostgreSQL 16 thật: đọc `pg_indexes.indexdef` để chứng minh index tồn tại, rồi chạy
   raw SQL chèn case active thứ hai và grep `duplicate key value violates unique constraint
   "uk_warranty_case_one_active_item"`.
4. `WARRANTY_REQUIRE_DB_DRIVER=pgsql` + `test_suite_runs_on_the_driver_the_pipeline_claims`
   chặn tình huống job tên "PostgreSQL" nhưng `phpunit.xml` kéo về SQLite (khai
   `DB_CONNECTION=sqlite` không có `force="true"`) — nếu không có chặn này, job PG là bằng
   chứng giả.

## 7. DEV244-P2-01 — Governance metadata (non-blocking)

Description/label WWHN-244 vẫn mang narrative BE2/Duy. Đề xuất: giữ nguyên phần historical
trace (không xoá), thêm một dòng "Owner hiện tại: DEV — canonical branch `m1`, superseding
`m1-be-2` @ `6081649`" như mục 1. Việc này thuộc PM, không chặn Done.

## 8. Còn mở (không tự đóng được ở tầng code)

| Mục | Trạng thái | Cần ai |
|---|---|---|
| HN-WAR-001 / API screen readiness dependency | **VẪN MỞ** ở mức dependency | BA/PM |
| BA pin HN-PIN-08 | theo rule current-sprint linked, không tính hard blocker riêng | BA |
| QA1 chạy trên exact candidate + báo cáo WWHN-245 | **MISSING** | QA1 |
| Re-run Warranty Readiness tới xanh | chờ hạ tầng | DEV/DevOps |
| UAT/QA UI evidence tại exact candidate | PARTIAL (repo có local/live) | QA1 |
