# WWHN-295 — BE runtime / config / health remediation evidence

Ngày: 2026-08-18 (ICT)
Nhánh: `m1` (không tạo nhánh riêng)
Commit khắc phục: `9aa2231acecb24e3a6be426e7d872b9c87ba5683`

## 1. Phạm vi và ranh giới sự thật

WWHN-295 sở hữu **cấu hình/runtime/health của backend ở tầng source**: readiness
contract của `/api/v1/health`, biên trusted proxy, hồ sơ log/queue/cache runtime,
và các gate tự động chặn cutover khi cấu hình lệch.

Ticket này **không** tự ký:

- hạ tầng production, giá trị env thực tế, TLS/CORS/rollback → `WWHN-294` (DevOps);
- bằng chứng security/health chạy trên môi trường đã deploy → `WWHN-297` (QA1);
- FE build/runtime → `WWHN-296`;
- Go/No-Go cha → `WWHN-79`.

## 2. Register P0/P1/P2

Bốn khiếm khuyết dưới đây đều thuộc loại **"chạy vẫn trả 200"**: không test nghiệp
vụ nào nhìn thấy vì chúng chỉ biểu hiện khi có reverse proxy / worker thật / nhiều
môi trường — đúng loại rủi ro mà WWHN-295 tồn tại để chặn.

| ID | Sev | Nguyên nhân gốc | Khắc phục | Trạng thái |
| --- | --- | --- | --- | --- |
| W295-P1-01 | P1 | **Không khai trusted proxy.** BE luôn chạy sau nginx (`docker-compose.yml`) và TLS terminator của DevOps. `Illuminate\Http\Middleware\TrustProxies` không được cấu hình nên bỏ qua toàn bộ `X-Forwarded-*`: `$request->ip()` trả về IP container nginx — chính giá trị `RefreshTokenService` ghi vào `refresh_tokens.ip_address`, làm trường bằng chứng phiên đăng nhập mất giá trị điều tra; `$request->secure()` trả về `false` sau TLS terminator. Đồng thời `docker/nginx/default.conf` **không sinh** `X-Forwarded-*` nào cho FastCGI, nên kể cả có khai trusted proxy cũng không có gì để đọc. | `config/trustedproxy.php` (mặc định loopback + RFC1918/ULA — không bao giờ trùng IP client public nên header giả mạo bị bỏ qua); `bootstrap/app.php` giới hạn đúng 4 header `X-Forwarded-For/Host/Port/Proto`; nginx forward cả 4 param, giữ nguyên giá trị proxy ngoài gửi qua `map`, chỉ sinh giá trị thay thế khi header trống. | Đóng ở source |
| W295-P1-02 | P1 | **`/api/v1/health` không công bố danh tính artifact.** `config/health.php` trả `version` từ `APP_VERSION` mà biến này không có trong `.env.example` và bị CI hard-code `'1.0.0'` → không đọc được commit đang chạy từ chính backend. Mọi gate exact-SHA (`WWHN-273/290/296/300`) vì thế phải tin lời khai trong mô tả deploy. | `data.build.{sha,ref,built_at}` trong response health; `APP_BUILD_SHA/REF/TIME` khai trong `.env.example`; ba workflow CI inject `${{ github.sha }}` / `$GITHUB_SHA`. | Đóng ở source |
| W295-P1-03 | P1 | **Cửa sổ retry của queue bằng đúng timeout của worker.** `retry_after` = 90 và worker chạy `--timeout=90`. Job chạy sát ngưỡng bị queue release lại đúng lúc worker đầu vẫn đang xử lý → worker thứ hai nhận **lại** chính job đó. Với audit outbox và reconcile tồn kho, chạy hai lần là bản ghi audit trùng / số liệu đối soát cộng dồn. | `config/queue.php` suy `retry_after = QUEUE_WORKER_TIMEOUT + 30` cho cả ba driver có vòng release; `docker-compose.yml` truyền đúng biến đó vào `--timeout`, hai giá trị không thể lệch do sửa một chỗ quên chỗ kia. | Đóng ở source |
| W295-P2-01 | P2 | **Lock cache nằm sai Valkey DB.** `cache.stores.redis.lock_connection` mặc định `'default'` (DB 0) trong khi `.env.example` và `ClearWmsCache` khẳng định DB 0 chỉ có session/queue, DB 1 mới là application cache. Lock của `CacheService::rememberStampede` vì thế nằm lẫn với session/queue. | `lock_connection` mặc định `'cache'`. | Đóng ở source |

Ngoài ra `security.runtime_config` của `wms:golive-check` nay chặn thêm hai cấu
hình dev sót lại ngoài local/testing: `LOG_STACK=single` (log không xoay vòng →
đầy đĩa là sự cố runtime) và `LOG_LEVEL=debug` (ghi payload request vào log).

## 3. Gate tự động chống tái diễn

`php artisan wms:golive-check` nhận thêm ba chiều:

| Check | Chặn điều gì |
| --- | --- |
| `release.build_identity` | Ngoài local/testing, thiếu `APP_BUILD_SHA` là **fail** — bằng chứng cutover không buộc được vào commit nào. |
| `runtime.queue_retry_window` | `retry_after <= worker_timeout` là **fail**. Driver không có vòng release (`sync`) được ghi rõ là N/A thay vì bỏ qua im lặng. |
| `security.runtime_config` | Bổ sung trusted proxy trống, `LOG_STACK=single`, `LOG_LEVEL=debug` ngoài local/testing. |

## 4. Bằng chứng kiểm chứng

`tests/Feature/Wwhn295RuntimeConfigHealthTest.php` — **13/13 pass**:

- `test_refresh_token_evidence_records_the_real_client_ip_behind_the_proxy` —
  end-to-end qua `/api/v1/auth/login`: `refresh_tokens.ip_address` ghi
  `203.0.113.9` (IP người dùng) thay vì `172.20.0.5` (IP proxy).
- `test_forged_forwarded_headers_from_an_untrusted_peer_are_ignored` — client
  public tự bịa `X-Forwarded-For` vẫn bị bỏ qua.
- `test_health_publishes_the_running_build_identity` + `..._as_null_instead_of_a_fabricated_sha`.
- `test_queue_retry_window_is_wider_than_the_worker_timeout` +
  `test_golive_check_fails_when_the_queue_retry_window_is_too_narrow`.
- `test_cache_locks_share_the_application_cache_database`.
- Ba test pin cấu hình hạ tầng (`docker/nginx/default.conf`, `docker-compose.yml`,
  `.env.example`) để lần refactor sau không lặng lẽ gỡ bỏ.

Lệnh đã chạy trên commit này:

```bash
php artisan test --filter="Wwhn295RuntimeConfigHealthTest|Wwhn300BackendGoLiveCheckTest|BackendMigrationSeedHealthTest|AuthSessionPersistenceTest"
```

Kết quả: **29/29 pass, 209 assertions**. Toàn bộ suite trước đó: 711 test,
688 pass, 22 skip, 1 fail — lỗi duy nhất là `Wwhn309PermissionCatalogEvidenceTest`
thuộc công việc WWHN-309 đang làm dở song song trên `m1`, không liên quan thay đổi
này. `./vendor/bin/pint --test` sạch trên toàn bộ file đã sửa.

## 5. Việc DevOps/QA phải làm trên môi trường thật

Nguồn không tự tạo ra được các giá trị sau, và không được bịa:

1. `TRUSTED_PROXIES` đúng dải của reverse proxy thực tế (nếu khác RFC1918).
2. `APP_BUILD_SHA` / `APP_BUILD_REF` / `APP_BUILD_TIME` inject lúc build image;
   kiểm tra bằng `GET /api/v1/health` → `data.build.sha` khớp commit đã deploy.
3. `LOG_STACK=daily`, `LOG_LEVEL=info` cho UAT/production.
4. `QUEUE_WORKER_TIMEOUT` khớp giữa cấu hình worker thật và `config/queue.php`.
5. Chạy `php artisan wms:golive-check --json=<evidence>` **trên chính môi trường
   deploy** và đính kèm artifact.

Chừng nào chưa có các bằng chứng độc lập đó, trạng thái đúng là **BE SOURCE
REMEDIATED / EXTERNAL DEPLOY+QA GATES PENDING**, không phải PASS production.

## 6. Ghi chú đối chiếu

Register ở §2 dựng từ audit source đối chiếu `HN_SRS_FRD_v1.12_FULL.md`,
`HoaNam_ERD_Moi_Nhat.md`, `HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html`
và `WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`, cộng với ranh giới phụ thuộc
đã ghi trong `WWHN-296.md`. Khi mở được nội dung review mới nhất trên Jira, phải
đối chiếu lại: mục nào reviewer nêu mà không có ở đây thì bổ sung, không đóng gate
dựa trên register này một mình.
