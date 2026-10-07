<?php

namespace Modules\Warehouse\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\MiniAppDraftService;
use Symfony\Component\HttpFoundation\Response;

/** Opt-in identity bridge on existing owner routes; no new record/camera/NFC/Post route. */
final class MiniAppDraftHandoff
{
    public function handle(Request $request, Closure $next): Response
    {
        $operation = MiniAppDraftService::RECORD_ROUTES[$request->path()] ?? null;
        $hasContext = $request->hasHeader('X-Draft-ID') || $request->hasHeader('X-Draft-Version') || $request->hasHeader('X-Draft-Scan-Session-ID');
        if ($operation === null || ! $hasContext || ! $request->isMethod('POST')) {
            return $next($request);
        }

        return P03JsonEnvelope::ready($request, app(MiniAppDraftService::class)->handoff($request, $next, $operation));
    }
}
