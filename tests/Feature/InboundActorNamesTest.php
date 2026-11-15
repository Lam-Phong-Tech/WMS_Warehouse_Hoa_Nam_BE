<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class InboundActorNamesTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_detail_returns_saved_scanners_and_poster_names(): void
    {
        $headers = $this->superAdminHeaders();
        $id = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $this->warehouseFixture()->id,
        ], $headers)->assertCreated()->json('data.id');
        $first = $this->actor('An quét');
        $second = $this->actor('Bình quét');
        $poster = $this->actor('Người ghi nhận');
        $sku = $this->productSkuFixture('ACTOR-SKU');
        $lineId = (string) Str::uuid();
        DB::table('stock_document_lines')->insert([
            'id' => $lineId, 'stock_document_id' => $id, 'product_id' => $sku->getKey(),
            'line_no' => 1, 'qty_planned' => 0, 'scanned_qty' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$first, $first, $second] as $index => $scanner) {
            DB::table('inbound_scan_entries')->insert([
                'id' => (string) Str::uuid(), 'inbound_line_id' => $lineId,
                'quantity' => 1, 'status' => 'ACTIVE', 'created_by' => $headers['__user_id'],
                'captured_by' => $index === 2 ? null : $scanner->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // Legacy evidence without captured_by falls back to its saved creator.
        DB::table('inbound_scan_entries')->where('inbound_line_id', $lineId)
            ->whereNull('captured_by')->update(['created_by' => $second->getKey()]);
        $first->delete();
        DB::table('stock_documents')->where('id', $id)->update([
            'status' => 'POSTED', 'posted_by' => $poster->getKey(), 'posted_at' => now(),
        ]);

        $this->getJson('/api/v1/inbound-documents/'.$id, $headers)->assertOk()
            ->assertJsonPath('data.scanned_by_names', ['An quét', 'Bình quét'])
            ->assertJsonPath('data.posted_by_name', 'Người ghi nhận')
            ->assertJsonPath('data.cancelled_by_name', null);
    }

    public function test_cancelled_actor_is_returned_only_after_cancellation(): void
    {
        $headers = $this->superAdminHeaders();
        $id = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $this->warehouseFixture()->id,
        ], $headers)->assertCreated()->json('data.id');
        $this->getJson('/api/v1/inbound-documents/'.$id, $headers)->assertOk()
            ->assertJsonPath('data.scanned_by_names', [])
            ->assertJsonPath('data.posted_by_name', null)
            ->assertJsonPath('data.cancelled_by_name', null);

        $this->postJson('/api/v1/inbound-documents/'.$id.'/cancel', [
            'reason' => 'Hủy phiếu kiểm thử',
        ], $headers + ['If-Match' => '"1"'])->assertOk();
        $this->getJson('/api/v1/inbound-documents/'.$id, $headers)->assertOk()
            ->assertJsonPath('data.cancelled_by_name', 'Outbound Fixture User');
    }

    public function test_detail_history_keeps_scan_remove_and_header_edit_actors_and_times_scoped_to_receipt(): void
    {
        $headers = $this->superAdminHeaders();
        $id = $this->postJson('/api/v1/inbound-documents', [
            'dst_warehouse_id' => $this->warehouseFixture()->id,
        ], $headers)->assertCreated()->json('data.id');
        $scanner = $this->actor('Người quét đã nghỉ');
        $remover = $this->actor('Người gỡ mã');
        $sku = $this->productSkuFixture('HISTORY-SKU');
        $line = (string) Str::uuid();
        DB::table('stock_document_lines')->insert([
            'id' => $line, 'stock_document_id' => $id, 'product_id' => $sku->getKey(),
            'line_no' => 1, 'qty_planned' => 0, 'scanned_qty' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inbound_scan_entries')->insert([
            'id' => (string) Str::uuid(), 'inbound_line_id' => $line,
            'quantity' => 3, 'status' => 'REMOVED', 'created_by' => $headers['__user_id'],
            'captured_by' => $scanner->getKey(), 'captured_at' => '2026-11-15 09:18:00',
            'removed_by' => $remover->getKey(), 'removed_at' => '2026-11-15 09:19:00',
            'created_at' => '2026-11-15 09:17:00', 'updated_at' => '2026-11-15 09:20:00',
        ]);
        $scanner->delete();
        foreach ([$id, (string) Str::uuid()] as $documentId) {
            DB::table('audit_log')->insert([
                'id' => (string) Str::uuid(), 'object_type' => 'stock_document', 'object_id' => $documentId,
                'action' => 'INBOUND_HEADER_UPDATE_SCAN_DRIVEN', 'actor_id' => $remover->getKey(),
                'created_at' => '2026-11-15 09:21:00',
            ]);
            DB::table('audit_log')->insert([
                'id' => (string) Str::uuid(), 'object_type' => 'container', 'object_id' => (string) Str::uuid(),
                'action' => 'INBOUND_CARTON_SCAN_COMPLETED', 'actor_id' => $remover->getKey(),
                'after_data' => json_encode(['document_id' => $documentId, 'carton_code' => 'CTN-001']),
                'created_at' => '2026-11-15 09:22:00',
            ]);
            DB::table('audit_log')->insert([
                'id' => (string) Str::uuid(), 'object_type' => 'sku', 'object_id' => $sku->getKey(),
                'action' => 'INBOUND_SKU_INFO_COMPLETED', 'actor_id' => $remover->getKey(),
                'after_data' => json_encode(['stock_document_id' => $documentId]),
                'created_at' => '2026-11-15 09:23:00',
            ]);
        }

        $history = collect($this->getJson('/api/v1/inbound-documents/'.$id, $headers)
            ->assertOk()->json('data.operation_history'));
        $this->assertSame('Người quét đã nghỉ', $history->firstWhere('action', 'SCAN')['actor_name'] ?? null);
        $this->assertSame('2026-11-15T09:18:00+00:00', $history->firstWhere('action', 'SCAN')['occurred_at'] ?? null);
        $this->assertSame('Người gỡ mã', $history->firstWhere('action', 'REMOVE')['actor_name'] ?? null);
        $this->assertSame('2026-11-15T09:19:00+00:00', $history->firstWhere('action', 'REMOVE')['occurred_at'] ?? null);
        $this->assertCount(1, $history->where('action', 'EDIT'));
        $this->assertCount(1, $history->where('action', 'CARTON_COMPLETE'));
        $this->assertCount(1, $history->where('action', 'SKU_INFO'));
    }

    private function actor(string $name): User
    {
        return User::create([
            'username' => 'in-actor-'.Str::lower(Str::random(8)), 'display_name' => $name,
            'password_hash' => bcrypt(Str::random(24)), 'status' => 'ACTIVE', 'version' => 1,
        ]);
    }
}
