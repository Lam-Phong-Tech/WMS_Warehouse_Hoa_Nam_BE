<?php

use App\Http\Middleware\ApiConventionMiddleware;
use App\Http\Middleware\Wwhn172AggregateLock;
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
        $middleware->prepend(Wwhn172AggregateLock::class);
        $middleware->prepend(ApiConventionMiddleware::class);

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
            'message' => 'Chưa xác thực',
            'error_code' => 'UNAUTHENTICATED',
            'errors' => [],
            'error' => ['code' => 'UNAUTHENTICATED', 'details' => []],
        ], 401);

        $exceptions->render(fn (AuthenticationException $e, $request) => $unauthenticated());
        $exceptions->render(fn (UnauthorizedHttpException $e, $request) => $unauthenticated());
        $exceptions->render(fn (JWTException $e, $request) => $unauthenticated());

        $exceptions->render(function (MethodNotAllowedHttpException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Phương thức HTTP không được hỗ trợ cho endpoint này.',
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
                'message' => 'Dữ liệu đã được ghi bởi request khác hoặc vi phạm ràng buộc duy nhất.',
                'error_code' => 'UNIQUE_CONSTRAINT_CONFLICT',
                'errors' => [],
                'error' => [
                    'code' => 'UNIQUE_CONSTRAINT_CONFLICT',
                    'details' => [],
                ],
            ], 409);
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
