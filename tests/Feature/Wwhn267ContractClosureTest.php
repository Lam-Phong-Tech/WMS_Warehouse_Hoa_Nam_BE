<?php

namespace Tests\Feature;

use App\Http\Middleware\ApiConventionMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Auth\Database\Seeders\AuthDatabaseSeeder;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-267 — cổng chống tái phát cho phần BE2 (contract/error/sample/data) của vòng
 * sửa lỗi WWHN-70 / HN-QA-005-ST01.
 *
 * Register review của WWHN-267 không tìm thấy PR/commit/code/evidence nào, và nguyên
 * nhân sâu hơn là: phần "contract" mà BE2 phải giao chưa bao giờ tồn tại dưới dạng
 * thứ máy kiểm được. §8.2 chỉ là một bảng ví dụ trong tài liệu, §3 chỉ là một bảng
 * actor — cả hai đều không có gì trong repo canh cho, nên mỗi vòng review lại phải
 * đọc lại bằng mắt và lại kết luận khác nhau.
 *
 * Test này biến bốn mệnh đề đó thành cổng:
 *
 *  1. Mã dành riêng của §8.2 chỉ được phát ở đúng HTTP status của nó
 *     (BE267-D01). Ngoại lệ duy nhất là 3 mã đã khai trong dualStatusCodes.
 *  2. Fallback của ApiConventionMiddleware không được ghép mã dành riêng với
 *     status sai — chính là lỗi BE267-D02 (400 + INSUFFICIENT_STOCK).
 *  3. Register khác biệt tên gọi (DIV-401-01 / DIV-403-01) phải khớp mã backend
 *     thực sự trả về, để hồ sơ không mục trong khi code đã đổi (BE267-D03).
 *  4. Ma trận RBAC theo TỪNG ô (role × bề mặt) và pin role baseline theo băm
 *     (BE267-D04, BE267-D05).
 *
 * Ranh giới sự thật: đây là lượt chạy in-process trên DB test. Nó KHÔNG thay thế
 * lượt regression độc lập của QA1 (WWHN-264) / QA2 (WWHN-265) trên build đã deploy —
 * xem docs/evidence/WWHN-267/QA_RETEST_PLAN.json.
 */
class Wwhn267ContractClosureTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const PAIRING_FILE = 'docs/evidence/WWHN-267/HTTP_ERROR_CODE_PAIRING.json';

    private const ROLE_PIN_FILE = 'docs/evidence/WWHN-267/ROLE_BASELINE_PIN.json';

    private const CATALOG_FILE = 'docs/api-contract/HN-BE-API-002_ERROR_CODE_CATALOG.json';

    /**
     * Ma trận RBAC kỳ vọng theo TỪNG ô.
     *
     * Vì sao phải là từng ô: test hợp đồng 403 của WWHN-268 duyệt qua sáu bề mặt
     * nhưng `continue` khi status khác 403 rồi chỉ khẳng định `denied > 0`. Đo thực
     * tế cho thấy vai trò LEADERSHIP chỉ bị chặn ở 2/6 bề mặt — nghĩa là bốn ô còn
     * lại chưa từng được ai kiểm, và nếu permission middleware rụng khỏi năm route
     * thì cổng đó vẫn xanh. Ma trận dưới đây khoá đủ 18 ô.
     *
     * Căn cứ từng ô nằm ở SRS §3 (bảng actor × action) đối chiếu với permission mà
     * route thật đang yêu cầu (Modules/Warehouse/routes/api.php); hai ô lệch §3 được
     * ghi thành DELTA-02 trong ROLE_BASELINE_PIN.json chứ không bị lặng lẽ hợp thức.
     *
     * @var array<string, array<string, int>>
     */
    private const RBAC_MATRIX = [
        // Ban lãnh đạo: §3 "Read-only reports"; row Inventory query/trace/reversal = V
        // (route GET reversal-documents yêu cầu inventory.view, không phải
        // inventory.reversal.post), row Publish PV/Reports = V.
        // warranty-cases = 200 là DELTA-02: §3 đánh D cho row Warranty nhưng chỉ liệt
        // kê key mức manage, còn seed cấp warranty.view. PII vẫn mask vì thiếu
        // warranty.pii.view.
        //
        // stock.inbound/outbound đổi 403 -> 200 ngày 06/09/2026: tài liệu BA
        // taikhoantheorole v2 cho Ban lãnh đạo "Phiếu nhập/xuất ở chế độ read-only khi
        // cần drill-down", nên seed cấp thêm inbound.view/outbound.view. Nới quyền có
        // chủ ý và CHỈ ở mức đọc — mọi hàng ghi của vai này trong ma trận BA vẫn ❌ và
        // seed không cấp create/scan/post/cancel nào.
        'LEADERSHIP' => [
            'stock.inbound' => 200,
            'stock.outbound' => 200,
            'stock.balance' => 200,
            'reversal' => 200,
            'warranty' => 200,
            'report' => 200,
        ],
        // Quản lý kho — vai GIÁM SÁT sau đợt sửa theo BA (nguyên tắc 3: "không trực
        // tiếp thực hiện nghiệp vụ kho"). Sáu ô 200 này khoá đúng mệnh đề khó nhất của
        // tài liệu: vai này phải ĐỌC được mọi bề mặt nghiệp vụ kho để giám sát, trong
        // khi không còn thao tác nào. Phần "không thao tác" không đo được ở đây vì
        // SURFACES toàn là GET — nó được khoá ở Wwhn180PermissionAliasContractTest
        // (chặn quyền quay lại qua WmsPermissionCompatibilitySeeder) và ở pin permission.
        'WAREHOUSE_MANAGER' => [
            'stock.inbound' => 200,
            'stock.outbound' => 200,
            'stock.balance' => 200,
            'reversal' => 200,
            'warranty' => 200,
            'report' => 200,
        ],
        // Nhân viên kho: §3 cho A ở inbound/outbound, V ở inventory, V* ở reports;
        // không có quyền bảo hành.
        'WAREHOUSE_STAFF' => [
            'stock.inbound' => 200,
            'stock.outbound' => 200,
            'stock.balance' => 200,
            'reversal' => 200,
            'warranty' => 403,
            'report' => 200,
        ],
        // Nhân viên bảo hành: §3 cho V* ở inventory, A ở warranty, D ở inbound/outbound.
        //
        // 'report' => 403 là QUYẾT ĐỊNH, không phải quan sát. Tài liệu BA để ngỏ điểm
        // này ("có thể xem báo cáo bảo hành NẾU ĐƯỢC CẤP report.view"; ma trận ghi "Theo
        // scope"), nên đã hỏi lại — BA chốt 07/09/2026: nhân viên bảo hành KHÔNG được
        // xem báo cáo. Ô này vì thế phải giữ 403; ai muốn đổi thì phải có quyết định mới
        // của BA, không phải sửa cho khỏi đỏ.
        'WARRANTY_STAFF' => [
            'stock.inbound' => 403,
            'stock.outbound' => 403,
            'stock.balance' => 200,
            'reversal' => 200,
            'warranty' => 200,
            'report' => 403,
        ],
    ];

    /** @var array<string, string> */
    private const SURFACES = [
        'stock.inbound' => '/api/v1/inbound-documents',
        'stock.outbound' => '/api/v1/outbound-documents',
        'stock.balance' => '/api/v1/inventory/balances',
        'reversal' => '/api/v1/reversal-documents',
        'warranty' => '/api/v1/warranty-cases',
        'report' => '/api/v1/reports/hub',
    ];

    // -----------------------------------------------------------------------
    // 1. §8.2 — cặp (mã dành riêng ↔ HTTP status)
    // -----------------------------------------------------------------------

    public function test_reserved_srs_82_codes_are_emitted_only_at_their_governed_status(): void
    {
        $register = $this->pairingRegister();
        $reserved = $register['reservedPairings'];
        $allowedDual = [];
        foreach ($register['dualStatusCodes'] as $entry) {
            $allowedDual[$entry['code']] = $entry['statuses'];
        }

        $observed = $this->scanAbortCallSites();
        $this->assertNotEmpty($observed, 'Không quét được điểm phát lỗi nào — regex hỏng thì cổng này vô nghĩa.');

        foreach ($reserved as $code => $governedStatus) {
            if (! isset($observed[$code])) {
                continue; // Mã §8.2 chưa được dùng ở cài đặt hiện hành: xem divergences.
            }

            $statuses = array_values(array_unique($observed[$code]));
            sort($statuses);

            if (isset($allowedDual[$code])) {
                $expected = $allowedDual[$code];
                sort($expected);
                $this->assertSame(
                    $expected,
                    $statuses,
                    "Mã {$code} đã được khai dualStatusCodes với ".json_encode($expected)
                    .' nhưng source đang phát ở '.json_encode($statuses)
                    .'. Sửa register hoặc sửa call site — không được để lệch.'
                );

                continue;
            }

            $this->assertSame(
                [$governedStatus],
                $statuses,
                "SRS §8.2 xếp {$code} ở HTTP {$governedStatus}, nhưng source phát mã này ở "
                .json_encode($statuses).'. Dòng Envelope của §8.2 đòi "HTTP and business code '
                .'must match Annex/API contract" — QA không viết được kỳ vọng negative test '
                .'tất định khi một mã trôi giữa hai status.'
            );
        }
    }

    public function test_no_undeclared_dual_status_code_appears(): void
    {
        $register = $this->pairingRegister();
        $declared = array_column($register['dualStatusCodes'], 'code');

        $undeclared = [];
        foreach ($this->scanAbortCallSites() as $code => $statuses) {
            if (count(array_unique($statuses)) > 1 && ! in_array($code, $declared, true)) {
                $undeclared[$code] = array_values(array_unique($statuses));
            }
        }

        $this->assertSame(
            [],
            $undeclared,
            'Mã dùng nhiều hơn một HTTP status mà chưa khai trong dualStatusCodes: '
            .json_encode($undeclared, JSON_UNESCAPED_UNICODE)
            .'. Mỗi mã như vậy là một chỗ FE/QA phải đoán, phải có lý do tầng nào dùng status nào.'
        );
    }

    public function test_every_status_backend_emits_is_governed_or_dispositioned(): void
    {
        $register = $this->pairingRegister();
        $allowed = $register['governedStatuses'];
        foreach ($register['statusesOutsideSrs82'] as $entry) {
            $allowed[] = $entry['status'];
        }

        $catalog = $this->catalog();
        $observed = [];
        foreach ($catalog['codes'] as $entry) {
            foreach ($entry['http_status'] as $status) {
                $observed[$status] = true;
            }
        }

        $ungoverned = array_values(array_diff(array_keys($observed), $allowed));
        sort($ungoverned);

        $this->assertSame(
            [],
            $ungoverned,
            'HTTP status backend phát ra nhưng không có row §8.2 và cũng không có disposition: '
            .json_encode($ungoverned)
            .'. Thêm mục vào statusesOutsideSrs82 kèm chủ sở hữu, hoặc bỏ status đó.'
        );
    }

    // -----------------------------------------------------------------------
    // 2. Fallback của middleware — BE267-D02
    // -----------------------------------------------------------------------

    public function test_middleware_fallback_never_pairs_a_reserved_code_with_a_wrong_status(): void
    {
        $reserved = $this->pairingRegister()['reservedPairings'];

        // Message được chọn để chạm đúng các nhánh suy luận theo chuỗi trong
        // ApiConventionMiddleware: "tồn kho"/"stock"/"không đủ", "idempotency",
        // "trùng"/"duplicate". Đây là cách BE267-D02 lộ ra: trước khi sửa, cặp
        // (400, "Không đủ tồn kho...") trả về INSUFFICIENT_STOCK — mã 422 của §8.2.
        $cases = [
            [400, 'Không đủ tồn kho để xuất.'],
            [400, 'Tham số không hợp lệ.'],
            [401, 'Chưa đăng nhập.'],
            [403, 'Không có quyền.'],
            [404, 'Không tìm thấy.'],
            [409, 'Idempotency-Key đã dùng với payload khác.'],
            [409, 'Bản ghi đã tồn tại.'],
            [409, 'Trạng thái không cho phép.'],
            [422, 'Không đủ tồn kho.'],
            [422, 'Dữ liệu không hợp lệ.'],
            [500, 'Lỗi ngoài dự kiến.'],
        ];

        $middleware = new ApiConventionMiddleware;

        foreach ($cases as [$status, $message]) {
            $request = Request::create('/api/v1/wwhn267-contract-probe', 'GET');
            $request->headers->set('Accept', 'application/json');

            $response = $middleware->handle($request, function () use ($status, $message) {
                return response()->json(['message' => $message], $status);
            });

            $body = json_decode((string) $response->getContent(), true);
            $code = $body['error_code'] ?? null;

            $this->assertNotEmpty($code, "Fallback {$status} phải luôn có error_code (§8.2 Envelope).");
            $this->assertNotEmpty(
                $body['meta']['request_id'] ?? null,
                "Fallback {$status} phải luôn có meta.request_id (§8.2)."
            );

            if (isset($reserved[$code])) {
                $this->assertSame(
                    $reserved[$code],
                    $status,
                    "Fallback ghép mã dành riêng {$code} với HTTP {$status}, trong khi §8.2 xếp mã này "
                    ."ở HTTP {$reserved[$code]} (message: \"{$message}\"). Suy mã lỗi từ chuỗi tiếng Việt "
                    .'trong message là đường rò: đổi câu chữ là đổi mã lỗi.'
                );
            }
        }
    }

    // -----------------------------------------------------------------------
    // 3. Register khác biệt tên gọi phải khớp hành vi thật — BE267-D03
    // -----------------------------------------------------------------------

    public function test_divergence_register_matches_the_codes_backend_actually_returns(): void
    {
        $this->seed(AuthDatabaseSeeder::class);
        $divergences = [];
        foreach ($this->pairingRegister()['divergences'] as $entry) {
            $divergences[$entry['id']] = $entry;
        }

        // DIV-401-01: chưa xác thực.
        $unauthenticated = $this->getJson(self::SURFACES['stock.inbound']);
        $this->assertSame(401, $unauthenticated->getStatusCode());
        $this->assertStringContainsString(
            (string) $unauthenticated->json('error_code'),
            $divergences['DIV-401-01']['implemented'],
            'DIV-401-01 khai mã 401 khác với mã backend thực trả. Hồ sơ khác biệt mà sai thì lần '
            .'disposition sau của BA sẽ dựa trên số liệu sai.'
        );

        // DIV-403-01: có phiên nhưng thiếu quyền.
        //
        // Đổi LEADERSHIP -> WARRANTY_STAFF ngày 06/09/2026: từ đợt áp ma trận BA
        // (taikhoantheorole v2), Ban lãnh đạo được cấp inbound.view để drill-down
        // read-only từ báo cáo, nên bề mặt này trả 200 và không còn là ví dụ "thiếu
        // quyền" nữa. WARRANTY_STAFF là vai KHÔNG có inbound.view theo đúng ma trận
        // BA (ô 'Tạo/Scan/Post Inbound | NV bảo hành ❌' và không có hàng view nào cấp
        // chứng từ nhập cho vai này) — xem RBAC_MATRIX ở đầu lớp, ô đó pin 403.
        $headers = $this->authHeadersWithRole('WARRANTY_STAFF');
        unset($headers['__user_id']);
        $denied = $this->getJson(self::SURFACES['stock.inbound'], $headers);
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertStringContainsString(
            (string) $denied->json('error_code'),
            $divergences['DIV-403-01']['implemented'],
            'DIV-403-01 khai mã 403 khác với mã backend thực trả.'
        );

        foreach (['DIV-401-01', 'DIV-403-01', 'DIV-422-01', 'DIV-500-01'] as $id) {
            $this->assertArrayHasKey($id, $divergences, "Thiếu mục khác biệt {$id}.");
            $this->assertNotEmpty(
                $divergences[$id]['owner'] ?? null,
                "{$id}: khác biệt contract phải có chủ sở hữu disposition, không được để trống."
            );
        }
    }

    // -----------------------------------------------------------------------
    // 4. RBAC theo từng ô + pin role baseline — BE267-D04 / BE267-D05
    // -----------------------------------------------------------------------

    public function test_rbac_matrix_is_enforced_cell_by_cell(): void
    {
        $this->seed(AuthDatabaseSeeder::class);
        $governed403 = $this->governed403Codes();

        foreach (self::RBAC_MATRIX as $role => $expectations) {
            $headers = $this->authHeadersWithRole($role);
            unset($headers['__user_id']);

            foreach ($expectations as $area => $expectedStatus) {
                $response = $this->getJson(self::SURFACES[$area], $headers);

                $this->assertSame(
                    $expectedStatus,
                    $response->getStatusCode(),
                    "Ô ({$role} × {$area}) lệch kỳ vọng. Nếu đây là thay đổi có chủ ý, cập nhật "
                    .'RBAC_MATRIX và ROLE_BASELINE_PIN.json trong cùng PR; nếu không, permission '
                    .'middleware trên route đã rụng.'
                );

                if ($expectedStatus !== 403) {
                    continue;
                }

                $this->assertContains(
                    $response->json('error_code'),
                    $governed403,
                    "Ô ({$role} × {$area}): 403 phải dùng mã trong danh mục đã chốt ("
                    .implode('/', $governed403).'), không được dùng mã tự chế.'
                );
                $this->assertNotEmpty(
                    $response->json('meta.request_id'),
                    "Ô ({$role} × {$area}): 403 vẫn phải trả request_id (§8.2)."
                );
            }
        }
    }

    public function test_role_baseline_matches_the_pin(): void
    {
        $pin = $this->roleBaselinePin();
        $roles = (new \ReflectionClass(AuthDatabaseSeeder::class))->getConstant('ROLES');
        $this->assertIsArray($roles, 'Không đọc được hằng ROLES của AuthDatabaseSeeder.');

        $this->assertSame(
            array_keys($pin['pinnedRoles']),
            array_keys($roles),
            'Danh sách role trong seeder khác danh sách đã pin. Thêm/bớt role là thay đổi baseline '
            .'quyền — phải cập nhật ROLE_BASELINE_PIN.json và nêu lý do trong PR.'
        );

        foreach ($roles as $code => $definition) {
            $permissions = $definition['permissions'];
            sort($permissions);
            $hash = hash('sha256', implode(',', $permissions));

            $this->assertSame(
                $pin['pinnedRoles'][$code]['permissionsSha256'],
                $hash,
                "Role {$code} đã đổi tập permission so với bản pin ("
                .count($permissions).' quyền). SRS §3 là nguồn: mỗi lần nới quyền phải đi kèm '
                .'cập nhật pin + đối chiếu lại bảng actor, nếu không quyền sẽ lớn dần qua từng PR '
                .'mà không ai chặn được.'
            );
        }

        foreach ($pin['conformanceDeltas'] as $delta) {
            $this->assertNotEmpty($delta['owner'] ?? null, "Delta {$delta['id']} phải có chủ sở hữu.");
            $this->assertNotEmpty(
                $delta['compensatingControl'] ?? null,
                "Delta {$delta['id']} còn mở thì phải khai kiểm soát bù đang giữ rủi ro ở mức nào."
            );
        }
    }

    public function test_leadership_cannot_read_warranty_pii_even_though_it_can_list_cases(): void
    {
        // DELTA-02 để mở việc LEADERSHIP đọc được danh sách case. Kiểm soát bù duy nhất
        // là PII nằm sau permission riêng — nếu kiểm soát đó rụng thì DELTA-02 lập tức
        // đổi hạng, nên nó phải có test riêng chứ không chỉ là một câu trong hồ sơ.
        $this->seed(AuthDatabaseSeeder::class);
        $roles = (new \ReflectionClass(AuthDatabaseSeeder::class))->getConstant('ROLES');

        $this->assertNotContains(
            'warranty.pii.view',
            $roles['LEADERSHIP']['permissions'],
            'LEADERSHIP được cấp warranty.pii.view — kiểm soát bù của DELTA-02 không còn, '
            .'danh sách case bảo hành sẽ lộ customer_name/phone/address cho vai trò mà SRS §3 '
            .'chỉ cho đọc báo cáo.'
        );
        $this->assertNotContains('warranty.pii.export', $roles['LEADERSHIP']['permissions']);
        $this->assertNotContains('warranty.manage', $roles['LEADERSHIP']['permissions']);
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    /**
     * Quét mọi điểm phát lỗi dạng ApiError::abort(<status>, '<CODE>').
     *
     * Đây là kiểm tra độc lập với catalog sinh sẵn: nếu ai đó đổi call site mà quên
     * chạy lại generate-error-code-catalog.mjs, cổng này vẫn bắt được.
     *
     * @return array<string, list<int>>
     */
    private function scanAbortCallSites(): array
    {
        $map = [];

        foreach (['app', 'Modules'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                if (! str_contains($source, 'ApiError::abort(')) {
                    continue;
                }

                if (preg_match_all(
                    "/ApiError::abort\(\s*(\d{3})\s*,\s*'([A-Z0-9_]+)'/",
                    $source,
                    $matches,
                    PREG_SET_ORDER
                )) {
                    foreach ($matches as $match) {
                        $map[$match[2]][] = (int) $match[1];
                    }
                }
            }
        }

        return $map;
    }

    /** @return array<string, mixed> */
    private function pairingRegister(): array
    {
        return $this->readJson(self::PAIRING_FILE);
    }

    /** @return array<string, mixed> */
    private function roleBaselinePin(): array
    {
        return $this->readJson(self::ROLE_PIN_FILE);
    }

    /** @return array<string, mixed> */
    private function catalog(): array
    {
        return $this->readJson(self::CATALOG_FILE);
    }

    /** @return list<string> */
    private function governed403Codes(): array
    {
        $codes = [];
        foreach ($this->catalog()['codes'] as $entry) {
            if (in_array(403, $entry['http_status'], true)) {
                $codes[] = $entry['code'];
            }
        }

        sort($codes);

        return $codes;
    }

    /** @return array<string, mixed> */
    private function readJson(string $relativePath): array
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path, "Thiếu artifact bắt buộc của WWHN-267: {$relativePath}");

        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, "{$relativePath} không phải JSON hợp lệ.");

        return $decoded;
    }
}
