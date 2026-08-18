# WWHN-300 — Backend GoLive check

| Trường | Giá trị |
|---|---|
| Jira | WWHN-300 `[BE] Kiểm tra backend/migration/core` |
| Task cha | WWHN-80 `[GOLIVE] Cutover, migration cuối, deployment và kiểm soát rollback` |
| Dependency | HN-DEVOPS-005-ST01 (WWHN-298 — DevOps execute cutover/deploy/rollback) |
| Role | BE1 |
| Repo / branch | `WMS_Warehouse_Hoa_Nam_BE` — `m1` |
| Driver kiểm chứng | PostgreSQL 16 (driver production) + SQLite (driver CI mặc định) |
| Output | `Backend GoLive check` = lệnh `php artisan wms:golive-check` + evidence round-trip migration |

## 1. Phạm vi — vì sao WWHN-300 khác WWHN-155

WWHN-155 đã chứng minh `migrate:fresh --seed` + `/api/v1/health` trên database sạch.
WWHN-300 trả lời câu hỏi khác mà runbook cutover đặt ra:

> Backend đang deploy, chạy trên database thật, có cutover được không — và **nếu phải hủy cutover thì schema có đường về không**?

Đó chính là AC của task cha: *"Triển khai/migrate/smoke trong window; nếu trigger xảy ra rollback theo authority"*.

## 2. Kết quả

Evidence pack: `build/evidence/wwhn-300/postgresql/` (có `SHA256SUMS`).
Pin tại `commit_sha = 6db0965940a6ae8b22a591d50705f258326b172d`,
`source_tree = 73dedef5ee529ca4d7d2e6586781366588e48ffa`, PHP 8.3.30 / Laravel 13.23.0,
PostgreSQL 16. **Lưu ý:** SHA này là commit sweep chung trên `m1`; nếu cần exact-SHA cho
release register thì chạy lại collector sau khi chốt FINAL_SHA.

| Hạng mục | Kết quả | Bằng chứng |
|---|---|---|
| Migration chain forward (140 migration) | PASS | `migrate-fresh-seed.raw.log` |
| **Rollback toàn chuỗi** | PASS — 140/140, chỉ còn bảng `migrations` | `migrate-rollback.raw.log`, `rollback-leftovers.raw.log` |
| Migrate lại sau rollback | PASS — 140 applied | `migrate-forward-after-rollback.raw.log` |
| Seed lại sau rollback | PASS | `seed-after-rollback.raw.log` |
| `wms:golive-check` (13 dimension) | PASS (1 WARN có disposition) | `golive-check.json`, `golive-check-after-roundtrip.json` |
| Regression test WWHN-300 | PASS 28/28 (13 case negative/fault-injection) | `focused-phpunit.raw.log` |
| Full suite SQLite (driver CI) | 645 pass / 22 skip / **0 fail** (667) | — |
| Full suite PostgreSQL | 638 pass / **6 fail** (667) | 6 fail là nhóm OPEN WWHN-31 có sẵn trước ticket này, xem §2.1 |

Trước WWHN-300, bước rollback **FAIL ở migration thứ 94/139** và để lại schema hỏng
(xem `P0_P1_P2_ACTION_REGISTER.md`).

### 2.1. 6 fail còn lại trên PostgreSQL — không thuộc WWHN-300

| Test | Nội dung | Trạng thái |
|---|---|---|
| `ErdPhysicalSchemaParityTest` ×4 | `defect_catalog` là view thay vì base table; PK `user_roles`; index/nullability kèm theo | OPEN WWHN-31, chờ Tech Lead sign-off |
| `DocumentationBaselineContractTest` ×1 | thiếu FK `reversal_lines.original_movement_id -> stock_movements.id` | OPEN WWHN-31 |
| `Flow02InboundPutawayAlignmentTest` ×1 | trigger mirror `hn_erd_mirror_inbound_lines` gây duplicate key | OPEN, thuộc luồng 02 |

Cả 6 đều đã tồn tại **trước** WWHN-300 (ghi nhận độc lập tại WWHN-299) và **không** xuất hiện trên
driver SQLite. Thay đổi của WWHN-300 chỉ nằm trong `down()` nên không tác động tới schema forward.

## 3. Nội dung `wms:golive-check`

| Check | Ý nghĩa với cutover | Ngưỡng |
|---|---|---|
| `migrations.applied` | So **hai chiều** nguồn ↔ database: không còn migration pending, và không có bản ghi nào trong bảng `migrations` mất file nguồn | pending = 0 **và** orphaned = 0 |
| `migrations.reversible` | Tập fail-forward (`down()` rỗng) phải khớp **allowlist có thẩm quyền** `database/golive/fail_forward_allowlist.php` | undispositioned = 0; fail-forward mới ⇒ FAIL |
| `schema.canonical_relations` | 20 aggregate lõi tồn tại (chấp nhận bảng vật lý **hoặc** compatibility view — hai driver đảo chiều tên) | missing = 0 |
| `seed.identity_markers` | `admin`, `KHO-TONG-HN`, `HN-PV-BASE-001`, permission catalog. **Chỉ khẳng định sự tồn tại** — trạng thái credential do `security.account_hygiene` trả lời | đủ 4 |
| `security.account_hygiene` | Không tài khoản ACTIVE nào còn mật khẩu mặc định đã biết; không còn tài khoản/role fixture của môi trường test | ngoài dev: mọi list rỗng, nếu không ⇒ FAIL |
| `security.runtime_config` | `APP_DEBUG` ngoài dev (cờ `--allow-debug` chỉ hiệu lực trên allowlist non-prod), `APP_KEY`, `JWT_SECRET`, trusted proxy, hồ sơ log | findings = 0 |
| `security.browser_runtime_contract` | CORS allow-list, https, SameSite/Secure của refresh cookie theo môi trường triển khai | findings = 0 |
| `security.route_default_deny` | Bề mặt `/api/v1` khớp **đúng ma trận đã pin** `database/golive/route_permission_contract.php` — thêm/mất route hoặc đổi permission đều FAIL | 5 list đều rỗng |
| `release.build_identity` | `APP_BUILD_SHA` buộc bằng chứng vào đúng commit đã deploy | ngoài dev: bắt buộc có |
| `runtime.queue_retry_window` | `retry_after > worker_timeout` để job audit/reconcile không chạy hai lần | retry_after > timeout |
| `core.idempotency_store` | **Đúng tập cột** unique: `business_key` và `idem_key+operation+user_id`; đếm lease treo | missing_unique_sets = 0 |
| `core.audit_backlog` | `audit_outbox` chưa xử lý. Thiếu bảng = **FAIL** (không kết luận được), trừ khi có disposition trong `golive.governed_na` | ngoài dev: unprocessed = 0 |
| `core.reconciliation_backlog` | SRS §7.5: incident OPEN chặn readiness/sign-off. Thiếu bảng/thiếu cột `status` = **FAIL** | open = 0 |

Lệnh trả **exit code 1** khi có bất kỳ FAIL nào — dùng trực tiếp làm gate trong runbook/pipeline,
không phải một trang log để người đọc tự diễn giải.

**Fail-closed (DEV300-P1-03):** một probe không kết luận được — thiếu quan hệ, driver không đọc
được index, thiếu hợp đồng đã pin — là **FAIL**. "Không biết" tại cutover không được phép trông
giống "sẵn sàng". Miễn trừ duy nhất là disposition có thẩm quyền khai trong
`config/golive.php → governed_na`, và khi đó nguyên văn disposition đi vào evidence.

## 4. Cách chạy lại

```bash
php artisan wms:golive-check --json=build/evidence/wwhn-300/golive-check.json
```

Toàn bộ evidence round-trip (fresh → rollback → forward → seed):

```bash
EVIDENCE_DIR=build/evidence/wwhn-300/postgresql bash scripts/collect-wwhn300-golive-evidence.sh
```

CI exact-SHA: `.github/workflows/wwhn-300-backend-golive-check.yml`.

## 5. Bàn giao

- **FE1** — không có thay đổi API contract, payload, error code hay permission. Route surface được
  gác lại bằng `security.route_default_deny`, nên FE không cần đổi gì cho WWHN-300.
- **QA** — `wms:golive-check` là lệnh QA/DevOps chạy được trên DEV/UAT trước và sau deploy; JSON
  output dùng làm evidence đính kèm Go/No-Go.
- **DevOps (WWHN-298)** — `CUTOVER_ROLLBACK_RUNBOOK.md` ghi rõ ranh giới: schema unwind bằng
  `migrate:rollback`, **dữ liệu** chỉ khôi phục bằng restore backup.
