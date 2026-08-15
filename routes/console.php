<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| DEV219 reconciliation schedules
|--------------------------------------------------------------------------
|
| Scheduler execution is intentionally registered in application code so the
| effective cadence is reviewable/testable. Infrastructure only needs the
| standard Laravel scheduler trigger (`php artisan schedule:run` every minute).
|
| Both commands use overlap locks to prevent duplicate processing when a prior
| run is still active. Cron expressions remain environment-configurable.
|
*/
Schedule::command('audit:reconcile')
    ->cron((string) config('audit.reconcile.schedule', '*/5 * * * *'))
    ->withoutOverlapping(max(1, (int) config('audit.reconcile.overlap_lock_minutes', 10)))
    ->name('dev219:audit-reconcile');

Schedule::command('inventory:reconcile')
    ->cron((string) config('reconciliation.inventory.schedule', '*/15 * * * *'))
    ->withoutOverlapping(max(1, (int) config('reconciliation.inventory.overlap_lock_minutes', 30)))
    ->name('dev219:inventory-reconcile');
