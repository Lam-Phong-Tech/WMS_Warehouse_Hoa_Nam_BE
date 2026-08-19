# WWHN-222 — P0/P1/P2 Closure Register

## R2 (2026-08-19) — đối chiếu review 75/100

| ID | Disposition | Đã làm gì | Evidence |
| --- | --- | --- | --- |
| DEV222-P1-01R2 | **Backend hoàn tất phần chuẩn bị + thực thi quy trình; phần UAT env thật bàn giao DO (WWHN-55/221)** | Runbook mới `docs/ops/HN-BE-OPS-002_WWHN222_LOG_AUDIT_OBSERVABILITY.md`: sink identity, query truy vết theo request_id, 4 tín hiệu alert (gồm pattern phát hiện sớm sự cố "audit rỗng toàn hệ thống" đã có tiền lệ), checklist DO 4 bước. Toàn bộ quy trình được **chạy thật** trên PostgreSQL 16.14 và chụp raw: event sample (PII redacted), retrieval query, error sample (AUDIT_WRITE_FAILED + outbox). | `OPS_UAT_HANDOFF.md` (config/env identity + raw trace/log/query), runbook HN-BE-OPS-002 |
| DEV222-P1-02R2 | **Đóng trong phiên này** | (1) Pin immutable: `RC_PIN.json` + verifier `scripts/verify-wwhn222-candidate-pin.mjs` — file sở hữu hash cả tệp, file dùng chung pin mức đối tượng (mô hình HN-PIN-328/184), implementation_range + bất biến ancestor; PASS 8/8 artifact. (2) PG execution proof: migrate từ scratch + phpunit 37/37 trên pgsql + probe raw (trước đây 0 evidence PG). (3) Fix gốc phát sinh khi đối chiếu ERD: vocabulary `result` v1.0 (`FAILURE`) lệch enum ERD §7 `CHECK SUCCESS/FAILED/DENIED` → sửa 4 call site Auth + migration `2026_08_19_150000` (trigger chuẩn hoá legacy + CHECK `NOT VALID`) + test DENIED mới + catalog v1.1 §2a (DEC-AUDIT-RESULT-001). Tech review: disposition ở phần Review bên dưới. | `TEST_EVIDENCE.md` §R2, `RC_PIN.json`, `EVENT_LOG_CATALOG.md` v1.1 |
| DEV222-P1-05R2 | **QA1 retest đã thực thi độc lập trên exact candidate** | Retest 6/6 hạng mục (correlation/error/PII/append-only/PG migration/policy) trên PostgreSQL cô lập + sqlite đối chứng, raw độc lập với evidence DEV; ghi chú phạm vi UAT tường minh. | `QA1_RETEST_REPORT_R2.md` |
| DEV222-P2-01 | **Jira governance (comment)** | Soạn comment ghi disposition "superseded/wrong-target" cho review DEV214/WWHN-214 bị gắn nhầm vào WWHN-222 (comment 15847) — reviewer không dùng nhầm làm căn cứ. | Jira comment (PM) |
| DEV222-P2-02 | **Jira governance (metadata)** | PM cập nhật Due Date/ETA — thao tác ngoài repo, comment đã soạn sẵn. | Jira metadata |
| DEV222-P2-03 | **Jira governance (trace note)** | Ghi note: Description giữ historical trace "BE1", classification hiện hành = unified ROLE=DEV. | Jira comment (PM) |

### Review disposition (Tech review cho P1-02R2)

- Thay đổi R2 là **additive + alignment**: không đổi chữ ký public nào ngoài giá trị chuỗi
  `result` tại 4 call site Auth; enum mới là enum ERD đã cam kết, CHECK để `NOT VALID` nên
  không phá dữ liệu/luồng cũ; trigger chuẩn hoá giữ tương thích payload outbox legacy.
- Rủi ro regression đã chặn bằng: suite audit/auth 2 driver (37/37 + sweep 52/53, 0 fail),
  probe ràng buộc raw, và pin object-level để mọi thay đổi sau này vào đúng các đối tượng
  WWHN-222 sẽ làm pin đỏ buộc re-review.
- ERD gap còn lại (cột vật lý `error_code`/`idempotency_key` chưa có trong ERD §7) là
  **BA/PM follow-up** — không tự sửa ERD vì ERD pin checksum 2 repo (ERD-AMD-008).

## R1 — đối chiếu review 61/100 (lịch sử)

| ID | Disposition | Đã làm gì | Còn lại |
| --- | --- | --- | --- |
| DEV222-P1-01 | **Một phần code + phần còn lại blocked ngoài repo** | Phần DEV "chạy instrumentation" = chính sửa đổi P1-03/P1-04 dưới đây (result/error_code/idempotency_key + PII redact + auto-fallback request_id). Phần BE-side của "config/runbook" đã có sẵn từ trước ở `docs/ops/HN-BE-OPS-001_OPERATIONS_RUNBOOK.md` §2/§6 (kênh log, `LOG_LEVEL`, `AUDIT_WRITE_POLICY`, tín hiệu cần theo dõi, `meta.request_id` là khoá đối chiếu). | Phần DO "pin log sink/environment/monitor path" là hạ tầng thật (WWHN-313/WWHN-221) — không thể hoàn thành bằng sửa code local, cần DevOps xác nhận môi trường UAT thật đang tồn tại. |
| DEV222-P1-02 | **Code + evidence trong phiên này** | Toàn bộ commit/file bên dưới **là** implementation range của WWHN-222 (trước đây 0 commit gắn mã này). Không có code reuse cần supersession matrix — đây là thay đổi mới, additive trên hạ tầng audit đã có sẵn từ các ticket khác (ghi rõ nguồn ở `EVENT_LOG_CATALOG.md` §1). | Sau khi commit, dán SHA vào ô "Candidate SHA" bên dưới. |
| DEV222-P1-03 | **Code trong phiên này** | Xem `EVENT_LOG_CATALOG.md` (catalog v1.0) + `TEST_EVIDENCE.md` (chạy local success/business-error/server-error-path, cùng request_id xuyên chuỗi). | Chạy thật trên UAT phụ thuộc P1-01 (DevOps). Rollout `result`/`error_code` cho các module ngoài Auth (nếu QA/Tech Lead yêu cầu) là phạm vi tiếp theo, chưa làm trong phiên này (tránh sửa tràn 73+ call site đang thuộc phạm vi phiên/task khác). |
| DEV222-P1-04 | **Code trong phiên này** | `config/audit.php` mở rộng `sensitive_keys` với PII thật đã xác nhận trong schema (email/phone/customer_*/recipient_*). Test negative: `Wwhn222AuditEventCatalogTest::test_business_error_login_...` chứng minh `email` bị `[REDACTED]` trong `audit_log.after_data` (trước đây audit `LOGIN_FAILED` ghi raw email — bug thật, nay đã đóng). | Đây không phải "secret scan" tự động toàn repo — chỉ redact tại điểm ghi audit. PII trong log ứng dụng khác (ngoài audit_log) không thuộc phạm vi review 61/100 nêu (register chỉ nói "central list", tức audit). |
| DEV222-P1-05 | **Sign-off (đọc + duyệt), không có việc code mới cho DEV** | Đã chuẩn bị đủ evidence để QA1 review: catalog v1.0, PII redact test, request_id trace test. | Bản chất P1-05 đòi QA1 **độc lập retest** trên môi trường thật — không phải việc DEV/AI có thể tự làm thay QA. Comment sign-off soạn riêng cho vai trò QA1 chỉ xác nhận đã đọc evidence, KHÔNG thay thế retest thật trên UAT (đang blocked bởi P1-01). |
| DEV222-P2-01 | **Sign-off (Jira metadata)** | — | Cập nhật Due Date/ETA trên Jira là thao tác ngoài repo (PM/DEV cập nhật field Jira), không phải code. |
| DEV222-P2-02 | **Sign-off (Jira metadata)** | — | Sửa Description trên Jira để ghi rõ role hiện tại = DEV là thao tác ngoài repo, không phải code. |

## Candidate (R2 — full immutable pin)

- **Repo:** `WMS_Warehouse_Hoa_Nam_BE`, nhánh `m1`
- **Implementation range (mọi commit chạm candidate, theo thứ tự):**
  1. `879c9704d55cf435bbb7445d87e594f7428391a6` — implementation R1 (catalog fields, PII redact, fallback request_id)
  2. `41dfa6f7145b2eaff22eae2ef1a20dcc40f1ba4a` — fix pgsql view no-op (migration `2026_08_19_000002`, phát hiện ở WWHN-184 QA1 retest)
  3. `0222069b3c90b1227bdf4957c9c915ef8c7db191` — R2: vocabulary ERD + PG proof + pin + evidence (candidate SHA hiện hành)
- **Bất biến dải:** `git diff 879c9704..HEAD -- <8 artifact trong RC_PIN>` chỉ được chứa
  thay đổi từ các commit liệt kê trên; đã kiểm tại HEAD `074a11ff` — ngoài 2 commit đầu,
  không commit nào khác chạm tập pin.
- **Pin nội dung (không rot theo HEAD):** `RC_PIN.json` — verify:
  `node scripts/verify-wwhn222-candidate-pin.mjs` (thêm `--expect-sha=<sha>` ở cổng deploy).
- **Migrations trong candidate:** `2026_08_19_000001`, `2026_08_19_000002`, `2026_08_19_150000`.
- **Re-verify 19/08/2026 (phiên soạn sign-off comments):** pin verifier **PASS 8/8 artifact**
  tại HEAD `4a6965b5d432947c497a2c3370a62a17907251a3`;
  `php vendor/bin/phpunit tests/Feature/Wwhn222AuditEventCatalogTest.php` → **5/5 passed
  (23 assertions)** tại cùng HEAD; chạy lại pin verifier lần nữa sau khi các phiên song song
  commit tiếp → **PASS 8/8 tại HEAD `63941526a144806f0e0245f54dd265f07591334f`** — candidate
  không rot. (Đối chiếu chéo: phiên QA1 độc lập cũng re-verify tại `5db3cb0` —
  `TEST_EVIDENCE.md` mục R2-5.)

## Affected files (tập pin — xem hash trong RC_PIN.json)

```
Modules/AuditLog/app/Models/AuditLog.php                                              (full hash)
Modules/AuditLog/app/Services/AuditLogger.php                                         (object-level)
Modules/AuditLog/database/migrations/2026_08_19_000001_add_event_catalog_fields_to_audit_log.php (full hash)
Modules/AuditLog/database/migrations/2026_08_19_000002_fix_audit_log_pgsql_view_missing_event_catalog_fields.php (full hash)
Modules/AuditLog/database/migrations/2026_08_19_150000_align_audit_result_vocabulary_with_erd.php (full hash)
Modules/Auth/app/Http/Controllers/AuthController.php                                  (object-level)
config/audit.php                                                                      (object-level)
tests/Feature/Wwhn222AuditEventCatalogTest.php                                        (full hash)
```

Evidence pack (`docs/evidence/WWHN-222/*`, runbook HN-BE-OPS-002, verifier script) đứng
**ngoài** tập pin — tránh vòng tự tham chiếu pin-evidence (bài học WWHN-187/233).

Không có reuse từ code của task khác cần supersession matrix — toàn bộ thay đổi
trên là mới, additive trên hạ tầng `AuditLogger`/`ApiConventionMiddleware` đã
tồn tại sẵn từ WWHN-172/WWHN-247/WWHN-267/WWHN-289 (tham chiếu, không sửa lại).
