<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Models\User;
use Modules\Auth\Support\MfaService;
use Symfony\Component\HttpFoundation\Response;

/**
 * WWHN-SEC-08 — buộc tài khoản đặc quyền đăng ký MFA.
 *
 * BÀI TOÁN: bật MFA cho SUPER_ADMIN mà không có lớp này thì nó chỉ là TUỲ CHỌN —
 * ai không bật thì vẫn đăng nhập bằng mật khẩu như cũ, và lớp bảo vệ tồn tại
 * đúng với những người vốn đã cẩn thận.
 *
 * BÀI TOÁN NGƯỢC LẠI, cũng thật: bật cưỡng chế mà chặn hết mọi thứ sẽ khoá luôn
 * chính các endpoint cần dùng ĐỂ đăng ký. Quản trị viên đăng nhập được nhưng
 * không gọi được `/auth/mfa/enroll` — hệ thống tự khoá mình, và cách thoát duy
 * nhất là sửa DB bằng tay.
 *
 * Vì vậy allowlist dưới đây là phần QUAN TRỌNG NHẤT của middleware này, không
 * phải phần phụ. Nó cho đúng những gì cần để hoàn tất việc đăng ký và không hơn:
 * nhóm MFA, đăng xuất, đổi mật khẩu (có thể đang bị bắt buộc), và đọc hồ sơ của
 * chính mình để giao diện hiển thị được.
 *
 * Middleware CỐ Ý đứng sau `EnforcePasswordChangeRequired`: đổi mật khẩu lần đầu
 * là điều kiện trước, đăng ký MFA là điều kiện sau.
 */
class EnforceMfaEnrollment
{
    /**
     * Đường dẫn (theo `Request::is()`) được đi qua khi tài khoản chưa đăng ký MFA.
     */
    private const ALLOWED = [
        'api/v1/auth/mfa/*',
        'api/v1/auth/logout',
        'api/v1/auth/change-password',
        'api/v1/auth/me',
        'api/v1/auth/refresh',
        'api/v1/health',
    ];

    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('mfa.enforce', false)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($request->is(...self::ALLOWED)) {
            return $next($request);
        }

        if (! $this->mfa->mustEnrollNow($user)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Vai trò của bạn bắt buộc dùng xác thực hai bước. Vui lòng đăng ký trước khi tiếp tục.',
            'error_code' => 'MFA_ENROLLMENT_REQUIRED',
            'errors' => [],
            'error' => [
                'code' => 'MFA_ENROLLMENT_REQUIRED',
                'details' => [
                    // FE dùng đúng hai trường này để điều hướng thẳng tới màn
                    // đăng ký thay vì hiện một lỗi 403 chung chung mà người dùng
                    // không biết phải làm gì.
                    'enroll_endpoint' => '/api/v1/auth/mfa/enroll',
                    'status_endpoint' => '/api/v1/auth/mfa/status',
                ],
            ],
        ], 403);
    }
}
