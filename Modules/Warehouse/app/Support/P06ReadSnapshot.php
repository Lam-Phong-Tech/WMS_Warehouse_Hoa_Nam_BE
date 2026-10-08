<?php

namespace Modules\Warehouse\Support;

use App\Support\ApiError;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One read-only database snapshot per P06 response; never a cross-request snapshot. */
final class P06ReadSnapshot
{
    public static function run(Closure $read): JsonResponse
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            ApiError::abort(503, 'P06_SNAPSHOT_UNSUPPORTED', 'Database snapshot chưa được xác nhận cho driver hiện tại.');
        }
        $outer = DB::transactionLevel() === 0;

        return DB::transaction(function () use ($read, $driver, $outer): JsonResponse {
            // Must precede the first query in this transaction. Nested test
            // transactions already own their snapshot; never change their mode.
            if ($driver === 'pgsql' && $outer) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $snapshot = [
                'id' => (string) Str::uuid(),
                'observed_at' => now()->utc()->toISOString(),
                'consistency' => $outer ? 'SINGLE_RESPONSE_DATABASE_SNAPSHOT' : 'ENCLOSING_TRANSACTION',
                'cross_request_snapshot' => false,
            ];

            return $read($snapshot);
        });
    }
}
