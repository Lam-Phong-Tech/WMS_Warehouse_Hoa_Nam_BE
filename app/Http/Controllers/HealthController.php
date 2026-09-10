<?php

namespace App\Http\Controllers;

use App\Http\Middleware\NoStoreApiResponses;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Modules\Auth\Models\User;
use Modules\Warehouse\Database\Seeders\DongChengAnchorSkus;
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

    /**
     * WWHN-295 / DEV295-P1-02 — contract của endpoint này được khai TƯỜNG MINH, không
     * để RouteSyncProcessor tự sinh baseline. Ba lý do, đều là hệ quả vận hành thật:
     *
     *  1. `data.build.*` là thứ duy nhất cho phép QA/DevOps buộc bằng chứng vào đúng
     *     commit đã deploy; envelope chung không mô tả được nó.
     *  2. Nhánh 503 đặt payload ở `errors`/`error.details` (respondError), không ở
     *     `data` — client suy diễn theo envelope chung sẽ đọc nhầm trường.
     *  3. Endpoint public không dùng auth/If-Match, nên baseline 401/403/412/428 là
     *     contract sai. `x-exhaustive-responses` báo processor giữ đúng 200/503.
     *
     * Schema thành phần: App\Swagger\HealthSchemas.
     */
    #[OA\Get(
        path: '/api/v1/health',
        operationId: 'backend_health_v1',
        summary: 'Public readiness health check',
        description: 'Readiness công khai (không auth). HTTP 200 khi toàn bộ critical check '
            .'(app, database, migrations, seed) ok; HTTP 503 + error_code HEALTH_CHECK_DEGRADED khi có check fail. '
            .'`checks.cache` là chẩn đoán Valkey và CỐ Ý không gate readiness (fail-open xuống PostgreSQL). '
            .'`data.build.sha` là danh tính artifact đang chạy, dùng cho mọi gate exact-SHA.',
        tags: ['00. Platform'],
        security: [],
        x: ['exhaustive-responses' => true],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Ready — mọi critical check ok. Payload health nằm ở `data`.',
                headers: [
                    new OA\Header(
                        header: 'Cache-Control',
                        description: 'Luôn no-store: readiness không được phục vụ từ cache trung gian.',
                        schema: new OA\Schema(type: 'string', example: 'no-store, private')
                    ),
                ],
                content: new OA\JsonContent(ref: '#/components/schemas/HealthReadyEnvelope')
            ),
            new OA\Response(
                response: 503,
                description: 'Degraded — ít nhất một critical check fail. Payload health nằm ở `errors` và `error.details`, KHÔNG ở `data`.',
                headers: [
                    new OA\Header(
                        header: 'Cache-Control',
                        description: 'Luôn no-store: readiness không được phục vụ từ cache trung gian.',
                        schema: new OA\Schema(type: 'string', example: 'no-store, private')
                    ),
                ],
                content: new OA\JsonContent(ref: '#/components/schemas/HealthDegradedEnvelope')
            ),
        ]
    )]
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

        // Cùng hằng số với NoStoreApiResponses (middleware ngoài cùng luôn ghi đè): hai
        // chuỗi khác nhau ở hai chỗ chính là cách contract công bố lệch khỏi header
        // client thực nhận — lỗi DEV295-P1-02 đang đóng.
        $response->headers->set('Cache-Control', NoStoreApiResponses::CACHE_CONTROL);
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
            // P0 10/09/2026: dấu hiệu là "có SUPER_ADMIN ACTIVE", không phải username
            // `admin` do seeder cũ tạo bằng mật khẩu hằng số. Môi trường mới nhận quản
            // trị viên đầu tiên qua `wms:bootstrap-super-admin` với username tuỳ chọn.
            'admin_user' => $this->safeExists(fn (): bool => User::query()
                ->where('status', 'ACTIVE')
                ->whereHas('roles', fn ($query) => $query->where('role.role_code', 'SUPER_ADMIN'))
                ->exists()),
            'baseline_warehouse' => $this->safeExists(fn (): bool => Warehouse::query()
                ->where('code', 'KHO-TONG-HN')
                ->where('status', 'ACTIVE')
                ->exists()),
            // Mã hàng nền của bộ seed. Từ 09/2026 là hàng THẬT DongCheng thay cho
            // mã demo cũ — đọc từ DongChengAnchorSkus để health check không trôi
            // khỏi seeder khi mã neo đổi.
            'baseline_product' => $this->safeExists(fn (): bool => Sku::query()
                ->where('sku_code', DongChengAnchorSkus::BASELINE_PRODUCT)
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
