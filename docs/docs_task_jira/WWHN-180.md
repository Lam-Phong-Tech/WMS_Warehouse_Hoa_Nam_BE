# [BE1] Chủ trì rà soát API/dữ liệu — WWHN-180

## 1. Trạng thái kiểm soát

- Parent gate: `WWHN-40` — G1 API/data contract review.
- Related: `WWHN-181` BA decision, `WWHN-182` QA2 verification, `WWHN-183` BA closure.
- Repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`.
- Branch triển khai: **chỉ `m1`**, không tạo branch/PR riêng theo yêu cầu thực hiện.
- BE implementation: **IMPLEMENTED ON M1**.
- Contract approval: **PENDING INDEPENDENT SIGN-OFF**.
- Không được đổi sang `APPROVED`, `Done` hoặc `100%` khi chưa có BA + FE1 + QA2 xác nhận độc lập trên cùng exact commit SHA và artifact digest.

## 2. Ma trận xử lý P0/P1/P2

| ID | Việc đã làm trên `m1` | Trạng thái | Điều kiện đóng |
|---|---|---|---|
| `BE1-P0-01` | Khóa approval status, approval ledger, checklist và quy tắc chống false closure. | BE DONE | BA G1 decision + FE1 mapping + QA2 evidence trên cùng SHA/hash. |
| `BE1-P0-02` | Gia cố Scan/Post Receipt/Post Issue, ledger/balance, idempotency, double-post, stale status, reconciliation và regression tests. | BE DONE; chờ CI exact SHA | FE/BA review contract; QA2 staging retest API/DB. |
| `BE1-P1-01` | OpenAPI JSON/YAML + Postman collection/environment cùng artifact, version và `SHA256SUMS`. | BE DONE | Reviewer xác nhận exact SHA/digest. |
| `BE1-P1-02` | Immutable commits trực tiếp trên `m1`, route list, migration/seed log, PHPUnit/Newman raw logs, build metadata. | BE DONE | Điền deployment SHA thật nếu có; chưa deploy giữ `null`. |
| `BE1-P1-03` | Permission catalog runtime, role mapping, route-permission mapping, verifier OpenAPI/route/RBAC/Postman. | BE DONE | FE1 + BA review và ký mapping. |
| `BE1-P1-04` | Full PHPUnit, Newman happy/negative/RBAC/idempotency/double-post/reconcile, DB before/after snapshot. | AUTOMATION DONE | QA2 chạy độc lập và attach evidence. |
| `BE1-P1-05` | G1 ledger, blocker matrix, closure criteria và minutes input. | DOC DONE | BA ghi minutes thật trên `WWHN-40`. |
| `BE1-P2-01` | Closure matrix và Jira payload đã chuẩn hóa ở mục 10. | READY; NOT POSTED | Atlassian connector trả `403: app is not installed`; BE1/BA cần đăng khi connector/site khả dụng. |

## 3. Contract khóa tại G1

| Action | Stock effect | Permission | Idempotency |
|---|---|---|---|
| `POST /api/v1/inbound-documents/{id}/scan` | `NONE`; không movement/balance/status | `stock_document.scan` | Không bắt buộc |
| `POST /api/v1/outbound-documents/{id}/scan` | `NONE`; không movement/balance/status | `stock_document.scan` | Không bắt buộc |
| `POST /api/v1/inbound-documents/{id}/post-receipt` | Signed movement dương + balance, đúng một lần | `stock_document.post` | Bắt buộc `Idempotency-Key` |
| `POST /api/v1/outbound-documents/{id}/post-issue` | Signed movement âm + balance, đúng một lần | `stock_document.post` | Bắt buộc `Idempotency-Key` |
| `GET /api/v1/reports/inventory-reconciliation` | Read-only; hợp dimension balance + ledger | `report.view` | Không áp dụng |

### 3.1 Idempotency và concurrency

- Same key + same request: replay response, không chạy ledger lần hai.
- Same key + different payload: `409 IDEMPOTENCY_ERROR`.
- Thiếu key ở post receipt/issue: `428 PRECONDITION_REQUIRED`.
- Different key sau khi đã post: `409 STATE_CONFLICT`.
- Movement business key tồn tại: rollback; không ghi balance/status/history lần hai.
- Stale document model: optimistic status check dưới row lock; lệch status trả `409 STATE_CONFLICT` và rollback toàn transaction.
- Scan và state transition lấy row lock chứng từ để không dùng trạng thái cũ.
- Balance mới dùng `insertOrIgnore` + reselect `lockForUpdate`; balance hiện hữu dùng row lock + version guard.

## 4. Reconciliation đã sửa

Reconciliation không còn chỉ xuất phát từ `inventory_balances`. Dimension được tạo từ hợp của:

- `inventory_balances(warehouse_id, product_id, stock_status)`;
- `stock_movements(warehouse_id, product_id, stock_status)`.

Mismatch reason:

- `BALANCE_MISSING_FOR_LEDGER`;
- `LEDGER_MISSING_FOR_BALANCE`;
- `BALANCE_NOT_EQUAL_SIGNED_LEDGER_SUM`.

Regression tests kiểm tra cả ledger-only và balance-only dimension.

## 5. Postman/Newman coverage

Collection v2.0.0 có **31 request tuần tự**:

- health, manager login, no-permission login, dynamic warehouse/product discovery;
- inbound create → submit → approve → scan → post → replay → payload conflict → different-key conflict;
- outbound create → submit → approve → scan → post → replay → payload conflict → different-key conflict;
- inbound/outbound riêng để test thiếu key `428`;
- reconciliation user không quyền `403 ACCESS_DENIED`;
- reconciliation canonical flow `PASS`, `mismatch_count = 0`.

Credential chỉ truyền qua Newman CLI `--env-var`; collection/environment không lưu password/token.

## 6. Immutable evidence pack

Workflow tạo artifact `api-data-evidence-<exact_commit_sha>` gồm:

- `openapi.json`, `openapi.yaml`;
- `postman.collection.json`, `postman.environment.json`;
- `route-list.raw.json`, `permission-catalog.json`, `contract-verification.json`;
- `phpunit.raw.log`, `openapi-generate.raw.log`;
- `migrate-fresh.raw.log`, `database-seed.raw.log`;
- `newman.raw.log`, `newman.result.json`;
- `db-before.json`, `db-after.json`;
- `health-response.json`, `server.raw.log`;
- `build-metadata.json`;
- `SHA256SUMS`.

CI gate:

1. PHP syntax toàn codebase.
2. Pint check toàn `app`, `Modules`, `tests`, `config`, `database`, `routes`.
3. Full PHPUnit.
4. OpenAPI generation + Redocly lint.
5. Runtime route/RBAC/OpenAPI/Postman verifier.
6. Migration + full DB seed.
7. Newman 31 request.
8. DB snapshot before/after.
9. JSON parse + SHA256 manifest verification.
10. npm lint/test/build.

Lệnh local:

```bash
bash scripts/collect-api-data-evidence.sh
(cd build/evidence/api-data && sha256sum --check SHA256SUMS)
```

## 7. Commit evidence

| Commit | Nội dung |
|---|---|
| `8c20ba197f0c5778d2a9ef61500af68d0428def6` | Reconciliation hai chiều balance/ledger. |
| `af5ee57cbdb42fb41071c371bc2d17ed82bd9203` | Lock/create balance an toàn khi cạnh tranh. |
| `f4b6287f0c4648ec8e07c27afc6f944eb47a97fd` | Test integrity reconciliation. |
| `d8393302e348e6dae32ba7c390fede694c506b1a`, `84496bd647a34fb943f099265ba471d3cc2d0500` | `evidence:stock-snapshot`, portable SQL. |
| `ba5d617980c43cdccb9701381cbecd6ead19639c` | Contract verifier v2. |
| `155e8db3a1ac60d6f2087ccd749d6e9394170ed1` | Newman + DB snapshot evidence pipeline. |
| `5567180c81a0fd0a7c306fa346cc5525027ee0b8`, `74fec56ba0c461ad59c281424694e6e803cb4647` | CI quality/evidence gate; full codebase Pint. |
| `b029f74871aafc7832a6087126a6978edb969005` | Postman CI environment không secret. |
| `62e28d9ee36b59f72203a9984828d33ffd5f3590`, `8ad286915f08efe2a761de74d65d1b4fee574fb7` | Postman canonical + full idempotency/double-post. |
| `a35dca181ea512a4152706ee2e7c7d8797728368` | Chặn movement trùng trước status/history. |
| `c08010fcd9538416baefc2862adaa726cf0b403a` | Row lock cho scan/state transition. |
| `57a2905ca229679c2fe9799d70ee865093ef9a4a` | Optimistic status lock ở model. |
| `c3efffca40c9f141cc541136a26e52e67926f07e` | Regression test stale model overwrite. |
| `ef84133afb49885690ac0165fb39f6fcba8e784b` | Cập nhật ma trận P0/P1/P2 và evidence. |

Exact review SHA phải lấy từ `build/evidence/api-data/build-metadata.json.commit_sha`; không lấy tên branch động hoặc hash viết tay trong tài liệu.

## 8. FE1 mapping review

| Action | Permission | Status |
|---|---|---|
| Scan inbound/outbound | `stock_document.scan` | PENDING FE1 |
| Post receipt/issue | `stock_document.post` | PENDING FE1 |
| Inventory reconciliation | `report.view` | PENDING FE1 |
| Product read/write | `product.view` / `product.manage` | PENDING FE1 |
| SKU alias | `sku.manage` | PENDING FE1 |

FE1 phải ghi reviewer, exact SHA, artifact digest, FE mapping version, result và blocker. BE1 tự review không tính là independent sign-off.

## 9. QA2 verification matrix

| Nhóm | Expected |
|---|---|
| Happy path | Scan delta 0; post signed delta đúng một lần. |
| Negative | Sai state/permission/key/stock trả đúng HTTP/error; không movement ngoài ý muốn. |
| Idempotency | Replay; payload conflict `409 IDEMPOTENCY_ERROR`. |
| Double-post/race | `409 STATE_CONFLICT`; không double movement/balance/status history. |
| DB before/after | Scan không đổi DB tồn; post delta đúng. |
| Reconcile | Canonical `PASS`; mismatch reason đúng cho ledger-only/balance-only. |
| Build binding | Tất cả evidence cùng exact SHA và artifact digest. |

CI BE evidence **không thay thế QA2 independent verification**.

## 10. Jira closure payload

```text
[WWHN-180 API/DATA CLOSURE MATRIX]
Branch: m1 — direct commits, no separate PR
Exact review SHA: <build-metadata.json.commit_sha>
CI run: <id/url/status>
Artifact: api-data-evidence-<SHA>
SHA256SUMS: PASS|FAIL
Contract verifier: PASS|FAIL
PHPUnit: PASS|FAIL
Newman: PASS|FAIL
DB before/after: PASS|FAIL — link/attachment

BE1-P0-01: BE IMPLEMENTED / PENDING BA+FE1+QA2
BE1-P0-02: BE IMPLEMENTED / PENDING INDEPENDENT VERIFY
BE1-P1-01: IMPLEMENTED / artifact digest <sha256>
BE1-P1-02: IMPLEMENTED / direct commits documented
BE1-P1-03: IMPLEMENTED / PENDING FE1 REVIEW
BE1-P1-04: AUTOMATED / PENDING QA2 RETEST
BE1-P1-05: READY / PENDING BA G1 MINUTES
BE1-P2-01: POSTED|BLOCKED — comment link/error

BA G1: PENDING|GO|CONDITIONAL GO|NO-GO — reviewer/date/link
FE1: PENDING|PASS|FAIL — reviewer/date/link
QA2: PENDING|PASS|FAIL — reviewer/date/link
WWHN-181: <status>
WWHN-182: <status>
WWHN-183: <status>
Final: BLOCKED|CONDITIONAL|APPROVED
Blockers: <list>
```

Current Jira posting result: **BLOCKED — Atlassian connector 403, app not installed on instance**. Không claim comment đã đăng.

## 11. Acceptance checklist

- [ ] Chọn exact commit SHA trên `m1`.
- [ ] CI workflow `success` trên SHA đó.
- [ ] Download đúng artifact `api-data-evidence-<SHA>`.
- [ ] `sha256sum --check SHA256SUMS` PASS.
- [ ] `contract-verification.json.summary.failed = 0`.
- [ ] PHPUnit/Newman/OpenAPI lint/npm build PASS.
- [ ] QA2 attach API + DB before/after evidence độc lập.
- [ ] FE1 ký route/schema/error/permission/idempotency mapping.
- [ ] BA ghi G1 decision và minutes trên WWHN-40/WWHN-181/WWHN-183.
- [ ] Chỉ sau toàn bộ điều kiện mới đổi `APPROVED`.

## 12. Chống false closure

- Không claim `100%`, `Approved`, `Done` khi thiếu independent sign-off.
- Không dùng CI của commit khác.
- Không dùng hash/test count viết tay thay artifact.
- Không giả deployment SHA; chưa deploy để `null`.
- Không sửa trực tiếp ledger/balance để ép reconcile pass.
- Không coi automated BE test là QA2 sign-off.
