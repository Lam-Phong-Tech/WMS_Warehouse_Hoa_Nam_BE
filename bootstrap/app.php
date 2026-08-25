<?php

use App\Http\Middleware\ApiConventionMiddleware;
use App\Http\Middleware\NoStoreApiResponses;
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
use Modules\Warehouse\Http\Middleware\EnforceInventoryWarehouseScope;
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
        // Keep this outermost so every successful API response is forced to
        // no-store after downstream middleware/controllers finish processing.
        $middleware->prepend(NoStoreApiResponses::class);
        // Global registration is deliberate: the middleware itself is a no-op
        // outside the six WWHN-211 read/trace endpoints. Registering centrally
        // prevents a future route refactor from silently dropping data-scope.
        $middleware->append(EnforceInventoryWarehouseScope::class);

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

            if (! $isUniqueConflict) {
                return null;
            }

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
