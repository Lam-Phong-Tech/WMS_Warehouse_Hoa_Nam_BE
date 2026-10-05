<?php

namespace Modules\Auth\Support;

use App\Support\ApiError;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;

/** A logical login session survives refresh-token rotation, never a new login. */
final class StableAuthSession
{
    public function __construct(private readonly RefreshTokenService $tokens) {}

    public function id(User $user): ?string
    {
        $request = request();
        $issuedId = $request->attributes->get('wms.issued_auth_session_id');
        $issuedUser = $request->attributes->get('wms.issued_auth_session_user_id');

        if (is_string($issuedId) && $issuedUser === (string) $user->user_id) {
            $id = $issuedId;
        } else {
            $sid = $this->tokens->currentSessionId();
            if ($sid === null) {
                return null;
            }
            $presented = RefreshToken::query()->whereKey($sid)
                ->where('user_id', $user->user_id)->first();
            if ($presented === null) {
                return null;
            }
            $id = (string) ($presented->auth_session_id ?? $presented->id);
        }

        // Never trust a signed JWT alone after logout, device revocation, replay
        // detection or refresh-session expiry. Older access tokens from a rotated
        // row remain usable only while their SAME family still has a live token.
        $active = RefreshToken::query()->where('user_id', $user->user_id)->active()
            ->where(function ($query) use ($id): void {
                $query->where('auth_session_id', $id)
                    ->orWhere(function ($legacy) use ($id): void {
                        $legacy->whereNull('auth_session_id')->where('id', $id);
                    });
            })->exists();

        return $active ? $id : null;
    }

    public function requireId(User $user): string
    {
        $id = $this->id($user);
        if ($id !== null) {
            return $id;
        }
        if ($this->tokens->currentSessionId() === null) {
            ApiError::abort(409, 'AUTH_SESSION_REQUIRED', 'Cần đăng nhập bằng phiên có hỗ trợ gia hạn để bắt đầu ca.');
        }
        ApiError::abort(401, 'AUTH_SESSION_EXPIRED', 'Phiên làm việc đã hết hạn hoặc bị thu hồi. Vui lòng đăng nhập lại.');
    }

    /** @return array{auth_session_id: ?string, active_warehouse_id: ?string} */
    public function payload(User $user): array
    {
        $id = $this->id($user);
        $warehouseId = $id === null ? null : DB::table('mini_app_work_shifts')
            ->where('actor_id', $user->user_id)->where('auth_session_id', $id)->value('warehouse_id');

        // This is the confirmed warehouse, not the first allowed warehouse and
        // not an authorization shortcut. Missing selection must remain null.
        if ($warehouseId !== null && ! $user->canAccessWarehouse((string) $warehouseId)) {
            $warehouseId = null;
        }

        return [
            'auth_session_id' => $id,
            'active_warehouse_id' => $warehouseId === null ? null : (string) $warehouseId,
        ];
    }
}
