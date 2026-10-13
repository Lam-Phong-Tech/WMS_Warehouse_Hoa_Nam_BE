<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
        $ids = AuditOutbox::query()->whereNull('processed_at')->where('available_at', '<=', now())
            ->orderBy('created_at')->orderBy('audit_outbox_id')->limit($limit)->pluck('audit_outbox_id');

        foreach ($ids as $id) {
            try {
                $outcome = DB::transaction(function () use ($id): string {
                    // A candidate list is not ownership. Recheck after acquiring the
                    // row lock so concurrent workers cannot publish the same entry.
                    $entry = AuditOutbox::query()->whereKey($id)->lockForUpdate()->first();
                    if ($entry === null || $entry->processed_at !== null || $entry->available_at->isFuture()) {
                        return 'SKIPPED';
                    }
                    try {
                        // Audit insert and processed marker are one unit. A nested
                        // savepoint also keeps PostgreSQL usable for the backoff
                        // update if publication or its marker fails.
                        DB::transaction(function () use ($entry): void {
                            AuditLog::create($entry->payload);
                            $entry->update(['processed_at' => now(), 'last_error' => null]);
                        }, 1);

                        return 'PROCESSED';
                    } catch (Throwable $error) {
                        // Discard dirty in-memory marker attributes after rollback.
                        $entry->refresh();
                        $attempts = (int) $entry->attempts + 1;
                        $maxBackoff = max(1, (int) config('audit.reconcile.max_backoff_minutes', 60));
                        $backoff = min($maxBackoff, 2 ** min($attempts, 10));
                        $entry->update([
                            'attempts' => $attempts,
                            'last_error' => mb_substr($error->getMessage(), 0, 2000),
                            'available_at' => now()->addMinutes($backoff),
                        ]);
                        Log::error('AUDIT_RECONCILE_FAILED', [
                            'audit_outbox_id' => $entry->getKey(), 'attempts' => $attempts,
                            'next_retry_minutes' => $backoff, 'exception' => $error::class,
                        ]);

                        return 'FAILED';
                    }
                }, 1);
                $processed += $outcome === 'PROCESSED' ? 1 : 0;
                $failed += $outcome === 'FAILED' ? 1 : 0;
            } catch (Throwable $error) {
                // Failed backoff/commit also rolls back publication. Keep the entry
                // unresolved rather than claiming success or retrying a write here.
                $failed++;
                Log::error('AUDIT_RECONCILE_FAILED', ['audit_outbox_id' => $id, 'exception' => $error::class]);
            }
        }

        $this->info("Audit reconcile hoàn tất: {$processed} thành công, {$failed} thất bại.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
