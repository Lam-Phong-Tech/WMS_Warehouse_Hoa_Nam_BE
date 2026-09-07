<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Permission;
use Modules\Auth\Models\Role;
use Modules\Warehouse\Models\Sku;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * WWHN-184 / DEV184-P1-01R — parity giữa Postman contract, route runtime và OpenAPI.
 *
 * Reviewer giữ P1-01R ở PARTIAL với hai lý do:
 *   (1) "Postman mutation version chaining còn sai/không chứng minh pass";
 *   (2) cần "independent reviewer xác nhận scoped OpenAPI/Postman/runtime cùng contract".
 *
 * Newman chỉ chứng minh được (1) khi có server chạy thật, mà bằng chứng Newman lại
 * không tự chứng minh rằng những gì collection assert là ĐÚNG hợp đồng. File này
 * đóng cả hai lỗ hổng ngay trong CI, chạy được trên cả sqlite lẫn PostgreSQL:
 *
 *   - test_runtime_matches_postman_expectations(): chạy đúng chuỗi request của
 *     collection qua HTTP kernel và assert đúng mã mà collection assert. Điểm cốt lõi
 *     là cặp D03/D04: cùng một request Cancel trên chứng từ đã CANCELLED trả
 *     409 STATE_CONFLICT khi If-Match là version hiện hành, nhưng trả 412 STALE_VERSION
 *     khi If-Match là version cũ — vì cancelDocument()/postDocument() gọi
 *     assertVersion() TRƯỚC state guard. Bản collection cũ không refresh version sau
 *     Cancel/Post nên mọi probe 409 của nó thực tế nhận 412.
 *   - test_postman_paths_and_permissions_match_route_table(): mọi path trong
 *     collection phải tồn tại thật trong bảng route với đúng permission Annex C.
 *   - test_scoped_openapi_matches_compiled_spec(): file OpenAPI scoped bàn giao cho
 *     FE/QA phải khớp từng operation với spec đã compile.
 */
class Wwhn184InboundContractParityTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    private const COLLECTION = 'docs/postman/WWHN-184 Inbound Contract.postman_collection.json';

    private const SCOPED_OPENAPI = 'docs/api-contract/WWHN-184-inbound-openapi.json';

    private const COMPILED_OPENAPI = 'storage/api-docs/api-docs.json';

    /**
     * Annex C (SRS v1.12) — nguồn sự thật cho permission của từng operation nhập kho.
     *
     * @var array<string, string>
     */
    private const ANNEX_C_PERMISSIONS = [
        'GET api/v1/inbound-documents' => 'inbound.view',
        'POST api/v1/inbound-documents' => 'inbound.create',
        'GET api/v1/inbound-documents/{id}' => 'inbound.view',
        'PATCH api/v1/inbound-documents/{id}' => 'inbound.create',
        'POST api/v1/inbound-documents/{id}/cancel' => 'inbound.cancel|inbound.create',
        'POST api/v1/inbound-documents/{id}/boxes' => 'inbound.scan',
        'POST api/v1/inbound-documents/{id}/scan' => 'inbound.scan',
        'POST api/v1/inbound-documents/{id}/unscan' => 'inbound.scan',
        'POST api/v1/inbound-documents/{id}/post-receipt' => 'inbound.post',
        'POST api/v1/inbound-documents/{id}/reverse' => 'inventory.reversal.post',
        'GET api/v1/inbound-documents/{id}/scan-entries' => 'inbound.view',
    ];

    /**
     * Tài khoản mang một vai fixture có ĐÚNG tập quyền được liệt kê — dùng khi mệnh
     * đề cần đo là hành vi của cổng route chứ không phải ma trận quyền của một vai
     * nghiệp vụ. Vai nghiệp vụ đổi theo quyết định của BA; cổng route thì không.
     *
     * @param  array<int, string>  $permissionKeys
     * @return array<string, string>
     */
    private function headersForRoleWithExactPermissions(string $roleCode, array $permissionKeys): array
    {
        $role = Role::firstOrCreate(
            ['role_code' => $roleCode],
            ['role_name' => $roleCode, 'scope_type' => 'FUNCTION', 'status' => 'ACTIVE']
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_key', $permissionKeys)
            ->pluck('permission_id')
            ->all();

        $this->assertCount(
            count($permissionKeys),
            $permissionIds,
            'Fixture đòi quyền chưa có trong danh mục — seed AuthDatabaseSeeder trước khi gọi.'
        );

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permission')->updateOrInsert(
                ['role_id' => $role->role_id, 'permission_id' => $permissionId],
                ['role_permission_id' => (string) Str::uuid()]
            );
        }

        return $this->authHeadersWithRole($roleCode);
    }

    private function componentSku(string $code): Sku
    {
        return Sku::create([
            'sku_code' => $code,
            'sku_name' => 'Component WWHN-184 parity',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Hợp đồng GĐ1 scan-driven: phiếu nhập tạo HEADER-ONLY, dòng COMPONENT
     * materialize qua /component-lines rồi đăng ký BOX qua /boxes. Số lượng là
     * số thực quét — không còn qty_planned nhập tay.
     */
    private function draftWithBox(array $headers, string $warehouseId, string $skuId, string $suffix): array
    {
        $create = $this->postJson('/api/v1/inbound-documents', [
            'doc_no' => 'PN-W184-'.$suffix.'-'.Str::upper(Str::random(6)),
            'dst_warehouse_id' => $warehouseId,
            'source_reference' => 'WWHN-184 parity '.$suffix,
        ], $headers);

        $create->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 1);
        $this->assertSame('"1"', $create->headers->get('ETag'), 'Create phải trả ETag khớp data.version.');

        $documentId = (string) $create->json('data.id');

        $ensure = $this->postJson("/api/v1/inbound-documents/{$documentId}/component-lines", [
            'sku_id' => $skuId,
        ], $headers)->assertOk();
        $lineId = (string) $ensure->json('data.line_id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-W184-'.$suffix.'-'.Str::upper(Str::random(6)),
        ], $headers)->assertSuccessful();

        // Materialize dòng + đăng ký BOX không được bump version chứng từ.
        $this->getJson("/api/v1/inbound-documents/{$documentId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 1);

        return [
            'id' => $documentId,
            'line_id' => $lineId,
            'box_id' => (string) ($box->json('data.container_id') ?? $box->json('container_id')),
        ];
    }

    public function test_runtime_matches_postman_expectations(): void
    {
        // Vai đứng vận hành đổi WAREHOUSE_MANAGER -> WAREHOUSE_STAFF ngày 06/09/2026:
        // tài liệu BA taikhoantheorole v2 chuyển Quản lý kho thành vai GIÁM SÁT (không
        // tạo/scan/Post/huỷ phiếu) và chuyển đúng bộ quyền đó sang Nhân viên kho — vai
        // "trực tiếp vận hành kho từ đầu đến cuối". Giữ WAREHOUSE_MANAGER ở đây thì cả
        // kịch bản parity chỉ còn đo được 403.
        //
        // $operator và $staff cùng role nhưng là HAI tài khoản khác nhau
        // (authHeadersWithRole tạo user mới mỗi lần gọi) — đúng thứ mục retest §12 của
        // BA đòi: hai nhân viên kho thao tác độc lập trên cùng một chứng từ, không cần
        // role trung gian nào đứng giữa.
        $operator = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $staff = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $viewer = $this->authHeadersWithRole('VIEWER');
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSku('COMP-W184-'.Str::upper(Str::random(6)));

        /* ---------- Nhánh A/B/C/D: Cancel ---------- */
        $docA = $this->draftWithBox($operator, $warehouse->id, $sku->sku_id, 'A');

        // A02 — thiếu If-Match.
        $this->patchJson("/api/v1/inbound-documents/{$docA['id']}", ['note' => 'missing if-match'], $operator)
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        // A03 — If-Match cũ.
        $this->patchJson("/api/v1/inbound-documents/{$docA['id']}", ['note' => 'stale'], $operator + ['If-Match' => '9999'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');

        // A04 — hai request lỗi ở trên là fail-closed.
        $this->getJson("/api/v1/inbound-documents/{$docA['id']}", $operator)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 1);

        // B01 — scan stock-neutral, version 1 -> 2.
        $movementsBeforeScan = DB::table('stock_movements')->count();
        $scan = $this->postJson("/api/v1/inbound-documents/{$docA['id']}/scan", [
            'line_id' => $docA['line_id'],
            'qty' => 5,
            'container_id' => $docA['box_id'],
        ], $operator + ['If-Match' => '1']);
        $scan->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame('"2"', $scan->headers->get('ETag'));
        $this->assertSame(
            $movementsBeforeScan,
            DB::table('stock_movements')->count(),
            'AC-SRS-006: scan không được sinh movement.',
        );

        // B02/B03.
        $this->getJson("/api/v1/inbound-documents/{$docA['id']}", $operator)
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.version', 2);
        $this->getJson("/api/v1/inbound-documents/{$docA['id']}/scan-entries?status=ACTIVE", $operator)
            ->assertOk();
        $this->assertGreaterThan(
            0,
            count($this->getJson("/api/v1/inbound-documents/{$docA['id']}/scan-entries?status=ACTIVE", $operator)->json('data')),
            'Annex D SCR-IN-01: phải đọc lại được bằng chứng quét ACTIVE.',
        );

        // C01 — post-receipt thiếu Idempotency-Key.
        $this->postJson("/api/v1/inbound-documents/{$docA['id']}/post-receipt", [], $operator + ['If-Match' => '2'])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');
        $this->getJson("/api/v1/inbound-documents/{$docA['id']}", $operator)
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.version', 2);

        // D01 — vai chỉ có inbound.create vẫn phải Cancel được (route khai
        // `inbound.cancel|inbound.create` — Annex C dòng 1443).
        //
        // Trước 06/09/2026 mệnh đề này mượn WAREHOUSE_STAFF làm ví dụ, kèm một
        // assertion khoá giả định "WAREHOUSE_STAFF KHÔNG được seed inbound.cancel".
        // Tài liệu BA taikhoantheorole v2 vừa cấp inbound.cancel cho chính vai đó
        // ("Huỷ phiếu: huỷ chứng từ khi trạng thái và rule nghiệp vụ cho phép"), nên
        // giả định cũ không còn đúng — và sau đợt sửa KHÔNG còn vai seed nào có
        // inbound.create mà thiếu inbound.cancel để mượn.
        //
        // Dựng vai fixture riêng thay vì đổi sang một vai seed khác: mệnh đề cần đo là
        // hành vi của CỔNG route (chấp nhận một trong hai key), không phải ma trận
        // quyền của một vai nghiệp vụ cụ thể. Buộc nó vào ma trận là lý do nó vỡ lần
        // này; tách ra thì nó độc lập với mọi lần BA đổi ma trận về sau.
        $createOnly = $this->headersForRoleWithExactPermissions(
            'W184_INBOUND_CREATE_ONLY',
            ['warehouse.view', 'inbound.view', 'inbound.create']
        );
        $cancel = $this->postJson("/api/v1/inbound-documents/{$docA['id']}/cancel", [
            'reason' => 'DEV184-P1-01R parity: inbound.create phải đủ để Cancel',
        ], $createOnly + ['If-Match' => '2']);
        $cancel->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.version', 3);
        $this->assertSame('"3"', $cancel->headers->get('ETag'));

        // D02 — refetch xác nhận version đã dịch chuyển.
        $this->getJson("/api/v1/inbound-documents/{$docA['id']}", $operator)
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.version', 3);

        // D03 vs D04 — CÙNG request, khác đúng một thứ: version trong If-Match.
        // Đây là bằng chứng trực tiếp cho defect mà reviewer nêu.
        $this->postJson("/api/v1/inbound-documents/{$docA['id']}/cancel", ['reason' => 'double cancel, version mới'], $operator + ['If-Match' => '3'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');
        $this->postJson("/api/v1/inbound-documents/{$docA['id']}/cancel", ['reason' => 'double cancel, version cũ'], $operator + ['If-Match' => '2'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');

        /* ---------- Nhánh E: Post Receipt ---------- */
        $docB = $this->draftWithBox($operator, $warehouse->id, $sku->sku_id, 'B');
        $this->postJson("/api/v1/inbound-documents/{$docB['id']}/scan", [
            'line_id' => $docB['line_id'],
            'qty' => 3,
            'container_id' => $docB['box_id'],
        ], $operator + ['If-Match' => '1'])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson("/api/v1/inbound-documents/{$docB['id']}", $operator)
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.version', 2);

        $balanceBefore = (int) (DB::table('inventory_balances')->where('sku_id', $sku->sku_id)->value('on_hand_qty') ?? 0);
        $movementsBefore = DB::table('stock_movements')->count();
        $key = 'w184-post-'.Str::lower(Str::random(12));

        // E03.
        $post = $this->postJson("/api/v1/inbound-documents/{$docB['id']}/post-receipt", [], $operator + [
            'If-Match' => '2',
            'Idempotency-Key' => $key,
        ]);
        $post->assertOk()
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.replayed', false);
        $this->assertSame('false', $post->headers->get('Idempotent-Replay'));
        $this->assertSame('"3"', $post->headers->get('ETag'));

        $balanceAfter = (int) (DB::table('inventory_balances')->where('sku_id', $sku->sku_id)->value('on_hand_qty') ?? 0);
        $this->assertSame($balanceBefore + 3, $balanceAfter, 'Post Receipt phải tăng tồn đúng số đã quét.');

        // E04.
        $this->getJson("/api/v1/inbound-documents/{$docB['id']}", $operator)
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.version', 3);

        // E05 — replay cùng key.
        $replay = $this->postJson("/api/v1/inbound-documents/{$docB['id']}/post-receipt", [], $operator + [
            'If-Match' => '3',
            'Idempotency-Key' => $key,
        ]);
        $replay->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.version', 3);
        $this->assertSame('true', $replay->headers->get('Idempotent-Replay'));

        // E05b — replay là no-op ở tầng dữ liệu, không chỉ ở tầng response.
        $this->assertSame(
            $balanceAfter,
            (int) (DB::table('inventory_balances')->where('sku_id', $sku->sku_id)->value('on_hand_qty') ?? 0),
            'REQ-SRS-008: replay không được tăng tồn lần hai.',
        );

        // E06 — cùng key, khác payload. Mã đúng là IDEMPOTENCY_CONFLICT (SRS §8.2),
        // KHÔNG phải IDEMPOTENCY_ERROR như bản collection cũ khai.
        $this->postJson("/api/v1/inbound-documents/{$docB['id']}/post-receipt", ['probe' => 'different-payload'], $operator + [
            'If-Match' => '3',
            'Idempotency-Key' => $key,
        ])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        // E07 — key khác trên chứng từ đã POSTED, version hiện hành.
        $this->postJson("/api/v1/inbound-documents/{$docB['id']}/post-receipt", [], $operator + [
            'If-Match' => '3',
            'Idempotency-Key' => 'w184-second-'.Str::lower(Str::random(10)),
        ])->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');

        // E08 — Cancel chứng từ đã POSTED (POSTED là terminal).
        $this->postJson("/api/v1/inbound-documents/{$docB['id']}/cancel", ['reason' => 'cancel sau khi POSTED'], $operator + ['If-Match' => '3'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');

        // E09 — ba probe lỗi ở trên không đổi state/version/tồn.
        $this->getJson("/api/v1/inbound-documents/{$docB['id']}", $operator)
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.version', 3);
        $this->assertSame(
            $movementsBefore + 1,
            DB::table('stock_movements')->count(),
            'Full-post exactly once: chỉ đúng một movement được sinh cho cả chuỗi.',
        );

        // F01 — RBAC âm.
        $this->postJson("/api/v1/inbound-documents/{$docB['id']}/post-receipt", [], $viewer + [
            'If-Match' => '3',
            'Idempotency-Key' => 'w184-rbac-'.Str::lower(Str::random(10)),
        ])->assertStatus(403);
    }

    public function test_postman_paths_and_permissions_match_route_table(): void
    {
        $collection = $this->readJson(self::COLLECTION);

        $requests = [];
        $walk = function (array $items) use (&$walk, &$requests): void {
            foreach ($items as $item) {
                if (isset($item['item'])) {
                    $walk($item['item']);

                    continue;
                }
                $url = is_string($item['request']['url']) ? $item['request']['url'] : ($item['request']['url']['raw'] ?? '');
                $requests[] = [
                    'name' => $item['name'],
                    'method' => strtoupper((string) $item['request']['method']),
                    'url' => $url,
                ];
            }
        };
        $walk($collection['item']);

        $this->assertNotEmpty($requests, 'Collection WWHN-184 không được rỗng.');

        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $routes[$method.' '.$route->uri()] = $route;
            }
        }

        $seenInboundPaths = [];
        foreach ($requests as $request) {
            // {{base_url}}/api/v1/x?y -> api/v1/x ; {{inbound_id}} -> {id}
            $path = preg_replace('/^\{\{base_url\}\}\//', '', $request['url']);
            $path = explode('?', (string) $path)[0];
            $path = str_replace('{{inbound_id}}', '{id}', $path);

            $key = $request['method'].' '.$path;
            $this->assertArrayHasKey(
                $key,
                $routes,
                "Request Postman \"{$request['name']}\" trỏ tới {$key} nhưng route này không tồn tại trong runtime.",
            );

            if (! str_contains($path, 'inbound-documents')) {
                continue;
            }
            $seenInboundPaths[$key] = true;

            $this->assertArrayHasKey(
                $key,
                self::ANNEX_C_PERMISSIONS,
                "Route nhập kho {$key} chưa được khai trong bảng Annex C của test này.",
            );

            $middleware = $routes[$key]->gatherMiddleware();
            $permission = collect($middleware)
                ->first(fn ($entry): bool => is_string($entry) && str_starts_with($entry, 'permission:'));
            $this->assertNotNull($permission, "Route {$key} không gắn middleware permission — vi phạm SRS §8.1.");
            $this->assertSame(
                'permission:'.self::ANNEX_C_PERMISSIONS[$key],
                $permission,
                "Permission runtime của {$key} lệch khỏi Annex C.",
            );
        }

        // Collection phải phủ đủ nhóm hành vi chính, không chỉ vài route dễ.
        foreach ([
            'POST api/v1/inbound-documents',
            'PATCH api/v1/inbound-documents/{id}',
            'POST api/v1/inbound-documents/{id}/scan',
            'POST api/v1/inbound-documents/{id}/cancel',
            'POST api/v1/inbound-documents/{id}/post-receipt',
            'GET api/v1/inbound-documents/{id}',
            'GET api/v1/inbound-documents/{id}/scan-entries',
        ] as $required) {
            $this->assertArrayHasKey($required, $seenInboundPaths, "Collection thiếu coverage cho {$required}.");
        }
    }

    public function test_scoped_openapi_matches_compiled_spec(): void
    {
        $scopedPath = base_path(self::SCOPED_OPENAPI);
        $compiledPath = base_path(self::COMPILED_OPENAPI);

        if (! file_exists($compiledPath)) {
            $this->markTestSkipped('storage/api-docs/api-docs.json chưa được compile trong môi trường này.');
        }

        $scoped = $this->readJson(self::SCOPED_OPENAPI);
        $compiled = $this->readJson(self::COMPILED_OPENAPI);

        $this->assertNotEmpty($scoped['paths'] ?? [], 'File OpenAPI scoped WWHN-184 không có path nào.');

        foreach ($scoped['paths'] as $path => $operations) {
            $this->assertArrayHasKey(
                $path,
                $compiled['paths'],
                "Path {$path} có trong bản scoped bàn giao nhưng không có trong spec đã compile.",
            );
            foreach ($operations as $method => $operation) {
                $this->assertArrayHasKey(
                    $method,
                    $compiled['paths'][$path],
                    "Operation {$method} {$path} có trong bản scoped nhưng không có trong spec đã compile.",
                );
                $compiledOperation = $compiled['paths'][$path][$method];
                $this->assertSame(
                    $operation['operationId'] ?? null,
                    $compiledOperation['operationId'] ?? null,
                    "operationId của {$method} {$path} lệch giữa bản scoped và spec đã compile.",
                );
                // So sánh TẬP response code, không so thứ tự: thứ tự merge giữa
                // annotation tường minh và các mã được RouteSyncProcessor/augmenter
                // tự thêm không ổn định giữa các lần generate (phụ thuộc thứ tự
                // scan filesystem), dù nội dung annotation không đổi. assertSame
                // trên mảng tuần tự trước đây vô tình so cả thứ tự nên có thể đỏ
                // giả (false positive) — phát hiện 26/08 khi thêm annotation mới.
                $expectedCodes = array_keys($operation['responses'] ?? []);
                $actualCodes = array_keys($compiledOperation['responses'] ?? []);
                sort($expectedCodes);
                sort($actualCodes);
                $this->assertSame(
                    $expectedCodes,
                    $actualCodes,
                    "Tập response code của {$method} {$path} lệch giữa bản scoped và spec đã compile.",
                );
            }
        }

        // Chống tái diễn lỗi RouteSyncProcessor sinh skeleton chung chung (commit 157968b).
        $cancel = $compiled['paths']['/api/v1/inbound-documents/{id}/cancel']['post'] ?? null;
        $this->assertNotNull($cancel, 'Spec compile thiếu operation cancel inbound.');
        $description = (string) ($cancel['responses']['403']['description'] ?? '');
        $this->assertStringContainsString('inbound.cancel', $description);
        $this->assertStringContainsString('inbound.create', $description);
        $this->assertNotSame(
            'Không có quyền truy cập',
            $description,
            'Mô tả 403 vẫn là skeleton chung chung — InboundDocumentController lại rơi khỏi config/l5-swagger.php.',
        );
    }

    /** @return array<mixed> */
    private function readJson(string $relative): array
    {
        $path = base_path($relative);
        $this->assertFileExists($path, "Artifact bắt buộc của WWHN-184 không tồn tại: {$relative}");

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
