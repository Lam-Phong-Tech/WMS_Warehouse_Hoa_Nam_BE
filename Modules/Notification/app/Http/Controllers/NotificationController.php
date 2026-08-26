<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Notification\Models\NotificationRecipient;
use OpenApi\Attributes as OA;

class NotificationController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/notifications',
        operationId: 'notification_list',
        summary: 'Danh sách thông báo của tôi (phân trang, filter unread/severity)',
        security: [['bearerAuth' => []]],
        tags: ['16. Notifications'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 15)),
            new OA\Parameter(name: 'unread', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'severity', in: 'query', schema: new OA\Schema(type: 'string', enum: ['info', 'warning', 'critical'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách thông báo của user hiện tại'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function index(Request $request)
    {
        $query = NotificationRecipient::query()
            ->with('notification')
            ->where('user_id', $request->user()->user_id)
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->when($request->filled('severity'), fn ($q) => $q->whereHas(
                'notification',
                fn ($n) => $n->where('severity', $request->input('severity'))
            ))
            ->orderByDesc('created_at');

        $recipients = $query->paginate((int) $request->input('per_page', 15));
        $recipients->getCollection()->transform(fn (NotificationRecipient $r) => $this->present($r));

        return $this->respondPaginated($recipients);
    }

    #[OA\Get(
        path: '/api/v1/notifications/unread-count',
        operationId: 'notification_unread_count',
        summary: 'Số thông báo chưa đọc của tôi',
        security: [['bearerAuth' => []]],
        tags: ['16. Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'unread_count'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function unreadCount(Request $request)
    {
        $count = NotificationRecipient::query()
            ->where('user_id', $request->user()->user_id)
            ->whereNull('read_at')
            ->count();

        return $this->respondSuccess(['unread_count' => $count]);
    }

    #[OA\Patch(
        path: '/api/v1/notifications/{id}/read',
        operationId: 'notification_mark_read',
        summary: 'Đánh dấu 1 thông báo đã đọc',
        security: [['bearerAuth' => []]],
        tags: ['16. Notifications'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Đã đánh dấu đã đọc'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy thông báo của bạn với id này'),
        ]
    )]
    public function markRead(Request $request, string $id)
    {
        $recipient = NotificationRecipient::query()
            ->where('user_id', $request->user()->user_id)
            ->where('id', $id)
            ->first();

        if ($recipient === null) {
            return $this->respondNotFound('Không tìm thấy thông báo.');
        }

        if ($recipient->read_at === null) {
            $recipient->read_at = now();
            $recipient->save();
        }

        return $this->respondSuccess($this->present($recipient->fresh('notification')), 'Đã đánh dấu đã đọc');
    }

    #[OA\Post(
        path: '/api/v1/notifications/read-all',
        operationId: 'notification_mark_all_read',
        summary: 'Đánh dấu toàn bộ thông báo của tôi là đã đọc',
        security: [['bearerAuth' => []]],
        tags: ['16. Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'updated_count'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function markAllRead(Request $request)
    {
        $updated = NotificationRecipient::query()
            ->where('user_id', $request->user()->user_id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->respondSuccess(['updated_count' => $updated], 'Đã đánh dấu tất cả đã đọc');
    }

    private function present(NotificationRecipient $recipient): array
    {
        $notification = $recipient->notification;

        return [
            'id' => $recipient->id,
            'notification_id' => $notification?->id,
            'event_key' => $notification?->event_key,
            'title' => $notification?->title,
            'body' => $notification?->body,
            'severity' => $notification?->severity,
            'source_module' => $notification?->source_module,
            'subject_type' => $notification?->subject_type,
            'subject_id' => $notification?->subject_id,
            'payload' => $notification?->payload,
            'read_at' => $recipient->read_at?->toIso8601String(),
            'created_at' => $recipient->created_at?->toIso8601String(),
        ];
    }
}
