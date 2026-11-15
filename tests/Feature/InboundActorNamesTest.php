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

    private function actor(string $name): User
    {
        return User::create([
            'username' => 'in-actor-'.Str::lower(Str::random(8)), 'display_name' => $name,
            'password_hash' => bcrypt(Str::random(24)), 'status' => 'ACTIVE', 'version' => 1,
        ]);
    }
}
