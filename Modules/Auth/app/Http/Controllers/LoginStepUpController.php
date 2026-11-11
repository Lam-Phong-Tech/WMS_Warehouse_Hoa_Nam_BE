<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\RateLimitResponse;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Support\LoginStepUp;
use Throwable;

class LoginStepUpController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly LoginStepUp $stepUp,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function verify(Request $request)
    {
        $data = $request->validate([
            'step_up_token' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:16'],
        ]);

        $result = $this->stepUp->verify((string) $data['step_up_token'], (string) $data['code']);

        if (! $result['ok']) {
            $errorCode = $result['locked']
                ? 'STEP_UP_LOCKED'
                : ($result['expired'] ? 'STEP_UP_EXPIRED' : 'STEP_UP_INVALID');

            $this->auditLogger->log(
                'LOGIN_STEP_UP_FAILED',
                'app_user',
                $result['user_id'],
                $result['user_id'],
                $this->currentRequestId(),
                [],
                [
                    'method' => $result['method'],
                    'locked' => $result['locked'],
                    'expired' => $result['expired'],
                ],
                'Step-up verification failed',
                result: 'FAILED',
                errorCode: $errorCode,
            );

            if ($result['locked']) {
                return $this->respondError(
                    'Đã nhập sai mã xác minh quá số lần cho phép. Vui lòng đăng nhập lại.',
                    [],
                    423,
                    'STEP_UP_LOCKED',
                );
            }

            if ($result['expired']) {
                return $this->respondError(
                    'Phiên xác minh bảo mật đã hết hạn hoặc không hợp lệ. Vui lòng đăng nhập lại.',
                    [],
                    422,
                    'STEP_UP_EXPIRED',
                );
            }

            return $this->respondError(
                'Mã xác minh không đúng hoặc đã hết hạn.',
                [],
                422,
                'STEP_UP_INVALID',
            );
        }

        $this->auditLogger->log(
            'LOGIN_STEP_UP_COMPLETED',
            'app_user',
            $result['user_id'],
            $result['user_id'],
            $this->currentRequestId(),
            [],
            ['method' => $result['method']],
            null,
            result: 'SUCCESS',
        );

        return $this->respondSuccess([
            'step_up_verified' => true,
            'password_attempts_left' => $result['password_attempts_left'],
            'expires_in' => $result['expires_in'],
        ], 'Xác minh bảo mật thành công. Vui lòng tiếp tục đăng nhập.');
    }

    public function resend(Request $request)
    {
        $data = $request->validate([
            'step_up_token' => ['required', 'string', 'max:128'],
        ]);

        try {
            $result = $this->stepUp->resend((string) $data['step_up_token'], (string) $request->ip());
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
                'Phiên xác minh bảo mật đã hết hạn hoặc không hợp lệ. Vui lòng đăng nhập lại.',
                [],
                422,
                'STEP_UP_EXPIRED',
            );
        }

        if (! $result['ok']) {
            $retryAfter = max(1, (int) $result['retry_after']);

            return RateLimitResponse::make(
                $request,
                ['Retry-After' => (string) $retryAfter],
                'Vui lòng chờ '.$retryAfter.' giây trước khi yêu cầu gửi lại mã xác minh.',
            );
        }

        $this->auditLogger->log(
            'LOGIN_STEP_UP_RESEND',
            'app_user',
            $result['user_id'],
            $result['user_id'],
            $this->currentRequestId(),
            [],
            [],
            null,
            result: 'SUCCESS',
        );

        return $this->respondSuccess([
            'expires_in' => $result['expires_in'],
        ], 'Nếu thông tin hợp lệ, mã xác minh mới đã được gửi.');
    }
}
