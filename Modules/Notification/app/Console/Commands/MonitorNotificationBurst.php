<?php

namespace Modules\Notification\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class MonitorNotificationBurst extends Command
{
    protected $signature = 'notifications:monitor {--json : Emit one JSON line for an external collector}';

    protected $description = 'Emit notification burst/queue metrics and warn when backlog or failures cross thresholds.';

    public function handle(): int
    {
        $queues = [
            'default' => Queue::size('default'),
            'notifications' => Queue::size('notifications'),
        ];
        $now = now();
        $metrics = [
            'measured_at' => $now->toIso8601String(),
            'queues' => $queues,
            'max_queue_depth' => max($queues),
            'failed_jobs' => Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->count() : 0,
            'pending_deliveries' => Schema::hasTable('notification_deliveries')
                ? DB::table('notification_deliveries')
                    ->whereIn('state', ['PENDING', 'RETRYABLE', 'SENDING'])->count() : 0,
            'notifications_5m' => DB::table('notifications')
                ->where('created_at', '>=', $now->copy()->subMinutes(5))->count(),
            'recipients_5m' => DB::table('notification_recipients')
                ->where('created_at', '>=', $now->copy()->subMinutes(5))->count(),
            'notifications_1h' => DB::table('notifications')
                ->where('created_at', '>=', $now->copy()->subHour())->count(),
            'recipients_1h' => DB::table('notification_recipients')
                ->where('created_at', '>=', $now->copy()->subHour())->count(),
        ];

        $queueThreshold = max(1, (int) config('notification_delivery.monitor_queue_threshold', 100));
        $failedThreshold = max(0, (int) config('notification_delivery.monitor_failed_threshold', 0));
        $metrics['status'] = $metrics['max_queue_depth'] > $queueThreshold
            || $metrics['failed_jobs'] > $failedThreshold ? 'alert' : 'ok';

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Metric', 'Value'], collect($metrics)
                ->map(fn ($value, $key): array => [$key, is_array($value) ? json_encode($value) : $value])
                ->values()
                ->all());
        }

        if ($metrics['max_queue_depth'] > $queueThreshold) {
            Log::warning('Notification queue backlog above threshold', $metrics);
        }
        if ($metrics['failed_jobs'] > $failedThreshold) {
            Log::warning('Notification failed jobs above threshold', $metrics);
        }

        return self::SUCCESS;
    }
}
