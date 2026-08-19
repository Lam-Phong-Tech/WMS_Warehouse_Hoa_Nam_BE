# WWHN-222 — Ops handoff evidence (DEV222-P1-01R2)

Yêu cầu R2: "Configure/verify UAT sink + query request_id + event/error sample +
alert/runbook. Closure evidence: Config/env identity + raw trace/log/query + runbook."

Phần backend đã **thực thi và chụp raw** toàn bộ quy trình trên môi trường PostgreSQL thật
(local, DB cô lập) — đây là bằng chứng quy trình chạy được đúng như runbook mô tả. Phần
**duy nhất** còn lại cho DO là lặp lại checklist §4 của runbook trên UAT env thật
(WWHN-55/WWHN-221) — backend không tự nhận phần đó đã xong.

- **Runbook:** `docs/ops/HN-BE-OPS-002_WWHN222_LOG_AUDIT_OBSERVABILITY.md` (sink identity,
  query truy vết, tín hiệu alert, checklist DO).
- **Catalog:** `EVENT_LOG_CATALOG.md` v1.1.
- **Thời điểm chạy:** 2026-08-19, worktree tại HEAD `074a11ff` (candidate pin: `RC_PIN.json`).

## 1. Config/env identity của lần chạy

```json
{
    "driver": "pgsql",
    "database": "hoa_nam_wwhn222_r2",          // DB cô lập, dựng bằng migrate --force từ scratch
    "pg_version": "16.14",                      // image postgres:16-alpine (docker-compose.yml)
    "audit.write_policy": "outbox_required",    // mặc định config/audit.php
    "log.default_channel": "stack"              // config/logging.php -> storage/logs/laravel.log
}
```

## 2. Event sample + retrieval theo request_id (raw)

Ghi 1 event qua `AuditLogger::record()` với `request_id = wwhn222-r2-ops-probe-0001`,
after_data cố tình chứa PII (`email`, `customer_phone`), rồi chạy đúng query §2.1 runbook:

```json
[
    {
        "action": "WWHN222_R2_OPS_PROBE",
        "object_type": "app_user",
        "result": "SUCCESS",
        "error_code": null,
        "request_id": "wwhn222-r2-ops-probe-0001",
        "reason": "DEV222-P1-01R2 ops sample",
        "after_data": "{\"note\": \"WWHN-222 R2 ops event sample\", \"email\": \"[REDACTED]\", \"customer_phone\": \"[REDACTED]\"}",
        "created_at": "2026-08-19 17:53:22+00"
    }
]
```

PII bị redact tại điểm ghi; trường nghiệp vụ (`note`) giữ nguyên.

## 3. Error sample (đường AUDIT_WRITE_FAILED) — raw

Ẩn view `audit_log` tạm thời rồi ghi event `request_id = wwhn222-r2-error-probe-0001`:

**3a. Application log (`storage/logs/laravel.log`) — đúng pattern alert của runbook §3, cùng request_id:**

```
[2026-08-19 17:53:22] local.ERROR: AUDIT_WRITE_FAILED {"request_id":"wwhn222-r2-error-probe-0001","action":"WWHN222_R2_ERROR_PROBE","object_type":"app_user","object_id":null,"exception":"Illuminate\\Database\\QueryException","message":"SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation \"audit_log\" does not exist ..."}
```

**3b. Outbox row (query §2.2 runbook) — payload giữ đủ request_id, PII đã redact, không lộ giá trị thô:**

```json
{
    "payload.request_id": "wwhn222-r2-error-probe-0001",
    "payload.action": "WWHN222_R2_ERROR_PROBE",
    "payload.after_data": { "email": "[REDACTED]" },
    "last_error_head": "SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation \"audit_log\" does not exist ...",
    "raw_pii_leaked": "không lộ"
}
```

## 4. Append-only (4 lớp thử, raw)

```json
{
    "sql_update_view":  "BLOCKED: SQLSTATE[P0001] ... audit_logs is append-only under HN-WMS-ERD-DD-001 (trigger hn_erd_reject_append_only_change)",
    "sql_delete_view":  "BLOCKED: SQLSTATE[P0001] ... audit_logs is append-only under HN-WMS-ERD-DD-001",
    "eloquent_update":  "BLOCKED: audit_log là append-only, không được update.",
    "eloquent_delete":  "BLOCKED: audit_log là append-only, không được xoá."
}
```

DB-level trigger (`trg_audit_logs_append_only`) + application-level model guard cùng chặn.

## 5. Ràng buộc vocabulary result (mới ở R2, raw)

Ghi qua view với giá trị legacy `FAILURE` → được chuẩn hoá thành `FAILED`; insert thẳng bảng
vật lý với `FAILURE` → bị CHECK của ERD chặn:

```
INSERT INTO audit_log (action, object_type, result, request_id)
  VALUES ('WWHN222_LEGACY_VOCAB_PROBE','app_user','FAILURE','wwhn222-r2-legacy-vocab-0001');
-- INSERT 0 1
SELECT action, result FROM audit_log WHERE request_id='wwhn222-r2-legacy-vocab-0001';
--  WWHN222_LEGACY_VOCAB_PROBE | FAILED

INSERT INTO audit_logs (action, entity_type, result, created_at)
  VALUES ('WWHN222_CHECK_PROBE','app_user','FAILURE', now());
-- ERROR:  new row for relation "audit_logs" violates check constraint "chk_erd_audit_logs_result"

SELECT conname, pg_get_constraintdef(oid), convalidated FROM pg_constraint
 WHERE conname='chk_erd_audit_logs_result';
-- chk_erd_audit_logs_result | CHECK (result IN ('SUCCESS','FAILED','DENIED')) NOT VALID | f
```

## 6. Phần còn treo cho DO (không tự nhận đã đóng)

UAT env identity thật + đấu alert vào aggregator cấp hệ thống = WWHN-55/WWHN-221
(checklist sẵn ở runbook HN-BE-OPS-002 §4). Toàn bộ query/pattern/procedure đã được chứng
minh chạy được ở trên — DO chỉ cần lặp lại trên UAT và đính raw output vào 2 ticket đó.
