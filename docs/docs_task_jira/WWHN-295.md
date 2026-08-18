# WWHN-295 — BE runtime / config / health remediation evidence

Ngày: 2026-08-18, cập nhật vòng 2 ngày 2026-08-19 (ICT)
Nhánh: `m1` (không tạo nhánh riêng)

**Exact candidate SHA (canonical): `7207feac7a4087680e69db609047729d08a5419e`**
— đây là SHA duy nhất được dùng cho mọi gate/bằng chứng của WWHN-295. Commit
`9aa2231acecb24e3a6be426e7d872b9c87ba5683` là vòng 1, đã bị SUPERSEDE (xem §7).

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

`tests/Feature/Wwhn295RuntimeConfigHealthTest.php` — **16/16 pass** tại candidate
`7207fea` (13 test vòng 1 + 3 test `--expect-sha` của vòng 2, xem §7):

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

Lệnh đã chạy tại candidate `7207feac7a4087680e69db609047729d08a5419e` (sqlite,
driver mặc định của `phpunit.xml`):

```bash
php vendor/bin/phpunit --filter="Wwhn295HealthContractTest|Wwhn295RuntimeConfigHealthTest|NoStoreApiResponsesTest|BackendMigrationSeedHealthTest|AuthSessionPersistenceTest"
```

Kết quả: **35/35 pass, 345 assertions**. `vendor/bin/pint --test` sạch trên toàn
bộ file đã sửa.

Ghi rõ để không ai đọc nhầm: `Wwhn300BackendGoLiveCheckTest` **đang đỏ trên `m1`**
tại thời điểm này vì migration `2026_08_19_000050_repair_erd_mirror_projection_casts`
của công việc song song chưa có disposition trong `database/golive/fail_forward_allowlist.php`
(`migrations.reversible` → `undispositioned`). Lỗi đó không liên quan thay đổi của
WWHN-295 (không file nào của W295 nằm trong đường dẫn gây lỗi) nhưng **vẫn chặn**
gate cutover của WWHN-300 cho tới khi chủ sở hữu migration đó xử lý — không được coi
là "đã xanh" khi ký.

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

## 7. Vòng 2 — đóng register 73/100 (DEV295-P1-01 … DEV295-P2-01)

### 7.1 Exact candidate và đối chiếu SHA (DEV295-P1-01)

| Commit | Vai trò | Nội dung | Trạng thái |
| --- | --- | --- | --- |
| `9aa2231acecb24e3a6be426e7d872b9c87ba5683` | Vòng 1 | Trusted proxy, `data.build.*`, cửa sổ retry queue, lock cache DB, 3 chiều mới của `wms:golive-check` | **SUPERSEDED** — không dùng làm candidate nữa (lý do ở 7.2) |
| `671cc4c` (WWHN-300) | Commit của task khác chạm file W295 | Sửa `app/Console/Commands/BackendGoLiveCheck.php` + `.env.example` (readiness fail-closed) | Đã hợp nhất; ba chiều của W295 (`release.build_identity`, `runtime.queue_retry_window`, `security.runtime_config`) còn nguyên tại candidate |
| `e593202` (WWHN-289) | Commit của task khác chạm file W295 | Sửa `.env.example` (rate limit) | Đã hợp nhất |
| `bd5b5d677fe8f528e872988ff843a1e69f593683` | Vòng 2 — thay đổi contract/runtime | 13 file: contract health tường minh (OpenAPI + Postman cùng revision), một nguồn sự thật `Cache-Control`, `wms:golive-check --expect-sha`, gate CI riêng, verifier | Nằm trong candidate |
| `8429b9b52557db5bf6d2323795ddaef567cef911` | Vòng 2 — sửa gate | Verifier báo sai "candidate không tồn tại" (`git cat-file -e` không có output) và đóng băng nhầm ba artifact dùng chung | Nằm trong candidate |
| **`7207feac7a4087680e69db609047729d08a5419e`** | **Exact candidate canonical** | Pin hash theo blob TẠI candidate thay vì working tree. Là commit **cuối cùng** chạm source của WWHN-295 | **CANDIDATE** |

Vì sao không giữ `9aa2231` làm candidate: sau nó, hai commit của WWHN-300/WWHN-289
đã sửa chính file mà WWHN-295 sở hữu, nên "chạy lại bằng chứng tại `9aa2231`" và
"đo backend đang chạy trên `m1`" là hai artifact khác nhau. Pin một SHA đã bị nhánh
vượt qua chính là pin rot: reviewer đọc số cũ trong khi code đã khác.

**Chống pin rot bằng range invariant.** `scripts/verify-wwhn-295-closure.mjs` không
chỉ ghi SHA vào tài liệu; nó kiểm:

1. candidate tồn tại và là ancestor của HEAD;
2. `git diff candidate..HEAD` trên **toàn bộ file thuộc WWHN-295** phải rỗng — tức
   source chưa trôi kể từ candidate. Ngày nào có người sửa, gate đỏ ngay và buộc pin
   lại, thay vì để tài liệu nói một đằng nhánh chạy một nẻo;
3. working tree không còn thay đổi chưa commit trên các file đó;
4. candidate **không mang theo diff của task khác** (7.3).

### 7.2 Contract công bố cùng revision với runtime (DEV295-P1-02)

Ba lệch thật đã đóng, đều là "chạy vẫn 200" nên không test nghiệp vụ nào thấy:

| Lệch | Hệ quả nếu để nguyên | Đã sửa ở đâu |
| --- | --- | --- |
| OpenAPI chỉ có `200 Ready`/`503 Degraded` trỏ envelope chung, không schema cho `data` | DevOps/QA phải đọc ngược PHP mới biết `build.sha` nằm ở khoá nào ⇒ mọi gate exact-SHA quay về tin lời khai; client không biết nhánh 503 đặt payload ở `errors`/`error.details` (respondError) nên alert đọc nhầm trường và im lặng đúng lúc degraded | `app/Swagger/HealthSchemas.php` (10 component: `HealthPayload`, `HealthBuildIdentity`, `HealthChecks`, 5 check con, `HealthReadyEnvelope`, `HealthDegradedEnvelope`) + attribute tường minh trên `HealthController::show()` |
| Spec publish thêm 400/401/403/404/409/412/422/428/500 cho một endpoint **public, không auth, không If-Match** | Monitor/QA phải đoán trạng thái nào có thật; 428/412 gợi ý sai rằng health có optimistic locking | `x: ['exhaustive-responses' => true]` + `RouteSyncProcessor::declaresExhaustiveResponses()`. Opt-out **có kiểm soát**: mặc định mọi endpoint khác giữ nguyên baseline |
| Postman DEV gọi `{{baseUrl}}/api/health` — route **không tồn tại** (`routes/api.php` chỉ đăng ký `v1/health`); ví dụ response rỗng; không có test | QA chạy collection nhận 404 nhưng báo cáo vẫn ghi "đã chạy health"; không trường readiness nào được kiểm | Thư mục `00. Platform` viết lại: path canonical, 2 ví dụ 200/503 đúng envelope thật, test script bám đúng `HealthPayload.required` và bắt buộc biến `expectedBuildSha` |

Ngoài ra, `Cache-Control` của health từng được khai ở hai nơi với hai giá trị khác
nhau: controller set `no-store, no-cache, must-revalidate, private` còn middleware
`NoStoreApiResponses` (chạy ngoài cùng, luôn ghi đè) trả `no-store, private`. Client
thực nhận giá trị thứ hai. Nay chỉ còn một hằng số `NoStoreApiResponses::CACHE_CONTROL`
và spec/Postman khai đúng giá trị đó.

**Pin hash.** `docs/evidence/WWHN-295/contract-lock.json` ghi:

- `slices` — sha256 canonical của operation health, của 10 schema `Health*`, và của
  thư mục `00. Platform`. Đây là gate cứng chạy trên working tree: regenerate kiểu gì
  cũng phải giữ đúng contract, nhưng KHÔNG đỏ oan khi task khác thêm endpoint mới.
- `artifacts` — sha256 toàn file `storage/api-docs/api-docs.json|yaml` và collection
  DEV, được xác minh trên **blob tại chính candidate** (`git show <candidate>:<path>`),
  nên con số này bất biến theo thời gian và không rot.

### 7.3 Diff của WWHN-296 bị gộp trong vòng 1 (DEV295-P1-03)

`9aa2231` có kèm `tests/Feature/Wwhn296BrowserRuntimeContractTest.php` — file thuộc
WWHN-296 (FE build/runtime). Disposition: **giữ nguyên tại chỗ, quyền sở hữu thuộc
WWHN-296**; WWHN-295 không ký cho nội dung đó và không xoá nó khỏi lịch sử (viết lại
history trên `m1` sẽ phá pin của các task khác).

Vòng 2 sạch: `bd5b5d6` chạm đúng 13 file, `8429b9b` và `7207fea` mỗi commit chạm đúng
1 file — tất cả đều thuộc phạm vi WWHN-295, không có file của task khác. Verifier kiểm
điều này bằng máy (`OWNED_PATHS`), nên "candidate không mang diff lạ" là dữ kiện kiểm
được chứ không phải lời hứa trong mô tả. Và vì `git diff <candidate>..HEAD` trên toàn
bộ source W295 phải rỗng, ba commit này không thể bị "trôi" thêm nội dung sau lưng
người ký.

CI riêng: `.github/workflows/wwhn-295-runtime-config-health.yml` chạy pint, sinh lại
OpenAPI rồi bắt `git diff --exit-code storage/api-docs` (chứng minh spec đã commit
đúng là bản sinh từ source này), chạy bộ test W295, chạy verifier, và upload artifact
`wwhn-295-source-gate-<sha>` kèm `SHA256SUMS`. Run ID của workflow này chính là bằng
chứng "green CI trên exact candidate" mà register yêu cầu — **phải push candidate lên
origin để có run ID; chưa push thì gate Build vẫn là MISSING.**

### 7.4 Mapping source → test → build

| Nguồn | Test khoá | Build/gate |
| --- | --- | --- |
| `app/Http/Controllers/HealthController.php`, `app/Swagger/HealthSchemas.php` | `Wwhn295HealthContractTest::test_ready_payload_matches_the_published_schema_in_both_directions`, `..._degraded_payload_lives_in_errors_and_error_details_not_in_data` | `php artisan l5-swagger:generate` → `storage/api-docs/api-docs.json|yaml` (pin trong contract-lock) |
| `app/Swagger/Processors/RouteSyncProcessor.php` | `..._openapi_publishes_only_the_two_outcomes_health_can_actually_return` | như trên |
| `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | `..._postman_collection_ships_the_canonical_health_request_at_the_same_revision` | `node scripts/verify-wwhn-295-closure.mjs` |
| `app/Http/Middleware/NoStoreApiResponses.php` | `NoStoreApiResponsesTest`, hai assert `Cache-Control` trong contract test | — |
| `app/Console/Commands/BackendGoLiveCheck.php` (`--expect-sha`) | `Wwhn295RuntimeConfigHealthTest::test_golive_check_fails_when_the_running_build_is_not_the_pinned_candidate` (+2 test cùng nhóm) | `scripts/collect-wwhn295-runtime-evidence.sh` trên môi trường deploy |
| `config/trustedproxy.php`, `bootstrap/app.php`, `docker/nginx/default.conf` | 4 test proxy/nginx vòng 1 | `wms:golive-check` → `security.runtime_config` |
| `config/queue.php`, `docker-compose.yml` | 2 test cửa sổ retry | `wms:golive-check` → `runtime.queue_retry_window` |

## 8. Runbook cho DevOps (DEV295-P1-04) và QA1 (DEV295-P1-05)

Nguồn không tạo được các giá trị chỉ tồn tại trên môi trường thật. Hai lệnh dưới đây
sinh ra đúng bộ artifact mà register đòi, và **tự fail nếu artifact đang chạy không
phải candidate** — nên không thể vô tình nộp bằng chứng của một build khác.

```bash
# DevOps, chạy TRÊN container ứng dụng của UAT/PROD
EXPECTED_SHA=7207feac7a4087680e69db609047729d08a5419e \
API_BASE_URL=https://<uat-host>/api/v1 ENVIRONMENT=uat \
  bash scripts/collect-wwhn295-runtime-evidence.sh
# → docs/evidence/WWHN-295/runtime/uat-7207feac/{health-body.json,health-headers.txt,
#    health-summary.json,golive-check.json}
```

```bash
# QA1, smoke FE↔BE đã deploy (repo FE)
EXPECTED_BACKEND_SHA=7207feac7a4087680e69db609047729d08a5419e \
  yarn smoke:wwhn-156 --env=uat --fe-base-url=https://<fe-host> \
  --api-base-url=https://<uat-host>/api/v1
```

Đối chiếu bắt buộc khi ký:

1. `health-summary.json → observed.build.sha` **bắt đầu bằng** exact candidate ở §7.1;
2. `golive-check.json → checks["release.build_identity"].detail.matches_expected = true`;
3. `checks["security.runtime_config"]` không có finding `trusted_proxies` / `log_stack`
   / `log_level` / `app_debug`;
4. `checks["runtime.queue_retry_window"].status = "ok"` với `worker_timeout` đúng bằng
   giá trị worker thật đang chạy;
5. `health-headers.txt` có `Cache-Control: no-store, private`;
6. Response không chứa stack trace/secret — `health-body.json` chỉ có các khoá đã khai
   trong `components.schemas.HealthPayload`.

Thiếu bất kỳ mục nào thì trạng thái đúng vẫn là **EXTERNAL DEPLOY+QA GATES PENDING**.
`DEV295-P1-06` (WWHN-79) chỉ đóng khi backup restore-tested, monitoring/alert, rollback
và Go/No-Go có bằng chứng riêng của chủ sở hữu — WWHN-295 không ký thay.

## 9. Trạng thái register sau vòng 2

| ID | Phần WWHN-295 sở hữu | Trạng thái tại `7207fea` |
| --- | --- | --- |
| DEV295-P1-01 | Pin canonical candidate + reconcile SHA + mapping source→test→build | **ĐÓNG** (§7.1, §7.4; verifier giữ range invariant) |
| DEV295-P1-02 | Schema/envelope 200+503 tường minh, OpenAPI + Postman cùng revision, pin hash | **ĐÓNG** (§7.2; `contract-lock.json`) |
| DEV295-P1-03 | CI xanh trên exact candidate, xử lý diff WWHN-296 bị gộp | **ĐÓNG Ở NGUỒN** — workflow + disposition đã có; run ID chỉ tồn tại sau khi push (§7.3) |
| DEV295-P1-04 | Cấu hình/deploy thật | **PENDING DevOps** — runbook + gate `--expect-sha` đã sẵn (§8) |
| DEV295-P1-05 | QA trên bản đã deploy | **PENDING QA1** — lệnh smoke buộc SHA đã sẵn (§8) |
| DEV295-P1-06 | Go/No-Go cha WWHN-79 | **PENDING** — ngoài phạm vi WWHN-295 |
| DEV295-P2-01 | Narrative vai trò BE1 → DEV | **PENDING PM** — chỉ sửa metadata Jira, không đụng code |
