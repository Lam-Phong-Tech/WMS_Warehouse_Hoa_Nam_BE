<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Modules\Warehouse\Support\P22NfcHistory;
use Symfony\Component\HttpFoundation\Response;

final class P22NfcHistoryRead
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has(P22NfcHistory::HEADER)) {
            return $next($request);
        }
        if ($request->header(P22NfcHistory::HEADER) !== P22NfcHistory::CONTRACT) {
            ApiError::abort(422, 'NFC_HISTORY_CONTRACT_UNSUPPORTED', 'Phiên bản contract lịch sử NFC chưa được hỗ trợ.');
        }

        return app(P22NfcHistory::class)->read($request);
    }
}
