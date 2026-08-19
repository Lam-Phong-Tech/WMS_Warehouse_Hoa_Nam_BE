# WWHN-222 — P0/P1/P2 Closure Register

Đối chiếu với bảng đánh giá 61/100 (review mới nhất). Mỗi dòng ghi rõ: đã **sửa
code** (owner DEV thực hiện trong phiên này) hay chỉ còn **sign-off** (đọc +
duyệt, không có việc mới) hay **blocked ở hạ tầng ngoài repo** (không thể làm
qua sửa code local).

| ID | Disposition | Đã làm gì | Còn lại |
| --- | --- | --- | --- |
| DEV222-P1-01 | **Một phần code + phần còn lại blocked ngoài repo** | Phần DEV "chạy instrumentation" = chính sửa đổi P1-03/P1-04 dưới đây (result/error_code/idempotency_key + PII redact + auto-fallback request_id). Phần BE-side của "config/runbook" đã có sẵn từ trước ở `docs/ops/HN-BE-OPS-001_OPERATIONS_RUNBOOK.md` §2/§6 (kênh log, `LOG_LEVEL`, `AUDIT_WRITE_POLICY`, tín hiệu cần theo dõi, `meta.request_id` là khoá đối chiếu). | Phần DO "pin log sink/environment/monitor path" là hạ tầng thật (WWHN-313/WWHN-221) — không thể hoàn thành bằng sửa code local, cần DevOps xác nhận môi trường UAT thật đang tồn tại. |
| DEV222-P1-02 | **Code + evidence trong phiên này** | Toàn bộ commit/file bên dưới **là** implementation range của WWHN-222 (trước đây 0 commit gắn mã này). Không có code reuse cần supersession matrix — đây là thay đổi mới, additive trên hạ tầng audit đã có sẵn từ các ticket khác (ghi rõ nguồn ở `EVENT_LOG_CATALOG.md` §1). | Sau khi commit, dán SHA vào ô "Candidate SHA" bên dưới. |
| DEV222-P1-03 | **Code trong phiên này** | Xem `EVENT_LOG_CATALOG.md` (catalog v1.0) + `TEST_EVIDENCE.md` (chạy local success/business-error/server-error-path, cùng request_id xuyên chuỗi). | Chạy thật trên UAT phụ thuộc P1-01 (DevOps). Rollout `result`/`error_code` cho các module ngoài Auth (nếu QA/Tech Lead yêu cầu) là phạm vi tiếp theo, chưa làm trong phiên này (tránh sửa tràn 73+ call site đang thuộc phạm vi phiên/task khác). |
| DEV222-P1-04 | **Code trong phiên này** | `config/audit.php` mở rộng `sensitive_keys` với PII thật đã xác nhận trong schema (email/phone/customer_*/recipient_*). Test negative: `Wwhn222AuditEventCatalogTest::test_business_error_login_...` chứng minh `email` bị `[REDACTED]` trong `audit_log.after_data` (trước đây audit `LOGIN_FAILED` ghi raw email — bug thật, nay đã đóng). | Đây không phải "secret scan" tự động toàn repo — chỉ redact tại điểm ghi audit. PII trong log ứng dụng khác (ngoài audit_log) không thuộc phạm vi review 61/100 nêu (register chỉ nói "central list", tức audit). |
| DEV222-P1-05 | **Sign-off (đọc + duyệt), không có việc code mới cho DEV** | Đã chuẩn bị đủ evidence để QA1 review: catalog v1.0, PII redact test, request_id trace test. | Bản chất P1-05 đòi QA1 **độc lập retest** trên môi trường thật — không phải việc DEV/AI có thể tự làm thay QA. Comment sign-off soạn riêng cho vai trò QA1 chỉ xác nhận đã đọc evidence, KHÔNG thay thế retest thật trên UAT (đang blocked bởi P1-01). |
| DEV222-P2-01 | **Sign-off (Jira metadata)** | — | Cập nhật Due Date/ETA trên Jira là thao tác ngoài repo (PM/DEV cập nhật field Jira), không phải code. |
| DEV222-P2-02 | **Sign-off (Jira metadata)** | — | Sửa Description trên Jira để ghi rõ role hiện tại = DEV là thao tác ngoài repo, không phải code. |

## Candidate SHA (điền sau khi commit)

- **Repo:** `WMS_Warehouse_Hoa_Nam_BE`, nhánh `m1`
- **Base HEAD trước khi sửa:** `089eacd20ca918fb42703a2bb97aeb3f70e2881a`
- **Candidate SHA (commit của WWHN-222):** _điền sau_

## Affected files (implementation range)

```
Modules/AuditLog/app/Models/AuditLog.php
Modules/AuditLog/app/Services/AuditLogger.php
Modules/AuditLog/database/migrations/2026_08_19_000001_add_event_catalog_fields_to_audit_log.php
Modules/Auth/app/Http/Controllers/AuthController.php
config/audit.php
tests/Feature/Wwhn222AuditEventCatalogTest.php
docs/evidence/WWHN-222/EVENT_LOG_CATALOG.md
docs/evidence/WWHN-222/TEST_EVIDENCE.md
docs/evidence/WWHN-222/CLOSURE_REGISTER.md
```

Không có reuse từ code của task khác cần supersession matrix — toàn bộ thay đổi
trên là mới, additive trên hạ tầng `AuditLogger`/`ApiConventionMiddleware` đã
tồn tại sẵn từ WWHN-172/WWHN-247/WWHN-267/WWHN-289 (tham chiếu, không sửa lại).
