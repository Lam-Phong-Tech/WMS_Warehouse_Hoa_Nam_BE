# WWHN-273 — [BE] Kiểm tra migration/smoke backend

## Kiểm soát

- Task cha: `WWHN-72` — `[BETA] Deploy, backup database và diễn tập rollback`.
- Parent Temp Key: `HN-DEVOPS-003`; Epic Temp Key: `HN-EPIC-10`; Dependency: `HN-DEVOPS-003-ST01`; Role: `BE1`.
- Output đầu ra: **Backend release sign-off**.
- Repo/branch: `WMS_Warehouse_Hoa_Nam_BE` / `m1` (không tạo nhánh riêng).
- Ngày: 2026-08-18.
- Status: `IMPLEMENTED / EVIDENCE READY — PENDING INDEPENDENT SIGN-OFF`. BE không tự ghi `Done` cho WWHN-72.

## 1. Gate của task này

| Hạng mục | Nơi kiểm | Kết quả kỳ vọng |
|---|---|---|
| Migration + seed trên DB sạch | `.github/workflows/wwhn-273-backend-release-signoff.yml` → `migrate:fresh --seed` trên `postgres:16` | PASS trên đúng SHA |
| Diễn tập rollback (BE half của WWHN-72) | cùng workflow → `migrate:reset` → `migrate` → `db:seed` | PASS, chain gỡ và dựng lại được |
| Readiness trước cutover | `php artisan wms:golive-check --json=...` (WWHN-300) | `overall=pass`, evidence JSON |
| Smoke runtime đã serve | `php artisan serve` + curl | `/api/v1/health` 200 `status=ok`; `/api/health` 404; `/api/v1/skus` không token = 401 |
| Smoke nghiệp vụ theo AC của WWHN-72 | cùng bước smoke, sau `POST /api/v1/auth/login` | `auth` 200 + access token; `skus`, `inbound-documents`, `outbound-documents`, `inventory/balances`, `reports/hub` = 200 |
| Auth/session/RBAC/OpenAPI | `BackendMigrationSeedHealthTest`, `AuthSessionPersistenceTest`, `AuthorizationTest`, `SwaggerRouteSyncTest` | PASS |
| Hợp đồng của chính gate | `tests/Feature/Wwhn273ReleaseSignoffContractTest.php` | PASS — gate không bị gỡ bước trong im lặng |

## 2. Bằng chứng đã chạy tại chỗ (2026-08-18)

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
