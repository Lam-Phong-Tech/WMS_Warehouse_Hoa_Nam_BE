<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\RateLimitResponse;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Support\AuthUserPayload;
use Modules\Auth\Support\DeviceVerification;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Auth\Support\RefreshTokenTransport;
use Modules\Auth\Support\TrustedDeviceService;
use Throwable;

class DeviceVerificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DeviceVerification $deviceVerification,
        private readonly TrustedDeviceService $trustedDevices,
        private readonly RefreshTokenService $refreshTokens,
        private readonly RefreshTokenTransport $tokenTransport,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_token' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:16'],
        ]);

        $result = $this->deviceVerification->verify(
            (string) $validated['device_token'],
            (string) $validated['code'],
        );

        if (! $result['ok'] || $result['user'] === null || $result['installation_id'] === null) {
            $this->auditLogger->log(
                'DEVICE_OTP_FAILED',
                'app_user',
                null,
                null,
                $this->currentRequestId(),
                [],
                [
                    'locked' => $result['locked'],
                    'expired' => $result['expired'],
                    'attempts_left' => $result['attempts_left'],
                ],
                'Device OTP verification failed',
                result: 'FAILED',
                errorCode: 'DEVICE_OTP_INVALID',
            );

            return $this->respondError(
                'Mã xác minh thiết bị không hợp lệ hoặc đã hết hạn.',
                [],
                422,
                'DEVICE_OTP_INVALID',
                ['attempts_left' => $result['attempts_left']],
            );
        }

        $user = $result['user'];
        $installationId = $result['installation_id'];

        $trust = $this->trustedDevices->trust($user, $installationId, $request);

        $completed = $this->refreshTokens->completeLogin(
            $user,
            $request,
            $this->tokenTransport->shouldIssueOnLogin($request),
        );
        $session = $completed['session'];
        $accessToken = $completed['access_token'];

        $response = $this->respondSuccess([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => AuthUserPayload::full($user),
            'replaced_existing_session' => $completed['replaced_existing_session'],
        ], 'Xác minh thiết bị và đăng nhập thành công.');

        if ($session !== null) {
            $this->tokenTransport->deliver($request, $response, $session['raw']);
        }

        $response->headers->setCookie($trust['cookie']);

        $this->auditLogger->log(
            'DEVICE_TRUSTED',
            'trusted_device',
            $trust['model']->id,
            $user->user_id,
            $this->currentRequestId(),
            [],
            ['installation_id' => $installationId, 'via' => 'email_otp'],
            'Device verified and trusted via email OTP',
            result: 'SUCCESS',
        );

        $this->auditLogger->log(
            'LOGIN',
            'app_user',
            $user->user_id,
            $user->user_id,
            $this->currentRequestId(),
            result: 'SUCCESS',
        );

        return $response;
    }

    public function resend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_token' => ['required', 'string', 'max:128'],
        ]);

        try {
            $result = $this->deviceVerification->resend((string) $validated['device_token']);
        } catch (Throwable) {
            return $this->respondError(
                'Không thể gửi mã xác minh qua email lúc này. Vui lòng thử lại sau hoặc liên hệ quản trị viên.',
                [],
                503,
                'EMAIL_DELIVERY_UNAVAILABLE',
            );
        }

        if ($result['expired']) {
            return $this->respondError(
                'Phiên xác minh thiết bị đã hết hạn. Vui lòng đăng nhập lại.',
                [],
                422,
                'DEVICE_TOKEN_EXPIRED',
            );
        }

        if ($result['rate_limited']) {
            return RateLimitResponse::make(
                $request,
                ['Retry-After' => (string) max(1, $result['retry_after'])],
                'Vui lòng đợi trước khi yêu cầu gửi lại mã xác minh thiết bị.',
            );
        }

        return $this->respondSuccess([
            'resent' => true,
            'expires_in' => $result['expires_in'],
        ], 'Đã gửi lại mã xác minh thiết bị.');
    }
}
