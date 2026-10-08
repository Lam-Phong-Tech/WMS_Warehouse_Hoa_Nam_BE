<?php

namespace Modules\Warehouse\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A scan session is one business batch, not a filtered history-list page. */
final class P08HistoryReadContract
{
    public function handle(Request $request, Closure $next): Response
    {
        $operation = $request->route('sessionId') !== null
            ? 'SCAN_SESSION'
            : (str_ends_with($request->path(), '/summary') ? 'SUMMARY' : 'RECORDS');
        $unexpected = self::unexpectedFilters($operation, $request->query());
        if ($unexpected !== []) {
            ApiError::abort(422, $operation === 'SCAN_SESSION' ? 'HISTORY_SESSION_FILTER_UNSUPPORTED' : 'HISTORY_SUMMARY_NOT_PAGED',
                $operation === 'SCAN_SESSION'
                    ? 'Phiên quét chỉ nhận warehouse_id, limit và cursor; tổng phiên không được thu hẹp bằng bộ lọc danh sách.'
                    : 'Summary không phân trang; không gửi limit, cursor hoặc activityId.');
        }

        return $next($request);
    }

    /** Pure input policy, also exercised without a database by the contract gate. */
    public static function unexpectedFilters(string $operation, array $query): array
    {
        return match ($operation) {
            'SCAN_SESSION' => array_values(array_diff(array_keys($query), ['warehouse_id', 'limit', 'cursor'])),
            'SUMMARY' => array_values(array_intersect(array_keys($query), ['limit', 'cursor', 'activityId'])),
            default => [],
        };
    }
}
