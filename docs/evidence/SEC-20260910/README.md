# SEC-20260910 — bằng chứng runtime (P0/P1 bảo mật)

Đo ngày 10/09/2026 trên máy chủ BE chạy thật trong container cô lập, không mock:
- image `wms_warehouse_hoa_nam_be-backend` (PHP 8.3.33);
- CSDL PostgreSQL riêng `wms_sec_runtime_test` (không đụng CSDL dev), migrate + `AuthDatabaseSeeder` ở `APP_ENV=testing`;
- mật khẩu fixture sinh ngẫu nhiên trong container, không in ra;
- `php artisan serve` với 8 worker.

Mã được đo: commit `e5dbe0d` (probe/ma trận commit sau ở `a035750`/`78f90ed`, không đổi mã ứng dụng).

| Tệp | Nội dung | Kết quả |
|---|---|---|
| `p1-refresh-runtime-probe.{json,txt}` | `scripts/sec-p1-refresh-runtime-probe.php`: cookie HttpOnly/Path/SameSite, login CSRF, refresh CSRF 403 không kết thúc phiên, body token 422, kênh body tắt, replay thu hồi cả họ, race 8 request, CORS preflight, logout thu hồi refresh + access token, header API | **PASS 32/32** (race: 1×200, 7×401) |
| `rbac-runtime-matrix.{json,txt}` | `scripts/sec-rbac-runtime-matrix.php`: 77 route GET × 13 vai trò = 1001 request qua HTTP | **PASS 0 FAIL, 0 WARN**; NO_PERMISSION bị 403 ở 74/77 (3 route tự phục vụ: `audit-logs/me`, `notifications`, `notifications/unread-count`) |
| `input-api-runtime-probe.txt` | đầu vào sai kiểu/SQLi-like/quá dài/JSON hỏng ở login; token trong query, chữ ký sửa, `alg=none`; filter/sort injection; payload user không hợp lệ | **PASS 13/13** |
| `security-suites-postgresql16.txt`, `security-suites-sqlite.txt` | 14 suite bảo mật của cổng deploy CI, chạy trên PostgreSQL 16 dùng một lần và trên SQLite, tại `b867a1e` (sau bản sửa kiểm VIEW) | **OK 146 test / 1028 assertion trên cả hai driver.** Trước bản sửa, pgsql đỏ 7 test `WwhnSecSuperAdminBootstrapTest`. |
| `refresh-latency-ab.json` | độ trễ refresh: bản mới (cookie) xen kẽ bản cũ `7a99426` (body), 3 lượt × 960 request | Không phát hiện chênh lệch vượt nhiễu. Trung vị p50: mới 729 ms, cũ 717 ms; trung vị p95: mới 1643 ms, cũ 1538 ms. Máy đang chạy song song 4 bộ PHPUnit, nhiễu ±40%. **Không phải số năng lực production.** |

Tái lập: `docs/security/SEC-20260910-P1-REFRESH-TOKEN-CONTRACT.md` §8 và docblock của hai script.
