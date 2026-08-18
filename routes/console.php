<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
 * WWHN-330 (DEV330-P1-01 gap): ImportErrorFileService ghi file lỗi CSV thật vào
 * storage/app/import-error-files/*.csv với error_file_expires_at (mặc định 24h).
 * Không có cron thì file vật lý không bao giờ bị xoá dù API đã coi là hết hạn (404) —
 * đĩa phình vô hạn theo số lần import lỗi. Chạy hàng ngày, lệch giờ khỏi các job khác
 * để không cạnh tranh I/O.
 */
Schedule::command('import:purge-error-files')
    ->dailyAt('02:15')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground();
