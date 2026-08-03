# [BE1] Chủ trì rà soát API/dữ liệu — WWHN-180

## 1. Document control

- Parent gate: `WWHN-40` — G1 API/data contract review.
- Related gates: `WWHN-181` BA decision, `WWHN-182` QA2 verification, `WWHN-183` BA closure.
- Repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`.
- Branch triển khai: **`m1` בלבד**; không tạo branch/PR riêng theo yêu cầu thực hiện.
- Contract status: **PENDING INDEPENDENT SIGN-OFF**.
- Repository implementation status: **BE FIX IMPLEMENTED — CI/REVIEW EVIDENCE REQUIRED**.
- Không được đổi sang `APPROVED`, `Done` hoặc `100%` cho đến khi BA, FE1 và QA2 xác nhận độc lập trên cùng exact commit SHA và artifact digest.

## 2. Kết quả xử lý P0/P1/P2

| ID | Kết quả đã triển khai trên `m1` | Trạng thái repository | Điều kiện đóng độc lập |
|---|---|---|---|
| `BE1-P0-01` | Khóa trạng thái tài liệu ở `PENDING INDEPENDENT SIGN-OFF`; định nghĩa approval ledger và quy tắc chống false closure. | IMPLEMENTED | BA ghi G1 decision; FE1 mapping sign-off; QA2 attach evidence cùng SHA/hash. |
| `BE1-P0-02` | Gia cố Scan/Post Receipt/Post Issue; chống double-post cùng key, khác payload, khác key; optimistic status lock; ledger lock; reconciliation hai chiều; Postman 31 request. | IMPLEMENTED — chờ CI exact SHA | FE/BA xác nhận contract; QA2 staging retest stock-effect, movement và balance. |
| `BE1-P1-01` | CI xuất OpenAPI JSON/YAML, Postman collection/environment, version và `SHA256SUMS` trong cùng artifact. | IMPLEMENTED | Reviewer xác nhận artifact name, exact SHA và digest. |
| `BE1-P1-02` | Có immutable commit history trực tiếp trên `m1`, raw route list, migrate/seed log, PHPUnit log, Newman log/result, build metadata. Không có PR vì yêu cầu sửa trực tiếp `m1`. | IMPLEMENTED — deployment SHA để `null` khi chưa deploy | Deployment owner bổ sung build/deploy SHA thật nếu có. |
| `BE1-P1-03` | Permission catalog runtime + route-permission mapping + verifier đối chiếu OpenAPI/route/Postman. | IMPLEMENTED | FE1/BA review `ROUTE_PERMISSIONS` và ký xác nhận. |
| `BE1-P1-04` | CI chạy full PHPUnit, Newman happy/negative/RBAC/idempotency/double-post/reconcile, DB snapshot before/after. | AUTOMATION IMPLEMENTED | QA2 chạy độc lập trên build SHA và attach raw evidence. |
| `BE1-P1-05` | Chuẩn hóa G1 ledger, closure criteria và minutes input. | IMPLEMENTED IN DOC | BA lập minutes thật trên `WWHN-40`. |
| `BE1-P2-01` | Chuẩn hóa closure matrix ngắn và Jira comment payload ở mục 11. | READY TO POST | BE1/BA đăng comment Jira thật, điền reviewer/status/link thực tế. |

## 3. Các thay đổi kỹ thuật đã commit

| Commit | Nội dung |
|---|---|
| `8c20ba197f0c5778d2a9ef61500af68d0428def6` | Đối soát tồn kho trên hợp dimension của balance và ledger; phát hiện `BALANCE_MISSING_FOR_LEDGER`, `LEDGER_MISSING_FOR_BALANCE`, `BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM`. |
| `af5ee57cbdb42fb41071c371bc2d17ed82bd9203` | Gia cố tạo/khóa balance khi transaction cạnh tranh. |
| `f4b6287f0c4648ec8e07c27afc6f944eb47a97fd` | Test API phát hiện sai lệch ledger/balance theo hai chiều. |
| `d8393302e348e6dae32ba7c390fede694c506b1a`, `84496bd647a34fb943f099265ba471d3cc2d0500` | Lệnh `evidence:stock-snapshot`, dữ liệu portable giữa SQLite/MySQL. |
| `ba5d617980c43cdccb9701381cbecd6ead19639c` | Verifier v2 đối chiếu runtime route ↔ middleware ↔ OpenAPI ↔ permission catalog ↔ Postman. |
| `155e8db3a1ac60d6f2087ccd749d6e9394170ed1` | Evidence pipeline chạy migration/seed, Newman, DB before/after và checksum. |
| `5567180c81a0fd0a7c306fa346cc5525027ee0b8` | CI quality gate cho PHP syntax, scoped Pint, full test, OpenAPI lint, Newman, JSON/checksum validation và artifact upload. |
| `b029f74871aafc7832a6087126a6978edb969005` | Environment Postman CI không lưu credential/token. |
| `62e28d9ee36b59f72203a9984828d33ffd5f3590`, `8ad286915f08efe2a761de74d65d1b4fee574fb7` | Postman canonical/negative flow; bổ sung idempotency, replay, payload conflict, double-post khác key và thiếu key cho cả inbound/outbound. |
| `a35dca181ea512a4152706ee2e7c7d8797728368` | Ledger trả `409 STATE_CONFLICT` khi movement business key đã tồn tại; không ghi status/history lần hai. |
| `c08010fcd9538416baefc2862adaa726cf0b403a` | Khóa row chứng từ trước scan và mọi state transition dùng trait chung. |
| `57a2905ca229679c2fe9799d70ee865093ef9a4a` | Optimistic status lock tại model; model cũ không thể ghi đè trạng thái mới, transaction bị rollback. |
| `c3efffca40c9f141cc541136a26e52e67926f07e` | Regression test chống stale model overwrite. |

> Exact review SHA không lấy từ nội dung viết tay. Nguồn chuẩn là `build/evidence/api-data/build-metadata.json.commit_sha` của CI run được reviewer chọn.

## 4. Contract đã khóa

| Action | Stock effect | Movement/balance | Permission | Idempotency |
|---|---|---|---|---|
| `POST /api/v1/inbound-documents/{id}/scan` | `NONE` | Không tạo movement, không đổi balance/status | `stock_document.scan` | Không bắt buộc |
| `POST /api/v1/outbound-documents/{id}/scan` | `NONE` | Không tạo movement, không đổi balance/status | `stock_document.scan` | Không bắt buộc |
| `POST /api/v1/inbound-documents/{id}/post-receipt` | Tăng tồn | Signed movement + balance, đúng một lần | `stock_document.post` | Bắt buộc `Idempotency-Key` |
| `POST /api/v1/outbound-documents/{id}/post-issue` | Giảm tồn | Signed movement + balance, đúng một lần | `stock_document.post` | Bắt buộc `Idempotency-Key` |
| `GET /api/v1/reports/inventory-reconciliation` | Read-only | So sánh hợp dimension balance/ledger theo `warehouse_id/product_id/stock_status` | `report.view` | Không áp dụng |

### 4.1 Quy tắc idempotency và cạnh tranh

- Same key + same request: replay response đã commit, không chạy StockLedger lần hai.
- Same key + different payload: `409 IDEMPOTENCY_ERROR`.
- Thiếu key ở `post-receipt`/`post-issue`: `428 PRECONDITION_REQUIRED`.
- Different key + chứng từ đã post: `409 STATE_CONFLICT`.
- Movement business key đã tồn tại: transaction bị từ chối; không đổi balance/status/history lần hai.
- Stale model status: optimistic status lock so sánh original status với DB status dưới row lock; lệch version trả `409 STATE_CONFLICT` và rollback transaction.
- Scan/state transition dùng row lock chung để không scan trên trạng thái đã bị request khác thay đổi.

## 5. Immutable evidence pack

CI tạo artifact `api-data-evidence-<exact_commit_sha>` gồm:

- `openapi.json`, `openapi.yaml` — contract sinh từ source annotation.
- `postman.collection.json`, `postman.environment.json` — collection v2.0.0 và environment không chứa secret.
- `route-list.raw.json` — `php artisan route:list --path=api/v1 -vv --json`.
- `permission-catalog.json` — active permissions, role mapping và runtime route mapping.
- `contract-verification.json` — kết quả verifier v2, trạng thái `PENDING_INDEPENDENT_SIGN_OFF`.
- `phpunit.raw.log`, `openapi-generate.raw.log`.
- `migrate-fresh.raw.log`, `database-seed.raw.log`.
- `newman.raw.log`, `newman.result.json`.
- `db-before.json`, `db-after.json` — counts, balance, signed ledger, CI documents, posting events.
- `health-response.json`, `server.raw.log`.
- `build-metadata.json` — exact SHA, runtime versions, DB driver, collection version, deployment SHA.
- `SHA256SUMS` — digest của toàn bộ file artifact.

Nguồn tạo evidence:

- `.github/workflows/auth-rbac-audit-contract.yml`
- `scripts/collect-api-data-evidence.sh`
- `scripts/verify-api-data-contract.mjs`
- `app/Console/Commands/ExportPermissionCatalog.php`
- `app/Console/Commands/ExportStockSnapshot.php`

Lệnh local tương đương:

```bash
bash scripts/collect-api-data-evidence.sh
(cd build/evidence/api-data && sha256sum --check SHA256SUMS)
```

## 6. Postman/Newman coverage

Collection hiện có **31 request tuần tự**, gồm:

- Health, manager login, no-permission login, dynamic warehouse/product discovery.
- Inbound: create → submit → approve → scan → post → replay → payload conflict → different-key conflict.
- Outbound: create → submit → approve → scan → post → replay → payload conflict → different-key conflict.
- Dedicated inbound/outbound documents để kiểm tra thiếu `Idempotency-Key` trả `428`.
- RBAC reconciliation với user không quyền trả `403 ACCESS_DENIED`.
- Reconciliation theo dimension trả `PASS`, `mismatch_count = 0` sau canonical flow.

Credential chỉ được truyền qua CLI `--env-var` trong CI; không commit token/password vào collection/environment.

## 7. RBAC catalog và FE mapping

Source of truth:

- `Modules/Auth/database/seeders/AuthDatabaseSeeder.php`
- `Modules/Warehouse/routes/api.php`
- Artifact runtime: `permission-catalog.json`

| FE route/action | Required permission | FE reviewer status |
|---|---|---|
| Scan inbound | `stock_document.scan` | PENDING FE1 |
| Scan outbound | `stock_document.scan` | PENDING FE1 |
| Post receipt | `stock_document.post` | PENDING FE1 |
| Post issue | `stock_document.post` | PENDING FE1 |
| Inventory reconciliation | `report.view` | PENDING FE1 |
| Product read/write | `product.view` / `product.manage` | PENDING FE1 |
| SKU alias | `sku.manage` | PENDING FE1 |

FE1 comment bắt buộc có reviewer, exact commit SHA, artifact digest, FE mapping version, result và điều kiện còn lại. BE1 tự xác nhận không được tính là independent FE review.

## 8. Verification matrix cho QA2

| Nhóm | Bắt buộc verify | Expected |
|---|---|---|
| Happy path | Create → submit → approve → scan → post | Scan delta 0; post signed delta đúng một lần. |
| Negative | Sai state, thiếu permission/key, insufficient stock | HTTP/error code chuẩn; không có movement ngoài ý muốn. |
| RBAC | User thiếu `scan/post/report` | `403 ACCESS_DENIED`. |
| Idempotency | Same key/same payload; same key/different payload | Replay; `409 IDEMPOTENCY_ERROR`. |
| Double-post | Different key sau post; request cạnh tranh | `409 STATE_CONFLICT`; không double movement/balance/status history. |
| DB before/after | `stock_movements`, `inventory_balances`, `posting_event` | Scan delta 0; post delta có dấu đúng. |
| Reconcile | Cả balance-only và ledger-only dimension | Mismatch có reason chính xác; canonical flow `PASS`. |
| Build binding | Newman/DB evidence | Cùng exact build SHA và artifact digest. |

CI evidence là BE automated evidence, **không thay thế QA2 sign-off**.

## 9. G1 approval ledger

| Gate | Owner | Required evidence | Current status |
|---|---|---|---|
| G1 decision | BA | Go / Conditional Go / No-Go, blocker mapping, scope, điều kiện | PENDING BA |
| API mapping | FE1 | Route/schema/error/permission/idempotency review trên exact SHA | PENDING FE1 |
| QA2 verification | QA2 | Newman/raw API log + DB before/after + digest trên build SHA | PENDING QA2 |
| BE implementation | BE1 | Commits trên `m1`, CI config, evidence pipeline, checksum | IMPLEMENTED; latest CI run chưa được ghi nhận trong tài liệu |
| Final closure | BA/PM | G1 minutes liên kết WWHN-181/182/183 | PENDING |

## 10. Acceptance checklist trước khi đổi Approved

- [ ] Chọn một exact commit SHA trên `m1`; không review theo tên branch động.
- [ ] CI workflow hoàn tất `success` trên SHA đó.
- [ ] Download artifact `api-data-evidence-<SHA>`.
- [ ] `sha256sum --check SHA256SUMS` PASS.
- [ ] `contract-verification.json.summary.failed = 0`.
- [ ] PHPUnit, Newman, OpenAPI lint và build đều PASS.
- [ ] QA2 đối chiếu `db-before.json`/`db-after.json` và attach evidence độc lập.
- [ ] FE1 xác nhận route/schema/error/permission/idempotency mapping.
- [ ] BA ghi G1 decision và final minutes trên WWHN-40/WWHN-181/WWHN-183.
- [ ] Chỉ sau toàn bộ checklist mới đổi `APPROVED`.

## 11. Jira closure comment

```text
[WWHN-180 API/DATA CLOSURE MATRIX]
Branch: m1 (direct commits; no separate PR by execution constraint)
Exact review SHA: <40-char SHA from build-metadata.json>
CI run: <run id / URL / status>
Artifact: api-data-evidence-<SHA>
Artifact manifest: SHA256SUMS = PASS|FAIL
OpenAPI/route/RBAC/Postman verifier: PASS|FAIL
PHPUnit: PASS|FAIL
Newman Scan/Post/RBAC/Idempotency/Reconcile: PASS|FAIL
DB before/after: PASS|FAIL — attachment/link

BE1-P0-01: IMPLEMENTED / PENDING BA+FE1+QA2 SIGN-OFF
BE1-P0-02: IMPLEMENTED / PENDING INDEPENDENT VERIFY
BE1-P1-01: IMPLEMENTED / artifact hash <digest>
BE1-P1-02: IMPLEMENTED / direct commit list in repository document
BE1-P1-03: IMPLEMENTED / PENDING FE1 REVIEW
BE1-P1-04: AUTOMATED / PENDING QA2 INDEPENDENT RETEST
BE1-P1-05: TEMPLATE READY / PENDING BA G1 MINUTES
BE1-P2-01: COMMENT POSTED / reviewer statuses below

BA G1: PENDING|GO|CONDITIONAL GO|NO-GO — reviewer/date/link
FE1 mapping: PENDING|PASS|FAIL — reviewer/date/link
QA2 API+DB: PENDING|PASS|FAIL — reviewer/date/link
WWHN-181: <status>
WWHN-182: <status>
WWHN-183: <status>
Final status: BLOCKED|CONDITIONAL|APPROVED
Conditions/blockers: <list>
```

## 12. Quy tắc chống false closure

- Không dùng `Approved`, `Done`, `100%` nếu thiếu BA, FE1 hoặc QA2 independent sign-off.
- Không dùng test count/hash viết tay làm evidence chính; phải dùng artifact gắn exact SHA.
- Không dùng deployment SHA giả; chưa deploy thì `deployment_sha = null`.
- Không sửa trực tiếp ledger/balance để làm reconcile pass.
- Không lấy CI của commit khác làm evidence.
- Không coi automated BE test là QA2 independent verification.
- Mọi retest phải tham chiếu cùng OpenAPI/Postman version, commit SHA và artifact digest.
