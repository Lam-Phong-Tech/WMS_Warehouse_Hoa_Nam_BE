<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class P12MixedDocumentsReadGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (! config('p12_mixed_documents.enabled', false)) {
                ApiError::abort(503, 'DOCUMENTS_AGGREGATE_DISABLED', 'Danh sách trộn chưa được mở trên bản triển khai này.');
            }
            if ($request->getContent() !== '' || $request->request->all() !== [] || $request->files->all() !== []) {
                ApiError::abort(422, 'DOCUMENTS_BODY_NOT_ALLOWED', 'GET danh sách trộn không nhận body.');
            }
            $response = $next($request);
        } catch (HttpResponseException $exception) {
            $response = $exception->getResponse();
        } catch (ValidationException $exception) {
            $response = app(ExceptionHandler::class)->render($request, $exception);
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Vary', 'Authorization');

        return $response;
    }
}
