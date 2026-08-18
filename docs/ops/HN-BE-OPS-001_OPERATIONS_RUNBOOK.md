# HN-BE-OPS-001 — Runbook vận hành backend WMS Hoa Nam

| Trường | Giá trị |
| --- | --- |
| Tài liệu | HN-BE-OPS-001 |
| Task | WWHN-309 — [BE] Hoàn thiện tài liệu kiến trúc/API/vận hành |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`, nhánh `m1` |
| Phạm vi | Vận hành **phía backend**: cấu hình, deploy, job nền, health, sự cố thường gặp |
| Ngoài phạm vi | Hạ tầng/access/backup/monitor cấp hệ thống → DevOps, WWHN-313 |
| Cập nhật | 2026-08-18 |

---

## 1. Thành phần cần chạy

Thiếu bất kỳ mục nào dưới đây thì hệ thống **vẫn trả API** nhưng vi phạm nghĩa vụ vận hành/tuân thủ. Đây là danh sách kiểm tra bắt buộc khi lên môi trường mới.

| Thành phần | Lệnh/ảnh | Bắt buộc | Hậu quả nếu thiếu |
| --- | --- | --- | --- |
| PHP-FPM (API) | `docker/php/Dockerfile` | Có | Không có API |
| nginx | `nginx:alpine`, cổng `${NGINX_DOCKER_PORT:-8080}` | Có | Không vào được API |
| PostgreSQL 16 | `postgres:16-alpine` | Có | Không có nguồn sự thật |
| Valkey 8 | `valkey/valkey:8-alpine` | Có | Mất session/queue; cache fail-open về DB |
| Queue worker | `php artisan queue:work --tries=3 --timeout=${QUEUE_WORKER_TIMEOUT:-90}` | Có | Job nền không chạy |
| **Scheduler** | `php artisan schedule:work` | **Có** | Toàn bộ job định kỳ ở §4 **không bao giờ chạy** |
| S3/MinIO | `minio/minio` (local) | Có | Không upload/download được đính kèm, export |
| Mail | `mailpit` (local) / SMTP thật | Tuỳ môi trường | Không gửi được thông báo |

> **Bẫy đã từng gây lỗi thật:** `Schedule::command()` trong `routes/console.php` chỉ là *đăng ký*. Không có process chạy `schedule:work` (hoặc cron `* * * * * php artisan schedule:run`) thì không job nào tự chạy — lệnh vẫn tồn tại, test vẫn xanh, mà nghĩa vụ vận hành không bao giờ được thực thi.

---

## 2. Cấu hình môi trường

Nguồn chuẩn là `.env.example` — file này có chú thích đầy đủ cho từng nhóm biến. Các biến **bắt buộc phải đặt riêng cho từng môi trường**:

| Nhóm | Biến | Ghi chú |
| --- | --- | --- |
| Ứng dụng | `APP_ENV`, `APP_KEY`, `APP_URL`, `APP_DEBUG` | `APP_DEBUG=false` ngoài local — `wms:golive-check` chặn nếu bật |
| Danh tính bản build | `APP_BUILD_SHA`, `APP_BUILD_REF`, `APP_BUILD_TIME` | CI/DevOps inject lúc build image. Thiếu ở môi trường ngoài local/testing sẽ bị `wms:golive-check` chặn |
| Database | `DB_*` | PostgreSQL |
| Cache/Session/Queue | `REDIS_*`, `CACHE_STORE`, `SESSION_DRIVER`, `QUEUE_CONNECTION` | `REDIS_CACHE_DB=1` tách cache khỏi session/queue |
| Cache prefix | `CACHE_PREFIX` | **Bắt buộc khác nhau** nếu nhiều môi trường dùng chung một Valkey |
| TTL cache | `CACHE_TTL_*`, `CACHE_TTL_JITTER_PERCENT` | Chính sách tập trung ở `config/wms_cache.php` |
| JWT | `JWT_SECRET`, `JWT_TTL`, `REFRESH_TOKEN_*` | `REFRESH_TOKEN_COOKIE_SECURE` bị ép `true` ngoài local |
| CORS | `CORS_ALLOWED_ORIGINS` | Liệt kê origin FE tường minh, **không wildcard** |
| Audit | `AUDIT_WRITE_POLICY` | Mặc định `outbox_required` |
| Lưu trữ | `AWS_*` | S3/MinIO |
| OpenAPI | `L5_SWAGGER_CONST_HOST` | Đặt theo `APP_URL` của môi trường |

**Không commit secret.** Bàn giao secret theo kênh an toàn riêng như quy định ở WWHN-83; tài liệu chỉ ghi *tên biến*, không ghi *giá trị*.

---

## 3. Deploy

### 3.1. Chạy tại chỗ (docker compose)

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan jwt:secret
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan db:seed --force
curl -fsS http://localhost:8080/api/v1/health
```

### 3.2. Thứ tự khi phát hành

1. Build image gắn `APP_BUILD_SHA/REF/TIME` bằng đúng commit sẽ deploy.
2. Backup database (§7) — **trước** khi migrate.
3. `php artisan migrate --force`.
4. `php artisan config:cache route:cache` (nếu quy trình môi trường có bước cache).
5. Khởi động lại API, queue worker, scheduler.
6. `php artisan wms:golive-check --json=build/evidence/<task>/golive-check.json`.
7. `curl /api/v1/health` — kỳ vọng `status=ok`.
8. Ghi lại: commit SHA, run ID CI, kết quả golive-check, kết quả health.

### 3.3. Cổng kiểm tra trước cutover

```bash
php artisan wms:golive-check --json=build/evidence/golive-check.json
```

Kiểm tra migration/seed/core/security readiness. Đây là cổng **fail-closed**: có mục đỏ thì không cutover.

---

## 4. Job định kỳ (scheduler)

Đăng ký tại `routes/console.php`. Tất cả đều `withoutOverlapping()` + `onOneServer()` nên an toàn khi chạy nhiều node.

| Lịch | Lệnh | Vì sao bắt buộc |
| --- | --- | --- |
| Mỗi 5 phút | `audit:reconcile` | Rút `audit_outbox` về `audit_log`. Không chạy → outbox phình vô hạn, audit trail không hoàn tất |
| 01:30 hằng ngày | `inventory:reconcile` | SRS §7.5 — lệch ledger/balance phải sinh `Reconciliation_Incident` chủ động. **Không tự sửa tồn**: sửa sai vẫn đi qua Reversal |
| 02:15 hằng ngày | `import:purge-error-files` | Xoá file lỗi CSV đã hết hạn. Không chạy → đĩa phình theo số lần import lỗi |
| 03:10 hằng ngày | `warranty:purge-pii` | SRS §13.8 / DR-PRIV-001 — anonymize PII sau retention 5 năm, trừ legal hold. Đây là nghĩa vụ tuân thủ |

Kiểm tra nhanh scheduler có sống không:

```bash
php artisan schedule:list
docker compose logs --tail=100 scheduler
```

---

## 5. Lệnh vận hành

| Lệnh | Mục đích |
| --- | --- |
| `php artisan wms:golive-check [--json=<file>]` | Cổng readiness cutover |
| `php artisan wms:cache:clear --scope=rbac\|master\|sku\|stock\|reports\|all` | Bump version namespace cache theo phạm vi. **Không** dùng `FLUSHALL`/`FLUSHDB`/`KEYS *` |
| `php artisan inventory:reconcile [--dry-run]` | Đối soát balance với ledger |
| `php artisan audit:reconcile [--limit=]` | Rút audit outbox |
| `php artisan warranty:purge-pii [--dry-run] [--limit=500]` | Purge PII theo retention |
| `php artisan import:purge-error-files [--dry-run]` | Dọn file lỗi import |
| `php artisan evidence:permission-catalog` | Xuất permission catalog + route matrix làm evidence |
| `php artisan evidence:stock-snapshot --label=before\|after` | Snapshot tồn/ledger trước–sau thao tác |
| `php artisan migration:final-load --mode=DRY_RUN\|COMMIT\|ROLLBACK --package=<dir> --run-code=<code>` | Nạp dữ liệu đầu kỳ có kiểm soát |
| `php artisan wms:seed-uat --password=<pwd>` | Seed tài khoản UAT |

**Luôn `--dry-run` trước** với các lệnh có xoá/ghi dữ liệu (`warranty:purge-pii`, `import:purge-error-files`, `inventory:reconcile`, `migration:final-load`).

---

## 6. Giám sát

### 6.1. Health endpoint

```bash
curl -fsS http://<host>/api/v1/health
```

- `200` → `status=ok`; `503` → `status=degraded`, error code `HEALTH_CHECK_DEGRADED`.
- Check critical (quyết định 200/503): `app`, `database`, `migrations`, `seed`.
- `cache` **cố ý không critical**: application cache có fail-open về PostgreSQL. Biến `cache` thành cổng cứng sẽ gây restart-loop chỉ vì Valkey chập chờn dù API vẫn phục vụ được.
- Payload có `version`, `environment`, `server_time`, `request_id` và khối `build` (`sha`/`ref`/`built_at`) để xác thực đúng artifact đang chạy.

Khuyến nghị probe: liveness và readiness cùng dùng `/api/v1/health`, interval 10–30s, timeout ≥ 5s.

### 6.2. Cần theo dõi

| Tín hiệu | Ngưỡng cảnh báo |
| --- | --- |
| `/api/v1/health` trả 503 | Bất kỳ lần nào |
| Số bản ghi tồn trong `audit_outbox` | Tăng liên tục qua nhiều chu kỳ 5 phút |
| `reconciliation_incidents` mới | Bất kỳ bản ghi mới nào |
| Queue backlog / job failed | Backlog tăng đều, hoặc có `failed_jobs` |
| Tỷ lệ 5xx | Vượt ngưỡng thoả thuận với DevOps |
| Dung lượng `storage/app/import-error-files` | Tăng bất thường (dấu hiệu purge không chạy) |

### 6.3. Log

- Kênh `stack` → `storage/logs/laravel.log` (`LOG_LEVEL`, mặc định `debug` ở local).
- Mọi response có `meta.request_id`; đây là khoá đối chiếu giữa báo lỗi của người dùng, log và audit.

---

## 7. Backup và khôi phục

> Chính sách backup/retention cấp hạ tầng thuộc DevOps (WWHN-313). Phần dưới là **nghĩa vụ phía backend**.

- **Nguồn sự thật là PostgreSQL.** Backup PostgreSQL là backup dữ liệu nghiệp vụ.
- **Valkey không cần backup nghiệp vụ**: mất toàn bộ Valkey chỉ mất cache/session/queue đang chờ. Cache tự nguội lại; người dùng phải đăng nhập lại; job trong queue có thể mất → tránh restart Valkey giữa lúc queue đang dày.
- **S3/MinIO cần backup**: chứa file đính kèm bảo hành và file export.
- Backup trước mọi lần migrate; giữ bản backup ít nhất đến khi hết cửa sổ rollback đã thoả thuận.

Khôi phục: restore PostgreSQL → restore bucket → khởi động lại API/queue/scheduler → `wms:golive-check` → `/api/v1/health`.

---

## 8. Rollback

> **Cảnh báo đã có tiền lệ hỏng thật:** `php artisan migrate:rollback` có thể làm hỏng database nếu migration `down()` xoá bảng/cột mà migration khác còn phụ thuộc. Chuẩn của dự án: `down()` **luôn dùng `dropRelation`** (helper hạ quan hệ đúng thứ tự) thay vì `dropIfExists` trần.

Thứ tự ưu tiên khi cần lùi:

1. **Deploy lại image của commit trước.** Rẻ nhất và an toàn nhất khi schema không đổi.
2. **Restore database từ backup** nếu migration đã đổi schema theo hướng không lùi được.
3. `migrate:rollback` **chỉ khi** đã kiểm tra `down()` của đúng batch đó và có backup sẵn sàng.

Sau rollback: `wms:golive-check` + `/api/v1/health` + kiểm tra `inventory:reconcile --dry-run` không sinh incident mới.

---

## 9. Sự cố thường gặp

| Hiện tượng | Nguyên nhân thường gặp | Xử lý |
| --- | --- | --- |
| `/api/v1/health` 503, `database` đỏ | Sai `DB_*`, PostgreSQL chưa sẵn sàng | Kiểm tra kết nối, chờ healthcheck của container postgres |
| `/api/v1/health` 503, `migrations` đỏ | Chưa `migrate --force` sau deploy | Chạy migrate rồi kiểm tra lại |
| `/api/v1/health` 503, `seed` đỏ | Chưa seed dữ liệu nền (role/permission) | `php artisan db:seed --force` |
| Toàn bộ request 401 | `JWT_SECRET` chưa đặt hoặc đổi giữa các node | Đặt lại `JWT_SECRET` thống nhất; người dùng đăng nhập lại |
| FE bị CORS chặn | Origin FE chưa có trong `CORS_ALLOWED_ORIGINS` | Thêm origin **chính xác**; không dùng wildcard vì có credentialed CORS |
| Đăng nhập được nhưng refresh hỏng | Cookie refresh không `Secure` trên HTTPS, hoặc sai `SameSite` | Kiểm tra `REFRESH_TOKEN_COOKIE_*`; `SameSite=none` bắt buộc `Secure=true` |
| 428 bất ngờ | Thiếu `Idempotency-Key` hoặc `If-Match` | Xem header bắt buộc của operation trong OpenAPI |
| 412 lặp lại | Client giữ `version` cũ | Tải lại bản ghi rồi gửi lại |
| `audit_outbox` phình | Scheduler không chạy | Kiểm tra container `scheduler`, `schedule:list` |
| Dữ liệu cũ hiện trên UI | Cache chưa hết TTL | `wms:cache:clear --scope=<phạm vi>`; không FLUSH toàn bộ |
| Cache đụng nhau giữa môi trường | Trùng `CACHE_PREFIX` trên cùng Valkey | Đặt prefix riêng cho từng môi trường |
| Job chạy hai lần | Worker timeout lệch `retry_after` | Giữ nguyên quy ước `retry_after = QUEUE_WORKER_TIMEOUT + 30` ở `config/queue.php` |

---

## 10. Kiểm tra định kỳ

| Tần suất | Việc |
| --- | --- |
| Hằng ngày | `/api/v1/health`; `reconciliation_incidents` mới; queue failed jobs |
| Hằng tuần | Số tồn `audit_outbox`; dung lượng `storage/app`; log 5xx |
| Trước mỗi lần phát hành | Backup DB; `wms:golive-check`; đối chiếu `APP_BUILD_SHA` với commit sắp deploy |
| Sau mỗi lần phát hành | `/api/v1/health`; `inventory:reconcile --dry-run`; smoke các luồng nhập/xuất |

---

## 11. Ranh giới trách nhiệm

| Hạng mục | Chủ sở hữu |
| --- | --- |
| Cấu hình app, job nền, health, lệnh vận hành backend | BE1 — tài liệu này |
| Hạ tầng, access control, chính sách backup/retention, monitor/alert cấp hệ thống | DevOps — WWHN-313 |
| Test/release evidence | QA — WWHN-312 |
| Tài liệu dữ liệu/migration/báo cáo | BE — WWHN-310 |

Tài liệu này **không** thay thế sign-off của DevOps cho environment/backup/monitor.
