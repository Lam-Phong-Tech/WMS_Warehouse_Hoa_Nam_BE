<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiError;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Auth\Models\User;
use Modules\Auth\Support\AuthUserPayload;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Auth\Support\WarehouseContext;

/** Handler for the EXISTING POST /auth/switch-context, not a new endpoint. */
final class WarehouseContextController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WarehouseContext $contexts,
        private readonly RefreshTokenService $tokens,
    ) {}

    public function switchContext(Request $request): JsonResponse
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $extra = array_values(array_diff(array_keys($body), ['context_type', 'context_id', 'scope_ref']));
        if ($extra !== []) {
            ApiError::abort(422, 'CONTEXT_FIELD_NOT_ALLOWED', 'Chỉ gửi context_type, context_id hoặc scope_ref; người dùng và phiên do máy chủ xác định.', ['fields' => $extra]);
        }
        $data = Validator::make($body, [
            'context_type' => ['nullable', 'string', 'in:WAREHOUSE,SYSTEM'],
            'context_id' => ['nullable', 'uuid'],
            'scope_ref' => ['nullable', 'uuid'],
        ])->validate();
        $contextId = isset($data['context_id']) ? strtolower($data['context_id']) : null;
        $scopeRef = isset($data['scope_ref']) ? strtolower($data['scope_ref']) : null;
        if ($contextId !== null && $scopeRef !== null && $contextId !== $scopeRef) {
            ApiError::abort(422, 'CONTEXT_FIELD_MISMATCH', 'context_id và scope_ref phải trỏ cùng một kho.');
        }
        $warehouseId = $contextId ?? $scopeRef;
        $type = $data['context_type'] ?? ($warehouseId === null ? 'SYSTEM' : 'WAREHOUSE');
        if ($type === 'WAREHOUSE' && $warehouseId === null) {
            ApiError::abort(422, 'WAREHOUSE_CONTEXT_REQUIRED', 'Cần UUID của kho đã được xác nhận.');
        }
        if ($type === 'SYSTEM' && $warehouseId !== null) {
            ApiError::abort(422, 'CONTEXT_FIELD_MISMATCH', 'Ngữ cảnh SYSTEM không nhận UUID kho.');
        }

        /** @var User $user */
        $user = auth('api')->user();
        $actor = $this->contexts->change($user, $warehouseId);
        // Keep both the stable login family and the existing JWT sid. Switching
        // does not rotate refresh credentials or invalidate another device.
        $accessToken = $this->tokens->accessTokenFor($actor, $this->tokens->currentSessionId());

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'active_context' => [
                'context_type' => $type,
                'context_id' => $warehouseId,
                'scope_ref' => $warehouseId,
            ],
            'user' => AuthUserPayload::full($actor),
        ], 'Đã cập nhật ngữ cảnh làm việc')->header('Cache-Control', 'no-store, private');
    }
}
