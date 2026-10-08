<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
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
        // The optional P05 existing-source mode also reads the selected plan and
        // recipient. Recheck its read permission BEFORE a cached receipt replay.
        // Manual/standalone legacy requests retain their existing permissions.
        if ($request->path() === 'api/v1/mini-app/outbound/record'
            && ($request->exists('source_document_id') || $request->exists('source_version'))
            && ! $request->user()->hasPermission('outbound.view')) {
            ApiError::abort(403, 'P05_OPERATION_DENIED', 'Không đủ quyền đọc phiếu nguồn.');
        }
        if ($operation === null || ! $hasContext || ! $request->isMethod('POST')) {
            return $next($request);
        }

        return P03JsonEnvelope::ready($request, app(MiniAppDraftService::class)->handoff($request, $next, $operation));
    }
}
