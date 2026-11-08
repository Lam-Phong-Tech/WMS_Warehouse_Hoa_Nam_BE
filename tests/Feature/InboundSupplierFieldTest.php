<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
}
