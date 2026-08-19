# WWHN-190 Posting Contract v1.8.0 — CHANGELOG

Supersedes: WWHN-180 posting OpenAPI **v1.7.0** + "WWHN-180 Canonical Contract" Postman v1.7.0.
Decision record: `docs/decision-records/DEC-API-POSTING-002-WWHN190-RUNTIME-PARITY.md`.
Sinh bởi: `scripts/build-wwhn190-posting-contract.mjs` (hash trong `RELEASE_MANIFEST.json` / `SHA256SUMS`).

Pack v1.7.0 **không bị sửa nội dung** (DISP-STK-236 §3) — chỉ bị supersede.

## Added

- Header `If-Match` (required) trên `inbound/outbound scan`, `post-receipt`, `post-issue`, `reverse`.
- Response `412 STALE_VERSION` cho mọi operation dùng If-Match.
- Response `404 RESOURCE_NOT_FOUND`.
- `x-lifecycle` (from → to + điều kiện chuyển trạng thái) cho từng operation.
- `x-requires-if-match`, `x-requires-idempotency-key`, `x-runtime-source`, `x-error-codes`.
- Header response `ETag` và `Idempotent-Replay` trên 200.
- Bề mặt bổ sung so với v1.7.0: `inbound-documents/{id}/reverse`, `outbound-documents/{id}/reverse`.
- `requestBody` cho **mọi** operation, trích đúng từ `$request->validate()` của handler runtime.
  Đóng luôn drift mà FE đã ghi nhận là `BE-HANDOFF-WARRANTY-BODY-DRIFT` (v1.7.0 không khai body của TEMP_ONLY,
  trong khi runtime bắt buộc `temporary_item_code`, `missing_code_reason`, `manual_product_description`, `condition_status`).

## Changed

- `x-permission` đổi từ tên giả định sang permission key thật của route:
  - `stock_document.post` → `inbound.post` (post-receipt) / `outbound.post` (post-issue)
  - `stock_document.scan` → `inbound.scan` / `outbound.scan`
  - `scan.classify` → `scan.execute|scan.classify`
- Mã 409 idempotency: `IDEMPOTENCY_ERROR` → **`IDEMPOTENCY_CONFLICT`** (SRS §8.2 + `ConflictCode::IDEMPOTENCY`).
- 428 mô tả lại: áp dụng cho **cả** thiếu `If-Match` lẫn thiếu `Idempotency-Key`, cùng mã `PRECONDITION_REQUIRED`.
- Enum `context` của `scan/classify`: v1.7.0 khai `STOCKTAKE,INBOUND,OUTBOUND,WARRANTY`; runtime là
  `INBOUND,OUTBOUND,INVENTORY,TRACE,WARRANTY_RECEIVE,WARRANTY_TEMP_ONLY` — **không còn STOCKTAKE** (REQ-SRS-037).
- Schema `Error` đổi theo envelope runtime thật do `ApiError::abort()` sinh: có ĐỒNG THỜI `error_code` phẳng và
  `error.code` lồng; `request_id`/`timestamp` nằm trong `meta` (v1.7.0 khai thiếu `error_code` và đặt sai vị trí `request_id`).

## Removed

- `POST /api/v1/stocktake/sessions/{id}/apply-adjustment` — route không tồn tại trong runtime;
  bị cấm bởi REQ-SRS-037 + DEC-NO-STOCKTAKE-001, đóng chính thức bằng DISP-STK-236 (DEFERRED / NO-CODE).
  Kèm theo: test case `W180-ROUTE-001` trong `docs/testing/WWHN-180-posting-test-cases.json` trở thành
  **OBSOLETE** (không sửa file v1.7.0; ghi nhận tại đây và trong QA1 pack).

## Không đổi

- `reports/inventory-reconciliation` giữ nguyên (RPT-INV-01 là cơ chế thay thế Stocktake theo AC-SRS-037).
- Idempotency-Key vẫn 8–100 ký tự sau trim; sai độ dài → `422 VALIDATION_ERROR`.
