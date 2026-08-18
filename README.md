# WMS Warehouse Hoa Nam — Backend

Backend API của hệ thống quản lý kho Hoa Nam. Laravel 13 + PHP 8.3 + PostgreSQL 16 + Valkey, kiến trúc module (`nwidart/laravel-modules`), REST API `/api/v1/*` với JWT, RBAC theo permission code và data scope theo kho.

| Hạng mục | Giá trị |
|---|---|
| Nhánh phát triển hiện hành | `m1` |
| Runtime | PHP 8.3+, Laravel 13, PostgreSQL 16, Valkey 8, Nginx, MinIO (S3-compatible) |
| Auth | JWT (`tymon/jwt-auth`), guard `api` |
| API docs | Swagger/OpenAPI 3.0 sinh từ annotation (`l5-swagger`) |
| Test | PHPUnit 12 (SQLite in-memory mặc định, PostgreSQL cho nghiệm thu) |
| Module | `Modules/Auth`, `Modules/Warehouse`, `Modules/AuditLog` |

Tài liệu bàn giao đầy đủ (kiến trúc/API/vận hành): [`docs/docs_task_jira/WWHN-309.md`](docs/docs_task_jira/WWHN-309.md).

## 1. Chạy bằng Docker Compose (khuyến nghị)

```bash
cp .env.example .env
docker compose up -d --build
```

Compose dựng: `nginx`, `backend` (php-fpm), `queue` (`queue:work`), `scheduler` (`schedule:work`), `postgres`, `valkey`, `minio`, `mailpit`.

| Service | Cổng mặc định | Biến đổi cổng |
|---|---|---|
| API qua Nginx | `8080` | `NGINX_DOCKER_PORT` |
| PostgreSQL | `5432` | `POSTGRES_DOCKER_PORT` |
| Valkey | `6379` | `REDIS_DOCKER_PORT` |
| MinIO / Mailpit | theo `docker-compose.yml` | — |

`docker/php/entrypoint.sh` tự: cài composer khi `composer.lock` đổi, tạo `.env`, sinh `APP_KEY`/`JWT_SECRET`, và chạy `php artisan migrate --force` **chỉ ở service `backend`** (`AUTO_MIGRATE=false` ở `queue`/`scheduler` để worker không migrate trùng).

Kiểm tra sau khi lên:

```bash
curl -s http://localhost:8080/api/v1/health
```

## 2. Chạy trực tiếp (không Docker)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate --force
php artisan db:seed
php artisan serve
```

Yêu cầu sẵn có PostgreSQL và Valkey/Redis theo cấu hình `.env`, hoặc đổi `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION` sang `file`/`sync` khi chạy cục bộ.

## 3. Biến môi trường phải khai

| Nhóm | Biến | Ghi chú |
|---|---|---|
| App | `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE=Asia/Ho_Chi_Minh` | `APP_DEBUG=false` bắt buộc ở UAT/production (`wms:golive-check` chặn) |
| Danh tính artifact | `APP_VERSION`, `APP_BUILD_SHA`, `APP_BUILD_REF`, `APP_BUILD_TIME` | CI/DevOps inject lúc build image; `/api/v1/health` trả lại để gate exact-SHA truy vết đúng commit đang chạy |
| Database | `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL là database canonical duy nhất |
| Cache/queue/session | `CACHE_STORE=redis`, `CACHE_PREFIX`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `REDIS_*` | Mỗi môi trường dùng chung Valkey **phải** đặt `CACHE_PREFIX` khác nhau |
| TTL cache | `CACHE_TTL_RBAC`, `CACHE_TTL_MASTER`, `CACHE_TTL_REFERENCE`, `CACHE_TTL_SKU`, `CACHE_TTL_SEARCH`, `CACHE_TTL_DASHBOARD`, `CACHE_TTL_REPORT` | Policy tập trung ở `config/wms_cache.php`, không hard-code TTL trong code |
| Auth | `JWT_SECRET`, `JWT_TTL` | |
| Storage | `FILESYSTEM_DISK=s3`, `AWS_*` | MinIO ở local, S3-compatible ở production |

Không commit `.env`; secret chuyển giao qua kênh an toàn, không dán vào tài liệu hay Jira.

## 4. Test

```bash
php artisan test
```

Mặc định chạy SQLite in-memory (`phpunit.xml`). Nghiệm thu cuối phải chạy trên PostgreSQL:

```bash
php artisan test -c phpunit.pgsql.xml
```

SQLite chỉ là workaround cho tốc độ dev: không có trigger/view PL/pgSQL, không enforce `varchar(N)`, một số bảng đảo chiều số ít/số nhiều. **PASS trên SQLite không phải căn cứ nghiệm thu.**

Kiểm tra hợp đồng/evidence hay dùng:

```bash
composer run verify:stock-write-path
npm run api:lint
npm run api:postman
npm run api:smoke
```

## 5. API và tài liệu hợp đồng

```bash
php artisan l5-swagger:generate
```

Sinh `storage/api-docs/api-docs.json|yaml` (số path/operation hiện tại: xem `docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.md`), UI tại `/api/documentation`.

- Annotation nằm ngay trên controller thật; đường dẫn quét khai trong `config/l5-swagger.php` — thêm controller mới có annotation thì **phải** thêm đường dẫn vào đó, nếu không Swagger publish endpoint rỗng contract.
- `App\Swagger\Processors\RouteSyncProcessor` đồng bộ spec với route table thật, gắn envelope schema (`ApiSuccessEnvelope`/`ApiErrorEnvelope`) cho **mọi** response và tự khai `Idempotency-Key`/`If-Match` theo đúng source của action — contract không thể lệch runtime mà không sửa cùng chỗ.
- `tests/Feature/SwaggerRouteSyncTest.php` chặn lệch giữa route runtime và OpenAPI.
- Permission catalog: `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json` (regenerate bằng `php artisan evidence:permission-catalog`).
- Postman collection/environment: `docs/postman/`.

Tài liệu contract sinh tự động (chạy lại sau mỗi lần đổi route/error code):

```bash
node scripts/generate-error-code-catalog.mjs           # error code -> HN-BE-API-002
node scripts/generate-endpoint-permission-matrix.mjs   # operation x permission -> HN-BE-API-003
node scripts/generate-api-contract-metrics.mjs         # số liệu contract -> HN-BE-API-004
node scripts/verify-wwhn-309-handover-contract.mjs     # cổng tổng gói bàn giao WWHN-309
```

Cả ba đều có chế độ fail-closed: `--check` của hai generator và verifier tổng đều exit khác 0 khi tài liệu lệch source, nên tài liệu không thể "đúng lúc viết, sai lúc đọc".

## 6. Vận hành

### 6.1. Health

`GET /api/v1/health` (public) trả `status`, `version`, `build.sha/ref/built_at`, `environment` và `checks`. Check bắt buộc để trả 200: `app`, `database`, `migrations`, `seed`. `cache` **cố ý** không phải hard gate — cache có fail-open xuống PostgreSQL, để tránh restart loop khi Valkey chập chờn. Laravel health mặc định vẫn ở `/up`.

### 6.2. Job nền (bắt buộc có process chạy `schedule:work`/`schedule:run`)

| Lịch | Lệnh | Vì sao bắt buộc |
|---|---|---|
| 01:30 hằng ngày | `inventory:reconcile` | SRS §7.5 — lệch ledger/balance phải sinh Reconciliation_Incident; lệnh chỉ ghi incident, không tự sửa tồn |
| mỗi 5 phút | `audit:reconcile` | Rút `audit_outbox`; không chạy thì audit trail mất và outbox phình vô hạn |
| 02:15 hằng ngày | `import:purge-error-files` | Xoá file lỗi CSV hết hạn khỏi đĩa |
| 03:10 hằng ngày | `warranty:purge-pii` | SRS §13.8 / DR-PRIV-001 — anonymize PII hết retention, bỏ qua legal hold |

Tất cả đều `withoutOverlapping()` + `onOneServer()`; `tests/Feature/Wwhn309ScheduledOperationsTest.php` khoá danh sách này.

### 6.3. Lệnh vận hành khác

| Lệnh | Dùng khi |
|---|---|
| `php artisan wms:golive-check --json=<file>` | Gate cutover: migration/seed/core/security readiness |
| `php artisan migration:final-load` | Lần nạp dữ liệu cuối (dry-run → commit → reconcile → rollback có kiểm soát) |
| `php artisan evidence:permission-catalog` | Sinh permission catalog + seed diff làm evidence |
| `php artisan evidence:stock-snapshot` | Snapshot balance/ledger/posting/reversal làm evidence |
| `php artisan wms:cache:clear` | Bump version namespace cache theo scope (không `FLUSHALL`/`KEYS *`) |
| `php artisan wwhn172:migration-preflight` | Kiểm tra dữ liệu legacy trước migration hardening |

### 6.4. Runbook

- Cutover/rollback: `docs/evidence/WWHN-300/CUTOVER_ROLLBACK_RUNBOOK.md`
- Nạp dữ liệu cuối: `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md`
- Rollback migration: `docs/evidence/WWHN-172/MIGRATION_ROLLBACK_RUNBOOK.md`

> `php artisan migrate:rollback` trên toàn bộ lịch sử migration đã từng làm hỏng DB; mọi `down()` phải dùng `dropRelation` theo chuẩn WWHN-300. Đọc runbook trước khi rollback trên môi trường có dữ liệu.

## 7. Invariants nghiệp vụ không được vi phạm

- MVP **không** có stocktake/adjustment trực tiếp; sửa sai đi qua **reversal**.
- Không có partial posting: inbound/outbound là **full-post** trong một transaction.
- Outbound: tiếp nhận toa → `DRAFT` → scan/match đủ → Packing Label (Preview/Ready/Print + xác nhận đã dán) → **Post Issue**; chỉ Post Issue thành công mới trừ tồn.
- RFID là **optional** theo SRS/wireframe v1.12, không phải gate bắt buộc để Post.
- Movement/audit đã post **không sửa trực tiếp**.
- Backend luôn tự kiểm tra permission **và** data scope; FE ẩn nút không phải cơ chế bảo vệ.
- Ghi tồn kho phải: transaction → lock → kiểm tra lại số lượng → ghi movement → ghi audit → chuyển trạng thái → commit.

## 8. Cấu trúc thư mục

```
app/                    Console command, HTTP middleware, Support (idempotency, cache, ERD schema)
Modules/Auth/           IAM: user, role, permission, JWT, CheckPermission
Modules/Warehouse/      Nghiệp vụ kho: inbound, outbound, inventory, warranty, label, report, import
Modules/AuditLog/       Audit log API
routes/                 api.php (health + scan classify canonical), console.php (schedule)
database/               Migration/seeder cấp ứng dụng
docs/                   SRS, ERD, standard, OpenAPI fragment, Postman, permission, evidence, runbook
tests/Feature|Unit/     PHPUnit
scripts/                Script thu thập evidence và verifier
.github/workflows/      CI/CD và các gate exact-SHA theo task
```

Luồng xử lý chuẩn: `Route → Controller → Form Request → Service/UseCase → Model/Repository → PostgreSQL`. Controller không chứa business logic.

## 9. CI/CD

- `HN DEVOPS CI/CD` (`.github/workflows/hn-devops-ci-cd.yml`) chạy trên push/PR vào `dev`, `m1`, `main`; deploy UAT chỉ qua `workflow_dispatch` có input.
- Các workflow `wwhn-*` / `flow06-exact-sha` là gate evidence theo task, chạy trên đúng commit SHA.
- Evidence bàn giao phải gắn với **exact commit SHA** đang review, kèm run ID/URL, log thô và checksum artifact. Không dùng log của SHA khác để kết luận PASS.

## 10. Tài liệu baseline

| Tài liệu | Đường dẫn |
|---|---|
| SRS/FRD v1.12 | `docs/HN_SRS_FRD_v1.12_FULL.md` |
| ERD | `docs/HoaNam_ERD_Moi_Nhat.md` |
| Techstack & Dev Guide | `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` |
| Backend Standard | `docs/HN-BE-001_BACKEND_STANDARD.md` |
| Decision Record | `docs/decision-records/`, `docs/DR_HN_*.md` |
| Handover BE (WWHN-309) | `docs/docs_task_jira/WWHN-309.md` |
| Kiến trúc backend as-built | `docs/architecture/HN-BE-ARCH-001_BACKEND_ARCHITECTURE.md` |
| Contract API bàn giao FE/QA | `docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md` |
| Catalog error code (sinh tự động) | `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md` |
| Ma trận endpoint × permission (sinh tự động) | `docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md` |
| Runbook vận hành backend | `docs/ops/HN-BE-OPS-001_OPERATIONS_RUNBOOK.md` |
