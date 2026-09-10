# WWHN-300 — Đóng register DEV300-P1/P2 (vòng review 58/100)

Nhánh: `m1` (không tạo nhánh riêng). Driver kiểm chứng: SQLite (bộ test) + PostgreSQL 16 (collector/CI).

Vòng review trước chấm **58/100** với 6 mục P1 và 1 mục P2. Tài liệu này ghi **từng mục
→ đã sửa chính xác ở đâu → chứng minh bằng gì**. Nguyên tắc xuyên suốt bản sửa này là
**fail-closed**: một probe không kết luận được (thiếu bảng, driver không đọc được index,
hợp đồng đã pin không tồn tại) là **FAIL**, không phải PASS.

| Mục | Trạng thái | Ai đóng |
|---|---|---|
| DEV300-P1-01 account hygiene + production-safe seeding | **CLOSED (code + test)** | BE1 → Security/DevOps + Tech |
| DEV300-P1-02 `--allow-debug` không override được production | **CLOSED (code + test)** | BE1 → Tech/QA1 |
| DEV300-P1-03 fail-closed probe + ma trận permission | **CLOSED (code + test)** | BE1 → Tech Lead + QA1 |
| DEV300-P1-04 so migration hai chiều + allowlist tường minh | **CLOSED (code + test)** | BE1 → Tech/QA1 |
| DEV300-P1-05 exact-SHA / PR / branch protection | **PARTIAL — phần code đã xong, phần quản trị repo thuộc DevOps** | BE1 + DevOps |
| DEV300-P1-06 cutover → data → reconcile → smoke → Go/No-Go | **BLOCKED — WWHN-298/299/301/302 chưa mở** | DO + BE2 + BA + QA1 |
| DEV300-P2-01 evidence index trên Jira | **CLOSED (tài liệu này + comment)** | BE1 |

---

## DEV300-P1-01 — Vệ sinh tài khoản + seeding an toàn cho production

**Vị trí lỗi:** `BackendGoLiveCheck::seedMarkers()` + `AuthDatabaseSeeder`.

**Hiện trạng trước fix.** `seed.identity_markers` coi *"tồn tại một `admin` ACTIVE"* là dấu
hiệu SẴN SÀNG. Trong khi đó `AuthDatabaseSeeder` tạo `admin` (role `SUPER_ADMIN`) bằng hằng
số mật khẩu mặc định (nay nằm trong `golive.credential.compromised`) ngay trong source code, kèm 9 tài khoản mẫu dùng chung mật khẩu đó —
trong đó có `test-super-admin`. Nghĩa là trạng thái *tệ nhất có thể* — tài khoản quyền cao
nhất với credential ai đọc repo cũng biết — lại **thỏa mãn** gate readiness.

**Đã sửa.**

1. **Dimension mới `security.account_hygiene`** (`BackendGoLiveCheck::accountHygiene()`):
   - Dò từng tài khoản ACTIVE bằng `Hash::check` với tập mật khẩu mặc định đã biết
     (`config/golive.php` → `credential.known_defaults`). Dò theo **hash** nên bắt được cả khi
     tài khoản đã bị đổi tên.
   - Chặn tài khoản chỉ dành cho test/demo còn ACTIVE (`non_production_usernames`, bao gồm cả
     `staff01`/`manager01`… vốn không mang tiền tố `test-`).
   - Chặn role fixture còn tồn tại (`SKU_MANAGER`, `MASTER_MANAGER`, `NO_PERMISSION`).
   - Vượt `credential.probe_limit` → FAIL vì coverage không đầy đủ, không kết luận được.
   - Evidence **chỉ ghi username vi phạm**, không bao giờ ghi giá trị mật khẩu. Chính tập rỗng
     ở `accounts_on_default_credential` **là** bằng chứng credential đã xoay vòng
     (`rotation_evidence`).
   - Ngoài `golive.development_environments` là **FAIL**; trên local/testing là WARN vì các tài
     khoản đó là fixture có chủ ý của bộ test RBAC.
2. **Seeder production-safe** (`AuthDatabaseSeeder`):
   - Ngoài development, seeder **ném `RuntimeException`** nếu thiếu `WMS_SEED_ADMIN_PASSWORD`;
     cũng từ chối giá trị trùng `known_defaults` hoặc ngắn hơn `min_admin_password_length`.
     Kiểm tra chạy **trước mọi thao tác ghi**, nên không để lại database seed dở dang.
   - Ngoài development, **không tạo bất kỳ tài khoản/role fixture nào**. Lối thoát tường minh
     `WMS_SEED_DEMO_ACCOUNTS=true` dành cho UAT — và khi bật, `security.account_hygiene` vẫn
     báo đỏ chúng, nên nó không phải cách nới lỏng production một cách im lặng.
   - `WmsPermissionCompatibilitySeeder` không còn `firstOrFail()` trên role fixture
     `SKU_MANAGER` (vắng fixture không phải lỗi seeding).

**Neo đặc tả:** BR-IAM-01..08 (SRS v1.12 §5.6) — account unique, least privilege, backend
enforcement, no self-escalation.

**Bằng chứng.** `tests/Feature/Wwhn300ProductionSafeSeedingTest.php` (6 case) +
`Wwhn300BackendGoLiveCheckTest`:
`test_default_credential_on_an_active_account_fails_the_gate_outside_development`,
`test_non_production_accounts_and_roles_fail_the_gate_outside_development`,
`test_account_hygiene_passes_once_fixtures_are_removed_and_credentials_rotated`.

---

## DEV300-P1-02 — `--allow-debug` không được override trên production

**Vị trí lỗi:** `BackendGoLiveCheck::runtimeConfig()`.

**Hiện trạng trước fix.** Điều kiện là `if (debug && !isDev && !option('allow-debug'))`. Cờ
`--allow-debug` vì thế tắt được phát hiện ở **bất kỳ** môi trường nào: operator chỉ cần thêm
cờ là gate xanh trong khi stack trace và toàn bộ config vẫn phơi ra response.

**Đã sửa.** Cờ chỉ có hiệu lực khi `APP_ENV` nằm trong allowlist non-prod tường minh
`config('golive.debug_override_environments')` (`local`, `testing`, `development`, `dev`).
Ngoài allowlist, `APP_DEBUG=true` là **FAIL không thể override**, và evidence ghi
`debug_override: rejected` kèm câu giải thích vì sao cờ bị từ chối.

Kèm theo, `isDevelopment()` được tập trung một chỗ và **fail-closed**: mọi `APP_ENV` không nằm
trong `golive.development_environments` — kể cả một tên gõ sai — được đối xử như production.

**Bằng chứng.** `test_allow_debug_override_is_rejected_on_a_production_environment` (case
dương tính đối chứng: `..._still_applies_on_an_allowlisted_environment`), và bước chạy thật
trong collector: hồ sơ `APP_ENV=production APP_DEBUG=true --allow-debug` **bắt buộc exit 1**,
collector hỏng nếu nó exit 0.

---

## DEV300-P1-03 — Probe critical phải fail-closed và permission phải khớp ma trận

**Vị trí lỗi:** `idempotencyStore()`, `auditBacklog()`, `reconciliationBacklog()`, route guard.

**Hiện trạng trước fix.**

| Probe | Đường trả PASS sai |
|---|---|
| `core.idempotency_store` | Chỉ hỏi *"có unique index nào không"*; trên SQLite biến kiểm tra mặc định `true` nên **không kiểm tra gì**. Một unique index trên cột bất kỳ cũng làm nó xanh. |
| `core.audit_backlog` | Thiếu bảng `audit_outbox` → trả `ok` với ghi chú "not present". |
| `core.reconciliation_backlog` | Thiếu bảng → trả `ok`. |
| `security.route_default_deny` | Chỉ kiểm tra *cấu trúc* (có `auth:api`, có `permission:` nào đó). Route gắn **sai** permission — ví dụ `post-issue` gắn `outbound.view` — vẫn hợp lệ về hình thức và mọi test happy-path vẫn xanh. |

**Đã sửa.**

1. `idempotencyStore()` so **đúng tập cột** của unique index thật với hai bất biến
   `['business_key']` và `['idem_key','operation','user_id']`, đọc từ `pg_index` (PostgreSQL)
   hoặc `PRAGMA index_list/index_info` (SQLite). Driver không đọc được → `null` → **FAIL**
   ("không kết luận được"), không phải mặc định `true`. Thêm đếm lease treo
   (`status=processing` + `locked_until < now()`) vì một lease treo chặn đúng lần Post mà
   smoke test cutover thực hiện.
2. `auditBacklog()` / `reconciliationBacklog()` dùng `missingCriticalInfrastructure()`:
   thiếu quan hệ = **FAIL**, trừ khi có disposition tường minh trong
   `config('golive.governed_na')` — khi đó hạ WARN và **nguyên văn disposition đi vào evidence**.
   `reconciliationBacklog()` cũng FAIL nếu quan hệ tồn tại nhưng thiếu cột `status`.
   Backlog audit chưa xử lý > 0 ngoài development là FAIL (audit chưa bền vững cho các giao
   dịch vừa chạy), trên development là WARN.
3. `routeDefaultDeny()` so bề mặt runtime với **ma trận đã pin**
   `database/golive/route_permission_contract.php` — 231 route. Thêm route, mất route, hoặc
   đổi permission đều là **FAIL** cho tới khi ma trận được cập nhật kèm review. Sinh lại bằng
   `php artisan wms:golive-check --write-route-contract`; **diff của file đó chính là bằng
   chứng review** cho một thay đổi bề mặt bảo mật.

**Đối chiếu SRS §8.1 (đã kiểm từng nhóm).** `post-receipt → inbound.post`;
`post-issue → outbound.post`; `reversals → inventory.reversal.post`;
`items/{id}/rfid → physical_code.assign_rfid`; `scan-batches/classify → scan.execute|scan.classify`;
`packing-labels/* → packing_label.preview|ready|print|reprint`; `reports/export → report.export`;
IAM → `iam.user.*`/`iam.role.*`; PV public GET → `PUBLIC_ANONYMOUS`. Dạng `A|B` là alias
canonical/legacy đã chốt ở Annex C.

**Bằng chứng.** `test_removing_the_idempotency_unique_index_fails_the_gate`,
`test_missing_audit_outbox_fails_closed_instead_of_passing`,
`test_missing_audit_outbox_is_downgraded_only_by_an_explicit_disposition`,
`test_missing_reconciliation_relation_fails_closed`,
`test_a_wrong_permission_on_a_write_route_fails_the_pinned_contract`,
`test_a_missing_route_contract_fails_closed`.

> **Ghi nhận thực địa:** trong lúc đóng ticket này, gate đã **bắt sống** một thay đổi route do
> phiên làm việc song song vừa đẩy lên `m1` — 5 route PV mới và một permission bị đổi
> (`pv.publish|sku.manage` → `pv.publish`). Đúng loại thay đổi mà kiểm tra theo cấu trúc không
> nhìn thấy. Sau khi đối chiếu Annex C (`/pv/products`, `/pv/products/{sku_id}` là public
> `security: []`), hai route đó được đưa vào danh sách public theo đặc tả và ma trận được pin lại.

---

## DEV300-P1-04 — So migration hai chiều + allowlist fail-forward tường minh

**Vị trí lỗi:** `migrationsApplied()`, `migrationsReversible()`, `Wwhn300BackendGoLiveCheckTest`.

**Hiện trạng trước fix.** `migrationsApplied()` chỉ so **một chiều** (file nào chưa chạy).
`migrationsReversible()` chỉ WARN, và test chỉ pin **ngưỡng** `<= 75` — nên một migration
fail-forward **mới** vẫn lọt qua miễn là một migration khác rời khỏi tập.

**Đã sửa.**

1. `migrationsApplied()` so **hai chiều**: thêm `orphaned_in_database` — bản ghi trong bảng
   `migrations` không còn file nguồn. Chiều đó nghĩa là artifact đang deploy **không phải**
   artifact đã tạo ra schema này, và `migrate:rollback` sẽ không tìm thấy file để gọi `down()`.
   Cả hai chiều đều là FAIL.
2. `migrationsReversible()` so với **allowlist tường minh có thẩm quyền**
   `database/golive/fail_forward_allowlist.php` (75 mục, mỗi mục ghi lý do; thẩm quyền Tech
   Lead — WWHN-300, disposition tại `CUTOVER_ROLLBACK_RUNBOOK.md`):
   - fail-forward **không** có trong allowlist → **FAIL** (viết `down()` thật hoặc xin
     disposition; không im lặng đi qua);
   - mục trong allowlist nay đã có `down()` thật → **WARN** (stale, gỡ ra để nó không che một
     mục mới);
   - thiếu file allowlist → **FAIL**.
3. Test đổi từ ngưỡng sang **so khớp chính xác từng tên**
   (`test_fail_forward_migrations_match_the_dispositioned_allowlist_exactly`).
4. Collector ghi **manifest migration trước rollback và sau roundtrip**, rồi `diff` hai bản:
   lệch một bản ghi là hỏng collector. Cộng với kiểm tra leftover đã có từ trước (sau rollback
   chỉ được còn bảng `migrations`), đây là bằng chứng roundtrip PostgreSQL thật.

**Bằng chứng.** `test_migration_ledger_matches_source_tree_in_both_directions`,
`test_fail_forward_migrations_match_the_dispositioned_allowlist_exactly`,
`test_a_missing_fail_forward_allowlist_fails_closed`; collector
`before-rollback.manifest.txt` / `after-roundtrip.manifest.txt` / `migration-manifest-diff.raw.log`.

---

## DEV300-P1-05 — Exact-SHA, PR/review, branch protection

**Phần BE1 đã làm:**

- Toàn bộ thay đổi nằm trên `m1` trong **một commit có tên** (xem
  `EVIDENCE_INDEX` bên dưới), không lẫn vào commit khác.
- `docs/evidence/WWHN-300/**` được `git add -f` (thư mục `docs/` bị `.gitignore`) nên đường dẫn
  evidence **truy cập được** trên GitHub thay vì 404.
- Workflow `wwhn-300-backend-golive-check.yml` mở rộng trigger sang `config/golive.php`,
  `database/golive/**`, hai seeder và bộ test mới; checkout theo `github.sha`, upload artifact
  kèm `SHA256SUMS`.

**Còn lại — không thuộc thẩm quyền BE1, cần DevOps/Tech thực hiện:**

- **PR/review URL:** yêu cầu vận hành hiện tại là *chỉ làm trên `m1`*, nên không có nhánh
  nguồn để mở PR. Nếu release governance bắt buộc PR, DevOps cần mở lại nhánh làm việc — đó là
  quyết định quy trình, không phải việc code.
- **Branch protection + required check** trên `m1`: cần quyền admin repo.
- Chạy workflow trên **đúng SHA cuối** và lưu artifact + digest.

---

## DEV300-P1-06 — Cutover → data → reconcile → smoke → Go/No-Go

**Vẫn BLOCKED**, không thể đóng từ phía backend: WWHN-298 (environment/deploy/backup-restore),
WWHN-299 (lần nạp dữ liệu cuối), WWHN-301/302 (BA freeze, QA smoke) chưa mở. `wms:golive-check`
là gate dùng cho bước đó và đã sẵn sàng chạy trên UAT/production ngay khi có môi trường —
nhưng bản thân nó **không thay thế** được smoke test hay Go/No-Go.

---

## DEV300-P2-01 — Evidence index

Chính là file này, cộng comment xác nhận trong `SIGNOFF_COMMENTS.md` §Vòng 2.

## Lệnh tái lập

```bash
php artisan wms:golive-check --json=build/evidence/wwhn-300/golive-check.json
./vendor/bin/phpunit --filter 'Wwhn300BackendGoLiveCheckTest|Wwhn300ProductionSafeSeedingTest|HoaNamErdSchemaDropRelationTest'
bash scripts/collect-wwhn300-golive-evidence.sh   # cần PostgreSQL
```
