<?php

namespace Tests\Feature;

use App\Support\UserFacingMessageLocalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Warehouse\Models\Container;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\Feature\Concerns\BuildsScanDrivenInbound;
use Tests\Feature\Concerns\PreparesPutawayFixtures;
use Tests\TestCase;

class FoundationInboundPutawayReadinessTest extends TestCase
{
    use BuildsScanDrivenInbound;
    use PreparesPutawayFixtures;
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $role = Role::create([
            'role_code' => 'SUPER_ADMIN',
            'role_name' => 'Super Admin',
            'scope_type' => 'SYSTEM',
            'status' => 'ACTIVE',
        ]);
        $user = User::create([
            'username' => 'readiness-'.Str::lower(Str::random(6)),
            'display_name' => 'Readiness Tester',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        $token = auth('api')->login($user);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function warehouse(string $code, string $name): Warehouse
    {
        // REQ-SRS-001: tái sử dụng warehouse ACTIVE duy nhất.
        return $this->ensureActiveWarehouse($code, $name);
    }

    public function test_products_write_routes_are_reachable_on_canonical_products_contract(): void
    {
        $headers = $this->authHeaders();
        $create = $this->postJson('/api/v1/products', [
            'code' => 'COMP-ROUTE-001',
            'name' => 'Linh kien test route',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
        ], $headers);
        $create->assertCreated()->assertJsonPath('data.sku_code', 'COMP-ROUTE-001');

        $id = $create->json('data.sku_id');
        $this->patchJson("/api/v1/products/{$id}", [
            'name' => 'Linh kien da cap nhat',
            'status' => 'INACTIVE',
        ], $headers)->assertOk()
            ->assertJsonPath('data.sku_name', 'Linh kien da cap nhat')
            ->assertJsonPath('data.status', 'INACTIVE');
    }

    public function test_direct_inbound_api_rejects_noncanonical_active_warehouse(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->warehouse('KHO-KHAC', 'Kho khong thuoc baseline');
        $sku = Sku::create([
            'sku_code' => 'COMP-WH-GUARD-001',
            'sku_name' => 'Component warehouse guard',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);

        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Direct API bypass attempt',
            'lines' => [[
                'product_id' => $sku->sku_id,
                'qty_planned' => 1,
            ]],
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INBOUND_WAREHOUSE_NOT_CANONICAL')
            ->assertJsonPath('error.details.required_warehouse_code', 'KHO-TONG-HN');

        $this->assertDatabaseMissing('stock_documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Direct API bypass attempt',
        ]);
    }

    public function test_component_receipt_requires_box_and_full_flow_preserves_stock_invariants(): void
    {
        $headers = $this->authHeaders();
        $warehouse = $this->warehouse('KHO-TONG-HN', 'Kho tổng Hoa Nam');
        $sku = Sku::create([
            'sku_code' => 'COMP-E2E-001',
            'sku_name' => 'Component E2E',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $tray = Container::create([
            'container_code' => 'TRAY-E2E-001',
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        $movementBefore = DB::table('stock_movements')->count();
        // Contract GĐ1 scan-driven: tạo đầu phiếu header-only, dòng COMPONENT
        // materialize qua /component-lines rồi mới quét theo Box.
        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'Nha cung cap E2E',
        ], $headers);
        $create->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.version', 1);

        $documentId = (string) $create->json('data.id');
        $lineId = (string) $this->postJson(
            "/api/v1/inbound-documents/{$documentId}/component-lines",
            ['sku_id' => $sku->sku_id],
            $headers
        )->assertOk()->json('data.line_id');

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 5,
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'COMPONENT_BOX_REQUIRED');
        $this->assertSame($movementBefore, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('stock_documents', [
            'id' => $documentId,
            'status' => 'DRAFT',
            'version' => 1,
        ]);

        $register = $this->postJson("/api/v1/inbound-documents/{$documentId}/boxes", [
            'line_id' => $lineId,
            'box_code' => 'BOX-E2E-001',
        ], $headers);
        $register->assertCreated()
            ->assertJsonPath('data.container_type', 'BOX')
            ->assertJsonPath('data.status', 'EMPTY')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);
        $boxId = (string) $register->json('data.container_id');

        $scan = $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 5,
            'container_id' => $boxId,
        ], $headers + ['If-Match' => '1']);
        $scan->assertOk()
            ->assertJsonPath('data.status', 'SCANNING')
            ->assertJsonPath('data.scanned_quantity', 5)
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false)
            ->assertJsonPath('data.version', 2);
        $this->assertSame($movementBefore, DB::table('stock_movements')->count());

        $postHeaders = $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'receipt-component-e2e-001',
        ];
        $post = $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $postHeaders);
        $post->assertOk()
            ->assertHeader('Idempotent-Replay', 'false')
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.stock_effect', 'INCREASE')
            ->assertJsonPath('data.version', 3);

        $movementAfterPost = DB::table('stock_movements')->count();
        $this->assertSame($movementBefore + 1, $movementAfterPost);
        $this->assertDatabaseHas('containers', [
            'container_id' => $boxId,
            'primary_sku_id' => $sku->sku_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
        ]);
        // Post Receipt phải materialize container_items cho SKU/box này — nếu không,
        // "Vị trí gợi ý" (InventoryBalanceController::buildContainerHints) không bao
        // giờ thấy hộp đã nhận hàng dù Post Receipt đã thành công.
        $this->assertDatabaseHas('container_items', [
            'container_id' => $boxId,
            'sku_id' => $sku->sku_id,
            'quantity' => 5,
            'is_active' => true,
            'source_type' => 'RECEIPT',
            'source_id' => $documentId,
        ]);
        $balanceAfterReceipt = $this->getJson('/api/v1/inventory?sku_id='.$sku->sku_id, $headers)
            ->assertOk()
            ->json('data.0');
        $this->assertSame('BOX-E2E-001', $balanceAfterReceipt['container_code'] ?? null);
        $this->assertNull($balanceAfterReceipt['tray_code']);
        $this->assertNull($balanceAfterReceipt['location_hint']);

        $this->postJson("/api/v1/inbound-documents/{$documentId}/post-receipt", [], $postHeaders)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.status', 'POSTED');
        $this->assertSame($movementAfterPost, DB::table('stock_movements')->count());

        $putaway = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $boxId,
            'to_tray_id' => $tray->container_id,
            'location_hint' => 'A-E2E-01',
            'note' => 'Component E2E putaway',
        ], $headers);
        $putaway->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        $putawayId = (string) $putaway->json('data.id');
        $this->assertDatabaseHas('putaway_events', [
            'id' => $putawayId,
            'status' => 'DRAFT',
            'confirmed_at' => null,
            'confirmed_by' => null,
        ]);

        $this->postJson("/api/v1/putaway/tasks/{$putawayId}/confirm", [], $headers + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.box_status', 'STORED')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        $confirmedEvent = DB::table('putaway_events')->where('id', $putawayId)->first();
        $this->assertNotNull($confirmedEvent?->confirmed_at);
        $this->assertNotNull($confirmedEvent?->confirmed_by);
        $this->assertSame($movementAfterPost, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('containers', [
            'container_id' => $boxId,
            'parent_container_id' => $tray->container_id,
            'status' => 'STORED',
            'location_hint' => 'A-E2E-01',
        ]);
        // Sau Putaway confirm, "Vị trí gợi ý" phải lộ luôn cả mã hộp lẫn vị trí/khay —
        // container_items từ Post Receipt vẫn active, container_id không đổi.
        $balanceAfterPutaway = $this->getJson('/api/v1/inventory?sku_id='.$sku->sku_id, $headers)
            ->assertOk()
            ->json('data.0');
        $this->assertSame('BOX-E2E-001', $balanceAfterPutaway['container_code'] ?? null);
        $this->assertSame('TRAY-E2E-001', $balanceAfterPutaway['tray_code'] ?? null);
        $this->assertSame('A-E2E-01', $balanceAfterPutaway['location_hint'] ?? null);

        $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $boxId,
            'to_tray_id' => $tray->container_id,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BOX_NOT_PUTAWAY_ELIGIBLE')
            // error.details đi qua UserFacingMessageLocalizer::translateDetails() ở
            // boundary API nên enum thô được Việt hóa — error_code giữ nguyên.
            ->assertJsonPath('error.details.box_status', UserFacingMessageLocalizer::translate('STORED'));
        $this->assertSame($movementAfterPost, DB::table('stock_movements')->count());
    }

    public function test_putaway_confirm_is_stock_neutral_and_updates_box_location(): void
    {
        $headers = $this->authHeaders();
        $sku = Sku::create([
            'sku_code' => 'COMP-PUT-001',
            'sku_name' => 'Component putaway',
            'sku_type' => 'COMPONENT',
            'unit' => 'cai',
            'is_serial_tracked' => false,
            'status' => 'ACTIVE',
        ]);
        $box = Container::create([
            'container_code' => 'BOX-PUT-001',
            'container_type' => 'BOX',
            'primary_sku_id' => $sku->sku_id,
            'status' => 'RECEIVED_PENDING_PUTAWAY',
            'version' => 1,
        ]);
        // DEV193-P1-05R: linkage phiếu nhập POSTED là điều kiện tiên quyết của putaway task.
        $this->seedPostedReceiptForBox((string) $box->container_id, (string) $sku->sku_id);
        $tray = Container::create([
            'container_code' => 'TRAY-PUT-001',
            'container_type' => 'TRAY',
            'status' => 'EMPTY',
            'version' => 1,
        ]);

        $movementBefore = DB::table('stock_movements')->count();
        $create = $this->postJson('/api/v1/putaway/tasks', [
            'box_container_id' => $box->container_id,
            'to_tray_id' => $tray->container_id,
            'location_hint' => 'A-01-01',
            'note' => 'PTW contract test',
        ], $headers);
        $create->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        $eventId = (string) $create->json('data.id');
        $this->assertDatabaseHas('putaway_events', [
            'id' => $eventId,
            'status' => 'DRAFT',
            'confirmed_at' => null,
            'confirmed_by' => null,
        ]);

        $this->postJson("/api/v1/putaway/tasks/{$eventId}/confirm", [], $headers + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED')
            ->assertJsonPath('data.box_status', 'STORED')
            ->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false);

        $this->assertDatabaseHas('containers', [
            'container_id' => $box->container_id,
            'parent_container_id' => $tray->container_id,
            'status' => 'STORED',
            'location_hint' => 'A-01-01',
        ]);
        $this->assertSame($movementBefore, DB::table('stock_movements')->count());

        $this->getJson('/api/v1/putaway/history', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.box_code', 'BOX-PUT-001')
            ->assertJsonPath('data.0.new_tray_code', 'TRAY-PUT-001')
            ->assertJsonPath('data.0.stock_effect', 'NONE');
    }
}
