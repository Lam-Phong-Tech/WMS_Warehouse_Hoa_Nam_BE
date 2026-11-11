<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Support\Cache\Concerns\CachesReadPayload;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Notification\Models\NotificationRecipient;
use Modules\Notification\Support\EventSourceSnapshot;
use Modules\Notification\Support\NotificationContentAccess;
use Modules\Notification\Support\NotificationTimestamp;
use Modules\Notification\Support\P13NotificationCursor;
use Modules\Notification\Support\P13NotificationPresentation;
use OpenApi\Attributes as OA;

class NotificationController extends Controller
{
    use ApiResponse;
    use CachesReadPayload;

    private const CACHE_VERSION_NS = 'notification:version';

    #[OA\Get(
        path: '/api/v1/notifications',
        operationId: 'notification_list',
        summary: 'Danh sách thông báo của tôi (offset hoặc keyset)',
        description: 'id là NotificationRecipient.id, không phải notification_id. Dùng cursor=start cho lượt keyset đầu, sau đó gửi nguyên next_cursor với cùng bộ lọc; không gửi page. Cursor hết hạn sau 900 giây. Unread là tập hiện tại, không phải snapshot bất biến.',
        security: [['bearerAuth' => []]],
        tags: ['16. Notifications'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 15)),
            new OA\Parameter(name: 'unread', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'severity', in: 'query', schema: new OA\Schema(type: 'string', enum: ['info', 'warning', 'critical'])),
            new OA\Parameter(name: 'cursor', in: 'query', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Danh sách thông báo của user hiện tại'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 409, description: 'NOTIFICATION_CURSOR_SCOPE_MISMATCH'),
            new OA\Response(response: 410, description: 'NOTIFICATION_CURSOR_EXPIRED'),
            new OA\Response(response: 422, description: 'Bộ lọc/cursor không hợp lệ hoặc kết hợp page và cursor'),
        ]
    )]
    public function index(Request $request)
    {
        $input = P13NotificationCursor::validate($request);
        $outer = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($request, $input, $outer): JsonResponse {
            // Count, page and projections share this response's snapshot, not a cross-request snapshot.
            if ($outer && DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            if ($input['cursor'] !== null) {
                $page = P13NotificationCursor::read($request, $input);
                $response = $this->respondSuccess($this->presentRows($request, $page['rows'])->all());
                $meta = $page['meta'];
            } else {
                // Retain offset transport for existing clients, but never label it stable for mutable unread.
                $page = P13NotificationCursor::query($request, $input)
                    ->orderByDesc('created_at')->orderByDesc('id')
                    ->paginate($input['per_page'], ['*'], 'page', $input['page']);
                $page->appends($request->query());
                $page->setCollection($this->presentRows($request, $page->getCollection()));
                $response = $this->respondPaginated($page);
                $meta = [
                    'contract' => P13NotificationCursor::CONTRACT, 'pagination_mode' => 'offset',
                    'sort' => ['created_at' => 'desc', 'id' => 'desc'], 'snapshot_consistent' => false,
                    'mutable_unread_safe' => false, 'keyset_start' => 'start',
                ];
            }

            return $this->withReadMeta($response, $meta);
        });
    }

    #[OA\Get(
        path: '/api/v1/notifications/unread-count',
        operationId: 'notification_unread_count',
        summary: 'Số thông báo chưa đọc của tôi',
        security: [['bearerAuth' => []]],
        tags: ['16. Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'data.unread_count là số thật, không giới hạn 9+/99+'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function unreadCount(Request $request)
    {
        // One fresh SQL count: list and badge caches cannot establish a common snapshot.
        return $this->withReadMeta($this->respondSuccess(['unread_count' => $this->liveUnreadCount($request)]), [
            'count_scope' => 'CURRENT_USER_ALL_UNREAD', 'count_consistency' => 'LIVE_DATABASE_READ',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/notifications/{id}',
        operationId: 'notification_show',
        summary: 'Chi tiết 1 thông báo của tôi',
        description: 'id là recipient UUID. GET không đánh dấu đã đọc. Recipient khác user hoặc không tồn tại đều trả 404. Thông báo không cấp quyền đọc subject.',
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
        if (! Str::isUuid($id)) {
            return $this->respondNotFound('Không tìm thấy thông báo.');
        }
        $recipient = NotificationRecipient::query()->with('notification')
            ->where('user_id', $request->user()->user_id)->where('id', strtolower($id))->first();
        if ($recipient === null) {
            return $this->respondNotFound('Không tìm thấy thông báo.');
        }

        return $this->withReadMeta($this->respondSuccess($this->presentRows($request, collect([$recipient]))->first()));
    }

    #[OA\Patch(
        path: '/api/v1/notifications/{id}/read',
        operationId: 'notification_mark_read',
        summary: 'Đánh dấu 1 thông báo đã đọc',
        description: 'Không gửi body/query, kể cả JSON rỗng. Ghi một chiều, idempotent và giữ read_at đầu tiên. Timeout: GET lại cùng recipient để đối chiếu; không tự trừ badge. Không cần và không mở quyền subject.',
        security: [['bearerAuth' => []]],
        tags: ['16. Notifications'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Recipient, read_at; meta.changed và meta.unread_count sau thao tác'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy thông báo của bạn với id này'),
            new OA\Response(response: 422, description: 'NOTIFICATION_READ_BODY_NOT_ALLOWED'),
        ]
    )]
    public function markRead(Request $request, string $id)
    {
        if (! Str::isUuid($id)) {
            return $this->respondNotFound('Không tìm thấy thông báo.');
        }
        // Reject bytes as well as parsed parameters: {}, [], null and malformed JSON are bodies too.
        if ($request->getContent() !== '' || $request->request->all() !== []
            || $request->query() !== [] || $request->allFiles() !== []) {
            ApiError::abort(422, 'NOTIFICATION_READ_BODY_NOT_ALLOWED', 'Không gửi dữ liệu khi đánh dấu đã đọc.');
        }
        [$recipient, $changed] = DB::transaction(function () use ($request, $id): array {
            $query = NotificationRecipient::query()->where('user_id', $request->user()->user_id)->where('id', strtolower($id));
            $recipient = (clone $query)->lockForUpdate()->first();
            if ($recipient === null) {
                return [null, false];
            }
            $changed = (clone $query)->whereNull('read_at')->update(['read_at' => NotificationTimestamp::write(now())]) === 1;
            if ($changed) {
                DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));
            }

            return [$recipient->fresh('notification'), $changed];
        }, 3);
        if ($recipient === null) {
            return $this->respondNotFound('Không tìm thấy thông báo.');
        }

        return $this->withReadMeta(
            $this->respondSuccess($this->presentRows($request, collect([$recipient]))->first(), 'Đã đánh dấu đã đọc'),
            ['changed' => $changed, 'unread_count' => $this->liveUnreadCount($request), 'count_consistency' => 'LIVE_AFTER_READ'],
        );
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
            ->update(['read_at' => NotificationTimestamp::write(now())]);

        // Cùng lý do với markRead(): danh sách và badge phải mới NGAY sau khi ghi, không
        // được chờ hết 20s TTL.
        DB::afterCommit(fn () => $this->bumpReadCache(self::CACHE_VERSION_NS));

        return $this->respondSuccess(['updated_count' => $updated], 'Đã đánh dấu tất cả đã đọc');
    }

    private function liveUnreadCount(Request $request): int
    {
        return NotificationRecipient::query()->where('user_id', $request->user()->user_id)->whereNull('read_at')->count();
    }

    private function presentRows(Request $request, Collection $recipients): Collection
    {
        $snapshots = DB::table('notification_source_snapshots')->whereIn('notification_id', $recipients->pluck('notification_id'))->get()->keyBy('notification_id');

        return $recipients->map(function (NotificationRecipient $recipient) use ($snapshots, $request): array {
            $notification = $recipient->notification;
            $rawReadAt = $recipient->getRawOriginal('read_at');
            $readAt = NotificationTimestamp::read($rawReadAt);
            $allowed = NotificationContentAccess::allows($request->user(), $notification);
            $denied = ! $allowed;

            return [
                'id' => $recipient->id,
                'notification_id' => $notification?->id,
                'event_key' => $notification?->event_key,
                'title' => $denied ? NotificationContentAccess::DENIED_TITLE : $notification?->title,
                'body' => $denied ? NotificationContentAccess::DENIED_BODY : $notification?->body,
                'severity' => $notification?->severity,
                'source_module' => $notification?->source_module,
                'subject_type' => $denied ? null : $notification?->subject_type,
                'subject_id' => $denied ? null : $notification?->subject_id,
                'actor_id' => $denied ? null : $notification?->actor_id,
                'actor_name' => $denied ? null : $notification?->actor_name,
                'payload' => $denied ? null : $notification?->payload,
                'read_at' => $readAt,
                'read_at_status' => $rawReadAt === null ? 'NOT_READ' : ($readAt === null ? 'SOURCE_INVALID' : 'RECORDED'),
                'created_at' => NotificationTimestamp::read($recipient->getRawOriginal('created_at')),
                'payload_semantics' => $denied ? 'CONTENT_ACCESS_DENIED' : 'EVENT_SNAPSHOT_NOT_AUTHORIZATION',
                'content_access' => $denied ? 'DENIED' : 'GRANTED',
                'subject' => $denied ? null : P13NotificationPresentation::subject($recipient),
                'source_snapshot' => $denied ? null : EventSourceSnapshot::presentation($notification?->id ?? '', $snapshots->get($notification?->id)),
            ];
        })->values();
    }

    private function withReadMeta(JsonResponse $response, array $meta = []): JsonResponse
    {
        $body = $response->getData(true);
        $body['meta'] = array_merge($body['meta'] ?? [], [
            'contract' => P13NotificationCursor::CONTRACT,
            'projection_revision' => P13NotificationPresentation::REVISION,
            'timestamp_contract' => NotificationTimestamp::CONTRACT,
            'count_unit' => 'NOTIFICATION_RECIPIENT',
        ], $meta);

        return $response->setData($body)->header('Cache-Control', 'private, no-store');
    }
}
