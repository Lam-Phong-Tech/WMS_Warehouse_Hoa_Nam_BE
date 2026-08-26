<?php

namespace Modules\Notification\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use OpenApi\Attributes as OA;

/**
 * App dùng JWT (auth:api), không có session/Sanctum, nên không dùng
 * Broadcast::routes() mặc định (giả định web/session) — tự khai route auth
 * cho channel private ở đây, bọc middleware auth:api như mọi route khác.
 */
class BroadcastAuthController extends Controller
{
    #[OA\Post(
        path: '/api/v1/broadcasting/auth',
        operationId: 'broadcasting_auth',
        summary: 'Xác thực quyền lắng nghe channel private/presence của Laravel Reverb (WebSocket)',
        description: 'Client WebSocket (Laravel Echo) gọi endpoint này để lấy chữ ký kênh riêng tư trước khi '
            .'subscribe. Yêu cầu JWT hợp lệ qua `auth:api` — không dùng session/Sanctum. Quyền truy cập từng '
            .'channel do định nghĩa trong `routes/channels.php` quyết định (vd channel theo user_id chỉ authorize '
            .'đúng chủ tài khoản).',
        security: [['bearerAuth' => []]],
        tags: ['16. Notifications'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    type: 'object',
                    required: ['socket_id', 'channel_name'],
                    properties: [
                        new OA\Property(property: 'socket_id', type: 'string', example: '123.456'),
                        new OA\Property(property: 'channel_name', type: 'string', example: 'private-notifications.<user_id>'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Chữ ký xác thực channel (auth token) cho Laravel Echo'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Không có quyền truy cập channel này'),
        ]
    )]
    public function authenticate(Request $request)
    {
        return Broadcast::auth($request);
    }
}
