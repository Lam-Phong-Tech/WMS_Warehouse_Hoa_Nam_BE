<?php

namespace Modules\Auth\Support;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Mail\LoginOtpMail;
use Modules\Auth\Models\LoginDeviceOtp;
use Modules\Auth\Models\User;

class LoginOtpService
{
    /**
     * @return array{otp_id: string, code: string, expires_in: int}
     */
    public function issue(User $user, string $purpose, string $ip): array
    {
        $normalizedPurpose = strtoupper(trim($purpose));

        LoginDeviceOtp::query()
            ->where('user_id', $user->user_id)
            ->where('purpose', $normalizedPurpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(100000, 999999);
        $ttlMinutes = $normalizedPurpose === 'STEP_UP'
            ? max(1, (int) ceil(((int) config('login_security.step_up.token_ttl_seconds', 300)) / 60))
            : max(1, (int) config('login_security.device_otp.ttl_minutes', 5));

        $record = LoginDeviceOtp::query()->create([
            'user_id' => $user->user_id,
            'email' => (string) $user->email,
            'purpose' => $normalizedPurpose,
            'otp_hash' => Hash::make($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($ttlMinutes),
            'ip_address' => $ip,
        ]);

        Mail::to((string) $user->email)->send(
            (new LoginOtpMail($code, $normalizedPurpose, $ttlMinutes))->locale(AuthMailLocale::fromRequest(request()))
        );

        return [
            'otp_id' => (string) $record->id,
            'code' => $code,
            'expires_in' => $ttlMinutes * 60,
        ];
    }

    public function verify(string $otpId, string $code): bool
    {
        $record = LoginDeviceOtp::query()
            ->whereKey($otpId)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($record === null) {
            return false;
        }

        $maxAttempts = $record->purpose === 'STEP_UP'
            ? max(1, (int) config('login_security.step_up.max_code_attempts', 5))
            : max(1, (int) config('login_security.device_otp.max_attempts', 5));

        if ($record->attempts >= $maxAttempts) {
            return false;
        }

        if (! Hash::check(trim($code), $record->otp_hash)) {
            $attempts = $record->attempts + 1;
            $record->forceFill([
                'attempts' => $attempts,
                'consumed_at' => $attempts >= $maxAttempts ? now() : null,
            ])->save();

            return false;
        }

        return true;
    }

    public function consume(string $otpId): void
    {
        LoginDeviceOtp::query()
            ->whereKey($otpId)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);
    }

    public function invalidate(string $otpId): void
    {
        $this->consume($otpId);
    }

    public function purposeOf(string $otpId): ?string
    {
        $purpose = LoginDeviceOtp::query()->whereKey($otpId)->value('purpose');

        return is_string($purpose) ? $purpose : null;
    }
}
