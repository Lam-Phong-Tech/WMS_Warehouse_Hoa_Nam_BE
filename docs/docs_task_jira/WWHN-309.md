# WWHN-309 — [BE] Hoàn thiện tài liệu kiến trúc / API / vận hành

Cập nhật: 2026-08-18
Repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
Nhánh thực hiện: `m1`
Pre-remediation BE SHA: `c1ce7bf1d4a18d2a428a46f10ea2e025cffd646c`
FE baseline đối chiếu: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE@m1` / `3e1be64883d85dfe4ff5569b6300b781fbe99a57`
Parent: WWHN-83 · Dependency: HN-HO-001-ST01 · Role: BE1

## 1. Phạm vi và nguồn chuẩn

WWHN-309 là **Backend handover** của parent WWHN-83: kiến trúc, hợp đồng API, RBAC/permission, vận hành (job nền, health, runbook, CI/CD evidence). Không tự ký thay BA/BAS/QA/DevOps và không tự đóng parent WWHN-83.

Nguồn được đọc/đối chiếu:

1. `docs/HN_SRS_FRD_v1.12_FULL.md` — requirement/flow/AC/TC hiện hành.
2. `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — screen/state/UX baseline (bản FE).
3. `docs/HoaNam_ERD_Moi_Nhat.md` — physical schema; xung đột cũ với SRS v1.12 không được dùng để ghi đè requirement mới hơn.
4. `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` + `docs/HN-BE-001_BACKEND_STANDARD.md` — stack, chuẩn cấu trúc code, quy tắc bảo mật/phân quyền.

Invariants BE không được làm sai:

- MVP không có stocktake/adjustment trực tiếp; correction đi qua **reversal**.
- Không partial posting; inbound/outbound là **full-post** trong một transaction.
- Outbound: tiếp nhận toa → `DRAFT` → scan/match đủ → Packing Label (Preview/Ready/Print + xác nhận đã dán) → **Post Issue**; chỉ Post Issue thành công mới trừ tồn.
- RFID **optional** theo SRS/wireframe v1.12, không phải gate bắt buộc để Post.
- Movement/audit đã post không sửa trực tiếp.
- Backend tự enforce permission **và** data scope; FE ẩn nút không phải cơ chế bảo vệ.
- PostgreSQL là database canonical duy nhất; PASS trên SQLite không phải căn cứ nghiệm thu.

## 2. Kết quả remediation theo AC của WWHN-309

| Sev | Finding | Xử lý BE | Trạng thái | Owner còn lại |
| --- | --- | --- | --- | --- |
| P1 | `evidence:permission-catalog` so khớp cả biểu thức `permission:A\|B` như một permission key ⇒ 31 endpoint OR-alias bị trả **sai danh sách role** và bị tính là route permission "lạ"; seed-diff FAIL giả | Tách theo `\|` đúng semantics `CheckPermission`, thêm trường `accepted_permissions`, schema `1.2.0` | CLOSED (test khoá) | — |
| P1 | Permission catalog đã publish stale: 31 permission / 31 route so với 83 permission / 226 route thật; 6/7 source hash lệch | Regenerate `docs/permissions/*` từ exporter đã sửa; test chặn stale theo SHA-256 | CLOSED | FE/BA review nội dung |
| P1 | `warranty:purge-pii` (SRS §13.8 / DR-PRIV-001) **chưa từng được lên lịch** — PII hết retention không bao giờ bị anonymize trên production | Đăng ký `Schedule::command('warranty:purge-pii')` 03:10 hằng ngày, `withoutOverlapping()` + `onOneServer()`; test khoá danh sách job nền bắt buộc | CLOSED | DevOps xác nhận có process `schedule:work` ở UAT/production |
| P1 | `README.md` vẫn là README mặc định của Laravel: không có build/run/env/ops của dự án | Viết lại README theo runtime thật (Docker Compose, env contract, test, API docs, job nền, runbook, invariants) | CLOSED | DO/QA đối chiếu khi dựng môi trường |
| P1 | Handover BE không có execution identity tái lập được (đúng lớp lỗi FE311-P1-04) | Thêm workflow `WWHN-309 Backend Handover Evidence`: exact SHA, run ID/URL, log thô, catalog/schedule/OpenAPI/health artifact + `SHA256SUMS` | READY ON CLOSURE CI | BE1 dispatch tại closure SHA + DO/QA pin run |
| P1 | `HN-BE-API-003` báo "Operation có optimistic locking If-Match: **0**" trong khi OpenAPI publish 41 operation khai `If-Match` và runtime abort 412 `STALE_VERSION` ở 25 vị trí | Generator dò thêm parameter `If-Match` trong OpenAPI, không chỉ dò middleware; regenerate → 41 | CLOSED | — |
| P2 | `app/Http/Middleware/CurrentBaselineIfMatch.php` không được gắn vào route nào nhưng tên gợi ý đang enforce | Ghi cảnh báo trong docblock, trỏ về ma trận endpoint; enforcement thật nằm ở controller | DOCUMENTED — chờ quyết định gỡ | Tech Lead |
| P2 | Markdown permission catalog còn liệt kê `stocktake.adjust`, `stock_document.*`, `warranty.temp_only` — trái baseline MVP | Viết lại theo nhóm permission có thật trong seed/route | CLOSED | — |
| P1 | OpenAPI có đủ 226 operation nhưng `components.schemas` **rỗng** và 2265/2267 response không có `content`: FE/QA đọc spec không suy ra được hình dạng envelope, phải đọc ngược code | Thêm `App\Swagger\ApiEnvelopeSchemas` (`ApiSuccessEnvelope`/`ApiErrorEnvelope`/`ApiErrorDetail`/`ApiMeta`/`ApiLinks`); `RouteSyncProcessor` gắn `$ref` cho **mọi** response, kể cả response viết tay chưa có content | CLOSED — 2268/2268 response có schema | FE/QA sinh type từ spec |
| P1 | Header contract lệch runtime: 18 call site `IdempotencyKeyGuard::require` nhưng chỉ 4 operation khai `Idempotency-Key`; 24 call site đọc `If-Match` nhưng chỉ 7 operation khai header ⇒ FE tích hợp gặp 428/412 bất ngờ | `RouteSyncProcessor::contractHeaders()` phân tích tĩnh source của action (kèm 1 cấp helper cùng class) và tự khai header đúng required/optional | CLOSED — 27 operation khai `Idempotency-Key` (23 bắt buộc), 41 khai `If-Match` | FE cập nhật client theo spec |
| P1 | Không có cổng tổng cho gói tài liệu: từng generator tự kiểm nhưng không ai chặn trường hợp thiếu tài liệu, README lệch `.env.example`, bất biến MVP bị tái nhập hoặc secret bị chép vào tài liệu | `scripts/verify-wwhn-309-handover-contract.mjs` — fail-closed trên 6 lớp lỗi đó | CLOSED — PASS tại SHA closure | CI gọi ở gate |
| P2 | Chưa có tài liệu kiến trúc as-built và runbook vận hành đứng riêng; nội dung nằm rải trong README/task doc nên BA/QA/DO phải đọc chéo | Bổ sung `HN-BE-ARCH-001` (kiến trúc as-built), `HN-BE-API-001` (contract bàn giao FE/QA), `HN-BE-OPS-001` (runbook vận hành) | CLOSED | BA/QA/DO đọc theo vai trò |
| P2 | `docs/api-contract/WMS_HoaNam_OpenAPI_Changelog_BE1.md` (v1.0.1) còn ghi `POST /stocktake-sessions/{id}/apply-adjustment` trong "Baseline Rule" — route này không còn tồn tại trong route table | Giữ changelog làm bản ghi lịch sử; `HN-BE-API-001` §8 ghi rõ khi mâu thuẫn thì route table + tài liệu hiện hành thắng | DOCUMENTED | — |

## 3. Kiến trúc backend

### 3.1. Thành phần

| Lớp | Nội dung |
| --- | --- |
| Runtime | PHP 8.3, Laravel 13, Nginx, php-fpm |
| Data | PostgreSQL 16 (canonical), Valkey 8 (cache/queue/session), MinIO/S3 (file) |
| Module | `Modules/Auth` (IAM/JWT/permission), `Modules/Warehouse` (nghiệp vụ kho), `Modules/AuditLog` |
| Job nền | `queue:work` + `schedule:work` chạy tách container, `AUTO_MIGRATE=false` |

Luồng xử lý chuẩn: `Route → Controller → Form Request → Service/UseCase → Model/Repository → PostgreSQL`. Controller không chứa business logic.

Ghi tồn kho bắt buộc: kiểm tra auth → permission → data scope → trạng thái phiếu → mở transaction → lock bản ghi tồn → kiểm tra lại số lượng → cập nhật tồn → ghi `stock_movement` → ghi audit → chuyển trạng thái → commit. Lỗi ở bất kỳ bước nào thì rollback toàn bộ.

### 3.2. Middleware toàn cục (`bootstrap/app.php`)

| Middleware | Vai trò |
| --- | --- |
| `NoStoreApiResponses` | Ép `no-store` cho response API (ngoài cùng) |
| `ApiConventionMiddleware` | Chuẩn hoá request/response envelope, `request_id`, `MALFORMED_JSON` |
| `Wwhn172AggregateLock` | Khoá aggregate cho thao tác đồng thời |
| `EnforceInventoryWarehouseScope` | Data scope theo kho; đăng ký toàn cục **cố ý** để refactor route không âm thầm mất scope |
| `permission` → `CheckPermission` | RBAC; hỗ trợ OR `permission:A\|B`; `SUPER_ADMIN` bypass |

### 3.3. Envelope và error code

Envelope lỗi thống nhất: `success`, `message` (tiếng Việt, không lộ stack/SQL/secret), `error_code`, `errors`, `error.code/details`, `meta.request_id/timestamp`. `error_code` và `error.code` **luôn bằng nhau** — client không được coi trường nào là optional.

| HTTP | Mã mặc định | Ý nghĩa |
| --- | --- | --- |
| 401 | `UNAUTHENTICATED` | thiếu/hết hạn token |
| 403 | `ACCESS_DENIED` | thiếu permission hoặc ngoài data scope |
| 404 | `RESOURCE_NOT_FOUND` | không tồn tại hoặc ngoài phạm vi dữ liệu |
| 405 | `METHOD_NOT_ALLOWED` | method không hỗ trợ |
| 409 | `STATE_CONFLICT` / `UNIQUE_CONSTRAINT_CONFLICT` | xung đột trạng thái/đồng thời/idempotency |
| 412 | `STALE_VERSION` | `If-Match` không khớp version hiện tại |
| 422 | `BUSINESS_RULE_ERROR` | validation/quy tắc nghiệp vụ |
| 428 | `PRECONDITION_REQUIRED` | thiếu `If-Match` bắt buộc |
| 500 | `INTERNAL_SERVER_ERROR` | lỗi ngoài dự kiến, giao dịch đã rollback |

Danh mục đầy đủ: `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md` (sinh tự động, CI `--check`).

## 4. Hợp đồng API bàn giao cho FE1/QA

| Artifact | Đường dẫn | Cách sinh lại |
| --- | --- | --- |
| OpenAPI 3.0 (179 path / 226 operation) | `storage/api-docs/api-docs.json\|yaml` | `php artisan l5-swagger:generate` |
| Ma trận endpoint × permission × OpenAPI | `docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md` | `node scripts/generate-endpoint-permission-matrix.mjs` |
| Error code catalog | `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md` | `node scripts/generate-error-code-catalog.mjs` |
| Permission catalog + role matrix | `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json` | `php artisan evidence:permission-catalog` |
| Postman collection/environment | `docs/postman/` | `npm run api:postman` |
| OpenAPI fragment theo task | `docs/api-contract/WWHN-*.yaml\|json` | giữ nguyên cho traceability |
| Contract API bàn giao (điểm vào cho FE/QA) | `docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md` | cập nhật tay khi đổi quy ước contract |
| Kiến trúc backend as-built | `docs/architecture/HN-BE-ARCH-001_BACKEND_ARCHITECTURE.md` | cập nhật tay khi đổi kiến trúc |
| Runbook vận hành backend | `docs/ops/HN-BE-OPS-001_OPERATIONS_RUNBOOK.md` | cập nhật tay khi đổi quy trình vận hành |
| Cổng tổng gói bàn giao | `scripts/verify-wwhn-309-handover-contract.mjs` | `node scripts/verify-wwhn-309-handover-contract.mjs` |

Quy tắc bắt buộc khi đọc permission trong contract:

1. `permission` là **biểu thức**; `A|B` nghĩa là OR. Dùng `accepted_permissions` đã tách sẵn.
2. `roles` đã gồm `SUPER_ADMIN` do bypass backend, không phải do gán permission.
3. Data scope là tầng kiểm tra độc lập: đúng permission vẫn có thể 403 vì ngoài phạm vi kho.
4. Optimistic locking: 41 operation khai `If-Match`; thiếu header → 428, sai version → 412.
5. Idempotency: 27 operation khai `Idempotency-Key` (23 bắt buộc, 4 tuỳ chọn theo Annex C §8.1 nhóm warranty stock-neutral). Chuỗi 8–100 ký tự; cùng key + cùng payload = replay kết quả lần đầu, cùng key + payload khác = 409. Retry cùng key khi mất mạng là an toàn.
6. Hình dạng payload đọc được ngay từ spec: mọi response `$ref` tới `ApiSuccessEnvelope` hoặc `ApiErrorEnvelope` trong `components.schemas`. Không cần đọc ngược code để biết envelope.
7. Header contract trong spec được sinh từ chính source của controller, không khai tay — thêm `IdempotencyKeyGuard::require()` hoặc đọc `If-Match` trong action là spec tự có header tương ứng ở lần generate kế tiếp.

Thêm controller có annotation mới **phải** khai đường dẫn trong `config/l5-swagger.php`, nếu không Swagger publish endpoint rỗng contract (bài học DEV184-P1-01R và Flow 06).

## 5. Vận hành

### 5.1. Health và danh tính artifact

`GET /api/v1/health` (public) trả `status`, `version`, `build.sha/ref/built_at`, `environment`, `checks`. Critical checks để trả 200: `app`, `database`, `migrations`, `seed`. `cache` cố ý **không** phải hard gate (fail-open xuống PostgreSQL, tránh restart loop khi Valkey chập chờn).

DevOps/CI phải inject `APP_BUILD_SHA` / `APP_BUILD_REF` / `APP_BUILD_TIME` lúc build image — đây là cách duy nhất reviewer đọc được commit đang chạy thay vì tin lời khai; thiếu ở môi trường ngoài local/testing sẽ bị `wms:golive-check` chặn.

### 5.2. Job nền bắt buộc

| Lịch | Lệnh | Hậu quả nếu không chạy |
| --- | --- | --- |
| 01:30 | `inventory:reconcile` | Lệch ledger/balance chỉ lộ khi có người mở báo cáo (SRS §7.5) |
| mỗi 5 phút | `audit:reconcile` | `audit_outbox` phình vô hạn, audit trail mất |
| 02:15 | `import:purge-error-files` | File lỗi CSV không bao giờ bị xoá, đĩa phình |
| 03:10 | `warranty:purge-pii` | PII tồn tại vô thời hạn, vi phạm DR-PRIV-001 |

Điều kiện vận hành: phải có process chạy `php artisan schedule:work` (container `scheduler` trong compose) hoặc cron `schedule:run`. Không có process này thì cả 4 nghĩa vụ trên đều **không tồn tại trên thực tế**.

### 5.3. Lệnh vận hành

`wms:golive-check` (gate cutover), `migration:final-load` (nạp dữ liệu cuối), `evidence:permission-catalog`, `evidence:stock-snapshot`, `wms:cache:clear`, `wwhn172:migration-preflight`, `inventory:reconcile`, `audit:reconcile`.

### 5.4. Runbook

- Cutover/rollback: `docs/evidence/WWHN-300/CUTOVER_ROLLBACK_RUNBOOK.md`
- Nạp dữ liệu cuối: `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md`
- Rollback migration: `docs/evidence/WWHN-172/MIGRATION_ROLLBACK_RUNBOOK.md`

`migrate:rollback` toàn lịch sử đã từng brick DB; mọi `down()` phải dùng `dropRelation` (chuẩn WWHN-300).

## 6. Build / run / quality gates

```bash
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate --force
php artisan db:seed
php artisan test                      # SQLite in-memory (dev)
php artisan test -c phpunit.pgsql.xml # PostgreSQL (nghiệm thu)
php artisan l5-swagger:generate
php artisan evidence:permission-catalog
php artisan schedule:list
composer run verify:stock-write-path
npm run api:lint
node scripts/generate-error-code-catalog.mjs --check
node scripts/generate-endpoint-permission-matrix.mjs --check
node scripts/verify-wwhn-309-handover-contract.mjs
```

Execution identity tối thiểu khi review: branch `m1`, source SHA, GitHub Actions run ID/URL, `APP_ENV`, `APP_BUILD_SHA`, kết quả từng gate kèm exit code, tên artifact + checksum. **Không dùng log của SHA khác để kết luận WWHN-309 PASS.**

## 7. Evidence

| Loại | Nguồn |
| --- | --- |
| Regression test handover | `tests/Feature/Wwhn309PermissionCatalogEvidenceTest.php` (6 test), `tests/Feature/Wwhn309ScheduledOperationsTest.php` (4 test) |
| CI exact-SHA | `.github/workflows/wwhn-309-backend-handover-evidence.yml` → artifact `wwhn-309-backend-handover-<sha>` |
| Permission catalog runtime | `build/evidence/wwhn-309/permission-catalog.json`, `permission-seed-diff.json` |
| API contract | `storage/api-docs/api-docs.json`, `docs/api-contract/HN-BE-API-002/003` |
| Ops | `php artisan schedule:list`, `/api/v1/health` |
| Cổng tổng tài liệu | `node scripts/verify-wwhn-309-handover-contract.mjs` → step `Handover documentation contract gate` trong workflow |
| Đối chiếu chéo FE ↔ BE | FE `npm run contract:sync -- <BE_SHA>` + `npm run check:api-mapping` = PASS 56 dòng; biên nhận `FE@docs/Task_docs_Jira/WWHN-309_BE_CONTRACT_RECEIPT.md` |
| Bộ comment sign-off theo vai trò | `docs/docs_task_jira/WWHN-309_SIGNOFF_COMMENTS.md` |

Workflow CI so byte catalog publish với catalog sinh tại đúng SHA (trừ `generated_at_utc`) — docs stale sẽ fail build, không còn phụ thuộc việc nhớ regenerate.

### 7.1. Bẫy truy vết: `/docs/` nằm trong .gitignore

`.gitignore` khai `/docs/`, nên **mọi file tài liệu mới dưới `docs/` mặc định không vào Git** — 158 file docs đang tracked là do từng được `git add -f`. Nếu quên bước này, tài liệu bàn giao chỉ tồn tại trên máy dev và reviewer fetch tại exact SHA sẽ không thấy gì: đúng lớp lỗi FE311-P1-03/P1-05 (artifact stale / weak GitHub trace).

Gói WWHN-309 đã được `git add -f`: `docs/docs_task_jira/WWHN-309.md`, `docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md`, `HN-BE-API-002_ERROR_CODE_CATALOG.md|json`, `HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md|json`.

Thêm tài liệu handover mới thì phải `git add -f` và kiểm chứng bằng `git ls-files docs/<path>` **trước khi** tuyên bố đã bàn giao.

## 8. Điểm cần sign-off và blocker còn lại

| # | Nội dung | Owner | Ghi chú |
| --- | --- | --- | --- |
| 1 | Baseline role của `scan classify` gồm 6 role (4 role WWHN-150 + `WAREHOUSE_KEEPER`/`WAREHOUSE_STAFF` qua alias Annex C `scan.execute`) | BA/BAS | Không thay đổi quyền trong seed/route; chỉ sửa expectation của evidence cho khớp code đã duyệt + SRS v1.12 §5 |
| 2 | Có process `schedule:work`/cron ở UAT/production và `APP_BUILD_*` được inject | DevOps (WWHN-313) | Không có thì 4 job nền và gate exact-SHA không thực thi được |
| 3 | Run CI exact-SHA tại closure SHA được pin vào task | BE1 + QA1 | Trước khi có run thật, CI/runtime để trạng thái PENDING CI EXECUTION, không ghi VERIFIED |
| 4 | Disposition cho `CurrentBaselineIfMatch` (gỡ hay wire vào route) | Tech Lead | Hiện là dead code đã được cảnh báo trong docblock |
| 5 | Parent WWHN-83 còn `Blocked/Pending` | BAS/PM | WWHN-309 không tự tuyên bố parent Done |

Không có blocker P0 thuộc vai trò BE1 tại thời điểm cập nhật này.
