<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Auth\Models\User;
use Modules\Auth\Models\Permission;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\PhysicalCode;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\Warehouse;
use Tests\TestCase;

class InboundScanTest extends TestCase
{
    use RefreshDatabase;

    private function headers(): array
    {
        $role = Role::create(['role_code' => 'INBOUND_SCAN', 'role_name' => 'Inbound scan', 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
        $user = User::create([
            'username' => 'scan-'.Str::random(8),
            'display_name' => 'Inbound scanner',
            'password_hash' => Hash::make('secret123'),
            'status' => 'ACTIVE',
            'version' => 1,
        ]);
        $user->roles()->attach($role->role_id, ['user_role_id' => (string) Str::uuid()]);
        foreach (['stock_document.create', 'stock_document.submit', 'stock_document.approve', 'stock_document.post'] as $key) {
            $permission = Permission::create(['permission_key' => $key, 'permission_name' => $key, 'scope_type' => 'SYSTEM', 'status' => 'ACTIVE']);
            DB::table('role_permission')->insert(['role_permission_id' => (string) Str::uuid(), 'role_id' => $role->role_id, 'permission_id' => $permission->permission_id]);
        }

        return ['Authorization' => 'Bearer '.auth('api')->login($user)];
    }

    private function serialItem(string $code): array
    {
        $sku = Sku::create(['sku_code' => 'SKU-SCAN-'.Str::random(8), 'sku_name' => 'Serial item', 'sku_type' => 'PRODUCT', 'unit' => 'pcs', 'is_serial_tracked' => true, 'status' => 'ACTIVE']);
        $physicalCode = PhysicalCode::create(['code_value' => $code, 'code_type' => 'ITEM', 'carrier_type' => 'QR', 'status' => 'ACTIVE']);
        $item = ItemInstance::create(['sku_id' => $sku->sku_id, 'physical_code_id' => $physicalCode->physical_code_id, 'item_status' => 'NEW', 'item_condition' => 'NORMAL', 'version' => 1]);

        return [$sku, $item];
    }

    private function approvedInbound(array $headers, Sku $sku, ItemInstance $item): array
    {
        $warehouse = Warehouse::create(['code' => 'WH-SCAN-'.Str::random(6), 'name' => 'Scan warehouse', 'status' => 'ACTIVE']);
        $create = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'lines' => [[
                'product_id' => $sku->sku_id,
                'item_instance_id' => $item->item_id,
                'qty_planned' => 1,
            ]],
        ], $headers + ['Idempotency-Key' => 'create-'.Str::random(10)]);
        $create->assertCreated();

        $documentId = $create->json('data.id');
        $lineId = $create->json('data.lines.0.id');
        $this->postJson("/api/v1/inbound-documents/{$documentId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/inbound-documents/{$documentId}/approve", [], $headers)->assertOk();

        return [$documentId, $lineId];
    }

    public function test_classify_uses_real_physical_code_and_records_one_idempotent_scan_event(): void
    {
        $headers = $this->headers();
        [, $item] = $this->serialItem('SCAN-CLASSIFY-001');
        $warehouse = Warehouse::create(['code' => 'WH-CLASSIFY', 'name' => 'Classify warehouse', 'status' => 'ACTIVE']);
        $payload = ['raw_code' => 'SCAN-CLASSIFY-001', 'context' => 'INBOUND', 'warehouse_id' => $warehouse->id];

        $first = $this->postJson('/api/v1/scan/classify', $payload, $headers + ['Idempotency-Key' => 'classify-001']);
        $first->assertOk()
            ->assertJsonPath('data.item_id', $item->item_id)
            ->assertJsonPath('data.classification', 'ITEM');

        $this->postJson('/api/v1/scan/classify', $payload, $headers + ['Idempotency-Key' => 'classify-001'])->assertOk();
        $this->assertSame(1, DB::table('scan_events')->where('raw_code', 'SCAN-CLASSIFY-001')->count());
    }

    public function test_inbound_serial_scan_requires_matching_code_and_marks_item_in_stock(): void
    {
        $headers = $this->headers();
        [$sku, $item] = $this->serialItem('SCAN-INBOUND-001');
        [$documentId, $lineId] = $this->approvedInbound($headers, $sku, $item);

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", ['line_id' => $lineId, 'qty' => 1], $headers + ['Idempotency-Key' => 'scan-missing-code'])
            ->assertStatus(422)->assertJsonPath('error.code', 'SERIAL_SCAN_REQUIRED');
        $this->assertDatabaseMissing('stock_movements', ['stock_document_id' => $documentId]);
        $this->assertSame('NEW', $item->fresh()->item_status);

        $response = $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'qty' => 1,
            'raw_code' => 'SCAN-INBOUND-001',
        ], $headers + ['Idempotency-Key' => 'scan-good-code']);

        $response->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertSame('IN_STOCK', $item->fresh()->item_status);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('scan_batches', ['source_type' => 'INBOUND', 'source_id' => $documentId]);
        $this->assertDatabaseHas('scan_events', ['raw_code' => 'SCAN-INBOUND-001', 'result_status' => 'ACCEPTED']);
        $this->assertDatabaseHas('audit_log', ['action' => 'SCAN', 'object_type' => 'stock_document_line', 'object_id' => $lineId]);
    }

    public function test_inbound_serial_scan_rejects_a_code_for_another_item_without_side_effect(): void
    {
        $headers = $this->headers();
        [$sku, $item] = $this->serialItem('SCAN-TARGET-001');
        $this->serialItem('SCAN-OTHER-001');
        [$documentId, $lineId] = $this->approvedInbound($headers, $sku, $item);

        $this->postJson("/api/v1/inbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'qty' => 1,
            'raw_code' => 'SCAN-OTHER-001',
        ], $headers + ['Idempotency-Key' => 'scan-wrong-code'])
            ->assertStatus(422)->assertJsonPath('error.code', 'SCAN_ITEM_MISMATCH');

        $this->assertDatabaseMissing('stock_movements', ['stock_document_id' => $documentId]);
        $this->assertSame('NEW', $item->fresh()->item_status);
    }
}
