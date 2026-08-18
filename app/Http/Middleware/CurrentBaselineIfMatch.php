<?php

namespace App\Http\Middleware;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * CẢNH BÁO (WWHN-309): middleware này hiện KHÔNG được gắn vào route nào.
 * Optimistic locking đang được enforce trong controller/service bằng
 * ApiError::abort(412, 'STALE_VERSION') / 428 PRECONDITION_REQUIRED. Đừng giả định
 * route có If-Match chỉ vì lớp này tồn tại — kiểm tra
 * docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md (cột If-Match).
 */
class CurrentBaselineIfMatch
{
    public function handle(Request $request, Closure $next, string $table, string $primaryKey): Response
    {
        $resourceId = $request->route('id');
        if (! is_string($resourceId) || $resourceId === '') {
            ApiError::abort(422, 'RESOURCE_ID_REQUIRED', 'Không resolve được resource id cho optimistic locking.');
        }
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'version')) {
            ApiError::abort(500, 'VERSION_CONTRACT_MISSING', "{$table}.version chưa được migrate.");
        }

        $expected = $this->expectedVersion($request);

        return DB::transaction(function () use ($request, $next, $table, $primaryKey, $resourceId, $expected): Response {
            $row = DB::table($table)->where($primaryKey, $resourceId)->lockForUpdate()->first();
            if ($row === null) {
                ApiError::abort(404, 'RESOURCE_NOT_FOUND', 'Không tìm thấy resource.');
            }
            $current = (int) ($row->version ?? 0);
            if ($current !== $expected) {
                ApiError::abort(412, 'STALE_VERSION', 'Resource version mismatch.', [
                    'expected_version' => $expected,
                    'current_version' => $current,
                ]);
            }

            /** @var Response $response */
            $response = $next($request);
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && strtoupper($request->method()) !== 'DELETE') {
                $stillExists = DB::table($table)->where($primaryKey, $resourceId)->exists();
                if ($stillExists) {
                    DB::table($table)->where($primaryKey, $resourceId)->update([
                        'version' => $current + 1,
                        'updated_at' => Schema::hasColumn($table, 'updated_at') ? now() : DB::raw('updated_at'),
                    ]);
                    $response->headers->set('ETag', '"'.($current + 1).'"');
                }
            }

            return $response;
        }, 3);
    }

    private function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->header('If-Match'));
        if ($header === '') {
            ApiError::abort(428, 'PRECONDITION_REQUIRED', 'If-Match là bắt buộc cho mutation trên aggregate hiện hữu.');
        }
        $header = preg_replace('/^W\//', '', $header) ?? $header;
        $header = trim($header, '" ');
        if (! ctype_digit($header) || (int) $header < 1) {
            ApiError::abort(422, 'INVALID_VERSION', 'If-Match phải là version số nguyên dương.');
        }

        return (int) $header;
    }
}
