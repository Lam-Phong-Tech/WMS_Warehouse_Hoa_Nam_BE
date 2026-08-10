<?php

use App\Http\Middleware\ApiConventionMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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
        $middleware->prepend(ApiConventionMiddleware::class);

        $middleware->alias([
            'permission' => CheckPermission::class,
        ]);

        $middleware->priority([
            Authenticate::class,
            CheckPermission::class,
        ]);

        // Dự án chỉ có API (không có route web `login`) — không redirect guest, để
        // AuthenticationException tự render JSON qua exception handler bên dưới.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Chưa xác thực',
                'error_code' => 'UNAUTHENTICATED',
                'errors' => [],
                'error' => ['code' => 'UNAUTHENTICATED', 'details' => []],
            ], 401);
        });

        $exceptions->render(function (UnauthorizedHttpException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Chưa xác thực',
                'error_code' => 'UNAUTHENTICATED',
                'errors' => [],
                'error' => ['code' => 'UNAUTHENTICATED', 'details' => []],
            ], 401);
        });

        $exceptions->render(function (JWTException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Chưa xác thực',
                'error_code' => 'UNAUTHENTICATED',
                'errors' => [],
                'error' => ['code' => 'UNAUTHENTICATED', 'details' => []],
            ], 401);
        });

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

        // Bắt các exception hệ thống chưa xử lý riêng ở trên (500 ngoài dự kiến). Không đụng tới
        // ValidationException/HttpResponseException (đã đúng shape sẵn từ FormRequest/Repository)
        // hay các HttpExceptionInterface khác (403, 429...) — chỉ nhắm lỗi hệ thống chung.
        $exceptions->render(function (Throwable $e, $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e instanceof ValidationException || $e instanceof HttpResponseException || $e instanceof HttpExceptionInterface) {
                return null;
            }

            $payload = [
                'success' => false,
                'message' => 'Đã xảy ra lỗi hệ thống. Vui lòng thử lại sau.',
                'error_code' => 'INTERNAL_SERVER_ERROR',
                'errors' => [],
                'error' => ['code' => 'INTERNAL_SERVER_ERROR', 'details' => []],
            ];

            if (config('app.debug')) {
                $payload['exception'] = get_class($e);
                $payload['file'] = $e->getFile();
                $payload['line'] = $e->getLine();
                $payload['trace'] = collect($e->getTrace())->map(fn ($t) => Arr::except($t, ['args']))->all();
            }

            return response()->json($payload, 500);
        });
    })->create();
