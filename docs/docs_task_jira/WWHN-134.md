# WWHN-134 — BE1 Soạn nội dung kỹ thuật/nghiệp vụ và lựa chọn đề xuất

## 1. Kết quả xử lý đánh giá 80/100

| Finding | Cách xử lý trong release này | Trạng thái |
|---|---|---|
| BE1-P1-01 — chưa có review sau v1.1 | Phát hành DEC-REV-001 v1.2, mapping đầy đủ các thay đổi BA yêu cầu; tạo mẫu comment explicit acceptance cho BA/Tech Lead | READY_FOR_INDEPENDENT_REVIEW |
| BE1-P1-02 — parent chưa formal sign-off | Giữ trạng thái `PROPOSED`; ghi Decision Owner Nguyễn Quang Đức, ETA 2026-08-06; cấm claim APPROVED trước comment độc lập trên WWHN-26 | DISPOSITIONED, PENDING OWNER ACTION |
| BE1-P1-03 — WWHN-136 To Do | Lập traceability matrix WWHN-17/31/33/35/40 và mẫu comment để BAS ghim; không tự nhận hoàn tất thay BAS | READY_FOR_BAS_ACTION |
| BE1-P1-04 — OpenAPI/Postman chưa khóa | Bổ sung `docs/api-contract/WWHN-134-reversal-openapi.yaml` và `docs/postman/WWHN-134-reversal.postman_collection.json` phiên bản 1.2.0-proposed | COMPLETED AT PROPOSAL LEVEL |
| BE1-P2-01 — chưa có actual evidence | Ghi rõ contract/Decision Record không phải implementation evidence; exact-SHA CI/API/DB/race/reconciliation là downstream gate | COMPLETED |
| BE1-P2-02 — sign-off table trống | Điền owner cho Tech Lead, QA Testability, Decision Owner; giữ PENDING thay vì giả mạo chữ ký | COMPLETED / PENDING INDEPENDENT SIGN-OFF |

Không có P0 được nêu trong bảng đánh giá. Mọi P1/P2 đã có output hoặc disposition rõ ràng; các chữ ký độc lập còn lại không thuộc quyền BE1 tự xác nhận.

## 2. Baseline kỹ thuật đã áp dụng

Theo `docs/WMS_Hoa_Nam_Techstack_Architecture_Dev_Guide.md`:

- Laravel Modular Monolith, PostgreSQL là database chính.
- Reverse phải chạy transaction, lock balance/document liên quan, validate lại state và tồn, append movement đối ứng, rollback khi lỗi.
- Inventory movement là audit source chính, không sửa/xóa movement đã phát sinh.
- Backend enforce RBAC và warehouse/data scope; frontend không phải security boundary.
- Idempotency bắt buộc cho action đổi tồn.
- Error contract rõ ràng, không lộ secret/stack trace.

## 3. BA REVIEW CHANGES REQUIRED — mapping v1.2

| BA request | Addressed tại |
|---|---|
| BRD v3.11, SRS/FRD v1.4, BPD v1.3; v3.9 superseded | DEC-REV-001 §1 |
| HTTP 409 + `error.code=IDEMPOTENCY_ERROR` | DEC-REV-001 §3/§5; OpenAPI 409 enum |
| PENDING_RETURN không phải document status | DEC-REV-001 §4; OpenAPI `physical_return_status` ở line |
| OP-REV-02/03/04 có owner/ETA/disposition | DEC-REV-001 §9 |
| Danh sách affected tickets | DEC-REV-001 §7; traceability matrix bên dưới |

## 4. Output đã phát hành trên nhánh m1

1. `docs/decision-records/DEC-REV-001.md` — Decision Record v1.2, trạng thái Proposed.
2. `docs/api-contract/WWHN-134-reversal-openapi.yaml` — OpenAPI 3.0.3 cho `POST /api/v1/reversals`.
3. `docs/postman/WWHN-134-reversal.postman_collection.json` — Postman contract cases.
4. `docs/docs_task_jira/WWHN-134.md` — hồ sơ remediation và handoff này.

## 5. Contract summary

### Endpoint

`POST /api/v1/reversals`

### Permission

`stock.reversal.create`, đồng thời kiểm tra warehouse/data scope tại backend.

### Headers bắt buộc

- `Authorization`
- `X-Request-Id`
- `Idempotency-Key` (8–128 ký tự)

### Error codes khóa

- 401 `UNAUTHENTICATED`
- 403 `NO_PERMISSION`
- 404 `ORIGINAL_NOT_FOUND`
- 409 `IDEMPOTENCY_ERROR`, `INVALID_STATE`, `REVERSAL_LIMIT_EXCEEDED`, `REVERSAL_DEPENDENCY_CONFLICT`, `CONCURRENT_MODIFICATION`
- 422 `VALIDATION_ERROR`
- 428 `IDEMPOTENCY_KEY_REQUIRED`

### Idempotency semantics

- Cùng key + cùng canonical payload: replay response, không tạo movement/balance delta lần hai.
- Cùng key + payload khác: HTTP 409 `IDEMPOTENCY_ERROR`.
- Idempotency claim, reversal, movement, balance, audit và response snapshot phải nằm trong cùng transaction hoặc cơ chế bảo đảm atomic tương đương.

## 6. Traceability matrix cho WWHN-136

| Ticket | Nội dung cần ghim | Evidence repo |
|---|---|---|
| WWHN-17 | Append-only reversal; PENDING_RETURN ở line/evidence; BRD v3.11 điều khiển | DEC-REV-001 §1–§4 |
| WWHN-31 | `/reversals`, payload, errors, headers, idempotency | OpenAPI v1.2.0-proposed |
| WWHN-33 | Positive/negative/RBAC/replay/conflict/race/rollback/audit/reconciliation test scope | DEC-REV-001 §8 + Postman cases |
| WWHN-35 | `stock.reversal.create`, 401/403, audit/no-stack-trace | DEC-REV-001 §3/§5 |
| WWHN-40 | Contract gate FE/BE/QA, sample payload, approval condition | toàn bộ release WWHN-134 |

## 7. Downstream evidence boundary

Các file trong release này là decision/contract artifacts. Chúng không chứng minh endpoint đã được implement hoặc tests đã PASS. Khi implementation được thực hiện, Definition of Done phải có:

- exact backend commit SHA;
- CI/PHPUnit/Newman raw logs;
- PostgreSQL version/environment;
- API happy/negative/RBAC/idempotency replay+conflict;
- concurrent double-reversal/race result;
- DB before/after movement và balance delta;
- rollback, audit, no-stack-trace và reconciliation evidence;
- independent QA reviewer/result/date.

Không dùng branch URL động, local path hoặc BE self-review để thay evidence trên exact SHA.

## 8. Sign-off gate

| Gate | Owner | Kết quả hiện tại | ETA |
|---|---|---|---|
| BA xác nhận v1.2 đã address CHANGES REQUIRED và accept proposal | Nguyễn Đình Hùng | PENDING | 2026-08-06 |
| Tech Lead technical review | Tech Lead | PENDING | 2026-08-06 |
| QA testability review | QA1/QA2 | PENDING | 2026-08-06 |
| BAS ghim traceability WWHN-17/31/33/35/40 | Nguyễn Thị Hải Yến | PENDING | 2026-08-06 |
| Decision Owner formal approval hoặc giữ Proposed có ETA | Nguyễn Quang Đức | PROPOSED / PENDING | 2026-08-06 |

## 9. Comment mẫu để cập nhật Jira sau commit

### WWHN-134 — yêu cầu BA/Tech Lead/QA review

> BE1 đã phát hành DEC-REV-001 v1.2 trên exact commit `[SHA]`. V1.2 đã address toàn bộ BA REVIEW CHANGES REQUIRED: baseline BRD v3.11/SRS-FRD v1.4/BPD v1.3; 409 IDEMPOTENCY_ERROR; PENDING_RETURN ở line/evidence; OP-REV-02/03/04 có owner/ETA/disposition; affected-ticket traceability. Đề nghị reviewer ghi rõ `REVIEWED-SUFFICIENT` hoặc `CHANGES REQUIRED`, reviewer, date và exact SHA. Contract vẫn `PROPOSED`, không claim implementation Done.

### WWHN-26 — Decision Owner

> DEC-REV-001 v1.2 tại exact commit `[SHA]` đang ở trạng thái `PROPOSED`. Decision Owner: Nguyễn Quang Đức; ETA 2026-08-06. Đề nghị ghi rõ `APPROVED` hoặc `REJECTED/CHANGES REQUIRED` kèm ngày và exact SHA. Nếu chưa ký, các phụ thuộc tiếp tục fail-closed theo Proposed.

### WWHN-136 — BAS traceability

> Đề nghị BAS ghim DEC-REV-001 v1.2 exact commit `[SHA]` xuống WWHN-17/31/33/35/40 theo matrix trong `docs/docs_task_jira/WWHN-134.md`, xác nhận BRD v3.11/SRS-FRD v1.4/BPD v1.3 là baseline và v3.9 superseded.

## 10. Kết luận BE1

BE1 đã hoàn thành sâu phần thuộc quyền kiểm soát: Decision Record, transaction/concurrency/idempotency/audit rules, OpenAPI, Postman, error/permission contract, traceability matrix, downstream evidence boundary và sign-off ownership. Điểm còn mở là hành động review/phê duyệt độc lập của BA, Tech Lead, QA, BAS và Decision Owner; không được đánh dấu hoàn tất bằng self-sign-off.