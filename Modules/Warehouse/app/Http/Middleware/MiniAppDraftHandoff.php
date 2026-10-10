<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\MiniAppDraftService;
use Modules\Warehouse\Services\P19LinkedComponentIssue;
use Symfony\Component\HttpFoundation\Response;

/** Opt-in identity bridge on existing owner routes; no new record/camera/NFC/Post route. */
final class MiniAppDraftHandoff
{
    public function handle(Request $request, Closure $next): Response
    {
        $operation = MiniAppDraftService::RECORD_ROUTES[$request->path()] ?? null;
        $hasContext = $request->hasHeader('X-Draft-ID') || $request->hasHeader('X-Draft-Version') || $request->hasHeader('X-Draft-Scan-Session-ID');
        if ($operation === 'COMPONENT_ISSUE' && $request->isMethod('POST') && $request->hasHeader(P19LinkedComponentIssue::HEADER)) {
            if ($request->header(P19LinkedComponentIssue::HEADER) !== P19LinkedComponentIssue::VERSION) {
                ApiError::abort(422, 'P19_CONTRACT_INVALID', 'Phiên bản contract xuất linh kiện không được hỗ trợ.');
            }
            if (! $hasContext) {
                ApiError::abort(409, 'P19_LINKED_DRAFT_REQUIRED', 'P19 yêu cầu bản nháp P03 đã lưu; không dùng record standalone để phục hồi.');
            }
        }
        if ($operation === null || ! $hasContext || ! $request->isMethod('POST')) {
            return $next($request);
        }
        if ($operation === 'COMPONENT_ISSUE') {
            $request->attributes->set('p19.original_operation_key', strtolower((string) $request->header('Idempotency-Key')));
            $owner = static fn (Request $inner): Response => app(P19LinkedComponentIssue::class)->handle($inner, $next);
        } else {
            $owner = $next;
        }

        return P03JsonEnvelope::ready($request, app(MiniAppDraftService::class)->handoff($request, $owner, $operation));
    }
}
