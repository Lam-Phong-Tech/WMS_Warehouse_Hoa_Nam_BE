<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

/**
 * WWHN-190 / DEV190-P1-01C — hợp đồng posting phải khớp runtime, chứng minh bằng
 * request thật chứ không bằng đọc file.
 *
 * Review DEV190-P1-01C chỉ ra hợp đồng WWHN-180 v1.7.0 lệch runtime ở 6 điểm
 * (If-Match, 412 STALE_VERSION, x-permission, IDEMPOTENCY_ERROR vs
 * IDEMPOTENCY_CONFLICT, 404, apply-adjustment đã bị gỡ). Bản đồng bộ nằm ở
 * `docs/api-contract/releases/WWHN-190/1.8.0/` (v1.7.0 là pack immutable theo
 * DISP-STK-236 §3 nên KHÔNG bị sửa, chỉ bị supersede — xem DEC-API-POSTING-002).
 *
 * Test này đóng cả hai chiều:
 *   - mọi thứ hợp đồng KHAI đều tồn tại thật (route, permission, mã lỗi),
 *   - mọi mã lỗi runtime TRẢ VỀ đều đã được hợp đồng khai (không có mã lỗi ngầm).
 */
class Wwhn190PostingContractParityTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = 'docs/api-contract/releases/WWHN-190/1.8.0/openapi.json';

    /** @return array<string,mixed> */
    private function contract(): array
    {
        $path = base_path(self::CONTRACT);
        $this->assertFileExists($path, 'Thiếu hợp đồng posting v1.8.0; chạy scripts/build-wwhn190-posting-contract.mjs.');

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function authHeaders(bool $withPermission = true): array
    {
        $role = Role::create([
            'role_code' => $withPermission ? 'SUPER_ADMIN' : 'WWHN190_NO_PERMISSION',
            'role_name' => $withPermission ? 'Super Admin' : 'No permission',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'w190-'.Str::lower(Str::random(8)),
            'display_name' => 'WWHN-190 parity tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    /**
     * Phiếu nhập SCANNING theo hợp đồng GĐ1 scan-driven: header-only, dòng
     * COMPONENT materialize qua /component-lines, BOX qua /boxes rồi ghi nhận số
     * đếm thực tế; sau scan document ở version 2.
     */
    private function readyToPostDocument(array $headers, string $skuCode, int $qty = 4): array
    {
        Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hòa Nam', 'status' => 'ACTIVE']
        );
        $sku = Sku::create([
            'sku_code' => $skuCode,
            'sku_name' => 'WWHN-190 parity component',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $prepared = $this->componentScanningDocument($headers, $sku, $qty);

        return ['document_id' => $prepared['document_id'], 'line_id' => $prepared['line_id'], 'sku_id' => $sku->sku_id, 'qty' => $qty];
    }

    /**
     * Header-only + component-line + BOX; nếu $scanQty > 0 thì ghi nhận số đếm đó
     * (quantity-mode). Trả về document/line/box id.
     */
    private function componentScanningDocument(array $headers, Sku $sku, int $scanQty): array
    {
        $warehouse = Warehouse::firstOrCreate(
            ['code' => 'KHO-TONG-HN'],
            ['name' => 'Kho tổng Hòa Nam', 'status' => 'ACTIVE']
        );

        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'WWHN-190 parity supplier',
        ], $headers);
        $create->assertCreated();
        $documentId = (string) $create->json('data.id');

        $ensure = $this->postJson("/api/v1/inbound-documents/{$documentId}/component-lines", [
            'sku_id' => (string) $sku->sku_id,
        ], $headers)->assertOk();
        $lineId = (string) $ensure->json('data.line_id');

        $box = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-W190-'.Str::upper(Str::random(6)),
        ], $headers);

        if ($scanQty > 0) {
            $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
                'line_id' => $lineId,
                'quantity' => $scanQty,
                'container_id' => (string) $box->json('data.container_id'),
            ], $headers + ['If-Match' => '1'])->assertOk();
        }

        return [
            'document_id' => $documentId,
            'line_id' => $lineId,
            'box_id' => (string) $box->json('data.container_id'),
        ];
    }

    /** Mã lỗi mà runtime trả về PHẢI nằm trong x-error-codes của status tương ứng. */
    private function assertDeclared(string $path, string $method, int $status, ?string $errorCode): void
    {
        $contract = $this->contract();
        $operation = $contract['paths'][$path][$method] ?? null;
        $this->assertIsArray($operation, "Hợp đồng không khai {$method} {$path}");

        $response = $operation['responses'][(string) $status] ?? null;
        $this->assertIsArray($response, "Hợp đồng không khai HTTP {$status} cho {$method} {$path}");

        if ($errorCode !== null) {
            $this->assertContains(
                $errorCode,
                $response['x-error-codes'] ?? [],
                "Runtime trả {$status} {$errorCode} nhưng hợp đồng không khai mã này cho {$method} {$path}"
            );
        }
    }

    public function test_contract_release_is_present_and_versioned(): void
    {
        $contract = $this->contract();

        $this->assertSame('1.8.0', $contract['info']['version']);
        $this->assertSame('1.8.0', $contract['x-document-version']);
        $this->assertSame('docs/api-contract/WWHN-180-posting-openapi.json', $contract['x-supersedes']['artifact']);
        $this->assertSame('1.7.0', $contract['x-supersedes']['version']);
        $this->assertFileExists(base_path($contract['x-supersedes']['decision_record']));

        $manifest = json_decode(
            (string) file_get_contents(base_path('docs/api-contract/releases/WWHN-190/1.8.0/RELEASE_MANIFEST.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('1.8.0', $manifest['document_version']);

        // Hash trong manifest phải khớp file thật — chống pin rot / chép tay.
        foreach ($manifest['files'] as $entry) {
            $file = base_path('docs/api-contract/releases/WWHN-190/1.8.0/'.$entry['file']);
            $this->assertFileExists($file);
            $this->assertSame(
                $entry['sha256'],
                hash_file('sha256', $file),
                "Hash lệch cho {$entry['file']}; chạy lại scripts/build-wwhn190-posting-contract.mjs."
            );
        }
    }

    public function test_pack_immutable_wwhn180_khong_bi_sua(): void
    {
        // DISP-STK-236 §3: v1.7.0 chỉ được supersede, không được sửa nội dung.
        $legacy = json_decode(
            (string) file_get_contents(base_path('docs/api-contract/WWHN-180-posting-openapi.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertSame('1.7.0', $legacy['info']['version'], 'Pack WWHN-180 phải giữ nguyên version 1.7.0.');
        $this->assertSame('1.7.0', $legacy['x-document-version']);
    }

    public function test_every_declared_path_exists_with_declared_permission(): void
    {
        $contract = $this->contract();
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ($contract['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $uri = ltrim($path, '/');
                $route = $routes->first(
                    fn ($r) => $r->uri() === $uri && in_array(strtoupper($method), $r->methods(), true)
                );
                $this->assertNotNull($route, "Runtime thiếu route {$method} {$path} mà hợp đồng khai.");

                $middleware = implode(',', $route->gatherMiddleware());
                preg_match('/permission:([^,]+)/', $middleware, $matches);
                $this->assertSame(
                    $operation['x-permission'],
                    $matches[1] ?? null,
                    "x-permission lệch cho {$method} {$path}."
                );
                $this->assertStringContainsString('auth:api', $middleware, "Route {$path} phải có auth:api.");
            }
        }
    }

    public function test_removed_surfaces_are_absent_from_runtime(): void
    {
        $contract = $this->contract();
        $this->assertNotEmpty($contract['x-removed-surfaces']);

        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => '/'.$r->uri())->all();
        foreach ($contract['x-removed-surfaces'] as $removed) {
            $this->assertNotContains(
                $removed['path'],
                $uris,
                "Bề mặt đã bị gỡ theo REQ-SRS-037/DISP-STK-236 nhưng runtime lại expose: {$removed['path']}"
            );
        }
    }

    public function test_missing_if_match_returns_428_precondition_required(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'W190-IFMATCH-MISSING');

        $response = $this->postJson(
            "/api/v1/inbound-documents/{$doc['document_id']}/post-receipt",
            [],
            $headers + ['Idempotency-Key' => 'w190-missing-ifmatch-001']
        );

        $response->assertStatus(428)->assertJsonPath('error_code', 'PRECONDITION_REQUIRED');
        $this->assertDeclared('/api/v1/inbound-documents/{id}/post-receipt', 'post', 428, 'PRECONDITION_REQUIRED');
        $this->assertSame(0, DB::table('stock_movements')->count(), '428 không được ghi movement.');
    }

    public function test_malformed_if_match_returns_422_invalid_version(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'W190-IFMATCH-BAD');

        $this->postJson("/api/v1/inbound-documents/{$doc['document_id']}/post-receipt", [], $headers + [
            'If-Match' => 'khong-phai-so',
            'Idempotency-Key' => 'w190-bad-ifmatch-001',
        ])->assertStatus(422)->assertJsonPath('error_code', 'INVALID_VERSION');

        $this->assertDeclared('/api/v1/inbound-documents/{id}/post-receipt', 'post', 422, 'INVALID_VERSION');
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_stale_if_match_returns_412_stale_version(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'W190-IFMATCH-STALE');

        $this->postJson("/api/v1/inbound-documents/{$doc['document_id']}/post-receipt", [], $headers + [
            'If-Match' => '1',
            'Idempotency-Key' => 'w190-stale-ifmatch-001',
        ])->assertStatus(412)->assertJsonPath('error_code', 'STALE_VERSION');

        $this->assertDeclared('/api/v1/inbound-documents/{id}/post-receipt', 'post', 412, 'STALE_VERSION');
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_missing_idempotency_key_returns_428(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'W190-IDEM-MISSING');

        $this->postJson("/api/v1/inbound-documents/{$doc['document_id']}/post-receipt", [], $headers + [
            'If-Match' => '2',
        ])->assertStatus(428)->assertJsonPath('error_code', 'PRECONDITION_REQUIRED');

        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_short_idempotency_key_returns_422_validation_error(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'W190-IDEM-SHORT');

        $this->postJson("/api/v1/inbound-documents/{$doc['document_id']}/post-receipt", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'short',
        ])->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this->assertDeclared('/api/v1/inbound-documents/{id}/post-receipt', 'post', 422, 'VALIDATION_ERROR');
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_happy_path_returns_etag_and_replay_headers_then_replays_exactly_once(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'W190-HAPPY');
        $post = $headers + ['If-Match' => '2', 'Idempotency-Key' => 'w190-happy-001'];

        $first = $this->postJson("/api/v1/inbound-documents/{$doc['document_id']}/post-receipt", ['note' => 'A'], $post);
        $first->assertOk()
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.replayed', false);
        $this->assertSame('"3"', $first->headers->get('ETag'));
        $this->assertSame('false', $first->headers->get('Idempotent-Replay'));

        $movementsAfterFirst = DB::table('stock_movements')->count();
        $balanceAfterFirst = (int) DB::table('inventory_balances')->where('sku_id', $doc['sku_id'])->value('on_hand_qty');
        $this->assertSame(1, $movementsAfterFirst);
        $this->assertSame($doc['qty'], $balanceAfterFirst);

        // Retry đúng key + đúng payload: replay bản đã lưu, không tác động lần hai.
        $replay = $this->postJson("/api/v1/inbound-documents/{$doc['document_id']}/post-receipt", ['note' => 'A'], $post);
        $replay->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame('true', $replay->headers->get('Idempotent-Replay'));

        $this->assertSame($movementsAfterFirst, DB::table('stock_movements')->count(), 'Replay không được sinh movement thứ hai.');
        $this->assertSame(
            $balanceAfterFirst,
            (int) DB::table('inventory_balances')->where('sku_id', $doc['sku_id'])->value('on_hand_qty'),
            'Replay không được đổi balance.'
        );
    }

    public function test_same_key_different_payload_returns_409_idempotency_conflict(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'W190-IDEM-CONFLICT');
        $post = $headers + ['If-Match' => '2', 'Idempotency-Key' => 'w190-conflict-001'];

        $this->postJson("/api/v1/inbound-documents/{$doc['document_id']}/post-receipt", ['note' => 'payload-A'], $post)->assertOk();
        $movements = DB::table('stock_movements')->count();

        $conflict = $this->postJson(
            "/api/v1/inbound-documents/{$doc['document_id']}/post-receipt",
            ['note' => 'payload-B'],
            $post
        );

        // SRS §8.2: mã đúng là IDEMPOTENCY_CONFLICT. Postman/test-case WWHN-180 v1.7.0
        // khai IDEMPOTENCY_ERROR — đó chính là điểm lệch DEV190-P1-01C đang đóng.
        $conflict->assertStatus(409)->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');
        $this->assertDeclared('/api/v1/inbound-documents/{id}/post-receipt', 'post', 409, 'IDEMPOTENCY_CONFLICT');
        $this->assertSame($movements, DB::table('stock_movements')->count());
    }

    public function test_different_key_after_posted_returns_409_state_conflict(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'W190-STATE-CONFLICT');

        $this->postJson("/api/v1/inbound-documents/{$doc['document_id']}/post-receipt", [], $headers + [
            'If-Match' => '2', 'Idempotency-Key' => 'w190-state-first-001',
        ])->assertOk();
        $movements = DB::table('stock_movements')->count();

        $this->postJson("/api/v1/inbound-documents/{$doc['document_id']}/post-receipt", [], $headers + [
            'If-Match' => '3', 'Idempotency-Key' => 'w190-state-second-001',
        ])->assertStatus(409)->assertJsonPath('error_code', 'STATE_CONFLICT');

        $this->assertDeclared('/api/v1/inbound-documents/{id}/post-receipt', 'post', 409, 'STATE_CONFLICT');
        $this->assertSame($movements, DB::table('stock_movements')->count());
    }

    /**
     * Hợp đồng scan-driven GĐ1: KHÔNG còn FULL_POST gate theo expected — thực
     * nhận bao nhiêu Post bấy nhiêu (chỉ cần ≥1 đơn vị đã scan); dòng
     * scanned_qty=0 bị xóa lúc Post, không có stock effect cho dòng đó.
     * (Thay cho test cũ assert 422 FULL_POST_REQUIRED cho scan 3/5.)
     */
    public function test_post_receipt_follows_actual_scans_and_drops_unscanned_lines(): void
    {
        $headers = $this->authHeaders();
        $scannedSku = Sku::create([
            'sku_code' => 'W190-PARTIAL',
            'sku_name' => 'WWHN-190 partial',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $untouchedSku = Sku::create([
            'sku_code' => 'W190-PARTIAL-UNSCANNED',
            'sku_name' => 'WWHN-190 partial unscanned',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $prepared = $this->componentScanningDocument($headers, $scannedSku, 3);
        $documentId = $prepared['document_id'];

        // Dòng thứ hai được materialize nhưng KHÔNG bao giờ scan.
        $ensureUntouched = $this->postJson("/api/v1/inbound-documents/{$documentId}/component-lines", [
            'sku_id' => (string) $untouchedSku->sku_id,
        ], $headers)->assertOk();
        $untouchedLineId = (string) $ensureUntouched->json('data.line_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $headers + [
            'If-Match' => '2', 'Idempotency-Key' => 'w190-partial-001',
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        // Số thực nhận là nguồn sự thật duy nhất.
        $this->assertSame(3, (int) DB::table('inventory_balances')->where('sku_id', $scannedSku->sku_id)->value('on_hand_qty'));
        $this->assertSame(1, DB::table('stock_movements')->count(), 'Chỉ dòng đã scan mới sinh movement.');
        // Dòng chưa từng scan bị xóa lúc Post, không có stock effect.
        $this->assertDatabaseMissing('stock_document_lines', ['id' => $untouchedLineId]);
        $this->assertSame(0, (int) (DB::table('inventory_balances')->where('sku_id', $untouchedSku->sku_id)->value('on_hand_qty') ?? 0));
    }

    /**
     * Tách riêng khỏi test 403: trong cùng một process test, `auth('api')->login()`
     * của authHeaders() để lại guard đã resolve, nên request "không kèm token" vẫn
     * được coi là đã đăng nhập. Test này không đăng nhập lần nào nên 401 là thật.
     */
    public function test_unauthenticated_is_rejected_with_401(): void
    {
        $this->postJson('/api/v1/inbound-documents/'.Str::uuid().'/post-receipt', [], [
            'If-Match' => '1', 'Idempotency-Key' => 'w190-anon-001',
        ])->assertStatus(401);

        $this->assertDeclared('/api/v1/inbound-documents/{id}/post-receipt', 'post', 401, null);
        $this->assertSame(0, DB::table('stock_movements')->count(), '401 không được ghi movement.');
    }

    public function test_forbidden_is_rejected_without_stock_effect(): void
    {
        $headers = $this->authHeaders();
        $doc = $this->readyToPostDocument($headers, 'W190-RBAC');

        $noPermission = $this->authHeaders(withPermission: false);
        $this->postJson("/api/v1/inbound-documents/{$doc['document_id']}/post-receipt", [], $noPermission + [
            'If-Match' => '2', 'Idempotency-Key' => 'w190-noperm-001',
        ])->assertStatus(403)->assertJsonPath('error_code', 'ACCESS_DENIED');

        $this->assertDeclared('/api/v1/inbound-documents/{id}/post-receipt', 'post', 403, 'ACCESS_DENIED');
        $this->assertDeclared('/api/v1/inbound-documents/{id}/post-receipt', 'post', 401, null);
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_not_found_document_returns_404_resource_not_found(): void
    {
        $headers = $this->authHeaders();

        $this->postJson('/api/v1/inbound-documents/'.Str::uuid().'/post-receipt', [], $headers + [
            'If-Match' => '1', 'Idempotency-Key' => 'w190-notfound-001',
        ])->assertStatus(404)->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');

        // v1.7.0 không khai 404 cho post-receipt; 1.8.0 khai đủ.
        $this->assertDeclared('/api/v1/inbound-documents/{id}/post-receipt', 'post', 404, 'RESOURCE_NOT_FOUND');
    }
}
