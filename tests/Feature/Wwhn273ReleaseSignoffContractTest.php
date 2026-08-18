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

    /**
     * AC-SRS-037 viết tiêu chí phát hành bằng chính chữ "deployment": bản phát
     * hành không được chứa capability legacy. Đó là hình thức duy nhất mà câu
     * "kiểm kê" trong AC3 của WWHN-72 có thể pass, nên gate phải chứng minh
     * absence trên runtime đã serve chứ không chỉ trong source.
     */
    public function test_release_workflow_proves_no_stocktake_surface_on_the_served_runtime(): void
    {
        $workflow = $this->readBaseFile(self::WORKFLOW);

        foreach ([
            '/api/v1/stocktake-sessions',
            '/api/v1/inventory-snapshots',
            '/api/v1/stock-adjustments',
            '/api/v1/reports/stocktake-variance',
            'apply-adjustment',
        ] as $probe) {
            $this->assertStringContainsString(
                $probe,
                $workflow,
                "Gate thiếu probe absence theo AC-SRS-037: {$probe}"
            );
        }

        $this->assertStringContainsString(
            'REQ-SRS-037 violated',
            $workflow,
            'Gate phải fail-closed khi bề mặt Stocktake được serve'
        );
        $this->assertStringContainsString(
            'stocktake-absence.txt',
            $workflow,
            'Kết quả absence phải được ghi thành evidence'
        );

        // Probe phải kỳ vọng đúng 404 (không phải "khác 200"): một catch-all trả
        // 200 hay một route trả 403 đều là vi phạm REQ-SRS-037.
        $this->assertMatchesRegularExpression(
            "/code.*!=.*'404'/s",
            $workflow,
            'Probe absence phải kỳ vọng đúng HTTP 404'
        );
    }

    /**
     * Mọi file evidence đều do một bước ở giữa job ghi ra, nên trước đây một run
     * đỏ không để lại gì và trạng thái đỏ không quan sát được từ artifact — đúng
     * chỗ mà DEV273-P1-01 phát sinh. Bước status luôn chạy là thứ đóng lỗ đó.
     */
    public function test_release_workflow_always_records_gate_status_even_when_red(): void
    {
        $workflow = $this->readBaseFile(self::WORKFLOW);

        $this->assertStringContainsString('Record gate status (always)', $workflow);
        $this->assertStringContainsString('gate-status.txt', $workflow);
        $this->assertStringContainsString('job_status=', $workflow);
        $this->assertStringContainsString('RED_NO_BACKEND_RELEASE_SIGNOFF', $workflow);

        $statusStep = mb_substr($workflow, (int) mb_strpos($workflow, 'Record gate status (always)'));
        $this->assertStringContainsString(
            'if: always()',
            mb_substr($statusStep, 0, 200),
            'Bước ghi trạng thái gate phải chạy kể cả khi job đỏ'
        );

        $this->assertStringContainsString(
            'node scripts/verify-wwhn-273-release-signoff.mjs',
            $workflow,
            'Gate phải chạy verifier pin/provenance/trung thực trạng thái'
        );
    }

    /**
     * RC pin là nơi exact run/artifact được truy xuất. Nếu nó lệch với workflow
     * thì checklist reviewer sẽ đòi một file mà gate không bao giờ ghi ra.
     */
    public function test_rc_pin_agrees_with_the_gate_and_names_the_observed_runs(): void
    {
        $pin = json_decode($this->readBaseFile('docs/evidence/WWHN-273/RC_PIN.json'), true);
        $this->assertIsArray($pin, 'RC_PIN.json phải là JSON hợp lệ');

        $workflow = $this->readBaseFile(self::WORKFLOW);
        $this->assertSame(
            self::WORKFLOW,
            $pin['gate']['workflow'] ?? null,
            'RC_PIN phải trỏ đúng workflow của gate'
        );

        foreach ($pin['gate']['required_artifact_files'] ?? [] as $file) {
            $this->assertStringContainsString(
                $file,
                $workflow,
                "RC_PIN đòi artifact {$file} nhưng gate không ghi file đó"
            );
        }

        $runs = $pin['gate']['observed_runs'] ?? [];
        $this->assertNotEmpty($runs, 'RC_PIN phải ghi lại run đã quan sát (DEV273-P1-01)');
        foreach ($runs as $run) {
            foreach (['run_id', 'sha', 'conclusion'] as $key) {
                $this->assertArrayHasKey($key, $run, "Run được ghi thiếu {$key}");
            }
        }

        foreach ($pin['owned_files'] ?? [] as $owned) {
            $this->assertFileExists(base_path($owned['path']));
        }
    }

    /**
     * Chốt chặn chống tái diễn: khi gate chưa xanh, task doc không được trình bày
     * như đã có bằng chứng phát hành.
     */
    public function test_task_document_cannot_claim_signoff_while_the_gate_is_red(): void
    {
        $pin = json_decode($this->readBaseFile('docs/evidence/WWHN-273/RC_PIN.json'), true);
        $doc = $this->readBaseFile(self::TASK_DOC);

        if (($pin['gate']['green_at_sha'] ?? null) === null) {
            $this->assertStringContainsString(
                'GATE_STATUS_AT_PIN=RED',
                $doc,
                'Gate chưa xanh thì task doc phải hiển thị đúng trạng thái đỏ'
            );
        } else {
            $this->assertStringNotContainsString(
                'GATE_STATUS_AT_PIN=RED',
                $doc,
                'Gate đã xanh thì marker đỏ phải được gỡ'
            );
        }

        $this->assertStringContainsString(
            'NON-CANDIDATE / DEV-ONLY',
            $doc,
            'Bằng chứng chạy tại chỗ phải được tách khỏi bằng chứng phát hành (DEV273-P2-01)'
        );
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
