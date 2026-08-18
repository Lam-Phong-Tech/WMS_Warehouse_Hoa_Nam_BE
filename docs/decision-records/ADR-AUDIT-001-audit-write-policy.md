# ADR-AUDIT-001 — Audit write policy (`outbox_required` / `fail_closed` / `best_effort`)

- Ticket: WWHN-219 (đóng action `DEV219-P1-06R`)
- Canonical repository: `Lam-Phong-Tech/WMS_Warehouse_Hoa_Nam_BE`
- Canonical branch: `m1`
- File điều chỉnh: `Modules/AuditLog/app/Services/AuditLogger.php`, `config/audit.php`
- Trạng thái: `ACCEPTED`

## Bối cảnh

`config/audit.php` mô tả 3 giá trị hợp lệ cho `AUDIT_WRITE_POLICY`, nhưng trước khi có
ADR này, code tại `AuditLogger::record()` chỉ có nhánh xử lý tường minh cho
`fail_closed` (dòng kiểm `if ($policy === 'fail_closed')` sau khi ghi outbox thành công).
Khi cả `audit_log` **và** `audit_outbox` đều ghi lỗi, code luôn `throw` vô điều kiện —
tức là `best_effort` khi đó hành xử **giống hệt** `outbox_required`, dù comment mô tả
`best_effort` là "luôn cảnh báo/đẩy outbox nhưng không làm hỏng nghiệp vụ". Đây là
`best_effort` không có nhánh riêng — chỉ tồn tại như một chuỗi hợp lệ trong config chứ
không có hành vi khác biệt trong code.

## Quyết định

Giữ nguyên 3 policy, làm rõ ràng hành vi bằng code cho từng policy khi **outbox cũng
lỗi** (trường hợp xấu nhất — cả hai nơi ghi audit đều thất bại):

| Policy | Primary lỗi, outbox OK | Primary lỗi, outbox cũng lỗi |
|---|---|---|
| `outbox_required` (mặc định) | Không throw — outbox coi là đủ traceability | **Throw** `RuntimeException` — không có tí traceability nào thì phải chặn nghiệp vụ |
| `fail_closed` | **Throw** `RuntimeException` — audit bắt buộc phải ghi được audit_log chính | **Throw** `RuntimeException` (cùng lý do) |
| `best_effort` | Không throw | **Không throw** — chỉ `Log::critical('AUDIT_OUTBOX_WRITE_FAILED', [...])` rồi return bình thường |

Khác biệt duy nhất do ADR này thêm vào: nhánh `best_effort` trong khối
`catch (Throwable $outboxError)` không còn `throw` vô điều kiện — chỉ `best_effort` mới
được phép tiếp tục nghiệp vụ khi mất toàn bộ traceability audit.

## Lý do chọn giữ `best_effort` (không xoá khỏi hệ thống)

- Một số job nền/side-effect không quan trọng bằng nghiệp vụ chính (ví dụ log truy vết
  bổ sung không phải chứng cứ pháp lý) cần một policy "đừng để audit làm sập luồng
  chính", đúng như comment gốc trong `config/audit.php` mô tả ý định.
- Nếu xoá `best_effort`, mọi nơi hiện đang cấu hình `AUDIT_WRITE_POLICY=best_effort`
  (nếu có ở môi trường thấp hơn) sẽ đổi hành vi ngầm thành `outbox_required` mà không ai
  quyết định — rủi ro hơn là làm rõ hành vi hiện có.
- `outbox_required` và `fail_closed` đã có test bao phủ đủ (`Wwhn172AuditFailClosedTest`
  cho `fail_closed`); `best_effort` trước ADR này hoàn toàn không có test riêng.

## Ràng buộc

- **Mặc định hệ thống vẫn là `outbox_required`.** `best_effort` phải được set tường
  minh qua `AUDIT_WRITE_POLICY=best_effort` ở nơi gọi (như `Wwhn172AggregateLock` đã làm
  với `fail_closed`), không phải giá trị ngầm định ở bất kỳ route mutation nào.
- Mọi lần rơi vào nhánh `best_effort` khi cả hai nơi ghi audit đều lỗi phải log
  `Log::critical` (không phải `error`) vì đây là mất traceability hoàn toàn — cần
  alerting riêng ở tầng vận hành, không chỉ dependency vào exception.

## Bằng chứng đóng

- Code: `Modules/AuditLog/app/Services/AuditLogger.php` — nhánh `if ($policy !== 'best_effort')` trong `catch (Throwable $outboxError)`.
- Test: `tests/Unit/AuditLoggerWritePolicyTest.php` — 3 test độc lập cho 3 policy, ép cả
  `audit_log` và `audit_outbox` cùng lỗi bằng Eloquent `creating` event, assert đúng
  throw/không-throw theo bảng trên.
