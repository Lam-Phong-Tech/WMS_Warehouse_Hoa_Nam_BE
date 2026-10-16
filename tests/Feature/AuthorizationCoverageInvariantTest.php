<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Models\Permission;
use Modules\Auth\Support\PermissionEnforcementMap;
use Tests\TestCase;

/**
 * Bất biến phân quyền toàn hệ thống.
 *
 * Trước bộ test này, việc "100% endpoint đều có cổng chặn" hoàn toàn dựa vào kỷ
 * luật viết tay: thêm một route mới mà quên `->middleware('permission:...')` thì
 * không có gì đỏ, endpoint đó mở cho MỌI tài khoản đã đăng nhập. Tương tự, thêm
 * một mã quyền vào seeder mà không có cổng nào đọc tới thì sinh ra "quyền ma" —
 * hiện trên ma trận IAM-01, tick được, nhưng không cấp gì.
 *
 * Mỗi test ở đây chốt một bất biến và, khi đỏ, chỉ đúng chỗ phải sửa.
 */
class AuthorizationCoverageInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionEnforcementMap::flushMemo();
    }

    protected function tearDown(): void
    {
        PermissionEnforcementMap::flushMemo();

        parent::tearDown();
    }

    /**
     * Bất biến 1 — mọi endpoint API cần đăng nhập đều phải có cổng phân quyền.
     *
     * Route tự giới hạn theo user hoặc guard của owner phải được khai tường minh.
     * Guard owner còn phải khớp đúng controller/action, không miễn theo prefix.
     */
    public function test_moi_route_can_dang_nhap_deu_co_permission_hoac_duoc_khai_la_tu_scope(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $requiresAuth = in_array('auth:api', $middleware, true);
            $hasPermission = (bool) array_filter(
                $middleware,
                static fn ($item): bool => is_string($item) && str_starts_with($item, 'permission:')
            );

            if (! $requiresAuth || $hasPermission) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $signature = $method.' '.$route->uri();
                $inCodeOwner = PermissionEnforcementMap::IN_CODE_GUARDED_ROUTES[$signature] ?? null;
                if (! in_array($signature, PermissionEnforcementMap::SELF_SCOPED_ROUTES, true)
                    && ($inCodeOwner === null || $inCodeOwner !== $route->getActionName())) {
                    $missing[] = $signature;
                }
            }
        }

        sort($missing);

        $this->assertSame([], $missing, implode("\n", array_merge(
            ['Route sau có auth:api nhưng KHÔNG có middleware permission: — mọi tài khoản đã đăng nhập đều gọi được.'],
            ['Thêm `->middleware(\'permission:<CODE>\')`, hoặc nếu route thực sự tự giới hạn theo user thì khai vào'],
            ['PermissionEnforcementMap::SELF_SCOPED_ROUTES kèm lý do:'],
            ['Owner guard có kiểm quyền trong code phải khai đúng action tại IN_CODE_GUARDED_ROUTES:'],
            $missing,
        )));
    }

    /**
     * Bất biến 2 — route khai trong SELF_SCOPED_ROUTES phải thực sự tồn tại.
     *
     * Chặn chiều ngược lại: một route từng được miễn gate, sau đó đổi path hoặc bị
     * xoá, nhưng dòng miễn trừ vẫn nằm lại và âm thầm miễn cho một route khác.
     */
    public function test_danh_sach_route_tu_scope_khong_co_dong_chet(): void
    {
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $registered[$method.' '.$route->uri()] = true;
                }
            }
        }

        $stale = array_values(array_filter(
            PermissionEnforcementMap::SELF_SCOPED_ROUTES,
            static fn (string $signature): bool => ! isset($registered[$signature])
        ));

        $this->assertSame([], $stale, 'SELF_SCOPED_ROUTES còn dòng trỏ tới route không tồn tại: '.implode(', ', $stale));
    }

    public function test_inline_owner_declarations_remain_exact_authenticated_actions(): void
    {
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $registered[$method.' '.$route->uri()] = $route;
                }
            }
        }
        foreach (PermissionEnforcementMap::IN_CODE_GUARDED_ROUTES as $signature => $owner) {
            $this->assertArrayHasKey($signature, $registered, $signature);
            $route = $registered[$signature];
            $this->assertSame($owner, $route->getActionName(), $signature);
            $this->assertContains('auth:api', $route->gatherMiddleware(), $signature);
            [$class, $action] = explode('@', $owner, 2);
            $this->assertTrue(method_exists($class, $action), $signature);
            $this->assertNotContains($signature, PermissionEnforcementMap::SELF_SCOPED_ROUTES, $signature);
        }
    }

    /**
     * Bất biến 3 — không có route nào bị khai trùng (cùng method + URI).
     *
     * Laravel giữ bản đăng ký SAU cùng và bỏ im lặng bản trước. Khi hai bản khai
     * cùng một endpoint với permission KHÁC NHAU, cổng thật của endpoint phụ thuộc
     * vào thứ tự nạp service provider — đổi thứ tự là quyền thay đổi mà không ai
     * sửa dòng code nào. Đã từng xảy ra với POST /api/v1/scan/classify
     * (routes/api.php `scan.execute|scan.classify` vs governance.php `scan.classify`).
     */
    public function test_khong_co_route_bi_khai_trung(): void
    {
        $seen = [];
        $duplicates = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $signature = $method.' '.$route->uri();
                if (isset($seen[$signature])) {
                    $duplicates[$signature] = true;
                }
                $seen[$signature] = true;
            }
        }

        $this->assertSame(
            [],
            array_keys($duplicates),
            'Route bị khai nhiều lần: '.implode(', ', array_keys($duplicates))
        );
    }

    /**
     * Bất biến 4 — mã quyền dùng trên route phải tồn tại và đang ACTIVE.
     *
     * Gõ sai một ký tự trong `permission:inbound.scann` không làm hỏng gì lúc khởi
     * động: middleware chỉ so chuỗi, không ai có mã đó nên endpoint khoá chặt với
     * mọi vai trừ SUPER_ADMIN — một lỗi im lặng rất khó truy.
     */
    public function test_moi_ma_quyen_tren_route_deu_ton_tai_va_dang_hoat_dong(): void
    {
        $this->seed(DatabaseSeeder::class);
        Cache::flush();

        $catalog = Permission::query()
            ->where('status', 'ACTIVE')
            ->pluck('permission_key')
            ->all();

        $unknown = array_values(array_diff(PermissionEnforcementMap::routeEnforcedCodes(), $catalog));

        $this->assertSame(
            [],
            $unknown,
            'Route tham chiếu mã quyền không có trong danh mục ACTIVE (gõ sai hoặc quên seed): '
            .implode(', ', $unknown)
        );
    }

    /**
     * Bất biến 5 — không sinh "quyền ma" mới.
     *
     * Mọi quyền ACTIVE phải thực sự được cưỡng chế ở đâu đó: qua middleware
     * `permission:` trên route, hoặc qua `hasPermission()` trong controller
     * (PermissionEnforcementMap::ENFORCED_IN_CODE), hoặc được khai rõ là mã cũ đã
     * ngừng hiệu lực (::DEPRECATED, giữ theo hợp đồng WWHN-180 nhưng FE hiển thị
     * là không còn tác dụng).
     *
     * Đây là bất biến đã bị vi phạm 21 lần trước đợt dọn 27/08.
     */
    public function test_moi_quyen_dang_hoat_dong_deu_duoc_cuong_che_o_dau_do(): void
    {
        $this->seed(DatabaseSeeder::class);
        Cache::flush();

        $accounted = array_merge(
            PermissionEnforcementMap::enforcedCodes(),
            array_keys(PermissionEnforcementMap::DEPRECATED),
        );

        $ghosts = Permission::query()
            ->where('status', 'ACTIVE')
            ->pluck('permission_key')
            ->reject(static fn (string $key): bool => in_array($key, $accounted, true))
            ->sort()
            ->values()
            ->all();

        $this->assertSame([], $ghosts, implode("\n", array_merge(
            ['Quyền ACTIVE nhưng không cổng nào đọc tới — sẽ hiện trên ma trận IAM-01 và tick vào không cấp gì:'],
            $ghosts,
            ['Cách xử lý: gắn vào route bằng `permission:<CODE>`, hoặc khai vào'],
            ['PermissionEnforcementMap::ENFORCED_IN_CODE nếu kiểm trong controller, hoặc thu hồi khỏi seeder.'],
        )));
    }

    /**
     * Bất biến 6 — mã quyền đã thu hồi không được sống lại qua seeder.
     *
     * Migration đặt status=INACTIVE, nhưng seeder chạy `updateOrCreate` với
     * status=ACTIVE. Nếu ai đó thêm lại mã cũ vào AuthDatabaseSeeder::PERMISSIONS,
     * seeder sẽ lặng lẽ bật lại đúng những quyền ma vừa dọn.
     */
    public function test_ma_quyen_da_thu_hoi_khong_song_lai_sau_khi_seed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $retired = [
            'user.view', 'user.manage', 'role.view', 'role.manage', 'permission.view',
            'product.view', 'product.manage', 'product_master.view', 'product_master.manage',
            'master.manage', 'defect_catalog.view', 'defect_catalog.manage',
            'physical_code.view', 'physical_code.manage',
            'stock_document.view', 'stock_document.create', 'stock_document.scan',
            'stock_document.post', 'stock_document.cancel', 'stock_document.reverse',
            'outbound.create',
        ];

        $revived = Permission::query()
            ->whereIn('permission_key', $retired)
            ->where('status', 'ACTIVE')
            ->pluck('permission_key')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([], $revived, 'Mã quyền đã thu hồi bị seed lại ở trạng thái ACTIVE: '.implode(', ', $revived));
    }
}
