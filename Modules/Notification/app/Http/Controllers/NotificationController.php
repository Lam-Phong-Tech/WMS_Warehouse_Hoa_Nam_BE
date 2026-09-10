<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Notification\Models\NotificationRecipient;
use OpenApi\Attributes as OA;

class NotificationController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    /**
     * Thông báo là dữ liệu RIÊNG TỪNG NGƯỜI nên dùng namespace phiên bản riêng chứ
     * không dùng chung `stock:version`: một lần đánh dấu đã đọc không được kéo theo
     * invalidate toàn bộ cache tồn kho/báo cáo, và ngược lại một mutation tồn kho
     * cũng không cần dựng lại danh sách thông báo.
     */
    private const CACHE_VERSION_NS = 'notification:version';

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
        $perPage = (int) $request->input('per_page', 15);
        $currentPage = LengthAwarePaginator::resolveCurrentPage();

        // TTL 'stock_short' (20s): danh sách thông báo phải bám sát thao tác của chính
        // người đang xem. actorId BẮT BUỘC nằm trong khoá — thiếu nó là người này đọc
        // được thông báo của người kia, lỗi nặng hơn hẳn dữ liệu cũ. Phân trang chạy
        // TRONG SQL nên page/per_page phải nằm trong khoá (readFiltersWithPaging).
        $cached = $this->cachedReadPayload(
            'notification:list',
            $this->readFiltersWithPaging($request),
            function () use ($request, $perPage): array {
                $recipients = NotificationRecipient::query()
                    ->with('notification')
                    ->where('user_id', $request->user()->user_id)
                    ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
                    ->when($request->filled('severity'), fn ($q) => $q->whereHas(
                        'notification',
                        fn ($n) => $n->where('severity', $request->input('severity'))
                    ))
                    ->orderByDesc('created_at')
                    ->paginate($perPage);

                return [
                    'items' => collect($recipients->items())
                        ->map(fn (NotificationRecipient $r): array => $this->present($r))
                        ->all(),
                    'total' => $recipients->total(),
                ];
            },
            'stock_short',
            self::CACHE_VERSION_NS,
            $this->readCacheActorId(),
        );

        // Dựng lại paginator để envelope (meta phân trang + links) giữ NGUYÊN hình dạng
        // cũ — cùng path/pageName mà ->paginate() vẫn dùng. `meta.request_id`/`timestamp`
        // do respondPaginated() sinh mới mỗi request nên không bị cache.
        return $this->respondPaginated(new LengthAwarePaginator(
            $cached['items'],
            $cached['total'],
            $perPage,
            $currentPage,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        ));
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
        // Badge chưa đọc: cùng TTL/namespace/actor với danh sách để hai chỗ không bao
        // giờ lệch nhau, và cùng được bump ngay khi người dùng đánh dấu đã đọc.
        $payload = $this->cachedReadPayload(
            'notification:unread-count',
            [],
            fn (): array => [
                'unread_count' => NotificationRecipient::query()
                    ->where('user_id', $request->user()->user_id)
                    ->whereNull('read_at')
                    ->count(),
            ],
            'stock_short',
            self::CACHE_VERSION_NS,
            $this->readCacheActorId(),
        );

        return $this->respondSuccess($payload);
    }

    #[OA\Get(
        path: '/api/v1/notifications/{id}',
        operationId: 'notification_show',
        summary: 'Chi tiết 1 thông báo của tôi',
        description: 'Chỉ ĐỌC, không tự đánh dấu đã đọc — muốn đánh dấu thì gọi PATCH /notifications/{id}/read. '
            .'Thông báo của người khác trả 404 chứ không phải 403, để không lộ việc id đó có tồn tại. '
            .'Trỏ tới đối tượng nghiệp vụ qua subject_type + subject_id.',
        security: [['bearerAuth' => []]],
        tags: ['16. Notifications'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Thông báo của user hiện tại'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy thông báo của bạn với id này'),
        ]
    )]
    public function show(Request $request, string $id)
    {
        // id không phải UUID thì trên PostgreSQL câu so sánh cột uuid ném 22P02 và
        // biến một lỗi "không tìm thấy" thành 500. Chặn trước, trả đúng 404.
        if (! Str::isUuid($id) && ! ctype_digit($id)) {
            return $this->respondNotFound('Không tìm thấy thông báo.');
        }

        $recipient = NotificationRecipient::query()
            ->with('notification')
            ->where('user_id', $request->user()->user_id)
            ->where('id', $id)
            ->first();

        if ($recipient === null) {
            return $this->respondNotFound('Không tìm thấy thông báo.');
        }

        return $this->respondSuccess($this->present($recipient));
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

        // Bump SAU commit: gọi trước commit thì một request khác có thể nạp lại cache từ
        // dữ liệu CHƯA commit, và badge chưa đọc vẫn sai suốt phần đời còn lại của TTL.
        DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));

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

        // Cùng lý do với markRead(): danh sách và badge phải mới NGAY sau khi ghi, không
        // được chờ hết 20s TTL.
        DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));

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
            'actor_id' => $notification?->actor_id,
            'actor_name' => $notification?->actor_name,
            'payload' => $notification?->payload,
            'read_at' => $recipient->read_at?->toIso8601String(),
            'created_at' => $recipient->created_at?->toIso8601String(),
        ];
    }
}
