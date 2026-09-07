<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-249 — cổng QUẢN TRỊ MẶT CÔNG KHAI của App PV.
 *
 * Bộ test này KHÔNG lặp lại kiểm thử nghiệp vụ của Wwhn252PvContractTest (list/
 * detail/recommendation/CRUD). Nó khoá đúng phần mà register WWHN-249 đòi:
 *
 * - DEV249-P1-03 "PV routes public, trong khi parent yêu cầu login/assignment/
 *   permission": chứng minh việc mở ẩn danh là CÓ CHỦ ĐÍCH và ĐÓNG KHUNG — tập
 *   route ẩn danh của hệ thống phải bằng ĐÚNG danh sách đã chốt; thêm bất kỳ
 *   route public nào mà không cập nhật hợp đồng sẽ làm test đỏ (fail-closed).
 * - DEV249-P1-04: nửa authenticated (`/pv-admin/resources/*`) bắt buộc bearer +
 *   `pv.master.manage`, và PATCH publish bắt buộc `pv.publish`.
 * - AC-SRS-018 "không stock effect": lưu lượng public không được đụng ledger.
 * - SRS §12: OpenAPI phải khai `security: []` cho đúng 4 operation public và
 *   bearerAuth cho phần còn lại — đây là thứ FE/QA đọc để wire.
 * - DEV249-P1-06: ma trận endpoint↔permission (nguồn handoff FE/QA) không được
 *   lệch khỏi route table thật.
 *
 * Nguồn hợp đồng: SRS v1.12 §3 (ma trận actor), §8.1 (nhóm PV), §12 (bảng
 * Security), Annex C (listPvProducts/getPvProduct/listPvIndustries/
 * listPvRecommendations/publishPvProduct/listPvAdminResources/
 * createPvAdminResource/updatePvAdminResource).
 */
class Wwhn249PvSurfaceGovernanceTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /**
     * MẶT CÔNG KHAI ĐÃ CHỐT của toàn hệ thống. Đây là hợp đồng bảo mật, không
     * phải danh sách tiện tay: mỗi dòng phải có nguồn trong SRS.
     *
     * @var array<string, string>
     */
    private const DECLARED_ANONYMOUS_ROUTES = [
        // Bootstrap phiên làm việc — không thể yêu cầu JWT để lấy JWT.
        'POST api/v1/auth/login' => 'SRS §8.1 IAM — cấp token',
        'POST api/v1/auth/refresh' => 'SRS §8.1 IAM — gia hạn token',
        'GET api/v1/health' => 'NFR §11 — health probe hạ tầng',
        // Quên mật khẩu bằng OTP email (WWHN — commit 368943c). Buộc phải ẩn
        // danh: người dùng mất mật khẩu thì theo định nghĩa không có JWT để gọi,
        // nên đây là bootstrap phiên giống login/refresh chứ không phải nới lỏng
        // gate. Bù lại cả ba bước đều không tiết lộ email có tồn tại hay không
        // (message/lỗi dùng chung) và hai bước đầu có limiter theo (IP + email).
        'POST api/v1/auth/forgot-password' => 'SRS §8.1 IAM — phát OTP đặt lại mật khẩu',
        'POST api/v1/auth/verify-otp' => 'SRS §8.1 IAM — xác thực OTP, cấp reset_token',
        'POST api/v1/auth/reset-password' => 'SRS §8.1 IAM — đặt mật khẩu mới bằng reset_token',
        // Annex C: 4 operation PUBLIC_ANONYMOUS của App PV.
        'GET api/v1/pv/products' => 'Annex C listPvProducts (security: [])',
        'GET api/v1/pv/products/{skuId}' => 'Annex C getPvProduct (security: [])',
        'GET api/v1/pv/industries' => 'Annex C listPvIndustries (security: [])',
        'GET api/v1/pv/recommendations' => 'Annex C listPvRecommendations (security: [])',
        // Alias lịch sử ngoài Annex C — cùng read model chỉ-đọc, giữ để client cũ
        // không vỡ. Gỡ hay giữ là quyết định của BA, không phải của DEV.
        'GET api/v1/pv/catalog' => 'Alias lịch sử của listPvProducts',
        'GET api/v1/pv/tags' => 'Alias lịch sử — tag public phục vụ bộ lọc',
        // Ảnh sản phẩm App PV (MST-PV-01). Bắt buộc ẩn danh: listPvProducts vốn là
        // mặt ẩn danh và trả `images[].url`, ảnh mà đòi token thì thẻ sản phẩm trên
        // App PV vẽ ra ô trống. Chỉ trả BYTES ẢNH trong đúng prefix ảnh PV và key
        // phải khớp `<uuid>.<jpg|jpeg|png|webp>`, nên không đọc được tệp nào khác
        // trong bucket; không JSON, không dữ liệu vận hành.
        'GET api/v1/pv/product-images/{key}' => 'SRS §15 OP-09 — ảnh của listPvProducts (security: [])',
        // Storefront công khai App PV — mặt API riêng, hợp đồng FE
        // `{VITE_PUBLIC_API_BASE_URL}/api/v1/public`. Chỉ trả sản phẩm
        // PUBLISHED + còn hàng và KHÔNG lộ số tồn, khác hẳn nhóm pv/* ở trên.
        'GET api/v1/public/config' => 'Storefront — cấu hình khởi động app',
        'GET api/v1/public/home' => 'Storefront — domain + section trang chủ',
        'GET api/v1/public/health/version' => 'Storefront — version hợp đồng + cờ bảo trì',
        'GET api/v1/public/categories' => 'Storefront — danh mục theo domain',
        'GET api/v1/public/facets' => 'Storefront — bộ lọc động',
        'GET api/v1/public/products' => 'Storefront — listing/search/filter/sort/cursor',
        'GET api/v1/public/products/{slug}' => 'Storefront — chi tiết sản phẩm theo slug',
        'GET api/v1/public/products/{slug}/related' => 'Storefront — sản phẩm liên quan',
        // Endpoint GHI ẩn danh duy nhất của mặt storefront (hệ thống còn nhóm
        // quên-mật-khẩu ở trên cũng ghi khi chưa đăng nhập). Được mở có chủ đích
        // vì người gửi yêu cầu tư vấn chưa có tài khoản; bù lại nó có limiter
        // riêng chặt hơn và bắt buộc Idempotency-Key.
        'POST api/v1/public/quote-requests' => 'Storefront — gửi yêu cầu tư vấn (idempotent)',
        // Trang tài liệu do l5-swagger đăng ký, không phải API nghiệp vụ.
        'GET api/documentation' => 'l5-swagger UI',
        'GET api/oauth2-callback' => 'l5-swagger UI',
    ];

    /** Ba operation của Annex C trên `/pv-admin/resources`. */
    private const PV_ADMIN_ROUTES = [
        'GET api/v1/pv-admin/resources/{resourceType}',
        'POST api/v1/pv-admin/resources/{resourceType}',
        'PATCH api/v1/pv-admin/resources/{resourceType}/{id}',
    ];

    /**
     * DEV249-P1-03 — fail-closed: nếu ai đó thêm một route API mà quên
     * `auth:api`, test này đỏ ngay, kể cả khi route đó chẳng liên quan tới PV.
     */
    public function test_anonymous_api_surface_matches_the_declared_contract(): void
    {
        $actual = $this->anonymousApiRoutes();
        $declared = array_keys(self::DECLARED_ANONYMOUS_ROUTES);

        sort($actual);
        sort($declared);

        $this->assertSame(
            $declared,
            $actual,
            "Mặt công khai đã đổi. Thêm route ẩn danh phải kèm nguồn SRS và cập nhật:\n".
            "  - Wwhn249PvSurfaceGovernanceTest::DECLARED_ANONYMOUS_ROUTES\n".
            "  - scripts/generate-endpoint-permission-matrix.mjs PUBLIC_ALLOWLIST\n".
            '  - docs/api-contract/HN-BE-API-003_ENDPOINT_PERMISSION_MATRIX.md'
        );
    }

    /**
     * SRS §12: nhóm PV ẩn danh phải có "rate-limit/anti-abuse controls" thì 429
     * trong error set Annex C mới là mã có thật.
     */
    public function test_every_anonymous_pv_route_is_rate_limited(): void
    {
        foreach ($this->anonymousApiRoutes() as $key) {
            if (! str_contains($key, 'api/v1/pv/')) {
                continue;
            }

            $this->assertContains(
                'throttle:pv-public',
                $this->middlewareOf($key),
                "Route công khai [{$key}] không nằm trong nhóm throttle:pv-public."
            );
        }
    }

    /**
     * Cùng lý do với test trên, áp cho mặt storefront `/api/v1/public/*`: mọi
     * route ẩn danh phải có limiter thì 429 công bố trong hợp đồng mới là mã có
     * thật. Endpoint GHI phải dùng limiter RIÊNG, chặt hơn nhóm đọc — dùng
     * chung ngưỡng đọc thì form gửi yêu cầu tư vấn ẩn danh thành cửa spam.
     */
    public function test_every_public_storefront_route_is_rate_limited(): void
    {
        $seen = 0;

        foreach ($this->anonymousApiRoutes() as $key) {
            if (! str_contains($key, 'api/v1/public/')) {
                continue;
            }

            $seen++;
            $middleware = $this->middlewareOf($key);
            $expected = str_starts_with($key, 'POST ') ? 'throttle:public-quote-request' : 'throttle:public-api';

            $this->assertContains(
                $expected,
                $middleware,
                "Route storefront [{$key}] phải nằm trong nhóm {$expected}."
            );
        }

        $this->assertGreaterThan(0, $seen, 'Không tìm thấy route storefront nào — kiểm tra lại việc nạp routes/public_api.php.');
    }

    /**
     * DEV249-P1-04 — nửa authenticated. `pv.master.manage` là quyền DUY NHẤT của
     * nhóm pv-admin theo Annex C; publish là quyền RIÊNG `pv.publish` theo §3 và
     * §12 ("PATCH publish remains bearer + pv.publish").
     */
    public function test_pv_admin_and_publish_routes_require_bearer_and_their_own_permission(): void
    {
        foreach (self::PV_ADMIN_ROUTES as $key) {
            $middleware = $this->middlewareOf($key);
            $this->assertContains('auth:api', $middleware, "[{$key}] thiếu auth:api.");
            $this->assertContains('permission:pv.master.manage', $middleware, "[{$key}] sai permission gate.");
        }

        $publish = $this->middlewareOf('PATCH api/v1/pv/products/{id}/publish');
        $this->assertContains('auth:api', $publish);
        $this->assertContains('permission:pv.publish', $publish, 'publish phải gate đúng một quyền pv.publish.');
        $this->assertNotContains(
            'permission:pv.publish|sku.manage',
            $publish,
            '§3 tách pv.publish khỏi sku.manage; nhánh OR làm việc tách quyền chỉ còn hình thức.'
        );
    }

    /** Không JWT -> 401; có JWT nhưng thiếu quyền -> 403. */
    public function test_pv_admin_rejects_anonymous_with_401(): void
    {
        $this->getJson('/api/v1/pv-admin/resources/industries')->assertStatus(401);
        $this->postJson('/api/v1/pv-admin/resources/industries', [])->assertStatus(401);
        $this->patchJson('/api/v1/pv-admin/resources/industries/'.Str::uuid(), [])->assertStatus(401);
    }

    /**
     * AC-SRS-018: "không lộ item/container/movement/cost; không stock effect".
     * Đây là phần "no stock side-effect" mà QA1 phải chứng minh (DEV249-P1-05):
     * chụp ledger trước/sau khi bắn toàn bộ lưu lượng public.
     */
    public function test_public_pv_traffic_has_no_stock_effect(): void
    {
        $headers = $this->superAdminHeaders();
        unset($headers['__user_id']);

        $sku = Sku::create([
            'code' => 'SKU-PV-249', 'name' => 'SKU governance PV', 'sku_type' => 'PRODUCT',
            'unit' => 'cai', 'is_serial_tracked' => false, 'status' => 'ACTIVE',
        ]);
        $this->patchJson("/api/v1/pv/products/{$sku->sku_id}/publish", ['published' => true], $headers + ['If-Match' => '0'])->assertOk();

        $before = $this->ledgerSnapshot();

        $this->getJson('/api/v1/pv/products')->assertOk();
        $this->getJson('/api/v1/pv/products/'.$sku->sku_id)->assertOk();
        $this->getJson('/api/v1/pv/industries')->assertOk();
        $this->getJson('/api/v1/pv/catalog')->assertOk();
        $this->getJson('/api/v1/pv/tags')->assertOk();

        $this->assertSame($before, $this->ledgerSnapshot(), 'Lưu lượng App PV công khai đã làm đổi ledger/tồn kho.');
    }

    /**
     * SRS §12 (bảng Security). Contract máy đọc là thứ FE/QA wire vào, nên nó
     * phải nói đúng cùng một điều với route table.
     */
    public function test_openapi_declares_anonymous_security_for_public_pv_and_bearer_for_the_rest(): void
    {
        $spec = $this->openApiSpec();

        foreach (['/api/v1/pv/products', '/api/v1/pv/products/{skuId}', '/api/v1/pv/industries', '/api/v1/pv/recommendations'] as $path) {
            $this->assertArrayHasKey($path, $spec['paths'], "OpenAPI thiếu operation public [{$path}].");
            $this->assertSame(
                [],
                $spec['paths'][$path]['get']['security'] ?? null,
                "[{$path}] phải override security: [] theo SRS §12."
            );
        }

        foreach ([
            '/api/v1/pv-admin/resources/{resourceType}' => ['get', 'post'],
            '/api/v1/pv-admin/resources/{resourceType}/{id}' => ['patch'],
            '/api/v1/pv/products/{id}/publish' => ['patch'],
        ] as $path => $methods) {
            foreach ($methods as $method) {
                $this->assertSame(
                    [['bearerAuth' => []]],
                    $spec['paths'][$path][$method]['security'] ?? null,
                    "[{$method} {$path}] phải yêu cầu bearerAuth."
                );
            }
        }

        // Annex C updatePvAdminResource khai If-Match; contract phải nói ra để FE
        // không đoán rồi ăn 428.
        $patchParameters = $spec['paths']['/api/v1/pv-admin/resources/{resourceType}/{id}']['patch']['parameters'] ?? [];
        $this->assertContains(
            'If-Match',
            array_column($patchParameters, 'name'),
            'updatePvAdminResource phải khai header If-Match trong OpenAPI.'
        );
    }

    /**
     * DEV249-P1-06 — nguồn handoff FE/QA. Allowlist trong generator là nơi DevOps
     * canh "route public mới"; nếu nó lệch route table thì gate mất tác dụng mà
     * không ai biết.
     */
    public function test_permission_matrix_allowlist_matches_the_route_table(): void
    {
        $source = file_get_contents(base_path('scripts/generate-endpoint-permission-matrix.mjs'));
        $this->assertIsString($source);

        preg_match('/const PUBLIC_ALLOWLIST = new Set\(\[(.*?)\]\);/s', $source, $matches);
        $this->assertNotEmpty($matches, 'Không đọc được PUBLIC_ALLOWLIST của generator.');

        preg_match_all('/"([A-Z]+) (\/api\/v1[^"]*|\/api\/[^"]*)"/', $matches[1], $entries, PREG_SET_ORDER);
        $allowlist = array_map(fn (array $entry): string => $entry[1].' '.ltrim($entry[2], '/'), $entries);

        $expected = array_values(array_filter(
            array_keys(self::DECLARED_ANONYMOUS_ROUTES),
            fn (string $key): bool => str_starts_with($key, 'GET api/v1/') || str_starts_with($key, 'POST api/v1/')
        ));

        sort($allowlist);
        sort($expected);
        $this->assertSame($expected, $allowlist, 'PUBLIC_ALLOWLIST của generator lệch với mặt công khai đã chốt.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<int, string> */
    private function anonymousApiRoutes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $requiresAuth = collect($route->gatherMiddleware())
                ->contains(fn ($item): bool => is_string($item) && str_starts_with($item, 'auth:'));
            if ($requiresAuth) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $routes[] = $method.' '.$uri;
            }
        }

        return array_values(array_unique($routes));
    }

    /** @return array<int, string> */
    private function middlewareOf(string $key): array
    {
        [$method, $uri] = explode(' ', $key, 2);

        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            }
        }

        $this->fail("Không tìm thấy route [{$key}].");
    }

    /** @return array<string, mixed> */
    private function ledgerSnapshot(): array
    {
        return [
            'stock_movements' => DB::table('stock_movements')->count(),
            'inventory_balances' => DB::table('inventory_balances')->count(),
            'available_total' => (float) DB::table('inventory_balances')->sum('available_qty'),
            'quantity_total' => (float) DB::table('inventory_balances')->sum('quantity'),
        ];
    }

    /**
     * D-04 (register WWHN-249) — SRS §3 xếp CẢ HAI dòng App PV ở mức A cho
     * "Quản lý kho":
     *   | Publish PV / Reports                                       | A | A | ... | pv.publish       |
     *   | PV Industry/Tag/Product Mapping/Recommendation Rule manage | A | A | ... | pv.master.manage |
     *
     * Seed baseline chỉ cấp `pv.master.manage` cho ADMIN, nên WAREHOUSE_MANAGER
     * bị 403 ở /pv-admin/resources dù ma trận quyền nói được. Test khoá cả hai
     * chiều: thiếu key thì đỏ, mà cấp nhầm cho vai chỉ-đọc (NV kho / lãnh đạo /
     * viewer) cũng đỏ — ma trận có cột V/D chứ không phải A cho họ.
     *
     * ─── CẬP NHẬT 06/09/2026: chỉ còn ADMIN. BA ĐÃ XÁC NHẬN 07/09/2026 ───
     *
     * Tài liệu BA 'taikhoantheorole_da_sua_v2' định nghĩa lại WAREHOUSE_MANAGER là vai
     * GIÁM SÁT: ma trận đánh ❌ ô "Master/SKU manage", phần mô tả ghi "Danh mục: xem
     * ... KHÔNG SỬA", và danh sách "Làm được" của vai này KHÔNG có mục App PV nào.
     * Quản lý hồ sơ/ngành hàng PV và bật hiển thị công khai là hành vi GHI trên master
     * data, nên đọc theo tài liệu mới thì vai này không còn giữ hai quyền đó.
     *
     * Nhưng SRS §3 (nguồn cũ, trích ở trên) vẫn xếp cả hai dòng App PV ở mức A cho
     * actor "Quản lý kho" — và hai tài liệu KHÔNG nói cùng một chuyện. Lưu ý thêm: ở
     * ánh xạ actor cũ (ROLE_BASELINE_PIN.json), actor "Quản lý kho" của SRS trỏ về
     * WAREHOUSE_KEEPER/WAREHOUSE_APPROVER chứ không phải WAREHOUSE_MANAGER; việc gán
     * hai quyền PV cho WAREHOUSE_MANAGER đến từ WWHN-249 D-04, không phải trực tiếp
     * từ §3.
     *
     * BA đã chốt 07/09/2026: giữ theo tài liệu mới — Quản lý kho KHÔNG quản trị App PV.
     * Xung đột với SRS §3 khép lại, §3 là bản cũ. ADMIN vẫn giữ đủ pv.publish +
     * pv.master.manage nên màn quản trị App PV không mất chủ.
     *
     * Đừng "sửa cho khớp SRS" khi thấy hai tài liệu lệch nhau: quyết định đã có, và nó
     * nằm ở đây cùng docs/evidence/WWHN-267/ROLE_BASELINE_PIN.json (DELTA-06).
     */
    public function test_seed_grants_pv_permissions_exactly_as_srs_role_matrix(): void
    {
        $roles = (new \ReflectionClass(AuthDatabaseSeeder::class))
            ->getConstant('ROLES');

        $this->assertIsArray($roles);

        foreach (['pv.publish', 'pv.master.manage'] as $permission) {
            $granted = collect($roles)
                ->filter(fn (array $role): bool => in_array($permission, $role['permissions'], true))
                ->keys()->sort()->values()->all();

            $this->assertSame(
                ['ADMIN'],
                $granted,
                "Chỉ ADMIN được giữ {$permission} theo tài liệu BA taikhoantheorole v2 (Quản lý kho "
                .'là vai giám sát, không sửa master data). Mọi vai khác chỉ đọc App PV.'
            );
        }
    }

    /**
     * DEV249-P1-03R2 — closure evidence đòi đúng định dạng "seed diff + manager
     * 200 + unauthorized 403 tests". Test reflection ở trên khoá seeder đúng ma
     * trận quyền; test này chứng minh bằng HTTP thật qua AuthDatabaseSeeder thật
     * (không phải permission rời rạc).
     *
     * Đổi tên + đổi kỳ vọng 06/09/2026 cùng thay đổi ở test phía trên: vai đứng ở vế
     * 200 giờ là ADMIN. WAREHOUSE_MANAGER chuyển sang vế 403 — đây chính là bằng chứng
     * HTTP cho việc thu hồi quyền quản trị App PV khỏi vai giám sát. Tên cũ
     * (test_warehouse_manager_role_gets_200_...) còn được nhắc trong
     * docs/evidence/WWHN-249/PV_SURFACE_EVIDENCE.md và WWHN-249_CLOSURE_REGISTER.json.
     */
    public function test_admin_role_gets_200_and_non_admin_roles_get_403_on_pv_admin(): void
    {
        $this->getJson('/api/v1/pv-admin/resources/industries', $this->authHeadersWithRole('ADMIN'))
            ->assertOk();

        foreach (['WAREHOUSE_MANAGER', 'WAREHOUSE_STAFF'] as $roleCode) {
            $this->getJson('/api/v1/pv-admin/resources/industries', $this->authHeadersWithRole($roleCode))
                ->assertStatus(403);
        }
    }

    /** @return array{paths: array<string, mixed>} */
    private function openApiSpec(): array
    {
        $path = storage_path('api-docs/api-docs.json');
        $this->assertFileExists($path, 'Chưa sinh OpenAPI: chạy `php artisan l5-swagger:generate`.');

        /** @var array{paths: array<string, mixed>} $spec */
        $spec = json_decode((string) file_get_contents($path), true);

        return $spec;
    }
}
