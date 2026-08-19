# WWHN-184 — QA1 retest R4 tại post-fix exact candidate (DEV184-P1-03R4 + DEV184-P1-05R4)

Register R4 ghi hai khuyết còn lại của lượt R3:
1. **P1-05R4:** "Final PASS phát sinh sau khi sửa defect nhưng report vẫn ghi exact SHA đầu
   lượt test" — report R3 (`QA1_RETEST_REPORT_20260819.md`) ghi `dae5ff1` là SHA lúc BẮT ĐẦU
   lượt, trong khi 3 defect (pin extractor, audit view pgsql, golive allowlist) được sửa
   TRONG lượt, nên không tồn tại một candidate SHA hậu-fix duy nhất để QA/TL/DevOps cùng
   tham chiếu.
2. **P1-03R4:** WWHN-185 cần một lượt chạy chính danh tại đúng candidate hậu-fix đó.

File này là lượt chạy lại **tại đúng post-fix exact candidate**, không thay thế report R3
(giữ nguyên làm bằng chứng point-in-time của quá trình phát hiện defect).

## 0. Post-fix exact candidate — cách resolve và giá trị

| Mục | Giá trị |
|---|---|
| **Post-fix candidate SHA** | `e16862ee6c33913330f69a888d33fba8f14442b1` |
| Cách resolve | Candidate = commit cuối cùng **đổi nội dung pinned object** (owned ∪ critical_dependencies của `RC_PIN.json`). Verifier kiểm bằng nội dung tại chính SHA khai báo: `git show <sha>:<path>` (qua extractor với file dùng chung) phải khớp hash đã pin, SHA phải là ancestor của HEAD — **không** dùng `git log -1 -- <paths>` vì path-based rot theo commit ticket khác trên file dùng chung (đã xảy ra thật trong lượt: `ce5f28d` của WWHN-289 sửa entry allowlist của họ, pin object-level vẫn xanh đúng thiết kế). |
| Pin verify tại candidate | `node scripts/verify-wwhn184-candidate-pin.mjs` → **PASS (21 artifact + 3 dependency, candidate e16862ee)** — raw: `QA1-retest-R4-20260819/pin-verification.json` |
| SHA đầu lượt R3 (tham chiếu lịch sử) | `dae5ff108a80330922b94cc0d439f474b58bbec5` |
| DB engine | PostgreSQL 16.14 (container `wms_warehouse_hoa_nam_be-postgres-1`, local docker-compose — **không dùng CI/GitHub Actions**), đối chứng sqlite `:memory:` |
| Database | `hn_wwhn184_r4` (contract/audit/RBAC/golive) + `hn_wwhn184_r4race` (race, tách riêng) — DB cô lập, dựng `migrate:fresh --force` từ đầu, không đụng DB dùng chung |
| Ngày giờ chạy | 2026-08-19, 18:32–19:10 (Asia/Ho_Chi_Minh) |

### 0.1. Dependency register (mới ở R4 — đóng "pin đầy đủ critical dependency bao gồm audit schema fix")

`RC_PIN.json` từ commit `27ea46e` pin thêm:

- **owned_artifacts** += `Modules/AuditLog/database/migrations/2026_08_19_000002_fix_audit_log_pgsql_view_missing_event_catalog_fields.php`
  (audit schema fix — chính là migration mà register gọi tên) và `scripts/verify-wwhn184-candidate-pin.mjs`
  (đóng lỗ "sửa extractor làm yếu pin mà pin không đỏ").
- **critical_dependencies** (file ticket khác sở hữu nhưng candidate PASS phụ thuộc trực tiếp):
  `2026_08_19_000001` (WWHN-222 — tạo cột catalog), `2026_08_19_150000` (WWHN-222 R2 —
  vocabulary trên cùng view/trigger), và `database/golive/fail_forward_allowlist.php` pin
  **mức đối tượng** (chỉ 2 entry WWHN-184 khai; entry ticket khác không làm đỏ pin).

### 0.2. Bất biến dải commit `dae5ff1..e16862e`

Các commit **đổi nội dung pinned object** trong dải — **đúng 6 commit**, tất cả đã giải trình:

| SHA | Thuộc | Nội dung |
|---|---|---|
| `41dfa6f` | WWHN-184 | 3 fix của lượt R3 (pin extractor + audit view pgsql + báo cáo QA1) |
| `ea3bdc4` | WWHN-184 | khai 2 entry fail-forward vào golive allowlist |
| `0222069` | WWHN-222 (dependency) | vocabulary FAILURE→FAILED/DENIED theo ERD §7 — đã retest riêng tại `docs/evidence/WWHN-222/QA1_RETEST_REPORT_R2.md` |
| `27ea46e` | WWHN-184 | pin R4 (dependency register + resolve candidate động) |
| `259066e` | WWHN-184 (hộ WWHN-222) | khai bổ sung entry allowlist cho `2026_08_19_150000` — bị THIẾU khi WWHN-222 R2 landing, phát hiện vì `wms:golive-check` đỏ ngay trong lượt R4 này (7/18 fail, `dispositioned_count 78 < irreversible_count 79`); sau khi khai, gate 18/18 PASS (WARN-có-disposition) |
| `e16862e` | WWHN-184 | **candidate** — fix mô hình đo race evidence (§1.3) + candidate check bằng nội dung tại SHA khai báo |

Commit ticket khác chạm phần KHÔNG pin của file dùng chung (vd `ce5f28d` WWHN-289 sửa entry
allowlist của họ) không đổi nội dung pinned object nên không phá bất biến — verifier chứng
minh bằng `git show e16862e:<path>` khớp hash pin cho cả 24 mục.

## 1. Kết quả chạy tại candidate `e16862e` — PostgreSQL 16.14

Nội dung mọi file thuộc tập pin tại thời điểm chạy **đồng nhất từng byte** với candidate
`e16862e` (pin verify PASS trước và sau lượt chạy); DB dựng `migrate:fresh --force` từ đầu.

| Batch | Lệnh (env `DB_CONNECTION=pgsql`, DB cô lập) | Kết quả |
|---|---|---|
| Migrate từ scratch | `php artisan migrate:fresh --force` (DB `hn_wwhn184_r4`) | Toàn bộ DONE, 0 lỗi — gồm chuỗi audit `2026_08_19_000001/000002/150000` — raw: `pg-migrate.raw.log` |
| Parity + Audit | `phpunit Wwhn184InboundContractParityTest PostReceiptAuditLogTest` | `{"result":"passed","tests":4,"passed":4,"assertions":273,"duration_ms":95063}` — raw: `pg-parity-audit.raw.log` |
| RBAC/data-scope | `phpunit SecurityRbacAuditNegativeTest PermissionAndValidationTest FoundationInboundPutawayReadinessTest` | `{"result":"passed","tests":30,"passed":30,"assertions":224}` — raw: `pg-rbac.raw.log` |
| GoLive gate | `phpunit Wwhn300BackendGoLiveCheckTest` | `{"result":"passed","tests":18,"passed":18,"assertions":109}` — raw: `pg-golive.raw.log` (lượt đầu 7/18 FAIL → §1.2) |
| Race đa tiến trình | `WWHN184_RUNTIME_CONCURRENCY=1 phpunit Wwhn184PostReceiptRuntimeConcurrencyTest` (DB `hn_wwhn184_r4race`) | `{"result":"passed","tests":1,"passed":1,"assertions":34}` — raw: `pg-race.raw.log` + `race-evidence/` (lượt đầu FAIL → §1.3) |

### 1.2. Defect mới phát hiện trong lượt R4 — allowlist thiếu entry của WWHN-222 R2 — FIXED

Lượt chạy đầu `Wwhn300BackendGoLiveCheckTest` trên PG: **7/18 FAIL**,
`migrations.reversible => dispositioned_count 78 < irreversible_count 79`, mục thiếu:
`2026_08_19_150000_align_audit_result_vocabulary_with_erd` (migration của WWHN-222 R2,
`down()` rỗng có chủ ý nhưng chưa từng khai vào `database/golive/fail_forward_allowlist.php`
— cùng kiểu thiếu như entry `130000` từng phát hiện ở R3). Fix tại commit `259066e`: khai
hộ entry với trạng thái "chờ Tech Lead phê duyệt nguồn gốc tại WWHN-222". Chạy lại: **18/18
PASS, 109 assertions**. Vì allowlist đã nằm trong `critical_dependencies` của pin (mức đối
tượng) từ `27ea46e`, loại thiếu-khai này với 2 entry WWHN-184 từ nay làm pin đỏ ngay.

### 1.3. Defect mới phát hiện trong lượt R4 — assertion overlap của race test bất khả thi ở mode locked — FIXED

Lượt đầu `Wwhn184PostReceiptRuntimeConcurrencyTest` (lần đầu tiên test này chạy thật với
`WWHN184_RUNTIME_CONCURRENCY=1` ngoài CI — ở R3 nó bị **skip** vì thiếu env flag, evidence
race R3 lấy từ chạy script trực tiếp): FAIL
`"Scenario same_document_same_version_two_keys: hai worker không hề chồng lấn trong wall-clock"`.

**Root cause (test-harness defect, không phải product defect):** ở mode `locked`, hai worker
pre-lock CÙNG một hàng `stock_documents`; bên thua chỉ stamp được `request_started_at` SAU
khi bên thắng commit rồi hold thêm 1200ms — cửa sổ request bị serialize bởi CHÍNH cái lock
đang được chứng minh, nên assertion "chồng lấn cửa sổ request" bất khả thi về cấu trúc.
Đối chiếu evidence R3 xác nhận: `timing.workers_in_flight_simultaneously = false`
(overlap −1175ms) đã nằm sẵn trong
`QA1-retest-20260819/runtime-concurrency/post-receipt-concurrency-summary.json` — assertion
này chưa từng chạy nên chưa từng lộ.

**Fix (commit `e16862e`):** worker ghi thêm mốc `lock_wait_started_at`/`lock_acquired_at`;
`overlapEvidence()` đo cửa sổ **engage-DB** `[lock_wait_started_at ?? request_started_at,
request_finished_at]` — phản ánh đúng chồng lấn thật của mode locked: bên thua
blocked-on-lock trong PostgreSQL suốt lúc bên thắng chạy request (đã chứng minh độc lập
bằng pg_locks sample, `real_contention_proven=true`). Kết quả sau fix:

| Scenario | passed | real_contention_proven | overlap_ms (engage-DB) |
|---|---|---|---|
| `same_document_same_version_two_keys` | true | true | 1398.349 |
| `shared_idempotency_key` (mode plain — overlap là chồng lấn request thuần) | true | n/a | 48.05 |
| `post_vs_cancel` | true | true | 1414.714 |

Bất biến nghiệp vụ giữ nguyên và PASS: document POSTED đúng 1 lần, đúng 1 movement,
`on_hand_qty` tăng đúng 1 lần (REQ-SRS-008); kẻ thua nhận `412 STALE_VERSION`;
Idempotency-Key chia sẻ trả replay, không double-post.

## 2. Đối chứng sqlite `:memory:` tại cùng candidate

| Batch | Lệnh | Kết quả |
|---|---|---|
| Parity + Audit | `phpunit tests/Feature/Wwhn184InboundContractParityTest.php tests/Feature/PostReceiptAuditLogTest.php` | `{"result":"passed","tests":4,"passed":4,"assertions":273}` — raw: `QA1-retest-R4-20260819/sqlite-parity-audit.raw.log` |

## 3. Hợp đồng tĩnh Postman (không cần DB)

`node scripts/verify-wwhn184-postman-contract.mjs "docs/postman/WWHN-184 Inbound Contract.postman_collection.json" "docs/postman/WWHN-184 Inbound CI.postman_environment.json" QA1-retest-R4-20260819/postman-contract-verification.json`
→ **PASS (33 request, 14 mutation, 9 refetch)** — bộ probe 409-vs-412 (D03/D04) và 428
thiếu If-Match giữ nguyên, khớp SRS §6 dòng Inbound/Outbound Document (erratum 19/08/2026, §15).

## 4. Phạm vi phủ so với yêu cầu P1-03R4

Register yêu cầu tối thiểu: *state / RBAC / 428 / 412 / 409 / idempotency / audit / race /
reconcile*. Mapping:

| Hạng mục | Bằng chứng trong lượt này |
|---|---|
| state, 428, 412, 409, idempotency | `Wwhn184InboundContractParityTest` (runtime = Postman = route table = OpenAPI; chuỗi D-series gồm cặp guard 412-trước-409) — PASS cả 2 driver |
| RBAC | `SecurityRbacAuditNegativeTest` + `PermissionAndValidationTest` + `FoundationInboundPutawayReadinessTest` trên PG |
| audit | `PostReceiptAuditLogTest` trên PG (đường từng dính defect P0 "audit_log rỗng toàn hệ thống") + sqlite đối chứng |
| race + reconcile | `Wwhn184PostReceiptRuntimeConcurrencyTest` trên PG (`hn_wwhn184_r4race`) — 2 backend PostgreSQL thật, assertion reconcile tồn/sổ cái sau race |
| golive/allowlist (điều kiện dependency) | `Wwhn300BackendGoLiveCheckTest` trên PG — xác nhận 2 entry allowlist của WWHN-184 vẫn giữ gate ở WARN-có-disposition, không FAIL |

## 5. Ghi chú minh bạch

- Lượt chạy này do phiên DEV chuẩn bị hạ tầng và thực thi lệnh; **giá trị của nó với
  WWHN-185 là ở chỗ mọi con số đều tái lập được tại đúng `e16862e`** bằng các lệnh ghi trong
  file này trên DB cô lập. QA1 chính danh xác nhận bằng comment trên Jira (ký hoặc chạy lại
  đối chứng) — file này không tự thay thế chữ ký QA1.
- Hai lượt chạy đầu của golive/race trong chính phiên này FAIL và được sửa tận gốc (§1.2,
  §1.3) TRƯỚC khi chốt candidate — vì vậy candidate `e16862e` mới là SHA hậu-fix thật sự,
  đúng tinh thần P1-05R4 (không lặp lại lỗi "PASS phát sinh sau fix nhưng report ghi SHA cũ").
- Không đụng CI/GitHub Actions (quota 3000/3000 đã cạn); mọi verify chạy local. Cột
  `gate.status_at_pin` trong RC_PIN giữ `NOT_YET_RUN` — đánh giá tại project-final gate
  theo DEV184-CI-DEFERRED.
