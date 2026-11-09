<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Phiếu xuất nháp được sửa thông tin đầu phiếu (người nhận/địa chỉ/SĐT/đại lý/
 * ngày yêu cầu/ghi chú) nhưng dòng SKU vẫn chốt từ toa nguồn.
 */
class OutboundDraftUpdateTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    /** @return array{document_id: string, request_id: string, sku_id: string} */
    private function draftDocument(): array
    {
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture('SKU-OUT-UPD-'.Str::upper(Str::random(4)));
        $requestId = $this->outboundRequestFixture(
            (string) $sku->sku_id,
            2,
            'TOA-UPD-'.Str::upper(Str::random(6)),
        );
        $document = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);

        return [
            'document_id' => $document['document_id'],
            'request_id' => $requestId,
            'sku_id' => (string) $sku->sku_id,
        ];
    }

    public function test_updates_draft_header_and_syncs_source_request(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->draftDocument();
        $documentId = $fixture['document_id'];
        $requestId = $fixture['request_id'];

        $this->patchJson("/api/v1/outbound-documents/{$documentId}", [
            'recipient_name' => 'Khách sửa tên',
            'recipient_address' => 'Số 9 đường mới',
            'recipient_contact_phone' => '0901234567',
            'request_date' => '2026-11-01',
            'note' => 'Ghi chú đã sửa',
        ], $headers + ['If-Match' => '1'])
            ->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('data.recipient_name', 'Khách sửa tên')
            ->assertJsonPath('data.recipient_address', 'Số 9 đường mới')
            ->assertJsonPath('data.recipient_contact_phone', '0901234567')
            ->assertJsonPath('data.note', 'Ghi chú đã sửa')
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('stock_documents', [
            'id' => $documentId,
            'recipient_name' => 'Khách sửa tên',
            'recipient_address' => 'Số 9 đường mới',
            'recipient_contact_phone' => '0901234567',
            'note' => 'Ghi chú đã sửa',
            'version' => 2,
        ]);
        $this->assertDatabaseHas('outbound_requests', [
            'id' => $requestId,
            'recipient_name' => 'Khách sửa tên',
            'recipient_address' => 'Số 9 đường mới',
            'customer_name' => 'Khách sửa tên',
            'customer_address' => 'Số 9 đường mới',
            'customer_phone' => '0901234567',
            'request_date' => '2026-11-01',
            'version' => 2,
        ]);
        // Dòng SKU vẫn giữ nguyên số lượng từ toa.
        $this->assertDatabaseHas('stock_document_lines', [
            'stock_document_id' => $documentId,
            'product_id' => $fixture['sku_id'],
            'qty_planned' => 2,
        ]);
    }

    public function test_rejects_line_changes(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->draftDocument();

        $this->patchJson("/api/v1/outbound-documents/{$fixture['document_id']}", [
            'lines' => [[
                'product_id' => $fixture['sku_id'],
                'qty_planned' => 99,
            ]],
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SOURCE_REQUEST_IMMUTABLE');
    }

    public function test_rejects_non_draft_document(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->draftDocument();
        DB::table('stock_documents')
            ->where('id', $fixture['document_id'])
            ->update(['status' => 'SCANNING']);

        $this->patchJson("/api/v1/outbound-documents/{$fixture['document_id']}", [
            'note' => 'Không được sửa',
        ], $headers + ['If-Match' => '1'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'STATE_CONFLICT');
    }

    public function test_requires_if_match_and_rejects_stale_version(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->draftDocument();

        $this->patchJson("/api/v1/outbound-documents/{$fixture['document_id']}", [
            'note' => 'Thiếu If-Match',
        ], $headers)
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $this->patchJson("/api/v1/outbound-documents/{$fixture['document_id']}", [
            'note' => 'Sai version',
        ], $headers + ['If-Match' => '99'])
            ->assertStatus(412)
            ->assertJsonPath('error.code', 'STALE_VERSION');
    }

    public function test_validates_phone_recipient_and_request_date(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->draftDocument();
        $url = "/api/v1/outbound-documents/{$fixture['document_id']}";

        $this->patchJson($url, [
            'recipient_name' => '   ',
        ], $headers + ['If-Match' => '1'])->assertStatus(422);

        $this->patchJson($url, [
            'recipient_contact_phone' => '123',
        ], $headers + ['If-Match' => '1'])->assertStatus(422);

        $this->patchJson($url, [
            'request_date' => now()->addDay()->toDateString(),
        ], $headers + ['If-Match' => '1'])->assertStatus(422);
    }

    public function test_viewer_cannot_update(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->draftDocument();
        $viewer = $this->authHeadersWithRole('VIEWER');

        $this->patchJson("/api/v1/outbound-documents/{$fixture['document_id']}", [
            'note' => 'Viewer không được sửa',
        ], $viewer + ['If-Match' => '1'])->assertForbidden();
    }

    public function test_detail_exposes_request_and_agency_labels(): void
    {
        $headers = $this->superAdminHeaders();
        $fixture = $this->draftDocument();
        $agencyId = (string) Str::uuid();
        DB::table('agencies')->insert([
            'agency_id' => $agencyId,
            'agency_code' => 'AG-OUT-UPD',
            'agency_name' => 'Đại lý sửa phiếu',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_documents')
            ->where('id', $fixture['document_id'])
            ->update(['agency_id' => $agencyId]);
        $requestNo = (string) DB::table('outbound_requests')
            ->where('id', $fixture['request_id'])
            ->value('request_no');

        $this->getJson("/api/v1/outbound-documents/{$fixture['document_id']}", $headers)
            ->assertOk()
            ->assertJsonPath('data.request_no', $requestNo)
            ->assertJsonPath('data.agency_code', 'AG-OUT-UPD')
            ->assertJsonPath('data.agency_name', 'Đại lý sửa phiếu');
    }
}
