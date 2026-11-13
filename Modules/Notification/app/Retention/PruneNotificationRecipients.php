<?php

namespace Modules\Notification\Retention;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Modules\Notification\Support\NotificationHistoryPolicy;
use RuntimeException;

/**
 * Dọn chuông thông báo theo mức độ quan trọng.
 *
 * Chỉ xóa `notification_recipients` của từng người, KHÔNG xóa
 * `notifications` (ledger sự kiện) hay `notification_source_snapshots`.
 * `critical`, `security.*`, `user.*`, `role.*` và severity lạ được giữ vĩnh viễn.
 */
final class PruneNotificationRecipients
{
    /** Số ngày giữ lại, tính từ read_at (đã đọc) hoặc created_at (chưa đọc). */
    public const RETENTION_DAYS = [
        'info_read' => 90,
        'info_unread' => 180,
        'warning_read' => 365,
        'warning_unread' => 540,
    ];

    private const PROTECTED_EVENT_FAMILIES = ['security.', 'user.', 'role.'];

    private const DELETE_CHUNK = 500;

    /**
     * @return array{
     *     dry_run: bool,
     *     matched: array<string,int>,
     *     deleted: array<string,int>,
     *     deleted_total: int,
     *     retention_days: array<string,int>,
     *     limit_per_tier: int
     * }
     */
    public function prune(int $limit = 5_000, bool $dryRun = false): array
    {
        if ($limit < 1 || $limit > 50_000) {
            throw new InvalidArgumentException('NOTIFICATION_RETENTION_LIMIT_INVALID');
        }
        foreach (['notifications', 'notification_recipients'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('NOTIFICATION_SCHEMA_NOT_READY');
            }
        }

        $matched = [];
        $deleted = [];
        $deletedTotal = 0;

        foreach ($this->tiers() as $tier => $query) {
            $ids = $dryRun
                ? []
                : $this->matchingIds($query, $limit);
            $matched[$tier] = $dryRun ? (clone $query)->count() : count($ids);
            $deleted[$tier] = $dryRun ? 0 : $this->deleteIds($ids);
            $deletedTotal += $deleted[$tier];
        }

        return [
            'dry_run' => $dryRun,
            'matched' => $matched,
            'deleted' => $deleted,
            'deleted_total' => $deletedTotal,
            'retention_days' => self::RETENTION_DAYS,
            'limit_per_tier' => $limit,
        ];
    }

    /**
     * @return array<string,Builder>
     */
    private function tiers(): array
    {
        $now = now()->toImmutable();

        return [
            'info_read' => $this->due(
                $this->tier('info')->whereNotNull('r.read_at'),
                'r.read_at',
                $now->subDays(self::RETENTION_DAYS['info_read']),
            ),
            'info_unread' => $this->due(
                $this->tier('info')->whereNull('r.read_at'),
                'r.created_at',
                $now->subDays(self::RETENTION_DAYS['info_unread']),
            ),
            'warning_read' => $this->due(
                $this->tier('warning')->whereNotNull('r.read_at'),
                'r.read_at',
                $now->subDays(self::RETENTION_DAYS['warning_read']),
            ),
            'warning_unread' => $this->due(
                $this->tier('warning')->whereNull('r.read_at'),
                'r.created_at',
                $now->subDays(self::RETENTION_DAYS['warning_unread']),
            ),
        ];
    }

    private function tier(string $severity): Builder
    {
        $query = DB::table('notification_recipients as r')
            ->join('notifications as n', 'n.id', '=', 'r.notification_id')
            ->where('n.severity', $severity);

        NotificationHistoryPolicy::excludeWarehouse($query);

        // Các nhóm này phải giữ để đối soát an ninh/tài khoản, bất kể severity.
        return $query->where(function (Builder $protected): void {
            foreach (self::PROTECTED_EVENT_FAMILIES as $family) {
                $protected->where('n.event_key', 'not like', $family.'%');
            }
        });
    }

    private function due(Builder $query, string $column, \DateTimeInterface $cutoff): Builder
    {
        $boundary = $cutoff->format('Y-m-d\TH:i:sP');

        // SQLite lưu timestamptz dưới dạng text có offset; julianday hiểu cả
        // 'Y-m-d H:i:s.uP' của Eloquent và ISO-8601 của cutoff.
        return DB::getDriverName() === 'sqlite'
            ? $query->whereRaw('julianday('.$column.') <= julianday(?)', [$boundary])
            : $query->where($column, '<=', $boundary);
    }

    /**
     * @return array<int,string>
     */
    private function matchingIds(Builder $query, int $limit): array
    {
        return $query->orderBy('r.id')->limit($limit)->pluck('r.id')->all();
    }

    /**
     * @param  array<int,string>  $ids
     */
    private function deleteIds(array $ids): int
    {
        $deleted = 0;

        // SQLite giới hạn số tham số bind trong một câu lệnh; chunk nhỏ an toàn cho cả hai driver.
        foreach (array_chunk($ids, self::DELETE_CHUNK) as $chunk) {
            $deleted += DB::table('notification_recipients')->whereIn('id', $chunk)->delete();
        }

        return $deleted;
    }
}
