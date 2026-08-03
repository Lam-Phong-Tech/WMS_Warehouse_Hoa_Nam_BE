<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Modules\Warehouse\Models\StockDocument;
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
}
