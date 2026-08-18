<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Models\RefreshToken;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * WWHN-295 — BE runtime/config/health.
 *
 * Những khiếm khuyết được khoá ở đây đều là loại "chạy vẫn 200": không test nào
 * của tầng nghiệp vụ nhìn thấy, chỉ lộ ra trên môi trường đã deploy.
 *
 *  - Không khai trusted proxy: mọi request đi qua nginx nên $request->ip() trả về
 *    IP container proxy. Đó chính là giá trị RefreshTokenService ghi vào
 *    refresh_tokens.ip_address — trường bằng chứng phiên đăng nhập trở nên vô nghĩa,
 *    và $request->secure() sai sau TLS terminator.
 *  - /api/v1/health không công bố danh tính artifact: mọi gate exact-SHA phải tin
 *    lời khai vì không đọc được commit đang chạy từ chính backend.
 *  - retry_after của queue bằng timeout của worker: job chạy sát ngưỡng bị phát lại
 *    cho worker thứ hai -> audit outbox / reconcile tồn kho chạy hai lần.
 */
class Wwhn295RuntimeConfigHealthTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE_URI = 'wwhn295-runtime-probe';

    protected function setUp(): void
    {
        parent::setUp();

        Route::get(self::PROBE_URI, fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]));
    }

    public function test_forwarded_headers_from_a_trusted_proxy_resolve_the_real_client(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.5'])
            ->getJson(self::PROBE_URI, [
                'X-Forwarded-For' => '203.0.113.9, 10.10.0.7',
                'X-Forwarded-Proto' => 'https',
            ])
            ->assertOk()
            ->assertJsonPath('data.ip', '203.0.113.9')
            ->assertJsonPath('data.secure', true);
    }

    /**
     * Chuỗi X-Forwarded-For do client public tự bịa phải bị bỏ qua: peer trực tiếp
     * không nằm trong dải private nên không có hop nào được tin.
     */
    public function test_forged_forwarded_headers_from_an_untrusted_peer_are_ignored(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77'])
            ->getJson(self::PROBE_URI, [
                'X-Forwarded-For' => '10.0.0.1',
                'X-Forwarded-Proto' => 'https',
            ])
            ->assertOk()
            ->assertJsonPath('data.ip', '198.51.100.77')
            ->assertJsonPath('data.secure', false);
    }

    public function test_refresh_token_evidence_records_the_real_client_ip_behind_the_proxy(): void
    {
        $user = User::create([
            'username' => 'wwhn295_proxy',
            'display_name' => 'WWHN295 Proxy',
            'email' => 'wwhn295_proxy@wms-hoanam.test',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '172.20.0.5'])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'secret123',
            ], ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk();

        $this->assertSame(
            '203.0.113.9',
            (string) RefreshToken::query()->where('user_id', $user->user_id)->value('ip_address'),
            'refresh_tokens.ip_address phải là IP người dùng, không phải IP của reverse proxy.'
        );
    }

    public function test_trusted_proxy_allow_list_never_covers_public_addresses(): void
    {
        $proxies = (string) config('trustedproxy.proxies');

        $this->assertNotSame('', trim($proxies), 'Thiếu allow-list thì X-Forwarded-* bị bỏ qua hoàn toàn.');
        $this->assertNotSame('*', trim($proxies), 'Tin mọi hop cho phép client public tự khai IP.');

        foreach (array_map(trim(...), explode(',', $proxies)) as $entry) {
            $this->assertMatchesRegularExpression(
                '/^(127\.0\.0\.1|::1|10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.|fc00::|fd)/i',
                $entry,
                "Trusted proxy {$entry} không thuộc dải loopback/private — client public có thể giả mạo X-Forwarded-For."
            );
        }
    }

    public function test_health_publishes_the_running_build_identity(): void
    {
        config([
            'health.version' => '1.12.0-test',
            'health.build.sha' => '0123456789abcdef0123456789abcdef01234567',
            'health.build.ref' => 'm1',
            'health.build.built_at' => '2026-08-18T09:00:00+07:00',
        ]);
        $this->seed(DatabaseSeeder::class);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.version', '1.12.0-test')
            ->assertJsonPath('data.build.sha', '0123456789abcdef0123456789abcdef01234567')
            ->assertJsonPath('data.build.ref', 'm1')
            ->assertJsonPath('data.build.built_at', '2026-08-18T09:00:00+07:00');
    }

    public function test_health_reports_build_identity_as_null_instead_of_a_fabricated_sha(): void
    {
        config(['health.build.sha' => null, 'health.build.ref' => null, 'health.build.built_at' => null]);
        $this->seed(DatabaseSeeder::class);

        $response = $this->getJson('/api/v1/health')->assertOk();

        $this->assertNull($response->json('data.build.sha'));
        $this->assertArrayHasKey('build', (array) $response->json('data'));
    }

    public function test_queue_retry_window_is_wider_than_the_worker_timeout(): void
    {
        $workerTimeout = (int) config('queue.worker_timeout');
        $this->assertGreaterThan(0, $workerTimeout);

        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            $this->assertGreaterThan(
                $workerTimeout,
                (int) config("queue.connections.{$connection}.retry_after"),
                "retry_after của {$connection} phải lớn hơn worker timeout, nếu không job chạy sát ngưỡng bị phát lại cho worker thứ hai."
            );
        }
    }

    public function test_golive_check_fails_when_the_queue_retry_window_is_too_narrow(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.worker_timeout' => 90,
            'queue.connections.redis.retry_after' => 90,
        ]);

        $path = storage_path('app/testing/wwhn-295-golive-check.json');
        File::delete($path);

        $this->artisan('wms:golive-check', ['--json' => $path])->run();

        $payload = json_decode((string) File::get($path), true);
        $this->assertSame('fail', $payload['checks']['runtime.queue_retry_window']['status']);
        $this->assertSame(90, $payload['checks']['runtime.queue_retry_window']['detail']['retry_after']);

        File::delete($path);
    }

    public function test_golive_check_reports_build_identity_dimension(): void
    {
        config(['health.build.sha' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef']);

        $path = storage_path('app/testing/wwhn-295-build-identity.json');
        File::delete($path);

        $this->artisan('wms:golive-check', ['--json' => $path])->run();

        $payload = json_decode((string) File::get($path), true);
        $this->assertSame('ok', $payload['checks']['release.build_identity']['status']);
        $this->assertSame(
            'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            $payload['checks']['release.build_identity']['detail']['sha']
        );

        File::delete($path);
    }

    /**
     * Lock của Cache::lock() thuộc application cache nên phải nằm cùng Valkey DB
     * với cache (DB 1). Mặc định 'default' của Laravel đẩy chúng sang DB 0 — nơi
     * .env.example và ClearWmsCache khẳng định chỉ có session/queue.
     */
    public function test_cache_locks_share_the_application_cache_database(): void
    {
        $this->assertSame('cache', config('cache.stores.redis.lock_connection'));
        $this->assertSame('cache', config('cache.stores.redis.connection'));
        $this->assertNotSame(
            config('database.redis.default.database'),
            config('database.redis.cache.database'),
            'Cache và session/queue phải nằm ở hai Valkey DB khác nhau.'
        );
    }

    /**
     * nginx là proxy trong cùng. Với FastCGI nó không tự sinh X-Forwarded-* nào,
     * nên nếu thiếu bốn fastcgi_param dưới đây thì trusted proxy phía Laravel
     * không có gì để đọc và $request->ip() vẫn là IP proxy.
     */
    public function test_nginx_forwards_the_proxy_headers_to_php_fpm(): void
    {
        $conf = (string) File::get(base_path('docker/nginx/default.conf'));

        $this->assertStringContainsString('fastcgi_param HTTP_X_FORWARDED_FOR   $proxy_add_x_forwarded_for;', $conf);
        $this->assertStringContainsString('fastcgi_param HTTP_X_FORWARDED_PROTO $wms_forwarded_proto;', $conf);
        $this->assertStringContainsString('fastcgi_param HTTP_X_FORWARDED_HOST  $wms_forwarded_host;', $conf);
        $this->assertStringContainsString('fastcgi_param HTTP_X_FORWARDED_PORT  $wms_forwarded_port;', $conf);

        // Giá trị do TLS terminator bên ngoài gửi phải được giữ nguyên, chỉ sinh
        // giá trị thay thế khi header trống.
        $this->assertStringContainsString('map $http_x_forwarded_proto $wms_forwarded_proto', $conf);
    }

    public function test_queue_worker_container_reads_the_same_timeout_as_the_retry_window(): void
    {
        $compose = (string) File::get(base_path('docker-compose.yml'));

        $this->assertStringContainsString('--timeout=${QUEUE_WORKER_TIMEOUT:-90}', $compose);
        $this->assertStringContainsString('QUEUE_WORKER_TIMEOUT=${QUEUE_WORKER_TIMEOUT:-90}', $compose);
    }

    public function test_env_example_documents_the_runtime_contract(): void
    {
        $env = (string) File::get(base_path('.env.example'));

        foreach (['TRUSTED_PROXIES=', 'APP_BUILD_SHA=', 'QUEUE_WORKER_TIMEOUT='] as $key) {
            $this->assertStringContainsString($key, $env, "{$key} phải được khai trong .env.example để DevOps biết cần inject.");
        }
    }
}
