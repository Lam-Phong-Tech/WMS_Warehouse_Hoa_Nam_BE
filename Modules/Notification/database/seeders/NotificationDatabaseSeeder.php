<?php

namespace Modules\Notification\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Notification\Services\NotificationService;

/**
 * Trước bản vá này, file chỉ là stub rỗng do nwidart sinh sẵn — module
 * Notification có bảng thật (`notifications`/`notification_recipients`) và
 * listener thật (SendInboundCompletedNotification, SendWarrantyStatusChanged...)
 * nhưng KHÔNG có dòng nào trong `db:seed` vì các listener chỉ chạy khi có event
 * domain thật bắn ra (Post Receipt/Post Issue/đổi trạng thái bảo hành...) —
 * WarehouseBusinessDataSeeder insert thẳng qua DB::table() nên không bắn event,
 * kết quả: chuông thông báo luôn trống trơn sau `db:seed` dù nghiệp vụ mẫu đã có.
 *
 * Bản vá: dùng lại đúng NotificationService::notify() thật (không tự INSERT tay)
 * để tạo thông báo tham chiếu ĐÚNG chứng từ/case đã seed ở WarehouseBusinessDataSeeder,
 * gửi cho đúng user mẫu theo vai trò liên quan — giữ nguyên tắc "seed data đi qua
 * đúng service tầng HTTP dùng" của file kia.
 */
class NotificationDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasTable('notification_recipients')) {
            return;
        }

        // app_user (số ít) là bảng vật lý thật trên sqlite nhưng chỉ là VIEW tương
        // thích ngược trên PostgreSQL (bảng vật lý canonical là app_users, PK id) —
        // cùng lý do dual-driver đã áp dụng ở các migration/seeder Auth khác.
        $userTable = DB::getDriverName() === 'pgsql' ? 'app_users' : 'app_user';
        $userKey = DB::getDriverName() === 'pgsql' ? 'id' : 'user_id';
        $userId = fn (string $username): ?string => DB::table($userTable)
            ->where('username', $username)->value($userKey);

        $adminId = $userId('admin');
        $managerId = $userId('manager01');
        $keeperId = $userId('keeper01');
        $approverId = $userId('approver01');

        if ($adminId === null) {
            // Fixture user chưa được seed (vd môi trường production golive) — không
            // có actor/recipient hợp lệ để gắn notification, bỏ qua an toàn.
            return;
        }

        $service = app(NotificationService::class);

        $receipt = DB::table('stock_documents')->where('doc_type', 'receipt')->where('status', 'POSTED')->orderBy('doc_no')->first();
        if ($receipt !== null && ! DB::table('notifications')->where('event_key', 'inbound.completed')->where('subject_id', $receipt->id)->exists()) {
            $service->notify(
                eventKey: 'inbound.completed',
                title: 'Đã hoàn tất nhập kho',
                body: "Phiếu nhập {$receipt->doc_no} đã Post Receipt thành công.",
                userIds: array_values(array_filter([$adminId, $managerId, $keeperId])),
                severity: 'info',
                sourceModule: 'warehouse',
                subjectType: 'stock_document',
                subjectId: (string) $receipt->id,
                payload: ['document_id' => (string) $receipt->id, 'doc_no' => $receipt->doc_no, 'warehouse_id' => $receipt->dst_warehouse_id],
                actorId: $adminId,
            );
        }

        $issue = DB::table('stock_documents')->where('doc_type', 'issue')->where('status', 'POSTED')->orderBy('doc_no')->first();
        if ($issue !== null && ! DB::table('notifications')->where('event_key', 'outbound.posted')->where('subject_id', $issue->id)->exists()) {
            $notification = $service->notify(
                eventKey: 'outbound.posted',
                title: 'Đã hoàn tất xuất kho',
                body: "Phiếu xuất {$issue->doc_no} đã Post Issue thành công; tồn kho đã được trừ.",
                userIds: array_values(array_filter([$adminId, $approverId])),
                severity: 'info',
                sourceModule: 'warehouse',
                subjectType: 'stock_document',
                subjectId: (string) $issue->id,
                payload: ['document_id' => (string) $issue->id, 'doc_no' => $issue->doc_no, 'warehouse_id' => $issue->src_warehouse_id],
                actorId: $adminId,
            );

            // Demo trạng thái "đã đọc" — không phải mọi notification mẫu đều
            // unread, để UI badge/đếm số chưa đọc có cả 2 trạng thái để kiểm thử.
            if ($notification !== null && $approverId !== null) {
                DB::table('notification_recipients')
                    ->where('notification_id', $notification->id)
                    ->where('user_id', $approverId)
                    ->update(['read_at' => now()]);
            }
        }

        $warrantyCase = DB::table('warranty_case')->where('warranty_case_code', 'WC-SEED-0001')->first();
        if ($warrantyCase !== null && ! DB::table('notifications')->where('event_key', 'warranty.status_changed')->where('subject_id', $warrantyCase->warranty_case_id)->exists()) {
            $service->notify(
                eventKey: 'warranty.status_changed',
                title: 'Ca bảo hành đổi trạng thái',
                body: 'Ca bảo hành chuyển từ RECEIVED sang CHECKING.',
                userIds: array_values(array_filter([$adminId, $keeperId])),
                severity: 'info',
                sourceModule: 'warehouse',
                subjectType: 'warranty_case',
                subjectId: (string) $warrantyCase->warranty_case_id,
                payload: ['case_id' => (string) $warrantyCase->warranty_case_id, 'from_status' => 'RECEIVED', 'to_status' => 'CHECKING'],
                actorId: $adminId,
            );
        }
    }
}
