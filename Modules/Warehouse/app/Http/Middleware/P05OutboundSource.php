<?php

namespace Modules\Warehouse\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Warehouse\Http\Controllers\P05OutboundController;
use Symfony\Component\HttpFoundation\Response;

/** Optional existing-source mode, inside the established P03 receipt transaction. */
final class P05OutboundSource
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->exists('source_document_id') || $request->exists('source_version')) {
            return app(P05OutboundController::class)->recordExisting($request);
        }

        // Original standalone/manual creation contracts are not reinterpreted.
        return $next($request);
    }
}
