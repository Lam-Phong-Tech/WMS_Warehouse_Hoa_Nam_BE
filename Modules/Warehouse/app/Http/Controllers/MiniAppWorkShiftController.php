<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Auth\Models\User;
use Modules\Warehouse\Support\MiniAppWorkShiftService;
use OpenApi\Attributes as OA;

class MiniAppWorkShiftController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MiniAppWorkShiftService $shifts) {}

    #[OA\Get(
        path: '/api/v1/mini-app/work-shifts/current', operationId: 'miniAppCurrentWorkShift',
        summary: 'Ca đang hoạt động của chính phiên hiện tại; không tạo ca',
        security: [['bearerAuth' => []]], tags: ['Mini App - Work Shifts'],
        responses: [
            new OA\Response(response: 200, description: 'data: WorkShift hoặc null; meta.shift_admission: p01-p14-admission-v1, chỉ capability phía server, không phải quyền bỏ qua UNKNOWN/local CLEAN'),
            new OA\Response(response: 401, description: 'Chưa xác thực hoặc phiên đã hết hiệu lực'),
            new OA\Response(response: 403, description: 'Không có quyền hoặc kho ngoài phạm vi'),
            new OA\Response(response: 404, description: 'Kho không còn tồn tại'),
            new OA\Response(response: 409, description: 'AUTH_SESSION_REQUIRED hoặc WAREHOUSE_INACTIVE'),
        ]
    )]
    public function current(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        $current = $this->shifts->current($user);
        $admission = $this->shifts->admission($user, $current);
        $response = $this->respondSuccess($current);
        $body = $response->getData(true);
        $body['meta']['shift_admission'] = $admission;

        return $response->setData($body)->header('Cache-Control', 'no-store, private');
    }

    #[OA\Post(
        path: '/api/v1/mini-app/work-shifts/start', operationId: 'miniAppStartWorkShift',
        summary: 'Bắt đầu ca theo phiên; gọi lại không tạo ca trùng',
        security: [['bearerAuth' => []]], tags: ['Mini App - Work Shifts'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true,
            schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['warehouse_id'], properties: [new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid')]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Đã lưu ca mới; data chứa shift_id, actor_id, auth_session_id, warehouse_id, status, started_at, preview_ready'),
            new OA\Response(response: 200, description: 'Trả lại đúng ca đã có, không thay started_at'),
            new OA\Response(response: 401, description: 'Chưa xác thực hoặc phiên đã hết hiệu lực'),
            new OA\Response(response: 403, description: 'Không có quyền hoặc kho ngoài phạm vi'),
            new OA\Response(response: 404, description: 'WAREHOUSE_NOT_FOUND'),
            new OA\Response(response: 409, description: 'AUTH_SESSION_REQUIRED, WAREHOUSE_INACTIVE, IDEMPOTENCY_KEY_REUSED hoặc SHIFT_WAREHOUSE_CONFLICT'),
            new OA\Response(response: 422, description: 'Body hoặc Idempotency-Key không hợp lệ'),
        ]
    )]
    public function start(Request $request): JsonResponse
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $extra = array_values(array_diff(array_keys($body), ['warehouse_id']));
        if ($extra !== []) {
            ApiError::abort(422, 'SHIFT_FIELD_NOT_ALLOWED', 'Chỉ được gửi warehouse_id; người dùng và phiên do máy chủ xác định.', ['fields' => $extra]);
        }
        $data = Validator::make($body, ['warehouse_id' => ['required', 'uuid']])->validate();
        $key = trim((string) $request->header('Idempotency-Key'));
        if (Validator::make(['key' => $key], ['key' => ['required', 'uuid']])->fails()) {
            ApiError::abort(422, 'IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key phải là UUID hợp lệ.');
        }
        /** @var User $user */
        $user = auth('api')->user();
        $result = $this->shifts->start($user, strtolower($data['warehouse_id']), strtolower($key));
        $response = $result['created']
            ? $this->respondCreated($result['data'], 'Bắt đầu ca thành công.')
            : $this->respondSuccess($result['data'], 'Tiếp tục ca hiện tại.');

        return $response->header('Cache-Control', 'no-store, private');
    }
}
