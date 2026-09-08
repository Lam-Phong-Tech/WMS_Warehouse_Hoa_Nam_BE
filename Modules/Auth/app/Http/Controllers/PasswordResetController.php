<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\RateLimitResponse;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Http\Requests\ForgotPasswordRequest;
use Modules\Auth\Http\Requests\ResetPasswordRequest;
use Modules\Auth\Http\Requests\VerifyOtpRequest;
use Modules\Auth\Mail\PasswordResetOtpMail;
use Modules\Auth\Models\PasswordResetOtp;
use Modules\Auth\Models\User;
use Modules\Auth\Support\RefreshTokenService;
use OpenApi\Attributes as OA;

/**
 * Quên mật khẩu bằng OTP gửi qua email (hạn 5 phút, xem config/password_reset.php).
 * Không dùng cơ chế `Password::sendResetLink()` mặc định của Laravel vì Model
 * User không dùng trait Notifiable/CanResetPassword (JWT tự viết, không có
 * bảng password_reset_tokens) — tự viết toàn bộ 3 bước dưới đây.
 */
class PasswordResetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly AuditLogger $auditLogger,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/forgot-password', operationId: 'auth_forgot_password',
        summary: 'Gửi mã OTP đặt lại mật khẩu về email. Gọi lại endpoint này để gửi lại mã.',
        tags: ['01. Authentication'], security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'nguyenvana@gmail.com'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Nếu email tồn tại, OTP đã được gửi (message chung, không tiết lộ email có tồn tại hay không)'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
            new OA\Response(response: 429, description: 'Vượt ngưỡng gửi yêu cầu'),
        ]
    )]
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $genericMessage = 'Nếu email tồn tại trong hệ thống, mã OTP đã được gửi. Vui lòng kiểm tra hộp thư.';

        $throttleKey = 'password-reset-request:'.sha1($request->ip().'|'.$email);
        $maxAttempts = (int) config('rate_limit.password_reset_request_per_minute', 3);
        if ($maxAttempts > 0 && RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return RateLimitResponse::make($request, ['Retry-After' => (string) RateLimiter::availableIn($throttleKey)]);
        }
        if ($maxAttempts > 0) {
            RateLimiter::hit($throttleKey, 60);
        }

        $user = User::query()->where('email', $email)->first();

        // Không tiết lộ tài khoản có tồn tại hay không: chỉ thực sự gửi mail khi
        // user tồn tại và đang ACTIVE, nhưng response luôn giống nhau.
        if ($user instanceof User && $user->status === 'ACTIVE') {
            $otp = (string) random_int(100000, 999999);
            $ttlMinutes = (int) config('password_reset.otp_ttl_minutes', 5);

            PasswordResetOtp::create([
                'user_id' => $user->user_id,
                'email' => $email,
                'otp_hash' => Hash::make($otp),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($ttlMinutes),
                'ip_address' => $request->ip(),
            ]);

            Mail::to($email)->send(new PasswordResetOtpMail($otp, $ttlMinutes));

            $this->auditLogger->log(
                'PASSWORD_RESET_REQUESTED', 'app_user', $user->user_id, $user->user_id,
                $this->currentRequestId(), result: 'SUCCESS',
            );
        }

        return $this->respondSuccess(null, $genericMessage);
    }

    #[OA\Post(
        path: '/api/v1/auth/verify-otp', operationId: 'auth_verify_otp',
        summary: 'Xác thực mã OTP quên mật khẩu, trả reset_token dùng cho bước đặt mật khẩu mới',
        tags: ['01. Authentication'], security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'otp'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'nguyenvana@gmail.com'),
                new OA\Property(property: 'otp', type: 'string', example: '123456'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'OTP hợp lệ, trả reset_token'),
            new OA\Response(response: 422, description: 'OTP không đúng hoặc đã hết hạn'),
            new OA\Response(response: 429, description: 'Vượt ngưỡng thử OTP'),
        ]
    )]
    public function verifyOtp(VerifyOtpRequest $request)
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $otp = (string) $request->input('otp');
        $genericError = 'Mã OTP không đúng hoặc đã hết hạn.';

        $throttleKey = 'otp-verify:'.sha1($request->ip().'|'.$email);
        $maxAttempts = (int) config('rate_limit.otp_verify_per_minute', 10);
        if ($maxAttempts > 0 && RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return RateLimitResponse::make($request, ['Retry-After' => (string) RateLimiter::availableIn($throttleKey)]);
        }
        if ($maxAttempts > 0) {
            RateLimiter::hit($throttleKey, 60);
        }

        $record = PasswordResetOtp::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->first();

        $maxOtpAttempts = (int) config('password_reset.max_attempts', 5);

        if (! $record || $record->attempts >= $maxOtpAttempts) {
            return $this->respondError($genericError, [], 422, 'BUSINESS_RULE_ERROR');
        }

        if (! Hash::check($otp, $record->otp_hash)) {
            $record->increment('attempts');

            return $this->respondError($genericError, [], 422, 'BUSINESS_RULE_ERROR');
        }

        $resetToken = Str::random(64);
        $record->forceFill([
            'verified_at' => now(),
            'reset_token_hash' => Hash::make($resetToken),
            'reset_token_expires_at' => now()->addMinutes((int) config('password_reset.reset_token_ttl_minutes', 10)),
        ])->save();

        return $this->respondSuccess([
            'reset_token' => $resetToken,
            'expires_in' => (int) config('password_reset.reset_token_ttl_minutes', 10) * 60,
        ], 'Xác thực OTP thành công');
    }

    #[OA\Post(
        path: '/api/v1/auth/reset-password', operationId: 'auth_reset_password',
        summary: 'Đặt mật khẩu mới bằng reset_token đã xác thực OTP (thu hồi toàn bộ refresh token)',
        tags: ['01. Authentication'], security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'reset_token', 'new_password'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'nguyenvana@gmail.com'),
                new OA\Property(property: 'reset_token', type: 'string'),
                new OA\Property(property: 'new_password', type: 'string', format: 'password', minLength: 8, example: 'NewCntt@2026'),
                new OA\Property(property: 'new_password_confirmation', type: 'string', format: 'password', example: 'NewCntt@2026'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Đặt lại mật khẩu thành công'),
            new OA\Response(response: 422, description: 'reset_token không hợp lệ/đã hết hạn hoặc dữ liệu không hợp lệ'),
            new OA\Response(response: 429, description: 'Vượt ngưỡng đặt lại mật khẩu (WWHN-SEC-04)'),
        ]
    )]
    public function resetPassword(ResetPasswordRequest $request)
    {
        $email = Str::lower(trim((string) $request->input('email')));
        $resetToken = (string) $request->input('reset_token');
        $genericError = 'Yêu cầu đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.';

        // WWHN-SEC-04 — trần gọi cho bước cuối của luồng quên mật khẩu.
        // Đếm MỌI lần gọi (không chỉ lần hỏng) vì thứ cần bảo vệ ở đây là CPU:
        // `Hash::check` bcrypt bên dưới chạy trước khi biết request đúng hay sai.
        // Khoá theo (IP + email) giống hai bước trước của cùng luồng, nên một
        // kho dùng chung NAT không tự khoá lẫn nhau.
        $throttleKey = 'password-reset-confirm:'.sha1($request->ip().'|'.$email);
        $maxAttempts = (int) config('rate_limit.password_reset_confirm_per_minute', 5);
        if ($maxAttempts > 0 && RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return RateLimitResponse::make($request, ['Retry-After' => (string) RateLimiter::availableIn($throttleKey)]);
        }
        if ($maxAttempts > 0) {
            RateLimiter::hit($throttleKey, 60);
        }

        $record = PasswordResetOtp::query()
            ->where('email', $email)
            ->whereNotNull('verified_at')
            ->whereNull('consumed_at')
            ->where('reset_token_expires_at', '>', now())
            ->latest('created_at')
            ->first();

        if (! $record || ! is_string($record->reset_token_hash) || ! Hash::check($resetToken, $record->reset_token_hash)) {
            return $this->respondError($genericError, [], 422, 'BUSINESS_RULE_ERROR');
        }

        /** @var User|null $user */
        $user = User::query()->find($record->user_id);
        if (! $user) {
            return $this->respondError($genericError, [], 422, 'BUSINESS_RULE_ERROR');
        }

        $user->forceFill([
            'password_hash' => Hash::make((string) $request->input('new_password')),
            'must_change_password' => false,
            'version' => ((int) $user->version) + 1,
        ])->save();

        // WWHN-SEC-02: đây là luồng "quên mật khẩu" — thường được dùng CHÍNH VÌ
        // nghi tài khoản đã bị chiếm. Nếu chỉ thu hồi refresh token thì access
        // token kẻ tấn công đang giữ vẫn sống tới khi hết hạn, đúng lúc người
        // dùng tin rằng mình vừa lấy lại được tài khoản.
        $user->markPasswordChangedNow();
        $this->refreshTokens->revokeAllForUser($user->user_id);
        $record->forceFill(['consumed_at' => now()])->save();

        // Đặt lại thành công thì bộ đếm không còn ý nghĩa: token đã bị tiêu, và
        // giữ bộ đếm chỉ phạt oan người vừa lấy lại được tài khoản.
        RateLimiter::clear($throttleKey);

        $this->auditLogger->log(
            'PASSWORD_RESET', 'app_user', $user->user_id, $user->user_id,
            $this->currentRequestId(), result: 'SUCCESS',
        );

        return $this->respondSuccess(null, 'Đặt lại mật khẩu thành công. Vui lòng đăng nhập bằng mật khẩu mới.');
    }
}
