<?php

namespace Modules\Notification\Console\Commands;

use Illuminate\Console\Command;
use Modules\Notification\Retention\PruneNotificationRecipients;

class PruneNotificationRecipientsCommand extends Command
{
    protected $signature = 'notification-retention:prune {--dry-run} {--limit=5000}';

    protected $description = 'Prune notification bell entries by tiered retention; keep critical/security/account rows and the notification ledger.';

    public function handle(PruneNotificationRecipients $pruner): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > 50_000) {
            $this->error('NOTIFICATION_RETENTION_LIMIT_INVALID');

            return self::FAILURE;
        }

        $this->line(json_encode(
            $pruner->prune($limit, (bool) $this->option('dry-run')),
            JSON_THROW_ON_ERROR,
        ));

        return self::SUCCESS;
    }
}
