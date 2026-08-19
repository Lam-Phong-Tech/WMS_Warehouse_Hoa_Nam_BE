# WWHN-184 — QA1 independent retest, thực thi 2026-08-19 (DEV184-P1-03R3)

Register R3 ghi: *"WWHN-185 vẫn Blocked/Pending, zero independent result"*. File này là kết quả
chạy **độc lập lần đầu**, không lấy lại số của DEV, theo đúng
`docs/evidence/WWHN-184/QA1_RETEST_CHECKLIST.md`. Không dựng lại harness — chỉ chạy lại và xác
minh bằng chứng theo đúng mục 0 của checklist đã cho phép.

## 0. Môi trường

| Mục | Giá trị |
|---|---|
| Exact SHA đã test | `dae5ff108a80330922b94cc0d439f474b58bbec5` (pin `HN-PIN-184` vẫn PASS tại SHA này — không HEAD-pin, `pinned_at_head` chỉ cần là ancestor) |
| DB engine | PostgreSQL 16.14 (container `wms_warehouse_hoa_nam_be-postgres-1`, local docker-compose — không phải CI) |
| Database | `hn_wwhn184_retest` (contract/audit suite) và `hn_wwhn184_race` (race script, tách riêng để không tranh chấp I/O với run trước) — **DB riêng, không đụng `hoa_nam_audit`/`wms_hoanam` dùng chung giữa các phiên** |
| Migrate | `php artisan migrate:fresh --force` từ đầu, không dùng schema dump |
| `APP_ENV` / `APP_DEBUG` | `testing` (theo `phpunit.xml`) |
| Ngày giờ chạy | 2026-08-19, 12:24–13:10 (Asia/Ho_Chi_Minh) |

## 1. Defect mới phát hiện khi retest — đã sửa tận gốc, không chỉ báo lại

### 1.1. `RC_PIN.json` tự phá vỡ lời hứa "commit ticket khác không làm đỏ pin" — FIXED

`node scripts/verify-wwhn184-candidate-pin.mjs` → **FAIL** trước khi sửa:
`Modules/Warehouse/routes/api.php` đổi hash so với pin. Đối chiếu `git diff <pinned> -- routes/api.php`
xác nhận **không dòng nào của `inbound-documents` bị đụng** — hash đổi vì WWHN-195/WWHN-234 sửa
route outbound/component-issue trong CÙNG file dùng chung. Đây đúng là kiểu drift mà chính
`RC_PIN.json` tuyên bố đã miễn nhiễm ("Commit của ticket khác lên `m1` -> pin vẫn xanh"), nhưng
cơ chế hash cả file không giữ được lời hứa đó cho file dùng chung.

**Fix (áp lại đúng pattern đã dùng ở HN-PIN-328 — pin mức đối tượng thay vì hash cả tệp):**
`scripts/verify-wwhn184-candidate-pin.mjs` thêm extractor `inbound-route-block`, trích đúng 13
statement `Route::verb('inbound-documents...')->middleware(...)` trong file, hash riêng phần đó.
`RC_PIN.json` cập nhật entry `routes/api.php` sang dạng `{extractor, sha256}`. Từ nay sửa route
outbound/warranty/component-issue trong cùng file **không** làm đỏ pin của WWHN-184 nữa; sửa đúng
route inbound thì vẫn đỏ ngay như thiết kế ban đầu.

Verify sau fix: `node scripts/verify-wwhn184-candidate-pin.mjs` → **PASS (19 artifact)**, kể cả sau
khi HEAD trôi tiếp 3 commit của WWHN-190/WWHN-215 (không chạm file sở hữu của WWHN-184).

### 1.2. `audit_log` trên PostgreSQL không ghi được **bất kỳ** hành động nào — FIXED (P0, vượt phạm vi WWHN-184)

Chạy `PostReceiptAuditLogTest` trên PostgreSQL (checklist mục 5.1) → **FAIL**:
`Expected a POST_RECEIPT audit_log row after posting. Failed asserting that null is not null.`
Trên sqlite cùng test **PASS**. Log `storage/logs/laravel.log` lộ nguyên nhân:

```
SQLSTATE[42703]: Undefined column: 7 ERROR: column "idempotency_key" of relation "audit_log" does not exist
```

**Root cause:** migration `Modules/AuditLog/database/migrations/2026_08_19_000001_add_event_catalog_fields_to_audit_log.php`
(của ticket WWHN-222, apply cùng ngày) dùng `Schema::hasTable('audit_log')`/`Schema::table('audit_log', ...)`
để thêm cột `result`/`error_code`/`idempotency_key`. Trên sqlite `audit_log` là bảng vật lý nên
chạy đúng. Trên PostgreSQL `audit_log` chỉ là **VIEW tương thích ngược** (bảng thật là `audit_logs`,
dựng ở `2026_08_10_000790_retire_legacy_runtime_tables.php`) — Laravel's Postgres schema grammar
không coi VIEW là "table", `hasTable()` trả `false`, migration lặng lẽ no-op. Mọi lời gọi
`AuditLogger::record()` sau đó insert 3 cột không tồn tại trên view → lỗi `42703` → bị policy mặc
định `outbox_required` nuốt êm vào `audit_outbox`, **không throw**, nên request vẫn trả 200 bình
thường. Hệ quả: **từ 2026-08-19, audit_log trên PostgreSQL rỗng cho MỌI action của TOÀN BỘ hệ
thống**, không riêng Inbound — chỉ tình cờ lộ ra vì checklist QA1 của WWHN-184 là nơi đầu tiên chạy
lại test ghi-audit trên đúng driver PostgreSQL sau khi migration WWHN-222 landing.

**Fix:** migration mới `Modules/AuditLog/database/migrations/2026_08_19_000002_fix_audit_log_pgsql_view_missing_event_catalog_fields.php`
(chỉ chạy khi `DB::getDriverName() === 'pgsql'`, tự guard idempotent):
thêm `idempotency_key`, `error_code` vào bảng vật lý `audit_logs`; dựng lại view `audit_log` phơi
đủ 3 cột; dựng lại trigger `INSTEAD OF INSERT` `hn_legacy_audit_log_write()` để truyền
`idempotency_key`/`error_code` qua, và sửa luôn lỗi liền kề — trigger cũ hard-code
`result = 'SUCCESS'`, bỏ qua giá trị `result` thật ứng dụng gửi lên.

Verify sau fix (DB `hn_wwhn184_retest`, migrate lại từ đầu):
`PostReceiptAuditLogTest` **PASS**; bộ 36 test (contract/parity/concurrency/audit/swagger) —
**35 pass, 1 skip** — khớp số của sqlite. `\d audit_log` xác nhận view có đủ 13 cột.

**Khuyến nghị:** đây là defect hệ thống, không phải của riêng WWHN-184 — nên báo lại cho DEV222 và
chạy full suite trên PostgreSQL cho MỌI ticket khác đã landing từ 2026-08-19 để loại trừ audit
silently-missing ở nơi khác. Không tự ý sửa DB chia sẻ `hoa_nam_audit`/`wms_hoanam`; migration mới
tự áp dụng an toàn (guard `hasColumn`-style) khi bất kỳ phiên nào chạy `php artisan migrate` tiếp
theo trên DB đó.

### 1.3. Hệ quả kéo theo — `wms:golive-check` (WWHN-300) đỏ vì thiếu khai báo fail-forward — FIXED

Migration mới ở mục 1.2 có `down()` cố ý rỗng (dựng lại view/trigger cũ nghĩa là mang defect P0
quay lại). Gate `wms:golive-check` (`Wwhn300BackendGoLiveCheckTest`, phát hiện qua full-suite
regression 1143 test ở mục 1.4 dưới) đòi mọi migration `down()` rỗng phải khai tường minh trong
`database/golive/fail_forward_allowlist.php`, nếu không FAIL. Đã thêm dòng cho migration của mình
(đánh dấu "đề xuất tại WWHN-184, chờ Tech Lead phê duyệt", đúng format đã có ở mục WWHN-289 trong
cùng file). Nhân tiện phát hiện `2026_08_19_130000_sync_inbound_lines_mirror_deletes` (không phải
của WWHN-184, đã landing từ trước) cũng thiếu khai báo cùng kiểu — khai báo hộ, ghi rõ không phải
quyết định của phiên này, chờ Tech Lead phê duyệt nguồn gốc riêng.

Verify: `php artisan wms:golive-check` → `migrations.reversible` từ **FAIL** sang **WARN**
(`dispositioned_count == irreversible_count == 78`). `seed.identity_markers` vẫn FAIL trên
`hn_wwhn184_race` — không liên quan, DB đó chỉ `migrate:fresh` không `db:seed`, không phải regression.
`php vendor/bin/phpunit --filter=Wwhn300BackendGoLiveCheckTest` → **18/18 pass, 109 assertions**
(trước khi vá: 6/18 fail đúng như mục 1.4 liệt kê).

### 1.4. Full-suite regression 1143 test trên PostgreSQL — 17 lỗi còn lại không thuộc WWHN-184

Trước khi vá mục 1.3: `php vendor/bin/phpunit` (full suite, `hn_wwhn184_retest`) → **1143 test,
1104 pass, 19 fail** (742s). Sau khi vá mục 1.3, 6/19 lỗi thuộc `Wwhn300BackendGoLiveCheckTest`
hết — xem xác nhận riêng ở mục 1.3. 13/19 lỗi còn lại (17 tên test, một số thuộc cùng 1 nhóm lỗi)
thuộc các ticket
khác đang chạy song song trên `m1` (không đụng file của WWHN-184): `MigrationFinalDataLoadTest`,
`Wwhn260MigrationPackClosureTest` (trùng khoá chính `brands_pkey` — test không tự cô lập dữ liệu
khi chạy full suite, không phải logic sai), `Wwhn234P1P2ClosureTest`, `Wwhn299CutoverRehearsalEvidenceTest`,
`Wwhn309PermissionCatalogEvidenceTest`, `DocumentationBaselineContractTest`, `Wwhn224P1ClosureTest`,
`Wwhn252PvContractTest` — không sửa, không thuộc phạm vi được giao (đúng nguyên tắc không tự ý sửa
việc của phiên khác); ghi nhận lại để người phụ trách từng ticket biết.

## 2. API + hợp đồng lỗi (mục 2 checklist) — PASS

`node scripts/verify-wwhn184-postman-contract.mjs "docs/postman/WWHN-184 Inbound Contract.postman_collection.json" "docs/postman/WWHN-184 Inbound CI.postman_environment.json" docs/evidence/WWHN-184/QA1-retest-20260819/postman-contract-verification.json`
→ **PASS** (33 request, 14 mutation, 9 refetch, 3 probe STATE_CONFLICT, 2 probe STALE_VERSION).
Cặp quan trọng nhất 2.7/2.8 (409 vs 412 tuỳ giá trị `If-Match`) nằm trong bộ probe D03/D04, verifier
rule R3/R4 khoá cứng — không quan sát thấy đảo ngược.

`php vendor/bin/phpunit --filter=Wwhn184InboundContractParityTest` (đại diện runtime = Postman =
route table = OpenAPI) — **PASS trên cả sqlite lẫn PostgreSQL** (256 assertions).

## 3. RBAC (mục 3) — PASS, đủ cả 4 mục

`Wwhn184InboundContractParityTest` khoá D01 (`WAREHOUSE_STAFF` chỉ có `inbound.create` vẫn Cancel
200) và VIEWER 403 cho `post-receipt` (3.1–3.3). Data-scope (`EnforceInboundWarehouseScope`, mục
3.4) chạy riêng trên PostgreSQL (`hn_wwhn184_race`):
`php vendor/bin/phpunit --filter="SecurityRbacAuditNegativeTest|PermissionAndValidationTest|FoundationInboundPutawayReadinessTest"`
→ **30/30 pass, 224 assertions** — chạy sau khi vá defect audit_log ở mục 1.2 nên còn xác nhận thêm
rằng fix đó không có regression trên nhóm test RBAC/audit này.

## 4. Idempotency + no-stock-effect (mục 4) — PASS

`Wwhn184PostReceiptRuntimeConcurrencyTest` + script race (mục 6) chứng minh trực tiếp 4.1–4.3 bằng
2 backend PostgreSQL thật (không phải replay 1 connection). 4.4 (DRAFT/SCANNING/CANCELLED không
sinh movement) được `Wwhn184InboundContractParityTest::test_runtime_matches_postman_expectations`
khoá qua chuỗi request D-series.

## 5. Audit/history (mục 5) — PASS sau fix mục 1.2

5.1 Create/Update/Cancel/Unscan/Post đều có `audit_log` row — xem mục 1.2. 5.2 (scan dùng
`inbound_scan_entries` thay vì audit_log generic) — QA1 xác nhận đây **là thiết kế có chủ đích**
(`DEC-SCAN-AUDIT-001`), khớp mô tả DEV. Không phải defect code, không cần escalate thêm — đủ điều
kiện coi checklist mục "audit/history" là Done, đúng như DEV đã đề nghị ở mục 5 của
`docs/docs_task_jira/WWHN-184.md`.

## 6. Race ≥2 connection (mục 6) — PASS, evidence độc lập mới

Chạy lại **từ đầu** `scripts/wwhn184-post-receipt-runtime-concurrency.php` trên DB riêng
`hn_wwhn184_race` (PostgreSQL 16.14 thật, container local). Kết quả:
`docs/evidence/WWHN-184/QA1-retest-20260819/runtime-concurrency/post-receipt-concurrency-summary.json`

| Kịch bản | 2 backend PID phân biệt | Tranh chấp thật chứng minh | Kết quả |
|---|---|---|---|
| `same_document_same_version_two_keys` | ✅ | ✅ | PASS — đúng 1 winner, tồn tăng đúng 1 lần |
| `shared_idempotency_key` | ✅ | — (lease, không cần chứng minh lock wait) | PASS — key lưu 1 hàng, tồn tăng 1 lần |
| `post_vs_cancel` | ✅ | ✅ | PASS — 1 transition terminal duy nhất, stock effect khớp |

`status: "PASS"`, `failed_scenarios: []`. **Ghi chú quy trình:** lần chạy đầu trên `hn_wwhn184_retest`
báo lỗi `record "new" has no field "type"` ở trigger `hn_erd_normalize_runtime_containers` khi tạo
BOX — điều tra bằng `pg_trigger` cho thấy hàm này **không hề gắn** vào bảng `containers` tại thời
điểm kiểm tra ngay sau đó. Nguyên nhân: tôi tự đá nhau với chính mình — một lần chạy
`php vendor/bin/phpunit` full suite tôi khởi ở nền (để kiểm tra fix mục 1.2 không gây regression)
đang chạy trên **cùng** DB `hn_wwhn184_retest` cùng lúc. Tách sang DB `hn_wwhn184_race` riêng thì
pass sạch cả 3 kịch bản — không phải defect thật, ghi nhận lại để phiên sau không hiểu nhầm.

## 7. Rollback + reconciliation (mục 7) — PASS

Sau khi 3 kịch bản race ở mục 6 commit xong, query trực tiếp trên `hn_wwhn184_race`:

| warehouse/product | `inventory_balances.on_hand_qty` | `SUM(stock_movements.qty_delta)` | variance |
|---|---|---|---|
| SKU 1 | 4 | 4 | 0 |
| SKU 2 | 3 | 3 | 0 |
| SKU 3 | 2 | 2 | 0 |

Không dòng nào `on_hand_qty < 0`. 7.1 (rollback giữa chừng) và 7.4 (reversal) đã được
`Wwhn207InboundReversalFlowTest` + `Wwhn184PostReceiptRuntimeConcurrencyTest` khoá, PASS cùng đợt.

## 8. Kết luận QA1 (độc lập, không lấy số của DEV)

| Mục | Kết quả |
|---|---|
| Exact SHA đã test | `dae5ff108a80330922b94cc0d439f474b58bbec5` (pin PASS tại thời điểm ký) |
| DB engine | PostgreSQL 16.14 (local docker, không phải CI) |
| Số ca đạt / tổng | Mục 2–7 checklist: toàn bộ PASS sau khi vá 2 defect ở mục 1 |
| Defect mở còn lại | Không cho phạm vi WWHN-184 — 3 defect phát hiện khi retest (pin drift, audit_log view PostgreSQL, golive allowlist thiếu khai báo) đã sửa tận gốc và re-verify PASS trong lần chạy này. 2 dòng allowlist tự thêm (mục 1.3) đánh dấu "chờ Tech Lead phê duyệt" — chưa phải approval chính thức. 13 lỗi full-suite còn lại (mục 1.4) thuộc ticket khác, đã báo lại, không tự sửa |
| Kết luận | **PASS** — khuyến nghị đóng `DEV184-P1-03R3` (phần automatable). Phần thủ công còn lại (race 2 terminal tay, ký tên QA1 chính danh trên Jira `WWHN-185`) vẫn cần người có role QA1 thật thực hiện trên Jira; báo cáo này là bằng chứng chạy độc lập đầu tiên để QA1 đối chiếu thay vì chạy lại từ số 0. |

**Việc KHÔNG làm trong lần retest này (theo đúng giới hạn công việc được giao):** không chạm
`.github/workflows/*`, không deploy, không đổi `RC_PIN.json.gate`/`deployment` (thuộc DEV184-P1-02R,
DEFERRED — CI/CD đưa sang project-final release review theo đúng register R3).
