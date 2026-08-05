# [BE1] WWHN-180 — API/Dữ liệu Hoa Nam

## Kiểm soát

- Parent: `WWHN-40`; review: `WWHN-181` FE1, `WWHN-182` QA2, `WWHN-183` BA.
- Repo/branch: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` / `m1`.
- Baseline: `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`.
- Version: `1.7.0`.
- Status: `PROPOSED / PENDING_INDEPENDENT_SIGN_OFF`.
- Exact SHA: `build/evidence/api-data/build-metadata.json.commit_sha`.
- Artifact: `api-data-evidence-<exact_commit_sha>`.

BE1 không tự ghi `APPROVED`, QA2 `PASS`, BA `GO`, `Done` hoặc `100%`. Final chỉ hợp lệ khi FE1, QA2 và BA cùng ký exact BE SHA, exact FE SHA và artifact digest.

## Kết quả sửa P0/P1/P2

### Evidence chain

Run `30918156852` đã PASS PHPUnit/Newman/DB/reconcile/checksum nhưng FAIL do YAML canonical dùng relative `$ref` không tồn tại tại artifact root và OAS 3.0 dùng sai kiểu `exclusiveMinimum`.

Run `30998228114` trên SHA tạm xác nhận full/focused PHPUnit, Newman, DB delta, reconciliation, canonical drift, immutable manifest `14/14`, credential hygiene và hai checksum đều PASS. Redocly là gate duy nhất FAIL vì lệnh lint gộp canonical WWHN-180 với full generated OpenAPI có nợ legacy xuyên module.

Bản cuối tách authority:

1. canonical WWHN-180 JSON/YAML là hard gate và immutable FE/QA handoff;
2. full generated OpenAPI vẫn lint và lưu `openapi-full-lint.raw.log` + `openapi-full-lint-status.json` dưới dạng advisory; không xóa hoặc giả PASS;
3. parity, version, forbidden path, secret hygiene, source hash, runtime checksum vẫn fail-closed;
4. GitHub artifact ID/digest và status `wwhn-180/evidence-gate`, `wwhn-180/artifact-integrity` là runtime authority.

Tài liệu, source register, focused OpenAPI JSON/YAML, canonical Postman/environment, permission catalog, test matrix và immutable manifest cùng version `1.7.0`.

### FE source binding

- FE repo/branch: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_FE` / `m1`.
- Exact FE SHA: `f6e137d01ed7afec5d30e8fecde6a2cc31bb7c8b`.
- Baseline: `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json`.
- Blob: `656b3315cdb64d2c74e854cde8fdd896477e8c52`.

Đây là source comparison WWHN-153, không thay FE1 sign-off WWHN-181.

## Canonical contract

| Action | Endpoint | BE permission | FE guard | Stock effect |
|---|---|---|---|---|
| Classify | `POST /api/v1/scan/classify` | `scan.classify` | `scan.classify` | `NONE` |
| Scan inbound | `POST /api/v1/inbound-documents/{id}/scan` | `stock_document.scan` | `scan.inbound` | `NONE` |
| Scan outbound | `POST /api/v1/outbound-documents/{id}/scan` | `stock_document.scan` | `scan.outbound` | `NONE` |
| Receipt | `POST /api/v1/inbound-documents/{id}/post-receipt` | `stock_document.post` | `inbound.post` | `RECEIPT_IN`, once |
| Issue | `POST /api/v1/outbound-documents/{id}/post-issue` | `stock_document.post` | `outbound.post` | `ISSUE_OUT`, once |
| Adjustment | `POST /api/v1/stocktake/sessions/{id}/apply-adjustment` | `stocktake.adjust` | `stocktake.adjust` | adjustment, once |
| TEMP_ONLY | `POST /api/v1/warranty-cases/{id}/temp-only` | `warranty.temp_only` | `warranty.temp_only` | `NONE` |
| Reconcile | `GET /api/v1/reports/inventory-reconciliation` | `report.view` | `report.view` | `NONE` |

Scope: một kho tổng Hoa Nam; Agency là destination party; scan không tạo movement; transfer/multi-warehouse và platform approval workflow ngoài canonical handoff.

## Immutable artifacts

- `docs/api-contract/WWHN-180-posting-openapi.{json,yaml}`
- `docs/postman/WWHN-180 Canonical Contract.postman_collection.json`
- `docs/postman/WWHN-180 Canonical Contract.postman_environment.json`
- `docs/testing/WWHN-180-posting-test-cases.json`
- `docs/evidence/WWHN-180/permission-catalog.json`
- `docs/evidence/WWHN-180/BE_FE_PERMISSION_MAPPING.json`
- `docs/evidence/WWHN-180/IMMUTABLE_HANDOFF_MANIFEST.json`

Canonical collection có 15 request, không có `/submit`, `/approve`, `/reject`; environment không có secret. Jira dùng URL `blob/<exact_commit_sha>/<path>`, không dùng branch URL động.

## Transaction/idempotency

`Idempotency identity = (trimmed key, operation, user_id)`.

- Missing key: `428 PRECONDITION_REQUIRED`; ngoài 8–100 ký tự: `422 VALIDATION_ERROR`.
- Same key/same payload: replay exact response; same key/different payload: `409 IDEMPOTENCY_ERROR`.
- Different key/document đã post: `409 STATE_CONFLICT`.
- Lock document, line, balance tại stock-effect boundary; movement append-only và unique business key.
- Business write, posting event, response snapshot cùng transaction.
- Race/double-post chỉ một stock effect; reconciliation PASS, `mismatch_count=0`.

## CI hard gates

1. PHP syntax, Pint, npm lint/test/build.
2. Full PHPUnit + focused atomicity/concurrency/RBAC/reconciliation.
3. Canonical drift/version/path/secret verification.
4. Canonical OpenAPI Redocly hard gate + JSON/YAML parity.
5. Full generated OpenAPI advisory raw/status.
6. Static SHA-256 + Git blob SHA + release-parent verification.
7. Newman failures `0`; DB delta/reconciliation PASS; credential residual `0`.
8. Build SHA bằng workflow SHA; `SHA256SUMS` và digest verify PASS.
9. Artifact digest dạng `sha256:<digest>`.

## Closure matrix

| ID | BE disposition | Còn lại |
|---|---|---|
| P0-01 | Contract/source/version/hash/evidence fail-closed; FE SHA bind | BA+FE1+QA2 ký cùng SHA/digest |
| P0-02 | Atomicity/idempotency/race/DB/reconcile hard gate | QA2 retest độc lập |
| P1-01 | OpenAPI/Postman/env/manifest có path/hash | Post exact-SHA URL + digest |
| P1-02 | Canonical lint tách full legacy lint; raw logs/metadata/checksum bắt buộc | Final run PASS |
| P1-03 | Permission mapping bind FE SHA/blob; BE middleware canonical | FE1/BA reviewer/result/date |
| P1-04 | Happy/negative/RBAC/replay/conflict/double-post/race/DB/reconcile protocol | QA2 evidence |
| P1-05 | G1 minutes input/closure matrix sẵn sàng | BA decision WWHN-40/183 |
| P2-01 | Canonical sources đồng bộ `1.7.0`, manifests kiểm hash | Ghi final digest Jira |

## Definition of Done

BE/repository đã hoàn thành: chỉ `m1`, không branch/PR phụ; một release commit từ parent khóa; canonical contract/version/parity; Postman không workflow/secret; CI harness tách canonical; runtime/API/DB/reconcile/checksum/artifact-integrity; FE source binding.

Formal còn mở: final evidence gate PASS; artifact digest post Jira; FE1 sign-off WWHN-181; QA2 evidence WWHN-182; BA G1 decision WWHN-183/WWHN-40. Chỉ sau đó mới ghi `100/100`.
