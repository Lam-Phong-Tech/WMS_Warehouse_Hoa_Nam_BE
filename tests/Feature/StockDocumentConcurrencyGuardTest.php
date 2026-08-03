<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\InventoryBalance;
use Modules\Warehouse\Models\Product;
use Modules\Warehouse\Models\StockDocument;
use Modules\Warehouse\Models\StockDocumentLine;
use Modules\Warehouse\Models\StockMovement;
use Modules\Warehouse\Models\Warehouse;
use Modules\Warehouse\Services\StockDocumentService;
use Tests\TestCase;

class StockDocumentConcurrencyGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_document_cannot_overwrite_newer_status(): void
    {
        $document = StockDocument::create([
            'doc_type' => StockDocument::TYPE_RECEIPT,
            'doc_no' => 'PN-CONCURRENCY-001',
            'doc_date' => now()->toDateString(),
            'status' => StockDocument::STATUS_APPROVED,
        ]);

        $winner = StockDocument::findOrFail($document->id);
        $stale = StockDocument::findOrFail($document->id);

        DB::transaction(function () use ($winner) {
            $winner->update(['status' => StockDocument::STATUS_RECEIVED]);
        });

        try {
            DB::transaction(function () use ($stale) {
                $stale->update(['status' => StockDocument::STATUS_CANCELLED]);
            });
            $this->fail('Model cũ phải bị từ chối bởi optimistic status lock.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame('STATE_CONFLICT', $exception->getResponse()->getData(true)['error']['code']);
        }

        $this->assertDatabaseHas('stock_documents', [
            'id' => $document->id,
            'status' => StockDocument::STATUS_RECEIVED,
        ]);
    }

    public function test_stale_approved_receipt_cannot_create_any_stock_effect_after_another_request_posts_it(): void
    {
        $warehouse = Warehouse::create([
            'code' => 'KHO-CONCURRENCY-001',
            'name' => 'Kho kiểm tra race',
            'status' => 'ACTIVE',
        ]);
        $product = Product::create([
            'code' => 'SP-CONCURRENCY-001',
            'name' => 'Sản phẩm kiểm tra race',
            'status' => 'ACTIVE',
        ]);
        $document = StockDocument::create([
            'doc_type' => StockDocument::TYPE_RECEIPT,
            'doc_no' => 'PN-CONCURRENCY-STOCK-001',
            'doc_date' => now()->toDateString(),
            'status' => StockDocument::STATUS_APPROVED,
            'dst_warehouse_id' => $warehouse->id,
        ]);
        $line = StockDocumentLine::create([
            'stock_document_id' => $document->id,
            'line_no' => 1,
            'product_id' => $product->id,
            'qty_planned' => 10,
            'qty_actual' => 10,
            'scanned_qty' => 0,
        ]);

        $stale = StockDocument::with('lines')->findOrFail($document->id);

        // Mô phỏng request thắng đã commit trạng thái trước khi request stale vào stock ledger.
        DB::table('stock_documents')
            ->where('id', $document->id)
            ->update([
                'status' => StockDocument::STATUS_RECEIVED,
                'posted_at' => now(),
                'updated_at' => now(),
            ]);

        try {
            app(StockDocumentService::class)->postReceipt(
                $stale,
                'qa2-race-probe',
                'wwhn180-stale-race-key',
                'hash-stale-race',
                "post_receipt_{$document->id}"
            );
            $this->fail('Model approved cũ không được phép tạo stock effect sau khi DB đã chuyển trạng thái.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(409, $exception->getResponse()->getStatusCode());
            $this->assertSame('STATE_CONFLICT', $exception->getResponse()->getData(true)['error']['code']);
        }

        $this->assertDatabaseHas('stock_documents', [
            'id' => $document->id,
            'status' => StockDocument::STATUS_RECEIVED,
        ]);
        $this->assertDatabaseHas('stock_document_lines', [
            'id' => $line->id,
            'scanned_qty' => 0,
        ]);
        $this->assertSame(0, StockMovement::where('stock_document_id', $document->id)->count());
        $this->assertFalse(InventoryBalance::where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->exists());
        $this->assertDatabaseMissing('posting_event', [
            'operation' => "post_receipt_{$document->id}",
            'idempotency_key' => 'wwhn180-stale-race-key',
        ]);
    }
}
