<?php

namespace Modules\Notification\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MonitorNotificationBurst extends Command
{
    protected $signature = 'notifications:monitor {--json : Emit one JSON line for an external collector}';

    protected $description = 'Emit notification burst/queue metrics and warn when backlog or failures cross thresholds.';

    public function handle(): int
    {
        $queues = [];
        $queueErrors = [];
        foreach (['default' => 'default', 'notifications' => config('queue.notification_queue', 'notifications'),
            'notification-delivery' => config('queue.notification_delivery_queue', 'notification-delivery')] as $label => $queue) {
            try {
                $queues[$label] = Queue::size((string) $queue);
            } catch (Throwable $error) {
                $queues[$label] = null;
                $queueErrors[$label] = $error::class;
            }
        }
        $now = now();
        $hasOutbox = Schema::hasTable('notification_deliveries');
        $states = $hasOutbox ? DB::table('notification_deliveries')->selectRaw('state, count(*) as aggregate')->groupBy('state')->pluck('aggregate', 'state')->map(fn ($n) => (int) $n)->all() : [];
        $waitingStates = ['PENDING', 'RETRYABLE', 'CONFIGURATION_ERROR', 'SENDING'];
        $waitingCount = array_sum(array_intersect_key($states, array_flip($waitingStates)));
        $oldest = $hasOutbox ? DB::table('notification_deliveries')->whereIn('state', $waitingStates)->min('created_at') : null;
        $dueQuery = $hasOutbox ? DB::table('notification_deliveries')->whereIn('state', ['PENDING', 'RETRYABLE', 'CONFIGURATION_ERROR']) : null;
        $due = $dueQuery === null ? null : (DB::getDriverName() === 'sqlite'
            ? $dueQuery->whereRaw('julianday(available_at) <= julianday(?)', [$now->toIso8601String()])
            : $dueQuery->where('available_at', '<=', $now->toIso8601String()))->min('available_at');
        $metrics = ['delivery_states' => $states,
            'waiting_deliveries' => $waitingCount,
            'rate_deferred_deliveries' => $hasOutbox ? DB::table('notification_deliveries')->whereIn('state', $waitingStates)->where('last_code', 'RATE_DEFERRED')->count() : 0,
            'admission_errors' => $hasOutbox ? DB::table('notification_deliveries')->whereIn('state', $waitingStates)->where('last_code', 'RATE_CONFIGURATION_ERROR')->count() : 0,
            'oldest_wait_seconds' => $oldest === null ? 0 : max(0, $now->getTimestamp() - CarbonImmutable::parse($oldest)->getTimestamp()),
            'oldest_overdue_seconds' => $due === null ? 0 : max(0, $now->getTimestamp() - CarbonImmutable::parse($due)->getTimestamp()),
            'measured_at' => $now->toIso8601String(),
            'queues' => $queues,
            'queue_errors' => $queueErrors,
            'max_queue_depth' => max($queues),
            'failed_jobs' => Schema::hasTable('failed_jobs')
                ? DB::table('failed_jobs')->count() : 0,
            'pending_deliveries' => Schema::hasTable('notification_deliveries')
                ? DB::table('notification_deliveries')
                    ->whereIn('state', $waitingStates)->count() : 0,
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
        $outboxAlert = $waitingCount > max(1, (int) config('notification_delivery.monitor_delivery_threshold', 100))
            || $metrics['oldest_overdue_seconds'] > max(1, (int) config('notification_delivery.monitor_overdue_seconds', 300))
            || ($states['CONFIGURATION_ERROR'] ?? 0) > 0 || ($states['UNKNOWN'] ?? 0) > 0 || $metrics['admission_errors'] > 0
            || $queueErrors !== [];
        $metrics['status'] = $metrics['max_queue_depth'] > $queueThreshold
            || $metrics['failed_jobs'] > $failedThreshold || $outboxAlert ? 'alert' : 'ok';

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Metric', 'Value'], collect($metrics)
                ->map(fn ($value, $key): array => [$key, is_array($value) ? json_encode($value) : $value])
                ->values()
                ->all());
        }

        if ($outboxAlert) {
            Log::warning('Notification delivery outbox needs attention', $metrics);
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
