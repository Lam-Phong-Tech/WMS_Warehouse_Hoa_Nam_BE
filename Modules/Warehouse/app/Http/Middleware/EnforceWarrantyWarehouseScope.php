<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Warehouse\Support\WarrantyWarehouseScope;
use Symfony\Component\HttpFoundation\Response;

/** API-group registration survives canonical runtime route overrides and Mini App aliases. */
final class EnforceWarrantyWarehouseScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');
        $write = ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);
        if (preg_match('~^api/v1/(?:mini-app/)?warranty-cases/([^/]+)(?:/|$)~D', $path, $match)) {
            WarrantyWarehouseScope::assertCase($request, $match[1], $write);
        } elseif (preg_match('~^api/v1/(?:mini-app/)?warranty-attachments/([^/]+)(?:/|$)~D', $path, $match)) {
            if (! Str::isUuid($match[1])) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy tệp đính kèm.');
            }
            $caseId = DB::table('warranty_attachments')->where('id', $match[1])->value('warranty_case_id');
            if ($caseId === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy tệp đính kèm.');
            }
            WarrantyWarehouseScope::assertCase($request, (string) $caseId, $write);
        }

        return $next($request);
    }
}
