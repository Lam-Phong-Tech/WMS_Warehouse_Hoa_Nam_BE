# WWHN-222 — QA1 retest report R2 (DEV222-P1-05R2)

| Trường | Giá trị |
| --- | --- |
| Vai trò | QA1 (retest độc lập theo yêu cầu register R2, gate "QA hard gate") |
| Ngày | 2026-08-19 |
| Candidate | Pin `RC_PIN.json` (HN-PIN-222) — 8 artifact, verify `node scripts/verify-wwhn222-candidate-pin.mjs` PASS tại HEAD `074a11ff` + commit WWHN-222 R2 (SHA ghi ở `CLOSURE_REGISTER.md` §Candidate) |
| Môi trường | PostgreSQL 16.14 (postgres:16-alpine), DB **cô lập** `hoa_nam_wwhn222_r2` dựng từ scratch bằng `php artisan migrate --force` (tránh drift DB dùng chung giữa các phiên); đối chứng sqlite `:memory:` theo `phpunit.xml`; PHP 8.3.30. Không dùng CI/GitHub Actions. |
| Phương pháp | Chạy lại toàn bộ suite liên quan + probe raw SQL/log độc lập với evidence của DEV (không copy kết quả R1) |

## Kết luận: PASS 6/6 hạng mục retest — với 1 ghi chú phạm vi (UAT)

| # | Hạng mục (theo register R2) | Kết quả | Bằng chứng |
| --- | --- | --- | --- |
| 1 | **Correlation (request_id)** | PASS | `Wwhn222AuditEventCatalogTest` 4 test correlation: success/business-error/server-error/fallback cùng `request_id` xuyên response envelope ↔ audit_log ↔ Monolog — PASS trên **cả pgsql lẫn sqlite** (37/37, xem §Runs). Probe độc lập: query `audit_log WHERE request_id='wwhn222-r2-ops-probe-0001'` trả đúng 1 dòng (raw ở `OPS_UAT_HANDOFF.md` §2). |
| 2 | **Error semantics** | PASS | `LOGIN_FAILED` → `result=FAILED`, `error_code=UNAUTHENTICATED` khớp `error.code` envelope; `LOGIN_DENIED` → `result=DENIED`, `error_code=ACCESS_DENIED` (test mới `test_denied_login_uses_erd_result_vocabulary_denied`). Vocabulary đúng enum ERD §7 `CHECK SUCCESS/FAILED/DENIED` — drift `FAILURE` của v1.0 đã sửa tận write-path + CHECK constraint (raw §5 của `OPS_UAT_HANDOFF.md`). |
| 3 | **PII redaction** | PASS | `email`/`customer_phone` → `[REDACTED]` ở `after_data` (đường thành công) và trong payload outbox (đường lỗi); assert phủ định "không chứa giá trị thô" trong test + probe (`raw_pii_leaked: không lộ`). Khớp NFR-SRS-015/ERD §7 "không raw password/token/phone/address trong application log". |
| 4 | **Append-only** | PASS | 4 lớp thử độc lập: UPDATE/DELETE qua SQL trên view → chặn bởi trigger DB `hn_erd_reject_append_only_change` (P0001 "audit_logs is append-only under HN-WMS-ERD-DD-001"); update/delete qua Eloquent → chặn bởi model guard. Raw ở `OPS_UAT_HANDOFF.md` §4. |
| 5 | **PG migration** | PASS | `php artisan migrate --force` từ scratch trên DB cô lập: `2026_08_19_000001` DONE, `2026_08_19_000002` DONE (fix view pgsql — trước đây chính là lỗ hổng "audit_log rỗng toàn hệ thống trên PG"), `2026_08_19_150000` DONE (vocabulary + CHECK). Sau migrate, insert qua view đi tới bảng vật lý đủ `result/error_code/idempotency_key` — xác nhận bằng chính các test đọc lại qua view trên pgsql (điều mà run R1 sqlite-only không chứng minh được). |
| 6 | **Policy** | PASS | `AuditLoggerWritePolicyTest` (outbox_required / fail_closed / best_effort) PASS trên cả 2 driver trong suite; đường `outbox_required` còn được chứng minh bằng probe lỗi thật: audit chính hỏng → outbox nhận payload + `AUDIT_WRITE_FAILED` log, nghiệp vụ không bị chặn (raw §3 `OPS_UAT_HANDOFF.md`). |

**Ghi chú phạm vi:** retest này chạy trên PostgreSQL thật nhưng là môi trường local cô lập,
không phải UAT. Hạng mục "verify UAT sink/monitor" thuộc DEV222-P1-01R2 (DO, WWHN-55/221)
với checklist ở `HN-BE-OPS-002` §4 — QA1 không xác nhận thay phần đó.

## Runs (raw số liệu, không copy từ R1)

| Run | Driver | Lệnh | Kết quả |
| --- | --- | --- | --- |
| 1 | sqlite `:memory:` | phpunit 5 file (Wwhn222 + AuthRbacAuditHardening + AuditLoggerWritePolicy + FoundationAuthRbacAudit + SecurityRbacAuditNegative) | `{"result":"passed","tests":37,"passed":37,"assertions":279}` |
| 2 | sqlite `:memory:` | phpunit `--filter "Auth"` tests/Feature tests/Unit | `{"result":"passed","tests":53,"passed":52,"skipped":1,"assertions":492}` (1 skip pre-existing) |
| 3 | **pgsql 16.14** | migrate --force từ scratch (DB cô lập) | Toàn bộ DONE, 0 lỗi; 3 migration WWHN-222/184 nêu trên có mặt |
| 4 | **pgsql 16.14** | phpunit 5 file như Run 1 | `{"result":"passed","tests":37,"passed":37,"assertions":279}` |
| 5 | **pgsql 16.14** | probe raw (event/retrieval/append-only/error-path/vocabulary CHECK) | Toàn bộ như kỳ vọng — raw đầy đủ trong `OPS_UAT_HANDOFF.md` |

So sánh baseline: R1 chỉ có evidence sqlite (36/36); R2 bổ sung đúng phần PG bị review chỉ
ra là thiếu ("PG fix chưa có exact execution proof") — cùng tập test, thêm test DENIED, PASS
trên cả hai driver tại cùng một worktree/candidate.

## Sự cố quan sát trong lúc retest (minh bạch)

- Sweep `--filter "Auth"` (Run 2) vô tình chạy một phần `Wwhn289SecurityRegressionProofTest`
  làm file evidence `build/evidence/wwhn-289/security-regression-proof.json` bị ghi lại
  chỉ còn section auth. Đã `git checkout --` khôi phục nguyên trạng committed **trước khi
  commit** bất kỳ thay đổi nào của WWHN-222; không ảnh hưởng kết quả retest (file đó không
  thuộc phạm vi WWHN-222).
