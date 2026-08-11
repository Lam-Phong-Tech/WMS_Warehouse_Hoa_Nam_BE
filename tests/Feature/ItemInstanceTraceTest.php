<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Warehouse\Models\ItemInstance;
use Modules\Warehouse\Models\Sku;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockMovement;
use Tests\TestCase;
use Illuminate\Foundation\Testing\WithoutMiddleware;

class ItemInstanceTraceTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    public function test_trace_returns_only_movements_for_specific_item_instance_not_just_sku()
    {
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = 0;');

        $skuId = \Illuminate\Support\Str::uuid()->toString();
        $itemAId = \Illuminate\Support\Str::uuid()->toString();
        $itemBId = \Illuminate\Support\Str::uuid()->toString();
        $docAId = 'batch-a-123';
        $docBId = 'batch-b-456';
        $lineAId = \Illuminate\Support\Str::uuid()->toString();
        $lineBId = \Illuminate\Support\Str::uuid()->toString();
        $movAId = \Illuminate\Support\Str::uuid()->toString();
        $movBId = \Illuminate\Support\Str::uuid()->toString();

        \Illuminate\Support\Facades\DB::table('app_user')->insert([
            ['user_id' => 'sys', 'username' => 'sys', 'password_hash' => '123', 'status' => 'ACTIVE', 'display_name' => 'sys']
        ]);

        \Illuminate\Support\Facades\DB::table('import_batches')->insert([
            ['import_batch_id' => 'batch-a-123', 'import_batch_code' => 'B-A'],
            ['import_batch_id' => 'batch-b-456', 'import_batch_code' => 'B-B']
        ]);

        \Illuminate\Support\Facades\DB::table('sku')->insert([
            'sku_id' => $skuId,
            'sku_code' => 'SKU-TEST-001',
            'sku_name' => 'SKU Test'
        ]);

        \Illuminate\Support\Facades\DB::table('item_instances')->insert([
            ['item_id' => $itemAId, 'sku_id' => $skuId, 'import_batch_id' => 'batch-a-123', 'serial_number' => 'SN-A'],
            ['item_id' => $itemBId, 'sku_id' => $skuId, 'import_batch_id' => 'batch-b-456', 'serial_number' => 'SN-B']
        ]);

        \Illuminate\Support\Facades\DB::table('stock_documents')->insert([
            ['id' => $docAId, 'doc_no' => 'DOC-A', 'doc_type' => 'RECEIPT', 'status' => 'DRAFT', 'doc_date' => now(), 'created_by' => 'sys'],
            ['id' => $docBId, 'doc_no' => 'DOC-B', 'doc_type' => 'RECEIPT', 'status' => 'DRAFT', 'doc_date' => now(), 'created_by' => 'sys']
        ]);

        \Illuminate\Support\Facades\DB::table('stock_document_lines')->insert([
            ['id' => $lineAId, 'stock_document_id' => $docAId, 'line_no' => 1, 'product_id' => $skuId, 'item_instance_id' => $itemAId, 'qty_planned' => 1, 'qty_actual' => 1],
            ['id' => $lineBId, 'stock_document_id' => $docBId, 'line_no' => 1, 'product_id' => $skuId, 'item_instance_id' => $itemBId, 'qty_planned' => 1, 'qty_actual' => 1],
        ]);

        $warehouseId = \Illuminate\Support\Str::uuid()->toString();

        \Illuminate\Support\Facades\DB::table('warehouses')->insert([
            'id' => $warehouseId, 'code' => 'WH1', 'name' => 'Warehouse 1', 'status' => 'ACTIVE'
        ]);

        \Illuminate\Support\Facades\DB::table('stock_movements')->insert([
            ['id' => $movAId, 'product_id' => $skuId, 'stock_document_id' => $docAId, 'stock_document_line_id' => $lineAId, 'movement_no' => 'MOV-A', 'movement_type' => 'IN', 'created_by' => 'sys', 'business_key' => 'BK-A', 'warehouse_id' => $warehouseId, 'qty_before' => 0, 'qty_delta' => 1, 'qty_after' => 1],
            ['id' => $movBId, 'product_id' => $skuId, 'stock_document_id' => $docBId, 'stock_document_line_id' => $lineBId, 'movement_no' => 'MOV-B', 'movement_type' => 'IN', 'created_by' => 'sys', 'business_key' => 'BK-B', 'warehouse_id' => $warehouseId, 'qty_before' => 0, 'qty_delta' => 1, 'qty_after' => 1]
        ]);

        $response = $this->getJson("/api/v1/item-instances/{$itemAId}/trace");
        $response->assertStatus(200);

        $movements = $response->json('data.movements');
        $this->assertCount(1, $movements);
        $this->assertEquals($movAId, $movements[0]['movement_id']);
    }
}
