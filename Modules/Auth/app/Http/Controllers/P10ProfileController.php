<?php

namespace Modules\Auth\Http\Controllers;

use App\Support\ApiError;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Support\AuthUserPayload;
use Modules\Auth\Support\ClientInstallations;
use Modules\Auth\Support\P10ProfilePolicy as Policy;
use Modules\Auth\Support\P10SessionFamily as Family;
use Modules\Auth\Support\P11SecurityCommands;
use Modules\Auth\Support\P11SecurityContract as SecurityContract;
use Modules\Auth\Support\ProfileAvatar;
use Modules\Auth\Support\ProfilePreferences;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Auth\Support\RefreshTokenTransport;
use Throwable;

/** Self-service profile and installation APIs; login/refresh/MFA remain inherited. */
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
        $body = $this->body($request, [...Policy::EDITABLE, 'current_password'], 'PROFILE_FIELD_NOT_EDITABLE');

        return $this->saveProfile($request, $this->profileFields($body), $this->expectedVersion($request));
    }

    private function profileFields(array $body): array
    {
        if (isset($body['display_name']) && is_string($body['display_name'])) {
            $body['display_name'] = trim($body['display_name']);
        }
        if (isset($body['email']) && is_string($body['email'])) {
            $body['email'] = strtolower(trim($body['email']));
        }

        return Validator::make($body, [
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^0[0-9]{9}$/D'],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:200'],
            'current_password' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ])->validate();
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'Gửi If-Match theo ETag mới nhất.');
        }
        try {
            return Policy::version($header);
        } catch (\InvalidArgumentException) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là phiên bản số nguyên dương hoặc ETag tương ứng.');
        }
    }

    private function saveProfile(Request $request, array $data, int $expected, ?array $avatar = null, bool $removeAvatar = false): JsonResponse
    {
        if (array_diff(array_keys($data), ['current_password']) === [] && $avatar === null && ! $removeAvatar) {
            ApiError::abort(422, 'PROFILE_EMPTY_UPDATE', 'Không có trường nào để cập nhật.');
        }
        try {
            return DB::transaction(function () use ($request, $data, $expected, $avatar, $removeAvatar): JsonResponse {
                $user = $this->actor($request, true);
                if ((int) $user->version !== $expected) {
                    ApiError::abort(412, 'STALE_VERSION', 'Hồ sơ đã thay đổi; đọc lại trước khi lưu.', [
                        'expected_version' => $expected, 'current_version' => (int) $user->version,
                    ]);
                }
                if (array_key_exists('email', $data)) {
                    if (strtolower((string) $user->email) !== $data['email']
                        && (! is_string($data['current_password'] ?? null) || ! Hash::check($data['current_password'], $user->password_hash))) {
                        ApiError::abort(422, 'INVALID_CURRENT_PASSWORD', 'Nhập mật khẩu hiện tại để thay đổi email.',
                            ['current_password' => ['Mật khẩu hiện tại không đúng hoặc chưa được nhập.']]);
                    }
                    Validator::make($data, ['email' => [Rule::unique('app_user', 'email')->ignore($user->user_id, 'user_id')]])->validate();
                    if (User::withTrashed()->where('user_id', '!=', $user->user_id)->whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
                        throw ValidationException::withMessages(['email' => ['Email đã được sử dụng.']]);
                    }
                }
                unset($data['current_password']);
                $user->forceFill([...$data, 'version' => $expected + 1])->save();
                if ($avatar !== null) {
                    DB::table('user_profile_avatars')->updateOrInsert(['user_id' => $user->user_id], [...$avatar, 'updated_at' => now('UTC')]);
                } elseif ($removeAvatar) {
                    DB::table('user_profile_avatars')->where('user_id', $user->user_id)->delete();
                }
                // Log changed field names only, not the customer's/employee's PII.
                AuditLogger::record(action: 'UPDATE_PROFILE', objectType: 'app_user',
                    objectId: (string) $user->user_id, actorId: (string) $user->user_id,
                    requestId: $request->attributes->get('request_id'),
                    afterData: ['changed_fields' => [...array_keys($data), ...($avatar !== null || $removeAvatar ? ['avatar'] : [])], 'version' => (int) $user->version]);

                return $this->profile($user->fresh(), 'Cập nhật hồ sơ thành công');
            }, 3);
        } catch (QueryException $error) {
            // Different users hold different parent locks; the unique index is the
            // final arbiter when two email changes race after validation.
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)
                && str_contains(strtolower($error->getMessage()), 'app_user')
                && str_contains(strtolower($error->getMessage()), 'email')) {
                throw ValidationException::withMessages(['email' => ['Email đã được sử dụng.']]);
            }
            throw $error;
        }
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $body = $request->request->all();
        if (array_diff(array_keys($body), [...Policy::EDITABLE, 'current_password']) !== []
            || array_diff(array_keys($request->allFiles()), ['avatar']) !== []) {
            ApiError::abort(422, 'PROFILE_FIELD_NOT_EDITABLE', 'Chỉ gửi ảnh và các trường hồ sơ được phép.');
        }
        $expected = $this->expectedVersion($request);
        $data = $this->profileFields($body);
        Validator::make($request->allFiles(), ['avatar' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048']])->validate();

        return $this->saveProfile($request, $data, $expected, ProfileAvatar::image($request->file('avatar')));
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $this->body($request, []);

        return $this->saveProfile($request, [], $this->expectedVersion($request), removeAvatar: true);
    }

    public function avatar(Request $request): Response
    {
        $this->noQuery($request);
        $user = $this->actor($request);
        $row = DB::table('user_profile_avatars')->where('user_id', $user->user_id)->first();
        if ($row === null) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Chưa có ảnh đại diện.');
        }

        return response(base64_decode($row->image_base64, true))->header('Content-Type', $row->mime_type)
            ->header('Content-Length', (string) $row->size)->header('Cache-Control', 'private, no-store, no-transform')
            ->header('X-Content-Type-Options', 'nosniff')->header('Content-Disposition', 'inline; filename="avatar"');
    }

    public function preferences(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $data = ProfilePreferences::read((string) $this->actor($request)->user_id);

        return $this->respondSuccess($data)->header('ETag', '"'.$data['version'].'"')->header('Cache-Control', 'private, no-store');
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $data = ProfilePreferences::validate($this->body($request, array_keys(ProfilePreferences::defaults()), 'PREFERENCE_FIELD_NOT_EDITABLE'));
        $expected = $this->expectedVersion($request);

        return DB::transaction(function () use ($request, $data, $expected): JsonResponse {
            $user = $this->actor($request, true);
            $saved = ProfilePreferences::put((string) $user->user_id, $data, $expected);
            AuditLogger::record(action: 'UPDATE_PROFILE_PREFERENCES', objectType: 'app_user', objectId: (string) $user->user_id,
                actorId: (string) $user->user_id, requestId: $request->attributes->get('request_id'),
                afterData: ['changed_fields' => array_keys($data), 'version' => $saved['version']]);

            return $this->respondSuccess($saved)->header('ETag', '"'.$saved['version'].'"')->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function devices(Request $request): JsonResponse
    {
        $this->noQuery($request);

        return DB::transaction(function () use ($request): JsonResponse {
            $user = $this->actor($request, true);
            $groups = ClientInstallations::groups((string) $user->user_id, Family::current($user, true));
            $response = $this->respondSuccess($groups['data']);
            $payload = $response->getData(true);
            $payload['meta'] = [...($payload['meta'] ?? []), ...$groups['meta']];

            return $response->setData($payload)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    /** Read-only ended login families; active device inventory remains unchanged. */
    public function deviceHistory(Request $request): JsonResponse
    {
        $this->noQuery($request, ['page', 'per_page']);
        $input = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        return DB::transaction(function () use ($request, $input): JsonResponse {
            $user = $this->actor($request, true);
            // Rank ALL rows before testing inactivity: a revoked rotation ancestor
            // must never make a still-active login appear signed out. Prefer an
            // unrevoked descendant even when timestamps tie within one second.
            $ranked = RefreshToken::query()->where('user_id', $user->user_id)
                ->select(['id', 'auth_session_id', 'auth_device_id', 'auth_session_started_at', 'user_agent', 'ip_address', 'created_at', 'expires_at', 'revoked_at'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY COALESCE(auth_session_id, id) ORDER BY CASE WHEN revoked_at IS NULL THEN 0 ELSE 1 END, created_at DESC, expires_at DESC, id DESC) AS history_rank');
            $page = DB::query()->fromSub($ranked, 'login_history')->where('history_rank', 1)
                ->where(fn ($query) => $query->whereNotNull('revoked_at')->orWhere('expires_at', '<=', now()))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate((int) ($input['per_page'] ?? 6), ['*'], 'page', (int) ($input['page'] ?? 1));
            $devices = DB::table('auth_client_installations')->where('user_id', $user->user_id)
                ->whereIn('id', $page->getCollection()->pluck('auth_device_id')->filter())->get()->keyBy('id');
            $page->setCollection($page->getCollection()->map(function (object $row) use ($devices): array {
                $expiredFirst = $row->revoked_at === null || Carbon::parse($row->expires_at)->lte(Carbon::parse($row->revoked_at));

                return [...ClientInstallations::session($row, '', $devices->get($row->auth_device_id)),
                    'is_current' => false, 'can_revoke' => false,
                    'status' => $expiredFirst ? 'EXPIRED' : 'SIGNED_OUT',
                    'ended_at' => ClientInstallations::time($expiredFirst ? $row->expires_at : $row->revoked_at)];
            }));

            return $this->respondPaginated($page)->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function revokeDevice(Request $request, string $id): JsonResponse
    {
        $this->noQuery($request);
        $this->body($request, []);
        if (! Str::isUuid($id)) {
            ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy thiết bị.');
        }

        return DB::transaction(function () use ($request, $id): JsonResponse {
            $user = $this->actor($request, true);
            $device = DB::table('auth_client_installations')->where('user_id', $user->user_id)->where('id', $id)->first();
            if ($device === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy thiết bị.');
            }
            $current = Family::current($user, true);
            $rows = ClientInstallations::activeFamilies((string) $user->user_id)->where('auth_device_id', $id);
            foreach ($rows as $row) {
                if (hash_equals($current, Policy::family($row->auth_session_id, (string) $row->id))) {
                    ApiError::abort(409, 'CURRENT_DEVICE_PROTECTED', 'Không thể thu hồi thiết bị có phiên hiện tại.');
                }
            }
            foreach ($rows as $row) {
                Family::revoke((string) $user->user_id, Policy::family($row->auth_session_id, (string) $row->id));
            }
            if ($rows->isNotEmpty()) {
                AuditLogger::record(action: 'REVOKE_DEVICE', objectType: 'auth_client_installation', objectId: $id,
                    actorId: (string) $user->user_id, requestId: $request->attributes->get('request_id'), afterData: ['revoked_session_count' => $rows->count()]);
            }

            return $this->respondSuccess(['device_id' => $id, 'revoked' => true, 'revoked_session_count' => $rows->count(),
                'already_revoked' => $rows->isEmpty(), 'was_current' => false, 'request_receipt_supported' => false])->header('Cache-Control', 'private, no-store');
        }, 3);
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $this->body($request, []);

        return DB::transaction(function () use ($request): JsonResponse {
            $user = $this->actor($request, true);
            $current = Family::current($user, true);
            $count = 0;
            foreach (ClientInstallations::activeFamilies((string) $user->user_id) as $row) {
                $family = Policy::family($row->auth_session_id, (string) $row->id);
                if (! hash_equals($current, $family)) {
                    Family::revoke((string) $user->user_id, $family);
                    $count++;
                }
            }
            if ($count > 0) {
                AuditLogger::record(action: 'REVOKE_OTHER_SESSIONS', objectType: 'app_user', objectId: (string) $user->user_id,
                    actorId: (string) $user->user_id, requestId: $request->attributes->get('request_id'), afterData: ['revoked_session_count' => $count]);
            }

            return $this->respondSuccess(['revoked' => true, 'revoked_session_count' => $count,
                'preserved_session_id' => $current, 'request_receipt_supported' => false])->header('Cache-Control', 'private, no-store');
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
                ->select(['id', 'auth_session_id', 'auth_device_id', 'auth_session_started_at', 'user_agent', 'ip_address', 'created_at', 'expires_at'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY COALESCE(auth_session_id, id) ORDER BY created_at DESC, id DESC) AS p10_rank');
            $page = DB::query()->fromSub($ranked, 'p10_sessions')->where('p10_rank', 1)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate((int) ($input['per_page'] ?? 20), ['*'], 'page', (int) ($input['page'] ?? 1));
            $page->setCollection($page->getCollection()->map(function (object $row) use ($current, $user): array {
                $device = $row->auth_device_id === null ? null : DB::table('auth_client_installations')->where('user_id', $user->user_id)->where('id', $row->auth_device_id)->first();

                return ClientInstallations::session($row, $current, $device);
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
        $command = fn (): JsonResponse => DB::transaction(function () use ($request, $id): JsonResponse {
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

        return $this->securityCommand($request, 'REVOKE_SESSION', strtolower($id), $command, false);
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
        $command = function () use ($request, $raw): JsonResponse {
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

            return $this->authResult('Đăng xuất thành công', [
                'scope' => $scope, 'business_drafts_deleted' => false, 'work_shift_ended' => false,
            ]);
        };

        return $this->securityCommand($request, 'LOGOUT', null, $command, true);
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
        $command = function () use ($request, $data, $newHash, $driver): JsonResponse {
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

            return $this->authResult('Đổi mật khẩu thành công. Vui lòng đăng nhập lại.', [
                'session_policy' => 'RELOGIN_ALL_REFRESH_FAMILIES_REVOKED', 'relogin_required' => true,
                'password_changed_at' => $this->passwordTime($changedAt),
                'password_policy_version' => SecurityContract::passwordPolicy($driver)['version'],
            ]);
        };

        return $this->securityCommand($request, 'CHANGE_PASSWORD', null, $command, true);
    }

    private function securityCommand(Request $request, string $operation, ?string $target, Closure $command, bool $endSession): JsonResponse
    {
        $native = $request->hasHeader(P11SecurityCommands::HEADER);
        $receipts = app(P11SecurityCommands::class);
        $response = $native ? $receipts->execute($request, $operation, $target, $command) : $receipts->legacy($request, $command);
        if ($endSession && $response->isSuccessful() && $response->getStatusCode() !== 202) {
            try {
                auth('api')->logout();
            } catch (Throwable $error) {
                if (! $native) {
                    throw $error; // Preserve the legacy uncertainty contract.
                }
                // The native receipt and DB family revocation already committed.
                // Cache acknowledgement cannot turn that fact into a failed command.
                $body = $response->getData(true);
                $body['meta']['access_invalidation'] = 'FAMILY_REVOKED_CACHE_ACK_UNAVAILABLE';
                $response->setData($body);
            }
        }

        return $response;
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
        $payload['meta']['security_command_candidate'] = P11SecurityCommands::contract();
        $payload['data']['password_changed_at'] = $this->passwordTime($user->passwordChangedAtEpoch());

        return $response->setData($payload)->header('ETag', '"'.(int) $user->version.'"')
            ->header('Cache-Control', 'private, no-store, no-transform');
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
