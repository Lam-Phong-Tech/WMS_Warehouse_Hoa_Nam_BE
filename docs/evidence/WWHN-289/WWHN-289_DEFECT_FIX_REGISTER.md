# WWHN-289 — Defect / fix register và closure map

- Ngày: 2026-08-19 (ICT) — trả lời đánh giá **73/100** của Wuangg (vòng trước: 34/100)
- Jira: `WWHN-289` — `[BE] Khắc phục lỗi stock/bảo mật/core` · Parent `WWHN-77` · Dependency `HN-UAT-003` · Role `BE1`
- Nhánh: `m1` (BE và FE). Không tạo nhánh riêng.
- Nguồn pin duy nhất: `docs/evidence/WWHN-289/WWHN-289_PINS.json`. Mọi SHA/số liệu dưới đây trích từ đó và được `scripts/verify-wwhn289-closure.mjs` + `Wwhn289ClosureEvidenceTest` ép bằng nhau.

| Pin | Giá trị |
|---|---|
| Change-range base (BE) | `93b1e71f0e01c8a7c77670e0c8f0af38d2e68470` |
| **Change-range final (BE — commit CUỐI chạm code WWHN-289)** | `8bdf9ad0a00461afe27e3e11a26e7fd13d68f9e6` |
| Revision chạy CI run đã pin | `d1d2e145c9f14798fb2b11706476fd8e174ae31f` (chỉ khác final ở workflow + verifier, ngoài `code_scope_paths`) |
| FE consumer base | `226075d21f2bf953c5f639c71598136e434fc42b` |
| FE consumer final | `404267083d2c0ca79507f7fc1fdf05170cdae48e` |

Vòng trước pin `e593202a580d8033257e8aabaae0e5d886071ab2` làm final trong khi vẫn còn commit code sau nó — đó chính là "evidence chain chưa sạch" mà `DEV289-P1-02R` nói. Bản ghi supersession nằm trong `PINS.json → superseded_revisions`; bất biến "không commit nào sau final chạm code WWHN-289" nay do verifier kiểm bằng máy, không bằng lời hứa.

## 0. Bộ evidence

| Tài liệu / artifact | Đóng |
|---|---|
| register này | closure map tổng, defect-input gate |
| `WWHN-289_PINS.json` | pin máy đọc được: SHA, digest, CI run, test count |
| `WWHN-289_STOCK_INVARIANT_PROOF.md` | `DEV289-P1-03R` |
| `WWHN-289_SECURITY_TRACE.md` | `DEV289-P1-04R` |
| `WWHN-289_QA1_HANDOFF.md` | `DEV289-P1-05R` (bàn giao, không tự ký) |
| `WWHN-289_FE_CONSUMER_SUPERSESSION.md` | phần FE của `DEV289-P1-02R` / `DEV289-P2-01` |
| `build/evidence/wwhn-289/stock-invariant-proof.json` | số liệu thô 7 chiều, do test sinh trên PostgreSQL 16 |
| `build/evidence/wwhn-289/security-regression-proof.json` | số liệu thô 9 nhóm, do test sinh trên PostgreSQL 16 |
| `tests/Feature/Wwhn289StockInvariantProofTest.php` | khoá hồi quy 7 chiều stock |
| `tests/Feature/Wwhn289SecurityRegressionProofTest.php` | khoá hồi quy 9 nhóm security |
| `tests/Feature/Wwhn289ErdMirrorTypeSafetyTest.php` | khoá hồi quy `BUILD289-05` (type-safety write-bridge) |
| `tests/Feature/Wwhn289ClosureEvidenceTest.php` | ép pin equality giữa các tài liệu |
| `scripts/verify-wwhn289-closure.mjs` | verifier chạy được cho toàn bộ register R |
| `.github/workflows/wwhn-289-closure.yml` | cổng CI exact-SHA trên PostgreSQL 16 |

## 1. Baseline được đối chiếu

- `docs/HN_SRS_FRD_v1.12_FULL.md` — §7.5 Reconciliation Controls, §8.1 Endpoint/enforcement standard, §8.2 HTTP/Error Contract, §9 NFR (NFR-SRS-001..005), §13.2 AC-GWT, §13.3 test design matrix.
- `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — trạng thái màn hình nhập/xuất, không đổi trong ticket này.
- `docs/HoaNam_ERD_Moi_Nhat.md` — `stock_movements` (business_key unique), `inventory_balances` (unique warehouse+sku+status), nhóm PV 51..54.
- `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` — RBAC theo permission code, API-first, CI gates.

Invariant không được phá: MVP không stocktake/adjustment trực tiếp; không partial posting; chỉ Post mới đổi tồn; posted movement/audit không sửa trực tiếp, correction đi qua reversal; enforcement = permission **và** data scope.

## 2. Defect thật tìm được và đã sửa tận gốc

Mỗi defect đối chiếu được với một câu trong baseline docs và có test đỏ-trước/xanh-sau.

| ID | Sev | Vị trí chính xác | Hiện trạng trước fix | Root cause | Fix | Regression test |
|---|---|---|---|---|---|---|
| `SEC289-01` | P1 | `Modules/Warehouse/routes/api.php` — 12 route `inbound-documents*` | User có `scope_ref` trỏ kho khác vẫn `POST /inbound-documents/{id}/post-receipt` thành công, sinh `stock_movement` + `inventory_balance` ngoài phạm vi dữ liệu | Data scope chỉ được cài cho luồng xuất và 6 endpoint đọc tồn. `EnsureCanonicalInboundWarehouse` chỉ ép kho canonical, **không đọc `scope_ref` của actor** | `EnforceInboundWarehouseScope` (mới) gắn toàn bộ route nhập kho canonical + alias Annex C; 403 `DATA_SCOPE_DENIED` đúng §8.2 | `Wwhn289SecurityRegressionProofTest::test_data_scope_dimension_*` |
| `SEC289-02` | P1 | `Modules/Auth/routes/api.php`, nhóm PV, toàn bộ app | Không có **bất kỳ** RateLimiter/throttle nào: `/auth/login` dò mật khẩu không giới hạn; nhóm PV ẩn danh được Annex C khai `429` nhưng 429 không thể xảy ra | Chưa từng đăng ký named limiter, chưa route nào dùng middleware `throttle` | `config/rate_limit.php`, `App\Support\RateLimitResponse` (429 đúng envelope §8.2), limiter `pv-public` / `auth-refresh`, khoá login theo **lần đăng nhập SAI** + audit `LOGIN_THROTTLED` | `Wwhn289SecurityRegressionProofTest::test_rate_limit_dimension_*` |
| `SEC289-03` | P1 | `app/Support/IdempotencyStore.php` + `app/Http/Middleware/ApiConventionMiddleware.php` | 409 idempotency trả `error_code = IDEMPOTENCY_ERROR` — mã **không có** trong §8.2 | Hằng mã lỗi viết thẳng trong `conflict()`, và có **nguồn phát thứ hai** ở middleware convention | `IDEMPOTENCY_CONFLICT` cho "cùng key khác payload", `CONCURRENCY_CONFLICT` cho tranh chấp lease; hai nơi dùng chung một hằng | `Wwhn289StockInvariantProofTest::test_idempotency_dimension_*` |
| `BUILD289-05` | P1 | `2026_08_10_000765_keep_legacy_runtime_and_erd_physical_schema_in_sync` | `migrate:fresh` trên PostgreSQL vỡ: `SQLSTATE[42804] column "created_by" is of type uuid but expression is of type text` trong `hn_erd_mirror_pv_industries()` → **cổng CI closure đỏ 3/3 run**, không phải vì fix sai mà vì build không dựng được | Write-bridge sinh tự động `INSERT INTO <canonical> (cols) SELECT cols FROM <projection>` không cast; view PV khai `NULL AS created_by` (text) còn ERD định nghĩa `uuid`, mà text→uuid không có assignment cast. Trigger chỉ nổ khi có ai đó GHI vào bảng nguồn nên lỗi nằm im từ 10/08 | Migration `2026_08_19_000050_repair_erd_mirror_projection_casts` chèn cast tường minh **chỉ cho cặp type PostgreSQL không tự ép được**, giữ nguyên văn phần `FROM ... ON CONFLICT ...` | `Wwhn289ErdMirrorTypeSafetyTest` (2 chân: tĩnh theo catalog + động ghi thật vào bảng nguồn PV) |
| `BUILD289-06` | P1 | `.github/workflows/wwhn-289-closure.yml` | Cổng đọc lại chính hai file artifact **đã commit** rồi tuyên bố PASS — nó xanh kể cả khi lần chạy không ghi được gì | Bước summary `cp` file có sẵn thay vì bắt lần chạy sinh lại | Bước `Discard committed proof artifacts so the run must regenerate them` + `Assert proof artifacts came from THIS run` (đủ 7 chiều / 9 nhóm, `mismatch=0`, `driver=pgsql`) | Chính cổng, tại run `32206778306` |
| `BUILD289-07` | P2 | `.github/workflows/wwhn-289-closure.yml` | `JWT_SECRET` của cổng ngắn hơn 256 bit làm toàn bộ test auth đỏ trong CI | Giá trị secret đặt tay, không đạt độ dài thuật toán yêu cầu | Secret đủ độ dài + verifier kiểm điều kiện này | `scripts/verify-wwhn289-closure.mjs`, run `32206778306` xanh 93/93 |

### 2.1 Vì sao `SEC289-03` là defect thật, không phải đổi tên cho đẹp

- SRS §8.2 liệt kê mã 409 hợp lệ: `STATE_CONFLICT / IDEMPOTENCY_CONFLICT / CONCURRENCY_CONFLICT / DOWNSTREAM_DEPENDENCY_EXISTS`.
- AC-GWT-IDEMP-001 (§13.2): "Request H2 trả **409 IDEMPOTENCY_CONFLICT**".
- Ma trận test §13.3: `TC-P1-006`, `TC-P1-014`, `TC-P1-030`, `TC-P1-038` đều ghi "409 IDEMPOTENCY_CONFLICT".
- FE đã map sẵn `IDEMPOTENCY_CONFLICT`.

Nghĩa là toàn bộ 4 test case P1 của QA cho kịch bản "same key, different payload" **fail đúng cách** trên build trước fix.

## 3. FE consumer adaptation (cùng ticket, nhánh `m1`)

| File | Thay đổi | Lý do |
|---|---|---|
| `src/shared/api/axios.ts` | interceptor đánh dấu `_isRateLimited` + `_retryAfterSeconds` cho 429 | Trước đây FE chưa từng thấy 429 nên rơi vào nhánh mặc định |
| `src/shared/api/api-error.ts` | `isRateLimitedError()`, `getRetryAfterSeconds()`, thêm `DATA_SCOPE_DENIED` vào nhóm lỗi thiếu quyền | §8.2 xếp `DATA_SCOPE_DENIED` cùng hạng 403 với `PERMISSION_DENIED` |
| `src/shared/api/__tests__/wwhn-289-rate-limit-and-data-scope.test.ts` | 6 test | Khoá hợp đồng tiêu thụ ở phía FE |

Hành vi này được **đo lại tại FE HEAD** sau khi các ticket khác sửa cùng lớp mã lỗi — xem `WWHN-289_FE_CONSUMER_SUPERSESSION.md` (13/13 pass).

## 4. Closure map theo **register R (73/100)**

| Review item | Trạng thái tại repo | Cổng độc lập còn lại |
|---|---|---|
| `DEV289-P1-01R` — không có actual triaged UAT defect | **FAIL-CLOSED / NEEDS OWNER DISPOSITION.** DEV không bịa defect ID. Năm defect ở §2 được ghi nhận là **DEV-discovered từ baseline docs / từ CI đỏ**, không đại diện cho defect UAT của QA1/BA | **QA1 (WWHN-286) + BA (WWHN-288)**: nạp defect thật hoặc xác nhận N/A tường minh |
| `DEV289-P1-02R` — exact-build evidence chain chưa sạch | **EVIDENCE COMPLETE.** Pin một closure revision duy nhất (`change_range.final` trong PINS); PINS chứa SHA code cuối; digest sha256 của từng tài liệu và của artifact CI; **CI run xanh thật `32206778306`** (workflow `wwhn-289-closure.yml`, PostgreSQL 16, conclusion `success`, 93 passed / 850 assertions); bất biến "không commit nào sau final chạm `code_scope_paths`" do `scripts/verify-wwhn289-closure.mjs` kiểm — chạy tại chỗ: **46/46 bất biến còn đúng** | **Tech Lead** duyệt pin + fix map (independent review) |
| `DEV289-P1-03R` — QA hard gate chưa đóng | **EVIDENCE COMPLETE.** Đủ **7 chiều** trên **PostgreSQL 16**: retry, same-key-different-payload, injected rollback, negative stock, true concurrency/race, reversal, reconcile `mismatch_count = 0` | **QA1** chạy lại trên build đã pin và đăng verdict |
| `DEV289-P1-04R` — chưa independent sign-off | **EVIDENCE COMPLETE.** 9 nhóm security trên PostgreSQL 16, gồm ma trận RBAC 6/6 ô DENY không rò quyền chéo và hợp đồng ngưỡng rate-limit ghi rõ **con số thuộc BA/DevOps** | **QA1 / Security / Tech Lead** retest + **BA/DevOps** chốt ngưỡng vận hành |
| `DEV289-P1-05R` — WWHN-291 chưa có live evidence | **HANDOFF COMPLETE.** `WWHN-289_QA1_HANDOFF.md` cập nhật đúng build đã pin, lệnh chạy trên DB tham chiếu, kết quả kỳ vọng 7 chiều + 9 nhóm | **QA1 trên WWHN-291.** DEV không tự ký, không dùng draft signoff GitHub |
| `DEV289-P1-06R` — WWHN-288 chưa có business disposition | **NGOÀI THẨM QUYỀN DEV** | **BA trên WWHN-288**: expected/no-waiver + ngưỡng rate-limit cho UAT và PROD |
| `DEV289-P2-01` — test count lệch 743/717 vs 738/712 | **ĐÓNG.** Số test chỉ tồn tại ở **một** nơi (`PINS.json → test_baseline`), kèm `measured_at_sha` và bản ghi supersession cho hai con số cũ; verifier fail nếu tài liệu nào nêu số khác | **QA1** đối chiếu khi retest |

Tài liệu này **không** tuyên bố QA1 PASS và **không** đóng thay parent `WWHN-77`.

## 5. Known gaps — nêu rõ, không giấu

1. **Không có defect UAT đầu vào.** Năm defect ở §2 do DEV tìm khi đối chiếu code với baseline docs và khi truy nguyên CI đỏ. Nếu QA1/BA nạp defect thật khác, mỗi defect thêm một dòng vào §2 và phải chạy lại đúng bộ suite ở §6.
2. ~~**CI run chưa được pin**~~ — **ĐÃ ĐÓNG 2026-08-19.** `m1` đã đẩy lên remote và workflow `wwhn-289-closure.yml` chạy thật: run `32206778306` trên `d1d2e145c9f14798fb2b11706476fd8e174ae31f`, kết luận **success**, PostgreSQL 16, focused suite **93 passed / 0 failed / 850 assertions**, artifact `wwhn-289-exact-sha-d1d2e145…` digest `sha256:7e8e25f2af5b4d666b01be08f9aa7a5408eb396051fec08bd9ce503a51332e5b`. Đây là run **xanh đầu tiên** của cổng này: ba run trước (`32149843840`, `32160962005`, `32163575388`) đều chết ở bước *Migrate and seed PostgreSQL* vì `BUILD289-05`, còn run `32206427989` — lần đầu qua được bước đó — lộ tiếp `BUILD289-07`. Toàn bộ số liệu nằm ở `PINS.json → ci_run`; `change_range.final` là `8bdf9ad0a00461afe27e3e11a26e7fd13d68f9e6` và giữa nó với revision chạy run không commit nào chạm `code_scope_paths`.
3. **`GET /pv/products` và `/pv/products/{sku_id}` chưa tồn tại** ở runtime (chỉ có `pv/catalog`). Gap coverage Annex C, ngoài phạm vi stock/bảo mật/core — cần DR hoặc ticket riêng, không đóng ngầm ở đây.
4. **Ngưỡng rate limit là giá trị mặc định do DEV chọn** (PV 120 req/phút/IP, login 10 lần sai/phút/(IP+định danh), refresh 30/phút/IP). Docs không quy định con số. Cần **BA/DevOps chốt ngưỡng vận hành cho UAT và PROD**; đổi bằng env (`RATE_LIMIT_*`), không cần sửa code.
5. **Cảnh báo phương pháp test:** request "ẩn danh" phải là thao tác đầu tiên của một test method. `auth('api')->login()` dùng dựng fixture sẽ nhét token vào chính instance JWT mà guard giữ, nên nếu gọi sau đó thì request không có `Authorization` vẫn được coi là đã đăng nhập → 200 và ta sẽ ký một bằng chứng sai.
6. **Chạy test song song trên cùng một DB PostgreSQL gây deadlock** khi hai tiến trình cùng `migrate:fresh` (SQLSTATE 40P01). Ai retest phải dùng database riêng — bằng chứng trong ticket này đo trên DB riêng — nếu không sẽ thấy lỗi đó và tưởng là defect sản phẩm.

## 6. Bộ suite phải chạy lại khi đụng vào phạm vi này

```
php artisan test --filter '/(Wwhn289StockInvariantProofTest|Wwhn289SecurityRegressionProofTest|Wwhn289ClosureEvidenceTest|Wwhn289ErdMirrorTypeSafetyTest|Wwhn201PostIssueAtomicNonNegativeStockTest|Wwhn199ReservationConcurrencyGuardTest|Wwhn180IdempotencyAtomicityTest|StockDocumentIdempotencyTest|StockDocumentConcurrencyGuardTest|InventoryReconciliationIntegrityTest|Wwhn211InventoryScopeTest|SecurityRbacAuditNegativeTest|AuthRbacAuditHardeningTest|AuthorizationTest|Wwhn172SkuContainerTransactionTest|WarrantyStatusOnlyContractTest)/'
```

Số liệu baseline **chỉ** nằm ở `PINS.json → test_baseline` (một nguồn duy nhất, kèm SHA đo được). Không chép số vào bất kỳ tài liệu hay comment nào — verifier sẽ bắt.

## 7. Quy tắc đóng ticket

WWHN-289 chỉ chuyển sang final review khi CI exact-SHA xanh trên revision đã pin **và** các cổng người ở §4 được ghi nhận trên ticket. Một phát biểu do DEV viết không thay thế được chữ ký QA1 / BA / Tech Lead.

Điều kiện máy: **ĐÃ ĐẠT** — run `32206778306` success (xem §5.2). Điều kiện người: **CHƯA ĐỦ** — còn `DEV289-P1-01R` (QA1+BA), `DEV289-P1-05R` (QA1 trên `WWHN-291`), `DEV289-P1-06R` (BA trên `WWHN-288`), và chữ ký review độc lập cho `DEV289-P1-02R`/`P1-04R`. Ngoài ra `database/golive/fail_forward_allowlist.php` có một mục mới ghi rõ *"chờ Tech Lead phê duyệt"* — mục đó phải được TL ratify, không được coi là đã dispositioned theo WWHN-300.

Chạy lại toàn bộ kiểm chứng pin/digest/supersession bằng một lệnh:

```
node scripts/verify-wwhn289-closure.mjs
```
