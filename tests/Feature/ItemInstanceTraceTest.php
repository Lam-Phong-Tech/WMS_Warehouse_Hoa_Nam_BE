<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemInstanceTraceTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    public function test_trace_returns_only_movements_for_specific_item_instance_not_just_sku()
    {
        Schema::disableForeignKeyConstraints();
        // PRAGMA chỉ có trên SQLite; PostgreSQL (reference DB theo ERD) không cần
        // tắt FK vì fixture bên dưới đã insert đầy đủ quan hệ theo thứ tự.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = 0;');
        }

        // Fixture theo canonical PostgreSQL: mọi khóa là uuid (ERD §0), actor là
        // app_users thật, batch/doc id không dùng chuỗi tự do kiểu SQLite cũ.
        $skuId = Str::uuid()->toString();
        $itemAId = Str::uuid()->toString();
        $itemBId = Str::uuid()->toString();
        $docAId = Str::uuid()->toString();
        $docBId = Str::uuid()->toString();
        $batchAId = Str::uuid()->toString();
        $batchBId = Str::uuid()->toString();
        $lineAId = Str::uuid()->toString();
        $lineBId = Str::uuid()->toString();
        $movAId = Str::uuid()->toString();
        $movBId = Str::uuid()->toString();
        $actorId = Str::uuid()->toString();

        // app_user là bảng vật lý trên cả hai driver; app_users chỉ là view tương
        // thích (chỉ ghi được trên PostgreSQL nhờ trigger INSTEAD OF) nên fixture
        // phải ghi vào bảng gốc.
        DB::table('app_user')->insert([
            'user_id' => $actorId, 'username' => 'trace-sys', 'display_name' => 'Trace Sys',
            'email' => 'trace-sys@wms.test', 'password_hash' => bcrypt('secret123'),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(), 'version' => 1,
        ]);

        DB::table('import_batches')->insert([
            ['import_batch_id' => $batchAId, 'import_batch_code' => 'B-A'],
            ['import_batch_id' => $batchBId, 'import_batch_code' => 'B-B'],
        ]);

        DB::table('sku')->insert([
            'sku_id' => $skuId,
            'sku_code' => 'SKU-TEST-001',
            'sku_name' => 'SKU Test',
        ]);

        DB::table('item_instances')->insert([
            ['item_id' => $itemAId, 'sku_id' => $skuId, 'import_batch_id' => $batchAId, 'serial_number' => 'SN-A'],
            ['item_id' => $itemBId, 'sku_id' => $skuId, 'import_batch_id' => $batchBId, 'serial_number' => 'SN-B'],
        ]);

        $warehouseId = Str::uuid()->toString();
        DB::table('warehouses')->insert([
            'id' => $warehouseId, 'code' => 'WH1', 'name' => 'Warehouse 1', 'status' => 'ACTIVE',
        ]);

        // Mirror inbound_documents yêu cầu warehouse_id (dst) NOT NULL cho receipt.
        DB::table('stock_documents')->insert([
            ['id' => $docAId, 'doc_no' => 'DOC-A', 'doc_type' => 'receipt', 'status' => 'DRAFT', 'doc_date' => now(), 'dst_warehouse_id' => $warehouseId, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $docBId, 'doc_no' => 'DOC-B', 'doc_type' => 'receipt', 'status' => 'DRAFT', 'doc_date' => now(), 'dst_warehouse_id' => $warehouseId, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('stock_document_lines')->insert([
            ['id' => $lineAId, 'stock_document_id' => $docAId, 'line_no' => 1, 'product_id' => $skuId, 'item_instance_id' => $itemAId, 'qty_planned' => 1, 'qty_actual' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $lineBId, 'stock_document_id' => $docBId, 'line_no' => 1, 'product_id' => $skuId, 'item_instance_id' => $itemBId, 'qty_planned' => 1, 'qty_actual' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // ERD bảng 30: movement_type CHECK (OPENING/RECEIPT/ISSUE/COMPONENT_ISSUE/REVERSAL),
        // created_by uuid FK app_users, quantity NOT NULL > 0, stock_status/occurred_at NOT NULL.
        DB::table('stock_movements')->insert([
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
