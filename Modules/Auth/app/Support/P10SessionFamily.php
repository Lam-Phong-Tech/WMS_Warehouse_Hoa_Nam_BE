<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;

/** All command callers hold the same parent-user lock used by token rotation. */
final class P10SessionFamily
{
    public static function query(string $userId, string $family): Builder
    {
        return RefreshToken::query()->where('user_id', $userId)
            ->where(function (Builder $query) use ($family): void {
                $query->where('auth_session_id', $family)
                    ->orWhere(fn (Builder $legacy): Builder => $legacy->whereNull('auth_session_id')->where('id', $family));
            });
    }

    public static function current(User $user, bool $required = false): ?string
    {
        AccountSessionPolicy::assertReady();
        $sid = app(RefreshTokenService::class)->currentSessionId();
        if ($sid === null) {
            if (AccountSessionPolicy::enabled()) {
                ApiError::abort(401, 'AUTH_SESSION_REQUIRED', 'Phiên cũ không có định danh ổn định. Vui lòng đăng nhập lại.');
            }
            if ($required) {
                ApiError::abort(409, 'AUTH_SESSION_REQUIRED', 'Đăng nhập lại bằng phiên hỗ trợ quản lý thiết bị.');
            }

            return null;
        }
        if (! Str::isUuid($sid)) {
            ApiError::abort(401, 'AUTH_SESSION_EXPIRED', 'Phiên đăng nhập không còn hợp lệ.');
        }
        $row = RefreshToken::query()->where('user_id', $user->user_id)->whereKey($sid)->first();
        if ($row === null) {
            ApiError::abort(401, 'AUTH_SESSION_EXPIRED', 'Phiên đăng nhập không còn hợp lệ.');
        }
        if ($row->replaced_by_auth_session_id !== null) {
            AccountSessionPolicy::replaced((string) $row->replaced_by_auth_session_id);
        }
        $family = P10ProfilePolicy::family($row->auth_session_id, (string) $row->id);
        if (! self::query((string) $user->user_id, $family)->active()->exists()) {
            ApiError::abort(401, 'AUTH_SESSION_EXPIRED', 'Phiên đăng nhập đã hết hạn hoặc bị thu hồi.');
        }

        return $family;
    }

    public static function assertPresentedToken(User $user, ?string $family, ?string $raw): void
    {
        if ($raw === null) {
            return; // A signed sid is sufficient; never revoke other families as fallback.
        }
        $token = RefreshToken::query()->where('user_id', $user->user_id)
            ->where('token_hash', hash('sha256', $raw))->first();
        if ($family === null || $token === null
            || ! hash_equals($family, P10ProfilePolicy::family($token->auth_session_id, (string) $token->id))) {
            ApiError::abort(409, 'AUTH_SESSION_TOKEN_MISMATCH', 'Refresh token không thuộc phiên của access token đang dùng.');
        }
    }

    public static function revoke(string $userId, string $family): int
    {
        return self::query($userId, $family)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
