# WWHN-155 — Chuẩn hóa migration/seed/health endpoint

## 1. Trạng thái remediation

- P0: Không có P0 trực tiếp thuộc BE1.
- P1 API contract: Đã chuẩn hóa endpoint chính thức `GET /api/v1/health`; loại bỏ route legacy `/api/health`.
- P1 response contract: Đã bổ sung `version`, `environment`, `server_time`, `request_id` và `checks` cho cả nhánh HTTP 200 và 503.
- P1 migration/seed test: Đã bổ sung test idempotency, seed marker và HTTP contract 200/503.
- P2 handoff: Đã bổ sung bảng bàn giao FE/QA và checklist evidence.
- External gate: WWHN-157 và WWHN-32 vẫn cần QA1/DevOps chạy trên DEV/UAT và attach evidence thực tế.

## 2. Contract chính thức

| Hạng mục | Giá trị |
|---|---|
| Method | `GET` |
| Endpoint | `/api/v1/health` |
| Authentication | Public, không yêu cầu token |
| Version env | `APP_VERSION`, mặc định `1.0.0` |
| Success | HTTP `200`, `success=true`, `data.status=ok` |
| Failure | HTTP `503`, `success=false`, `error_code=HEALTH_CHECK_DEGRADED` |
| Request trace | Nhận/trả `X-Request-ID`; payload có `request_id` |
| Security | Không trả credential, DSN, SQL error, hostname, exception hoặc stack trace |

### Success sample

```json
{
  "success": true,
  "message": "Backend health check passed.",
  "data": {
    "status": "ok",
    "version": "1.0.0",
    "environment": "uat",
    "server_time": "2026-08-06T13:30:00+07:00",
    "request_id": "WWHN-155-UAT-001",
    "checks": {
      "app": {"status": "ok"},
      "database": {"status": "ok", "driver": "pgsql"},
      "migrations": {"status": "ok", "ran_count": 42},
      "seed": {
        "status": "ok",
        "markers": {
          "admin_user": true,
          "baseline_warehouse": true,
          "baseline_product": true
        }
      }
    }
  },
  "meta": {
    "request_id": "WWHN-155-UAT-001",
    "timestamp": "2026-08-06T13:30:00+07:00"
  }
}
```

### Failure sample

HTTP `503` giữ cùng metadata, `errors.status=degraded`, component lỗi có `status=fail`; không lộ chi tiết hạ tầng nhạy cảm.

## 3. Seed markers bắt buộc

| Marker | Bảng | Điều kiện |
|---|---|---|
| Admin baseline | `app_user` | `username=admin` |
| Kho tổng Hoa Nam | `warehouses` | `code=KHO-TONG-HN` |
| Sản phẩm baseline PV | `products` | `code=HN-PV-BASE-001` |

Seeder phải idempotent: chạy lặp không tạo duplicate marker.

## 4. Test và evidence bắt buộc để đóng P1

Chạy trên exact commit SHA ở local CI, DEV và UAT:

```bash
php artisan test --filter BackendMigrationSeedHealthTest
php artisan migrate:fresh --seed
php artisan route:list --path=api/v1/health
curl -i -H 'X-Request-ID: WWHN-155-DEV-001' "$DEV_API_URL/api/v1/health"
curl -i -H 'X-Request-ID: WWHN-155-UAT-001' "$UAT_API_URL/api/v1/health"
```

DB marker evidence:

```sql
select username, status from app_user where username = 'admin';
select code, status from warehouses where code = 'KHO-TONG-HN';
select code, published_to_app from products where code = 'HN-PV-BASE-001';
```

Evidence phải ghi: commit SHA, workflow/run URL, environment URL, DB engine/version, PHP/Laravel version, timestamp, command, exit code, raw log/artifact digest.

## 5. Handoff FE/QA

| Consumer | Input | Expected | Failure | Owner xác nhận |
|---|---|---|---|---|
| FE1 | `VITE_API_BASE_URL` kết thúc `/api/v1`; health path `/health` | HTTP 200 và schema success | HTTP 503 hiển thị degraded; không fallback `/api/health` | FE1 |
| QA1 | DEV/UAT URL, exact SHA/build | Smoke health + migration/seed + login/master API theo parent AC | Attach raw log và PASS/FAIL | QA1 / WWHN-157 |
| DevOps | `APP_VERSION`, APP_ENV, DB config, deploy manifest | Config matrix, deploy log, rollback/recovery, health pass | Fail-closed nếu config/seed/migration thiếu | DevOps / WWHN-32 |

## 6. Boundary và rollback

- Health endpoint là readiness check, không thay thế monitoring sâu hoặc liveness probe riêng.
- Không ghi dữ liệu khi gọi health.
- Rollback code: revert exact commit; rollback deployment theo manifest của WWHN-32.
- Rollback database: dùng backup/restore hoặc migration rollback đã được DevOps xác minh; không chạy `migrate:fresh` ngoài môi trường kiểm thử.
- Không đánh dấu Done sạch khi WWHN-157 chưa có smoke log DEV/UAT hoặc WWHN-32 chưa xác nhận environment/deploy/config/secret/rollback.

## 7. Traceability

- Parent: WWHN-32
- QA smoke: WWHN-157
- FE contract baseline: `WWHN-156-health-v1`
- Code scope: `routes/api.php`, `app/Http/Controllers/HealthController.php`, `config/health.php`, `tests/Feature/BackendMigrationSeedHealthTest.php`
