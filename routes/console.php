<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Warehouse\Support\SkuImportContract;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * SRS §7.5 Reconciliation Controls: divergence giữa ledger, balance và subledger phải
 * "Create Reconciliation_Incident and block readiness/sign-off". Nếu chỉ chạy tay thì
 * lệch chỉ lộ ra khi có người chủ động mở báo cáo, nên chạy nền mỗi ngày.
 * Không tự sửa tồn: lệnh chỉ ghi incident, sửa sai vẫn phải qua Reversal (WWHN-26).
 */
Schedule::command('inventory:reconcile')
    ->dailyAt('01:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * DEV219-P1-04R disposition: audit:reconcile PHẢI chạy theo lịch, không phải N/A.
 * AuditLogger::record() (outbox_required/fail_closed) đẩy audit event lỗi vào
 * audit_outbox với backoff; nếu không có gì rút outbox ra thì audit_outbox phình vô hạn
 * và audit trail của các bản ghi đó không bao giờ vào audit_log. Chạy mỗi 5 phút — đủ
 * gần "chủ động" theo SRS §7.5 mà không cạnh tranh tài nguyên với request nghiệp vụ.
 */
Schedule::command('audit:reconcile')
    ->everyFiveMinutes()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * WWHN-330 (DEV330-P1-01 / P1-01R3): ImportErrorFileService ghi file lỗi CSV thật vào
 * storage/app/import-error-files/*.csv với error_file_expires_at (mặc định 24h).
 * Không có cron thì file vật lý không bao giờ bị xoá dù API đã coi là hết hạn (404) —
 * đĩa phình vô hạn theo số lần import lỗi và nội dung file người dùng nộp tồn tại vô
 * thời hạn. Chạy hàng ngày, lệch giờ khỏi các job khác để không cạnh tranh I/O.
 *
 * Giờ chạy đọc từ hợp đồng (`warehouse.sku_import.error_file_purge_daily_at`) chứ không
 * viết cứng ở đây: DEC-IMP-001 §5 công bố "thời gian tối đa file nằm trên đĩa" và con số
 * đó chỉ đúng nếu lịch chạy thật và lịch ghi trong tài liệu là cùng một giá trị.
 */
Schedule::command('import:purge-error-files')
    ->dailyAt(SkuImportContract::errorFilePurgeDailyAt())
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();

/*
 * WWHN-309 (cùng lớp lỗi với DEV219-P1-04R và DEV330-P1-01): `warranty:purge-pii` hiện
 * thực hóa SRS §13.8 / DR-PRIV-001 — sau retention 5 năm kể từ khi đóng case, PII phải
 * được anonymize nếu không có legal hold. Lệnh đã có, `retention_until` đã được ghi khi
 * đóng case và đã backfill, nhưng KHÔNG có process nào gọi nó: PII tồn tại vô thời hạn
 * trong production dù hợp đồng privacy đã cam kết purge. Đây là nghĩa vụ tuân thủ, không
 * phải housekeeping tùy chọn.
 *
 * Chạy hàng ngày lúc 03:10 (lệch khỏi inventory 01:30 và import 02:15 để không cạnh
 * tranh I/O). Lệnh idempotent và có --limit mặc định 500 nên một ngày lỡ nhịp được bù ở
 * lần chạy kế tiếp; không dùng --dry-run ở đây vì lịch phải thực sự purge.
 */
Schedule::command('warranty:purge-pii')
    ->dailyAt('03:10')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
