<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Throwable;

class HealthController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/health',
        operationId: 'backend_health',
        summary: 'Public backend health endpoint for environment, database, migration and seed baseline checks',
        tags: ['00. Platform'],
        security: [],
        responses: [
            new OA\Response(response: 200, description: 'Backend health check passed'),
            new OA\Response(response: 503, description: 'Backend health check degraded'),
        ]
    )]
    public function show(): JsonResponse
    {
        $checks = [
            'app' => [
                'status' => 'ok',
                'environment' => app()->environment(),
                'timezone' => config('app.timezone'),
            ],
            'database' => $this->databaseCheck(),
            'migrations' => $this->migrationCheck(),
            'seed' => $this->seedCheck(),
        ];

        $healthy = collect($checks)->every(fn (array $check): bool => ($check['status'] ?? null) === 'ok');

        $payload = [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ];

        if (! $healthy) {
            return $this->respondError(
                'Backend health check degraded.',
                $payload,
                503,
                'HEALTH_CHECK_DEGRADED'
            );
        }

        return $this->respondSuccess($payload, 'Backend health check passed.');
    }

    /**
     * Keep health output deployable: expose component status, never connection strings,
     * credentials, SQL errors, paths, stack traces or hostnames.
     */
    private function databaseCheck(): array
    {
        try {
            DB::select('select 1');

            return [
                'status' => 'ok',
                'connection' => DB::connection()->getDriverName(),
            ];
        } catch (Throwable) {
            return [
                'status' => 'fail',
                'connection' => config('database.default'),
            ];
        }
    }

    private function migrationCheck(): array
    {
        try {
            $ranCount = DB::table(config('database.migrations.table', 'migrations'))->count();

            return [
                'status' => $ranCount > 0 ? 'ok' : 'fail',
                'ran_count' => $ranCount,
            ];
        } catch (Throwable) {
            return [
                'status' => 'fail',
                'ran_count' => 0,
            ];
        }
    }

    private function seedCheck(): array
    {
        try {
            $markers = [
                'admin_user' => DB::table('app_user')->where('username', 'admin')->exists(),
                'baseline_warehouse' => DB::table('warehouses')->where('code', 'KHO-TONG-HN')->exists(),
                'baseline_product' => DB::table('products')->where('code', 'HN-PV-BASE-001')->exists(),
            ];

            return [
                'status' => collect($markers)->every() ? 'ok' : 'fail',
                'markers' => $markers,
            ];
        } catch (Throwable) {
            return [
                'status' => 'fail',
                'markers' => [
                    'admin_user' => false,
                    'baseline_warehouse' => false,
                    'baseline_product' => false,
                ],
            ];
        }
    }
}
