# [DEV] WWHN-237 — truy vấn/báo cáo chênh lệch kiểm kê (variance)

## Kiểm soát

- Parent: `WWHN-60`; QA gate: `WWHN-238`; Decision Record liên quan: `WWHN-25` (`DEC-STK-001`, In Progress).
- Repo/branch: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE` + `..._FE` / `m1`.
- Baseline điều khiển: `docs/HN_SRS_FRD_v1.12_FULL.md` — `REQ-SRS-037` (Must), adopt tại `WWHN-334`.
- Disposition: `docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md` — version `1.0.0`.
- Status: `PROPOSED / PENDING_APPROVER_AUTHORED_DISPOSITION`. Effective date `NULL`.
- Evidence: `docs/evidence/WWHN-237/` (BE) · `docs/evidence/WWHN-237/FE_CLOSURE_IDENTITY.json` (FE).

DEV không tự ghi `APPROVED`, `Done` hay `100%`. Đóng chỉ hợp lệ khi BA/PM ghi disposition, QA1 nộp
kết quả `WWHN-238`, FE1 xác nhận contract trên cùng exact SHA.

## Kết quả xử lý review 32/100 (18/08/2026)

| Register | Kết quả | Bằng chứng |
|---|---|---|
| `DEV237-P1-01` — source conflict | **Đã chốt**: Option A, DEFERRED/NO CODE theo `REQ-SRS-037`; thang authority + điều kiện re-enable + affected ticket ghi thành văn | `DISP-STK-237` §1–§2 |
| `DEV237-P1-02` — canonical branch còn expose Stocktake | **Đã đóng**: route/permission/entity vốn đã zero; gỡ nốt tàn dư *hợp đồng* còn sót (DEV Postman + FE contract baseline); guard `Flow05` đang đỏ nay xanh | `CONTRACT_NEGATIVE_LOCK.json`, `logs/be-disposition-gate.txt`, `logs/fe-closure-gate.txt` |
| `DEV237-P1-03` — không có implementation variance | **Đúng theo baseline**: phát hành no-code/deprecation closure thay vì viết code | `NO_CODE_HANDOFF.md` |
| `DEV237-P1-04` — QA1 Blocked/Pending | **Đã chuyển DEFERRED/N-A** kèm ma trận N1..N8 + R1..R4 chạy được | `QA_DISPOSITION_WWHN-238.md`, `logs/be-regression-test.txt` |
| `DEV237-P1-05` — thiếu contract artifact/handoff | **Đã publish**: contract hiện hành được nêu đích danh và pin digest; consumer confirmation nêu rõ FE1/QA1/BA phải ký gì | `SOURCE_REGISTER.json`, `NO_CODE_HANDOFF.md` §6 |

## Hai lỗi thật đã tìm ra

Cả hai nằm ở tầng **hợp đồng**, nên mọi gate soi route/permission/schema trước đây đều xanh trong
khi hợp đồng vẫn khai capability bị cấm:

1. `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json` (FE) khai criticalFlow `APPLY_ADJUSTMENT`
   → `POST /stocktake-sessions/:sessionId/apply-adjustment`, permission `stocktake.adjust`,
   `feDisposition: IMPLEMENTED_EXACT_V1_7_SESSION_VERSION_CAS`. Tức FE **tuyên bố đã implement**
   một capability mà `REQ-SRS-037` cấm và route table không có.
2. Cùng file, `SCAN_CLASSIFY.requestConstraints.context` liệt `STOCKTAKE` và thiếu ba giá trị
   runtime thật. `ScanController@classify` nhận `in:INBOUND,OUTBOUND,INVENTORY,TRACE,
   WARRANTY_RECEIVE,WARRANTY_TEMP_ONLY` — gửi `STOCKTAKE` nay trả `422`.

Ngoài ra `Flow05InventoryControlAlignmentTest::test_published_contract_artifacts_carry_no_stocktake_capability`
**đang đỏ sẵn trên `m1`** trước task này (2 offender trong `docs/postman`).

## Thay đổi trong task

Zero capability. Chỉ hàng rào + dọn hợp đồng:

**BE**

- `docs/decision-records/DISP-STK-237-VARIANCE-REPORT-DEFERRED.md` (mới)
- `scripts/verify-wwhn237-variance-report-disposition.mjs` (mới, 11 check fail-closed)
- `scripts/run-wwhn-237-evidence.mjs` (mới, chạy 6 lệnh + ghi log/register/SHA256SUMS)
- `tests/Feature/Wwhn237VarianceReportDeferredGateTest.php` (mới, 10 test)
- `tests/Feature/Flow05InventoryControlAlignmentTest.php` — allowlist historical tường minh
- `docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json` — gỡ `12. Stocktake` + `List stocktake sessions`
- `.github/workflows/wwhn-237-variance-report-deferral-gate.yml` (mới)
- `docs/evidence/WWHN-237/**` (mới)

**FE**

- `docs/api-evidence/WMS_FE_CONTRACT_BASELINE.json` — gỡ `APPLY_ADJUSTMENT`, sửa enum context, thêm `removedFromMvp`
- `docs/api-evidence/STOCKTAKE_GOVERNANCE_STATUS.json` — thêm `scopeDisposition` WWHN-237
- `scripts/verify-wwhn-237-closure.mjs` (mới, 17 check) + `package.json` script `wwhn-237:verify`
- `src/test/wwhn-237-variance-report-guard.test.ts` (mới, 6 test)
- `src/styles/hn-wms-page-normalize.css` — gỡ selector chết `.stocktake-page`
- `src/shared/hooks/usePermission.ts` — sửa doc-comment mô tả sai quyền `stocktake.*`
- `.github/workflows/ci.yml` + `scripts/assert-ci-gates.mjs` — gắn gate `wwhn_237`

## Ranh giới cố ý không đụng

Theo `DISP-STK-236 §3`: immutable handoff chỉ được superseded bằng decision record, không bao giờ
bằng cách sửa nội dung. Giữ nguyên digest: `WWHN-180-posting-openapi.{json,yaml}`,
`WWHN-180 Canonical Contract.postman_{collection,environment}.json`,
`docs/evidence/WWHN-180/{permission-catalog,BE_FE_PERMISSION_MAPPING}.json`,
`WWHN-150-scan-openapi.yaml`. Digest của chúng được ghi vào `CONTRACT_NEGATIVE_LOCK.json`.

## Finding chuyển ticket khác

| Finding | Owner |
|---|---|
| `docs/api-contract/releases/DEC-REV-001/1.3.0-proposed/{openapi.yaml,reversal.schema.json}` — `CreateReversalRequest.original_document_type` còn enum `ADJUSTMENT`, runtime chỉ sinh `INBOUND`/`OUTBOUND`; release immutable `PROPOSED` nên không sửa ở đây | `WWHN-26` / `WWHN-134` |
| `docs/evidence/WWHN-180/REPOSITORY_SHA256SUMS` — 3 mismatch có sẵn trước WWHN-237 (`StockDocumentService.php`, `verify-wwhn180-evidence.mjs`, `StockDocumentConcurrencyGuardTest.php`) | `WWHN-180` |

## Điều kiện mở lại phạm vi dương

Đúng bộ 4 điều kiện tại `DISP-STK-237 §2`: approved Change Request **và** `WWHN-25` đóng có
effective date **và** SRS rebaseline sửa/gỡ `REQ-SRS-037` **và** chỉ khi đó mới mở DEV. Trước đó
mọi PR chạm bề mặt variance/stocktake fail-closed tại gate BE + FE.
