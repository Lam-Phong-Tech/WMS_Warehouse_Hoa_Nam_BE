<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockMovement;
use Tests\TestCase;
use App\Models\User;

class ItemInstanceTraceTest extends TestCase
{
    use RefreshDatabase;

    public function test_trace_returns_only_movements_for_specific_item_instance_not_just_sku()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Tạo 1 SKU chung
        $sku = Sku::factory()->create(['sku_code' => 'SKU-001']);

        // Tạo 2 ItemInstance thuộc về cùng 1 SKU, nhưng khác import_batch_id
        $itemA = ItemInstance::factory()->create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SN-A',
            'import_batch_id' => 'batch-a-123'
        ]);

        $itemB = ItemInstance::factory()->create([
            'sku_id' => $sku->sku_id,
            'serial_number' => 'SN-B',
            'import_batch_id' => 'batch-b-456'
        ]);

        // Tạo Document và Movement cho Item A
        $docA = StockDocument::factory()->create(['reference_id' => 'batch-a-123']);
        $movementA = StockMovement::factory()->create([
            'product_id' => $sku->sku_id,
            'stock_document_id' => $docA->id,
            'movement_no' => 'MOV-A'
        ]);

        // Tạo Document và Movement cho Item B
        $docB = StockDocument::factory()->create(['reference_id' => 'batch-b-456']);
        $movementB = StockMovement::factory()->create([
            'product_id' => $sku->sku_id,
            'stock_document_id' => $docB->id,
            'movement_no' => 'MOV-B'
        ]);

        // Gọi API trace cho Item A
        $response = $this->getJson("/api/v1/item-instances/{$itemA->item_id}/trace");

        $response->assertStatus(200);
        
        // Assert chỉ trả về movement của A, không trả về movement của B (Negative test)
        $movements = $response->json('data.movements');
        $this->assertCount(1, $movements);
        $this->assertEquals($movementA->id, $movements[0]['movement_id']);
        $this->assertNotEquals($movementB->id, $movements[0]['movement_id']);
    }
}
