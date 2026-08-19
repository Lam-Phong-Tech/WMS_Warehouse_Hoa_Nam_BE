# WWHN-222 — Test evidence

> **R2 (2026-08-19, sau review 75/100):** phần "R2 — PostgreSQL execution proof" ở cuối
> file bổ sung đúng gap review nêu: chạy thật trên PostgreSQL + vocabulary fix. Phần sqlite
> bên dưới là evidence R1 giữ nguyên làm lịch sử.

# R1 — Test evidence (local, sqlite)

Chạy local trên `phpunit.xml` mặc định (sqlite `:memory:`), trước khi commit, tại
working tree nhánh `m1` (HEAD lúc chạy: `089eacd2`). Không chạy qua CI/GitHub
Actions — theo đúng yêu cầu "chỉ thay đổi/chạy test local, không động CI".

## Run 1 — test mới + nhóm audit/auth trực tiếp liên quan

```
php vendor/bin/phpunit tests/Feature/Wwhn222AuditEventCatalogTest.php \
  tests/Feature/AuthRbacAuditHardeningTest.php \
  tests/Unit/AuditLoggerWritePolicyTest.php \
  tests/Feature/FoundationAuthRbacAuditTest.php \
  tests/Feature/SecurityRbacAuditNegativeTest.php
```

Kết quả:

```json
{"tool":"phpunit","result":"passed","tests":36,"passed":36,"assertions":276,"duration_ms":17429}
```

4/4 test case mới của `Wwhn222AuditEventCatalogTest.php` PASS:

- `test_success_login_shares_same_request_id_across_response_and_audit_log`
- `test_business_error_login_shares_same_request_id_and_carries_error_code_without_raw_pii`
- `test_server_error_path_audit_write_failure_is_logged_with_same_request_id`
- `test_request_id_falls_back_to_current_request_when_caller_forgets_to_pass_it`

32 test case sẵn có ở 4 file kia (audit redact secret, audit outbox fail-closed,
RBAC/data-scope, negative security) **không có regression** sau khi đổi chữ ký
`AuditLogger::record()`/`log()` và mở rộng `config/audit.php`.

## Run 2 — sweep rộng hơn nhóm Auth (kiểm tra không phá test khác dùng AuthController)

```
php vendor/bin/phpunit --filter "Auth" tests/Feature tests/Unit
```

Kết quả:

```json
{"tool":"phpunit","result":"passed","tests":52,"passed":51,"assertions":483,"duration_ms":18021,"skipped":1}
```

0 fail. 1 skip là pre-existing (không liên quan thay đổi của phiên này — không có
test nào trong 2 file mới/sửa bị skip).

## Ghi chú vận hành khi chạy

Giữa hai lần chạy trên, một phiên Claude song song khác chạy `composer install`
trên cùng working tree khiến `vendor/autoload.php`/`vendor/bin/phpunit` biến mất
tạm thời (đúng bẫy đã ghi nhận trước đó cho repo này) — không liên quan tới thay
đổi của WWHN-222. Hai run trên đều chạy **trước** khi hiện tượng này xảy ra nên
không bị ảnh hưởng.

## Giới hạn

Đây là evidence **local**, không phải run trên UAT. Run trên UAT phụ thuộc
DEV222-P1-01 (DevOps pin log sink/environment) — ngoài phạm vi sửa code của
phiên này.

---

# R2 — PostgreSQL execution proof + vocabulary fix (2026-08-19)

Chạy tại worktree HEAD `074a11ff` (m1), sau các sửa đổi R2 (AuthController vocabulary,
migration `2026_08_19_150000`, test DENIED mới). PostgreSQL 16.14, DB **cô lập**
`hoa_nam_wwhn222_r2` (tạo mới bằng `createdb` + `migrate --force` — không đụng DB
`hoa_nam_audit` dùng chung giữa các phiên). Không dùng CI/GitHub Actions.

## Run R2-1 — migrate từ scratch trên PG (exact execution proof cho migration)

```
php artisan migrate --force   # DB_CONNECTION=pgsql DB_DATABASE=hoa_nam_wwhn222_r2
...
 2026_08_19_000001_add_event_catalog_fields_to_audit_log .. 4.00ms DONE
 2026_08_19_000002_fix_audit_log_pgsql_view_missing_event_catalog_fields .. 25.77ms DONE
...
 2026_08_19_150000_align_audit_result_vocabulary_with_erd .. 23.94ms DONE
```

(Migration `150000` chạy ở lần migrate thứ hai sau khi thêm file — cả hai lần đều 0 lỗi.)

## Run R2-2 — phpunit trên pgsql (trước đây review chỉ ra là chưa từng có)

```
php vendor/bin/phpunit tests/Feature/Wwhn222AuditEventCatalogTest.php \
  tests/Feature/AuthRbacAuditHardeningTest.php tests/Unit/AuditLoggerWritePolicyTest.php \
  tests/Feature/FoundationAuthRbacAuditTest.php tests/Feature/SecurityRbacAuditNegativeTest.php
```

- Trước vocabulary fix (baseline, cùng DB): `{"result":"passed","tests":36,"passed":36,"assertions":276,"duration_ms":42290}`
- Sau vocabulary fix (+1 test DENIED): `{"result":"passed","tests":37,"passed":37,"assertions":279,"duration_ms":42380}`

## Run R2-3 — sqlite đối chứng sau fix

- 5 file như trên: `{"result":"passed","tests":37,"passed":37,"assertions":279,"duration_ms":3225}`
- Sweep `--filter "Auth"` tests/Feature tests/Unit: `{"result":"passed","tests":53,"passed":52,"skipped":1,"assertions":492}` — 0 fail, 1 skip pre-existing (không đổi so với R1).

## Run R2-4 — probe raw trên PG

Event sample + retrieval theo request_id + append-only 4 lớp + error-path
AUDIT_WRITE_FAILED/outbox + vocabulary CHECK: raw output đầy đủ ở `OPS_UAT_HANDOFF.md`.

## Ghi chú vận hành R2

- Sweep `--filter "Auth"` kích hoạt một phần `Wwhn289SecurityRegressionProofTest` làm
  `build/evidence/wwhn-289/security-regression-proof.json` bị regenerate thiếu section.
  Đã khôi phục bằng `git checkout --` trước khi commit — file đó không thuộc WWHN-222.
- HEAD trôi trong lúc chạy (9a8481f → 074a11f, phiên song song đóng WWHN-231 docs) —
  đã kiểm `git diff 879c970..HEAD -- <tập file WWHN-222>`: ngoài 2 commit của candidate
  (`879c9704`, `41dfa6f7`), không commit nào khác chạm tập file pin.
