# WWHN-172 — Migration dry-run, precheck và rollback runbook

## Phạm vi

Migration: `2026_08_04_000220_harden_wwhn172_serial_and_code_uniqueness.php`.

Migration không tự hợp nhất/xóa/đổi owner dữ liệu legacy. Khi preflight `BLOCKED`, DBA dừng rollout, xuất vi phạm, xác nhận owner nghiệp vụ và xử lý bằng change record riêng.

## Precheck

```bash
php artisan wwhn172:migration-preflight --output=build/evidence/wwhn-172/migration-preflight.json
```

Chỉ tiếp tục khi `summary.status=PASS` và `summary.failed=0`.

## Dry-run và rollout

```bash
php artisan migrate --pretend --force | tee migration-pretend.raw.log
php artisan migrate --force | tee migration-apply.raw.log
php artisan wwhn172:migration-preflight --output=migration-postcheck.json
```

Sau apply xác nhận bảng `aggregate_mutexes`, unique serial/container physical-code và check constraints trên PostgreSQL/MySQL.

## Rollback/re-apply

```bash
php artisan migrate:rollback --step=1 --force | tee migration-rollback.raw.log
php artisan migrate --force | tee migration-reapply.raw.log
```

Không rollback riêng code khi schema đã rollback hoặc ngược lại. Dừng rollout nếu preflight BLOCKED, migration/test fail, audit fail-closed không rollback hoặc manifest hash mismatch.
