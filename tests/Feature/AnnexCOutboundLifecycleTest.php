<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\PreparesOutboundFixtures;
use Tests\TestCase;

/**
 * Annex C + SRS §6/§13.9 cho các endpoint bổ sung:
 *  - PUT /outbound-documents/{id}/items/{item_id}/rfid (RFID tùy chọn, stock-neutral)
 *  - POST /outbound-documents/{id}/packing-labels/{label_id}/preview|ready
 *  - POST /reversal-documents/{id}/confirm|post|cancel
 */
class AnnexCOutboundLifecycleTest extends TestCase
{
    use PreparesOutboundFixtures;
    use RefreshDatabase;

    public function test_assign_item_rfid_is_stock_neutral_and_idempotent(): void
    {
        [$headers, $documentId, $itemId] = $this->scannedOutbound('TOA-RFID-001', 'PROD-RFID-001');
        $hardwareUid = 'UID-'.Str::upper(Str::random(10));

        $first = $this->putJson("/api/v1/outbound-documents/{$documentId}/items/{$itemId}/rfid", [
            'hardware_uid' => $hardwareUid,
        ], $headers)->assertOk();

        $first->assertJsonPath('data.stock_effect', 'NONE')
            ->assertJsonPath('data.movement_created', false)
            ->assertJsonPath('data.rfid_required_for_post', false);

        $movementsBefore = DB::table('stock_movements')->count();

        // Gọi lại cùng tag: không tạo mapping trùng (§13.9 "assign" idempotent).
        $this->putJson("/api/v1/outbound-documents/{$documentId}/items/{$itemId}/rfid", [
            'hardware_uid' => $hardwareUid,
        ], $headers)->assertOk();

        $this->assertSame(1, DB::table('physical_codes')->where('hardware_uid', $hardwareUid)->count());
        $this->assertSame($movementsBefore, DB::table('stock_movements')->count());
    }

    public function test_assign_item_rfid_rejects_duplicate_hardware_uid_of_another_item(): void
    {
        [$headers, $documentId, $itemId] = $this->scannedOutbound('TOA-RFID-002', 'PROD-RFID-002');
        $otherItemId = $this->seedItemInStock($this->productSkuFixture('PROD-RFID-OTHER')->sku_id);
        $hardwareUid = 'UID-'.Str::upper(Str::random(10));

        DB::table('physical_codes')->insert([
            'physical_code_id' => (string) Str::uuid(),
            'code_value' => 'RFID-OTHER-'.Str::upper(Str::random(6)),
            'code_type' => 'ITEM',
            'carrier_type' => 'RFID',
            'hardware_uid' => $hardwareUid,
            'item_id' => $otherItemId,
            'assigned_object_type' => 'ITEM',
            'assigned_object_id' => $otherItemId,
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson("/api/v1/outbound-documents/{$documentId}/items/{$itemId}/rfid", [
            'hardware_uid' => $hardwareUid,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_HARDWARE_UID');
    }

    public function test_packing_label_preview_and_ready_follow_state_model(): void
    {
        [$headers, $documentId] = $this->scannedOutbound('TOA-LBL-001', 'PROD-LBL-001');

        // §5.2/§15: full match đã tự tổng hợp lô và tạo/đảm bảo 01 Packing Label DRAFT.
        $label = DB::table('label_snapshots')->where('outbound_document_id', $documentId)->first();
        $this->assertNotNull($label, 'Full match phải tạo/đảm bảo Packing Label.');
        $this->assertSame('DRAFT', (string) $label->status);
        $labelId = (string) $label->id;
        $version = (int) $label->version;

        // Annex C previewPackingLabel: If-Match bắt buộc.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/packing-labels/{$labelId}/preview", [], $headers)
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $preview = $this->postJson("/api/v1/outbound-documents/{$documentId}/packing-labels/{$labelId}/preview", [], $headers + [
            'If-Match' => (string) $version,
        ]);
        $preview->assertOk()
            ->assertJsonPath('data.status', 'PREVIEWED')
            ->assertJsonPath('data.stock_effect', 'NONE');
        $version = (int) $preview->json('data.version');

        // Annex C readyPackingLabel: Idempotency-Key + If-Match; version cũ → 412.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/packing-labels/{$labelId}/ready", [], $headers + [
            'If-Match' => (string) ($version - 1),
            'Idempotency-Key' => 'label-ready-stale',
        ])->assertStatus(412)->assertJsonPath('error.code', 'STALE_VERSION');

        $ready = $this->postJson("/api/v1/outbound-documents/{$documentId}/packing-labels/{$labelId}/ready", [], $headers + [
            'If-Match' => (string) $version,
            'Idempotency-Key' => 'label-ready-001',
        ]);
        $ready->assertOk()->assertJsonPath('data.status', 'READY_TO_PRINT');
        $version = (int) $ready->json('data.version');

        // Preview sau khi đã READY_TO_PRINT là bước lùi trạng thái → 409.
        $this->postJson("/api/v1/outbound-documents/{$documentId}/packing-labels/{$labelId}/preview", [], $headers + [
            'If-Match' => (string) $version,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'LABEL_STATE_CONFLICT');

        $this->assertSame(0, DB::table('stock_movements')->where('stock_document_id', $documentId)->count());
    }

    public function test_packing_label_lifecycle_rejects_label_of_another_document(): void
    {
        [$headers, $documentId] = $this->scannedOutbound('TOA-LBL-002', 'PROD-LBL-002');
        $label = DB::table('label_snapshots')->where('outbound_document_id', $documentId)->first();
        $labelId = (string) $label->id;
        $otherDocumentId = (string) Str::uuid();

        $this->postJson("/api/v1/outbound-documents/{$otherDocumentId}/packing-labels/{$labelId}/preview", [], $headers + [
            'If-Match' => (string) $label->version,
        ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'LABEL_NOT_FOUND');
    }

    public function test_reversal_confirm_then_post_produces_movement_only_at_post(): void
    {
        Storage::fake('local');
        [$headers, $documentId, $itemId, $movementId] = $this->postedOutbound('TOA-REV-001', 'PROD-REV-001');

        // Tạo DRAFT ở trạng thái chờ hàng về (BR-REV-06).
        $draft = $this->postJson("/api/v1/outbound-documents/{$documentId}/reverse", [
            'reason' => 'Khách trả hàng, chờ nhập lại kho',
            'condition_status' => 'SELLABLE',
            'physical_return_confirmed' => false,
            'lines' => [['movement_id' => $movementId, 'quantity' => 1, 'item_id' => $itemId]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => 'rev-draft-001'])->assertOk();

        $reversalId = (string) $draft->json('data.reversal_document_id');
        $this->assertSame('PENDING_RETURN', $draft->json('data.physical_return_status'));
        $movementsAfterDraft = DB::table('stock_movements')->count();

        $this->postJson("/api/v1/reversal-documents/{$reversalId}/confirm", [
            'return_condition' => 'SELLABLE',
        ], $headers + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.physical_return_status', 'CONFIRMED')
            ->assertJsonPath('data.stock_effect', 'NONE');
        $this->assertSame($movementsAfterDraft, DB::table('stock_movements')->count(), 'Confirm không được sinh movement.');

        // "Chứng từ nhập/xuất/hoàn trả": Post reversal bắt buộc có chứng từ đính kèm
        // (DocumentEvidenceGuard::TYPE_REVERSAL luôn requires_evidence).
        $this->post("/api/v1/reversal-documents/{$reversalId}/attachments", [
            'file' => UploadedFile::fake()->image('bien-ban-hoan-tra.jpg'),
        ], $headers)->assertCreated();

        $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'rev-post-001',
        ])->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');

        $this->assertGreaterThan($movementsAfterDraft, DB::table('stock_movements')->count());
        $this->assertDatabaseHas('reversal_documents', ['id' => $reversalId, 'status' => 'POSTED']);
    }

    /** "Chứng từ nhập/xuất/hoàn trả": Post reversal phải bị chặn khi chưa đính kèm chứng từ. */
    public function test_reversal_post_blocked_without_evidence_then_succeeds_after_attachment(): void
    {
        Storage::fake('local');
        [$headers, $documentId, $itemId, $movementId] = $this->postedOutbound('TOA-REV-EVID-001', 'PROD-REV-EVID-001');
        $draft = $this->postJson("/api/v1/outbound-documents/{$documentId}/reverse", [
            'reason' => 'Khách trả hàng, chờ nhập lại kho',
            'condition_status' => 'SELLABLE',
            'physical_return_confirmed' => false,
            'lines' => [['movement_id' => $movementId, 'quantity' => 1, 'item_id' => $itemId]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => 'rev-evid-draft-001'])->assertOk();
        $reversalId = (string) $draft->json('data.reversal_document_id');

        $this->postJson("/api/v1/reversal-documents/{$reversalId}/confirm", [
            'return_condition' => 'SELLABLE',
        ], $headers + ['If-Match' => '1'])->assertOk();

        $blocked = $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'rev-evid-post-blocked',
        ]);
        $blocked->assertStatus(422)->assertJsonPath('error.code', 'EVIDENCE_REQUIRED');

        $listPending = $this->getJson("/api/v1/reversal-documents/{$reversalId}/attachments", $headers)->assertOk();
        $this->assertSame('PENDING', $listPending->json('meta.evidence_status'));

        $this->post("/api/v1/reversal-documents/{$reversalId}/attachments", [
            'file' => UploadedFile::fake()->image('bien-ban-hoan-tra.jpg'),
        ], $headers)->assertCreated();

        $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'rev-evid-post-ok',
        ])->assertOk()->assertJsonPath('data.reversal_status', 'POSTED');
    }

    public function test_reversal_post_requires_if_match_and_idempotency_key(): void
    {
        [$headers, $documentId, $itemId, $movementId] = $this->postedOutbound('TOA-REV-002', 'PROD-REV-002');
        $draft = $this->postJson("/api/v1/outbound-documents/{$documentId}/reverse", [
            'reason' => 'Khách trả hàng, chờ nhập lại kho',
            'condition_status' => 'SELLABLE',
            'physical_return_confirmed' => false,
            'lines' => [['movement_id' => $movementId, 'quantity' => 1, 'item_id' => $itemId]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => 'rev-draft-002'])->assertOk();
        $reversalId = (string) $draft->json('data.reversal_document_id');

        $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + ['Idempotency-Key' => 'rev-post-002'])
            ->assertStatus(428)
            ->assertJsonPath('error.code', 'PRECONDITION_REQUIRED');

        $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + ['If-Match' => '1'])
            ->assertStatus(428);
    }

    public function test_reversal_cancel_only_allowed_while_draft(): void
    {
        [$headers, $documentId, $itemId, $movementId] = $this->postedOutbound('TOA-REV-003', 'PROD-REV-003');
        $draft = $this->postJson("/api/v1/outbound-documents/{$documentId}/reverse", [
            'reason' => 'Tạo nhầm phiếu hoàn đảo',
            'condition_status' => 'SELLABLE',
            'physical_return_confirmed' => false,
            'lines' => [['movement_id' => $movementId, 'quantity' => 1, 'item_id' => $itemId]],
        ], $headers + ['If-Match' => '3', 'Idempotency-Key' => 'rev-draft-003'])->assertOk();
        $reversalId = (string) $draft->json('data.reversal_document_id');

        $this->postJson("/api/v1/reversal-documents/{$reversalId}/cancel", [
            'reason' => 'Tạo nhầm, huỷ phiếu hoàn đảo',
        ], $headers + ['If-Match' => '1'])
            ->assertOk()
            ->assertJsonPath('data.reversal_status', 'CANCELLED');

        // Đã CANCELLED thì không Post được nữa.
        $this->postJson("/api/v1/reversal-documents/{$reversalId}/post", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'rev-post-003',
        ])->assertStatus(409)->assertJsonPath('error.code', 'REVERSAL_NOT_DRAFT');
    }

    /** @return array{0:array<string,string>,1:string,2:string} */
    private function scannedOutbound(string $requestNo, string $skuCode): array
    {
        $headers = $this->superAdminHeaders();
        $warehouse = $this->warehouseFixture();
        $sku = $this->productSkuFixture($skuCode);
        $this->seedAvailableBalance($warehouse->id, $sku->sku_id, 5);
        $requestId = $this->outboundRequestFixture($sku->sku_id, 1, $requestNo);

        $created = $this->outboundDocumentFixture((string) $warehouse->id, $requestId);
        $documentId = $created['document_id'];
        $lineId = $created['line_ids'][0];
        $itemId = $this->seedItemInStock($sku->sku_id);
        $this->postJson("/api/v1/outbound-documents/{$documentId}/scan", [
            'line_id' => $lineId,
            'quantity' => 1,
            'item_id' => $itemId,
        ], $headers + ['If-Match' => '1'])->assertOk();

        return [$headers, $documentId, $itemId];
    }

    /** @return array{0:array<string,string>,1:string,2:string,3:string} */
    private function postedOutbound(string $requestNo, string $skuCode): array
    {
        [$headers, $documentId, $itemId] = $this->scannedOutbound($requestNo, $skuCode);
        $this->markLabelsPrinted($documentId);

        $this->postJson("/api/v1/outbound-documents/{$documentId}/post-issue", [], $headers + [
            'If-Match' => '2',
            'Idempotency-Key' => 'post-'.Str::lower(Str::random(10)),
        ])->assertOk();

        $movementId = (string) DB::table('stock_movements')
            ->where('stock_document_id', $documentId)
            ->where('movement_type', 'ISSUE')
            ->value('id');

        return [$headers, $documentId, $itemId, $movementId];
    }
}
