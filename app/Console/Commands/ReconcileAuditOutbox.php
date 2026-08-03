<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\AuditLog\Models\AuditLog;
use Modules\AuditLog\Models\AuditOutbox;
use Throwable;

class ReconcileAuditOutbox extends Command
{
    protected $signature = 'audit:reconcile {--limit= : Số bản ghi tối đa mỗi lần chạy}';
    protected $description = 'Ghi lại các audit event bị lỗi từ audit_outbox vào audit_log';

    public function handle(): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('audit.reconcile.batch_size', 200)));
        $processed = 0;
        $failed = 0;

        AuditOutbox::query()
            ->whereNull('processed_at')
            ->where('available_at', '<=', now())
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->each(function (AuditOutbox $entry) use (&$processed, &$failed): void {
                try {
                    AuditLog::create($entry->payload);
                    $entry->update([
                        'processed_at' => now(),
                        'last_error' => null,
                    ]);
                    $processed++;
                } catch (Throwable $error) {
                    $attempts = $entry->attempts + 1;
                    $maxBackoff = max(1, (int) config('audit.reconcile.max_backoff_minutes', 60));
                    $backoff = min($maxBackoff, 2 ** min($attempts, 10));

                    $entry->update([
                        'attempts' => $attempts,
                        'last_error' => mb_substr($error->getMessage(), 0, 2000),
                        'available_at' => now()->addMinutes($backoff),
                    ]);
                    $failed++;

                    Log::error('AUDIT_RECONCILE_FAILED', [
                        'audit_outbox_id' => $entry->getKey(),
                        'attempts' => $attempts,
                        'next_retry_minutes' => $backoff,
                        'exception' => $error::class,
                    ]);
                }
            });

        $this->info("Audit reconcile hoàn tất: {$processed} thành công, {$failed} thất bại.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
