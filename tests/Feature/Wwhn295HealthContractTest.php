<?php

namespace Tests\Feature;

use App\Http\Middleware\NoStoreApiResponses;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Models\User;
use Tests\TestCase;

/**
 * WWHN-295 / DEV295-P1-02 — khoá contract công bố ↔ runtime của `/api/v1/health`.
 *
 * Register 73/100 ghi: runtime đã thêm `data.build.*` nhưng OpenAPI chưa khai schema
 * tường minh, và Postman không cùng revision. Đó không phải lỗi hình thức:
 *
 *  - `docs/postman/... - DEV.postman_collection.json` trước commit này gọi
 *    `{{baseUrl}}/api/health` — route KHÔNG tồn tại (routes/api.php chỉ đăng ký
 *    `v1/health`). QA chạy collection sẽ nhận 404 và không thấy bất kỳ trường
 *    readiness nào, trong khi báo cáo vẫn ghi "đã chạy health".
 *  - Spec cũ trỏ vào envelope chung nên không nói được `build.sha` nằm ở đâu, và
 *    publish cả 401/403/412/428 cho một endpoint public không auth, không If-Match.
 *
 * Các test dưới đây so KHOÁ theo hai chiều: mọi khoá runtime phải có trong schema,
 * và mọi khoá required của schema phải có trong runtime. Sửa một bên mà quên bên kia
 * là đỏ ngay, không chờ tới môi trường deploy.
 */
class Wwhn295HealthContractTest extends TestCase
{
    use RefreshDatabase;

    private const OPENAPI_PATH = 'storage/api-docs/api-docs.json';

    private const POSTMAN_PATH = 'docs/postman/WMS_HoaNam API Contract - DEV.postman_collection.json';

    /** Trường bắt buộc theo §4 WWHN-295: thiếu bất kỳ trường nào là contract vỡ. */
    private const REQUIRED_PAYLOAD_FIELDS = ['status', 'version', 'build', 'environment', 'server_time', 'request_id', 'checks'];

    public function test_openapi_publishes_only_the_two_outcomes_health_can_actually_return(): void
    {
        $operation = $this->healthOperation();

        $this->assertSame(
            [200, 503],
            array_keys($operation['responses']),
            'Health là endpoint public không auth và không If-Match: baseline 401/403/412/428 là contract sai, '
            .'buộc monitor/QA phải đoán trạng thái nào có thật.'
        );
        $this->assertTrue(
            $operation['x-exhaustive-responses'] ?? false,
            'Cờ này là thứ giữ cho RouteSyncProcessor không tiêm lại baseline error ở lần regenerate sau.'
        );
        $this->assertSame([], $operation['security'], 'Readiness phải gọi được khi chưa đăng nhập.');
        $this->assertSame(
            '#/components/schemas/HealthReadyEnvelope',
            $operation['responses']['200']['content']['application/json']['schema']['$ref'] ?? null
        );
        $this->assertSame(
            '#/components/schemas/HealthDegradedEnvelope',
            $operation['responses']['503']['content']['application/json']['schema']['$ref'] ?? null
        );

        foreach ([200, 503] as $status) {
            $this->assertArrayHasKey(
                'Cache-Control',
                $operation['responses'][$status]['headers'] ?? [],
                "Response {$status} phai khai Cache-Control: readiness phục vụ từ cache trung gian là bằng chứng giả."
            );
        }
    }

    public function test_ready_payload_matches_the_published_schema_in_both_directions(): void
    {
        config([
            'health.version' => '1.12.0-test',
            'health.build.sha' => '0123456789abcdef0123456789abcdef01234567',
            'health.build.ref' => 'refs/heads/m1',
            'health.build.built_at' => '2026-08-18T09:00:00+07:00',
        ]);
        $this->seed(DatabaseSeeder::class);

        $response = $this->getJson('/api/v1/health')->assertOk();
        $payload = (array) $response->json('data');

        $this->assertSame('ok', $payload['status']);
        $this->assertPayloadMatchesSchema($payload, 'HealthPayload');
        $this->assertSame(
            NoStoreApiResponses::CACHE_CONTROL,
            $response->headers->get('Cache-Control')
        );
        $this->assertSame($response->json('meta.request_id'), $payload['request_id'], 'request_id trong payload phải là chính request_id của envelope, nếu không log không nối được.');
    }

    /**
     * 503 dùng respondError() nên payload nằm ở `errors` và `error.details`, KHÔNG ở
     * `data`. Client nào đọc `data` ở nhánh này sẽ luôn thấy undefined — đúng kiểu alert
     * im lặng giữa lúc hệ thống degraded. Contract phải nói thẳng điều đó.
     */
    public function test_degraded_payload_lives_in_errors_and_error_details_not_in_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        User::query()->where('username', 'admin')->update(['status' => 'INACTIVE']);

        $response = $this->getJson('/api/v1/health')->assertStatus(503);
        $body = (array) $response->json();

        $this->assertArrayNotHasKey('data', $body, 'Nhánh 503 không có khoá data — schema HealthDegradedEnvelope phải phản ánh đúng.');
        $this->assertSame('HEALTH_CHECK_DEGRADED', $body['error_code']);
        $this->assertSame($body['error_code'], $body['error']['code']);

        $payload = (array) $body['errors'];
        $this->assertSame('degraded', $payload['status']);
        $this->assertSame($payload, (array) $body['error']['details'], 'errors và error.details phải là cùng một payload.');
        $this->assertPayloadMatchesSchema($payload, 'HealthPayload');
        $this->assertSame('fail', $payload['checks']['seed']['status']);
        $this->assertSame(
            NoStoreApiResponses::CACHE_CONTROL,
            $response->headers->get('Cache-Control')
        );
    }

    /**
     * Valkey chết KHÔNG được kéo readiness xuống 503: CacheService fail-open xuống
     * PostgreSQL, biến cache thành hard gate là restart loop liveness/readiness trong
     * khi API vẫn phục vụ được. Schema nói vậy thì runtime phải làm đúng vậy.
     */
    public function test_cache_diagnostics_never_gate_readiness(): void
    {
        $this->seed(DatabaseSeeder::class);

        $payload = (array) $this->getJson('/api/v1/health')->assertOk()->json('data');

        $this->assertContains($payload['checks']['cache']['status'], ['ok', 'degraded', 'skipped']);
        $schema = $this->schema('HealthCacheCheck');
        $this->assertSame(
            ['ok', 'degraded', 'skipped'],
            $schema['properties']['status']['enum'],
            'Enum trong spec phải trùng tập trạng thái runtime của cacheCheck().'
        );
    }

    public function test_postman_collection_ships_the_canonical_health_request_at_the_same_revision(): void
    {
        $collection = $this->readJsonFile(self::POSTMAN_PATH);

        $platform = null;
        foreach ($collection['item'] as $folder) {
            if (($folder['name'] ?? '') === '00. Platform') {
                $platform = $folder;
            }
        }
        $this->assertNotNull($platform, 'Collection DEV phải có thư mục 00. Platform.');

        $request = $platform['item'][0]['request'];
        $this->assertSame(['api', 'v1', 'health'], $request['url']['path'], 'Đường dẫn phải là /api/v1/health — /api/health không tồn tại trong routes/api.php.');
        $this->assertSame('GET', $request['method']);
        $this->assertSame(
            [],
            $this->collectPaths($collection['item'], static fn (array $path): bool => $path === ['api', 'health']),
            'Không được sót request nào trỏ vào path legacy /api/health — route đó không tồn tại.'
        );

        $script = implode("\n", $platform['item'][0]['event'][0]['script']['exec']);
        foreach (self::REQUIRED_PAYLOAD_FIELDS as $field) {
            $this->assertStringContainsString("'{$field}'", $script, "Test script Postman phải kiểm trường {$field}.");
        }
        $this->assertStringContainsString('expectedBuildSha', $script, 'Bằng chứng QA phải buộc được vào exact candidate SHA.');
        $this->assertStringContainsString('HEALTH_CHECK_DEGRADED', $script);

        $codes = array_map(static fn (array $example): int => (int) $example['code'], $platform['item'][0]['response']);
        $this->assertSame([200, 503], $codes, 'Hai ví dụ response phải phủ đúng hai kết cục thật của endpoint.');
    }

    /**
     * Duyệt đệ quy mọi request trong collection và trả về các url.path thoả điều kiện.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<int, string>>
     */
    private function collectPaths(array $items, callable $matches): array
    {
        $found = [];

        foreach ($items as $item) {
            if (isset($item['item']) && is_array($item['item'])) {
                $found = array_merge($found, $this->collectPaths($item['item'], $matches));

                continue;
            }

            $path = $item['request']['url']['path'] ?? null;
            if (is_array($path) && $matches($path)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * Khoá theo hai chiều giữa payload runtime và schema đã publish.
     */
    private function assertPayloadMatchesSchema(array $payload, string $schemaName): void
    {
        $schema = $this->schema($schemaName);
        $properties = $schema['properties'] ?? [];

        foreach (array_keys($payload) as $key) {
            $this->assertArrayHasKey(
                $key,
                $properties,
                "Runtime trả khoá `{$key}` mà schema {$schemaName} không khai — spec đang nói dối client."
            );
        }

        foreach ($schema['required'] ?? [] as $required) {
            $this->assertArrayHasKey(
                $required,
                $payload,
                "Schema {$schemaName} khai `{$required}` là bắt buộc nhưng runtime không trả."
            );
        }

        foreach ($properties as $name => $property) {
            if (! array_key_exists($name, $payload) || ! is_array($payload[$name])) {
                continue;
            }

            if (isset($property['$ref'])) {
                $this->assertPayloadMatchesSchema($payload[$name], $this->refName($property['$ref']));

                continue;
            }

            // Object khai inline (vd. checks.seed.markers) vẫn phải phủ đúng khoá runtime.
            if (($property['type'] ?? null) === 'object' && isset($property['properties'])) {
                foreach (array_keys($payload[$name]) as $key) {
                    $this->assertArrayHasKey($key, $property['properties'], "Khoá `{$name}.{$key}` không có trong schema {$schemaName}.");
                }
            }
        }
    }

    private function healthOperation(): array
    {
        $spec = $this->readJsonFile(self::OPENAPI_PATH);

        $this->assertArrayHasKey('/api/v1/health', $spec['paths'], 'Spec đã publish phải chứa endpoint readiness.');

        return $spec['paths']['/api/v1/health']['get'];
    }

    private function schema(string $name): array
    {
        $spec = $this->readJsonFile(self::OPENAPI_PATH);

        $this->assertArrayHasKey($name, $spec['components']['schemas'], "Thiếu component schema {$name} trong spec đã publish (chạy lại php artisan l5-swagger:generate).");

        return $spec['components']['schemas'][$name];
    }

    private function refName(string $ref): string
    {
        return (string) substr($ref, (int) strrpos($ref, '/') + 1);
    }

    private function readJsonFile(string $relativePath): array
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
