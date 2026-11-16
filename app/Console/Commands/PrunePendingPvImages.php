<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Warehouse\Services\PvImageLifecycle;

class PrunePendingPvImages extends Command
{
    protected $signature = 'pv-images:prune {--dry-run : Report expired uploads without changing files or metadata} {--limit=500 : Maximum uploads per run}';

    protected $description = 'Delete expired PV image uploads that were never saved to a product';

    public function handle(PvImageLifecycle $images): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        if ($limit === false) {
            $this->error('--limit must be between 1 and 5000.');

            return self::FAILURE;
        }
        $count = $images->prune((bool) $this->option('dry-run'), $limit);
        $this->info(($this->option('dry-run') ? 'Would delete: ' : 'Deleted: ').$count.' pending PV image(s).');

        return self::SUCCESS;
    }
}
