<?php

namespace Modules\Auth\Http\Controllers;

use App\Support\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Support\AuthUserPayload;
use Modules\Auth\Support\P10ProfilePolicy as Policy;
use Modules\Auth\Support\P10SessionFamily as Family;
use Modules\Auth\Support\P11SecurityContract as SecurityContract;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Auth\Support\RefreshTokenTransport;

/** Existing self-service routes only. Login/refresh/MFA behavior remains inherited. */
final class P10ProfileController extends AuthController
{
    public function me(Request $request): JsonResponse
    {
        $this->noQuery($request);
        return $this->profile($this->actor($request));
    }

    public function updateMe(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $body = $this->body($request, Policy::EDITABLE, 'PROFILE_FIELD_NOT_EDITABLE');
        if (isset($body['display_name']) && is_string($body['display_name'])) {
            $body['display_name'] = trim($body['display_name']);
        }
        $data = Validator::make($body, [
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^0[0-9]{9}$/D'],
        ])->validate();
        if ($data === []) {
            ApiError::abort(422, 'PROFILE_EMPTY_UPDATE', 'Không có trường nào để cập nhật.');
        }
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Gửi If-Match theo ETag của GET auth/me.');
        }
        try {
            $expected = Policy::version($header);
        } catch (\InvalidArgumentException) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là phiên bản số nguyên dương hoặc ETag tương ứng.');
        }
        return DB::transaction(function () use ($request, $data, $expected): JsonResponse {
            $user = $this->actor($request, true);
            if ((int) $user->version !== $expected) {
                ApiError::abort(412, 'STALE_VERSION', 'Hồ sơ đã thay đổi; đọc lại trước khi lưu.', [
                    'expected_version' => $expected, 'current_version' => (int) $user->version,
                ]);
            }
            $user->forceFill([...$data, 'version' => $expected + 1])->save();
            // Log changed field names only, not the customer's/employee's PII.
            AuditLogger::record(action: 'UPDATE_PROFILE', objectType: 'app_user',
                objectId: (string) $user->user_id, actorId: (string) $user->user_id,
                requestId: $request->attributes->get('request_id'),
                afterData: ['changed_fields' => array_keys($data), 'version' => (int) $user->version]);
            return $this->profile($user->fresh(), 'Cập nhật hồ sơ thành công');
        }, 3);
    }

    public function sessions(Request $request): JsonResponse
    {
        $this->noQuery($request, ['page', 'per_page']);
        $input = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();
        return DB::transaction(function () use ($request, $input): JsonResponse {
            $user = $this->actor($request, true);
            $current = Family::current($user, true);
            // Count/page only the caller's active families. The target id stays the
            // existing refresh-row UUID; auth_session_id is the stable login family.
            $ranked = RefreshToken::query()->where('user_id', $user->user_id)->active()
                ->select(['id', 'auth_session_id', 'user_agent', 'ip_address', 'created_at', 'expires_at'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY COALESCE(auth_session_id, id) ORDER BY created_at DESC, id DESC) AS p10_rank');
            $page = DB::query()->fromSub($ranked, 'p10_sessions')->where('p10_rank', 1)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate((int) ($input['per_page'] ?? 20), ['*'], 'page', (int) ($input['page'] ?? 1));
            $page->setCollection($page->getCollection()->map(function (object $row) use ($current): array {
                $family = Policy::family($row->auth_session_id, (string) $row->id);
                $isCurrent = hash_equals($current, $family);
                return [
                    'id' => (string) $row->id, 'auth_session_id' => $family,
                    'session_id' => $family,
                    'is_current' => $isCurrent, 'can_revoke' => ! $isCurrent,
                    'device_label' => null, 'location' => null,
                    'user_agent' => $row->user_agent, 'ip_address' => $row->ip_address,
                    'metadata_source' => 'STORED_REQUEST_HEADERS_NOT_VERIFIED_DEVICE_OR_LOCATION',
                    'last_active_at' => $row->created_at === null ? null : Carbon::parse($row->created_at)->toIso8601String(),
                    'last_active_semantics' => 'LOGIN_OR_TOKEN_REFRESH_NOT_EACH_API_REQUEST',
                    'expires_at' => $row->expires_at === null ? null : Carbon::parse($row->expires_at)->toIso8601String(),
                ];
            }));
            $response = $this->respondPaginated($page);
            $payload = $response->getData(true);
            // Available even when the current family is not on this page.
            $payload['meta']['current_session_id'] = $current;
            $payload['meta']['session_contract'] = [
                'version' => SecurityContract::VERSION, 'stable_session_id' => 'session_id',
                'target_id' => 'id', 'target_id_kind' => 'REFRESH_TOKEN_ROW_UUID',
                'stable_family_id' => 'auth_session_id', 'count_unit' => 'ACTIVE_AUTH_SESSION_FAMILY',
                'revoke_scope' => 'ENTIRE_TARGET_FAMILY', 'current_family_protected' => true,
                'pagination' => 'PAGE_LIVE_NOT_FROZEN', 'request_receipt_supported' => false,
            ];
            return $response->setData($payload)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $this->noQuery($request);
        $this->body($request, []);
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiên đăng nhập.');
        }
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $user = $this->actor($request, true);
            $current = Family::current($user, true);
            $target = RefreshToken::query()->where('user_id', $user->user_id)->whereKey($id)->first();
            if ($target === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy phiên đăng nhập.');
            }
            $family = Policy::family($target->auth_session_id, (string) $target->id);
            if (hash_equals($current, $family)) {
                ApiError::abort(409, 'CURRENT_SESSION_PROTECTED', 'Dùng auth/logout để đăng xuất phiên hiện tại.');
            }
            $changed = Family::revoke((string) $user->user_id, $family);
            if ($changed > 0) {
                AuditLogger::record(action: 'REVOKE_SESSION', objectType: 'refresh_token',
                    objectId: (string) $target->id, actorId: (string) $user->user_id,
                    requestId: $request->attributes->get('request_id'),
                    afterData: ['auth_session_id' => $family, 'scope' => 'TARGET_FAMILY']);
            }
            return $this->respondSuccess([
                'id' => (string) $target->id, 'auth_session_id' => $family,
                'session_id' => $family,
                'revoked' => true, 'already_revoked' => $changed === 0, 'was_current' => false,
                'revocation_scope' => 'AUTH_SESSION_FAMILY', 'request_receipt_supported' => false,
            ], 'Đã thu hồi phiên đăng nhập')->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $body = $this->body($request, ['refresh_token']);
        Validator::make($body, ['refresh_token' => ['nullable', 'string', 'max:255']])->validate();
        $transport = app(RefreshTokenTransport::class);
        if ($transport->mode($request) === RefreshTokenTransport::COOKIE && array_key_exists('refresh_token', $body)) {
            ApiError::abort(422, 'REFRESH_TOKEN_TRANSPORT_MISMATCH', 'Trình duyệt chỉ dùng cookie HttpOnly, không gửi refresh token trong body.');
        }
        $raw = $transport->presentedToken($request);
        $scope = DB::transaction(function () use ($request, $raw): string {
            $user = $this->actor($request, true);
            $family = Family::current($user);
            Family::assertPresentedToken($user, $family, $raw);
            if ($family !== null) {
                Family::revoke((string) $user->user_id, $family);
            }
            $scope = $family === null ? 'CURRENT_ACCESS_TOKEN_ONLY' : 'CURRENT_AUTH_SESSION_FAMILY';
            AuditLogger::record(action: 'LOGOUT', objectType: 'app_user',
                objectId: (string) $user->user_id, actorId: (string) $user->user_id,
                requestId: $request->attributes->get('request_id'), afterData: ['scope' => $scope]);
            return $scope;
        }, 3);
        // Failure here is UNKNOWN to the client; never claim a durable receipt.
        auth('api')->logout();
        return $this->authResult('Đăng xuất thành công', [
            'scope' => $scope, 'business_drafts_deleted' => false, 'work_shift_ended' => false,
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $body = $this->body($request, ['current_password', 'new_password', 'new_password_confirmation']);
        $validator = Validator::make($body, SecurityContract::passwordRules());
        $driver = (string) config('hashing.driver', 'bcrypt');
        if ($validator->fails()) {
            ApiError::abort(422, SecurityContract::validationCode($validator->failed()),
                'Thông tin đổi mật khẩu không hợp lệ.', $validator->errors()->toArray(),
                ['password_policy' => SecurityContract::passwordPolicy($driver)]);
        }
        $data = $validator->validated();
        if (SecurityContract::unsafeEncoding($data['new_password'], $driver)) {
            ApiError::abort(422, 'PASSWORD_POLICY_VIOLATION',
                'Mật khẩu chứa ký tự không hỗ trợ hoặc vượt giới hạn byte của bộ mã hóa.',
                ['new_password' => ['Mật khẩu không đáp ứng password_policy của server.']],
                ['password_policy' => SecurityContract::passwordPolicy($driver)]);
        }
        $newHash = Hash::make($data['new_password']);
        $changedAt = DB::transaction(function () use ($request, $data, $newHash): ?int {
            $user = $this->actor($request, true);
            if (! Hash::check($data['current_password'], $user->password_hash)) {
                ApiError::abort(422, SecurityContract::WRONG_CURRENT, 'Mật khẩu hiện tại không đúng.', ['current_password' => ['Mật khẩu hiện tại không đúng.']]);
            }
            $user->forceFill(['password_hash' => $newHash, 'must_change_password' => false,
                'version' => (int) $user->version + 1])->save();
            $user->markPasswordChangedNow();
            app(RefreshTokenService::class)->revokeAllForUser((string) $user->user_id);
            AuditLogger::record(action: 'CHANGE_PASSWORD', objectType: 'app_user',
                objectId: (string) $user->user_id, actorId: (string) $user->user_id,
                requestId: $request->attributes->get('request_id'),
                afterData: ['session_policy' => 'RELOGIN_ALL_REFRESH_FAMILIES_REVOKED']);
            // Return the persisted server timestamp, never a client clock or receipt.
            return $user->fresh()->passwordChangedAtEpoch();
        }, 3);
        auth('api')->logout();
        return $this->authResult('Đổi mật khẩu thành công. Vui lòng đăng nhập lại.', [
            'session_policy' => 'RELOGIN_ALL_REFRESH_FAMILIES_REVOKED', 'relogin_required' => true,
            'password_changed_at' => $this->passwordTime($changedAt),
            'password_policy_version' => SecurityContract::passwordPolicy($driver)['version'],
        ]);
    }

    private function actor(Request $request, bool $lock = false): User
    {
        $presented = $request->user() ?? auth('api')->user();
        if (! $presented instanceof User) {
            ApiError::abort(401, 'UNAUTHENTICATED', 'Cần đăng nhập.');
        }
        $query = User::query()->whereKey($presented->user_id);
        $user = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($user === null || $user->status !== 'ACTIVE') {
            ApiError::abort(403, 'ACCOUNT_DISABLED', 'Tài khoản không còn hoạt động.');
        }
        Family::current($user); // Sid-bearing access tokens cannot survive family revocation.
        return $user;
    }

    private function profile(User $user, ?string $message = null): JsonResponse
    {
        $response = $this->respondSuccess(AuthUserPayload::full($user), $message ?? 'Thông tin tài khoản');
        $payload = $response->getData(true);
        $payload['meta']['profile_contract'] = Policy::contract();
        $payload['meta']['security_contract'] = SecurityContract::contract((string) config('hashing.driver', 'bcrypt'));
        $payload['data']['password_changed_at'] = $this->passwordTime($user->passwordChangedAtEpoch());
        return $response->setData($payload)->header('ETag', '"'.(int) $user->version.'"')
            ->header('Cache-Control', 'private, no-store');
    }

    private function passwordTime(?int $epoch): ?string
    {
        return $epoch === null ? null : Carbon::createFromTimestamp($epoch, 'UTC')->toIso8601String();
    }

    private function authResult(string $message, array $result): JsonResponse
    {
        $response = $this->respondSuccess(null, $message); // Preserve existing mobile data=null shape.
        $payload = $response->getData(true);
        $payload['meta']['auth_result'] = $result + ['state' => 'SUCCEEDED',
            'durable_request_receipt' => false, 'request_reconcile_supported' => false];
        return $response->setData($payload)->header('Cache-Control', 'private, no-store');
    }

    private function noQuery(Request $request, array $allowed = []): void
    {
        if (array_diff(array_keys($request->query()), $allowed) !== []) {
            ApiError::abort(422, 'PROFILE_UNKNOWN_FILTER', 'Không nhận actor, quyền hoặc ngữ cảnh từ query của API cá nhân.');
        }
    }

    private function body(Request $request, array $allowed, string $code = 'PROFILE_FIELD_NOT_EDITABLE'): array
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $unknown = array_values(array_diff(array_keys($body), $allowed));
        if ($unknown !== [] || $request->allFiles() !== []) {
            ApiError::abort(422, $code, 'Payload có trường chưa được hỗ trợ; không gửi name, nickname, avatar hoặc quyền ngoài contract.', ['fields' => $unknown]);
        }
        return $body;
    }
}
