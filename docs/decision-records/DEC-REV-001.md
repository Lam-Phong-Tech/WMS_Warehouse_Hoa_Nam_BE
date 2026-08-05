# DEC-REV-001 — Đảo giao dịch/điều chỉnh theo append-only

- Version: 1.2
- Trạng thái: PROPOSED — PENDING INDEPENDENT SIGN-OFF
- Decision Owner: Nguyễn Quang Đức (PM/PO) — ETA xác nhận: 2026-08-06
- Technical Owner/Author: Quang Nguyễn Thanh (BE1)
- Tech Lead Reviewer: PENDING — owner: Tech Lead, ETA: 2026-08-06
- QA Testability Reviewer: PENDING — owner: QA1/QA2, ETA: 2026-08-06
- Parent: WWHN-26
- Delivery task: WWHN-134
- Traceability task: WWHN-136

## 1. Nguồn chuẩn và supersession

Nguồn điều khiển: BRD v3.11, SRS/FRD v1.4, BPD v1.3, WWHN-17 và `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`. BRD v3.9 và các handoff dựa trên v3.9 là superseded, chỉ dùng lịch sử, không dùng làm baseline triển khai.

## 2. Bối cảnh và quyết định

Không sửa hoặc xóa `inventory_movements` đã phát sinh. Mọi reversal/correction tạo movement đối ứng, liên kết bất biến tới giao dịch gốc và chạy trong một database transaction. Quyết định này chọn Option B: append-only reversal/correction.

Lý do: bảo toàn audit trail, hỗ trợ reconciliation, loại bỏ chỉnh sửa lịch sử, giảm rủi ro double-counting và phù hợp nguyên tắc inventory movement là nguồn audit chính.

## 3. Bất biến nghiệp vụ

1. Chỉ giao dịch đã POSTED và còn reversible quantity mới được đảo.
2. Tổng lượng đã đảo không vượt lượng gốc theo từng line/SKU/serial/box.
3. Một reversal không được đảo lại; correction chain không được tạo cycle.
4. Movement gốc bất biến; reversal tạo movement có quantity/sign đối ứng và `reversal_of_movement_id`.
5. Balance và movement được cập nhật nguyên tử; lỗi ở bất kỳ bước nào phải rollback toàn bộ.
6. Lock theo document và balance key trước khi validate lại reversible quantity.
7. `Idempotency-Key` bắt buộc. Cùng key+cùng payload trả lại kết quả cũ; cùng key+khác payload trả HTTP 409 với `error.code=IDEMPOTENCY_ERROR`.
8. Permission bắt buộc: `stock.reversal.create`; backend enforce warehouse/data scope.
9. Audit phải có actor, request_id, idempotency key hash, reason, original/reversal references, before/after và timestamp.
10. Không trả stack trace, SQL, secret hoặc internal path.

## 4. PENDING_RETURN

Document status vẫn chỉ dùng `DRAFT | CONFIRMED | POSTED | CANCELLED`. `PENDING_RETURN` không phải document status. Trạng thái hoàn trả vật lý được lưu tại line/evidence bằng `physical_return_status = NOT_REQUIRED | PENDING_RETURN | RETURNED | WAIVED`. UI có thể hiển thị trạng thái tổng hợp nhưng không được thay đổi state machine của document.

## 5. API contract khóa ở mức proposal

`POST /api/v1/reversals`

Headers:
- `Authorization: Bearer <token>`
- `X-Request-Id: <uuid>`
- `Idempotency-Key: <8..128 chars>`

Request tối thiểu:

```json
{
  "original_document_type": "RECEIPT",
  "original_document_id": "uuid",
  "reason_code": "DATA_CORRECTION",
  "reason": "Mô tả nghiệp vụ tối thiểu 10 ký tự",
  "lines": [{"original_line_id":"uuid","quantity":1,"physical_return_status":"PENDING_RETURN"}]
}
```

Success: HTTP 201 lần đầu, HTTP 200 khi replay cùng key/payload.

Error semantics: 401 `UNAUTHENTICATED`; 403 `NO_PERMISSION`; 404 `ORIGINAL_NOT_FOUND`; 409 `IDEMPOTENCY_ERROR`, `INVALID_STATE`, `REVERSAL_LIMIT_EXCEEDED`, `REVERSAL_DEPENDENCY_CONFLICT`, `CONCURRENT_MODIFICATION`; 422 `VALIDATION_ERROR`; 428 `IDEMPOTENCY_KEY_REQUIRED`.

## 6. Transaction/concurrency sequence

1. Validate request shape và header.
2. Resolve permission + warehouse scope.
3. Begin transaction.
4. Claim idempotency record bằng unique constraint `(actor_scope, endpoint, idempotency_key)` và payload hash.
5. Lock original document/lines theo thứ tự ID ổn định.
6. Lock inventory balances theo `(warehouse_id, sku_id, lot/serial)` theo thứ tự ổn định.
7. Revalidate state, dependencies, reversible quantity và no-negative rule.
8. Tạo reversal document/lines.
9. Append movement đối ứng và cập nhật balance.
10. Ghi audit/outbox trong cùng transaction.
11. Persist response snapshot cho idempotent replay; commit.

## 7. Affected tickets và traceability

- WWHN-17: BA Pin Inventory/Stocktake/Reversal baseline.
- WWHN-31: OpenAPI v1, schema, error semantics, idempotency.
- WWHN-33: test strategy/TC cho positive, negative, RBAC, replay, conflict, race, rollback, audit, reconciliation.
- WWHN-35: permission, audit, 401/403 và error envelope.
- WWHN-40: FE/BE/QA contract gate và sample payload.
- WWHN-136: BAS ghim các liên kết trên và xác nhận supersession.

## 8. Downstream implementation evidence

Actual PHPUnit/Newman/API/DB/race/reconciliation evidence là downstream implementation evidence. Tài liệu quyết định này không dùng để claim code đã triển khai, test đã PASS hoặc task kỹ thuật đã Done. Chỉ exact commit SHA + CI logs + DB engine + raw API/DB evidence + independent reviewer mới đủ cho implementation sign-off.

## 9. Open points có disposition

| ID | Nội dung | Disposition | Owner | ETA |
|---|---|---|---|---|
| OP-REV-02 | Quy tắc dependency downstream | Fail closed bằng `REVERSAL_DEPENDENCY_CONFLICT`; whitelist dependency được BA duyệt | BA + BE2 | 2026-08-06 |
| OP-REV-03 | Return evidence bắt buộc theo loại giao dịch | `physical_return_status` ở line; evidence URI bắt buộc khi policy yêu cầu | BA + QA | 2026-08-06 |
| OP-REV-04 | SLA/phê duyệt reversal giá trị lớn | Policy/rule config, không hard-code; mặc định permission gate | Decision Owner | 2026-08-06 |

## 10. Sign-off

| Vai trò | Người/Owner | Kết quả | Ngày | Evidence |
|---|---|---|---|---|
| BE1 Author | Quang Nguyễn Thanh | PROPOSED | 2026-08-05 | exact commit chứa file này |
| BA Review v1.1/v1.2 | Nguyễn Đình Hùng | PENDING explicit acceptance | — | Jira comment WWHN-134 |
| Tech Lead | Tech Lead | PENDING | — | Jira comment WWHN-134/WWHN-26 |
| QA Testability | QA1/QA2 | PENDING | — | Jira comment + TC mapping |
| Decision Owner/Approver | Nguyễn Quang Đức | PENDING | — | WWHN-26 formal sign-off |

Không được đổi trạng thái thành ACCEPTED/APPROVED trước khi các reviewer độc lập ghi rõ reviewer, result, date và evidence trên exact commit.