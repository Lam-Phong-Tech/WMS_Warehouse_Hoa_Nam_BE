# DEC-API-POSTING-002 — Đồng bộ hợp đồng Posting với runtime (WWHN-190 / DEV190-P1-01C)

| Trường | Giá trị |
|---|---|
| Decision ID | DEC-API-POSTING-002 |
| Jira | WWHN-190 (DEV), review item `DEV190-P1-01C` |
| Trạng thái | PROPOSED — chờ Tech Lead + FE/QA sign-off |
| Ngày lập | 2026-08-19 |
| Supersedes | `docs/api-contract/WWHN-180-posting-openapi.json` / `.yaml` **v1.7.0** và `docs/postman/WWHN-180 Canonical Contract.postman_collection.json` |
| Bản thay thế | `docs/api-contract/releases/WWHN-190/1.8.0/` (openapi.json/yaml + postman_collection.json + RELEASE_MANIFEST.json + SHA256SUMS) |
| Authority error catalog | `HN_SRS_FRD_v1.12_FULL.md` §8.2 |

## 1. Vấn đề

Review DEV190-P1-01C: *"Đồng bộ contract với runtime: If-Match, 412 STALE_VERSION, current permission,
current state/lifecycle. Pin exact OpenAPI version/blob/hash + Postman version/blob/hash."*

Hợp đồng posting đang lưu hành (WWHN-180 v1.7.0) lệch runtime ở 6 điểm. Runtime và SRS §8.2 **đồng ý với
nhau**; chỉ artifact hợp đồng là lệch:

| # | Điểm lệch | v1.7.0 khai | Runtime + SRS §8.2 |
|---|---|---|---|
| 1 | Header `If-Match` | không khai | **bắt buộc** cho scan/post/reversal (`CurrentBaselineStockFlowController::expectedVersion()`) |
| 2 | `412` | không khai response nào | `412 STALE_VERSION` khi If-Match lệch (`CurrentBaselineStockFlowController.php:692`) |
| 3 | `x-permission` | `stock_document.post`, `stock_document.scan` (không tồn tại) | `inbound.post`, `outbound.post`, `inbound.scan`, `outbound.scan`, `inventory.reversal.post` |
| 4 | Mã 409 idempotency | `IDEMPOTENCY_ERROR` (Postman + test-cases) | `IDEMPOTENCY_CONFLICT` (`ConflictCode::IDEMPOTENCY`), đúng SRS §8.2 |
| 5 | `404` | không khai | `404 RESOURCE_NOT_FOUND` (`lockDocument()`) |
| 6 | `/api/v1/stocktake/sessions/{id}/apply-adjustment` | có khai | **không tồn tại** trong runtime — bị cấm bởi REQ-SRS-037 + DEC-NO-STOCKTAKE-001, đóng bằng DISP-STK-236 |

Hệ quả thực tế: FE/QA đọc hợp đồng sẽ không gửi `If-Match` → nhận `428 PRECONDITION_REQUIRED` không được
mô tả ở đâu; QA viết assert `IDEMPOTENCY_ERROR` sẽ fail trên runtime đúng; và bộ test-case W180-ROUTE-001
còn yêu cầu tồn tại một route đã bị quyết định gỡ.

## 2. Quyết định

1. **KHÔNG sửa nội dung pack WWHN-180 v1.7.0.** `DISP-STK-236 §3` quy định pack này là immutable, chỉ được
   *superseded bằng decision record trỏ tới nó*. Một phiên trước đã bump pack lên 1.8.0 và phải revert toàn
   bộ — không lặp lại.
2. Phát hành **release mới `WWHN-190/1.8.0`** dưới `docs/api-contract/releases/` (đúng khuôn `DEC-REV-001/`,
   `WWHN-155/`), đồng bộ 100% với runtime, và trỏ ngược về v1.7.0 qua `x-supersedes`.
3. Artifact được **sinh bằng script**, không chép tay: `scripts/build-wwhn190-posting-contract.mjs`.
   Hash nằm trong `RELEASE_MANIFEST.json` + `SHA256SUMS` do script sinh (chống pin rot — bài học WWHN-236).
4. **Gate chống tái lệch**: `node scripts/build-wwhn190-posting-contract.mjs --check` đối chiếu từng
   `x-permission` với `php artisan route:list --json` thật và fail nếu bề mặt đã gỡ quay lại. Chạy trong
   `.github/workflows/wwhn-190-posting-concurrency-gate.yml`. Tầng PHP: `tests/Feature/Wwhn190PostingContractParityTest.php`
   bắn HTTP thật và assert đúng từng mã lỗi mà hợp đồng khai.
5. v1.7.0 giữ nguyên byte-for-byte làm bản lịch sử; mọi consumer mới đọc `WWHN-190/1.8.0`.

## 3. Phạm vi bề mặt trong release 1.8.0

`scan/classify`, `inbound-documents/{id}/scan`, `inbound-documents/{id}/post-receipt`,
`inbound-documents/{id}/reverse`, `outbound-documents/{id}/scan`, `outbound-documents/{id}/post-issue`,
`outbound-documents/{id}/reverse`, `warranty-cases/{id}/temp-only`, `reports/inventory-reconciliation`.

Mỗi operation khai thêm `x-requires-if-match`, `x-requires-idempotency-key`, `x-lifecycle`
(from → to + điều kiện), `x-runtime-source` (handler thật) và `x-error-codes` cho từng HTTP status.

## 4. Ràng buộc giữ lại (không được "dọn cho sạch")

- Không thêm lại bất kỳ path nào trong `x-removed-surfaces`.
- Không đổi `IDEMPOTENCY_CONFLICT`/`CONCURRENCY_CONFLICT` sang tên khác: SRS §8.2 là authority.
- `reports/inventory-reconciliation` phải CÒN — đây là cơ chế thay thế Stocktake do AC-SRS-037 chỉ định
  (xem DISP-STK-237 check `RECONCILIATION-ALIVE`).

## 5. Sign-off cần có

| Vai trò | Nội dung duyệt |
|---|---|
| Tech Lead | Chấp nhận supersession v1.7.0 → 1.8.0 và cách đóng bằng release mới thay vì sửa pack immutable |
| FE | Xác nhận client đã gửi `If-Match` + `Idempotency-Key` và xử lý 412/428 theo 1.8.0 |
| QA | Chấp nhận 1.8.0 làm nguồn assert (thay `IDEMPOTENCY_ERROR` → `IDEMPOTENCY_CONFLICT`, bỏ W180-ROUTE-001) |
