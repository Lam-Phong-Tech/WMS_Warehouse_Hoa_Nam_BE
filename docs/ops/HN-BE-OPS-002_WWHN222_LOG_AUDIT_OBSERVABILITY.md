# HN-BE-OPS-002 — Runbook truy vết log/audit theo request_id (WWHN-222)

| Trường | Giá trị |
| --- | --- |
| Tài liệu | HN-BE-OPS-002 |
| Task | WWHN-222 — Operational logging / request tracing / audit trail (DEV222-P1-01R2) |
| Repository | `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`, nhánh `m1` |
| Phạm vi | Truy vết một request qua log + audit; tín hiệu cảnh báo audit; nghĩa vụ phía backend |
| Ngoài phạm vi | Log sink/monitor cấp hạ tầng (aggregator, alerting engine) → DevOps WWHN-313/WWHN-221; UAT env identity thật → WWHN-55 |
| Liên quan | `HN-BE-OPS-001` §6 (giám sát chung), `docs/evidence/WWHN-222/EVENT_LOG_CATALOG.md` (catalog v1.1) |
| Cập nhật | 2026-08-19 |

---

## 1. Danh tính sink (nguồn log/audit ở mọi môi trường)

| Sink | Ở đâu | Cấu hình quyết định | Khoá đối chiếu |
| --- | --- | --- | --- |
| **Audit trail** (sự kiện nghiệp vụ có actor) | PostgreSQL — view `audit_log` (bảng vật lý `audit_logs`) | `DB_*`; policy `AUDIT_WRITE_POLICY` (`config/audit.php`, mặc định `outbox_required`) | cột `request_id` (đã có index `idx_erd_audit_logs_request_id`) |
| **Audit outbox** (audit chính ghi lỗi) | PostgreSQL — bảng `audit_outbox` | như trên; job `audit:reconcile` mỗi 5 phút rút về audit chính | `payload->>'request_id'` |
| **Application log** (framework/exception/AUDIT_WRITE_FAILED) | Monolog kênh `stack` → `storage/logs/laravel.log` | `LOG_CHANNEL=stack`, `LOG_STACK` (`single` local / `daily` khuyến nghị ngoài local), `LOG_LEVEL` (xem `.env.example`) | trường `request_id` trong context JSON (Laravel Context tự gắn) |

`X-Request-ID`: client gửi hoặc `ApiConventionMiddleware` tự sinh; luôn được echo lại ở
response header + `meta.request_id` của envelope (NFR-SRS-008). Người dùng báo lỗi chỉ cần
gửi `meta.request_id` là truy được cả ba sink trên.

## 2. Truy vết một request theo request_id

Thay `<RID>` bằng request_id cần tra.

**2.1. Audit trail (PostgreSQL):**

```sql
SELECT action, object_type, object_id, result, error_code, reason,
       request_id, created_at
  FROM audit_log
 WHERE request_id = '<RID>'
 ORDER BY created_at;
```

**2.2. Nếu không thấy ở audit chính — kiểm tra outbox (audit ghi lỗi đang chờ reconcile):**

```sql
SELECT payload->>'action' AS action, payload->>'request_id' AS request_id,
       attempts, last_error, available_at
  FROM audit_outbox
 WHERE payload->>'request_id' = '<RID>';
```

**2.3. Application log:**

```bash
grep '<RID>' storage/logs/laravel.log
# multi-node/daily: grep '<RID>' storage/logs/laravel-*.log
```

Bằng chứng chạy thật của đúng ba bước trên (raw output, PostgreSQL 16.14):
`docs/evidence/WWHN-222/OPS_UAT_HANDOFF.md`.

## 3. Tín hiệu cảnh báo (bổ sung cho HN-BE-OPS-001 §6.2)

| Tín hiệu | Cách phát hiện | Ngưỡng | Ý nghĩa / hành động |
| --- | --- | --- | --- |
| `AUDIT_WRITE_FAILED` | grep pattern trong application log (mức `error`) | Bất kỳ lần nào | Audit chính ghi lỗi, payload đã vào outbox. Điều tra nguyên nhân (schema drift, DB lỗi); xác nhận `audit:reconcile` rút được. **Tiền lệ thật 2026-08-19:** migration event-catalog no-op trên pgsql (view vs table) → mọi audit rơi outbox âm thầm, `audit_log` rỗng toàn hệ thống — grep pattern này là cách phát hiện sớm duy nhất. |
| `AUDIT_OUTBOX_WRITE_FAILED` | grep pattern trong application log (mức `critical`) | Bất kỳ lần nào | Cả audit chính lẫn outbox đều hỏng. Với policy `outbox_required`/`fail_closed` request đã bị fail để giữ traceability — sự cố vận hành mức cao nhất của audit. |
| Backlog `audit_outbox` | `SELECT count(*), min(created_at) FROM audit_outbox;` | Tăng liên tục qua nhiều chu kỳ 5 phút | Scheduler chết hoặc audit chính hỏng kéo dài (xem HN-BE-OPS-001 §9). |
| `audit_log` "im lặng bất thường" | `SELECT max(created_at) FROM audit_log;` so với lưu lượng API | Không có bản ghi mới trong khi API vẫn có traffic ghi | Chính là hình thái sự cố tiền lệ ở trên — audit đang rơi outbox hoặc bị nuốt. |

## 4. Checklist bàn giao UAT (phần DO thực thi — WWHN-55/WWHN-221)

Backend đã chuẩn bị xong (mục 1–3 + evidence). Phần còn lại thuộc DO trên môi trường UAT thật:

1. Pin env identity UAT: host/DB/DSN, giá trị `LOG_CHANNEL`/`LOG_STACK`/`LOG_LEVEL`, `AUDIT_WRITE_POLICY` (khuyến nghị giữ mặc định `outbox_required`).
2. Trỏ log aggregator/monitor cấp hệ thống vào 2 pattern `AUDIT_WRITE_FAILED` / `AUDIT_OUTBOX_WRITE_FAILED` + query backlog outbox ở §3.
3. Chạy lại đúng 3 truy vấn §2 trên UAT với một request thật, đính raw output vào WWHN-55/WWHN-221.
4. Xác nhận scheduler UAT chạy `audit:reconcile` (HN-BE-OPS-001 §4).

Retention nhắc lại (nguồn SRS): audit/ledger ≥ 5 năm, không hard-delete (NFR-SRS-014/018);
application/security log ≥ 180 ngày online + ≥ 365 ngày archive (NFR-SRS-008) — chính sách
lưu trữ file log cấp hạ tầng do DevOps thực thi.
