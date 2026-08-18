# WWHN-309 — [BE] Hoàn thiện tài liệu kiến trúc / API / vận hành

Cập nhật: 2026-08-19
Repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
Nhánh thực hiện: `m1`
Candidate SHA đóng task: xem `docs/evidence/WWHN-309/CANDIDATE_MANIFEST.json` → `candidate_sha`
FE baseline đối chiếu: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE@m1` (pin ở FE `docs/api-evidence/BE_CONTRACT_PIN.json`)
Parent: WWHN-83 · Dependency: HN-HO-001-ST01 · Role: **DEV** (vai trò FE1/BE1 đã hợp nhất; tài liệu cũ ghi `BE1` là dấu vết lịch sử, xem §10)

## 1. Phạm vi và nguồn chuẩn

WWHN-309 là **Backend handover** của parent WWHN-83: kiến trúc, hợp đồng API, RBAC/permission, vận hành (job nền, health, runbook, CI/CD evidence). Không tự ký thay BA/BAS/QA/DevOps và không tự đóng parent WWHN-83.

Nguồn được đọc/đối chiếu:

1. `docs/HN_SRS_FRD_v1.12_FULL.md` — requirement/flow/AC/TC hiện hành.
2. `docs/HN_WMS_Wireframe_MVP_ALIGNED_v3.20_v1.12_20260814.html` — screen/state/UX baseline (bản FE).
3. `docs/HoaNam_ERD_Moi_Nhat.md` — physical schema; xung đột cũ với SRS v1.12 không được dùng để ghi đè requirement mới hơn.
4. `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md` + `docs/HN-BE-001_BACKEND_STANDARD.md` — stack, chuẩn cấu trúc code, quy tắc bảo mật/phân quyền.

Invariants BE không được làm sai:

- MVP không có stocktake/adjustment trực tiếp; correction đi qua **reversal**.
- Không partial posting; inbound/outbound là **full-post** trong một transaction.
- Outbound: tiếp nhận toa → `DRAFT` → scan/match đủ → Packing Label (Preview/Ready/Print + xác nhận đã dán) → **Post Issue**; chỉ Post Issue thành công mới trừ tồn.
- RFID **optional** theo SRS/wireframe v1.12, không phải gate bắt buộc để Post.
- Movement/audit đã post không sửa trực tiếp.
- Backend tự enforce permission **và** data scope; FE ẩn nút không phải cơ chế bảo vệ.
- PostgreSQL là database canonical duy nhất; PASS trên SQLite không phải căn cứ nghiệm thu.

## 2. Vòng 2 — đóng bộ P1/P2 của review 74/100

Review vòng 1 chấm 74/100 với 6 P1 + 2 P2. Điểm chung của cả 6 P1 **không phải thiếu tài liệu** mà là: *lời khẳng định trong tài liệu không bị ràng buộc vào một revision kiểm lại được*. Vòng 2 sửa đúng gốc đó.

| ID | Sev | Nguyên nhân gốc | Cách sửa tận gốc | Trạng thái |
| --- | --- | --- | --- | --- |
| DEV309-P1-01 | P1 | Pin chỉ là một dòng chữ trong Jira; nhánh `m1` đi tiếp thì pin mục mà không ai biết. Commit mang WWHN-309 lại là commit gộp nhiều task | `docs/evidence/WWHN-309/CANDIDATE_MANIFEST.json` pin candidate SHA + SHA-256 của toàn bộ artifact; `scripts/verify-wwhn-309-candidate-pin.mjs` kiểm **range invariant** (không commit nào sau candidate được chạm vào tập artifact) và bắt buộc khai `pull_request` hoặc `independent_review`. §9 quy scope cho commit gộp | CLOSED |
| DEV309-P1-02 | P1 | Số liệu contract sống dưới dạng **văn xuôi** trong 5 tài liệu, không ai kiểm chéo được ⇒ cùng gói bàn giao ghi hai giá trị khác nhau cho cùng phép đếm | `HN-BE-API-004_CONTRACT_METRICS.json\|md` sinh tự động; cổng tổng đối chiếu **mọi** phát biểu số liệu trong tài liệu bàn giao với artifact này, dòng mô tả trạng thái cũ phải đánh dấu `[historical]` | CLOSED |
| DEV309-P1-03 | P1 | Workflow tồn tại nhưng `paths:` không phủ tài liệu contract/OpenAPI ⇒ sửa hợp đồng API mà workflow evidence không hề chạy; và không có chỗ pin run | `paths:` mở rộng phủ toàn bộ artifact bàn giao; thêm step `Candidate pin integrity`; `docs/evidence/WWHN-309/CI_RUN_RECORD.md` là chỗ duy nhất pin run; `scripts/collect-wwhn309-handover-evidence.sh` cho evidence dự phòng | CLOSED (chờ pin run thật) |
| DEV309-P1-04 | P1 | QA1 không có kịch bản retest độc lập nên WWHN-312 kẹt ở Blocked | `docs/evidence/WWHN-309/QA_RETEST_PLAN.md`: 7 nhóm kiểm dương + **3 phép kiểm âm** bắt buộc (chứng minh cổng thật sự chặn chứ không chỉ xanh) | CLOSED (chờ QA1 chạy) |
| DEV309-P1-05 | P1 | Không có nơi ghi nội dung BA/BAS và Tech Lead phải duyệt, nên sign-off dễ thành ký khống | §11 liệt kê đích danh từng điểm cần duyệt; `WWHN-309_SIGNOFF_COMMENTS.md` mỗi vai trò đúng một comment và nói rõ xác nhận cái gì | CLOSED (chờ chủ vai trò post) |
| DEV309-P1-06 | P1 | Yêu cầu vận hành chỉ nằm trong văn xuôi, không có chỗ ghi bằng chứng theo mục | `docs/evidence/WWHN-309/DEVOPS_HANDOVER_VERIFICATION.md`: 10 mục có lệnh kiểm + bằng chứng runtime đã thu sẵn cho 3 mục | CLOSED (chờ DevOps điền UAT/prod) |
| DEV309-P2-01 | P2 | Tài liệu ghi vai trò `BE1` trong khi tổ chức đã hợp nhất về `DEV` | Chuẩn hoá về `DEV`, giữ `BE1` làm dấu vết lịch sử ở §10 | CLOSED |
| DEV309-P2-02 | P2 | `CurrentBaselineIfMatch` là dead code nhưng chỉ được "ghi chú", không có disposition | **Gỡ** theo `DEC-API-IFMATCH-001`; test đổi vai để chặn quay lại; catalog error code giảm 2 mã ma | CLOSED |

## 3. Kết quả remediation vòng 1 (giữ để truy vết)

| Sev | Finding | Xử lý BE | Trạng thái |
| --- | --- | --- | --- |
| P1 | `evidence:permission-catalog` so khớp cả biểu thức `permission:A\|B` như một permission key ⇒ 31 endpoint OR-alias bị trả sai danh sách role, seed-diff FAIL giả | Tách theo `\|` đúng semantics `CheckPermission`, thêm `accepted_permissions`, schema `1.2.0` | CLOSED |
| P1 | Permission catalog publish stale so với seed/route thật `[historical]` | Regenerate từ exporter đã sửa; test chặn stale theo SHA-256 | CLOSED |
| P1 | `warranty:purge-pii` (SRS §13.8 / DR-PRIV-001) chưa từng được lên lịch — PII hết retention không bao giờ bị anonymize | `Schedule::command('warranty:purge-pii')` 03:10, `withoutOverlapping()` + `onOneServer()`; test khoá danh sách job nền | CLOSED |
| P1 | `README.md` vẫn là README mặc định của Laravel | Viết lại theo runtime thật | CLOSED |
| P1 | Handover BE không có execution identity tái lập được | Workflow `WWHN-309 Backend Handover Evidence` | CLOSED |
| P1 | `HN-BE-API-003` báo "operation có If-Match: 0" trong khi runtime abort 412 ở 25 vị trí `[historical]` | Generator dò thêm parameter `If-Match` trong OpenAPI, không chỉ dò middleware | CLOSED |
| P1 | OpenAPI có đủ operation nhưng `components.schemas` rỗng và hầu hết response không có `content` `[historical]` | `App\Swagger\ApiEnvelopeSchemas` + `RouteSyncProcessor` gắn `$ref` cho mọi response | CLOSED |
| P1 | Header contract lệch runtime: 18 call site `IdempotencyKeyGuard::require` nhưng rất ít operation khai header `[historical]` | `RouteSyncProcessor::contractHeaders()` phân tích tĩnh source của action (kèm 1 cấp helper cùng class) | CLOSED |
| P1 | Không có cổng tổng cho gói tài liệu | `scripts/verify-wwhn-309-handover-contract.mjs` fail-closed trên 6 lớp lỗi | CLOSED |
| P1 | `.gitignore` chặn toàn bộ `/docs/` rồi whitelist từng file ⇒ 20 tài liệu nằm ngoài git, reviewer clone đúng SHA không thấy | Bỏ rule; guard `git check-ignore` trong cổng tổng | CLOSED |
| P2 | Markdown permission catalog liệt kê `stocktake.adjust`, `stock_document.*`, `warranty.temp_only` — trái baseline MVP | Viết lại theo permission có thật trong seed/route | CLOSED |
| P2 | Chưa có tài liệu kiến trúc as-built và runbook đứng riêng | `HN-BE-ARCH-001`, `HN-BE-API-001`, `HN-BE-OPS-001` | CLOSED |
| P2 | Changelog OpenAPI v1.0.1 còn ghi `POST /stocktake-sessions/{id}/apply-adjustment` | Giữ làm bản ghi lịch sử; `HN-BE-API-001` §8: route table + tài liệu hiện hành thắng | DOCUMENTED |

## 4. Kiến trúc backend

### 4.1. Thành phần

| Lớp | Nội dung |
| --- | --- |
| Runtime | PHP 8.3, Laravel 13, Nginx, php-fpm |
| Data | PostgreSQL 16 (canonical), Valkey 8 (cache/queue/session), MinIO/S3 (file) |
| Module | `Modules/Auth` (IAM/JWT/permission), `Modules/Warehouse` (nghiệp vụ kho), `Modules/AuditLog` |
| Job nền | `queue:work` + `schedule:work` chạy tách container, `AUTO_MIGRATE=false` |

Luồng xử lý chuẩn: `Route → Controller → Form Request → Service/UseCase → Model/Repository → PostgreSQL`. Controller không chứa business logic.

Ghi tồn kho bắt buộc: kiểm tra auth → permission → data scope → trạng thái phiếu → mở transaction → lock bản ghi tồn → kiểm tra lại số lượng → cập nhật tồn → ghi `stock_movement` → ghi audit → chuyển trạng thái → commit. Lỗi ở bất kỳ bước nào thì rollback toàn bộ.

### 4.2. Middleware toàn cục (`bootstrap/app.php`)

| Middleware | Vai trò |
| --- | --- |
| `NoStoreApiResponses` | Ép `no-store` cho response API (ngoài cùng) |
| `ApiConventionMiddleware` | Chuẩn hoá request/response envelope, `request_id`, `MALFORMED_JSON` |
| `Wwhn172AggregateLock` | Khoá aggregate cho thao tác đồng thời |
| `EnforceInventoryWarehouseScope` | Data scope theo kho; đăng ký toàn cục **cố ý** để refactor route không âm thầm mất scope |
| `permission` → `CheckPermission` | RBAC; hỗ trợ OR `permission:A\|B`; `SUPER_ADMIN` bypass |

Optimistic locking **không** có middleware riêng: enforcement nằm ở controller. `CurrentBaselineIfMatch` đã được gỡ theo `DEC-API-IFMATCH-001`.

### 4.3. Envelope và error code

Envelope lỗi thống nhất: `success`, `message` (tiếng Việt, không lộ stack/SQL/secret), `error_code`, `errors`, `error.code/details`, `meta.request_id/timestamp`. `error_code` và `error.code` **luôn bằng nhau** — client không được coi trường nào là optional.

| HTTP | Mã mặc định | Ý nghĩa |
| --- | --- | --- |
| 401 | `UNAUTHENTICATED` | thiếu/hết hạn token |
| 403 | `ACCESS_DENIED` | thiếu permission hoặc ngoài data scope |
| 404 | `RESOURCE_NOT_FOUND` | không tồn tại hoặc ngoài phạm vi dữ liệu |
| 405 | `METHOD_NOT_ALLOWED` | method không hỗ trợ |
| 409 | `STATE_CONFLICT` / `UNIQUE_CONSTRAINT_CONFLICT` | xung đột trạng thái/đồng thời/idempotency |
| 412 | `STALE_VERSION` | `If-Match` không khớp version hiện tại |
| 422 | `BUSINESS_RULE_ERROR` | validation/quy tắc nghiệp vụ |
| 428 | `PRECONDITION_REQUIRED` | thiếu `If-Match` bắt buộc |
| 500 | `INTERNAL_SERVER_ERROR` | lỗi ngoài dự kiến, giao dịch đã rollback |

Danh mục đầy đủ: `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md` (sinh tự động, CI `--check`).

## 5. Hợp đồng API bàn giao cho FE/QA

**Số liệu contract chỉ có một nguồn: `docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.json`.** Không chép số vào văn xuôi ở nơi khác — cổng tổng đối chiếu ngược lại và sẽ FAIL.

| Artifact | Đường dẫn | Cách sinh lại |
| --- | --- | --- |
| OpenAPI 3.0 (183 path / 231 operation) | `storage/api-docs/api-docs.json\|yaml` | `php artisan l5-swagger:generate` |
| Số liệu contract chính thức | `docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.json\|md` | `node scripts/generate-api-contract-metrics.mjs` |
| Ma trận endpoint × permission × OpenAPI | `docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md` | `node scripts/generate-endpoint-permission-matrix.mjs` |
| Error code catalog | `docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.md` | `node scripts/generate-error-code-catalog.mjs` |
| Permission catalog + role matrix | `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json` | `php artisan evidence:permission-catalog` |
| Postman collection/environment | `docs/postman/` | `npm run api:postman` |
| Contract API bàn giao (điểm vào cho FE/QA) | `docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md` | cập nhật tay khi đổi quy ước contract |
| Kiến trúc backend as-built | `docs/architecture/HN-BE-ARCH-001_BACKEND_ARCHITECTURE.md` | cập nhật tay khi đổi kiến trúc |
| Runbook vận hành backend | `docs/ops/HN-BE-OPS-001_OPERATIONS_RUNBOOK.md` | cập nhật tay khi đổi quy trình vận hành |
| Cổng tổng gói bàn giao | `scripts/verify-wwhn-309-handover-contract.mjs` | `node scripts/verify-wwhn-309-handover-contract.mjs` |
| Cổng pin candidate | `scripts/verify-wwhn-309-candidate-pin.mjs` | `node scripts/verify-wwhn-309-candidate-pin.mjs` |

Quy tắc bắt buộc khi đọc permission trong contract:

1. `permission` là **biểu thức**; `A|B` nghĩa là OR. Dùng `accepted_permissions` đã tách sẵn.
2. `roles` đã gồm `SUPER_ADMIN` do bypass backend, không phải do gán permission.
3. Data scope là tầng kiểm tra độc lập: đúng permission vẫn có thể 403 vì ngoài phạm vi kho.
4. Optimistic locking: 58 operation khai `If-Match`; thiếu header → 428, sai version → 412.
5. Idempotency: 27 operation khai `Idempotency-Key` (23 bắt buộc, 4 tuỳ chọn theo Annex C §8.1 nhóm warranty stock-neutral). Chuỗi 8–100 ký tự; cùng key + cùng payload = replay kết quả lần đầu, cùng key + payload khác = 409.
6. Hình dạng payload đọc được ngay từ spec: 2323/2323 response `$ref` tới `ApiSuccessEnvelope` hoặc `ApiErrorEnvelope`.
7. Header contract trong spec được sinh từ chính source của controller, không khai tay.

Thêm controller có annotation mới **phải** khai đường dẫn trong `config/l5-swagger.php`, nếu không Swagger publish endpoint rỗng contract (bài học DEV184-P1-01R và Flow 06).

## 6. Vận hành

### 6.1. Health và danh tính artifact

`GET /api/v1/health` (public) trả `status`, `version`, `build.sha/ref/built_at`, `environment`, `checks`. Critical checks để trả 200: `app`, `database`, `migrations`, `seed`. `cache` cố ý **không** phải hard gate (fail-open xuống PostgreSQL, tránh restart loop khi Valkey chập chờn).

DevOps/CI phải inject `APP_BUILD_SHA` / `APP_BUILD_REF` / `APP_BUILD_TIME` lúc build image — đây là cách duy nhất reviewer đọc được commit đang chạy thay vì tin lời khai; thiếu ở môi trường ngoài local/testing sẽ bị `wms:golive-check` chặn. Bằng chứng runtime và bảng xác minh: `docs/evidence/WWHN-309/DEVOPS_HANDOVER_VERIFICATION.md`.

### 6.2. Job nền bắt buộc

| Lịch | Lệnh | Hậu quả nếu không chạy |
| --- | --- | --- |
| 01:30 | `inventory:reconcile` | Lệch ledger/balance chỉ lộ khi có người mở báo cáo (SRS §7.5) |
| mỗi 5 phút | `audit:reconcile` | `audit_outbox` phình vô hạn, audit trail mất |
| 02:15 | `import:purge-error-files` | File lỗi CSV không bao giờ bị xoá, đĩa phình |
| 03:10 | `warranty:purge-pii` | PII tồn tại vô thời hạn, vi phạm DR-PRIV-001 |

Điều kiện vận hành: phải có process chạy `php artisan schedule:work` (container `scheduler` trong compose) hoặc cron `schedule:run`. Không có process này thì cả 4 nghĩa vụ trên đều **không tồn tại trên thực tế**.

### 6.3. Lệnh vận hành

`wms:golive-check` (gate cutover), `migration:final-load` (nạp dữ liệu cuối), `evidence:permission-catalog`, `evidence:stock-snapshot`, `wms:cache:clear`, `wwhn172:migration-preflight`, `inventory:reconcile`, `audit:reconcile`.

### 6.4. Runbook

- Cutover/rollback: `docs/evidence/WWHN-300/CUTOVER_ROLLBACK_RUNBOOK.md`
- Nạp dữ liệu cuối: `docs/migration/FINAL_DATA_LOAD_RUNBOOK.md`
- Rollback migration: `docs/evidence/WWHN-172/MIGRATION_ROLLBACK_RUNBOOK.md`

`migrate:rollback` toàn lịch sử đã từng brick DB; mọi `down()` phải dùng `dropRelation` (chuẩn WWHN-300).

## 7. Build / run / quality gates

```bash
composer install --no-interaction --prefer-dist
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate --force
php artisan db:seed
php artisan test                      # SQLite in-memory (dev)
php artisan test -c phpunit.pgsql.xml # PostgreSQL (nghiệm thu)
php artisan l5-swagger:generate
php artisan evidence:permission-catalog
php artisan schedule:list
composer run verify:stock-write-path
npm run api:lint
node scripts/generate-error-code-catalog.mjs --check
node scripts/generate-endpoint-permission-matrix.mjs --check
node scripts/generate-api-contract-metrics.mjs --check
node scripts/verify-wwhn-309-handover-contract.mjs
node scripts/verify-wwhn-309-candidate-pin.mjs
```

Máy dev chạy Docker (vendor nằm trong volume, không có trên host) thì đặt `WWHN309_ARTISAN` để generator/cổng gọi được artisan thật:

```bash
export WWHN309_ARTISAN="docker exec wms_warehouse_hoa_nam_be-backend-1 php artisan"
```

Execution identity tối thiểu khi review: branch `m1`, candidate SHA, GitHub Actions run ID/URL, `APP_ENV`, `APP_BUILD_SHA`, kết quả từng gate kèm exit code, tên artifact + checksum. **Không dùng log của SHA khác để kết luận WWHN-309 PASS.**

## 8. Evidence

| Loại | Nguồn |
| --- | --- |
| Candidate pin + quy scope commit gộp | `docs/evidence/WWHN-309/CANDIDATE_MANIFEST.json` + `scripts/verify-wwhn-309-candidate-pin.mjs` |
| Pin run CI tại exact SHA | `docs/evidence/WWHN-309/CI_RUN_RECORD.md` |
| Kịch bản retest độc lập của QA1 | `docs/evidence/WWHN-309/QA_RETEST_PLAN.md` |
| Xác minh vận hành của DevOps | `docs/evidence/WWHN-309/DEVOPS_HANDOVER_VERIFICATION.md` |
| Regression test handover | `tests/Feature/Wwhn309PermissionCatalogEvidenceTest.php`, `tests/Feature/Wwhn309ScheduledOperationsTest.php`, `tests/Feature/CurrentBaselineIfMatchContractTest.php` |
| CI exact-SHA | `.github/workflows/wwhn-309-backend-handover-evidence.yml` → artifact `wwhn-309-backend-handover-<sha>` |
| Evidence dự phòng khi chưa dispatch được CI | `bash scripts/collect-wwhn309-handover-evidence.sh` |
| Permission catalog runtime | `build/evidence/wwhn-309/permission-catalog.json`, `permission-seed-diff.json` |
| Số liệu contract | `docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.json` |
| Ops | `php artisan schedule:list`, `/api/v1/health` |
| Đối chiếu chéo FE ↔ BE | FE `npm run contract:sync -- <BE_SHA>` + `npm run check:api-mapping`; pin ở FE `docs/api-evidence/BE_CONTRACT_PIN.json`; biên nhận `FE@docs/Task_docs_Jira/WWHN-309_BE_CONTRACT_RECEIPT.md` |
| Bộ comment sign-off theo vai trò | `docs/docs_task_jira/WWHN-309_SIGNOFF_COMMENTS.md` |

### 8.1. Bẫy truy vết: `/docs/` từng nằm trong .gitignore

Rule chặn `/docs/` đã được bỏ, nhưng thói quen kiểm tra thì giữ: thêm tài liệu handover mới phải kiểm chứng bằng `git ls-files docs/<path>` **trước khi** tuyên bố đã bàn giao. Cổng tổng cũng FAIL nếu tài liệu bắt buộc bị `git check-ignore` bắt.

## 9. Quy scope commit và pin candidate (DEV309-P1-01)

### 9.1. Vì sao cần mục này

Commit `127c573` ("gỡ cờ 13") mang thay đổi của WWHN-309 **cùng lúc với** nhiều task khác. Reviewer mở commit đó không tách được đâu là phạm vi WWHN-309, nên không review độc lập được. Không sửa lịch sử đã push, nên cách xử lý là **khai scope tường minh** và chuyển việc kiểm sang tập artifact + hash.

### 9.2. Quy scope

| Đường dẫn trong commit gộp | Thuộc task | Ghi chú |
| --- | --- | --- |
| `README.md`, `docs/architecture/**`, `docs/api-contract/**`, `docs/ops/**`, `docs/permissions/**`, `docs/docs_task_jira/WWHN-309*.md`, `docs/evidence/WWHN-309/**`, `app/Swagger/ApiEnvelopeSchemas.php`, `app/Swagger/Processors/RouteSyncProcessor.php`, `storage/api-docs/**`, `scripts/generate-*.mjs`, `scripts/verify-wwhn-309-*.mjs`, `tests/Feature/Wwhn309*.php`, `tests/Feature/CurrentBaselineIfMatchContractTest.php`, `.github/workflows/wwhn-309-*.yml` | **WWHN-309** | Đúng tập artifact được pin trong `CANDIDATE_MANIFEST.json` |
| `docs/HoaNam_ERD_Moi_Nhat.md`, `docs/contracts/erd-document-pin.json`, `docs/evidence/WWHN-310/**`, `tests/Feature/Wwhn310*.php` | WWHN-310 | Tài liệu dữ liệu/migration/báo cáo — **không** thuộc WWHN-309, không dùng để chấm WWHN-309 |
| `app/Http/Controllers/HealthController.php`, `app/Swagger/HealthSchemas.php` | WWHN-295 | Contract health/build identity. WWHN-309 chỉ *tiêu thụ* `/api/v1/health`, không sở hữu |
| `Modules/Warehouse/**`, migration nghiệp vụ | WWHN-299/330/331 và các task luồng | Ngoài phạm vi |

### 9.3. Pin không mục

`scripts/verify-wwhn-309-candidate-pin.mjs` kiểm 4 điều, fail-closed:

1. Candidate SHA có thật và **vẫn là tổ tiên của HEAD** (không bị rebase/amend làm mất).
2. Mọi artifact bàn giao đang được git tracked và không bị `.gitignore` loại.
3. Nội dung artifact khớp SHA-256 đã pin.
4. **Range invariant**: `git log <candidate>..HEAD -- <tập artifact>` phải rỗng. Nhánh `m1` chạy tiếp cho task khác thì pin vẫn đúng; ai chạm vào artifact bàn giao thì cổng đỏ ngay và phải re-pin + chạy lại evidence.

### 9.4. Review độc lập khi không có PR

Quy trình dự án đang commit thẳng lên `m1`, nên `pull_request` = `N/A`. Khi đó manifest **bắt buộc** khai `independent_review` (ai review, review artifact nào, ở đâu) — cổng FAIL nếu vừa không có PR vừa không có bằng chứng review độc lập. Đây là cách chặn "handover tự ký".

## 10. Vai trò và dấu vết lịch sử (DEV309-P2-01)

Vai trò hiện hành của người thực hiện task này là **DEV** (FE1/BE1 đã hợp nhất). Các tài liệu và artifact sinh trước thời điểm hợp nhất còn mang chuỗi `BE1` — giữ nguyên **có chủ đích** để truy vết:

| Chuỗi còn `BE1` | Lý do giữ |
| --- | --- |
| `docs/permissions/WMS_PERMISSION_CATALOG_BE1.json\|md` | Tên file là khoá tra cứu trong nhiều tài liệu/CI đã publish; đổi tên sẽ phá trace của WWHN-155/219/289 |
| `docs/api-contract/WMS_HoaNam_OpenAPI_Changelog_BE1.md\|txt` | Bản ghi lịch sử changelog, không được viết lại |
| `docs/api-contract/WWHN-150_OpenAPI_Changelog_BE1.md` | Như trên |

Mọi phát biểu **về trách nhiệm hiện tại** trong gói bàn giao này dùng `DEV`.

## 11. Điểm cần sign-off và blocker còn lại

| # | Nội dung | Owner | Trạng thái |
| --- | --- | --- | --- |
| 1 | Baseline role của `scan classify` gồm 6 role (4 role WWHN-150 + `WAREHOUSE_KEEPER`/`WAREHOUSE_STAFF` qua alias Annex C `scan.execute`) | BA/BAS | Chờ comment xác nhận |
| 2 | Ranh giới nghiệp vụ trong `HN-BE-API-001` §8 (contract âm) khớp quyết định đã chốt | BA/BAS | Chờ comment xác nhận |
| 3 | Duyệt kiến trúc as-built + cách sinh header/envelope từ source | Tech Lead | Chờ comment xác nhận |
| 4 | Disposition `CurrentBaselineIfMatch` = **GỠ** theo `DEC-API-IFMATCH-001` | Tech Lead | Chờ duyệt quyết định (code đã gỡ) |
| 5 | Run CI exact-SHA tại candidate SHA được pin vào `CI_RUN_RECORD.md` | DEV + QA1 | PENDING CI EXECUTION |
| 6 | Retest độc lập theo `QA_RETEST_PLAN.md` | QA1 (WWHN-312) | Chờ QA1 chạy |
| 7 | Xác minh vận hành UAT/production theo `DEVOPS_HANDOVER_VERIFICATION.md` | DevOps (WWHN-313) | Chờ DevOps điền |
| 8 | Parent WWHN-83 còn `Blocked/Pending` | BAS/PM | WWHN-309 không tự tuyên bố parent Done |

Không có blocker P0 thuộc vai trò DEV tại thời điểm cập nhật này.
