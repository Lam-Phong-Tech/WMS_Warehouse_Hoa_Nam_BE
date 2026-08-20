# WWHN-209 — BE Authorization Runtime Evidence (Review 14186 · P1-01 + P1-03)

Ngày chạy: 2026-08-20 · Người chạy: session dev local (thay CI theo DEC-325-CI-LOCAL-01 — quota GitHub Actions 3000/3000, không phát sinh run mới).

## 1. Phạm vi

Đóng hai hạng mục P1 của review comment 14186 (Jira WWHN-209):

- **P1-01 (QA1)**: security/RBAC/audit runtime — 401 / 403 / token expired / permission / data-scope / audit tại đúng build/SHA.
- **P1-03 (BE1 + QA1)**: backend authorization phải được chứng minh **độc lập bằng direct API call trả 403**, không dựa vào FE hide/redirect.

## 2. Candidate & run identity

| Mục | Giá trị |
|---|---|
| Base HEAD lúc bắt đầu | `7da26cb1cc13df58537c5ff8556070ad8529ed4d` |
| Candidate (commit-cuối-chạm-artifact) | `b40cbdaee9d39d9cef80f9c944780c2048d1d0e7` — thêm `tests/Feature/Wwhn209OutboundSecurityRbacAuditTest.php` |
| Nhánh | `m1` |
| Disclosure | Working tree lúc chạy có thay đổi **chưa commit của phiên song song** (deny-audit WWHN-165: `CheckPermission.php`, các `Enforce*WarehouseScope`, `DenyAuditRecorder`). Suite WWHN-209 được chạy lại sau khi các thay đổi đó xuất hiện và vẫn 5/5 PASS — assertion của suite chỉ khoá status/error-code/state-unchanged nên trung lập với deny-audit. |

## 3. Bốn khoảng trống coverage đã bịt (so với OutboundPermissionTest / Wwhn217RuntimeErrorContractTest)

1. **Token expired 401 trên chính endpoint outbound** — trước đây chỉ có test expired trên `/auth/me` (`AuthRbacAuditHardeningTest`).
2. **401/403 trên bề mặt chưa từng có negative test**: `POST .../unmatch`, alias Annex C `POST .../scans`, `POST .../reversals`, `PUT .../items/{itemId}/rfid`, và cả 4 packing-label operations (`preview/ready/print/reprint`).
3. **`DATA_SCOPE_DENIED` runtime trên outbound** — `EnforceOutboundWarehouseScope` trước đây **không có test nào** (grep tests/ = 0 hit); chỉ được suy ra từ inbound/component-issue/reversal. Actor có đủ permission (`outbound.view/scan/post/cancel`, `physical_code.assign_rfid`, `packing_label.print`) nhưng `user_role.scope_ref` trỏ kho khác bị 403 `DATA_SCOPE_DENIED` trên đọc lẫn ghi; DRAFT giữ nguyên, `stock_movements` = 0, `scanned_qty` = 0, `available_qty` không đổi (SRS §8.2).
4. **Positive control**: cùng cơ chế `scope_ref` trỏ đúng kho canonical thì qua gate bình thường — chứng minh cú deny không phải false-positive.

## 4. Kết quả chạy

### 4.1 SQLite in-memory (host Windows, PHP 8.3.30)

```
php artisan test --filter=Wwhn209OutboundSecurityRbacAuditTest
→ 5/5 PASS (50 assertions)

php artisan test --filter='Wwhn209OutboundSecurityRbacAuditTest|OutboundPermissionTest|SecurityRbacAuditNegativeTest|Wwhn217RuntimeErrorContractTest|PostIssueAuditLogTest|AuthRbacAuditHardeningTest'
→ 40/40 PASS (333 assertions)
```

### 4.2 PostgreSQL 16 cô lập (postgres:16-alpine, backend container PHP 8.3.33)

DB riêng `wms_hoanam_testing_wwhn209` để không giẫm run PG của phiên song song:

```
docker exec <postgres> psql -U postgres -c "CREATE DATABASE wms_hoanam_testing_wwhn209;"
sed 's/wms_hoanam_testing/wms_hoanam_testing_wwhn209/' phpunit.pgsql.xml > phpunit.pgsql.wwhn209.xml

docker exec <backend> php artisan test -c phpunit.pgsql.wwhn209.xml \
  --filter='Wwhn209...|OutboundPermissionTest|...'         → 9/9 PASS (94 assertions)
  (chỉ Wwhn209OutboundSecurityRbacAuditTest + Wwhn217RuntimeErrorContractTest được discover)

docker exec <backend> vendor/bin/phpunit -c phpunit.pgsql.wwhn209.xml \
  tests/Feature/OutboundPermissionTest.php \
  tests/Feature/SecurityRbacAuditNegativeTest.php \
  tests/Feature/PostIssueAuditLogTest.php \
  tests/Feature/AuthRbacAuditHardeningTest.php              → 31/31 PASS (239 assertions)
```

**Tổng PG = 40/40 PASS — khớp 1-1 với sqlite.**

> **Ghi chú discovery (bẫy môi trường, không phải lỗi test):** trong container Linux, PHPUnit 12 quét `tests/Feature` theo thứ tự readdir (không alphabet như NTFS); một file contract iterate sớm autoload trước nhiều class test khác khiến `require_once` no-op và class bị bỏ qua khỏi suite → `--filter` "No tests found" dù file tồn tại. Workaround chuẩn: truyền **đường dẫn file trực tiếp** cho phpunit (đã dùng ở trên, discover đủ). Trên host Windows không bị.

## 5. Ma trận P1-01 → test

| Yêu cầu review | Test | Kết quả |
|---|---|---|
| 401 unauthenticated | `Wwhn209...::test_unauthenticated_requests_are_rejected_on_untested_outbound_surfaces` + `OutboundPermissionTest::test_unauthenticated_request_is_rejected_with_401` | PASS sqlite+PG |
| Token expired | `Wwhn209...::test_expired_access_token_is_rejected_with_401_on_outbound_surface` (TTL=1, Carbon +2min, guard reset) | PASS sqlite+PG |
| 403 permission (direct API) | `Wwhn209...::test_viewer_receives_403_access_denied_on_label_scan_and_rfid_surfaces` + toàn bộ `OutboundPermissionTest` (VIEWER/STAFF/APPROVER/SUPER_ADMIN matrix) | PASS sqlite+PG |
| 403 data-scope (direct API) | `Wwhn209...::test_foreign_scope_actor_with_permissions_receives_403_data_scope_denied` + positive control | PASS sqlite+PG |
| Audit runtime | `PostIssueAuditLogTest` (POST_ISSUE/OUTBOUND_IMPORT_CONSUME/cancel-reason, idempotent replay không nhân đôi) + `SecurityRbacAuditNegativeTest::test_auth_lifecycle_creates_audit_logs` | PASS sqlite+PG |
| Deny không đổi state/stock/movement (§8.2) | assertions trong cả 2 test 403 của Wwhn209 (version/scanned_qty/stock_movements/available_qty) + `Wwhn217RuntimeErrorContractTest` | PASS sqlite+PG |

## 6. Kết luận

- **P1-03 đóng**: 403 được chứng minh bằng direct HTTP call ở tầng API (ACCESS_DENIED lẫn DATA_SCOPE_DENIED), hoàn toàn không phụ thuộc FE; có positive control chống false-positive.
- **P1-01 đóng phần BE**: đủ 401/403/token-expired/permission/data-scope/audit ở runtime trên cả hai driver, trong đó PG 16 là driver nghiệm thu (README §4).
