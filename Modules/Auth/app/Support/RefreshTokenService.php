<?php

namespace Modules\Auth\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;

/**
 * Phát hành/xoay vòng refresh token. Không snapshot active-context claims (khác ERP-Backend
 * gốc) vì WMS_HoaNam không có active-context/multi-role-scope — access token mới luôn tính
 * lại quyền hiện tại của user tại thời điểm refresh.
 */
class RefreshTokenService
{
    public function issue(User $user, Request $request): string
    {
        $raw = $this->generateRawToken();

        RefreshToken::create([
            'user_id' => $user->user_id,
            'token_hash' => $this->hash($raw),
            'user_agent' => (string) $request->userAgent(),
            'ip_address' => (string) $request->ip(),
            'expires_at' => now()->addMinutes((int) config('refresh_token.ttl_minutes')),
        ]);

        return $raw;
    }

    /**
     * @return array{user: User, raw: string}|null
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

            $newRaw = $this->issue($user, $request);

            return ['user' => $user, 'raw' => $newRaw];
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
