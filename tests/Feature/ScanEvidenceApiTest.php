<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * NFR-SRS-004: bằng chứng scan phải tra cứu được. FE (TRACE-01 /
 * PhysicalCodesPage) đọc GET /scan/batches và /scan/events; hai kho evidence
 * song song (scan_record_events và scan_events canonical) phải cùng xuất hiện.
 */
class ScanEvidenceApiTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_scan_batches_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/scan/batches')->assertUnauthorized();
        $this->getJson('/api/v1/scan/events')->assertUnauthorized();
    }

    public function test_scan_events_endpoint_merges_both_evidence_stores(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();

        $batchId = (string) Str::uuid();
        DB::table('scan_batches')->insert([
            'scan_batch_id' => $batchId,
            'source_type' => 'INBOUND',
            'warehouse_id' => $warehouse->id,
            'created_at' => now()->subMinute(),
        ]);
        DB::table('scan_record_events')->insert([
            'scan_event_id' => (string) Str::uuid(),
            'scan_batch_id' => $batchId,
            'raw_code' => 'EVIDENCE-RECORD-001',
            'result_status' => 'SUCCESS',
            'created_at' => now()->subMinute(),
        ]);

        $canonicalBatchId = (string) Str::uuid();
        DB::table('scan_batch')->insert([
            'scan_batch_id' => $canonicalBatchId,
            'source_type' => 'INBOUND',
            'warehouse_id' => $warehouse->id,
            'created_at' => now(),
        ]);
        // scan_events là bảng vật lý trên PostgreSQL (sau migration 000791) nhưng
        // chỉ là view trên sqlite, nên fixture phải ghi vào đúng bảng gốc theo driver.
        $canonicalScanTable = DB::getDriverName() === 'pgsql' ? 'scan_events' : 'scan_event';
        DB::table($canonicalScanTable)->insert([
            ($canonicalScanTable === 'scan_events' ? 'id' : 'scan_event_id') => (string) Str::uuid(),
            'scan_batch_id' => $canonicalBatchId,
            'raw_code' => 'EVIDENCE-CANONICAL-001',
            'result_status' => 'SUCCESS',
            'created_at' => now(),
        ]);

        $codes = collect($this->getJson('/api/v1/scan/events?per_page=50', $headers)->assertOk()->json('data'))
            ->pluck('raw_code')
            ->all();

        $this->assertContains('EVIDENCE-RECORD-001', $codes);
        $this->assertContains('EVIDENCE-CANONICAL-001', $codes);
    }

    public function test_scan_batches_endpoint_returns_paginated_rows(): void
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        DB::table('scan_batches')->insert([
            'scan_batch_id' => (string) Str::uuid(),
            'source_type' => 'OUTBOUND',
            'warehouse_id' => $warehouse->id,
            'created_at' => now(),
        ]);

        $this->getJson('/api/v1/scan/batches?per_page=10', $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page']]);
    }
}
