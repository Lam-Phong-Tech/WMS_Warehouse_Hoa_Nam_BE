<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Models\User;
use Modules\Auth\Support\AuthUserPayload;
use Modules\Auth\Support\InstallationIdentity;
use Modules\Auth\Support\MfaService;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Auth\Support\RefreshTokenTransport;
use Modules\Auth\Support\TrustedDeviceService;
use OpenApi\Attributes as OA;

/**
 * WWHN-SEC-08 — đăng ký / xác minh / gỡ bỏ MFA.
 *
 * Mọi endpoint ở đây đều ghi audit: bật, tắt, và đặc biệt là THẤT BẠI khi xác
 * minh. Một chuỗi `MFA_VERIFY_FAILED` liên tiếp trên cùng tài khoản chính là tín
 * hiệu sớm nhất của việc mật khẩu đã bị lộ — mất tín hiệu đó thì lớp MFA chỉ
 * chặn được kẻ tấn công chứ không cho ai biết là đã có kẻ tấn công.
 */
class MfaController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditLogger $auditLogger,
        private readonly RefreshTokenService $refreshTokens,
        private readonly RefreshTokenTransport $tokenTransport,
    ) {}

    #[OA\Get(
        path: '/api/v1/auth/mfa/status', operationId: 'auth_mfa_status',
        summary: 'Trạng thái MFA của tài khoản đang đăng nhập',
        tags: ['01. Authentication'], security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'enabled / pending / required_for_role / recovery_codes_remaining')]
    )]
    public function status(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $record = $this->mfa->statusFor($user);

        return $this->respondSuccess([
            'enabled' => $record?->isConfirmed() === true,
            'pending' => $record !== null && ! $record->isConfirmed(),
            'required_for_role' => $this->mfa->isPrivileged($user),
            'enforced' => (bool) config('mfa.enforce', false),
            'must_enroll_now' => $this->mfa->mustEnrollNow($user),
            'recovery_codes_remaining' => $this->mfa->remainingRecoveryCodes($user),
        ], 'Trạng thái MFA.');
    }

    #[OA\Post(
        path: '/api/v1/auth/mfa/enroll', operationId: 'auth_mfa_enroll',
        summary: 'Bắt đầu đăng ký MFA — trả về bí mật và URI otpauth để quét QR',
        description: 'Bí mật chỉ hiển thị ở response này. MFA CHƯA có hiệu lực cho tới khi gọi /confirm với một mã hợp lệ.',
        tags: ['01. Authentication'], security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'secret + otpauth_uri'),
            new OA\Response(response: 409, description: 'MFA_ALREADY_ENABLED — phải /disable trước khi đổi thiết bị'),
        ]
    )]
    public function enroll(Request $request)
    {
        /** @var User $user */
        $user = $request->user();
        $result = $this->mfa->beginEnrollment($user);

        if ($result['already_enabled']) {
            return $this->respondError(
                'Tài khoản đã bật MFA. Hãy tắt MFA hiện tại trước khi đăng ký thiết bị mới.',
                [], 409, 'MFA_ALREADY_ENABLED',
            );
        }

        $this->auditLogger->log(
            'MFA_ENROLL_STARTED', 'app_user', $user->user_id, $user->user_id,
            $this->currentRequestId(), [], [], null, result: 'SUCCESS',
        );

        return $this->respondSuccess([
            // Bí mật KHÔNG bao giờ được ghi vào audit/log — chỉ trả về đúng một
            // lần cho chính chủ tài khoản đang đăng nhập.
            'secret' => $result['secret'],
            'otpauth_uri' => $result['uri'],
            'digits' => (int) config('mfa.totp.digits', 6),
            'period' => (int) config('mfa.totp.period', 30),
        ], 'Quét mã QR bằng ứng dụng xác thực rồi gửi mã 6 chữ số để xác nhận.');
    }

    #[OA\Post(
        path: '/api/v1/auth/mfa/confirm', operationId: 'auth_mfa_confirm',
        summary: 'Xác nhận đăng ký MFA và nhận mã khôi phục (hiển thị một lần)',
        tags: ['01. Authentication'], security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code'], properties: [new OA\Property(property: 'code', type: 'string', example: '123456')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'recovery_codes — LƯU LẠI NGAY, không hiển thị lần thứ hai'),
            new OA\Response(response: 422, description: 'MFA_CODE_INVALID'),
        ]
    )]
    public function confirm(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:16']]);

        /** @var User $user */
        $user = $request->user();
        $codes = $this->mfa->confirmEnrollment($user, (string) $request->input('code'));

        if ($codes === null) {
            $this->auditLogger->log(
                'MFA_ENROLL_FAILED', 'app_user', $user->user_id, $user->user_id,
                $this->currentRequestId(), [], [], 'Invalid TOTP code during enrollment',
                result: 'FAILED', errorCode: 'MFA_CODE_INVALID',
            );

            return $this->respondError('Mã xác thực không đúng hoặc đã hết hạn.', [], 422, 'MFA_CODE_INVALID');
        }

        $this->auditLogger->log(
            'MFA_ENABLED', 'app_user', $user->user_id, $user->user_id,
            $this->currentRequestId(), [], ['recovery_codes_issued' => count($codes)],
            null, result: 'SUCCESS',
        );

        return $this->respondSuccess([
            'recovery_codes' => $codes,
        ], 'Đã bật xác thực hai bước. Hãy lưu mã khôi phục ở nơi an toàn — chúng không hiển thị lại.');
    }

    #[OA\Post(
        path: '/api/v1/auth/mfa/disable', operationId: 'auth_mfa_disable',
        summary: 'Tắt MFA (yêu cầu mật khẩu hiện tại và một mã hợp lệ)',
        tags: ['01. Authentication'], security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['password', 'code'], properties: [
                new OA\Property(property: 'password', type: 'string', format: 'password'),
                new OA\Property(property: 'code', type: 'string', description: 'Mã TOTP hoặc mã khôi phục'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Đã tắt MFA'),
            new OA\Response(response: 403, description: 'MFA_REQUIRED_FOR_ROLE — vai trò này bắt buộc MFA'),
            new OA\Response(response: 422, description: 'Sai mật khẩu hoặc sai mã'),
        ]
    )]
    public function disable(Request $request)
    {
        $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string', 'max:16'],
        ]);

        /** @var User $user */
        $user = $request->user();

        // Vai trò bắt buộc MFA thì KHÔNG được tự tắt. Cho phép tắt nghĩa là bất
        // kỳ ai chiếm được phiên đăng nhập cũng gỡ được lớp bảo vệ — MFA khi đó
        // chỉ là một tuỳ chọn giao diện.
        if ((bool) config('mfa.enforce', false) && $this->mfa->isPrivileged($user)) {
            return $this->respondError(
                'Vai trò của tài khoản này bắt buộc dùng xác thực hai bước. Liên hệ quản trị viên nếu cần đổi thiết bị.',
                [], 403, 'MFA_REQUIRED_FOR_ROLE',
            );
        }

        if (! Hash::check((string) $request->input('password'), (string) $user->password_hash)) {
            return $this->respondError('Mật khẩu hiện tại không đúng.', [], 422, 'INVALID_CREDENTIALS');
        }

        if ($this->mfa->verifySecondFactor($user, (string) $request->input('code')) === null) {
            return $this->respondError('Mã xác thực không đúng.', [], 422, 'MFA_CODE_INVALID');
        }

        $this->mfa->disable($user);

        $this->auditLogger->log(
            'MFA_DISABLED', 'app_user', $user->user_id, $user->user_id,
            $this->currentRequestId(), [], [], null, result: 'SUCCESS',
        );

        return $this->respondSuccess([], 'Đã tắt xác thực hai bước.');
    }

    #[OA\Post(
        path: '/api/v1/auth/mfa/verify', operationId: 'auth_mfa_verify',
        summary: 'Bước 2 của đăng nhập: đổi mfa_token + mã xác thực lấy access token',
        description: 'mfa_token do POST /auth/login trả về khi tài khoản đã bật MFA. Mã có thể là TOTP 6 chữ số hoặc một mã khôi phục.',
        tags: ['01. Authentication'], security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['mfa_token', 'code'], properties: [
                new OA\Property(property: 'mfa_token', type: 'string'),
                new OA\Property(property: 'code', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'access_token + user + replaced_existing_session. Refresh token KHÔNG nằm trong JSON: trình duyệt nhận qua cookie HttpOnly, client không phải trình duyệt chỉ nhận qua kênh `X-WMS-Token-Transport: body` khi máy chủ bật.'),
            new OA\Response(response: 403, description: 'ACCESS_DENIED (tài khoản bị khoá), ORIGIN_NOT_ALLOWED hoặc TOKEN_TRANSPORT_NOT_ALLOWED'),
            new OA\Response(response: 401, description: 'MFA_CHALLENGE_INVALID hoặc MFA_CODE_INVALID'),
            new OA\Response(response: 423, description: 'MFA_LOCKED — quá số lần thử'),
        ]
    )]
    public function verify(Request $request)
    {
        $request->validate([
            'mfa_token' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'max:16'],
        ]);

        $user = $this->mfa->consumeChallenge((string) $request->input('mfa_token'));

        if ($user === null) {
            // Không phân biệt "token sai" với "token hết hạn": phân biệt cho kẻ
            // tấn công biết token nào từng hợp lệ.
            return $this->respondError('Phiên xác thực hai bước không hợp lệ hoặc đã hết hạn. Hãy đăng nhập lại.', [], 401, 'MFA_CHALLENGE_INVALID');
        }

        // Phiên chờ sống 5 phút, và bước PHÁT TOKEN là bước này chứ không phải
        // bước mật khẩu. Trong cửa sổ đó tài khoản có thể vừa bị khoá: nếu không
        // kiểm lại, một lệnh khoá khẩn cấp vẫn bị qua mặt bởi một phiên chờ đã
        // phát trước đó vài giây. Cùng đúng một luật với AuthController::login và
        // EnforceActiveAccountStatus — chỉ ACTIVE mới đi tiếp.
        if ($user->status !== 'ACTIVE') {
            $this->auditLogger->log(
                'LOGIN_DENIED', 'app_user', $user->user_id, $user->user_id,
                $this->currentRequestId(), [], ['status' => $user->status],
                'Inactive or locked account at second factor',
                result: 'DENIED', errorCode: 'ACCESS_DENIED',
            );

            return $this->respondError('Tài khoản đang bị khoá hoặc ngừng hoạt động.', [], 403, 'ACCESS_DENIED');
        }

        $record = $this->mfa->statusFor($user);

        if ($record !== null && $record->isLocked()) {
            $this->auditLogger->log(
                'MFA_LOCKED', 'app_user', $user->user_id, $user->user_id,
                $this->currentRequestId(), [], [], 'Too many failed MFA attempts',
                result: 'DENIED', errorCode: 'MFA_LOCKED',
            );

            return $this->respondError('Đã nhập sai mã quá nhiều lần. Vui lòng thử lại sau.', [], 423, 'MFA_LOCKED');
        }

        $method = $this->mfa->verifySecondFactor($user, (string) $request->input('code'));

        if ($method === null) {
            $this->auditLogger->log(
                'MFA_VERIFY_FAILED', 'app_user', $user->user_id, $user->user_id,
                $this->currentRequestId(), [], [], 'Invalid second factor',
                result: 'FAILED', errorCode: 'MFA_CODE_INVALID',
            );

            return $this->respondError('Mã xác thực không đúng.', [], 401, 'MFA_CODE_INVALID');
        }

        $installation = app(InstallationIdentity::class)->resolve($request, $user);
        $trustCookie = null;
        if ($this->tokenTransport->mode($request) === RefreshTokenTransport::COOKIE) {
            $issuedTrust = app(TrustedDeviceService::class)->trust($user, $installation['installation_id'], $request);
            $trustCookie = $issuedTrust['cookie'];
            $this->auditLogger->log(
                'DEVICE_TRUSTED', 'trusted_device', $issuedTrust['model']->id, $user->user_id,
                $this->currentRequestId(), [], ['via' => 'mfa_'.$method, 'installation_id' => $installation['installation_id']],
                'Device trusted after MFA verification', result: 'SUCCESS',
            );
        }

        // Chỉ tới đây mới phát token thật. Phiên refresh (nếu có) tạo TRƯỚC để access
        // token mang claim `sid` trỏ đúng phiên đó — cùng lý do với AuthController::login().
        $completed = $this->refreshTokens->completeLogin($user, $request, $this->tokenTransport->shouldIssueOnLogin($request));
        $session = $completed['session'];
        $accessToken = $completed['access_token'];

        $this->auditLogger->log(
            'LOGIN', 'app_user', $user->user_id, $user->user_id,
            $this->currentRequestId(), [], ['second_factor' => $method], null, result: 'SUCCESS',
        );

        if ($method === 'recovery') {
            // Mã khôi phục là đường thoát hiểm, không phải cách đăng nhập hằng
            // ngày. Dùng tới nó là tín hiệu đáng ghi lại rõ ràng.
            $this->auditLogger->log(
                'MFA_RECOVERY_CODE_USED', 'app_user', $user->user_id, $user->user_id,
                $this->currentRequestId(), [], ['remaining' => $this->mfa->remainingRecoveryCodes($user)],
                null, result: 'SUCCESS',
            );
        }

        $response = $this->respondSuccess([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            // Cùng hồ sơ với POST /auth/login: FE dựng phiên từ `user`, thiếu nó thì bước 2
            // của đăng nhập ném AuthContractError trên web.
            'user' => AuthUserPayload::full($user),
            'replaced_existing_session' => $completed['replaced_existing_session'],
            'second_factor' => $method,
            'recovery_codes_remaining' => $this->mfa->remainingRecoveryCodes($user),
        ], 'Đăng nhập thành công');

        // P1 10/09/2026: refresh token đi theo kênh của request — cookie HttpOnly với trình
        // duyệt — và không bao giờ nằm trong JSON của trình duyệt. Phiên đã được tạo ở
        // trên (trước khi cấp access token); ở đây chỉ giao nó đi, KHÔNG issue lần hai.
        if ($session !== null) {
            $this->tokenTransport->deliver($request, $response, $session['raw']);
        }

        if ($trustCookie !== null) {
            $response->headers->setCookie($trustCookie);
        }

        return $response;
    }
}
