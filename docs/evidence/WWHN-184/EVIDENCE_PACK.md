# WWHN-184 — Evidence Pack (DEV184-P1-01R)

Ngày: 2026-08-18. Nhánh: `m1` (không tạo nhánh riêng, theo yêu cầu). Không
push lên remote — chờ xác nhận trước khi push/PR.

## Exact commit SHA

| Repo | SHA | Commit message |
|---|---|---|
| `WMS_Warehouse_Hoa_Nam_BE` | `157968b7e6a863b626d5090eef051d80b363012d` | fix(WWHN-184): reconcile inbound.cancel\|inbound.create contract, close Swagger scan gap |
| `WMS_Warehouse_Hoa_Nam_FE` | `09e0dd376f04610505669c79009478030c3a9223` | fix(WWHN-184): let inbound.create satisfy Cancel like the BE route does |

Cả hai đều fast-forward thẳng trên `m1` từ HEAD trước đó (`043c0da` BE,
`99fae69` FE) — không rebase, không squash, không tạo nhánh trung gian.

## Scope diff (BE, 6 file)

```
Modules/Warehouse/app/Http/Controllers/InboundDocumentController.php | 2 +-
config/l5-swagger.php                                                | 6 +
docs/docs_task_jira/WWHN-207.md                                      | 2 +-
storage/api-docs/api-docs.json                                       | 523 ++++++++++++++++-----
storage/api-docs/api-docs.yaml                                       | 411 +++++++++++-----
tests/Feature/SwaggerRouteSyncTest.php                                | 30 ++
6 files changed, 738 insertions(+), 236 deletions(-)
```

## Scope diff (FE, 4 file)

```
docs/HN_SRS_FRD_v1.12_FULL.md                                    | 4 ++--
src/modules/inbound/pages/MvpInboundScreens.tsx                  | 8 ++++---
src/modules/inbound/services/inboundService.test.ts              | 25 ++++++++++++++++++++++
src/shared/constants/wms-permissions.ts                          | 10 ++++++++-
4 files changed, 41 insertions(+), 6 deletions(-)
```

## Test evidence

| Lệnh | Kết quả |
|---|---|
| BE `php vendor/bin/phpunit --filter="Flow02InboundPutawayAlignmentTest\|InboundCanonicalListContractTest\|InboundExpectedCorrectionContractTest\|InboundScanConcurrencyBehaviorTest\|PostReceiptConcurrencyBehaviorTest\|Wwhn198InboundScanValidationTest\|Wwhn207InboundReversalFlowTest\|PostReceiptAuditLogTest\|PermissionAndValidationTest\|SecurityRbacAuditNegativeTest\|FoundationInboundPutawayReadinessTest"` (¹) | 88/88 pass, 585 assertions |
| BE `php vendor/bin/phpunit --filter=SwaggerRouteSyncTest` | 3/3 pass, 10 assertions (gồm 1 test mới) |
| BE `php vendor/bin/phpunit` (full suite, sqlite, driver mặc định) | 553 tests, 534 passed, 19 skipped, **0 failed**, 6537 assertions |
| FE `npx vitest run src/modules/inbound/services/inboundService.test.ts src/test/wms-contract-signoff-guards.test.ts` | 25/25 pass |
| FE `npx vitest run` (full suite) | **482/482 pass** (63 test file) |

(¹) Ghi chú (2026-08-18): lệnh trên là evidence lịch sử, giữ nguyên không sửa để bảo toàn tính xác thực tại thời điểm đã chạy. Lớp `Wwhn198InboundScanValidationTest` sau đó đã được đổi tên thành `Wwhn187InboundScanValidationTest` (cùng nội dung test, không đổi logic) sau khi xác nhận qua Jira live rằng key chính xác của task là WWHN-187 — xem `docs/docs_task_jira/WWHN-187.md`.

## API/DB evidence — OpenAPI compile trước/sau

Trước fix (`storage/api-docs/api-docs.json`, route `POST
/inbound-documents/{id}/cancel`, tự sinh bởi `RouteSyncProcessor` vì thiếu
scan path):

```json
"responses": {
  "403": { "description": "Không có quyền truy cập" }
},
"requestBody": { "content": { "application/json": {
  "schema": { "type": "object", "example": {}, "additionalProperties": true }
}}}
```

Sau fix (annotation thật từ `InboundDocumentController.php` được compile
đúng, `operationId: stock_in_cancel`):

```json
"responses": {
  "403": { "description": "Không có permission inbound.cancel hoặc inbound.create (route nhận cả hai — Annex C khai inbound.create, baseline seed cấp inbound.cancel riêng cho WAREHOUSE_APPROVER/KEEPER; xem docs/DR_HN_20260817_FLOW02_OPEN_DECISIONS.md)" }
},
"requestBody": { "required": true, "content": { "application/json": {
  "schema": { "type": "object", "required": ["reason"],
    "properties": { "reason": { "type": "string", "maxLength": 2000 } } }
}}}
```

Không có migration nào thêm/sửa trong fix này → `migrate:status` không đổi,
không cần DBA sign-off riêng cho phần P1-01R.

## Artifact đã cập nhật/tạo mới

- `storage/api-docs/api-docs.{json,yaml}` — compile lại (đã commit).
- `docs/api-contract/WWHN-184-inbound-openapi.{json,yaml}` — mới, trích xuất
  trực tiếp từ `api-docs.json` đã compile (11 path tag "09. Stock In
  (Inbound)"), không phải viết tay nên không lệch khỏi nguồn thật.
- `docs/postman/WWHN-184 Inbound Contract.postman_collection.json` — mới,
  19 request cover create/update/scan/scan-entries/cancel (cả 2 permission
  variant)/post-receipt (idempotency đủ 3 case)/state-conflict.
- `docs/docs_task_jira/WWHN-184.md` — viết lại hoàn toàn, thay nội dung sai
  (trỏ nhầm WWHN-56/224) bằng đúng phạm vi Inbound API/WWHN-41.
