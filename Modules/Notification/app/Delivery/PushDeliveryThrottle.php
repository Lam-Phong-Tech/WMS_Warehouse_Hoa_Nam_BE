<?php

namespace Modules\Notification\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/** Durable admission slots, shared by every queue worker and the scheduler. */
final class PushDeliveryThrottle
{
    public function admit(string $userId, CarbonImmutable $now): ?CarbonImmutable
    {
        $limit = filter_var(config('notification_delivery.max_push_per_user_per_minute', 60), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 600]]);
        if ($limit === false) {
            throw new UnexpectedValueException('NOTIFICATION_PUSH_RATE_LIMIT_INVALID');
        }

        return DB::transaction(function () use ($userId, $now, $limit): ?CarbonImmutable {
            $stamp = $now->utc()->format('Y-m-d\TH:i:s.uP');
            DB::table('notification_push_rate_limits')->insertOrIgnore([
                'user_id' => $userId, 'next_send_at' => $stamp, 'updated_at' => $stamp,
            ]);
            $row = DB::table('notification_push_rate_limits')->where('user_id', $userId)->lockForUpdate()->first();
            // A contended lock can take longer than a slot; admission starts after acquiring it.
            $fresh = CarbonImmutable::now('UTC');
            if ($fresh->greaterThan($now)) {
                $now = $fresh;
            }
            $stamp = $now->utc()->format('Y-m-d\TH:i:s.uP');
            $next = CarbonImmutable::parse($row->next_send_at);
            if ($next->greaterThan($now)) {
                return $next;
            }
            DB::table('notification_push_rate_limits')->where('user_id', $userId)->update([
                'next_send_at' => $now->addMicroseconds((int) ceil(60_000_000 / $limit))->utc()->format('Y-m-d\TH:i:s.uP'),
                'updated_at' => $stamp,
            ]);

            return null;
        });
    }
}
