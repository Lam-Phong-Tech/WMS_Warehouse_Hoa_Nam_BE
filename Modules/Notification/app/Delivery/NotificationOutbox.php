<?php

namespace Modules\Notification\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Auth\Support\ProfilePreferences;
use Modules\Notification\Jobs\DispatchNotificationDeliveries;
use Modules\Notification\Models\Notification;
use Modules\Notification\Models\NotificationRecipient;
use Throwable;

final class NotificationOutbox
{
    public function enqueue(Notification $n, ?array $registrationIds = null): int
    {
        $canary = app(AndroidTestCanary::class);
        if (($canary->configured() ? $canary->active() === null : ! config('notification_delivery.enabled', false)) || ! Schema::hasTable('notification_deliveries')) {
            return 0;
        }
        $count = 0;
        $now = now()->utc()->format('Y-m-d\TH:i:s.uP');
        $recipients = NotificationRecipient::where('notification_id', $n->id)->get();
        $bindings = $recipients->isEmpty()
            ? collect()
            : DB::table('notification_delivery_bindings')
                ->whereIn('user_id', $recipients->pluck('user_id')->unique()->values()->all())
                ->where('status', 'ACTIVE')
                ->when($registrationIds !== null, fn (Builder $q) => $q->whereIn('id', $registrationIds))
                ->get()
                ->groupBy('user_id');
        $category = ProfilePreferences::category($n->event_key, $n->source_module);
        $preferences = ProfilePreferences::readMany($recipients->pluck('user_id')->all());
        foreach ($recipients as $r) {
            if ($category !== null && $category !== 'account' && ! $preferences[$r->user_id]['notifications'][$category]['in_app']) {
                continue;
            }
            foreach ($bindings->get($r->user_id, collect()) as $b) {
                if (! CarbonImmutable::parse($b->expires_at)->isFuture()) {
                    continue;
                }
                if ($canary->configured() && ! $canary->enqueueAllowed($n, $b, $r, $registrationIds)) {
                    continue;
                }
                $count += DB::table('notification_deliveries')->insertOrIgnore(['id' => (string) Str::uuid(), 'recipient_id' => $r->id, 'registration_id' => $b->id, 'binding_version' => $b->binding_version, 'state' => 'PENDING', 'attempts' => 0, 'available_at' => $now, 'expires_at' => $canary->configured() ? now()->utc()->addSeconds(3600)->toIso8601String() : null, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        if ($count > 0) {
            DB::afterCommit(function (): void {
                try {
                    $this->wake();
                } catch (Throwable $e) {
                    Log::warning('Notification delivery enqueue deferred', ['exception' => $e::class]);
                }
            });
        }

        return $count;
    }

    public function drain(DeliverySender $sender, int $limit = 50): int
    {
        $canary = app(AndroidTestCanary::class);
        $scoped = $canary->configured();
        if (DB::transactionLevel() > 0 || ($scoped ? $canary->active() === null : ! config('notification_delivery.enabled', false)) || ! Schema::hasTable('notification_deliveries')) {
            return 0;
        }
        $now = now()->utc()->format('Y-m-d\TH:i:s.uP');
        $scope = fn () => $scoped ? $canary->selected() : DB::table('notification_deliveries');
        $this->due($scope()->where('state', 'SENDING'), 'lease_expires_at', $now)->update(['state' => 'UNKNOWN', 'lease_token' => null, 'lease_expires_at' => null, 'available_at' => $now, 'last_code' => 'LEASE_EXPIRED']);
        // FCM has no receipt reconciliation by delivery_id. An uncertain send
        // or expired lease must not automatically send the same signal again.
        $query = $scope()->whereIn('state', $scoped ? ['PENDING'] : ['PENDING', 'RETRYABLE', 'CONFIGURATION_ERROR']);
        if ($scoped) {
            $query->where('attempts', 0);
        }
        $started = hrtime(true);
        $budget = max(1, min(20, (int) config('notification_delivery.drain_time_budget_seconds', 20)));
        $rows = $this->due($query, 'available_at', $now)->orderBy('available_at')->orderBy('created_at')->orderBy('id')->limit(max(1, min(100, $limit)))->get();
        $sent = 0;
        foreach ($rows as $candidate) {
            if ((hrtime(true) - $started) / 1_000_000_000 >= $budget) {
                break;
            }
            if ($scoped && ! $canary->deliveryAllowed($candidate)) {
                continue;
            }
            $lease = (string) Str::uuid();
            $claimed = DB::table('notification_deliveries')->where('id', $candidate->id)->where('state', $candidate->state)->where('attempts', $candidate->attempts)->where('available_at', $candidate->available_at)
                ->update(['state' => 'SENDING', 'lease_token' => $lease, 'lease_expires_at' => now()->utc()->addSeconds(120)->toIso8601String(), 'updated_at' => $now]);
            if ($claimed !== 1) {
                continue;
            }
            $row = DB::table('notification_deliveries')->where('id', $candidate->id)->first();
            if ($row->expires_at !== null && ! CarbonImmutable::parse($row->expires_at)->isFuture()) {
                $this->finish($row, $lease, 'EXPIRED', 'TTL_EXPIRED');

                continue;
            }
            $b = DB::table('notification_delivery_bindings')->where('id', $row->registration_id)->first();
            $i = $b === null ? null : DB::table('notification_delivery_installations')->where('id', $b->installation_id)->first();
            $r = NotificationRecipient::find($row->recipient_id);
            $reason = $b === null || $i === null || $r === null ? 'SOURCE_MISSING' :
                (($i->current_binding_id !== $b->id || (int) $i->binding_version !== (int) $row->binding_version || ! CarbonImmutable::parse($b->expires_at)->isFuture()) ? 'BINDING_STALE' : app(NotificationDeliveryPolicy::class)->reason($r, $b));
            if ($reason !== null) {
                $this->finish($row, $lease, 'SUPPRESSED', $reason);

                continue;
            }
            if ($scoped && ! $canary->deliveryAllowed($row)) {
                $this->finish($row, $lease, 'SUPPRESSED', 'CANARY_CLOSED');

                continue;
            }
            // Admission and any deferral are atomic with the lease check, never with network I/O.
            try {
                $admitted = DB::transaction(function () use ($row, $lease, $r, $scoped, $candidate): bool {
                    $current = DB::table('notification_deliveries')->where('id', $row->id)->lockForUpdate()->first();
                    if ($current === null || $current->state !== 'SENDING' || $current->lease_token !== $lease) {
                        return false;
                    }
                    $clock = CarbonImmutable::now('UTC');
                    if ($current->lease_expires_at === null || CarbonImmutable::parse($current->lease_expires_at)->lessThanOrEqualTo($clock)) {
                        $this->finish($row, $lease, 'UNKNOWN', 'LEASE_EXPIRED');

                        return false;
                    }
                    $mandatory = ProfilePreferences::category($r->notification->event_key, $r->notification->source_module) === 'account';
                    $next = $mandatory || $scoped ? null : app(PushDeliveryThrottle::class)->admit((string) $r->user_id, $clock);
                    // The user lock may have blocked after the earlier lease/policy check.
                    $clock = CarbonImmutable::now('UTC');
                    if (CarbonImmutable::parse($current->lease_expires_at)->lessThanOrEqualTo($clock)) {
                        throw new DeliveryAdmissionAborted('UNKNOWN', 'LEASE_EXPIRED');
                    }
                    if ($current->expires_at !== null && CarbonImmutable::parse($current->expires_at)->lessThanOrEqualTo($clock)) {
                        throw new DeliveryAdmissionAborted('EXPIRED', 'TTL_EXPIRED');
                    }
                    if ($next !== null) {
                        DB::table('notification_deliveries')->where('id', $row->id)->where('lease_token', $lease)->update([
                            'state' => $candidate->state, 'available_at' => $next->utc()->format('Y-m-d\TH:i:s.uP'),
                            'last_code' => 'RATE_DEFERRED', 'lease_token' => null, 'lease_expires_at' => null,
                            'updated_at' => $clock->toIso8601String(),
                        ]);

                        return false;
                    }
                    if ($row->expires_at === null) {
                        $row->expires_at = $clock->addSeconds(max(1, (int) config('notification_delivery.message_ttl_seconds', 3600)))->toIso8601String();
                        DB::table('notification_deliveries')->where('id', $row->id)->where('lease_token', $lease)->update(['expires_at' => $row->expires_at]);
                    }

                    return true;
                });
            } catch (DeliveryAdmissionAborted $aborted) {
                $this->finish($row, $lease, $aborted->state, $aborted->reason);

                continue;
            } catch (Throwable $error) {
                DB::table('notification_deliveries')->where('id', $row->id)->where('state', 'SENDING')->where('lease_token', $lease)->update([
                    'state' => $candidate->state, 'last_code' => 'RATE_CONFIGURATION_ERROR',
                    'available_at' => now()->utc()->addMinute()->toIso8601String(),
                    'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()->utc()->toIso8601String(),
                ]);
                Log::warning('Notification push admission deferred', ['delivery_id' => $row->id, 'exception' => $error::class]);

                continue;
            }
            if (! $admitted) {
                continue;
            }
            $data = ['contract' => DeliveryConfig::CONTRACT, 'delivery_id' => $row->id, 'recipient_id' => (string) $r->id, 'registration_id' => $b->id, 'binding_version' => (string) $row->binding_version, 'expires_at' => CarbonImmutable::parse($row->expires_at)->utc()->toIso8601String()];
            try {
                $result = $sender->send(Crypt::decryptString($b->token_ciphertext), $data);
            } catch (Throwable $e) {
                $result = ['state' => 'UNKNOWN', 'provider_id' => null];
                Log::warning('Notification provider outcome unknown', ['delivery_id' => $row->id, 'exception' => $e::class]);
            }
            $state = $result['state'] ?? 'UNKNOWN';
            if (! in_array($state, ['PROVIDER_ACCEPTED', 'UNREGISTERED', 'UNKNOWN', 'RETRYABLE', 'CONFIGURATION_ERROR', 'REJECTED', 'EXPIRED'], true)) {
                $state = 'UNKNOWN';
            }
            $attempt = (int) $row->attempts + 1;
            $delay = min(900, 60 * (5 ** min(2, $attempt - 1)));
            $retry = $result['retry_after'] ?? null;
            if (is_int($retry) && $retry > 0) {
                $delay = min(3600, $retry);
            }
            DB::transaction(function () use ($row, $lease, $state, $result, $attempt, $delay, $b): void {
                $patch = ['state' => $state === 'UNREGISTERED' ? 'REJECTED' : $state, 'attempts' => $attempt, 'lease_token' => null, 'lease_expires_at' => null, 'last_code' => $state, 'available_at' => now()->utc()->addSeconds($delay)->toIso8601String(), 'updated_at' => now()->utc()->toIso8601String()];
                if ($state === 'PROVIDER_ACCEPTED') {
                    $patch += ['provider_id' => substr((string) ($result['provider_id'] ?? ''), 0, 512), 'provider_accepted_at' => now()->utc()->toIso8601String()];
                }
                $changed = DB::table('notification_deliveries')->where('id', $row->id)->where('lease_token', $lease)->where('state', 'SENDING')->update($patch);
                if ($changed === 1 && $state === 'UNREGISTERED') {
                    DB::table('notification_delivery_bindings')->where('id', $b->id)->where('binding_version', $row->binding_version)->where('status', 'ACTIVE')->update(['status' => 'INVALID', 'token_ciphertext' => null, 'token_fingerprint' => null, 'updated_at' => now()->utc()->toIso8601String()]);
                }
            });
            $sent++;
        }

        return $sent;
    }

    /** Queue wakeups are expendable; the committed outbox is the source of truth. */
    public function wake(?CarbonImmutable $at = null): void
    {
        $connection = (string) config('queue.default');
        if (config('queue.connections.'.$connection.'.driver') === 'sync' || ! $this->enabled()) {
            return;
        }
        $job = new DispatchNotificationDeliveries;
        if ($at !== null) {
            $delay = max(1, min(5, (int) ceil(CarbonImmutable::now('UTC')->diffInSeconds($at, false))));
            $job->delay($delay);
        }
        dispatch($job);
    }

    public function nextWakeAt(): ?CarbonImmutable
    {
        if (! $this->enabled()) {
            return null;
        }
        $canary = app(AndroidTestCanary::class);
        $query = $canary->configured() ? $canary->selected()->where('attempts', 0) : DB::table('notification_deliveries');
        $waiting = (clone $query)->whereIn('state', $canary->configured() ? ['PENDING'] : ['PENDING', 'RETRYABLE', 'CONFIGURATION_ERROR'])->min('available_at');
        $sending = (clone $query)->where('state', 'SENDING')->min('lease_expires_at');
        $times = array_map(static fn ($value) => CarbonImmutable::parse($value), array_filter([$waiting, $sending]));
        if ($times === []) {
            return null;
        }
        usort($times, static fn ($a, $b) => $a->getTimestampMs() <=> $b->getTimestampMs());

        return $times[0];
    }

    private function enabled(): bool
    {
        $canary = app(AndroidTestCanary::class);

        return ($canary->configured() ? $canary->active() !== null : (bool) config('notification_delivery.enabled', false))
            && Schema::hasTable('notification_deliveries');
    }

    private function finish(object $row, string $lease, string $state, string $code): void
    {
        DB::table('notification_deliveries')->where('id', $row->id)->where('lease_token', $lease)->update(['state' => $state, 'last_code' => $code, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()->utc()->toIso8601String()]);
    }

    private function due(Builder $q, string $field, string $now): Builder
    {
        return DB::getDriverName() === 'sqlite' ? $q->whereRaw('julianday('.$field.') <= julianday(?)', [$now]) : $q->where($field, '<=', $now);
    }
}
