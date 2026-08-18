# WWHN-273 — [BE] Kiểm tra migration/smoke backend

## Kiểm soát

- Task cha: `WWHN-72` — `[BETA] Deploy, backup database và diễn tập rollback`.
- Parent Temp Key: `HN-DEVOPS-003`; Epic Temp Key: `HN-EPIC-10`; Dependency: `HN-DEVOPS-003-ST01`; Role: `BE1`.
- Output đầu ra: **Backend release sign-off**.
- Repo/branch: `WMS_Warehouse_Hoa_Nam_BE` / `m1` (không tạo nhánh riêng).
- Ngày: 2026-08-18; cập nhật trạng thái gate 2026-08-19.
- Status: `GATE_STATUS_AT_PIN=RED` — **gate chưa bao giờ xanh**, xem §1.1. Trước 2026-08-19 task này
  được ghi là `IMPLEMENTED / EVIDENCE READY`; đó là mô tả sai và đã được sửa. BE không tự ghi `Done`
  cho WWHN-72, và **không** ký `Backend release sign-off` khi gate còn đỏ.
- RC pin: `docs/evidence/WWHN-273/RC_PIN.json` — verifier `node scripts/verify-wwhn-273-release-signoff.mjs`.
- Provenance (PR = N/A): `docs/evidence/WWHN-273/CANDIDATE_PROVENANCE.md`.
- Disposition câu chữ "kiểm kê" của AC cha: `docs/decision-records/DISP-REL-273-STOCKTAKE-RELEASE-CRITERIA.md`.
- Bàn giao QA1: `docs/evidence/WWHN-273/QA1_RELEASE_SMOKE_HANDOFF.md`.

## 1.1. Trạng thái thật của gate (2026-08-19)

`gh run list --workflow=wwhn-273-backend-release-signoff.yml` trả về **7/7 run `failure`**, run mới
nhất là `32163575255` tại `c29a220`. Cả 7 run đều dừng ở **step 8 `Migration and seed exact-SHA
gate`**, nên mọi step sau bị `skipped` và step `Upload …evidence` cũng đỏ vì không có file nào để
upload (`if-no-files-found: error`).

Hệ quả phải nói thẳng: **artifact `release-signoff.txt` / `SHA256SUMS` / `golive-check.json` /
`health.json` / `served-smoke.txt` chưa từng tồn tại trên bất kỳ run nào.** Bảng ở §2 là bằng chứng
chạy tay trên máy dev, và nó đã bị dùng như thể thay được artifact của CI — đó chính là gốc của
`DEV273-P1-01`.

| Defect | Nội dung |
|---|---|
| `W273-P0-03` | `php artisan migrate:fresh --seed` vỡ trên PostgreSQL sạch: `SQLSTATE[42804] column "created_by" is of type uuid but expression is of type text`, `CONTEXT: hn_erd_mirror_pv_industries()`, `STATEMENT: update "pv_industry" set "version" = $1 where "version" is null`. Đã tái hiện tại chỗ trên PostgreSQL 16 DB sạch ngày 2026-08-19, cùng dấu vết với run `32163575255`. **Gốc**: generator write-bridge trong `2026_08_10_000765` sinh `INSERT INTO <canonical> (cols) SELECT cols FROM <canonical>__legacy_projection` ghép cột **chỉ theo tên, không đối chiếu kiểu**; projection PV khai `NULL::text AS created_by/updated_by` còn bảng canonical khai `uuid`; trigger là `FOR EACH STATEMENT` nên nổ ngay cả khi `UPDATE` khớp 0 dòng. **Owner: `WWHN-289` (`DEV289-P1-02R`)** — không phải WWHN-273, và WWHN-273 không sửa chồng lên bản fix đang bay của họ. |
| `W273-P0-04` | Một run đỏ không để lại evidence nào, nên trạng thái đỏ của gate không quan sát được từ artifact. Đã sửa: bước `Record gate status (always)` luôn ghi `gate-status.txt` (`job_status`, `run_id`, `run_url`, danh sách artifact `PRESENT/MISSING`, `result`), nên artifact tồn tại kể cả khi gate đỏ và chỗ dừng lộ ra ngay. |

## 1. Gate của task này

| Hạng mục | Nơi kiểm | Kết quả kỳ vọng |
|---|---|---|
| Migration + seed trên DB sạch | `.github/workflows/wwhn-273-backend-release-signoff.yml` → `migrate:fresh --seed` trên `postgres:16` | PASS trên đúng SHA |
| Diễn tập rollback (BE half của WWHN-72) | cùng workflow → `migrate:reset` → `migrate` → `db:seed` | PASS, chain gỡ và dựng lại được |
| Readiness trước cutover | `php artisan wms:golive-check --json=...` (WWHN-300) | `overall=pass`, evidence JSON |
| Smoke runtime đã serve | `php artisan serve` + curl | `/api/v1/health` 200 `status=ok`; `/api/health` 404; `/api/v1/skus` không token = 401 |
| Smoke nghiệp vụ theo AC của WWHN-72 | cùng bước smoke, sau `POST /api/v1/auth/login` | `auth` 200 + access token; `skus`, `inbound-documents`, `outbound-documents`, `inventory/balances`, `reports/hub` = 200 |
| Absence kiểm kê theo `AC-SRS-037` | cùng bước smoke, probe **kèm token hợp lệ** | `stocktake-sessions`, `inventory-snapshots`, `stock-adjustments`, `reports/stocktake-variance`, `POST …/apply-adjustment` = **404** → `stocktake-absence.txt` |
| Auth/session/RBAC/OpenAPI | `BackendMigrationSeedHealthTest`, `AuthSessionPersistenceTest`, `AuthorizationTest`, `SwaggerRouteSyncTest` | PASS |
| Trạng thái run luôn quan sát được | bước `Record gate status (always)` | `gate-status.txt` có `job_status`, `run_id`, `run_url`, `result`, và `PRESENT/MISSING` cho từng artifact bắt buộc |
| Pin / provenance / trung thực trạng thái | `node scripts/verify-wwhn-273-release-signoff.mjs` | PASS — pin không rot, gate ↔ pin ↔ task doc không lệch |
| Hợp đồng của chính gate | `tests/Feature/Wwhn273ReleaseSignoffContractTest.php` | PASS — gate không bị gỡ bước trong im lặng |

## 2. Bằng chứng chạy tại chỗ — `NON-CANDIDATE / DEV-ONLY` (2026-08-18)

> **NON-CANDIDATE / DEV-ONLY DIAGNOSTIC.** Toàn bộ bảng dưới đây chạy trên máy dev, **không** phải
> bằng chứng phát hành và **không** thay artifact của run CI. Nó chỉ có một tác dụng: chẩn đoán. Ba
> lý do nó không đủ tư cách làm evidence của release:
>
> 1. Nó chạy ở SHA `624af01`, lệch với candidate đang xét (khi đó `m1` đã đi thêm 14 commit, trong đó
>    có thay đổi migration/runtime thật).
> 2. Nó chạy trên snapshot `git archive` với `vendor/` junction về repo chính, nên class PHP đến từ
>    working tree chứ không từ snapshot — hai nguồn khác nhau trong cùng một lần chạy.
> 3. `wms:golive-check` tại chỗ báo `security.runtime_config = FAIL` vì `.env` local không khai
>    `TRUSTED_PROXIES`; trên runner thì biến này được pin trong `env:` của workflow.
>
> Bằng chứng phát hành **chỉ** là artifact `wwhn-273-backend-release-signoff-<sha>` của một run
> `success` — xem `docs/evidence/WWHN-273/RC_PIN.json`. Tại thời điểm cập nhật này chưa có run nào
> như vậy (§1.1).

| Lần chạy | Môi trường | Kết quả |
|---|---|---|
| Focused release suite | sqlite `:memory:` (`php artisan test`) | 20 test / 243 assertion PASS |
| `migrate:fresh --seed` | PostgreSQL 16.14, DB sạch `hoanam_wwhn273_head` | PASS (140 migration) |
| `migrate:reset` (gỡ toàn bộ chain) | cùng DB | PASS |
| `migrate` + `db:seed` sau rollback | cùng DB | PASS |
| `wms:golive-check` | cùng DB | `migrations.applied`, `schema.canonical_relations`, `seed.identity_markers`, `security.route_default_deny`, `core.*` = OK |
| Smoke HTTP trên runtime đã serve | `php artisan serve` trên cùng DB | `/api/v1/health` = 200 `"status":"ok"`; `/api/health` = 404; `/api/v1/skus` không token = 401 |
| Smoke nghiệp vụ có token | login `admin@gmail.com` | `skus`, `inbound-documents`, `outbound-documents`, `inventory/balances`, `reports/hub` = 200 |

Lần chạy PostgreSQL thực hiện trên snapshot `git archive HEAD` (SHA `624af01`) với `vendor/` junction về repo chính: file migration và `docs/HoaNam_ERD_Moi_Nhat.md` lấy từ snapshot, class PHP autoload qua `vendor/` nên đến từ working tree. Ghi rõ để reviewer không hiểu nhầm đây là bằng chứng của một job CI.

`wms:golive-check` chạy tại chỗ báo `security.runtime_config = FAIL` **do `.env` local không khai `TRUSTED_PROXIES`** — là cấu hình môi trường, không phải lỗi code. Vì vậy workflow của task này pin sẵn `TRUSTED_PROXIES` và `APP_BUILD_SHA=${{ github.sha }}` trong `env:` để check chạy tất định trên runner và evidence gắn đúng commit.

## 3. Register P0/P1/P2

> Đây là phân loại kỹ thuật suy ra từ AC/DoD của WWHN-273 + trạng thái repo. Tại thời điểm dựng gate này, Jira WWHN-273 **không có comment chấm điểm P0/P1/P2 nào mới hơn**, nên không được trình bày các mức dưới đây như điểm số của reviewer.

| ID | Sev | Phát hiện | Xử lý tận gốc / disposition | Owner |
|---|---|---|---|---|
| W273-P0-01 | P0 | Gate release của BE không có bước diễn tập rollback, trong khi đó chính là mục tiêu của task cha WWHN-72. `migrate:fresh` một chiều không chứng minh được chain gỡ được. | Thêm bước `migrate:reset` → `migrate` → `db:seed` vào gate, chạy trên PostgreSQL 16. | BE1 |
| W273-P0-02 | P0 | `HoaNamErdSchema::parse()` đọc `docs/HoaNam_ERD_Moi_Nhat.md` **lúc chạy migration**; đổi tên entity trong tài liệu làm vỡ `migrate:fresh --seed` trên DB sạch. Tái hiện 2026-08-18 với thay đổi đang có trong working tree (đổi entity 15 `defect_catalog` → `defect_catalogs`): `RuntimeException: ERD key field missing on defect_catalogs: id` tại `2026_08_10_0007695_prepare_all_canonical_erd_keys`, và `db:seed` lỗi `null value in column "id" of relation "defect_catalogs"`. Cùng chain đó PASS khi dùng bản ERD tại HEAD. | Gate WWHN-273 chạy `migrate:fresh --seed` + rollback drill trên DB sạch nên chặn được lớp lỗi này trước Beta. Việc đổi tên entity thuộc WWHN-310 / `DEC-DATA-001` và phải làm cùng lúc với audit 7 migration đọc ERD (đúng khuyến nghị đã ghi trong `2026_08_18_000100_align_defect_catalogs_with_erd_dictionary`). | Owner WWHN-310 (chưa commit) |
| W273-P1-01 | P1 | Gate chỉ kiểm health trong tiến trình phpunit; Beta/UAT lại poll qua HTTP. Một cấu hình route/nginx sai vẫn xanh gate cũ. | Thêm smoke trên runtime đã `serve`: `/api/v1/health` 200 + `status=ok`, `/api/health` phải 404, `/api/v1/skus` không token phải 401. | BE1 |
| W273-P1-02 | P1 | Gate không chạy `wms:golive-check`, nên readiness (migration pending, default-deny, idempotency store, backlog audit/đối soát) không được kiểm trước khi tuyên bố backend sẵn sàng. | Thêm bước golive-check kèm evidence JSON vào artifact. | BE1 |
| W273-P1-03 | P1 | WWHN-273 không có tài liệu evidence trong source (khác WWHN-274 phía FE), reviewer không có chỗ đối chiếu AC ↔ bằng chứng. | File này + manifest `artifacts/wwhn-273/release-signoff.txt`. | BE1 |
| W273-P1-04 | P1 | AC của WWHN-72 yêu cầu "Smoke auth/master/inbound/outbound/inventory/kiểm kê pass", nhưng gate BE không gọi một endpoint nghiệp vụ nào qua HTTP — chỉ có phpunit in-process. | Thêm smoke có token: `auth/login` → `skus` / `inbound-documents` / `outbound-documents` / `inventory/balances` / `reports/hub` phải trả 200 trên DB đã migrate + seed. **Kiểm kê (Stocktake) không được hiện thực**: SRS v1.12 + Techstack baseline loại Stocktake/Variance/Adjustment khỏi MVP, đường sửa sai là Reversal append-only. BE không tự sinh màn hình/API ngoài baseline; câu chữ "kiểm kê" trong AC của task cha cần PM/PO + QA disposition. | BE1 (smoke) + PM/PO/QA (câu chữ kiểm kê) |
| W273-P2-01 | P2 | Hợp đồng của gate nằm trong một file YAML: gỡ một bước không làm test nào đỏ. | `tests/Feature/Wwhn273ReleaseSignoffContractTest.php` khoá các bước bắt buộc + hai khẳng định runtime, và chính nó nằm trong suite mà gate chạy. | BE1 |
| W273-P2-02 | P2 | `wms:golive-check` báo WARN `migrations.reversible`: nhiều migration có `down()` rỗng (fail-forward). | Có chủ đích: quyền rollback quá các bước fail-forward thuộc về restore-from-backup của DevOps (WWHN-272), runbook `docs/evidence/WWHN-300/CUTOVER_ROLLBACK_RUNBOOK.md` đã ghi. Không sửa code, giữ WARN để reviewer thấy. | DO + BE1 |

## 3.1. Đóng register của reviewer (68/100 — vòng rà soát 2026-08-19)

Khác §3, đây là register **do reviewer chấm**, không phải phân loại tự dựng.

| ID | Sev | Trạng thái | Đã làm gì |
|---|---|---|---|
| `DEV273-P1-01` | P1 | **ĐÓNG phần thuộc BE1; còn chờ `W273-P0-03`** | Truy xuất được exact run/artifact: `gh run list` cho thấy **7/7 run failure**, ghi đủ `run_id`/`sha`/`conclusion`/`failed_step` vào `RC_PIN.gate.observed_runs`. Nguyên nhân gate đỏ đã tìm ra và **tái hiện tại chỗ** (`W273-P0-03`, owner `WWHN-289`). Cơ chế làm cho lỗi này không tái diễn: `Record gate status (always)` + verifier R4 chặn việc tuyên bố evidence-ready khi `green_at_sha` còn null. Pin dùng **sha256 nội dung từng file** thay vì pin theo HEAD, nên commit của ticket khác trên `m1` không làm pin rot. |
| `DEV273-P1-02` | P1 | **ĐÓNG** | Bộ comment handoff BE cho Jira đã soạn theo đúng cấu trúc reviewer yêu cầu (exact SHA, run/job, PG version, kết quả migration/rollback, OpenAPI hash, artifact ID/digest, known gaps, truth boundary) — và nói đúng sự thật là gate đang đỏ, không tuyên bố PASS. |
| `DEV273-P1-03` | P1 | **KHÔNG THUỘC BE1 — đã tháo blocker** | Release smoke là việc của QA1 (`WWHN-275`). BE1 không chạy thay. Đã cung cấp `docs/evidence/WWHN-273/QA1_RELEASE_SMOKE_HANDOFF.md`: candidate nào, đối chiếu SHA trước, BE1 đã kiểm gì, checklist nghiệp vụ + checklist absence, yêu cầu ghi evidence. |
| `EXT273-P1-04` | P1 external | **KHÔNG THUỘC BE1** | Deploy/backup/restore/rollback Beta thuộc DevOps (`WWHN-272`). Runbook cutover đã có ở `docs/evidence/WWHN-300/CUTOVER_ROLLBACK_RUNBOOK.md`. Ranh giới được ghi lại ở §4 và trong `RC_PIN.truth_boundary`: rollback drill của gate là **rollback migration chain**, không phải restore-from-backup. |
| `DEV273-P1-05` | P1 source conflict | **ĐÓNG phần thuộc BE1; chờ approver** | `DISP-REL-273` xếp hạng authority và neo vào `REQ-SRS-037` + `AC-SRS-037` ("deployment v1.12 không chứa capability legacy"). Câu "kiểm kê" trong AC3 của `WWHN-72` được thực thi dưới dạng **kiểm chứng absence trên runtime đã serve** (5 probe, kỳ vọng đúng 404, probe kèm token). `DEC-STK-001` giữ nguyên `PROPOSED` / `Effective date = NULL` — repo không tự duyệt. Việc còn lại là của BA + PM/PO + Process Owner: sửa câu chữ AC3 và formal approve/reject `DEC-STK-001`. |
| `DEV273-P1-06` | P1 | **ĐÓNG** | `docs/evidence/WWHN-273/CANDIDATE_PROVENANCE.md`: khai `PR = N/A`, pin reviewed compare `c965db1` → candidate, bảng 5 commit kèm cột "task key có khớp?" và chỉ đúng chỗ nguy hiểm — `68852e1` mang nhãn `docs(WWHN-295)` nhưng thực chất thêm **toàn bộ smoke nghiệp vụ** cho WWHN-273 (tức là đóng `W273-P1-04`). Lọc commit theo task key sẽ bỏ sót phần đó. Kèm danh sách file WWHN-273 sở hữu và danh sách file **không** sở hữu nhưng đang chặn gate. |
| `DEV273-P2-01` | P2 | **ĐÓNG** | §2 được đổi tiêu đề thành `NON-CANDIDATE / DEV-ONLY` và nêu 3 lý do cụ thể vì sao nó không đủ tư cách làm evidence phát hành (lệch SHA 14 commit, snapshot trộn `vendor/` của working tree, `TRUSTED_PROXIES` local). Verifier R5 fail nếu nhãn này bị gỡ. |

## 4. Ranh giới sự thật

- Đây là **bằng chứng do BE kiểm soát**: migration/seed/rollback drill/health/auth/RBAC/OpenAPI trên runner CI với PostgreSQL của job.
- Không phải bằng chứng deploy Beta/UAT thật, không phải backup/restore của DevOps (WWHN-272), không phải release smoke độc lập của QA1 (WWHN-275).
- BE1 chỉ ký `Backend release sign-off` khi job WWHN-273 xanh trên đúng SHA đang xét và không còn P0/P1 thuộc vai trò BE1.
- Không dùng lại screenshot/kết quả PASS của một SHA khác làm bằng chứng đóng gate.

## 5. Checklist reviewer

- [ ] SHA `m1` được ghi rõ và job WWHN-273 xanh trên đúng SHA đó.
- [ ] Artifact có `release-signoff.txt`, `SHA256SUMS`, `golive-check.json`, `health.json`, `served-smoke.txt`.
- [ ] `rollback_drill` trong manifest = PASS.
- [ ] `golive_check` trong manifest = `pass`.
- [ ] `legacy_health=/api/health:REJECTED` và smoke ghi `legacy_health_http=404`, `protected_no_token_http=401`.
- [ ] `served-smoke.txt` có `auth_login=200` và 200 cho `skus`/`inbound-documents`/`outbound-documents`/`inventory/balances`/`reports/hub`.
- [ ] `openapi_sha256` khớp `storage/api-docs/api-docs.json` của cùng SHA.
- [ ] Không có dòng nào trong evidence tự nhận sign-off của QA1 hoặc DevOps.
- [ ] `gate-status.txt` có `result=GREEN_BACKEND_RELEASE_SIGNOFF_AT_EXACT_SHA` và `job_status=success`.
- [ ] `stocktake-absence.txt` ghi `404` cho cả 5 probe (4 GET + `apply-adjustment` POST).
- [ ] `node scripts/verify-wwhn-273-release-signoff.mjs` PASS trên đúng SHA đó.
- [ ] `RC_PIN.gate.green_at_sha` đã được điền và khớp `sha` trong `release-signoff.txt`; marker
      `GATE_STATUS_AT_PIN=RED` đã được gỡ khỏi tài liệu này (verifier R4 fail nếu còn sót).
- [ ] Tech Lead đã comment xác nhận reviewed diff `c965db1` → candidate theo
      `docs/evidence/WWHN-273/CANDIDATE_PROVENANCE.md` §5 (vì `PR = N/A`).
- [ ] `W273-P0-03` đã đóng ở phía `WWHN-289` (không phải WWHN-273 tự sửa).
