# [BE1] Chủ trì rà soát API/dữ liệu — WWHN-180

## 1. Document control

- Parent gate: `WWHN-40` — G1 API/data contract review.
- Related gates: `WWHN-181` BA decision, `WWHN-182` QA2 verification, `WWHN-183` BA closure.
- Repository branch under review: `m1`.
- Contract status: **PENDING INDEPENDENT SIGN-OFF**.
- Không được đổi sang `APPROVED` cho đến khi BA, FE1 và QA2 xác nhận độc lập trên cùng immutable commit SHA và artifact digest.

## 2. Quyết định contract bắt buộc

| Action | Stock effect | Movement/balance | Permission | Idempotency |
|---|---|---|---|---|
| `POST /api/v1/inbound-documents/{id}/scan` | `NONE` | Không tạo movement, không đổi balance | `stock_document.scan` | Không bắt buộc vì không đổi tồn |
| `POST /api/v1/outbound-documents/{id}/scan` | `NONE` | Không tạo movement, không đổi balance | `stock_document.scan` | Không bắt buộc vì không đổi tồn |
| `POST /api/v1/inbound-documents/{id}/post-receipt` | Tăng tồn | Tạo signed movement và cập nhật balance | `stock_document.post` | Bắt buộc `Idempotency-Key` |
| `POST /api/v1/outbound-documents/{id}/post-issue` | Giảm tồn | Tạo signed movement và cập nhật balance | `stock_document.post` | Bắt buộc `Idempotency-Key` |
| `GET /api/v1/reports/inventory-reconciliation` | Read-only | So sánh balance với signed `SUM(qty_delta)` theo `warehouse_id/product_id/stock_status` | `report.view` | Không áp dụng |

## 3. Immutable evidence pack

CI tạo artifact tên `api-data-evidence-<exact_commit_sha>` và chứa:

- `openapi.json` — OpenAPI JSON sinh từ source annotations.
- `openapi.yaml` — OpenAPI YAML cùng nội dung/version.
- `postman.collection.json` — Postman collection được commit.
- `postman.environment.json` — environment không chứa credential.
- `route-list.raw.json` — raw output `php artisan route:list --path=api/v1 -vv --json`.
- `permission-catalog.json` — permission catalog, role mapping và route-permission mapping lấy từ DB seed/runtime routes.
- `contract-verification.json` — kết quả kiểm tra chéo runtime route ↔ OpenAPI ↔ permission catalog.
- `phpunit.raw.log` — full raw Laravel test log.
- `openapi-generate.raw.log` — raw Swagger generation log.
- `migrate-fresh.raw.log` và `auth-seed.raw.log` — migration/seed evidence.
- `build-metadata.json` — exact commit SHA, runtime versions, database driver, approval status, deployment SHA.
- `SHA256SUMS` — SHA-256 của từng file trong evidence pack.

Nguồn tạo evidence:

- `.github/workflows/auth-rbac-audit-contract.yml`
- `scripts/collect-api-data-evidence.sh`
- `scripts/verify-api-data-contract.mjs`
- `app/Console/Commands/ExportPermissionCatalog.php`

## 4. RBAC catalog và FE mapping

Permission source of truth:

- `Modules/Auth/database/seeders/AuthDatabaseSeeder.php`
- `Modules/Warehouse/routes/api.php`
- Artifact runtime: `permission-catalog.json`

FE1 phải review các route sau trên đúng artifact/commit SHA:

| FE route/action | Required permission | FE reviewer status |
|---|---|---|
| Scan inbound | `stock_document.scan` | PENDING FE1 |
| Scan outbound | `stock_document.scan` | PENDING FE1 |
| Post receipt | `stock_document.post` | PENDING FE1 |
| Post issue | `stock_document.post` | PENDING FE1 |
| Inventory reconciliation | `report.view` | PENDING FE1 |
| Product read/write | `product.view` / `product.manage` | PENDING FE1 |
| SKU alias | `sku.manage` | PENDING FE1 |

FE1 xác nhận phải ghi rõ: reviewer, exact commit SHA, artifact digest, route mapping version, result và điều kiện còn lại. Cùng assignee BE1 không được tính là independent FE review.

## 5. Verification matrix cho QA2

QA2 phải chạy trên cùng build SHA và attach evidence độc lập:

| Nhóm | Bắt buộc verify | Expected |
|---|---|---|
| Happy path | Create → submit → approve → scan → post | Scan không đổi tồn; post đổi tồn đúng một lần |
| Negative | Sai state, thiếu permission, thiếu idempotency key, insufficient stock | Chuẩn HTTP/error code, không có movement ngoài ý muốn |
| RBAC | User thiếu `scan/post/report` permission | `403 ACCESS_DENIED` |
| Idempotency | Same key/same request; same key/different request | Replay cùng kết quả; conflict khi payload khác |
| Race/double post | Hai request post cạnh tranh | Không double movement/balance |
| DB before/after | `stock_movements`, `inventory_balances`, `posting_event` | Scan delta 0; post signed delta đúng |
| Reconcile | Signed `SUM(qty_delta)` theo stock status | `MATCH` khi ledger/balance khớp; mismatch có reason |

CI evidence là BE automated evidence, **không thay thế QA2 sign-off**.

## 6. G1 approval ledger

| Gate | Owner | Required evidence | Current status |
|---|---|---|---|
| G1 decision | BA | Go / Conditional Go / No-Go, blocker mapping, scope và điều kiện | PENDING BA |
| API mapping | FE1 | Route/schema/error/permission/idempotency review trên exact SHA | PENDING FE1 |
| QA2 verification | QA2 | Test report + Newman/raw API log + DB before/after + artifact digest | PENDING QA2 |
| BE implementation | BE1 | PR/commit, CI run, evidence pack, SHA256SUMS | GENERATED BY CI; chờ exact run |
| Final closure | BA/PM | G1 minutes liên kết WWHN-181/182/183 | PENDING |

## 7. Closure criteria theo issue register

| ID | Repository-controlled result | External closure required |
|---|---|---|
| BE1-P0-01 | Không claim Approved; status bị khóa ở `PENDING INDEPENDENT SIGN-OFF`; có approval ledger | BA G1 + FE1 + QA2 sign-off |
| BE1-P0-02 | Contract verifier khóa Scan/Post/OpenAPI/permission; full raw test log và DB evidence commands được artifact hóa | FE/BA review và QA2 staging retest |
| BE1-P1-01 | JSON, YAML, Postman collection và environment cùng exact artifact SHA/digest | Reviewer xác nhận version/hash |
| BE1-P1-02 | PR/commit, raw route list, seed log/diff source, build metadata và raw logs | Deployment owner điền deployment SHA nếu có |
| BE1-P1-03 | Permission catalog + role matrix + route mapping được xuất từ runtime | BA/FE1 reviewer comment |
| BE1-P1-04 | Full automated suite, contract verification, migration/seed logs | QA2 Newman/API/DB/race evidence độc lập |
| BE1-P1-05 | G1 minutes template và blocker matrix đã định nghĩa | BA chủ trì ghi minutes thật trên WWHN-40 |
| BE1-P2-01 | Closure matrix ngắn được chuẩn hóa bên dưới | BE1/BA đăng comment thật trên Jira |

## 8. Jira closure comment template

```text
[WWHN-180 API/DATA CLOSURE]
Commit SHA: <40-char SHA>
PR: <PR number>
CI run: <run id/status>
Artifact: api-data-evidence-<SHA>
Artifact digest: <sha256>
OpenAPI/Postman/route/RBAC verification: PASS|FAIL
BA G1: PENDING|GO|CONDITIONAL GO|NO-GO — reviewer/date/link
FE1 mapping: PENDING|PASS|FAIL — reviewer/date/link
QA2 API+DB: PENDING|PASS|FAIL — reviewer/date/link
WWHN-181: <status>
WWHN-182: <status>
WWHN-183: <status>
Final status: BLOCKED|CONDITIONAL|APPROVED
Conditions/blockers: <list>
```

## 9. Quy tắc chống false closure

- Không dùng từ `Approved`, `Done`, `100%` nếu thiếu một trong BA, FE1, QA2 independent sign-off.
- Không dùng test count hoặc hash viết tay trong tài liệu làm evidence chính; nguồn chính là artifact gắn exact commit SHA.
- Không dùng deployment SHA giả. Nếu chưa deploy, `deployment_sha = null`.
- Không sửa trực tiếp ledger hoặc balance để làm reconcile pass.
- Mọi retest phải tham chiếu cùng OpenAPI/Postman version, commit SHA và artifact digest.
