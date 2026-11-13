<?php

namespace Modules\Notification\Delivery;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class PruneNotificationDeliveryState
{
    public function prune(int $limit = 250): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('NOTIFICATION_PRUNE_LIMIT_INVALID');
        }
        foreach (['notification_delivery_installations', 'notification_delivery_bindings', 'notification_deliveries', 'notification_delivery_test_receipts'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('NOTIFICATION_SCHEMA_NOT_READY');
            }
        }
        $now = now()->utc()->toIso8601String();
        $cutoff = now()->utc()->subDays(30)->toIso8601String();
        $expired = 0;
        $candidates = $this->due(DB::table('notification_delivery_bindings')->where('status', 'ACTIVE'), 'expires_at', $now)->orderBy('expires_at')->limit($limit)->get(['id', 'installation_id']);
        foreach ($candidates as $row) {
            $expired += DB::transaction(function () use ($row, $now): int {
                DB::table('notification_delivery_installations')->where('id', $row->installation_id)->lockForUpdate()->first();
                $b = DB::table('notification_delivery_bindings')->where('id', $row->id)->lockForUpdate()->first();
                if ($b === null || $b->status !== 'ACTIVE' || CarbonImmutable::parse($b->expires_at)->isFuture()) {
                    return 0;
                }

                return DB::table('notification_delivery_bindings')->where('id', $b->id)->where('status', 'ACTIVE')->update(['status' => 'EXPIRED', 'token_ciphertext' => null, 'token_fingerprint' => null, 'updated_at' => $now]);
            });
        }
        $receipts = $this->deleteBounded($this->due(DB::table('notification_delivery_test_receipts'), 'created_at', $cutoff), $limit);
        $deliveries = $this->deleteBounded($this->due(DB::table('notification_deliveries')->whereIn('state', ['PROVIDER_ACCEPTED', 'REJECTED', 'EXPIRED', 'SUPPRESSED', 'UNKNOWN']), 'updated_at', $cutoff)->where(function (Builder $q) use ($now) {
            $q->whereNull('lease_expires_at');
            $q->orWhere(function (Builder $sub) use ($now) {
                $this->due($sub, 'lease_expires_at', $now);
            });
        }), $limit);
        $bindings = $this->deleteBounded($this->due(DB::table('notification_delivery_bindings')->where('status', '!=', 'ACTIVE')->whereNull('token_ciphertext')->whereNotIn('id', DB::table('notification_deliveries')->select('registration_id'))->whereNotIn('id', DB::table('notification_delivery_installations')->whereNotNull('current_binding_id')->select('current_binding_id')), 'updated_at', $cutoff), $limit);

        return ['expired_bindings' => $expired, 'old_receipts_pruned' => $receipts, 'old_deliveries_pruned' => $deliveries, 'old_bindings_pruned' => $bindings, 'retention_days' => 30, 'limit_per_stage' => $limit];
    }

    private function due(Builder $q, string $field, string $date): Builder
    {
        return DB::getDriverName() === 'sqlite' ? $q->whereRaw('julianday('.$field.') <= julianday(?)', [$date]) : $q->where($field, '<=', $date);
    }

    private function deleteBounded(Builder $q, int $limit): int
    {
        $ids = $q->orderBy('id')->limit($limit)->pluck('id');

        return $ids->isEmpty() ? 0 : DB::table($q->from)->whereIn('id', $ids)->delete();
    }
}
