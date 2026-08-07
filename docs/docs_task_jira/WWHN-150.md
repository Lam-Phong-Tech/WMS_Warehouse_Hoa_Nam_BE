# WWHN-150 — BE1 Output Architecture & Exact-SHA Evidence Handoff

| Thuộc tính | Giá trị |
|---|---|
| Branch | `m1` |
| Document version | `1.2.0` |
| Scan contract version | `1.1.0` / runtime `WWHN-150.1.1.0` |
| Trạng thái | **PROPOSED — PENDING INDEPENDENT SIGN-OFF** |
| Implementation owner | BE1 |
| Independent reviewers | QA1 (WWHN-152), BE2 (WWHN-151), FE1 + BA (WWHN-153) |
| Exact SHA | `build-metadata.json.commit_sha` trong artifact |
| Workflow | `.github/workflows/auth-rbac-audit-contract.yml` |
| Artifact | `wwhn-150-evidence-<exact_commit_sha>` |
| Integrity | GitHub artifact digest + `SHA256SUMS` + `SHA256SUMS.sha256` |

Tài liệu này chỉ ghi nhận phần BE1 có thể hoàn tất trong repository. Không tự nhận thay QA1, BE2, FE1 hoặc BA và không tự tuyên bố `Done/100%` trước sign-off độc lập.

## 1. Kết quả khắc phục P0/P1/P2

| ID | Fix đã triển khai | Evidence exact-SHA | Trạng thái |
|---|---|---|---|
| BE1-P0-01 | Workflow riêng WWHN-150 tạo OpenAPI JSON/YAML, Postman collection/environment, changelog, raw logs, build metadata, source-tree hash, `SHA256SUMS`, artifact digest và run URL | `wwhn-150-evidence-<SHA>` | **BE1 IMPLEMENTED** |
| BE1-P1-01 | Scan classify bị khóa read-only bằng test fingerprint và query listener; không DML vào `inventory_balances`, `stock_movements`, `posting_event`, `idempotency_keys`, `scan_event` | `scan-readonly-phpunit.raw.log`, `scan-db-before.json`, `scan-db-after.json`, `scan-readonly-comparison.json` | **READY FOR QA1** |
| BE1-P1-02 | Xuất raw full PHPUnit, route list, OpenAPI generation, migration/seed, HTTP/Newman, DB before/after và reconciliation | `full-phpunit.raw.log`, `route-list.raw.json`, `openapi-generate.raw.log`, `migrate-fresh.raw.log`, `database-seed.raw.log`, `wwhn150-newman.raw.log`, `reconciliation-report.json` | **BE1 IMPLEMENTED** |
| BE1-P1-03 | Exact-SHA packet có 403/404/409/422, same-key replay, 20 concurrent requests, race, rollback, idempotency và reconciliation | `race-rollback-reconciliation-phpunit.raw.log`, `wwhn150-concurrent-scan.json`, `wwhn150-newman.result.json` | **READY FOR QA1** |
| BE1-P1-04 | Machine boundary report kiểm report/snapshot/warranty/PV không tạo nguồn tồn cạnh tranh | `stock-writer-boundary.json` | **READY FOR BE2** |
| BE1-P1-05 | Endpoint/payload/error/permission khóa bằng generated OpenAPI + focused OpenAPI + executable Postman cùng exact SHA | `openapi.json`, `wwhn150-openapi.yaml`, `wwhn150.postman.collection.json`, `wwhn150-contract-verification.json` | **READY FOR FE1 + BA** |
| BE1-P1-06 | Permission catalog runtime, seed diff/hash, endpoint → permission → role matrix là artifact rời | `permission-catalog.json`, `permission-seed-diff.json`, `permission-seed.git.diff`, `endpoint-permission-role-matrix.json` | **BE1 IMPLEMENTED** |
| BE1-P2-01 | Closure matrix được lưu trong repository và phải post trực tiếp lên Jira cùng exact run/hash | `docs/evidence/WWHN-150/CLOSURE_MATRIX.md` | **READY FOR JIRA** |

## 2. Canonical architecture boundary

```text
POST /api/v1/scan/classify
  -> auth:api
  -> permission:scan.classify
  -> warehouse data-scope guard
  -> ScanClassificationService (SELECT-only)
  -> response stock_effect=NONE
```

Scan/classify không phải posting operation. Nó không được:

- tạo hoặc sửa `inventory_balances`;
- tạo `stock_movements`;
- tạo `posting_event`;
- ghi `idempotency_keys`;
- ghi `scan_event`;
- thay đổi trạng thái chứng từ, bảo hành hoặc snapshot.

Nguồn tồn canonical vẫn là:

- `stock_movements`: signed append-only ledger;
- `inventory_balances`: projection/current balance;
- `posting_event`: operation-level posting evidence cho write operation.

Report/PV là read model; warranty `TEMP_ONLY` là status-only. Mọi write tồn phải đi qua posting service được kiểm soát, không đi qua scan.

## 3. Contract FE/QA

### Request

```http
POST /api/v1/scan/classify
Authorization: Bearer <JWT>
Content-Type: application/json
```

```json
{
  "raw_code": "HN-PV-2026-00001",
  "context": "STOCKTAKE",
  "warehouse_id": "<uuid>"
}
```

`Idempotency-Key` không bắt buộc. Nếu client gửi, endpoint vẫn không tạo idempotency cache.

### Success invariants

```json
{
  "success": true,
  "data": {
    "contract_version": "WWHN-150.1.1.0",
    "stock_effect": "NONE",
    "movement_created": false,
    "posting_event_created": false,
    "idempotency_cache_written": false
  }
}
```

### Error mapping

| HTTP | Nhóm lỗi | Yêu cầu |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Không DB side effect |
| 403 | `ACCESS_DENIED`, `WAREHOUSE_SCOPE_DENIED` | Không lộ object ngoài scope |
| 404 | `SCAN_CODE_NOT_FOUND`, `WAREHOUSE_NOT_FOUND` | Cross-warehouse raw code trả generic 404 |
| 409 | inactive/mismatch/context conflict | Không retry tự động; không DB side effect |
| 422 | validation | Map lỗi field; không DB side effect |

Permission canonical:

```text
POST /api/v1/scan/classify
  -> scan.classify
  -> WAREHOUSE_MANAGER
  -> SUPER_ADMIN bypass theo backend policy
```

## 4. Exact-SHA artifact contents

Artifact chỉ được phát hành khi workflow PASS. Tối thiểu gồm:

- `build-metadata.json`, `source-tree.sha256`;
- `SHA256SUMS`, `SHA256SUMS.sha256`;
- generated `openapi.json`, `openapi.yaml`, focused `wwhn150-openapi.yaml`;
- Postman collection/environment, Newman raw log/result;
- raw `php artisan test`, targeted scan, race/rollback/reconciliation logs;
- raw route list, migration/seed logs;
- permission catalog, seed diff/git diff, endpoint-permission-role matrix;
- DB before/after, read-only comparison, reconciliation report;
- concurrency result cho 20 request;
- report/warranty/PV boundary report;
- credential hygiene report;
- source copies cần thiết để reviewer kiểm tra độc lập.

Verification sau khi tải artifact:

```bash
sha256sum --check SHA256SUMS
sha256sum --check SHA256SUMS.sha256
```

Các file structured sau phải có `PASS`: `wwhn150-contract-verification.json`, `scan-readonly-comparison.json`, `reconciliation-report.json`, `stock-writer-boundary.json`, `wwhn150-concurrent-scan.json`, `credential-hygiene.json`.

## 5. Independent review gates

### QA1 — WWHN-152

QA1 phải ghi trên Jira: exact SHA, workflow run URL, artifact ID/digest, focused OpenAPI SHA-256, Postman SHA-256, `SHA256SUMS PASS`, kết quả 403/409/422, same-key/concurrent, no-write DB, race/rollback/reconciliation và kết luận PASS/FAIL.

### BE2 — WWHN-151

BE2 phải xác nhận schema/report/warranty/PV không:

- tạo balance/ledger/posting source thứ hai;
- ghi ngược read model vào tồn canonical;
- dùng warranty `TEMP_ONLY` để post tồn;
- tạo mapping warehouse/product/status song song không reconciliation.

### FE1 + BA — WWHN-153

FE1 + BA phải xác nhận trên exact hash:

- endpoint/method/request payload;
- success invariants;
- error envelope 401/403/404/409/422;
- permission `scan.classify`;
- FE không coi classify là posting và không tự tăng/giảm tồn.

## 6. Exit criteria

WWHN-150 chỉ được chuyển final khi đồng thời có:

1. workflow `wwhn-150/evidence-gate` PASS;
2. workflow `wwhn-150/artifact-integrity` PASS;
3. artifact `wwhn-150-evidence-<SHA>` có digest và checksum PASS;
4. QA1 sign-off WWHN-152;
5. BE2 boundary sign-off WWHN-151;
6. FE1 + BA sign-off WWHN-153;
7. closure matrix được post trực tiếp trên Jira.

Mọi review phải dùng commit SHA bất biến, không review theo branch động.
