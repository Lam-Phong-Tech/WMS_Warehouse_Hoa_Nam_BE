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

    #[OA\Get(path: '/api/v1/health', operationId: 'backend_health_v1', summary: 'Public readiness health check', tags: ['00. Platform'], security: [], responses: [new OA\Response(response: 200, description: 'Ready'), new OA\Response(response: 503, description: 'Degraded')])]
    public function show(): JsonResponse
    {
        $checks = [
            'app' => ['status' => 'ok'],
            'database' => $this->databaseCheck(),
            'migrations' => $this->migrationCheck(),
            'seed' => $this->seedCheck(),
        ];
        $healthy = collect($checks)->every(fn (array $check): bool => ($check['status'] ?? null) === 'ok');
        $payload = [
            'status' => $healthy ? 'ok' : 'degraded',
            'version' => (string) config('health.version'),
            'environment' => app()->environment(),
            'server_time' => now()->toIso8601String(),
            'request_id' => $this->currentRequestId(),
            'checks' => $checks,
        ];

        return $healthy
            ? $this->respondSuccess($payload, 'Backend health check passed.')
            : $this->respondError('Backend health check degraded.', $payload, 503, 'HEALTH_CHECK_DEGRADED');
    }

    private function databaseCheck(): array
    {
        try {
            DB::select('select 1');
            return ['status' => 'ok', 'driver' => DB::connection()->getDriverName()];
        } catch (Throwable) {
            return ['status' => 'fail'];
        }
    }

    private function migrationCheck(): array
    {
        try {
            $count = DB::table(config('database.migrations.table', 'migrations'))->count();
            return ['status' => $count > 0 ? 'ok' : 'fail', 'ran_count' => $count];
        } catch (Throwable) {
            return ['status' => 'fail', 'ran_count' => 0];
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
            return ['status' => collect($markers)->every() ? 'ok' : 'fail', 'markers' => $markers];
        } catch (Throwable) {
            return ['status' => 'fail', 'markers' => ['admin_user' => false, 'baseline_warehouse' => false, 'baseline_product' => false]];
        }
    }
}
