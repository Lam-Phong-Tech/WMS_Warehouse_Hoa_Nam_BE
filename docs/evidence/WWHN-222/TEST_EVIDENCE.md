# WWHN-222 — Test evidence (local, sqlite)

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
