<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class InboundSupplierFieldTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_stock_documents_has_nullable_supplier_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('stock_documents', 'supplier_id'));
        $this->assertNotSame([], Schema::getColumnListing('stock_documents'));

        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $created = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'source_name' => 'NCC test',
        ], $headers)->assertCreated();

        $this->assertNull(
            DB::table('stock_documents')->where('id', $created->json('data.id'))->value('supplier_id'),
        );
    }

    public function test_create_persists_supplier_id_and_returns_it(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $supplier = $this->supplier();

        $created = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier,
        ], $headers)->assertCreated();

        $this->assertSame($supplier, $created->json('data.supplier_id'));
        $this->assertDatabaseHas('stock_documents', [
            'id' => $created->json('data.id'),
            'supplier_id' => $supplier,
        ]);
        $audit = DB::table('audit_log')
            ->where('object_type', 'stock_document')
            ->where('object_id', $created->json('data.id'))
            ->where('action', 'INBOUND_CREATE_SCAN_DRIVEN')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(
            $supplier,
            json_decode((string) $audit->after_data, true)['supplier_id'] ?? null,
        );
    }

    public function test_create_without_supplier_remains_backward_compatible(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();

        $created = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
        ], $headers)->assertCreated();

        $this->assertNull($created->json('data.supplier_id'));
    }

    public function test_create_rejects_unknown_supplier(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();

        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'supplier_id' => (string) Str::uuid(),
        ], $headers)->assertStatus(422)->assertJsonPath('error.code', 'SUPPLIER_NOT_AVAILABLE');
    }

    public function test_create_rejects_inactive_supplier(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $supplier = $this->supplier('INACTIVE');

        $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier,
        ], $headers)->assertStatus(409)->assertJsonPath('error.code', 'SUPPLIER_INACTIVE');
    }

    public function test_update_changes_supplier_and_increments_version(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $first = $this->supplier('ACTIVE', 'NCC-001');
        $second = $this->supplier('ACTIVE', 'NCC-002');
        $created = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'supplier_id' => $first,
        ], $headers)->assertCreated();
        $id = $created->json('data.id');

        $updated = $this->patchJson('/api/v1/inbound-documents/'.$id, [
            'supplier_id' => $second,
        ], $headers + ['If-Match' => '"1"'])->assertOk();

        $this->assertSame($second, $updated->json('data.supplier_id'));
        $this->assertSame(2, $updated->json('data.version'));
        $this->assertDatabaseHas('stock_documents', ['id' => $id, 'supplier_id' => $second]);

        $audit = DB::table('audit_log')
            ->where('object_type', 'stock_document')
            ->where('object_id', $id)
            ->where('action', 'INBOUND_HEADER_UPDATE_SCAN_DRIVEN')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame($first, json_decode((string) $audit->before_data, true)['supplier_id'] ?? null);
        $this->assertSame($second, json_decode((string) $audit->after_data, true)['supplier_id'] ?? null);
    }

    public function test_detail_returns_selected_supplier(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $supplier = $this->supplier('ACTIVE', 'NCC-001');
        $created = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier,
        ], $headers)->assertCreated();
        $id = $created->json('data.id');

        $this->getJson('/api/v1/inbound-documents/'.$id, $headers)
            ->assertOk()
            ->assertJsonPath('data.supplier.id', $supplier)
            ->assertJsonPath('data.supplier.code', 'NCC-001')
            ->assertJsonPath('data.supplier.name', 'Honda Việt Nam')
            ->assertJsonPath('data.supplier.display_name', 'NCC-001 - Honda Việt Nam')
            ->assertJsonPath('data.supplier.status', 'ACTIVE')
            ->assertJsonPath('data.supplier.version', 1);
    }

    public function test_detail_returns_null_supplier_when_not_selected(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $created = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $warehouse->id,
        ], $headers)->assertCreated();

        $this->getJson('/api/v1/inbound-documents/'.$created->json('data.id'), $headers)
            ->assertOk()
            ->assertJsonPath('data.supplier', null);
    }

    private function supplier(string $status = 'ACTIVE', string $code = 'NCC-001'): string
    {
        $id = (string) Str::uuid();
        DB::table('inbound_suppliers')->insert([
            'id' => $id,
            'warehouse_id' => $this->warehouseFixture()->id,
            'code' => $code,
            'name' => 'Honda Việt Nam',
            'status' => $status,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
