# WWHN-237 — NO-CODE handoff (DEV237-P1-03 / DEV237-P1-05)

| Field | Value |
|---|---|
| Jira | `WWHN-237` — [DEV] truy vấn/báo cáo chênh lệch kiểm kê (variance) |
| Parent | `WWHN-60` · QA gate `WWHN-238` |
| Disposition | `DISP-STK-237` — **DEFERRED / NO CODE**, Option A |
| Controlling requirement | `REQ-SRS-037` (Must) — SRS/FRD v1.12, adopt tại `WWHN-334` |
| Branch | `m1` (BE + FE) |
| Kiểu handoff | **NO-CODE** — không capability, chỉ hàng rào |
| Status | `PROPOSED` — chờ approver ghi disposition trên Jira |

## 1. Vì sao không có PR implementation

`WWHN-237` yêu cầu công thức variance, filter, pagination và report output cho kiểm kê.
`REQ-SRS-037` cấm đúng bộ đó: *"Không expose Stocktake/Variance, menu/API/permission/entity/
**report** hoặc action sửa tồn trực tiếp"*, `AC-SRS-031` nhắc lại *"không có stocktake report"*.

Đây là **source conflict**, không phải thiếu sót implementation. Viết code variance sẽ dọn được
một cờ "thiếu chức năng" nhưng tạo ra vi phạm một requirement `Must`. Vì vậy disposition là
Option A — đóng dạng removal/deprecation closure. Chi tiết và thang authority:
`docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md`.

## 2. Thay đổi thật sự có trong task này

Chỉ **hàng rào và dọn tàn dư hợp đồng**, zero capability:

| Loại | File | Nội dung |
|---|---|---|
| Disposition | `docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md` | Bản ghi quyết định, thang authority, điều kiện re-enable |
| Gate BE | `scripts/verify-wwhn237-variance-report-disposition.mjs` | 11 check fail-closed, `--emit-lock` sinh `CONTRACT_NEGATIVE_LOCK.json` |
| Test BE | `tests/Feature/Wwhn237VarianceReportDeferredGateTest.php` | 10 test: nghĩa vụ âm + nghĩa vụ dương |
| Guard cũ | `tests/Feature/Flow05InventoryControlAlignmentTest.php` | Làm chính xác phạm vi "bộ bàn giao hiện hành" + allowlist historical tường minh |
| Contract BE | `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` | Gỡ folder `12. Stocktake` + request `List stocktake sessions` |
| Contract FE | `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json` | Gỡ criticalFlow `APPLY_ADJUSTMENT`; `SCAN_CLASSIFY.context` về đúng enum runtime; thêm khối `removedFromMvp` |
| Governance FE | `docs/api-evidence/STOCKTAKE_GOVERNANCE_STATUS.json` | Thêm `scopeDisposition` của WWHN-237 |
| Gate FE | `scripts/verify-wwhn-237-closure.mjs` + `src/test/wwhn-237-variance-report-guard.test.ts` | 17 check + 6 test |
| Dọn source FE | `src/styles/hn-wms-page-normalize.css`, `src/shared/hooks/usePermission.ts` | Gỡ selector chết `.stocktake-page`; sửa doc-comment mô tả sai quyền `stocktake.*` |

**Không** thay đổi: route, controller, service, migration, permission, report code, màn hình.

## 3. Hai lỗi thật đã tìm ra và sửa

Cả hai đều nằm ở tầng *hợp đồng*, không ở tầng runtime — nên mọi gate soi route/permission/schema
trước đây đều xanh trong khi hợp đồng vẫn khai capability bị cấm:

1. **`WMS_FE_CONTRACT_BASELINE.json` khai FE đã implement Stocktake.** criticalFlow
   `APPLY_ADJUSTMENT` → `POST /stocktake-sessions/:sessionId/apply-adjustment`, permission
   `stocktake.adjust`, `feDisposition: IMPLEMENTED_EXACT_V1_7_SESSION_VERSION_CAS`. QA/FE đọc file
   này như hợp đồng thật; nó tuyên bố ngược hẳn với `REQ-SRS-037` và với route table thực tế.
2. **`SCAN_CLASSIFY.context` liệt `STOCKTAKE`.** Runtime BE (`ScanController@classify`) nhận
   `in:INBOUND,OUTBOUND,INVENTORY,TRACE,WARRANTY_RECEIVE,WARRANTY_TEMP_ONLY` — gửi `STOCKTAKE`
   nay trả `422`. Hợp đồng vừa thừa một giá trị bị cấm, vừa thiếu ba giá trị đang phục vụ.

Ngoài ra `Flow05InventoryControlAlignmentTest::test_published_contract_artifacts_carry_no_stocktake_capability`
**đang đỏ sẵn trên `m1`** trước task này (2 offender). Nay xanh — xem §5.

3. **Gate `verify-wwhn237-variance-report-disposition.mjs` (check `CONTRACT-SURFACE`) báo FAIL
   giả trên pack sống `docs/api-contract/releases/WWHN-190/1.8.0/{openapi.json,openapi.yaml,
   CHANGELOG.md}`.** Pack này (WWHN-190, `DEC-API-POSTING-002`) ghi lại việc gỡ
   `/stocktake/sessions/{id}/apply-adjustment` đúng khuôn `x-removed-surfaces` +
   `x-supersedes` mà `DISP-STK-236 §3` đã xác lập — tức đây là **bằng chứng tuân thủ**
   REQ-SRS-037, không phải capability sống, nhưng gate cũ so needle trên raw string nên
   không phân biệt được. Sửa gate để parse JSON/YAML và gỡ khối tường thuật
   (`x-removed-surfaces`, `x-supersedes`, `description`, `summary`, `reason`,
   `declared_in`, `notes`, `title`) trước khi so needle, và bỏ qua `.md` — **cùng đúng
   danh sách khoá** mà `Flow05InventoryControlAlignmentTest::stripStocktakeNarrativeFields`
   (PHP, do nhánh WWHN-190/260 thêm) đã dùng, để hai gate không lệch nhau. Đã kiểm chứng
   gate vẫn fail đúng khi tiêm một path `stocktake` sống thật vào `paths` (không nằm
   trong khối tường thuật) — không nới lỏng guard, chỉ hết false positive.

## 4. Ranh giới cố ý không đụng tới

Theo `DISP-STK-236 §3`: một immutable handoff artifact chỉ được superseded bằng decision record
trỏ tới nó, **không bao giờ bằng cách sửa nội dung**. Các file sau giữ nguyên digest, được ghi vào
`CONTRACT_NEGATIVE_LOCK.json` để reviewer nhìn thấy chính xác cái gì được miễn:

- `docs/api-contract/WWHN-180-posting-openapi.{json,yaml}`
- `docs/postman/WWHN-180 Canonical Contract.postman_{collection,environment}.json`
- `docs/evidence/WWHN-180/permission-catalog.json`, `BE_FE_PERMISSION_MAPPING.json`
- `docs/api-contract/WWHN-150-scan-openapi.yaml`

**Finding chuyển cho ticket khác — không sửa ở WWHN-237:**

| Finding | Owner |
|---|---|
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/{openapi.yaml,reversal.schema.json}`: `CreateReversalRequest.original_document_type` còn enum `ADJUSTMENT`, trong khi runtime chỉ sinh `INBOUND`/`OUTBOUND`. Release là immutable `PROPOSED`, reviewer verify theo digest trong `RELEASE_MANIFEST.json` | `WWHN-26` / `WWHN-134` (DEC-REV-001) |
| `docs/evidence/WWHN-180/REPOSITORY_SHA256SUMS`: 3 mismatch có sẵn trước WWHN-237 (`StockDocumentService.php`, `verify-wwhn180-evidence.mjs`, `StockDocumentConcurrencyGuardTest.php`) | `WWHN-180` |
| FE `npm run check:api-mapping` (`fe-api-mapping`, xem `docs/evidence/WWHN-237/logs/fe-api-mapping.txt`) FAIL vì `docs/api-evidence/BE_CONTRACT_PIN.json` lệch với `swagger-api.json` hiện hành (pinned `8326d505e5c7` vs current) — bề mặt lệch không chứa `stocktake`/`variance`, chỉ là contract pin đã cũ do BE tiến thêm (vd. WWHN-190 v1.8.0). Không phải hồi quy no-Stocktake của WWHN-237/WWHN-296; cần `npm run contract:sync` chạy bởi chủ artifact | `WWHN-309` (BE contract handover/pin) |

## 5. Cách tái lập

```bash
# BE
node scripts/verify-wwhn237-variance-report-disposition.mjs --emit-lock
php artisan test --filter="Wwhn237VarianceReportDeferredGateTest|Flow05InventoryControlAlignmentTest"

# FE
npm run wwhn-237:verify
npx vitest run src/test/wwhn-237-variance-report-guard.test.ts
```

Định danh exact SHA + digest baseline tại `docs/evidence/WWHN-237/SOURCE_REGISTER.json` (BE) và
`docs/evidence/WWHN-237/FE_CLOSURE_IDENTITY.json` (FE).

## 6. Consumer confirmation cần cho `DEV237-P1-05`

| Vai trò | Xác nhận trên cùng version |
|---|---|
| FE1 | Hợp đồng FE hiện hành = `WMS_FE_CONTRACT_BASELINE.json` sau khi gỡ `APPLY_ADJUSTMENT`; không dùng Swagger Standard cũ hay OpenAPI Changelog BE1 v1.0.1 |
| QA1 | Bộ test WWHN-238 là negative gate + regression, chạy trên đúng exact SHA ở §5 |
| BA | AC của `WWHN-60`/`WWHN-237` liên quan variance được rebaseline |
