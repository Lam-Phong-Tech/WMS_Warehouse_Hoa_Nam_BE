<?php

namespace Modules\Auth\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Phát hành/xoay vòng refresh token. Không snapshot active-context claims (khác ERP-Backend
 * gốc) vì WMS_HoaNam không có active-context/multi-role-scope — access token mới luôn tính
 * lại quyền hiện tại của user tại thời điểm refresh.
 */
class RefreshTokenService
{
    public function issue(User $user, Request $request): string
    {
        return $this->issueSession($user, $request)['raw'];
    }

    /**
     * Như issue() nhưng trả kèm id bản ghi phiên. Id này được gắn vào access token
     * dưới claim `sid` (xem accessTokenFor()), để API "thiết bị đang đăng nhập" nhận
     * ra phiên nào là của CHÍNH request đang gọi — cờ `is_current`. So user-agent thì
     * sai: một máy có thể giữ nhiều phiên với cùng user-agent.
     *
     * @return array{raw: string, id: string}
     */
    public function issueSession(User $user, Request $request, ?string $authSessionId = null): array
    {
        $raw = $this->generateRawToken();
        $authSessionId ??= (string) Str::uuid();

        $record = RefreshToken::create([
            'user_id' => $user->user_id,
            'auth_session_id' => $authSessionId,
            'token_hash' => $this->hash($raw),
            // refresh_tokens.user_agent là varchar(255): trình duyệt nào gửi UA
            // dài hơn 255 ký tự (Chrome kèm extension/enterprise agent) sẽ làm
            // Postgres ném 22001 "value too long" và /auth/login trả 500 dù mật
            // khẩu đúng. Cắt ngắn giống PublicQuoteRequestController thay vì để
            // chiều dài UA của client quyết định đăng nhập được hay không.
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'ip_address' => Str::limit((string) $request->ip(), 45, ''),
            'expires_at' => now()->addMinutes((int) config('refresh_token.ttl_minutes')),
        ]);

        // Request-scoped only: login/MFA/refresh can expose the newly issued
        // session without parsing an old or absent incoming Authorization header.
        $request->attributes->set('wms.issued_auth_session_id', $authSessionId);
        $request->attributes->set('wms.issued_auth_session_user_id', (string) $user->user_id);

        return ['raw' => $raw, 'id' => (string) $record->getKey()];
    }

    /**
     * Access token cho một user, gắn claim `sid` khi biết phiên refresh tương ứng.
     * Không có phiên (request không nhận refresh token, vd công cụ dòng lệnh) thì cấp
     * token trơn như trước.
     *
     * Luôn gọi claims(), kể cả với mảng rỗng: JWTAuth là singleton và fromUser()
     * không xoá custom claim của lần gọi trước, nên token trơn cấp sau một token có
     * sid trong cùng tiến trình sẽ mang nhầm sid cũ.
     */
    public function accessTokenFor(User $user, ?string $sessionId): string
    {
        return JWTAuth::claims($sessionId === null ? [] : ['sid' => $sessionId])->fromUser($user);
    }

    /**
     * Id phiên refresh mà access token của request hiện tại thuộc về (claim `sid`).
     * Null với token cấp trước khi có claim này, hoặc khi không đọc được payload.
     *
     * Parse lại token mà request đang trình ra thay vì auth('api')->payload(): guard
     * JWT giữ token nó thấy đầu tiên trong vòng đời tiến trình (attempt() lúc login,
     * hay request trước), nên trong tiến trình sống lâu nó trả sid của phiên khác.
     */
    public function currentSessionId(): ?string
    {
        try {
            $sid = JWTAuth::parseToken()->getPayload()->get('sid');
        } catch (\Throwable) {
            return null;
        }

        return is_string($sid) && $sid !== '' ? $sid : null;
    }

    /**
     * @return array{user: User, raw: string, id: string}|null
     */
    public function rotate(string $rawToken, Request $request): ?array
    {
        $hash = $this->hash($rawToken);

        return DB::transaction(function () use ($hash, $request) {
            $record = RefreshToken::where('token_hash', $hash)->lockForUpdate()->first();

            if (! $record || $record->expires_at->isPast()) {
                return null;
            }

            if ($record->revoked_at !== null) {
                // Token cũ bị dùng lại sau khi đã revoke — nghi ngờ bị đánh cắp, khóa toàn bộ phiên.
                $this->revokeAllForUser($record->user_id);

                return null;
            }

            $record->update(['revoked_at' => now()]);

            $user = $record->user;

            if (! $user || $user->status !== 'ACTIVE') {
                $this->revokeAllForUser($record->user_id);

                return null;
            }

            $session = $this->issueSession($user, $request, (string) ($record->auth_session_id ?? $record->id));

            return ['user' => $user, 'raw' => $session['raw'], 'id' => $session['id']];
        });
    }

    public function revoke(string $rawToken): void
    {
        RefreshToken::where('token_hash', $this->hash($rawToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeAllForUser(string $userId): void
    {
        RefreshToken::where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function generateRawToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}