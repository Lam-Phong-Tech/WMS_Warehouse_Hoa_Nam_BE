<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Operational worker; support receipt is not proof of account ownership. */
final class P14RecoveryDispatch extends Command
{
    protected $signature = 'p14:recovery-dispatch {--limit=50}';

    protected $description = 'Deliver pending P14 support requests to the configured internal mailbox';

    public function handle(): int
    {
        $email = config('p14.support_email');
        if (! config('p14.recovery_enabled') || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('P14 recovery support is not configured.');

            return self::FAILURE;
        }
        $ids = DB::table('p14_recovery_requests')->whereNull('delivered_at')->where('expires_at', '>', now())
            ->orderBy('received_at')->orderBy('operation_id')->limit(max(1, min(200, (int) $this->option('limit'))))->pluck('operation_id');
        $sent = 0;
        foreach ($ids as $id) {
            $sent += DB::transaction(function () use ($id, $email): int {
                $row = DB::table('p14_recovery_requests')->where('operation_id', $id)->whereNull('delivered_at')
                    ->where('expires_at', '>', now())->lockForUpdate()->first();
                if ($row === null) {
                    return 0;
                }
                // At-least-once mail delivery: after an ambiguous transport failure the
                // worker may send the SAME operation ID again. Never triggers a reset.
                $text = 'Support request: '.$row->operation_id."\nSubmitted username: ".Crypt::decryptString($row->username_ciphertext)
                    ."\nUnverified user input. Verify ownership through the approved administrative process. Do not reset automatically.";
                Mail::raw($text, static function ($message) use ($email, $id): void {
                    $message->to($email)->subject('P14 support request '.$id);
                });
                DB::table('p14_recovery_requests')->where('operation_id', $id)->update(['delivered_at' => now()->utc()]);

                return 1;
            });
        }
        $this->info('Delivered support requests: '.$sent);

        return self::SUCCESS;
    }
}
