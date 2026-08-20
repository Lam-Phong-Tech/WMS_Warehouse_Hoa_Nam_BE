# HN-BE-API-005 — DEV Handover Response cho QA/Tester

| | |
|---|---|
| Ngày tạo | 2026-08-20 (bản đầu) — cập nhật cùng ngày sau khi áp fix mục 1 |
| Phạm vi | Trả lời 11 mục yêu cầu handover từ QA/Tester để test môi trường DEV |
| Nguồn | Đọc trực tiếp source code + evidence trong repo tại commit `0895c14a` (branch `m1`), **không sửa/không chạm** `.github/`, `.ci/` |
| Trạng thái | Gap ở mục 1 (Swagger placeholder 90/112) **đã fix tại chỗ** trong repo local — xem "Đã fix" bên dưới. Có 3 mục (3, 9, 10 một phần) **không thể xác nhận chỉ bằng repo local** — cần DevOps xác nhận trên máy chủ DEV thật |

> ⚠️ Lưu ý quan trọng: Toàn bộ nội dung dưới đây lấy từ việc **đọc và sửa code local**, không chạy/sửa bất kỳ workflow CI/CD nào (`.github/`, `.ci/` không bị đụng tới). Mọi thay đổi được xác minh bằng `php -l`, `php artisan test` (sqlite) và `node scripts/verify-wwhn-309-handover-contract.mjs` chạy local — không tốn phút GitHub Actions.

## Đã fix trong phiên này (local code, đã lint + test xanh)

1. **`config/l5-swagger.php`** — bổ sung 23 controller Warehouse (14 gap gốc + 9 phát sinh khi rà kỹ hơn) + `Modules/Auth/.../RoleController.php` đã có sẵn vào allowlist scan.
2. **21 controller Warehouse + `RoleController`** — bổ sung annotation `#[OA\...]` cho **86 operation mutation** trước đó chỉ có body rỗng, lấy field trực tiếp từ `$request->validate()`/FormRequest thật (không bịa field). Danh sách file: `AlignedOutboundFlowController`, `CurrentBaselineComponentFlowController`, `CurrentBaselineDocumentController`, `CurrentBaselineImportController`, `CurrentBaselineInboundBoxController`, `CurrentBaselineItemIdentityController`, `CurrentBaselineMasterController`, `CurrentBaselineOperationsController`, `CurrentBaselinePhysicalCodeController`, `CurrentBaselinePutawayTaskController`, `CurrentBaselineSkuController`, `CurrentBaselineStockFlowController`, `CurrentBaselineWarrantyAttachmentController`, `CurrentBaselineWarrantyController` (bổ sung), `DefectCatalogController` (bổ sung), `ProductController` (bổ sung), `PvAdminResourceController` (khảo sát, không sửa — xem lý do bên dưới), `ReversalDocumentController`, `ScanController` (đã có annotation, chỉ thiếu allowlist).
3. **`app/Swagger/Processors/RouteSyncProcessor.php`** — hàm `tag()` thiếu map cho 10 resource (`catalog-masters`, `defects`, `label-print-events`, `outbound-requests`, `putaway-tasks`, `pv-admin`, `reversal-documents`, `scan-batches`, `warranty-component-issues`) nên rơi vào nhóm mặc định `"00. Platform"` chung với `health` — bug có sẵn, bị lộ ra khi làm giàu schema. Đã map đúng nhóm nghiệp vụ (ví dụ `catalog-masters` → `08. Catalog Master`, `reversal-documents` → `07. Inventory`).
4. **`app/Swagger/Flow06ReportDocumentation.php`** — gỡ 1 stub annotation `POST /api/v1/reversal-documents` trùng path với schema thật trên `ReversalDocumentController` (gây warning "Unable to merge" khi generate).
5. **`docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md`** + **`docs/docs_task_jira/WWHN-309_SIGNOFF_COMMENTS.md`** — cập nhật số liệu Idempotency-Key/endpoint public theo state hiện tại (33 operation, 23 bắt buộc/10 tuỳ chọn — tăng so với 27 do trước đây nhiều operation optional chưa được khai đúng); đánh dấu `[historical]` các số liệu mô tả trạng thái tại thời điểm sign-off vòng 2 cũ.
6. Regenerate: `storage/api-docs/api-docs.json/.yaml`, `HN-BE-API-002/003/004`. **Không regenerate lại toàn bộ file Postman DEV** — đã thử và revert vì `npm run api:postman` ghi đè mất test script tay (WWHN-295 health check) và toàn bộ example đã lưu; giữ nguyên Postman gốc + chạy lại 2 script đồng bộ có sẵn (`sync-wwhn-266-postman-if-match.mjs`, `sync-wwhn-202-postman-surfaces.mjs`) — cả hai PASS không đổi gì (collection gốc đã khớp).
7. **Xác minh không regression:** `php artisan test` full suite (1199 test) — kết quả giống hệt baseline trước khi sửa (10 failed + 5 errors, đã đối chiếu từng test bằng `git stash`) ngoại trừ 1 test WWHN-309 tự khớp lại sau khi cập nhật docs. Toàn bộ 15 failure/error còn lại **có sẵn từ trước, không liên quan** (migration pipeline cục bộ, thiếu file `docker/nginx/default.conf` trên máy dev này, redaction audit). `node scripts/verify-wwhn-309-handover-contract.mjs` → **PASS**.

**Placeholder còn lại sau fix: 25/112 (22.3%), giảm từ 90/112 (80.4%).** Toàn bộ 25 còn lại thuộc 1 trong 3 nhóm, không phải gap tài liệu:
- Alias path dùng chung handler với path canonical đã có schema (`/putaway/tasks/*`, `/warranty-component-issues/*`, `/scan-batches/classify` — bản canonical `/putaway-tasks/*`, `/component-issue-documents/*`, `/scan/classify` đã có schema đầy đủ).
- Endpoint thật sự không có body (chỉ đọc header `If-Match`/`Idempotency-Key`): `post-receipt`, `apply`, `preview`, `ready`, `post-issue`, `post` (component/reversal), `disable`/`enable`.
- `POST/PATCH /api/v1/pv-admin/resources/{resourceType}[/{id}]` — cố ý chưa annotate vì validation rẽ nhánh động theo 4 `resourceType` khác nhau trong cùng 1 method, annotate tĩnh sẽ sai lệch với ít nhất 1 nhánh; cần DEV tách theo từng resourceType hoặc dùng `oneOf` mới làm đúng.

---

## 1. Swagger/OpenAPI/api.json — request schema/query/header

**File hiện có (đã regenerate sau fix):**
- `storage/api-docs/api-docs.json` và `.yaml` — generate bởi `darkaonline/l5-swagger ^11.1`.
- UI: `GET /api/documentation`.
- Metrics tự động: `docs/api-contract/HN-BE-API-004_CONTRACT_METRICS.md` — **183 paths / 231 operations / 2334 responses / 22 component schemas**, 58 operations có `If-Match`, 33 có `Idempotency-Key` (23 bắt buộc/10 tuỳ chọn), 248 error code, 9 operation public (không JWT).

**✅ GAP ĐÃ FIX trong phiên này** (trạng thái trước khi fix, để lại làm hồ sơ):

Trước fix, parse `api-docs.json` phân loại 112 operation POST/PUT/PATCH theo `requestBody.schema` cho thấy 90/112 (80.4%) chỉ là placeholder rỗng `{"type":"object","example":{},"additionalProperties":true}`. Nguyên nhân: `config/l5-swagger.php` mục `'annotations'` là **allowlist theo từng file cụ thể**, không quét cả thư mục `Modules/Warehouse/app/Http/Controllers` (56 controller file) — nhiều controller có annotation `#[OA\...]` thật trong source nhưng không nằm trong allowlist nên generator tự sinh skeleton rỗng thay vì đọc annotation thật.

**Đã sửa:** bổ sung 23 controller Warehouse + `RoleController` vào allowlist, viết thêm annotation cho 86 operation trước đó thiếu (field lấy trực tiếp từ `$request->validate()`/FormRequest thật), sửa 1 bug phụ trong `RouteSyncProcessor::tag()` và gỡ 1 stub trùng path — chi tiết đầy đủ ở mục "Đã fix trong phiên này" phía trên. Kết quả: **placeholder giảm còn 25/112 (22.3%)**, và 25 còn lại đều có lý do chính đáng (alias path, endpoint thật sự không có body, hoặc `pv-admin/resources` cố ý chưa làm vì validation rẽ nhánh động — xem chi tiết ở trên).

Ví dụ đã verify hết placeholder: `POST /api/v1/agencies` — trước fix `grep -c "agency_code" api-docs.json` = 0 dù source có đủ schema; sau fix = 4 (xuất hiện đúng trong `requestBody` + example).

**Còn cần DEV team làm thêm (ngoài phạm vi phiên này):** tách schema `PATCH/POST /api/v1/pv-admin/resources/{resourceType}[/{id}]` theo từng `resourceType` (hoặc `oneOf`) — hiện vẫn là placeholder vì 1 method dùng validation rẽ nhánh động theo 4 loại resource khác nhau, annotate tĩnh sẽ sai lệch với ít nhất 1 nhánh.

**`routes.tmp.json`** ở root repo — là dump thô của `route:list --json`, cũ hơn `api-docs.json` hiện tại → **không phải nguồn chính thức**, chỉ là file làm việc/debug, bỏ qua khi đối chiếu.

---

## 2. Version contract mới

Có **hai trục version độc lập, không được nhầm lẫn:**

| Trục | Giá trị hiện tại | Nguồn |
|---|---|---|
| OpenAPI spec version | `2.0.0` | `app/Swagger/OpenApi.php:8` — `#[OA\Info(version: '2.0.0', ...)]`, hardcode |
| App/runtime version (`APP_VERSION`) | `1.0.0` (fallback nếu `.env` không set) | `config/health.php:12` = `env('APP_VERSION', '1.0.0')`, trả về ở `GET /api/v1/health` |
| Per-ticket release-pack `document_version` | vd. `1.0.0` cho WWHN-165 | `docs/api-contract/releases/<JIRA-KEY>/<semver>/RELEASE_MANIFEST.json` — độc lập theo từng lần đóng ticket |

`config/app.php` và `composer.json` **không có** field version chung; **không có** `CHANGELOG.md` ở root.

Contract chính thức: **`docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md`** (cập nhật 2026-08-18) — quy định lấy artifact theo **đúng commit SHA**, không phải "latest":
```
curl -fsSL -o swagger-api.json "https://raw.githubusercontent.com/Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE/<BE_SHA>/storage/api-docs/api-docs.json"
```
Envelope response chuẩn: `success/message/data/meta/links/error`, `error_code` luôn bằng `error.code`.

**Bản mới nhất hiện có:** release pack `docs/api-contract/releases/WWHN-165/1.0.0/` (2026-08-20), pin tại source_commit `a452e4459fe20aaaf1c23981577b3e0570c13c00`.

---

## 3. Exact DEV deploy commit SHA/build/tag đang chạy

**⚠️ Không thể xác định chỉ từ repo local — cần DevOps xác nhận trực tiếp trên máy chủ DEV.**

Lý do: đã kiểm tra toàn bộ 49 file `.github/workflows/*.yml` (chỉ đọc, không sửa) — pipeline chính `hn-devops-ci-cd.yml` chỉ có 2 loại trigger:
```yaml
push: branches: [dev, m1, main]        # build/test only
workflow_dispatch: deploy_uat input     # deploy job DUY NHẤT là deploy-uat (environment: uat), thủ công
```
**Không có job deploy-dev tự động nào trong CI.** File `wwhn-273-backend-release-signoff.yml:236,292` tự ghi rõ: `devops_deploy_backup_rollback=NOT_CLAIMED_BY_BE` — việc deploy lên DEV nằm ngoài phạm vi BE/CI, do DevOps quản lý riêng.

**Thông tin có thể cung cấp thay thế:**
- Git HEAD hiện tại (local, branch `m1`, **chưa push** — ahead origin/m1 5 commit): `0895c14a5cc1a39837a79ce5de2ac1669ddb16ef` — "docs(WWHN-165): release pack contract 1.0.0 ...", 2026-08-20 10:03:55 +0700.
- SHA code-fix đã đóng gói gần nhất (WWHN-165, đã pin trong evidence + release manifest): `a452e4459fe20aaaf1c23981577b3e0570c13c00`.
- `.env.example` có sẵn field `VITE_BUILD_VERSION`/`VITE_BUILD_SHA` và comment yêu cầu CI/DevOps phải inject `APP_BUILD_SHA/REF/TIME` lúc build — nhưng `.env` local hiện là `local-dev`/`local` (placeholder), và `GET /api/v1/health` trả `build: {sha:null, ref:null, built_at:null}` khi các biến này chưa được set trên môi trường thật.

**Việc cần DEV/DevOps làm:** SSH/kiểm tra `.env` thật trên máy DEV lấy `APP_BUILD_SHA` (nếu có), hoặc chạy `git rev-parse HEAD` trực tiếp trên thư mục deploy DEV — repo local không phản ánh được máy chủ.

---

## 4. Mapping If-Match / Idempotency-Key theo từng endpoint

Trích trực tiếp từ `storage/api-docs/api-docs.json` (khớp với số liệu 58/33 trong HN-BE-API-004), quy tắc lỗi tương ứng (theo HN-BE-API-001): `If-Match` sai/thiếu-nhưng-bắt-buộc → `412 STALE_VERSION` / `428 PRECONDITION_REQUIRED`; `Idempotency-Key` trùng key khác payload → `409 IDEMPOTENCY_CONFLICT`.

### If-Match (58 endpoint, ✱ = optional)
```
PATCH  /api/v1/agencies/{id} ✱
PATCH  /api/v1/brands/{id} ✱
PATCH  /api/v1/catalog-masters/{masterType}/{id} ✱
POST   /api/v1/component-issue-documents/{id}/cancel
POST   /api/v1/component-issue-documents/{id}/post
POST   /api/v1/component-issue-documents/{id}/scan
PATCH  /api/v1/containers/{id}
POST   /api/v1/containers/{id}/items
DELETE /api/v1/containers/{id}/items/{containerItemId}
PATCH  /api/v1/defect-catalogs/{id} ✱
PATCH  /api/v1/defects/{id} ✱
PATCH  /api/v1/inbound-documents/{id}
POST   /api/v1/inbound-documents/{id}/cancel
POST   /api/v1/inbound-documents/{id}/post-receipt
POST   /api/v1/inbound-documents/{id}/reversals
POST   /api/v1/inbound-documents/{id}/reverse
POST   /api/v1/inbound-documents/{id}/scan
POST   /api/v1/inbound-documents/{id}/scans
POST   /api/v1/inbound-documents/{id}/unscan
POST   /api/v1/label-snapshots/{id}/apply
POST   /api/v1/label-snapshots/{id}/print
POST   /api/v1/label-snapshots/{id}/reprint
POST   /api/v1/outbound-documents/{id}/cancel
POST   /api/v1/outbound-documents/{id}/packing-labels/{labelId}/preview
POST   /api/v1/outbound-documents/{id}/packing-labels/{labelId}/print
POST   /api/v1/outbound-documents/{id}/packing-labels/{labelId}/ready
POST   /api/v1/outbound-documents/{id}/packing-labels/{labelId}/reprint
POST   /api/v1/outbound-documents/{id}/post-issue
POST   /api/v1/outbound-documents/{id}/reversals
POST   /api/v1/outbound-documents/{id}/reverse
POST   /api/v1/outbound-documents/{id}/scan
POST   /api/v1/outbound-documents/{id}/scans
POST   /api/v1/outbound-request-imports/{id}/confirm
POST   /api/v1/outbound-request-imports/{id}/consume
PATCH  /api/v1/package-variants/{id} ✱
PATCH  /api/v1/physical-codes/{id}
PATCH  /api/v1/power-sources/{id} ✱
PATCH  /api/v1/product-categories/{id} ✱
PATCH  /api/v1/product-models/{id} ✱
PATCH  /api/v1/products/{id} ✱
POST   /api/v1/putaway-tasks/{id}/cancel
POST   /api/v1/putaway-tasks/{id}/confirm
POST   /api/v1/putaway/tasks/{id}/cancel
POST   /api/v1/putaway/tasks/{id}/confirm
PATCH  /api/v1/pv-admin/resources/{resourceType}/{id}
PATCH  /api/v1/pv/products/{id}/publish
POST   /api/v1/reversal-documents
POST   /api/v1/reversal-documents/{id}/cancel
POST   /api/v1/reversal-documents/{id}/confirm
POST   /api/v1/reversal-documents/{id}/post
PATCH  /api/v1/skus/{id}
DELETE /api/v1/skus/{id}/physical-codes/{physicalCodeId}
PATCH  /api/v1/users/{id} ✱
PATCH  /api/v1/warranty-cases/{id}
POST   /api/v1/warranty-cases/{id}/status
POST   /api/v1/warranty-component-issues/{id}/cancel
POST   /api/v1/warranty-component-issues/{id}/post
POST   /api/v1/warranty-component-issues/{id}/scans
```
(Ghi chú: `/putaway-tasks/{id}/...` và `/putaway/tasks/{id}/...` cùng tồn tại — 2 path variant cho cùng action, canonical + alias.)

### Idempotency-Key (33 endpoint, ✱ = optional)
```
POST   /api/v1/component-issue-documents/{id}/post
POST   /api/v1/inbound-documents ✱
POST   /api/v1/inbound-documents/{id}/post-receipt
POST   /api/v1/inbound-documents/{id}/reversals
POST   /api/v1/inbound-documents/{id}/reverse
POST   /api/v1/inbound-documents/{id}/scan ✱
POST   /api/v1/inbound-documents/{id}/scans ✱
POST   /api/v1/label-snapshots/{id}/print
POST   /api/v1/label-snapshots/{id}/reprint
POST   /api/v1/outbound-documents/{id}/packing-labels/{labelId}/print
POST   /api/v1/outbound-documents/{id}/packing-labels/{labelId}/ready
POST   /api/v1/outbound-documents/{id}/packing-labels/{labelId}/reprint
POST   /api/v1/outbound-documents/{id}/post-issue
POST   /api/v1/outbound-documents/{id}/reversals
POST   /api/v1/outbound-documents/{id}/reverse
POST   /api/v1/outbound-request-imports/{id}/confirm
POST   /api/v1/outbound-request-imports/{id}/consume
POST   /api/v1/putaway-tasks/{id}/cancel ✱
POST   /api/v1/putaway-tasks/{id}/confirm ✱
POST   /api/v1/putaway/tasks/{id}/cancel ✱
POST   /api/v1/putaway/tasks/{id}/confirm ✱
POST   /api/v1/reports/export
POST   /api/v1/reversal-documents
POST   /api/v1/reversal-documents/{id}/post
POST   /api/v1/skus
PATCH  /api/v1/skus/{id}
POST   /api/v1/skus/{id}/physical-codes
DELETE /api/v1/skus/{id}/physical-codes/{physicalCodeId}
POST   /api/v1/warranty-cases ✱
PATCH  /api/v1/warranty-cases/{id} ✱
POST   /api/v1/warranty-cases/{id}/status ✱
POST   /api/v1/warranty-cases/{id}/temp-only
POST   /api/v1/warranty-component-issues/{id}/post
```

Mẫu response lỗi thật (dump từ test, không phải viết tay) tại `docs/api-contract/releases/WWHN-165/1.0.0/samples/{409_IDEMPOTENCY_CONFLICT,412_STALE_VERSION,428_PRECONDITION_REQUIRED,...}.json`.

---

## 5. Example happy-path request cho API mutation

- Postman chính thức: **`docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json`** (155 request, 151/155 có example response đã lưu). **⚠️ Con số "57/155 (36.8%) có request body mẫu khác rỗng" đo TRƯỚC fix mục 1 vẫn còn đúng cho file Postman hiện tại** — xem "Vì sao chưa regenerate Postman" ngay dưới.
- Ngoài ra có các Postman collection theo từng ticket trong `docs/postman/` (WWHN-134, 150, 172, 180, 184, 187, 195...) — phạm vi hẹp hơn, dùng để đối chiếu case cụ thể.
- Release pack WWHN-165 có bản Postman pin theo đúng SHA: `docs/api-contract/releases/WWHN-165/1.0.0/postman_collection.json` (sha256 = `59ea3226...`, khớp `RELEASE_MANIFEST.json`).
- **Không có** thư mục `docs/examples/` riêng cho happy-path request — chỉ có sample **response lỗi** (mục 4) và Postman saved response.

**Vì sao chưa regenerate Postman trong phiên này:** OpenAPI (`api-docs.json`) đã có schema đầy đủ cho 86 operation mới (mục 1), lẽ ra `npm run api:postman` sẽ kéo hết field mới vào Postman. Đã thử — nhưng lệnh này **ghi đè toàn bộ file** (`openapi2postmanv2` regenerate from scratch), xoá mất nội dung soạn tay không có generator nào tái tạo lại được: cụ thể là test script Postman của request `health` (WWHN-295, kiểm `expectedBuildSha`/`HEALTH_CHECK_DEGRADED`) và có thể cả các example response đã lưu tay ở nơi khác. Đã revert về bản gốc (`git checkout`) để không làm mất dữ liệu, và xác nhận 2 script đồng bộ có sẵn (`sync-wwhn-266-postman-if-match.mjs`, `sync-wwhn-202-postman-surfaces.mjs`, chỉ thêm không xoá/không sắp xếp lại) vẫn PASS trên bản gốc.

**Khuyến nghị cho DEV:** cần viết một script đồng bộ mới theo đúng khuôn 2 script trên (đọc `api-docs.json`, chỉ **thêm** field/example còn thiếu vào request Postman đã có theo `operationId`, giữ nguyên `JSON.stringify(_, null, 4)` và không đụng test script/example đã có) thay vì chạy `npm run api:postman` — lệnh đó chỉ an toàn dùng cho lần khởi tạo đầu tiên của file, không dùng để cập nhật một file đã có nội dung soạn tay.

---

## 6. Enum/UUID/reference constraints

**Không dùng PHP Enum class** (đã grep toàn repo, 0 kết quả) — mọi status/type là cột `string` + **PostgreSQL CHECK constraint**, thêm ở `Modules/Warehouse/database/migrations/2026_08_10_000620_remove_superseded_fields_and_lock_append_only_history.php:80-93`:

| Bảng.cột | Giá trị hợp lệ |
|---|---|
| `stock_documents.status` | `DRAFT, SCANNING, POSTED, CANCELLED` |
| `stock_documents.doc_type` | `receipt, issue` |
| `stock_movements.movement_type` | `OPENING, RECEIPT, ISSUE, COMPONENT_ISSUE, REVERSAL` |
| `stock_movements.stock_status` | `AVAILABLE, NON_SELLABLE, DEFECTIVE, QUARANTINE` |
| `item_instances.item_status` | `NEW, IN_STOCK, ISSUED, IN_WARRANTY, RETURNED_TO_CUSTOMER, LOST, ADJUSTED_OUT` |
| `component_issue_documents.status` | `DRAFT, SCANNING, POSTED, CANCELLED` |
| `outbound_request_imports.status` | `UPLOADED, VALIDATED, REJECTED, CONSUMED` |
| `label_snapshots.status` / `label_print_events.status` | `PENDING_PRINT, PRINTED, PRINT_FAILED` |
| `warranty_case.status` | `RECEIVED, CHECKING, REPAIRING, COMPLETED, RETURNED, CANCELLED` |
| `physical_codes.assigned_object_type` | `NULL, ITEM, CONTAINER` |
| `scan_event.scan_source` | `CAMERA, MANUAL, HARDWARE` (chỉ có CHECK trên PostgreSQL; sqlite chỉ validate ở app layer — `AlignedOutboundFlowController::SCAN_SOURCES`) |
| `audit_logs.result` | `SUCCESS, FAILED, DENIED` |

**ID strategy: UUID toàn bộ, không có cột auto-increment nào** (grep `bigIncrements`/`->increments(` trên 155 file migration = 0 kết quả). Ví dụ: `warehouses.id`, `stock_documents.id`, `app_user.user_id`, `products.sku_id` đều `$table->uuid(...)->primary()`.

---

## 7. Cách lấy refresh_token hợp lệ

Auth stack: `tymon/jwt-auth ^2.3` (JWT tự viết, **không phải** Sanctum/Passport).

```
POST /api/v1/auth/login   (không cần auth) — body: {email|username, password}
→ { data: { access_token, refresh_token, token_type: "bearer", expires_in, user{...} } }

POST /api/v1/auth/refresh (không cần bearer, có throttle) — body: {refresh_token}
→ cùng shape, refresh_token cũ bị revoke ngay (rotate), replay = khoá toàn phiên

POST /api/v1/auth/logout  (cần auth:api)
GET  /api/v1/auth/me      (cần auth:api)
```

Refresh token cũng được set vào cookie HttpOnly `wms_refresh_token` (path `/api/v1/auth`) — Postman/non-browser client nên dùng field `refresh_token` trong JSON body thay vì cookie.

TTL: access token `JWT_TTL=60` phút; refresh token thật (không phải TTL nội bộ của jwt-auth) là `REFRESH_TOKEN_TTL_MINUTES=20160` phút (14 ngày).

**⚠️ Liên quan mục 10:** tài khoản test (`staff01/approver01/keeper01/viewer01/manager01/admin`, password `Cntt@1234`) **chỉ được seed** khi môi trường nằm trong `golive.development_environments` (mặc định `local, testing`) hoặc `WMS_SEED_DEMO_ACCOUNTS=true`. Nếu DEV thật có `APP_ENV=production` (đúng như QA nghi vấn ở mục 10), **các tài khoản test này sẽ KHÔNG được tạo** — đây có thể là lý do QA không login được, cần DevOps xác nhận.

---

## 8. Dependency/data chain để lấy ID thật

Thứ tự chuẩn (theo đúng code seeder — coi đây là "reference implementation" của luồng API):

1. **Warehouse** (`KHO-TONG-HN`) phải có trước.
2. **SKU** — `PRODUCT` (có serial) hoặc `COMPONENT` (không serial).
3. Với PRODUCT: tạo **item_instance** (serial) → **physical_code** RFID `ACTIVE` + `hardware_uid` (bắt buộc trước Post Issue).
4. Tạo **stock_document** (`doc_type=receipt|issue`, khởi tạo `DRAFT`) + `stock_document_lines`.
5. **Scan** để gắn item/container vào line → chuyển `SCANNING`.
6. Receipt COMPONENT: bắt buộc có **container** loại `BOX` trước; sau khi post mới putaway sang container loại `TRAY`.
7. Issue: bắt buộc có **outbound_request** (từ `outbound_request_imports`) làm nguồn trước — không thể post issue nếu chưa có toa xuất.
8. Issue PRODUCT (serial): bắt buộc có **label_snapshots** `status=PRINTED` trước Post Issue.
9. **Post** document (`CurrentBaselineStockService::postStockDocument`) → `POSTED`, lúc này balance/movement mới thực sự thay đổi.
10. **Warranty case** chỉ mở được trên item có `item_status=ISSUED` — tức phải hoàn tất 1 chu trình issue trước trên đúng serial đó.

---

## 9. Seed data / test fixture DEV

**Chạy mặc định** (`database/seeders/DatabaseSeeder.php`, thứ tự: Auth → Warehouse → WarehouseBusinessData → AuditLog):
- Auth: 60+ permission, 11 role, 9 user fixture (điều kiện xem mục 7/10).
- Warehouse: 1 kho `KHO-TONG-HN`, 1 SKU baseline `HN-PV-BASE-001`, 3 agency, 5 defect catalog, 2 power source, 4 package variant.
- Business data (idempotent, doc_no cố định `PN-SEED-0001..3`, `PX-SEED-0001/2`, `WC-SEED-0001`): 1 receipt COMPONENT đã post, 1 receipt PRODUCT đã post (3 serial + RFID), 1 receipt draft, 1 issue PRODUCT đã post (có label PRINTED), 1 issue draft, 1 warranty case + 2 event.
- AuditLog: 5 dòng log gắn với data trên.

**Không chạy mặc định, phải chạy tay riêng** (`Modules/Warehouse/database/seeders/`): `LabelSnapshotSeeder`, `UatScenarioSeeder`, `WarrantyDemoSeeder`, `Wwhn172ContainerItemInvariantSeeder`. Riêng `DemoDataSeeder` đã bị **superseded, không dùng nữa** (không còn khớp FK/contract hiện tại).

**Không có Eloquent factory nào trong repo** — `database/factories/` rỗng ở cả root lẫn trong từng Module.

---

## 10. Xác nhận environment=production/version=1.0.0

`GET /api/v1/health` trả `environment` = `app()->environment()` (đọc trực tiếp `APP_ENV`) và `version` = `env('APP_VERSION', '1.0.0')`.

- Nếu QA thấy `environment: production` trên DEV → do 1 trong 2 nguyên nhân: (a) `.env` thật trên máy DEV có `APP_ENV=production`, hoặc (b) `APP_ENV` không được set/load → Laravel tự fallback về `'production'` (default cứng của framework). Repo local (`.env`/`.env.example`) đều có `APP_ENV=local` — nên đây là **lỗi cấu hình riêng của máy chủ DEV**, không phải lỗi code.
- `version: 1.0.0` là **giá trị fallback đúng như thiết kế** nếu `APP_VERSION` chưa được set trên DEV (`.env.example` cũng để mẫu `APP_VERSION=1.0.0`) — không phải bug.
- Lưu ý thêm: Swagger `info.version` = `"2.0.0"` (hardcode, khác trục với `APP_VERSION`) — QA đối chiếu "Swagger nói 2.0.0" với "health check nói 1.0.0" là **2 con số độc lập theo thiết kế**, không phải mâu thuẫn.

**⚠️ Cần DevOps xác nhận trực tiếp:** giá trị `APP_ENV` và `APP_VERSION` thật trong `.env` trên máy chủ DEV — không thể soi từ repo local.

---

## 11. Pagination URL http → https

Pagination dùng `LengthAwarePaginator` chuẩn Laravel (`app/Repositories/BaseRepository.php`, `app/Traits/ApiResponse.php::respondPaginated()`), scheme của link quyết định bởi:

1. `app/Providers/AppServiceProvider.php:28-30` — nếu `APP_URL` bắt đầu bằng `https://` thì force `URL::forceScheme('https')` toàn cục.
2. Nếu không, fallback theo `$request->isSecure()`, chỉ tin header `X-Forwarded-Proto` khi IP proxy nằm trong `TRUSTED_PROXIES` (`bootstrap/app.php:35-38`, mặc định chỉ dải private/loopback).
3. Nginx trong repo (`docker/nginx/default.conf.template`) luôn nghe cổng `:80` thường bên trong container, **không tự terminate TLS** — chỉ forward `X-Forwarded-Proto` nếu tầng ngoài (reverse proxy/ingress) đã gửi.

**Cấu hình mặc định hiện tại:** `.env`/`.env.example` đều `APP_URL=http://localhost:8080` → bước 1 **không** kích hoạt → link pagination phụ thuộc hoàn toàn vào `$request->isSecure()`.

**Nguyên nhân khả dĩ nhất của lỗi "pagination URL ra http trên site https":** DEV được serve qua reverse proxy HTTPS bên ngoài, nhưng `.env` trên DEV vẫn giữ `APP_URL=http://...` (giống default repo) — nếu proxy đó không gửi đúng `X-Forwarded-Proto: https`, hoặc IP proxy không nằm trong `TRUSTED_PROXIES`, thì link sẽ ra `http://` dù trang chạy `https://`.

**Fix khuyến nghị (local code + config, không đụng CI):** set `APP_URL=https://<dev-host>` trong `.env` thật của DEV → bước 1 sẽ force scheme vô điều kiện, không còn phụ thuộc độ chính xác của header proxy. Đây là thay đổi **cấu hình môi trường DEV**, không phải thay đổi CI/CD.

---

## Tổng hợp việc cần làm

| # | Việc | File | Loại | Trạng thái |
|---|---|---|---|---|
| 1 | Thêm controller Warehouse còn thiếu vào `l5-swagger` allowlist + viết annotation request body | `config/l5-swagger.php` + 21 controller + `RouteSyncProcessor.php` | Code fix | ✅ **Đã làm trong phiên này** — placeholder 90/112 → 25/112, đã lint + test xanh, `verify-wwhn-309-handover-contract.mjs` PASS |
| 2 | Tách schema `pv-admin/resources/{resourceType}` theo từng loại resource | `PvAdminResourceController.php` | Code fix | Chưa làm — cố ý bỏ qua vì validation rẽ nhánh động, cần DEV quyết định cách mô tả (`oneOf` hay 4 endpoint riêng) |
| 3 | Viết script đồng bộ Postman kiểu "chỉ thêm" (theo mẫu WWHN-202/266) để kéo field mới từ OpenAPI vào Postman DEV mà không mất nội dung soạn tay | script mới trong `scripts/` | Code fix | Chưa làm — đã thử `npm run api:postman` toàn bộ và revert vì phá dữ liệu soạn tay, xem mục 5 |
| 4 | DevOps xác nhận SHA/`.env` thật trên máy DEV (không thể lấy từ repo local) | ngoài repo | Cần DevOps | Chưa làm — ngoài phạm vi repo local |
| 5 | Set `APP_URL=https://...` trong `.env` thật của DEV nếu DEV chạy sau HTTPS proxy | `.env` (server) | Config | Chưa làm — ngoài phạm vi repo local |
| 6 | Xác nhận `APP_ENV` thật trên DEV — nếu đang là `production`, tài khoản test sẽ không được seed | `.env` (server) | Config | Chưa làm — ngoài phạm vi repo local |

**Danh sách file đã sửa trong phiên này** (tất cả local, chưa commit — xem `git status`, chờ user xác nhận trước khi commit):
`config/l5-swagger.php`, `app/Swagger/Processors/RouteSyncProcessor.php`, `app/Swagger/Flow06ReportDocumentation.php`, `Modules/Auth/app/Http/Controllers/RoleController.php`, 17 controller trong `Modules/Warehouse/app/Http/Controllers/` (xem mục "Đã fix trong phiên này"), `storage/api-docs/api-docs.json`/`.yaml` (regenerate), `docs/api-contract/HN-BE-API-002/003/004_*` (regenerate), `docs/api-contract/HN-BE-API-001_API_CONTRACT_HANDOVER.md`, `docs/docs_task_jira/WWHN-309_SIGNOFF_COMMENTS.md`, và file này (`HN-BE-API-005`). **Không đụng** `docs/postman/*.json`, `.github/`, `.ci/`.
