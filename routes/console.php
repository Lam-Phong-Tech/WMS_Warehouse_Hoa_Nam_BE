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
