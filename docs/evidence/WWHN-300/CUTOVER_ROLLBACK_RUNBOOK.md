# WWHN-300 — Phần backend của runbook cutover/rollback

Tài liệu này chỉ bao phủ **phạm vi BE1**. Environment, pipeline, secret, backup/restore và
quyết định Go/No-Go thuộc WWHN-298 (DevOps) và WWHN-80.

## 1. Trước cutover (T-0)

```bash
php artisan wms:golive-check --json=evidence/pre-cutover.json
```

- Exit code 0 → backend sẵn sàng. Exit code 1 → **dừng**, đọc cột `Detail` để biết dimension nào FAIL.
- Bắt buộc chạy trên **chính environment sắp cutover**, không phải máy dev: các check
  `security.runtime_config` (APP_DEBUG/APP_KEY/JWT_SECRET) và `core.reconciliation_backlog`
  chỉ có ý nghĩa với runtime thật.

## 2. Áp migration

```bash
php artisan migrate --force
php artisan wms:golive-check --json=evidence/post-migrate.json
```

`migrations.applied` phải cho `pending = []`. Nếu còn pending nghĩa là build và schema lệch version.

## 3. Rollback — ranh giới thẩm quyền

Có **hai** loại rollback, đừng nhầm:

| Tình huống | Hành động đúng | Ghi chú |
|---|---|---|
| Schema mới lỗi, **chưa có dữ liệu nghiệp vụ mới** | `php artisan migrate:rollback --force` | Sau WWHN-300, chuỗi này unwind trọn vẹn (140/140) và `migrate` forward lại được. Đây là *schema* rollback |
| Đã phát sinh dữ liệu nghiệp vụ sau cutover | **Restore backup** theo authority DevOps | 75/140 migration là fail-forward: chúng đã copy/di chuyển dữ liệu sang bảng canonical và `down()` không dựng lại được dữ liệu cũ. `migrate:rollback` lúc này xóa schema **kèm dữ liệu** |

`php artisan wms:golive-check` liệt kê chính xác tập fail-forward ở dimension
`migrations.reversible` — dùng nó để biết cutover đã đi qua ranh giới nào.

> **Không bao giờ** chạy `migrate:fresh` trên môi trường có dữ liệu thật. Lệnh này drop toàn bộ
> schema rồi dựng lại; nó chỉ dùng cho CI và máy dev.

## 4. Sau rollback

```bash
php artisan migrate --force
php artisan db:seed --force        # chỉ khi rollback về schema rỗng
php artisan wms:golive-check
```

Round-trip này được CI chứng minh mỗi lần đụng vào `database/migrations` hoặc
`Modules/*/database/migrations`: `.github/workflows/wwhn-300-backend-golive-check.yml`.

## 5. Smoke tối thiểu phía backend

```bash
curl -fsS -H 'Accept: application/json' "$BASE_URL/api/v1/health"
```

Kỳ vọng 200 + `data.status = ok` + `checks.migrations.status = ok` + `checks.seed.status = ok`.
`checks.cache` **không** gate readiness (Valkey fail-open xuống PostgreSQL) — xem
`HealthController::CRITICAL_CHECKS`. Hợp đồng chi tiết của endpoint này thuộc WWHN-155/WWHN-156.
