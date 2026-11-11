<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\RateLimitResponse;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\AuditLog\Services\AuditLogger;
use Modules\Auth\Events\SecurityAlertRaised;
use Modules\Auth\Http\Requests\LoginRequest;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Modules\Auth\Support\AccountSessionPolicy;
use Modules\Auth\Support\AuthUserPayload;
use Modules\Auth\Support\DeviceVerification;
use Modules\Auth\Support\InstallationIdentity;
use Modules\Auth\Support\LoginPow;
use Modules\Auth\Support\LoginStepUp;
use Modules\Auth\Support\LoginThrottle;
use Modules\Auth\Support\MfaService;
use Modules\Auth\Support\RefreshTokenService;
use Modules\Auth\Support\RefreshTokenTransport;
use Modules\Auth\Support\TrustedDeviceService;
use OpenApi\Attributes as OA;
use Throwable;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly AuditLogger $auditLogger,
        private readonly MfaService $mfa,
        private readonly RefreshTokenTransport $tokenTransport,
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/login', operationId: 'auth_login',
        summary: 'Đăng nhập bằng email và password',
        description: 'Không có tài khoản hay mật khẩu mặc định trên môi trường triển khai: quản trị viên đầu tiên được cấp bằng `php artisan wms:bootstrap-super-admin`, người dùng khác tạo qua IAM. Khi data.user.account_session_policy.enabled=true, login hoàn tất thay thế các phiên cũ trên toàn tài khoản; MFA challenge hoặc sai mật khẩu không thay phiên. Contract auth-session-replacement-v1; không tự END ca hoặc xóa nháp.',
        tags: ['01. Authentication'], security: [],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'], properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@example.com'),
                new OA\Property(
                    property: 'password',
                    type: 'string',
                    format: 'password',
                    description: 'Mật khẩu của tài khoản. Tài liệu API cố ý không công bố giá trị mẫu.'
                ),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Đăng nhập thành công; data.replaced_existing_session cho biết lần này đã đăng xuất phiên trước còn hoạt động hay chưa.'),
            new OA\Response(response: 401, description: 'Sai định danh hoặc mật khẩu'),
            new OA\Response(response: 403, description: 'Tài khoản bị khoá/ngừng hoạt động'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
            new OA\Response(response: 429, description: 'Vượt ngưỡng đăng nhập sai liên tiếp'),
        ]
    )]
    public function login(LoginRequest $request)
    {
        // Định danh đăng nhập DUY NHẤT là email (xem LoginRequest): trước đây
        // endpoint nhận thêm `username` nên `admin` và `admin@gmail.com` cùng vào
        // được một tài khoản.
        $credentials = ['email' => (string) $request->input('email'), 'password' => (string) $request->input('password')];
        $email = $credentials['email'];
        $ip = (string) $request->ip();
        $isCookieTransport = $this->tokenTransport->mode($request) === RefreshTokenTransport::COOKIE;
        $backoffEnabled = (bool) config('login_security.backoff.enabled', true);
        $stepUpEnabled = (bool) config('login_security.step_up.enabled', true) && $isCookieTransport;
        $trustEnabled = (bool) config('login_security.trusted_device.enabled', false) && $isCookieTransport;
        $throttle = app(LoginThrottle::class);
        $stepUpService = app(LoginStepUp::class);
        $trustedDeviceService = app(TrustedDeviceService::class);

        $presentedTrustCookie = $isCookieTransport
            ? $request->cookie($trustedDeviceService->cookieName($email))
            : null;
        $validTrust = $isCookieTransport
            ? $trustedDeviceService->findValid($email, is_string($presentedTrustCookie) ? $presentedTrustCookie : null)
            : null;
        $hasValidTrust = $validTrust !== null;

        $stepUpToken = is_string($request->input('step_up_token')) && trim((string) $request->input('step_up_token')) !== ''
            ? trim((string) $request->input('step_up_token'))
            : null;
        $stepUpMethod = null;

        // WWHN-289 (DEV289-P1-04): trước đây /auth/login không có bất kỳ giới hạn
        // nào — dò mật khẩu không giới hạn tốc độ. Chỉ đếm lần THẤT BẠI và khoá
        // theo (IP + định danh đăng nhập) nên đăng nhập đúng không bao giờ bị
        // chặn và một IP dùng chung (NAT trong kho) không tự khoá lẫn nhau.
        $throttleKey = $this->loginThrottleKey($request, $credentials);
        $maxAttempts = (int) config('rate_limit.auth_login_per_minute', 10);

        if ($stepUpToken !== null) {
            if (! $stepUpService->isVerified($stepUpToken, $email)) {
                $this->auditLogger->log(
                    'LOGIN_STEP_UP_FAILED', 'app_user', null, null,
                    $this->currentRequestId(), [], ['reason' => 'unverified_or_expired'],
                    'Step-up token not verified or expired',
                    result: 'FAILED', errorCode: 'STEP_UP_EXPIRED',
                );

                return $this->respondError(
                    'Phiên xác minh bảo mật đã hết hạn hoặc không hợp lệ. Vui lòng đăng nhập lại.',
                    [], 422, 'STEP_UP_EXPIRED',
                );
            }

            $consumedAttempt = $stepUpService->consumePasswordAttempt($stepUpToken, $email);
            if (! $consumedAttempt['ok']) {
                $this->auditLogger->log(
                    'LOGIN_STEP_UP_FAILED', 'app_user', null, null,
                    $this->currentRequestId(), [], ['reason' => 'password_attempts_exhausted'],
                    'Step-up password attempts exhausted',
                    result: 'FAILED', errorCode: 'STEP_UP_EXPIRED',
                );

                return $this->respondError(
                    'Phiên xác minh bảo mật đã hết hạn hoặc không hợp lệ. Vui lòng đăng nhập lại.',
                    [], 422, 'STEP_UP_EXPIRED',
                );
            }

            $stepUpMethod = $consumedAttempt['method'];
        } elseif ($backoffEnabled && ! $hasValidTrust) {
            $state = $throttle->state($email, $ip);
            $retryAfter = $throttle->retryAfter($email, $ip);

            if ($retryAfter > 0) {
                $this->auditLogger->log(
                    'LOGIN_THROTTLED', 'app_user', null, null,
                    $this->currentRequestId(), [], [
                        'scope' => $state['account_retry_after'] > $state['pair_retry_after'] ? 'account' : 'pair',
                        'failure_count' => $state['failures'],
                        'cap_hits' => $state['cap_hits'],
                        'retry_after_seconds' => $retryAfter,
                        'trust_cookie_valid' => false,
                    ],
                    'Too many failed login attempts',
                    result: 'FAILED', errorCode: 'RATE_LIMIT_EXCEEDED',
                );

                event(new SecurityAlertRaised(
                    'LOGIN_THROTTLED',
                    null,
                    'Phát hiện nhiều lần đăng nhập sai liên tiếp; một định danh đã bị tạm khoá thử đăng nhập.',
                ));

                return RateLimitResponse::make(
                    $request,
                    ['Retry-After' => (string) $retryAfter],
                    'Bạn đã đăng nhập sai quá nhiều lần. Vui lòng thử lại sau '.$retryAfter.' giây.',
                );
            }

            if ($stepUpEnabled && $state['step_up_required']) {
                $existingUser = User::query()->where('email', $email)->first();
                if ($existingUser !== null && $existingUser->status !== 'ACTIVE') {
                    return $this->denyInactiveAccount($existingUser, $throttleKey, 0);
                }

                try {
                    $stepUpChallenge = $stepUpService->issue($existingUser, $ip, $email);
                } catch (Throwable) {
                    return $this->respondError(
                        'Không thể gửi mã xác minh qua email lúc này. Vui lòng thử lại sau hoặc liên hệ quản trị viên.',
                        [], 503, 'EMAIL_DELIVERY_UNAVAILABLE',
                    );
                }

                $this->auditLogger->log(
                    'LOGIN_STEP_UP_REQUIRED', 'app_user', $existingUser?->user_id, $existingUser?->user_id,
                    $this->currentRequestId(), [], [
                        'method' => $stepUpChallenge['method'],
                        'cap_hits' => $state['cap_hits'],
                    ],
                    'Five cap cycles reached; step-up verification required',
                    result: 'SUCCESS',
                );

                return $this->respondSuccess([
                    'step_up_required' => true,
                    'step_up_token' => $stepUpChallenge['token'],
                    'method' => $stepUpChallenge['method'],
                    'masked_email' => $stepUpChallenge['masked_email'],
                    'expires_in' => $stepUpChallenge['expires_in'],
                ], 'Vì lý do bảo mật, vui lòng xác minh thiết bị để tiếp tục.');
            }
        } elseif (! $backoffEnabled && $maxAttempts > 0 && RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($throttleKey);
            $this->auditLogger->log(
                'LOGIN_THROTTLED', 'app_user', null, null,
                $this->currentRequestId(), [], ['retry_after_seconds' => $retryAfter],
                'Too many failed login attempts',
                result: 'FAILED', errorCode: 'RATE_LIMIT_EXCEEDED',
            );

            event(new SecurityAlertRaised(
                'LOGIN_THROTTLED',
                null,
                'Phát hiện nhiều lần đăng nhập sai liên tiếp; một định danh đã bị tạm khoá thử đăng nhập.',
            ));

            return RateLimitResponse::make(
                $request,
                ['Retry-After' => (string) $retryAfter],
                'Bạn đã đăng nhập sai quá nhiều lần. Vui lòng thử lại sau '.$retryAfter.' giây.',
            );
        }

        $powService = app(LoginPow::class);
        if ($isCookieTransport && ! $hasValidTrust && $powService->required($email, $ip)) {
            $powSupported = $request->input('pow_supported');
            if ($powSupported === false || $powSupported === 'false' || $powSupported === 0 || $powSupported === '0') {
                $this->auditLogger->log(
                    'LOGIN_POW_UNAVAILABLE',
                    'app_user',
                    null,
                    null,
                    $this->currentRequestId(),
                    [],
                    ['user_agent' => substr((string) $request->userAgent(), 0, 255)],
                    'Client reported WebWorker/WebCrypto unavailable for PoW',
                    result: 'SUCCESS',
                );
            } else {
                $powInput = $request->input('pow');
                if (! is_array($powInput) || ! $powService->verify($powInput, $email, $ip)) {
                    $freshChallenge = $powService->challenge($email, $ip);
                    $this->auditLogger->log(
                        is_array($powInput) ? 'LOGIN_POW_FAILED' : 'LOGIN_POW_REQUIRED',
                        'app_user',
                        null,
                        null,
                        $this->currentRequestId(),
                        [],
                        ['difficulty' => $freshChallenge['difficulty']],
                        'Proof of work required or invalid for elevated-risk login',
                        result: 'FAILED',
                        errorCode: 'POW_REQUIRED',
                    );

                    return $this->respondError(
                        'Vui lòng xác minh thiết bị trước khi tiếp tục đăng nhập.',
                        ['challenge' => $freshChallenge],
                        428,
                        'POW_REQUIRED',
                    );
                }
            }
        }

        if (! $accessToken = auth('api')->attempt($credentials)) {
            $user = User::query()->where('email', $credentials['email'] ?? null)->first();

            // Chỉ ghi diagnostic không nhạy cảm. Tuyệt đối không log password,
            // password hash/fingerprint hoặc token. `account_found=true` + ACTIVE
            // giúp phân biệt ngay sai credential/request với lỗi account lifecycle
            // khi đối chiếu HTTP UAT và Laravel CLI mà không mở đường bypass auth.
            $logData = $credentials;
            unset($logData['password']);
            $logData['identity_field'] = 'email';
            $logData['account_found'] = $user !== null;
            $logData['account_status'] = $user?->status;
            $logData['content_type'] = $request->header('Content-Type');

            $this->auditLogger->log(
                'LOGIN_FAILED',
                'app_user',
                $user?->user_id,
                $user?->user_id,
                $this->currentRequestId(),
                [],
                $logData,
                'Invalid credentials',
                result: 'FAILED',
                errorCode: 'UNAUTHENTICATED',
            );

            if ($stepUpToken === null) {
                $throttledResponse = $this->handleFailedPasswordThrottle($request, $throttle, $email, $ip, $user, $throttleKey, $maxAttempts, $backoffEnabled);
                if ($throttledResponse !== null) {
                    return $throttledResponse;
                }
            }

            return $this->respondError('Email, tài khoản hoặc mật khẩu không đúng.', [], 401, 'UNAUTHENTICATED');
        }

        /** @var User $user */
        $user = auth('api')->user();

        if (! $this->credentialsMatchExactly($user, $credentials)) {
            auth('api')->logout();

            $logData = $credentials;
            unset($logData['password']);

            $this->auditLogger->log(
                'LOGIN_FAILED',
                'app_user',
                $user->user_id,
                $user->user_id,
                $this->currentRequestId(),
                [],
                $logData,
                'Credential identifier case or value mismatch',
                result: 'FAILED',
                errorCode: 'UNAUTHENTICATED',
            );

            if ($stepUpToken === null) {
                $throttledResponse = $this->handleFailedPasswordThrottle($request, $throttle, $email, $ip, $user, $throttleKey, $maxAttempts, $backoffEnabled);
                if ($throttledResponse !== null) {
                    return $throttledResponse;
                }
            }

            return $this->respondError('Email, tài khoản hoặc mật khẩu không đúng.', [], 401, 'UNAUTHENTICATED');
        }

        if ($user->status !== 'ACTIVE') {
            auth('api')->logout();
            if ($stepUpToken === null && $backoffEnabled) {
                $throttle->registerFailure($email, $ip);
            }

            return $this->denyInactiveAccount($user, $throttleKey, $backoffEnabled ? 0 : $maxAttempts);
        }

        // Đăng nhập thành công xoá bộ đếm để người dùng hợp lệ không bị phạt oan.
        RateLimiter::clear($throttleKey);
        if ($stepUpToken !== null) {
            $stepUpService->clear($stepUpToken);
        }
        if ($backoffEnabled) {
            $throttle->registerSuccess($email, $ip);
        }

        $installation = app(InstallationIdentity::class)->resolve($request, $user);
        $installationId = $validTrust !== null
            ? (string) $validTrust->installation_id
            : $installation['installation_id'];

        // WWHN-SEC-08 — mật khẩu mới chỉ là YẾU TỐ THỨ NHẤT.
        //
        // Tài khoản đã bật MFA thì dừng ở đây: KHÔNG phát access token, KHÔNG
        // phát refresh token, KHÔNG cập nhật last_login_at. Chỉ trả một mã phiên
        // chờ ngắn hạn dùng một lần. Bất kỳ biến thể nào phát ra một token "hạn
        // chế" ở bước này đều làm hỏng mục đích của MFA — kẻ có mật khẩu đã ở
        // bên trong rồi.
        //
        // JWT vừa tạo ở bước xác thực mật khẩu phải được huỷ ngay: nó là một
        // access token hợp lệ đầy đủ và không bao giờ được rời khỏi tiến trình.
        if ($this->mfa->isEnabled($user) && $stepUpMethod !== 'mfa') {
            auth('api')->logout();

            $challenge = $this->mfa->issueChallenge($user);

            $this->auditLogger->log(
                'MFA_CHALLENGE_ISSUED', 'app_user', $user->user_id, $user->user_id,
                $this->currentRequestId(), [], [], null, result: 'SUCCESS',
            );

            return $this->respondSuccess([
                'mfa_required' => true,
                'mfa_token' => $challenge['mfa_token'],
                'expires_in' => $challenge['expires_in'],
            ], 'Nhập mã xác thực hai bước để hoàn tất đăng nhập.');
        }

        $trustCookieToAttach = null;
        if ($isCookieTransport && $stepUpToken !== null && in_array($stepUpMethod, ['email', 'mfa'], true)) {
            $issuedTrust = $trustedDeviceService->trust($user, $installationId, $request);
            $trustCookieToAttach = $issuedTrust['cookie'];
            $this->auditLogger->log(
                'DEVICE_TRUSTED',
                'trusted_device',
                $issuedTrust['model']->id,
                $user->user_id,
                $this->currentRequestId(),
                [],
                ['via' => 'step_up_'.$stepUpMethod, 'installation_id' => $installationId],
                'Device trusted via step-up verification',
                result: 'SUCCESS',
            );
        } elseif ($trustEnabled) {
            if ($validTrust === null) {
                auth('api')->logout();
                try {
                    $deviceChallenge = app(DeviceVerification::class)->issue($user, $installationId, $ip);
                } catch (Throwable) {
                    return $this->respondError(
                        'Không thể gửi mã xác minh qua email lúc này. Vui lòng thử lại sau hoặc liên hệ quản trị viên.',
                        [],
                        503,
                        'EMAIL_DELIVERY_UNAVAILABLE',
                    );
                }

                $this->auditLogger->log(
                    'DEVICE_OTP_SENT',
                    'app_user',
                    $user->user_id,
                    $user->user_id,
                    $this->currentRequestId(),
                    [],
                    ['installation_id' => $installationId],
                    'Unknown device verification OTP sent',
                    result: 'SUCCESS',
                );

                return $this->respondSuccess([
                    'device_verification_required' => true,
                    'device_token' => $deviceChallenge['device_token'],
                    'expires_in' => $deviceChallenge['expires_in'],
                    'masked_email' => $deviceChallenge['masked_email'],
                ], 'Thiết bị này chưa được tin cậy. Nhập mã xác minh đã gửi tới email đăng ký.');
            }

            $rotatedRaw = $trustedDeviceService->rotateIfNeeded($validTrust);
            if ($rotatedRaw !== null) {
                $trustCookieToAttach = $trustedDeviceService->makeCookieForModel($validTrust, $user->email, $rotatedRaw);
            }
        } elseif ($validTrust !== null) {
            $rotatedRaw = $trustedDeviceService->rotateIfNeeded($validTrust);
            if ($rotatedRaw !== null) {
                $trustCookieToAttach = $trustedDeviceService->makeCookieForModel($validTrust, $user->email, $rotatedRaw);
            }
        }

        // P1 10/09/2026: refresh token KHÔNG còn nằm trong JSON. Trình duyệt (Origin tin
        // cậy) nhận nó trong cookie HttpOnly; client không phải trình duyệt chỉ nhận qua kênh
        // body được bật tường minh; request không mang Origin nào (công cụ dòng lệnh) chỉ có
        // access token ngắn hạn. Xem RefreshTokenTransport.
        //
        // Phiên refresh được tạo TRƯỚC khi dựng response, để access token trả về mang
        // claim `sid` trỏ đúng phiên đó (API /auth/sessions dựa vào nó cho cờ is_current).
        // Token mà attempt() vừa cấp ở trên chưa từng rời máy chủ, thay thế là an toàn.
        $completed = $this->refreshTokens->completeLogin($user, $request, $this->tokenTransport->shouldIssueOnLogin($request));
        $session = $completed['session'];
        $accessToken = $completed['access_token'];

        $response = $this->respondSuccess([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => AuthUserPayload::full($user),
            'replaced_existing_session' => $completed['replaced_existing_session'],
        ], 'Đăng nhập thành công');

        if ($session !== null) {
            $this->tokenTransport->deliver($request, $response, $session['raw']);
        }

        if ($trustCookieToAttach !== null) {
            $response->headers->setCookie($trustCookieToAttach);
        }

        $this->auditLogger->log('LOGIN', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId(), result: 'SUCCESS');

        return $response;
    }

    #[OA\Post(
        path: '/api/v1/auth/refresh', operationId: 'auth_refresh',
        summary: 'Xoay vòng refresh token và cấp access token mới',
        description: 'Trình duyệt: body RỖNG; refresh token đi trong cookie HttpOnly `wms_refresh_token` (Path=/api/v1/auth) và request phải mang Origin thuộc allowlist CORS. Response KHÔNG chứa refresh_token — token mới chỉ nằm trong Set-Cookie. Client không phải trình duyệt: gửi header `X-WMS-Token-Transport: body` kèm `refresh_token` trong body (chỉ khi máy chủ bật REFRESH_TOKEN_BODY_TRANSPORT_ENABLED); token mới trả trong JSON, không có cookie. Token cũ bị thu hồi; replay giữ chính sách bảo mật hiện có. Credential đã được login mới thay thế trả 401 AUTH_SESSION_REPLACED kèm replacement.device_label, replacement.signed_in_at và replacement.ip_hint (IPv4 đã che), không thu hồi phiên kế nhiệm và không cho refresh/retry tiếp.',
        tags: ['01. Authentication'], security: [],
        parameters: [
            new OA\Parameter(
                name: 'X-WMS-Token-Transport',
                in: 'header',
                required: false,
                description: 'Kênh refresh token: `cookie` (mặc định, trình duyệt) hoặc `body` (client không phải trình duyệt, máy chủ phải bật).',
                schema: new OA\Schema(type: 'string', enum: ['cookie', 'body'])
            ),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [new OA\Property(
                property: 'refresh_token',
                type: 'string',
                nullable: true,
                description: 'CHỈ dùng với `X-WMS-Token-Transport: body`. Kênh cookie gửi kèm trường này nhận 422 REFRESH_TOKEN_TRANSPORT_MISMATCH.'
            )]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Làm mới token thành công'),
            new OA\Response(response: 401, description: 'Refresh token không hợp lệ, hết hạn hoặc bị replay'),
            new OA\Response(response: 403, description: 'ORIGIN_NOT_ALLOWED (refresh bằng cookie từ nguồn không tin cậy) hoặc TOKEN_TRANSPORT_NOT_ALLOWED'),
            new OA\Response(response: 422, description: 'Không có refresh token ở đúng kênh, hoặc gửi refresh token trong body khi dùng kênh cookie'),
        ]
    )]
    public function refresh(Request $request)
    {
        if ($this->tokenTransport->mode($request) === RefreshTokenTransport::COOKIE && $request->exists('refresh_token')) {
            // FE cũ giữ refresh token trong bộ nhớ JS và gửi lại qua body — đúng con đường
            // cho XSS lấy credential dài hạn. Kênh cookie không nhận token đến từ JavaScript.
            return $this->respondError(
                'Phiên trình duyệt được làm mới bằng cookie HttpOnly; không gửi refresh token trong body.',
                [], 422, 'REFRESH_TOKEN_TRANSPORT_MISMATCH',
            );
        }

        $rawToken = $this->tokenTransport->presentedToken($request);

        if ($rawToken === null || strlen($rawToken) > 255) {
            throw ValidationException::withMessages([
                'refresh_token' => ['Không tìm thấy refresh token hợp lệ cho phiên này.'],
            ]);
        }

        $rotated = $this->refreshTokens->rotate($rawToken, $request);

        if ($rotated === null) {
            $replaced = $request->attributes->get('wms.auth_refresh_failure') === 'AUTH_SESSION_REPLACED';
            $this->auditLogger->log(
                'TOKEN_REFRESH_FAILED', 'refresh_token', null, null,
                $this->currentRequestId(), [], ['token_fingerprint' => substr(hash('sha256', $rawToken), 0, 12)],
                'Invalid, expired, revoked or replayed refresh token',
                result: 'FAILED', errorCode: $replaced ? 'AUTH_SESSION_REPLACED' : 'UNAUTHENTICATED',
            );

            return $replaced
                ? $this->respondError('Tài khoản đã đăng nhập ở phiên khác. Vui lòng đăng nhập lại.', [], 401, 'AUTH_SESSION_REPLACED', [
                    'replacement' => AccountSessionPolicy::replacementDetails($request->attributes->get('wms.auth_replacing_session_id')),
                ])
                : $this->respondError('Refresh token không hợp lệ hoặc đã hết hạn.', [], 401, 'UNAUTHENTICATED');
        }

        $user = $rotated['user'];
        // Token mới mang `sid` của phiên VỪA xoay vòng, để /auth/sessions vẫn nhận ra
        // đúng phiên hiện tại sau mỗi lần làm mới.
        $accessToken = $this->refreshTokens->accessTokenFor($user, $rotated['id']);
        $this->auditLogger->log('TOKEN_REFRESH', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId(), result: 'SUCCESS');

        $response = $this->respondSuccess([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => AuthUserPayload::full($user),
        ], 'Làm mới token thành công');

        return $this->tokenTransport->deliver($request, $response, $rotated['raw']);
    }

    #[OA\Post(
        path: '/api/v1/auth/logout', operationId: 'auth_logout',
        summary: 'Đăng xuất, thu hồi refresh token và huỷ access token hiện tại',
        description: 'Trình duyệt: body rỗng; refresh token của phiên đọc từ cookie HttpOnly và response hết hạn cookie đó. Không có cookie thì thu hồi mọi refresh token của tài khoản. Kênh `X-WMS-Token-Transport: body` thu hồi refresh_token gửi trong body.',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        parameters: [
            new OA\Parameter(
                name: 'X-WMS-Token-Transport',
                in: 'header',
                required: false,
                description: 'Kênh refresh token: `cookie` (mặc định, trình duyệt) hoặc `body` (client không phải trình duyệt, máy chủ phải bật).',
                schema: new OA\Schema(type: 'string', enum: ['cookie', 'body'])
            ),
        ],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(
                property: 'refresh_token',
                type: 'string',
                nullable: true,
                description: 'CHỈ có tác dụng với `X-WMS-Token-Transport: body`; kênh cookie bỏ qua trường này.'
            ),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Đăng xuất thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 422, description: 'refresh_token sai kiểu dữ liệu'),
        ]
    )]
    public function logout(Request $request)
    {
        $request->validate(['refresh_token' => ['nullable', 'string', 'max:255']]);
        /** @var User $user */
        $user = auth('api')->user();

        // Đọc đúng kênh của request: trình duyệt → cookie HttpOnly; kênh body → trường body.
        $rawToken = $this->tokenTransport->presentedToken($request);

        if ($rawToken !== null) {
            $this->refreshTokens->revoke($rawToken);
        } else {
            $this->refreshTokens->revokeAllForUser($user->user_id);
        }

        auth('api')->logout();
        $this->auditLogger->log('LOGOUT', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId(), result: 'SUCCESS');

        return $this->respondSuccess(null, 'Đăng xuất thành công');
    }

    #[OA\Get(
        path: '/api/v1/auth/me', operationId: 'auth_me',
        summary: 'Thông tin tài khoản, role, scope và permission hiện hành',
        description: 'Có data.account_session_policy: auth-session-replacement-v1 ở chế độ cũ; auth-session-policy-v2 khi DEV bật đa phiên có kiểm soát, tối đa 2 family đồng thời và không tự thu hồi phiên để lấy chỗ. Phiên đã bị thay thế vẫn trả 401 AUTH_SESSION_REPLACED. Cả hai chế độ yêu cầu sid hợp lệ; client kiểm tra identity khi foreground và trước lệnh ghi. Đa phiên không cung cấp takeover ca/nháp hoặc realtime kho.',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Thông tin tài khoản'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function me(Request $request)
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $this->respondSuccess([
            ...$this->userProfilePayload($user),
            ...$this->userAuthorizationPayload($user),
        ]);
    }

    #[OA\Patch(
        path: '/api/v1/auth/me', operationId: 'auth_update_me',
        summary: 'Sửa hồ sơ của chính mình (tên hiển thị, số điện thoại)',
        description: 'Chỉ nhận display_name và phone. Gửi bất kỳ trường nào khác (email, username, status, roles, permissions, department_id, password...) trả 422 PROFILE_FIELD_NOT_EDITABLE kèm danh sách trường vi phạm — vai trò và quyền không bao giờ tự sửa được. Email là định danh đăng nhập nên không sửa ở đây. Gửi kèm If-Match: <version> (lấy từ GET /auth/me) để chống ghi đè giữa hai thiết bị; lệch version trả 412 STALE_VERSION. Response cùng dạng GET /auth/me, kèm header ETag.',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        parameters: [
            new OA\Parameter(name: 'If-Match', in: 'header', required: false, description: 'version hiện tại của hồ sơ.', schema: new OA\Schema(type: 'string', example: '3')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'display_name', type: 'string', maxLength: 255),
                new OA\Property(property: 'phone', type: 'string', nullable: true, pattern: '^0\d{9}$', example: '0912345678'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Hồ sơ sau khi sửa'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 412, description: 'STALE_VERSION — hồ sơ đã được sửa ở phiên khác'),
            new OA\Response(response: 422, description: 'PROFILE_FIELD_NOT_EDITABLE / PROFILE_EMPTY_UPDATE / INVALID_VERSION / VALIDATION_ERROR'),
        ]
    )]
    public function updateMe(Request $request)
    {
        /** @var User $user */
        $user = auth('api')->user();

        // Allowlist tường minh, và trường lạ là LỖI chứ không phải bị bỏ qua: client
        // gửi `roles` mà nhận 200 sẽ tưởng đã đổi được vai trò. Đọc riêng body, không
        // lẫn query string.
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $forbidden = array_values(array_diff(array_keys($body), ['display_name', 'phone']));
        if ($forbidden !== []) {
            return $this->respondError(
                'Chỉ được sửa tên hiển thị và số điện thoại. Vai trò, quyền và email không tự sửa được.',
                ['fields' => $forbidden], 422, 'PROFILE_FIELD_NOT_EDITABLE',
            );
        }

        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^0\d{9}$/'],
        ]);
        if ($data === []) {
            return $this->respondError('Không có trường nào để cập nhật.', [], 422, 'PROFILE_EMPTY_UPDATE');
        }

        // Cùng quy ước với AgencyController::assertVersionIfProvided(): chỉ ép khi
        // client gửi header, để client cũ không vỡ.
        $ifMatch = trim((string) $request->header('If-Match'));
        if ($ifMatch !== '') {
            $expected = trim(preg_replace('/^W\//', '', $ifMatch) ?? $ifMatch, '" ');
            if (! ctype_digit($expected)) {
                return $this->respondError('If-Match phải là version số nguyên.', [], 422, 'INVALID_VERSION');
            }
            if ((int) $expected !== (int) $user->version) {
                return $this->respondError(
                    'Hồ sơ đã được cập nhật ở phiên khác; tải lại trước khi lưu.',
                    ['expected_version' => (int) $expected, 'current_version' => (int) $user->version],
                    412, 'STALE_VERSION',
                );
            }
        }

        if (array_key_exists('display_name', $data)) {
            $data['display_name'] = trim((string) $data['display_name']);
        }
        $before = array_intersect_key(['display_name' => $user->display_name, 'phone' => $user->phone], $data);

        $user->forceFill([...$data, 'version' => ((int) $user->version) + 1])->save();

        $this->auditLogger->log(
            'UPDATE_PROFILE', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId(),
            $before, array_intersect_key(['display_name' => $user->display_name, 'phone' => $user->phone], $data),
            result: 'SUCCESS',
        );

        $fresh = $user->fresh() ?? $user;

        return $this->respondSuccess([
            ...$this->userProfilePayload($fresh),
            ...$this->userAuthorizationPayload($fresh),
        ], 'Cập nhật hồ sơ thành công')->header('ETag', '"'.((int) $fresh->version).'"');
    }

    #[OA\Get(
        path: '/api/v1/auth/sessions', operationId: 'auth_sessions',
        summary: 'Các thiết bị đang đăng nhập của chính mình',
        description: 'Mỗi dòng là một lần đăng nhập còn hiệu lực (refresh token chưa thu hồi, chưa hết hạn). KHÔNG bao giờ trả token. `is_current` đánh dấu phiên của chính request đang gọi — chỉ xác định được với access token cấp từ bản này trở đi (mang claim sid); token cũ hơn thì mọi dòng đều false cho tới lần đăng nhập/làm mới kế tiếp. `last_active_at` là lần đăng nhập hoặc làm mới token gần nhất, không phải từng request.',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'data[]: id, device_label, user_agent, ip_address, last_active_at, expires_at, is_current + meta phân trang'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
        ]
    )]
    public function sessions(Request $request)
    {
        /** @var User $user */
        $user = auth('api')->user();
        $currentId = $this->refreshTokens->currentSessionId();

        $page = RefreshToken::query()
            ->active()
            ->where('user_id', $user->user_id)
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 20));

        $page->setCollection($page->getCollection()->map(fn ($session): array => [
            'id' => (string) $session->id,
            'device_label' => $this->deviceLabel($session->user_agent),
            'user_agent' => $session->user_agent,
            'ip_address' => $session->ip_address,
            'last_active_at' => $session->created_at?->toIso8601String(),
            'expires_at' => $session->expires_at?->toIso8601String(),
            'is_current' => $currentId !== null && strcasecmp($currentId, (string) $session->id) === 0,
        ]));

        return $this->respondPaginated($page);
    }

    #[OA\Delete(
        path: '/api/v1/auth/sessions/{id}', operationId: 'auth_session_revoke',
        summary: 'Đăng xuất một thiết bị của chính mình',
        description: 'Thu hồi refresh token của phiên đó: thiết bị ấy KHÔNG làm mới token được nữa và phải đăng nhập lại. Lưu ý: access token đang dùng của thiết bị đó vẫn gọi API được tới khi hết hạn tự nhiên (JWT stateless). Id phiên của người khác trả 404, không phải 403. Gọi lại trên phiên đã thu hồi vẫn 200 (already_revoked=true) nên bấm lại không sao. Đăng xuất chính máy đang dùng thì gọi POST /auth/logout.',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'id, revoked, already_revoked, was_current'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 404, description: 'Không tìm thấy phiên của bạn với id này'),
        ]
    )]
    public function revokeSession(Request $request, string $id)
    {
        /** @var User $user */
        $user = auth('api')->user();

        // id không phải UUID thì PostgreSQL ném 22P02 khi so cột uuid -> 500. Trả 404.
        if (! Str::isUuid($id)) {
            return $this->respondNotFound('Không tìm thấy phiên đăng nhập.');
        }

        $session = RefreshToken::query()
            ->where('user_id', $user->user_id)
            ->where('id', $id)
            ->first();
        if ($session === null) {
            return $this->respondNotFound('Không tìm thấy phiên đăng nhập.');
        }

        $currentId = $this->refreshTokens->currentSessionId();
        $wasCurrent = $currentId !== null && strcasecmp($currentId, (string) $session->id) === 0;
        $alreadyRevoked = $session->revoked_at !== null;

        if (! $alreadyRevoked) {
            $session->forceFill(['revoked_at' => now()])->save();
            $this->auditLogger->log(
                'REVOKE_SESSION', 'refresh_token', (string) $session->id, $user->user_id, $this->currentRequestId(),
                [], ['was_current' => $wasCurrent], result: 'SUCCESS',
            );
        }

        return $this->respondSuccess([
            'id' => (string) $session->id,
            'revoked' => true,
            'already_revoked' => $alreadyRevoked,
            'was_current' => $wasCurrent,
        ], 'Đã đăng xuất thiết bị');
    }

    /** Nhãn thiết bị thô từ user-agent, đủ để người dùng nhận ra máy của mình. */
    private function deviceLabel(?string $userAgent): string
    {
        $ua = strtolower((string) $userAgent);

        return match (true) {
            $ua === '' => 'Không xác định',
            str_contains($ua, 'iphone') => 'iPhone',
            str_contains($ua, 'ipad') => 'iPad',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'macintosh') || str_contains($ua, 'mac os') => 'macOS',
            str_contains($ua, 'linux') => 'Linux',
            default => 'Thiết bị khác',
        };
    }

    #[OA\Post(
        path: '/api/v1/auth/change-password', operationId: 'auth_change_password',
        summary: 'Đổi mật khẩu tài khoản hiện tại (thu hồi toàn bộ refresh token)',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['current_password', 'new_password'],
            properties: [
                new OA\Property(property: 'current_password', type: 'string', format: 'password'),
                new OA\Property(property: 'new_password', type: 'string', format: 'password', minLength: 8),
                new OA\Property(property: 'new_password_confirmation', type: 'string', format: 'password'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Đổi mật khẩu thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 422, description: 'Mật khẩu hiện tại sai hoặc dữ liệu không hợp lệ'),
        ]
    )]
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'new_password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);
        /** @var User $user */
        $user = auth('api')->user();

        if (! Hash::check($data['current_password'], $user->password_hash)) {
            return $this->respondError('Mật khẩu hiện tại không đúng.', [
                'current_password' => ['Mật khẩu hiện tại không đúng.'],
            ], 422, 'VALIDATION_ERROR');
        }

        $user->forceFill([
            'password_hash' => Hash::make($data['new_password']),
            'must_change_password' => false,
            'version' => ((int) $user->version) + 1,
        ])->save();
        // WWHN-SEC-02: thu hồi refresh token là CHƯA đủ — access token JWT
        // stateless đã phát hành vẫn gọi API được tới khi hết hạn tự nhiên. Mốc
        // này khiến EnforceCredentialFreshness từ chối mọi access token cũ hơn
        // nó, nên đổi mật khẩu thực sự cắt được phiên của kẻ đã lấy được token.
        //
        // Contract response giữ NGUYÊN (data = null): FE đã chủ động xoá auth
        // state và bắt đăng nhập lại sau khi đổi mật khẩu thành công
        // (use-change-password.ts), nên không phát sinh thao tác mới cho người dùng.
        $user->markPasswordChangedNow();
        $this->refreshTokens->revokeAllForUser($user->user_id);
        if (config('login_security.trusted_device.revoke_on_password_change', true)) {
            app(TrustedDeviceService::class)->revokeAllForUser((string) $user->user_id);
        }
        $this->auditLogger->log('CHANGE_PASSWORD', 'app_user', $user->user_id, $user->user_id, $this->currentRequestId(), result: 'SUCCESS');

        return $this->respondSuccess(null, 'Đổi mật khẩu thành công');
    }

    #[OA\Post(
        path: '/api/v1/auth/switch-context', operationId: 'auth_switch_context',
        summary: 'Chuyển ngữ cảnh (warehouse scope) và cấp lại access token mới',
        security: [['bearerAuth' => []]], tags: ['01. Authentication'],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'context_type', type: 'string', maxLength: 50, nullable: true, example: 'WAREHOUSE'),
                new OA\Property(property: 'context_id', type: 'string', maxLength: 100, nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
                new OA\Property(property: 'scope_ref', type: 'string', maxLength: 100, nullable: true, example: '3fa85f64-5717-4562-b3fc-2c963f66afa6'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Chuyển ngữ cảnh thành công'),
            new OA\Response(response: 401, description: 'Chưa xác thực'),
            new OA\Response(response: 403, description: 'Ngữ cảnh kho nằm ngoài phạm vi được gán'),
            new OA\Response(response: 422, description: 'Dữ liệu không hợp lệ'),
        ]
    )]
    public function switchContext(Request $request)
    {
        $request->validate([
            'context_type' => ['nullable', 'string', 'max:50'],
            'context_id' => ['nullable', 'string', 'max:100'],
            'scope_ref' => ['nullable', 'string', 'max:100'],
        ]);
        /** @var User $user */
        $user = auth('api')->user();
        $scopeRef = $request->input('scope_ref');

        if ($scopeRef !== null && ! $user->canAccessWarehouse((string) $scopeRef)) {
            return $this->respondError('Ngữ cảnh kho nằm ngoài phạm vi được gán.', [], 403, 'ACCESS_DENIED');
        }

        // Giữ nguyên `sid` của phiên đang dùng: chuyển ngữ cảnh không tạo phiên mới, và
        // bỏ mất claim thì /auth/sessions sẽ không còn nhận ra phiên hiện tại.
        $accessToken = $this->refreshTokens->accessTokenFor($user, $this->refreshTokens->currentSessionId());
        $this->auditLogger->log(
            'SWITCH_CONTEXT', 'app_user', $user->user_id, $user->user_id,
            $this->currentRequestId(), [], $request->only(['context_type', 'context_id', 'scope_ref']),
            result: 'SUCCESS',
        );

        return $this->respondSuccess([
            'access_token' => $accessToken,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'active_context' => [
                'context_type' => $request->input('context_type', 'SYSTEM'),
                'context_id' => $request->input('context_id') ?? $scopeRef,
                'scope_ref' => $scopeRef,
            ],
            'user' => $this->userPayload($user),
        ], 'Chuyển ngữ cảnh thành công');
    }

    /**
     * WWHN-289: khoá brute-force theo (IP + định danh đăng nhập đã chuẩn hoá).
     * Không dùng riêng IP (một kho NAT chung sẽ tự khoá lẫn nhau) và không dùng
     * riêng username (kẻ tấn công đổi username là thoát khoá).
     *
     * @param  array<string, string>  $credentials
     */
    private function loginThrottleKey(Request $request, array $credentials): string
    {
        $identifier = Str::lower(trim((string) ($credentials['email'] ?? '')));

        return 'auth-login:'.sha1($request->ip().'|'.$identifier);
    }

    /**
     * Guard against database collations that compare login identifiers
     * case-insensitively. Password verification remains Laravel Hash::check().
     *
     * @param  array<string, string>  $credentials
     */
    private function credentialsMatchExactly(User $user, array $credentials): bool
    {
        return hash_equals((string) $user->email, (string) ($credentials['email'] ?? ''));
    }

    private function handleFailedPasswordThrottle(
        Request $request,
        LoginThrottle $throttle,
        string $submittedEmail,
        string $ip,
        ?User $user,
        string $throttleKey,
        int $maxAttempts,
        bool $backoffEnabled
    ): ?JsonResponse {
        if ($backoffEnabled) {
            $failure = $throttle->registerFailure($submittedEmail, $ip);

            if ($failure['step_up_triggered']) {
                event(new SecurityAlertRaised(
                    'LOGIN_STEP_UP_REQUIRED',
                    $user?->user_id,
                    'Phát hiện nhiều chu kỳ khoá tối đa liên tiếp; yêu cầu xác minh bổ sung trước khi đăng nhập.',
                ));
            }

            if ($failure['retry_after'] > 0) {
                $this->auditLogger->log(
                    'LOGIN_THROTTLED',
                    'app_user',
                    $user?->user_id,
                    $user?->user_id,
                    $this->currentRequestId(),
                    [],
                    [
                        'scope' => $failure['scope'],
                        'failure_count' => $failure['failures'],
                        'cap_hits' => $failure['cap_hits'],
                        'retry_after_seconds' => $failure['retry_after'],
                        'trust_cookie_valid' => false,
                    ],
                    'Too many failed login attempts',
                    result: 'FAILED',
                    errorCode: 'RATE_LIMIT_EXCEEDED',
                );

                event(new SecurityAlertRaised(
                    'LOGIN_THROTTLED',
                    $user?->user_id,
                    'Phát hiện nhiều lần đăng nhập sai liên tiếp; một định danh đã bị tạm khoá thử đăng nhập.',
                ));

                return RateLimitResponse::make(
                    $request,
                    ['Retry-After' => (string) $failure['retry_after']],
                    'Bạn đã đăng nhập sai quá nhiều lần. Vui lòng thử lại sau '.$failure['retry_after'].' giây.',
                );
            }

            return null;
        }

        if ($maxAttempts > 0) {
            RateLimiter::hit($throttleKey, 60);
        }

        return null;
    }

    private function denyInactiveAccount(
        User $user,
        string $throttleKey,
        int $maxAttempts
    ): JsonResponse {
        if ($maxAttempts > 0) {
            RateLimiter::hit($throttleKey, 60);
        }

        $this->auditLogger->log(
            'LOGIN_FAILED',
            'app_user',
            $user->user_id,
            $user->user_id,
            $this->currentRequestId(),
            [],
            ['account_status' => $user->status],
            'Inactive account login attempt',
            result: 'FAILED',
            errorCode: 'ACCOUNT_INACTIVE',
        );

        return $this->respondError(
            'Tài khoản đã bị khoá hoặc vô hiệu hoá.',
            [],
            403,
            'ACCOUNT_INACTIVE',
        );
    }

    private function userPayload(User $user): array
    {
        return AuthUserPayload::full($user);
    }

    private function userProfilePayload(User $user): array
    {
        return AuthUserPayload::profile($user);
    }

    private function userAuthorizationPayload(User $user): array
    {
        return AuthUserPayload::authorization($user);
    }
}
