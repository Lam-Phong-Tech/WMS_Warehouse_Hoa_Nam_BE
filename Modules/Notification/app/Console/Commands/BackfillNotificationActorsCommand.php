<?php

namespace Modules\Notification\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vá actor_id/actor_name cho các notification tạo TRƯỚC khi 2 cột này tồn tại
 * (migration 2026_08_27_090000_add_actor_to_notifications_table). Actor thật
 * của những hành động đó vẫn còn dấu vết ở nơi khác trong cùng transaction:
 * - audit_logs (entity_type/entity_id = notifications.subject_type/subject_id)
 *   cho các event tạo/post chứng từ, reversal, role, user, warranty.
 * - document_status_history (action=scan_start) cho *.scan_started — hành
 *   động này không được audit_logs ghi riêng (chỉ ghi CREATE/POST).
 * Không thể khớp chính xác 1-1 bằng action string (tên action lệch giữa 2
 * nguồn), nên dùng bản ghi GẦN THỜI ĐIỂM notification nhất trong tập đã lọc
 * đúng entity — cùng transaction nên độ lệch thực tế chỉ ~1-4 giây.
 */
class BackfillNotificationActorsCommand extends Command
{
    protected $signature = 'notifications:backfill-actor {--dry-run : Chỉ in ra kết quả dự kiến, không ghi DB}';

    protected $description = 'Tra ngược actor qua audit_logs/document_status_history để vá actor_id/actor_name cho notification cũ (tạo trước khi có 2 cột này)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = DB::table('notifications')->whereNull('actor_id')->get();
        if ($rows->isEmpty()) {
            $this->info('Không còn notification nào thiếu actor_id.');

            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($rows as $row) {
            $actorId = $this->resolveActorId($row);
            if ($actorId === null) {
                $this->warn("  [bỏ qua] {$row->event_key} ({$row->id}) — không tìm được dấu vết actor.");

                continue;
            }

            $actorName = DB::table('app_user')->where('user_id', $actorId)->value('display_name');

            $this->line("  {$row->event_key} ({$row->id}) -> actor {$actorId} ({$actorName})");

            if (! $dryRun) {
                DB::table('notifications')->where('id', $row->id)->update([
                    'actor_id' => $actorId,
                    'actor_name' => $actorName,
                ]);
            }
            $fixed++;
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."Đã vá {$fixed}/{$rows->count()} notification.");

        return self::SUCCESS;
    }

    private function resolveActorId(object $notification): ?string
    {
        if ($notification->subject_type === 'stock_document'
            && str_ends_with((string) $notification->event_key, 'scan_started')
            && $notification->subject_id !== null
        ) {
            return $this->nearestByTime(
                'document_status_history',
                ['stock_document_id' => $notification->subject_id, 'action' => 'scan_start'],
                $notification->created_at,
                'changed_by',
            );
        }

        if ($notification->subject_type !== null && $notification->subject_id !== null) {
            $actorId = $this->nearestByTime(
                'audit_logs',
                ['entity_type' => $notification->subject_type, 'entity_id' => $notification->subject_id],
                $notification->created_at,
                'actor_id',
            );
            if ($actorId !== null) {
                return $actorId;
            }
        }

        if ($notification->subject_type !== null) {
            // entity_id rỗng trong audit_logs cho vài loại cũ (role/user) — chỉ còn
            // cách khớp theo entity_type + thời điểm gần nhất trong tập nhỏ này.
            return $this->nearestByTime(
                'audit_logs',
                ['entity_type' => $notification->subject_type],
                $notification->created_at,
                'actor_id',
            );
        }

        return null;
    }

    /** @param  array<string,string>  $where */
    private function nearestByTime(string $table, array $where, string $targetCreatedAt, string $valueColumn): ?string
    {
        $query = DB::table($table);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }
        $rows = $query->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $target = Carbon::parse($targetCreatedAt);
        $nearest = $rows->sortBy(
            fn ($candidate) => abs(Carbon::parse($candidate->created_at)->diffInSeconds($target))
        )->first();

        return $nearest->{$valueColumn} ?? null;
    }
}
