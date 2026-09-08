<?php

use App\Http\Middleware\ApiConventionMiddleware;
use App\Http\Middleware\ApiRateLimit;
use App\Http\Middleware\EnsurePublicApiEnvelope;
use App\Http\Middleware\NoStoreApiResponses;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SlowRequestLog;
use App\Http\Middleware\Wwhn172AggregateLock;
use App\Support\UserFacingMessageLocalizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Auth\Http\Middleware\CheckPermission;
use Modules\Auth\Http\Middleware\EnforceActiveAccountStatus;
use Modules\Auth\Http\Middleware\EnforceCredentialFreshness;
use Modules\Auth\Http\Middleware\EnforceMfaEnrollment;
use Modules\Auth\Http\Middleware\EnforcePasswordChangeRequired;
use Modules\Warehouse\Http\Middleware\EnforceInventoryWarehouseScope;
use Modules\Warehouse\Http\Middleware\EnsureWarehouseOperational;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Tymon\JWTAuth\Exceptions\JWTException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // WWHN-295: BE luôn chạy sau reverse proxy (nginx -> php-fpm, cộng TLS
        // terminator do DevOps sở hữu). Chỉ tin đúng 4 header X-Forwarded chuẩn
        // của topology này — không tin biến thể AWS ELB / X-Forwarded-Prefix.
        // Danh sách proxy được tin đặt tại config/trustedproxy.php để
        // `php artisan config:cache` không làm rỗng giá trị.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->prepend(Wwhn172AggregateLock::class);
        $middleware->prepend(ApiConventionMiddleware::class);
        // Vị trí CÓ Ý NGHĨA: phải nằm NGOÀI ApiConventionMiddleware. Middleware
        // đó có hai nhánh RETURN SỚM (payload không phải UTF-8, JSON hỏng) trả
        // response ngay mà không gọi $next — đặt bên trong thì hai nhánh ấy
        // thoát ra với envelope nội bộ. Nằm ngoài thì mọi response của
        // api/v1/public, kể cả 405/404 lúc khớp route và 429 của throttle, đều
        // đi qua đây đúng một lần.
        // No-op ngoài tiền tố api/v1/public — xem EnsurePublicApiEnvelope.
        $middleware->prepend(EnsurePublicApiEnvelope::class);
        // Keep this outermost so every successful API response is forced to
        // no-store after downstream middleware/controllers finish processing.
        $middleware->prepend(NoStoreApiResponses::class);
        // Ngoài cùng: đo trọn thời gian của mọi middleware + controller, ghi
        // request vượt ngưỡng wms_perf.slow_request_ms kèm phân rã SQL/cache.
        $middleware->prepend(SlowRequestLog::class);
        // WWHN-SEC-03: đặt NGOÀI CÙNG để phủ cả response do middleware/exception
        // handler sinh ra (401/403/404/429/500), không chỉ response của controller.
        $middleware->prepend(SecurityHeaders::class);
        // WWHN-SEC-01: trần gọi API cho nhóm đã đăng nhập. Gắn vào NHÓM `api`
        // (không phải global) vì cùng lý do với EnforceInventoryWarehouseScope —
        // gắn `throttle:` thủ công vào từng nhóm route thì một lần refactor quên
        // một nhóm là mất trần ở đó mà không có gì báo; gắn ở nhóm `api` thì mọi
        // route api/* mới sinh ra đã có trần sẵn.
        //
        // PHẢI là nhóm `api` chứ KHÔNG phải global: middleware global chạy TRƯỚC
        // bước khớp route nên `$request->route()` luôn null ở đó, và
        // ApiRateLimit::hasExplicitThrottle() sẽ không bao giờ thấy được
        // `throttle:pv-public`. Hệ quả nếu đăng ký global: nhóm PV công khai bị
        // đếm hai lần và bị hạ trần từ 120/phút xuống 60/phút.
        $middleware->api(append: [ApiRateLimit::class]);
        // Kho tạm dừng -> đóng băng GHI trên toàn API (BA 07/09/2026, màn Cấu hình kho).
        // Gắn vào nhóm `api` chứ KHÔNG global, cùng lý do với ApiRateLimit ở trên:
        // middleware global chạy trước bước khớp route nên `$request->route()` là null,
        // mà danh sách route được phép ghi lúc kho tạm dừng so theo `Route::uri()`.
        $middleware->api(append: [EnsureWarehouseOperational::class]);
        // Global registration is deliberate: the middleware itself is a no-op
        // outside the six WWHN-211 read/trace endpoints. Registering centrally
        // prevents a future route refactor from silently dropping data-scope.
        $middleware->append(EnforceInventoryWarehouseScope::class);
        // Global, cheap early-exit: chặn tài khoản bị khoá/vô hiệu hoá ngay từ
        // request kế tiếp, kể cả khi access token đang dùng vẫn còn hiệu lực
        // (login/refresh chỉ chặn được token MỚI, không thu hồi token cũ).
        $middleware->append(EnforceActiveAccountStatus::class);
        // Cấp tài khoản nhân viên: bắt buộc đổi mật khẩu lần đầu trước khi
        // dùng bất kỳ endpoint nào khác (trừ change-password/logout).
        $middleware->append(EnforcePasswordChangeRequired::class);
        // WWHN-SEC-08: vai tro dac quyen phai dang ky MFA truoc khi dung API.
        // Dat SAU EnforcePasswordChangeRequired: doi mat khau lan dau la dieu
        // kien truoc, dang ky MFA la dieu kien sau. Middleware tu no la no-op
        // khi MFA_ENFORCE_FOR_PRIVILEGED_ROLES=false.
        $middleware->append(EnforceMfaEnrollment::class);
        // WWHN-SEC-02: từ chối access token phát hành TRƯỚC lần đổi mật khẩu
        // gần nhất. Thu hồi refresh token là chưa đủ vì access token JWT
        // stateless vẫn sống tới khi hết hạn tự nhiên.
        $middleware->append(EnforceCredentialFreshness::class);

        $middleware->alias([
            'permission' => CheckPermission::class,
        ]);

        $middleware->priority([
            Authenticate::class,
            CheckPermission::class,
        ]);

        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $unauthenticated = static fn () => response()->json([
            'success' => false,
            'message' => 'Chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
            'error_code' => 'UNAUTHENTICATED',
            'errors' => [],
            'error' => ['code' => 'UNAUTHENTICATED', 'details' => []],
        ], 401);

        $exceptions->render(fn (AuthenticationException $e, $request) => $unauthenticated());
        $exceptions->render(fn (UnauthorizedHttpException $e, $request) => $unauthenticated());
        $exceptions->render(fn (JWTException $e, $request) => $unauthenticated());

        // ValidationException được Laravel xử lý ngoài middleware stack, vì vậy phải
        // Việt hóa ngay tại exception boundary để errors/details không rò câu mặc định
        // tiếng Anh như "The ... field is required". Giữ nguyên BUSINESS_RULE_ERROR
        // theo contract 422 hiện hành; chỉ thay message/details hiển thị.
        $exceptions->render(function (ValidationException $e, $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $details = UserFacingMessageLocalizer::translateDetails($e->errors());

            return response()->json([
                'success' => false,
                'message' => 'Dữ liệu gửi lên không hợp lệ.',
                'error_code' => 'BUSINESS_RULE_ERROR',
                'errors' => $details,
                'error' => ['code' => 'BUSINESS_RULE_ERROR', 'details' => $details],
            ], 422);
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Phương thức HTTP không được hỗ trợ cho đường dẫn này.',
                'error_code' => 'METHOD_NOT_ALLOWED',
                'errors' => [],
                'error' => ['code' => 'METHOD_NOT_ALLOWED', 'details' => []],
            ], 405);
        });

        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy tài nguyên hoặc đường dẫn yêu cầu.',
                'error_code' => 'RESOURCE_NOT_FOUND',
                'errors' => [],
                'error' => ['code' => 'RESOURCE_NOT_FOUND', 'details' => []],
            ], 404);
        });

        $exceptions->render(function (QueryException $e, $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            $driverCode = (string) ($e->errorInfo[1] ?? '');
            $internalMessage = strtolower($e->getMessage());
            $isUniqueConflict = $sqlState === '23505'
                || in_array($driverCode, ['1062', '1555', '2067'], true)
                || str_contains($internalMessage, 'unique constraint failed')
                || str_contains($internalMessage, 'duplicate entry');

            if ($isUniqueConflict) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dữ liệu đã được ghi bởi thao tác khác hoặc vi phạm ràng buộc duy nhất.',
                    'error_code' => 'UNIQUE_CONSTRAINT_CONFLICT',
                    'errors' => [],
                    'error' => [
                        'code' => 'UNIQUE_CONSTRAINT_CONFLICT',
                        'details' => [],
                    ],
                ], 409);
            }

            // Safety net toàn cục cho lớp bug đã lặp lại ở nhiều controller: route
            // {id}/{xxxId} không ràng buộc whereUuid() nên chuỗi rác lọt thẳng xuống
            // where('col', $id) trên cột kiểu uuid native của PostgreSQL, Postgres ném
            // 22P02 (invalid_text_representation) — KHÔNG phải HttpExceptionInterface
            // nên trước đây rơi thẳng xuống 500 chung. sqlite không strict kiểu nên bug
            // không lộ khi chạy test bằng sqlite, chỉ lộ trên Postgres thật.
            if ($sqlState === '22P02') {
                return response()->json([
                    'success' => false,
                    'message' => 'Định dạng mã định danh trong yêu cầu không hợp lệ.',
                    'error_code' => 'INVALID_IDENTIFIER_FORMAT',
                    'errors' => [],
                    'error' => [
                        'code' => 'INVALID_IDENTIFIER_FORMAT',
                        'details' => [],
                    ],
                ], 422);
            }

            return null;
        });

        // Safety net cho HttpException framework/third-party còn lại. Giữ mã HTTP và
        // error_code kỹ thuật, chỉ Việt hóa message hiển thị cho client.
        $exceptions->render(function (HttpExceptionInterface $e, $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $status = $e->getStatusCode();
            $codes = [
                400 => 'BAD_REQUEST',
                401 => 'UNAUTHENTICATED',
                403 => 'ACCESS_DENIED',
                404 => 'RESOURCE_NOT_FOUND',
                405 => 'METHOD_NOT_ALLOWED',
                409 => 'STATE_CONFLICT',
                412 => 'STALE_VERSION',
                422 => 'BUSINESS_RULE_ERROR',
                428 => 'PRECONDITION_REQUIRED',
                500 => 'INTERNAL_SERVER_ERROR',
            ];
            $fallbacks = [
                400 => 'Yêu cầu không hợp lệ.',
                401 => 'Chưa đăng nhập hoặc phiên đăng nhập đã hết hạn.',
                403 => 'Bạn không có quyền thực hiện thao tác này.',
                404 => 'Không tìm thấy dữ liệu.',
                405 => 'Phương thức HTTP không được hỗ trợ.',
                409 => 'Dữ liệu hoặc trạng thái hiện tại không cho phép thao tác này.',
                412 => 'Dữ liệu đã thay đổi, vui lòng tải lại và thử lại.',
                422 => 'Dữ liệu không đáp ứng quy tắc nghiệp vụ.',
                428 => 'Thiếu điều kiện bắt buộc để thực hiện thao tác.',
                500 => 'Hệ thống gặp lỗi nội bộ.',
            ];
            $rawMessage = trim($e->getMessage());
            $message = $rawMessage !== ''
                ? UserFacingMessageLocalizer::translate($rawMessage)
                : ($fallbacks[$status] ?? 'Không thể thực hiện yêu cầu.');

            return response()->json([
                'success' => false,
                'message' => $message,
                'error_code' => $codes[$status] ?? 'HTTP_ERROR',
                'errors' => [],
                'error' => ['code' => $codes[$status] ?? 'HTTP_ERROR', 'details' => []],
            ], $status);
        });

        $exceptions->render(function (Throwable $e, $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e instanceof ValidationException || $e instanceof HttpResponseException || $e instanceof HttpExceptionInterface) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
                'errors' => [],
                'error' => ['code' => 'INTERNAL_SERVER_ERROR', 'details' => []],
            ], 500);
        });
    })->create();
