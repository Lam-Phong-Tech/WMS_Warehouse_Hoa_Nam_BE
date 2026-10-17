# WWHN-300 — Phần backend của runbook cutover/rollback

Tài liệu này chỉ bao phủ **phạm vi BE1**. Environment, pipeline, secret, backup/restore và
quyết định Go/No-Go thuộc WWHN-298 (DevOps) và WWHN-80.

> Cập nhật 17/10/2026: bằng chứng round-trip 140 migration bên dưới là lịch sử,
> không phải chứng nhận rollback cho source hiện tại. Bốn ranh giới giữ bằng chứng
> mới được đối chiếu tại `BASELINE_RECONCILIATION_20261017.md`. Nghiệm thu runtime
> và production vẫn riêng biệt; không chạy rollback/restore để làm gate PASS.

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
| Schema mới lỗi, **chưa có dữ liệu nghiệp vụ mới** | Kiểm chứng exact batch trên bản sao cô lập trước khi duyệt phương án lùi | Không suy kết quả 140/140 cũ thành khả năng unwind source mới; `down()` fail-forward không khôi phục schema/trigger cũ |
| Đã phát sinh dữ liệu nghiệp vụ hoặc receipt sau cutover | Ưu tiên sửa tiến; chỉ lùi code khi đã chứng minh tương thích | Giữ receipt, quyền đã gán và bằng chứng bàn giao; không phục hồi trigger idempotency đã biết lỗi |
| Cần restore snapshot | Chỉ theo phương án được DevOps phê duyệt riêng | Xác minh backup và đối chiếu mọi dữ liệu phát sinh sau snapshot trước khi quyết định; không restore tự động |

`php artisan wms:golive-check` liệt kê chính xác tập fail-forward ở dimension
`migrations.reversible` — dùng nó để biết cutover đã đi qua ranh giới nào.

> **Không bao giờ** chạy `migrate:fresh` trên môi trường có dữ liệu thật. Lệnh này drop toàn bộ
> schema rồi dựng lại; nó chỉ dùng cho CI và máy dev.

## 4. Sau rollback

```bash
php artisan migrate --force
php artisan wms:golive-check
```

Không tự kèm seed vào lệnh deploy/rollback. Mỗi lượt seed cần phạm vi và kế hoạch
đã duyệt riêng; thử trên DB/storage/media cô lập trước. Workflow round-trip cũ
không phải bằng chứng full seed hiện tại đã PASS.

## 5. Smoke tối thiểu phía backend

```bash
curl -fsS -H 'Accept: application/json' "$BASE_URL/api/v1/health"
```

Kỳ vọng 200 + `data.status = ok` + `checks.migrations.status = ok` + `checks.seed.status = ok`.
`checks.cache` **không** gate readiness (Valkey fail-open xuống PostgreSQL) — xem
`HealthController::CRITICAL_CHECKS`. Hợp đồng chi tiết của endpoint này thuộc WWHN-155/WWHN-156.
