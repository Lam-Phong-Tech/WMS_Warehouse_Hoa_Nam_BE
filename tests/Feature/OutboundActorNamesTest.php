<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Auth\Models\User;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

class OutboundActorNamesTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_detail_returns_creator_scanners_and_poster_names(): void
    {
        $headers = $this->superAdminHeaders();
        $sku = $this->productSkuFixture('OUT-ACTORS');
        $request = $this->outboundRequestFixture($sku->getKey(), 3, 'REQ-ACTORS');
        $document = $this->outboundDocumentFixture($this->warehouseFixture()->id, $request);
        $first = $this->actor('An quét');
        $second = $this->actor('Bình quét');
        $poster = $this->actor('Người ghi nhận xuất');
        foreach ([$first, $first, $second] as $index => $scanner) {
            DB::table('outbound_item_matches')->insert([
                'id' => (string) Str::uuid(), 'outbound_line_id' => $document['line_ids'][0],
                'quantity' => 1, 'status' => $index === 1 ? 'REMOVED' : 'ACTIVE',
                'matched_by' => $index === 2 ? null : $scanner->getKey(),
                'created_by' => $index === 2 ? $second->getKey() : $headers['__user_id'],
                'matched_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $first->delete();
        DB::table('stock_documents')->where('id', $document['document_id'])->update([
            'status' => 'POSTED', 'posted_by' => $poster->getKey(), 'posted_at' => now(),
        ]);

        $this->getJson('/api/v1/outbound-documents/'.$document['document_id'], $headers)->assertOk()
            ->assertJsonPath('data.created_by_name', 'Outbound Fixture User')
            ->assertJsonPath('data.scanned_by_names', ['An quét', 'Bình quét'])
            ->assertJsonPath('data.posted_by_name', 'Người ghi nhận xuất')
            ->assertJsonPath('data.cancelled_by_name', null);
    }

    public function test_detail_returns_canceller_name_and_empty_scan_history(): void
    {
        $headers = $this->superAdminHeaders();
        $sku = $this->productSkuFixture('OUT-CANCEL-ACTOR');
        $request = $this->outboundRequestFixture($sku->getKey(), 1, 'REQ-CANCEL-ACTOR');
        $document = $this->outboundDocumentFixture($this->warehouseFixture()->id, $request);
        $canceller = $this->actor('Người hủy phiếu xuất');
        DB::table('stock_documents')->where('id', $document['document_id'])->update([
            'status' => 'CANCELLED', 'cancelled_by' => $canceller->getKey(), 'cancelled_at' => now(),
        ]);

        $this->getJson('/api/v1/outbound-documents/'.$document['document_id'], $headers)->assertOk()
            ->assertJsonPath('data.created_by_name', 'Outbound Fixture User')
            ->assertJsonPath('data.scanned_by_names', [])
            ->assertJsonPath('data.posted_by_name', null)
            ->assertJsonPath('data.cancelled_by_name', 'Người hủy phiếu xuất');
    }

    private function actor(string $name): User
    {
        return User::create([
            'username' => 'out-actor-'.Str::lower(Str::random(8)), 'display_name' => $name,
            'password_hash' => bcrypt(Str::random(24)), 'status' => 'ACTIVE', 'version' => 1,
        ]);
    }
}
