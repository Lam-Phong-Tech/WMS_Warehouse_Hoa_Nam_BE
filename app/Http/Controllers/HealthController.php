<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
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

        $response = $healthy
            ? $this->respondSuccess($payload, 'Backend health check passed.')
            : $this->respondError('Backend health check degraded.', $payload, 503, 'HEALTH_CHECK_DEGRADED');

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
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
        // Use canonical Eloquent models. Compatibility VIEW availability must not
        // influence readiness, and mutable presentation flags are not seed identity.
        $markers = [
            'admin_user' => $this->safeExists(fn (): bool => User::query()
                ->where('username', 'admin')
                ->where('status', 'ACTIVE')
                ->exists()),
            'baseline_warehouse' => $this->safeExists(fn (): bool => Warehouse::query()
                ->where('code', 'KHO-TONG-HN')
                ->where('status', 'ACTIVE')
                ->exists()),
            'baseline_product' => $this->safeExists(fn (): bool => Sku::query()
                ->where('sku_code', 'HN-PV-BASE-001')
                ->where('status', 'ACTIVE')
                ->exists()),
        ];

        return [
            'status' => collect($markers)->every(fn (bool $value): bool => $value) ? 'ok' : 'fail',
            'markers' => $markers,
        ];
    }

    private function safeExists(callable $probe): bool
    {
        try {
            return (bool) $probe();
        } catch (Throwable) {
            return false;
        }
    }
}
