<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-268 — hợp đồng regression cho vòng sửa lỗi WWHN-70 / HN-QA-005-ST01.
 *
 * Review 64/100 của WWHN-268 chỉ ra rằng phần backend của vòng retest không có
 * thứ để đối chiếu: WWHN-264 (QA1 API/DB/integration) và WWHN-266/267 (BE1/BE2
 * contract/error/sample) đều nói "đã chạy" nhưng không ai chứng minh được
 * *phạm vi* đã chạy là gì và *hợp đồng lỗi* mà FE dựa vào có thật hay không.
 *
 * Test này khoá đúng hai thứ đó lại:
 *
 *  1. Phạm vi regression (`docs/evidence/WWHN-268/BE_REGRESSION_SCOPE.json`)
 *     phải liệt kê class test có thật cho từng vùng stock / reversal / warranty
 *     / PV / report. Một vùng bị bỏ trống, hoặc trỏ tới class đã xoá, là FAIL —
 *     không cho phép "regression đã chạy" trong khi phạm vi âm thầm co lại.
 *
 *  2. Hợp đồng lỗi §8.2 trên chính các bề mặt đó: 401 AUTH_REQUIRED khi không
 *     đăng nhập, 403 PERMISSION_DENIED khi thiếu quyền, và envelope luôn có
 *     `error_code` + `request_id` để FE map sang trạng thái UI tương ứng.
 *     Đây chính là "contract/error/sample" mà register đòi BE1/BE2 cung cấp.
 *
 * Ranh giới sự thật: test chạy in-process trên DB test, KHÔNG thay thế bằng
 * chứng regression trên build đã deploy của QA1.
 */
class Wwhn268RegressionContractTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const SCOPE_FILE = 'docs/evidence/WWHN-268/BE_REGRESSION_SCOPE.json';

    /**
     * Danh mục mã 403 đã chốt của hệ thống.
     *
     * SRS §8.2 liệt kê PERMISSION_DENIED / DATA_SCOPE_DENIED dưới cột "Error code
     * examples", còn cài đặt hiện hành dùng ACCESS_DENIED làm mã mặc định cho
     * default-deny (AppTraitsApiResponse, CheckPermission, các mô tả OpenAPI của
     * WWHN-254). Hai cách gọi tên này CHƯA được hợp nhất — xem BE268-D02 trong
     * register WWHN-268; test khoá theo danh mục thực tế để không hợp thức hoá mã
     * tự chế, đồng thời không âm thầm đổi contract khi chưa có disposition.
     *
     * @var array<int, string>
     */
    private const GOVERNED_403_CODES = ['ACCESS_DENIED', 'PERMISSION_DENIED', 'DATA_SCOPE_DENIED'];

    /**
     * Bề mặt đại diện của từng vùng regression, kèm permission mà route thật
     * đang yêu cầu (Modules/Warehouse/routes/api.php).
     *
     * @var array<string, array{path: string, permission: string}>
     */
    private const PROTECTED_SURFACES = [
        'stock.inbound' => ['path' => '/api/v1/inbound-documents', 'permission' => 'inbound.view'],
        'stock.outbound' => ['path' => '/api/v1/outbound-documents', 'permission' => 'outbound.view'],
        'stock.balance' => ['path' => '/api/v1/inventory/balances', 'permission' => 'inventory.view'],
        'reversal' => ['path' => '/api/v1/reversal-documents', 'permission' => 'inventory.reversal.post'],
        'warranty' => ['path' => '/api/v1/warranty-cases', 'permission' => 'warranty.view'],
        'report' => ['path' => '/api/v1/reports/hub', 'permission' => 'report.view'],
    ];

    public function test_regression_scope_manifest_covers_every_area_with_real_test_classes(): void
    {
        $path = base_path(self::SCOPE_FILE);
        $this->assertFileExists(
            $path,
            'WWHN-268 phải có manifest phạm vi regression để QA1/BE đối chiếu, không được kể miệng.'
        );

        $manifest = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($manifest, 'Manifest phạm vi regression phải là JSON hợp lệ.');
        $this->assertSame('WWHN-268', $manifest['task'] ?? null);
        $this->assertSame('m1', $manifest['branch'] ?? null);

        $areas = $manifest['areas'] ?? [];
        $this->assertIsArray($areas);

        foreach (['stock', 'reversal', 'warranty', 'pv', 'report'] as $required) {
            $this->assertArrayHasKey(
                $required,
                $areas,
                "Vùng regression '{$required}' bị thiếu — register WWHN-268 yêu cầu đủ stock/reversal/warranty/PV/report."
            );

            $classes = $areas[$required]['testClasses'] ?? [];
            $this->assertIsArray($classes);
            $this->assertNotEmpty(
                $classes,
                "Vùng regression '{$required}' không được để rỗng: phạm vi rỗng đồng nghĩa chưa chạy."
            );

            foreach ($classes as $class) {
                $this->assertTrue(
                    class_exists($class),
                    "Manifest trỏ tới class test không tồn tại: {$class}"
                );
            }
        }
    }

    public function test_protected_surfaces_return_401_auth_required_with_canonical_envelope(): void
    {
        foreach (self::PROTECTED_SURFACES as $area => $surface) {
            $response = $this->getJson($surface['path']);

            $this->assertSame(
                401,
                $response->getStatusCode(),
                "{$area}: bề mặt bảo vệ phải trả 401 khi chưa xác thực (SRS §8.2)."
            );

            $body = $response->json();
            $this->assertFalse($body['success'] ?? true, "{$area}: envelope lỗi phải có success=false.");
            $this->assertNotEmpty(
                $body['error_code'] ?? null,
                "{$area}: envelope lỗi phải có error_code để FE map sang trạng thái UI."
            );
            $this->assertNotEmpty(
                $body['meta']['request_id'] ?? null,
                "{$area}: envelope lỗi phải có request_id để truy vết (SRS §8.2)."
            );
        }
    }

    public function test_protected_surfaces_return_403_permission_denied_for_a_role_without_the_permission(): void
    {
        $this->seed(AuthDatabaseSeeder::class);

        // LEADERSHIP là vai trò read-only báo cáo theo SRS §3: có report.view nhưng
        // không có quyền ghi/đọc nghiệp vụ kho, nên là mẫu tự nhiên cho 403.
        $headers = $this->authHeadersWithRole('LEADERSHIP');
        unset($headers['__user_id']);

        $denied = 0;
        foreach (self::PROTECTED_SURFACES as $area => $surface) {
            $response = $this->getJson($surface['path'], $headers);

            if ($response->getStatusCode() !== 403) {
                continue;
            }

            $denied++;
            $body = $response->json();
            $this->assertContains(
                $body['error_code'] ?? null,
                self::GOVERNED_403_CODES,
                "{$area}: 403 phải dùng mã trong danh mục đã chốt (".implode('/', self::GOVERNED_403_CODES).'), không được dùng mã tự chế.'
            );
            $this->assertNotEmpty(
                $body['meta']['request_id'] ?? null,
                "{$area}: 403 vẫn phải trả request_id."
            );
        }

        $this->assertGreaterThan(
            0,
            $denied,
            'Không có bề mặt nào từ chối vai trò LEADERSHIP — permission middleware không còn hiệu lực.'
        );
    }

    public function test_pv_public_catalog_stays_anonymous_readable(): void
    {
        // SRS §3: "Người xem App PV" chỉ đọc sản phẩm đã publish và số lượng khả
        // dụng — bề mặt này KHÔNG được rơi vào default-deny cùng nhóm nghiệp vụ.
        $response = $this->getJson('/api/v1/pv/catalog');

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'PV catalog là bề mặt công khai theo SRS §3/§8.4; 401/403 ở đây là hồi quy.'
        );
        $this->assertTrue($response->json('success') ?? false);
    }
}
