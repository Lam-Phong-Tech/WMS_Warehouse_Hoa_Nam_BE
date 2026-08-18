<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * WWHN-273 — [BE] Kiểm tra migration/smoke backend (subtask của WWHN-72).
 *
 * Gate exact-SHA của WWHN-273 nằm trong một file workflow. File đó có thể bị sửa
 * mất một bước mà không test nào đỏ, và khi đó "release sign-off" vẫn xanh trong
 * khi thứ nó hứa chứng minh đã biến mất. Test này khoá HỢP ĐỒNG của gate:
 * migration/seed trên PostgreSQL, diễn tập rollback (theo đúng yêu cầu của task
 * cha WWHN-72), golive-check, smoke HTTP trên runtime đã serve và ranh giới
 * "BE không tự nhận sign-off của QA1/DevOps".
 *
 * Hai khẳng định runtime đi kèm (health canonical + default-deny) được kiểm ở
 * đây bằng in-process request, còn workflow kiểm lại chính hai điều đó qua HTTP
 * thật — trùng nhau là cố ý: một cái chặn regression lúc dev, một cái chứng minh
 * runtime đã deploy.
 */
class Wwhn273ReleaseSignoffContractTest extends TestCase
{
    use RefreshDatabase;

    private const WORKFLOW = '.github/workflows/wwhn-273-backend-release-signoff.yml';

    private const TASK_DOC = 'docs/docs_task_jira/WWHN-273.md';

    public function test_release_workflow_pins_the_exact_sha_migration_and_rollback_drill(): void
    {
        $workflow = $this->readBaseFile(self::WORKFLOW);

        foreach ([
            'branches: [m1]' => 'gate phải chạy trên nhánh m1',
            'image: postgres:16' => 'migration gate phải chạy trên PostgreSQL, không phải sqlite',
            'php artisan migrate:fresh --seed --force' => 'migration + seed trên DB sạch',
            'php artisan migrate:reset --force' => 'bước gỡ toàn bộ chain (diễn tập rollback)',
            'php artisan migrate --force' => 'bước migrate tiến lại sau rollback',
            'php artisan db:seed --force' => 'bước seed lại sau rollback',
            'php artisan wms:golive-check' => 'kiểm tra readiness trước cutover',
            'php artisan l5-swagger:generate' => 'đồng bộ OpenAPI theo đúng SHA',
            'actions/upload-artifact@v4' => 'evidence phải được upload theo SHA',
            '${{ github.sha }}' => 'evidence phải gắn với exact SHA',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $workflow, "WWHN-273 gate thiếu: {$why} ({$needle})");
        }
    }

    public function test_release_workflow_runs_the_focused_suite_including_this_contract(): void
    {
        $workflow = $this->readBaseFile(self::WORKFLOW);

        foreach ([
            'tests/Feature/BackendMigrationSeedHealthTest.php',
            'tests/Feature/AuthSessionPersistenceTest.php',
            'tests/Feature/AuthorizationTest.php',
            'tests/Feature/SwaggerRouteSyncTest.php',
            'tests/Feature/Wwhn273ReleaseSignoffContractTest.php',
        ] as $testFile) {
            $this->assertStringContainsString($testFile, $workflow, "WWHN-273 gate không chạy {$testFile}");
            $this->assertFileExists(base_path($testFile));
        }
    }

    public function test_release_workflow_smokes_the_served_runtime_and_rejects_the_legacy_health_path(): void
    {
        $workflow = $this->readBaseFile(self::WORKFLOW);

        $this->assertStringContainsString('php artisan serve', $workflow, 'Thiếu smoke trên runtime đã serve');

        // RefreshDatabase trong suite phía trên chạy trên chính DB của job và để
        // lại DB đã migrate nhưng CHƯA seed. Không nạp lại trạng thái phát hành
        // thì health trả 503 và smoke đăng nhập không có identity để dùng.
        $smokeStep = mb_substr($workflow, (int) mb_strpos($workflow, 'Served runtime smoke'));
        $this->assertStringContainsString(
            'php artisan migrate:fresh --seed --force',
            mb_substr($smokeStep, 0, (int) mb_strpos($smokeStep, 'php artisan serve')),
            'Bước smoke phải nạp lại migrate+seed trước khi serve'
        );
        $this->assertStringContainsString('/api/v1/health', $workflow);
        $this->assertStringContainsString('/api/health', $workflow, 'Smoke phải chứng minh path legacy trả 404');
        $this->assertMatchesRegularExpression(
            '/legacy_code.*!=.*404/s',
            $workflow,
            'Smoke phải fail khi /api/health vẫn được serve'
        );
        $this->assertMatchesRegularExpression(
            '/protected_code.*!=.*401/s',
            $workflow,
            'Smoke phải fail khi endpoint được bảo vệ không trả 401'
        );
    }

    public function test_release_workflow_smokes_auth_master_inbound_outbound_and_inventory(): void
    {
        $workflow = $this->readBaseFile(self::WORKFLOW);

        $this->assertStringContainsString('/api/v1/auth/login', $workflow, 'WWHN-72 yêu cầu smoke auth');

        // Đúng bộ mặt nghiệp vụ mà AC của task cha liệt kê: master, inbound,
        // outbound, inventory (kiểm kê nằm ngoài MVP hiện tại, xem task doc).
        foreach ([
            '/api/v1/skus',
            '/api/v1/inbound-documents',
            '/api/v1/outbound-documents',
            '/api/v1/inventory/balances',
        ] as $path) {
            $this->assertStringContainsString($path, $workflow, "Smoke release thiếu {$path}");
        }

        $this->assertMatchesRegularExpression(
            '/code.*!=.*200/s',
            $workflow,
            'Smoke phải fail khi một endpoint nghiệp vụ không trả 200'
        );
    }

    public function test_smoked_endpoints_are_registered_routes(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->all();

        foreach ([
            'api/v1/auth/login',
            'api/v1/skus',
            'api/v1/inbound-documents',
            'api/v1/outbound-documents',
            'api/v1/inventory/balances',
            'api/v1/reports/hub',
        ] as $uri) {
            $this->assertContains($uri, $uris, "Smoke trỏ tới route không tồn tại: {$uri}");
        }
    }

    public function test_canonical_health_route_is_versioned_and_legacy_path_is_not_registered(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->all();

        $this->assertContains('api/v1/health', $uris, 'Health probe canonical /api/v1/health phải tồn tại');
        $this->assertNotContains('api/health', $uris, 'Path legacy /api/health không được đăng ký lại');

        // Health chỉ trả 200 khi seed marker có mặt (503 HEALTH_CHECK_DEGRADED nếu
        // thiếu) — đúng trạng thái sau bước migrate/seed của release gate.
        $this->seed(DatabaseSeeder::class);

        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.status', 'ok');
    }

    public function test_protected_endpoint_fails_closed_without_a_token(): void
    {
        $this->getJson('/api/v1/skus')->assertStatus(401);
    }

    public function test_task_document_keeps_the_release_truth_boundary(): void
    {
        $doc = $this->readBaseFile(self::TASK_DOC);

        foreach ([
            'WWHN-72',
            'Backend release sign-off',
            'WWHN-272',
            'WWHN-275',
            'P0',
            'P1',
            'P2',
        ] as $marker) {
            $this->assertStringContainsString($marker, $doc, "Task doc WWHN-273 thiếu mốc truy vết: {$marker}");
        }
    }

    private function readBaseFile(string $relativePath): string
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
