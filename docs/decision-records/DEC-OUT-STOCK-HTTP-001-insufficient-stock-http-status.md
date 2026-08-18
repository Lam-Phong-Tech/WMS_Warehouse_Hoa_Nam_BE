# DEC-OUT-STOCK-HTTP-001 — HTTP status của "không đủ tồn" khi Post Issue

| Trường | Giá trị |
|---|---|
| ID | `DEC-OUT-STOCK-HTTP-001` |
| Ticket | WWHN-201 (đóng finding `DEV201-P1-05`) |
| Ngày | 2026-08-19 |
| Trạng thái | **DECIDED** |
| Phạm vi | `POST /api/v1/outbound-documents/{id}/post-issue` và mọi đường ghi trừ tồn dùng chung `mutateBalance()` |
| Authority neo | SRS/FRD v1.12 §8.2 (`REQ-SRS-024` / `AC-SRS-024`) |

## 1. Vấn đề (nguyên văn finding)

`DEV201-P1-05 — SOURCE CONFLICT`: parent WWHN-47 AC1 nói **409** cho tình huống
không đủ tồn, trong khi runtime (`mutateBalance()`), test WWHN-201 và OpenAPI
WWHN-195 (PROPOSED) đều trả **422**. Một tình huống nghiệp vụ không được phép có
hai HTTP status.

## 2. Quyết định

**Chọn 422.**

| Tình huống | HTTP | Business code |
|---|---|---|
| Không đủ tồn khả dụng để trừ | **422** | `INSUFFICIENT_STOCK` / `INSUFFICIENT_AVAILABLE_STOCK` |
| Phiếu không ở `SCANNING` (đã POSTED/CANCELLED) | 409 | `STATE_CONFLICT` |
| Cùng `Idempotency-Key`, khác payload hash | 409 | `IDEMPOTENCY_CONFLICT` |
| Thua lease/row-lock | 409 | `CONCURRENCY_CONFLICT` |
| `If-Match` cũ | 412 | `STALE_VERSION` |
| Thiếu `If-Match`/`Idempotency-Key` | 428 | `PRECONDITION_REQUIRED` |

## 3. Căn cứ

1. **§8.2 là authority cao nhất và đã nói rõ.** Bảng §8.2 liệt kê
   `INSUFFICIENT_STOCK` nằm ở hàng **422** ("Field/business validation"), còn hàng
   **409** chỉ công nhận đúng bốn mã `STATE_CONFLICT / IDEMPOTENCY_CONFLICT /
   CONCURRENCY_CONFLICT / DOWNSTREAM_DEPENDENCY_EXISTS`. `INSUFFICIENT_STOCK`
   không nằm trong bốn mã đó, nên trả 409 sẽ vi phạm chính §8.2 — vi phạm này đã
   từng bị bắt ở `DEV289-P1-02`.
2. **Ngữ nghĩa hành động khác nhau.** 409 trong hợp đồng FE
   (`concurrency-contract.ts`) có nghĩa "tải lại rồi thử lại". Thiếu tồn thì
   reload không sinh ra tồn mới — hướng dẫn đó sai và đẩy người dùng vào vòng lặp
   thử lại vô ích. 422 mang đúng nghĩa "dữ liệu nghiệp vụ không hợp lệ, phải sửa
   dữ liệu/tồn rồi làm lại".
3. **Chi phí đổi chiều.** Runtime, error catalog `HN-BE-API-002`
   (`INSUFFICIENT_STOCK` → `[422]`, `INSUFFICIENT_AVAILABLE_STOCK` → `[422]`),
   test `Wwhn201PostIssueAtomicNonNegativeStockTest`, harness runtime concurrency
   và OpenAPI WWHN-195 đều đang là 422. Chọn 409 buộc phải sửa ngược cả sáu nơi
   để đi ngược lại §8.2 — không có lợi ích nào bù lại.

## 4. Hệ quả bắt buộc (đã thực hiện)

| Nơi | Trạng thái sau quyết định |
|---|---|
| Parent WWHN-47 AC1 | **Phải sửa "409" → "422"** trên Jira (BA). Đây là hạng mục duy nhất còn nằm ngoài repo. |
| `mutateBalance()` / `AlignedOutboundStockService` | Không đổi — đã 422. |
| `HN-BE-API-002_ERROR_CODE_CATALOG.{md,json}` | Không đổi — đã map 422. |
| OpenAPI `WWHN-195-outbound-openapi.{json,yaml}` | Bổ sung `x-decision`, khoá version, khoá hash (xem `DEC-API-OUT-195-001`). |
| Postman | Sinh bộ `WWHN-195-outbound.postman_collection.json` bind đúng revision. |
| FE | `src/shared/api/post-issue-contract.ts` + test — thiếu tồn = 422, không retry, không tái dùng Idempotency-Key. |
| Test hồi quy | `tests/Feature/Wwhn201PostIssueErrorContractTest.php` (BE) và `post-issue-contract.test.ts` (FE) chốt bảng ở §2. |

## 5. Chống hồi quy

Bất kỳ đường ghi nào trả 409 kèm `INSUFFICIENT_*`, hoặc 422 kèm một trong bốn mã
409 của §8.2, đều làm đỏ `Wwhn201PostIssueErrorContractTest` và
`scripts/verify-wwhn-201-contract.mjs`.
