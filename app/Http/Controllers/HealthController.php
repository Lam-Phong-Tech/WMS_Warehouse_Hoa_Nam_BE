<?php

namespace App\Http\Controllers;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use OpenApi\Attributes as OA;
use Throwable;

class HealthController extends Controller
{
    use ApiResponse;

    /**
     * Check nào bắt buộc phải 'ok' để readiness trả 200 — 'cache' CỐ Ý không nằm
     * trong danh sách này (mục 25): application cache là optional dependency, có
     * fail-open xuống PostgreSQL khi Valkey lỗi (App\Support\Cache\CacheService).
     * Biến 'cache' thành hard gate ở đây sẽ khiến liveness/readiness restart loop
     * chỉ vì Valkey tạm thời không kết nối được, dù API vẫn phục vụ được từ DB.
     *
     * @var array<int, string>
     */
    private const CRITICAL_CHECKS = ['app', 'database', 'migrations', 'seed'];

    #[OA\Get(path: '/api/v1/health', operationId: 'backend_health_v1', summary: 'Public readiness health check', tags: ['00. Platform'], security: [], responses: [new OA\Response(response: 200, description: 'Ready'), new OA\Response(response: 503, description: 'Degraded')])]
    public function show(): JsonResponse
    {
        $checks = [
            'app' => ['status' => 'ok'],
            'database' => $this->databaseCheck(),
            'migrations' => $this->migrationCheck(),
            'seed' => $this->seedCheck(),
            'cache' => $this->cacheCheck(),
        ];
        $healthy = collect($checks)
            ->only(self::CRITICAL_CHECKS)
            ->every(fn (array $check): bool => ($check['status'] ?? null) === 'ok');
        $payload = [
            'status' => $healthy ? 'ok' : 'degraded',
            'version' => (string) config('health.version'),
            // WWHN-295: danh tính artifact đang chạy, để QA/DevOps buộc bằng chứng
            // vào đúng SHA đã deploy thay vì tin lời khai. Không phải secret —
            // chỉ commit SHA/ref/thời điểm build, không lộ cấu hình hay đường dẫn.
            'build' => [
                'sha' => config('health.build.sha'),
                'ref' => config('health.build.ref'),
                'built_at' => config('health.build.built_at'),
            ],
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

    /**
     * Diagnostic riêng cho Valkey/Redis (cache store) — KHÔNG gate readiness (xem
     * CRITICAL_CHECKS). status 'degraded' nghĩa là Valkey không kết nối được nhưng
     * API vẫn hoạt động qua fail-open; 'skipped' khi CACHE_STORE không phải redis
     * (vd. test dùng driver 'array').
     */
    private function cacheCheck(): array
    {
        $store = (string) config('cache.default');
        if ($store !== 'redis') {
            return ['status' => 'skipped', 'store' => $store];
        }

        try {
            $pong = Redis::connection('cache')->ping();

            return ['status' => 'ok', 'store' => 'redis', 'ping' => is_string($pong) ? $pong : (string) $pong];
        } catch (Throwable) {
            return ['status' => 'degraded', 'store' => 'redis', 'note' => 'application cache unavailable — fail-open to database'];
        }
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
