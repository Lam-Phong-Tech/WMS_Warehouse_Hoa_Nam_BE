<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class P14RecoveryPurge extends Command
{
    protected $signature = 'p14:recovery-purge';

    protected $description = 'Remove expired P14 support intake and receipt capabilities';

    public function handle(): int
    {
        $count = DB::table('p14_recovery_requests')->where('expires_at', '<=', now())->delete();
        $this->info('Expired requests removed: '.$count);

        return self::SUCCESS;
    }
}
