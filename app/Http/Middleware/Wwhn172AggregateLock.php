<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class Wwhn172AggregateLock
{
    public function handle(Request $request, Closure $next): Response
    {
        $mutex = $this->mutexFor($request);
        if ($mutex === null) {
            return $next($request);
        }
        if (! Schema::hasTable('aggregate_mutexes')) {
            throw new \RuntimeException('Thiếu migration aggregate_mutexes của WWHN-172.');
        }

        $previousPolicy = config('audit.write_policy', 'outbox_required');
        config(['audit.write_policy' => 'fail_closed']);

        try {
            return DB::transaction(function () use ($request, $next, $mutex): Response {
                DB::table('aggregate_mutexes')->insertOrIgnore([
                    'mutex_key' => $mutex,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('aggregate_mutexes')->where('mutex_key', $mutex)->lockForUpdate()->firstOrFail();

                return $next($request);
            }, 3);
        } finally {
            config(['audit.write_policy' => $previousPolicy]);
        }
    }

    private function mutexFor(Request $request): ?string
    {
        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        $path = '/'.ltrim($request->path(), '/');
        if (str_starts_with($path, '/api/v1/skus')) {
            return 'WWHN172:SKU_GRAPH_AND_IDENTITY';
        }
        if (str_starts_with($path, '/api/v1/containers')) {
            return 'WWHN172:CONTAINER_GRAPH_AND_IDENTITY';
        }

        return null;
    }
}
