<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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
        $manager = $this->authHeadersWithRole('WAREHOUSE_MANAGER');
        $staff = $this->authHeadersWithRole('WAREHOUSE_STAFF');
        $viewer = $this->authHeadersWithRole('VIEWER');
        $warehouse = $this->warehouseFixture();
        $sku = $this->componentSku('COMP-W184-'.Str::upper(Str::random(6)));

        /* ---------- Nhánh A/B/C/D: Cancel ---------- */
        $docA = $this->draftWithBox($manager, $warehouse->id, $sku->sku_id, 'A');

        // A02 — thiếu If-Match.
        $this->patchJson("/api/v1/inbound-documents/{$docA['id']}", ['note' => 'missing if-match'], $manager)
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        // A03 — If-Match cũ.
        $this->patchJson("/api/v1/inbound-documents/{$docA['id']}", ['note' => 'stale'], $manager + ['If-Match' => '9999'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');

        // A04 — hai request lỗi ở trên là fail-closed.
        $this->getJson("/api/v1/inbound-documents/{$docA['id']}", $manager)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 1);

        // B01 — scan stock-neutral, version 1 -> 2.
        $movementsBeforeScan = DB::table('stock_movements')->count();
        $scan = $this->postJson("/api/v1/inbound-documents/{$docA['id']}/scan", [
            'line_id' => $docA['line_id'],
            'qty' => 5,
            'container_id' => $docA['box_id'],
        ], $manager + ['If-Match' => '1']);
        $scan->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame('"2"', $scan->headers->get('ETag'));
        $this->assertSame(
            $movementsBeforeScan,
            DB::table('stock_movements')->count(),
            'AC-SRS-006: scan không được sinh movement.',
        );

        // B02/B03.
        $this->getJson("/api/v1/inbound-documents/{$docA['id']}", $manager)
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.version', 2);
        $this->getJson("/api/v1/inbound-documents/{$docA['id']}/scan-entries?status=ACTIVE", $manager)
            ->assertOk();
        $this->assertGreaterThan(
            0,
            count($this->getJson("/api/v1/inbound-documents/{$docA['id']}/scan-entries?status=ACTIVE", $manager)->json('data')),
            'Annex D SCR-IN-01: phải đọc lại được bằng chứng quét ACTIVE.',
        );

        // C01 — post-receipt thiếu Idempotency-Key.
        $this->postJson("/api/v1/inbound-documents/{$docA['id']}/post-receipt", [], $manager + ['If-Match' => '2'])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');
        $this->getJson("/api/v1/inbound-documents/{$docA['id']}", $manager)
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.version', 2);

        // D01 — WAREHOUSE_STAFF chỉ có inbound.create vẫn phải Cancel được
        // (route khai inbound.cancel|inbound.create — Annex C dòng 1443).
        $this->assertFalse(
            DB::table('role_permission')
                ->join('permission', 'permission.permission_id', '=', 'role_permission.permission_id')
                ->join('role', 'role.role_id', '=', 'role_permission.role_id')
                ->where('role.role_code', 'WAREHOUSE_STAFF')
                ->where('permission.permission_key', 'inbound.cancel')
                ->exists(),
            'Fixture phải giữ đúng giả định: WAREHOUSE_STAFF KHÔNG được seed inbound.cancel.',
        );
        $cancel = $this->postJson("/api/v1/inbound-documents/{$docA['id']}/cancel", [
            'reason' => 'DEV184-P1-01R parity: inbound.create phải đủ để Cancel',
        ], $staff + ['If-Match' => '2']);
        $cancel->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.version', 3);
        $this->assertSame('"3"', $cancel->headers->get('ETag'));

        // D02 — refetch xác nhận version đã dịch chuyển.
        $this->getJson("/api/v1/inbound-documents/{$docA['id']}", $manager)
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.version', 3);

        // D03 vs D04 — CÙNG request, khác đúng một thứ: version trong If-Match.
        // Đây là bằng chứng trực tiếp cho defect mà reviewer nêu.
        $this->postJson("/api/v1/inbound-documents/{$docA['id']}/cancel", ['reason' => 'double cancel, version mới'], $manager + ['If-Match' => '3'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');
        $this->postJson("/api/v1/inbound-documents/{$docA['id']}/cancel", ['reason' => 'double cancel, version cũ'], $manager + ['If-Match' => '2'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');

        /* ---------- Nhánh E: Post Receipt ---------- */
        $docB = $this->draftWithBox($manager, $warehouse->id, $sku->sku_id, 'B');
        $this->postJson("/api/v1/inbound-documents/{$docB['id']}/scan", [
            'line_id' => $docB['line_id'],
            'qty' => 3,
            'container_id' => $docB['box_id'],
        ], $manager + ['If-Match' => '1'])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson("/api/v1/inbound-documents/{$docB['id']}", $manager)
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.version', 2);

        $balanceBefore = (int) (DB::table('inventory_balances')->where('sku_id', $sku->sku_id)->value('on_hand_qty') ?? 0);
        $movementsBefore = DB::table('stock_movements')->count();
        $key = 'w184-post-'.Str::lower(Str::random(12));

        // E03.
        $post = $this->postJson("/api/v1/inbound-documents/{$docB['id']}/post-receipt", [], $manager + [
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
        $this->getJson("/api/v1/inbound-documents/{$docB['id']}", $manager)
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.version', 3);

        // E05 — replay cùng key.
        $replay = $this->postJson("/api/v1/inbound-documents/{$docB['id']}/post-receipt", [], $manager + [
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
        $this->postJson("/api/v1/inbound-documents/{$docB['id']}/post-receipt", ['probe' => 'different-payload'], $manager + [
            'If-Match' => '3',
            'Idempotency-Key' => $key,
        ])->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

        // E07 — key khác trên chứng từ đã POSTED, version hiện hành.
        $this->postJson("/api/v1/inbound-documents/{$docB['id']}/post-receipt", [], $manager + [
            'If-Match' => '3',
            'Idempotency-Key' => 'w184-second-'.Str::lower(Str::random(10)),
        ])->assertStatus(409)->assertJsonPath('error.code', 'STATE_CONFLICT');

        // E08 — Cancel chứng từ đã POSTED (POSTED là terminal).
        $this->postJson("/api/v1/inbound-documents/{$docB['id']}/cancel", ['reason' => 'cancel sau khi POSTED'], $manager + ['If-Match' => '3'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');

        // E09 — ba probe lỗi ở trên không đổi state/version/tồn.
        $this->getJson("/api/v1/inbound-documents/{$docB['id']}", $manager)
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
                $this->assertSame(
                    array_keys($operation['responses'] ?? []),
                    array_keys($compiledOperation['responses'] ?? []),
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
