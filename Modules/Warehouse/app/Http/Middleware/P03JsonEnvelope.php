<?php

namespace Modules\Warehouse\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Use the existing ApiResponse envelope-ready contract without losing JSON object shapes. */
final class P03JsonEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::ready($request, $next($request));
    }

    public static function ready(Request $request, Response $response): Response
    {
        if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response;
        }
        // Only invoked by P03 routes and their opt-in handoff. Do not decode to
        // associative arrays: nested empty form objects must remain objects.
        $body = $response->getData();
        if (! is_object($body) || ($body->success ?? false) !== true) {
            return $response;
        }
        $body->meta ??= (object) [];
        $body->meta->request_id = $request->attributes->get('request_id');
        $body->meta->timestamp = now()->toIso8601String();
        $response->setData($body);
        // ApiConventionMiddleware consumes/removes this internal response marker,
        // exactly as for App\Traits\ApiResponse. All request/auth guards still run.
        $response->headers->set('X-WMS-Envelope-Ready', '1');

        return $response;
    }
}
