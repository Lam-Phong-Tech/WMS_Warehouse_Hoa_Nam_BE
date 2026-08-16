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
        // PRAGMA chỉ có trên SQLite; PostgreSQL (reference DB theo ERD) không cần
        // tắt FK vì fixture bên dưới đã insert đầy đủ quan hệ theo thứ tự.
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = 0;');
        }

        // Fixture theo canonical PostgreSQL: mọi khóa là uuid (ERD §0), actor là
        // app_users thật, batch/doc id không dùng chuỗi tự do kiểu SQLite cũ.
        $skuId = \Illuminate\Support\Str::uuid()->toString();
        $itemAId = \Illuminate\Support\Str::uuid()->toString();
        $itemBId = \Illuminate\Support\Str::uuid()->toString();
        $docAId = \Illuminate\Support\Str::uuid()->toString();
        $docBId = \Illuminate\Support\Str::uuid()->toString();
        $batchAId = \Illuminate\Support\Str::uuid()->toString();
        $batchBId = \Illuminate\Support\Str::uuid()->toString();
        $lineAId = \Illuminate\Support\Str::uuid()->toString();
        $lineBId = \Illuminate\Support\Str::uuid()->toString();
        $movAId = \Illuminate\Support\Str::uuid()->toString();
        $movBId = \Illuminate\Support\Str::uuid()->toString();
        $actorId = \Illuminate\Support\Str::uuid()->toString();

        // app_user là bảng vật lý trên cả hai driver; app_users chỉ là view tương
        // thích (chỉ ghi được trên PostgreSQL nhờ trigger INSTEAD OF) nên fixture
        // phải ghi vào bảng gốc.
        \Illuminate\Support\Facades\DB::table('app_user')->insert([
            'user_id' => $actorId, 'username' => 'trace-sys', 'display_name' => 'Trace Sys',
            'email' => 'trace-sys@wms.test', 'password_hash' => bcrypt('secret123'),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(), 'version' => 1,
        ]);

        \Illuminate\Support\Facades\DB::table('import_batches')->insert([
            ['import_batch_id' => $batchAId, 'import_batch_code' => 'B-A'],
            ['import_batch_id' => $batchBId, 'import_batch_code' => 'B-B'],
        ]);

        \Illuminate\Support\Facades\DB::table('sku')->insert([
            'sku_id' => $skuId,
            'sku_code' => 'SKU-TEST-001',
            'sku_name' => 'SKU Test'
        ]);

        \Illuminate\Support\Facades\DB::table('item_instances')->insert([
            ['item_id' => $itemAId, 'sku_id' => $skuId, 'import_batch_id' => $batchAId, 'serial_number' => 'SN-A'],
            ['item_id' => $itemBId, 'sku_id' => $skuId, 'import_batch_id' => $batchBId, 'serial_number' => 'SN-B']
        ]);

        $warehouseId = \Illuminate\Support\Str::uuid()->toString();
        \Illuminate\Support\Facades\DB::table('warehouses')->insert([
            'id' => $warehouseId, 'code' => 'WH1', 'name' => 'Warehouse 1', 'status' => 'ACTIVE'
        ]);

        // Mirror inbound_documents yêu cầu warehouse_id (dst) NOT NULL cho receipt.
        \Illuminate\Support\Facades\DB::table('stock_documents')->insert([
            ['id' => $docAId, 'doc_no' => 'DOC-A', 'doc_type' => 'receipt', 'status' => 'DRAFT', 'doc_date' => now(), 'dst_warehouse_id' => $warehouseId, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $docBId, 'doc_no' => 'DOC-B', 'doc_type' => 'receipt', 'status' => 'DRAFT', 'doc_date' => now(), 'dst_warehouse_id' => $warehouseId, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]
        ]);

        \Illuminate\Support\Facades\DB::table('stock_document_lines')->insert([
            ['id' => $lineAId, 'stock_document_id' => $docAId, 'line_no' => 1, 'product_id' => $skuId, 'item_instance_id' => $itemAId, 'qty_planned' => 1, 'qty_actual' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $lineBId, 'stock_document_id' => $docBId, 'line_no' => 1, 'product_id' => $skuId, 'item_instance_id' => $itemBId, 'qty_planned' => 1, 'qty_actual' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ERD bảng 30: movement_type CHECK (OPENING/RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL),
        // created_by uuid FK app_users, quantity NOT NULL > 0, stock_status/occurred_at NOT NULL.
        \Illuminate\Support\Facades\DB::table('stock_movements')->insert([
            ['id' => $movAId, 'product_id' => $skuId, 'stock_document_id' => $docAId, 'stock_document_line_id' => $lineAId, 'movement_no' => 'MOV-A', 'movement_type' => 'RECEIPT', 'created_by' => $actorId, 'business_key' => 'BK-A', 'warehouse_id' => $warehouseId, 'stock_status' => 'AVAILABLE', 'qty_before' => 0, 'qty_delta' => 1, 'qty_after' => 1, 'quantity' => 1, 'occurred_at' => now(), 'created_at' => now()],
            ['id' => $movBId, 'product_id' => $skuId, 'stock_document_id' => $docBId, 'stock_document_line_id' => $lineBId, 'movement_no' => 'MOV-B', 'movement_type' => 'RECEIPT', 'created_by' => $actorId, 'business_key' => 'BK-B', 'warehouse_id' => $warehouseId, 'stock_status' => 'AVAILABLE', 'qty_before' => 0, 'qty_delta' => 1, 'qty_after' => 1, 'quantity' => 1, 'occurred_at' => now(), 'created_at' => now()],
        ]);

        $response = $this->getJson("/api/v1/item-instances/{$itemAId}/trace");
        $response->assertStatus(200);

        $movements = $response->json('data.movements');
        $this->assertCount(1, $movements);
        $this->assertEquals($movAId, $movements[0]['movement_id']);
    }
}
