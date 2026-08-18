# WWHN-289 — Defect / fix register và closure map

- Ngày: 2026-08-18 (ICT) — trả lời đánh giá **34/100** của Wuangg
- Jira: `WWHN-289` — `[BE] Khắc phục lỗi stock/bảo mật/core` · Parent `WWHN-77` · Dependency `HN-UAT-003` · Role `BE1`
- Nhánh: `m1` (BE và FE). Không tạo nhánh riêng.
- Nguồn pin duy nhất: `docs/evidence/WWHN-289/WWHN-289_PINS.json` — mọi SHA/ngưỡng/kết quả dưới đây trích từ đó và được `Wwhn289ClosureEvidenceTest` ép bằng nhau.

| Pin | Giá trị |
|---|---|
| Change-range base (BE) | `93b1e71f0e01c8a7c77670e0c8f0af38d2e68470` |
| Change-range final (BE candidate) | `e593202a580d8033257e8aabaae0e5d886071ab2` (+ hai commit evidence kế tiếp, xem PINS) |
| FE consumer base | `226075d21f2bf953c5f639c71598136e434fc42b` |
| FE consumer final | `404267083d2c0ca79507f7fc1fdf05170cdae48e` |

## 0. Bộ evidence

| Tài liệu / artifact | Đóng |
|---|---|
| register này | closure map tổng, defect-input gate |
| `WWHN-289_PINS.json` | pin máy đọc được, chống rot |
| `WWHN-289_STOCK_INVARIANT_PROOF.md` | `DEV289-P1-03` |
| `WWHN-289_SECURITY_TRACE.md` | `DEV289-P1-04` |
| `WWHN-289_QA1_HANDOFF.md` | `DEV289-P1-05` (bàn giao, không tự ký) |
| `build/evidence/wwhn-289/stock-invariant-proof.json` | số liệu thô do test sinh |
| `build/evidence/wwhn-289/security-regression-proof.json` | số liệu thô do test sinh |
| `tests/Feature/Wwhn289StockInvariantProofTest.php` | khoá hồi quy 5 chiều stock |
| `tests/Feature/Wwhn289SecurityRegressionProofTest.php` | khoá hồi quy 4 nhóm security |
| `tests/Feature/Wwhn289ClosureEvidenceTest.php` | ép pin equality giữa các tài liệu |
| `.github/workflows/wwhn-289-closure.yml` | cổng CI exact-SHA trên PostgreSQL 16 |

## 1. Baseline được đối chiếu

- `docs/HN_SRS_FRD_v1.12_FULL.md` — §7.5 Reconciliation Controls, §8.1 Endpoint/enforcement standard, §8.2 HTTP/Error Contract, §9 NFR, §13.2 AC-GWT, §13.3 test design matrix.
- `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — trạng thái màn hình nhập/xuất, không đổi trong ticket này.
- `docs/HoaNam_ERD_Moi_Nhat.md` — `stock_movements` (business_key unique), `inventory_balances` (unique warehouse+sku+status).
- `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — RBAC theo permission code, API-first, CI gates.

Invariant không được phá: MVP không stocktake/adjustment trực tiếp; không partial posting; chỉ Post mới đổi tồn; posted movement/audit không sửa trực tiếp, correction đi qua reversal; enforcement = permission **và** data scope.

## 2. Defect thật tìm được và đã sửa tận gốc

Ba defect dưới đây **không** phải suy diễn: mỗi cái đối chiếu được với một câu trong docs baseline và có test đỏ-trước/xanh-sau.

| ID | Sev | Vị trí chính xác | Hiện trạng trước fix | Root cause | Fix | Regression test |
|---|---|---|---|---|---|---|
| `SEC289-01` | P1 | `Modules/Warehouse/routes/api.php` — 12 route `inbound-documents*` | User có `scope_ref` trỏ kho khác vẫn `POST /inbound-documents/{id}/post-receipt` thành công, sinh `stock_movement` + `inventory_balance` ngoài phạm vi dữ liệu | Data scope chỉ được cài cho luồng xuất và 6 endpoint đọc tồn. `EnsureCanonicalInboundWarehouse` chỉ ép kho canonical, **không đọc `scope_ref` của actor**, nên bị nhầm là đã đủ | `EnforceInboundWarehouseScope` (mới) gắn vào toàn bộ route nhập kho canonical + alias Annex C; 403 `DATA_SCOPE_DENIED` đúng §8.2 | `Wwhn289SecurityRegressionProofTest::test_data_scope_dimension_*` |
| `SEC289-02` | P1 | `Modules/Auth/routes/api.php`, `Modules/Warehouse/routes/api.php` (nhóm PV), toàn bộ app | Không có **bất kỳ** RateLimiter/throttle nào: `/auth/login` dò mật khẩu không giới hạn; nhóm PV ẩn danh được Annex C khai `429` nhưng 429 không thể xảy ra | Chưa từng đăng ký named limiter, chưa route nào dùng middleware `throttle` | `config/rate_limit.php`, `App\Support\RateLimitResponse` (429 đúng envelope §8.2), limiter `pv-public` / `auth-refresh`, khoá login theo **lần đăng nhập SAI** + audit `LOGIN_THROTTLED` | `Wwhn289SecurityRegressionProofTest::test_rate_limit_dimension_*` |
| `SEC289-03` | P1 | `app/Support/IdempotencyStore.php` | 409 idempotency trả `error_code = IDEMPOTENCY_ERROR` — mã **không có** trong §8.2 | Hằng mã lỗi được viết thẳng trong hàm `conflict()`, không đối chiếu contract | `IDEMPOTENCY_CONFLICT` cho "cùng key khác payload", `CONCURRENCY_CONFLICT` cho tranh chấp lease | `Wwhn289StockInvariantProofTest::test_idempotency_dimension_*` |

### 2.1 Vì sao `SEC289-03` là defect thật, không phải đổi tên cho đẹp

- SRS §8.2 liệt kê mã 409 hợp lệ: `STATE_CONFLICT / IDEMPOTENCY_CONFLICT / CONCURRENCY_CONFLICT / DOWNSTREAM_DEPENDENCY_EXISTS`.
- AC-GWT-IDEMP-001 (§13.2): "Request H2 trả **409 IDEMPOTENCY_CONFLICT**".
- Ma trận test §13.3: `TC-P1-006`, `TC-P1-014`, `TC-P1-030`, `TC-P1-038` đều ghi "409 IDEMPOTENCY_CONFLICT".
- FE đã map sẵn `IDEMPOTENCY_CONFLICT` tại `src/shared/api/wwhn-153-final-signoff.ts`.

Nghĩa là toàn bộ 4 test case P1 của QA cho kịch bản "same key, different payload" sẽ **fail đúng cách** trên build trước fix, và FE không bao giờ nhận diện được xung đột này.

## 3. FE consumer adaptation (cùng ticket, nhánh `m1`)

| File | Thay đổi | Lý do |
|---|---|---|
| `src/shared/api/axios.ts` | interceptor đánh dấu `_isRateLimited` + `_retryAfterSeconds` cho 429 | Trước đây FE chưa từng thấy 429 nên rơi vào nhánh mặc định "Máy chủ trả về lỗi (429)" |
| `src/shared/api/api-error.ts` | `isRateLimitedError()`, `getRetryAfterSeconds()`, thêm `DATA_SCOPE_DENIED` vào nhóm lỗi thiếu quyền | §8.2 xếp `DATA_SCOPE_DENIED` cùng hạng 403 với `PERMISSION_DENIED` |
| `src/shared/api/__tests__/wwhn-289-rate-limit-and-data-scope.test.ts` | 6 test | Khoá hợp đồng tiêu thụ ở phía FE |

Không cần đổi retry policy: `src/app/providers/query-client.ts` đã fail-fast mọi 4xx (`shouldRetryQuery`) và `mutations.retry = 0`, nên 429 không tạo bão retry — đã kiểm chứng bằng đọc code, không phải giả định.

## 4. Closure map theo đúng register của review

| Review item | Trạng thái tại repo | Cổng độc lập còn lại |
|---|---|---|
| `DEV289-P1-01` — chưa có defect UAT cụ thể | **FAIL-CLOSED / NEEDS OWNER DISPOSITION.** DEV không được bịa defect ID. Ba defect ở §2 được ghi nhận là **DEV-discovered từ baseline docs**, không được đại diện cho defect UAT của QA1/BA | **QA1 (WWHN-286) + BA (WWHN-288)** nạp triage log + defect ID |
| `DEV289-P1-02` — không có PR/SHA/build | **EVIDENCE COMPLETE.** Pin base/final trong `WWHN-289_PINS.json`, fix map ở §2, known gaps ở §5, CI exact-SHA `.github/workflows/wwhn-289-closure.yml` | **Tech Lead / PM** duyệt pin, một comment |
| `DEV289-P1-03` — chưa chứng minh stock invariant | **EVIDENCE COMPLETE.** 5 chiều + reconcile `mismatch_count = 0`, số liệu thô tại `build/evidence/wwhn-289/stock-invariant-proof.json` | **QA1** chạy lại trên build đã pin |
| `DEV289-P1-04` — không có trace security | **EVIDENCE COMPLETE.** 3 finding → fix → retest ở §2 + `WWHN-289_SECURITY_TRACE.md`, 4 nhóm regression chạy qua HTTP thật | **QA1 / Security / Tech Lead** duyệt |
| `DEV289-P1-05` — QA1 chưa retest | **HANDOFF COMPLETE.** `WWHN-289_QA1_HANDOFF.md` có lệnh chạy, môi trường, kết quả kỳ vọng | **QA1 phải thực hiện trên WWHN-291.** DEV không tự ký |
| `DEV289-P1-06` — BA chưa chốt expected/waiver | **NGOÀI THẨM QUYỀN DEV** | **BA trên WWHN-288** |

Tài liệu này **không** tuyên bố QA1 PASS và **không** đóng thay parent `WWHN-77`.

## 5. Known gaps — nêu rõ, không giấu

1. **Không có defect UAT đầu vào.** Ba defect ở §2 do DEV tìm khi đối chiếu code với baseline docs. Nếu QA1/BA nạp defect thật khác, mỗi defect thêm một dòng vào §2 và phải chạy lại đúng bộ suite ở §6.
2. **Bằng chứng của DEV chạy trên sqlite `:memory:`** (driver mặc định của `phpunit.xml`). CI `wwhn-289-closure.yml` chạy lại đúng bộ này trên **PostgreSQL 16** ở exact SHA. Chỉ kết quả CI mới là bằng chứng chạy trên DB tham chiếu.
3. **`GET /pv/products` và `/pv/products/{sku_id}` chưa tồn tại** ở runtime (chỉ có `pv/catalog`). Đây là gap **coverage Annex C**, không thuộc phạm vi stock/bảo mật/core của WWHN-289 — cần DR hoặc ticket riêng, không được đóng ngầm ở đây.
4. **Ngưỡng rate limit là giá trị mặc định do DEV chọn** (PV 120 req/phút/IP, login 10 lần sai/phút/(IP+định danh), refresh 30/phút/IP). Docs không quy định con số. Cần **BA/DevOps chốt ngưỡng vận hành**; đổi bằng env, không cần sửa code.
5. **Cảnh báo phương pháp test** (đã ghi trong file test): request "ẩn danh" phải là thao tác đầu tiên của một test method. `auth('api')->login()` dùng dựng fixture sẽ nhét token vào chính instance JWT mà guard giữ, nên nếu gọi sau đó thì request không có `Authorization` vẫn được coi là đã đăng nhập → **200 và ta sẽ ký một bằng chứng sai**. Đây chính là bẫy đã bắt được trong phiên này.

## 6. Bộ suite phải chạy lại khi đụng vào phạm vi này

```
php artisan test --filter '/(Wwhn289StockInvariantProofTest|Wwhn289SecurityRegressionProofTest|Wwhn289ClosureEvidenceTest|Wwhn201PostIssueAtomicNonNegativeStockTest|Wwhn199ReservationConcurrencyGuardTest|Wwhn180IdempotencyAtomicityTest|StockDocumentIdempotencyTest|StockDocumentConcurrencyGuardTest|InventoryReconciliationIntegrityTest|Wwhn211InventoryScopeTest|SecurityRbacAuditNegativeTest|AuthRbacAuditHardeningTest|AuthorizationTest|Wwhn172SkuContainerTransactionTest|WarrantyStatusOnlyContractTest)/'
```

Baseline toàn suite sqlite: **trước** 681 test / 657 pass / 1 fail / 1 error / 22 skip — **sau** 738 test / 712 pass / **0 fail / 0 error** / 26 skip. (Chênh tổng số test bao gồm cả test do ticket khác thêm vào `m1` cùng ngày; điều WWHN-289 khẳng định là 0 fail / 0 error sau thay đổi.)

FE: `npm run test` → **69 file / 522 test pass**; `npm run typecheck` sạch.

## 7. Quy tắc đóng ticket

WWHN-289 chỉ chuyển sang final review khi CI exact-SHA xanh **và** ba cổng người ở §4 được ghi nhận trên ticket. Một phát biểu do DEV viết không thay thế được chữ ký QA1 / BA / Tech Lead.
